/**
 * @file
 * PWA Suite — Background Sync + Web Share API.
 */
(function (Drupal, drupalSettings) {
  'use strict';

  const DB = {
    db: null,
    async open() {
      if (this.db) return this.db;
      return new Promise((resolve, reject) => {
        const req = indexedDB.open('pwa_suite_bg_sync', 1);
        req.onupgradeneeded = e => {
          const db = e.target.result;
          if (!db.objectStoreNames.contains('forms')) {
            db.createObjectStore('forms', { keyPath: 'id', autoIncrement: true });
          }
        };
        req.onsuccess = e => { this.db = e.target.result; resolve(this.db); };
        req.onerror   = e => reject(e.target.error);
      });
    },
    async save(data) {
      const db = await this.open();
      return new Promise((resolve, reject) => {
        const tx = db.transaction('forms', 'readwrite');
        const req = tx.objectStore('forms').add(data);
        req.onsuccess = () => resolve(req.result);
        req.onerror   = e => reject(e.target.error);
      });
    },
    async getAll() {
      const db = await this.open();
      return new Promise((resolve, reject) => {
        const req = db.transaction('forms', 'readonly').objectStore('forms').getAll();
        req.onsuccess = () => resolve(req.result);
        req.onerror   = e => reject(e.target.error);
      });
    },
    async clear() {
      const db = await this.open();
      return new Promise((resolve, reject) => {
        const req = db.transaction('forms', 'readwrite').objectStore('forms').clear();
        req.onsuccess = () => resolve();
        req.onerror   = e => reject(e.target.error);
      });
    },
  };

  const BgSync = {
    interceptForms(context) {
      const s = drupalSettings.pwaSuite || {};
      if (!s.bgSyncEnabled) return;
      context.querySelectorAll('form[data-pwa-bg-sync]').forEach(form => {
        if (form._bgSyncBound) return;
        form._bgSyncBound = true;
        form.addEventListener('submit', async (e) => {
          if (navigator.onLine) return;
          e.preventDefault();
          const data = {};
          new FormData(form).forEach((val, key) => { data[key] = val; });
          await DB.save({ action: form.action || window.location.href, method: form.method || 'POST', data, timestamp: Date.now() });
          if ('serviceWorker' in navigator && 'SyncManager' in window) {
            try {
              const reg = await navigator.serviceWorker.ready;
              await reg.sync.register('pwa-form-sync');
            } catch (err) {
              window.addEventListener('online', () => this.flushPendingForms(), { once: true });
            }
          } else {
            window.addEventListener('online', () => this.flushPendingForms(), { once: true });
          }
          Drupal.announce(Drupal.t('Internet bağlantısı yok. Form online olunca gönderilecek.'));
        });
      });
    },
    async flushPendingForms() {
      const s = drupalSettings.pwaSuite || {};
      const endpoint = s.syncEndpoint || '/pwa/sync/forms';
      let forms = [];
      try { forms = await DB.getAll(); } catch (err) { return; }
      if (!forms.length) return;
      try {
        const res = await fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({ forms }),
          credentials: 'same-origin',
        });
        if (res.ok) await DB.clear();
      } catch (err) {}
    },
  };

  const WebShareAPI = {
    init(context) {
      const s = drupalSettings.pwaSuite || {};
      if (!s.webShareEnabled || !navigator.share) return;
      context.querySelectorAll('[data-pwa-share]').forEach(btn => {
        btn.style.display = '';
        if (btn._shareBound) return;
        btn._shareBound = true;
        btn.addEventListener('click', async (e) => {
          e.preventDefault();
          const shareData = { title: document.title, url: window.location.href };
          const meta = document.querySelector('meta[name="description"]');
          if (meta) shareData.text = meta.getAttribute('content');
          if (btn.dataset.shareTitle) shareData.title = btn.dataset.shareTitle;
          if (btn.dataset.shareText)  shareData.text  = btn.dataset.shareText;
          if (btn.dataset.shareUrl)   shareData.url   = btn.dataset.shareUrl;
          try { await navigator.share(shareData); } catch (err) {}
        });
      });
    },
  };

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (e) => {
      if (e.data && e.data.type === 'FLUSH_FORMS') BgSync.flushPendingForms();
    });
  }

  Drupal.behaviors.pwaBgSync = {
    attach(context) {
      BgSync.interceptForms(context);
      WebShareAPI.init(context);
    },
  };

  window.PwaBgSync = BgSync;
})(Drupal, drupalSettings);
