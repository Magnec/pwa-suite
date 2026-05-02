<?php
namespace Drupal\pwa_suite\Plugin\Block;
use Drupal\Core\Block\BlockBase;

/**
 * @Block(
 *   id = "pwa_share_block",
 *   admin_label = @Translation("PWA: Web Share Butonu"),
 *   category = @Translation("PWA Suite"),
 * )
 */
class PwaShareBlock extends BlockBase {
  public function build(): array {
    $config = \Drupal::config('pwa_suite.settings');
    if (!$config->get('web_share_enabled')) return [];
    return [
      '#theme'    => 'pwa_share_block',
      '#label'    => $this->t('Paylaş'),
      '#enabled'  => TRUE,
      '#cache'    => ['max-age' => 0],
      '#attached' => ['library' => ['pwa_suite/pwa_suite']],
    ];
  }
  public function getCacheMaxAge(): int { return 0; }
}
