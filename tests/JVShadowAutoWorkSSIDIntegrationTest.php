<?php
declare(strict_types=1);

/**
 * End-to-end real SHADOW auto-SSID discovery without SymBox writes.
 * Verifies that installing an authenticated dedicated bridge DOES NOT
 * accidentally hide the earlier Geofency WifiSSID source by ambiguity.
 */
class IPSModule {
    private array $props=[];
    private array $attributes=[];
    public array $registered=[];
    public function Create():void {}
    public function RegisterPropertyBoolean(string $n,bool $v):void{$this->props[$n]=$v;}
    public function RegisterPropertyInteger(string $n,int $v):void{$this->props[$n]=$v;}
    public function RegisterPropertyString(string $n,string $v):void{$this->props[$n]=$v;}
    public function ReadPropertyInteger(string $n):int{return (int)($this->props[$n]??0);}
    public function ReadPropertyString(string $n):string{return (string)($this->props[$n]??'');}
    public function RegisterAttributeString(string $n,string $v):void{$this->attributes[$n]=$v;}
    public function ReadAttributeString(string $n):string{return (string)($this->attributes[$n]??'');}
    public function WriteAttributeString(string $n,string $v):void{$this->attributes[$n]=$v;}
    public function RegisterVariableString(string $n,string $label,string $profile,int $sort):void{$this->registered[]=$n;}
    public function RegisterVariableBoolean(string $n,string $label,string $profile,int $sort):void{$this->registered[]=$n;}
    public function RegisterTimer(string $n,int $time,string $code):void{}
}
$now=time();
$GLOBALS['phoneSSIDFixtures']=[
    701=>['parent'=>50000,'name'=>'WifiSSID','type'=>3,'value'=>'Privat-Veit','updated'=>$now-50],
    702=>['parent'=>45439,'name'=>'WifiSSID','type'=>3,'value'=>'Veit-FilzenerWeg','updated'=>$now-50],
    801=>['parent'=>810,'name'=>'WifiSSID','type'=>3,'value'=>'Intern-Veit','updated'=>$now-5],
    802=>['parent'=>820,'name'=>'WifiSSID','type'=>3,'value'=>'Privat-Veit','updated'=>$now-5],
    803=>['parent'=>830,'name'=>'WifiSSID','type'=>3,'value'=>'Intern-Veit','updated'=>$now-5],
];
$GLOBALS['phoneSSIDParents']=[
    50000=>['parent'=>35965,'name'=>'Device'],
    45439=>['parent'=>48903,'name'=>'Device'],
    810=>['parent'=>777,'name'=>'Johannes Smartphone SSID Quelle'],
    820=>['parent'=>777,'name'=>'Carina Smartphone SSID Quelle'],
    830=>['parent'=>999,'name'=>'Generic AP'],
];
function IPS_GetVariableList():array{return array_keys($GLOBALS['phoneSSIDFixtures']);}
function IPS_VariableExists(int $id):bool{return isset($GLOBALS['phoneSSIDFixtures'][$id]);}
function IPS_GetName(int $id):string{
    return (string)($GLOBALS['phoneSSIDFixtures'][$id]['name']
        ??$GLOBALS['phoneSSIDParents'][$id]['name']??'');
}
function IPS_GetObject(int $id):array{
    return ['ParentID'=>$GLOBALS['phoneSSIDFixtures'][$id]['parent']
        ??$GLOBALS['phoneSSIDParents'][$id]['parent']??0];
}
function IPS_GetVariable(int $id):array{
    $x=$GLOBALS['phoneSSIDFixtures'][$id]??null;
    if(!is_array($x))throw new RuntimeException('INVALID_VARIABLE');
    return ['VariableType'=>$x['type'],'VariableUpdated'=>$x['updated']];
}
function GetValue(int $id):mixed{return $GLOBALS['phoneSSIDFixtures'][$id]['value']??null;}
require_once dirname(__DIR__).'/JVPresenceIdentityShadow/module.php';
require_once dirname(__DIR__).'/libs/JVCompanyWiFiEvidence.php';
function autoSSIDAssert(bool $condition,string $why):void{
    if(!$condition)throw new RuntimeException('AUTO_WORK_SSID '.$why);
}
function freshModule():JVPresenceIdentityShadow{
    $m=new JVPresenceIdentityShadow();$m->Create();return $m;
}
$method=new ReflectionMethod(JVPresenceIdentityShadow::class,'readAutoKnownPhoneSSIDs');
$module=freshModule();
$auto=$method->invoke($module);
autoSSIDAssert(($auto['johannes']['sourceVariableID']??null)===801
    &&($auto['carina']['sourceVariableID']??null)===802,
    'dedicated bridge outranks different native WifiSSID and generic AP');
$work=JVCompanyWiFiEvidence::evaluate($auto,$now,true);
autoSSIDAssert($work['workPhoneCount']===2
    &&$work['people']['johannes']['status']==='WORK_FIRMEN_WLAN_PHONE'
    &&$work['people']['carina']['status']==='WORK_FIRMEN_WLAN_PHONE',
    'both per-person current SSIDs map to WORK without AP inspection');
autoSSIDAssert(count($module->registered)===7,
    'seven existing Symcon display variables remain unchanged');

// A duplicate dedicated bridge candidate for the SAME person is not
// silently resolved from order or SSID text; refuse WORK instead.
$GLOBALS['phoneSSIDFixtures'][804]=[
    'parent'=>811,'name'=>'WifiSSID','type'=>3,
    'value'=>'Intern-Veit','updated'=>$now-1];
$GLOBALS['phoneSSIDParents'][811]=[
    'parent'=>778,'name'=>'Johannes Smartphone SSID Quelle'];
$duplicate=$method->invoke(freshModule());
autoSSIDAssert(($duplicate['johannes']['verified']??false)===false
    &&($duplicate['carina']['sourceVariableID']??null)===802,
    'two different authenticated-looking bridge parents fail closed');
unset($GLOBALS['phoneSSIDFixtures'][804],$GLOBALS['phoneSSIDParents'][811]);

// Without bridge, existing native phone data remain recognized, rather
// than requiring creation of another script or status variable.
unset($GLOBALS['phoneSSIDFixtures'][801],$GLOBALS['phoneSSIDFixtures'][802]);
$native=$method->invoke(freshModule());
autoSSIDAssert(($native['johannes']['sourceVariableID']??null)===701
    &&($native['carina']['sourceVariableID']??null)===702,
    'native Geofency WifiSSID sources remain usable without bridge');

// No known person-specific source -> no invented recognition from AP.
unset($GLOBALS['phoneSSIDFixtures'][701],$GLOBALS['phoneSSIDFixtures'][702]);
$none=$method->invoke(freshModule());
autoSSIDAssert(($none['johannes']['verified']??false)===false
    &&($none['carina']['verified']??false)===false,
    'generic AP SSID is never a resident phone signal');
echo "SHADOW automatic native/bridge per-person SSID discovery PASS\n";
