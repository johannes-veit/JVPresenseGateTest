<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPhoneEvidenceEngine.php';
function check(bool $ok, string $msg): void {
    if (!$ok) throw new RuntimeException('FAIL: '.$msg);
    echo "PASS: ".$msg."\n";
}
$p=[
    ['PersonID'=>'johannes','Name'=>'Johannes','Enabled'=>true],
    ['PersonID'=>'carina','Name'=>'Carina','Enabled'=>true]
];
$d=[
    ['DeviceID'=>'xiaomi13t','PersonID'=>'johannes','Name'=>'Xiaomi 13T',
      'Enabled'=>true,'MAC'=>'','WiFiVarID'=>101,'GeoVarID'=>102],
    ['DeviceID'=>'iphone','PersonID'=>'carina','Name'=>'iPhone',
      'Enabled'=>true,'MAC'=>'','WiFiVarID'=>103,'GeoVarID'=>104]
];
$t=100000;
$evaluate=static fn(array $in,array $devices=$d):array=>
    JVPhoneEvidenceEngine::evaluate($p,$devices,$in,$t,180,900);
$a=$evaluate([]);
check(count($a['people'])===2 && count($a['devices'])===2,'two persistent person profiles and device rows');
check($a['people'][0]['phoneStatus']==='PHONE_UNKNOWN','no input means UNKNOWN not AWAY');
check(!$a['personPresenceMayBeChanged'] && !$a['propertyEmptyMayBeCertified']
    && $a['people'][0]['personLocation']==='UNKNOWN','phone never sets person presence or certifies vacancy');

$a=$evaluate(['xiaomi13t'=>['wifi'=>['value'=>true,'updatedAt'=>$t]]]);
check($a['people'][0]['phoneStatus']==='PHONE_PROPERTY_INDICATION'
    && $a['people'][0]['personLocation']==='UNKNOWN','Wi-Fi CONNECTED indicates phone only');
$a=$evaluate(['xiaomi13t'=>['wifi'=>['value'=>false,'updatedAt'=>$t]]]);
check($a['people'][0]['phoneStatus']==='PHONE_UNKNOWN','Wi-Fi disconnect is not absence');
$a=$evaluate(['iphone'=>['geo'=>['value'=>'OUTSIDE','updatedAt'=>$t]]]);
check($a['people'][1]['phoneStatus']==='PHONE_OUTSIDE_INDICATION'
    && $a['people'][1]['personLocation']==='UNKNOWN','GPS OUTSIDE is not the person leaving');
$a=$evaluate(['iphone'=>['geo'=>['value'=>'PROPERTY','updatedAt'=>$t]]]);
check($a['people'][1]['phoneStatus']==='PHONE_PROPERTY_INDICATION','GPS PROPERTY is phone indication');
$a=$evaluate(['xiaomi13t'=>[
    'wifi'=>['value'=>true,'updatedAt'=>$t],
    'geo'=>['value'=>'OUTSIDE','updatedAt'=>$t]
]]);
check($a['people'][0]['phoneStatus']==='PHONE_CONFLICT','simultaneous GPS-away and Wi-Fi-connected fail closed');
$a=$evaluate(['xiaomi13t'=>['wifi'=>['value'=>true,'updatedAt'=>$t-181]]]);
check($a['people'][0]['phoneStatus']==='PHONE_UNKNOWN'
    && $a['devices'][0]['wifi']==='STALE','stale association cannot prove phone presence');
$a=$evaluate(['xiaomi13t'=>['wifi'=>['value'=>true,'updatedAt'=>$t+30]]]);
check($a['people'][0]['phoneStatus']==='PHONE_UNKNOWN','future-dated source ignored');
$a=$evaluate(['iphone'=>['geo'=>['value'=>'HOME','updatedAt'=>$t]]]);
check($a['people'][1]['phoneStatus']==='PHONE_UNKNOWN'
    && $a['devices'][1]['geofence']==='INVALID','geofence accepts strict schema only');

$replaced=$d;
$replaced[0]['DeviceID']='replacement_new_android';
$replaced[0]['Name']='Neues Handy';
$a=$evaluate(['replacement_new_android'=>['wifi'=>['value'=>true,'updatedAt'=>$t]]],$replaced);
check($a['people'][0]['id']==='johannes'
    && $a['people'][0]['phoneStatus']==='PHONE_PROPERTY_INDICATION'
    && $a['devices'][0]['name']==='Neues Handy','device swap retains stable person identity');
$a=$evaluate(['xiaomi13t'=>['wifi'=>['value'=>true,'updatedAt'=>$t]]],$replaced);
check($a['people'][0]['phoneStatus']==='PHONE_UNKNOWN','old device is not recognized after replacement');

$twophones=$d;
$twophones[]=['DeviceID'=>'spare','PersonID'=>'johannes','Name'=>'Ersatz',
              'Enabled'=>true,'MAC'=>'','WiFiVarID'=>105,'GeoVarID'=>106];
$a=$evaluate([
    'xiaomi13t'=>['geo'=>['value'=>'OUTSIDE','updatedAt'=>$t]],
    'spare'=>['wifi'=>['value'=>true,'updatedAt'=>$t]]
],$twophones);
check($a['people'][0]['phoneStatus']==='PHONE_CONFLICT','different same-owner phones produce conflict, never person whereabouts');
$bad=$d;
$bad[1]['DeviceID']='xiaomi13t';
check(count($evaluate([],$bad)['errors'])>0,'duplicate device ID blocks all evidence');
$bad=$d;
$bad[1]['WiFiVarID']=101;
check(count($evaluate([],$bad)['errors'])>0,'shared Wi-Fi variable blocks all evidence');
$bad=$d;
$bad[1]['GeoVarID']=101;
check(count($evaluate([],$bad)['errors'])>0,'GPS/Wi-Fi variable reuse across devices rejected');
$bad=$d;
$bad[0]['MAC']='aa:bb:cc:dd:ee:ff';
$bad[1]['MAC']='aa:bb:cc:dd:ee:ff';
check(count($evaluate([],$bad)['errors'])>0,'same MAC bound to two phones rejected');
$bad=$d;
$bad[0]['PersonID']='unknown-person';
check(count($evaluate([],$bad)['errors'])>0,'unregistered person cannot inherit smartphone');
$bad=$d;
$bad[0]['MAC']='THIS-IS-NOT-A-MAC';
check(count($evaluate([],$bad)['errors'])>0,'invalid MAC rejected');

// Isolated Symcon module lifecycle and variable-access simulation.
$GLOBALS['vars']=[];
$GLOBALS['meta']=[];
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['meta'][$id]); }
function IPS_GetVariable(int $id): array { return $GLOBALS['meta'][$id]; }
function GetValue(int $id): mixed { return $GLOBALS['vars'][$id]; }
class IPSModule {
    public int $InstanceID=60000;
    public array $properties=[];
    public array $values=[];
    public array $timer=[];
    public function Create():void {}
    public function ApplyChanges():void {}
    protected function RegisterPropertyBoolean(string $k,bool $v):void {$this->properties[$k]=$v;}
    protected function RegisterPropertyInteger(string $k,int $v):void {$this->properties[$k]=$v;}
    protected function RegisterPropertyString(string $k,string $v):void {$this->properties[$k]=$v;}
    protected function RegisterVariableString(string $k,string $n,string $p='',int $o=0):void {$this->values[$k]='';}
    protected function RegisterTimer(string $k,int $ms,string $script):void {$this->timer[$k]=$ms;}
    protected function SetTimerInterval(string $k,int $ms):void {$this->timer[$k]=$ms;}
    protected function ReadPropertyBoolean(string $k):bool {return (bool)($this->properties[$k]??false);}
    protected function ReadPropertyInteger(string $k):int {return (int)($this->properties[$k]??0);}
    protected function ReadPropertyString(string $k):string {return (string)($this->properties[$k]??'');}
    protected function SetValue(string $k,mixed $v):void {$this->values[$k]=$v;}
}
require_once dirname(__DIR__).'/JVPresencePhoneObserver/module.php';
$mod=new JVPresencePhoneObserver();
$mod->Create();
$mod->ApplyChanges();
check(count($mod->values)===2 && $mod->timer['Poll']===60000,
      'only 2 variables, single passive 60s timer');
$report=json_decode($mod->values['EvidenceJSON'],true);
check(($report['people'][0]['id']??'')==='johannes'
    && ($report['people'][1]['id']??'')==='carina'
    && !$report['propertyEmptyMayBeCertified'],
    'real module default no physical presence or home vacancy');
$mod->properties['Devices']=json_encode([
 ['DeviceID'=>'newandroid','PersonID'=>'johannes','Name'=>'Neues Android',
  'Enabled'=>true,'MAC'=>'','WiFiVarID'=>701,'GeoVarID'=>0],
 ['DeviceID'=>'iphone','PersonID'=>'carina','Name'=>'iPhone',
  'Enabled'=>true,'MAC'=>'','WiFiVarID'=>0,'GeoVarID'=>0]
]);
$GLOBALS['meta'][701]=['VariableType'=>0,'VariableUpdated'=>time()];
$GLOBALS['vars'][701]=true;
$mod->RefreshEvidence();
$report=json_decode($mod->values['EvidenceJSON'],true);
check(($report['people'][0]['phoneStatus']??'')==='PHONE_PROPERTY_INDICATION'
    && ($report['people'][0]['personLocation']??'')==='UNKNOWN',
    'module reads valid fresh Boolean AP input but cannot change person');
$GLOBALS['meta'][701]['VariableUpdated']=time()-500;
$mod->RefreshEvidence();
$report=json_decode($mod->values['EvidenceJSON'],true);
check(($report['people'][0]['phoneStatus']??'')==='PHONE_UNKNOWN',
    'actual module stale input fails closed');
$GLOBALS['meta'][701]=['VariableType'=>3,'VariableUpdated'=>time()];
$mod->RefreshEvidence();
$report=json_decode($mod->values['EvidenceJSON'],true);
check(($report['devices'][0]['wifi']??'')==='INVALID',
    'wrong source type is rejected');
$mod->properties['Enabled']=false;
$mod->ApplyChanges();
check($mod->timer['Poll']===0
    && json_decode($mod->values['EvidenceJSON'],true)['authority']==='DISABLED',
    'disabling observer stops timer without affecting other modules');
$form=json_decode($mod->GetConfigurationForm(),true);
check(count($form['actions'])===2
    && count($form['elements'])>=5,'module configuration form loads with actions and editing');
$mod->RunSimulation();
check(str_contains($mod->values['Status'],'PASS'),'safe built-in simulation');
echo "PHONE OBSERVER: ALL REGRESSION TESTS PASSED\n";
