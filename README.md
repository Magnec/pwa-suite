# PWA Suite — Drupal PWA Modülü

Kapsamlı Progressive Web App desteği: Web App Manifest, Service Worker,
Push Bildirimleri ve Install Banner.

## Gereksinimler

- Drupal 10 veya 11
- PHP 8.1+
- `minishlink/web-push` (^9.0 veya ^10.0)
- `ext-gmp` veya `ext-bcmath` (VAPID imzalama için)

## Kurulum

```bash
composer require minishlink/web-push
drush en pwa_suite
drush pwa:vapid:generate
drush pwa:files-write
```

## Nginx Yapılandırması (ISPConfig)

ISPConfig → Web → Site → Options → nginx Directives:

```nginx
location ~* ^/(sw\.js|manifest\.webmanifest)$ {
    default_type application/javascript;
    add_header Service-Worker-Allowed / always;
    add_header Cache-Control "no-store, no-cache, must-revalidate" always;
    try_files $uri @drupal;
}

location = /manifest.webmanifest {
    default_type application/manifest+json;
    add_header Cache-Control "public, max-age=3600" always;
    add_header Access-Control-Allow-Origin * always;
    try_files $uri @drupal;
}
```

## Tanı

- Admin: `/admin/config/system/pwa-suite/tani`
- Drush: `drush pwa:diagnose`

## Drush Komutları

| Komut                     | Açıklama                          |
|---------------------------|-----------------------------------|
| `pwa:files-write`         | SW ve manifest dosyalarını yaz    |
| `pwa:diagnose`            | Kurulum tanısı                    |
| `pwa:vapid:generate`      | VAPID anahtar oluştur             |
| `pwa:vapid:info`          | VAPID public key göster           |
| `pwa:push:send <title>`   | Test bildirimi gönder             |
| `pwa:push:stats`          | İstatistikleri göster             |
| `pwa:subscribers:cleanup` | Pasif abonelikleri sil            |
