<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVResidentRoomPaths.php';
require_once dirname(__DIR__).'/libs/JVRoomMotionInsight.php';
require_once dirname(__DIR__).'/libs/JVAnonymousRoomRouteAtlas.php';
require_once dirname(__DIR__).'/libs/JVRoomDayDigest.php';
require_once dirname(__DIR__).'/libs/JVHousePowerContext.php';
require_once dirname(__DIR__).'/libs/JVLocalActivityLedger.php';
function milestoneCheck(bool $condition,string $name):void{
    if(!$condition)throw new RuntimeException('V0_8_80 '.$name);
}
$now=1900000000;
$names=[
 'eg_flur'=>'Bewegungsmelder EG Flur',
 'og_flur'=>'Bewegungsmelder OG Flur',
 'og_schlafen_1'=>'Bewegungsmelder OG Schlafen 1',
 'eg_wohnen_kueche'=>'Bewegungsmelder EG Wohnen / Küche',
 'eg_wohnen_kamin'=>'Bewegungsmelder EG Wohnen Kamin'
];
$per=[];
foreach($names as $id=>$label)$per[$id]=['name'=>$label,'zone'=>'HOME'];
$entries=[
 ['id'=>'eg_flur','at'=>$now-5150,'status'=>'SINGLE_UNCERTAIN'],
 ['id'=>'og_flur','at'=>$now-5100,'status'=>'SINGLE_UNCERTAIN'],
 ['id'=>'og_schlafen_1','at'=>$now-5050,'status'=>'MULTI_SENSOR_CORROBORATED']
];
// Make >80 later events that bury the earlier 3-hop sequence in the
// display-only tail but must NOT erase its internal 90-minute analysis.
for($i=0;$i<112;$i++)$entries[]=[
 'id'=>'eg_wohnen_kueche','at'=>$now-4800+$i*38,
 'status'=>'SINGLE_UNCERTAIN'
];
$state=['schema'=>1,'startedAt'=>$now-86500,'entries'=>$entries];
$activity=['schema'=>1,'authority'=>'MOTION_EDGE_HISTORY_ONLY',
    'perSource'=>$per,'full24hWindowObserved'=>true,'warnings'=>[]];
$rooms=JVResidentRoomPaths::evaluate($state,$activity,$now,true);
milestoneCheck($rooms['roomEdgeCount90m']===115
    &&count($rooms['recentRoomEdges'])===80
    &&count($rooms['internalFullRoomEdges90m'])===115,
    'preserve all 115 historical LCN edges internally but only 80 externally');
$all=$rooms['internalFullRoomEdges90m'];unset($rooms['internalFullRoomEdges90m']);
$insight=JVRoomMotionInsight::evaluate($rooms,$now,$all);
milestoneCheck($insight['fullInternalHistoryUsed']===true
    &&$insight['coverage']==='ALL_REPORTED_EDGES_90M'
    &&$insight['roomEdgesSeen90m']===115
    &&$insight['usableDebouncedEdges90m']===115,
    'full 90m insight rather than truncated last 80');
$atlas=JVAnonymousRoomRouteAtlas::evaluate($rooms,$all,$now);
milestoneCheck($atlas['status']==='ANONYMOUS_TEMPORAL_HISTORY_AVAILABLE'
    &&in_array('EG_TO_OG_BEDROOM1',array_column($atlas['latestPatterns'],'pattern'),true)
    &&$atlas['patternsFound']>=1,
    'older validated EG-OG-bedroom sequence survives visible 80-edge truncation');
milestoneCheck(!$atlas['sameIndividualNotVerified']===false,
    'impossible boolean check always fail if changed');
milestoneCheck($atlas['personCountCertified']===false
    &&$atlas['personIdentityCertified']===false
    &&$atlas['alarmMayBeControlled']===false
    &&$atlas['homeEmptyCertified']===false,
    'anonymous GUS route atlas may not identify or arm');

$badAtlas=$atlas;
$missing=array_slice($all,0,30);
$rejected=JVAnonymousRoomRouteAtlas::evaluate($rooms,$missing,$now);
milestoneCheck($rejected['status']==='NOT_VERIFIED'
    &&$rejected['patternsFound']===0,'mismatched full history is not trusted');
$short=JVRoomMotionInsight::evaluate($rooms,$now,[]);
milestoneCheck($short['coverage']==='LATEST_80_ROOM_EDGES_ONLY'
    &&!$short['fullInternalHistoryUsed'],
    'when full history is unavailable the old bounded fallback is truthful');
$wrong=$all;
$wrong[count($wrong)-1]['at']=$now+200;
$future=JVAnonymousRoomRouteAtlas::evaluate($rooms,$wrong,$now);
milestoneCheck($future['status']==='NOT_VERIFIED',
    'future event fails the whole atlas input instead of inventing routes');

$day=JVRoomDayDigest::evaluate($state,$activity,$now);
milestoneCheck($day['status']==='BOUNDED_24H_HISTORY_AVAILABLE'
    &&$day['homeRoomEvents24h']===115
    &&$day['motionEvents6h']===115
    &&$day['motionEvents1h']>0
    &&$day['distinctRooms24h']===4
    &&array_sum($day['hourlyAgeBuckets24h'])===115
    &&!$day['mayControlAlarm'] && !$day['mayCertifyHomeEmpty'],
    'bounded relative-hour 24h digest and absence safety');
$partialActivity=$activity;$partialActivity['full24hWindowObserved']=false;
$partial=JVRoomDayDigest::evaluate($state,$partialActivity,$now);
milestoneCheck($partial['status']==='PARTIAL_WINDOW_NO_SILENCE_CONCLUSION',
    'fresh startup is not a complete quiet-day claim');

$rooms['kitchenLastMotionAt']=$now-70;
$high=['validatedWattMetadata'=>true,'value'=>3510.4,'updatedAt'=>$now-30];
$power=JVHousePowerContext::evaluate($high,$rooms,$now,true);
milestoneCheck($power['highLoad3000W']
    &&$power['highLoadAndKitchenMovementNearby']
    &&$power['watts']===3510
    &&!$power['personIdentityCertified']
    &&!$power['alarmMayBeControlled'],
    'fresh existing watt measurement + anonymous kitchen activity, no cooking proof');
$stale=$high;$stale['updatedAt']=$now-500;
milestoneCheck(JVHousePowerContext::evaluate($stale,$rooms,$now,true)['state']
    ==='STALE_WATT_SNAPSHOT','stale power cannot be treated as live');
$kw=$high;$kw['validatedWattMetadata']=false;
milestoneCheck(JVHousePowerContext::evaluate($kw,$rooms,$now,true)['state']
    ==='SOURCE_NOT_VERIFIED','unqualified W/Wh/kW source is not read');
milestoneCheck(JVHousePowerContext::evaluate($high,$rooms,$now,false)['state']
    ==='DISABLED','opt-in genuinely defaults off');
$negative=$high;$negative['value']=-1000;
milestoneCheck(JVHousePowerContext::evaluate($negative,$rooms,$now,true)['state']
    ==='INVALID_NUMERIC_WATT_VALUE','negative power is not accepted');
$away=$rooms;$away['kitchenLastMotionAt']=$now-700;
$other=JVHousePowerContext::evaluate($high,$away,$now,true);
milestoneCheck($other['highLoad3000W']
    &&!$other['highLoadAndKitchenMovementNearby'],
    'high watts without kitchen motion is not kitchen activity');

$registered=['eg_flur'=>['name'=>'EG Flur','zone'=>'HOME']];
$longOld=[];
for($i=0;$i<2050;$i++)$longOld[]=[
 'id'=>'eg_flur','at'=>$now-3000+$i,'status'=>'SINGLE_UNCERTAIN'];
$over=JVLocalActivityLedger::evaluate(
 $longOld,$registered,['schema'=>1,'startedAt'=>$now-86400,'entries'=>[]],$now);
milestoneCheck(in_array('LEDGER_CAPACITY_REACHED',$over['report']['warnings'],true)
    &&!$over['report']['full24hWindowObserved']
    &&$over['next']['capacityTruncatedAt']===$now,
    'overflow marks 24h observed coverage as partial');
$next=JVLocalActivityLedger::evaluate([],$registered,$over['next'],$now+60);
milestoneCheck(!$next['report']['full24hWindowObserved']
    &&$next['report']['capacityTruncatedAt']===$now,
    'persisted overflow remains incomplete on next poll and restart');
$expired=JVLocalActivityLedger::evaluate([],$registered,$next['next'],$now+86500);
milestoneCheck($expired['report']['capacityTruncatedAt']===null
    &&$expired['report']['full24hWindowObserved'],
    'overflow incident eventually expires only after entire affected day');

// A recycled Symcon ID with the wrong room name is not a valid 24h room.
// Fresh person claims must also stay impossible after corrupt local data.
$spoofActivity=$activity;
$spoofActivity['perSource']['eg_wohnen_kueche']['name']='Bewegungsmelder fremde Halle';
$spoof=JVRoomDayDigest::evaluate($state,$spoofActivity,$now);
milestoneCheck($spoof['homeRoomEvents24h']===3
    &&$spoof['distinctRooms24h']===3
    &&!$spoof['mayCertifyHomeEmpty'],
    '24h replay rejects a reused sensor ID with wrong original room');
$corrupted=JVLocalActivityLedger::evaluate([],$registered,[
    'schema'=>999,'entries'=>[['unexpected'=>'damaged']],
    'startedAt'=>$now-86400
],$now);
milestoneCheck(!$corrupted['report']['full24hWindowObserved']
    &&$corrupted['next']['startedAt']===$now
    &&$corrupted['next']['capacityTruncatedAt']===$now,
    'corrupted old 24h ledger rebaselines continuity instead of certifying');
$corruptNext=JVLocalActivityLedger::evaluate(
    [],$registered,$corrupted['next'],$now+60);
milestoneCheck(!$corruptNext['report']['full24hWindowObserved']
    &&$corruptNext['report']['capacityTruncatedAt']===$now,
    'corrupt source continuity cannot silently repair itself at next poll');
echo "SHADOW 0.8.80 deep replay: full 90m, 24h digest, 7 route patterns, watt safety, sticky overflow PASS\n";
