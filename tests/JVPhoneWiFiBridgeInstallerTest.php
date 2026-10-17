<?php
declare(strict_types=1);
// Standalone PHP mock for idempotent Symcon receiver installation.
// No real Symcon instance, webhook, AP, phone, or other module touched.
$_IPS=['SELF'=>24001];
$objects=[24001=>['ParentID'=>0,'Ident'=>'','name'=>'JV Smartphone WLAN Webhook','type'=>'script']];
$variables=[];$values=[];$nextId=30000;$hidden=[];
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
echo "Standalone Symcon bridge: idempotent installer, 2 SSID + 2 hidden tokens PASS\n";
