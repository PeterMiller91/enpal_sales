<?php
/*
 * Digitales Fahrtenbuch (SQLite, eigene Datei im Datenordner).
 *
 * Anforderungen des Finanzamts (R 8.1 Abs. 9 LStR, BMF):
 *  - zeitnah, in geschlossener Form, lückenlos, mit Datum, km-Stand Beginn/Ende, Reiseziel, Reisezweck, Geschäftspartner
 *  - nachträgliche Änderungen ausgeschlossen oder in ihrer Art und Weise dokumentiert
 *
 * Umsetzung: Fahrten werden nie überschrieben oder gelöscht. Jede Korrektur ist eine neue Version (mit Grund und
 * Zeitstempel), eine Fahrt kann nur storniert werden. Alle Versionen bilden eine Hash-Kette (SHA-256), mit der sich
 * spätere Eingriffe in die Datenbank erkennen lassen.
 */
declare(strict_types=1);

final class Logbook
{
    private PDO $pdo;
    private string $user;

    public const TYPES = ['business' => 'Dienstlich', 'private' => 'Privat', 'commute' => 'Wohnung–Arbeit'];

    public function __construct(string $dir, string $user)
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) throw new HttpError(500, 'PHP-Erweiterung pdo_sqlite fehlt.');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new HttpError(500, 'Ordner für das Fahrtenbuch kann nicht angelegt werden.');
        if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\n");
        $this->user = $user;
        $this->pdo = new PDO("sqlite:$dir/fahrtenbuch.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA busy_timeout = 10000; PRAGMA journal_mode = WAL;');
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS vehicles (
                user TEXT PRIMARY KEY, plate TEXT, model TEXT, driver TEXT, start_km INTEGER,
                home_address TEXT, work_address TEXT, commute_km REAL, updated_at TEXT
            );
            CREATE TABLE IF NOT EXISTS vehicle_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user TEXT, data TEXT, created_at TEXT
            );
            -- one row per version of a trip; never updated except the "current" flag
            CREATE TABLE IF NOT EXISTS revisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user TEXT NOT NULL, trip_id TEXT NOT NULL, version INTEGER NOT NULL,
                kind TEXT NOT NULL,            -- create | correct | cancel
                data TEXT NOT NULL,            -- the trip as JSON
                reason TEXT,
                recorded_at TEXT NOT NULL,     -- server time of saving
                client_time TEXT,              -- device time when it was entered (offline entries)
                prev_hash TEXT NOT NULL, hash TEXT NOT NULL,
                UNIQUE (trip_id, version)
            );
            CREATE INDEX IF NOT EXISTS rev_user ON revisions(user, id);
        SQL);
    }

    // ---------------------------------------------------------------- vehicle

    public function vehicle(): array
    {
        $st = $this->pdo->prepare('SELECT * FROM vehicles WHERE user = ?');
        $st->execute([$this->user]);
        $v = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'plate' => $v['plate'] ?? '', 'model' => $v['model'] ?? '', 'driver' => $v['driver'] ?? '',
            'startKm' => isset($v['start_km']) ? (int)$v['start_km'] : null,
            'homeAddress' => $v['home_address'] ?? '', 'workAddress' => $v['work_address'] ?? '',
            'commuteKm' => isset($v['commute_km']) ? (float)$v['commute_km'] : null,
        ];
    }

    public function saveVehicle(array $b): array
    {
        $clean = [
            'plate' => self::str($b['plate'] ?? '', 20), 'model' => self::str($b['model'] ?? '', 80), 'driver' => self::str($b['driver'] ?? '', 80),
            'startKm' => self::intOrNull($b['startKm'] ?? null), 'homeAddress' => self::str($b['homeAddress'] ?? '', 200),
            'workAddress' => self::str($b['workAddress'] ?? '', 200), 'commuteKm' => self::numOrNull($b['commuteKm'] ?? null),
        ];
        $now = gmdate('c');
        $st = $this->pdo->prepare('INSERT INTO vehicles (user, plate, model, driver, start_km, home_address, work_address, commute_km, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?) ON CONFLICT(user) DO UPDATE SET plate=excluded.plate, model=excluded.model, driver=excluded.driver,
            start_km=excluded.start_km, home_address=excluded.home_address, work_address=excluded.work_address, commute_km=excluded.commute_km, updated_at=excluded.updated_at');
        $st->execute([$this->user, $clean['plate'], $clean['model'], $clean['driver'], $clean['startKm'], $clean['homeAddress'], $clean['workAddress'], $clean['commuteKm'], $now]);
        $this->pdo->prepare('INSERT INTO vehicle_log (user, data, created_at) VALUES (?,?,?)')->execute([$this->user, json_encode($clean, JSON_UNESCAPED_UNICODE), $now]);
        return $this->vehicle();
    }

    // ---------------------------------------------------------------- trips

    /** Validate and normalise a trip as sent by the app. */
    private function cleanTrip(array $t): array
    {
        $type = (string)($t['type'] ?? '');
        if (!isset(self::TYPES[$type])) throw new HttpError(400, 'Fahrtart fehlt');
        $start = self::intOrNull($t['startKm'] ?? null);
        $end = self::intOrNull($t['endKm'] ?? null);
        if ($start === null || $end === null) throw new HttpError(400, 'km-Stand Beginn und Ende angeben');
        if ($end < $start) throw new HttpError(400, 'km-Stand Ende ist kleiner als Beginn');
        if ($end - $start > 3000) throw new HttpError(400, 'Mehr als 3.000 km in einer Fahrt – bitte km-Stände prüfen');
        $date = (string)($t['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new HttpError(400, 'Datum fehlt');
        $out = [
            'type' => $type,
            'date' => $date,
            'startTime' => preg_match('/^\d{2}:\d{2}$/', (string)($t['startTime'] ?? '')) ? $t['startTime'] : '',
            'endTime' => preg_match('/^\d{2}:\d{2}$/', (string)($t['endTime'] ?? '')) ? $t['endTime'] : '',
            'startKm' => $start,
            'endKm' => $end,
            'from' => self::str($t['from'] ?? '', 200),
            'to' => self::str($t['to'] ?? '', 200),
            'route' => self::str($t['route'] ?? '', 300),
            'purpose' => self::str($t['purpose'] ?? '', 300),
            'partner' => self::str($t['partner'] ?? '', 200),
            'gapNote' => self::str($t['gapNote'] ?? '', 300),
            'source' => in_array($t['source'] ?? '', ['manual', 'route', 'gps'], true) ? $t['source'] : 'manual',
            'gpsKm' => self::numOrNull($t['gpsKm'] ?? null),
            'routeKm' => self::numOrNull($t['routeKm'] ?? null),
        ];
        if ($type === 'business') {
            if ($out['to'] === '') throw new HttpError(400, 'Reiseziel fehlt (Pflicht bei Dienstfahrten)');
            if ($out['purpose'] === '') throw new HttpError(400, 'Reisezweck fehlt (Pflicht bei Dienstfahrten)');
        }
        return $out;
    }

    private function lastHash(): string
    {
        $st = $this->pdo->prepare('SELECT hash FROM revisions WHERE user = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$this->user]);
        return (string)($st->fetchColumn() ?: str_repeat('0', 64));
    }

    private static function hashOf(array $r): string
    {
        return hash('sha256', implode('|', [$r['prev_hash'], $r['user'], $r['trip_id'], $r['version'], $r['kind'], $r['data'], (string)$r['reason'], $r['recorded_at']]));
    }

    private function append(string $tripId, int $version, string $kind, array $data, ?string $reason, ?string $clientTime): void
    {
        $row = [
            'user' => $this->user, 'trip_id' => $tripId, 'version' => $version, 'kind' => $kind,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'reason' => $reason,
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'), 'prev_hash' => $this->lastHash(),
        ];
        $row['hash'] = self::hashOf($row);
        $st = $this->pdo->prepare('INSERT INTO revisions (user, trip_id, version, kind, data, reason, recorded_at, client_time, prev_hash, hash) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$row['user'], $tripId, $version, $kind, $row['data'], $reason, $row['recorded_at'], $clientTime, $row['prev_hash'], $row['hash']]);
    }

    private function tx(callable $fn)
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $r = $fn();
            $this->pdo->exec('COMMIT');
            return $r;
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    public function createTrip(string $tripId, array $t, ?string $clientTime): array
    {
        $data = $this->cleanTrip($t);
        return $this->tx(function () use ($tripId, $data, $clientTime) {
            $st = $this->pdo->prepare('SELECT 1 FROM revisions WHERE trip_id = ? AND user = ?');
            $st->execute([$tripId, $this->user]);
            if ($st->fetchColumn()) return $this->trip($tripId); // already saved (offline retry)
            $this->append($tripId, 1, 'create', $data, null, $clientTime);
            return $this->trip($tripId);
        });
    }

    public function correctTrip(string $tripId, array $t, string $reason): array
    {
        $reason = self::str($reason, 300);
        if ($reason === '') throw new HttpError(400, 'Bitte einen Grund für die Korrektur angeben');
        $data = $this->cleanTrip($t);
        return $this->tx(function () use ($tripId, $data, $reason) {
            $cur = $this->trip($tripId);
            if (!$cur) throw new HttpError(404, 'Fahrt nicht gefunden');
            if ($cur['cancelled']) throw new HttpError(409, 'Stornierte Fahrten können nicht geändert werden');
            $this->append($tripId, $cur['version'] + 1, 'correct', $data, $reason, null);
            return $this->trip($tripId);
        });
    }

    public function cancelTrip(string $tripId, string $reason): array
    {
        $reason = self::str($reason, 300);
        if ($reason === '') throw new HttpError(400, 'Bitte einen Grund für die Stornierung angeben');
        return $this->tx(function () use ($tripId, $reason) {
            $cur = $this->trip($tripId);
            if (!$cur) throw new HttpError(404, 'Fahrt nicht gefunden');
            if ($cur['cancelled']) return $cur;
            $this->append($tripId, $cur['version'] + 1, 'cancel', $cur['data'], $reason, null);
            return $this->trip($tripId);
        });
    }

    /** Current state of one trip, with its full history. */
    public function trip(string $tripId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM revisions WHERE trip_id = ? AND user = ? ORDER BY version');
        $st->execute([$tripId, $this->user]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return $rows ? self::fold($rows) : null;
    }

    /** All trips (current versions), oldest first, with their number (Nr.) in the logbook. */
    public function trips(): array
    {
        $st = $this->pdo->prepare('SELECT * FROM revisions WHERE user = ? ORDER BY id');
        $st->execute([$this->user]);
        $byTrip = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byTrip[$r['trip_id']][] = $r;
        $out = [];
        foreach ($byTrip as $rows) $out[] = self::fold($rows);
        // logbook order: by date, then km
        usort($out, fn($a, $b) => [$a['data']['date'], $a['data']['startKm'], $a['createdAt']] <=> [$b['data']['date'], $b['data']['startKm'], $b['createdAt']]);
        foreach ($out as $i => &$t) $t['no'] = $i + 1;
        return $out;
    }

    private static function fold(array $rows): array
    {
        $first = $rows[0];
        $last = $rows[count($rows) - 1];
        $history = array_map(fn($r) => [
            'version' => (int)$r['version'], 'kind' => $r['kind'], 'reason' => $r['reason'], 'recordedAt' => $r['recorded_at'],
            'data' => json_decode($r['data'], true),
        ], $rows);
        return [
            'id' => $first['trip_id'],
            'version' => (int)$last['version'],
            'data' => json_decode($last['data'], true),
            'cancelled' => $last['kind'] === 'cancel',
            'createdAt' => $first['recorded_at'],
            'clientTime' => $first['client_time'],
            'changed' => count($rows) > 1,
            'history' => $history,
        ];
    }

    /** Recompute the hash chain; returns ['ok' => bool, 'count' => n, 'last' => hash, 'brokenAt' => id|null]. */
    public function verify(): array
    {
        $st = $this->pdo->prepare('SELECT * FROM revisions WHERE user = ? ORDER BY id');
        $st->execute([$this->user]);
        $prev = str_repeat('0', 64);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n++;
            if ($r['prev_hash'] !== $prev || self::hashOf($r) !== $r['hash']) {
                return ['ok' => false, 'count' => $n, 'last' => $r['hash'], 'brokenAt' => (int)$r['id']];
            }
            $prev = $r['hash'];
        }
        return ['ok' => true, 'count' => $n, 'last' => $prev, 'brokenAt' => null];
    }

    // ---------------------------------------------------------------- helpers

    private static function str($v, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string)$v) ?? '');
        return mb_substr($s, 0, $max);
    }

    private static function intOrNull($v): ?int
    {
        if ($v === null || $v === '') return null;
        $s = str_replace(['.', ' '], '', (string)$v);
        return preg_match('/^\d{1,7}$/', $s) ? (int)$s : null;
    }

    private static function numOrNull($v): ?float
    {
        if ($v === null || $v === '') return null;
        $f = (float)str_replace(',', '.', (string)$v);
        return is_finite($f) && $f >= 0 ? round($f, 1) : null;
    }
}
