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
        'data_dir' => dirname(__DIR__) . '/daten/kunden',
        'company_name' => '[FIRMENNAME]',
        'logo_path' => '',
        'min_width_cm' => 0,
        'max_upload_mb' => 1024,
    ];
    $cfg = array_merge($defaults, is_array($cfg) ? $cfg : []);
    $cfg['data_dir'] = resolve_data_dir((string)$cfg['data_dir']);
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

function within_open_basedir(string $dir): bool
{
    $ob = (string)ini_get('open_basedir');
    if ($ob === '') return true;
    foreach (explode(PATH_SEPARATOR, $ob) as $allowed) {
        $allowed = rtrim(trim($allowed), '/');
        if ($allowed !== '' && ($dir === $allowed || str_starts_with($dir, $allowed . '/'))) return true;
    }
    return false;
}

/**
 * Hosts like Netcup only let PHP reach the web root (open_basedir = httpdocs).
 * Then use httpdocs/daten/kunden: visible via FTP, blocked for web visitors by .htaccess.
 */
function resolve_data_dir(string $dir): string
{
    if (within_open_basedir($dir)) return $dir;
    $fallback = dirname(__DIR__) . '/daten/kunden';
    return within_open_basedir($fallback) ? $fallback : $dir;
}

/** Human-readable reason why PHP cannot use $dir. */
function dir_problem(string $dir): string
{
    $show = ftp_path($dir) !== $dir ? '"' . ftp_path($dir) . '" (Server-Pfad ' . $dir . ')' : '"' . $dir . '"';
    $ob = (string)ini_get('open_basedir');
    if ($ob !== '') {
        if (!within_open_basedir($dir)) {
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

    public function __construct(string $dir)
    {
        if ((!@is_dir($dir) && !@mkdir($dir, 0775, true)) || !@is_writable($dir)) {
            throw new HttpError(500, dir_problem($dir));
        }
        $this->dir = rtrim(realpath($dir) ?: $dir, '/');
        $this->tmp = $this->dir . '/.tmp';
        if (!is_dir($this->tmp)) @mkdir($this->tmp, 0775, true);
        // In case data_dir lies inside the web root: never serve it directly.
        $deny = "Require all denied\n";
        if (!is_file($this->dir . '/.htaccess')) @file_put_contents($this->dir . '/.htaccess', $deny);
        $parent = dirname($this->dir);
        if (basename($parent) === 'daten' && !is_file($parent . '/.htaccess')) @file_put_contents($parent . '/.htaccess', $deny);
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

    /** Small JPEG preview (max. 480 px) for another device's list; cached in .tmp/thumbs. */
    public function thumbnail(string $id, string $fileId, string $source): ?string
    {
        if (!function_exists('imagecreatetruecolor') || !is_file($source)) return null;
        $dir = $this->tmp . '/thumbs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $dest = "$dir/{$id}_{$fileId}.jpg";
        if (is_file($dest) && filemtime($dest) >= filemtime($source)) return $dest;
        $info = @getimagesize($source);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return null;
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            default => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
        };
        if (!$src) return null;
        $scale = min(1, 480 / max($info[0], $info[1]));
        $w = max(1, (int)round($info[0] * $scale));
        $h = max(1, (int)round($info[1] * $scale));
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
        imagejpeg($dst, $dest, 75);
        imagedestroy($src);
        imagedestroy($dst);
        return $dest;
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

    /** Move a finished upload into the customer folder and register it in the database. */
    public function completeUpload(Db $db, string $id, string $fileId, string $step, array $body): array
    {
        $tmpMain = $this->tmpFile($id, $fileId, 'file');
        $tmpPoster = $this->tmpFile($id, $fileId, 'poster');
        clearstatcache();
        $existing = $db->media($fileId);
        if ($existing && $existing['path']) return ['ok' => true]; // already done (retry after a lost answer)
        if (!is_file($tmpMain)) throw new HttpError(400, 'Datei fehlt – Upload neu starten');
        $expected = (int)($body['size'] ?? -1);
        if ($expected >= 0 && filesize($tmpMain) !== $expected) {
            @unlink($tmpMain);
            throw new HttpError(400, 'Upload unvollständig – wird neu gestartet');
        }
        return $db->registerUpload($id, $fileId, $step, $body, function (array $row, bool $removed) use ($fileId, $step, $body, $tmpMain, $tmpPoster) {
            $stepDir = (array_search($step, STEP_IDS, true) + 1) . '_' . $step;
            if ($removed) $stepDir = '_entfernt'; // removed in the app before the upload finished
            if (!is_dir($this->path($row['folder'], $stepDir))) @mkdir($this->path($row['folder'], $stepDir), 0775, true);
            // File name: <Kunde>_<Datum>_<Uhrzeit>_<Bezeichnung>_<ID>, readable when copied out of the folder.
            $customer = (array)($row['doc']['customer'] ?? []);
            $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
            $when = date_segment($customer);
            if (!empty($meta['takenAt']) && ($t = strtotime((string)$meta['takenAt'])) !== false) {
                $when = (new DateTime('@' . $t))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d_H-i');
            }
            $label = safe_segment($customer['name'] ?? '', 'Kunde') . "_{$when}_" . safe_segment($body['label'] ?? '', 'datei');
            $short = substr($fileId, 0, 8);
            $ext = self::ext((string)($body['name'] ?? ''), (string)($body['mime'] ?? ''));
            $name = "{$label}_{$short}$ext";
            if (is_file($this->path($row['folder'], $stepDir, $name))) $name = "{$label}_{$fileId}$ext"; // never overwrite
            if (!rename($tmpMain, $this->path($row['folder'], $stepDir, $name))) throw new HttpError(500, 'Datei konnte nicht abgelegt werden');
            $poster = null;
            if (is_file($tmpPoster)) {
                $pname = "{$label}_{$short}_vorschau.jpg";
                if (rename($tmpPoster, $this->path($row['folder'], $stepDir, $pname))) $poster = "$stepDir/$pname";
            }
            return ["$stepDir/$name", $poster, filesize($this->path($row['folder'], $stepDir, $name))];
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
