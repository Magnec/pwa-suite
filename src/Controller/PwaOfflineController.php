<?php
namespace Drupal\pwa_suite\Controller;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;

class PwaOfflineController extends ControllerBase {
  public function preview(): Response {
    $config = $this->configFactory->get('pwa_suite.settings');
    $html   = $config->get('sw_offline_html') ?: $this->defaultOfflineHtml($config);
    return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
  }
  protected function defaultOfflineHtml($config): string {
    $site_name   = htmlspecialchars($config->get('name') ?: \Drupal::config('system.site')->get('name') ?: 'Site');
    $bg_color    = htmlspecialchars($config->get('background_color') ?: '#ffffff');
    $theme_color = htmlspecialchars($config->get('theme_color') ?: '#1565c0');
    return "<!DOCTYPE html><html lang=\"tr\"><head><meta charset=\"utf-8\"><title>Çevrimdışı</title>"
      . "<style>body{font-family:sans-serif;background:{$bg_color};display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}"
      . ".card{background:#fff;border-radius:16px;padding:48px 32px;text-align:center;max-width:420px}"
      . "h1{color:{$theme_color}}button{background:{$theme_color};color:#fff;padding:12px 28px;border:none;border-radius:10px;cursor:pointer}</style>"
      . "</head><body><div class=\"card\"><div style=\"font-size:64px\">📡</div><h1>Bağlantı Yok</h1>"
      . "<p>{$site_name} şu an erişilemiyor.</p><button onclick=\"location.reload()\">Tekrar Dene</button></div></body></html>";
  }
}
