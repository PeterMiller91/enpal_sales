<?php
/*
 * Baustellen-Fotos – Schnittstelle. Aufruf: baustelle/api.php?p=<route>
 * Anmeldung wie in der Bau-App (Cookie). Alle angemeldeten Benutzer sehen alle Baustellen.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/lib/util.php';
require dirname(__DIR__) . '/lib/db.php';
require dirname(__DIR__) . '/lib/baustelle.php';

const ID_RE = '/^[A-Za-z0-9_-]{6,64}$/';
const STEP_IDS = ['heizkoerper', 'zaehler', 'heizraum', 'weg', 'hausseiten', 'unterlagen'];

set_exception_handler(function (Throwable $e) {
    $status = $e instanceof HttpError ? $e->status : 500;
    if ($status >= 500) error_log('[baustelle] ' . $e);
    json_out(['error' => $status >= 500 && !($e instanceof HttpError) ? 'Serverfehler' : $e->getMessage()], $status);
});

$config = load_config();
$user = current_user($config);
if (!$user) throw new HttpError(401, 'Anmeldung erforderlich');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = trim((string)($_GET['p'] ?? ''), '/');
$parts = $route === '' ? [] : explode('/', $route);

// photos live next to the customer folders: daten/baustellen (blocked for web visitors, visible via FTP)
$sp = new SitePhotos(dirname(rtrim($config['data_dir'], '/')) . '/baustellen');

if ($route === 'state' && $method === 'GET') {
    json_out(['user' => $user, 'sites' => $sp->sites(), 'categories' => SitePhotos::CATEGORIES]);
}

// customer suggestions from the Baubarkeits-Check
if ($route === 'customers' && $method === 'GET') {
    $out = [];
    try {
        $db = new Db(new Store($config['data_dir']));
        foreach ($db->listChecks() as $c) {
            if (!empty($c['deletedAt'])) continue;
            $name = trim((string)($c['customer']['name'] ?? ''));
            if ($name !== '') $out[] = ['name' => $name, 'address' => trim((string)($c['customer']['address'] ?? ''))];
        }
    } catch (Throwable $e) {
        $out = [];
    }
    json_out($out);
}

if (($parts[0] ?? '') === 'sites' && isset($parts[1])) {
    $siteId = $parts[1];
    if (!preg_match(ID_RE, $siteId)) throw new HttpError(400, 'Ungültige ID');
    $action = $parts[2] ?? '';

    if ($action === '' && $method === 'POST') json_out($sp->saveSite($siteId, json_body(), $user));
    if ($action === 'photos' && $method === 'GET') json_out($sp->photos($siteId));

    // read-only links for observers
    if ($action === 'shares' && $method === 'GET') json_out($sp->shares($siteId));
    if ($action === 'shares' && $method === 'POST') {
        $b = json_body();
        $days = isset($b['days']) && (int)$b['days'] > 0 ? min(3650, (int)$b['days']) : null;
        $ids = is_array($b['photoIds'] ?? null) ? array_slice($b['photoIds'], 0, 2000) : null;
        json_out($sp->createShare($siteId, (string)($b['label'] ?? ''), $days, $user, $ids));
    }

    // one photo per request (multipart: file + meta JSON); the app sends photos already resized
    if ($action === 'upload' && $method === 'POST') {
        $photoId = (string)($_POST['id'] ?? '');
        if (!preg_match(ID_RE, $photoId)) throw new HttpError(400, 'Ungültige Foto-ID');
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
            throw new HttpError(in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400, $code === UPLOAD_ERR_NO_FILE ? 'Datei fehlt' : 'Upload fehlgeschlagen (Fehler ' . $code . ')');
        }
        $tmp = tempnam($sp->tmpDir(), 'up');
        if (!move_uploaded_file($f['tmp_name'], $tmp)) throw new HttpError(500, 'Upload konnte nicht gespeichert werden');
        $meta = json_decode((string)($_POST['meta'] ?? '{}'), true);
        json_out($sp->addPhoto($siteId, $photoId, $tmp, is_array($meta) ? $meta + ['name' => $f['name'] ?? ''] : [], $user));
    }

    if ($action === 'zip' && $method === 'GET') {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['day'] ?? '')) ? $_GET['day'] : null;
        $ids = isset($_GET['ids']) ? array_values(array_filter(explode(',', (string)$_GET['ids']), fn($x) => preg_match(ID_RE, $x))) : null;
        $z = $sp->zip($siteId, $day, $ids);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($z['file']));
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($z['name']));
        readfile($z['file']);
        @unlink($z['file']);
        exit;
    }
}

// remove several photos at once (moved to _entfernt/)
if ($route === 'photos/remove' && $method === 'POST') {
    $b = json_body();
    $n = 0;
    foreach (array_slice(is_array($b['ids'] ?? null) ? $b['ids'] : [], 0, 1000) as $pid) {
        if (is_string($pid) && preg_match(ID_RE, $pid)) { $sp->removePhoto($pid); $n++; }
    }
    json_out(['ok' => true, 'removed' => $n]);
}

if (($parts[0] ?? '') === 'shares' && isset($parts[1]) && ($parts[2] ?? '') === 'revoke' && $method === 'POST') {
    if (!preg_match(ID_RE, $parts[1])) throw new HttpError(400, 'Ungültige ID');
    json_out($sp->revokeShare($parts[1]));
}

if (($parts[0] ?? '') === 'photos' && isset($parts[1])) {
    $photoId = $parts[1];
    if (!preg_match(ID_RE, $photoId)) throw new HttpError(400, 'Ungültige ID');
    $action = $parts[2] ?? '';
    if ($action === '' && $method === 'GET') {
        if (!empty($_GET['thumb'])) {
            $t = $sp->thumbnail($photoId);
            if ($t) {
                header('Cache-Control: private, max-age=604800');
                while (ob_get_level()) ob_end_clean();
                header('Content-Type: image/jpeg');
                header('Content-Length: ' . filesize($t));
                readfile($t);
                exit;
            }
        }
        $f = $sp->file($photoId);
        if (!empty($_GET['download'])) {
            if (!is_file($f['abs'])) throw new HttpError(404, 'Datei nicht gefunden');
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: ' . ($f['mime'] ?: 'application/octet-stream'));
            header('Content-Length: ' . filesize($f['abs']));
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($f['name']));
            readfile($f['abs']);
            exit;
        }
        send_file($f['abs'], $f['mime'] ?: 'application/octet-stream', $f['name']);
    }
    if ($action === '' && $method === 'POST') json_out($sp->updatePhoto($photoId, json_body()));
    if ($action === 'remove' && $method === 'POST') { $sp->removePhoto($photoId); json_out(['ok' => true]); }
}

throw new HttpError(404, 'Unbekannte Route');
