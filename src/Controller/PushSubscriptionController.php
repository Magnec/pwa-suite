<?php

/**
 * @file
 * Push abonelik yönetimi ve istatistik controller'ı.
 */

namespace Drupal\pwa_suite\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\pwa_suite\Service\PwaVapidKeyService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Push abonelik yönetimi ve istatistik controller'ı.
 */
class PushSubscriptionController extends ControllerBase {

  protected PwaVapidKeyService $vapidKeyService;
  protected Connection $database;
  protected FloodInterface $flood;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->vapidKeyService = $container->get('pwa_suite.vapid_key_service');
    $instance->database        = $container->get('database');
    $instance->flood           = $container->get('flood');
    return $instance;
  }

  // ── API Endpoint'leri ─────────────────────────────────────────────────────

  /**
   * VAPID public key döndürür.
   */
  public function vapidPublicKey(): JsonResponse {
    return new JsonResponse(['publicKey' => $this->vapidKeyService->getPublicKey()]);
  }

  /**
   * Push aboneliği kaydeder veya mevcut aboneliği aktifleştirir.
   */
  public function subscribe(Request $request): JsonResponse {
    $config     = \Drupal::config('pwa_suite.settings');
    $rate_limit = (int) ($config->get('rate_limit_subscribe') ?: 10);

    if (!$this->flood->isAllowed('pwa_suite_subscribe', $rate_limit, 60)) {
      return new JsonResponse(['error' => 'Çok fazla istek.'], 429);
    }
    $this->flood->register('pwa_suite_subscribe', 60);

    $data = json_decode($request->getContent(), TRUE);
    if (empty($data['endpoint']))       return new JsonResponse(['error' => '"endpoint" eksik.'], 400);
    if (empty($data['keys']['auth']))   return new JsonResponse(['error' => '"keys.auth" eksik.'], 400);
    if (empty($data['keys']['p256dh'])) return new JsonResponse(['error' => '"keys.p256dh" eksik.'], 400);

    $endpoint = (string) $data['endpoint'];

    // Endpoint URL güvenlik validasyonu.
    // - Maksimum 2048 karakter (sonsuz veri kabul etme).
    // - https:// ile başlamalı (HTTP'den gelen push endpoint geçersiz).
    // - URL formatı geçerli olmalı.
    if (strlen($endpoint) > 2048) {
      return new JsonResponse(['error' => 'Geçersiz endpoint.'], 400);
    }
    if (!str_starts_with($endpoint, 'https://')) {
      return new JsonResponse(['error' => 'Geçersiz endpoint protokolü.'], 400);
    }
    if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
      return new JsonResponse(['error' => 'Geçersiz endpoint URL.'], 400);
    }

    // Auth ve p256dh uzunluk kontrolü (base64url encoded).
    $auth   = (string) $data['keys']['auth'];
    $p256dh = (string) $data['keys']['p256dh'];
    if (strlen($auth) < 10 || strlen($auth) > 512)     return new JsonResponse(['error' => 'Geçersiz auth anahtarı.'], 400);
    if (strlen($p256dh) < 10 || strlen($p256dh) > 512) return new JsonResponse(['error' => 'Geçersiz p256dh anahtarı.'], 400);
    // auth ve p256dh yukarıda zaten tanımlandı ve doğrulandı.
    $hash     = hash('sha256', $endpoint);
    $now      = \Drupal::time()->getRequestTime();
    $uid      = $this->currentUser()->isAuthenticated() ? (int) $this->currentUser()->id() : 0;
    $ua       = (string) $request->headers->get('User-Agent', '');
    [$browser, $platform] = $this->detectBrowserPlatform($ua);

    $existing = $this->database
      ->select('pwa_push_subscription', 's')
      ->fields('s', ['id'])
      ->condition('subscription_hash', $hash)
      ->execute()
      ->fetchField();

    if ($existing) {
      // Daha önce iptal edilmiş aboneliği yeniden aktifleştir.
      $this->database->update('pwa_push_subscription')
        ->fields(['auth' => $auth, 'p256dh' => $p256dh, 'status' => 1, 'uid' => $uid, 'last_active' => $now])
        ->condition('id', $existing)
        ->execute();
      return new JsonResponse(['subscribed' => TRUE, 'id' => $existing]);
    }

    $id = $this->database->insert('pwa_push_subscription')->fields([
      'uid'               => $uid,
      'endpoint'          => $endpoint,
      'subscription_hash' => $hash,
      'auth'              => $auth,
      'p256dh'            => $p256dh,
      'browser'           => $browser,
      'platform'          => $platform,
      'status'            => 1,
      'created'           => $now,
      'last_active'       => $now,
    ])->execute();

    return new JsonResponse(['subscribed' => TRUE, 'id' => $id]);
  }

  /**
   * Push aboneliğini iptal eder.
   *
   * Abonelik kaydı veritabanından silinir; kullanıcı isterse tekrar abone olabilir.
   */
  public function unsubscribe(Request $request): JsonResponse {
    // Rate limiting — aynı IP'den aşırı istek engelle.
    if (!$this->flood->isAllowed('pwa_suite_unsubscribe', 10, 60)) {
      return new JsonResponse(['error' => 'Çok fazla istek.'], 429);
    }
    $this->flood->register('pwa_suite_unsubscribe', 60);

    $data = json_decode($request->getContent(), TRUE);

    if (empty($data['endpoint'])) {
      return new JsonResponse(['error' => '"endpoint" eksik.'], 400);
    }

    $hash = hash('sha256', $data['endpoint']);

    // Aboneliği tamamen sil — sadece pasifleştirme değil, gerçek silme.
    $this->database->delete('pwa_push_subscription')
      ->condition('subscription_hash', $hash)
      ->execute();

    return new JsonResponse(['unsubscribed' => TRUE]);
  }

  /**
   * Aboneliği günceller (pushsubscriptionchange olayında).
   */
  public function update(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);

    if (empty($data['endpoint'])) {
      return new JsonResponse(['error' => '"endpoint" eksik.'], 400);
    }

    $hash = hash('sha256', $data['endpoint']);
    $now  = \Drupal::time()->getRequestTime();
    $uid  = $this->currentUser()->isAuthenticated() ? (int) $this->currentUser()->id() : 0;

    $existing = $this->database
      ->select('pwa_push_subscription', 's')
      ->fields('s', ['id'])
      ->condition('subscription_hash', $hash)
      ->execute()
      ->fetchField();

    if ($existing) {
      $this->database->update('pwa_push_subscription')
        ->fields([
          'auth'        => $data['keys']['auth']   ?? '',
          'p256dh'      => $data['keys']['p256dh'] ?? '',
          'status'      => 1,
          'last_active' => $now,
        ])
        ->condition('id', $existing)
        ->execute();
    }
    else {
      $ua = (string) $request->headers->get('User-Agent', '');
      [$browser, $platform] = $this->detectBrowserPlatform($ua);
      $this->database->insert('pwa_push_subscription')->fields([
        'uid'               => $uid,
        'endpoint'          => $data['endpoint'],
        'subscription_hash' => $hash,
        'auth'              => $data['keys']['auth']   ?? '',
        'p256dh'            => $data['keys']['p256dh'] ?? '',
        'browser'           => $browser,
        'platform'          => $platform,
        'status'            => 1,
        'created'           => $now,
        'last_active'       => $now,
      ])->execute();
    }

    return new JsonResponse(['updated' => TRUE]);
  }

  /**
   * Kullanıcının abonelik durumunu döndürür.
   */
  public function status(Request $request): JsonResponse {
    $endpoint = $request->query->get('endpoint', '');

    if (empty($endpoint)) {
      return new JsonResponse(['subscribed' => FALSE]);
    }

    $hash = hash('sha256', $endpoint);
    $row  = $this->database
      ->select('pwa_push_subscription', 's')
      ->fields('s', ['id', 'status'])
      ->condition('subscription_hash', $hash)
      ->execute()
      ->fetchAssoc();

    return new JsonResponse([
      'subscribed' => !empty($row) && (int) $row['status'] === 1,
      'id'         => $row['id'] ?? NULL,
    ]);
  }

  // ── Admin Sayfaları ───────────────────────────────────────────────────────

  /**
   * Admin: Push aboneleri listesi.
   */
  public function adminList(Request $request): array {
    $total  = (int) $this->database->select('pwa_push_subscription', 's')->countQuery()->execute()->fetchField();
    $active = (int) $this->database->select('pwa_push_subscription', 's')->condition('status', 1)->countQuery()->execute()->fetchField();

    $per_page = 50;
    $page     = max(0, (int) $request->query->get('page', 0));

    $subs = $this->database
      ->select('pwa_push_subscription', 's')
      ->fields('s')
      ->orderBy('created', 'DESC')
      ->range($page * $per_page, $per_page)
      ->execute()
      ->fetchAll();

    $rows = [];
    foreach ($subs as $sub) {
      $user_label = $sub->uid
        ? (\Drupal\user\Entity\User::load($sub->uid)?->getDisplayName() ?: 'uid:' . $sub->uid)
        : $this->t('Anonim');

      $del_link = Link::fromTextAndUrl(
        $this->t('Sil'),
        Url::fromRoute('pwa_suite.subscriber_delete', ['id' => $sub->id])
      )->toString();

      $rows[] = [
        $sub->id,
        $user_label,
        $this->browserIcon($sub->browser),
        $this->platformIcon($sub->platform),
        ['data' => ['#markup' => '<code title="' . htmlspecialchars($sub->endpoint) . '" style="font-size:11px">'
          . htmlspecialchars(substr($sub->endpoint, -40)) . '</code>']],
        \Drupal::service('date.formatter')->format((int) $sub->created, 'short'),
        \Drupal::service('date.formatter')->format((int) $sub->last_active, 'short'),
        ['data' => ['#markup' => $sub->status
          ? '<span style="color:#2e7d32;font-weight:600">✓ Aktif</span>'
          : '<span style="color:#c62828">✗ Pasif</span>']],
        ['data' => ['#markup' => $del_link]],
      ];
    }

    $build['summary'] = ['#markup' =>
      '<div class="pwa-stats-summary">'
      . '<div class="pwa-stat"><strong>' . $total  . '</strong>Toplam</div>'
      . '<div class="pwa-stat"><strong>' . $active . '</strong>Aktif</div>'
      . '<div class="pwa-stat"><strong>' . ($total - $active) . '</strong>Pasif</div>'
      . '</div>',
    ];

    $bulk_url = Url::fromRoute('pwa_suite.subscriber_delete_bulk')->toString();
    $build['bulk_actions'] = ['#markup' =>
      '<div style="margin:8px 0">'
      . '<a href="' . $bulk_url . '" class="button button--danger" '
      . 'onclick="return confirm(\'Tüm pasif abonelikler silinecek. Emin misiniz?\')">'
      . '🗑 Pasif Abonelikleri Sil</a>'
      . '</div>',
    ];

    $build['table'] = [
      '#type'   => 'table',
      '#header' => [
        'ID', $this->t('Kullanıcı'), $this->t('Tarayıcı'), $this->t('Platform'),
        $this->t('Endpoint'), $this->t('Kayıt'), $this->t('Son Aktif'),
        $this->t('Durum'), $this->t('İşlem'),
      ],
      '#rows'  => $rows,
      '#empty' => $this->t('Henüz abone yok.'),
    ];

    $build['#attached']['library'][] = 'pwa_suite/pwa_suite.admin';
    return $build;
  }

  /**
   * Admin: Tekil abonelik silme.
   */
  public function deleteSubscriber(Request $request, int $id) {
    if ($id > 0) {
      $this->database->delete('pwa_push_subscription')->condition('id', $id)->execute();
      $this->messenger()->addStatus($this->t('Abonelik #@id silindi.', ['@id' => $id]));
    }
    return $this->redirect('pwa_suite.subscribers');
  }

  /**
   * Admin: Toplu pasif abonelik silme.
   */
  public function deleteSubscriberBulk(Request $request) {
    $n = $this->database->delete('pwa_push_subscription')->condition('status', 0)->execute();
    $this->messenger()->addStatus($this->t('@n pasif abonelik silindi.', ['@n' => $n]));
    return $this->redirect('pwa_suite.subscribers');
  }

  /**
   * Admin: İstatistikler ve push log.
   */
  public function stats(Request $request): array {
    $db         = $this->database;
    $total      = (int) $db->select('pwa_push_subscription', 's')->countQuery()->execute()->fetchField();
    $active     = (int) $db->select('pwa_push_subscription', 's')->condition('status', 1)->countQuery()->execute()->fetchField();
    $sent       = (int) $db->select('pwa_push_log', 'l')->condition('status', 'sent')->countQuery()->execute()->fetchField();
    $failed     = (int) $db->select('pwa_push_log', 'l')->condition('status', 'failed')->countQuery()->execute()->fetchField();
    $queued     = (int) \Drupal::queue('pwa_suite_push_queue')->numberOfItems();
    $rate       = ($sent + $failed) > 0 ? round($sent / ($sent + $failed) * 100, 1) . '%' : '-';
    $total_logs = (int) $db->select('pwa_push_log', 'l')->countQuery()->execute()->fetchField();

    // Log + abonelik + kullanıcı bilgisi tek sorguda.
    $query = $db->select('pwa_push_log', 'l');
    $query->fields('l');
    $query->leftJoin('pwa_push_subscription', 's', 's.id = l.subscription_id');
    $query->addField('s', 'uid', 'sub_uid');
    $query->leftJoin('users_field_data', 'u', 'u.uid = s.uid');
    $query->addField('u', 'name', 'username');
    $query->orderBy('l.sent_at', 'DESC');
    $query->range(0, 200);
    $logs = $query->execute()->fetchAll();

    $log_rows = [];
    foreach ($logs as $log) {
      $badge = match($log->status ?? '') {
        'sent'    => '<span class="pwa-log-badge pwa-log-badge--sent">✓ Gönderildi</span>',
        'failed'  => '<span class="pwa-log-badge pwa-log-badge--failed">✗ Başarısız</span>',
        'expired' => '<span class="pwa-log-badge pwa-log-badge--expired">⚠ Expired</span>',
        default   => htmlspecialchars($log->status ?? ''),
      };

      // Kullanıcı bilgisi: kayıtlı kullanıcı ise profil linki, anonim ise ikon.
      if (!empty($log->sub_uid) && (int) $log->sub_uid > 0) {
        $username = htmlspecialchars($log->username ?: ('uid:' . $log->sub_uid));
        $user_cell = '<a href="/user/' . (int) $log->sub_uid . '" class="pwa-log-user" target="_blank" title="Kullanıcı profili aç">👤 ' . $username . '</a>';
      }
      else {
        $user_cell = '<span class="pwa-log-anon" title="Anonim kullanıcı">🌐 Anonim</span>';
      }

      $del_link = Link::fromTextAndUrl(
        $this->t('Sil'),
        Url::fromRoute('pwa_suite.stats_log_delete', ['id' => $log->id])
      )->toString();

      $log_rows[] = [
        $log->id,
        ['data' => ['#markup' => $user_cell]],
        htmlspecialchars(substr($log->title ?? '', 0, 55)),
        ['data' => ['#markup' => $badge]],
        $log->sent_at ? \Drupal::service('date.formatter')->format((int) $log->sent_at, 'short') : '-',
        ['data' => ['#markup' => '<small class="pwa-log-err">' . htmlspecialchars(substr($log->error_message ?? '', 0, 80)) . '</small>']],
        ['data' => ['#markup' => $del_link]],
      ];
    }

    $build['summary'] = ['#markup' =>
      '<div class="pwa-stats-summary">'
      . '<div class="pwa-stat"><strong>' . $total  . '</strong>Toplam Abone</div>'
      . '<div class="pwa-stat"><strong>' . $active . '</strong>Aktif</div>'
      . '<div class="pwa-stat"><strong>' . $sent   . '</strong>Gönderildi</div>'
      . '<div class="pwa-stat"><strong>' . $failed . '</strong>Başarısız</div>'
      . '<div class="pwa-stat"><strong>' . $rate   . '</strong>Başarı Oranı</div>'
      . '<div class="pwa-stat"><strong>' . $queued . '</strong>Kuyrukta</div>'
      . '</div>',
    ];

    $clear_url = Url::fromRoute('pwa_suite.stats_log_clear')->toString();
    $build['log_actions'] = ['#markup' =>
      '<div style="margin:12px 0;display:flex;gap:12px;align-items:center;flex-wrap:wrap">'
      . '<strong>' . $total_logs . ' log kaydı</strong>'
      . (count($logs) < $total_logs ? ' <em>(son 200 gösteriliyor)</em>' : '')
      . '<a href="' . $clear_url . '" class="button button--danger" '
      . 'onclick="return confirm(\'Tüm log kayıtları kalıcı silinecek. Emin misiniz?\')">'
      . '🗑 Tümünü Sil</a>'
      . '</div>',
    ];

    $build['logs'] = [
      '#type'   => 'table',
      '#header' => ['ID', $this->t('Kullanıcı'), $this->t('Başlık'), $this->t('Durum'), $this->t('Zaman'), $this->t('Hata'), $this->t('İşlem')],
      '#rows'   => $log_rows,
      '#empty'  => $this->t('Log kaydı yok.'),
    ];

    $build['#attached']['library'][] = 'pwa_suite/pwa_suite.admin';
    return $build;
  }

  /**
   * Admin: Tek log kaydını sil.
   */
  public function deleteLog(Request $request, int $id) {
    if ($id > 0) {
      $this->database->delete('pwa_push_log')->condition('id', $id)->execute();
      $this->messenger()->addStatus($this->t('Log #@id silindi.', ['@id' => $id]));
    }
    return $this->redirect('pwa_suite.stats');
  }

  /**
   * Admin: Tüm log kayıtlarını sil.
   */
  public function clearLogs(Request $request) {
    $this->database->truncate('pwa_push_log')->execute();
    $this->messenger()->addStatus($this->t('Tüm push log kayıtları silindi.'));
    return $this->redirect('pwa_suite.stats');
  }

  // ── Yardımcı Metodlar ─────────────────────────────────────────────────────

  /**
   * User-Agent'tan tarayıcı ve platform tespiti.
   *
   * @return array [browser, platform]
   */
  protected function detectBrowserPlatform(string $ua): array {
    $b = 'other';
    $p = 'other';

    if (str_contains($ua, 'SamsungBrowser'))                           $b = 'samsung';
    elseif (str_contains($ua, 'Edg/'))                                 $b = 'edge';
    elseif (str_contains($ua, 'Firefox/'))                             $b = 'firefox';
    elseif (str_contains($ua, 'Chrome/'))                              $b = 'chrome';
    elseif (str_contains($ua, 'Safari/'))                              $b = 'safari';

    if (str_contains($ua, 'Android'))                                  $p = 'android';
    elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) $p = 'ios';
    elseif (str_contains($ua, 'Windows'))                              $p = 'windows';
    elseif (str_contains($ua, 'Macintosh'))                            $p = 'macos';
    elseif (str_contains($ua, 'Linux'))                                $p = 'linux';

    return [$b, $p];
  }

  protected function platformIcon(string $p): string {
    return match($p) {
      'android' => '🤖 Android', 'ios'     => '🍎 iOS',
      'windows' => '🪟 Windows',  'macos'   => '🍎 macOS',
      'linux'   => '🐧 Linux',    default   => '❓ ' . $p,
    };
  }

  protected function browserIcon(string $b): string {
    return match($b) {
      'chrome'  => '🟡 Chrome',   'firefox' => '🟠 Firefox',
      'safari'  => '🔵 Safari',   'edge'    => '🔷 Edge',
      'samsung' => '📱 Samsung',  default   => '❓ ' . $b,
    };
  }

  /**
   * Anahtar değerini döndürür.
   */
  public static function decryptKey(string $value): string {
    return $value;
  }

}
