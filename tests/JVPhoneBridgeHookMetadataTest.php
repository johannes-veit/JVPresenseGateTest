<?php
declare(strict_types=1);
class IPSModule {public int $InstanceID=32969;}
require_once dirname(__DIR__).'/JVPresenceIdentityShadow/module.php';
function hookMetaCheck(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('HOOK_METADATA '.$why);
}
$GLOBALS['hookCtlIds']=[70001];
$GLOBALS['hookCtlConfig']=[
    ['Hook'=>'/hook/geofency','TargetID'=>10011],
    ['Hook'=>'/hook/egigeozone','TargetID'=>10012],
    ['Hook'=>'/hook/jvphonewifi','TargetID'=>21958]
];
$GLOBALS['hookTargetType']=3;
$GLOBALS['hookReadCalls']=[];
function IPS_GetInstanceListByModuleID(string $guid):array{
    hookMetaCheck($guid==='{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}',
        'only official hook control GUID queried');
    return $GLOBALS['hookCtlIds'];
}
function IPS_GetProperty(int $id,string $property):string {
    $GLOBALS['hookReadCalls'][]=[$id,$property];
    hookMetaCheck($id===70001&&$property==='Hooks',
        'never read camera property/token');
    return json_encode($GLOBALS['hookCtlConfig'],JSON_UNESCAPED_SLASHES);
}
function IPS_GetObject(int $id):array {
    hookMetaCheck($id===21958,'only mapped script object is inspected');
    return ['ObjectType'=>$GLOBALS['hookTargetType'],'ParentID'=>36587];
}
$m=new JVPresenceIdentityShadow();
$method=new ReflectionMethod(JVPresenceIdentityShadow::class,
    'readPhoneBridgeHookMetadata');
$read=static fn()=>$method->invoke($m);
$r=$read();
hookMetaCheck($r['status']==='HOOK_ASSIGNED_VALID_SCRIPT'
    &&$r['targetScriptID']===21958
    &&$r['noSecretValuesRead']&&$r['noHookModified']
    &&count($GLOBALS['hookReadCalls'])===1,
    'valid source hook is metadata only');
$GLOBALS['hookCtlConfig']=array_slice($GLOBALS['hookCtlConfig'],0,2);
hookMetaCheck($read()['status']==='HOOK_NOT_REGISTERED',
    'missing source path is diagnosed not guessed');
$GLOBALS['hookCtlConfig'][]=['Hook'=>'/hook/jvphonewifi','TargetID'=>21958];
$GLOBALS['hookCtlConfig'][]=['Hook'=>'/hook/jvphonewifi','TargetID'=>21958];
hookMetaCheck($read()['status']==='HOOK_CONFLICT',
    'duplicate hook mapping rejected even if same script');
array_pop($GLOBALS['hookCtlConfig']);
$GLOBALS['hookTargetType']=1;
hookMetaCheck($read()['status']==='HOOK_TARGET_INVALID',
    'link to instance not accepted as script target');
$GLOBALS['hookTargetType']=3;
$GLOBALS['hookCtlIds']=[70001,70002];
hookMetaCheck($read()['status']==='HOOK_UNVERIFIABLE',
    'ambiguous global WebHook Control lookup fails closed');
$GLOBALS['hookCtlIds']=[70001];
$GLOBALS['hookCtlConfig']=[['Hook'=>'/hook/jvphonewifi',
    'TargetID'=>'21958']];
hookMetaCheck($read()['status']==='HOOK_UNVERIFIABLE',
    'noninteger hook IDs fail validation');
echo "No-secret passive phone WebHook metadata validation PASS\n";
