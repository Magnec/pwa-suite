<?php
namespace Drupal\pwa_suite\Controller;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class PwaSyncController extends ControllerBase {
  public function syncForms(Request $request): JsonResponse {
    $body = json_decode($request->getContent(), TRUE);
    if (empty($body['forms']) || !is_array($body['forms'])) {
      return new JsonResponse(['error' => 'Geçersiz payload.'], 400);
    }
    $processed = 0; $errors = [];
    foreach ($body['forms'] as $form_data) {
      $action = $form_data['action'] ?? '';
      if (empty($action)) { $errors[] = 'Boş action.'; continue; }
      $host = parse_url($action, PHP_URL_HOST);
      if ($host && $host !== $request->getHost()) { $errors[] = 'Farklı domain engellendi: ' . $host; continue; }
      \Drupal::logger('pwa_suite')->info('BG Sync form: @action', ['@action' => $action]);
      $processed++;
    }
    return new JsonResponse(['success' => TRUE, 'processed' => $processed, 'errors' => $errors]);
  }
}
