<?php

/**
 * @file
 * PWA Push bildirim kuyruk işleyicisi.
 */

namespace Drupal\pwa_suite\Plugin\QueueWorker;

use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\pwa_suite\Service\PwaPushNotificationService;
use Drupal\pwa_suite\ValueObject\PwaPushMessage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Push bildirim kuyruğunu işler.
 *
 * @QueueWorker(
 *   id = "pwa_suite_push_queue",
 *   title = @Translation("PWA Push Bildirim Kuyruğu"),
 *   cron = {"time" = 60}
 * )
 */
class PwaPushQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  protected PwaPushNotificationService $pushService;
  protected Connection $database;

  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->pushService = $container->get('pwa_suite.push_notification_service');
    $instance->database    = $container->get('database');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Kuyruk verisi JSON formatında gelir — serialize() kullanılmaz.
   */
  public function processItem($data): void {
    if (empty($data['message']) || empty($data['ids'])) {
      return;
    }

    // JSON'dan mesaj nesnesi oluştur.
    try {
      $message = PwaPushMessage::fromJson($data['message']);
    }
    catch (\JsonException $e) {
      // Bozuk kuyruk öğesini sessizce at.
      \Drupal::logger('pwa_suite')->error('Kuyruk öğesi parse edilemedi: @msg', ['@msg' => $e->getMessage()]);
      return;
    }

    $ids = $data['ids'];

    // 'all' magic string — cursor pagination ile sendToAll'dan gelir (artık kullanılmıyor
    // ama geriye dönük uyumluluk için bırakıldı).
    if ($ids === 'all') {
      $ids = $this->database
        ->select('pwa_push_subscription', 's')
        ->fields('s', ['id'])
        ->condition('status', 1)
        ->execute()
        ->fetchCol();
    }

    if (!empty($ids)) {
      $this->pushService->processBatch((array) $ids, $message);
    }
  }

}
