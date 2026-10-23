<?php
declare(strict_types=1);
/**
 * Real 23.10.2026 recovery: deleted webhook script #21958, old phone SSID
 * IDs still in a different bridge, new script #14652 with new SSID IDs.
 * Neither foreign source nor personal secret is written or read.
 */
class IPSModule {
    public int $InstanceID=32969;
    protected array $attrs=[];
    protected array $buffers=[];
    public int $attrWrites=0;
    public function Create():void {}
    public function RegisterPropertyBoolean(string $k,bool $v):void {}
    public function RegisterPropertyInteger(string $k,int $v):void {}
    public function RegisterPropertyString(string $k,string $v):void {}
    public function RegisterAttributeString(string $k,string $v):void {
        if(!array_key_exists($k,$this->attrs))$this->attrs[$k]=$v;
    }
    public function ReadAttributeString(string $k):string {
        return $this->attrs[$k]??'{}';
    }
    public function WriteAttributeString(string $k,string $v):void {
        $this->attrs[$k]=$v;$this->attrWrites++;
    }
    public function GetBuffer(string $k):string{return $this->buffers[$k]??'';}
    public function SetBuffer(string $k,string $v):void{$this->buffers[$k]=$v;}
    public function RegisterVariableString(string $id,string $name,string $profile,int $sort):void {}
    public function RegisterVariableBoolean(string $id,string $name,string $profile,int $sort):void {}
    public function RegisterTimer(string $name,int $ms,string $code):void {}
}
require_once dirname(__DIR__).'/JVPresenceIdentityShadow/module.php';
require_once dirname(__DIR__).'/libs/JVCompanyWiFiEvidence.php';
function bridgeAssert(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException('ACTIVE_PHONE_BRIDGE '.$reason);
}
$now=time();
$GLOBALS['recoveryObjects']=[
  70000=>['ObjectType'=>0,'ParentID'=>0,'ObjectName'=>'Symcon'],
  14652=>['ObjectType'=>3,'ParentID'=>70000,'ObjectName'=>'JV Smartphone WLAN Webhook'],
  70001=>['ObjectType'=>0,'ParentID'=>70000,'ObjectName'=>'JV Phone WiFi Bridge','ObjectIdent'=>'JVPhoneWifiBridgeRoot'],
  40500=>['ObjectType'=>0,'ParentID'=>70001,'ObjectName'=>'Johannes Smartphone SSID Quelle','ObjectIdent'=>'JVWifiPhone_Johannes'],
  19570=>['ObjectType'=>0,'ParentID'=>70001,'ObjectName'=>'Carina Smartphone SSID Quelle','ObjectIdent'=>'JVWifiPhone_Carina'],
  70100=>['ObjectType'=>0,'ParentID'=>0,'ObjectName'=>'alte Struktur'],
  70101=>['ObjectType'=>0,'ParentID'=>70100,'ObjectName'=>'JV Phone WiFi Bridge','ObjectIdent'=>'JVPhoneWifiBridgeRoot'],
  56881=>['ObjectType'=>0,'ParentID'=>70101,'ObjectName'=>'Johannes Smartphone SSID Quelle','ObjectIdent'=>'JVWifiPhone_Johannes'],
  32081=>['ObjectType'=>0,'ParentID'=>70101,'ObjectName'=>'Carina Smartphone SSID Quelle','ObjectIdent'=>'JVWifiPhone_Carina'],
];
$GLOBALS['recoveryVars']=[
  21780=>['parent'=>40500,'name'=>'WifiSSID','ident'=>'JVPhoneWifiCurrentSSID','value'=>'','updated'=>$now-30],
  42876=>['parent'=>19570,'name'=>'WifiSSID','ident'=>'JVPhoneWifiCurrentSSID','value'=>'','updated'=>$now-30],
  56338=>['parent'=>56881,'name'=>'WifiSSID','ident'=>'JVPhoneWifiCurrentSSID','value'=>'Intern-Veit','updated'=>$now-2000],
  24058=>['parent'=>32081,'name'=>'WifiSSID','ident'=>'JVPhoneWifiCurrentSSID','value'=>'Privat-Veit','updated'=>$now-2000],
];
$GLOBALS['phoneHookTarget']=14652;
function IPS_GetInstanceListByModuleID(string $g):array {
    bridgeAssert($g==='{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}','only WebHook Control inspected');
    return [77000];
}
function IPS_GetProperty(int $id,string $prop):string {
    bridgeAssert($id===77000&&$prop==='Hooks','only public webhook routing metadata');
    return json_encode([
        ['Hook'=>'/hook/geofency','TargetID'=>81001],
        ['Hook'=>'/hook/jvphonewifi','TargetID'=>$GLOBALS['phoneHookTarget']]
    ],JSON_UNESCAPED_SLASHES);
}
function IPS_GetVariableList():array{return array_keys($GLOBALS['recoveryVars']);}
function IPS_GetVariable(int $id):array {
    if(!isset($GLOBALS['recoveryVars'][$id]))throw new RuntimeException('not a variable');
    return ['VariableType'=>3,'VariableUpdated'=>$GLOBALS['recoveryVars'][$id]['updated']];
}
function IPS_VariableExists(int $id):bool{return isset($GLOBALS['recoveryVars'][$id]);}
function IPS_GetObject(int $id):array {
    if(isset($GLOBALS['recoveryVars'][$id])){
        $v=$GLOBALS['recoveryVars'][$id];
        return ['ObjectType'=>2,'ParentID'=>$v['parent'],
            'ObjectName'=>$v['name'],'ObjectIdent'=>$v['ident']];
    }
    if(!isset($GLOBALS['recoveryObjects'][$id]))throw new RuntimeException('missing object');
    return $GLOBALS['recoveryObjects'][$id];
}
function IPS_GetChildrenIDs(int $parent):array {
    $ids=[];
    foreach($GLOBALS['recoveryObjects'] as $id=>$o)
        if(($o['ParentID']??null)===$parent)$ids[]=$id;
    foreach($GLOBALS['recoveryVars'] as $id=>$v)
        if(($v['parent']??null)===$parent)$ids[]=$id;
    return $ids;
}
function IPS_GetName(int $id):string {
    return IPS_GetObject($id)['ObjectName']??'';
}
function GetValue(int $id):mixed {
    bridgeAssert(isset($GLOBALS['recoveryVars'][$id]),
        'never read token or unrelated variable');
    return $GLOBALS['recoveryVars'][$id]['value'];
}
$m=new JVPresenceIdentityShadow();$m->Create();
$read=new ReflectionMethod(JVPresenceIdentityShadow::class,'readAutoKnownPhoneSSIDs');
$root=new ReflectionMethod(JVPresenceIdentityShadow::class,
    'activePhoneWiFiBridgeRootReadOnly');
bridgeAssert($root->invoke($m)===70001,'only active hook sibling ident root is canonical');
$pair=$read->invoke($m);
bridgeAssert(($pair['johannes']['sourceVariableID']??null)===21780
    &&($pair['carina']['sourceVariableID']??null)===42876,
    'new active bridge overrides duplicate stale old variable pairs');
$evaluated=JVCompanyWiFiEvidence::evaluate($pair,$now,true);
bridgeAssert($evaluated['workPhoneCount']===0
    &&$evaluated['people']['johannes']['status']==='INVALID_CURRENT_SSID'
    &&$evaluated['people']['carina']['status']==='INVALID_CURRENT_SSID',
    'empty new personal SSIDs cannot establish either workplace');
$attrWrites=$m->attrWrites;
$repeat=$read->invoke($m);
bridgeAssert($repeat===$pair&&$m->attrWrites===$attrWrites,
    'same hook root/source selection has no flash write loop');

$GLOBALS['recoveryVars'][21780]['value']='Intern-Veit';
$GLOBALS['recoveryVars'][21780]['updated']=$now-2;
$GLOBALS['recoveryVars'][42876]['value']='Veit-FilzenerWeg';
$GLOBALS['recoveryVars'][42876]['updated']=$now-2;
$fresh=$read->invoke($m);
bridgeAssert($fresh['johannes']['ssid']==='Intern-Veit'
    &&$fresh['carina']['ssid']==='Veit-FilzenerWeg',
    'new authenticated receiver sources used once phone POST is received');
$live=JVCompanyWiFiEvidence::evaluate($fresh,$now,true);
bridgeAssert($live['people']['johannes']['workRecent']===true
    &&$live['people']['carina']['homeSsidRecent']===true,
    'correct independent work and home SSID context');

$GLOBALS['phoneHookTarget']=21958; // deleted receiver must NOT be trusted
$lost=$read->invoke($m);
bridgeAssert(($lost['johannes']['verified']??null)===false
    &&($lost['carina']['verified']??null)===false,
    'unknown active endpoint with two bridge copies fails closed');
$GLOBALS['phoneHookTarget']=14652;
$GLOBALS['recoveryVars'][21780]['ident']='AnotherValue';
$wrong=$read->invoke($m);
bridgeAssert(($wrong['johannes']['verified']??null)===false
    &&($wrong['carina']['sourceVariableID']??null)===42876,
    'tampered canonical source ident cannot silently fall back to stale bridge');
$GLOBALS['recoveryVars'][21780]['ident']='JVPhoneWifiCurrentSSID';
$GLOBALS['recoveryObjects'][70001]['ObjectIdent']='DifferentRoot';
$disconnected=$read->invoke($m);
bridgeAssert(($disconnected['johannes']['verified']??null)===false,
    'wrong root identity cannot claim ownership of two competing same-name bridges');
echo "Active phone WebHook bridge duplicate-safe routing and no-action selection PASS\n";
