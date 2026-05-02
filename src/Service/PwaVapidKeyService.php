<?php
namespace Drupal\pwa_suite\Service;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;

class PwaVapidKeyService {
  public function __construct(
    protected StateInterface $state,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  public function getPublicKey(): ?string  { return $this->state->get('pwa_suite.vapid_public_key'); }
  public function getPrivateKey(): ?string { return $this->state->get('pwa_suite.vapid_private_key'); }
  public function hasKeys(): bool { return !empty($this->getPublicKey()) && !empty($this->getPrivateKey()); }

  public function generateKeys(): array {
    if (!class_exists('\Minishlink\WebPush\VAPID')) {
      throw new \RuntimeException('minishlink/web-push kurulu değil. Çalıştırın: composer require minishlink/web-push');
    }
    $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
    $this->state->set('pwa_suite.vapid_public_key',  $keys['publicKey']);
    $this->state->set('pwa_suite.vapid_private_key', $keys['privateKey']);
    $this->loggerFactory->get('pwa_suite')->info('Yeni VAPID anahtar çifti oluşturuldu.');
    return $keys;
  }

  public function exportKeys(): array { return ['publicKey' => $this->getPublicKey(), 'privateKey' => $this->getPrivateKey()]; }

  public function importKeys(string $public_key, string $private_key): void {
    if (empty($public_key) || empty($private_key)) throw new \InvalidArgumentException('Geçersiz anahtar.');
    $this->state->set('pwa_suite.vapid_public_key',  $public_key);
    $this->state->set('pwa_suite.vapid_private_key', $private_key);
  }

  public function deleteKeys(): void {
    $this->state->deleteMultiple(['pwa_suite.vapid_public_key', 'pwa_suite.vapid_private_key']);
  }

  public function getVapidDetails(string $subject = ''): array {
    if (!$this->hasKeys()) throw new \RuntimeException('VAPID anahtarları oluşturulmamış.');
    if (empty($subject)) {
      $subject = $this->configFactory->get('pwa_suite.settings')->get('push_vapid_subject')
        ?: $this->configFactory->get('system.site')->get('mail')
        ?: 'mailto:admin@example.com';
      if (!str_starts_with($subject, 'mailto:') && !str_starts_with($subject, 'https://')) {
        $subject = 'mailto:' . $subject;
      }
    }
    return ['subject' => $subject, 'publicKey' => $this->getPublicKey(), 'privateKey' => $this->getPrivateKey()];
  }
}
