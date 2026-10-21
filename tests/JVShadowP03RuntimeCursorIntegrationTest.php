<?php
declare(strict_types=1);

// Exercise the ACTUAL module's private read/write path, not just the
// parser or the checkpoint policy in isolation. There must be no new
// Symcon variables, events, modules, timers or foreign writes.
class IPSModule {
    private array $attributes=[],$buffers=[],$variables=[],$properties=[];
    public array $registered=[];
    public function Create():void {}
    public function RegisterPropertyBoolean(string $k,bool $v):void{$this->properties[$k]=$v;}
    public function RegisterPropertyInteger(string $k,int $v):void{$this->properties[$k]=$v;}
    public function RegisterPropertyString(string $k,string $v):void{$this->properties[$k]=$v;}
    public function RegisterAttributeString(string $k,string $v):void{$this->attributes[$k]??=$v;}
    public function ReadAttributeString(string $k):string{return $this->attributes[$k]??'';}
    public function WriteAttributeString(string $k,string $v):void{$this->attributes[$k]=$v;}
    public function GetBuffer(string $k):string{return $this->buffers[$k]??'';}
    public function SetBuffer(string $k,string $v):void{$this->buffers[$k]=$v;}
    public function restart():void{$this->buffers=[];}
    public function RegisterVariableString(string $k,string $label,string $profile,int $sort):void{$this->registered[]=$k;$this->variables[$k]='';}
    public function RegisterVariableBoolean(string $k,string $label,string $profile,int $sort):void{$this->registered[]=$k;$this->variables[$k]=false;}
    public function RegisterTimer(string $k,int $ms,string $script):void{}
}
require_once dirname(__DIR__).'/JVPresenceIdentityShadow/module.php';
function cursor107(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('SHADOW_CURSOR_0_8_107 '.$why);
}
$m=new JVPresenceIdentityShadow();$m->Create();
$read=new ReflectionMethod(JVPresenceIdentityShadow::class,'readShadowState');
$write=new ReflectionMethod(JVPresenceIdentityShadow::class,'writeShadowState');
$time=time();$tz=new DateTimeZone('Europe/Berlin');
$proof=static function(int $at)use($tz):string{
    $local=(new DateTimeImmutable('@'.$at))->setTimezone($tz)->format('Y-m-d H:i:s');
    return '['.$local.'] P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_JV_THEN_WORK';
};
$raw="P03 DIAG BASELINE\n";
$start=JVP03IncrementalTimeline::evaluate($raw,[],$time-240);
cursor107(!array_key_exists('schema',$start['next'])
    &&JVShadowStateCheckpoint::validRuntimeCache(
        'P03IncrementalTimeline',$start['next']),
    'actual native P03 cursor is valid despite no schema');
$m->WriteAttributeString('PersistP03IncrementalTimeline',
    json_encode($start['next'],JSON_THROW_ON_ERROR));

// Two unchanged refreshes do not persist an idle-only heartbeat,
// but the live in-memory P03 cursor MUST advance for the next poll.
$one=JVP03IncrementalTimeline::evaluate($raw,
    json_decode($read->invoke($m,'P03IncrementalTimeline'),true),$time-180);
$write->invoke($m,'P03IncrementalTimeline',
    json_encode($one['next'],JSON_THROW_ON_ERROR));
cursor107(json_decode($read->invoke($m,'P03IncrementalTimeline'),true)['lastObservedAt']
        ===$time-180
    &&json_decode($m->ReadAttributeString('PersistP03IncrementalTimeline'),true)['lastObservedAt']
        ===$time-240,
    'only runtime heartbeat advances until periodic durable checkpoint');
$two=JVP03IncrementalTimeline::evaluate($raw,
    json_decode($read->invoke($m,'P03IncrementalTimeline'),true),$time-120);
$write->invoke($m,'P03IncrementalTimeline',
    json_encode($two['next'],JSON_THROW_ON_ERROR));
cursor107(json_decode($read->invoke($m,'P03IncrementalTimeline'),true)['lastObservedAt']
        ===$time-120
    &&$two['report']['newDirectionProofsThisRead']===0,
    'subsequent parser read uses latest cursor not outdated persistent snapshot');

// Genuine new proof is written immediately to the durable attribute.
$raw.=$proof($time-65)."\n";
$event=JVP03IncrementalTimeline::evaluate($raw,
    json_decode($read->invoke($m,'P03IncrementalTimeline'),true),$time-60);
cursor107($event['report']['newDirectionProofsThisRead']===1,
    'new P03 proof arrives exactly once in live epoch');
$write->invoke($m,'P03IncrementalTimeline',
    json_encode($event['next'],JSON_THROW_ON_ERROR));
cursor107(count(json_decode($m->ReadAttributeString(
    'PersistP03IncrementalTimeline'),true)['events'])===1,
    'real new proof is durable without waiting ten minutes');
$repeat=JVP03IncrementalTimeline::evaluate($raw,
    json_decode($read->invoke($m,'P03IncrementalTimeline'),true),$time-45);
cursor107($repeat['report']['newDirectionProofsThisRead']===0,
    'same native proof cannot be replayed at next poll');

// After process restart, RAM is empty and the persisted cursor recovers.
$m->restart();
cursor107($read->invoke($m,'P03IncrementalTimeline')
        ===$m->ReadAttributeString('PersistP03IncrementalTimeline'),
    'restart falls back to genuine last durable P03 cursor');
$recovered=JVP03IncrementalTimeline::evaluate($raw,
    json_decode($read->invoke($m,'P03IncrementalTimeline'),true),$time-20);
cursor107($recovered['report']['newDirectionProofsThisRead']===0,
    'process restart does not reinvent an already persisted proof');

// The source-loss quarantine must also win over an older healthy
// durable state. It is schema-less and can have lastRaw=null.
$quarantine=['initialized'=>false,'lastRaw'=>null,'events'=>[]];
$write->invoke($m,'P03IncrementalTimeline',
    json_encode($quarantine,JSON_THROW_ON_ERROR));
cursor107($read->invoke($m,'P03IncrementalTimeline')
        ===json_encode($quarantine,JSON_THROW_ON_ERROR)
    &&$m->ReadAttributeString('PersistP03IncrementalTimeline')
        ===json_encode($quarantine,JSON_THROW_ON_ERROR),
    'source-loss P03 quarantine is honored and immediately durable');
$invalid=$quarantine;$invalid['events']=[['forged'=>true]];
$m->SetBuffer('P03IncrementalTimeline',json_encode($invalid));
cursor107($read->invoke($m,'P03IncrementalTimeline')
        ===$m->ReadAttributeString('PersistP03IncrementalTimeline'),
    'corrupt P03 in-memory cursor never overrides validated durable data');
$m->SetBuffer('P03IncrementalTimeline','{bad json');
cursor107($read->invoke($m,'P03IncrementalTimeline')
        ===$m->ReadAttributeString('PersistP03IncrementalTimeline'),
    'broken JSON also falls back to last durable P03 cursor');

// Explicit restore/repair of an attribute must override prior RAM;
// silently returning the old buffer would cancel a restoration.
$restored=JVP03IncrementalTimeline::evaluate(
    "P03 RESTORED SOURCE BASELINE\n",[],$time-5);
$restoredJson=json_encode($restored['next'],JSON_THROW_ON_ERROR);
$m->SetBuffer('P03IncrementalTimeline',
    json_encode($recovered['next'],JSON_THROW_ON_ERROR));
$m->WriteAttributeString('PersistP03IncrementalTimeline',$restoredJson);
cursor107($read->invoke($m,'P03IncrementalTimeline')===$restoredJson,
    'manually restored persistent cursor overrides earlier RAM snapshot');
// Resetting an existing checkpoint to an intentionally blank state is
// authoritative too; neither read nor ApplyChanges migration may resurrect
// the stale P03 proof from the buffer.
$m->WriteAttributeString('PersistP03IncrementalTimeline','{}');
cursor107($read->invoke($m,'P03IncrementalTimeline')==='{}',
    'deliberate durable reset cannot be undone by stale RAM cursor');
$migrate=new ReflectionMethod(
    JVPresenceIdentityShadow::class,'migrateLegacyShadowBuffers');
$migrate->invoke($m);
cursor107($m->ReadAttributeString('PersistP03IncrementalTimeline')==='{}',
    'legacy buffer migration skips existing new-version RAM checkpoint');

// Neither a recovered nor a forged dynamic P03 state is a person identity.
cursor107(!$event['report']['identityCertified']
    &&!$recovered['report']['identityCertified'],
    'read-only replay never certifies the identity of a human');
cursor107(count($m->registered)===7,
    'no extra presence status variable created in real Symcon mock');
echo "SHADOW native P03 in-memory continuity, proof replay, quarantine and restart PASS\n";
