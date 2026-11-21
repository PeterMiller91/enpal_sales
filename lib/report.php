<?php
declare(strict_types=1);

require_once __DIR__ . '/fpdf/fpdf.php';

/** FPDF core fonts use Windows-1252: convert UTF-8 (→ becomes ->). */
function pdf_text($s): string
{
    $s = str_replace(['→', '–'], ['->', '-'], (string)$s);
    $t = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
    return $t === false ? (string)$s : $t;
}

final class ReportPdf extends FPDF
{
    public string $folder = '';

    public function Footer(): void
    {
        $this->SetY(-34);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(82, 96, 92);
        $w = $this->GetPageWidth() - $this->lMargin - $this->rMargin;
        $this->Cell($w * 0.7, 10, pdf_text('Ablage: ' . $this->folder));
        $this->Cell($w * 0.3, 10, pdf_text('Seite ' . $this->PageNo() . ' von {nb}'), 0, 0, 'R');
    }
}

/**
 * Build the PDF report for one check and save it to $outFile.
 * @param callable(string):string $resolve maps a file path relative to the customer folder to an absolute path
 */
function build_report(array $check, string $folder, callable $resolve, array $config, string $outFile): void
{
    $ACCENT = [10, 42, 92];
    $MUTED = [82, 96, 92];
    $TEXT = [20, 32, 29];
    $LINE = [213, 221, 218];
    $SOFT = [233, 238, 247];
    $PH = [227, 232, 230];

    $files = (array)($check['files'] ?? []);
    $steps = (array)($check['steps'] ?? []);
    $c = (array)($check['customer'] ?? []);
    $minW = (float)($config['min_width_cm'] ?? 0);
    $tmpImages = [];

    $pdf = new ReportPdf('P', 'pt', 'A4');
    $pdf->folder = $folder;
    $pdf->SetMargins(50, 50, 50);
    $pdf->SetAutoPageBreak(true, 56);
    $pdf->AliasNbPages();
    $pdf->SetTitle('Baubarkeits-Check Bericht ' . ($c['name'] ?? ''), true);
    $pdf->AddPage();

    $L = 50.0;
    $W = $pdf->GetPageWidth() - 100;
    $bottom = fn() => $pdf->GetPageHeight() - 60;
    $ensure = function (float $h) use ($pdf, $bottom) {
        if ($pdf->GetY() + $h > $bottom()) $pdf->AddPage();
    };
    $color = fn(array $rgb) => $pdf->SetTextColor(...$rgb);

    $fmtDateTime = function ($iso): string {
        if (!$iso) return '';
        $t = strtotime((string)$iso);
        if ($t === false) return '';
        $d = new DateTime('@' . $t);
        $d->setTimezone(new DateTimeZone(date_default_timezone_get() === 'UTC' ? 'Europe/Berlin' : date_default_timezone_get()));
        return $d->format('d.m.Y H:i');
    };
    $fmtNum = function ($v, string $unit): string {
        if ($v === null || $v === '') return '-';
        return str_replace('.', ',', (string)$v) . " $unit";
    };
    $ids = fn($a) => is_array($a) ? array_values(array_filter($a, fn($x) => is_string($x) && $x !== '')) : [];
    $isImage = fn($f) => $f && (preg_match('#^image/(jpeg|png)$#', (string)($f['mime'] ?? '')) || preg_match('/\.(jpe?g|png)$/i', (string)($f['path'] ?? '')));
    $isVideo = fn($f) => $f && str_starts_with((string)($f['mime'] ?? ''), 'video/');

    /** Downscale big photos so the PDF stays small (needs GD; otherwise the original is embedded). */
    $prepImage = function (string $path) use (&$tmpImages): ?string {
        if (!is_file($path)) return null;
        $info = @getimagesize($path);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) return null;
        if (!function_exists('imagecreatetruecolor') || max($info[0], $info[1]) <= 1400) return $path;
        $src = $info[2] === IMAGETYPE_JPEG ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);
        if (!$src) return $path;
        $scale = 1400 / max($info[0], $info[1]);
        $w = max(1, (int)round($info[0] * $scale));
        $h = max(1, (int)round($info[1] * $scale));
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
        $base = tempnam(sys_get_temp_dir(), 'bbc');
        $tmp = $base . '.jpg';
        $tmpImages[] = $base;
        imagejpeg($dst, $tmp, 80);
        imagedestroy($src);
        imagedestroy($dst);
        $tmpImages[] = $tmp;
        return $tmp;
    };

    $heading = function (string $text) use ($pdf, $ensure, $color, $ACCENT, $TEXT, $LINE, $L, $W) {
        $ensure(70);
        $pdf->Ln(10);
        $pdf->SetFont('Helvetica', 'B', 14);
        $color($ACCENT);
        $pdf->SetX($L);
        $pdf->Cell($W, 18, pdf_text($text), 0, 1);
        $pdf->SetDrawColor(...$LINE);
        $pdf->SetLineWidth(0.5);
        $pdf->Line($L, $pdf->GetY() + 2, $L + $W, $pdf->GetY() + 2);
        $pdf->Ln(10);
        $color($TEXT);
    };
    $sub = function (string $text) use ($pdf, $ensure, $color, $TEXT, $L, $W) {
        $ensure(40);
        $pdf->SetFont('Helvetica', 'B', 11);
        $color($TEXT);
        $pdf->SetX($L);
        $pdf->Cell($W, 16, pdf_text($text), 0, 1);
    };
    $para = function ($text, ?array $rgb = null) use ($pdf, $color, $TEXT, $L, $W) {
        if ($text === null || trim((string)$text) === '') return;
        $pdf->SetFont('Helvetica', '', 10);
        $color($rgb ?? $TEXT);
        $pdf->SetX($L);
        $pdf->MultiCell($W, 13, pdf_text($text), 0, 'L');
        $pdf->Ln(4);
        $color($TEXT);
    };
    $table = function (array $headers, array $rows, array $widths) use ($pdf, $ensure, $color, $TEXT, $LINE, $SOFT, $L, $W) {
        $rowH = 18;
        $draw = function (array $cells, bool $head) use ($pdf, $ensure, $color, $TEXT, $LINE, $SOFT, $L, $W, $widths, $rowH) {
            $ensure($rowH + 4);
            $y = $pdf->GetY();
            if ($head) {
                $pdf->SetFillColor(...$SOFT);
                $pdf->Rect($L, $y, $W, $rowH, 'F');
            }
            $pdf->SetFont('Helvetica', $head ? 'B' : '', 9.5);
            $color($TEXT);
            $x = $L;
            foreach ($cells as $i => $cell) {
                $cw = $widths[$i] * $W;
                $txt = pdf_text((string)$cell);
                while ($txt !== '' && $pdf->GetStringWidth($txt) > $cw - 10) $txt = substr($txt, 0, -2) . '.';
                $pdf->SetXY($x + 5, $y);
                $pdf->Cell($cw - 10, $rowH, $txt);
                $x += $cw;
            }
            $pdf->SetDrawColor(...$LINE);
            $pdf->SetLineWidth(0.5);
            $pdf->Line($L, $y + $rowH, $L + $W, $y + $rowH);
            $pdf->SetXY($L, $y + $rowH);
        };
        $draw($headers, true);
        foreach ($rows as $r) $draw($r, false);
        $pdf->Ln(8);
    };
    $caption = function ($f, string $label) use ($fmtDateTime): string {
        $parts = [$label];
        if ($f && !empty($f['takenAt'])) $parts[] = $fmtDateTime($f['takenAt']);
        if ($f && is_numeric($f['lat'] ?? null) && is_numeric($f['lng'] ?? null)) {
            $parts[] = sprintf('GPS %.5f, %.5f', $f['lat'], $f['lng']);
        }
        return implode(' · ', array_filter($parts));
    };

    /** @param array<int,array{0:?string,1:string}> $items [fileId, label] */
    $gallery = function (array $items, int $cols) use ($pdf, $ensure, $para, $color, $caption, $prepImage, $resolve, $files, $isImage, $isVideo, $L, $W, $MUTED, $TEXT, $PH) {
        $items = array_values(array_filter($items, fn($it) => !empty($it[0])));
        if (!$items) {
            $para('Keine Aufnahmen vorhanden.', $MUTED);
            return;
        }
        $gap = 10;
        $cw = ($W - $gap * ($cols - 1)) / $cols;
        $ih = $cw * 0.75;
        $capH = 24;
        foreach (array_chunk($items, $cols) as $row) {
            $ensure($ih + $capH + 6);
            $y = $pdf->GetY();
            foreach ($row as $j => [$fid, $label]) {
                $x = $L + $j * ($cw + $gap);
                $f = $files[$fid] ?? null;
                $src = null;
                if ($f) {
                    if ($isImage($f)) $src = $prepImage($resolve($f['path']));
                    elseif (!empty($f['poster'])) $src = $prepImage($resolve($f['poster']));
                }
                $pdf->SetFillColor(...$PH);
                $pdf->Rect($x, $y, $cw, $ih, 'F');
                $drawn = false;
                if ($src) {
                    $info = @getimagesize($src);
                    if ($info) {
                        $s = min($cw / $info[0], $ih / $info[1]);
                        $w = $info[0] * $s;
                        $h = $info[1] * $s;
                        try {
                            $pdf->Image($src, $x + ($cw - $w) / 2, $y + ($ih - $h) / 2, $w, $h);
                            $drawn = true;
                        } catch (Throwable $e) {
                            $drawn = false;
                        }
                    }
                }
                if (!$drawn) {
                    $msg = !$f ? 'Noch nicht hochgeladen' : (($isVideo($f) ? 'Video: ' : 'Datei: ') . $f['path']);
                    $pdf->SetFont('Helvetica', '', 8);
                    $color($MUTED);
                    $pdf->SetXY($x + 6, $y + $ih / 2 - 10);
                    $pdf->MultiCell($cw - 12, 10, pdf_text($msg), 0, 'C');
                } elseif ($isVideo($f)) {
                    $pdf->SetFillColor(20, 32, 29);
                    $pdf->Rect($x, $y + $ih - 14, 34, 14, 'F');
                    $pdf->SetFont('Helvetica', 'B', 7);
                    $pdf->SetTextColor(255, 255, 255);
                    $pdf->SetXY($x + 3, $y + $ih - 14);
                    $pdf->Cell(30, 14, 'VIDEO');
                }
                $pdf->SetFont('Helvetica', '', 7.5);
                $color($MUTED);
                $pdf->SetXY($x, $y + $ih + 3);
                $pdf->MultiCell($cw, 9, pdf_text($caption($f, $label)), 0, 'L');
            }
            $color($TEXT);
            $pdf->SetXY($L, $y + $ih + $capH + 4);
        }
        $pdf->Ln(4);
    };

    // ---------- header ----------
    $logoW = 0;
    $logo = (string)($config['logo_path'] ?? '');
    if ($logo !== '' && is_file($logo) && ($info = @getimagesize($logo))) {
        $s = min(120 / $info[0], 50 / $info[1]);
        try {
            $pdf->Image($logo, $L + $W - $info[0] * $s, 40, $info[0] * $s, $info[1] * $s);
            $logoW = 130;
        } catch (Throwable $e) {
            $logoW = 0;
        }
    }
    $pdf->SetXY($L, 50);
    $pdf->SetFont('Helvetica', 'B', 9);
    $color($ACCENT);
    $pdf->Cell($W - $logoW, 12, pdf_text('BAUBARKEITS-CHECK  ·  BERICHT'), 0, 1);
    $pdf->SetFont('Helvetica', 'B', 22);
    $color($TEXT);
    $pdf->MultiCell($W - $logoW, 26, pdf_text($c['name'] ?? 'Kunde'));
    $pdf->SetFont('Helvetica', '', 10);
    $color($MUTED);
    $lines = array_filter([
        $c['address'] ?? '',
        implode(' · ', array_filter([!empty($c['number']) ? 'Kunden-Nr. ' . $c['number'] : '', $c['phone'] ?? '', $c['email'] ?? ''])),
        implode(' · ', array_filter([!empty($c['date']) ? 'Termin: ' . (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$c['date'], $dm) ? "$dm[3].$dm[2].$dm[1]" : $c['date']) : '', !empty($c['advisor']) ? 'Berater: ' . $c['advisor'] : '', $config['company_name'] ?? ''])),
    ]);
    foreach ($lines as $l) $pdf->Cell($W, 13, pdf_text($l), 0, 1);
    $pdf->Ln(6);
    $pdf->SetDrawColor(255, 210, 0);
    $pdf->SetLineWidth(2.5);
    $pdf->Line($L, $pdf->GetY(), $L + $W, $pdf->GetY());
    $pdf->Ln(4);
    $color($TEXT);

    // ---------- 1 Heizkörper ----------
    $hk = (array)($steps['heizkoerper']['items'] ?? []);
    $heading('1 · Heizkörper (' . count($hk) . ')');
    if ($hk) {
        $rows = [];
        $imgs = [];
        foreach ($hk as $i => $it) {
            $dims = array_map(fn($v) => ($v === null || $v === '') ? '-' : $v, [$it['width'] ?? '', $it['height'] ?? '', $it['depth'] ?? '']);
            $g = $it['photos']['gesamt'] ?? null;
            $h = $it['photos']['hahnblock'] ?? null;
            $rows[] = [$i + 1, ($it['room'] ?? '') ?: '-', implode(' × ', $dims), ($it['type'] ?? '') ?: '-', count(array_filter([$g, $h])) . '/2'];
            $name = 'HK ' . ($i + 1) . (!empty($it['room']) ? ' ' . $it['room'] : '');
            $imgs[] = [$g, "$name - Gesamt"];
            $imgs[] = [$h, "$name - Hahnblock"];
        }
        $table(['#', 'Raum', 'B × H × T (cm)', 'Typ', 'Fotos'], $rows, [0.06, 0.34, 0.26, 0.16, 0.18]);
        $gallery($imgs, 4);
        foreach ($hk as $i => $it) if (!empty($it['note'])) $para('HK ' . ($i + 1) . ': ' . $it['note'], $MUTED);
    } else {
        $para('Keine Heizkörper erfasst.', $MUTED);
    }

    // ---------- 2 Zählerschrank ----------
    $z = (array)($steps['zaehler'] ?? []);
    $heading('2 · Zählerschrank (geöffnet, + 20 cm Umfeld)');
    $gallery(array_map(fn($id, $i) => [$id, 'Zählerschrank ' . ($i + 1)], $ids($z['photos'] ?? []), array_keys($ids($z['photos'] ?? []))), 3);
    $para($z['note'] ?? '');

    // ---------- 3 Heizraum ----------
    $hr = (array)($steps['heizraum'] ?? []);
    $heading('3 · Heizraum 360°-Video');
    $items = [];
    foreach ($ids($hr['videos'] ?? []) as $i => $id) $items[] = [$id, '360°-Video ' . ($i + 1)];
    foreach ($ids($hr['photos'] ?? []) as $i => $id) $items[] = [$id, 'Heizraum Foto ' . ($i + 1)];
    $gallery($items, 3);
    if (!empty($hr['length']) || !empty($hr['width']) || !empty($hr['heightCm'])) {
        $para('Raummaße: L ' . $fmtNum($hr['length'] ?? '', 'm') . ' · B ' . $fmtNum($hr['width'] ?? '', 'm') . ' · H ' . $fmtNum($hr['heightCm'] ?? '', 'cm'));
    }
    $para($hr['note'] ?? '');

    // ---------- 4 Weg ----------
    $weg = (array)($steps['weg'] ?? []);
    $eng = (array)($weg['items'] ?? []);
    $heading('4 · Weg vom Heizraum bis zur Haustür');
    $items = [];
    foreach ($ids($weg['videos'] ?? []) as $i => $id) $items[] = [$id, 'Weg-Video ' . ($i + 1)];
    $gallery($items, 3);
    if ($eng) {
        $sub('Engstellen');
        $widths = [];
        $rows = [];
        $imgs = [];
        foreach ($eng as $e) {
            $n = (float)str_replace(',', '.', (string)($e['widthCm'] ?? ''));
            if ($n > 0) $widths[] = $n;
            $rating = '-';
            if ($minW > 0 && $n > 0) $rating = $n >= $minW ? 'ok' : 'zu eng (min. ' . $fmtNum($minW, 'cm') . ')';
            $rows[] = [($e['label'] ?? '') ?: '-', $fmtNum($e['widthCm'] ?? '', 'cm'), $rating];
            foreach ($ids($e['photos'] ?? []) as $id) $imgs[] = [$id, (($e['label'] ?? '') ?: 'Engstelle') . ' - ' . $fmtNum($e['widthCm'] ?? '', 'cm')];
        }
        $table(['Engstelle', 'Breite', 'Bewertung'], $rows, [0.45, 0.2, 0.35]);
        if ($widths) $para('Engste Stelle: ' . $fmtNum(min($widths), 'cm') . ($minW > 0 ? ' (benötigt: ' . $fmtNum($minW, 'cm') . ')' : ''));
        $gallery($imgs, 4);
    } else {
        $para('Keine Engstellen erfasst.', $MUTED);
    }
    $para($weg['note'] ?? '');

    // ---------- 5 Hausseiten ----------
    $sides = (array)($steps['hausseiten']['sides'] ?? []);
    $names = ['nord' => 'Nord', 'ost' => 'Ost', 'sued' => 'Süd', 'west' => 'West'];
    $heading('5 · Hausseiten & Abstände');
    $rows = [];
    $imgs = [];
    foreach ($names as $k => $name) {
        $s = (array)($sides[$k] ?? []);
        $rows[] = [$name, $fmtNum($s['fensterM'] ?? '', 'm'), $fmtNum($s['grenzeM'] ?? '', 'm'), $s['note'] ?? ''];
        foreach ($ids($s['photos'] ?? []) as $i => $id) $imgs[] = [$id, "{$name}seite " . ($i + 1)];
    }
    $table(['Seite', 'Abstand Nachbarfenster', 'Abstand Grenze', 'Notiz'], $rows, [0.14, 0.26, 0.2, 0.4]);
    $gallery($imgs, 4);

    // ---------- 6 Unterlagen ----------
    $u = (array)($steps['unterlagen'] ?? []);
    $heading('6 · Bauplan & Rechnungen');
    $rows = [];
    $imgs = [];
    foreach (['bauplan' => 'Bauplan', 'gas_oel' => 'Gas-/Ölrechnung', 'strom' => 'Stromrechnung'] as $k => $name) {
        $list = $ids($u[$k] ?? []);
        if (!$list) $rows[] = [$name, 'fehlt', ''];
        foreach ($list as $id) {
            $f = $files[$id] ?? null;
            $rows[] = [$name, $f ? 'vorhanden' : 'noch nicht hochgeladen', $f['path'] ?? ''];
            if ($f && $isImage($f)) $imgs[] = [$id, $name];
        }
    }
    $table(['Unterlage', 'Status', 'Ablage'], $rows, [0.25, 0.25, 0.5]);
    if ($imgs) $gallery($imgs, 3);
    $para($u['note'] ?? '');

    if (!empty($check['remarks'])) {
        $heading('Bemerkungen / Fazit');
        $para($check['remarks']);
    }

    try {
        $pdf->Output('F', $outFile);
    } finally {
        foreach ($tmpImages as $t) @unlink($t);
    }
}
