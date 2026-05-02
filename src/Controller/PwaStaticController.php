<?php
namespace Drupal\pwa_suite\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * /sw.js ve /manifest.webmanifest için dedicated controller.
 *
 * routing.yml route'ları bu controller'ı çağırır.
 * Nginx'in statik dosya araması bypass edilir.
 */
class PwaStaticController extends ControllerBase {

  // ControllerBase zaten $configFactory tanımlıyor — tekrar tanımlamıyoruz.
  // Sadece ihtiyacımız olan ek servisleri inject ediyoruz.

  protected $state;
  protected $moduleHandler;

  public static function create(ContainerInterface $container): static {
    $instance = new static();
    // ControllerBase'in $configFactory'si lazy-load ile gelir, create'de inject etmiyoruz.
    // Doğrudan container'dan alıyoruz.
    $instance->state         = $container->get('state');
    $instance->moduleHandler = $container->get('module_handler');
    return $instance;
  }

  /**
   * GET /sw.js
   */
  public function serviceWorker(): Response {
    $subscriber = new \Drupal\pwa_suite\EventSubscriber\PwaRequestSubscriber(
      $this->configFactory(),   // ControllerBase::configFactory() — lazy loader
      $this->state,
      $this->moduleHandler,
    );
    return $subscriber->serveServiceWorker();
  }

  /**
   * GET /manifest.webmanifest
   */
  public function manifest(): Response {
    $subscriber = new \Drupal\pwa_suite\EventSubscriber\PwaRequestSubscriber(
      $this->configFactory(),
      $this->state,
      $this->moduleHandler,
    );
    return $subscriber->serveManifest();
  }
}
