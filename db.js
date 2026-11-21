/* Tiny IndexedDB wrapper: offline storage for checks and captured media. */
(function () {
  'use strict';

  const DB_NAME = 'baubarkeits-check';
  const VERSION = 2;
  let dbPromise = null;

  function open() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
      const req = indexedDB.open(DB_NAME, VERSION);
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains('checks')) db.createObjectStore('checks', { keyPath: 'id' });
        if (!db.objectStoreNames.contains('media')) {
          const s = db.createObjectStore('media', { keyPath: 'id' });
          s.createIndex('checkId', 'checkId');
        }
        // v2: changes waiting to be sent to the server, and small settings (last server revision …)
        if (!db.objectStoreNames.contains('ops')) {
          const s = db.createObjectStore('ops', { keyPath: 'opId' });
          s.createIndex('checkId', 'checkId');
        }
        if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta', { keyPath: 'key' });
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
    return dbPromise;
  }

  function tx(store, mode, fn) {
    return open().then(
      (db) =>
        new Promise((resolve, reject) => {
          const t = db.transaction(store, mode);
          const s = t.objectStore(store);
          let result;
          Promise.resolve(fn(s)).then((r) => (result = r));
          t.oncomplete = () => resolve(result);
          t.onerror = () => reject(t.error);
          t.onabort = () => reject(t.error);
        })
    );
  }

  const req2p = (req) =>
    new Promise((resolve, reject) => {
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });

  window.DB = {
    getCheck: (id) => tx('checks', 'readonly', (s) => req2p(s.get(id))),
    putCheck: (check) => tx('checks', 'readwrite', (s) => req2p(s.put(check))),
    deleteCheck: (id) => tx('checks', 'readwrite', (s) => req2p(s.delete(id))),
    allChecks: () => tx('checks', 'readonly', (s) => req2p(s.getAll())),

    getMedia: (id) => tx('media', 'readonly', (s) => req2p(s.get(id))),
    putMedia: (m) => tx('media', 'readwrite', (s) => req2p(s.put(m))),
    deleteMedia: (id) => tx('media', 'readwrite', (s) => req2p(s.delete(id))),
    mediaForCheck: (checkId) => tx('media', 'readonly', (s) => req2p(s.index('checkId').getAll(checkId))),

    putOp: (op) => tx('ops', 'readwrite', (s) => req2p(s.put(op))),
    deleteOp: (id) => tx('ops', 'readwrite', (s) => req2p(s.delete(id))),
    allOps: () => tx('ops', 'readonly', (s) => req2p(s.getAll())).then((a) => a.sort((x, y) => x.seq - y.seq)),
    opsForCheck: (checkId) => tx('ops', 'readonly', (s) => req2p(s.index('checkId').getAll(checkId))).then((a) => a.sort((x, y) => x.seq - y.seq)),

    getMeta: (key) => tx('meta', 'readonly', (s) => req2p(s.get(key))).then((r) => (r ? r.value : undefined)),
    setMeta: (key, value) => tx('meta', 'readwrite', (s) => req2p(s.put({ key, value }))),
  };
})();
