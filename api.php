<?php
/*
 * Baubarkeits-Check – Server-Schnittstelle (PHP, ohne Abhängigkeiten).
 * Aufruf: api.php?p=<route>, z. B. api.php?p=checks/abc123
 */
declare(strict_types=1);

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/report.php';
require __DIR__ . '/lib/db.php';

const ID_RE = '/^[A-Za-z0-9_-]{6,64}$/';
const STEP_IDS = ['heizkoerper', 'zaehler', 'heizraum', 'weg', 'hausseiten', 'unterlagen'];
const CHUNK_MAX = 16 * 1024 * 1024;

set_exception_handler(function (Throwable $e) {
    $status = $e instanceof HttpError ? $e->status : 500;
    if ($status >= 500) error_log('[baubarkeits-check] ' . $e);
    json_out(['error' => $status >= 500 && !($e instanceof HttpError) ? 'Serverfehler' : $e->getMessage()], $status);
});

$config = load_config();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = trim((string)($_GET['p'] ?? ''), '/');
$parts = $route === '' ? [] : explode('/', $route);

// ---------------------------------------------------------------- public routes

if ($route === 'config' && $method === 'GET') {
    $user = current_user($config);
    $storage = $user ? storage_status($config) : ['ok' => null, 'error' => null];
    json_out([
        'companyName' => $config['company_name'],
        'minWidthCm' => (float)$config['min_width_cm'],
        'user' => $user,
        'storageOk' => $storage['ok'],
        'storageError' => $storage['error'],
    ]);
}

if ($route === 'login' && $method === 'POST') {
    $body = json_body();
    $name = trim((string)($body['user'] ?? ''));
    $pass = (string)($body['password'] ?? '');
    usleep(random_int(200000, 400000)); // slow down guessing
    if (!check_password($config, $name, $pass)) throw new HttpError(401, 'Benutzer oder Passwort falsch');
    set_auth_cookie($config, $name);
    json_out(['ok' => true, 'user' => $name]);
}

if ($route === 'logout' && $method === 'POST') {
    clear_auth_cookie();
    json_out(['ok' => true]);
}

// ---------------------------------------------------------------- everything else needs a login

if (!current_user($config)) throw new HttpError(401, 'Anmeldung erforderlich');
$store = new Store($config['data_dir']);
$db = new Db($store);
$user = current_user($config);

// POST sync: apply this device's changes and return everything new since its last sync
if ($route === 'sync' && $method === 'POST') {
    $body = json_body();
    $ops = is_array($body['ops'] ?? null) ? array_values($body['ops']) : [];
    if (count($ops) > 2000) throw new HttpError(413, 'Zu viele Änderungen auf einmal');
    $device = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($body['device'] ?? '')), 0, 64);
    json_out($db->sync($user, $device, $ops, max(0, (int)($body['since'] ?? 0))));
}

// GET checks (older app versions)
if ($parts === ['checks'] && $method === 'GET') {
    json_out($db->listChecks());
}

if (($parts[0] ?? '') !== 'checks' || !isset($parts[1])) throw new HttpError(404, 'Unbekannte Route');
$id = $parts[1];
if (!preg_match(ID_RE, $id)) throw new HttpError(400, 'Ungültige ID');

// GET/POST checks/{id} (older app versions: whole document)
if (count($parts) === 2) {
    if ($method === 'GET') {
        $row = $db->getCheck($id);
        if (!$row || $row['folder'] === null) throw new HttpError(404, 'Nicht gefunden');
        json_out($db->legacyCheck($row) + ['folder' => $row['folder']]);
    }
    if ($method === 'POST' || $method === 'PUT') {
        $body = json_body();
        if (!is_array($body['customer'] ?? null) || trim((string)($body['customer']['name'] ?? '')) === '') {
            throw new HttpError(400, 'Kundenname fehlt');
        }
        $ops = [['opId' => bin2hex(random_bytes(12)), 'checkId' => $id, 'type' => 'merge', 'doc' => $body]];
        if (!empty($body['deletedAt'])) $ops[] = ['opId' => bin2hex(random_bytes(12)), 'checkId' => $id, 'type' => 'delete'];
        $db->sync($user, 'legacy', $ops, PHP_INT_MAX);
        $row = $db->getCheck($id);
        json_out(['ok' => true, 'folder' => $row['folder'], 'updatedAt' => $row['updated_at'], 'deletedAt' => $row['deleted_at']]);
    }
}

// GET checks/{id}/report.pdf
if (count($parts) === 3 && $parts[2] === 'report.pdf' && $method === 'GET') {
    $row = $db->getCheck($id);
    if (!$row || $row['folder'] === null) throw new HttpError(404, 'Nicht gefunden');
    $fileName = 'Bericht_' . safe_segment(str_replace('/', '_', $row['folder']), 'Bericht') . '.pdf';
    $dest = $store->path($row['folder'], $fileName);
    @set_time_limit(300);
    build_report($db->legacyCheck($row), $row['folder'], fn(string $rel) => $store->path($row['folder'], $rel), $config, $dest);
    send_file($dest, 'application/pdf', $fileName);
}

// checks/{id}/files/{fileId}[/chunk|/complete]
if (($parts[2] ?? '') === 'files' && isset($parts[3])) {
    $fileId = $parts[3];
    if (!preg_match(ID_RE, $fileId)) throw new HttpError(400, 'Ungültige ID');
    $action = $parts[4] ?? '';

    if ($action === '' && $method === 'GET') {
        $row = $db->getCheck($id);
        $m = $db->media($fileId);
        if (!$row || !$m || $m['check_id'] !== $id || !$m['path'] || $row['folder'] === null) throw new HttpError(404, 'Nicht gefunden');
        if (!empty($_GET['thumb'])) {
            $thumb = $store->thumbnail($id, $fileId, $store->path($row['folder'], $m['poster'] ?: $m['path']));
            if ($thumb) send_file($thumb, 'image/jpeg', 'vorschau.jpg');
            throw new HttpError(404, 'Keine Vorschau');
        }
        $rel = !empty($_GET['poster']) && $m['poster'] ? $m['poster'] : $m['path'];
        send_file($store->path($row['folder'], $rel), !empty($_GET['poster']) ? 'image/jpeg' : ($m['mime'] ?: 'application/octet-stream'), basename($rel));
    }

    if ($action === 'chunk' && $method === 'POST') {
        $part = ($_GET['part'] ?? 'file') === 'poster' ? 'poster' : 'file';
        $offset = (int)($_GET['offset'] ?? 0);
        $size = $store->appendChunk($id, $fileId, $part, $offset, (int)$config['max_upload_mb']);
        json_out(['ok' => true, 'size' => $size]);
    }

    if ($action === 'complete' && $method === 'POST') {
        $body = json_body();
        $step = (string)($body['step'] ?? '');
        if (!in_array($step, STEP_IDS, true)) throw new HttpError(400, 'Ungültiger Schritt');
        json_out($store->completeUpload($db, $id, $fileId, $step, $body));
    }
}

throw new HttpError(404, 'Unbekannte Route');
