<?php
declare(strict_types=1);
// Exact screenshot hierarchy: Anwesenheitserkennung #36895, Shadow #33054.
class IPSModule {
    public int $InstanceID=32969;
    private array $attrs=[];
    public array $values=[];
    public function ReadAttributeString(string $key):string {
        return $this->attrs[$key]??'{}';
    }
    public function WriteAttributeString(string $key,string $value):void{
        $this->attrs[$key]=$value;
    }
    public function SendDebug(string $key,string $value,int $severity):void {}
    public function GetValue(string $key):mixed{return $this->values[$key]??null;}
    public function SetValue(string $key,mixed $value):void{$this->values[$key]=$value;}
}
require_once dirname(__DIR__).'/JVPresenceIdentityShadow/module.php';
function rootCheck(bool $value,string $text):void {
    if(!$value)throw new RuntimeException('ROOT_VISU '.$text);
}
$GLOBALS['rootObjects']=[];
$GLOBALS['rootTargets']=[];
$GLOBALS['rootCreateCount']=0;
$GLOBALS['rootDeleted']=[];
$GLOBALS['rootSetHiddenCount']=0;
function rootReset():void {
    $GLOBALS['rootObjects']=[
        36895=>['ObjectType'=>0,'ParentID'=>0,
            'ObjectName'=>'Anwesenheitserkennung','ObjectIsHidden'=>false],
        33054=>['ObjectType'=>0,'ParentID'=>36895,
            'ObjectName'=>'Identity Shadow','ObjectIsHidden'=>false],
        32969=>['ObjectType'=>1,'ParentID'=>33054,
            'ObjectName'=>'JV Presence Identitaet SHADOW','ObjectIsHidden'=>false],
        36587=>['ObjectType'=>0,'ParentID'=>36895,
            'ObjectName'=>'JV Phone WiFi Bridge','ObjectIsHidden'=>false],
        33107=>['ObjectType'=>1,'ParentID'=>36895,
            'ObjectName'=>'P03 – JV Terrasse','ObjectIsHidden'=>false],
        57215=>['ObjectType'=>1,'ParentID'=>36895,
            'ObjectName'=>'P03 – Lagerplatz JV links','ObjectIsHidden'=>false],
        27938=>['ObjectType'=>1,'ParentID'=>36895,
            'ObjectName'=>'P03 – Lagerplatz Werkstatt links','ObjectIsHidden'=>false],
        20483=>['ObjectType'=>1,'ParentID'=>36895,
            'ObjectName'=>'Presence Gate Test','ObjectIsHidden'=>true],
        53879=>['ObjectType'=>1,'ParentID'=>36895,
            'ObjectName'=>'Presence P03 Terrace Test','ObjectIsHidden'=>false],
    ];
    $GLOBALS['rootTargets']=[];
    $GLOBALS['rootCreateCount']=0;
    $GLOBALS['rootDeleted']=[];
    $GLOBALS['rootSetHiddenCount']=0;
}
function IPS_GetObject(int $id):array {
    if(!isset($GLOBALS['rootObjects'][$id]))throw new RuntimeException('OBJECT_NOT_FOUND');
    return $GLOBALS['rootObjects'][$id];
}
function IPS_GetChildrenIDs(int $parent):array {
    $ids=[];
    foreach($GLOBALS['rootObjects'] as $id=>$row)
        if($row['ParentID']===$parent)$ids[]=$id;
    return $ids;
}
function IPS_GetLink(int $id):array {
    if(!isset($GLOBALS['rootTargets'][$id]))throw new RuntimeException('LINK_NOT_FOUND');
    return ['TargetID'=>$GLOBALS['rootTargets'][$id]];
}
function IPS_CreateLink():int {
    $id=88000+(++$GLOBALS['rootCreateCount']);
    $GLOBALS['rootObjects'][$id]=['ObjectType'=>6,
        'ParentID'=>0,'ObjectName'=>'Link','ObjectIsHidden'=>false];
    $GLOBALS['rootTargets'][$id]=0;return $id;
}
function IPS_SetLinkTargetID(int $id,int $target):bool {
    if(!isset($GLOBALS['rootTargets'][$id])||!isset($GLOBALS['rootObjects'][$target]))
        return false;
    $GLOBALS['rootTargets'][$id]=$target;return true;
}
function IPS_SetParent(int $id,int $parent):bool {
    if(!isset($GLOBALS['rootObjects'][$id])||!isset($GLOBALS['rootObjects'][$parent]))
        return false;
    $GLOBALS['rootObjects'][$id]['ParentID']=$parent;return true;
}
function IPS_SetName(int $id,string $name):bool {
    if(!isset($GLOBALS['rootObjects'][$id]))return false;
    $GLOBALS['rootObjects'][$id]['ObjectName']=$name;return true;
}
function IPS_SetPosition(int $id,int $position):bool {
    if(!isset($GLOBALS['rootObjects'][$id]))return false;
    $GLOBALS['rootObjects'][$id]['ObjectPosition']=$position;return true;
}
function IPS_SetHidden(int $id,bool $hide):bool {
    if(!isset($GLOBALS['rootObjects'][$id]))return false;
    $GLOBALS['rootObjects'][$id]['ObjectIsHidden']=$hide;
    $GLOBALS['rootSetHiddenCount']++;return true;
}
function IPS_LinkExists(int $id):bool {return isset($GLOBALS['rootTargets'][$id]);}
function IPS_DeleteLink(int $id):bool {
    if(!isset($GLOBALS['rootTargets'][$id]))return false;
    unset($GLOBALS['rootObjects'][$id],$GLOBALS['rootTargets'][$id]);
    $GLOBALS['rootDeleted'][]=$id;return true;
}
rootReset();
$m=new JVPresenceIdentityShadow();
$m->InstallKnownRootVisu();
rootCheck(count($GLOBALS['rootTargets'])===1
    &&($GLOBALS['rootTargets'][88001]??null)===32969
    &&($GLOBALS['rootObjects'][88001]['ParentID']??null)===36895
    &&$GLOBALS['rootObjects'][88001]['ObjectIsHidden']===false,
    'one resident native instance link visible at root');
foreach([33054,36587,33107,57215,27938,20483,53879] as $id)
    rootCheck($GLOBALS['rootObjects'][$id]['ObjectIsHidden']===true,
        'only root resident tile visible, sibling '.$id.' hidden');
rootCheck($GLOBALS['rootObjects'][32969]['ObjectIsHidden']===false
    &&($GLOBALS['rootObjects'][53879]['ParentID']??null)===36895,
    'no module disabled/deleted or physically moved');
$backup=json_decode($m->ReadAttributeString('ResidentRootVisuSetup'),true);
rootCheck(($backup['phase']??null)==='installed'
    &&($backup['visibilityBefore'][20483]??false)===true
    &&($backup['visibilityBefore'][33054]??true)===false,
    'durable previous visibility recorded exactly');
$m->InstallKnownRootVisu();
rootCheck($GLOBALS['rootCreateCount']===1
    &&count($GLOBALS['rootTargets'])===1,
    'repeated one-click setup remains idempotent');

$m->RestoreKnownRootVisu();
rootCheck($GLOBALS['rootTargets']===[]
    &&$GLOBALS['rootObjects'][33054]['ObjectIsHidden']===false
    &&$GLOBALS['rootObjects'][36587]['ObjectIsHidden']===false
    &&$GLOBALS['rootObjects'][33107]['ObjectIsHidden']===false
    &&$GLOBALS['rootObjects'][53879]['ObjectIsHidden']===false
    &&$GLOBALS['rootObjects'][20483]['ObjectIsHidden']===true,
    'restores every original flag and only its own created link');
rootCheck((json_decode($m->ReadAttributeString('ResidentRootVisuSetup'),true)['phase']??null)
    ==='restored','opted-out restoration is durable');
// Auto apply is prevented from immediately reinstalling after a restore:
$method=new ReflectionMethod(JVPresenceIdentityShadow::class,'installKnownRootVisuSafely');
$status=$method->invoke($m);
rootCheck(str_contains($status,'bewusst wiederhergestellt')
    &&$GLOBALS['rootTargets']===[],
    'automatic repeated update respects restoration');
$m->InstallKnownRootVisu();
rootCheck(count($GLOBALS['rootTargets'])===1,
    'explicit user click may reinstall after restore');

rootReset();
$wrong=new JVPresenceIdentityShadow();
$GLOBALS['rootObjects'][36895]['ObjectName']='Anderes';
$wrong->InstallKnownRootVisu();
rootCheck($GLOBALS['rootTargets']===[]
    &&$GLOBALS['rootObjects'][33054]['ObjectIsHidden']===false,
    'changed category cannot be guessed or hidden');
rootReset();
$GLOBALS['rootObjects'][32969]['ParentID']=53879;
$wrong2=new JVPresenceIdentityShadow();
$wrong2->InstallKnownRootVisu();
rootCheck($GLOBALS['rootTargets']===[],'wrong hierarchy fails closed');
echo "Real screenshot root #36895: native tile only, idempotent, restore, no foreign controls PASS\n";
