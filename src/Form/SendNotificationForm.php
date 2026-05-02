<?php
namespace Drupal\pwa_suite\Form;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\pwa_suite\Service\PwaPushNotificationService;
use Drupal\pwa_suite\ValueObject\PwaPushMessage;
use Symfony\Component\DependencyInjection\ContainerInterface;

class SendNotificationForm extends FormBase {
  protected PwaPushNotificationService $pushService;
  protected Connection $database;

  public static function create(ContainerInterface $container): static {
    $instance=parent::create($container);
    $instance->pushService=$container->get('pwa_suite.push_notification_service');
    $instance->database=$container->get('database');
    return $instance;
  }
  public function getFormId(): string { return 'pwa_suite_send_notification_form'; }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $active_count=(int)$this->database->select('pwa_push_subscription','s')->condition('status',1)->countQuery()->execute()->fetchField();
    $queue_count =(int)\Drupal::queue('pwa_suite_push_queue')->numberOfItems();
    $form['info']=['#markup'=>'<div class="messages messages--info"><strong>'.$active_count.'</strong> aktif abone | <strong>'.$queue_count.'</strong> kuyrukta</div>'];
    if (!$this->config('pwa_suite.settings')->get('push_enabled')) {
      $form['warning']=['#markup'=>'<div class="messages messages--warning">Push bildirimleri devre dışı.</div>'];
    }
    $form['message']=['#type'=>'details','#title'=>$this->t('Bildirim İçeriği'),'#open'=>TRUE];
    $form['message']['title']=['#type'=>'textfield','#title'=>$this->t('Başlık'),'#required'=>TRUE,'#maxlength'=>100];
    $form['message']['body'] =['#type'=>'textarea', '#title'=>$this->t('İçerik'),'#rows'=>3,'#maxlength'=>300];
    $form['message']['url']  =['#type'=>'url',      '#title'=>$this->t('Yönlendirme URL'),'#default_value'=>'/'];
    $form['message']['icon'] =['#type'=>'url',      '#title'=>$this->t('İkon URL')];
    $form['message']['image']=['#type'=>'url',      '#title'=>$this->t('Büyük Görsel URL')];
    $form['targeting']=['#type'=>'details','#title'=>$this->t('Hedef Kitle'),'#open'=>TRUE];
    $form['targeting']['target']=['#type'=>'radios','#title'=>$this->t('Kime?'),'#options'=>['all'=>$this->t('Tüm aktif aboneler (@n)',['@n'=>$active_count]),'logged_in'=>$this->t('Giriş yapmış'),'anonymous'=>$this->t('Anonim')],'#default_value'=>'all'];
    $form['advanced']=['#type'=>'details','#title'=>$this->t('Gelişmiş'),'#open'=>FALSE];
    $form['advanced']['urgency']=['#type'=>'select','#title'=>$this->t('Öncelik'),'#options'=>['very-low'=>'Çok Düşük','low'=>'Düşük','normal'=>'Normal','high'=>'Yüksek'],'#default_value'=>'normal'];
    $form['advanced']['ttl']=['#type'=>'number','#title'=>$this->t('TTL (saniye)'),'#default_value'=>86400,'#min'=>0,'#max'=>2419200];
    $form['advanced']['require_interaction']=['#type'=>'checkbox','#title'=>$this->t('Kullanıcı kapatana kadar kalsın')];
    $form['advanced']['tag']=['#type'=>'textfield','#title'=>$this->t('Tag'),'#default_value'=>'pwa-suite'];
    $form['submit']=['#type'=>'submit','#value'=>$this->t('🔔 Kuyruğa Ekle')];
    $form['#attached']['library'][]='pwa_suite/pwa_suite.admin';
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (empty(trim($form_state->getValue('title')))) $form_state->setErrorByName('title',$this->t('Başlık zorunludur.'));
    if (!$this->config('pwa_suite.settings')->get('push_enabled')) $form_state->setError($form,$this->t('Push bildirimleri devre dışı.'));
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $target=$form_state->getValue('target','all');
    $message=new PwaPushMessage(
      title: trim($form_state->getValue('title')),
      body:  trim($form_state->getValue('body','')),
      url:   $form_state->getValue('url','/')?:'/',
      icon:  $form_state->getValue('icon',''),
      image: $form_state->getValue('image',''),
      urgency: $form_state->getValue('urgency','normal'),
      ttl:   (int)$form_state->getValue('ttl',86400),
      requireInteraction: (bool)$form_state->getValue('require_interaction',FALSE),
      tag:   $form_state->getValue('tag','pwa-suite')?:'pwa-suite',
    );
    $q=$this->database->select('pwa_push_subscription','s')->fields('s',['id'])->condition('status',1);
    if ($target==='logged_in') $q->condition('uid',0,'>');
    elseif ($target==='anonymous') $q->condition('uid',0);
    $ids=$q->execute()->fetchCol();
    if (empty($ids)) { $this->messenger()->addWarning($this->t('Seçilen hedefte aktif abone yok.')); return; }
    $result=$this->pushService->send($ids,$message);
    $this->messenger()->addStatus($this->t('🔔 @count aboneye bildirim kuyruğa eklendi.',['@count'=>$result->getQueued()?:count($ids)]));
    \Drupal::logger('pwa_suite')->info('Manuel bildirim: "@title" → @count abone',['@title'=>$message->title,'@count'=>count($ids)]);
  }
}
