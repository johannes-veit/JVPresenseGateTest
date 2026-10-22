<?php
declare(strict_types=1);
class IPSModule {
    public int $InstanceID=32969;
    public array $values=['Status'=>''];
    public array $debug=[];
    public function GetValue(string $ident):mixed{return $this->values[$ident]??null;}
    public function SetValue(string $ident,mixed $value):void{$this->values[$ident]=$value;}
    public function SendDebug(string $topic,string $message,int $format):void{
        $this->debug[]=[$topic,$message,$format];
    }
}
require_once dirname(__DIR__).'/JVPresenceIdentityShadow/module.php';
function placementCheck(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException('VISU_PLACEMENT '.$reason);
}
$GLOBALS['placementObjects']=[];
$GLOBALS['placementLinks']=[];
$GLOBALS['placementCreated']=0;
$GLOBALS['placementDeleted']=[];
$GLOBALS['placementTargetFails']=false;
function resetTree():void {
    $GLOBALS['placementObjects']=[
        160=>['ObjectType'=>0,'ParentID'=>0,'name'=>'Anwesenheitserkennung'],
        505=>['ObjectType'=>0,'ParentID'=>160,'name'=>'Identity Shadow'],
        32969=>['ObjectType'=>1,'ParentID'=>505,'name'=>'JV Presence Identität SHADOW'],
        510=>['ObjectType'=>0,'ParentID'=>160,'name'=>'WLAN Carina'],
        511=>['ObjectType'=>0,'ParentID'=>160,'name'=>'WLAN Johannes']
    ];
    $GLOBALS['placementLinks']=[];
    $GLOBALS['placementCreated']=0;
    $GLOBALS['placementDeleted']=[];
    $GLOBALS['placementTargetFails']=false;
}
function IPS_GetObject(int $id):array {
    if(!isset($GLOBALS['placementObjects'][$id]))
        throw new RuntimeException('OBJECT_UNKNOWN');
    return $GLOBALS['placementObjects'][$id];
}
function IPS_GetName(int $id):string {
    return (string)(IPS_GetObject($id)['name']??'');
}
function IPS_GetChildrenIDs(int $parent):array {
    $ids=[];
    foreach($GLOBALS['placementObjects'] as $id=>$o)
        if(($o['ParentID']??null)===$parent)$ids[]=$id;
    return $ids;
}
function IPS_GetLink(int $id):array {
    if(!isset($GLOBALS['placementLinks'][$id]))
        throw new RuntimeException('LINK_NOT_FOUND');
    return ['TargetID'=>$GLOBALS['placementLinks'][$id]];
}
function IPS_CreateLink():int {
    $id=90001+(++$GLOBALS['placementCreated'])-1;
    $GLOBALS['placementObjects'][$id]=[
        'ObjectType'=>6,'ParentID'=>0,'name'=>'Unbenannter Link'];
    $GLOBALS['placementLinks'][$id]=0;
    return $id;
}
function IPS_SetParent(int $id,int $parent):bool {
    if(!isset($GLOBALS['placementObjects'][$id])
        ||!isset($GLOBALS['placementObjects'][$parent]))return false;
    $GLOBALS['placementObjects'][$id]['ParentID']=$parent;
    return true;
}
function IPS_SetLinkTargetID(int $id,int $target):bool {
    if($GLOBALS['placementTargetFails'])return false;
    if(!isset($GLOBALS['placementLinks'][$id])
        ||!isset($GLOBALS['placementObjects'][$target]))return false;
    $GLOBALS['placementLinks'][$id]=$target;return true;
}
function IPS_SetName(int $id,string $name):bool {
    if(!isset($GLOBALS['placementObjects'][$id]))return false;
    $GLOBALS['placementObjects'][$id]['name']=$name;return true;
}
function IPS_SetPosition(int $id,int $position):bool {
    if(!isset($GLOBALS['placementObjects'][$id]))return false;
    $GLOBALS['placementObjects'][$id]['position']=$position;return true;
}
function IPS_LinkExists(int $id):bool {return isset($GLOBALS['placementLinks'][$id]);}
function IPS_DeleteLink(int $id):bool {
    if(!isset($GLOBALS['placementLinks'][$id]))return false;
    unset($GLOBALS['placementLinks'][$id],$GLOBALS['placementObjects'][$id]);
    $GLOBALS['placementDeleted'][]=$id;return true;
}

resetTree();
$mod=new JVPresenceIdentityShadow();
$mod->CreateAnwesenheitTileLink();
placementCheck($GLOBALS['placementCreated']===1
    &&isset($GLOBALS['placementObjects'][90001])
    &&$GLOBALS['placementObjects'][90001]['ParentID']===160
    &&$GLOBALS['placementObjects'][90001]['name']==='Bewohnerstatus'
    &&$GLOBALS['placementObjects'][90001]['position']===0
    &&$GLOBALS['placementLinks'][90001]===32969,
    'one link on existing instance exactly under Anwesenheitserkennung');
placementCheck(($GLOBALS['placementObjects'][505]['ParentID']??null)===160
    &&($GLOBALS['placementObjects'][510]['ParentID']??null)===160
    &&($GLOBALS['placementObjects'][511]['ParentID']??null)===160,
    'existing WLAN/Identity Shadow folders never moved');
placementCheck(str_contains($mod->values['Status'],'Link #90001'),
    'result appears in existing Status string only');

$mod->CreateAnwesenheitTileLink();
placementCheck($GLOBALS['placementCreated']===1
    &&count($GLOBALS['placementLinks'])===1,
    'second click is idempotent without duplicate link');

resetTree();
$GLOBALS['placementObjects'][90007]=[
    'ObjectType'=>6,'ParentID'=>160,'name'=>'Meine eigene Bewohneranzeige'];
$GLOBALS['placementLinks'][90007]=32969;
$mod->CreateAnwesenheitTileLink();
placementCheck($GLOBALS['placementCreated']===0
    &&$GLOBALS['placementObjects'][90007]['name']==='Meine eigene Bewohneranzeige',
    'existing custom link to same target is reused untouched');

resetTree();
$GLOBALS['placementObjects'][710]=[
    'ObjectType'=>6,'ParentID'=>160,'name'=>'Bewohnerstatus'];
$GLOBALS['placementLinks'][710]=505;
$mod->CreateAnwesenheitTileLink();
placementCheck($GLOBALS['placementCreated']===0
    &&$GLOBALS['placementLinks'][710]===505,
    'name conflict never overwrites foreign link');

resetTree();
$GLOBALS['placementObjects'][160]['name']='Unbekannte Kategorie';
$mod->CreateAnwesenheitTileLink();
placementCheck($GLOBALS['placementCreated']===0,
    'missing required ancestor never creates a guessed category');

resetTree();
$GLOBALS['placementObjects'][160]['ObjectType']=2;
$mod->CreateAnwesenheitTileLink();
placementCheck($GLOBALS['placementCreated']===0,
    'matching name on non-category object rejected');

resetTree();
$GLOBALS['placementTargetFails']=true;
$mod->CreateAnwesenheitTileLink();
placementCheck($GLOBALS['placementCreated']===1
    &&count($GLOBALS['placementLinks'])===0
    &&$GLOBALS['placementDeleted']===[90001],
    'partial installation cleans up ONLY its own newly created link');

resetTree();
$GLOBALS['placementObjects'][32969]['ObjectType']=6;
$mod->CreateAnwesenheitTileLink();
placementCheck($GLOBALS['placementCreated']===0,
    'wrong owner object type cannot be linked as trusted instance');
echo "Single-click native SHADOW root-category link: 8 fail-closed scenarios PASS\n";
