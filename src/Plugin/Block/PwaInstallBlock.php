<?php
namespace Drupal\pwa_suite\Plugin\Block;
use Drupal\Core\Block\BlockBase;

/**
 * @Block(
 *   id = "pwa_install_block",
 *   admin_label = @Translation("PWA: Uygulama Yükleme Butonu"),
 *   category = @Translation("PWA Suite"),
 * )
 */
class PwaInstallBlock extends BlockBase {
  public function build(): array {
    $config = \Drupal::config('pwa_suite.settings');
    return [
      '#theme'         => 'pwa_install_block',
      '#title'         => $config->get('install_banner_title') ?: $this->t('Uygulamayı Yükle'),
      '#body'          => $config->get('install_banner_body')  ?: '',
      '#install_label' => $this->t('Yükle'),
      '#cache'         => ['max-age' => 0],
      '#attached'      => ['library' => ['pwa_suite/pwa_suite']],
    ];
  }
  public function getCacheMaxAge(): int { return 0; }
}
