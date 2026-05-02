<?php
namespace Drupal\pwa_suite\Form;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\pwa_suite\Service\PwaVapidKeyService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class PwaSuiteSettingsForm extends ConfigFormBase {
  protected PwaVapidKeyService $vapidKeyService;
  protected EntityTypeManagerInterface $entityTypeManager;

  public static function create(ContainerInterface $container): static {
    $instance=parent::create($container);
    $instance->vapidKeyService=$container->get('pwa_suite.vapid_key_service');
    $instance->entityTypeManager=$container->get('entity_type.manager');
    return $instance;
  }
  protected function getEditableConfigNames(): array { return ['pwa_suite.settings']; }
  public function getFormId(): string { return 'pwa_suite_settings_form'; }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config=$this->config('pwa_suite.settings');
    $form['pwa_tabs']=['#type'=>'vertical_tabs'];
    $this->buildManifestTab($form,$config);
    $this->buildIconsTab($form,$config);
    $this->buildPushTab($form,$config);
    $this->buildTriggersTab($form,$config);
    $this->buildSwTab($form,$config);
    $this->buildIosTab($form,$config);
    $this->buildBannerTab($form,$config);
    $this->buildAdvancedTab($form,$config);
    $form['#attached']['library'][]='pwa_suite/pwa_suite.admin';
    return parent::buildForm($form,$form_state);
  }

  protected function buildManifestTab(array &$form, $config): void {
    $form['manifest']=['#type'=>'details','#title'=>$this->t('Web App Manifest'),'#group'=>'pwa_tabs'];
    $f=&$form['manifest'];
    $f['name']        =['#type'=>'textfield','#title'=>$this->t('Uygulama Adı'),'#default_value'=>$config->get('name')?:\Drupal::config('system.site')->get('name'),'#required'=>TRUE];
    $f['short_name']  =['#type'=>'textfield','#title'=>$this->t('Kısa Ad (maks 12)'),  '#default_value'=>$config->get('short_name'),'#maxlength'=>12];
    $f['description'] =['#type'=>'textarea', '#title'=>$this->t('Açıklama'),            '#default_value'=>$config->get('description'),'#rows'=>2];
    $f['id']          =['#type'=>'textfield','#title'=>$this->t('Uygulama ID'),         '#default_value'=>$config->get('id')?:'/?source=pwa'];
    $f['start_url']   =['#type'=>'textfield','#title'=>$this->t('Başlangıç URL'),       '#default_value'=>$config->get('start_url')?:'/?source=pwa'];
    $f['scope']       =['#type'=>'textfield','#title'=>$this->t('Kapsam'),              '#default_value'=>$config->get('scope')?:'/'];
    $f['theme_color']     =['#type'=>'color','#title'=>$this->t('Tema Rengi'),          '#default_value'=>$config->get('theme_color')?:'#1565c0'];
    $f['background_color']=['#type'=>'color','#title'=>$this->t('Arkaplan Rengi'),      '#default_value'=>$config->get('background_color')?:'#ffffff'];
    $f['display']=['#type'=>'select','#title'=>$this->t('Görüntüleme Modu'),'#options'=>['standalone'=>'standalone','fullscreen'=>'fullscreen','minimal-ui'=>'minimal-ui','browser'=>'browser','window-controls-overlay'=>'window-controls-overlay'],'#default_value'=>$config->get('display')?:'standalone'];
    $f['orientation']=['#type'=>'select','#title'=>$this->t('Yönelim'),'#options'=>['any'=>'any','portrait'=>'portrait','landscape'=>'landscape'],'#default_value'=>$config->get('orientation')?:'any'];
    $f['lang']=['#type'=>'textfield','#title'=>$this->t('Dil'),'#default_value'=>$config->get('lang')?:'tr','#size'=>8];
    $f['dir'] =['#type'=>'select',   '#title'=>$this->t('Yön'), '#options'=>['ltr'=>'ltr','rtl'=>'rtl'],'#default_value'=>$config->get('dir')?:'ltr'];
    $f['share_target_enabled']=['#type'=>'checkbox','#title'=>$this->t('Share Target API'),'#default_value'=>(bool)$config->get('share_target_enabled')];
    $f['share_target_action'] =['#type'=>'textfield','#title'=>$this->t('Share Target URL'),'#default_value'=>$config->get('share_target_action')?:'/share-target','#states'=>['visible'=>[':input[name="share_target_enabled"]'=>['checked'=>TRUE]]]];
  }

  protected function buildIconsTab(array &$form, $config): void {
    $form['icons_tab']=['#type'=>'details','#title'=>$this->t('İkonlar'),'#group'=>'pwa_tabs'];
    $f=&$form['icons_tab'];
    $icon_fids=$config->get('icon_fids')?:[];
    $sizes=['icon_512'=>'512x512 (Zorunlu)','icon_192'=>'192x192 (Zorunlu)','icon_384'=>'384x384','icon_256'=>'256x256','icon_180'=>'180x180 (Apple)','icon_152'=>'152x152','icon_144'=>'144x144','icon_128'=>'128x128','icon_96'=>'96x96','icon_72'=>'72x72','icon_48'=>'48x48'];
    $f['icons_group']=['#type'=>'details','#title'=>$this->t('Standart İkonlar'),'#open'=>TRUE];
    foreach ($sizes as $key=>$label) {
      $fid=$icon_fids[$key]??0;
      $f['icons_group'][$key]=['#type'=>'managed_file','#title'=>$label,'#default_value'=>$fid?[$fid]:[],'#upload_location'=>'public://pwa_suite/icons','#upload_validators'=>['FileExtension'=>['extensions'=>'png jpg jpeg webp svg']]];
    }
    $f['maskable_group']=['#type'=>'details','#title'=>$this->t('Maskable İkonlar'),'#open'=>TRUE];
    $f['maskable_group']['icon_maskable_fid']=['#type'=>'managed_file','#title'=>$this->t('Maskable 512x512'),'#default_value'=>$config->get('icon_maskable_fid')?[$config->get('icon_maskable_fid')]:[],'#upload_location'=>'public://pwa_suite/icons','#upload_validators'=>['FileExtension'=>['extensions'=>'png webp']]];
    $f['maskable_group']['icon_maskable_192_fid']=['#type'=>'managed_file','#title'=>$this->t('Maskable 192x192'),'#default_value'=>$config->get('icon_maskable_192_fid')?[$config->get('icon_maskable_192_fid')]:[],'#upload_location'=>'public://pwa_suite/icons','#upload_validators'=>['FileExtension'=>['extensions'=>'png webp']]];
    $f['push_icons_group']=['#type'=>'details','#title'=>$this->t('Push İkonları'),'#open'=>TRUE];
    $f['push_icons_group']['push_notification_icon_url'] =['#type'=>'textfield','#title'=>$this->t('Bildirim İkon URL'),'#default_value'=>$config->get('push_notification_icon_url')];
    $f['push_icons_group']['push_notification_icon_fid'] =['#type'=>'managed_file','#title'=>$this->t('Bildirim İkon Dosyası'),'#default_value'=>$config->get('push_notification_icon_fid')?[$config->get('push_notification_icon_fid')]:[],'#upload_location'=>'public://pwa_suite/icons','#upload_validators'=>['FileExtension'=>['extensions'=>'png jpg jpeg webp']]];
    $f['push_icons_group']['push_notification_badge_url']=['#type'=>'textfield','#title'=>$this->t('Badge URL'),'#default_value'=>$config->get('push_notification_badge_url')];
    $f['push_icons_group']['push_notification_badge_fid']=['#type'=>'managed_file','#title'=>$this->t('Badge Dosyası'),'#default_value'=>$config->get('push_notification_badge_fid')?[$config->get('push_notification_badge_fid')]:[],'#upload_location'=>'public://pwa_suite/icons','#upload_validators'=>['FileExtension'=>['extensions'=>'png webp']]];
  }

  protected function buildPushTab(array &$form, $config): void {
    $form['push']=['#type'=>'details','#title'=>$this->t('Push Bildirimleri'),'#group'=>'pwa_tabs'];
    $f=&$form['push'];
    $f['push_enabled']      =['#type'=>'checkbox',  '#title'=>$this->t('Push etkin'),      '#default_value'=>(bool)$config->get('push_enabled')];
    $f['push_vapid_subject']=['#type'=>'textfield', '#title'=>$this->t('VAPID Subject'),   '#default_value'=>$config->get('push_vapid_subject'),'#placeholder'=>'mailto:admin@example.com'];
    $hasKeys=$this->vapidKeyService->hasKeys();
    $pubKey =$this->vapidKeyService->getPublicKey();
    $msg=$hasKeys?'<strong style="color:green">VAPID mevcut.</strong><br><small>'.htmlspecialchars(substr((string)$pubKey,0,40)).'...</small>':'<strong style="color:red">VAPID yok.</strong>';
    $f['vapid_info']    =['#markup'=>'<div class="messages messages--'.($hasKeys?'status':'warning').'">'.$msg.'</div>'];
    $f['generate_vapid']=['#type'=>'submit','#value'=>$this->t('Yeni VAPID Oluştur'),'#submit'=>['::generateVapidKeys'],'#button_type'=>'danger'];
  }

  protected function buildTriggersTab(array &$form, $config): void {
    $form['triggers']=['#type'=>'details','#title'=>$this->t('Otomatik Tetikleyiciler'),'#group'=>'pwa_tabs','#tree'=>FALSE];
    $f=&$form['triggers'];
    $triggers=$config->get('triggers')??[];
    $nt=$this->getNodeTypeOptions(); $ro=$this->getRoleOptions();
    $nth=$this->getNodeTokenHelp(); $cth=$this->getCommentTokenHelp();
    $f['node_insert']   =$this->buildTrigger('node_insert',   $this->t('Yeni İçerik Oluşturulduğunda'), $triggers['node_insert']??[],$nt,$ro,['all'=>$this->t('Tüm aboneler'),'logged_in'=>$this->t('Giriş yapmış'),'author'=>$this->t('İçerik sahibi')],$nth);
    $f['node_published']=$this->buildTrigger('node_published',$this->t('İçerik Yayınlandığında'),       $triggers['node_published']??[],$nt,$ro,['all'=>$this->t('Tüm aboneler'),'logged_in'=>$this->t('Giriş yapmış'),'author'=>$this->t('İçerik sahibi')],$nth);
    $f['node_update']   =$this->buildTrigger('node_update',   $this->t('İçerik Güncellendiğinde'),      $triggers['node_update']??[],$nt,$ro,['all'=>$this->t('Tüm aboneler'),'logged_in'=>$this->t('Giriş yapmış')],$nth);
    $f['comment_insert']=$this->buildTrigger('comment_insert',$this->t('Yeni Yorum Eklendiğinde'),      $triggers['comment_insert']??[],$nt,$ro,['all'=>$this->t('Tüm aboneler'),'node_author'=>$this->t('İçeriğin sahibi')],$cth);
    $f['comment_reply'] =$this->buildTrigger('comment_reply', $this->t('Yoruma Yanıt Verildiğinde'),    $triggers['comment_reply']??[],$nt,$ro,['node_author'=>$this->t('İçeriğin sahibi'),'comment_author'=>$this->t('Yanıt verilen yorumun sahibi')],$cth);
  }

  protected function buildTrigger(string $key,$title,array $cfg,array $nt,array $ro,array $to,string $th): array {
    $targets=$cfg['targets']??(isset($cfg['target'])?[$cfg['target']]:['all']);
    $el=['#type'=>'details','#title'=>$title,'#open'=>!empty($cfg['enabled']),'#tree'=>FALSE];
    $el[$key.'_enabled']       =['#type'=>'checkbox',  '#title'=>$this->t('Etkin'),           '#default_value'=>(bool)($cfg['enabled']??FALSE)];
    $vis=[':input[name="'.$key.'_enabled"]'=>['checked'=>TRUE]];
    if (!empty($nt)) $el[$key.'_content_types']=['#type'=>'checkboxes','#title'=>$this->t('İçerik Türleri'),'#options'=>$nt,'#default_value'=>$cfg['content_types']??[],'#states'=>['visible'=>$vis]];
    $el[$key.'_title_template']=['#type'=>'textfield','#title'=>$this->t('Bildirim Başlığı'), '#default_value'=>$cfg['title_template']??'','#maxlength'=>100,'#states'=>['visible'=>$vis]];
    $el[$key.'_body_template'] =['#type'=>'textarea', '#title'=>$this->t('Bildirim Metni'),   '#default_value'=>$cfg['body_template']??'','#rows'=>2,'#states'=>['visible'=>$vis]];
    $el[$key.'_url_template']  =['#type'=>'textfield','#title'=>$this->t('Yönlendirme URL'),  '#default_value'=>$cfg['url_template']??'/','#states'=>['visible'=>$vis]];
    $el[$key.'_token_help']    =['#type'=>'details',  '#title'=>$this->t('Tokenlar'),          '#open'=>FALSE,'#markup'=>$th,'#states'=>['visible'=>$vis]];
    $el[$key.'_targets']       =['#type'=>'checkboxes','#title'=>$this->t('Kimlere?'),         '#options'=>$to,'#default_value'=>$targets,'#states'=>['visible'=>$vis]];
    if (!empty($ro)) $el[$key.'_roles']=['#type'=>'checkboxes','#title'=>$this->t('Ek Roller'),'#options'=>$ro,'#default_value'=>$cfg['roles']??[],'#states'=>['visible'=>$vis]];
    return $el;
  }

  protected function buildSwTab(array &$form, $config): void {
    $form['sw']=['#type'=>'details','#title'=>$this->t('Service Worker'),'#group'=>'pwa_tabs'];
    $f=&$form['sw'];
    $f['sw_enabled']      =['#type'=>'checkbox',  '#title'=>$this->t('Service Worker etkin'),'#default_value'=>(bool)$config->get('sw_enabled')];
    $f['sw_debug']        =['#type'=>'checkbox',  '#title'=>$this->t('Debug modu'),           '#default_value'=>(bool)$config->get('sw_debug')];
    $f['sw_cache_version']=['#type'=>'textfield', '#title'=>$this->t('Cache Versiyonu'),       '#default_value'=>$config->get('sw_cache_version')?:'v1'];
    $f['sw_auto_version'] =['#type'=>'checkbox',  '#title'=>$this->t('Versiyonu otomatik güncelle (cron)'),'#default_value'=>(bool)$config->get('sw_auto_version')];
    $f['sw_offline_url']  =['#type'=>'textfield', '#title'=>$this->t('Offline Sayfa URL'),     '#default_value'=>$config->get('sw_offline_url')?:'/offline'];
    $f['sw_bg_sync_enabled']=['#type'=>'checkbox','#title'=>$this->t('Background Sync'),       '#default_value'=>(bool)$config->get('sw_bg_sync_enabled')];
    $f['sw_precache_urls_text']=['#type'=>'textarea','#title'=>$this->t('Precache URL listesi (satır satır)'),'#default_value'=>implode("\n",$config->get('sw_precache_urls')?:[]),'#rows'=>5];
  }

  protected function buildIosTab(array &$form, $config): void {
    $form['ios']=['#type'=>'details','#title'=>$this->t('iOS PWA'),'#group'=>'pwa_tabs'];
    $f=&$form['ios'];
    $f['ios_meta_enabled']       =['#type'=>'checkbox','#title'=>$this->t('iOS PWA meta etiketleri'),      '#default_value'=>(bool)$config->get('ios_meta_enabled')];
    $f['ios_status_bar_style']   =['#type'=>'select',  '#title'=>$this->t('Status Bar Stili'),             '#options'=>['default'=>'default','black'=>'black','black-translucent'=>'black-translucent'],'#default_value'=>$config->get('ios_status_bar_style')?:'black-translucent'];
    $f['ios_splash_screens']     =['#type'=>'checkbox','#title'=>$this->t('iOS Splash Screens'),           '#default_value'=>(bool)$config->get('ios_splash_screens')];
    $f['ios_splash_bg_color']    =['#type'=>'color',   '#title'=>$this->t('Splash Arkaplan'),              '#default_value'=>$config->get('ios_splash_bg_color')?:'#ffffff'];
    $f['ios_smart_banner_enabled']=['#type'=>'checkbox','#title'=>$this->t('iOS Smart App Banner'),        '#default_value'=>(bool)$config->get('ios_smart_banner_enabled')];
    $f['ios_app_id']             =['#type'=>'textfield','#title'=>$this->t('App Store App ID'),            '#default_value'=>$config->get('ios_app_id'),'#size'=>20,'#states'=>['visible'=>[':input[name="ios_smart_banner_enabled"]'=>['checked'=>TRUE]]]];
  }

  protected function buildBannerTab(array &$form, $config): void {
    $form['banner']=['#type'=>'details','#title'=>$this->t('Install Banner'),'#group'=>'pwa_tabs'];
    $f=&$form['banner'];
    $f['install_banner_enabled']=['#type'=>'checkbox',  '#title'=>$this->t('Install banner etkin'),'#default_value'=>(bool)$config->get('install_banner_enabled')];
    $f['install_banner_title']  =['#type'=>'textfield', '#title'=>$this->t('Banner Başlığı'),       '#default_value'=>$config->get('install_banner_title')];
    $f['install_banner_body']   =['#type'=>'textfield', '#title'=>$this->t('Banner Metni'),         '#default_value'=>$config->get('install_banner_body')];
    $f['install_banner_delay']  =['#type'=>'number',    '#title'=>$this->t('Gecikme (ms)'),         '#default_value'=>(int)($config->get('install_banner_delay')?:3000),'#min'=>0,'#max'=>60000];
    $f['web_share_enabled']     =['#type'=>'checkbox',  '#title'=>$this->t('Web Share API butonu'), '#default_value'=>(bool)$config->get('web_share_enabled')];
    $f['pwa_page_meta']         =['#type'=>'checkbox',  '#title'=>$this->t('PWA meta etiketlerini sayfaya ekle'),'#default_value'=>(bool)$config->get('pwa_page_meta')];
  }

  protected function buildAdvancedTab(array &$form, $config): void {
    $form['advanced']=['#type'=>'details','#title'=>$this->t('Gelişmiş'),'#group'=>'pwa_tabs'];
    $f=&$form['advanced'];
    $nginx_code = "location = /sw.js {\n    add_header Service-Worker-Allowed / always;\n    add_header Cache-Control \"no-store, no-cache, must-revalidate\" always;\n}\nlocation = /manifest.webmanifest {\n    add_header Content-Type \"application/manifest+json\" always;\n    add_header Cache-Control \"public, max-age=3600\" always;\n}";
    $f['nginx_info'] = [
      '#markup' => '<div class="messages messages--warning">'
        . '<strong>' . $this->t('ISPConfig / Nginx Konfigürasyonu') . '</strong><br>'
        . $this->t('Push bildirimleri çalışmıyorsa ISPConfig &rarr; Web &rarr; Site &rarr; <em>Options</em> sekmesi &rarr; <em>nginx Directives</em> alanına şunu ekleyin:')
        . '<pre style="background:#fff8e1;border:1px solid #ffe082;padding:10px;border-radius:4px;font-size:12px;margin:8px 0 0;overflow-x:auto">'
        . htmlspecialchars($nginx_code) . '</pre>'
        . '<small>' . $this->t('Bu direktifler, <code>sw.js</code> için gerekli <code>Service-Worker-Allowed</code> header\'ını ekler.') . '</small>'
        . '</div>',
    ];
    $f['rate_limit_subscribe']=['#type'=>'number', '#title'=>$this->t('Abonelik Rate Limit (dk/IP)'),    '#default_value'=>(int)($config->get('rate_limit_subscribe')?:10),'#min'=>1,'#max'=>100];
    $f['https_redirect']      =['#type'=>'checkbox','#title'=>$this->t('HTTP → HTTPS yönlendirme'),     '#default_value'=>(bool)$config->get('https_redirect')];
    $f['launch_handler_client_mode']=['#type'=>'select','#title'=>$this->t('Launch Handler'),'#options'=>['auto'=>'auto','navigate-existing'=>'navigate-existing','navigate-new'=>'navigate-new','focus-existing'=>'focus-existing'],'#default_value'=>$config->get('launch_handler_client_mode')?:'auto'];
    $f['edge_side_panel_enabled']=['#type'=>'checkbox','#title'=>$this->t('Edge Side Panel'),'#default_value'=>(bool)$config->get('edge_side_panel_enabled')];
    $f['iarc_rating_id']=['#type'=>'textfield','#title'=>$this->t('IARC Rating ID'),'#default_value'=>$config->get('iarc_rating_id')?:'','#size'=>40];
    $f['note_taking_new_note_url']=['#type'=>'textfield','#title'=>$this->t('Note Taking URL'),'#default_value'=>$config->get('note_taking_new_note_url')?:'','#placeholder'=>'/node/add/note'];
    $cats=implode("\n",$config->get('categories')?:[]);
    $f['categories_text']=['#type'=>'textarea','#title'=>$this->t('Kategoriler (satır satır)'),'#default_value'=>$cats,'#rows'=>3];
    $f['prefer_related_applications']=['#type'=>'checkbox','#title'=>$this->t('Native uygulamayı tercih et'),'#default_value'=>(bool)$config->get('prefer_related_applications')];
    $f['sw_periodic_sync_enabled'] =['#type'=>'checkbox','#title'=>$this->t('Periodic Background Sync'),'#default_value'=>(bool)$config->get('sw_periodic_sync_enabled')];
    $f['sw_periodic_sync_interval']=['#type'=>'number',  '#title'=>$this->t('Periodic Sync Aralığı (saniye)'),'#default_value'=>(int)($config->get('sw_periodic_sync_interval')?:3600),'#min'=>300];
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config=$this->configFactory->getEditable('pwa_suite.settings');
    $vals=$form_state->getValues();

    // Manifest
    $config->set('name',$vals['name'])->set('short_name',$vals['short_name'])->set('description',$vals['description'])
      ->set('id',$vals['id'])->set('start_url',$vals['start_url'])->set('scope',$vals['scope'])
      ->set('theme_color',$vals['theme_color'])->set('background_color',$vals['background_color'])
      ->set('display',$vals['display'])->set('orientation',$vals['orientation'])
      ->set('lang',$vals['lang'])->set('dir',$vals['dir'])
      ->set('share_target_enabled',(bool)$vals['share_target_enabled'])->set('share_target_action',$vals['share_target_action']);

    // İkonlar — file.usage takibi + permanent işaretleme
    // Drupal temporary dosyaları cron'da siler. Tüm yüklenen dosyalar:
    //  1. setPermanent() ile kalıcı işaretlenmeli
    //  2. file.usage->add() ile kayıt altına alınmalı
    //  3. Değiştirilen eski dosyalar için file.usage->delete() çağrılmalı
    $icon_fids_old = $config->get('icon_fids') ?: [];
    $icon_fids     = $icon_fids_old;
    $icon_sizes    = ['icon_512','icon_192','icon_384','icon_256','icon_180','icon_152','icon_144','icon_128','icon_96','icon_72','icon_48'];
    $icons = [];
    foreach ($icon_sizes as $key) {
      $fids = $vals[$key] ?? [];
      $fid  = !empty($fids) ? (int) reset($fids) : 0;
      $old_fid = (int) ($icon_fids_old[$key] ?? 0);
      $this->_saveFileUsage($old_fid, $fid, $key);
      $icon_fids[$key] = $fid;
      if ($fid && ($file = \Drupal\file\Entity\File::load($fid))) {
        $url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
        $size = str_replace('icon_', '', $key);
        [$w, $h] = array_pad(explode('x', $size . 'x' . $size), 2, $size);
        $icons[] = ['src' => $url, 'sizes' => $w . 'x' . $h, 'type' => 'image/png', 'purpose' => 'any'];
      }
    }
    $config->set('icon_fids', $icon_fids);

    foreach (['icon_maskable_fid' => '512x512', 'icon_maskable_192_fid' => '192x192'] as $fid_key => $size) {
      $fids    = $vals[$fid_key] ?? [];
      $fid     = !empty($fids) ? (int) reset($fids) : 0;
      $old_fid = (int) ($config->get($fid_key) ?: 0);
      $this->_saveFileUsage($old_fid, $fid, $fid_key);
      $config->set($fid_key, $fid);
      if ($fid && ($file = \Drupal\file\Entity\File::load($fid))) {
        $url  = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
        $icons[] = ['src' => $url, 'sizes' => $size, 'type' => 'image/png', 'purpose' => 'maskable'];
      }
    }
    $config->set('icons', $icons);

    foreach (['push_notification_icon_fid', 'push_notification_badge_fid'] as $fid_key) {
      $fids    = $vals[$fid_key] ?? [];
      $fid     = !empty($fids) ? (int) reset($fids) : 0;
      $old_fid = (int) ($config->get($fid_key) ?: 0);
      $this->_saveFileUsage($old_fid, $fid, $fid_key);
      $config->set($fid_key, $fid);
    }

    // Push
    $config->set('push_notification_icon_url',trim($vals['push_notification_icon_url']??''))
      ->set('push_notification_badge_url',trim($vals['push_notification_badge_url']??''))
      ->set('push_enabled',(bool)$vals['push_enabled'])->set('push_vapid_subject',$vals['push_vapid_subject']);

    // iOS
    $config->set('ios_meta_enabled',(bool)$vals['ios_meta_enabled'])->set('ios_status_bar_style',$vals['ios_status_bar_style'])
      ->set('ios_splash_screens',(bool)$vals['ios_splash_screens'])->set('ios_splash_bg_color',$vals['ios_splash_bg_color'])
      ->set('ios_smart_banner_enabled',(bool)$vals['ios_smart_banner_enabled'])->set('ios_app_id',trim($vals['ios_app_id']??''));

    // SW
    $precache_list=array_values(array_filter(array_map('trim',explode("\n",$vals['sw_precache_urls_text']??''))));
    $config->set('sw_enabled',(bool)$vals['sw_enabled'])->set('sw_debug',(bool)$vals['sw_debug'])
      ->set('sw_cache_version',$vals['sw_cache_version'])->set('sw_auto_version',(bool)$vals['sw_auto_version'])
      ->set('sw_offline_url',$vals['sw_offline_url'])->set('sw_bg_sync_enabled',(bool)$vals['sw_bg_sync_enabled'])
      ->set('sw_periodic_sync_enabled',(bool)$vals['sw_periodic_sync_enabled'])->set('sw_periodic_sync_interval',(int)$vals['sw_periodic_sync_interval'])
      ->set('sw_precache_urls',$precache_list);

    // Banner
    $config->set('install_banner_enabled',(bool)$vals['install_banner_enabled'])->set('install_banner_title',$vals['install_banner_title'])
      ->set('install_banner_body',$vals['install_banner_body'])->set('install_banner_delay',(int)$vals['install_banner_delay'])
      ->set('web_share_enabled',(bool)$vals['web_share_enabled'])->set('pwa_page_meta',(bool)$vals['pwa_page_meta']);

    // Gelişmiş
    $config->set('rate_limit_subscribe',(int)$vals['rate_limit_subscribe'])->set('https_redirect',(bool)$vals['https_redirect'])
      ->set('launch_handler_client_mode',$vals['launch_handler_client_mode']??'auto')
      ->set('edge_side_panel_enabled',(bool)($vals['edge_side_panel_enabled']??FALSE))
      ->set('iarc_rating_id',trim($vals['iarc_rating_id']??''))->set('note_taking_new_note_url',trim($vals['note_taking_new_note_url']??''))
      ->set('prefer_related_applications',(bool)($vals['prefer_related_applications']??FALSE))
      ->set('categories',array_values(array_filter(array_map('trim',explode("\n",$vals['categories_text']??'')))));

    // Triggers
    $triggers=$config->get('triggers')??[];
    foreach (['node_insert','node_published','node_update','comment_insert','comment_reply'] as $key) {
      $raw_t=$vals[$key.'_targets']??[]; $raw_r=$vals[$key.'_roles']??[]; $raw_c=$vals[$key.'_content_types']??[];
      $triggers[$key]=[
        'enabled'       =>(bool)($vals[$key.'_enabled']??FALSE),
        'content_types' =>is_array($raw_c)?array_values(array_filter(array_keys(array_filter($raw_c)))):[],
        'title_template'=>trim((string)($vals[$key.'_title_template']??'')),
        'body_template' =>trim((string)($vals[$key.'_body_template']??'')),
        'url_template'  =>trim((string)($vals[$key.'_url_template']??'/')),
        'targets'       =>is_array($raw_t)?(array_values(array_filter(array_keys(array_filter($raw_t))))?:['all']):['all'],
        'roles'         =>is_array($raw_r)?array_values(array_filter(array_keys(array_filter($raw_r)))):[],
      ];
    }
    $config->set('triggers',$triggers)->save();
    parent::submitForm($form,$form_state);
    $this->messenger()->addStatus($this->t('PWA Suite ayarları kaydedildi. drush cr önerilir.'));
  }

  /**
   * Dosya kullanımını takip eder ve dosyayı kalıcı olarak işaretler.
   *
   * Drupal, file.usage tablosunda kaydı olmayan 'temporary' dosyaları cron
   * sırasında siler. Bu metod:
   *  - Yeni dosyayı 'permanent' olarak işaretler ve kullanımı kaydeder.
   *  - Eski dosyanın kullanım kaydını siler; başka kullanımı yoksa siler.
   *
   * @param int    $old_fid  Önceki dosya ID'si (0 = yoktu)
   * @param int    $new_fid  Yeni dosya ID'si (0 = kaldırıldı)
   * @param string $usage_id Kullanım tanımlayıcısı (örn: 'icon_512')
   */
  protected function _saveFileUsage(int $old_fid, int $new_fid, string $usage_id): void {
    /** @var \Drupal\file\FileUsage\FileUsageInterface $file_usage */
    $file_usage = \Drupal::service('file.usage');

    // Eski dosyanın kullanım kaydını kaldır.
    if ($old_fid > 0 && $old_fid !== $new_fid) {
      $old_file = \Drupal\file\Entity\File::load($old_fid);
      if ($old_file) {
        $file_usage->delete($old_file, 'pwa_suite', 'config', $usage_id);
        // Başka kullanıcısı yoksa geçici olarak işaretle (cron temizleyecek).
        $usages = $file_usage->listUsage($old_file);
        if (empty($usages)) {
          $old_file->setTemporary();
          $old_file->save();
        }
      }
    }

    // Yeni dosyayı kalıcı işaretle ve kullanım kaydı oluştur.
    if ($new_fid > 0) {
      $new_file = \Drupal\file\Entity\File::load($new_fid);
      if ($new_file) {
        if ($new_file->isTemporary()) {
          $new_file->setPermanent();
          $new_file->save();
        }
        // Aynı dosya zaten kayıtlıysa tekrar ekleme.
        $existing = $file_usage->listUsage($new_file);
        $already  = FALSE;
        foreach ($existing['pwa_suite']['config'] ?? [] as $id => $count) {
          if ($id === $usage_id) { $already = TRUE; break; }
        }
        if (!$already) {
          $file_usage->add($new_file, 'pwa_suite', 'config', $usage_id);
        }
      }
    }
  }

  public function generateVapidKeys(array &$form, FormStateInterface $form_state): void {
    try {
      $keys=$this->vapidKeyService->generateKeys();
      $this->messenger()->addStatus($this->t('VAPID oluşturuldu: @k',['@k'=>substr($keys['publicKey'],0,40).'...']));
    } catch(\Exception $e) { $this->messenger()->addError($this->t('Hata: @msg',['@msg'=>$e->getMessage()])); }
  }

  protected function getNodeTypeOptions(): array {
    $o=[];
    try{foreach($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $t) $o[$t->id()]=$t->label();}catch(\Exception $e){}
    return $o;
  }
  protected function getRoleOptions(): array {
    $o=[];
    try{foreach($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $r){if(!in_array($r->id(),['anonymous','authenticated']))$o[$r->id()]=$r->label();}}catch(\Exception $e){}
    return $o;
  }
  protected function getNodeTokenHelp(): string {
    return $this->buildTokenTable(['[node:title]'=>'Başlık','[node:url]'=>'URL','[node:body:summary]'=>'Özet','[node:author:name]'=>'Yazar','[node:created]'=>'Tarih','[site:name]'=>'Site adı']);
  }
  protected function getCommentTokenHelp(): string {
    return $this->buildTokenTable(['[comment:subject]'=>'Başlık','[comment:body]'=>'İçerik','[comment:author:name]'=>'Yazar','[comment:node:title]'=>'İçerik başlığı','[comment:node:url]'=>'İçerik URL','[site:name]'=>'Site adı']);
  }
  protected function buildTokenTable(array $tokens): string {
    $r='';
    foreach ($tokens as $t=>$d) $r.='<tr><td><code style="background:#f5f5f5;padding:2px 6px;border-radius:3px;font-size:12px">'.htmlspecialchars($t).'</code></td><td style="padding-left:10px;color:#555;font-size:13px">'.$d.'</td></tr>';
    return '<table style="border-collapse:collapse;width:100%"><tbody>'.$r.'</tbody></table>';
  }
}
