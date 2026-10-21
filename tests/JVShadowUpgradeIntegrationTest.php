<?php
declare(strict_types=1);
// Pure PHP simulation of the existing seven-variable IP-Symcon instance.
class IPSModule {
    private array $attrs=[],$props=[],$vars=[],$buffers=[];
    public array $registered=[];
    public int $instanceStatus=0;
    public function ApplyChanges():void {}
    public function SetTimerInterval(string $name,int $ms):void {}
    public function SetStatus(int $status):bool{$this->instanceStatus=$status;return true;}
    public function SendDebug(string $name,string $message,int $format):void {}
    public function Create():void {}
    public function RegisterPropertyBoolean(string $n,bool $v):void{$this->props[$n]=$v;}
    public function RegisterPropertyInteger(string $n,int $v):void{$this->props[$n]=$v;}
    public function RegisterPropertyString(string $n,string $v):void{$this->props[$n]=$v;}
    public function ReadPropertyBoolean(string $n):bool{return (bool)($this->props[$n]??false);}
    public function ReadPropertyInteger(string $n):int{return (int)($this->props[$n]??0);}
    public function ReadPropertyString(string $n):string{return (string)($this->props[$n]??'');}
    public function setProperty(string $n,mixed $v):void{$this->props[$n]=$v;}
    public function RegisterAttributeString(string $n,string $v):void{$this->attrs[$n]??=$v;}
    public function ReadAttributeString(string $n):string{return $this->attrs[$n]??'';}
    public function WriteAttributeString(string $n,string $v):void{$this->attrs[$n]=$v;}
    public function RegisterTimer(string $n,int $ms,string $php):void{}
    public function RegisterVariableString(string $n,string $label,string $profile,int $pos):void{
        $this->registered[]=$n;$this->vars[$n]='';
    }
    public function RegisterVariableBoolean(string $n,string $label,string $profile,int $pos):void{
        $this->registered[]=$n;$this->vars[$n]=false;
    }
    public function GetValue(string $n):mixed{return $this->vars[$n]??null;}
    public function SetValue(string $n,mixed $v):void{$this->vars[$n]=$v;}
    public function GetBuffer(string $n):string{return $this->buffers[$n]??'';}
    public function SetBuffer(string $n,string $v):void{$this->buffers[$n]=$v;}
    public function dropBuffers():void{$this->buffers=[];}
}
require_once dirname(__DIR__).'/JVPresenceIdentityShadow/module.php';
function upgradeIntegration(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('UPGRADE_INTEGRATION '.$why);
}
$m=new JVPresenceIdentityShadow();$m->Create();
$m->setProperty('Enabled',true);
$m->setProperty('P03InstanceID',0);
$m->setProperty('SensorSources','[]');
$damaged='{invalid private historic proof';
$m->WriteAttributeString('PersistResidentEventFusion',$damaged);
$m->ApplyChanges();
upgradeIntegration($m->instanceStatus===201,
    'critical persisted journal never appears as active status after ApplyChanges');
$m->Refresh();
$blocked=json_decode((string)$m->GetValue('EvidenceJSON'),true);
upgradeIntegration(($blocked['authority']??null)==='SHADOW_UPGRADE_READ_ONLY_BLOCK'
    &&($blocked['upgradePreflight']['status']??null)
        ==='BLOCKED_PRESERVE_CRITICAL_STATE'
    &&$m->ReadAttributeString('PersistResidentEventFusion')===$damaged
    &&$m->GetValue('PresenceDetected')===false,
    'malformed durable resident ledger must survive upgrade unchanged');
upgradeIntegration(str_contains((string)$m->GetValue('TestReport'),
    'SHADOW UPGRADE-SCHUTZ')
    &&!str_contains((string)$m->GetValue('TestReport'),'private historic proof'),
    'blocked update produces actionable redacted diagnostic');
$m->dropBuffers();
$m->Refresh();
upgradeIntegration($m->ReadAttributeString('PersistResidentEventFusion')===$damaged,
    'second restart cannot destroy persisted bad evidence');
$m->WriteAttributeString('PersistResidentEventFusion','{}');
$m->ApplyChanges();
upgradeIntegration($m->instanceStatus===102,
    'after deliberate repair, ApplyChanges re-enters native active status');
// After a deliberate repair of an invalid saved critical value, ordinary
// SHADOW can bootstrap from baseline without a new user variable.
$m->Refresh();
$resumed=json_decode((string)$m->GetValue('EvidenceJSON'),true);
upgradeIntegration(($resumed['authority']??null)!=='SHADOW_UPGRADE_READ_ONLY_BLOCK'
    &&($resumed['shadowUpgradePreflight']['safeToRefresh']??false)===true,
    'repaired saved journal allows non-mutating safe baseline/recovery');
upgradeIntegration($m->registered===[
    'Status','EvidenceJSON','JohannesPresence','CarinaPresence',
    'PresenceDetected','ResidentTile','TestReport'
], 'upgrade must not create duplicate output variables');
// Integration: simulate a real P03 variable-read failure while the old
// SHADOW instance still retains a completed camera corridor. A later
// restored source must be baselined, NEVER join these old live proofs.
$oldProof=[
    'at'=>time()-30,
    'localTime'=>(new DateTimeImmutable('@'.(time()-30)))
        ->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i:s'),
    'direction'=>'HOME_TO_LAGER',
    'route'=>'P03_2CAM_JV_THEN_WORK',
    'source'=>'P03_TWO_MAST_VERIFIED'
];
$m->WriteAttributeString('PersistP03IncrementalTimeline',json_encode([
    'initialized'=>true,'lastRaw'=>"P03 VERIFIED PREVIOUS EPOCH",
    'events'=>[$oldProof]
],JSON_THROW_ON_ERROR));
$m->Refresh(); // P03 instance 0: local P03 Protocol is unreadable
$p03AfterRead=json_decode(
    $m->ReadAttributeString('PersistP03IncrementalTimeline'),true);
upgradeIntegration(is_array($p03AfterRead)
    &&($p03AfterRead['initialized']??null)===false
    &&($p03AfterRead['events']??null)===[]
    &&array_key_exists('lastRaw',$p03AfterRead)
    &&$p03AfterRead['lastRaw']===null,
    'actual Symcon Refresh quarantines durable stale P03 cursor after unreadable source');
$m->dropBuffers();
$m->Refresh();
$p03AfterRestart=json_decode(
    $m->ReadAttributeString('PersistP03IncrementalTimeline'),true);
upgradeIntegration(($p03AfterRestart['events']??null)===[]
    &&($p03AfterRestart['initialized']??null)===false,
    'after actual lost source and SymBox process restart no old camera proof resurrects');


// Final-runtime guard: unlike ordinary bad P03/WLAN inputs, an
// unexpected PHP exception halfway through an active Refresh formerly
// left PREVIOUS positive named statuses and the combined Boolean on
// the user's SymBox. No person/absence claim may survive that failure.
class JVInjectedRefreshFault extends JVPresenceIdentityShadow {
    public bool $simulateBadRead=false;
    public function ReadPropertyString(string $name):string {
        if($this->simulateBadRead&&$name==='SensorSources')
            throw new RuntimeException('PRIVATE_RUNTIME_DIAGNOSTIC_PAYLOAD');
        return parent::ReadPropertyString($name);
    }
}
$faulty=new JVInjectedRefreshFault();
$faulty->Create();
$faulty->setProperty('Enabled',true);
$faulty->setProperty('P03InstanceID',0);
$faulty->setProperty('SensorSources','[]');
$faulty->ApplyChanges();
upgradeIntegration($faulty->instanceStatus===102
    &&$faulty->GetBuffer('ShadowObservationStarted')==='1',
    'healthy complete first read alone establishes process observation epoch');
$faulty->SetValue('JohannesPresence','ANWESEND');
$faulty->SetValue('CarinaPresence','ANWESEND');
$faulty->SetValue('PresenceDetected',true);
$faulty->SetValue('Status','ANWESEND VERIFIED STALE');
$faulty->SetValue('ResidentTile','<section>STALER-STATUS</section>');
$faulty->simulateBadRead=true;
$raised=false;
try{$faulty->Refresh();}
catch(RuntimeException $error){$raised=true;}
$blocked=json_decode((string)$faulty->GetValue('EvidenceJSON'),true);
upgradeIntegration($raised
    &&$faulty->GetBuffer('ShadowObservationStarted')==='0'
    &&$faulty->instanceStatus===201
    &&$faulty->GetValue('PresenceDetected')===false
    &&$faulty->GetValue('JohannesPresence')==='NICHT BESTÄTIGT'
    &&$faulty->GetValue('CarinaPresence')==='NICHT BESTÄTIGT'
    &&($blocked['authority']??null)==='SHADOW_RUNTIME_READ_ONLY_BLOCK'
    &&($blocked['mayArmAlarm']??true)===false
    &&str_contains((string)$faulty->GetValue('Status'),'SHADOW FEHLER'),
    'unexpected thrown Refresh invalidates every stale positive presence');
upgradeIntegration(
    !str_contains((string)$faulty->GetValue('TestReport'),
        'PRIVATE_RUNTIME_DIAGNOSTIC_PAYLOAD')
    &&!str_contains((string)$faulty->GetValue('EvidenceJSON'),
        'PRIVATE_RUNTIME_DIAGNOSTIC_PAYLOAD'),
    'runtime exception details/credentials are never exported to user variables');
$faulty->simulateBadRead=false;
$faulty->Refresh();
$recovered=json_decode((string)$faulty->GetValue('EvidenceJSON'),true);
upgradeIntegration($faulty->instanceStatus===102
    &&$faulty->GetBuffer('ShadowObservationStarted')==='1'
    &&($recovered['authority']??null)!=='SHADOW_RUNTIME_READ_ONLY_BLOCK'
    &&$faulty->GetValue('PresenceDetected')===false,
    'next successful complete Refresh automatically restores active native status');

echo "SHADOW pre-upgrade, runtime fail-closed and recovery PASS\n";
