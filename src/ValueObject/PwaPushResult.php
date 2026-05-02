<?php
namespace Drupal\pwa_suite\ValueObject;
class PwaPushResult {
  private int $sent=0, $failed=0, $expired=0, $queued=0;
  private array $errors=[], $expiredSubscriptionIds=[];
  public function __construct(int $queued=0) { $this->queued=$queued; }
  public function addSent(int $n=1): void { $this->sent+=$n; }
  public function addFailed(string $reason=''): void { $this->failed++; if($reason) $this->errors[]=$reason; }
  public function addExpired(int $subId, string $endpoint=''): void { $this->expired++; $this->expiredSubscriptionIds[]=$subId; }
  public function getSent():   int   { return $this->sent; }
  public function getFailed(): int   { return $this->failed; }
  public function getExpired():int   { return $this->expired; }
  public function getQueued(): int   { return $this->queued; }
  public function getErrors(): array { return $this->errors; }
  public function getExpiredSubscriptionIds(): array { return $this->expiredSubscriptionIds; }
  public function getTotal():  int   { return $this->sent+$this->failed+$this->expired; }
  public function isSuccess(): bool  { return $this->failed===0 && $this->expired===0; }
  public function toArray():   array { return ['sent'=>$this->sent,'failed'=>$this->failed,'expired'=>$this->expired,'queued'=>$this->queued,'total'=>$this->getTotal(),'errors'=>$this->errors]; }
  public function merge(PwaPushResult $other): void {
    $this->sent+=$other->sent; $this->failed+=$other->failed; $this->expired+=$other->expired; $this->queued+=$other->queued;
    $this->errors=array_merge($this->errors,$other->errors);
    $this->expiredSubscriptionIds=array_merge($this->expiredSubscriptionIds,$other->expiredSubscriptionIds);
  }
}
