<?php
/*
 * Baustellen-Fotos: Baustellen und ihre Fotos (SQLite) mit geordneter Ablage im Datenordner:
 *   daten/baustellen/<Baustelle>/<JJJJ-MM-TT>/<NN_Kategorie>/<Baustelle>_<Datum_Uhrzeit>_<Kategorie>_<ID>.jpg
 * Gelöschte Fotos werden nach <Baustelle>/_entfernt/ verschoben, nie endgültig gelöscht.
 */
declare(strict_types=1);

final class SitePhotos
{
    public const CATEGORIES = [
        'vorher' => 'Vorher',
        'aussengeraet' => 'Außengerät & Fundament',
        'hydraulik' => 'Hydraulik & Heizraum',
        'elektrik' => 'Elektrik & Zählerschrank',
        'leitungen' => 'Leitungen & Kernbohrung',
        'pv' => 'PV & Dach',
        'inbetriebnahme' => 'Inbetriebnahme',
        'nachher' => 'Nachher & Abnahme',
        'maengel' => 'Mängel & Schäden',
        'dokumente' => 'Lieferscheine & Dokumente',
        'sonstiges' => 'Sonstiges',
    ];

    private PDO $pdo;
    private string $dir;

    public function __construct(string $dir)
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) throw new HttpError(500, 'PHP-Erweiterung pdo_sqlite fehlt.');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new HttpError(500, 'Ordner für Baustellen-Fotos kann nicht angelegt werden.');
        if (!is_writable($dir)) throw new HttpError(500, 'Ordner für Baustellen-Fotos ist nicht beschreibbar: ' . $dir);
        if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\n");
        $this->dir = rtrim($dir, '/');
        foreach (['.db', '.tmp', '.thumbs'] as $sub) if (!is_dir("$dir/$sub")) @mkdir("$dir/$sub", 0775, true);
        $this->pdo = new PDO("sqlite:$dir/.db/baustellen.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA busy_timeout = 10000; PRAGMA journal_mode = WAL;');
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS sites (
                id TEXT PRIMARY KEY, name TEXT NOT NULL, address TEXT, customer TEXT, note TEXT, folder TEXT NOT NULL UNIQUE,
                archived INTEGER NOT NULL DEFAULT 0, created_by TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS photos (
                id TEXT PRIMARY KEY, site_id TEXT NOT NULL, category TEXT NOT NULL, day TEXT NOT NULL, taken_at TEXT,
                lat REAL, lng REAL, note TEXT, path TEXT NOT NULL, mime TEXT, size INTEGER, width INTEGER, height INTEGER,
                original_name TEXT, uploaded_by TEXT, uploaded_at TEXT NOT NULL, deleted_at TEXT
            );
            CREATE INDEX IF NOT EXISTS photos_site ON photos(site_id, day);
            -- read-only links for observers (token looked up by its hash; kept so the link can be copied again)
            CREATE TABLE IF NOT EXISTS shares (
                id TEXT PRIMARY KEY, site_id TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE, token TEXT NOT NULL, label TEXT NOT NULL,
                created_by TEXT, created_at TEXT NOT NULL, expires_at TEXT, revoked_at TEXT,
                access_count INTEGER NOT NULL DEFAULT 0, last_access_at TEXT
            );
            CREATE INDEX IF NOT EXISTS shares_site ON shares(site_id);
        SQL);
    }

    private function path(string ...$parts): string
    {
        $rel = implode('/', $parts);
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '..' || str_contains($seg, "\0") || str_contains($seg, '\\')) throw new HttpError(400, 'Ungültiger Pfad');
        }
        return $this->dir . '/' . ltrim($rel, '/');
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    // ---------------------------------------------------------------- sites

    public function sites(): array
    {
        $rows = $this->pdo->query("SELECT s.*,
                (SELECT COUNT(*) FROM photos p WHERE p.site_id = s.id AND p.deleted_at IS NULL) AS photo_count,
                (SELECT MAX(day) FROM photos p WHERE p.site_id = s.id AND p.deleted_at IS NULL) AS last_day,
                (SELECT id FROM photos p WHERE p.site_id = s.id AND p.deleted_at IS NULL ORDER BY uploaded_at DESC LIMIT 1) AS cover
            FROM sites s ORDER BY s.archived, COALESCE(last_day, substr(s.created_at, 1, 10)) DESC, s.name")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => [
            'id' => $r['id'], 'name' => $r['name'], 'address' => $r['address'] ?? '', 'customer' => $r['customer'] ?? '',
            'note' => $r['note'] ?? '', 'folder' => $r['folder'], 'archived' => (bool)$r['archived'],
            'photoCount' => (int)$r['photo_count'], 'lastDay' => $r['last_day'], 'cover' => $r['cover'], 'createdAt' => $r['created_at'],
        ], $rows);
    }

    private function site(string $id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM sites WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new HttpError(404, 'Baustelle nicht gefunden');
        return $r;
    }

    public function saveSite(string $id, array $b, string $user): array
    {
        $name = trim(mb_substr((string)($b['name'] ?? ''), 0, 120));
        if ($name === '') throw new HttpError(400, 'Name der Baustelle fehlt');
        $vals = [
            'name' => $name,
            'address' => trim(mb_substr((string)($b['address'] ?? ''), 0, 200)),
            'customer' => trim(mb_substr((string)($b['customer'] ?? ''), 0, 120)),
            'note' => trim(mb_substr((string)($b['note'] ?? ''), 0, 1000)),
            'archived' => !empty($b['archived']) ? 1 : 0,
        ];
        $st = $this->pdo->prepare('SELECT * FROM sites WHERE id = ?');
        $st->execute([$id]);
        $now = self::now();
        if ($st->fetch()) {
            // the folder name stays as created, so existing paths keep working
            $this->pdo->prepare('UPDATE sites SET name = ?, address = ?, customer = ?, note = ?, archived = ?, updated_at = ? WHERE id = ?')
                ->execute([$vals['name'], $vals['address'], $vals['customer'], $vals['note'], $vals['archived'], $now, $id]);
        } else {
            $base = safe_segment($name, 'Baustelle');
            $folder = $base;
            $chk = $this->pdo->prepare('SELECT 1 FROM sites WHERE folder = ?');
            for ($n = 2; ; $n++) {
                $chk->execute([$folder]);
                if (!$chk->fetchColumn() && !is_dir($this->path($folder))) break;
                $folder = "{$base}_$n";
            }
            if (!@mkdir($this->path($folder), 0775, true)) throw new HttpError(500, 'Ordner der Baustelle kann nicht angelegt werden');
            $this->pdo->prepare('INSERT INTO sites (id, name, address, customer, note, folder, archived, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id, $vals['name'], $vals['address'], $vals['customer'], $vals['note'], $folder, 0, $user, $now, $now]);
        }
        $this->writeInfo($id);
        foreach ($this->sites() as $s) if ($s['id'] === $id) return $s;
        throw new HttpError(500, 'Baustelle nicht gespeichert');
    }

    /** baustelle.txt in the folder: who/where at a glance for someone browsing the FTP. */
    private function writeInfo(string $id): void
    {
        $s = $this->site($id);
        $txt = "Baustelle: {$s['name']}\r\nAdresse: {$s['address']}\r\nKunde: {$s['customer']}\r\n"
            . ($s['note'] !== '' && $s['note'] !== null ? "Notiz: {$s['note']}\r\n" : '')
            . 'Angelegt: ' . $s['created_at'] . ' von ' . $s['created_by'] . "\r\n";
        @file_put_contents($this->path($s['folder'], 'baustelle.txt'), $txt);
    }

    // ---------------------------------------------------------------- photos

    public function photos(string $siteId): array
    {
        $this->site($siteId);
        $st = $this->pdo->prepare('SELECT * FROM photos WHERE site_id = ? AND deleted_at IS NULL ORDER BY day DESC, COALESCE(taken_at, uploaded_at)');
        $st->execute([$siteId]);
        return array_map([self::class, 'out'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function out(array $p): array
    {
        return [
            'id' => $p['id'], 'siteId' => $p['site_id'], 'category' => $p['category'], 'day' => $p['day'], 'takenAt' => $p['taken_at'],
            'lat' => $p['lat'] !== null ? (float)$p['lat'] : null, 'lng' => $p['lng'] !== null ? (float)$p['lng'] : null,
            'note' => $p['note'] ?? '', 'path' => $p['path'], 'size' => (int)$p['size'], 'width' => (int)$p['width'], 'height' => (int)$p['height'],
            'uploadedBy' => $p['uploaded_by'], 'uploadedAt' => $p['uploaded_at'],
        ];
    }

    private function photo(string $id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM photos WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new HttpError(404, 'Foto nicht gefunden');
        return $p;
    }

    /** Where a photo belongs: <Datum>/<NN_Kategorie>/<Baustelle>_<Datum_Uhrzeit>_<Kategorie>_<ID>.jpg */
    private function target(array $site, string $category, string $day, ?string $takenAt, string $photoId, string $ext): string
    {
        $idx = array_search($category, array_keys(self::CATEGORIES), true);
        $catDir = sprintf('%02d_%s', $idx + 1, safe_segment(self::CATEGORIES[$category], $category));
        $when = $day;
        if ($takenAt && ($t = strtotime($takenAt)) !== false) {
            $when = (new DateTime('@' . $t))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d_H-i');
        }
        $name = safe_segment($site['name'], 'Baustelle') . "_{$when}_" . safe_segment(self::CATEGORIES[$category], $category) . '_' . substr($photoId, 0, 8) . $ext;
        return "$day/$catDir/$name";
    }

    /** Store an uploaded file (already moved to a temp path) and register it. Idempotent per photo id. */
    public function addPhoto(string $siteId, string $photoId, string $tmpFile, array $meta, string $user): array
    {
        $site = $this->site($siteId);
        $st = $this->pdo->prepare('SELECT * FROM photos WHERE id = ?');
        $st->execute([$photoId]);
        if ($existing = $st->fetch(PDO::FETCH_ASSOC)) { @unlink($tmpFile); return self::out($existing); } // retry after lost answer

        $info = @getimagesize($tmpFile);
        $mime = $info['mime'] ?? (mime_content_type($tmpFile) ?: 'application/octet-stream');
        $allowed = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp', 'image/heic' => '.heic', 'image/heif' => '.heic', 'application/pdf' => '.pdf'];
        if (!isset($allowed[$mime])) { @unlink($tmpFile); throw new HttpError(415, 'Nur Fotos (JPG, PNG, WebP, HEIC) und PDF'); }
        $category = isset(self::CATEGORIES[$meta['category'] ?? '']) ? $meta['category'] : 'sonstiges';
        $takenAt = isset($meta['takenAt']) && strtotime((string)$meta['takenAt']) !== false ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$meta['takenAt'])) : null;
        $day = $takenAt ? (new DateTime($takenAt))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d') : date('Y-m-d');
        $rel = $this->target($site, $category, $day, $takenAt, $photoId, $allowed[$mime]);
        $abs = $this->path($site['folder'], $rel);
        if (!is_dir(dirname($abs))) @mkdir(dirname($abs), 0775, true);
        if (!@rename($tmpFile, $abs)) { @unlink($tmpFile); throw new HttpError(500, 'Foto konnte nicht abgelegt werden'); }
        $num = fn($v) => is_int($v) || is_float($v) ? (float)$v : null;
        $this->pdo->prepare('INSERT INTO photos (id, site_id, category, day, taken_at, lat, lng, note, path, mime, size, width, height, original_name, uploaded_by, uploaded_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            $photoId, $siteId, $category, $day, $takenAt, $num($meta['lat'] ?? null), $num($meta['lng'] ?? null),
            trim(mb_substr((string)($meta['note'] ?? ''), 0, 500)), $rel, $mime, filesize($abs), $info[0] ?? null, $info[1] ?? null,
            mb_substr((string)($meta['name'] ?? ''), 0, 200), $user, self::now(),
        ]);
        $this->pdo->prepare('UPDATE sites SET updated_at = ? WHERE id = ?')->execute([self::now(), $siteId]);
        return self::out($this->photo($photoId));
    }

    /** Change category / note; the file moves into the new category folder. */
    public function updatePhoto(string $photoId, array $b): array
    {
        $p = $this->photo($photoId);
        if ($p['deleted_at']) throw new HttpError(409, 'Foto wurde entfernt');
        $site = $this->site($p['site_id']);
        $category = isset(self::CATEGORIES[$b['category'] ?? '']) ? $b['category'] : $p['category'];
        $note = array_key_exists('note', $b) ? trim(mb_substr((string)$b['note'], 0, 500)) : ($p['note'] ?? '');
        $path = $p['path'];
        if ($category !== $p['category']) {
            $new = $this->target($site, $category, $p['day'], $p['taken_at'], $photoId, '.' . pathinfo($p['path'], PATHINFO_EXTENSION));
            $dst = $this->path($site['folder'], $new);
            if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0775, true);
            if (@rename($this->path($site['folder'], $p['path']), $dst)) {
                $path = $new;
                @rmdir(dirname($this->path($site['folder'], $p['path']))); // remove the old category folder if empty
            }
        }
        $this->pdo->prepare('UPDATE photos SET category = ?, note = ?, path = ? WHERE id = ?')->execute([$category, $note, $path, $photoId]);
        return self::out($this->photo($photoId));
    }

    /** Never deleted for good: moved to <Baustelle>/_entfernt/ and hidden in the app. */
    public function removePhoto(string $photoId): void
    {
        $p = $this->photo($photoId);
        if ($p['deleted_at']) return;
        $site = $this->site($p['site_id']);
        $dstRel = '_entfernt/' . basename($p['path']);
        if (!is_dir($this->path($site['folder'], '_entfernt'))) @mkdir($this->path($site['folder'], '_entfernt'), 0775, true);
        $path = @rename($this->path($site['folder'], $p['path']), $this->path($site['folder'], $dstRel)) ? $dstRel : $p['path'];
        @rmdir(dirname($this->path($site['folder'], $p['path'])));
        $this->pdo->prepare('UPDATE photos SET deleted_at = ?, path = ? WHERE id = ?')->execute([self::now(), $path, $photoId]);
    }

    public function file(string $photoId): array
    {
        $p = $this->photo($photoId);
        $site = $this->site($p['site_id']);
        return ['abs' => $this->path($site['folder'], $p['path']), 'mime' => $p['mime'], 'name' => basename($p['path']), 'id' => $p['id']];
    }

    /** Cached preview (max. 480 px) for the gallery. */
    public function thumbnail(string $photoId): ?string
    {
        $f = $this->file($photoId);
        $dest = "{$this->dir}/.thumbs/{$photoId}.jpg";
        if (is_file($dest)) return $dest;
        if (!function_exists('imagecreatetruecolor') || !is_file($f['abs'])) return null;
        $info = @getimagesize($f['abs']);
        if (!$info) return null;
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($f['abs']),
            IMAGETYPE_PNG => @imagecreatefrompng($f['abs']),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($f['abs']) : false,
            default => false,
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

    /** ZIP of all current photos of a site (optionally one day), keeping the folder structure. */
    public function zip(string $siteId, ?string $day): array
    {
        if (!class_exists('ZipArchive')) throw new HttpError(501, 'ZIP-Download ist auf diesem Server nicht verfügbar (PHP-Erweiterung zip).');
        $site = $this->site($siteId);
        $file = tempnam($this->dir . '/.tmp', 'zip');
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::OVERWRITE);
        $n = 0;
        foreach ($this->photos($siteId) as $p) {
            if ($day && $p['day'] !== $day) continue;
            $abs = $this->path($site['folder'], $p['path']);
            if (is_file($abs)) { $zip->addFile($abs, $site['folder'] . '/' . $p['path']); $n++; }
        }
        if (is_file($this->path($site['folder'], 'baustelle.txt'))) $zip->addFile($this->path($site['folder'], 'baustelle.txt'), $site['folder'] . '/baustelle.txt');
        $zip->close();
        if (!$n) { @unlink($file); throw new HttpError(404, 'Keine Fotos zum Herunterladen'); }
        return ['file' => $file, 'name' => $site['folder'] . ($day ? "_$day" : '') . '.zip'];
    }

    // ---------------------------------------------------------------- read-only links for observers

    /** Create a read-only link for an observer. */
    public function createShare(string $siteId, string $label, ?int $days, string $user): array
    {
        $this->site($siteId);
        $label = trim(mb_substr($label, 0, 80));
        if ($label === '') throw new HttpError(400, 'Bitte angeben, für wen der Link ist');
        $token = bin2hex(random_bytes(16));
        $id = bin2hex(random_bytes(12));
        $expires = $days ? gmdate('Y-m-d\TH:i:s\Z', time() + $days * 86400) : null;
        $this->pdo->prepare('INSERT INTO shares (id, site_id, token_hash, token, label, created_by, created_at, expires_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$id, $siteId, hash('sha256', $token), $token, $label, $user, self::now(), $expires]);
        return $this->shareOut($this->shareRow($id));
    }

    private function shareRow(string $id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM shares WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new HttpError(404, 'Freigabe nicht gefunden');
        return $r;
    }

    private function shareOut(array $r): array
    {
        $expired = $r['expires_at'] !== null && $r['expires_at'] < self::now();
        return [
            'id' => $r['id'], 'token' => $r['token'], 'label' => $r['label'], 'createdBy' => $r['created_by'], 'createdAt' => $r['created_at'],
            'expiresAt' => $r['expires_at'], 'revokedAt' => $r['revoked_at'], 'accessCount' => (int)$r['access_count'],
            'lastAccessAt' => $r['last_access_at'], 'active' => $r['revoked_at'] === null && !$expired,
        ];
    }

    public function shares(string $siteId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM shares WHERE site_id = ? ORDER BY created_at DESC');
        $st->execute([$siteId]);
        return array_map([$this, 'shareOut'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function revokeShare(string $id): array
    {
        $this->pdo->prepare('UPDATE shares SET revoked_at = COALESCE(revoked_at, ?) WHERE id = ?')->execute([self::now(), $id]);
        return $this->shareOut($this->shareRow($id));
    }

    /** Site for a valid token (counts the visit when $count); throws 404 for unknown, expired or revoked links. */
    public function siteForToken(string $token, bool $count): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new HttpError(404, 'Link ungültig');
        $st = $this->pdo->prepare('SELECT * FROM shares WHERE token_hash = ?');
        $st->execute([hash('sha256', $token)]);
        $sh = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sh) throw new HttpError(404, 'Link ungültig');
        if ($sh['revoked_at'] !== null) throw new HttpError(410, 'Dieser Link wurde gesperrt.');
        if ($sh['expires_at'] !== null && $sh['expires_at'] < self::now()) throw new HttpError(410, 'Dieser Link ist abgelaufen.');
        if ($count) $this->pdo->prepare('UPDATE shares SET access_count = access_count + 1, last_access_at = ? WHERE id = ?')->execute([self::now(), $sh['id']]);
        $site = $this->site($sh['site_id']);
        return ['site' => $site, 'share' => $this->shareOut($sh)];
    }

    /** Photo of the shared site only. */
    public function sharedPhoto(string $siteId, string $photoId): array
    {
        $p = $this->photo($photoId);
        if ($p['site_id'] !== $siteId || $p['deleted_at']) throw new HttpError(404, 'Foto nicht gefunden');
        return $this->file($photoId);
    }

    public function tmpDir(): string
    {
        return $this->dir . '/.tmp';
    }
}
