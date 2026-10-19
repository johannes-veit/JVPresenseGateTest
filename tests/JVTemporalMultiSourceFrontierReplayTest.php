<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVAnonymousRoomRouteAtlas.php';
require_once dirname(__DIR__).'/libs/JVSharedDepartureTimeline.php';
function temporal83(bool $yes,string $reason):void{
    if(!$yes)throw new RuntimeException('0.8.83: '.$reason);
}
$now=1900000000;
$rooms=[
    'eg_buero'=>'EG Büro','eg_flur'=>'EG Flur',
    'eg_gaeste_wc'=>'EG Gäste-WC',
    'eg_wohnen_kueche'=>'EG Wohnen/Küche',
    'og_flur'=>'OG Flur','og_schlafen_1'=>'OG Schlafen 1',
    'og_bad'=>'OG Bad','og_ankleide'=>'OG Ankleide'
];
$at=static fn(string $id,int $time):array=>[
    'id'=>$id,'room'=>$rooms[$id],'at'=>$time,
    'status'=>'SINGLE_UNCERTAIN'
];
$input=static fn(array $edges):array=>[
    'schema'=>1,'authority'=>'LCN_ROOM_MOTION_PATHS_ANONYMOUS_ONLY',
    'coverageStatus'=>'AVAILABLE_ANONYMOUS_EDGES',
    'roomEdgeCount90m'=>count($edges),
    'recentRoomEdges'=>array_reverse(array_slice($edges,-80))
];
// At t=30 C and B are simultaneous, so C may continue the earlier B=20.
// The old v0.8.82 stage overwrote B=20 with B=30 and lost this match.
$case=[
    $at('eg_buero',$now-200),
    $at('eg_flur',$now-180),
    $at('eg_flur',$now-170),
    $at('eg_wohnen_kueche',$now-170)
];
$got=JVAnonymousRoomRouteAtlas::evaluate($input($case),$case,$now);
temporal83(in_array('OFFICE_TO_KITCHEN',
        array_column($got['latestPatterns'],'pattern'),true)
    &&$got['sameSecondStagesIsolated']
    &&$got['nonDominatedPathFrontier'],
    'same timestamp B+C must preserve earlier B route');
$noOrder=[
    $at('eg_buero',$now-80),
    $at('eg_flur',$now-80),
    $at('eg_wohnen_kueche',$now-80)
];
temporal83(JVAnonymousRoomRouteAtlas::evaluate(
    $input($noOrder),$noOrder,$now)['patternsFound']===0,
    'three PIR events same second cannot be converted into movement');
$overlap=[
    $at('eg_buero',$now-130),
    $at('eg_flur',$now-110),
    $at('og_flur',$now-95),
    $at('eg_wohnen_kueche',$now-92)
];
$cross=JVAnonymousRoomRouteAtlas::evaluate(
    $input($overlap),$overlap,$now);
temporal83($cross['patternsWithInterleavedRooms']>=1
    &&$cross['personCountCertified']===false
    &&$cross['personIdentityCertified']===false
    &&$cross['homeEmptyCertified']===false
    &&$cross['alarmMayBeControlled']===false,
    'two overlapping paths remain anonymous and not headcount');

// Independently enumerate ALL possible prior legs of every 14 template.
// A time-grouped frontier must agree with full O(N^2) DP even under
// many same-second duplicates and repeated room labels.
$reflection=new ReflectionClass(JVAnonymousRoomRouteAtlas::class);
$templates=$reflection->getReflectionConstant('PATTERNS')->getValue();
mt_srand(20261019);
$ids=array_keys($rooms);
for($round=0;$round<48;$round++){
    $events=[];$time=$now-5000;
    for($i=0;$i<150;$i++){
        $time+=mt_rand(0,40); // deliberate same-second duplicates
        $id=$ids[mt_rand(0,count($ids)-1)];
        $events[]=$at($id,$time);
    }
    // The last sample always fits inside the supported 90m window.
    $fast=JVAnonymousRoomRouteAtlas::evaluate(
        $input($events),$events,$now);
    temporal83($fast['status']==='ANONYMOUS_TEMPORAL_HISTORY_AVAILABLE',
        'replay input qualification '.$round);
    $expected=0;
    foreach($templates as $pattern=>$required){
        $stageCount=count($required);
        $n=count($events);
        $reachable=array_fill(0,$stageCount,array_fill(0,$n,null));
        for($i=0;$i<$n;$i++){
            if($events[$i]['id']===$required[0])
                $reachable[0][$i]=$events[$i]['at'];
        }
        for($stage=1;$stage<$stageCount;$stage++){
            for($i=0;$i<$n;$i++){
                if($events[$i]['id']!==$required[$stage])continue;
                for($j=0;$j<$i;$j++){
                    $start=$reachable[$stage-1][$j];
                    if(!is_int($start))continue;
                    $gap=$events[$i]['at']-$events[$j]['at'];
                    if($gap<1||$gap>180||$events[$i]['at']-$start>600)
                        continue;
                    if($reachable[$stage][$i]===null
                        ||$start>$reachable[$stage][$i])
                        $reachable[$stage][$i]=$start;
                }
            }
        }
        if(count(array_filter($reachable[$stageCount-1],
            static fn($v)=>$v!==null))>0)$expected++;
    }
    temporal83($fast['patternsFound']===$expected,
        'monotonic frontier disagrees with full DP in random replay '.$round
        .' expected='.$expected.' observed='.$fast['patternsFound']);
}
// Duplicate 2048 events at one timestamp must be accepted only as
// observation volume, never as an assumed walk or many people.
$large=array_fill(0,2048,$at('eg_flur',$now-35));
$largeRun=JVAnonymousRoomRouteAtlas::evaluate(
    $input($large),$large,$now);
temporal83($largeRun['validatedRoomEvents90m']===2048
    &&$largeRun['patternsFound']===0
    &&!$largeRun['personCountCertified'],
    '2048 same-second edges are not human paths');

// Keep a previously observed dual-phone OUT pair as historical when
// either app subsequently reports an IN. A phone return is NOT a person
// returning, and does not prove whether somebody else stayed home.
$person=static fn(array $eventHistory):array=>[
    'geoSourceContinuous'=>true,'macSourceContinuous'=>true,
    'sourceRebaselineRequired'=>false,'lastSourceRebindAt'=>null,
    'eventHistory'=>$eventHistory
];
$outEvent=static fn(int $time):array=>[
    'code'=>'PHONE_GEOFENCE_OUT_OBSERVED','at'=>$time];
$inEvent=static fn(int $time):array=>[
    'code'=>'PHONE_GEOFENCE_IN_OBSERVED','at'=>$time];
$phones=['schema'=>1,
    'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>[
        'johannes'=>$person([$outEvent($now-900),$inEvent($now-650)]),
        'carina'=>$person([$outEvent($now-860)])
    ]];
$cam=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'logContinuity'=>'OK','baselineOnly'=>false,
    'newProofBatchOverflow'=>false,'verifiedTimelineTruncated'=>false,
    'verifiedDirectionTimeline24h'=>[[
        'source'=>'P03_TWO_MAST_VERIFIED',
        'direction'=>'HOME_TO_LAGER','at'=>$now-880]]];
$timing=['schema'=>1,
    'authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
    'p03ModeTrustedFromFreshDiagnosis'=>true,
    'p03OperatingMode'=>'PRODUCTION_ACTIVE'];
$oneReturn=JVSharedDepartureTimeline::evaluate(
    $phones,$cam,$timing,$now);
temporal83($oneReturn['pairedPhoneAreaOutObserved']
    &&$oneReturn['pairedOutSupersededByAreaIn']
    &&$oneReturn['returnedAfterPairedOut']['johannes']
    &&!$oneReturn['returnedAfterPairedOut']['carina']
    &&$oneReturn['verifiedP03NearPair']
    &&$oneReturn['status']
        ==='HISTORIC_PHONE_AREA_OUT_AND_ANONYMOUS_P03_NEARBY'
    &&!$oneReturn['homeEmptyCertified']
    &&!$oneReturn['personDepartureCertified'],
    'later phone IN marks historical paired OUT without rejecting real past proof');
$bothReturn=$phones;
$bothReturn['people']['carina']['eventHistory'][]=$inEvent($now-600);
$twoReturn=JVSharedDepartureTimeline::evaluate(
    $bothReturn,$cam,$timing,$now);
temporal83($twoReturn['returnedAfterPairedOut']['carina']
    &&$twoReturn['pairedOutSupersededByAreaIn'],
    'two separate IN events invalidate any current joint-out interpretation');
$before=$phones;
$before['people']['johannes']['eventHistory']=[
    $inEvent($now-1000),$outEvent($now-900)];
$beforeResult=JVSharedDepartureTimeline::evaluate(
    $before,$cam,$timing,$now);
temporal83(!$beforeResult['pairedOutSupersededByAreaIn'],
    'IN before OUT is not a return from that OUT');
$off=$timing;$off['p03OperatingMode']='TEST_AND_PRODUCTION_OFF';
$offResult=JVSharedDepartureTimeline::evaluate(
    $phones,$cam,$off,$now);
temporal83($offResult['status']
    ==='HISTORIC_PHONE_AREA_OUT_PAIR_P03_OFF'
    &&!$offResult['verifiedP03NearPair'],
    'P03 disabled: historical phone OUT plus IN cannot upgrade anonymous proof');
$onlyWlan=$phones;
$onlyWlan['people']['johannes']['eventHistory'][]=[
    'code'=>'HOME_PHONE_REAPPEARED_AFTER_CONTACT_GAP','at'=>$now-10];
$wlanRun=JVSharedDepartureTimeline::evaluate(
    $onlyWlan,$cam,$timing,$now);
temporal83($wlanRun['latestPhoneAreaIn']['johannes']===$now-650
    &&$wlanRun['pairedOutSupersededByAreaIn'],
    'recent MAC observation never substitutes an IN geofence event');
echo "SHADOW v0.8.83: 48 exhaustive same-second/random GUS replays + return-aware dual-phone replay PASS\n";
