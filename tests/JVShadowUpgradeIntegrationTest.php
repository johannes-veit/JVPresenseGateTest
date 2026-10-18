<?php
declare(strict_types=1);
// Pure PHP simulation of the existing seven-variable IP-Symcon instance.
class IPSModule {
    private array $attrs=[],$props=[],$vars=[],$buffers=[];
    public array $registered=[];
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
echo "SHADOW pre-upgrade instance preservation and recovery PASS\n";
