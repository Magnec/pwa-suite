<?php
namespace Drupal\pwa_suite\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Push bildirim abone/iptal bloğu — 8 varyant.
 *
 * @Block(
 *   id = "pwa_notification_block",
 *   admin_label = @Translation("PWA: Push Bildirim Aboneliği"),
 *   category = @Translation("PWA Suite"),
 * )
 */
class PwaNotificationBlock extends BlockBase {

  public function defaultConfiguration(): array {
    return [
      'variant'             => 'default',
      'subscribe_label'     => '',
      'unsubscribe_label'   => '',
      'toggle_label_on'     => '',
      'toggle_label_off'    => '',
      'modal_title'         => '',
      'modal_description'   => '',
      'modal_icon'          => '🔔',
      'modal_auto_delay'    => 5,
      'card_title'          => '',
      'card_description'    => '',
      'card_image_url'      => '',
      'banner_text'         => '',
      'show_status'         => TRUE,
    ];
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);
    $conf = $this->configuration;

    $variant_options = [
      'default'       => $this->t('1. Varsayılan — ayrı abone/iptal butonları'),
      'toggle'        => $this->t('2. Toggle — tek buton, metin değişir'),
      'floating'      => $this->t('3. Floating FAB — sağ altta sabit yüzen buton'),
      'modal'         => $this->t('4. Modal — buton + açılır diyalog penceresi'),
      'banner'        => $this->t('5. Banner — sayfada tam genişlik şerit'),
      'card'          => $this->t('6. Card — görsel açıklama kartı'),
      'inline-switch' => $this->t('7. Inline Switch — iOS tarzı toggle switch'),
      'snackbar'      => $this->t('8. Snackbar — sayfanın altından kayan bildirim'),
    ];

    $form['variant'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Varyant'),
      '#options'       => $variant_options,
      '#default_value' => $conf['variant'] ?? 'default',
      '#description'   => $this->t('Her varyant farklı bir UX sunar. Aşağıdaki alanlar seçilen varyanta göre aktif olur.'),
    ];

    // ── Tüm varyantlarda geçerli ──────────────────────────────
    $form['labels'] = [
      '#type'  => 'details',
      '#title' => $this->t('Etiketler'),
      '#open'  => TRUE,
    ];
    $form['labels']['subscribe_label'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Abone Ol etiketi'),
      '#default_value' => $conf['subscribe_label'] ?: '',
      '#placeholder'   => '🔔 Bildirimlere Abone Ol',
    ];
    $form['labels']['unsubscribe_label'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Aboneliği İptal etiketi'),
      '#default_value' => $conf['unsubscribe_label'] ?: '',
      '#placeholder'   => '🔕 Aboneliği İptal Et',
    ];
    $form['labels']['show_status'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Abonelik durumunu göster'),
      '#default_value' => (bool) ($conf['show_status'] ?? TRUE),
    ];

    // ── Toggle varyantı ───────────────────────────────────────
    $vis_toggle = ['visible' => [':input[name="settings[variant]"]' => ['value' => 'toggle']]];
    $form['toggle_group'] = [
      '#type'   => 'details',
      '#title'  => $this->t('Toggle Ayarları'),
      '#open'   => FALSE,
      '#states' => $vis_toggle,
    ];
    $form['toggle_group']['toggle_label_on'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Abone değilken buton metni'),
      '#default_value' => $conf['toggle_label_on'] ?: '',
    ];
    $form['toggle_group']['toggle_label_off'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Abone iken buton metni'),
      '#default_value' => $conf['toggle_label_off'] ?: '',
    ];

    // ── Modal varyantı ────────────────────────────────────────
    $vis_modal = ['visible' => [':input[name="settings[variant]"]' => ['value' => 'modal']]];
    $form['modal_group'] = [
      '#type'   => 'details',
      '#title'  => $this->t('Modal Ayarları'),
      '#open'   => FALSE,
      '#states' => $vis_modal,
    ];
    $form['modal_group']['modal_icon'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('İkon (emoji veya metin)'),
      '#default_value' => $conf['modal_icon'] ?: '🔔',
      '#size'          => 6,
    ];
    $form['modal_group']['modal_title'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Modal başlığı'),
      '#default_value' => $conf['modal_title'] ?: '',
      '#placeholder'   => 'Bildirimleri Aç',
    ];
    $form['modal_group']['modal_description'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Açıklama metni'),
      '#default_value' => $conf['modal_description'] ?: '',
      '#rows'          => 2,
      '#placeholder'   => 'Yeni içeriklerden anında haberdar olmak için...',
    ];
    $form['modal_group']['modal_auto_delay'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Otomatik açılma gecikmesi (saniye)'),
      '#default_value' => (int) ($conf['modal_auto_delay'] ?? 5),
      '#min'           => 1,
      '#max'           => 120,
      '#description'   => $this->t('Kullanıcı sayfaya geldikten kaç saniye sonra modal otomatik açılsın? Zaten abone ise açılmaz. Aynı oturumda kapatıldıktan sonra tekrar açılmaz.'),
      '#field_suffix'  => $this->t('saniye'),
    ];

    // ── Banner varyantı ───────────────────────────────────────
    $vis_banner = ['visible' => [':input[name="settings[variant]"]' => ['value' => 'banner']]];
    $form['banner_group'] = [
      '#type'   => 'details',
      '#title'  => $this->t('Banner Ayarları'),
      '#open'   => FALSE,
      '#states' => $vis_banner,
    ];
    $form['banner_group']['banner_text'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Banner metni'),
      '#default_value' => $conf['banner_text'] ?: '',
      '#placeholder'   => 'Yeni içerikler için bildirimleri açın!',
    ];

    // ── Card varyantı ─────────────────────────────────────────
    $vis_card = ['visible' => [':input[name="settings[variant]"]' => ['value' => 'card']]];
    $form['card_group'] = [
      '#type'   => 'details',
      '#title'  => $this->t('Card Ayarları'),
      '#open'   => FALSE,
      '#states' => $vis_card,
    ];
    $form['card_group']['card_title'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Kart başlığı'),
      '#default_value' => $conf['card_title'] ?: '',
      '#placeholder'   => 'Bildirimlere Abone Ol',
    ];
    $form['card_group']['card_description'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Kart açıklaması'),
      '#default_value' => $conf['card_description'] ?: '',
      '#rows'          => 2,
      '#placeholder'   => 'Yeni bölümler ve içeriklerden anında haberdar ol.',
    ];
    $form['card_group']['card_image_url'] = [
      '#type'          => 'url',
      '#title'         => $this->t('Görsel URL (opsiyonel)'),
      '#default_value' => $conf['card_image_url'] ?: '',
      '#description'   => $this->t('Boş bırakılırsa emoji gösterilir.'),
    ];

    // ── Snackbar varyantı ─────────────────────────────────────
    $vis_snack = ['visible' => [':input[name="settings[variant]"]' => ['value' => 'snackbar']]];
    $form['snackbar_group'] = [
      '#type'   => 'details',
      '#title'  => $this->t('Snackbar Ayarları'),
      '#open'   => FALSE,
      '#states' => $vis_snack,
    ];
    $form['snackbar_group']['banner_text'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Snackbar metni'),
      '#default_value' => $conf['banner_text'] ?: '',
      '#placeholder'   => 'Bildirimlere abone olmak ister misiniz?',
    ];

    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['variant']           = $form_state->getValue('variant');
    $this->configuration['subscribe_label']   = $form_state->getValue(['labels', 'subscribe_label']);
    $this->configuration['unsubscribe_label'] = $form_state->getValue(['labels', 'unsubscribe_label']);
    $this->configuration['show_status']       = (bool) $form_state->getValue(['labels', 'show_status']);
    $this->configuration['toggle_label_on']   = $form_state->getValue(['toggle_group', 'toggle_label_on']);
    $this->configuration['toggle_label_off']  = $form_state->getValue(['toggle_group', 'toggle_label_off']);
    $this->configuration['modal_icon']        = $form_state->getValue(['modal_group', 'modal_icon']);
    $this->configuration['modal_title']       = $form_state->getValue(['modal_group', 'modal_title']);
    $this->configuration['modal_description'] = $form_state->getValue(['modal_group', 'modal_description']);
    $this->configuration['banner_text']       = $form_state->getValue(['banner_group', 'banner_text'])
                                             ?: $form_state->getValue(['snackbar_group', 'banner_text']);
    $this->configuration['card_title']        = $form_state->getValue(['card_group', 'card_title']);
    $this->configuration['card_description']  = $form_state->getValue(['card_group', 'card_description']);
    $this->configuration['card_image_url']    = $form_state->getValue(['card_group', 'card_image_url']);
  }

  public function build(): array {
    $config = \Drupal::config('pwa_suite.settings');
    if (!$config->get('push_enabled')) return [];

    $conf = $this->configuration;

    return [
      '#theme'               => 'pwa_notification_block',
      '#push_enabled'        => TRUE,
      '#variant'             => $conf['variant']           ?: 'default',
      '#subscribe_label'     => $conf['subscribe_label']   ?: $this->t('🔔 Bildirimlere Abone Ol'),
      '#unsubscribe_label'   => $conf['unsubscribe_label'] ?: $this->t('🔕 Aboneliği İptal Et'),
      '#toggle_label_on'     => $conf['toggle_label_on']   ?: $this->t('🔔 Bildirimlere Abone Ol'),
      '#toggle_label_off'    => $conf['toggle_label_off']  ?: $this->t('🔕 Bildirimleri Kapat'),
      '#modal_title'         => $conf['modal_title']       ?: $this->t('Bildirimleri Aç'),
      '#modal_description'   => $conf['modal_description'] ?: $this->t('Yeni içeriklerden anında haberdar olmak için bildirimlere abone olun.'),
      '#modal_icon'          => $conf['modal_icon']        ?: '🔔',
      '#modal_auto_delay'    => (int) ($conf['modal_auto_delay'] ?? 5) * 1000,
      '#card_title'          => $conf['card_title']        ?: $this->t('Bildirimlere Abone Ol'),
      '#card_description'    => $conf['card_description']  ?: $this->t('Yeni bölümler ve içeriklerden anında haberdar ol.'),
      '#card_image_url'      => $conf['card_image_url']    ?: '',
      '#banner_text'         => $conf['banner_text']       ?: $this->t('Yeni içerikler için bildirimleri açın!'),
      '#show_status'         => (bool) ($conf['show_status'] ?? TRUE),
      '#cache'               => ['max-age' => 0],
      '#attached'            => ['library' => ['pwa_suite/pwa_suite']],
    ];
  }

  public function getCacheMaxAge(): int { return 0; }
}
