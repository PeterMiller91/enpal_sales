<?php
/*
 * Baubarkeits-Check – Server-Schnittstelle (PHP, ohne Abhängigkeiten).
 * Aufruf: api.php?p=<route>, z. B. api.php?p=checks/abc123
 */
declare(strict_types=1);

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/report.php';

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

// GET checks
if ($parts === ['checks'] && $method === 'GET') {
    json_out($store->listChecks());
}

if (($parts[0] ?? '') !== 'checks' || !isset($parts[1])) throw new HttpError(404, 'Unbekannte Route');
$id = $parts[1];
if (!preg_match(ID_RE, $id)) throw new HttpError(400, 'Ungültige ID');

// GET/POST checks/{id}
if (count($parts) === 2) {
    if ($method === 'GET') {
        $c = $store->read($id);
        if (!$c) throw new HttpError(404, 'Nicht gefunden');
        json_out($c['data'] + ['folder' => $c['rel']]);
    }
    if ($method === 'POST' || $method === 'PUT') {
        $body = json_body();
        if (!is_array($body['customer'] ?? null) || trim((string)($body['customer']['name'] ?? '')) === '') {
            throw new HttpError(400, 'Kundenname fehlt');
        }
        json_out($store->save($id, $body));
    }
}

// GET checks/{id}/report.pdf
if (count($parts) === 3 && $parts[2] === 'report.pdf' && $method === 'GET') {
    $c = $store->read($id);
    if (!$c) throw new HttpError(404, 'Nicht gefunden');
    $fileName = 'Bericht_' . safe_segment(str_replace('/', '_', $c['rel']), 'Bericht') . '.pdf';
    $dest = $store->path($c['rel'], $fileName);
    @set_time_limit(300);
    build_report($c['data'], $c['rel'], fn(string $rel) => $store->path($c['rel'], $rel), $config, $dest);
    send_file($dest, 'application/pdf', $fileName);
}

// checks/{id}/files/{fileId}[/chunk|/complete]
if (($parts[2] ?? '') === 'files' && isset($parts[3])) {
    $fileId = $parts[3];
    if (!preg_match(ID_RE, $fileId)) throw new HttpError(400, 'Ungültige ID');
    $action = $parts[4] ?? '';

    if ($action === '' && $method === 'GET') {
        $c = $store->read($id);
        $f = $c['data']['files'][$fileId] ?? null;
        if (!$f) throw new HttpError(404, 'Nicht gefunden');
        $rel = !empty($_GET['poster']) && !empty($f['poster']) ? $f['poster'] : $f['path'];
        send_file($store->path($c['rel'], $rel), !empty($_GET['poster']) ? 'image/jpeg' : ($f['mime'] ?: 'application/octet-stream'), basename($rel));
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
        json_out($store->completeUpload($id, $fileId, $step, $body));
    }
}

throw new HttpError(404, 'Unbekannte Route');
