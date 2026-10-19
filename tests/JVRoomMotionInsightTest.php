<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVRoomMotionInsight.php';

function motionInsightCheck(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('ROOM_INSIGHT '.$why);
}
$now=1900000000; // synthetic, not a household timestamp
$make=static fn(string $id,string $name,int $ago,string $status='SINGLE_UNCERTAIN'):array =>
    ['id'=>$id,'room'=>$name,'at'=>$now-$ago,'status'=>$status];
$edges=[
    $make('eg_wohnen_kueche','EG Wohnen/Küche',210),
    $make('eg_buero','EG Büro',198),
    $make('eg_flur','EG Flur',185),
    $make('og_flur','OG Flur',160),
    $make('og_ankleide','OG Ankleide',151),
    $make('og_ankleide','OG Ankleide',149),
    $make('eg_wohnen_kamin','EG Wohnen Kamin',130,'KAMIN_UNCORROBORATED'),
    $make('eg_wohnen_kueche','EG Wohnen/Küche',110),
    $make('eg_buero','EG Büro',100),
    $make('eg_wohnen_kueche','EG Wohnen/Küche',40)
];
$input=['schema'=>1,'authority'=>'LCN_ROOM_MOTION_PATHS_ANONYMOUS_ONLY',
    'coverageStatus'=>'AVAILABLE_ANONYMOUS_EDGES',
    'recentRoomEdges'=>array_reverse($edges),
    'roomEdgeCount90m'=>count($edges)];
$r=JVRoomMotionInsight::evaluate($input,$now);
motionInsightCheck($r['authority']==='ANONYMOUS_LCN_ROOM_ACTIVITY_CONTEXT_ONLY'
    &&$r['observationsAvailable']===true
    &&$r['coverage']==='ALL_REPORTED_EDGES_WITH_EXCLUSIONS'
    &&$r['weakKaminEdgesExcluded']===1
    &&$r['retriggerEdgesSuppressed']===1,
    'validated existing GUS edges, Kamin quality gate, repeat suppression');
motionInsightCheck($r['events5m']===8&&$r['events15m']===8
    &&$r['distinctRooms15m']===5
    &&count($r['activeFloors15m'])===2
    &&$r['officeKitchenCloseTiming']
    &&$r['egOgHallSequencePossible']
    &&count($r['recentMotionBursts'])>0,
    'short windows, office/kitchen coincidence, inter-floor chronology');
motionInsightCheck($r['hotRooms15m'][0]['id']==='eg_wohnen_kueche'
    &&$r['hotRooms15m'][0]['events']===3
    &&$r['lastEGMotionAt']===$now-40
    &&$r['lastOGMotionAt']===$now-151,
    'anonymized hotspots and latest floor edges');
motionInsightCheck(!$r['identityCertified']
    &&!$r['personCountCertified']
    &&!$r['physicalPresenceOrAbsenceCertified']
    &&!$r['alarmMayBeControlled']
    &&!$r['cookingOrClothesChangeProven']
    &&$r['simultaneousSignalsAreNotTwoPeople']
    &&$r['burstsAreNotIndividualRoutes'],
    'close room events can never become people, cooking or alarm proof');

$partial=$input;
$partial['roomEdgeCount90m']=125;
$limited=JVRoomMotionInsight::evaluate($partial,$now);
motionInsightCheck($limited['coverage']==='LATEST_80_ROOM_EDGES_ONLY',
    'report does not advertise complete history when newest edges are truncated');
$renamed=$input;
$renamed['recentRoomEdges'][0]['room']='Fremdquelle';
$bad=JVRoomMotionInsight::evaluate($renamed,$now);
motionInsightCheck($bad['usableDebouncedEdges90m']<$r['usableDebouncedEdges90m'],
    'reused ID with changed room label not accepted');
$wrong=$input;
$wrong['authority']='UNVERIFIED_SENSOR_INPUT';
$unknown=JVRoomMotionInsight::evaluate($wrong,$now);
motionInsightCheck(!$unknown['observationsAvailable']
    &&$unknown['coverage']==='NOT_VERIFIED'
    &&!$unknown['officeKitchenCloseTiming'],
    'no evidence from unverified room source');
$old=$input;
foreach($old['recentRoomEdges'] as &$event)$event['at']=$now-10000;
unset($event);
$aged=JVRoomMotionInsight::evaluate($old,$now);
motionInsightCheck($aged['events5m']===0
    &&$aged['events15m']===0
    &&!$aged['officeKitchenCloseTiming']
    &&!$aged['egOgHallSequencePossible'],
    'old motion does not imply recent activity or a journey');
$future=$input;
$future['recentRoomEdges'][0]['at']=$now+3600;
$untrusted=JVRoomMotionInsight::evaluate($future,$now);
motionInsightCheck($untrusted['usableDebouncedEdges90m']<$r['usableDebouncedEdges90m'],
    'future-dated motions rejected');
echo "15-GUS anonymous room insight windows, hotspots, quality and safety PASS\n";
