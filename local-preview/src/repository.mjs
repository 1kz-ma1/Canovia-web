import {emptyState, validate, LocalError, requireValue} from './model.mjs';
export const DB_NAME = 'canovia-local-preview-v1';
export class Repository {
  constructor(db) {
    this.db = db; this.closed = false;
    db.onversionchange = () => { this.closed = true; db.close(); this.onchange?.('VERSION_CHANGED'); };
    this.channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel(DB_NAME) : null;
    if (this.channel) this.channel.onmessage = () => this.onchange?.('CHANGED');
  }
  static open() {
    return new Promise((resolve, reject) => {
      if (!globalThis.indexedDB || !globalThis.crypto?.randomUUID) { reject(new LocalError('STORAGE_UNAVAILABLE')); return; }
      let request;
      try { request = indexedDB.open(DB_NAME, 1); } catch { reject(new LocalError('STORAGE_UNAVAILABLE')); return; }
      request.onupgradeneeded = () => {
        // Dedicated database only; no existing Canovia stores are touched.
        request.result.createObjectStore('state');
      };
      let blocked = false;
      request.onblocked = () => { blocked = true; reject(new LocalError('VERSION_BLOCKED')); };
      request.onerror = () => reject(new LocalError('STORAGE_UNAVAILABLE'));
      request.onsuccess = async () => {
        if (blocked) { request.result.close(); return; }
        const repo = new Repository(request.result);
        try { await repo.initialize(); resolve(repo); } catch (e) { repo.close(); reject(e); }
      };
    });
  }
  transaction(update) {
    return new Promise((resolve, reject) => {
      let tx, result, failure, written = false;
      try {
        requireValue(!this.closed, 'VERSION_CHANGED');
        tx = this.db.transaction('state', update ? 'readwrite' : 'readonly');
        const store = tx.objectStore('state'); const request = store.get('root');
        request.onsuccess = () => {
          try {
            const before = request.result;
            if (before !== undefined) validate(before);
            result = update ? update(before) : before;
            if (update && result !== before) { validate(result); store.put(result, 'root'); written = true; }
          } catch (e) { failure = e instanceof LocalError ? e : new LocalError(e?.name === 'QuotaExceededError' ? 'STORAGE_FULL' : 'INVALID_DATA'); tx.abort(); }
        };
        tx.oncomplete = () => { if (written) this.channel?.postMessage('CHANGED'); resolve(structuredClone(result)); };
        tx.onabort = () => reject(failure || new LocalError(tx.error?.name === 'QuotaExceededError' ? 'STORAGE_FULL' : 'SAVE_FAILED'));
        tx.onerror = () => {}; // onabort owns the failed operation; never announce saved.
      } catch (e) { reject(e instanceof LocalError ? e : new LocalError('STORAGE_UNAVAILABLE')); }
    });
  }
  initialize() { return this.transaction(before => before ?? emptyState()); }
  read() { return this.transaction(); }
  change(revision, mutate) {
    return this.transaction(before => {
      requireValue(before && before.revision === revision, 'CONFLICT');
      const next = structuredClone(before); const result = mutate(next);
      requireValue(!result?.then, 'ASYNC_MUTATION_REFUSED');
      next.revision++; return next;
    });
  }
  close() { this.closed = true; this.db.close(); this.channel?.close(); }
}
