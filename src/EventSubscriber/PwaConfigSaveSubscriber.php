<?php
namespace Drupal\pwa_suite\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * pwa_suite.settings kaydedilince web root'taki fiziksel dosyaları günceller.
 */
class PwaConfigSaveSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => [['onConfigSave', 0]]];
  }

  public function onConfigSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() !== 'pwa_suite.settings') {
      return;
    }
    \Drupal\pwa_suite\PwaStaticFiles::writeAll(
      $this->configFactory,
      $this->state,
      $this->moduleHandler,
    );
  }
}
