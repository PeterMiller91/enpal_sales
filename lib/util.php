<?php
declare(strict_types=1);

final class HttpError extends Exception
{
    public int $status;
    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 4 * 1024 * 1024) throw new HttpError(413, 'Anfrage zu groß');
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new HttpError(400, 'Ungültige Daten');
    return $data;
}

function load_config(): array
{
    $file = dirname(__DIR__) . '/config.php';
    if (!is_file($file)) throw new HttpError(500, 'config.php fehlt – bitte config.sample.php kopieren und anpassen.');
    $cfg = require $file;
    $defaults = [
        'users' => [],
        'secret' => '',
        'data_dir' => dirname(__DIR__, 2) . '/baubarkeits-daten/kunden',
        'company_name' => '[FIRMENNAME]',
        'logo_path' => '',
        'min_width_cm' => 0,
        'max_upload_mb' => 1024,
    ];
    $cfg = array_merge($defaults, is_array($cfg) ? $cfg : []);
    if (strlen((string)$cfg['secret']) < 16 || str_starts_with((string)$cfg['secret'], 'BITTE-AENDERN')) {
        throw new HttpError(500, 'In config.php fehlt ein eigener "secret"-Wert.');
    }
    return $cfg;
}

// ---------------------------------------------------------------- auth

function check_password(array $cfg, string $user, string $pass): bool
{
    $stored = $cfg['users'][$user] ?? null;
    if (!is_string($stored) || $stored === '' || $pass === '' || str_starts_with($stored, 'BITTE-AENDERN')) return false;
    if (preg_match('/^\$(2y|argon2)/', $stored)) return password_verify($pass, $stored);
    return hash_equals($stored, $pass);
}

const AUTH_COOKIE = 'bbc_auth';
const AUTH_DAYS = 90;

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function set_auth_cookie(array $cfg, string $user): void
{
    $exp = time() + AUTH_DAYS * 86400;
    $payload = b64url($user) . '.' . $exp;
    $token = $payload . '.' . hash_hmac('sha256', $payload, (string)$cfg['secret']);
    setcookie(AUTH_COOKIE, $token, [
        'expires' => $exp,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_auth_cookie(): void
{
    setcookie(AUTH_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

function current_user(array $cfg): ?string
{
    $token = (string)($_COOKIE[AUTH_COOKIE] ?? '');
    $p = explode('.', $token);
    if (count($p) !== 3) return null;
    [$u, $exp, $sig] = $p;
    if (!hash_equals(hash_hmac('sha256', "$u.$exp", (string)$cfg['secret']), $sig)) return null;
    if ((int)$exp < time()) return null;
    $user = base64_decode(strtr($u, '-_', '+/'), true);
    // A user removed from config.php loses access immediately.
    if ($user === false || !isset($cfg['users'][$user])) return null;
    return $user;
}

// ---------------------------------------------------------------- names & paths

/** Turn arbitrary text into a safe single path segment (Umlaute → ae/oe/ue). */
function safe_segment($text, string $fallback): string
{
    $s = (string)$text;
    if (class_exists('Normalizer')) $s = Normalizer::normalize($s, Normalizer::FORM_C) ?: $s;
    $s = strtr($s, ['ä' => 'ae', 'Ä' => 'Ae', 'ö' => 'oe', 'Ö' => 'Oe', 'ü' => 'ue', 'Ü' => 'Ue', 'ß' => 'ss']);
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false) $s = $t;
    $s = preg_replace('/[^A-Za-z0-9._-]+/', '_', $s) ?? '';
    $s = trim($s, '._-');
    $s = substr($s, 0, 80);
    return $s !== '' ? $s : $fallback;
}

function folder_name_for(array $customer): string
{
    $nr = safe_segment($customer['number'] ?? '', '');
    $name = safe_segment($customer['name'] ?? '', 'Kunde');
    return $nr !== '' ? "{$nr}_{$name}" : $name;
}

function date_segment(array $customer): string
{
    $d = (string)($customer['date'] ?? '');
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $d, $m)) return sprintf('%s-%02d-%02d', $m[3], $m[2], $m[1]);
    return date('Y-m-d');
}

/** ['ok' => bool, 'error' => ?string] – used by the app to show what is wrong with the data folder. */
function storage_status(array $cfg): array
{
    try {
        new Store($cfg['data_dir']);
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function php_user(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $u = @posix_getpwuid(posix_geteuid());
        if (!empty($u['name'])) return $u['name'];
    }
    return get_current_user() ?: '?';
}

/** Path as seen in the FTP client (Plesk: relative to /var/www/vhosts/<domain>). */
function ftp_path(string $abs): string
{
    return preg_match('#/vhosts/[^/]+(/.*)$#', $abs, $m) ? $m[1] : $abs;
}

/** Human-readable reason why PHP cannot use $dir. */
function dir_problem(string $dir): string
{
    $show = ftp_path($dir) !== $dir ? '"' . ftp_path($dir) . '" (Server-Pfad ' . $dir . ')' : '"' . $dir . '"';
    $ob = (string)ini_get('open_basedir');
    if ($ob !== '') {
        $inside = false;
        foreach (explode(PATH_SEPARATOR, $ob) as $allowed) {
            $allowed = rtrim(trim($allowed), '/');
            if ($allowed !== '' && ($dir === $allowed || str_starts_with($dir, $allowed . '/'))) $inside = true;
        }
        if (!$inside) {
            return "Datenordner $show liegt außerhalb des für PHP erlaubten Bereichs (open_basedir: $ob). "
                . 'In config.php bei data_dir einen Ordner innerhalb davon eintragen.';
        }
    }
    $p = $dir;
    while (!@is_dir($p) && dirname($p) !== $p) $p = dirname($p);
    if (!@is_dir($dir)) {
        return "Datenordner $show existiert nicht und PHP (Benutzer " . php_user() . ') darf ihn in "'
            . ftp_path($p) . '" nicht anlegen. Bitte den Ordner per FTP anlegen.';
    }
    return "Datenordner $show ist für PHP (Benutzer " . php_user() . ') nicht beschreibbar. '
        . 'Bitte per FTP die Rechte des Ordners auf 775 setzen.';
}

function send_file(string $file, string $mime, string $name): void
{
    if (!is_file($file)) throw new HttpError(404, 'Datei nicht gefunden');
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($file));
    header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($name));
    header('Cache-Control: private, no-store');
    readfile($file);
    exit;
}

// ---------------------------------------------------------------- storage

final class Store
{
    private string $dir;
    private string $tmp;
    /** @var resource|null */
    private $lock = null;

    public function __construct(string $dir)
    {
        if ((!@is_dir($dir) && !@mkdir($dir, 0775, true)) || !@is_writable($dir)) {
            throw new HttpError(500, dir_problem($dir));
        }
        $this->dir = rtrim(realpath($dir) ?: $dir, '/');
        $this->tmp = $this->dir . '/.tmp';
        if (!is_dir($this->tmp)) @mkdir($this->tmp, 0775, true);
        // In case data_dir lies inside the web root: never serve it directly.
        if (!is_file($this->dir . '/.htaccess')) @file_put_contents($this->dir . '/.htaccess', "Require all denied\nDeny from all\n");
        if (random_int(1, 50) === 1) $this->cleanupTmp();
    }

    /** Absolute path inside the data dir; rejects "..". */
    public function path(string ...$parts): string
    {
        $rel = implode('/', $parts);
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '..' || str_contains($seg, "\0") || str_contains($seg, '\\')) throw new HttpError(400, 'Ungültiger Pfad');
        }
        return $this->dir . '/' . ltrim($rel, '/');
    }

    private function locked(callable $fn)
    {
        $this->lock = fopen($this->dir . '/.lock', 'c');
        flock($this->lock, LOCK_EX);
        try {
            return $fn();
        } finally {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    private function index(): array
    {
        $f = $this->dir . '/.index.json';
        if (!is_file($f)) return [];
        $d = json_decode((string)file_get_contents($f), true);
        return is_array($d) ? $d : [];
    }

    private function writeJson(string $file, array $data): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            throw new HttpError(500, 'Speichern fehlgeschlagen (Speicherplatz?)');
        }
        rename($tmp, $file);
    }

    public function read(string $id): ?array
    {
        $rel = $this->index()[$id] ?? null;
        if (!$rel) return null;
        $f = $this->path($rel, 'check.json');
        if (!is_file($f)) return null;
        $data = json_decode((string)file_get_contents($f), true);
        return is_array($data) ? ['rel' => $rel, 'data' => $data] : null;
    }

    public function listChecks(): array
    {
        $out = [];
        foreach (array_keys($this->index()) as $id) {
            $c = $this->read((string)$id);
            if ($c) $out[] = ['id' => $id, 'folder' => $c['rel'], 'customer' => $c['data']['customer'] ?? [], 'updatedAt' => $c['data']['updatedAt'] ?? ''];
        }
        usort($out, fn($a, $b) => strcmp((string)$b['updatedAt'], (string)$a['updatedAt']));
        return $out;
    }

    public function save(string $id, array $body): array
    {
        return $this->locked(function () use ($id, $body) {
            $index = $this->index();
            $rel = $index[$id] ?? null;
            $existing = [];
            if ($rel) {
                $c = $this->read($id);
                if ($c) $existing = $c['data'];
            } else {
                $base = folder_name_for($body['customer']);
                $day = date_segment($body['customer']);
                $rel = "$base/$day";
                $taken = array_flip(array_values($index));
                for ($n = 2; isset($taken[$rel]); $n++) $rel = "$base/{$day}_$n";
                if (!is_dir($this->path($rel)) && !@mkdir($this->path($rel), 0775, true)) {
                    throw new HttpError(500, 'Kundenordner konnte nicht angelegt werden');
                }
                $index[$id] = $rel;
                $this->writeJson($this->dir . '/.index.json', $index);
            }
            unset($body['dirty'], $body['folder']);
            // The server owns the record of uploaded files; the client owns the rest.
            $data = array_merge($body, [
                'id' => $id,
                'files' => $existing['files'] ?? new stdClass(),
                'createdAt' => $existing['createdAt'] ?? gmdate('c'),
                'updatedAt' => gmdate('c'),
            ]);
            $this->writeJson($this->path($rel, 'check.json'), $data);
            return ['ok' => true, 'folder' => $rel];
        });
    }

    private function tmpFile(string $id, string $fileId, string $part): string
    {
        return "{$this->tmp}/{$id}_{$fileId}_{$part}.part";
    }

    /** Append the raw request body at $offset. Chunks keep every request below PHP's upload limits. */
    public function appendChunk(string $id, string $fileId, string $part, int $offset, int $maxMb): int
    {
        $file = $this->tmpFile($id, $fileId, $part);
        clearstatcache(true, $file);
        $current = is_file($file) ? filesize($file) : 0;
        if ($offset !== 0 && $offset !== $current) {
            json_out(['error' => 'Offset passt nicht', 'size' => $current], 409);
        }
        $in = fopen('php://input', 'rb');
        $out = fopen($file, $offset === 0 ? 'wb' : 'ab');
        if (!$in || !$out) throw new HttpError(500, 'Upload konnte nicht gespeichert werden');
        $written = stream_copy_to_stream($in, $out, CHUNK_MAX + 1);
        fclose($in);
        fclose($out);
        if ($written === false || $written > CHUNK_MAX) {
            @unlink($file);
            throw new HttpError(413, 'Teilstück zu groß');
        }
        $size = $offset + $written;
        if ($size > $maxMb * 1024 * 1024) {
            @unlink($file);
            throw new HttpError(413, "Datei größer als $maxMb MB");
        }
        return $size;
    }

    public function completeUpload(string $id, string $fileId, string $step, array $body): array
    {
        $tmpMain = $this->tmpFile($id, $fileId, 'file');
        $tmpPoster = $this->tmpFile($id, $fileId, 'poster');
        clearstatcache();
        if (!is_file($tmpMain)) throw new HttpError(400, 'Datei fehlt – Upload neu starten');
        $expected = (int)($body['size'] ?? -1);
        if ($expected >= 0 && filesize($tmpMain) !== $expected) {
            @unlink($tmpMain);
            throw new HttpError(400, 'Upload unvollständig – wird neu gestartet');
        }

        return $this->locked(function () use ($id, $fileId, $step, $body, $tmpMain, $tmpPoster) {
            $c = $this->read($id);
            if (!$c) throw new HttpError(404, 'Check nicht gefunden – zuerst Daten speichern');
            $stepDir = (array_search($step, STEP_IDS, true) + 1) . '_' . $step;
            if (!is_dir($this->path($c['rel'], $stepDir))) @mkdir($this->path($c['rel'], $stepDir), 0775, true);

            // File name: <Kunde>_<Datum>_<Uhrzeit>_<Bezeichnung>_<ID>, readable when copied out of the folder.
            $customer = (array)($c['data']['customer'] ?? []);
            $meta0 = is_array($body['meta'] ?? null) ? $body['meta'] : [];
            $when = date_segment($customer);
            if (!empty($meta0['takenAt']) && ($t = strtotime((string)$meta0['takenAt'])) !== false) {
                $when = (new DateTime('@' . $t))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d_H-i');
            }
            $label = safe_segment($customer['name'] ?? '', 'Kunde') . "_{$when}_" . safe_segment($body['label'] ?? '', 'datei');
            $short = substr($fileId, 0, 8);
            $name = "{$label}_{$short}" . self::ext((string)($body['name'] ?? ''), (string)($body['mime'] ?? ''));
            if (!rename($tmpMain, $this->path($c['rel'], $stepDir, $name))) throw new HttpError(500, 'Datei konnte nicht abgelegt werden');

            $posterRel = null;
            if (is_file($tmpPoster)) {
                $pname = "{$label}_{$short}_vorschau.jpg";
                if (rename($tmpPoster, $this->path($c['rel'], $stepDir, $pname))) $posterRel = "$stepDir/$pname";
            }

            $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
            $num = fn($v) => is_int($v) || is_float($v) ? $v : null;
            $files = (array)($c['data']['files'] ?? []);
            $files[$fileId] = [
                'path' => "$stepDir/$name",
                'poster' => $posterRel,
                'mime' => (string)($body['mime'] ?? ''),
                'size' => filesize($this->path($c['rel'], $stepDir, $name)),
                'step' => $step,
                'label' => (string)($body['label'] ?? ''),
                'takenAt' => isset($meta['takenAt']) ? (string)$meta['takenAt'] : null,
                'lat' => $num($meta['lat'] ?? null),
                'lng' => $num($meta['lng'] ?? null),
                'accuracy' => $num($meta['accuracy'] ?? null),
                'originalName' => (string)($body['name'] ?? ''),
                'uploadedAt' => gmdate('c'),
            ];
            $c['data']['files'] = $files;
            $this->writeJson($this->path($c['rel'], 'check.json'), $c['data']);
            return ['ok' => true, 'path' => "{$c['rel']}/$stepDir/$name"];
        });
    }

    private static function ext(string $name, string $mime): string
    {
        $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (preg_match('/^[a-z0-9]{1,5}$/', $e)) return ".$e";
        $map = [
            'image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp', 'image/heic' => '.heic',
            'video/mp4' => '.mp4', 'video/quicktime' => '.mov', 'video/webm' => '.webm', 'application/pdf' => '.pdf',
        ];
        return $map[$mime] ?? '.bin';
    }

    private function cleanupTmp(): void
    {
        foreach (glob($this->tmp . '/*.part') ?: [] as $f) {
            if (filemtime($f) < time() - 2 * 86400) @unlink($f);
        }
    }
}
