/* Baubarkeits-Check – Vor-Ort-Erfassung (offline-fähig, ohne Build-Schritt). */
(function () {
  'use strict';

  // ---------------------------------------------------------------- config

  const STEPS = [
    { id: 'heizkoerper', title: 'Heizkörper', sub: 'Pro Heizkörper 2 Fotos: Gesamtansicht & Hahnblock' },
    { id: 'zaehler', title: 'Zählerschrank', sub: 'Geöffnet fotografieren, inkl. ca. 20 cm Umfeld' },
    { id: 'heizraum', title: 'Heizraum 360°', sub: 'Video: einmal langsam im Kreis drehen, Boden bis Decke' },
    { id: 'weg', title: 'Weg Heizraum → Haustür', sub: 'Video vom Heizraum bis zur Haustür, Engstellen mit Zollstock' },
    { id: 'hausseiten', title: 'Hausseiten', sub: 'Jede Seite fotografieren, Abstand zu Nachbarfenster und Grenze' },
    { id: 'unterlagen', title: 'Bauplan & Rechnungen', sub: 'Abfotografieren oder als Datei (PDF/Bild) hochladen' },
  ];
  const SIDES = [
    ['nord', 'Nord'],
    ['ost', 'Ost'],
    ['sued', 'Süd'],
    ['west', 'West'],
  ];
  const DOCS = [
    ['bauplan', 'Bauplan'],
    ['gas_oel', 'Gas-/Ölrechnung'],
    ['strom', 'Stromrechnung'],
  ];
  const PHOTO_MAX = 2400; // px, long edge for uploads
  const THUMB_MAX = 480;

  let serverConfig = { companyName: '', minWidthCm: 0 };

  // ---------------------------------------------------------------- utils

  const $app = document.getElementById('app');
  const esc = (v) =>
    String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

  function uid() {
    const a = new Uint8Array(12);
    crypto.getRandomValues(a);
    return Array.from(a, (b) => b.toString(16).padStart(2, '0')).join('');
  }

  function getPath(obj, path) {
    return path.split('.').reduce((o, k) => (o == null ? undefined : o[k]), obj);
  }
  function setPath(obj, path, value) {
    const keys = path.split('.');
    const last = keys.pop();
    const target = keys.reduce((o, k) => (o[k] == null ? (o[k] = {}) : o[k]), obj);
    target[last] = value;
  }

  function fmtTime(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  }

  function toast(msg, ms = 2600) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.hidden = false;
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => (t.hidden = true), ms);
  }

  const ICONS = {
    back: '<path d="M15 18l-6-6 6-6"/>',
    camera: '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
    image: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
    video: '<path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/>',
    file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
    trash: '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
    check: '<path d="M20 6L9 17l-5-5"/>',
    warn: '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
    folder: '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
    play: '<path d="M8 5v14l11-7z" fill="currentColor"/>',
    archive: '<rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8M10 12h4"/>',
    restore: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
  };
  const icon = (name, size = 16) =>
    `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name]}</svg>`;

  // ---------------------------------------------------------------- model

  function newHK() {
    return { id: uid(), room: '', width: '', height: '', depth: '', type: '', note: '', photos: { gesamt: null, hahnblock: null } };
  }

  function newCheck() {
    const sides = {};
    SIDES.forEach(([k]) => (sides[k] = { photos: [], fensterM: '', grenzeM: '', note: '' }));
    let advisor = '';
    try {
      advisor = localStorage.getItem('advisor') || '';
    } catch (_) {
      advisor = '';
    }
    const now = new Date().toISOString();
    return {
      id: uid(),
      customer: { name: '', address: '', number: '', date: now.slice(0, 10), advisor, phone: '', email: '' },
      steps: {
        heizkoerper: { items: [newHK()] },
        zaehler: { photos: [], note: '' },
        heizraum: { videos: [], photos: [], length: '', width: '', heightCm: '', note: '' },
        weg: { videos: [], items: [], note: '' },
        hausseiten: { sides },
        unterlagen: { bauplan: [], gas_oel: [], strom: [], note: '' },
      },
      remarks: '',
      createdAt: now,
      updatedAt: now,
      folder: '',
    };
  }

  function stepDone(check, stepId) {
    const s = check.steps[stepId];
    switch (stepId) {
      case 'heizkoerper':
        return s.items.length > 0 && s.items.every((i) => i.photos.gesamt && i.photos.hahnblock);
      case 'zaehler':
        return s.photos.length > 0;
      case 'heizraum':
        return s.videos.length > 0;
      case 'weg':
        return s.videos.length > 0;
      case 'hausseiten':
        return SIDES.every(([k]) => s.sides[k].photos.length > 0);
      case 'unterlagen':
        return DOCS.every(([k]) => s[k].length > 0);
      default:
        return false;
    }
  }

  function stepSummary(check, stepId) {
    const s = check.steps[stepId];
    const count = (ids) => ids.filter(Boolean).length;
    switch (stepId) {
      case 'heizkoerper': {
        const photos = s.items.reduce((n, i) => n + count([i.photos.gesamt, i.photos.hahnblock]), 0);
        return `${s.items.length} HK · ${plural(photos, 'Foto', 'Fotos')}`;
      }
      case 'zaehler':
        return plural(s.photos.length, 'Foto', 'Fotos');
      case 'heizraum':
        return `${plural(s.videos.length, 'Video', 'Videos')} · ${plural(s.photos.length, 'Foto', 'Fotos')}`;
      case 'weg': {
        const min = minWidth(check);
        return `${plural(s.videos.length, 'Video', 'Videos')} · ${plural(s.items.length, 'Engstelle', 'Engstellen')}${min ? ` · min. ${min} cm` : ''}`;
      }
      case 'hausseiten':
        return `${SIDES.filter(([k]) => s.sides[k].photos.length).length}/4 Seiten`;
      case 'unterlagen':
        return `${DOCS.filter(([k]) => s[k].length).length}/3 Unterlagen`;
      default:
        return '';
    }
  }

  const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

  /**
   * Ampel pro Kunde: grün = alle 6 Schritte vollständig, gelb = 3–5 Schritte, rot = höchstens 2.
   */
  function checkStatus(c) {
    const missingIdx = STEPS.map((s, i) => (stepDone(c, s.id) ? -1 : i)).filter((i) => i >= 0);
    const done = STEPS.length - missingIdx.length;
    const level = done === STEPS.length ? 'green' : done >= 3 ? 'yellow' : 'red';
    return {
      done,
      level,
      label: { green: 'Vollständig', yellow: 'Etwas fehlt', red: 'Ungenügend' }[level],
      missing: missingIdx.map((i) => STEPS[i].title),
      firstMissing: missingIdx.length ? missingIdx[0] + 1 : null,
    };
  }

  function fmtDate(d) {
    const m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})$/);
    return m ? `${m[3]}.${m[2]}.${m[1]}` : d;
  }

  function minWidth(check) {
    const ws = check.steps.weg.items
      .map((e) => Number(String(e.widthCm).replace(',', '.')))
      .filter((n) => !Number.isNaN(n) && n > 0);
    return ws.length ? Math.min(...ws) : null;
  }

  // ---------------------------------------------------------------- state

  let check = null; // current check
  const media = new Map(); // id -> { id, kind, mime, uploaded, meta, thumbUrl, name }
  let saveTimer = null;

  async function loadCheck(id) {
    if (check && check.id === id) return check;
    const c = await DB.getCheck(id);
    if (!c) return null;
    for (const m of media.values()) if (m.thumbUrl) URL.revokeObjectURL(m.thumbUrl);
    media.clear();
    const list = await DB.mediaForCheck(id);
    list.forEach(cacheMedia);
    check = c;
    return c;
  }

  function cacheMedia(rec) {
    const prev = media.get(rec.id);
    if (prev && prev.thumbUrl && prev.thumbUrl.startsWith('blob:')) URL.revokeObjectURL(prev.thumbUrl);
    const thumbBlob = rec.thumb || (rec.kind === 'photo' ? rec.blob : null);
    // Files captured on another device: preview straight from the server.
    const remoteThumb = !thumbBlob && rec.remote && rec.hasPreview ? api(`checks/${rec.checkId}/files/${rec.id}`, '&thumb=1') : '';
    media.set(rec.id, {
      id: rec.id,
      kind: rec.kind,
      mime: rec.mime,
      name: rec.name,
      uploaded: !!rec.uploaded,
      error: rec.error || '',
      meta: rec.meta || {},
      thumbUrl: thumbBlob ? URL.createObjectURL(thumbBlob) : remoteThumb,
    });
  }

  /** Persist the open check on this device (the change itself is already queued as an op). */
  function save({ immediate = false } = {}) {
    if (!check) return;
    check.updatedAt = new Date().toISOString();
    clearTimeout(saveTimer);
    const run = () =>
      DB.putCheck(check)
        .then(() => scheduleSync())
        .catch((e) => toast('Speichern fehlgeschlagen: ' + e.message));
    if (immediate) return run();
    saveTimer = setTimeout(run, 400);
  }

  // ---------------------------------------------------------------- sync

  let syncing = false;
  let syncAgain = false;
  let syncTimer = null;
  let uploadProgress = '';

  function scheduleSync(delay = 800) {
    clearTimeout(syncTimer);
    syncTimer = setTimeout(sync, delay);
  }

  const api = (route, query = '') => `api.php?p=${route}${query}`;
  const CHUNK = 4 * 1024 * 1024; // stays below PHP's default post_max_size (8 MB)

  /** fetch + JSON with readable errors; a 401 asks for a login. */
  async function apiFetch(route, opts = {}, query = '') {
    const r = await fetch(api(route, query), { credentials: 'same-origin', ...opts });
    const data = await r.json().catch(() => ({}));
    if (r.status === 401 && route !== 'login') {
      auth.user = null;
      needLogin();
    }
    if (!r.ok) throw Object.assign(new Error(data.error || `HTTP ${r.status}`), { status: r.status, data });
    return data;
  }

  // ---------------------------------------------------------------- changes as operations
  //
  // Every change is queued as a small operation ("set Breite of radiator X", "add photo Y to list Z").
  // The server applies them in order and returns the resulting state, so edits from several devices
  // merge instead of overwriting each other, and photos and data stay consistent.

  const DEVICE = (() => {
    try {
      let d = localStorage.getItem('device');
      if (!d) localStorage.setItem('device', (d = uid()));
      return d;
    } catch (_) {
      return uid();
    }
  })();
  const LOCAL_KEYS = ['ui', 'folder', 'rev', 'createdAt', 'updatedAt', 'deletedAt', 'dirty', 'serverUpdatedAt', 'id'];
  let opSeq = Date.now();
  let pendingOps = 0;
  const inFlight = new Set();

  /** "steps.heizkoerper.items.0.room" → ['steps','heizkoerper','items',{id:'…'},'room'] (list items by id, not position). */
  function opPath(obj, dotted) {
    const out = [];
    let cur = obj;
    for (const seg of dotted.split('.')) {
      if (Array.isArray(cur) && /^\d+$/.test(seg) && cur[+seg] && typeof cur[+seg] === 'object' && cur[+seg].id) {
        out.push({ id: cur[+seg].id });
        cur = cur[+seg];
      } else {
        out.push(seg);
        cur = cur == null ? undefined : cur[seg];
      }
    }
    return out;
  }

  /** Same rules as the server (lib/db.php): used to replay this device's unsent changes on top of the server state. */
  function applyOp(doc, op) {
    const update = (node, path, fn) => {
      const [seg, ...rest] = path;
      if (seg && typeof seg === 'object') {
        if (!Array.isArray(node)) throw new Error('skip');
        const i = node.findIndex((e) => e && e.id === seg.id);
        if (i < 0) throw new Error('skip');
        const copy = node.slice();
        copy[i] = rest.length ? update(node[i], rest, fn) : fn(node[i]);
        return copy;
      }
      const base = node && typeof node === 'object' && !Array.isArray(node) ? { ...node } : {};
      if (!rest.length) base[seg] = fn(base[seg]);
      else base[seg] = update(base[seg] && typeof base[seg] === 'object' ? base[seg] : {}, rest, fn);
      return base;
    };
    try {
      switch (op.type) {
        case 'merge':
          return deepMerge(doc, op.doc || {});
        case 'set':
          return update(doc, op.path, () => op.value);
        case 'addItem':
          return update(doc, op.path, (l) => (Array.isArray(l) ? (l.some((e) => e && e.id === op.item.id) ? l : [...l, op.item]) : [op.item]));
        case 'removeItem':
          return update(doc, op.path, (l) => (Array.isArray(l) ? l.filter((e) => !(e && e.id === op.itemId)) : []));
        case 'addMedia':
          return update(doc, op.path, (l) => (Array.isArray(l) ? (l.includes(op.mediaId) ? l : [...l, op.mediaId]) : [op.mediaId]));
        case 'setMedia':
          return update(doc, op.path, () => op.mediaId);
        case 'removeMedia':
          return update(doc, op.path, (v) => (Array.isArray(v) ? v.filter((x) => x !== op.mediaId) : v === op.mediaId ? null : v));
        case 'delete':
          return { ...doc, deletedAt: doc.deletedAt || new Date().toISOString() };
        default:
          return doc;
      }
    } catch (_) {
      return doc; // target gone (removed on another device)
    }
  }

  function deepMerge(a, b) {
    if (!a || typeof a !== 'object' || !b || typeof b !== 'object') return b;
    if (Array.isArray(b)) {
      const A = Array.isArray(a) ? a : [];
      if ([...A, ...b].some((e) => e && typeof e === 'object' && e.id)) {
        const out = A.slice();
        for (const item of b) {
          const i = out.findIndex((e) => e && e.id === item.id);
          if (i >= 0) out[i] = deepMerge(out[i], item);
          else out.push(item);
        }
        return out;
      }
      return [...new Set([...A, ...b])];
    }
    const out = { ...a };
    for (const [k, v] of Object.entries(b)) out[k] = k in out ? deepMerge(out[k], v) : v;
    return out;
  }

  function docOf(c) {
    const d = { ...c };
    for (const k of LOCAL_KEYS) delete d[k];
    return d;
  }

  /** Queue a change. Repeated typing in the same field is merged into one change. */
  async function queueOp(checkId, op) {
    if (op.type === 'set') {
      const key = JSON.stringify(op.path);
      const last = (await DB.opsForCheck(checkId)).filter((o) => !inFlight.has(o.opId)).pop();
      if (last && last.type === 'set' && JSON.stringify(last.path) === key) {
        last.value = op.value;
        await DB.putOp(last);
        scheduleSync(1500);
        return;
      }
    }
    await DB.putOp({ ...op, opId: uid(), checkId, seq: ++opSeq });
    pendingOps++;
    scheduleSync(op.type === 'set' ? 1500 : 300);
  }

  /** Change a value in the open check and queue it. `path` is the dotted form used by the form fields. */
  function setValue(path, value) {
    if (path.startsWith('ui.')) return setPath(check, path, value);
    const p = opPath(check, path);
    setPath(check, path, value);
    queueOp(check.id, { type: 'set', path: p, value });
  }

  /** Send queued changes, receive everything new from other devices. Returns ids of checks that changed. */
  async function pushPull() {
    const ops = (await DB.allOps()).slice(0, 500);
    ops.forEach((o) => inFlight.add(o.opId));
    const since = Number((await DB.getMeta('rev')) || 0);
    let res;
    try {
      res = await apiFetch('sync', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ device: DEVICE, since, ops: ops.map(({ seq, ...o }) => o) }),
      });
    } finally {
      inFlight.clear();
    }
    for (const id of [...res.applied, ...res.skipped]) await DB.deleteOp(id);
    pendingOps = (await DB.allOps()).length;

    const changed = new Set();
    for (const sc of res.checks) {
      const local = await DB.getCheck(sc.id);
      const pending = await DB.opsForCheck(sc.id);
      if (sc.deletedAt) {
        const unsent = (await DB.mediaForCheck(sc.id)).some((m) => !m.uploaded);
        if (!pending.length && !unsent) await purgeLocal(sc.id);
        else if (local) await DB.putCheck({ ...local, deletedAt: sc.deletedAt });
        changed.add(sc.id);
        continue;
      }
      let doc = sc.doc;
      for (const op of pending) doc = applyOp(doc, op);
      const merged = { ...doc, id: sc.id, folder: sc.folder, rev: sc.rev, createdAt: sc.createdAt, updatedAt: sc.updatedAt, ui: local && local.ui };
      if (local && JSON.stringify(docOf(local)) === JSON.stringify(docOf(merged)) && local.folder === merged.folder) {
        await DB.putCheck({ ...local, rev: sc.rev, folder: sc.folder });
        if (check && check.id === sc.id) check.folder = sc.folder;
        continue;
      }
      await DB.putCheck(merged);
      changed.add(sc.id);
      if (check && check.id === sc.id) {
        for (const k of Object.keys(check)) delete check[k];
        Object.assign(check, merged);
      }
    }
    for (const m of res.media) {
      const loc = await DB.getMedia(m.id);
      if (m.deletedAt) {
        if (loc && (loc.uploaded || loc.remote)) {
          await DB.deleteMedia(m.id);
          if (media.has(m.id)) media.delete(m.id);
          changed.add(m.checkId);
        }
        continue;
      }
      if (!m.uploaded || loc) continue;
      const rec = {
        id: m.id, checkId: m.checkId, step: m.step, label: m.label || '', mime: m.mime || '', name: m.name || '',
        kind: (m.mime || '').startsWith('video/') ? 'video' : m.step === 'unterlagen' ? 'doc' : (m.mime || '').startsWith('image/') ? 'photo' : 'doc',
        blob: null, thumb: null, uploaded: true, remote: true, hasPreview: !!m.hasPreview,
        meta: { takenAt: m.takenAt || undefined, lat: m.lat ?? undefined, lng: m.lng ?? undefined },
        createdAt: new Date().toISOString(),
      };
      await DB.putMedia(rec);
      if (check && check.id === m.checkId) cacheMedia(rec);
      changed.add(m.checkId);
    }
    await DB.setMeta('rev', res.rev);
    return changed;
  }

  /** One-time switch from the old "whole customer" sync: queue each local customer as one merge. */
  async function migrateLocal() {
    if (await DB.getMeta('opsMigrated')) return;
    for (const c of await DB.allChecks()) {
      if (c.dirty || !c.folder) await DB.putOp({ type: 'merge', doc: docOf(c), opId: uid(), checkId: c.id, seq: ++opSeq });
      if (c.deletedAt) await DB.putOp({ type: 'delete', opId: uid(), checkId: c.id, seq: ++opSeq });
    }
    await DB.setMeta('opsMigrated', true);
    pendingOps = (await DB.allOps()).length;
  }

  /** Upload one blob in 4 MB pieces (no PHP upload limit, survives weak mobile networks). */
  async function uploadBlob(rec, part, blob, onBytes) {
    let offset = 0;
    do {
      const piece = blob.slice(offset, offset + CHUNK);
      try {
        const res = await apiFetch(
          `checks/${rec.checkId}/files/${rec.id}/chunk`,
          { method: 'POST', headers: { 'Content-Type': 'application/octet-stream' }, body: piece },
          `&part=${part}&offset=${offset}`
        );
        offset = res.size;
      } catch (e) {
        if (e.status === 409 && e.data && typeof e.data.size === 'number') {
          offset = e.data.size > blob.size ? 0 : e.data.size; // resume where the server is
          continue;
        }
        throw e;
      }
      onBytes(offset);
    } while (offset < blob.size);
  }

  async function uploadMedia(rec, onProgress) {
    const total = rec.blob.size + (rec.poster ? rec.poster.size : 0);
    let base = 0;
    const report = (n) => onProgress(Math.min(100, Math.round(((base + n) / (total || 1)) * 100)));
    if (rec.poster) {
      await uploadBlob(rec, 'poster', rec.poster, report);
      base = rec.poster.size;
    }
    await uploadBlob(rec, 'file', rec.blob, report);
    await apiFetch(`checks/${rec.checkId}/files/${rec.id}/complete`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ step: rec.step, label: rec.label, meta: rec.meta, mime: rec.mime, name: rec.name, size: rec.blob.size }),
    });
  }

  async function sync() {
    if (syncing) {
      syncAgain = true;
      return;
    }
    if (!navigator.onLine || !auth.user) {
      renderSync();
      return;
    }
    syncing = true;
    try {
      const changed = await pushPull();
      if (changed.size) onRemoteChange(changed);
      const checks = await DB.allChecks();
      for (const c of checks) {
        if (!c.folder || c.deletedAt) continue; // the server creates the customer folder once the name is known
        const pending = (await DB.mediaForCheck(c.id)).filter((m) => !m.uploaded && m.blob);
        for (let i = 0; i < pending.length; i++) {
          const rec = pending[i];
          try {
            await uploadMedia(rec, (p) => {
              uploadProgress = `Lädt hoch ${i + 1}/${pending.length} · ${p} %`;
              renderSync();
            });
            rec.uploaded = true;
            rec.error = '';
            // Free phone storage: keep only the thumbnail once on the server.
            if (rec.kind === 'photo' && !rec.thumb) rec.thumb = rec.blob;
            rec.blob = null;
            rec.poster = null;
          } catch (e) {
            rec.error = e.message;
            // network/server/login problems: stop and retry later; other errors stay on this file
            if (!e.status || e.status >= 500 || e.status === 401 || e.status === 409) throw e;
          }
          // Removed by the user while it was uploading? Then don't resurrect it.
          if (!(await DB.getMedia(rec.id))) continue;
          await DB.putMedia(rec);
          if (check && check.id === rec.checkId) {
            cacheMedia(rec);
            refreshTile(rec.id);
          }
        }
      }
      uploadProgress = '';
    } catch (e) {
      uploadProgress = e.status === 401 ? '' : `Upload unterbrochen (${e.message}) – neuer Versuch folgt`;
      if (e.status !== 401) scheduleSync(20000);
    } finally {
      syncing = false;
      renderSync();
      if (syncAgain) {
        syncAgain = false;
        scheduleSync(200);
      }
    }
  }

  window.addEventListener('online', async () => {
    if (!auth.user) {
      await loadConfig();
      if (!auth.user && !auth.skipped) render();
    }
    scheduleSync(100);
  });
  setInterval(() => scheduleSync(0), 60000);

  function pendingCount() {
    let n = 0;
    media.forEach((m) => !m.uploaded && n++);
    return n;
  }

  function syncText() {
    if (!check) return '';
    if (uploadProgress) return uploadProgress;
    const n = pendingCount();
    if (!navigator.onLine) return `Offline · ${n} Datei(en) warten`;
    if (!auth.user) return 'Nicht angemeldet – Upload pausiert';
    if (n) return `${n} Datei(en) warten auf Upload`;
    if (pendingOps) return 'Wird gespeichert …';
    return 'Alles auf dem Server gesichert';
  }

  function renderSync() {
    document.querySelectorAll('[data-sync]').forEach((el) => {
      el.textContent = syncText();
      el.classList.toggle('pending', pendingCount() > 0 || !navigator.onLine);
    });
    const up = document.querySelector('[data-upload-bar]');
    if (up) {
      const total = media.size;
      const done = total - pendingCount();
      up.style.width = total ? `${Math.round((done / total) * 100)}%` : '100%';
      const label = document.querySelector('[data-upload-count]');
      if (label) label.textContent = `${done} / ${total}`;
    }
  }

  // ---------------------------------------------------------------- media processing

  /** Minimal EXIF reader for JPEG: DateTimeOriginal + GPS. */
  async function readExif(file) {
    try {
      if (!/jpe?g/i.test(file.type) && !/\.jpe?g$/i.test(file.name)) return {};
      const buf = await file.slice(0, 256 * 1024).arrayBuffer();
      const v = new DataView(buf);
      if (v.getUint16(0) !== 0xffd8) return {};
      let off = 2;
      while (off + 4 < v.byteLength) {
        const marker = v.getUint16(off);
        const len = v.getUint16(off + 2);
        if (marker === 0xffe1 && v.getUint32(off + 4) === 0x45786966) return parseTiff(v, off + 10);
        if ((marker & 0xff00) !== 0xff00) break;
        off += 2 + len;
      }
    } catch (_) {
      /* ignore broken EXIF */
    }
    return {};
  }

  function parseTiff(v, start) {
    const little = v.getUint16(start) === 0x4949;
    const u16 = (o) => v.getUint16(start + o, little);
    const u32 = (o) => v.getUint32(start + o, little);
    const readIfd = (o) => {
      const tags = {};
      const n = u16(o);
      for (let i = 0; i < n; i++) {
        const e = o + 2 + i * 12;
        tags[u16(e)] = { type: u16(e + 2), count: u32(e + 4), valueOff: e + 8 };
      }
      return tags;
    };
    const ascii = (t) => {
      const o = t.count > 4 ? u32(t.valueOff) : t.valueOff;
      let s = '';
      for (let i = 0; i < t.count - 1; i++) s += String.fromCharCode(v.getUint8(start + o + i));
      return s;
    };
    const rationals = (t) => {
      const o = u32(t.valueOff);
      const out = [];
      for (let i = 0; i < t.count; i++) out.push(u32(o + i * 8) / (u32(o + i * 8 + 4) || 1));
      return out;
    };
    const res = {};
    const ifd0 = readIfd(u32(4));
    if (ifd0[0x8769]) {
      const exif = readIfd(u32(ifd0[0x8769].valueOff));
      if (exif[0x9003]) {
        const m = ascii(exif[0x9003]).match(/^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})/);
        if (m) res.takenAt = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]).toISOString();
      }
    }
    if (ifd0[0x8825]) {
      const gps = readIfd(u32(ifd0[0x8825].valueOff));
      if (gps[2] && gps[4]) {
        const toDeg = (a) => a[0] + a[1] / 60 + a[2] / 3600;
        let lat = toDeg(rationals(gps[2]));
        let lng = toDeg(rationals(gps[4]));
        if (gps[1] && String.fromCharCode(v.getUint8(gps[1].valueOff)) === 'S') lat = -lat;
        if (gps[3] && String.fromCharCode(v.getUint8(gps[3].valueOff)) === 'W') lng = -lng;
        if (Number.isFinite(lat) && Number.isFinite(lng) && (lat || lng)) {
          res.lat = lat;
          res.lng = lng;
        }
      }
    }
    return res;
  }

  async function resizeImage(source, max, quality) {
    let bmp;
    try {
      bmp = await createImageBitmap(source, { imageOrientation: 'from-image' });
    } catch (_) {
      bmp = await new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = reject;
        img.src = URL.createObjectURL(source);
      });
    }
    const w = bmp.width || bmp.naturalWidth;
    const h = bmp.height || bmp.naturalHeight;
    const scale = Math.min(1, max / Math.max(w, h));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(w * scale);
    canvas.height = Math.round(h * scale);
    canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
    if (bmp.close) bmp.close();
    return new Promise((resolve, reject) =>
      canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('Bild konnte nicht umgewandelt werden'))), 'image/jpeg', quality)
    );
  }

  function videoPoster(file) {
    return new Promise((resolve) => {
      const url = URL.createObjectURL(file);
      const v = document.createElement('video');
      let done = false;
      const finish = (blob) => {
        if (done) return;
        done = true;
        URL.revokeObjectURL(url);
        resolve(blob || null);
      };
      setTimeout(() => finish(null), 10000);
      v.muted = true;
      v.playsInline = true;
      v.preload = 'auto';
      v.src = url;
      v.onloadedmetadata = () => {
        v.currentTime = Math.min(1, (v.duration || 2) / 2);
      };
      v.onseeked = () => {
        try {
          const scale = Math.min(1, 1280 / Math.max(v.videoWidth, v.videoHeight));
          const c = document.createElement('canvas');
          c.width = Math.round(v.videoWidth * scale);
          c.height = Math.round(v.videoHeight * scale);
          c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
          c.toBlob((b) => finish(b), 'image/jpeg', 0.8);
        } catch (_) {
          finish(null);
        }
      };
      v.onerror = () => finish(null);
    });
  }

  function currentPosition() {
    return new Promise((resolve) => {
      if (!navigator.geolocation) return resolve(null);
      navigator.geolocation.getCurrentPosition(
        (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: Math.round(p.coords.accuracy) }),
        () => resolve(null),
        { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
      );
    });
  }

  async function processFile(file, kind, source, positionPromise) {
    const isImage = /^image\//.test(file.type) || /\.(jpe?g|png|heic|heif|webp)$/i.test(file.name);
    const isVideo = /^video\//.test(file.type) || /\.(mp4|mov|webm|m4v)$/i.test(file.name);
    const meta = {};
    if (source === 'camera') meta.takenAt = new Date().toISOString();
    else if (file.lastModified) meta.takenAt = new Date(file.lastModified).toISOString();

    const rec = { kind, mime: file.type || 'application/octet-stream', name: file.name || 'datei', blob: file, thumb: null, poster: null, meta };

    if (isImage) {
      rec.kind = kind === 'doc' ? 'doc' : 'photo';
      Object.assign(meta, await readExif(file)); // EXIF wins over lastModified
      try {
        rec.blob = await resizeImage(file, PHOTO_MAX, 0.85);
        rec.mime = 'image/jpeg';
        rec.name = (file.name || 'foto').replace(/\.[^.]+$/, '') + '.jpg';
        rec.thumb = await resizeImage(rec.blob, THUMB_MAX, 0.7);
      } catch (_) {
        // e.g. HEIC on browsers that can't decode it: upload the original.
      }
    } else if (isVideo) {
      rec.kind = 'video';
      rec.poster = await videoPoster(file);
      if (rec.poster) {
        try {
          rec.thumb = await resizeImage(rec.poster, THUMB_MAX, 0.7);
        } catch (_) {
          rec.thumb = rec.poster;
        }
      }
    } else {
      rec.kind = 'doc';
    }

    if (meta.lat === undefined && positionPromise) {
      const pos = await positionPromise;
      if (pos) Object.assign(meta, pos);
    }
    return rec;
  }

  /** Open camera/gallery, store the result and attach it at `path`. */
  function capture({ path, mode, kind, source, label, step }) {
    const input = document.createElement('input');
    input.type = 'file';
    input.className = 'visually-hidden';
    if (kind === 'photo') input.accept = 'image/*';
    else if (kind === 'video') input.accept = 'video/*';
    else input.accept = 'image/*,application/pdf';
    if (source === 'camera') input.setAttribute('capture', 'environment');
    if (mode === 'list' && source !== 'camera') input.multiple = true;
    // Only ask for live GPS for fresh camera shots.
    const posPromise = source === 'camera' ? currentPosition() : null;
    input.onchange = async () => {
      const files = Array.from(input.files || []);
      input.remove();
      if (!files.length) return;
      toast(files.length > 1 ? `${files.length} Dateien werden verarbeitet …` : 'Wird verarbeitet …', 1500);
      for (const file of files) {
        try {
          const rec = await processFile(file, kind, source, posPromise);
          Object.assign(rec, { id: uid(), checkId: check.id, step, label, uploaded: false, createdAt: new Date().toISOString() });
          await DB.putMedia(rec);
          cacheMedia(rec);
          if (mode === 'single') {
            const old = getPath(check, path);
            if (old) await removeMedia(old);
            const p = opPath(check, path);
            setPath(check, path, rec.id);
            await queueOp(check.id, { type: 'setMedia', path: p, mediaId: rec.id });
          } else {
            const p = opPath(check, path);
            getPath(check, path).push(rec.id);
            await queueOp(check.id, { type: 'addMedia', path: p, mediaId: rec.id });
          }
        } catch (e) {
          toast('Datei konnte nicht übernommen werden: ' + e.message, 4000);
        }
      }
      await save({ immediate: true });
      render();
    };
    document.body.appendChild(input);
    input.click();
  }

  async function removeMedia(id) {
    const m = media.get(id);
    if (m && m.thumbUrl) URL.revokeObjectURL(m.thumbUrl);
    media.delete(id);
    await DB.deleteMedia(id);
  }

  // ---------------------------------------------------------------- render helpers

  function tile(id, path) {
    const m = media.get(id);
    if (!m) return '';
    const metaParts = [fmtTime(m.meta.takenAt), m.meta.lat !== undefined ? 'GPS ✓' : ''].filter(Boolean).join(' · ');
    const state = m.uploaded
      ? '<span class="state up">Hochgeladen</span>'
      : `<span class="state wait"${m.error ? ` title="${esc(m.error)}"` : ''}>${m.error ? 'Fehler' : 'Wartet'}</span>`;
    let inner;
    let cls = 'thumb';
    if (m.thumbUrl) {
      inner = `<img src="${m.thumbUrl}" alt="${esc(m.name)}">`;
      if (m.kind === 'video') inner += `<span class="meta" style="top:6px;bottom:auto">${icon('video', 12)} Video</span>`;
    } else if (m.kind === 'video') {
      cls += ' video';
      inner = `<span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#fff">${icon('play', 36)}</span>`;
    } else {
      cls += ' doc';
      inner = `${icon('file', 20)}<span style="display:block;overflow:hidden;text-overflow:ellipsis">${esc(m.name)}</span>`;
    }
    return `<div class="${cls}" data-tile="${id}">${inner}${metaParts ? `<span class="meta">${esc(metaParts)}</span>` : ''}${state}
      <button class="del" data-action="remove-media" data-path="${path}" data-file="${id}" aria-label="Entfernen">${icon('trash', 16)}</button></div>`;
  }

  function refreshTile(id) {
    const el = document.querySelector(`[data-tile="${id}"]`);
    if (!el) return;
    const del = el.querySelector('.del');
    const path = del ? del.dataset.path : '';
    el.outerHTML = tile(id, path);
  }

  /** label may contain "{}" which is filled at capture time from the field at labelRef (e.g. the room). */
  function captureButtons({ path, mode, kind, label, step, labelRef }) {
    const attrs = `data-action="capture" data-path="${path}" data-mode="${mode}" data-kind="${kind}" data-label="${esc(label)}" data-step="${step}"${labelRef ? ` data-label-ref="${labelRef}"` : ''}`;
    const [camText, camIcon] = kind === 'video' ? ['Video aufnehmen', 'video'] : kind === 'doc' ? ['Fotografieren', 'camera'] : ['Kamera', 'camera'];
    const [galText, galIcon] = kind === 'doc' ? ['Datei wählen', 'file'] : ['Galerie', 'image'];
    return `<div class="empty-slot">
      <button class="btn primary" ${attrs} data-source="camera">${icon(camIcon)}${camText}</button>
      <button class="btn" ${attrs} data-source="gallery">${icon(galIcon)}${galText}</button>
    </div>`;
  }

  /** One fixed slot (holds a single file id). */
  function singleSlot(path, kind, title, label, step, labelRef) {
    const id = getPath(check, path);
    return `<div class="slot">${id ? tile(id, path) : captureButtons({ path, mode: 'single', kind, label, step, labelRef })}<span class="label">${esc(title)}</span></div>`;
  }

  /** A growing list of files. */
  function listSlot(path, kind, label, step, labelRef) {
    const ids = getPath(check, path) || [];
    return `<div class="media-grid">${ids.map((id) => `<div class="slot">${tile(id, path)}</div>`).join('')}
      <div class="slot">${captureButtons({ path, mode: 'list', kind, label, step, labelRef })}</div></div>`;
  }

  function field(path, label, opts = {}) {
    const id = 'f_' + path.replace(/\./g, '_');
    const val = getPath(check, path) ?? '';
    if (opts.textarea) {
      return `<div class="field"><label for="${id}">${esc(label)}</label><textarea id="${id}" data-bind="${path}" placeholder="${esc(opts.placeholder || '')}">${esc(val)}</textarea></div>`;
    }
    const type = opts.type || 'text';
    const mode = opts.decimal ? ' inputmode="decimal"' : '';
    return `<div class="field"><label for="${id}">${esc(label)}</label><input id="${id}" type="${type}"${mode} data-bind="${path}" value="${esc(val)}" placeholder="${esc(opts.placeholder || '')}"${opts.required ? ' required' : ''} autocomplete="off"></div>`;
  }

  function progressBar(active) {
    return `<div class="progress" aria-hidden="true">${STEPS.map((s, i) => `<span class="${i <= active ? 'on' : ''}"></span>`).join('')}</div>`;
  }

  function shortName() {
    const n = check.customer.name.trim();
    return n.length > 18 ? n.slice(0, 17) + '…' : n;
  }

  // ---------------------------------------------------------------- views

  // ---------------------------------------------------------------- login

  const auth = { user: null, checked: false };

  function needLogin() {
    if (!document.querySelector('[data-login-form]')) render();
  }

  function viewLogin() {
    $app.innerHTML = `
      <header class="bar"><span class="kicker">Baubarkeits-Check</span><h1>Anmelden</h1></header>
      <main>
        <form class="card" data-login-form>
          <div class="field"><label for="l_user">Benutzer</label><input id="l_user" name="user" autocomplete="username" autocapitalize="none" required></div>
          <div class="field"><label for="l_pass">Passwort</label><input id="l_pass" name="password" type="password" autocomplete="current-password" required></div>
          <p class="sub" data-login-error role="alert"></p>
          <button class="btn primary big" type="submit">Anmelden</button>
        </form>
        <p class="sub">Ohne Anmeldung kannst du offline weiter erfassen – hochgeladen wird erst nach dem Anmelden.</p>
        <button class="btn" data-action="skip-login">Offline weiterarbeiten</button>
      </main>`;
  }

  $app.addEventListener('submit', async (e) => {
    if (!e.target.matches('[data-login-form]')) return;
    e.preventDefault();
    const fd = new FormData(e.target);
    const err = e.target.querySelector('[data-login-error]');
    err.textContent = '';
    try {
      const res = await apiFetch('login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user: fd.get('user'), password: fd.get('password') }),
      });
      auth.user = res.user;
      auth.skipped = false;
      await loadConfig();
      render();
      scheduleSync(100);
    } catch (ex) {
      err.textContent = ex.message;
    }
  });

  async function loadConfig() {
    try {
      const c = await apiFetch('config');
      serverConfig = c;
      auth.user = c.user || null;
      if (c.user && c.storageOk === false) toast(c.storageError || 'Server: Datenordner nicht beschreibbar', 12000);
    } catch (e) {
      if (e.status >= 500) toast(e.message, 6000);
    }
    auth.checked = true;
  }

  async function viewHome() {
    check = null;
    // Show this device's list right away; the sync brings in customers from other devices.
    if (!viewHome.synced || Date.now() - viewHome.synced > 15000) {
      viewHome.synced = Date.now();
      scheduleSync(50);
    }
    const checks = (await DB.allChecks())
      .filter((c) => !c.deletedAt)
      .sort((a, b) => String(b.updatedAt).localeCompare(String(a.updatedAt)));
    const rows = await Promise.all(
      checks.map(async (c) => {
        const pending = (await DB.mediaForCheck(c.id)).filter((m) => !m.uploaded).length;
        const st = checkStatus(c);
        // Open the first missing step; complete checks open the summary.
        const target = !c.customer.name ? 'kunde' : st.firstMissing ? `step/${st.firstMissing}` : 'abschluss';
        const name = c.customer.name || 'Ohne Namen';
        const archived = !!c.archived;
        // Swipe row: dragging right reveals "Archivieren"/"Wiederherstellen", dragging left reveals "Löschen".
        const html = `<div class="swipe" data-swipe="${c.id}" data-name="${esc(name)}" data-archived="${archived ? 1 : 0}">
          <div class="swipe-track">
          <div class="swipe-bg archive" aria-hidden="true">${icon(archived ? 'restore' : 'archive', 20)}<span>${archived ? 'Wiederherstellen' : 'Archivieren'}</span></div>
          <div class="swipe-bg delete" aria-hidden="true"><span>Löschen</span>${icon('trash', 20)}</div>
          <a class="customer ${st.level}" href="#/check/${c.id}/${target}" draggable="false">
          <span class="dot" aria-hidden="true"></span>
          <div class="grow">
            <div class="title">${esc(c.customer.name || 'Ohne Namen')}</div>
            <div class="small">${esc(c.customer.address || '')}${c.customer.date ? ' · ' + esc(fmtDate(c.customer.date)) : ''}</div>
            ${st.missing.length ? `<div class="small missing">Fehlt: ${esc(st.missing.join(', '))}</div>` : ''}
            ${pending ? `<div class="small">${pending} Datei(en) noch nicht hochgeladen</div>` : ''}
          </div>
          <span class="status"><span class="badge">${st.label}</span><span class="count">${st.done}/6</span></span></a>
          </div>
          <div class="swipe-keys">
            <button class="btn small" data-action="row-archive" data-id="${c.id}">${archived ? 'Wiederherstellen' : 'Archivieren'}<span class="visually-hidden">: ${esc(name)}</span></button>
            <button class="btn small danger" data-action="row-delete" data-id="${c.id}">Löschen<span class="visually-hidden">: ${esc(name)}</span></button>
          </div>
        </div>`;
        return { level: st.level, archived, html };
      })
    );
    const order = { red: 0, yellow: 1, green: 2 };
    const active = rows.filter((r) => !r.archived);
    const archivedRows = rows.filter((r) => r.archived);
    const open = active.filter((r) => r.level !== 'green').sort((a, b) => order[a.level] - order[b.level]);
    const complete = active.filter((r) => r.level === 'green');
    $app.innerHTML = `
      <header class="bar"><span class="kicker">Baubarkeits-Check</span><h1>Hauptmenü</h1></header>
      <main>
        ${serverConfig.storageOk === false ? `<div class="hint warn" role="alert">${icon('warn')}<span><strong>Server-Speicher nicht bereit:</strong> ${esc(serverConfig.storageError || '')}</span></div>` : ''}
        <section class="card"><div class="row"><h2>Offene Kunden</h2><span class="small-muted">${open.length}</span></div>
          ${open.length ? `<div class="customers">${open.map((r) => r.html).join('')}</div>` : '<p class="sub">Keine offenen Kunden.</p>'}
          <p class="legend"><span class="lg green"></span>vollständig <span class="lg yellow"></span>etwas fehlt <span class="lg red"></span>ungenügend</p>
          ${rows.length ? '<p class="legend">Wischen: nach rechts → archivieren, nach links ← löschen</p>' : ''}
        </section>
        ${complete.length ? `<section class="card"><div class="row"><h2>Vollständig</h2><span class="small-muted">${complete.length}</span></div>
          <div class="customers">${complete.map((r) => r.html).join('')}</div></section>` : ''}
        ${archivedRows.length ? `<details class="card archive-box"><summary><h2>Archiv</h2><span class="small-muted">${archivedRows.length}</span></summary>
          <div class="customers">${archivedRows.map((r) => r.html).join('')}</div>
          <p class="legend">Wischen: nach rechts → wiederherstellen, nach links ← löschen</p></details>` : ''}
        <section class="card" style="gap:8px"><h2>Für das Kundengespräch</h2>
          <a class="btn" href="wirtschaftlichkeit/">Wirtschaftlichkeitsrechner</a>
          <a class="btn" href="faq/">FAQ Wärmepumpe (100 Antworten)</a>
          <a class="btn" href="checkliste/">Checkliste Beratungstermin</a>
          <a class="btn" href="baustelle/">Baustellen-Fotos</a></section>
        <section class="card" style="gap:8px"><h2>Für mich</h2>
          <a class="btn" href="fahrtenbuch/">Fahrtenbuch</a></section>
        ${auth.user ? `<p class="sub">Angemeldet als ${esc(auth.user)} · <button class="btn small" data-action="logout">Abmelden</button></p>` : ''}
      </main>
      <footer class="actions"><button class="btn primary big" data-action="new">Neuer Check</button></footer>`;
  }

  function viewCustomer() {
    const folderHint = check.folder
      ? `/kunden/${esc(check.folder)}/`
      : `/kunden/${esc([check.customer.number, check.customer.name].filter(Boolean).join('_') || '…')}/${esc(check.customer.date || '')}/`;
    $app.innerHTML = `
      <header class="bar">
        <div class="bar-top"><a class="icon-btn" href="#/" aria-label="Zur Übersicht">${icon('back', 22)}</a><span class="sync" data-sync></span></div>
        <span class="kicker">Baubarkeits-Check</span><h1>Kundendaten</h1>
      </header>
      <main>
        <section class="card">
          ${field('customer.name', 'Name *', { required: true, placeholder: 'z. B. Familie Muster' })}
          ${field('customer.address', 'Adresse', { placeholder: 'Straße, PLZ Ort' })}
          <div class="grid2">${field('customer.number', 'Kunden-Nr.')}${field('customer.date', 'Datum', { type: 'date' })}</div>
          <div class="grid2">${field('customer.phone', 'Telefon', { type: 'tel' })}${field('customer.email', 'E-Mail', { type: 'email' })}</div>
          ${field('customer.advisor', 'Berater')}
          <div class="hint info">${icon('folder')}<span>Server-Ordner: <strong data-folder>${folderHint}</strong></span></div>
        </section>
        <section class="card"><h2>Ablauf (6 Schritte)</h2>
          <ol style="margin:0;padding-left:20px;display:flex;flex-direction:column;gap:6px;font-size:14px">
            ${STEPS.map((s) => `<li>${esc(s.title)} – <span class="sub">${esc(s.sub)}</span></li>`).join('')}
          </ol>
        </section>
      </main>
      <footer class="actions"><button class="btn primary big" data-action="start">${check.folder ? 'Weiter' : 'Check starten'}</button></footer>`;
    renderSync();
  }

  function stepShell(n, body) {
    const s = STEPS[n - 1];
    const next = n < 6 ? `Weiter zu Schritt ${n + 1}` : 'Zum Abschluss';
    $app.innerHTML = `
      <header class="bar">
        <div class="bar-top">
          <button class="icon-btn" data-action="prev" aria-label="Zurück">${icon('back', 22)}</button>
          <span class="step-of">Schritt ${n} von 6</span>
          <span class="sync" data-sync style="max-width:45%"></span>
        </div>
        ${progressBar(n - 1)}
        <h1>${esc(s.title)}</h1>
        <p class="sub">${esc(s.sub)} · ${esc(shortName())}</p>
      </header>
      <main>${body}</main>
      <footer class="actions">
        <button class="btn" data-action="prev">Zurück</button>
        <button class="btn primary" data-action="next">${next}</button>
      </footer>`;
    renderSync();
  }

  function viewHeizkoerper() {
    const items = check.steps.heizkoerper.items;
    const cards = items
      .map((it, i) => {
        const base = `steps.heizkoerper.items.${i}`;
        const n = [it.photos.gesamt, it.photos.hahnblock].filter(Boolean).length;
        const name = `HK${i + 1}`;
        return `<section class="card${n < 2 ? ' active' : ''}">
          <div class="row"><h2 data-hk-title="${i}">${hkTitle(i)}</h2>
            <span class="pill ${n === 2 ? 'ok' : 'todo'}">${n}/2 Fotos</span></div>
          ${field(base + '.room', 'Raum', { placeholder: 'z. B. Wohnzimmer' })}
          <div class="grid2">
            ${singleSlot(base + '.photos.gesamt', 'photo', 'Gesamtansicht', `${name}_{}_Gesamt`, 'heizkoerper', base + '.room')}
            ${singleSlot(base + '.photos.hahnblock', 'photo', 'Hahnblock', `${name}_{}_Hahnblock`, 'heizkoerper', base + '.room')}
          </div>
          <div class="grid3">
            ${field(base + '.width', 'Breite cm', { decimal: true })}
            ${field(base + '.height', 'Höhe cm', { decimal: true })}
            ${field(base + '.depth', 'Tiefe cm', { decimal: true })}
          </div>
          <div class="grid2">${field(base + '.type', 'Typ', { placeholder: 'z. B. 22' })}
            <div class="field" style="justify-content:flex-end"><button class="btn danger" data-action="remove-item" data-path="steps.heizkoerper.items" data-index="${i}">${icon('trash')}Entfernen</button></div></div>
          ${field(base + '.note', 'Notiz', { placeholder: 'optional' })}
        </section>`;
      })
      .join('');
    stepShell(1, `${cards}<button class="btn dashed" data-action="add-hk">+ Heizkörper hinzufügen</button>`);
  }

  function viewZaehler() {
    stepShell(
      2,
      `<section class="card"><h2>Fotos Zählerschrank</h2>
        <div class="hint info">${icon('camera')}<span>Tür ganz öffnen und ca. 20 cm Umfeld mit aufs Bild nehmen.</span></div>
        ${listSlot('steps.zaehler.photos', 'photo', 'Zaehlerschrank', 'zaehler')}
        ${field('steps.zaehler.note', 'Notiz', { textarea: true, placeholder: 'z. B. freie Plätze, Hauptsicherung, Zählerart' })}
      </section>`
    );
  }

  function viewHeizraum() {
    stepShell(
      3,
      `<section class="card"><h2>360°-Video</h2>
        ${listSlot('steps.heizraum.videos', 'video', 'Heizraum_360', 'heizraum')}
      </section>
      <section class="card"><h2>Zusätzliche Fotos & Maße (optional)</h2>
        ${listSlot('steps.heizraum.photos', 'photo', 'Heizraum_Foto', 'heizraum')}
        <div class="grid3">
          ${field('steps.heizraum.length', 'Länge m', { decimal: true })}
          ${field('steps.heizraum.width', 'Breite m', { decimal: true })}
          ${field('steps.heizraum.heightCm', 'Höhe cm', { decimal: true })}
        </div>
        ${field('steps.heizraum.note', 'Notiz', { textarea: true })}
      </section>`
    );
  }

  function hkTitle(i) {
    const room = check.steps.heizkoerper.items[i].room;
    return `HK ${i + 1}${room ? ' · ' + esc(room) : ''}`;
  }

  function wegWarn() {
    const min = minWidth(check);
    if (min === null) return '';
    const need = serverConfig.minWidthCm;
    const tooNarrow = need && min < need;
    return `<div class="hint ${tooNarrow ? 'warn' : 'info'}">${icon(tooNarrow ? 'warn' : 'check')}<span>Engste Stelle ${String(min).replace('.', ',')} cm${need ? ` – benötigt: ${need} cm` : ''}</span></div>`;
  }

  function viewWeg() {
    const weg = check.steps.weg;
    const items = weg.items
      .map((e, i) => {
        const base = `steps.weg.items.${i}`;
        return `<section class="card">
          <div class="row"><h2>Engstelle ${i + 1}</h2>
            <button class="btn small danger" data-action="remove-item" data-path="steps.weg.items" data-index="${i}">${icon('trash')}Entfernen</button></div>
          <div class="grid2">${field(base + '.label', 'Bezeichnung', { placeholder: 'z. B. Kellertür' })}${field(base + '.widthCm', 'Breite cm', { decimal: true })}</div>
          ${listSlot(base + '.photos', 'photo', `Engstelle${i + 1}_{}`, 'weg', base + '.label')}
        </section>`;
      })
      .join('');
    stepShell(
      4,
      `<section class="card"><h2>Video Heizraum → Haustür</h2>
        ${listSlot('steps.weg.videos', 'video', 'Weg_Heizraum_Haustuer', 'weg')}
      </section>
      <div data-weg-warn>${wegWarn()}</div>
      ${items}
      <button class="btn dashed" data-action="add-eng">+ Engstelle erfassen (Foto mit Zollstock)</button>
      <section class="card">${field('steps.weg.note', 'Notiz zum Weg', { textarea: true })}</section>`
    );
  }

  function viewHausseiten() {
    const sides = check.steps.hausseiten.sides;
    const cur = (check.ui && check.ui.side) || 'nord';
    const curName = SIDES.find(([k]) => k === cur)[1];
    const base = `steps.hausseiten.sides.${cur}`;
    const tabs = SIDES.map(
      ([k, name]) =>
        `<button data-action="side" data-side="${k}" aria-pressed="${k === cur}" class="${sides[k].photos.length ? 'done' : ''}">${name}${sides[k].photos.length ? ' ✓' : ''}</button>`
    ).join('');
    stepShell(
      5,
      `<div class="tabs" role="group" aria-label="Hausseite wählen">${tabs}</div>
      <section class="card"><h2>${curName}seite</h2>
        ${listSlot(base + '.photos', 'photo', 'Hausseite_' + curName, 'hausseiten')}
        <div class="grid2">
          ${field(base + '.fensterM', 'Abstand Nachbarfenster (m)', { decimal: true })}
          ${field(base + '.grenzeM', 'Abstand Grenze (m)', { decimal: true })}
        </div>
        ${field(base + '.note', 'Notiz (z. B. möglicher Aufstellort)', { textarea: true })}
      </section>`
    );
  }

  function viewUnterlagen() {
    const u = check.steps.unterlagen;
    const cards = DOCS.map(
      ([k, name]) => `<section class="card"><div class="row"><h2>${name}</h2>
        <span class="pill ${u[k].length ? 'ok' : 'todo'}">${u[k].length ? 'vorhanden' : 'fehlt'}</span></div>
        ${listSlot('steps.unterlagen.' + k, 'doc', name.replace(/[^A-Za-zÄÖÜäöüß]+/g, '_'), 'unterlagen')}</section>`
    ).join('');
    stepShell(6, `${cards}<section class="card">${field('steps.unterlagen.note', 'Notiz', { textarea: true, placeholder: 'z. B. Verbrauch kWh/Jahr' })}</section>`);
  }

  function viewAbschluss() {
    const rows = STEPS.map((s, i) => {
      const ok = stepDone(check, s.id);
      return `<a class="list-item" href="#/check/${check.id}/step/${i + 1}">
        <span style="color:${ok ? 'var(--accent)' : 'var(--warn-ink)'}">${icon(ok ? 'check' : 'warn', 18)}</span>
        <span class="grow" style="font-size:14px">${esc(s.title)}</span>
        <span class="small">${esc(stepSummary(check, s.id))}</span></a>`;
    }).join('');
    const total = media.size;
    const done = total - pendingCount();
    $app.innerHTML = `
      <header class="bar">
        <div class="bar-top"><button class="icon-btn" data-action="prev" aria-label="Zurück">${icon('back', 22)}</button>
          <span class="step-of">Abschluss</span><span class="sync" data-sync style="max-width:45%"></span></div>
        <h1>${STEPS.every((s) => stepDone(check, s.id)) ? 'Alles erfasst' : 'Fast fertig'}</h1>
        <p class="sub">${esc(check.customer.name)}${check.customer.address ? ' · ' + esc(check.customer.address) : ''}</p>
      </header>
      <main>
        <section class="card" style="padding:6px 14px"><div class="list">${rows}</div></section>
        <section class="card" style="gap:8px">
          <div class="row" style="font-size:13px"><strong>Upload auf Server</strong><strong style="color:var(--accent-ink)" data-upload-count>${done} / ${total}</strong></div>
          <div class="bar-fill"><span data-upload-bar style="width:${total ? Math.round((done / total) * 100) : 100}%"></span></div>
          <span class="sub">${check.folder ? '/kunden/' + esc(check.folder) + '/' : 'Ordner wird beim ersten Upload angelegt'}</span>
        </section>
        <section class="card">${field('remarks', 'Bemerkungen / Fazit für den Bericht', { textarea: true })}</section>
      </main>
      <footer class="actions stack">
        <button class="btn primary big" data-action="report">Bericht erstellen (PDF)</button>
        <button class="btn" data-action="share">Bericht teilen / per E-Mail senden</button>
        <button class="btn" data-action="home">Zurück zum Hauptmenü</button>
      </footer>`;
    renderSync();
  }

  // ---------------------------------------------------------------- report

  async function ensureSynced() {
    if (!navigator.onLine) throw new Error('Keine Internetverbindung – Bericht kann erst online erstellt werden.');
    if (!auth.user) throw new Error('Bitte zuerst anmelden.');
    await save({ immediate: true });
    await sync();
    if (pendingOps) throw new Error('Daten konnten nicht gespeichert werden. Bitte erneut versuchen.');
    if (pendingCount()) {
      if (!confirm(`${pendingCount()} Datei(en) sind noch nicht hochgeladen und fehlen im Bericht. Trotzdem erstellen?`)) throw new Error('abgebrochen');
    }
  }

  async function fetchReport() {
    const r = await fetch(api(`checks/${check.id}/report.pdf`), { credentials: 'same-origin' });
    if (r.status === 401) {
      auth.user = null;
      needLogin();
    }
    if (!r.ok) throw new Error((await r.json().catch(() => ({}))).error || `HTTP ${r.status}`);
    const blob = await r.blob();
    const name = `Bericht_${(check.folder || check.customer.name).replace(/[^A-Za-z0-9_-]+/g, '_')}.pdf`;
    return new File([blob], name, { type: 'application/pdf' });
  }

  async function makeReport(share) {
    try {
      toast('Bericht wird erstellt …', 8000);
      await ensureSynced();
      const file = await fetchReport();
      toast('Bericht erstellt und im Kundenordner gespeichert');
      if (share && navigator.canShare && navigator.canShare({ files: [file] })) {
        await navigator.share({ files: [file], title: 'Baubarkeits-Check', text: `Bericht ${check.customer.name}` });
        return;
      }
      const url = URL.createObjectURL(file);
      if (share) {
        const a = document.createElement('a');
        a.href = url;
        a.download = file.name;
        document.body.appendChild(a);
        a.click();
        a.remove();
        const body = encodeURIComponent(`Anbei der Bericht zum Baubarkeits-Check (${check.customer.name}). Die PDF-Datei wurde heruntergeladen und kann angehängt werden.`);
        location.href = `mailto:${encodeURIComponent(check.customer.email || '')}?subject=${encodeURIComponent('Baubarkeits-Check – Bericht')}&body=${body}`;
      } else {
        window.open(url, '_blank') || (location.href = url);
      }
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (e) {
      if (e.name !== 'AbortError' && e.message !== 'abgebrochen') toast(e.message, 5000);
    }
  }

  // ---------------------------------------------------------------- swipe: archive / delete

  /** Confirmation sheet; resolves true/false. Falls back to confirm() without <dialog>. */
  function confirmSheet({ title, text, ok, danger }) {
    if (typeof HTMLDialogElement === 'undefined') return Promise.resolve(confirm(`${title}\n\n${text}`));
    return new Promise((resolve) => {
      const d = document.createElement('dialog');
      d.className = 'sheet';
      d.innerHTML = `<form method="dialog">
        <h2>${esc(title)}</h2><p class="sub">${esc(text)}</p>
        <div class="sheet-actions">
          <button class="btn big" value="cancel">Abbrechen</button>
          <button class="btn big ${danger ? 'danger-solid' : 'primary'}" value="ok">${esc(ok)}</button>
        </div></form>`;
      document.body.appendChild(d);
      d.addEventListener('close', () => {
        resolve(d.returnValue === 'ok');
        d.remove();
      });
      d.addEventListener('click', (e) => e.target === d && d.close('cancel')); // tap on backdrop
      d.showModal();
    });
  }

  async function archiveCheck(id, name, archived) {
    const ok = await confirmSheet(
      archived
        ? { title: 'Wiederherstellen?', text: `„${name}“ wieder in die Kundenliste übernehmen?`, ok: 'Wiederherstellen' }
        : { title: 'Archivieren?', text: `„${name}“ ins Archiv verschieben? Die Daten bleiben erhalten und lassen sich wiederherstellen.`, ok: 'Archivieren' }
    );
    if (!ok) return false;
    const c = await DB.getCheck(id);
    if (!c) return false;
    c.archived = !archived;
    c.updatedAt = new Date().toISOString();
    await DB.putCheck(c);
    await queueOp(id, { type: 'set', path: ['archived'], value: c.archived });
    toast(archived ? 'Wiederhergestellt' : 'Archiviert');
    return true;
  }

  async function deleteCheck(id, name) {
    const media = await DB.mediaForCheck(id);
    const pending = media.filter((m) => !m.uploaded).length;
    const ok = await confirmSheet({
      title: 'Kunde löschen?',
      text:
        `„${name}“ wird auf allen Geräten aus der Kundenliste entfernt. Hochgeladene Fotos und Dokumente bleiben im Kundenordner auf dem Server (FTP).` +
        (pending ? ` Achtung: ${pending} Datei(en) sind noch nicht hochgeladen und gehen verloren!` : '') +
        ' Das kann in der App nicht rückgängig gemacht werden.',
      ok: 'Endgültig löschen',
      danger: true,
    });
    if (!ok) return false;
    const c = await DB.getCheck(id);
    if (!c) return false;
    // Mark as deleted; the next sync tells the server, so it disappears on all devices.
    c.deletedAt = new Date().toISOString();
    await DB.putCheck(c);
    for (const m of media) if (!m.uploaded) await DB.deleteMedia(m.id);
    await queueOp(id, { type: 'delete' });
    toast('Gelöscht');
    return true;
  }

  async function purgeLocal(id) {
    for (const m of await DB.mediaForCheck(id)) await DB.deleteMedia(m.id);
    await DB.deleteCheck(id);
  }

  // ---------------------------------------------------------------- changes from other devices

  let deferredRender = false;
  /** Show what other devices changed, without stealing focus from a field being typed in. */
  function onRemoteChange(ids) {
    const onHome = (location.hash || '#/') === '#/';
    if (onHome) {
      if (!document.querySelector('.swipe.dragging, dialog[open]')) viewHome();
      return;
    }
    if (!check || !ids.has(check.id)) return;
    if (check.deletedAt) {
      toast('Dieser Kunde wurde auf einem anderen Gerät gelöscht.', 5000);
      location.hash = '#/';
      return;
    }
    const a = document.activeElement;
    if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) deferredRender = true;
    else render();
  }
  document.addEventListener('focusout', () => {
    setTimeout(() => {
      const a = document.activeElement;
      if (deferredRender && !(a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName))) {
        deferredRender = false;
        render();
      }
    }, 0);
  });
  document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && scheduleSync(100));

  let drag = null;
  const SWIPE_THRESHOLD = 0.35; // share of the row width

  $app.addEventListener('pointerdown', (e) => {
    const row = e.target.closest('.swipe');
    if (!row || e.button > 0 || e.target.closest('button')) return;
    drag = { row, card: row.querySelector('.customer'), x: e.clientX, y: e.clientY, dx: 0, active: false, id: e.pointerId };
  });

  $app.addEventListener('pointermove', (e) => {
    if (!drag || e.pointerId !== drag.id) return;
    const dx = e.clientX - drag.x;
    const dy = e.clientY - drag.y;
    if (!drag.active) {
      if (Math.abs(dy) > 10 && Math.abs(dy) > Math.abs(dx)) {
        drag = null; // vertical scroll
        return;
      }
      if (Math.abs(dx) < 10) return;
      drag.active = true;
      drag.row.classList.add('dragging');
      try {
        drag.row.setPointerCapture(e.pointerId);
      } catch (_) {
        /* ignore */
      }
    }
    drag.dx = dx;
    drag.card.style.transform = `translateX(${dx}px)`;
    const w = drag.row.offsetWidth;
    drag.row.classList.toggle('show-archive', dx > 0);
    drag.row.classList.toggle('show-delete', dx < 0);
    drag.row.classList.toggle('armed', Math.abs(dx) > w * SWIPE_THRESHOLD);
  });

  async function endDrag(e) {
    if (!drag || e.pointerId !== drag.id) return;
    const d = drag;
    drag = null;
    if (!d.active) return;
    d.row.dataset.justDragged = '1';
    setTimeout(() => delete d.row.dataset.justDragged, 400);
    const w = d.row.offsetWidth;
    const { swipe: id, name, archived } = d.row.dataset;
    let changed = false;
    if (Math.abs(d.dx) > w * SWIPE_THRESHOLD && e.type === 'pointerup') {
      d.card.style.transform = `translateX(${d.dx > 0 ? w : -w}px)`;
      changed = d.dx > 0 ? await archiveCheck(id, name, archived === '1') : await deleteCheck(id, name);
    }
    if (changed) return render();
    d.row.classList.remove('dragging', 'armed');
    d.card.style.transform = '';
    setTimeout(() => d.row.classList.remove('show-archive', 'show-delete'), 200);
  }
  $app.addEventListener('pointerup', endDrag);
  $app.addEventListener('pointercancel', endDrag);

  // A swipe must not also open the customer.
  $app.addEventListener(
    'click',
    (e) => {
      const row = e.target.closest('.swipe');
      if (row && row.dataset.justDragged) {
        e.preventDefault();
        e.stopPropagation();
      }
    },
    true
  );

  // ---------------------------------------------------------------- routing

  async function render() {
    if (auth.checked && !auth.user && !auth.skipped && navigator.onLine) return viewLogin();
    const hash = location.hash || '#/';
    const m = hash.match(/^#\/check\/([a-f0-9]+)\/(kunde|step\/(\d)|abschluss)$/);
    if (!m) return viewHome();
    const c = await loadCheck(m[1]);
    if (!c) {
      location.hash = '#/';
      return;
    }
    const y = window.scrollY;
    const sameView = render.last === hash;
    render.last = hash;
    if (m[2] === 'kunde') viewCustomer();
    else if (m[2] === 'abschluss') viewAbschluss();
    else {
      const n = Number(m[3]);
      [viewHeizkoerper, viewZaehler, viewHeizraum, viewWeg, viewHausseiten, viewUnterlagen][n - 1]();
    }
    window.scrollTo(0, sameView ? y : 0);
  }

  function currentStep() {
    const m = location.hash.match(/step\/(\d)/);
    if (m) return Number(m[1]);
    if (/abschluss$/.test(location.hash)) return 7;
    return 0;
  }

  function go(n) {
    if (n <= 0) location.hash = `#/check/${check.id}/kunde`;
    else if (n >= 7) location.hash = `#/check/${check.id}/abschluss`;
    else location.hash = `#/check/${check.id}/step/${n}`;
  }

  // ---------------------------------------------------------------- events

  $app.addEventListener('input', (e) => {
    const path = e.target.dataset && e.target.dataset.bind;
    if (!path || !check) return;
    setValue(path, e.target.value);
    if (path === 'customer.advisor') {
      try {
        localStorage.setItem('advisor', e.target.value);
      } catch (_) {
        /* ignore */
      }
    }
    if (path.startsWith('customer.') && !check.folder) {
      const f = document.querySelector('[data-folder]');
      if (f) f.textContent = `/kunden/${[check.customer.number, check.customer.name].filter(Boolean).join('_') || '…'}/${check.customer.date || ''}/`;
    }
    // Update dependent text in place (a re-render would steal focus from the next field).
    const hk = path.match(/^steps\.heizkoerper\.items\.(\d+)\.room$/);
    if (hk) {
      const h = document.querySelector(`[data-hk-title="${hk[1]}"]`);
      if (h) h.innerHTML = hkTitle(Number(hk[1]));
    }
    if (/^steps\.weg\.items\.\d+\.widthCm$/.test(path)) {
      const w = document.querySelector('[data-weg-warn]');
      if (w) w.innerHTML = wegWarn();
    }
    save();
  });


  $app.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    const a = btn.dataset.action;
    switch (a) {
      case 'new': {
        const c = newCheck();
        await DB.putCheck(c);
        await queueOp(c.id, { type: 'merge', doc: docOf(c) });
        location.hash = `#/check/${c.id}/kunde`;
        break;
      }
      case 'start':
        if (!check.customer.name.trim()) {
          toast('Bitte zuerst den Kundennamen eingeben.');
          document.getElementById('f_customer_name').focus();
          return;
        }
        if (!check.started) setValue('started', true);
        await save({ immediate: true });
        go(1);
        break;
      case 'prev':
        await save({ immediate: true });
        go(currentStep() - 1);
        break;
      case 'next':
        await save({ immediate: true });
        go(currentStep() + 1);
        break;
      case 'capture': {
        const d = { ...btn.dataset };
        const ref = d.labelRef ? String(getPath(check, d.labelRef) || '').trim() : '';
        d.label = d.label.replace('{}', ref).replace(/_+/g, '_').replace(/^_|_$/g, '');
        capture(d);
        break;
      }
      case 'remove-media': {
        if (!confirm('Diese Aufnahme entfernen?')) return;
        const { path, file } = btn.dataset;
        const p = opPath(check, path);
        const val = getPath(check, path);
        if (Array.isArray(val)) val.splice(val.indexOf(file), 1);
        else setPath(check, path, null);
        await removeMedia(file);
        await queueOp(check.id, { type: 'removeMedia', path: p, mediaId: file });
        await save({ immediate: true });
        render();
        break;
      }
      case 'add-hk': {
        const item = newHK();
        check.steps.heizkoerper.items.push(item);
        await queueOp(check.id, { type: 'addItem', path: ['steps', 'heizkoerper', 'items'], item });
        await save({ immediate: true });
        render();
        break;
      }
      case 'add-eng': {
        const item = { id: uid(), label: '', widthCm: '', photos: [] };
        check.steps.weg.items.push(item);
        await queueOp(check.id, { type: 'addItem', path: ['steps', 'weg', 'items'], item });
        await save({ immediate: true });
        render();
        break;
      }
      case 'remove-item': {
        if (!confirm('Eintrag inkl. Fotos entfernen?')) return;
        const list = getPath(check, btn.dataset.path);
        const [item] = list.splice(Number(btn.dataset.index), 1);
        await queueOp(check.id, { type: 'removeItem', path: opPath(check, btn.dataset.path), itemId: item.id });
        const ids = JSON.stringify(item).match(/[a-f0-9]{24}/g) || [];
        for (const id of ids) if (media.has(id)) await removeMedia(id);
        await save({ immediate: true });
        render();
        break;
      }
      case 'side':
        check.ui = { ...(check.ui || {}), side: btn.dataset.side };
        save();
        render();
        break;
      case 'row-archive':
      case 'row-delete': {
        const row = btn.closest('.swipe');
        const { name, archived } = row.dataset;
        const changed = a === 'row-archive' ? await archiveCheck(btn.dataset.id, name, archived === '1') : await deleteCheck(btn.dataset.id, name);
        if (changed) render();
        break;
      }
      case 'home':
        await save({ immediate: true });
        location.hash = '#/';
        break;
      case 'skip-login':
        auth.skipped = true;
        render();
        break;
      case 'logout':
        await apiFetch('logout', { method: 'POST' }).catch(() => {});
        auth.user = null;
        auth.skipped = false;
        render();
        break;
      case 'report':
        makeReport(false);
        break;
      case 'share':
        makeReport(true);
        break;
      default:
        break;
    }
  });

  window.addEventListener('hashchange', render);

  // ---------------------------------------------------------------- boot

  if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  }
  if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(() => {});

  Promise.all([loadConfig(), migrateLocal().catch(() => {})])
    .then(() => DB.allOps())
    .then((ops) => {
      pendingOps = ops.length;
      render();
      scheduleSync(500);
    });
})();
