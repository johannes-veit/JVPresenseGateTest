<?php
declare(strict_types=1);
// Standalone PHP mock for idempotent Symcon receiver installation.
// No real Symcon instance, webhook, AP, phone, or other module touched.
$_IPS=['SELF'=>24001,'SENDER'=>'Execute'];
$objects=[24001=>['ParentID'=>0,'Ident'=>'','name'=>'JV Smartphone WLAN Webhook','type'=>'script']];
$variables=[];$values=[];$nextId=30000;$hidden=[];
// Real core-instance hook API is simulated; existing hooks must survive.
$coreHooks=[
    ['Hook'=>'/hook/egigeozone','TargetID'=>90100],
    ['Hook'=>'/hook/geofency','TargetID'=>90101],
    ['Hook'=>'/hook/schiebetor','TargetID'=>90102]
];
$hookWrites=0;$hookApplies=0;
function IPS_GetInstanceListByModuleID(string $guid):array{
    if($guid!=='{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}')
        throw new RuntimeException('wrong webhook control GUID');
    return [55000];
}
function IPS_GetProperty(int $id,string $name):string{
    global $coreHooks;
    if($id!==55000||$name!=='Hooks')throw new RuntimeException('wrong hook property');
    return json_encode($coreHooks,JSON_UNESCAPED_SLASHES);
}
function IPS_SetProperty(int $id,string $name,string $value):void{
    global $coreHooks,$hookWrites;
    if($id!==55000||$name!=='Hooks')throw new RuntimeException('unexpected hook write');
    $next=json_decode($value,true);
    if(!is_array($next))throw new RuntimeException('invalid JSON');
    $hookWrites++;$coreHooks=$next;
}
function IPS_ApplyChanges(int $id):void{
    global $hookApplies;
    if($id!==55000)throw new RuntimeException('unexpected core instance');
    $hookApplies++;
}
function IPS_GetObject(int $id):array {global $objects;return $objects[$id]??throw new RuntimeException('unknown obj '.$id);}
function IPS_GetObjectIDByIdent(string $ident,int $parent):int {global $objects;foreach($objects as $id=>$o)if($o['ParentID']===$parent&&$o['Ident']===$ident)return $id;throw new RuntimeException('not found');}
function IPS_CreateCategory():int {global $nextId,$objects;$id=++$nextId;$objects[$id]=['ParentID'=>0,'Ident'=>'','name'=>'','type'=>'category'];return $id;}
function IPS_CreateVariable(int $type):int {global $nextId,$objects,$variables;$id=++$nextId;$objects[$id]=['ParentID'=>0,'Ident'=>'','name'=>'','type'=>'variable'];$variables[$id]=['VariableType'=>$type];return $id;}
function IPS_SetParent(int $id,int $p):void {global $objects;$objects[$id]['ParentID']=$p;}
function IPS_SetName(int $id,string $n):void {global $objects;$objects[$id]['name']=$n;}
function IPS_SetIdent(int $id,string $i):void {global $objects;$objects[$id]['Ident']=$i;}
function IPS_GetVariable(int $id):array {global $variables;return $variables[$id]??[];}
function IPS_GetName(int $id):string {global $objects;return $objects[$id]['name'];}
function IPS_SetHidden(int $id,bool $b):void {global $hidden;$hidden[$id]=$b;}
function SetValue(int $id,mixed $val):void {global $values;$values[$id]=$val;}
function GetValue(int $id):mixed {global $values;return $values[$id];}
ob_start();require dirname(__DIR__).'/docs/phone-wifi-bridge/JVPhoneWiFiWebhook.php';$first=ob_get_clean();
$count=count($objects);$vals=$values;
ob_start();require dirname(__DIR__).'/docs/phone-wifi-bridge/JVPhoneWiFiWebhook.php';$second=ob_get_clean();
if(count($objects)!==$count||$vals!==$values)throw new RuntimeException('installer mutated on rerun');
if(count($variables)!==4||count($hidden)!==2)throw new RuntimeException('not exactly two SSIDs and two hidden secrets');
foreach($values as $v)if(strlen($v)!==0&&strlen($v)!==48)throw new RuntimeException('invalid token length');
if(!str_contains($first,'jvphonewifi')||!str_contains($second,'CARINA'))throw new RuntimeException('setup text missing');
if(!str_contains($first,'AUTOMATISCH_EINGERICHTET')
    ||!str_contains($second,'BEREITS_KORREKT'))
    throw new RuntimeException('one-click hook setup and rerun status missing');
if($hookWrites!==1||$hookApplies!==1||count($coreHooks)!==4)
    throw new RuntimeException('duplicate or repeated WebHook Control mutation');
foreach([['/hook/egigeozone',90100],['/hook/geofency',90101],
    ['/hook/schiebetor',90102],['/hook/jvphonewifi',24001]] as [$path,$target]){
    $matches=array_values(array_filter($coreHooks,
        static fn(array $x):bool=>$x['Hook']===$path&&$x['TargetID']===$target));
    if(count($matches)!==1)throw new RuntimeException('lost or overwritten existing hook: '.$path);
}
$count=count($objects);
$_IPS['SENDER']='RunScript';
ob_start();require dirname(__DIR__).'/docs/phone-wifi-bridge/JVPhoneWiFiWebhook.php';$other=ob_get_clean();
if(count($objects)!==$count||$hookWrites!==1
    ||!str_contains($other,'Setup verweigert'))
    throw new RuntimeException('unexpected automatic initialization on external event');
$_IPS['SENDER']='Execute';
// Security: an existing hook OWNED BY SOMEONE ELSE must NEVER be stolen.
$coreHooks[3]['TargetID']=989898;
try {
    ob_start();require dirname(__DIR__).'/docs/phone-wifi-bridge/JVPhoneWiFiWebhook.php';
    ob_end_clean();
    throw new RuntimeException('collision not rejected');
} catch(RuntimeException $e) {
    if(ob_get_level()>0)ob_end_clean();
    if(!str_contains($e->getMessage(),'WebHook-Konflikt:'))throw $e;
}
if($hookWrites!==1||$hookApplies!==1||count($objects)!==$count)
    throw new RuntimeException('conflict modified foreign hook or created new objects');
echo "Standalone Symcon bridge: one-click auto-hook, no foreign hook override, secure rerun PASS\n";
echo "Standalone Symcon bridge: idempotent installer, 2 SSID + 2 hidden tokens PASS\n";
