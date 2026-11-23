<?php
/*
 * Fahrtenbuch – Schnittstelle. Aufruf: fahrtenbuch/api.php?p=<route>
 * Anmeldung: dieselbe wie bei der Bau-App (Cookie), jeder Benutzer hat sein eigenes Fahrtenbuch.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/lib/util.php';
require dirname(__DIR__) . '/lib/db.php';
require dirname(__DIR__) . '/lib/fahrtenbuch.php';
require dirname(__DIR__) . '/lib/report.php'; // pdf_text() + FPDF

/** PDF with a header line and page footer on every page (defined before use: PHP does not hoist it). */
final class LogbookPdf extends FPDF
{
    public string $head = '';
    public string $foot = '';

    public function Header(): void
    {
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetTextColor(10, 42, 92);
        $this->Cell(0, 10, pdf_text($this->head), 0, 1);
        $this->SetTextColor(20, 32, 29);
    }

    public function Footer(): void
    {
        $this->SetY(-24);
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(82, 96, 92);
        $w = $this->GetPageWidth() - $this->lMargin - $this->rMargin;
        $this->Cell($w * 0.8, 10, pdf_text($this->foot));
        $this->Cell($w * 0.2, 10, pdf_text('Seite ' . $this->PageNo() . ' von {nb}'), 0, 0, 'R');
    }
}

const ID_RE = '/^[A-Za-z0-9_-]{6,64}$/';
const STEP_IDS = ['heizkoerper', 'zaehler', 'heizraum', 'weg', 'hausseiten', 'unterlagen'];

set_exception_handler(function (Throwable $e) {
    $status = $e instanceof HttpError ? $e->status : 500;
    if ($status >= 500) error_log('[fahrtenbuch] ' . $e);
    json_out(['error' => $status >= 500 && !($e instanceof HttpError) ? 'Serverfehler' : $e->getMessage()], $status);
});

$config = load_config();
$user = current_user($config);
if (!$user) throw new HttpError(401, 'Anmeldung erforderlich');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = trim((string)($_GET['p'] ?? ''), '/');
$parts = $route === '' ? [] : explode('/', $route);

// Data lives next to the customer folders (daten/fahrtenbuch), blocked for web visitors.
$book = new Logbook(dirname(rtrim($config['data_dir'], '/')) . '/fahrtenbuch', $user);

if ($route === 'state' && $method === 'GET') {
    json_out(['user' => $user, 'vehicle' => $book->vehicle(), 'trips' => $book->trips(), 'verify' => $book->verify()]);
}

if ($route === 'vehicle' && $method === 'POST') {
    json_out(['vehicle' => $book->saveVehicle(json_body())]);
}

// Suggestions for business partners / destinations: customers from the Baubarkeits-Check
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

if (($parts[0] ?? '') === 'trips') {
    $id = $parts[1] ?? '';
    if ($id === '' && $method === 'POST') {
        $b = json_body();
        $tid = (string)($b['id'] ?? '');
        if (!preg_match(ID_RE, $tid)) throw new HttpError(400, 'Ungültige ID');
        json_out($book->createTrip($tid, is_array($b['trip'] ?? null) ? $b['trip'] : [], isset($b['clientTime']) ? substr((string)$b['clientTime'], 0, 40) : null));
    }
    if (!preg_match(ID_RE, $id)) throw new HttpError(400, 'Ungültige ID');
    $action = $parts[2] ?? '';
    if ($action === 'correct' && $method === 'POST') {
        $b = json_body();
        json_out($book->correctTrip($id, is_array($b['trip'] ?? null) ? $b['trip'] : [], (string)($b['reason'] ?? '')));
    }
    if ($action === 'cancel' && $method === 'POST') {
        $b = json_body();
        json_out($book->cancelTrip($id, (string)($b['reason'] ?? '')));
    }
}

if (in_array($route, ['export.pdf', 'export.csv'], true) && $method === 'GET') {
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : '0000-00-00';
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : '9999-12-31';
    $trips = array_values(array_filter($book->trips(), fn($t) => $t['data']['date'] >= $from && $t['data']['date'] <= $to));
    $vehicle = $book->vehicle();
    $verify = $book->verify();
    $name = 'Fahrtenbuch_' . safe_segment($vehicle['plate'] ?: $user, 'Fahrzeug') . '_' . ($from === '0000-00-00' ? 'gesamt' : $from . '_bis_' . $to);
    if ($route === 'export.csv') export_csv($trips, $name);
    export_pdf($trips, $vehicle, $verify, $from, $to, $name, $user);
}

throw new HttpError(404, 'Unbekannte Route');

// ---------------------------------------------------------------- export

function fmt_date(string $iso): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) ? "$m[3].$m[2].$m[1]" : $iso;
}

function fmt_ts(?string $iso): string
{
    if (!$iso || ($t = strtotime($iso)) === false) return '';
    return (new DateTime('@' . $t))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i');
}

/** Short note shown in the "Hinweise" column. */
function trip_notes(array $t): string
{
    $n = [];
    if ($t['cancelled']) $n[] = 'STORNIERT';
    if ($t['changed']) $n[] = 'geändert (v' . $t['version'] . ')';
    $days = (strtotime(substr($t['createdAt'], 0, 10)) - strtotime($t['data']['date'])) / 86400;
    if ($days > 7) $n[] = 'erfasst ' . fmt_ts($t['createdAt']);
    if ($t['data']['gapNote'] !== '') $n[] = 'Lücke: ' . $t['data']['gapNote'];
    return implode('; ', $n);
}

function export_csv(array $trips, string $name): void
{
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name . '.csv'));
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // Excel: UTF-8
    fputcsv($out, ['Nr', 'Datum', 'Beginn', 'Ende', 'km-Stand Beginn', 'km-Stand Ende', 'km', 'Art', 'Start', 'Ziel', 'Route', 'Zweck', 'Geschäftspartner', 'Version', 'Status', 'Erfasst am', 'Hinweise'], ';');
    foreach ($trips as $t) {
        $d = $t['data'];
        fputcsv($out, [
            $t['no'], fmt_date($d['date']), $d['startTime'], $d['endTime'], $d['startKm'], $d['endKm'], $d['endKm'] - $d['startKm'],
            Logbook::TYPES[$d['type']] ?? $d['type'], $d['from'], $d['to'], $d['route'], $d['purpose'], $d['partner'],
            $t['version'], $t['cancelled'] ? 'storniert' : 'gültig', fmt_ts($t['createdAt']), trip_notes($t),
        ], ';');
    }
    fclose($out);
    exit;
}

function export_pdf(array $trips, array $vehicle, array $verify, string $from, string $to, string $name, string $user): void
{
    $pdf = new LogbookPdf('L', 'pt', 'A4');
    $pdf->SetMargins(30, 28, 30);
    $pdf->SetAutoPageBreak(true, 34);
    $pdf->AliasNbPages();
    $period = $from === '0000-00-00' ? 'gesamter Zeitraum' : fmt_date($from) . ' bis ' . fmt_date($to);
    $pdf->head = 'Fahrtenbuch ' . ($vehicle['plate'] ?: '') . ' · ' . $period;
    $pdf->foot = 'Erstellt ' . fmt_ts(gmdate('c')) . ' · Prüfkette ' . ($verify['ok'] ? 'unverändert' : 'BESCHÄDIGT') . ' · Prüfsumme ' . substr($verify['last'], 0, 16) . '… (' . $verify['count'] . ' Einträge)';
    $pdf->SetTitle($name, true);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 60;

    // header block
    $pdf->SetFont('Helvetica', 'B', 18);
    $pdf->Cell(0, 22, pdf_text('Fahrtenbuch'), 0, 1);
    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->SetTextColor(82, 96, 92);
    $info = [
        'Fahrzeug: ' . trim(($vehicle['model'] ?: '–') . ' · Kennzeichen ' . ($vehicle['plate'] ?: '–')),
        'Fahrer: ' . ($vehicle['driver'] ?: $user) . ' · Zeitraum: ' . $period,
        'Wohnung: ' . ($vehicle['homeAddress'] ?: '–') . ' · Erste Tätigkeitsstätte: ' . ($vehicle['workAddress'] ?: '–') . ($vehicle['commuteKm'] ? ' (' . str_replace('.', ',', (string)$vehicle['commuteKm']) . ' km einfach)' : ''),
    ];
    foreach ($info as $l) $pdf->Cell(0, 12, pdf_text($l), 0, 1);
    $pdf->SetTextColor(20, 32, 29);
    $pdf->Ln(4);

    // summary
    $sum = ['business' => 0, 'private' => 0, 'commute' => 0];
    foreach ($trips as $t) if (!$t['cancelled']) $sum[$t['data']['type']] += $t['data']['endKm'] - $t['data']['startKm'];
    $total = array_sum($sum);
    $pct = fn($v) => $total ? str_replace('.', ',', (string)round($v / $total * 100, 1)) . ' %' : '–';
    $pdf->SetFillColor(233, 238, 247);
    $pdf->SetFont('Helvetica', 'B', 9.5);
    $cells = [
        'Gesamt: ' . number_format($total, 0, ',', '.') . ' km',
        'Dienstlich: ' . number_format($sum['business'], 0, ',', '.') . ' km (' . $pct($sum['business']) . ')',
        'Privat: ' . number_format($sum['private'], 0, ',', '.') . ' km (' . $pct($sum['private']) . ')',
        'Wohnung–Arbeit: ' . number_format($sum['commute'], 0, ',', '.') . ' km (' . $pct($sum['commute']) . ')',
    ];
    foreach ($cells as $c) $pdf->Cell($W / 4, 20, pdf_text($c), 0, 0, 'C', true);
    $pdf->Ln(28);

    // table
    $cols = [['Nr', 24], ['Datum', 52], ['Zeit', 52], ['km Beginn', 52], ['km Ende', 52], ['km', 34], ['Art', 58], ['Start → Ziel / Route', 170], ['Zweck', 120], ['Geschäftspartner', 100], ['Hinweise', 0]];
    $used = array_sum(array_column($cols, 1));
    $cols[count($cols) - 1][1] = $W - $used;
    $header = function () use ($pdf, $cols) {
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(10, 42, 92);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($cols as [$t, $w]) $pdf->Cell($w, 16, pdf_text($t), 0, 0, 'L', true);
        $pdf->Ln();
        $pdf->SetTextColor(20, 32, 29);
        $pdf->SetFont('Helvetica', '', 7.5);
    };
    $header();
    foreach ($trips as $i => $t) {
        $d = $t['data'];
        $vals = [
            (string)$t['no'], fmt_date($d['date']), trim($d['startTime'] . ($d['endTime'] ? '–' . $d['endTime'] : '')),
            number_format($d['startKm'], 0, ',', '.'), number_format($d['endKm'], 0, ',', '.'), (string)($d['endKm'] - $d['startKm']),
            Logbook::TYPES[$d['type']] ?? $d['type'],
            trim(($d['from'] !== '' ? $d['from'] . ' → ' : '') . $d['to'] . ($d['route'] !== '' ? ' (über ' . $d['route'] . ')' : '')),
            $d['purpose'], $d['partner'], trip_notes($t),
        ];
        // row height from the longest wrapped cell
        $lines = 1;
        foreach ($cols as $k => [$_, $w]) $lines = max($lines, (int)ceil($pdf->GetStringWidth(pdf_text($vals[$k])) / max(1, $w - 6)));
        $h = 10 * $lines + 4;
        if ($pdf->GetY() + $h > $pdf->GetPageHeight() - 40) { $pdf->AddPage(); $header(); }
        $y = $pdf->GetY();
        $x = 30;
        if ($i % 2) { $pdf->SetFillColor(246, 248, 251); $pdf->Rect($x, $y, $W, $h, 'F'); }
        if ($t['cancelled']) $pdf->SetTextColor(150, 150, 150);
        foreach ($cols as $k => [$_, $w]) {
            $pdf->SetXY($x + 2, $y + 2);
            $pdf->MultiCell($w - 4, 10, pdf_text($vals[$k]), 0, 'L');
            $x += $w;
        }
        if ($t['cancelled']) {
            $pdf->SetDrawColor(150, 150, 150);
            $pdf->Line(30, $y + $h / 2, 30 + $W - $cols[count($cols) - 1][1], $y + $h / 2);
            $pdf->SetTextColor(20, 32, 29);
        }
        $pdf->SetDrawColor(213, 221, 218);
        $pdf->Line(30, $y + $h, 30 + $W, $y + $h);
        $pdf->SetXY(30, $y + $h);
    }
    if (!$trips) { $pdf->Ln(6); $pdf->Cell(0, 14, pdf_text('Keine Fahrten im Zeitraum.'), 0, 1); }

    // change log
    $changed = array_values(array_filter($trips, fn($t) => $t['changed']));
    if ($changed) {
        $pdf->Ln(14);
        if ($pdf->GetY() > $pdf->GetPageHeight() - 120) $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(0, 16, pdf_text('Änderungsprotokoll'), 0, 1);
        $pdf->SetFont('Helvetica', '', 8);
        foreach ($changed as $t) {
            foreach ($t['history'] as $h) {
                $d = $h['data'];
                $what = $h['kind'] === 'create' ? 'erfasst' : ($h['kind'] === 'cancel' ? 'storniert' : 'korrigiert');
                $line = 'Nr. ' . $t['no'] . ' · Version ' . $h['version'] . ' ' . $what . ' am ' . fmt_ts($h['recordedAt'])
                    . ($h['reason'] ? ' · Grund: ' . $h['reason'] : '')
                    . ' · ' . fmt_date($d['date']) . ', ' . $d['startKm'] . '–' . $d['endKm'] . ' km, ' . (Logbook::TYPES[$d['type']] ?? '') . ', ' . $d['to'] . ($d['purpose'] ? ', ' . $d['purpose'] : '');
                $pdf->MultiCell(0, 11, pdf_text($line), 0, 'L');
            }
            $pdf->Ln(3);
        }
    }

    $pdf->Ln(10);
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(82, 96, 92);
    $pdf->MultiCell(0, 10, pdf_text('Hinweis: Einträge werden nicht überschrieben oder gelöscht. Korrekturen und Stornierungen werden als neue Version mit Grund und Zeitpunkt gespeichert; alle Versionen sind über eine SHA-256-Prüfkette verbunden. "Erfasst am" zeigt den Zeitpunkt der Speicherung. Prüfkette: ' . ($verify['ok'] ? 'unverändert' : 'BESCHÄDIGT ab Eintrag ' . $verify['brokenAt']) . ', Prüfsumme ' . $verify['last'] . '.'), 0, 'L');

    $file = tempnam(sys_get_temp_dir(), 'fb');
    $pdf->Output('F', $file);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($file));
    header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($name . '.pdf'));
    readfile($file);
    @unlink($file);
    exit;
}
