<?php

namespace Drupal\pwa_suite\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drupal\pwa_suite\Service\PwaVapidKeyService;
use Drupal\pwa_suite\Service\PwaPushNotificationService;
use Drupal\pwa_suite\PwaStaticFiles;
use Drupal\pwa_suite\ValueObject\PwaPushMessage;

/**
 * PWA Suite Drush komutları.
 */
final class PwaSuiteCommands extends DrushCommands {

  public function __construct(
    private readonly PwaVapidKeyService $vapidKeyService,
    private readonly PwaPushNotificationService $pushService,
  ) {
    parent::__construct();
  }

  // ── Dosya yazma komutları ─────────────────────────────────────────────────

  /**
   * sw.js ve manifest dosyalarını web root'a zorla yazar.
   *
   * ISPConfig/Nginx ortamlarında bu komut gereklidir.
   * Başarısız olursa web root yazılabilir değildir — chmod kullanın.
   */
  #[CLI\Command(name: 'pwa:files-write', aliases: ['pwa-fw'])]
  #[CLI\Help(description: 'sw.js, manifest.webmanifest ve manifest.json dosyalarını web root\'a yazar.')]
  public function filesWrite(): void {
    $web_root = PwaStaticFiles::getWebRoot();
    $this->io()->note("Web root: {$web_root}");
    $this->io()->note("PHP kullanıcısı: " . (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'bilinmiyor') : 'bilinmiyor'));

    if (!is_writable($web_root)) {
      $this->io()->error("Web root YAZILABİLİR DEĞİL: {$web_root}");
      $this->io()->caution("Çözüm: chmod g+w {$web_root}");
      $this->io()->caution("Ya da ISPConfig Nginx Directives'e location bloğu ekleyin:");
      $this->io()->block(
        "location ~* ^/(sw\\.js|manifest\\.webmanifest|manifest\\.json)$ {\n"
        . "    add_header Service-Worker-Allowed / always;\n"
        . "    add_header Cache-Control \"no-store\" always;\n"
        . "    try_files \$uri @drupal;\n"
        . "}",
        null, 'fg=yellow'
      );
      return;
    }

    try {
      PwaStaticFiles::writeAll(
        \Drupal::configFactory(),
        \Drupal::state(),
        \Drupal::moduleHandler(),
      );

      // Yazılan dosyaları doğrula.
      $files = ['sw.js', 'manifest.webmanifest', 'manifest.json'];
      $allOk = TRUE;
      foreach ($files as $file) {
        $status = PwaStaticFiles::fileStatus($file);
        if ($status['exists'] && $status['size'] > 10) {
          $this->io()->success("{$file}: " . number_format($status['size']) . " byte — {$status['path']}");
        }
        else {
          $this->io()->error("{$file}: YAZILMADI — {$status['path']}");
          $allOk = FALSE;
        }
      }

      if ($allOk) {
        $this->io()->success("Tüm PWA dosyaları başarıyla yazıldı.");
      }
      else {
        $this->io()->warning("Bazı dosyalar yazılamadı. Nginx yapılandırmasını kontrol edin.");
      }
    }
    catch (\Exception $e) {
      $this->io()->error("Hata: " . $e->getMessage());
    }
  }

  /**
   * PWA kurulum durumunu tanılar.
   */
  #[CLI\Command(name: 'pwa:diagnose', aliases: ['pwa-diag'])]
  #[CLI\Help(description: 'PWA kurulum durumunu kontrol eder ve sorunları raporlar.')]
  public function diagnose(): void {
    $config   = \Drupal::config('pwa_suite.settings');
    $web_root = PwaStaticFiles::getWebRoot();
    $errors   = 0;
    $warnings = 0;

    $this->io()->title('PWA Suite Tanı Raporu');

    // Temel kontroller.
    $checks = [
      ['SW Etkin', (bool) $config->get('sw_enabled'), 'Ayarlar → SW sekmesinden etkinleştirin.'],
      ['Push Etkin', (bool) $config->get('push_enabled'), 'İsteğe bağlı — push bildirimleri için.'],
      ['Web Root Yazılabilir', is_writable($web_root), "chmod g+w {$web_root}"],
      ['VAPID Anahtarları', $this->vapidKeyService->hasKeys(), 'drush pwa:vapid:generate'],
    ];

    foreach ($checks as [$label, $ok, $fix]) {
      if ($ok) {
        $this->io()->success($label);
      }
      else {
        $this->io()->warning("{$label}: {$fix}");
        $warnings++;
      }
    }

    // Dosya kontrolleri.
    foreach (['sw.js', 'manifest.webmanifest', 'manifest.json'] as $file) {
      $status = PwaStaticFiles::fileStatus($file);
      if ($status['exists'] && $status['size'] > 10) {
        $this->io()->success("{$file}: " . number_format($status['size']) . " byte");
      }
      else {
        $this->io()->error("{$file}: YOK — drush pwa:files-write çalıştırın.");
        $errors++;
      }
    }

    // İkon kontrolü.
    $icon_fids = $config->get('icon_fids') ?: [];
    $has_512   = (int) ($icon_fids['icon_512'] ?? 0) > 0;
    $has_192   = (int) ($icon_fids['icon_192'] ?? 0) > 0;
    if ($has_512 && $has_192) {
      $this->io()->success('PWA ikonları (512x512 + 192x192) mevcut.');
    }
    else {
      $this->io()->warning('PWA ikonları eksik — Admin → İkonlar sekmesinden yükleyin.');
      $warnings++;
    }

    $this->io()->newLine();
    if ($errors > 0) {
      $this->io()->error("{$errors} kritik sorun bulundu. Yukarıdaki adımları izleyin.");
    }
    elseif ($warnings > 0) {
      $this->io()->warning("{$warnings} uyarı var. Önerileri göz önünde bulundurun.");
    }
    else {
      $this->io()->success('PWA kurulumu sağlıklı görünüyor!');
    }

    $this->io()->note('Detaylı tanı için: /admin/config/system/pwa-suite/tani');
  }

  // ── VAPID komutları ───────────────────────────────────────────────────────

  #[CLI\Command(name: 'pwa:vapid:generate', aliases: ['pwa-vapid-gen'])]
  #[CLI\Help(description: 'Yeni VAPID anahtar çifti oluşturur.')]
  public function vapidGenerate(): void {
    if ($this->vapidKeyService->hasKeys()) {
      if (!$this->io()->confirm('Mevcut VAPID anahtarları silinecek. Devam?', FALSE)) return;
    }
    try {
      $keys = $this->vapidKeyService->generateKeys();
      $this->io()->success('VAPID anahtar çifti oluşturuldu.');
      $this->io()->definitionList(['Public Key' => $keys['publicKey']], ['Private Key' => '(gizli)']);
    }
    catch (\Exception $e) { $this->io()->error('Hata: ' . $e->getMessage()); }
  }

  #[CLI\Command(name: 'pwa:vapid:info', aliases: ['pwa-vapid-info'])]
  #[CLI\Help(description: 'Mevcut VAPID public key gösterir.')]
  public function vapidInfo(): void {
    if (!$this->vapidKeyService->hasKeys()) {
      $this->io()->warning('VAPID yok. drush pwa:vapid:generate');
      return;
    }
    $this->io()->success('VAPID Public Key: ' . $this->vapidKeyService->getPublicKey());
  }

  #[CLI\Command(name: 'pwa:vapid:delete', aliases: ['pwa-vapid-del'])]
  #[CLI\Help(description: 'VAPID anahtarlarını siler.')]
  public function vapidDelete(): void {
    if (!$this->io()->confirm('VAPID anahtarları kalıcı silinecek. Emin misiniz?', FALSE)) return;
    $this->vapidKeyService->deleteKeys();
    $this->io()->success('VAPID anahtarları silindi.');
  }

  // ── Push komutları ────────────────────────────────────────────────────────

  #[CLI\Command(name: 'pwa:push:send', aliases: ['pwa-push'])]
  #[CLI\Help(description: 'Tüm aktif abonelere push bildirimi kuyruğa ekler.')]
  #[CLI\Argument(name: 'title', description: 'Bildirim başlığı')]
  #[CLI\Option(name: 'body',    description: 'Bildirim içeriği')]
  #[CLI\Option(name: 'url',     description: 'Tıklama URL')]
  #[CLI\Option(name: 'icon',    description: 'İkon URL')]
  #[CLI\Option(name: 'urgency', description: 'very-low|low|normal|high')]
  public function pushSend(string $title, array $options = ['body' => '', 'url' => '/', 'icon' => '', 'urgency' => 'normal']): void {
    $message = new PwaPushMessage(
      title:   $title,
      body:    $options['body'],
      url:     $options['url'],
      icon:    $options['icon'],
      urgency: $options['urgency'],
    );
    $result = $this->pushService->sendToAll($message);
    $this->io()->success(sprintf('Bildirim kuyruğa eklendi: %d abone', $result->getQueued()));
  }

  #[CLI\Command(name: 'pwa:push:stats', aliases: ['pwa-stats'])]
  #[CLI\Help(description: 'Push abonelik istatistiklerini gösterir.')]
  public function pushStats(): void {
    $db     = \Drupal::database();
    $total  = (int) $db->select('pwa_push_subscription', 's')->countQuery()->execute()->fetchField();
    $active = (int) $db->select('pwa_push_subscription', 's')->condition('status', 1)->countQuery()->execute()->fetchField();
    $sent   = (int) $db->select('pwa_push_log', 'l')->condition('status', 'sent')->countQuery()->execute()->fetchField();
    $failed = (int) $db->select('pwa_push_log', 'l')->condition('status', 'failed')->countQuery()->execute()->fetchField();
    $queued = (int) \Drupal::queue('pwa_suite_push_queue')->numberOfItems();
    $this->io()->definitionList(
      ['Toplam Abone' => $total], ['Aktif' => $active], ['Pasif' => $total - $active],
      ['Gönderildi' => $sent], ['Başarısız' => $failed], ['Kuyrukta' => $queued]
    );
  }

  #[CLI\Command(name: 'pwa:push:process-queue', aliases: ['pwa-process'])]
  #[CLI\Help(description: 'Push kuyruğunu hemen işler.')]
  public function processQueue(): void {
    $queue  = \Drupal::queue('pwa_suite_push_queue');
    $n      = $queue->numberOfItems();
    $this->io()->note(sprintf('%d öğe kuyrukta.', $n));
    $processed = 0;
    while ($item = $queue->claimItem()) {
      try {
        $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('pwa_suite_push_queue');
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $processed++;
      }
      catch (\Exception $e) {
        $queue->releaseItem($item);
        $this->io()->error('Hata: ' . $e->getMessage());
      }
    }
    $this->io()->success(sprintf('%d öğe işlendi.', $processed));
  }

  #[CLI\Command(name: 'pwa:subscribers:cleanup', aliases: ['pwa-cleanup'])]
  #[CLI\Help(description: 'Pasif abonelikleri siler.')]
  #[CLI\Option(name: 'days', description: 'Bu kadar günden uzun süredir pasif olanları sil (varsayılan: 90)')]
  public function subscribersCleanup(array $options = ['days' => 90]): void {
    $cutoff = \Drupal::time()->getRequestTime() - ((int) $options['days'] * 86400);
    $n      = \Drupal::database()->delete('pwa_push_subscription')
      ->condition('status', 0)
      ->condition('last_active', $cutoff, '<')
      ->execute();
    $this->io()->success(sprintf('%d pasif abonelik silindi.', $n));
  }

}
