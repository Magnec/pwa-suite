<?php

namespace Drupal\pwa_suite\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * /sw.js, /manifest.webmanifest ve /manifest.json isteklerini Drupal routing'den
 * ÖNCE yakalar (priority 200).
 *
 * Bu, Nginx try_files @drupal yönlendirmesi olduğunda fiziksel dosya olmadan da çalışır.
 * Fiziksel dosya varsa Nginx onu doğrudan servis eder (EventSubscriber çalışmaz — bu doğrudur).
 */
class PwaRequestSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => [['onRequest', 200]]];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) return;

    $path = $event->getRequest()->getPathInfo();

    if ($path === '/sw.js') {
      $event->setResponse($this->buildSWResponse());
      return;
    }

    if ($path === '/manifest.webmanifest' || $path === '/manifest.json') {
      $event->setResponse($this->buildManifestResponse());
      return;
    }
  }

  /** Route: pwa_suite.service_worker */
  public function serveServiceWorker(): Response {
    return $this->buildSWResponse();
  }

  /** Route: pwa_suite.manifest + pwa_suite.manifest_json */
  public function serveManifest(): Response {
    return $this->buildManifestResponse();
  }

  // ── Response üreticiler ──────────────────────────────────────────────────

  protected function buildSWResponse(): Response {
    $config = $this->configFactory->get('pwa_suite.settings');
    $js     = $config->get('sw_enabled')
      ? $this->generateSWJs($config)
      : '/* PWA Suite Service Worker — disabled */';

    return new Response($js, 200, [
      'Content-Type'              => 'application/javascript; charset=utf-8',
      'Service-Worker-Allowed'    => '/',
      'Cache-Control'             => 'no-store, no-cache, must-revalidate',
      'Pragma'                    => 'no-cache',
      'X-Content-Type-Options'    => 'nosniff',
      // SW'nin fetch yapmasına izin ver, ancak eval/inline script engelle.
      'Content-Security-Policy'   => "default-src 'self'; connect-src *; script-src 'self'",
    ]);
  }

  protected function buildManifestResponse(): Response {
    $config      = $this->configFactory->get('pwa_suite.settings');
    $site_config = $this->configFactory->get('system.site');
    $site_name   = (string) ($site_config->get('name') ?? 'Site');
    $app_name    = (string) ($config->get('name') ?: $site_name);
    $start_url   = (string) ($config->get('start_url') ?: '/?source=pwa');

    $manifest = [
      'name'             => $app_name,
      'short_name'       => $config->get('short_name') ?: mb_substr($app_name, 0, 12),
      'description'      => $config->get('description') ?: '',
      'start_url'        => $start_url,
      'id'               => $config->get('id') ?: $start_url,
      'scope'            => $config->get('scope') ?: '/',
      'display'          => $config->get('display') ?: 'standalone',
      'theme_color'      => $config->get('theme_color') ?: '#1565c0',
      'background_color' => $config->get('background_color') ?: '#ffffff',
      'orientation'      => $config->get('orientation') ?: 'any',
      'lang'             => $config->get('lang') ?: 'tr',
      'dir'              => $config->get('dir') ?: 'ltr',
    ];

    $do = array_values(array_filter($config->get('display_override') ?: []));
    if (!empty($do)) $manifest['display_override'] = $do;

    $cats = array_values(array_filter($config->get('categories') ?: []));
    if (!empty($cats)) $manifest['categories'] = $cats;

    if ($config->get('prefer_related_applications')) $manifest['prefer_related_applications'] = TRUE;
    $related = $config->get('related_applications') ?: [];
    if (!empty($related)) $manifest['related_applications'] = $related;

    // İkonlar — PWA Builder 192x192 + 512x512 gerektiriyor.
    // Yüklü ikon yoksa favicon ile fallback ikonu oluştur.
    $icons = $config->get('icons') ?: [];
    if (empty($icons)) {
      $icons = $this->buildFallbackIcons($config);
    }
    $manifest['icons'] = $icons;

    $screenshots = $config->get('screenshots') ?: [];
    if (!empty($screenshots)) $manifest['screenshots'] = $screenshots;

    $shortcuts = $this->buildShortcuts($config);
    if (!empty($shortcuts)) $manifest['shortcuts'] = $shortcuts;

    if ($config->get('share_target_enabled')) {
      $manifest['share_target'] = [
        'action'  => $config->get('share_target_action') ?: '/share-target',
        'method'  => 'GET',
        'enctype' => 'application/x-www-form-urlencoded',
        'params'  => ['title' => 'title', 'text' => 'text', 'url' => 'url'],
      ];
    }

    $phs = $config->get('protocol_handlers') ?: [];
    if (!empty($phs)) $manifest['protocol_handlers'] = $phs;

    if ($config->get('edge_side_panel_enabled')) {
      $manifest['edge_side_panel'] = ['preferred_width' => (int) ($config->get('edge_side_panel_preferred_width') ?? 400)];
    }

    $lh_mode = $config->get('launch_handler_client_mode') ?: 'auto';
    if ($lh_mode && $lh_mode !== 'auto') $manifest['launch_handler'] = ['client_mode' => $lh_mode];

    if ($iarc = (string) ($config->get('iarc_rating_id') ?: ''))               $manifest['iarc_rating_id']  = $iarc;
    if ($note  = (string) ($config->get('note_taking_new_note_url') ?: ''))     $manifest['note_taking']     = ['new_note_url' => $note];
    $se = $config->get('scope_extensions') ?: [];
    if (!empty($se)) $manifest['scope_extensions'] = $se;

    $this->moduleHandler->invokeAll('pwa_manifest_alter', [&$manifest]);

    return new Response(
      json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
      200,
      [
        'Content-Type'    => 'application/manifest+json; charset=utf-8',
        'Cache-Control'   => 'public, max-age=3600',
        'X-Content-Type-Options' => 'nosniff',
      ]
    );
  }

  /**
   * Yüklü ikon olmadığında PWA standartlarını karşılayan fallback ikonları üretir.
   *
   * PWA Builder 192x192 ve 512x512 ikonlar gerektiriyor.
   * Favicon varsa onu kullanır, yoksa `/favicon.ico` ile temel bir entry oluşturur.
   */
  protected function buildFallbackIcons($config): array {
    $icons   = [];
    $favicon = '/favicon.ico';

    // Drupal sistem favicon kontrolü.
    $site_config = $this->configFactory->get('system.site');

    // Yüklü ikonlar var mı kontrol et.
    $icon_fids = $config->get('icon_fids') ?: [];
    $sizes_map = [
      'icon_512' => '512x512', 'icon_192' => '192x192',
      'icon_384' => '384x384', 'icon_256' => '256x256',
      'icon_180' => '180x180', 'icon_128' => '128x128',
    ];

    foreach ($sizes_map as $key => $size) {
      $fid = (int) ($icon_fids[$key] ?? 0);
      if ($fid && ($file = \Drupal\file\Entity\File::load($fid))) {
        $url     = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
        $mime    = $file->getMimeType() ?: 'image/png';
        $icons[] = ['src' => $url, 'sizes' => $size, 'type' => $mime, 'purpose' => 'any'];
      }
    }

    // Maskable ikonlar.
    foreach (['icon_maskable_fid' => '512x512', 'icon_maskable_192_fid' => '192x192'] as $fid_key => $size) {
      $fid = (int) ($config->get($fid_key) ?: 0);
      if ($fid && ($file = \Drupal\file\Entity\File::load($fid))) {
        $url     = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
        $icons[] = ['src' => $url, 'sizes' => $size, 'type' => 'image/png', 'purpose' => 'maskable'];
      }
    }

    if (!empty($icons)) return $icons;

    // Hiç ikon yüklenmemiş — favicon ile minimum PWA uyumlu entry oluştur.
    // PWA Builder'ın sert zorunluluğunu geçmek için 192x192 ve 512x512 boyutlarını
    // aynı favicon ile işaret ediyoruz. Kullanıcı ikon yükleyene kadar çalışır.
    $req = \Drupal::request();
    $base_url = $req->getSchemeAndHttpHost();

    return [
      ['src' => $base_url . '/favicon.ico', 'sizes' => '48x48',   'type' => 'image/x-icon', 'purpose' => 'any'],
      ['src' => $base_url . '/favicon.ico', 'sizes' => '192x192',  'type' => 'image/x-icon', 'purpose' => 'any'],
      ['src' => $base_url . '/favicon.ico', 'sizes' => '512x512',  'type' => 'image/x-icon', 'purpose' => 'any'],
    ];
  }

  // ── Yardımcı metodlar ────────────────────────────────────────────────────

  protected function defaultOfflineHtml($config): string {
    $site_name   = htmlspecialchars($config->get('name') ?: \Drupal::config('system.site')->get('name') ?: 'Site');
    $bg_color    = htmlspecialchars($config->get('background_color') ?: '#ffffff');
    $theme_color = htmlspecialchars($config->get('theme_color') ?: '#1565c0');
    return '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
      . '<title>Çevrimdışı</title><style>body{font-family:-apple-system,sans-serif;background:' . $bg_color . ';display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
      . '.card{background:#fff;border-radius:16px;padding:48px 32px;text-align:center;max-width:420px;width:100%;box-shadow:0 4px 32px rgba(0,0,0,.1)}'
      . 'h1{color:' . $theme_color . '}button{background:' . $theme_color . ';color:#fff;padding:12px 28px;border:none;border-radius:10px;cursor:pointer;font-size:15px}</style>'
      . '</head><body><div class="card"><div style="font-size:64px">📡</div><h1>Bağlantı Yok</h1>'
      . '<p>' . $site_name . ' şu an erişilemiyor.</p><button onclick="location.reload()">Tekrar Dene</button></div></body></html>';
  }

  protected function generateSWJs($config): string {
    $ver          = $config->get('sw_cache_version')         ?: 'v1';
    $precache     = $config->get('sw_precache_urls')         ?: [];
    $offline_url  = $config->get('sw_offline_url')           ?: '/offline';
    $debug        = $config->get('sw_debug')                 ? 'true' : 'false';
    $bg_sync      = $config->get('sw_bg_sync_enabled')       ? 'true' : 'false';
    $periodic     = $config->get('sw_periodic_sync_enabled') ? 'true' : 'false';
    $periodic_int = (int) ($config->get('sw_periodic_sync_interval') ?: 3600);
    $bg_sync_tag  = json_encode($config->get('sw_bg_sync_tag') ?: 'pwa-form-sync');

    $precache[]      = $offline_url;
    $precache        = array_values(array_unique($precache));
    $precache_json   = json_encode($precache);
    $offline_html_js = json_encode($this->defaultOfflineHtml($config));
    $vapid_key_js    = json_encode($this->state->get('pwa_suite.vapid_public_key', ''));
    $build_ts        = time(); // Her config save'de değişen timestamp — tarayıcı yeni versiyonu fark eder.

    return <<<JS
/* PWA Suite Service Worker — cache:{$ver} — build:{$build_ts} */
'use strict';

const CACHE_NAME    = 'pwa-suite-{$ver}';
const PRECACHE      = {$precache_json};
const OFFLINE_URL   = '{$offline_url}';
const OFFLINE_HTML  = {$offline_html_js};
const DEBUG         = {$debug};
const BG_SYNC       = {$bg_sync};
const BG_SYNC_TAG   = {$bg_sync_tag};
const PERIODIC      = {$periodic};
const PERIODIC_INT  = {$periodic_int};
self._vapidPublicKey = {$vapid_key_js};

const log = (...a) => { if (DEBUG) console.log('[PWA SW]', ...a); };

// ── Strateji tanımları ─────────────────────────────────────────
const STRATEGIES = [
  { test: /\.(?:png|jpe?g|webp|gif|ico|svg|avif)(?:\?|$)/i, strategy: 'CacheFirst',          name: 'pwa-images',  maxEntries: 100, maxAge: 2592000 },
  { test: /\.(?:woff2?|ttf|eot|otf)(?:\?|$)/i,              strategy: 'CacheFirst',          name: 'pwa-fonts',   maxEntries: 30,  maxAge: 2592000 },
  { test: /\.(?:js|css)(?:\?|$)/i,                           strategy: 'StaleWhileRevalidate',name: 'pwa-static',  maxEntries: 60,  maxAge: 86400   },
  { test: /^\/api\//,                                        strategy: 'NetworkFirst',        name: 'pwa-api',     maxEntries: 30,  maxAge: 300     },
];

// ── Install: precache + skipWaiting ───────────────────────────
self.addEventListener('install', e => {
  self.skipWaiting();
  e.waitUntil(
    caches.open(CACHE_NAME)
      .then(c => c.addAll(PRECACHE.map(u => new Request(u, { cache: 'reload' }))))
      .catch(err => log('precache error:', err))
  );
});

// ── Activate: eski cache'leri temizle + clients.claim ─────────
self.addEventListener('activate', e => {
  e.waitUntil(Promise.all([
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => {
        log('delete old cache:', k);
        return caches.delete(k);
      }))
    ),
    self.clients.claim(),
  ]));
});

// ── Fetch ─────────────────────────────────────────────────────
self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;

  const url = req.url;

  // Bu dosyaları asla önbelleğe alma / intercept etme.
  if (/\/(sw\.js|manifest\.webmanifest|manifest\.json)(\?|$)/.test(url)) return;
  if (/\/admin\/|\/user\/login|\/system\/ajax|\/pwa\/push\//.test(url)) return;

  const matched = STRATEGIES.find(s => s.test.test(url.replace(self.location.origin, '')));
  const strategy = matched
    ? matched.strategy
    : (req.headers.get('accept') || '').includes('text/html') ? 'NetworkFirst' : 'StaleWhileRevalidate';
  const cacheName   = matched ? matched.name    : CACHE_NAME;
  const maxEntries  = matched ? matched.maxEntries : 50;
  const maxAge      = matched ? matched.maxAge     : 86400;

  switch (strategy) {
    case 'CacheFirst':         e.respondWith(cacheFirst(req, cacheName, maxEntries, maxAge)); break;
    case 'NetworkFirst':       e.respondWith(networkFirst(req, cacheName, maxEntries));       break;
    default: /* StaleWhileRevalidate */ e.respondWith(staleWhileRevalidate(req, cacheName, maxEntries)); break;
  }
});

// Cache'e yazılabilir response mi?
function isCacheable(r) {
  if (!r) return false;
  if (r.status === 206)  return false; // Partial Content
  if (r.status === 0)    return false; // Opaque
  if (!r.ok)             return false; // 4xx/5xx
  return true;
}

async function cacheFirst(req, name, mx, ma) {
  const c      = await caches.open(name);
  const cached = await c.match(req);
  if (cached) {
    const d = cached.headers.get('date');
    if (!d || Date.now() - new Date(d).getTime() < ma * 1000) return cached;
  }
  try {
    const r = await fetch(req);
    if (isCacheable(r)) { await trimCache(c, mx); c.put(req, r.clone()); }
    return r || cached || fallback(req);
  } catch { return cached || fallback(req); }
}

async function networkFirst(req, name, mx) {
  const c = await caches.open(name);
  try {
    const r = await fetch(req);
    if (isCacheable(r)) { await trimCache(c, mx); c.put(req, r.clone()); }
    return r;
  } catch { return (await c.match(req)) || fallback(req); }
}

async function staleWhileRevalidate(req, name, mx) {
  const c      = await caches.open(name);
  const cached = await c.match(req);
  const fp     = fetch(req).then(r => {
    if (isCacheable(r)) { trimCache(c, mx); c.put(req, r.clone()); }
    return r;
  }).catch(() => null);
  return cached || fp || fallback(req);
}

async function trimCache(c, mx) {
  if (!mx) return;
  const keys = await c.keys();
  if (keys.length > mx) await Promise.all(keys.slice(0, keys.length - mx).map(k => c.delete(k)));
}

async function fallback(req) {
  const a = req.headers.get('accept') || '';
  if (a.includes('text/html')) {
    const c = await caches.match(OFFLINE_URL);
    if (c) return c;
    return new Response(OFFLINE_HTML, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
  }
  return new Response('', { status: 503 });
}

// ── Push ───────────────────────────────────────────────────────
self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (_) {}
  const title = d.title || 'Bildirim';
  const opts  = {
    body:                d.body  || '',
    icon:                d.icon  || (self.location.origin + '/favicon.ico'),
    badge:               d.badge || '',
    tag:                 d.tag   || 'pwa-suite',
    data:                { url: d.url || '/' },
    requireInteraction:  !!d.requireInteraction,
    silent:              !!d.silent,
  };
  if (d.image)   opts.image   = d.image;
  if (d.actions) opts.actions = d.actions;
  e.waitUntil(self.registration.showNotification(title, opts));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || '/';
  e.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
      for (const c of list) { if (c.url === url && 'focus' in c) return c.focus(); }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});

// ── VAPID helpers ──────────────────────────────────────────────
function b64ToUint8(b) {
  const p   = '='.repeat((4 - b.length % 4) % 4);
  const raw = atob((b + p).replace(/-/g, '+').replace(/_/g, '/'));
  const arr = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; ++i) arr[i] = raw.charCodeAt(i);
  return arr;
}

self.addEventListener('pushsubscriptionchange', e => {
  const vk = self._vapidPublicKey;
  if (!vk) return;
  e.waitUntil(
    self.registration.pushManager
      .subscribe({ userVisibleOnly: true, applicationServerKey: b64ToUint8(vk) })
      .then(sub => fetch('/pwa/push/update', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(sub.toJSON()),
        credentials: 'same-origin',
      }))
      .catch(err => log('resubscribe error:', err))
  );
});

// ── Mesajlar ───────────────────────────────────────────────────
self.addEventListener('message', e => {
  if (!e.data) return;
  if (e.data.type === 'SKIP_WAITING') { self.skipWaiting(); return; }
  if (e.data.type === 'SET_VAPID_KEY' && e.data.vapidPublicKey) {
    self._vapidPublicKey = e.data.vapidPublicKey;
    log('VAPID key updated');
  }
  if (e.data.type === 'GET_VERSION' && e.ports[0]) {
    e.ports[0].postMessage({ version: CACHE_NAME });
  }
});

// ── Background Sync ────────────────────────────────────────────
if (BG_SYNC) {
  self.addEventListener('sync', e => {
    if (e.tag === BG_SYNC_TAG) e.waitUntil(Promise.resolve());
  });
}

// ── Periodic Sync ──────────────────────────────────────────────
if (PERIODIC) {
  self.addEventListener('periodicsync', e => {
    if (e.tag === 'pwa-content-refresh') {
      e.waitUntil(
        caches.open(CACHE_NAME).then(c =>
          Promise.allSettled(PRECACHE.map(u =>
            fetch(u, { cache: 'reload' }).then(r => r.ok && c.put(u, r))
          ))
        )
      );
    }
  });
}

log('ready — cache:', CACHE_NAME);
JS;
  }

  protected function buildShortcuts($config): array {
    if ($config->get('shortcuts_from_menu')) return $this->buildShortcutsFromMenu();
    $shortcuts = $config->get('shortcuts') ?: [];
    $result    = [];
    foreach ($shortcuts as $sc) {
      $item = ['name' => $sc['name'], 'url' => $sc['url']];
      if (!empty($sc['description'])) $item['description'] = $sc['description'];
      if (!empty($sc['icon']))        $item['icons']        = [['src' => $sc['icon'], 'sizes' => '96x96']];
      $result[] = $item;
    }
    return $result;
  }

  protected function buildShortcutsFromMenu(): array {
    try {
      $menu_tree = \Drupal::service('menu.link_tree');
      $params    = new \Drupal\Core\Menu\MenuTreeParameters();
      $params->setMaxDepth(1);
      $tree  = $menu_tree->load('main', $params);
      $links = $menu_tree->transform($tree, [
        ['callable' => 'menu.default_tree_manipulators:checkAccess'],
        ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
      ]);
      $result = [];
      foreach (array_slice($links, 0, 4) as $link) {
        $l = $link->link;
        if ($l->isEnabled()) {
          $result[] = ['name' => $l->getTitle(), 'url' => $l->getUrlObject()->toString()];
        }
      }
      return $result;
    }
    catch (\Exception $e) { return []; }
  }

}
