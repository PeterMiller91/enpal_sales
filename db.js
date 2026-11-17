/* Tiny IndexedDB wrapper: offline storage for checks and captured media. */
(function () {
  'use strict';

  const DB_NAME = 'baubarkeits-check';
  const VERSION = 1;
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
  };
})();
