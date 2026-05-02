<?php

/**
 * @file
 * PWA Tanı sayfası — neyin kırık olduğunu ve nasıl düzeltileceğini gösterir.
 */

namespace Drupal\pwa_suite\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\pwa_suite\PwaStaticFiles;
use Symfony\Component\HttpFoundation\Request;

/**
 * PWA kurulum tanılama controller'ı.
 */
class PwaDiagnosticsController extends ControllerBase {

  public function page(Request $request): array {
    $config     = \Drupal::config('pwa_suite.settings');
    $base_url   = $request->getSchemeAndHttpHost();
    $web_root   = PwaStaticFiles::getWebRoot();

    $checks = [];

    // ── 1. HTTPS ──────────────────────────────────────────────────────────
    $is_https = $request->isSecure();
    $checks[] = $this->check(
      'HTTPS',
      $is_https,
      $is_https
        ? 'Site HTTPS üzerinde çalışıyor.'
        : 'Service Worker HTTPS gerektiriyor! SSL sertifikası kurun.',
      !$is_https
    );

    // ── 2. SW enabled ─────────────────────────────────────────────────────
    $sw_enabled = (bool) $config->get('sw_enabled');
    $checks[]   = $this->check(
      'Service Worker Etkin',
      $sw_enabled,
      $sw_enabled
        ? 'Service Worker etkinleştirilmiş.'
        : 'Ayarlar → Service Worker sekmesinden SW\'yi etkinleştirin.',
      !$sw_enabled
    );

    // ── 3. sw.js fiziksel dosya ────────────────────────────────────────────
    $sw_status  = PwaStaticFiles::fileStatus('sw.js');
    $sw_exists  = $sw_status['exists'] && $sw_status['size'] > 100;
    $checks[]   = $this->check(
      'sw.js (fiziksel dosya)',
      $sw_exists,
      $sw_exists
        ? "sw.js dosyası mevcut ({$sw_status['path']}, " . number_format($sw_status['size']) . " byte)."
        : "sw.js dosyası YOK ({$sw_status['path']}). drush pwa:files-write komutu çalıştırın.",
      !$sw_exists
    );

    // ── 4. manifest.webmanifest fiziksel dosya ────────────────────────────
    $mwm_status = PwaStaticFiles::fileStatus('manifest.webmanifest');
    $mwm_exists = $mwm_status['exists'] && $mwm_status['size'] > 50;
    $checks[]   = $this->check(
      'manifest.webmanifest (fiziksel dosya)',
      $mwm_exists,
      $mwm_exists
        ? "manifest.webmanifest dosyası mevcut ({$mwm_status['size']} byte)."
        : "manifest.webmanifest dosyası YOK. drush pwa:files-write çalıştırın.",
      !$mwm_exists
    );

    // ── 5. manifest.json fiziksel dosya ───────────────────────────────────
    $mj_status  = PwaStaticFiles::fileStatus('manifest.json');
    $mj_exists  = $mj_status['exists'] && $mj_status['size'] > 50;
    $checks[]   = $this->check(
      'manifest.json (fiziksel dosya)',
      $mj_exists,
      $mj_exists
        ? "manifest.json dosyası mevcut ({$mj_status['size']} byte)."
        : "manifest.json dosyası YOK. drush pwa:files-write çalıştırın.",
      !$mj_exists
    );

    // ── 6. Web root yazılabilir mi? ───────────────────────────────────────
    $writable = is_writable($web_root);
    $checks[]  = $this->check(
      'Web Root Yazılabilir',
      $writable,
      $writable
        ? "PHP, {$web_root} dizinine yazabilir."
        : "PHP, {$web_root} dizinine yazamıyor. chmod g+w {$web_root} komutunu çalıştırın.",
      !$writable
    );

    // ── 7. İkonlar ────────────────────────────────────────────────────────
    $icon_fids   = $config->get('icon_fids') ?: [];
    $has_512     = (int) ($icon_fids['icon_512'] ?? 0) > 0;
    $has_192     = (int) ($icon_fids['icon_192'] ?? 0) > 0;
    $icons_ok    = $has_512 && $has_192;
    $checks[]    = $this->check(
      'PWA İkonları (512x512 + 192x192)',
      $icons_ok,
      $icons_ok
        ? '512x512 ve 192x192 ikonlar yüklenmiş.'
        : 'Ayarlar → İkonlar sekmesinden 512x512 ve 192x192 ikonlarını yükleyin. '
          . 'Şu an favicon.ico fallback kullanılıyor — PWA Builder sınırlı puan verebilir.',
      !$icons_ok
    );

    // ── 8. VAPID ──────────────────────────────────────────────────────────
    $push_enabled = (bool) $config->get('push_enabled');
    $vapid_ok     = \Drupal::service('pwa_suite.vapid_key_service')->hasKeys();
    if ($push_enabled) {
      $checks[] = $this->check(
        'VAPID Anahtarları',
        $vapid_ok,
        $vapid_ok
          ? 'VAPID anahtarları mevcut.'
          : 'Push bildirimleri etkin ama VAPID yok! Ayarlar → Push sekmesinden oluşturun.',
        !$vapid_ok
      );
    }

    // ── 9. manifest.json HTTP erişimi ─────────────────────────────────────
    $manifest_url = $base_url . '/manifest.json';
    $sw_url       = $base_url . '/sw.js';

    // ── Nginx config ──────────────────────────────────────────────────────
    $nginx_config = "location ~* ^/(sw\\.js|manifest\\.webmanifest|manifest\\.json)\$ {\n"
      . "    add_header Service-Worker-Allowed / always;\n"
      . "    add_header Cache-Control \"no-store\" always;\n"
      . "    try_files \$uri \@drupal;\n"
      . "}";

    $build = [];

    // Özet başlık.
    $all_ok = !array_filter($checks, fn($c) => $c['status'] === 'error');
    $warnings = array_filter($checks, fn($c) => $c['status'] === 'warning');

    if ($all_ok && empty($warnings)) {
      $build['status'] = ['#markup' => '<div class="messages messages--status">✅ <strong>Tüm kontroller başarılı!</strong> PWA Builder testlerini geçmeye hazır.</div>'];
    }
    elseif ($all_ok) {
      $build['status'] = ['#markup' => '<div class="messages messages--warning">⚠️ <strong>Uyarılar var</strong>, ancak temel PWA özellikleri çalışıyor. Detaylar aşağıda.</div>'];
    }
    else {
      $build['status'] = ['#markup' => '<div class="messages messages--error">❌ <strong>Kritik sorunlar tespit edildi.</strong> PWA Builder testleri başarısız olabilir. Aşağıdaki adımları izleyin.</div>'];
    }

    // Kontrol listesi.
    $rows = [];
    foreach ($checks as $check) {
      $icon = match($check['status']) {
        'ok'      => '✅',
        'warning' => '⚠️',
        'error'   => '❌',
        default   => 'ℹ️',
      };
      $rows[] = [
        ['data' => ['#markup' => $icon], 'style' => 'text-align:center;font-size:20px'],
        ['data' => ['#markup' => '<strong>' . htmlspecialchars($check['label']) . '</strong>']],
        ['data' => ['#markup' => htmlspecialchars($check['message'])]],
      ];
    }

    $build['checks'] = [
      '#type'   => 'table',
      '#header' => ['', 'Kontrol', 'Durum'],
      '#rows'   => $rows,
    ];

    // Nginx yapılandırma rehberi.
    $build['nginx'] = ['#markup' =>
      '<h3>ISPConfig Nginx Yapılandırması</h3>'
      . '<p>Fiziksel dosyalar web root\'a yazılamazsa (<code>chmod g+w ' . htmlspecialchars($web_root) . '</code> komutuyla çözülebilir) '
      . '<strong>ya da</strong> Nginx JS dosyalarını PHP\'ye iletmeden servis ediyorsa, '
      . 'ISPConfig → Web → Site → <em>Options</em> sekmesi → <em>nginx Directives</em> alanına şunu ekleyin:</p>'
      . '<pre style="background:#1e1e2e;color:#cdd6f4;padding:18px;border-radius:10px;font-size:13px;overflow-x:auto;line-height:1.6">'
      . htmlspecialchars($nginx_config) . '</pre>'
      . '<p><strong>drush pwa:files-write</strong> komutuyla da fiziksel dosyaları zorla yazabilirsiniz.</p>',
    ];

    // Hızlı test linkleri.
    $build['links'] = ['#markup' =>
      '<h3>Test Linkleri</h3>'
      . '<ul>'
      . '<li><a href="' . $sw_url . '" target="_blank">' . $sw_url . '</a></li>'
      . '<li><a href="' . $base_url . '/manifest.webmanifest" target="_blank">' . $base_url . '/manifest.webmanifest</a></li>'
      . '<li><a href="' . $manifest_url . '" target="_blank">' . $manifest_url . '</a></li>'
      . '<li><a href="https://www.pwabuilder.com/?site=' . urlencode($base_url) . '" target="_blank" rel="noopener">PWA Builder\'da Test Et</a></li>'
      . '<li><a href="https://pagespeed.web.dev/report?url=' . urlencode($base_url) . '" target="_blank" rel="noopener">Google Lighthouse</a></li>'
      . '</ul>',
    ];

    $build['#attached']['library'][] = 'pwa_suite/pwa_suite.admin';

    return $build;
  }

  /**
   * Kontrol öğesi oluşturur.
   */
  protected function check(string $label, bool $ok, string $message, bool $is_warning = FALSE): array {
    return [
      'label'   => $label,
      'status'  => $ok ? 'ok' : ($is_warning ? 'warning' : 'error'),
      'message' => $message,
    ];
  }

}
