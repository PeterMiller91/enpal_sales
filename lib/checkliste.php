<?php
declare(strict_types=1);

require_once __DIR__ . '/report.php';

/**
 * Checkliste Beratungstermin als PDF: Seite 1 Berater, Seite 2 Kunde.
 * $data kommt von checkliste/index.html (Texte, Häkchen, Notizen).
 */
final class ChecklistePdf extends FPDF
{
    public string $folder = '';
    public string $meta = '';

    public function Footer(): void
    {
        $this->SetY(-30);
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(82, 96, 92);
        $w = $this->GetPageWidth() - $this->lMargin - $this->rMargin;
        $this->Cell($w * 0.75, 10, pdf_text('Ablage: ' . $this->folder));
        $this->Cell($w * 0.25, 10, pdf_text('Seite ' . $this->PageNo() . ' von {nb}'), 0, 0, 'R');
    }
}

/** Text auf eine Breite umbrechen (FPDF-Kernschrift, bereits CP1252). */
function cl_wrap(FPDF $pdf, string $text, float $w): array
{
    $lines = [];
    foreach (explode("\n", str_replace("\r", '', $text)) as $para) {
        $line = '';
        foreach (preg_split('/ +/', $para) ?: [] as $word) {
            $try = $line === '' ? $word : "$line $word";
            if ($pdf->GetStringWidth($try) <= $w) { $line = $try; continue; }
            if ($line !== '') $lines[] = $line;
            $line = $word;
            while ($pdf->GetStringWidth($line) > $w && strlen($line) > 1) { // sehr langes Wort
                $cut = strlen($line);
                while ($cut > 1 && $pdf->GetStringWidth(substr($line, 0, $cut)) > $w) $cut--;
                $lines[] = substr($line, 0, $cut);
                $line = substr($line, $cut);
            }
        }
        $lines[] = $line;
    }
    return $lines ?: [''];
}

function cl_str($v, int $max = 2000): string
{
    $s = trim(str_replace(['m²', '²'], ['qm', '2'], is_scalar($v) ? (string)$v : ''));
    return mb_substr($s, 0, $max);
}

function build_checkliste(array $data, string $folder, string $outFile): void
{
    $ACCENT = [10, 42, 92]; $YELLOW = [255, 210, 0]; $MUTED = [82, 96, 92];
    $TEXT = [20, 32, 29]; $LINE = [213, 221, 218]; $SOFT = [244, 246, 245]; $MARK = [255, 246, 204];

    $kunde = cl_str($data['kunde'] ?? '', 120);
    $termin = cl_str($data['termin'] ?? '', 20);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $termin, $m)) $termin = "$m[3].$m[2].$m[1]";

    $pdf = new ChecklistePdf('P', 'pt', 'A4');
    $pdf->folder = $folder;
    $pdf->SetMargins(40, 40, 40);
    $pdf->SetAutoPageBreak(false);
    $pdf->AliasNbPages();
    $pdf->SetTitle('Checkliste Beratungstermin ' . $kunde, true);
    $L = 40.0; $W = $pdf->GetPageWidth() - 80; $bottom = $pdf->GetPageHeight() - 48;
    $color = fn(array $c, string $t = 'T') => $t === 'T' ? $pdf->SetTextColor(...$c) : ($t === 'F' ? $pdf->SetFillColor(...$c) : $pdf->SetDrawColor(...$c));

    $page = function (string $title, string $h2, string $lead) use ($pdf, $color, $ACCENT, $YELLOW, $MUTED, $TEXT, $L, $W, $kunde, $termin) {
        $pdf->AddPage();
        $color($ACCENT, 'F'); $pdf->Rect(0, 0, $pdf->GetPageWidth(), 46, 'F');
        $color($YELLOW, 'F'); $pdf->Rect(0, 46, $pdf->GetPageWidth(), 3, 'F');
        $pdf->SetXY($L, 15); $pdf->SetFont('Helvetica', 'B', 13); $color([255, 255, 255]);
        $pdf->Cell($W * 0.55, 16, pdf_text($title));
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->Cell($W * 0.45, 16, pdf_text('Kunde: ' . ($kunde ?: '________________') . '   ·   Termin: ' . ($termin ?: '__________')), 0, 0, 'R');
        $pdf->SetXY($L, 64); $pdf->SetFont('Helvetica', 'B', 13); $color($ACCENT);
        $pdf->Cell($W, 16, pdf_text($h2));
        $pdf->SetXY($L, 82); $pdf->SetFont('Helvetica', '', 8.5); $color($MUTED);
        $pdf->MultiCell($W, 11, pdf_text($lead));
        $pdf->SetY($pdf->GetY() + 4);
        $color($TEXT);
    };

    $checkbox = function (float $x, float $y, bool $on) use ($pdf, $color, $ACCENT) {
        $color($ACCENT, 'D'); $pdf->SetLineWidth(0.8); $pdf->Rect($x, $y, 9, 9);
        if ($on) { $pdf->Line($x + 1.8, $y + 4.8, $x + 3.8, $y + 7.2); $pdf->Line($x + 3.8, $y + 7.2, $x + 7.4, $y + 1.8); }
        $pdf->SetLineWidth(0.4);
    };

    $table = function (array $head, array $rows) use ($pdf, $color, $checkbox, $ACCENT, $MUTED, $TEXT, $LINE, $SOFT, $MARK, $L, $W, $bottom) {
        $wPh = 64; $wChk = 42; $wNote = 128; $wTask = $W - $wPh - $wChk - $wNote; $lh = 10.5;
        $y = $pdf->GetY();
        $color($SOFT, 'F'); $pdf->Rect($L, $y, $W, 15, 'F');
        $pdf->SetFont('Helvetica', 'B', 8); $color($MUTED);
        $x = $L;
        foreach ([[$head[0], $wPh, 'L'], [$head[1], $wTask, 'L'], [$head[2], $wChk, 'C'], [$head[3], $wNote, 'L']] as [$t, $w, $a]) {
            $pdf->SetXY($x + 3, $y + 2); $pdf->Cell($w - 6, 11, pdf_text($t), 0, 0, $a); $x += $w;
        }
        $y += 15;
        foreach ($rows as $r) {
            $pdf->SetFont('Helvetica', '', 8.3);
            $task = cl_wrap($pdf, pdf_text($r['text']), $wTask - 8);
            $note = $r['note'] !== '' ? cl_wrap($pdf, pdf_text($r['note']), $wNote - 8) : [];
            $pdf->SetFont('Helvetica', 'B', 8);
            $ph = cl_wrap($pdf, pdf_text($r['phase']), $wPh - 6);
            $pdf->SetFont('Helvetica', '', 8.3);
            $h = max(count($task), count($note), $r['phase'] !== '' ? count($ph) : 1, 1) * $lh + 6;
            if ($y + $h > $bottom) break; // Seite voll: Rest weglassen statt Layout zu sprengen
            if ($r['phase'] !== '') { $color($ACCENT, 'D'); $pdf->SetLineWidth(1); $pdf->Line($L, $y, $L + $W, $y); $pdf->SetLineWidth(0.4); }
            if ($r['mark']) { $color($MARK, 'F'); $pdf->Rect($L + $wPh + 2, $y + 2, $wTask - 4, $h - 4, 'F'); }
            $pdf->SetFont('Helvetica', 'B', 8); $color($ACCENT);
            foreach ($ph as $i => $ln) { $pdf->SetXY($L + 3, $y + 3 + $i * $lh); $pdf->Cell($wPh - 6, $lh, $ln); }
            $pdf->SetFont('Helvetica', '', 8.3); $color($TEXT);
            foreach ($task as $i => $ln) { $pdf->SetXY($L + $wPh + 4, $y + 3 + $i * $lh); $pdf->Cell($wTask - 8, $lh, $ln); }
            $checkbox($L + $wPh + $wTask + ($wChk - 9) / 2, $y + ($h - 9) / 2, $r['checked']);
            if ($note) {
                $pdf->SetFont('Helvetica', 'I', 8); $color([40, 55, 90]);
                foreach ($note as $i => $ln) { $pdf->SetXY($L + $W - $wNote + 4, $y + 3 + $i * $lh); $pdf->Cell($wNote - 8, $lh, $ln); }
            } else {
                $color($LINE, 'D'); $pdf->Line($L + $W - $wNote + 4, $y + $h - 4, $L + $W - 4, $y + $h - 4);
            }
            $y += $h;
            $color($LINE, 'D'); $pdf->Line($L, $y, $L + $W, $y);
        }
        $pdf->SetY($y + 4);
    };

    $notes = function (string $title, string $text, float $minH) use ($pdf, $color, $ACCENT, $TEXT, $LINE, $L, $W, $bottom) {
        $y = $pdf->GetY() + 6;
        $pdf->SetXY($L, $y); $pdf->SetFont('Helvetica', 'B', 10.5); $color($TEXT); $pdf->Cell($W, 14, pdf_text($title));
        $y += 17;
        $pdf->SetFont('Helvetica', '', 9);
        $lines = $text !== '' ? cl_wrap($pdf, pdf_text($text), $W - 12) : [];
        $h = min(max($minH, count($lines) * 12 + 10), $bottom - $y);
        if ($h < 20) return;
        $color($LINE, 'D'); $pdf->Rect($L, $y, $W, $h);
        if (!$lines) { for ($ly = $y + 17; $ly < $y + $h - 4; $ly += 17) $pdf->Line($L + 6, $ly, $L + $W - 6, $ly); }
        foreach ($lines as $i => $ln) {
            if (($i + 1) * 12 + 6 > $h) break;
            $pdf->SetXY($L + 6, $y + 5 + $i * 12); $pdf->Cell($W - 12, 12, $ln);
        }
        $pdf->SetY($y + $h);
    };

    $rows = function ($list): array {
        $out = [];
        foreach (array_slice(is_array($list) ? $list : [], 0, 40) as $r) {
            if (!is_array($r)) continue;
            $out[] = ['phase' => cl_str($r['phase'] ?? '', 40), 'text' => cl_str($r['text'] ?? '', 300),
                      'note' => cl_str($r['note'] ?? '', 300), 'checked' => !empty($r['checked']), 'mark' => !empty($r['mark'])];
        }
        return $out;
    };

    // ---------------- Seite 1: Berater
    $page('Heizungsberatung - Checkliste Berater', 'Vor, im und nach dem Termin',
          'Von oben nach unten abhaken - dann sitzt jede Zahl im Vollkosten-Rechner.');
    $table(['Phase', 'Aufgabe', 'Erledigt', 'Notiz'], $rows($data['berater'] ?? []));
    $notes('Notizen zum Termin', cl_str($data['notizBerater'] ?? '', 4000), 90);

    // ---------------- Seite 2: Kunde
    $page('Ihre Checkliste für den Beratungstermin', 'Gut vorbereitet in den Termin',
          'Mit diesen Unterlagen rechnen wir mit Ihren echten Zahlen statt mit Durchschnittswerten. Was fehlt, schätzen wir gemeinsam.');
    $table(['Bereich', 'Was wir brauchen', 'Erledigt', 'Ihre Angabe'], $rows($data['kunde_liste'] ?? []));

    $y = $pdf->GetY() + 4;
    $pdf->SetXY($L, $y); $pdf->SetFont('Helvetica', 'B', 10.5); $color($TEXT);
    $pdf->Cell($W, 14, pdf_text('Ihre wichtigsten Punkte für die Entscheidung'));
    $y += 16;
    $pdf->SetFont('Helvetica', 'B', 8); $color($MUTED);
    $wNr = 20; $wChk = 40; $wCol = ($W - $wNr - $wChk) / 2;
    $color($SOFT, 'F'); $pdf->Rect($L, $y, $W, 14, 'F');
    $pdf->SetXY($L + 3, $y + 2); $pdf->Cell($wNr - 3, 10, 'Nr.');
    $pdf->Cell($wCol, 10, pdf_text('Ihr Punkt / Ihre Frage'));
    $pdf->Cell($wCol, 10, pdf_text('Antwort / Lösung (Berater)'));
    $pdf->Cell($wChk - 6, 10, pdf_text('Geklärt'), 0, 0, 'C');
    $y += 16;
    $top = array_slice(is_array($data['top'] ?? null) ? $data['top'] : [], 0, 5);
    for ($i = 0; $i < 5; $i++) {
        $t = is_array($top[$i] ?? null) ? $top[$i] : [];
        $pdf->SetFont('Helvetica', '', 8.3);
        $p = cl_wrap($pdf, pdf_text(cl_str($t['punkt'] ?? '', 400)), $wCol - 12);
        $a = cl_wrap($pdf, pdf_text(cl_str($t['antwort'] ?? '', 400)), $wCol - 12);
        $h = max(30, max(count($p), count($a)) * 10.5 + 8);
        if ($y + $h > $bottom - 60) $h = max(22, $bottom - 60 - $y);
        $pdf->SetFont('Helvetica', 'B', 9); $color($ACCENT);
        $pdf->SetXY($L + 3, $y + 4); $pdf->Cell($wNr - 3, 10, (string)($i + 1));
        $color($LINE, 'D');
        $pdf->Rect($L + $wNr, $y + 1, $wCol - 4, $h - 2); $pdf->Rect($L + $wNr + $wCol, $y + 1, $wCol - 4, $h - 2);
        $pdf->SetFont('Helvetica', '', 8.3); $color($TEXT);
        foreach ($p as $k => $ln) { if (($k + 1) * 10.5 + 4 > $h) break; $pdf->SetXY($L + $wNr + 4, $y + 4 + $k * 10.5); $pdf->Cell($wCol - 12, 10.5, $ln); }
        foreach ($a as $k => $ln) { if (($k + 1) * 10.5 + 4 > $h) break; $pdf->SetXY($L + $wNr + $wCol + 4, $y + 4 + $k * 10.5); $pdf->Cell($wCol - 12, 10.5, $ln); }
        $checkbox($L + $W - $wChk / 2 - 4.5, $y + ($h - 9) / 2, !empty($t['geklaert']));
        $y += $h + 3;
    }
    $pdf->SetY($y);
    $notes('Weitere Notizen', cl_str($data['notizKunde'] ?? '', 3000), 40);

    $pdf->SetXY($L, min($pdf->GetY() + 6, $bottom - 14)); $pdf->SetFont('Helvetica', 'I', 7); $color($MUTED);
    $pdf->MultiCell($W, 9, pdf_text('Alle Berechnungen im Termin sind Modellrechnungen auf Basis Ihrer Angaben und keine Garantie für künftige Kosten. Förderbedingungen können sich ändern.'));

    $tmp = $outFile . '.part';
    $pdf->Output('F', $tmp);
    if (!@rename($tmp, $outFile)) { @unlink($tmp); throw new HttpError(500, 'PDF konnte nicht gespeichert werden'); }
}
