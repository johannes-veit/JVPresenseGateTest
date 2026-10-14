<?php
declare(strict_types=1);
// Self-contained mock: only local existing Symcon variables may be read.
class IPSModule {
    private array $buffers=[];
    private array $props=[];
    private array $values=[];
    public array $registered=[];
    public array $subscriptions=[];
    public function Create():void {}
    public function RegisterPropertyBoolean(string $id,bool $val):void {if(!array_key_exists($id,$this->props))$this->props[$id]=$val;}
    public function RegisterPropertyInteger(string $id,int $val):void {if(!array_key_exists($id,$this->props))$this->props[$id]=$val;}
    public function RegisterPropertyString(string $id,string $val):void {if(!array_key_exists($id,$this->props))$this->props[$id]=$val;}
    private array $attributes=[];
    public function RegisterAttributeString(string $id,string $value):void {
        if(!array_key_exists($id,$this->attributes))$this->attributes[$id]=$value;
    }
    public function ReadAttributeString(string $id):string {
        return $this->attributes[$id]??'';
    }
    public function WriteAttributeString(string $id,string $value):void {
        $this->attributes[$id]=$value;
    }
    public function clearRuntimeBuffers():void {$this->buffers=[];}
    public function exportPersistedAttributes():array{return $this->attributes;}
    public function importPersistedAttributes(array $data):void{$this->attributes=$data;}
    public function RegisterTimer(string $id,int $v,string $script):void {}
    public function SetTimerInterval(string $id,int $v):void {}
    public function RegisterMessage(int $id,int $message):void {
        $this->subscriptions[$id.'|'.$message]=true;
    }
    public function UnregisterMessage(int $id,int $message):void {
        unset($this->subscriptions[$id.'|'.$message]);
    }
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
checkGate(($data['gateSignalAudit']['messageSubscriptions']??null)===7,
    'all seven gate candidates observed via existing VM_UPDATE');
checkGate(count($m->subscriptions)===7,
    'seven passive subscriptions, no new Symcon event objects');
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


// Gate input update is captured immediately even between the timer ticks.
$lastReadCount=count($GLOBALS['readCalls']);
$m->MessageSink(1,99999,10603,[]);
checkGate(count($GLOBALS['readCalls'])===$lastReadCount,
    'unknown sender never triggers gate status reads');
$m->MessageSink(2,56808,10604,[]);
checkGate(count($GLOBALS['readCalls'])===$lastReadCount,
    'unrelated message type ignored');
$GLOBALS['gateDevices'][56808]['changed']=time()+3;
$GLOBALS['gateDevices'][56808]['value']=true;
$m->MessageSink(3,56808,10603,[]);
$event=json_decode((string)$m->GetValue('EvidenceJSON'),true);
checkGate(($event['gateSignalAudit']['newStatusEdgesThisRead']??null)===1,
    'VM_UPDATE captures event without waiting for next 60s poll');
checkGate(($event['gateSignalAudit']['recentStatusEdges'][0]['variableId']??null)===56808,
    'correct original input ID recorded, no made-up gate direction');
$m->MessageSink(4,56808,10603,[]);
$repeat=json_decode((string)$m->GetValue('EvidenceJSON'),true);
checkGate(($repeat['gateSignalAudit']['newStatusEdgesThisRead']??null)===0,
    'duplicate update message does not create a duplicate status edge');
// A new PHP module object after a Symcon/service restart must recover the
// previous scan, opt-in gate watch, GUS baseline, P03 timeline and gate edges.
// Runtime buffers are intentionally NOT copied to the replacement object.
$beforeRestart=json_decode((string)$m->GetValue('EvidenceJSON'),true);
$attrs=$m->exportPersistedAttributes();
checkGate(($attrs['PersistExitCandidateInventory']??'{}')!=='{}',
    'inventory stored durably in Symcon attribute');
checkGate(($attrs['PersistGateSignalLedger']??'{}')!=='{}',
    'gate status edges stored durably in Symcon attribute');
checkGate(($attrs['PersistLocalActivityLedger']??'{}')!=='{}',
    'GUS 24h activity baseline stored durably in Symcon attribute');
checkGate(array_key_exists('PersistP03IncrementalTimeline',$attrs),
    'P03 incremental ledger has a persistent attribute even without P03 fixture');
$after=new JVPresenceIdentityShadow();
$after->importPersistedAttributes($attrs);
$after->Create();
$after->setProp('Enabled',true);
$after->setProp('SensorSources','[]');
$after->setProp('P03InstanceID',0);
$after->ApplyChanges();
checkGate(count($after->subscriptions)===7,
    'ApplyChanges re-registers VM_UPDATE for all seven saved inputs');
$after->Refresh();
$afterRestart=json_decode((string)$after->GetValue('EvidenceJSON'),true);
checkGate(($afterRestart['exitCandidateInventory']['candidateCount']??null)===7,
    'new module object finds inventory without rescan');
checkGate(($afterRestart['gateSignalAudit']['signalCount']??null)===7,
    'opt-in passive gate audit active after complete restart');
checkGate(($afterRestart['gateSignalAudit']['newStatusEdgesThisRead']??null)===0,
    'restarting does not invent a fresh gate edge');
checkGate(count($afterRestart['gateSignalAudit']['recentStatusEdges']??[])>=1,
    'historical status chronology survives full restart');
checkGate(($afterRestart['localActivity']['startedAt']??null)
    ===($beforeRestart['localActivity']['startedAt']??null),
    'GUS observer startedAt survives complete restart');
checkGate($after->registered===['Status','EvidenceJSON'],
    'no additional Shadow variables after instance restart');
// Also test reentrant Create on an existing instance: registered attributes
// must never be rewritten with blank defaults.
$attrsBeforeSecondCreate=$after->exportPersistedAttributes();
$after->clearRuntimeBuffers();
$after->Create();
checkGate($after->exportPersistedAttributes()===$attrsBeforeSecondCreate,
    'reentrant Create does not reset any persistent attribute');
$after->setProp('Enabled',false);
$after->ApplyChanges();
checkGate(count($after->subscriptions)===0,
    'disabling Shadow unregisters seven passive gate subscriptions');
checkGate($after->registered===['Status','EvidenceJSON',
    'Status','EvidenceJSON'],
    'still exactly two output identifiers after simulated module reload');

echo "Gate Shadow integration / no-actions / path-revalidation PASS\n";
