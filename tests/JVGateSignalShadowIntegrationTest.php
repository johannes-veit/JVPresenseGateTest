<?php
declare(strict_types=1);
// Self-contained mock: only local existing Symcon variables may be read.
class IPSModule {
    private array $buffers=[];
    private array $props=[];
    private array $values=[];
    public array $registered=[];
    public array $subscriptions=[];
    // Mimic a real Symcon instance stuck in IS_CREATING until ApplyChanges.
    public int $instanceStatus=101;
    public array $statusTransitions=[];
    public array $bootstrapDebug=[];
    public bool $failTimerConfiguration=false;
    public bool $failActiveStatus=false;
    public function Create():void {}
    public function ApplyChanges():void {}
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
    public function SetTimerInterval(string $id,int $v):void {
        if($this->failTimerConfiguration && $v>0)
            throw new RuntimeException('MOCK_TIMER_FAILURE');
    }
    public function SetStatus(int $next):bool {
        $this->statusTransitions[]=$next;
        if($next===102 && $this->failActiveStatus)return false;
        $this->instanceStatus=$next;return true;
    }
    public function SendDebug(string $message,string $data,int $format):void {
        $this->bootstrapDebug[]=['message'=>$message,'data'=>$data];
    }
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
    return ['VariableType'=>$GLOBALS['gateDevices'][$id]['type']??0,
        'VariableChanged'=>$GLOBALS['gateDevices'][$id]['changed'],
        'VariableUpdated'=>$GLOBALS['gateDevices'][$id]['updated']??($GLOBALS['gateDevices'][$id]['changed']??0)];
}
function IPS_GetObject(int $id):array{
    if($id===50000)return ['ParentID'=>35965];
    if($id===62000)return ['ParentID'=>61000];
    if($id===45439)return ['ParentID'=>48903];
    if(isset($GLOBALS['gateDevices'][$id]))return ['ParentID'=>
        $GLOBALS['gateDevices'][$id]['pairedParent']??($id+100000)];
    if($id>=100000&&isset($GLOBALS['gateDevices'][$id-100000]))
        return ['ParentID'=>$GLOBALS['gateDevices'][$id-100000]['grandParentID']??200000];
    if($id===200000||$id===200001)return ['ParentID'=>0];
    if($id>=8001&&$id<=8005)return ['ParentID'=>300000];
    if($id===300000)return ['ParentID'=>0];
    throw new RuntimeException('object unknown');
}
function IPS_GetName(int $id):string{
    if(isset($GLOBALS['gateDevices'][$id]))return $GLOBALS['gateDevices'][$id]['name'];
    if($id>=100000&&isset($GLOBALS['gateDevices'][$id-100000]))
        return $GLOBALS['gateDevices'][$id-100000]['parent'];
    if($id===200000)return 'Schiebetor_Logik';
    if($id===200001)return 'Licht';
    if($id===300000)return 'Vorhandene Dahua-Kameras';
    if($id===62000)return 'Device';
    if($id===8001)return 'Dahua Kamera Torhalle Strasse';
    if($id===8002)return 'Dahua Kamera JV rechts';
    if($id===8003)return 'Dahua Kamera WORK_LEFT';
    if($id===8004)return 'TiOC Kamera Hof';
    if($id===8005)return 'LCN Eingang 103';
    throw new RuntimeException('name unknown');
}
function GetValue(int $id):mixed{
    if(!IPS_VariableExists($id))throw new RuntimeException('read outside known devices');
    $GLOBALS['readCalls'][]=$id;
    return $GLOBALS['gateDevices'][$id]['value'];
}
// This is invoked only inside P03's optional lookup try/catch.
function IPS_GetInstance(int $id):array{
    if($id===61000)return ['ModuleInfo'=>['ModuleID'=>'{0100CA50-D2DA-11E4-8830-0800200C9A66}']];
    if($id===48903)return ['ModuleInfo'=>['ModuleID'=>'{0100CA50-D2DA-11E4-8830-0800200C9A66}']];
    throw new RuntimeException('no P03 fixture');
}
function IPS_GetInstanceList():array{return [8001,8002,8003,8004,8005];}
function IPS_GetConfiguration(int $id):string{
    return match($id){
        8001=>json_encode(['Host'=>'192.168.107.98',
            'Password'=>'PRIVATE_CAMERA_PASSWORD_NEVER_OUTPUT']),
        8002=>json_encode(['CameraHost'=>'192.168.107.96',
            'Password'=>'HIDDEN_ANOTHER_SECRET']),
        8003=>json_encode(['Host'=>'192.168.107.95']),
        8004=>json_encode(['Host'=>'192.168.107.111']),
        8005=>json_encode(['Host'=>'192.168.103.45']),
        default=>'{}'
    };
}

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
// Existing button 6: first scan camera-looking names, then ONLY known
// camera-host configuration fields. Never send config or passwords to JSON.
$readsBeforeCameraAudit=count($GLOBALS['readCalls']);
$m->AuditOtherIPCCandidates();
$camScan=json_decode((string)$m->GetValue('EvidenceJSON'),true);
checkGate(($camScan['authority']??null)==='ADDITIONAL_IPC_LOCAL_METADATA_ONLY',
    'existing IPC metadata scan action remains unchanged');
checkGate(($camScan['qualifiedOtherIpcFoundInSymcon']??null)===2,
    'two extra camera hosts matched local configuration metadata');
checkGate(($camScan['qualifiedOtherIpcCandidates'][0]['ip']??null)
    ==='192.168.107.98','street-side additional camera ranked first');
checkGate(!str_contains(json_encode($camScan),'PRIVATE_CAMERA_PASSWORD_NEVER_OUTPUT')
    &&!str_contains(json_encode($camScan),'HIDDEN_ANOTHER_SECRET'),
    'never print Symcon camera passwords');
checkGate(count($GLOBALS['readCalls'])===$readsBeforeCameraAudit,
    'camera scan never reads camera device variables');
// Test one genuine read-only indoor Boolean status while gate evidence
// and other device sources remain untouched.
$GLOBALS['gateDevices'][39745]=[
    'name'=>'Status','parent'=>'OG Bad Fenster',
    'grandParentID'=>200001,'type'=>0,
    'value'=>true,'changed'=>$now-9
];
// Indoor candidate discovery reads metadata only, never device states,
// and cannot change the established gate watch or GUS configuration.
$readsBeforeIndoorScan=count($GLOBALS['readCalls']);
$m->AuditIndoorHintCandidates();
$metadataOnly=json_decode((string)$m->GetValue('EvidenceJSON'),true);
checkGate(($metadataOnly['authority']??null)==='INDOOR_LIGHT_TV_CANDIDATES_METADATA_ONLY',
    'explicit indoor/TV audit returns metadata inventory');
checkGate(($metadataOnly['configuredSourcesChanged']??null)===false,
    'indoor scan never auto-links unknown output IDs');
checkGate(count($GLOBALS['readCalls'])===$readsBeforeIndoorScan,
    'indoor scan does not read hardware values');
$m->Refresh();

$data=json_decode((string)$m->GetValue('EvidenceJSON'),true);
checkGate(($data['qualifiedAdditionalIPCInventory']['otherIpcFoundInSymcon']??null)===2,
    'qualified IPC metadata persists through periodic Refresh');
checkGate(($data['cameraCapabilityPriority']['additionalIpcCandidatesConfiguredInSymcon'][0]['ip']??null)
    ==='192.168.107.98','existing street IPC candidate leads supplemental list');
checkGate(($data['qualifiedAdditionalIPCInventory']['smartPssHfw5442Count']??null)===7
    &&($data['qualifiedAdditionalIPCInventory']['smartPssTiocPdW3849Count']??null)===2,
    'nine real SmartPSS camera model counts included without altering Symcon');
checkGate(($data['qualifiedAdditionalIPCInventory']['otherIpcCandidates'][0]['modelFromPreviousInventory']??null)
    ==='IPC-HFW5442E-ZE','Torhalle Strasse now confirmed IPC model in screenshot');
checkGate(($data['qualifiedAdditionalIPCInventory']['otherIpcCandidates'][0]['ipConfirmedBySmartPSSTcpIpDialog']??null)
    ===false,'historical .98 IP not invented as screenshot verified');
checkGate(($data['cameraCapabilityPriority']['candidateEvaluationOrderForDedicatedPeopleCounting'][0]['historicalRole']??null)
    ==='TORHALLE_STRASSE_CANDIDATE',
    'Torhalle Street reviewed before any other dedicated count candidate');
checkGate(($data['cameraCapabilityPriority']['recommendedFirstCameraCanCountNow']??null)
    ===false,'no people count merely because model matches');
checkGate(($data['qualifiedAdditionalIPCInventory']['smartPssOnlineDoesNotVerifySymBoxEventStream']??null)
    ===true,'SmartPSS online not a working SymBox camera stream');
checkGate(($data['supplementaryIpcEventEvidence']['numberOfPeople']??null)==='UNKNOWN',
    'without preexisting extra IPC AUX event strings no people count invented');
checkGate(($data['supplementaryIpcEventEvidence']['supplementaryHumanHint']??null)===false,
    'no invented live human event for unmatched additional IPC sources');
checkGate(($data['gateSignalAudit']['signalCount']??null)===7,'manual audit samples 7');
checkGate(($data['gateSignalAudit']['readableSignals']??null)===7,'all 7 read');
checkGate(($data['gateDisplay']['positionHint']??null)==='ZWISCHENSTELLUNG_ODER_FAHRT',
    'old gate display mapping handles both end positions false');
checkGate(($data['gateDisplay']['propertyEmptyMayBeCertified']??null)===false,
    'gate display cannot assert vacancy');
checkGate(($data['indoorDeviceHints']['sourceCount']??null)===0,
    'no new indoor output IDs attached without verification');
checkGate(($data['gateSignalAudit']['messageSubscriptions']??null)===7,
    'all seven gate candidates observed via existing VM_UPDATE');
checkGate(count($m->subscriptions)===7,
    'seven passive subscriptions, no new Symcon event objects');
checkGate(($data['gateSignalAudit']['newStatusEdgesThisRead']??null)===0,
    'no fake event on activation');
checkGate(count($GLOBALS['readCalls'])===14
    && count(array_diff($GLOBALS['readCalls'],[
        40678,56808,13257,20724,31597,49204,50300
    ]))===0,'only original seven gate candidates read across two refreshes');
checkGate($m->registered===['Status','EvidenceJSON','JohannesPresence','CarinaPresence'],
    'unchanged two Shadow variables');
checkGate(($data['boundaryEvidence']['configuredBoundarySignals']??null)===0,
    'candidate audit does not bind physical sensors');
checkGate(($data['personPresenceMayBeChanged']??null)===false,
    'no presence writes');
checkGate(($data['cameraCapabilityPriority']['cameraPriorityForStreet'][0]??null)
    ==='WORK_LEFT_IPC_STREET_IVS',
    'known installed WORK_LEFT IPC prioritized for street IVS');
checkGate(($data['p03MastHealth']['authority']??null)
    ==='P03_EXISTING_MAST_OBSERVER_HEALTH_ONLY',
    'read-only P03 mast source diagnostics present in existing Shadow JSON');
checkGate(($data['p03MastHealth']['bothMastsLive']??null)===false,
    'test fixture without P03 Aux connections never claims live streams');
checkGate(($data['p03MastHealth']['camerasContacted']??null)===false,
    'no camera CGI calls or camera smart-plan changes');

checkGate(($data['cameraCapabilityPriority']['installedCameras']['JV_TERRASSE']['model']??null)
    ==='IPC-PDW3849-A180-AS-PV',
    'the exact already known TiOC model is reported');
checkGate(($data['cameraCapabilityPriority']['installedCameras']['JV_HOF_GARAGE']['model']??null)
    ==='IPC-PDW3849-A180-AS-PV',
    'the second known 180-degree TiOC is also reported');
checkGate(($data['cameraCapabilityPriority']['installedCameras']['JV_TERRASSE']['independentPeopleCountingAdvertised']??null)
    ===false,
    'no undocumented TiOC native headcount mode is assumed');
checkGate(($data['cameraCapabilityPriority']['panoramaCameraDoesNotCertifyExactlyTwoPeople']??null)
    ===true,
    '180-degree panorama remains only a group hint');
checkGate(($data['numberStatEvidence']['status']??null)==='NOT_CONNECTED',
    'never contact counting camera without opt-in or existing local source');
checkGate(($data['parallelPersonPassage']['individualPeopleCount']??null)==='UNKNOWN',
    'gate state and P03 diagnostic never count the house residents');
checkGate(($data['parallelPersonPassage']['propertyEmptyMayBeCertified']??null)===false,
    'a single camera event cannot mean two residents gone');
checkGate(($data['boundaryEvidence']['individualsOrEntireGroupExitedCertified']??null)===false,
    'gate status and street learning do not certify entire group crossing');
checkGate(($data['combinedPresenceEvidence']['authority']??null)==='ANONYMOUS_COMBINED_CONTEXT_ONLY',
    'combined diagnostic correctly attached');
checkGate(($data['combinedPresenceEvidence']['alarmMayBeControlled']??null)===false,
    'fusion has no alarm control');
checkGate(($data['propertyEmptyMayBeCertified']??null)===false,
    'no absence certification');
// Opt-in indoor light simulation starts only AFTER baseline regression counts.
$m->setProp('EnableAutoIndoorReadOnlyHints',true);
$m->Refresh();
$withLight=json_decode((string)$m->GetValue('EvidenceJSON'),true);
checkGate(($withLight['indoorAutoRead']['candidateCount']??null)===1
    &&($withLight['indoorAutoRead']['readableCount']??null)===1,
    'opt-in reads just one strictly whitelisted existing status');
checkGate(($withLight['indoorDeviceHints']['onCount']??null)===1
    &&($withLight['combinedPresenceEvidence']['personPresenceMayBeChanged']??null)===false,
    'existing light ON is weak only, no occupancy or alarm action');
$GLOBALS['gateDevices'][39745]['parent']='OG Bad Fenster GEÄNDERT';
$readsBefore=count($GLOBALS['readCalls']);
$m->Refresh();
$moved=json_decode((string)$m->GetValue('EvidenceJSON'),true);
checkGate(($moved['indoorAutoRead']['readableCount']??null)===0
    &&($moved['indoorDeviceHints']['onCount']??null)===0,
    'reparented status fails closed');
checkGate(!in_array(39745,array_slice($GLOBALS['readCalls'],$readsBefore),true),
    'wrong-path variable value never read');
$m->setProp('EnableAutoIndoorReadOnlyHints',false);
$m->Refresh();
$disabledLight=json_decode((string)$m->GetValue('EvidenceJSON'),true);
checkGate(($disabledLight['indoorAutoRead']['enabled']??null)===false
    &&($disabledLight['indoorDeviceHints']['onCount']??null)===0,
    'opt-out preserves original SHADOW behavior');


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
checkGate($after->instanceStatus===102,
    'ApplyChanges must complete status 101 to IS_ACTIVE 102 after preserved restart');
checkGate($after->statusTransitions===[102],
    'successful existing Shadow reload performs exactly one active acknowledgement');
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
checkGate($after->registered===['Status','EvidenceJSON','JohannesPresence','CarinaPresence'],
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
checkGate($after->instanceStatus===102,
    'switching off passive observer still means a healthy initialized module');
checkGate(count($after->subscriptions)===0,
    'disabling Shadow unregisters seven passive gate subscriptions');
checkGate($after->registered===['Status','EvidenceJSON','JohannesPresence','CarinaPresence',
    'Status','EvidenceJSON','JohannesPresence','CarinaPresence'],
    'still exactly four output identifiers after simulated module reload');

// Fail-closed lifecycle regression. Do not turn a failed bootstrap green.
$bad=new JVPresenceIdentityShadow();
$bad->Create();
$bad->setProp('Enabled',true);
$bad->setProp('SensorSources','[]');
$bad->setProp('P03InstanceID',0);
$bad->failTimerConfiguration=true;
$bad->ApplyChanges();
checkGate($bad->instanceStatus===201,
    'bootstrap timer failure becomes genuine error status 201, not 101 or 102');
checkGate(!in_array(102,$bad->statusTransitions,true),
    'bootstrap failure NEVER acknowledges ACTIVE');
checkGate(str_contains((string)$bad->GetValue('Status'),
    'CONFIGURE_READ_ONLY_TIMER'),
    'existing Status string identifies failed initialization stage');
checkGate(count($bad->registered)===4,
    'bootstrap failure does not duplicate or create output variables');
$bad->failTimerConfiguration=false;
$bad->ApplyChanges();
checkGate($bad->instanceStatus===102,
    'retry recovers module from 201 only after fully successful bootstrap');
checkGate($bad->registered===['Status','EvidenceJSON','JohannesPresence','CarinaPresence'],
    'successful retry preserves two existing output variables');
$activeFailure=new JVPresenceIdentityShadow();
$activeFailure->Create();
$activeFailure->setProp('Enabled',false);
$activeFailure->setProp('P03InstanceID',0);
$activeFailure->failActiveStatus=true;
$activeFailure->ApplyChanges();
checkGate($activeFailure->instanceStatus===201,
    'failed status transition is reported as a failure, never ignored');
checkGate(str_contains((string)$activeFailure->GetValue('Status'),
    'SET_ACTIVE_STATUS'),
    'status transition rejection has diagnostic stage');
$activeFailure->failActiveStatus=false;
$activeFailure->ApplyChanges();
checkGate($activeFailure->instanceStatus===102,
    'when status API recovers the module becomes active on next ApplyChanges');
// Johannes/Carina independently tested scripts: 4 existing variables,
// no WLAN traffic, no new event/category or change to their scripts.
$now=time();
$GLOBALS['gateDevices'][19980]=[
    'name'=>'WLAN anwesend','parent'=>'WLAN Johannes',
    'type'=>0,'value'=>true,'changed'=>$now-9,'pairedParent'=>801001];
$GLOBALS['gateDevices'][26497]=[
    'name'=>'Letzter Kontakt','parent'=>'WLAN Johannes',
    'type'=>1,'value'=>$now-30,'changed'=>$now-30,'pairedParent'=>801001];
$GLOBALS['gateDevices'][38430]=[
    'name'=>'WLAN anwesend','parent'=>'WLAN Carina',
    'type'=>0,'value'=>true,'changed'=>$now-9,'pairedParent'=>801002];
$GLOBALS['gateDevices'][10760]=[
    'name'=>'Letzter Kontakt','parent'=>'WLAN Carina',
    'type'=>1,'value'=>$now-90,'changed'=>$now-90,'pairedParent'=>801002];
$wlanMod=new JVPresenceIdentityShadow();
$wlanMod->Create();
$wlanMod->setProp('Enabled',true);
$wlanMod->setProp('SensorSources','[]');
$wlanMod->setProp('P03InstanceID',0);
$wlanMod->setProp('EnableExistingWLANHints',true);
$wlanMod->Refresh();
$wlanData=json_decode((string)$wlanMod->GetValue('EvidenceJSON'),true);
checkGate(($wlanData['combinedPresenceEvidence']['freshWlanDeviceCount']??null)===2,
    'both verified existing WLAN phones visible in combined context');
checkGate(($wlanData['combinedPresenceEvidence']['status']??null)
    ==='WLAN_DEVICES_ONLY_NO_RECENT_HOME_MOTION',
    'unmapped GUS means WLAN alone cannot claim house occupancy');
checkGate(($wlanData['houseObservation']['combinedContextStatus']??null)
    ==='WLAN_DEVICES_ONLY_NO_RECENT_HOME_MOTION',
    'house observation retains old state while adding context');
checkGate(($wlanData['existingWlanSmartphones']['freshMacConfirmedDeviceCount']??null)===2,
    'MAC verified actual Johannes and Carina booleans + Unix contact times');
checkGate(($wlanData['existingWlanSmartphones']['people']['johannes']['recentMacEvidence']??false)===true
    &&($wlanData['existingWlanSmartphones']['people']['carina']['recentMacEvidence']??false)===true,
    'two separate handset MAC devices supported without forcing a human count');
checkGate(($wlanData['phone']['johannes']??null)==='PHONE_PROPERTY_INDICATION'
    &&($wlanData['phone']['carina']??null)==='PHONE_PROPERTY_INDICATION',
    'existing high-level phone indications receive read-only device evidence');
checkGate(($wlanData['people']['johannes']??null)==='UNKNOWN'
    &&($wlanData['people']['carina']??null)==='UNKNOWN'
    &&($wlanData['propertyEmptyMayBeCertified']??null)===false,
    'both phones online never assert two people present nor empty premises');
checkGate($wlanMod->registered===['Status','EvidenceJSON','JohannesPresence','CarinaPresence'],
    'read-only phone integration does not allocate a new Symcon variable');
$GLOBALS['gateDevices'][38430]['value']=false;
$wlanMod->Refresh();
$off=json_decode((string)$wlanMod->GetValue('EvidenceJSON'),true);
checkGate(($off['existingWlanSmartphones']['people']['carina']['state']??null)
    ==='NO_RECENT_MAC_CONFIRMATION_NOT_ABSENCE',
    'Carina WLAN off does NOT prove Carina actually left');
checkGate(($off['people']['carina']??null)==='UNKNOWN',
    'Carina identity/absence never automatically written');
$GLOBALS['gateDevices'][38430]['value']=true;
$GLOBALS['gateDevices'][10760]['value']=$now-900;
$wlanMod->Refresh();
$stale=json_decode((string)$wlanMod->GetValue('EvidenceJSON'),true);
checkGate(($stale['existingWlanSmartphones']['people']['carina']['state']??null)
    ==='STALE_TRUE_CANNOT_CONFIRM_PRESENCE',
    'old TRUE is rejected beyond the 660s positive-contact threshold');
$GLOBALS['gateDevices'][10760]['value']=$now-5;
$GLOBALS['gateDevices'][38430]['name']='Other variable ID';
$readsBefore=count($GLOBALS['readCalls']);
$wlanMod->Refresh();
$badName=json_decode((string)$wlanMod->GetValue('EvidenceJSON'),true);
checkGate(($badName['existingWlanSmartphones']['people']['carina']['state']??null)
    ==='SOURCE_NOT_VERIFIED',
    'accidental ID reuse with wrong variable name fails closed');
checkGate(count($GLOBALS['readCalls'])===$readsBefore+2,
    'invalid Carina IDs NOT read: only Johannes two existing WLAN variables read');
$wlanMod->setProp('EnableExistingWLANHints',false);
$readsBefore=count($GLOBALS['readCalls']);
$wlanMod->Refresh();
$disabled=json_decode((string)$wlanMod->GetValue('EvidenceJSON'),true);
checkGate(($disabled['existingWlanSmartphones']['enabled']??null)===false
    &&count($GLOBALS['readCalls'])===$readsBefore,
    'disabling WLAN read avoids touching any phone source');
checkGate(($disabled['phoneLink']['state']??null)==='DISABLED_SEPARATE_PROJECT',
    'legacy phone observer still remains disabled');

// Real installed EgiGeoZone: #35965 / dummy #50000 / Zuhause #41869
// / Timestamp #28055. Raw latitude #42608 and longitude #12243 MUST NOT
// be read. Opt-in preserves all existing phone/P03/gate integrations.
$now=time();
$GLOBALS['gateDevices'][41869]=[
    'name'=>'Zuhause','parent'=>'Smartphone Johannes',
    'type'=>0,'value'=>true,'changed'=>$now-18,
    'pairedParent'=>50000];
$GLOBALS['gateDevices'][28055]=[
    'name'=>'Timestamp','parent'=>'Smartphone Johannes',
    'type'=>1,'value'=>$now-35,'changed'=>$now-35,
    'pairedParent'=>50000];
$GLOBALS['gateDevices'][42608]=[
    'name'=>'Latitude','parent'=>'Smartphone Johannes',
    'type'=>2,'value'=>49.0,'changed'=>$now-20,
    'pairedParent'=>50000];
$GLOBALS['gateDevices'][12243]=[
    'name'=>'Longitude','parent'=>'Smartphone Johannes',
    'type'=>2,'value'=>6.0,'changed'=>$now-20,
    'pairedParent'=>50000];
$geoMod=new JVPresenceIdentityShadow();
$geoMod->Create();
$geoMod->setProp('Enabled',true);
$geoMod->setProp('P03InstanceID',0);
$geoMod->setProp('SensorSources','[]');
$geoMod->setProp('EnableExistingWLANHints',true);
$geoMod->setProp('EnableJohannesEgiGeoZone',true);
$readsBefore=count($GLOBALS['readCalls']);
$geoMod->Refresh();
$geo=json_decode((string)$geoMod->GetValue('EvidenceJSON'),true);
checkGate(($geo['johannesEgiGeoZone']['state']??null)
    ==='RECENT_INSIDE_150M_DEVICE_HINT'
    &&($geo['johannesEgiGeoZone']['radiusMetersConfiguredInPhoneApp']??null)===150,
    'read real existing inside state and app-reported 150 m radius');
checkGate(($geo['combinedPresenceEvidence']['johannesWlanGeofenceCrossCheck']??null)
    ==='GEOFENCE_AND_WLAN_PHONE_NEAR_HOME'
    &&($geo['combinedPresenceEvidence']['status']??null)
    ==='JOHANNES_WLAN_GEOFENCE_DEVICE_NEAR_HOME_NO_RECENT_MOTION',
    'two independent Johannes phone device channels agree, no GUS needed');
checkGate(($geo['people']['johannes']??null)==='UNKNOWN'
    &&($geo['combinedPresenceEvidence']['personPresenceMayBeChanged']??null)===false
    &&($geo['combinedPresenceEvidence']['absenceReview']['mayArmAlarm']??null)===false,
    'geofence cannot set identity, person location, property vacancy or alarm');
$readGeo=array_slice($GLOBALS['readCalls'],$readsBefore);
checkGate(in_array(41869,$readGeo,true)&&in_array(28055,$readGeo,true),
    'only opt-in existing Boolean and Unix timestamp read');
checkGate(!in_array(42608,$readGeo,true)&&!in_array(12243,$readGeo,true),
    'sensitive raw latitude/longitude never read');
$GLOBALS['gateDevices'][41869]['value']=false;
$geoMod->Refresh();
$geoOut=json_decode((string)$geoMod->GetValue('EvidenceJSON'),true);
checkGate(($geoOut['johannesEgiGeoZone']['outsideRecent']??null)===true
    &&($geoOut['combinedPresenceEvidence']['johannesWlanGeofenceCrossCheck']??null)
    ==='WLAN_ON_GEOFENCE_OUT_REVIEW_ONLY'
    &&($geoOut['people']['johannes']??null)==='UNKNOWN',
    'geofence outside while WLAN on: conflict only, never human away');
$GLOBALS['gateDevices'][28055]['value']=$now-22000;
$geoMod->Refresh();
$staleGeo=json_decode((string)$geoMod->GetValue('EvidenceJSON'),true);
checkGate(($staleGeo['johannesEgiGeoZone']['outsideRecent']??true)===false
    &&($staleGeo['johannesEgiGeoZone']['state']??null)
    ==='STALE_OUTSIDE_NOT_LIVE_ABSENCE',
    'old outside events never certify person absent');
$GLOBALS['gateDevices'][28055]['value']=$now-20;
$GLOBALS['gateDevices'][41869]['pairedParent']=50001; // actual Symcon ParentID moves
$readsBefore=count($GLOBALS['readCalls']);
$geoMod->Refresh();
$badGeo=json_decode((string)$geoMod->GetValue('EvidenceJSON'),true);
checkGate(($badGeo['johannesEgiGeoZone']['state']??null)==='SOURCE_NOT_VERIFIED',
    'moved/reused EgiGeoZone source fails closed');
$readBad=array_slice($GLOBALS['readCalls'],$readsBefore);
checkGate(!in_array(41869,$readBad,true)&&!in_array(28055,$readBad,true),
    'invalid EgiGeoZone ID paths never read source values');
$geoMod->setProp('EnableJohannesEgiGeoZone',false);
$readsBefore=count($GLOBALS['readCalls']);
$geoMod->Refresh();
$disabledGeo=json_decode((string)$geoMod->GetValue('EvidenceJSON'),true);
checkGate(($disabledGeo['johannesEgiGeoZone']['state']??null)==='DISABLED'
    &&($disabledGeo['combinedPresenceEvidence']['johannesGeofenceContext']??null)
    ==='DISABLED_OR_UNVERIFIED',
    'geo opt-out leaves previous WLAN/GUS status unchanged');
checkGate(!in_array(41869,array_slice($GLOBALS['readCalls'],$readsBefore),true)
    &&!in_array(28055,array_slice($GLOBALS['readCalls'],$readsBefore),true),
    'disabled geofence reads zero smartphone geo values');
checkGate($geoMod->registered===['Status','EvidenceJSON','JohannesPresence','CarinaPresence'],
    'EgiGeoZone opt-in creates no new Symcon variables');

// Carina iPhone Geofency is a separate real IP-Symcon Geofency module
// under root 61000 / generic dummy 62000 / actual boolean & timestamp.
// Its child IDs must be identified through metadata only before activation.
$now=time();
$GLOBALS['gateDevices'][38430]['name']='WLAN anwesend';
$GLOBALS['gateDevices'][38430]['value']=true;
$GLOBALS['gateDevices'][10760]['value']=$now-20;
$GLOBALS['gateDevices'][41869]['pairedParent']=50000;
$GLOBALS['gateDevices'][41869]['value']=true;
$GLOBALS['gateDevices'][28055]['value']=$now-30;
$GLOBALS['gateDevices'][63000]=[
    'name'=>'Zuhause','parent'=>'Device','type'=>0,
    'pairedParent'=>62000,'value'=>true,'changed'=>$now-15,'updated'=>$now-15
];
$GLOBALS['gateDevices'][63001]=[
    'name'=>'Timestamp','parent'=>'Device','type'=>1,
    'pairedParent'=>62000,'value'=>$now-65,'changed'=>$now-65,'updated'=>$now-15
];
$GLOBALS['gateDevices'][63002]=[
    'name'=>'Latitude','parent'=>'Device','type'=>2,
    'pairedParent'=>62000,'value'=>48.0,'changed'=>$now-15
];
$carGeoMod=new JVPresenceIdentityShadow();
$carGeoMod->Create();
$carGeoMod->setProp('Enabled',true);
$carGeoMod->setProp('P03InstanceID',0);
$carGeoMod->setProp('SensorSources','[]');
$carGeoMod->setProp('EnableExistingWLANHints',true);
$carGeoMod->setProp('EnableJohannesEgiGeoZone',true);
$readsBefore=count($GLOBALS['readCalls']);
$carGeoMod->AuditCarinaGeofencyCandidates();
$candidates=json_decode((string)$carGeoMod->GetValue('EvidenceJSON'),true);
checkGate(($candidates['authority']??null)==='CARINA_GEOFENCY_METADATA_ONLY'
    &&($candidates['candidateCount']??0)===2
    &&($candidates['deviceCount']??0)===1,
    'Carina metadata audit identifies only existing Boolean Zuhause and Timestamp');
checkGate(count($GLOBALS['readCalls'])===$readsBefore,
    'metadata inventory reads no device values and no GPS coordinates');
$carGeoMod->setProp('CarinaGeofencyInstanceID',61000);
$carGeoMod->setProp('CarinaGeofencyDeviceID',62000);
$carGeoMod->setProp('CarinaGeofencyZoneVarID',63000);
$carGeoMod->setProp('CarinaGeofencyTimestampID',63001);
$carGeoMod->setProp('EnableCarinaGeofencyHints',true);
$readsBefore=count($GLOBALS['readCalls']);
$carGeoMod->Refresh();
$both=json_decode((string)$carGeoMod->GetValue('EvidenceJSON'),true);
checkGate(($both['carinaGeofency']['state']??null)
    ==='RECENT_INSIDE_HOME_GEOFENCE_DEVICE_HINT',
    'Carina actual existing iPhone Geofency Boolean with valid timestamp');
checkGate(($both['combinedPresenceEvidence']['bothPhoneGeofencesNearHome']??false)
    &&($both['combinedPresenceEvidence']['carinaWlanGeofencyCrossCheck']??null)
    ==='GEOFENCY_AND_WLAN_CARINA_PHONE_NEAR_HOME'
    &&($both['combinedPresenceEvidence']['status']??null)
    ==='BOTH_PHONES_WLAN_AND_GEOFENCE_NEAR_HOME_NO_MOTION',
    'Johannes and Carina each report geofence and WLAN with no recent indoor GUS');
checkGate(($both['people']['carina']??null)==='UNKNOWN'
    &&($both['people']['johannes']??null)==='UNKNOWN'
    &&($both['combinedPresenceEvidence']['absenceReview']['mayArmAlarm']??null)===false,
    'two home phones cannot identify humans or arm alarm');
checkGate($carGeoMod->GetValue('JohannesPresence')==='ANWESEND (Handyhinweis)'
    &&$carGeoMod->GetValue('CarinaPresence')==='ANWESEND (Handyhinweis)',
    'both requested status values show confirmed smartphone device proximity');
checkGate(($both['residentDisplay']['people']['carina']['personPhysicallyHomeCertified']??true)===false,
    'new display cannot claim physical resident identification');
$readNew=array_slice($GLOBALS['readCalls'],$readsBefore);
checkGate(in_array(63000,$readNew,true)&&in_array(63001,$readNew,true)
    &&!in_array(63002,$readNew,true),
    'read actual Boolean and Unix timestamp; never iPhone GPS coordinates');
$GLOBALS['gateDevices'][63000]['value']=false;
$carGeoMod->Refresh();
$conflict=json_decode((string)$carGeoMod->GetValue('EvidenceJSON'),true);
checkGate(($conflict['carinaGeofency']['outsideRecent']??null)===true
    &&($conflict['combinedPresenceEvidence']['carinaWlanGeofencyCrossCheck']??null)
    ==='WLAN_ON_GEOFENCY_OUT_REVIEW_ONLY'
    &&($conflict['people']['carina']??null)==='UNKNOWN',
    'Carina outside while WLAN true is conflict review, never absent');
checkGate($carGeoMod->GetValue('CarinaPresence')==='WIDERSPRUCH (WLAN / Geofence)',
    'conflicting source shown without claiming away');
$GLOBALS['gateDevices'][63001]['value']=$now-22000;
$carGeoMod->Refresh();
$stale=json_decode((string)$carGeoMod->GetValue('EvidenceJSON'),true);
checkGate(($stale['carinaGeofency']['outsideRecent']??true)===false
    &&($stale['carinaGeofency']['state']??null)
    ==='STALE_OUTSIDE_NOT_PERSON_AWAY',
    'stale Geofency event not current phone position');
checkGate($carGeoMod->GetValue('CarinaPresence')
    ==='WLAN ZUHAUSE (Geofence unbestätigt)',
    'stale outside plus WLAN does not claim away');
$GLOBALS['gateDevices'][63001]['value']=$now-60;
$GLOBALS['gateDevices'][63000]['pairedParent']=62001; // actual tree change
$readsBefore=count($GLOBALS['readCalls']);
$carGeoMod->Refresh();
$bad=json_decode((string)$carGeoMod->GetValue('EvidenceJSON'),true);
checkGate(($bad['carinaGeofency']['state']??null)==='SOURCE_NOT_VERIFIED',
    'moved or reused Geofency Boolean fails closed before state read');
checkGate($carGeoMod->GetValue('CarinaPresence')
    ==='WLAN ZUHAUSE (Geofence unbestätigt)',
    'reparented source cannot certify absent');
$readNew=array_slice($GLOBALS['readCalls'],$readsBefore);
checkGate(!in_array(63000,$readNew,true)&&!in_array(63001,$readNew,true),
    'invalid ID hierarchy never reads geofence values');
$carGeoMod->setProp('EnableCarinaGeofencyHints',false);
$readsBefore=count($GLOBALS['readCalls']);
$carGeoMod->Refresh();
$disabledCarina=json_decode((string)$carGeoMod->GetValue('EvidenceJSON'),true);
checkGate(($disabledCarina['carinaGeofency']['state']??null)==='DISABLED'
    &&($disabledCarina['combinedPresenceEvidence']['carinaGeofencyContext']??null)
    ==='DISABLED_OR_UNVERIFIED'
    &&($disabledCarina['combinedPresenceEvidence']['johannesGeofenceContext']??null)
    ==='JOHANNES_PHONE_REPORTED_WITHIN_HOME_GEOFENCE',
    'Carina opt-out does not disrupt EgiGeoZone Johannes');
checkGate(!in_array(63000,array_slice($GLOBALS['readCalls'],$readsBefore),true)
    &&!in_array(63001,array_slice($GLOBALS['readCalls'],$readsBefore),true)
    &&$carGeoMod->registered===['Status','EvidenceJSON','JohannesPresence','CarinaPresence'],
    'disabled Geofency reads no values and creates no Symcon output variables');

// Actual confirmed Carina live IDs: #48903 / #45439 / #39169 / #45666.
// No separate metadata audit is needed after update; default properties
// must already link these objects, but only after strict live verification.
$now=time();
$GLOBALS['gateDevices'][39169]=[
    'name'=>'Zuhause','parent'=>'Device','type'=>0,
    'pairedParent'=>45439,'value'=>true,
    'changed'=>$now-20,'updated'=>$now-20
];
$GLOBALS['gateDevices'][45666]=[
    'name'=>'Timestamp','parent'=>'Device','type'=>1,
    'pairedParent'=>45439,'value'=>$now-30,
    'changed'=>$now-30,'updated'=>$now-20
];
$GLOBALS['gateDevices'][36910]=[
    'name'=>'Current Latitude','parent'=>'Device','type'=>2,
    'pairedParent'=>45439,'value'=>0.0,'changed'=>$now-20
];
$GLOBALS['gateDevices'][51224]=[
    'name'=>'Current Longitude','parent'=>'Device','type'=>2,
    'pairedParent'=>45439,'value'=>0.0,'changed'=>$now-20
];
$GLOBALS['gateDevices'][41869]['pairedParent']=50000;
$GLOBALS['gateDevices'][41869]['name']='Zuhause';
$GLOBALS['gateDevices'][41869]['value']=true;
$GLOBALS['gateDevices'][28055]['value']=$now-20;
$GLOBALS['gateDevices'][38430]['name']='WLAN anwesend';
$GLOBALS['gateDevices'][38430]['value']=true;
$GLOBALS['gateDevices'][10760]['value']=$now-10;
$real=new JVPresenceIdentityShadow();
$real->Create();
$real->setProp('Enabled',true);
$real->setProp('SensorSources','[]');
$real->setProp('P03InstanceID',0);
$real->setProp('EnableExistingWLANHints',true);
$real->setProp('EnableJohannesEgiGeoZone',true);
$real->setProp('EnableCarinaGeofencyHints',true);
$readsBefore=count($GLOBALS['readCalls']);
$real->Refresh();
$live=json_decode((string)$real->GetValue('EvidenceJSON'),true);
checkGate(($live['johannesEgiGeoZone']['state']??null)
    ==='RECENT_INSIDE_150M_DEVICE_HINT',
    'actual Johannes variable name Zuhause is now recognized');
checkGate(($live['carinaGeofency']['state']??null)
    ==='RECENT_INSIDE_HOME_GEOFENCE_DEVICE_HINT',
    'actual Carina known IDs work WITHOUT the optional Button 7 metadata scan');
checkGate($real->GetValue('JohannesPresence')==='ANWESEND (Handyhinweis)'
    &&$real->GetValue('CarinaPresence')==='ANWESEND (Handyhinweis)',
    'both requested human-readable status variables show handset hints');
checkGate(($live['people']['carina']??null)==='UNKNOWN'
    &&($live['people']['johannes']??null)==='UNKNOWN'
    &&($live['propertyEmptyMayBeCertified']??null)===false,
    'device-hint display never claims humans physically located');
$readLive=array_slice($GLOBALS['readCalls'],$readsBefore);
checkGate(!in_array(36910,$readLive,true)&&!in_array(51224,$readLive,true),
    'new Carina display does not read real GPS coordinates');
$GLOBALS['gateDevices'][41869]['updated']=$now-900;
$GLOBALS['gateDevices'][28055]['updated']=$now-2;
$readsBefore=count($GLOBALS['readCalls']);
$real->Refresh();
$otherEgi=json_decode((string)$real->GetValue('EvidenceJSON'),true);
checkGate(($otherEgi['johannesEgiGeoZone']['state']??null)
    ==='SOURCE_NOT_VERIFIED',
    'EgiGeoZone timestamp for other zone cannot fake fresh home flag');
checkGate($real->GetValue('JohannesPresence')
    ==='WLAN ZUHAUSE (Geofence unbestätigt)',
    'Johannes conflicting timestamps fall back to WLAN-only, never physical presence');
$otherEgiRead=array_slice($GLOBALS['readCalls'],$readsBefore);
checkGate(!in_array(41869,$otherEgiRead,true)
    &&!in_array(28055,$otherEgiRead,true),
    'invalid EgiGeoZone timestamp provenance blocks geofence value reads');
$GLOBALS['gateDevices'][41869]['updated']=$now-15;
$GLOBALS['gateDevices'][28055]['updated']=$now-15;
$real->Refresh();
checkGate($real->GetValue('JohannesPresence')==='ANWESEND (Handyhinweis)',
    'Johannes recovers after consistent two timestamp updates');
$GLOBALS['gateDevices'][39169]['updated']=$now-1000;
$GLOBALS['gateDevices'][45666]['updated']=$now-3;
$readsBefore=count($GLOBALS['readCalls']);
$real->Refresh();
$unrelated=json_decode((string)$real->GetValue('EvidenceJSON'),true);
checkGate(($unrelated['carinaGeofency']['selectionStatus']??null)
    ==='OTHER_GEOFENCE_TIMESTAMP',
    'an office geofence update does not freshen the Zuhause zone');
checkGate($real->GetValue('CarinaPresence')
    ==='WLAN ZUHAUSE (Geofence unbestätigt)',
    'other zone timestamp cannot falsely create a positive home geofence');
$unrelatedReads=array_slice($GLOBALS['readCalls'],$readsBefore);
checkGate(!in_array(39169,$unrelatedReads,true)
    &&!in_array(45666,$unrelatedReads,true),
    'other-zone timestamp case does not read Geofency values');
$real->setProp('Enabled',false);
$real->Refresh();
checkGate($real->GetValue('JohannesPresence')==='NICHT BESTÄTIGT'
    &&$real->GetValue('CarinaPresence')==='NICHT BESTÄTIGT',
    'disabled instance immediately clears earlier positive phone displays');
checkGate($real->registered===['Status','EvidenceJSON','JohannesPresence','CarinaPresence'],
    'exactly two additional user-requested instance variables');

// Live upgrade path from 0.8.47: the three Carina IDs are persisted as
// zeros even when v0.8.48+ registers nonzero defaults! Reading must
// transparently use verified screenshot IDs without writing module props.
$GLOBALS['gateDevices'][39169]['updated']=$now-15;
$GLOBALS['gateDevices'][45666]['updated']=$now-15;
$legacy=new JVPresenceIdentityShadow();
$legacy->Create();
$legacy->setProp('Enabled',true);
$legacy->setProp('SensorSources','[]');
$legacy->setProp('P03InstanceID',0);
$legacy->setProp('EnableExistingWLANHints',true);
$legacy->setProp('EnableJohannesEgiGeoZone',true);
$legacy->setProp('EnableCarinaGeofencyHints',true);
$legacy->setProp('CarinaGeofencyDeviceID',0);
$legacy->setProp('CarinaGeofencyZoneVarID',0);
$legacy->setProp('CarinaGeofencyTimestampID',0);
$readsBefore=count($GLOBALS['readCalls']);
$legacy->Refresh();
$zeroUpgrade=json_decode((string)$legacy->GetValue('EvidenceJSON'),true);
checkGate(($zeroUpgrade['carinaGeofency']['state']??null)
    ==='RECENT_INSIDE_HOME_GEOFENCE_DEVICE_HINT',
    'legacy persisted 0/0/0 resolves to verified real Carina IDs by safe read-only fallback');
checkGate($legacy->GetValue('JohannesPresence')==='ANWESEND (Handyhinweis)'
    &&$legacy->GetValue('CarinaPresence')==='ANWESEND (Handyhinweis)',
    'legacy upgrades show both user-requested display states without manual IDs');
$readsLegacy=array_slice($GLOBALS['readCalls'],$readsBefore);
checkGate(in_array(39169,$readsLegacy,true)&&in_array(45666,$readsLegacy,true)
    &&!in_array(36910,$readsLegacy,true)&&!in_array(51224,$readsLegacy,true),
    'fallback reads only already-verified Geofency Boolean and Unix timestamp');
$legacy->setProp('CarinaGeofencyZoneVarID',99999);
$legacy->Refresh();
$wrongMix=json_decode((string)$legacy->GetValue('EvidenceJSON'),true);
checkGate(($wrongMix['carinaGeofency']['state']??null)==='SOURCE_NOT_VERIFIED',
    'partial/incorrect old IDs are never silently repaired with a mixed source');
checkGate($legacy->GetValue('CarinaPresence')
    ==='WLAN ZUHAUSE (Geofence unbestätigt)',
    'bad source mapping remains non-authoritative and never claims person AWAY');
echo "Gate Shadow integration / initialization 101->102, failure 201 recovery PASS\n";
