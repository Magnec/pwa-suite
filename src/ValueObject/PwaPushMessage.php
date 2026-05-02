<?php

namespace Drupal\pwa_suite\ValueObject;

/**
 * Push bildirim mesaj nesnesi.
 *
 * Immutable value object — tüm alanlar readonly.
 * JSON serileştirme desteği — serialize() kullanılmaz.
 */
final class PwaPushMessage {

  public function __construct(
    public readonly string $title,
    public readonly string $body                = '',
    public readonly string $icon                = '',
    public readonly string $badge               = '',
    public readonly string $url                 = '/',
    public readonly string $tag                 = 'pwa-suite',
    public readonly string $urgency             = 'normal',
    public readonly int    $ttl                 = 86400,
    public readonly bool   $requireInteraction  = FALSE,
    public readonly bool   $silent              = FALSE,
    public readonly array  $actions             = [],
    public readonly array  $data                = [],
    public readonly string $image               = '',
    public readonly array  $vibrate             = [],
  ) {}

  /**
   * Push payload array'i döndürür (boş değerler hariç).
   */
  public function toPayload(): array {
    $payload = [
      'title'              => $this->title,
      'body'               => $this->body,
      'icon'               => $this->icon,
      'badge'              => $this->badge,
      'tag'                => $this->tag,
      'url'                => $this->url,
      'silent'             => $this->silent,
      'requireInteraction' => $this->requireInteraction,
    ];

    if ($this->image)           $payload['image']   = $this->image;
    if (!empty($this->actions)) $payload['actions'] = $this->actions;
    if (!empty($this->vibrate)) $payload['vibrate'] = $this->vibrate;
    if (!empty($this->data))    $payload['data']    = $this->data;

    // Boş string, false ve null değerleri kaldır (title ve url hariç).
    return array_filter($payload, function ($v, $k) {
      if (in_array($k, ['title', 'url'], TRUE)) return TRUE;
      return $v !== '' && $v !== NULL && $v !== FALSE && $v !== [];
    }, ARRAY_FILTER_USE_BOTH);
  }

  /**
   * Nesneyi JSON string'e dönüştürür (kuyruk için — serialize() yerine).
   */
  public function toJson(): string {
    return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  }

  /**
   * Nesneyi düz array'e dönüştürür.
   */
  public function toArray(): array {
    return [
      'title'             => $this->title,
      'body'              => $this->body,
      'icon'              => $this->icon,
      'badge'             => $this->badge,
      'url'               => $this->url,
      'tag'               => $this->tag,
      'urgency'           => $this->urgency,
      'ttl'               => $this->ttl,
      'requireInteraction'=> $this->requireInteraction,
      'silent'            => $this->silent,
      'actions'           => $this->actions,
      'data'              => $this->data,
      'image'             => $this->image,
      'vibrate'           => $this->vibrate,
    ];
  }

  /**
   * JSON string'den nesne oluşturur.
   */
  public static function fromJson(string $json): static {
    $data = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    return static::fromArray($data);
  }

  /**
   * Array'den nesne oluşturur.
   */
  public static function fromArray(array $data): static {
    return new static(
      title:              (string) ($data['title'] ?? ''),
      body:               (string) ($data['body']  ?? ''),
      icon:               (string) ($data['icon']  ?? ''),
      badge:              (string) ($data['badge'] ?? ''),
      url:                (string) ($data['url']   ?? '/'),
      tag:                (string) ($data['tag']   ?? 'pwa-suite'),
      urgency:            (string) ($data['urgency'] ?? 'normal'),
      ttl:                (int)    ($data['ttl']    ?? 86400),
      requireInteraction: (bool)   ($data['requireInteraction'] ?? FALSE),
      silent:             (bool)   ($data['silent'] ?? FALSE),
      actions:            (array)  ($data['actions'] ?? []),
      data:               (array)  ($data['data']    ?? []),
      image:              (string) ($data['image']   ?? ''),
      vibrate:            (array)  ($data['vibrate'] ?? []),
    );
  }

}
