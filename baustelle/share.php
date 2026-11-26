<?php
/*
 * Öffentliche, schreibgeschützte Ansicht einer Baustelle für Beobachter (ohne Login).
 * Aufruf: baustelle/share.php?t=<Token>[&p=data|thumb/<id>|photo/<id>|zip]
 * Gültig nur, solange der Link nicht gesperrt oder abgelaufen ist. Notizen und Benutzernamen werden nicht gezeigt.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/lib/util.php';
require dirname(__DIR__) . '/lib/baustelle.php';

const ID_RE = '/^[A-Za-z0-9_-]{6,64}$/';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$token = (string)($_GET['t'] ?? '');
$route = trim((string)($_GET['p'] ?? ''), '/');
$parts = $route === '' ? [] : explode('/', $route);

function fail_page(int $status, string $msg): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $m = htmlspecialchars($msg, ENT_QUOTES);
    echo "<!doctype html><html lang=\"de\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>Baustellen-Fotos</title>
    <style>body{margin:0;font:16px/1.5 Inter,system-ui,sans-serif;background:#F4F6F5;color:#14201D}header{background:#0A2A5C;border-bottom:3px solid #FFD200;color:#fff;padding:18px 16px}
    main{max-width:560px;margin:32px auto;padding:0 16px}.card{background:#fff;border:1px solid #D5DDDA;border-radius:12px;padding:18px}</style></head>
    <body><header><b style=\"color:#FFD200;font-size:12px;letter-spacing:.06em\">BAUSTELLEN-FOTOS</b></header><main><div class=\"card\"><h1 style=\"font-size:20px;margin:0 0 6px\">Link nicht verfügbar</h1><p style=\"margin:0\">$m Bitte wenden Sie sich an die Person, die Ihnen den Link geschickt hat.</p></div></main></body></html>";
    exit;
}

try {
    $config = load_config();
    $sp = new SitePhotos(dirname(rtrim($config['data_dir'], '/')) . '/baustellen');
    $ctx = $sp->siteForToken($token, $route === ''); // count only page views, not every thumbnail
} catch (HttpError $e) {
    if ($route === '') fail_page($e->status, $e->getMessage());
    json_out(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('[baustelle-share] ' . $e);
    fail_page(500, 'Der Server ist gerade nicht erreichbar.');
}
$site = $ctx['site'];
$only = $ctx['share']['photoIds']; // null = whole site

try {
    if ($route === 'data') {
        $photos = array_map(fn($p) => [
            'id' => $p['id'], 'category' => $p['category'], 'day' => $p['day'], 'takenAt' => $p['takenAt'],
            'pdf' => (bool)preg_match('/\.pdf$/i', $p['path']), 'name' => basename($p['path']),
        ], array_values(array_filter($sp->photos($site['id']), fn($p) => $only === null || in_array($p['id'], $only, true))));
        json_out([
            'site' => ['name' => $site['name'], 'address' => $site['address'] ?? ''],
            'expiresAt' => $ctx['share']['expiresAt'], 'categories' => SitePhotos::CATEGORIES, 'photos' => $photos,
            'zip' => class_exists('ZipArchive'),
        ]);
    }
    if (in_array($parts[0] ?? '', ['thumb', 'photo'], true) && isset($parts[1]) && preg_match(ID_RE, $parts[1])) {
        $f = $sp->sharedPhoto($site['id'], $parts[1], $only);
        $file = $f['abs'];
        $mime = $f['mime'] ?: 'application/octet-stream';
        if ($parts[0] === 'thumb' && ($t = $sp->thumbnail($parts[1]))) { $file = $t; $mime = 'image/jpeg'; }
        if (!is_file($file)) throw new HttpError(404, 'Datei nicht gefunden');
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=86400');
        $disp = !empty($_GET['download']) ? 'attachment' : 'inline';
        header("Content-Disposition: $disp; filename*=UTF-8''" . rawurlencode($f['name']));
        readfile($file);
        exit;
    }
    if ($route === 'zip') {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['day'] ?? '')) ? $_GET['day'] : null;
        $z = $sp->zip($site['id'], $day, $only);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($z['file']));
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($z['name']));
        readfile($z['file']);
        @unlink($z['file']);
        exit;
    }
    if ($route !== '') throw new HttpError(404, 'Unbekannt');
} catch (HttpError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
}

// ---------------------------------------------------------------- viewer page
$name = htmlspecialchars($site['name'], ENT_QUOTES);
$address = htmlspecialchars((string)($site['address'] ?? ''), ENT_QUOTES);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#0A2A5C">
<title><?= $name ?> – Baustellen-Fotos</title>
<link rel="icon" href="../icon.svg" type="image/svg+xml">
<style>
:root { --bg:#F4F6F5; --text:#14201D; --muted:#52605C; --line:#D5DDDA; --accent:#0A2A5C; --accent-soft:#E9EEF7; --yellow:#FFD200; --yellow-hover:#F0C400; }
* { box-sizing: border-box; } [hidden] { display: none !important; }
html, body { margin: 0; background: var(--bg); color: var(--text); }
body { font: 15px/1.45 Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
button { font: inherit; cursor: pointer; }
header { background: var(--accent); color: #fff; border-bottom: 3px solid var(--yellow); padding: calc(12px + env(safe-area-inset-top)) 16px 14px; }
header .in { max-width: 960px; margin: 0 auto; display: flex; flex-direction: column; gap: 4px; }
.kicker { font-size: 12px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--yellow); }
h1 { margin: 0; font-size: 22px; }
.sub { margin: 0; color: #C9D6EE; font-size: 13px; }
main { max-width: 960px; margin: 0 auto; padding: 14px 16px 32px; display: flex; flex-direction: column; gap: 12px; }
.card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 8px; }
.btn { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0 14px; border-radius: 10px; border: 1px solid var(--line); background: #fff; color: var(--text); font-size: 14px; font-weight: 600; text-decoration: none; }
.btn.primary { background: var(--yellow); border-color: var(--yellow); color: var(--accent); }
.btn.small { min-height: 36px; font-size: 13px; padding: 0 10px; }
.chips { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 2px; }
.chip { flex: none; min-height: 40px; border: 1px solid var(--line); background: #fff; color: var(--muted); border-radius: 999px; padding: 0 12px; font-size: 13px; font-weight: 600; }
.chip[aria-pressed="true"] { background: var(--accent); border-color: var(--accent); color: #fff; }
.day { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.day h2 { margin: 0; font-size: 16px; }
.cat-h { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); margin: 8px 0 4px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)); gap: 6px; }
.ph { aspect-ratio: 1; border-radius: 8px; overflow: hidden; background: var(--accent-soft); border: 0; padding: 0; display: flex; align-items: center; justify-content: center; font-weight: 700; color: var(--accent); }
.ph img { width: 100%; height: 100%; object-fit: cover; display: block; }
.help { font-size: 12px; color: var(--muted); }
#lb { position: fixed; inset: 0; z-index: 30; background: #0B1220; color: #fff; display: flex; flex-direction: column; }
#lb .top { display: flex; align-items: center; justify-content: space-between; padding: calc(8px + env(safe-area-inset-top)) 8px 8px; }
#lb .top button, #lb .top a { color: #fff; background: none; border: 0; min-height: 44px; min-width: 44px; font-weight: 600; font-size: 14px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
#lb .img { flex: 1; min-height: 0; display: flex; align-items: center; justify-content: center; position: relative; touch-action: pan-y; }
#lb .img img { max-width: 100%; max-height: 100%; object-fit: contain; }
#lb .nav { position: absolute; top: 50%; transform: translateY(-50%); width: 44px; height: 64px; border: 0; background: rgba(255,255,255,.12); color: #fff; font-size: 26px; border-radius: 8px; }
#lb .prev { left: 8px; } #lb .next { right: 8px; }
#lb .cap { padding: 10px 16px calc(12px + env(safe-area-inset-bottom)); font-size: 13px; color: #C9D6EE; text-align: center; }
</style>
</head>
<body>
<header><div class="in"><span class="kicker">Baustellen-Fotos · Nur ansehen</span><h1><?= $name ?></h1><?= $address !== '' ? "<p class=\"sub\">$address</p>" : '' ?></div></header>
<main id="main"><p class="help">Fotos werden geladen …</p></main>
<script>
(function () {
  'use strict';
  const T = <?= json_encode($token) ?>;
  const url = (p, q = '') => `share.php?t=${T}&p=${p}${q}`;
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const fmtDay = (iso) => new Date(iso + 'T12:00:00').toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' });
  const fmtTs = (iso) => (iso ? new Date(iso).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');
  const $main = document.getElementById('main');
  let data, filter = 'alle';

  function render() {
    const cats = data.categories;
    const counts = {};
    data.photos.forEach((p) => (counts[p.category] = (counts[p.category] || 0) + 1));
    const shown = data.photos.filter((p) => filter === 'alle' || p.category === filter);
    const days = [...new Set(shown.map((p) => p.day))].sort().reverse();
    $main.innerHTML = `
      <div class="card"><div class="day"><span>${data.photos.length} Fotos${data.expiresAt ? ` · Link gültig bis ${new Date(data.expiresAt).toLocaleDateString('de-DE')}` : ''}</span>
        ${data.zip && data.photos.length ? `<a class="btn primary" href="${url('zip')}">Alle herunterladen (ZIP)</a>` : ''}</div></div>
      ${data.photos.length ? `<div class="chips"><button class="chip" data-f="alle" aria-pressed="${filter === 'alle'}">Alle ${data.photos.length}</button>
        ${Object.entries(cats).filter(([k]) => counts[k]).map(([k, l]) => `<button class="chip" data-f="${k}" aria-pressed="${filter === k}">${esc(l)} ${counts[k]}</button>`).join('')}</div>` : '<p class="help">Noch keine Fotos vorhanden.</p>'}
      ${days.map((day) => {
        const inDay = shown.filter((p) => p.day === day);
        return `<section class="card"><div class="day"><h2>${esc(fmtDay(day))}</h2>${data.zip ? `<a class="btn small" href="${url('zip', '&day=' + day)}">ZIP</a>` : ''}</div>
          ${Object.keys(cats).filter((c) => inDay.some((p) => p.category === c)).map((c) => `<div class="cat-h">${esc(cats[c])}</div><div class="grid">
            ${inDay.filter((p) => p.category === c).map((p) => `<button class="ph" data-id="${p.id}" aria-label="${esc(cats[c])} vom ${esc(fmtTs(p.takenAt))}">${p.pdf ? 'PDF' : `<img src="${url('thumb/' + p.id)}" alt="" loading="lazy">`}</button>`).join('')}</div>`).join('')}</section>`;
      }).join('')}
      <p class="help">Schreibgeschützte Ansicht. Bitte geben Sie den Link nicht weiter.</p>`;
  }

  function open(id) {
    const list = data.photos.filter((p) => filter === 'alle' || p.category === filter);
    let i = list.findIndex((p) => p.id === id);
    const lb = document.createElement('div');
    lb.id = 'lb'; lb.setAttribute('role', 'dialog'); lb.setAttribute('aria-modal', 'true');
    document.body.appendChild(lb);
    const draw = () => {
      const p = list[i];
      lb.innerHTML = `<div class="top"><button data-a="close">Schließen</button><span style="font-size:13px">${i + 1} / ${list.length}</span><a href="${url('photo/' + p.id, '&download=1')}">Herunterladen</a></div>
        <div class="img">${p.pdf ? `<a class="btn" href="${url('photo/' + p.id)}" target="_blank" rel="noopener">PDF öffnen</a>` : `<img src="${url('photo/' + p.id)}" alt="">`}
        ${i > 0 ? '<button class="nav prev" data-a="prev" aria-label="Vorheriges Foto">‹</button>' : ''}${i < list.length - 1 ? '<button class="nav next" data-a="next" aria-label="Nächstes Foto">›</button>' : ''}</div>
        <div class="cap">${esc(data.categories[p.category])} · ${esc(fmtTs(p.takenAt))}</div>`;
    };
    draw();
    const close = () => { lb.remove(); document.removeEventListener('keydown', key); };
    const key = (e) => { if (e.key === 'Escape') close(); if (e.key === 'ArrowLeft' && i > 0) { i--; draw(); } if (e.key === 'ArrowRight' && i < list.length - 1) { i++; draw(); } };
    document.addEventListener('keydown', key);
    let x0 = null;
    lb.addEventListener('pointerdown', (e) => { if (e.target.closest('.img')) x0 = e.clientX; });
    lb.addEventListener('pointerup', (e) => { if (x0 === null) return; const dx = e.clientX - x0; x0 = null; if (dx > 60 && i > 0) { i--; draw(); } else if (dx < -60 && i < list.length - 1) { i++; draw(); } });
    lb.addEventListener('click', (e) => { const b = e.target.closest('[data-a]'); if (!b) return; if (b.dataset.a === 'close') close(); if (b.dataset.a === 'prev') { i--; draw(); } if (b.dataset.a === 'next') { i++; draw(); } });
  }

  $main.addEventListener('click', (e) => {
    const f = e.target.closest('[data-f]'); if (f) { filter = f.dataset.f; render(); return; }
    const p = e.target.closest('[data-id]'); if (p) open(p.dataset.id);
  });
  fetch(url('data')).then((r) => r.json().then((d) => ({ ok: r.ok, d }))).then(({ ok, d }) => {
    if (!ok) { $main.innerHTML = `<div class="card">${esc(d.error || 'Link nicht verfügbar')}</div>`; return; }
    data = d; render();
  }).catch(() => ($main.innerHTML = '<div class="card">Die Fotos konnten nicht geladen werden.</div>'));
})();
</script>
</body>
</html>
