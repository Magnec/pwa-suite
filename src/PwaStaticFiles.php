<?php

/**
 * @file
 * Web root'a fiziksel sw.js ve manifest dosyaları yazar.
 */

namespace Drupal\pwa_suite;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Web root'a fiziksel SW ve manifest dosyaları yazar.
 *
 * ISPConfig/Nginx ortamlarında .js uzantılı dosyalar için try_files PHP'ye
 * düşmeyebilir. Bu sınıf fiziksel dosyaları oluşturarak bu sorunu çözer.
 *
 * Yazma başarısız olursa:
 * - Admin'e açık hata mesajı gösterilir.
 * - Nginx location bloğu eklenmesi önerilir.
 * - Drupal routing (EventSubscriber) fallback olarak çalışmaya devam eder
 *   (Nginx try_files @drupal yönlendirmesi VARSA).
 *
 * ISPConfig Nginx fix (ISPConfig → Web → Site → Nginx Directives):
 *   location ~* ^/(sw\.js|manifest\.webmanifest|manifest\.json)$ {
 *     add_header Service-Worker-Allowed / always;
 *     try_files $uri @drupal;
 *   }
 */
class PwaStaticFiles {

  public static function getWebRoot(): string {
    return DRUPAL_ROOT;
  }

  public static function writeAll(
    ConfigFactoryInterface $configFactory,
    StateInterface $state,
    ModuleHandlerInterface $moduleHandler,
  ): void {
    self::writeServiceWorker($configFactory, $state, $moduleHandler);
    self::writeManifest($configFactory, $state, $moduleHandler);
  }

  public static function writeServiceWorker(
    ConfigFactoryInterface $configFactory,
    StateInterface $state,
    ModuleHandlerInterface $moduleHandler,
  ): void {
    $config = $configFactory->get('pwa_suite.settings');
    if (!$config->get('sw_enabled')) {
      $content = '/* PWA Suite: Service Worker devre dışı. */';
    }
    else {
      $subscriber = new EventSubscriber\PwaRequestSubscriber($configFactory, $state, $moduleHandler);
      $content    = $subscriber->serveServiceWorker()->getContent();
    }
    self::writeFile(self::getWebRoot() . '/sw.js', $content, 'sw.js');
  }

  public static function writeManifest(
    ConfigFactoryInterface $configFactory,
    StateInterface $state,
    ModuleHandlerInterface $moduleHandler,
  ): void {
    $subscriber = new EventSubscriber\PwaRequestSubscriber($configFactory, $state, $moduleHandler);
    $content    = $subscriber->serveManifest()->getContent();

    // manifest.webmanifest (W3C standardı)
    self::writeFile(self::getWebRoot() . '/manifest.webmanifest', $content, 'manifest.webmanifest');

    // manifest.json (PWA Builder, Lighthouse vb. tarafından kontrol edilir)
    self::writeFile(self::getWebRoot() . '/manifest.json', $content, 'manifest.json');
  }

  /**
   * Dosyayı web root'a yazar.
   *
   * Başarısız olursa:
   *  - Aynı hatayı tekrar loglamaz (state ile kontrol edilir).
   *  - Admin'e net bir uyarı mesajı gösterir.
   *  - Başarılı olursa state sıfırlanır ve info logu yazılır.
   */
  protected static function writeFile(string $path, string $content, string $label): void {
    $web_root  = self::getWebRoot();
    $state_key = 'pwa_suite.write_error.' . md5($label);

    // Dizin yazılabilir mi kontrol et.
    if (!is_writable($web_root)) {
      if (!\Drupal::state()->get($state_key, FALSE)) {
        \Drupal::state()->set($state_key, TRUE);
        \Drupal::logger('pwa_suite')->warning(
          'PWA Suite: @label web root\'a yazılamıyor (@root). '
          . 'PHP-FPM kullanıcısının dizine yazma yetkisi yok. '
          . 'Çözüm A: chmod g+w @root | '
          . 'Çözüm B: ISPConfig Nginx Directives\'e location bloğu ekleyin.',
          ['@label' => $label, '@root' => $web_root]
        );
        \Drupal::messenger()->addWarning(\t(
          'PWA Suite: <strong>@label</strong> web root\'a yazılamadı. '
          . '<a href="/admin/config/system/pwa-suite/tani">Tanı sayfasını ziyaret edin</a> '
          . 'veya ISPConfig Nginx Directives\'e gerekli yapılandırmayı ekleyin.',
          ['@label' => $label]
        ));
      }
      return;
    }

    // Dosyayı yaz.
    $written = file_put_contents($path, $content);

    if ($written === FALSE) {
      if (!\Drupal::state()->get($state_key, FALSE)) {
        \Drupal::state()->set($state_key, TRUE);
        \Drupal::logger('pwa_suite')->error(
          '@label yazılamadı: @path — Dosya izinlerini kontrol edin.',
          ['@label' => $label, '@path' => $path]
        );
      }
    }
    else {
      \Drupal::state()->delete($state_key);
      \Drupal::logger('pwa_suite')->info('@label güncellendi: @path', ['@label' => $label, '@path' => $path]);
    }
  }

  /**
   * Web root'taki fiziksel dosyaları siler (uninstall için).
   */
  public static function deleteAll(): void {
    foreach (['/sw.js', '/manifest.webmanifest', '/manifest.json'] as $file) {
      $path = self::getWebRoot() . $file;
      if (file_exists($path)) {
        @unlink($path);
      }
    }
    \Drupal::state()->deleteMultiple([
      'pwa_suite.write_error.' . md5('sw.js'),
      'pwa_suite.write_error.' . md5('manifest.webmanifest'),
      'pwa_suite.write_error.' . md5('manifest.json'),
    ]);
  }

  /**
   * Dosyanın fiziksel olarak mevcut olup olmadığını ve boyutunu döndürür.
   *
   * @return array{exists: bool, size: int, path: string}
   */
  public static function fileStatus(string $filename): array {
    $path = self::getWebRoot() . '/' . ltrim($filename, '/');
    return [
      'exists' => file_exists($path),
      'size'   => file_exists($path) ? filesize($path) : 0,
      'path'   => $path,
    ];
  }

}
