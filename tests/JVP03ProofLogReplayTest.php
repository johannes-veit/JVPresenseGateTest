<?php
declare(strict_types=1);
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
function IPS_GetInstance(int $id):array {
    return $GLOBALS['instances'][$id]??throw new RuntimeException('not found');
}
function IPS_GetObjectIDByIdent(string $ident,int $id):int|false {
    return $GLOBALS['ids'][$id.':'.$ident]??false;
}
function IPS_VariableExists(int $id):bool {return isset($GLOBALS['meta'][$id]);}
function IPS_GetVariable(int $id):array {return $GLOBALS['meta'][$id];}
function GetValue(int $id):mixed {
    $GLOBALS['readCount']++;
    return $GLOBALS['raw'][$id];
}
class IPSModule {
    public array $properties=[];
    public array $values=[];
    public array $timer=[];
    public array $writes=[];
    public array $buffers=[];
    public function Create():void {}
    public function ApplyChanges():void {}
    protected function RegisterPropertyBoolean(string $k,bool $v):void {$this->properties[$k]=$v;}
    protected function RegisterPropertyInteger(string $k,int $v):void {$this->properties[$k]=$v;}
    protected function RegisterPropertyString(string $k,string $v):void {$this->properties[$k]=$v;}
    protected function ReadPropertyString(string $k):string {return (string)($this->properties[$k]??'');}
    protected function RegisterVariableString(string $k,string $n,string $p='',int $o=0):void {$this->values[$k]='';}
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
require_once dirname(__DIR__).'/JVPresenceIdentityShadow/module.php';
$module=new JVPresenceIdentityShadow();
$module->Create();
$module->ApplyChanges();
eq(count($module->values),2,'two variables total');
eq($module->timer['ReadLocal'],0,'disabled by default: no background polling');
eq($GLOBALS['readCount'],0,'disabled by default: no external read');

$module->properties['Enabled']=true;
$module->properties['PhoneObserverInstanceID']=12345;
$module->ApplyChanges();
eq($module->timer['ReadLocal'],60000,'reads locally at most once per minute');
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
$writeCount=count($module->writes);
for($i=0;$i<100;$i++)$module->Refresh();
eq(count($module->writes),$writeCount,'100 unchanged refreshes add zero variable writes');

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
$module->Refresh();
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
$module->Refresh();
$one=json_decode($module->values['EvidenceJSON'],true);
eq($one['localSensors']['zones']['HOME']['activity'],'SINGLE_MOTION_HINT',
   'one independently changed GUS reports weak activity only');
$GLOBALS['raw'][7002]=true;
$GLOBALS['meta'][7002]['VariableChanged']=time();
$module->Refresh();
$two=json_decode($module->values['EvidenceJSON'],true);
eq($two['localSensors']['zones']['HOME']['activity'],'CORROBORATED_MOTION_HINT',
   'two separate GUS inside 60sec report corroborated motion');
eq($two['people']['johannes'],'UNKNOWN','two GUS do not identify Johannes');
eq($two['personPresenceMayBeChanged'],false,'GUS never commands core');
$writesAfterMotion=count($module->writes);
for($i=0;$i<100;$i++)$module->Refresh();
eq(count($module->writes),$writesAfterMotion,
   '100 fixed LCN snapshots do not create output write storms');

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
eq(count($module->values),2,'module never creates more than 2 variables');
$form=json_decode($module->GetConfigurationForm(),true);
eq(count($form['actions']),1,'one read-only diagnostic button');
echo "P03 historic replay & passive shadow PASS ($checks assertions)\n";
