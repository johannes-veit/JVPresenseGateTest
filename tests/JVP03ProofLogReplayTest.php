<?php
declare(strict_types=1);
// Mock the IP-Symcon per-instance Refresh semaphore (no real server).
if(!function_exists('IPS_SemaphoreEnter')){
    $GLOBALS['shadowSemaphores']=[];
    function IPS_SemaphoreEnter(string $name,int $timeout):bool {
        if(isset($GLOBALS['shadowSemaphores'][$name]))return false;
        $GLOBALS['shadowSemaphores'][$name]=true;return true;
    }
    function IPS_SemaphoreLeave(string $name):void {
        unset($GLOBALS['shadowSemaphores'][$name]);
    }
}
require_once dirname(__DIR__).'/libs/JVP03ProofLogReplay.php';

$checks=0;
function eq(mixed $got,mixed $expected,string $name):void {
    global $checks;$checks++;
    if ($got!==$expected) {
        throw new RuntimeException("$name: got ".var_export($got,true)
            .", expected ".var_export($expected,true));
    }
}

// Selective exact test-log events from the ALREADY COMPLETED 12.10.2026
// commissioning (real supplied source, not an invented extra field test).
// They intentionally contain no P03 group-count or personal-ID evidence.
$historic=implode("\n",[
    '[2026-10-12 19:04:25] TESTFOLGE: OUT → IN → OUT → IN – jedes Mal ueber die Treppenlinie UND die Straßenlinie der WORK_LEFT-Kamera (RuleID5) gehen.',
    '[2026-10-12 19:05:24] P03 HUMAN JV_LEFT @1791824724.497',
    '[2026-10-12 19:05:41] P03 HUMAN WORK_LEFT @1791824741.155',
    '[2026-10-12 19:05:43] P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_JV_THEN_WORK',
    '[2026-10-12 19:05:49] P03 STRASSE LERNEN 1/4 OUT Kamera-Richtung=RightToLeft | 19:05:49',
    '[2026-10-12 19:06:32] P03 STRASSE LERNEN 2/4 IN Kamera-Richtung=LeftToRight | 19:06:32',
    '[2026-10-12 19:06:55] P03 PROOF VERIFIED LAGER_TO_HOME via P03_2CAM_WORK_THEN_JV',
    '[2026-10-12 19:08:24] P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_JV_THEN_WORK',
    '[2026-10-12 19:08:30] P03 STRASSE LERNEN 3/4 OUT Kamera-Richtung=RightToLeft | 19:08:30',
    '[2026-10-12 19:09:17] P03 STRASSE LERNEN 4/4 IN Kamera-Richtung=LeftToRight | 19:09:17',
    '[2026-10-12 19:09:47] P03 PROOF VERIFIED LAGER_TO_HOME via P03_2CAM_WORK_THEN_JV',
    '[2026-10-12 19:09:47] P03 COMMISSIONING COMPLETE: OUT=2, IN=2 – ausschließlich Mastkamera-Reihenfolge.'
]);
$report=JVP03ProofLogReplay::parse($historic);
eq($report['p03']['homeToWork'],2,'historic P03 twice OUT');
eq($report['p03']['workToHome'],2,'historic P03 twice IN');
eq($report['p03']['commissioningReplayed'],true,'historic fourfold run complete');
eq($report['street']['out'],'RightToLeft','actual WORK street OUT');
eq($report['street']['in'],'LeftToRight','actual WORK street IN');
eq($report['street']['directionMappingCertified'],true,'actual street map consistent');
eq($report['p03']['lastProofLocalTime'],'2026-10-12 19:09:47','last historic proof is fixed');
eq($report['personCountCertified'],false,'P03 cannot count simultaneous humans yet');
eq($report['identityCertified'],false,'archive gives no personal identity');
eq($report['propertyEmptyMayBeCertified'],false,'direction does not certify empty');
eq($report['personPresenceMayBeChanged'],false,'archive must not change presence');

$joined=str_replace("\n",'',$historic);
eq(JVP03ProofLogReplay::parse($joined)['p03']['commissioningReplayed'],true,
    'actual text-paste concatenation without newlines supported');
$repeat=$historic."\n".$historic;
eq(JVP03ProofLogReplay::parse($repeat)['p03']['homeToWork'],2,
    'repeated protocol does not double-count proofs');
eq(JVP03ProofLogReplay::parse($repeat)['p03']['workToHome'],2,
    'repeated protocol does not double-count reverse');

$spoof=str_replace('P03_2CAM_WORK_THEN_JV','P03_2CAM_JV_THEN_WORK',$historic);
$bad=JVP03ProofLogReplay::parse($spoof);
eq($bad['p03']['workToHome'],0,'direction-route mismatch rejected');
eq($bad['p03']['commissioningReplayed'],false,'spoof cannot prove commissioning');
eq(in_array('P03_DIRECTION_ROUTE_MISMATCH',$bad['warnings'],true),true,
    'tampering is diagnostic');

$single='[2026-10-12 19:04:25] SmartMotionHuman START Human'.
    "\n[2026-10-12 19:04:26] P03 HUMAN JV_LEFT @1791824640.0";
eq(JVP03ProofLogReplay::parse($single)['p03']['homeToWork'],0,
    'SMD and one mast alone never synthesize proof');

$unfinished=str_replace('P03 PROOF VERIFIED LAGER_TO_HOME via P03_2CAM_WORK_THEN_JV',
    'P03 HUMAN JV_LEFT @1791824985.317',$historic);
eq(JVP03ProofLogReplay::parse($unfinished)['p03']['commissioningReplayed'],false,
    'written commissioning claim without four proofs cannot pass replay');

$flip=str_replace('3/4 OUT Kamera-Richtung=RightToLeft',
    '3/4 OUT Kamera-Richtung=LeftToRight',$historic);
eq(JVP03ProofLogReplay::parse($flip)['street']['directionMappingCertified'],false,
    'contradictory street directions do not auto-train');

eq(JVP03ProofLogReplay::parse(str_repeat('x',270000))['warnings'],
    ['PROTOCOL_TOO_LARGE_OR_TRUNCATED'],'oversize rejected safely');

// Isolated Symcon API with read/write surveillance; NO network tools.
$GLOBALS['instances']=[
    53879=>['ModuleInfo'=>['ModuleID'=>'{0A7A91CE-3BC1-4B1E-9C11-7B7E973F5C03}']],
    12345=>['ModuleInfo'=>['ModuleID'=>'{B5BF0266-A4D1-40A0-AAC8-97E9C24C4173}']],
    22222=>['ModuleInfo'=>['ModuleID'=>'{00000000-0000-0000-0000-000000000000}']]
];
$GLOBALS['ids']=['53879:Protocol'=>53524,'12345:EvidenceJSON'=>60001,'22222:Protocol'=>60002];
$GLOBALS['meta']=[
    53524=>['VariableType'=>3],
    60001=>['VariableType'=>3],
    60002=>['VariableType'=>3]
];
$GLOBALS['raw']=[
    53524=>$historic,
    60001=>json_encode(['schema'=>1,'authority'=>'PHONE_OBSERVATIONS_ONLY',
        'people'=>[
            ['id'=>'johannes','phoneStatus'=>'PHONE_PROPERTY_INDICATION'],
            ['id'=>'carina','phoneStatus'=>'PHONE_OUTSIDE_INDICATION']
        ]]),
    60002=>$historic
];
$GLOBALS['readCount']=0;
$GLOBALS['readById']=[];
function IPS_GetInstance(int $id):array {
    return $GLOBALS['instances'][$id]??throw new RuntimeException('not found');
}
function IPS_GetObjectIDByIdent(string $ident,int $id):int|false {
    return $GLOBALS['ids'][$id.':'.$ident]??false;
}
function IPS_GetInstanceListByModuleID(string $guid):array {
    if($guid==='{2D871359-14D8-493F-9B01-26432E3A710F}')
        return [8001,8002];
    if($guid==='{B5BF0266-A4D1-40A0-AAC8-97E9C24C4173}')
        return $GLOBALS['phoneObserverCandidates']??[12345];
    return [];
}
function IPS_GetChildrenIDs(int $id):array {
    return $GLOBALS['unitChildren'][$id]??[];
}
function IPS_GetVariableList():array {
    return $GLOBALS['candidateVariableList']??[];
}
function IPS_GetObject(int $id):array {
    return $GLOBALS['objects'][$id]??throw new RuntimeException('no object');
}
function IPS_GetName(int $id):string {
    return $GLOBALS['names'][$id]??('object '.$id);
}
function IPS_VariableExists(int $id):bool {return isset($GLOBALS['meta'][$id]);}
function IPS_GetVariable(int $id):array {return $GLOBALS['meta'][$id];}
function GetValue(int $id):mixed {
    $GLOBALS['readCount']++;
    $GLOBALS['readById'][$id]=($GLOBALS['readById'][$id]??0)+1;
    return $GLOBALS['raw'][$id];
}
class IPSModule {
    public int $InstanceID=24680;
    public array $properties=[];
    public array $values=[];
    public array $timer=[];
    public array $writes=[];
    public array $buffers=[];
    public array $attributes=[];
    public array $messages=[];
    public function Create():void {}
    public function ApplyChanges():void {}
    public int $instanceStatus=101;
    protected function SetStatus(int $code):bool {
        $this->instanceStatus=$code;return true;
    }
    protected function SendDebug(string $topic,string $text,int $format):void {}
    protected function RegisterPropertyBoolean(string $k,bool $v):void {$this->properties[$k]=$v;}
    protected function RegisterPropertyInteger(string $k,int $v):void {$this->properties[$k]=$v;}
    protected function RegisterPropertyString(string $k,string $v):void {$this->properties[$k]=$v;}
    protected function RegisterAttributeString(string $k,string $v):void {if(!array_key_exists($k,$this->attributes))$this->attributes[$k]=$v;}
    protected function ReadAttributeString(string $k):string {return (string)($this->attributes[$k]??'');}
    protected function WriteAttributeString(string $k,string $v):void {$this->attributes[$k]=$v;}
    protected function RegisterMessage(int $id,int $code):void {$this->messages[$id.':'.$code]=true;}
    protected function UnregisterMessage(int $id,int $code):void {unset($this->messages[$id.':'.$code]);}
    protected function ReadPropertyString(string $k):string {return (string)($this->properties[$k]??'');}
    protected function RegisterVariableString(string $k,string $n,string $p='',int $o=0):void {$this->values[$k]='';}
    protected function RegisterVariableBoolean(string $k,string $n,string $p='',int $o=0):void {$this->values[$k]=false;}
    protected function RegisterTimer(string $k,int $ms,string $script):void {$this->timer[$k]=$ms;}
    protected function SetTimerInterval(string $k,int $ms):void {$this->timer[$k]=$ms;}
    protected function ReadPropertyBoolean(string $k):bool {return (bool)($this->properties[$k]??false);}
    protected function SetBuffer(string $k,string $v):void {$this->buffers[$k]=$v;}
    protected function GetBuffer(string $k):string {return (string)($this->buffers[$k]??'');}
    protected function ReadPropertyInteger(string $k):int {return (int)($this->properties[$k]??0);}
    protected function GetValue(string $k):mixed {return $this->values[$k]??null;}
    protected function SetValue(string $k,mixed $v):void {
        $this->values[$k]=$v;
        $this->writes[]=['key'=>$k,'value'=>$v];
    }
}
function IPS_SetProperty(int $instance,string $property,string $value):void {
    if($instance!==24680) throw new RuntimeException('FOREIGN_INSTANCE_WRITE');
    $GLOBALS['shadowPropertyWrites'][]=[$instance,$property];
    $GLOBALS['shadowTestModule']->properties[$property]=$value;
}
function IPS_ApplyChanges(int $instance):void {
    if($instance!==24680) throw new RuntimeException('FOREIGN_INSTANCE_APPLY');
    $GLOBALS['shadowApplyCalls'][]=$instance;
    $GLOBALS['shadowTestModule']->ApplyChanges();
}
require_once dirname(__DIR__).'/JVPresenceIdentityShadow/module.php';
$module=new JVPresenceIdentityShadow();
$GLOBALS['shadowTestModule']=$module;
$GLOBALS['shadowPropertyWrites']=[];
$GLOBALS['shadowApplyCalls']=[];
$module->Create();
$module->ApplyChanges();
eq(count($module->values),7,'five existing outputs plus one combined HTML tile and copy-paste TestReport');
eq($module->timer['ReadLocal'],0,'disabled by default: no background polling');
eq(count($module->messages),0,'disabled default: no local VM_UPDATE subscriptions');
eq($GLOBALS['readCount'],0,'disabled by default: no external read');

$module->properties['Enabled']=true;
$module->properties['PhoneObserverInstanceID']=12345;
$module->ApplyChanges();
$separate=json_decode($module->values['EvidenceJSON'],true);
eq($separate['phoneLink']['state'],'DISABLED_SEPARATE_PROJECT',
    'phone observer integration is OFF by default');
eq($separate['phone'],[],'phone list stays empty even with manual instance ID');
eq(($GLOBALS['readById'][60001]??0),0,
    'no smartphone data read when phone project excluded');
eq($module->properties['EnablePhoneIntegration'],false,
    'phone opt-in stays disabled after applying main observer');
eq($module->timer['ReadLocal'],60000,'reads locally at most once per minute');
// Explicit later opt-in is separately tested but not required by the user.
$module->properties['EnablePhoneIntegration']=true;
$module->ApplyChanges();
$observed=json_decode($module->values['EvidenceJSON'],true);
eq($observed['p03']['p03']['commissioningReplayed'],true,
    'existing P03 fourfold proof observed without new test');
eq($observed['p03']['street']['directionMappingCertified'],true,
    'existing street direction observed without new test');
eq($observed['phone']['johannes'],'PHONE_PROPERTY_INDICATION',
    'phone indication read through existing adapter');
eq($observed['phone']['carina'],'PHONE_OUTSIDE_INDICATION',
    'Carina phone indication read without person inference');
eq($observed['people']['johannes'],'UNKNOWN',
    'identity stays unknown even if phone is present');
eq($observed['people']['carina'],'UNKNOWN',
    'identity stays unknown even if phone is away');
eq($observed['personPresenceMayBeChanged'],false,'P03 and phone cannot alter presence');
eq($observed['propertyEmptyMayBeCertified'],false,'P03 and phone cannot certify vacancy');
// First post-baseline sample legitimately changes the baselineInitialized flag.
$module->Refresh();
// EvidenceJSON is a live observation report: its elapsed-second values
// (e.g. quietForSeconds) MUST change across a clock-second boundary.
// Stable status, resident presence and the HTML tile must not be rewritten.
$stableWrites=static fn(array $writes):array=>array_values(
    array_filter($writes,static fn($w)=>!in_array(
        $w['key']??'', ['TestReport','EvidenceJSON'],true))
);
$beforeStable=$stableWrites($module->writes);
$beforeEvidence=json_decode($module->values['EvidenceJSON'],true);
for($i=0;$i<100;$i++)$module->Refresh();
eq($stableWrites($module->writes),$beforeStable,
    '100 unchanged polls never rewrite resident status or tiles even if diagnostic clock advances');
$afterEvidence=json_decode($module->values['EvidenceJSON'],true);
foreach(['people','residentDisplay','presenceDetected'] as $field)
    eq($afterEvidence[$field]??null,$beforeEvidence[$field]??null,
        'elapsed diagnostic seconds cannot change logical presence field '.$field);

// Local LCN and output-variable integration without any real Symcon writes.
// Sensor snapshots are initially BASELINED, not replayed as new movements.
$GLOBALS['meta'][7001]=['VariableType'=>0,'VariableChanged'=>time()-10];
$GLOBALS['meta'][7002]=['VariableType'=>0,'VariableChanged'=>time()-10];
$GLOBALS['meta'][7003]=['VariableType'=>0,'VariableChanged'=>time()-10];
$GLOBALS['meta'][7004]=['VariableType'=>1,'VariableChanged'=>time()-10];
$GLOBALS['raw'][7001]=true; // active when SHADOW attaches: NOT a new person
$GLOBALS['raw'][7002]=false;
$GLOBALS['raw'][7003]=false; // light off
$GLOBALS['raw'][7004]=0; // gate closed
$module->properties['SensorSources']=json_encode([
  ['Enabled'=>true,'SourceID'=>'flur','Name'=>'Flur','Kind'=>'MOTION',
   'Zone'=>'HOME','VariableID'=>7001],
  ['Enabled'=>true,'SourceID'=>'bad','Name'=>'Bad','Kind'=>'MOTION',
   'Zone'=>'HOME','VariableID'=>7002],
  ['Enabled'=>true,'SourceID'=>'licht','Name'=>'Licht','Kind'=>'LIGHT',
   'Zone'=>'HOME','VariableID'=>7003],
  ['Enabled'=>true,'SourceID'=>'tor','Name'=>'Garagentor','Kind'=>'GATE',
   'Zone'=>'PROPERTY_OTHER','VariableID'=>7004]
]);
$module->ApplyChanges();
eq(count($module->messages),4,'only selected local source variables subscribed');
$first=json_decode($module->values['EvidenceJSON'],true);
eq($first['localSensors']['motionSignals'],[],
   'GUS active at attach does not invent a new event');
eq($first['localSensors']['allConfiguredLightsOff'],true,
   'selected light OFF remains contextual only');
eq($first['propertyEmptyMayBeCertified'],false,
   'no all-dark garage-closed or GUS claim certifies vacancy');
// Emulate a real OFF then a fresh ON edge on two independent GUS.
$GLOBALS['raw'][7001]=false;
$GLOBALS['meta'][7001]['VariableChanged']=time()-1;
$module->Refresh();
$GLOBALS['raw'][7001]=true;
$GLOBALS['meta'][7001]['VariableChanged']=time();
$module->MessageSink(1,7001,10603,[]);
$one=json_decode($module->values['EvidenceJSON'],true);
eq($one['localSensors']['zones']['HOME']['activity'],'SINGLE_MOTION_HINT',
   'one independently changed GUS reports weak activity only');
$GLOBALS['raw'][7002]=true;
$GLOBALS['meta'][7002]['VariableChanged']=time();
$module->MessageSink(2,7002,10603,[]);
$two=json_decode($module->values['EvidenceJSON'],true);
eq($two['localSensors']['zones']['HOME']['activity'],'CORROBORATED_MOTION_HINT',
   'two separate GUS inside 60sec report corroborated motion');
eq($two['people']['johannes'],'UNKNOWN','two GUS do not identify Johannes');
eq($two['personPresenceMayBeChanged'],false,'GUS never commands core');
// Allow the first post-event normalization cycle, then verify stability.
$module->Refresh();
$writesAfterMotion=count($module->writes);
for($i=0;$i<100;$i++)$module->Refresh();
$postPollWrites=array_slice($module->writes,$writesAfterMotion);
eq(count($postPollWrites)<=4,true,
   '100 fixed LCN snapshots yield only bounded clock-dependent JSON updates');
eq(count(array_filter($postPollWrites,static fn($row)=>in_array(
    $row['key']??'',[
        'JohannesPresence','CarinaPresence','PresenceDetected'
    ],true))),0,
   'unchanged LCN snapshot never changes resident-presence variables; time-aware status is allowed');
$stableMotion=json_decode($module->values['EvidenceJSON'],true);
eq($stableMotion['localActivity']['totalMotionEdges24h']>=0,true,
   'background polling does not invent a new person or negative motion count');

$unchanged=count($module->writes);
$module->MessageSink(3,7002,10603,[]);
eq(count($module->writes),$unchanged,
   'same-value VM_UPDATE cannot generate extra output writes');
$module->MessageSink(4,53524,10603,[]);
eq(count($module->writes),$unchanged,
   'unregistered P03 output ignored as event sender');
$module->properties['SensorSources']=json_encode([
    ['Enabled'=>true,'SourceID'=>'flur','Name'=>'Flur','Kind'=>'MOTION',
     'Zone'=>'HOME','VariableID'=>7001]
]);
$module->ApplyChanges();
eq(count($module->messages),1,'removed LCN sources are unsubscribed on changes');
eq(isset($module->messages['7001:10603']),true,
   'remaining subscribed source preserved');
$module->properties['P03InstanceID']=22222;
$module->Refresh();
$wrong=json_decode($module->values['EvidenceJSON'],true);
eq(in_array('P03_SOURCE_INVALID_OR_UNAVAILABLE',$wrong['warnings'],true),true,
    'wrong Symcon module GUID rejected, no arbitrary file read');
eq($wrong['p03']['p03']['homeToWork'],0,'untrusted module cannot fake proof');

$module->properties['P03InstanceID']=53879;
$GLOBALS['meta'][53524]['VariableType']=0;
$module->Refresh();
$wrong=json_decode($module->values['EvidenceJSON'],true);
eq(in_array('P03_SOURCE_INVALID_OR_UNAVAILABLE',$wrong['warnings'],true),true,
    'wrong P03 string variable type rejected');
$GLOBALS['meta'][53524]['VariableType']=3;

$module->properties['PhoneObserverInstanceID']=22222;
$module->Refresh();
$wrong=json_decode($module->values['EvidenceJSON'],true);
eq(in_array('PHONE_SOURCE_INVALID_OR_UNAVAILABLE',$wrong['warnings'],true),true,
    'wrong phone source module rejected');
$module->properties['PhoneObserverInstanceID']=12345;

$GLOBALS['raw'][60001]='{"schema":1,"authority":"PRESENCE_CORE","people":[]}';
$module->Refresh();
$wrong=json_decode($module->values['EvidenceJSON'],true);
eq(in_array('PHONE_SOURCE_INVALID_OR_UNAVAILABLE',$wrong['warnings'],true),true,
    'untrusted phone report rejected even with valid shape');

$module->properties['Enabled']=false;
$module->ApplyChanges();
eq($module->timer['ReadLocal'],0,'disabling shadow stops timer');
eq(count($module->messages),0,'disabled instance releases all GUS subscriptions');
eq(count($module->values),7,'module registers five historical outputs plus tile and handoff');
$form=json_decode($module->GetConfigurationForm(),true);
eq(count($form['actions']),9,'eight existing read-only actions plus one requested copy-paste TestReport refresh');
// A one-click LCN inventory must work even with Shadow DISABLED and
// must not activate configured GUS, alter P03, or read camera credentials.
$GLOBALS['instances'][8001]=['ModuleInfo'=>[
    'ModuleID'=>'{2D871359-14D8-493F-9B01-26432E3A710F}']];
$GLOBALS['instances'][8002]=['ModuleInfo'=>[
    'ModuleID'=>'{2D871359-14D8-493F-9B01-26432E3A710F}']];
$GLOBALS['names']=[
    8001=>'Bewegungsmelder EG Wohnen Küche',7101=>'Status',
    8002=>'Bewegungsmeder EG Wohnen Kamin',42452=>'Status'
];
$GLOBALS['objects']=[7101=>['ParentID'=>8001],42452=>['ParentID'=>8002]];
$GLOBALS['unitChildren']=[8001=>[7101],8002=>[42452]];
$GLOBALS['meta'][7101]=['VariableType'=>0];
$GLOBALS['meta'][42452]=['VariableType'=>0];
$module->properties['SensorSources']=json_encode([
    ['Enabled'=>false,'SourceID'=>'eg_wohnen_kamin','Name'=>'Kamin',
      'Kind'=>'MOTION_KAMIN','Zone'=>'HOME','VariableID'=>42452],
    ['Enabled'=>false,'SourceID'=>'eg_flur','Name'=>'Flur',
      'Kind'=>'MOTION','Zone'=>'HOME','VariableID'=>39155]
]);
$readsBefore=$GLOBALS['readCount'];
$module->AuditLocalLCNInventory();
$audit=json_decode($module->values['EvidenceJSON'],true);
eq($audit['authority'],'SHADOW_LOCAL_INVENTORY_ONLY',
    'one-click scan stays inventory-only');
eq($audit['configuredCandidates'][0]['valid'],true,
    'historic real Kamin source ID validated locally');
eq($audit['configuredCandidates'][1]['error'],'VARIABLE_MISSING',
    'stale source ID not enabled or assumed correct');
eq(count($audit['detectedLCNMotionSensors']),2,
    'discovery includes unconfigured kitchen GUS');
eq($audit['detectedLCNMotionSensors'][1]['variableId'],7101,
    'kitchen variable ID exposed without new walking test');
eq($audit['configurationChanged'],false,
    'audit did not reconfigure any Symcon source');
eq($audit['devicesChanged'],false,
    'audit never controls cameras or LCN');
eq($GLOBALS['readCount'],$readsBefore,
    'inventory audit reads metadata, not raw sensor or camera values');
eq($module->timer['ReadLocal'],0,
    'inventory audit does not start disabled shadow observer');
eq(count($module->messages),0,
    'inventory audit does not subscribe to local GUS');


// Already provided 13 Oct live inventory: all fifteen status variables.
// Check a one-click import is limited to this Shadow instance, even if the
// observer itself stays globally OFF and without any extra field walk tests.
foreach(JVLCNGUSInventory::KNOWN as $known) {
    $unit=$known['unit'];
    $id=$known['id'];
    $GLOBALS['instances'][$unit]=['ModuleInfo'=>[
        'ModuleID'=>JVLCNGUSInventory::LCN_UNIT_GUID
    ]];
    $GLOBALS['objects'][$id]=['ParentID'=>$unit];
    $GLOBALS['meta'][$id]=['VariableType'=>0,'VariableChanged'=>time()-60];
    $GLOBALS['names'][$unit]=$known['name'];
    $GLOBALS['names'][$id]='Status';
    $GLOBALS['raw'][$id]=false;
}
$module->ImportVerifiedLCNGUS();
$import=json_decode($module->values['EvidenceJSON'],true);
eq($import['authority'],'SHADOW_LOCAL_GUS_SOURCES_CONFIGURED',
    'import current 15 GUS in Shadow-only settings');
eq($import['importedCount'],15,'user-supplied real 15 GUS IDs are all found');
eq($import['configurationChanged'],true,'first one-click import changes own config');
eq(count($GLOBALS['shadowPropertyWrites']),1,'exactly one property write for full import');
eq(count($GLOBALS['shadowApplyCalls']),1,'exactly one own-instance apply');
eq(count($module->messages),0,'global SHADOW still OFF; no subscriptions');
eq($module->timer['ReadLocal'],0,'global SHADOW OFF; no timer');
eq($module->properties['Enabled'],false,'global enable state unchanged');
$rows=json_decode($module->properties['SensorSources'],true);
eq(count($rows),15,'all 15 GUS imported; no duplicate pre-existing six');
eq(count(array_filter($rows,static fn($r)=>$r['Enabled'])),15,
    'fifteen per-source read flags ready, global observer still OFF');
$kitchen=array_values(array_filter($rows,
    static fn($r)=>($r['VariableID']??0)===51040));
eq($kitchen[0]['Kind'],'MOTION_KITCHEN',
    'newly discovered kitchen #51040 is Kamin corroborator');
$kamin=array_values(array_filter($rows,
    static fn($r)=>($r['VariableID']??0)===42452));
eq($kamin[0]['Kind'],'MOTION_KAMIN','Kamin special rule retained');
$module->ImportVerifiedLCNGUS();
$again=json_decode($module->values['EvidenceJSON'],true);
eq($again['configurationChanged'],false,'repeat button idempotent');
eq(count($GLOBALS['shadowPropertyWrites']),1,
    'repeat import has no additional writes');
eq(count($GLOBALS['shadowApplyCalls']),1,
    'repeat import has no second IPS_ApplyChanges');

$sourceBefore=$module->properties['SensorSources'];
$GLOBALS['names'][51040]='Alarm'; // an unexpected rename is a blocker
$module->ImportVerifiedLCNGUS();
$blocked=json_decode($module->values['EvidenceJSON'],true);
eq($blocked['authority'],'SHADOW_LOCAL_GUS_IMPORT_REJECTED',
   'renamed kitchen blocks importing everything');
eq($module->properties['SensorSources'],$sourceBefore,
   'failed import preserves all source configuration');
eq(count($GLOBALS['shadowPropertyWrites']),1,
   'failed import makes zero changes');
$GLOBALS['names'][51040]='Status';

// Previously phone=[] with unconfigured ID 0. An existing UNIQUE phone
// observer must be found automatically with no network scan or IPS writes.
$GLOBALS['raw'][60001]=json_encode(['schema'=>1,
    'authority'=>'PHONE_OBSERVATIONS_ONLY',
    'people'=>[
      ['id'=>'johannes','phoneStatus'=>'PHONE_PROPERTY_INDICATION'],
      ['id'=>'carina','phoneStatus'=>'PHONE_UNKNOWN']
    ]]);
$GLOBALS['ids']['53879:P03VerifiedTransfers']=62001;
$GLOBALS['ids']['53879:P03ProofState']=62002;
$GLOBALS['meta'][62001]=['VariableType'=>1];
$GLOBALS['meta'][62002]=['VariableType'=>3];
$GLOBALS['raw'][62001]=4;
$GLOBALS['raw'][62002]='VERIFIED';
$module->properties['P03InstanceID']=53879;
$module->properties['PhoneObserverInstanceID']=0;
$module->properties['Enabled']=true;
$module->ApplyChanges();
$linked=json_decode($module->values['EvidenceJSON'],true);
eq($linked['phoneLink']['state'],'AUTO_LINKED',
    'unique phone observer recognized with zero configured ID');
eq($linked['phoneLink']['instanceId'],12345,
    'phone source id from existing matching Symcon module');
eq($linked['phone']['johannes'],'PHONE_PROPERTY_INDICATION',
    'existing smartphone evidence read automatically');
eq($linked['phone']['carina'],'PHONE_UNKNOWN',
    'unknown smartphone remains unknown');
eq($linked['people']['johannes'],'UNKNOWN',
    'phone hint does not invent Johannes at home');
eq($linked['personPresenceMayBeChanged'],false,
    'automatic phone link never changes PresenceCore');
eq($linked['p03Live']['verifiedProofCounter'],4,
    'live P03 counter read independently from old protocol');
eq($linked['p03Live']['proofState'],'VERIFIED',
    'read-only P03 proof state');
eq($linked['p03Live']['countIsNumberOfPeople'],false,
    'four historical proof events cannot be counted as four humans');
eq($module->properties['PhoneObserverInstanceID'],0,
    'automatic reader never writes its own phone linkage property');

$GLOBALS['phoneObserverCandidates']=[];
$module->Refresh();
$missing=json_decode($module->values['EvidenceJSON'],true);
eq($missing['phoneLink']['state'],'NO_OBSERVER_FOUND',
    'missing observer is surfaced rather than silently claiming absent phone');
eq($missing['phone'],[],'missing observer produces no fake phone presence');

$GLOBALS['instances'][12346]=['ModuleInfo'=>[
   'ModuleID'=>'{B5BF0266-A4D1-40A0-AAC8-97E9C24C4173}'
]];
$GLOBALS['phoneObserverCandidates']=[12345,12346];
$module->Refresh();
$multi=json_decode($module->values['EvidenceJSON'],true);
eq($multi['phoneLink']['state'],'AMBIGUOUS_MULTIPLE',
   'two existing phone observers not silently merged');
eq($multi['phone'],[],'ambiguous sources not read');
$module->properties['PhoneObserverInstanceID']=12345;
$module->Refresh();
$manual=json_decode($module->values['EvidenceJSON'],true);
eq($manual['phoneLink']['state'],'MANUAL_LINK',
   'explicit owner choice overrides ambiguous auto selection');
eq($manual['phone']['johannes'],'PHONE_PROPERTY_INDICATION',
   'user-selected correct module read');
$GLOBALS['phoneObserverCandidates']=[12345];
$module->properties['PhoneObserverInstanceID']=0;
$module->Refresh();
$normal=json_decode($module->values['EvidenceJSON'],true);
eq($normal['phoneLink']['state'],'AUTO_LINKED','auto selection resumes');

$GLOBALS['raw'][62001]=5;
$module->Refresh();
$updated=json_decode($module->values['EvidenceJSON'],true);
eq($updated['p03Live']['verifiedProofCounter'],5,
    'new P03 verification counter visible as proof-only diagnostic');
eq($updated['p03']['p03']['homeToWork'],2,
    'historic archive does not fabricate a new direction event');
eq($updated['p03Live']['countIsNumberOfPeople'],false,
    'incremental P03 proof count never becomes headcount');
$GLOBALS['raw'][62001]=4;
// Turning integration back OFF must cut all phone reads, including
// previously known observer sources and future automatic module discovery.
$module->properties['EnablePhoneIntegration']=false;
$readsBeforePhoneOff=$GLOBALS['readById'][60001]??0;
$module->Refresh();
$off=json_decode($module->values['EvidenceJSON'],true);
eq($off['phoneLink']['state'],'DISABLED_SEPARATE_PROJECT',
   'explicit OFF wins over old auto-link');
eq($off['phone'],[],'previous phone evidence immediately removed');
eq(($GLOBALS['readById'][60001]??0),$readsBeforePhoneOff,
   'no more smartphone reads after decoupling');
eq($off['people'],[],'no person objects from disconnected phones');
eq($off['localSensors']['sourceCount'],15,
   'disabling phones never disables 15 local GUS');
eq(($off['parallelPersonPassage']['individualPeopleCount']??null),'UNKNOWN',
    'live P03 proof does not prove exactly one vs two people side by side');
eq(($off['parallelPersonPassage']['propertyEmptyMayBeCertified']??null),false,
    'even shared exit must not certify empty premises');
eq(($off['parallelPersonPassage']['liveStreetPersonExitCountAvailable']??null),false,
    'street direction training alone is not actual street crossing evidence');
eq($off['localActivity']['personCountCertified'],false,
   'motion events do not morph into human numbers');
eq($off['localActivity']['propertyEmptyMayBeCertified'],false,
   'motion history never certifies empty property');

$module->properties['Enabled']=false;
$module->ApplyChanges();

// Shadow integration: archived commissioning is BASELINE, never a current
// HOME/WORK person event. A newly appended P03 proof appears once.
$module->buffers['P03IncrementalTimeline']='{}';
$module->attributes['PersistP03IncrementalTimeline']='{}'; // explicit test-fixture reset
$module->properties['EnablePhoneIntegration']=false;
$module->properties['Enabled']=true;
$GLOBALS['raw'][53524]=$historic;
$module->ApplyChanges();
$p03Initial=json_decode($module->values['EvidenceJSON'],true);
eq($p03Initial['p03LiveTimeline']['baselineOnly'],true,
    'historic field-test log baseline not new occupancy');
eq($p03Initial['p03LiveTimeline']['directionProofs24h'],
    ['HOME_TO_LAGER'=>0,'LAGER_TO_HOME'=>0],
    'do not replay yesterday as today movement');
eq($p03Initial['phoneLink']['state'],'DISABLED_SEPARATE_PROJECT',
    'phone remains disabled regardless of P03 proof');
$localBerlin=(new DateTimeImmutable('now',
    new DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
$GLOBALS['raw'][53524]=$historic."\n[$localBerlin] "
    .'P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_JV_THEN_WORK';
$module->Refresh();
$actual=json_decode($module->values['EvidenceJSON'],true);
eq($actual['p03LiveTimeline']['newDirectionProofsThisRead'],1,
    'new P03 log append reflected in shadow');
eq($actual['p03LiveTimeline']['directionProofs24h']['HOME_TO_LAGER'],1,
    'live directional proof counted exactly once');
eq($actual['recentCombinedEvidence'][0]['kind'],'P03_ZONE_DIRECTION',
    'P03 event included in combined timeline');
eq($actual['recentCombinedEvidence'][0]['identity'],'UNKNOWN',
    'combined timeline cannot invent a named person');
eq($actual['p03LiveTimeline']['personCountCertified'],false,
    'verified crossing is not number of people');
$module->Refresh();
$repeat=json_decode($module->values['EvidenceJSON'],true);
eq($repeat['p03LiveTimeline']['directionProofs24h']['HOME_TO_LAGER'],1,
    'same P03 protocol is idempotent across polls');
eq($repeat['p03LiveTimeline']['newDirectionProofsThisRead'],0,
    'unchanged P03 protocol has zero new entries');
eq($repeat['localSensors']['sourceCount'],15,
    'new P03 timeline cannot remove existing GUS');
eq($repeat['houseObservation']['observedHomeGUS'],15,
    'house evaluator retains fifteen existing LCN motion sensors');
eq($repeat['houseObservation']['sourcesHealthy'],true,
    'house sensor report valid without new camera or WLAN checks');
eq($repeat['houseObservation']['recentP03Direction'],'HOME_TO_LAGER',
    'real appended P03 OUT appears as current directional house hint');
eq($repeat['houseObservation']['houseStatus'],'HOME_EXIT_DIRECTION_HINT',
    'someone walking HOME to WORK does not certify the house empty');
eq($repeat['houseObservation']['propertyEmptyMayBeCertified'],false,
    'leaving house toward storage cannot establish property absence');
eq($repeat['houseObservation']['alarmMayBeControlled'],false,
    'read-only home observation cannot arm existing alarm');
eq($repeat['houseObservation']['personCount'],'UNKNOWN',
    'even 15 GUS and P03 cannot count people');
eq($repeat['houseObservation']['absenceControlState'],'NOT_CERTIFIED',
    'there is still no automatic AWAY certainty');
eq($repeat['phoneLink']['state'],'DISABLED_SEPARATE_PROJECT',
    'WLAN project remains completely separate');
eq(count($module->values),7,
    'home observation extends existing JSON with zero new variables');


$module->properties['Enabled']=false;
$module->ApplyChanges();

// Property-boundary inventory only reads existing object metadata, NEVER
// variable values and NEVER creates a second WLAN/project category.
$GLOBALS['meta'][8888]=['VariableType'=>0,'VariableChanged'=>time()-10];
$GLOBALS['meta'][8889]=['VariableType'=>0,'VariableChanged'=>time()-10];
$GLOBALS['meta'][8890]=['VariableType'=>0,'VariableChanged'=>time()-10];
$GLOBALS['meta'][8891]=['VariableType'=>0,'VariableChanged'=>time()-10];
$GLOBALS['objects'][8888]=['ParentID'=>9901];
$GLOBALS['objects'][8889]=['ParentID'=>9902];
$GLOBALS['objects'][8890]=['ParentID'=>9903];
$GLOBALS['objects'][8891]=['ParentID'=>9904];
$GLOBALS['objects'][9901]=['ParentID'=>9911];
$GLOBALS['objects'][9902]=['ParentID'=>9911];
$GLOBALS['objects'][9903]=['ParentID'=>9911];
$GLOBALS['objects'][9904]=['ParentID'=>9911];
$GLOBALS['names'][8888]='Status';
$GLOBALS['names'][8889]='Status';
$GLOBALS['names'][8890]='WLAN erreichbar';
$GLOBALS['names'][8891]='Status';
$GLOBALS['names'][9901]='Garagentor Hof';
$GLOBALS['names'][9902]='Haustür Kontakt';
$GLOBALS['names'][9903]='WLAN Johannes';
$GLOBALS['names'][9904]='Bewegungsmelder Büro';
$GLOBALS['names'][9911]='EG / Hof';
$GLOBALS['candidateVariableList']=[8888,8889,8890,8891];
$readsBeforeAudit=$GLOBALS['readCount'];
$propsBeforeAudit=$module->properties;
$writesBeforeAudit=count($GLOBALS['shadowPropertyWrites']);
$module->AuditExistingExitCandidates();
$inventory=json_decode($module->values['EvidenceJSON'],true);
eq($inventory['authority'],'READ_ONLY_EXIT_SOURCE_CANDIDATES',
    'new read-only access variable metadata scan');
eq($inventory['candidateCount'],2,
    'inventory finds existing gate and door but not WLAN/GUS');
eq(array_column($inventory['listedCandidates'],'variableId'),[8889,8888],
    'door contact ranked before generic gate status, exact existing IDs');
eq($inventory['configurationChanged'],false,
    'metadata scan does not alter any observer settings');
eq($inventory['devicesChanged'],false,
    'metadata scan does not actuate door/gate');
eq($GLOBALS['readCount'],$readsBeforeAudit,
    'read-only inventory reads no device values');
eq($module->properties,$propsBeforeAudit,
    'source configuration is unchanged after scan');
eq(count($GLOBALS['shadowPropertyWrites']),$writesBeforeAudit,
    'inventory does not invoke IPS_SetProperty');
eq(count($module->values),7,
    'metadata-only inventory creates no additional Symcon variables beyond the two explicitly requested displays');

// Only explicitly chosen existing contact/gate/vehicle signals can be read,
// and after first baseline they are still status hints, NEVER exits.
$sourceConfig=json_decode($module->properties['SensorSources'],true);
$sourceConfig[]=['Enabled'=>true,'SourceID'=>'front_door','Name'=>'Haustür Kontakt',
    'Kind'=>'CONTACT','Zone'=>'HOME','VariableID'=>8889];
$sourceConfig[]=['Enabled'=>true,'SourceID'=>'yard_gate','Name'=>'Garagentor Hof',
    'Kind'=>'GATE','Zone'=>'PROPERTY_OTHER','VariableID'=>8888];
$module->properties['SensorSources']=json_encode($sourceConfig);
$GLOBALS['raw'][8888]=false;
$GLOBALS['raw'][8889]=false;
$module->properties['Enabled']=true;
$module->ApplyChanges();
$initialBoundary=json_decode($module->values['EvidenceJSON'],true);
eq($initialBoundary['boundaryEvidence']['configuredBoundarySignals'],2,
    'two existing boundary status variables can be observed');
eq($initialBoundary['boundaryEvidence']['boundaryChanges24h'],0,
    'no contact movement invented when attaching new sources');
eq($initialBoundary['boundaryEvidence']['coverageState'],'PARTIAL_UNVERIFIED',
    'selected garage and door do not certify complete property exits');
eq($initialBoundary['houseObservation']['observedHomeGUS'],15,
    '15 house GUS preserved when adding two read-only sources');
eq($initialBoundary['phoneLink']['state'],'DISABLED_SEPARATE_PROJECT',
    'boundary observation does not activate WLAN');

$GLOBALS['raw'][8889]=true;
$expectedDoorChangeAt=time();
$GLOBALS['meta'][8889]['VariableChanged']=$expectedDoorChangeAt;
$module->MessageSink(1,8889,10603,[]);
$changedBoundary=json_decode($module->values['EvidenceJSON'],true);
eq($changedBoundary['boundaryEvidence']['boundaryChanges24h'],1,
    'real door status update appears once');
eq($changedBoundary['boundaryEvidence']['lastBoundaryChangeAt'],$expectedDoorChangeAt,
    'door status timestamp reflected');
eq($changedBoundary['boundaryEvidence']['exitDirectionCertified'],false,
    'Boolean door status cannot prove exit direction');
eq($changedBoundary['boundaryEvidence']['propertyEmptyMayBeCertified'],false,
    'door event never books away');
eq($changedBoundary['boundaryEvidence']['alarmMayBeControlled'],false,
    'gate/door evidence never arms alarm');
eq($changedBoundary['houseObservation']['absenceControlState'],'NOT_CERTIFIED',
    'house absence remains uncertified even with door state transition');
$boundaryEvents=array_values(array_filter(
    $changedBoundary['recentCombinedEvidence'],
    static fn($row)=>($row['kind']??'')==='BOUNDARY_STATUS_CHANGE'
));
eq(count($boundaryEvents),1,
    'boundary contact appears in combined read-only chronology');
eq($boundaryEvents[0]['direction'],'UNKNOWN',
    'contact event gets no invented IN/OUT direction');
$module->MessageSink(1,8889,10603,[]);
$repeatBoundary=json_decode($module->values['EvidenceJSON'],true);
eq($repeatBoundary['boundaryEvidence']['boundaryChanges24h'],1,
    'same contact update not counted twice');
eq(count($module->values),7,
    'no further Symcon variables after boundary integration');
$module->properties['Enabled']=false;
$module->ApplyChanges();

echo "P03 historic replay & passive shadow PASS ($checks assertions)\n";
