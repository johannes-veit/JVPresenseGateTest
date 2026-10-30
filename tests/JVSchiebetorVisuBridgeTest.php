<?php
declare(strict_types=1);
// Offline-Mock der WebHook-Bridge. Keine echte TOR-Aktion.
$GLOBALS['g']=[
 'objects'=>[29252=>['ObjectType'=>0,'ParentID'=>0,'ObjectIdent'=>''],30894=>['ObjectType'=>3,'ParentID'=>29252,'ObjectIdent'=>''],56236=>['ObjectType'=>1,'ParentID'=>0,'ObjectIdent'=>''],21149=>['ObjectType'=>0,'ParentID'=>0,'ObjectIdent'=>''],60000=>['ObjectType'=>2,'ParentID'=>29252,'ObjectIdent'=>'JVSchiebetorVisuToken']],
 'key'=>str_repeat('a',64),'open'=>0,'calls'=>[]
];
function IPS_GetKernelDir(): string {return sys_get_temp_dir().'/jv_gate_bridge_test_'.getmypid().'/';}
function IPS_ObjectExists(int $id): bool {return isset($GLOBALS['g']['objects'][$id]);}
function IPS_GetObject(int $id): array {return $GLOBALS['g']['objects'][$id];}
function IPS_GetChildrenIDs(int $id): array {return array_keys(array_filter($GLOBALS['g']['objects'],static fn($v)=>($v['ParentID']??null)===$id));}
function IPS_GetVariable(int $id): array {return ['VariableType'=>3];}
function GetValue(int $id): string {return $GLOBALS['g']['key'];}
if (!function_exists('IPS_SemaphoreEnter')) { function IPS_SemaphoreEnter(string $name,int $timeout): bool {return true;} }
if (!function_exists('IPS_SemaphoreLeave')) { function IPS_SemaphoreLeave(string $name): void {} }
function VISU_OpenObject(int $visu,int $category,string $tokens): bool {
 $GLOBALS['g']['calls'][]=[$visu,$category,$tokens];return true;
}
function ok(bool $result,string $name): void {if (!$result)throw new RuntimeException('FAIL '.$name);echo "PASS ".$name."\n";}
$_IPS=['SENDER'=>'Other'];
require dirname(__DIR__).'/docs/schiebetor/SchiebetorVisuBridge.php';
$folder=IPS_GetKernelDir().'user/schiebetor';
@mkdir($folder,0750,true);
$now=time();
$state=['schema'=>1,'v16_preview_at'=>$now,'v16_preview_last_episode'=>2,'v16_preview_count'=>1,'v16_preview_last_side'=>'OSTSEITE','last_seen_at'=>$now,'last_ts'=>$now];
file_put_contents($folder.'/monitor_v11_state.json',json_encode($state));
function req(string $action,string $method,string $key): string {
 global $_IPS;
 $_IPS=['SENDER'=>'WebHook'];$_GET=['action'=>$action];$_SERVER['REQUEST_METHOD']=$method;$_SERVER['HTTP_X_SCHIEBETOR_KEY']=$key;
 ob_start(); jvgbMain();return trim((string)ob_get_clean());
}
ok(req('poll','GET',$GLOBALS['g']['key'])==='OPEN','Frischer Anfahrts-Hinweis');
ok($GLOBALS['g']['calls']===[],'Poll steuert nichts');
ok(req('show','POST',$GLOBALS['g']['key'])==='SHOWN','App-Handshake zeigt Kategorie');
ok($GLOBALS['g']['calls']===[[56236,21149,'']],'Ausschliesslich bestehende Tor-Kategorie, kein Motor');
ok(req('poll','GET',$GLOBALS['g']['key'])==='WAIT','Doppeltes Ereignis unterdrueckt');
ok(req('poll','GET','falsch')==='DENIED','Unautorisierter Aufruf gesperrt');
$state['v16_preview_at']=$now-40;
file_put_contents($folder.'/monitor_v11_state.json',json_encode($state));
ok(req('poll','GET',$GLOBALS['g']['key'])==='WAIT','Altes Ereignis verweigert');
echo "ALLE BRIDGE-TESTS BESTANDEN\n";
