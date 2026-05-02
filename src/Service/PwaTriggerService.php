<?php
namespace Drupal\pwa_suite\Service;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Utility\Token;
use Drupal\node\NodeInterface;
use Drupal\comment\CommentInterface;
use Drupal\pwa_suite\ValueObject\PwaPushMessage;

class PwaTriggerService {
  public function __construct(
    protected PwaPushNotificationService $pushService,
    protected ConfigFactoryInterface $configFactory,
    protected Token $token,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
  ) {}

  public function onEntityInsert(EntityInterface $entity): void {
    if ($entity instanceof NodeInterface)    $this->handleNodeInsert($entity);
    elseif ($entity instanceof CommentInterface) $this->handleCommentInsert($entity);
  }
  public function onEntityUpdate(EntityInterface $entity): void {
    if ($entity instanceof NodeInterface) $this->handleNodeUpdate($entity);
  }

  protected function handleNodeInsert(NodeInterface $node): void {
    $triggers = $this->configFactory->get('pwa_suite.settings')->get('triggers') ?? [];
    $cfg = $triggers['node_insert'] ?? [];
    if (!empty($cfg['enabled']) && $this->matchesContentType($node,$cfg['content_types']??[])) $this->dispatchNodeNotification($node,$cfg);
    $pub_cfg = $triggers['node_published'] ?? [];
    if (!empty($pub_cfg['enabled']) && $node->isPublished() && $this->matchesContentType($node,$pub_cfg['content_types']??[])) $this->dispatchNodeNotification($node,$pub_cfg);
  }

  protected function handleNodeUpdate(NodeInterface $node): void {
    $triggers = $this->configFactory->get('pwa_suite.settings')->get('triggers') ?? [];
    $cfg = $triggers['node_update'] ?? [];
    if (!empty($cfg['enabled']) && $this->matchesContentType($node,$cfg['content_types']??[])) $this->dispatchNodeNotification($node,$cfg);
    $pub_cfg = $triggers['node_published'] ?? [];
    if (!empty($pub_cfg['enabled'])) {
      $original = $node->original ?? NULL;
      if ($node->isPublished() && $original && !$original->isPublished() && $this->matchesContentType($node,$pub_cfg['content_types']??[])) $this->dispatchNodeNotification($node,$pub_cfg);
    }
  }

  protected function handleCommentInsert(CommentInterface $comment): void {
    $triggers = $this->configFactory->get('pwa_suite.settings')->get('triggers') ?? [];
    $pid = NULL;
    try { if ($comment->hasField('pid')) $pid = $comment->get('pid')->target_id ?? NULL; } catch(\Exception $e){}
    $is_reply = !empty($pid);
    if ($is_reply) {
      $reply_cfg = $triggers['comment_reply'] ?? [];
      if (!empty($reply_cfg['enabled']) && $this->matchesCommentContentType($comment,$reply_cfg['content_types']??[])) $this->dispatchCommentNotification($comment,$reply_cfg,TRUE);
    } else {
      $cfg = $triggers['comment_insert'] ?? [];
      if (!empty($cfg['enabled']) && $this->matchesCommentContentType($comment,$cfg['content_types']??[])) $this->dispatchCommentNotification($comment,$cfg,FALSE);
    }
  }

  protected function dispatchNodeNotification(NodeInterface $node, array $cfg): void {
    $data  = ['node'=>$node];
    $title = $this->renderToken($cfg['title_template']??'[node:title]',$data);
    $body  = $this->renderToken($cfg['body_template']??'',$data);
    $url   = $this->renderToken($cfg['url_template']??'[node:url]',$data);
    if (empty($title)) $title = $node->getTitle()?:'Yeni İçerik';
    if (empty($url))   { try{$url=$node->toUrl('canonical',['absolute'=>TRUE])->toString();}catch(\Exception $e){$url='/';} }
    $message = new PwaPushMessage(title:$title,body:$body,url:$url?:'/');
    $sub_ids = $this->resolveSubscriptionIds($cfg['targets']??['all'],$cfg['roles']??[],NULL,$node,NULL);
    $this->sendDeduped($sub_ids,$message,'node/'.$node->id());
  }

  protected function dispatchCommentNotification(CommentInterface $comment, array $cfg, bool $is_reply): void {
    $node = NULL;
    try { $entity=$comment->getCommentedEntity(); if($entity instanceof NodeInterface) $node=$entity; } catch(\Exception $e){}
    $data = ['comment'=>$comment];
    if ($node) $data['node']=$node;
    $title_tpl = $cfg['title_template']??'';
    if (empty($title_tpl)) $title_tpl = $is_reply?'Yorumunuza yanıt: [comment:subject]':'Yeni Yorum: [comment:subject]';
    $title = $this->renderToken($title_tpl,$data);
    $body  = $this->renderToken($cfg['body_template']??'',$data);
    $url   = $this->renderToken(!empty($cfg['url_template'])?$cfg['url_template']:'[comment:node:url]',$data);
    if (empty($title)) $title = $comment->getSubject()?:'Yeni Bildirim';
    $message = new PwaPushMessage(title:$title,body:$body,url:$url?:'/');
    $parent_comment = NULL;
    if ($is_reply) {
      $pid=NULL; try{if($comment->hasField('pid'))$pid=$comment->get('pid')->target_id??NULL;}catch(\Exception $e){}
      if ($pid) { try{$parent_comment=\Drupal\comment\Entity\Comment::load($pid);}catch(\Exception $e){} }
    }
    $sub_ids = $this->resolveSubscriptionIds($cfg['targets']??['node_author'],$cfg['roles']??[],$comment,$node,$parent_comment);
    $this->sendDeduped($sub_ids,$message,'comment/'.$comment->id());
  }

  protected function resolveSubscriptionIds(array $targets, array $roles, ?CommentInterface $comment, ?NodeInterface $node, $parent): array {
    $all_ids=[];
    foreach ($targets as $target) {
      $ids = match($target) {
        'all'            => $this->getSubIds(),
        'logged_in'      => $this->getSubIds(TRUE, FALSE),
        'anonymous'      => $this->getSubIds(FALSE, TRUE),
        'author'         => $node?$this->getSubIdsByUid((int)$node->getOwnerId()):[],
        'node_author'    => $node?$this->getSubIdsByUid((int)$node->getOwnerId()):[],
        'comment_author' => $parent?$this->getSubIdsByUid((int)$parent->getOwnerId()):[],
        default          => [],
      };
      $all_ids = array_merge($all_ids,$ids);
    }
    if (!empty($roles)) $all_ids=array_merge($all_ids,$this->getSubIdsByRoles($roles));
    return array_values(array_unique($all_ids));
  }

  protected function getSubIds(bool $uid_gt_zero=FALSE, bool $anonymous_only=FALSE): array {
    $q=$this->database->select('pwa_push_subscription','s')->fields('s',['id'])->condition('status',1);
    if ($uid_gt_zero) $q->condition('uid',0,'>');
    elseif ($anonymous_only) $q->condition('uid',0);
    return $q->execute()->fetchCol();
  }
  protected function getSubIdsByUid(int $uid): array {
    if ($uid<=0) return [];
    return $this->database->select('pwa_push_subscription','s')->fields('s',['id'])->condition('uid',$uid)->condition('status',1)->execute()->fetchCol();
  }
  protected function getSubIdsByRoles(array $roles): array {
    if (empty($roles)) return [];
    $uids=$this->database->select('user__roles','ur')->fields('ur',['entity_id'])->condition('roles_target_id',$roles,'IN')->execute()->fetchCol();
    if (empty($uids)) return [];
    return $this->database->select('pwa_push_subscription','s')->fields('s',['id'])->condition('uid',array_unique($uids),'IN')->condition('status',1)->execute()->fetchCol();
  }
  protected function sendDeduped(array $sub_ids, PwaPushMessage $message, string $context=''): void {
    if (empty($sub_ids)) { $this->loggerFactory->get('pwa_suite')->debug('Trigger [@ctx]: abone yok.',['@ctx'=>$context]); return; }
    try {
      $this->pushService->send($sub_ids,$message);
      $this->loggerFactory->get('pwa_suite')->info('Trigger [@ctx]: @count abone kuyruğa eklendi.',['@ctx'=>$context,'@count'=>count($sub_ids)]);
    } catch(\Exception $e) {
      $this->loggerFactory->get('pwa_suite')->error('Trigger hatası [@ctx]: @msg',['@ctx'=>$context,'@msg'=>$e->getMessage()]);
    }
  }
  protected function matchesContentType(NodeInterface $node, array $content_types): bool {
    return empty($content_types)||in_array($node->bundle(),$content_types,TRUE);
  }
  protected function matchesCommentContentType(CommentInterface $comment, array $content_types): bool {
    if (empty($content_types)) return TRUE;
    try { $e=$comment->getCommentedEntity(); if($e instanceof NodeInterface) return in_array($e->bundle(),$content_types,TRUE); } catch(\Exception $e){}
    return FALSE;
  }
  protected function renderToken(string $template, array $data): string {
    if (empty($template)) return '';
    $replaced = $this->token->replace($template,$data,['clear'=>FALSE,'sanitize'=>FALSE]);
    if (is_array($replaced)) { try{$replaced=\Drupal::service('renderer')->renderPlain($replaced);}catch(\Exception $e){$replaced=$template;} }
    $replaced=(string)$replaced;
    if (preg_match('/\[[a-z_]+:[a-z_:]+\]/i',$replaced)) $replaced=$this->manualResolveTokens($replaced,$data);
    $replaced=strip_tags($replaced);
    $replaced=html_entity_decode($replaced,ENT_QUOTES|ENT_HTML5,'UTF-8');
    return trim(preg_replace('/\s+/',' ',$replaced));
  }
  protected function manualResolveTokens(string $text, array $data): string {
    $node=$data['node']??NULL; $comment=$data['comment']??NULL;
    if (!$node instanceof NodeInterface && $comment instanceof CommentInterface) {
      try{$e=$comment->getCommentedEntity();if($e instanceof NodeInterface)$node=$e;}catch(\Exception $e2){}
    }
    $r=[];
    if ($node instanceof NodeInterface) {
      $node_url=''; try{$node_url=$node->toUrl('canonical',['absolute'=>TRUE])->toString();}catch(\Exception $e){}
      $node_body='';$node_summary='';
      try{if($node->hasField('body')){$bf=$node->get('body')->first();if($bf){$node_body=(string)($bf->value??'');$node_summary=(string)($bf->summary??'');if(empty($node_summary)&&!empty($node_body))$node_summary=mb_substr(strip_tags($node_body),0,200);}}}catch(\Exception $e){}
      $r['[node:nid]']=(string)$node->id();$r['[node:title]']=$node->getTitle()??'';$r['[node:url]']=$node_url;
      $r['[node:type]']=$node->bundle();$r['[node:body]']=strip_tags($node_body);$r['[node:body:summary]']=strip_tags($node_summary);
      $r['[node:created]']=\Drupal::service('date.formatter')->format((int)$node->getCreatedTime(),'short');
      try{$owner=$node->getOwner();$r['[node:author:name]']=$owner?$owner->getDisplayName():'';}catch(\Exception $e){$r['[node:author:name]']='';}
    }
    if ($comment instanceof CommentInterface) {
      $cb='';try{if($comment->hasField('comment_body')){$bf=$comment->get('comment_body')->first();$cb=$bf?strip_tags((string)($bf->value??'')):'';};}catch(\Exception $e){}
      $comment_url='';try{$comment_url=$comment->toUrl('canonical',['absolute'=>TRUE])->toString();}catch(\Exception $e){if($node instanceof NodeInterface){try{$comment_url=$node->toUrl('canonical',['absolute'=>TRUE])->toString().'#comment-'.$comment->id();}catch(\Exception $e2){}}}
      $r['[comment:cid]']=(string)$comment->id();$r['[comment:subject]']=(string)($comment->getSubject()??'');
      $r['[comment:body]']=$cb;$r['[comment:url]']=$comment_url;
      $r['[comment:created]']=\Drupal::service('date.formatter')->format((int)$comment->getCreatedTime(),'short');
      try{$author=$comment->getOwner();$r['[comment:author:name]']=$author?$author->getDisplayName():(string)($comment->getAuthorName()??'');}catch(\Exception $e){$r['[comment:author:name]']='';}
      if ($node instanceof NodeInterface) {
        $r['[comment:node:title]']=$r['[node:title]']??'';$r['[comment:node:url]']=$r['[node:url]']??'';
        $r['[comment:node:nid]']=$r['[node:nid]']??'';$r['[comment:node:author:name]']=$r['[node:author:name]']??'';
      }
    }
    $site_config=\Drupal::config('system.site');
    $r['[site:name]']=(string)($site_config->get('name')??'');
    try{$r['[site:url]']=\Drupal::request()->getSchemeAndHttpHost();}catch(\Exception $e){$r['[site:url]']='';}
    $text=str_replace(array_keys($r),array_values($r),$text);
    return preg_replace('/\[[a-z_][a-z_0-9]*(?::[a-z_][a-z_0-9:]*)+\]/i','',$text);
  }
}
