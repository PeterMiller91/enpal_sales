<?php
/*
 * Datenbank & Synchronisation (SQLite, eine Datei im Datenordner).
 *
 * Geräte schicken keine kompletten Kundendaten mehr, sondern einzelne Änderungen ("Operationen"),
 * z. B. "Breite von Heizkörper X = 120" oder "Foto Y zu Hausseite Ost hinzufügen". Der Server wendet sie
 * der Reihe nach an. Dadurch überschreiben sich Änderungen verschiedener Geräte nicht gegenseitig,
 * und Fotos (Tabelle media) und Daten (Tabelle checks) bleiben auf demselben Stand.
 *
 * Nach jeder Änderung wird check.json im Kundenordner neu geschrieben, damit der FTP-Ordner
 * immer den aktuellen Stand zeigt. In der App entfernte Fotos wandern in den Unterordner _entfernt/.
 */
declare(strict_types=1);

final class OpSkipped extends Exception
{
}

final class Db
{
    private PDO $pdo;
    private Store $store;
    /** @var array<string,bool> */
    private array $exportQueue = [];

    /** Top-level keys of a check document that devices may not set directly. */
    private const RESERVED = ['id', 'files', 'folder', 'rev', 'createdAt', 'updatedAt', 'deletedAt', 'dirty', 'ui', 'serverUpdatedAt'];

    public function __construct(Store $store)
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new HttpError(500, 'PHP-Erweiterung pdo_sqlite fehlt – bitte in Plesk unter PHP-Einstellungen aktivieren.');
        }
        $this->store = $store;
        $dir = $store->path('.db');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new HttpError(500, 'Datenbank-Ordner kann nicht angelegt werden');
        $file = "$dir/baubarkeit.sqlite";
        $fresh = !is_file($file);
        $this->pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA busy_timeout = 10000; PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;');
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS checks (
                id TEXT PRIMARY KEY, folder TEXT, doc TEXT NOT NULL, rev INTEGER NOT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT
            );
            CREATE INDEX IF NOT EXISTS checks_rev ON checks(rev);
            CREATE UNIQUE INDEX IF NOT EXISTS checks_folder ON checks(folder);
            CREATE TABLE IF NOT EXISTS media (
                id TEXT PRIMARY KEY, check_id TEXT NOT NULL, step TEXT, label TEXT, path TEXT, poster TEXT,
                mime TEXT, size INTEGER, taken_at TEXT, lat REAL, lng REAL, accuracy REAL, original_name TEXT,
                uploaded_at TEXT, deleted_at TEXT, rev INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS media_rev ON media(rev);
            CREATE INDEX IF NOT EXISTS media_check ON media(check_id);
            CREATE TABLE IF NOT EXISTS ops (
                op_id TEXT PRIMARY KEY, check_id TEXT NOT NULL, rev INTEGER NOT NULL, user TEXT, device TEXT,
                type TEXT NOT NULL, payload TEXT NOT NULL, created_at TEXT NOT NULL
            );
            INSERT OR IGNORE INTO meta (key, value) VALUES ('rev', '0');
        SQL);
        @chmod($file, 0660);
        if ($fresh) $this->migrateFromJson();
    }

    // ---------------------------------------------------------------- basics

    private function tx(callable $fn)
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $r = $fn();
            $this->pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
        $this->flushExports();
        return $r;
    }

    private function nextRev(): int
    {
        $this->pdo->exec("UPDATE meta SET value = CAST(value AS INTEGER) + 1 WHERE key = 'rev'");
        return (int)$this->pdo->query("SELECT value FROM meta WHERE key = 'rev'")->fetchColumn();
    }

    public function currentRev(): int
    {
        return (int)$this->pdo->query("SELECT value FROM meta WHERE key = 'rev'")->fetchColumn();
    }

    private static function enc($v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Decode a document, keeping JSON objects as objects ({} must not become []). */
    private static function decDoc(string $json): array
    {
        $d = json_decode($json, true);
        return is_array($d) ? $d : [];
    }

    public function getCheck(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM checks WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['doc'] = self::decDoc($row['doc']);
        return $row;
    }

    /** @return array<string,array> media rows of a check, keyed by id (only files that are still in use) */
    public function activeMedia(string $checkId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM media WHERE check_id = ? AND deleted_at IS NULL AND path IS NOT NULL');
        $st->execute([$checkId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) $out[$m['id']] = $m;
        return $out;
    }

    public function media(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM media WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** The check in the shape of the former check.json (document + files map); used by the report and check.json. */
    public function legacyCheck(array $row): array
    {
        $files = [];
        foreach ($this->activeMedia($row['id']) as $m) {
            $files[$m['id']] = [
                'path' => $m['path'], 'poster' => $m['poster'], 'mime' => $m['mime'], 'size' => (int)$m['size'],
                'step' => $m['step'], 'label' => $m['label'], 'takenAt' => $m['taken_at'],
                'lat' => $m['lat'] !== null ? (float)$m['lat'] : null, 'lng' => $m['lng'] !== null ? (float)$m['lng'] : null,
                'accuracy' => $m['accuracy'] !== null ? (float)$m['accuracy'] : null,
                'originalName' => $m['original_name'], 'uploadedAt' => $m['uploaded_at'],
            ];
        }
        return array_merge($row['doc'], [
            'id' => $row['id'],
            'files' => $files ?: new stdClass(),
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
            'deletedAt' => $row['deleted_at'],
        ]);
    }

    // ---------------------------------------------------------------- sync

    /**
     * Apply operations from a device, then return everything that changed since $since.
     * @param array<int,array> $ops
     */
    public function sync(string $user, string $device, array $ops, int $since): array
    {
        $applied = [];
        $skipped = [];
        if ($ops) {
            $this->tx(function () use ($user, $device, $ops, &$applied, &$skipped) {
                foreach ($ops as $op) {
                    $opId = (string)($op['opId'] ?? '');
                    $checkId = (string)($op['checkId'] ?? '');
                    if (!preg_match(ID_RE, $opId) || !preg_match(ID_RE, $checkId)) {
                        $skipped[] = $opId;
                        continue;
                    }
                    $st = $this->pdo->prepare('SELECT 1 FROM ops WHERE op_id = ?');
                    $st->execute([$opId]);
                    if ($st->fetchColumn()) { $applied[] = $opId; continue; } // already applied earlier (retry)
                    try {
                        $this->applyOp($checkId, $op, $user, $device);
                    } catch (OpSkipped $e) {
                        // e.g. the item was removed on another device meanwhile: nothing to do
                    }
                    $applied[] = $opId;
                }
            });
        }
        return ['rev' => $this->currentRev(), 'applied' => $applied, 'skipped' => $skipped] + $this->changesSince($since);
    }

    public function changesSince(int $since): array
    {
        $st = $this->pdo->prepare('SELECT * FROM checks WHERE rev > ? AND folder IS NOT NULL ORDER BY rev');
        $st->execute([$since]);
        $checks = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $checks[] = [
                'id' => $row['id'], 'folder' => $row['folder'], 'rev' => (int)$row['rev'],
                'doc' => self::decDoc($row['doc']) ?: new stdClass(),
                'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at'], 'deletedAt' => $row['deleted_at'],
            ];
        }
        $st = $this->pdo->prepare('SELECT * FROM media WHERE rev > ? ORDER BY rev');
        $st->execute([$since]);
        $media = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $media[] = [
                'id' => $m['id'], 'checkId' => $m['check_id'], 'step' => $m['step'], 'label' => $m['label'],
                'mime' => $m['mime'], 'hasPreview' => str_starts_with((string)$m['mime'], 'image/') || !empty($m['poster']),
                'uploaded' => $m['path'] !== null, 'name' => $m['original_name'],
                'takenAt' => $m['taken_at'], 'lat' => $m['lat'] !== null ? (float)$m['lat'] : null, 'lng' => $m['lng'] !== null ? (float)$m['lng'] : null,
                'deletedAt' => $m['deleted_at'], 'rev' => (int)$m['rev'],
            ];
        }
        return ['checks' => $checks, 'media' => $media];
    }

    private function applyOp(string $checkId, array $op, string $user, string $device): void
    {
        $type = (string)($op['type'] ?? '');
        $row = $this->getCheck($checkId);
        $now = gmdate('c');
        if (!$row) {
            if ($type !== 'merge') throw new OpSkipped('unknown check');
            $row = ['id' => $checkId, 'folder' => null, 'doc' => [], 'created_at' => $now, 'deleted_at' => null, 'new' => true];
        }
        $doc = $row['doc'];
        $removedMedia = [];   // explicitly removed photos (may not be uploaded yet)
        $orphanedMedia = [];  // photos of a removed item
        $path = is_array($op['path'] ?? null) ? $op['path'] : [];
        if ($type !== 'merge' && $type !== 'delete') {
            if (!$path || !is_string($path[0]) || in_array($path[0], self::RESERVED, true)) throw new OpSkipped('bad path');
        }

        switch ($type) {
            case 'merge':
                $in = is_array($op['doc'] ?? null) ? $op['doc'] : [];
                foreach (self::RESERVED as $k) unset($in[$k]);
                $doc = self::deepMerge($doc, $in);
                break;
            case 'set':
                $doc = self::update($doc, $path, fn($old) => $op['value'] ?? null);
                break;
            case 'addItem':
                $item = is_array($op['item'] ?? null) ? $op['item'] : null;
                if (!$item || !isset($item['id'])) throw new OpSkipped('bad item');
                $doc = self::update($doc, $path, function ($list) use ($item) {
                    $list = is_array($list) ? array_values($list) : [];
                    foreach ($list as $e) if (is_array($e) && ($e['id'] ?? null) === $item['id']) return $list;
                    $list[] = $item;
                    return $list;
                });
                break;
            case 'removeItem':
                $itemId = (string)($op['itemId'] ?? '');
                $doc = self::update($doc, $path, function ($list) use ($itemId, &$orphanedMedia) {
                    $out = [];
                    foreach (is_array($list) ? $list : [] as $e) {
                        if (is_array($e) && ($e['id'] ?? null) === $itemId) {
                            if (is_array($e['photos'] ?? null)) {
                                array_walk_recursive($e['photos'], function ($v) use (&$orphanedMedia) { if (is_string($v)) $orphanedMedia[] = $v; });
                            }
                        } else {
                            $out[] = $e;
                        }
                    }
                    return $out;
                });
                break;
            case 'addMedia':
                $mid = (string)($op['mediaId'] ?? '');
                $doc = self::update($doc, $path, function ($list) use ($mid) {
                    $list = is_array($list) ? array_values($list) : [];
                    if (!in_array($mid, $list, true)) $list[] = $mid;
                    return $list;
                });
                break;
            case 'setMedia':
                $mid = (string)($op['mediaId'] ?? '');
                $doc = self::update($doc, $path, function ($old) use ($mid, &$removedMedia) {
                    if (is_string($old) && $old !== '' && $old !== $mid) $removedMedia[] = $old;
                    return $mid;
                });
                break;
            case 'removeMedia':
                $mid = (string)($op['mediaId'] ?? '');
                $doc = self::update($doc, $path, function ($old) use ($mid) {
                    if (is_array($old)) return array_values(array_filter($old, fn($v) => $v !== $mid));
                    return $old === $mid ? null : $old;
                });
                $removedMedia[] = $mid;
                break;
            case 'delete':
                $row['deleted_at'] = $row['deleted_at'] ?? $now;
                break;
            default:
                throw new OpSkipped('unknown op');
        }

        $rev = $this->nextRev();
        $folder = $row['folder'];
        $customer = is_array($doc['customer'] ?? null) ? $doc['customer'] : [];
        // The folder is created when the check is started (name and customer number are complete by then).
        // Customers from older app versions arrive as one "merge" and get their folder right away.
        if ($folder === null && trim((string)($customer['name'] ?? '')) !== '' && (!empty($doc['started']) || $type === 'merge')) {
            $folder = $this->newFolder($customer);
        }
        $docJson = self::enc($doc ?: new stdClass());
        if (!empty($row['new'])) {
            $st = $this->pdo->prepare('INSERT INTO checks (id, folder, doc, rev, created_at, updated_at, deleted_at) VALUES (?,?,?,?,?,?,?)');
            $st->execute([$checkId, $folder, $docJson, $rev, $row['created_at'], $now, $row['deleted_at']]);
        } else {
            $st = $this->pdo->prepare('UPDATE checks SET folder = ?, doc = ?, rev = ?, updated_at = ?, deleted_at = ? WHERE id = ?');
            $st->execute([$folder, $docJson, $rev, $now, $row['deleted_at'], $checkId]);
        }
        $st = $this->pdo->prepare('INSERT INTO ops (op_id, check_id, rev, user, device, type, payload, created_at) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute([(string)$op['opId'], $checkId, $rev, $user, $device, $type, self::enc($op), $now]);

        foreach (array_unique($removedMedia) as $mid) $this->removeMedia($checkId, $mid, $rev, $folder, true);
        foreach (array_unique($orphanedMedia) as $mid) $this->removeMedia($checkId, $mid, $rev, $folder, true);
        if ($folder !== null) $this->exportQueue[$checkId] = true;
    }

    /** Navigate along $path (strings = keys, {"id": x} = list item by id) and replace the value there. */
    private static function update(array $node, array $path, callable $fn): array
    {
        $seg = array_shift($path);
        if (is_array($seg)) {
            $id = $seg['id'] ?? null;
            if (!array_is_list($node)) throw new OpSkipped('not a list');
            foreach ($node as $i => $e) {
                if (is_array($e) && ($e['id'] ?? null) === $id) {
                    $node[$i] = $path ? self::update($e, $path, $fn) : $fn($e);
                    return $node;
                }
            }
            throw new OpSkipped('item gone');
        }
        $key = (string)$seg;
        if (!$path) {
            $node[$key] = $fn($node[$key] ?? null);
            return $node;
        }
        $child = $node[$key] ?? [];
        if (!is_array($child)) $child = [];
        $node[$key] = self::update($child, $path, $fn);
        return $node;
    }

    /** Merge $b into $a: objects key by key, lists of items by id, lists of ids as a union, values from $b win. */
    private static function deepMerge($a, $b)
    {
        if (!is_array($a) || !is_array($b)) return $b;
        if ($b === []) return $a;
        if (array_is_list($b) && ($a === [] || array_is_list($a))) {
            $byId = fn($e) => is_array($e) && isset($e['id']);
            if (array_filter($b, $byId) || array_filter($a, $byId)) {
                $out = $a;
                foreach ($b as $item) {
                    if (!$byId($item)) continue;
                    $found = false;
                    foreach ($out as $i => $e) {
                        if ($byId($e) && $e['id'] === $item['id']) { $out[$i] = self::deepMerge($e, $item); $found = true; break; }
                    }
                    if (!$found) $out[] = $item;
                }
                return array_values($out);
            }
            return array_values(array_unique(array_merge($a, $b), SORT_REGULAR));
        }
        foreach ($b as $k => $v) $a[$k] = array_key_exists($k, $a) ? self::deepMerge($a[$k], $v) : $v;
        return $a;
    }

    private function newFolder(array $customer): string
    {
        $base = folder_name_for($customer);
        $day = date_segment($customer);
        $rel = "$base/$day";
        $st = $this->pdo->prepare('SELECT 1 FROM checks WHERE folder = ?');
        for ($n = 2; ; $n++) {
            $st->execute([$rel]);
            if (!$st->fetchColumn() && !is_file($this->store->path($rel, 'check.json'))) break;
            $rel = "$base/{$day}_$n";
        }
        if (!is_dir($this->store->path($rel)) && !@mkdir($this->store->path($rel), 0775, true)) {
            throw new HttpError(500, 'Kundenordner konnte nicht angelegt werden');
        }
        return $rel;
    }

    /** Mark a file as removed and move it into _entfernt/ so the FTP folder matches the app. */
    private function removeMedia(string $checkId, string $mediaId, int $rev, ?string $folder, bool $tombstone): void
    {
        if (!preg_match(ID_RE, $mediaId)) return;
        $m = $this->media($mediaId);
        $now = gmdate('c');
        if (!$m) {
            if (!$tombstone) return;
            // Removed before its upload finished: leave a marker so the late upload goes straight to _entfernt/.
            $st = $this->pdo->prepare('INSERT INTO media (id, check_id, deleted_at, rev) VALUES (?,?,?,?)');
            $st->execute([$mediaId, $checkId, $now, $rev]);
            return;
        }
        if ($m['check_id'] !== $checkId || $m['deleted_at']) return;
        $path = $m['path'];
        $poster = $m['poster'];
        if ($folder !== null && $path) {
            $path = $this->moveToRemoved($folder, $path);
            if ($poster) $poster = $this->moveToRemoved($folder, $poster);
        }
        $st = $this->pdo->prepare('UPDATE media SET deleted_at = ?, rev = ?, path = ?, poster = ? WHERE id = ?');
        $st->execute([$now, $rev, $path, $poster, $mediaId]);
    }

    private function moveToRemoved(string $folder, string $rel): string
    {
        if (str_starts_with($rel, '_entfernt/')) return $rel;
        $src = $this->store->path($folder, $rel);
        $destRel = '_entfernt/' . basename($rel);
        if (!is_dir($this->store->path($folder, '_entfernt'))) @mkdir($this->store->path($folder, '_entfernt'), 0775, true);
        for ($n = 2; is_file($this->store->path($folder, $destRel)); $n++) {
            $destRel = '_entfernt/' . pathinfo($rel, PATHINFO_FILENAME) . "_$n" . (pathinfo($rel, PATHINFO_EXTENSION) !== '' ? '.' . pathinfo($rel, PATHINFO_EXTENSION) : '');
        }
        if (is_file($src) && @rename($src, $this->store->path($folder, $destRel))) return $destRel;
        return $rel;
    }

    // ---------------------------------------------------------------- uploads

    /** Called when a file upload is complete: $place moves the temp files and returns [path, poster]. */
    public function registerUpload(string $checkId, string $mediaId, string $step, array $body, callable $place): array
    {
        return $this->tx(function () use ($checkId, $mediaId, $step, $body, $place) {
            $row = $this->getCheck($checkId);
            if (!$row || $row['folder'] === null) throw new HttpError(409, 'Kunde noch nicht gespeichert – Upload folgt gleich');
            $existing = $this->media($mediaId);
            if ($existing && $existing['path']) return ['ok' => true, 'path' => $row['folder'] . '/' . $existing['path']]; // retry
            $tombstone = $existing && $existing['deleted_at'];
            [$path, $poster, $size] = $place($row, $tombstone);
            $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
            $num = fn($v) => is_int($v) || is_float($v) ? (float)$v : null;
            $rev = $this->nextRev();
            $st = $this->pdo->prepare('INSERT OR REPLACE INTO media
                (id, check_id, step, label, path, poster, mime, size, taken_at, lat, lng, accuracy, original_name, uploaded_at, deleted_at, rev)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([
                $mediaId, $checkId, $step, (string)($body['label'] ?? ''), $path, $poster, (string)($body['mime'] ?? ''), $size,
                isset($meta['takenAt']) ? (string)$meta['takenAt'] : null, $num($meta['lat'] ?? null), $num($meta['lng'] ?? null),
                $num($meta['accuracy'] ?? null), (string)($body['name'] ?? ''), gmdate('c'), $tombstone ? $existing['deleted_at'] : null, $rev,
            ]);
            $this->exportQueue[$checkId] = true;
            return ['ok' => true, 'path' => $row['folder'] . '/' . $path];
        });
    }

    // ---------------------------------------------------------------- check.json on the FTP

    private function flushExports(): void
    {
        $queue = array_keys($this->exportQueue);
        $this->exportQueue = [];
        foreach ($queue as $id) {
            $row = $this->getCheck($id);
            if (!$row || $row['folder'] === null) continue;
            $data = $this->legacyCheck($row);
            $file = $this->store->path($row['folder'], 'check.json');
            $tmp = $file . '.' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
                @rename($tmp, $file);
            }
        }
    }

    /** List for the old "GET checks" route. */
    public function listChecks(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT * FROM checks WHERE folder IS NOT NULL ORDER BY updated_at DESC') as $row) {
            $doc = self::decDoc($row['doc']);
            $out[] = ['id' => $row['id'], 'folder' => $row['folder'], 'customer' => $doc['customer'] ?? [], 'archived' => !empty($doc['archived']), 'updatedAt' => $row['updated_at'], 'deletedAt' => $row['deleted_at']];
        }
        return $out;
    }

    // ---------------------------------------------------------------- one-time import of the former check.json files

    private function migrateFromJson(): void
    {
        $indexFile = $this->store->path('.index.json');
        if (!is_file($indexFile)) return;
        $index = json_decode((string)file_get_contents($indexFile), true);
        if (!is_array($index)) return;
        $this->tx(function () use ($index) {
            foreach ($index as $id => $rel) {
                if (!preg_match(ID_RE, (string)$id) || !is_string($rel)) continue;
                $f = $this->store->path($rel, 'check.json');
                $data = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
                if (!is_array($data)) continue;
                $files = is_array($data['files'] ?? null) ? $data['files'] : [];
                $doc = $data;
                foreach (self::RESERVED as $k) unset($doc[$k]);
                $rev = $this->nextRev();
                $st = $this->pdo->prepare('INSERT OR IGNORE INTO checks (id, folder, doc, rev, created_at, updated_at, deleted_at) VALUES (?,?,?,?,?,?,?)');
                $st->execute([$id, $rel, self::enc($doc ?: new stdClass()), $rev, $data['createdAt'] ?? gmdate('c'), $data['updatedAt'] ?? gmdate('c'), $data['deletedAt'] ?? null]);
                foreach ($files as $mid => $m) {
                    if (!preg_match(ID_RE, (string)$mid) || !is_array($m)) continue;
                    $st = $this->pdo->prepare('INSERT OR IGNORE INTO media
                        (id, check_id, step, label, path, poster, mime, size, taken_at, lat, lng, accuracy, original_name, uploaded_at, deleted_at, rev)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,?)');
                    $st->execute([$mid, $id, $m['step'] ?? null, $m['label'] ?? '', $m['path'] ?? null, $m['poster'] ?? null, $m['mime'] ?? '',
                        (int)($m['size'] ?? 0), $m['takenAt'] ?? null, $m['lat'] ?? null, $m['lng'] ?? null, $m['accuracy'] ?? null,
                        $m['originalName'] ?? '', $m['uploadedAt'] ?? null, $rev]);
                }
            }
        });
    }
}
