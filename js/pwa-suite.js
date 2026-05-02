/**
 * @file
 * PWA Suite — Push Aboneliği (9 varyant), Loading States, Toast, SW, Install.
 */
(function (Drupal, drupalSettings) {
  'use strict';

  const DEBUG = new URLSearchParams(window.location.search).has('pwa-debug');
  if (DEBUG) console.info('[PWA Suite] DEBUG modu aktif');

  // ── Yardımcılar ──────────────────────────────────────────────────────────

  function urlBase64ToUint8Array(b) {
    const p = '='.repeat((4 - b.length % 4) % 4);
    const raw = atob((b + p).replace(/-/g, '+').replace(/_/g, '/'));
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  function postJSON(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body),
      credentials: 'same-origin',
    });
  }

  /** Browser'da gerçekten push subscription var mı? */
  async function isBrowserSubscribed() {
    try {
      if (!('serviceWorker' in navigator) || !('PushManager' in window)) return false;
      const reg = await navigator.serviceWorker.getRegistration('/');
      if (!reg) return false;
      return !!(await reg.pushManager.getSubscription());
    } catch (_) { return false; }
  }

  /**
   * PushManager'ın server-doğrulamalı abonelik durumunu bekler (max 3 sn).
   * pushEnabled=false ise browser'a bakar.
   */
  async function waitForSubscriptionStatus() {
    if (PushManager._subscribed !== null) return PushManager._subscribed;
    const deadline = Date.now() + 3000;
    while (Date.now() < deadline) {
      await new Promise(r => setTimeout(r, 80));
      if (PushManager._subscribed !== null) return PushManager._subscribed;
    }
    return await isBrowserSubscribed();
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // LoadingManager — butonlara loading state ekler/kaldırır
  // ═══════════════════════════════════════════════════════════════════════════
  const LoadingManager = {
    /** Tüm subscribe/unsubscribe butonlarını loading moduna al */
    start() {
      document.querySelectorAll('[data-pwa-subscribe],[data-pwa-unsubscribe],[data-pwa-push-toggle],[data-pwa-push-switch]').forEach(el => {
        if (el.disabled) return;
        el._pwa_original_html = el.innerHTML;
        el.disabled = true;
        el.classList.add('pwa-btn--loading');

        // Buton tipi değil switch ise farklı yaklaşım.
        if (el.hasAttribute('data-pwa-push-switch')) {
          el.setAttribute('aria-busy', 'true');
          return;
        }

        // Label'i koru, spinner ekle.
        const label = el.querySelector('.pwa-btn__label, .pwa-fab__label, .pwa-btn__text');
        if (label) label.setAttribute('data-pwa-original-text', label.textContent);
        el.innerHTML =
          '<span class="pwa-spinner" aria-hidden="true"></span>' +
          '<span class="pwa-btn__label">' + Drupal.t('Lütfen bekleyin...') + '</span>';
      });
    },

    /** Loading modunu kaldır */
    stop() {
      document.querySelectorAll('[data-pwa-subscribe],[data-pwa-unsubscribe],[data-pwa-push-toggle],[data-pwa-push-switch]').forEach(el => {
        el.disabled = false;
        el.classList.remove('pwa-btn--loading');
        el.removeAttribute('aria-busy');
        if (el._pwa_original_html !== undefined) {
          el.innerHTML = el._pwa_original_html;
          delete el._pwa_original_html;
        }
      });
    },
  };

  // ═══════════════════════════════════════════════════════════════════════════
  // ToastManager — başarı/hata bildirimleri
  // ═══════════════════════════════════════════════════════════════════════════
  const ToastManager = {
    _container: null,

    _getContainer() {
      if (!this._container || !this._container.isConnected) {
        this._container = document.createElement('div');
        this._container.className = 'pwa-toast-container';
        this._container.setAttribute('aria-live', 'polite');
        this._container.setAttribute('aria-atomic', 'false');
        document.body.appendChild(this._container);
      }
      return this._container;
    },

    show(type, message) {
      const toast = document.createElement('div');
      const icons = { success: '✅', error: '❌', info: 'ℹ️', warning: '⚠️' };
      toast.className = 'pwa-toast pwa-toast--' + type;
      toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
      toast.innerHTML =
        '<span class="pwa-toast__icon" aria-hidden="true">' + (icons[type] || 'ℹ️') + '</span>' +
        '<span class="pwa-toast__msg">' + message + '</span>' +
        '<button class="pwa-toast__close" type="button" aria-label="Kapat">' +
          '<svg width="10" height="10" viewBox="0 0 10 10" fill="none"><path d="M1 1l8 8M9 1L1 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>' +
        '</button>';

      this._getContainer().appendChild(toast);

      const dismiss = () => {
        toast.classList.add('pwa-toast--out');
        setTimeout(() => toast.remove(), 300);
      };

      toast.querySelector('.pwa-toast__close').addEventListener('click', dismiss);
      setTimeout(dismiss, type === 'error' ? 6000 : 4000);
    },

    success(msg) { this.show('success', msg); },
    error(msg)   { this.show('error', msg); },
    info(msg)    { this.show('info', msg); },
  };

  // ═══════════════════════════════════════════════════════════════════════════
  // PushManager
  // ═══════════════════════════════════════════════════════════════════════════
  const PushManager = {
    _subscribed: null,

    async init(s) {
      try {
        const browserSub = await isBrowserSubscribed();
        if (!browserSub) {
          this._subscribed = false;
          this._updateUI(false);
          return;
        }
        this._subscribed = true;
        this._updateUI(true);
        if (s.statusEndpoint) this._verifyWithServer(s);
      } catch (_) {
        this._subscribed = false;
        this._updateUI(false);
      }
    },

    async _verifyWithServer(s) {
      try {
        const reg = await navigator.serviceWorker.getRegistration('/').catch(() => null);
        const sub = reg ? await reg.pushManager.getSubscription().catch(() => null) : null;
        if (!sub) { this._subscribed = false; this._updateUI(false); return; }
        const res  = await fetch(s.statusEndpoint + '?endpoint=' + encodeURIComponent(sub.endpoint), { credentials: 'same-origin' });
        const data = res.ok ? await res.json() : {};
        if (!data.subscribed) { this._subscribed = false; this._updateUI(false); }
      } catch (_) {}
    },

    async subscribe(s) {
      if (!('PushManager' in window)) {
        ToastManager.error(Drupal.t('Bu tarayıcı push bildirimlerini desteklemiyor.'));
        return;
      }
      if (Notification.permission === 'denied') {
        ToastManager.error(Drupal.t('Bildirimler tarayıcı tarafından engellendi. Tarayıcı ayarlarından izin verin.'));
        return;
      }

      LoadingManager.start();
      try {
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') { LoadingManager.stop(); return; }
        if (!s.vapidPublicKey) { LoadingManager.stop(); return; }

        const reg = await navigator.serviceWorker.ready;
        let sub = await reg.pushManager.getSubscription();
        if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(s.vapidPublicKey) });

        const res = await postJSON(s.subscribeEndpoint, sub.toJSON());
        if (res.ok) {
          this._subscribed = true;
          // sw mesajını hemen gönder
          if (navigator.serviceWorker.controller)
            navigator.serviceWorker.controller.postMessage({ type: 'SET_VAPID_KEY', vapidPublicKey: s.vapidPublicKey });
        } else {
          await sub.unsubscribe().catch(() => {});
          this._subscribed = 'error';
        }
      } catch (err) {
        this._subscribed = err.name === 'AbortError' ? 'brave' : 'error';
      } finally {
        // ÖNCE orijinal HTML geri yükle, SONRA UI güncelle
        // (aksi hâlde spinner üzerinde çalışılır, ikonlar kaybolur)
        LoadingManager.stop();
        if (this._subscribed === true) {
          this._updateUI(true);
          ToastManager.success(Drupal.t('🎉 Bildirimlere abone oldunuz!'));
        } else if (this._subscribed === 'brave') {
          this._subscribed = null;
          ToastManager.error('🦁 Brave Shields bildirimleri engelliyor. Aslan simgesi → Shields Kapat → Sayfayı Yenile');
        } else if (this._subscribed === 'error') {
          this._subscribed = null;
          ToastManager.error(Drupal.t('Bildirim aboneliği başarısız oldu.'));
        }
      }
    },

    async unsubscribe(s) {
      LoadingManager.start();
      let success = false;
      try {
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
          await postJSON(s.unsubscribeEndpoint, { endpoint: sub.endpoint }).catch(() => {});
          await sub.unsubscribe();
        }
        success = true;
      } catch (_) {
        // hata aşağıda işlenir
      } finally {
        // ÖNCE orijinal HTML geri yükle, SONRA UI güncelle
        LoadingManager.stop();
        if (success) {
          this._subscribed = false;
          this._updateUI(false);
          ToastManager.info(Drupal.t('Bildirim aboneliği iptal edildi.'));
        } else {
          ToastManager.error(Drupal.t('Abonelik iptal edilemedi.'));
        }
      }
    },

    _updateUI(isSubscribed) {
      this._subscribed = isSubscribed;

      // hidden attribute kullan (style.display yerine — daha semantik)
      document.querySelectorAll('[data-pwa-subscribe]').forEach(el => {
        isSubscribed ? el.setAttribute('hidden', '') : el.removeAttribute('hidden');
      });
      document.querySelectorAll('[data-pwa-unsubscribe]').forEach(el => {
        isSubscribed ? el.removeAttribute('hidden') : el.setAttribute('hidden', '');
      });

      document.querySelectorAll('[data-pwa-push-status]').forEach(el => {
        el.textContent = isSubscribed ? '✓ ' + Drupal.t('Bildirimler açık') : Drupal.t('Bildirimler kapalı');
        el.classList.toggle('pwa-status--on', isSubscribed);
      });

      document.querySelectorAll('[data-pwa-push-toggle]').forEach(el => {
        const text = isSubscribed
          ? (el.dataset.labelOff || Drupal.t('Bildirimleri Kapat'))
          : (el.dataset.labelOn  || Drupal.t('Bildirimlere Abone Ol'));
        const label = el.querySelector('.pwa-btn__label');
        if (label) {
          label.textContent = text;
        } else {
          // İçerik yoksa yalnızca text node ekle, mevcut element çocuklarına dokunma.
          // (el.textContent = text DOM çocuklarını siler — ikonları yok eder)
          const tn = el.querySelector('[data-pwa-label]');
          if (tn) tn.textContent = text;
          else {
            const span = document.createElement('span');
            span.className = 'pwa-btn__label';
            span.setAttribute('data-pwa-label', '');
            span.textContent = text;
            el.appendChild(span);
          }
        }
        el.dataset.subscribed = isSubscribed ? '1' : '0';
        el.classList.toggle('pwa-btn--toggle-on', isSubscribed);
      });

      document.querySelectorAll('[data-pwa-push-switch]').forEach(el => {
        el.setAttribute('aria-checked', isSubscribed ? 'true' : 'false');
      });

      document.querySelectorAll('[data-pwa-modal-subscribe-view]').forEach(el => {
        isSubscribed ? el.setAttribute('hidden', '') : el.removeAttribute('hidden');
      });
      document.querySelectorAll('[data-pwa-modal-unsubscribe-view]').forEach(el => {
        isSubscribed ? el.removeAttribute('hidden') : el.setAttribute('hidden', '');
      });

      document.querySelectorAll('[data-pwa-banner-subscribe-view]').forEach(el => {
        isSubscribed ? el.setAttribute('hidden', '') : el.removeAttribute('hidden');
      });
      document.querySelectorAll('[data-pwa-banner-success-view]').forEach(el => {
        isSubscribed ? el.removeAttribute('hidden') : el.setAttribute('hidden', '');
      });

      if (isSubscribed) {
        SnackbarManager.hideAll();
        document.querySelectorAll('.pwa-prompt').forEach(el => { el.classList.add('pwa-prompt--out'); setTimeout(() => el.remove(), 320); });
        document.querySelectorAll('.pwa-modal-overlay:not([hidden])').forEach(m => { m.hidden = true; document.body.style.overflow = ''; });
      }
    },
  };

  // ═══════════════════════════════════════════════════════════════════════════
  // ModalManager
  // ═══════════════════════════════════════════════════════════════════════════
  const ModalManager = {
    open(id) {
      const m = document.getElementById(id);
      if (!m) { if (DEBUG) console.warn('[PWA Suite] Modal bulunamadı: #' + id); return; }
      if (!m.hidden) return;
      m.hidden = false;
      document.body.style.overflow = 'hidden';
      if (DEBUG) console.info('[PWA Suite] Modal açıldı: #' + id);
      setTimeout(() => m.querySelector('button,[href],[tabindex]:not([tabindex="-1"])')?.focus(), 60);
    },
    close(id) {
      const m = document.getElementById(id);
      if (!m) return;
      m.hidden = true;
      document.body.style.overflow = '';
      if (!DEBUG) try { sessionStorage.setItem('pwa-modal-closed-' + id, '1'); } catch (_) {}
    },
    closeAll() {
      document.querySelectorAll('.pwa-modal-overlay:not([hidden])').forEach(m => {
        if (m.id) this.close(m.id);
        else { m.hidden = true; document.body.style.overflow = ''; }
      });
    },
    schedule(cfg) {
      if (cfg._pwa_ok) return;
      cfg._pwa_ok = true;
      const id    = cfg.getAttribute('data-pwa-modal-auto');
      const delay = parseInt(cfg.getAttribute('data-delay') || '5000', 10);
      if (!id) return;
      if (!DEBUG) try { if (sessionStorage.getItem('pwa-modal-closed-' + id)) return; } catch (_) {}
      if (DEBUG) console.info('[PWA Suite] Modal planlandı: #' + id + ' (' + delay + 'ms)');
      setTimeout(async () => {
        if (DEBUG) { this.open(id); return; }
        const sub = await waitForSubscriptionStatus();
        if (!sub) this.open(id);
      }, delay);
    },
  };

  // ═══════════════════════════════════════════════════════════════════════════
  // SnackbarManager — modern kart
  // ═══════════════════════════════════════════════════════════════════════════
  const SnackbarManager = {
    _shown: false,
    _active: [],

    schedule(cfg) {
      if (cfg._pwa_ok) return;
      cfg._pwa_ok = true;
      const delay = parseInt(cfg.dataset.delay || '4000', 10);
      if (DEBUG) console.info('[PWA Suite] Snackbar planlandı (' + delay + 'ms)');
      setTimeout(async () => {
        if (DEBUG) { this._showFromConfig(cfg); return; }
        if (this._shown) return;
        const sub = await waitForSubscriptionStatus();
        if (!sub) { this._showFromConfig(cfg); }
      }, delay);
    },

    _showFromConfig(cfg) {
      if (this._shown && !DEBUG) return;
      this._shown = true;
      this.show({
        icon:        '🔔',
        title:       cfg.dataset.bannerText     || Drupal.t('Bildirimlere abone olmak ister misiniz?'),
        actionLabel: cfg.dataset.subscribeLabel || Drupal.t('Abone Ol'),
      });
    },

    show({ icon = '🔔', title = '', desc = '', actionLabel = null, duration = 12000, extraClass = '' } = {}) {
      const el = document.createElement('div');
      el.className = 'pwa-snackbar' + (extraClass ? ' ' + extraClass : '');
      el.setAttribute('role', 'alert');
      el.setAttribute('aria-live', 'assertive');
      el.innerHTML =
        '<div class="pwa-snackbar__body">' +
          '<div class="pwa-snackbar__icon-wrap"><span class="pwa-snackbar__icon">' + icon + '</span></div>' +
          '<div class="pwa-snackbar__text">' +
            '<span class="pwa-snackbar__title">' + title + '</span>' +
            (desc ? '<span class="pwa-snackbar__desc">' + desc + '</span>' : '') +
          '</div>' +
          '<button class="pwa-snackbar__dismiss" type="button" aria-label="' + Drupal.t('Kapat') + '">' +
            '<svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M1 1l10 10M11 1L1 11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>' +
          '</button>' +
        '</div>' +
        (actionLabel
          ? '<div class="pwa-snackbar__foot"><button class="pwa-snackbar__cta" data-pwa-subscribe type="button">' + actionLabel + '</button></div>'
          : '');
      document.body.appendChild(el);
      this._active.push(el);
      el.querySelector('.pwa-snackbar__dismiss').addEventListener('click', () => this._hide(el));
      el._timer = setTimeout(() => this._hide(el), duration);
    },

    hideAll() { [...this._active].forEach(el => this._hide(el)); },
    _hide(el) {
      if (!el?.isConnected) return;
      clearTimeout(el._timer);
      el.classList.add('pwa-snackbar--out');
      setTimeout(() => { el.remove(); this._active = this._active.filter(a => a !== el); }, 320);
    },
  };

  // ═══════════════════════════════════════════════════════════════════════════
  // PromptManager — varyant 9
  // ═══════════════════════════════════════════════════════════════════════════
  const PromptManager = {
    _shown: false,
    schedule(cfg) {
      if (cfg._pwa_ok) return;
      cfg._pwa_ok = true;
      const delay = parseInt(cfg.dataset.delay || '5000', 10);
      setTimeout(async () => {
        if (!DEBUG) {
          if (this._shown) return;
          try { const sn = parseInt(sessionStorage.getItem('pwa-prompt-snoozed') || '0', 10); if (sn && Date.now() < sn) return; } catch (_) {}
          if (await waitForSubscriptionStatus()) return;
        }
        this._shown = true;
        this._show(cfg);
      }, delay);
    },
    _show(cfg) {
      if (document.querySelector('.pwa-prompt')) return;
      const el = document.createElement('div');
      el.className = 'pwa-prompt';
      el.setAttribute('data-pwa-prompt', '');
      el.setAttribute('role', 'dialog');
      el.innerHTML =
        '<div class="pwa-prompt__card">' +
          '<button class="pwa-prompt__close" data-pwa-prompt-close aria-label="Kapat">✕</button>' +
          '<div class="pwa-prompt__icon">' + (cfg.dataset.icon || '🔔') + '</div>' +
          '<div class="pwa-prompt__content">' +
            '<strong class="pwa-prompt__title">' + (cfg.dataset.title || Drupal.t('Bildirimlere Abone Ol')) + '</strong>' +
            '<p class="pwa-prompt__desc">' + (cfg.dataset.desc || Drupal.t('Yeni içeriklerden anında haberdar ol!')) + '</p>' +
          '</div>' +
          '<div class="pwa-prompt__actions">' +
            '<button class="pwa-prompt__yes" data-pwa-subscribe type="button">' + (cfg.dataset.subscribeLabel || Drupal.t('Evet, Abone Ol')) + '</button>' +
            '<button class="pwa-prompt__no" data-pwa-prompt-close type="button">Şimdi değil</button>' +
          '</div>' +
        '</div>';
      document.body.appendChild(el);
      el.querySelectorAll('[data-pwa-prompt-close]').forEach(btn => btn.addEventListener('click', () => {
        el.classList.add('pwa-prompt--out');
        setTimeout(() => el.remove(), 320);
        try { sessionStorage.setItem('pwa-prompt-snoozed', String(Date.now() + 1800000)); } catch (_) {}
      }));
    },
  };

  // ═══════════════════════════════════════════════════════════════════════════
  // SWManager / BannerManager
  // ═══════════════════════════════════════════════════════════════════════════
  const SWManager = {
    async register(s) {
      try {
        const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
        reg.addEventListener('updatefound', () => {
          const nw = reg.installing; if (!nw) return;
          nw.addEventListener('statechange', () => { if (nw.state === 'installed' && navigator.serviceWorker.controller) BannerManager.showUpdate(reg); });
        });
        if (s.vapidPublicKey) {
          const send = () => { if (navigator.serviceWorker.controller) navigator.serviceWorker.controller.postMessage({ type: 'SET_VAPID_KEY', vapidPublicKey: s.vapidPublicKey }); };
          send();
          navigator.serviceWorker.addEventListener('controllerchange', send);
        }
      } catch (err) { console.warn('[PWA Suite] SW kayıt hatası:', err); }
    },
  };

  const BannerManager = {
    _prompt: null,
    initInstall(s) {
      window.addEventListener('beforeinstallprompt', e => {
        // localStorage'ı SYNC kontrol et — dismissed ise preventDefault çağırma.
        // e.preventDefault() çağrılıp prompt gösterilmezse tarayıcı warning verir.
        let dismissed = false;
        try { dismissed = !!localStorage.getItem('pwa-install-dismissed'); } catch (_) {}

        if (!dismissed) {
          e.preventDefault();
          this._prompt = e;
          setTimeout(() => this._showInstall(s), s.autoPromptDelay || 3000);
        }
        // dismissed ise olayı engelleme — tarayıcı kendi banner'ını gösterebilir
      });
      window.addEventListener('appinstalled', () => { this._prompt = null; document.querySelector('.pwa-install-banner')?.remove(); });
    },
    _showInstall(s) {
      if (document.querySelector('.pwa-install-banner')) return;
      const b = document.createElement('div'); b.className = 'pwa-install-banner';
      b.innerHTML = '<div class="pwa-install-banner__content"><strong>' + (s.installBannerTitle || Drupal.t('Uygulamayı Yükle')) + '</strong>' + (s.installBannerBody ? '<p>' + s.installBannerBody + '</p>' : '') + '</div><div class="pwa-install-banner__actions"><button class="pwa-install-btn">' + Drupal.t('Yükle') + '</button><button class="pwa-dismiss-btn">✕</button></div>';
      document.body.appendChild(b);
      b.querySelector('.pwa-install-btn').addEventListener('click', async () => { b.remove(); if (this._prompt) { this._prompt.prompt(); await this._prompt.userChoice; this._prompt = null; } });
      b.querySelector('.pwa-dismiss-btn').addEventListener('click', () => { b.remove(); try { localStorage.setItem('pwa-install-dismissed', '1'); } catch (_) {} });
    },
    showUpdate(reg) {
      if (document.querySelector('.pwa-update-banner')) return;
      const b = document.createElement('div'); b.className = 'pwa-update-banner';
      b.innerHTML = '<span>' + Drupal.t('Yeni versiyon mevcut.') + '</span><button id="pwa-upd-btn">' + Drupal.t('Güncelle') + '</button><button id="pwa-upd-x">✕</button>';
      document.body.appendChild(b);
      document.getElementById('pwa-upd-btn').onclick = () => { reg?.waiting?.postMessage({ type: 'SKIP_WAITING' }); location.reload(); };
      document.getElementById('pwa-upd-x').onclick = () => b.remove();
    },
    initIOS(s) {
      if (!/iphone|ipad|ipod/i.test(navigator.userAgent) || !/^((?!chrome|android).)*safari/i.test(navigator.userAgent) || window.navigator.standalone || !s.showInstallBanner) return;
      try { if (sessionStorage.getItem('pwa-ios-dismissed')) return; } catch (_) {}
      setTimeout(() => {
        const g = document.createElement('div'); g.className = 'pwa-ios-guide';
        g.innerHTML = '<div class="pwa-ios-guide__content"><button class="pwa-ios-guide__close" aria-label="Kapat">✕</button><p>' + Drupal.t('Ana Ekrana eklemek için:') + '</p><ol><li><strong>' + Drupal.t('Paylaş') + '</strong> → <strong>' + Drupal.t('Ana Ekrana Ekle') + '</strong></li></ol></div>';
        document.body.appendChild(g);
        g.querySelector('.pwa-ios-guide__close').addEventListener('click', () => { g.remove(); try { sessionStorage.setItem('pwa-ios-dismissed', '1'); } catch (_) {} });
      }, s.autoPromptDelay || 3000);
    },
  };

  // ═══════════════════════════════════════════════════════════════════════════
  // Event Delegation
  // ═══════════════════════════════════════════════════════════════════════════
  let _bound = false;
  function bindDelegation(s) {
    if (_bound) return; _bound = true;
    document.addEventListener('click', async e => {
      if (e.target.closest('[data-pwa-subscribe]'))   { e.preventDefault(); await PushManager.subscribe(s); return; }
      if (e.target.closest('[data-pwa-unsubscribe]')) { e.preventDefault(); await PushManager.unsubscribe(s); return; }
      const tog = e.target.closest('[data-pwa-push-toggle]');
      if (tog) { e.preventDefault(); tog.dataset.subscribed === '1' ? await PushManager.unsubscribe(s) : await PushManager.subscribe(s); return; }
      const sw = e.target.closest('[data-pwa-push-switch]');
      if (sw) { e.preventDefault(); sw.getAttribute('aria-checked') === 'true' ? await PushManager.unsubscribe(s) : await PushManager.subscribe(s); return; }
      if (e.target.closest('[data-pwa-modal-trigger]')) { e.preventDefault(); ModalManager.open('pwa-push-modal'); return; }
      if (e.target.closest('[data-pwa-modal-close]'))   { e.preventDefault(); const ov = e.target.closest('.pwa-modal-overlay'); ModalManager.close(ov?.id || 'pwa-push-modal'); return; }
      if (e.target.classList.contains('pwa-modal-overlay')) { ModalManager.close(e.target.id || 'pwa-push-modal'); return; }
      if (e.target.closest('[data-pwa-banner-dismiss]')) { e.preventDefault(); e.target.closest('.pwa-nb--banner')?.setAttribute('hidden', ''); return; }
      if (e.target.closest('[data-pwa-install]')) { e.preventDefault(); if (BannerManager._prompt) { BannerManager._prompt.prompt(); await BannerManager._prompt.userChoice; BannerManager._prompt = null; } }
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') ModalManager.closeAll(); });
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // Context Tarama
  // ═══════════════════════════════════════════════════════════════════════════
  function scanContext(context) {
    context.querySelectorAll('[data-pwa-snackbar-config]').forEach(cfg => SnackbarManager.schedule(cfg));
    const seen = new Set();
    [...context.querySelectorAll('[data-pwa-modal-auto]'), ...document.querySelectorAll('[data-pwa-modal-auto]')]
      .forEach(cfg => { if (!seen.has(cfg)) { seen.add(cfg); ModalManager.schedule(cfg); } });
    context.querySelectorAll('[data-pwa-prompt-config]').forEach(cfg => PromptManager.schedule(cfg));
  }

  if ('serviceWorker' in navigator)
    navigator.serviceWorker.addEventListener('message', e => { if (e.data?.type === 'FLUSH_FORMS' && window.PwaBgSync) window.PwaBgSync.flushPendingForms(); });

  // ═══════════════════════════════════════════════════════════════════════════
  // Drupal Behavior
  // ═══════════════════════════════════════════════════════════════════════════
  Drupal.behaviors.pwaSuite = {
    attach(context) {
      const s = drupalSettings.pwaSuite || {};
      if (context === document) {
        if (s.swEnabled && 'serviceWorker' in navigator) SWManager.register(s);
        bindDelegation(s);
        PushManager.init(s); // Her zaman çağır — waitForSubscriptionStatus buna bağlı
        if (s.showInstallBanner) { BannerManager.initInstall(s); BannerManager.initIOS(s); }
        if (s.periodicSyncEnabled && 'serviceWorker' in navigator) {
          navigator.serviceWorker.ready.then(reg => {
            if (!('periodicSync' in reg)) return;
            navigator.permissions.query({ name: 'periodic-background-sync' })
              .then(st => { if (st.state === 'granted') reg.periodicSync.register('pwa-content-refresh', { minInterval: (s.periodicSyncInterval || 3600) * 1000 }); }).catch(() => {});
          }).catch(() => {});
        }
      } else {
        bindDelegation(s);
        if (PushManager._subscribed !== null) PushManager._updateUI(PushManager._subscribed);
      }
      scanContext(context);
    },
  };

})(Drupal, drupalSettings);
