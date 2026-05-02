<?php

/**
 * @file
 * Push bildirim gönderim servisi.
 */

namespace Drupal\pwa_suite\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\pwa_suite\ValueObject\PwaPushMessage;
use Drupal\pwa_suite\ValueObject\PwaPushResult;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Push bildirim gönderim ve kuyruk servisi.
 *
 * ── Güvenlik notları ────────────────────────────────────────────────────────
 * serialize()/unserialize() kullanılmaz. PHP Object Injection saldırısına karşı
 * tüm kuyruk verisi JSON olarak saklanır ve json_decode() ile okunur.
 * ────────────────────────────────────────────────────────────────────────────
 */
class PwaPushNotificationService {

  /** Bir abone için maksimum art arda başarısız gönderim sayısı. */
  const MAX_RETRIES = 5;

  /** Queue öğesi başına maksimum abonelik sayısı. */
  const BATCH_SIZE = 200;

  /** Üstel gecikme tabanı (saniye). */
  const RETRY_BASE_SECONDS = 120;

  public function __construct(
    protected PwaVapidKeyService $vapidKeyService,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QueueFactory $queueFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected ConfigFactoryInterface $configFactory,
    protected Connection $database,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Belirtilen abonelik ID'lerine bildirim kuyruğa ekler.
   *
   * Büyük listelerde bellek patlamasını önlemek için BATCH_SIZE'a böler.
   * Kuyruk verisi JSON olarak saklanır (serialize() kullanılmaz).
   */
  public function send(array $subscription_ids, PwaPushMessage $message): PwaPushResult {
    if (empty($subscription_ids)) {
      return new PwaPushResult();
    }

    $queue   = $this->queueFactory->get('pwa_suite_push_queue');
    $payload = $message->toJson(); // serialize() yerine JSON

    foreach (array_chunk($subscription_ids, self::BATCH_SIZE) as $batch) {
      $queue->createItem([
        'ids'     => $batch,
        'message' => $payload,
      ]);
    }

    $this->loggerFactory->get('pwa_suite')->info(
      '@count aboneye bildirim kuyruğa eklendi: @title',
      ['@count' => count($subscription_ids), '@title' => $message->title]
    );

    return new PwaPushResult(queued: count($subscription_ids));
  }

  /**
   * Belirli rollerdeki kullanıcılara bildirim kuyruğa ekler.
   */
  public function sendToRoles(array $roles, PwaPushMessage $message): PwaPushResult {
    if (empty($roles)) {
      return new PwaPushResult();
    }

    $uids = $this->database
      ->select('user__roles', 'ur')
      ->fields('ur', ['entity_id'])
      ->condition('roles_target_id', $roles, 'IN')
      ->execute()
      ->fetchCol();

    if (empty($uids)) {
      return new PwaPushResult();
    }

    $ids = $this->database
      ->select('pwa_push_subscription', 's')
      ->fields('s', ['id'])
      ->condition('uid', array_unique($uids), 'IN')
      ->condition('status', 1)
      ->execute()
      ->fetchCol();

    return $this->send($ids, $message);
  }

  /**
   * Tüm aktif abonelere bildirim kuyruğa ekler.
   *
   * Cursor tabanlı pagination ile büyük tablolarda bellek patlamasını önler.
   * Her BATCH_SIZE abonelik için ayrı queue öğesi oluşturur.
   */
  public function sendToAll(PwaPushMessage $message): PwaPushResult {
    $queue   = $this->queueFactory->get('pwa_suite_push_queue');
    $payload = $message->toJson();
    $total   = 0;
    $last_id = 0;

    // Cursor tabanlı pagination — tüm ID'leri bir anda RAM'e çekmez.
    do {
      $ids = $this->database
        ->select('pwa_push_subscription', 's')
        ->fields('s', ['id'])
        ->condition('status', 1)
        ->condition('id', $last_id, '>')
        ->orderBy('id', 'ASC')
        ->range(0, self::BATCH_SIZE)
        ->execute()
        ->fetchCol();

      if (!empty($ids)) {
        $queue->createItem(['ids' => $ids, 'message' => $payload]);
        $total   += count($ids);
        $last_id  = (int) end($ids);
      }
    } while (count($ids) === self::BATCH_SIZE);

    $this->loggerFactory->get('pwa_suite')->info(
      'sendToAll: @total aboneye bildirim kuyruğa eklendi: @title',
      ['@total' => $total, '@title' => $message->title]
    );

    return new PwaPushResult(queued: $total);
  }

  /**
   * Bir batch'i WebPush kütüphanesi ile gerçek olarak gönderir.
   *
   * Queue worker tarafından çağrılır.
   */
  public function processBatch(array $subscription_ids, PwaPushMessage $message): PwaPushResult {
    $result = new PwaPushResult();

    if (!$this->vapidKeyService->hasKeys()) {
      $this->loggerFactory->get('pwa_suite')->error('VAPID anahtarları bulunamadı.');
      $result->addFailed('VAPID eksik');
      return $result;
    }

    if (!class_exists('\\Minishlink\\WebPush\\WebPush')) {
      $this->loggerFactory->get('pwa_suite')->error('minishlink/web-push kütüphanesi eksik.');
      $result->addFailed('WebPush eksik');
      return $result;
    }

    try {
      $vapid = $this->vapidKeyService->getVapidDetails();
    }
    catch (\RuntimeException $e) {
      $result->addFailed($e->getMessage());
      return $result;
    }

    $subscriptions = $this->database
      ->select('pwa_push_subscription', 's')
      ->fields('s')
      ->condition('id', $subscription_ids, 'IN')
      ->condition('status', 1)
      ->execute()
      ->fetchAllAssoc('id');

    if (empty($subscriptions)) {
      return $result;
    }

    // Endpoint hash → ID eşlemesi (O(1) lookup).
    $hash_to_id = [];
    foreach ($subscriptions as $id => $sub) {
      $hash_to_id[hash('sha256', $sub->endpoint)] = (int) $id;
    }

    try {
      $webPush = new \Minishlink\WebPush\WebPush(
        ['VAPID' => $vapid],
        ['TTL' => $message->ttl, 'urgency' => $message->urgency]
      );

      $payload = $message->toPayload();
      if (empty($payload['icon']))  $payload['icon']  = $this->defaultIconUrl();
      if (empty($payload['badge'])) {
        $badge = $this->defaultBadgeUrl();
        if ($badge) $payload['badge'] = $badge;
      }

      \Drupal::moduleHandler()->invokeAll('pwa_push_message_alter', [&$payload, ['message' => $message]]);

      $payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE);
      $msg_hash     = hash('sha256', $payload_json);

      foreach ($subscriptions as $sub) {
        $sub_obj = \Minishlink\WebPush\Subscription::create([
          'endpoint' => $sub->endpoint,
          'keys'     => ['auth' => $sub->auth, 'p256dh' => $sub->p256dh],
        ]);
        $webPush->queueNotification($sub_obj, $payload_json);
      }

      \Drupal::moduleHandler()->invokeAll('pwa_push_send_pre', [$payload, array_keys($subscriptions)]);

      $now = \Drupal::time()->getRequestTime();

      // Toplu yazma için log batch'i — her gönderim için ayrı INSERT yerine.
      $log_batch = [];

      foreach ($webPush->flush() as $report) {
        $sub_id = $this->resolveSubscriptionId($report, $hash_to_id, $subscriptions);

        if ($report->isSuccess()) {
          $result->addSent();
          if ($sub_id) {
            $this->database->update('pwa_push_subscription')
              ->fields(['last_active' => $now])
              ->condition('id', $sub_id)
              ->execute();
          }
          if ($sub_id) {
            $log_batch[] = ['sub_id' => $sub_id, 'hash' => $msg_hash, 'title' => $message->title, 'status' => 'sent', 'error' => NULL];
          }
        }
        elseif ($report->isSubscriptionExpired()) {
          if ($sub_id) {
            $result->addExpired($sub_id, '');
            $this->disableSubscription($sub_id);
            $log_batch[] = ['sub_id' => $sub_id, 'hash' => $msg_hash, 'title' => $message->title, 'status' => 'expired', 'error' => '410 Gone'];
          }
        }
        elseif ($this->isPermanentClientError($report)) {
          $reason = $this->extractReason($report);
          if ($sub_id) {
            $result->addExpired($sub_id, '');
            $this->disableSubscription($sub_id);
            $log_batch[] = ['sub_id' => $sub_id, 'hash' => $msg_hash, 'title' => $message->title, 'status' => 'expired', 'error' => $reason];
          }
        }
        else {
          $reason = $this->extractReason($report);
          $result->addFailed($reason);
          if ($sub_id) {
            $this->maybeRetry($sub_id, $message, $reason);
            $log_batch[] = ['sub_id' => $sub_id, 'hash' => $msg_hash, 'title' => $message->title, 'status' => 'failed', 'error' => $reason];
          }
        }
      }

      // Toplu log INSERT — N INSERT yerine tek transaction.
      $this->writeLogs($log_batch, $now);

      \Drupal::moduleHandler()->invokeAll('pwa_push_send_post', [$result->toArray()]);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('pwa_suite')->error(
        'Push gönderim hatası: @msg',
        ['@msg' => $e->getMessage()]
      );
      $result->addFailed($e->getMessage());
    }

    return $result;
  }

  /**
   * Retry kuyruğundaki zamanı gelen öğeleri işler (cron tarafından çağrılır).
   */
  public function processRetryQueue(): void {
    $now   = \Drupal::time()->getRequestTime();
    $items = $this->database
      ->select('pwa_push_queue_retry', 'r')
      ->fields('r')
      ->condition('next_retry', $now, '<=')
      ->range(0, 50)
      ->execute()
      ->fetchAllAssoc('id');

    foreach ($items as $id => $item) {
      try {
        // JSON decode — serialize() kullanılmaz.
        $data = json_decode($item->message_data, TRUE);
        if (is_array($data)) {
          $message = PwaPushMessage::fromArray($data);
          $this->processBatch([(int) $item->subscription_id], $message);
        }
        $this->database->delete('pwa_push_queue_retry')->condition('id', $id)->execute();
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('pwa_suite')->error(
          'Retry hatası: @msg',
          ['@msg' => $e->getMessage()]
        );
      }
    }
  }

  // ── Korumalı yardımcı metodlar ────────────────────────────────────────────

  /**
   * Kalıcı 4xx client hatası mı?
   * 410 Gone zaten isSubscriptionExpired() ile yakalanır.
   */
  protected function isPermanentClientError(\Minishlink\WebPush\MessageSentReport $report): bool {
    $response = $report->getResponse();
    if ($response !== NULL) {
      $code = $response->getStatusCode();
      return $code >= 400 && $code < 500 && $code !== 410;
    }
    // Guzzle ClientException string pattern.
    return str_starts_with($report->getReason() ?? '', 'Client error:');
  }

  /**
   * Hata mesajı çıkar.
   */
  protected function extractReason(\Minishlink\WebPush\MessageSentReport $report): string {
    $response = $report->getResponse();
    if ($response !== NULL) {
      return 'HTTP ' . $response->getStatusCode();
    }
    return substr($report->getReason() ?? 'unknown', 0, 200);
  }

  /**
   * Flush raporundan subscription_id belirle.
   */
  protected function resolveSubscriptionId(
    \Minishlink\WebPush\MessageSentReport $report,
    array $hash_to_id,
    array $subscriptions,
  ): ?int {
    $endpoint = (string) $report->getRequest()->getUri();
    $hash     = hash('sha256', $endpoint);

    if (isset($hash_to_id[$hash])) {
      return $hash_to_id[$hash];
    }

    foreach ($subscriptions as $id => $sub) {
      if ($sub->endpoint === $endpoint) return (int) $id;
    }

    return NULL;
  }

  /**
   * Aboneliği pasifleştir.
   */
  protected function disableSubscription(int $sub_id): void {
    $this->database->update('pwa_push_subscription')
      ->fields(['status' => 0])
      ->condition('id', $sub_id)
      ->execute();
  }

  /**
   * Geçici hata sonrası retry kuyruğuna ekle.
   *
   * MAX_RETRIES geçilmişse aboneliği pasifleştir.
   * Kuyruk verisi JSON olarak saklanır.
   */
  protected function maybeRetry(int $sub_id, PwaPushMessage $message, string $error): void {
    // Tüm başarısız logları say (tek sorgu).
    $fail_count = (int) $this->database
      ->select('pwa_push_log', 'l')
      ->condition('subscription_id', $sub_id)
      ->condition('status', 'failed')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($fail_count >= self::MAX_RETRIES) {
      $this->disableSubscription($sub_id);
      return;
    }

    // Mevcut retry sayısı.
    $retry_count = (int) $this->database
      ->select('pwa_push_queue_retry', 'r')
      ->fields('r', ['retry_count'])
      ->condition('subscription_id', $sub_id)
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    // Üstel gecikme: 2dk → 10dk → 50dk
    $next_retry  = \Drupal::time()->getRequestTime() + (int) (self::RETRY_BASE_SECONDS * pow(5, $retry_count));

    $this->database->insert('pwa_push_queue_retry')->fields([
      'subscription_id' => $sub_id,
      'message_data'    => json_encode($message->toArray(), JSON_UNESCAPED_UNICODE), // JSON — serialize() değil
      'retry_count'     => $retry_count + 1,
      'next_retry'      => $next_retry,
      'created'         => \Drupal::time()->getRequestTime(),
    ])->execute();
  }

  /**
   * Toplu log INSERT — N ayrı query yerine tek transaction.
   */
  protected function writeLogs(array $entries, int $now): void {
    if (empty($entries)) return;

    $insert = $this->database->insert('pwa_push_log')
      ->fields(['subscription_id', 'message_hash', 'title', 'status', 'sent_at', 'error_message']);

    foreach ($entries as $e) {
      $insert->values([
        'subscription_id' => $e['sub_id'],
        'message_hash'    => $e['hash'],
        'title'           => substr($e['title'], 0, 255),
        'status'          => $e['status'],
        'sent_at'         => $now,
        'error_message'   => $e['error'] ? substr($e['error'], 0, 1000) : NULL,
      ]);
    }

    try {
      $insert->execute();
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('pwa_suite')->error('Log yazma hatası: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  protected function defaultIconUrl(): string {
    $config = $this->configFactory->get('pwa_suite.settings');

    if ($url = $config->get('push_notification_icon_url')) return $url;

    if ($fid = (int) $config->get('push_notification_icon_fid')) {
      $file = \Drupal\file\Entity\File::load($fid);
      if ($file) return \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
    }

    $icon_fids = $config->get('icon_fids') ?: [];
    foreach (['icon_192', 'icon_512', 'icon_384', 'icon_256'] as $key) {
      if (!empty($icon_fids[$key]) && ($file = \Drupal\file\Entity\File::load($icon_fids[$key]))) {
        return \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
      }
    }

    $req = $this->requestStack->getCurrentRequest();
    return ($req ? $req->getSchemeAndHttpHost() : '') . '/favicon.ico';
  }

  protected function defaultBadgeUrl(): string {
    $icon_fids = $this->configFactory->get('pwa_suite.settings')->get('icon_fids') ?: [];
    foreach (['icon_96', 'icon_72', 'icon_48', 'icon_128'] as $key) {
      if (!empty($icon_fids[$key]) && ($file = \Drupal\file\Entity\File::load($icon_fids[$key]))) {
        return \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
      }
    }
    return '';
  }

}
