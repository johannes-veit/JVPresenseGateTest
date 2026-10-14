<?php
declare(strict_types=1);
// Self-contained mock: only local existing Symcon variables may be read.
class IPSModule {
    private array $buffers=[];
    private array $props=[];
    private array $values=[];
    public array $registered=[];
    public function Create():void {}
    public function RegisterPropertyBoolean(string $id,bool $val):void {$this->props[$id]=$val;}
    public function RegisterPropertyInteger(string $id,int $val):void {$this->props[$id]=$val;}
    public function RegisterPropertyString(string $id,string $val):void {$this->props[$id]=$val;}
    public function RegisterAttributeString(string $id,string $val):void {}
    public function RegisterTimer(string $id,int $v,string $script):void {}
    public function RegisterVariableString(string $id,string $name,string $profile,int $position):void {
        $this->registered[]=$id;$this->values[$id]='';
    }
    public function ReadPropertyBoolean(string $id):bool{return (bool)$this->props[$id];}
    public function ReadPropertyInteger(string $id):int{return (int)$this->props[$id];}
    public function ReadPropertyString(string $id):string{return (string)$this->props[$id];}
    public function setProp(string $id,mixed $value):void{$this->props[$id]=$value;}
    public function SetBuffer(string $id,string $value):void{$this->buffers[$id]=$value;}
    public function GetBuffer(string $id):string{return $this->buffers[$id]??'';}
    public function GetValue(string $id):mixed{return $this->values[$id]??null;}
    public function SetValue(string $id,mixed $v):void{$this->values[$id]=$v;}
}
$now=time();
$GLOBALS['gateDevices']=[
    40678=>['name'=>'Eingangstatus 2','parent'=>'input:2','value'=>false,'changed'=>$now-100],
    56808=>['name'=>'Eingangstatus 1','parent'=>'Endlage ZU','value'=>false,'changed'=>$now-100],
    13257=>['name'=>'Status 1','parent'=>'shellypro3-8c4f008eeb14','value'=>false,'changed'=>$now-100],
    20724=>['name'=>'Status 2','parent'=>'shellypro3-8c4f008eeb14','value'=>false,'changed'=>$now-100],
    31597=>['name'=>'Status','parent'=>'shellypro3-8c4f008eeb14','value'=>false,'changed'=>$now-100],
    49204=>['name'=>'Eingangstatus 2','parent'=>'shellypro3-8c4f008eeb14','value'=>false,'changed'=>$now-100],
    50300=>['name'=>'Eingangstatus 1','parent'=>'shellypro3-8c4f008eeb14','value'=>false,'changed'=>$now-100],
    42452=>['name'=>'Status','parent'=>'Bewegungsmelder EG Wohnen Kamin','value'=>false,'changed'=>$now-100]
];
$GLOBALS['readCalls']=[];
function IPS_GetVariableList():array{return array_keys($GLOBALS['gateDevices']);}
function IPS_VariableExists(int $id):bool{return isset($GLOBALS['gateDevices'][$id]);}
function IPS_GetVariable(int $id):array{
    if(!IPS_VariableExists($id))throw new RuntimeException('unknown variable');
    return ['VariableType'=>0,'VariableChanged'=>$GLOBALS['gateDevices'][$id]['changed']];
}
function IPS_GetObject(int $id):array{
    if(isset($GLOBALS['gateDevices'][$id]))return ['ParentID'=>$id+100000];
    if($id>=100000&&isset($GLOBALS['gateDevices'][$id-100000]))
        return ['ParentID'=>200000];
    if($id===200000)return ['ParentID'=>0];
    throw new RuntimeException('object unknown');
}
function IPS_GetName(int $id):string{
    if(isset($GLOBALS['gateDevices'][$id]))return $GLOBALS['gateDevices'][$id]['name'];
    if($id>=100000&&isset($GLOBALS['gateDevices'][$id-100000]))
        return $GLOBALS['gateDevices'][$id-100000]['parent'];
    if($id===200000)return 'Schiebetor_Logik';
    throw new RuntimeException('name unknown');
}
function GetValue(int $id):mixed{
    if(!IPS_VariableExists($id))throw new RuntimeException('read outside known devices');
    $GLOBALS['readCalls'][]=$id;
    return $GLOBALS['gateDevices'][$id]['value'];
}
// This is invoked only inside P03's optional lookup try/catch.
function IPS_GetInstance(int $id):array{throw new RuntimeException('no P03 fixture');}

require_once dirname(__DIR__).'/JVPresenceIdentityShadow/module.php';
function checkGate(bool $true,string $name):void{
    if(!$true)throw new RuntimeException($name);
}
$m=new JVPresenceIdentityShadow();
$m->Create();
$m->setProp('Enabled',true);
$m->setProp('SensorSources','[]');
$m->setProp('P03InstanceID',0);
$m->AuditExistingExitCandidates();
$raw=json_decode((string)$m->GetValue('EvidenceJSON'),true);
checkGate(($raw['candidateCount']??null)===7,'find seven from fixture');
checkGate($GLOBALS['readCalls']===[],'candidate scan reads metadata not values');
$m->AuditExistingGateSignals();
$data=json_decode((string)$m->GetValue('EvidenceJSON'),true);
checkGate(($data['gateSignalAudit']['signalCount']??null)===7,'manual audit samples 7');
checkGate(($data['gateSignalAudit']['readableSignals']??null)===7,'all 7 read');
checkGate(($data['gateSignalAudit']['newStatusEdgesThisRead']??null)===0,
    'no fake event on activation');
checkGate(count($GLOBALS['readCalls'])===7,'no other device values read');
checkGate($m->registered===['Status','EvidenceJSON'],
    'unchanged two Shadow variables');
checkGate(($data['boundaryEvidence']['configuredBoundarySignals']??null)===0,
    'candidate audit does not bind physical sensors');
checkGate(($data['personPresenceMayBeChanged']??null)===false,
    'no presence writes');
checkGate(($data['propertyEmptyMayBeCertified']??null)===false,
    'no absence certification');
$GLOBALS['gateDevices'][40678]['changed']=time();
$GLOBALS['gateDevices'][40678]['value']=true;
$m->Refresh();
$changed=json_decode((string)$m->GetValue('EvidenceJSON'),true);
checkGate(($changed['gateSignalAudit']['newStatusEdgesThisRead']??null)===1,
    'one new physical variable edge observed');
checkGate(($changed['gateSignalAudit']['recentStatusEdges'][0]['variableId']??null)===40678,
    'no guessed endstop mapping');
$m->Refresh();
$again=json_decode((string)$m->GetValue('EvidenceJSON'),true);
checkGate(($again['gateSignalAudit']['newStatusEdgesThisRead']??null)===0,
    'poll same value not double counted');
$GLOBALS['gateDevices'][40678]['parent']='reparented-other-component';
$readsBefore=count($GLOBALS['readCalls']);
$m->Refresh();
$invalid=json_decode((string)$m->GetValue('EvidenceJSON'),true);
checkGate(($invalid['gateSignalAudit']['readableSignals']??null)===6,
    'invalidated Symcon parent leads UNKNOWN');
checkGate(($invalid['gateSignalAudit']['signals'][0]['readState']??null)==='UNKNOWN',
    'invalid object never trusted as real gate');
checkGate(count($GLOBALS['readCalls'])===$readsBefore+6,
    'reparented ID not read');

// Field-like scenario: both input:2 and the Shelly input:2 alias have
// valid Boolean values, but VariableChanged=0 (never seen change).
$GLOBALS['gateDevices'][40678]['parent']='input:2';
$GLOBALS['gateDevices'][40678]['changed']=0;
$GLOBALS['gateDevices'][49204]['changed']=0;
$m->Refresh();
$notChanged=json_decode((string)$m->GetValue('EvidenceJSON'),true);
checkGate(($notChanged['gateSignalAudit']['readableSignals']??null)===7,
    'both previously unknown inputs now read as raw Boolean values');
checkGate(($notChanged['gateSignalAudit']['timedSignals']??null)===5,
    'five valid source last-change timestamps');
checkGate(($notChanged['gateSignalAudit']['untimedSignals']??null)===2,
    'two sources have no last-change timestamp');
checkGate(($notChanged['gateSignalAudit']['signals'][0]['readState']??null)
    ==='VALUE_ONLY_UNTIMED',
    'raw state shown without pretending to have a change date');
checkGate(($notChanged['gateSignalAudit']['signals'][5]['diagnosticCode']??null)
    ==='NO_CHANGE_TIMESTAMP',
    'second input has explicit no-change-timestamp diagnosis');
checkGate(($notChanged['gateSignalAudit']['newStatusEdgesThisRead']??null)===0,
    'untimed sources do not generate false gate motions');
// On the FIRST timed transition following unknown time, use baseline only.
$GLOBALS['gateDevices'][40678]['changed']=time()+1;
$GLOBALS['gateDevices'][40678]['value']=false;
$m->Refresh();
$firstTimed=json_decode((string)$m->GetValue('EvidenceJSON'),true);
checkGate(($firstTimed['gateSignalAudit']['newStatusEdgesThisRead']??null)===0,
    'first trusted timestamp after no-change state is baseline only');
// Later change of same ID can become an edge (still not physical proof).
$GLOBALS['gateDevices'][40678]['changed']=time()+2;
$GLOBALS['gateDevices'][40678]['value']=true;
$m->Refresh();
$secondTimed=json_decode((string)$m->GetValue('EvidenceJSON'),true);
checkGate(($secondTimed['gateSignalAudit']['newStatusEdgesThisRead']??null)===1,
    'second timed transition is seen as raw state edge');
checkGate(($secondTimed['gateSignalAudit']['gateOpenOrClosedCertified']??null)===false,
    'still no certified physical gate position');

echo "Gate Shadow integration / no-actions / path-revalidation PASS\n";
