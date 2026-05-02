<?php
namespace Drupal\pwa_suite\Controller;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;

class PwaSplashController extends ControllerBase {
  public function generate(string $dimensions): Response {
    [$w, $h] = array_map('intval', explode('x', $dimensions));
    if ($w < 100 || $w > 3000 || $h < 100 || $h > 3000) return new Response('Invalid dimensions', 400);
    $config   = $this->configFactory->get('pwa_suite.settings');
    $bg_color = $config->get('ios_splash_bg_color') ?: '#ffffff';
    $fg_color = $config->get('theme_color') ?: '#1565c0';
    $app_name = $config->get('name') ?: \Drupal::config('system.site')->get('name') ?: 'App';
    $icon_url = '';
    $icon_fids = $config->get('icon_fids') ?: [];
    foreach (['icon_512','icon_384','icon_256','icon_192'] as $k) {
      if (!empty($icon_fids[$k])) {
        $file = \Drupal\file\Entity\File::load($icon_fids[$k]);
        if ($file) { $icon_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri()); break; }
      }
    }
    $icon_size = (int) min($w,$h)/4;
    $icon_x = (int)($w/2-$icon_size/2); $icon_y = (int)($h/2-$icon_size/2-40); $text_y = $icon_y+$icon_size+60;
    $font_size = max(24,(int)$icon_size/5);
    $rx = (int)($icon_size*0.2);
    $icon_tag = $icon_url
      ? "<image href=\"".htmlspecialchars($icon_url)."\" x=\"{$icon_x}\" y=\"{$icon_y}\" width=\"{$icon_size}\" height=\"{$icon_size}\" rx=\"{$rx}\"/>"
      : "<rect x=\"{$icon_x}\" y=\"{$icon_y}\" width=\"{$icon_size}\" height=\"{$icon_size}\" rx=\"{$rx}\" fill=\"{$fg_color}\"/>";
    $svg = "<?xml version=\"1.0\"?><svg xmlns=\"http://www.w3.org/2000/svg\" xmlns:xlink=\"http://www.w3.org/1999/xlink\" width=\"{$w}\" height=\"{$h}\" viewBox=\"0 0 {$w} {$h}\">"
      . "<rect width=\"100%\" height=\"100%\" fill=\"{$bg_color}\"/>{$icon_tag}"
      . "<text x=\"50%\" y=\"{$text_y}\" text-anchor=\"middle\" font-family=\"sans-serif\" font-size=\"{$font_size}\" font-weight=\"600\" fill=\"{$fg_color}\">{$app_name}</text></svg>";
    return new Response($svg, 200, ['Content-Type'=>'image/svg+xml','Cache-Control'=>'public, max-age=86400']);
  }
}
