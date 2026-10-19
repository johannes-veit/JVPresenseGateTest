<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVAnonymousRoomRouteAtlas.php';
require_once dirname(__DIR__).'/libs/JVJointDepartureReadiness.php';

function routeLinearCheck(bool $ok,string $reason):void{
    if(!$ok)throw new RuntimeException('V0_8_81_ROUTE: '.$reason);
}
$now=1900000000;
$names=[
    'eg_buero'=>'EG Büro','eg_flur'=>'EG Flur',
    'eg_gaeste_wc'=>'EG Gäste-WC',
    'eg_wohnen_kueche'=>'EG Wohnen/Küche',
    'og_flur'=>'OG Flur','og_schlafen_1'=>'OG Schlafen 1',
    'og_bad'=>'OG Bad'
];
$make=static fn(string $id,int $ago):array => [
    'id'=>$id,'room'=>$names[$id],'at'=>$now-$ago,
    'status'=>'SINGLE_UNCERTAIN'
];
$edges=[];
for($i=0;$i<1990;$i++)
    $edges[]=$make('eg_wohnen_kueche',5000-$i*2);
$edges[]=$make('eg_buero',4991);
$edges[]=$make('eg_flur',4983);
$edges[]=$make('eg_gaeste_wc',4975);
$edges[]=$make('eg_flur',4967);
$edges[]=$make('eg_buero',4959);
$edges[]=$make('og_flur',4950); // crosses a different motion zone
$edges[]=$make('og_bad',4941);
usort($edges,static fn($a,$b)=>($a['at']<=>$b['at'])
    ?:strcmp($a['id'],$b['id']));
$rooms=['schema'=>1,'authority'=>'LCN_ROOM_MOTION_PATHS_ANONYMOUS_ONLY',
    'coverageStatus'=>'AVAILABLE_ANONYMOUS_EDGES',
    'roomEdgeCount90m'=>count($edges),
    'recentRoomEdges'=>array_reverse(array_slice($edges,-80))];
$t0=microtime(true);
$out=JVAnonymousRoomRouteAtlas::evaluate($rooms,$edges,$now);
$ms=(microtime(true)-$t0)*1000;
routeLinearCheck($out['status']==='ANONYMOUS_TEMPORAL_HISTORY_AVAILABLE'
    &&$out['sourceIntegrityTailValidated']
    &&$out['validatedRoomEvents90m']===count($edges)
    &&$out['patternTemplates']===14
    &&$out['boundedMatcher']==='LINEAR_TIME_PER_TEMPLATE'
    &&$ms<3000,
    'bounded 1997-event 14-template linear matcher');
// Only the five most recent distinct templates are published, so an
// earlier valid office-WC route must be verified on an isolated fixture
// rather than demanding it is in the limited summary of 14 templates.
routeLinearCheck($out['patternsFound']>=1,
    'crowded 90m yields bounded complete-pattern counts');
$isolated=array_values(array_filter($edges,
    static fn($e)=>$e['id']!=='eg_wohnen_kueche'));
$isolatedSnapshot=$rooms;
$isolatedSnapshot['roomEdgeCount90m']=count($isolated);
$isolatedSnapshot['recentRoomEdges']=array_reverse($isolated);
$isolatedOut=JVAnonymousRoomRouteAtlas::evaluate(
    $isolatedSnapshot,$isolated,$now);
routeLinearCheck(in_array('OFFICE_GUESTWC_RETURN',
    array_column($isolatedOut['latestPatterns'],'pattern'),true),
    'office hall guestWC hall office is validated when not buried by newer templates');
routeLinearCheck($out['patternsWithInterleavedRooms']>=1
    &&is_int($out['lastCrossTrafficAt'])
    &&$out['personIdentityCertified']===false
    &&$out['personCountCertified']===false
    &&$out['homeEmptyCertified']===false
    &&$out['alarmMayBeControlled']===false,
    'interleaving is evidence ambiguity, not multiple identified residents');

$tailMistake=$rooms;
$tailMistake['recentRoomEdges'][0]['room']='falscher Raum';
$untrusted=JVAnonymousRoomRouteAtlas::evaluate($tailMistake,$edges,$now);
routeLinearCheck($untrusted['status']==='NOT_VERIFIED'
    &&!$untrusted['sourceIntegrityTailValidated'],
    'inconsistent recent 80-tail cannot silently approve full stream');
$clock=$edges;$clock[0]['at']=$now+100;
$bad=JVAnonymousRoomRouteAtlas::evaluate($rooms,$clock,$now);
routeLinearCheck($bad['status']==='NOT_VERIFIED',
    'future timestamp or unsorted input is rejected');
$spoof=$edges;$spoof[0]['room']='Falsche Quelle';
$bad=JVAnonymousRoomRouteAtlas::evaluate($rooms,$spoof,$now);
routeLinearCheck($bad['status']==='NOT_VERIFIED',
    'physical room metadata mismatches source ID: reject');
$tooMany=$edges;
while(count($tooMany)<=2048)$tooMany[]=$edges[count($edges)-1];
$bad=JVAnonymousRoomRouteAtlas::evaluate($rooms,$tooMany,$now);
routeLinearCheck($bad['status']==='NOT_VERIFIED',
    'capacity fails closed');

$wlan=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'freshMacConfirmedDeviceCount'=>2,
    'people'=>[
        'johannes'=>['recentMacEvidence'=>true],
        'carina'=>['recentMacEvidence'=>true]
    ]];
$timing=['schema'=>1,
    'authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
    'p03ModeTrustedFromFreshDiagnosis'=>true,
    'p03OperatingMode'=>'TEST_AND_PRODUCTION_OFF'];
$p03=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'directionProofs24h'=>['HOME_TO_LAGER'=>0,'LAGER_TO_HOME'=>0]];
$ready=['schema'=>1,'authority'=>'READ_ONLY_PRESENCE_EVIDENCE_READINESS',
    'roomMotionCoverage'=>'FIFTEEN_GUS_SOURCES_VERIFIED_ANONYMOUS',
    'phones'=>[
        'johannes'=>['personalWorkSSIDCurrent'=>false],
        'carina'=>['personalWorkSSIDCurrent'=>false]
    ]];
$joint=JVJointDepartureReadiness::evaluate(
    $out,$wlan,$timing,$p03,$ready,$now);
routeLinearCheck($joint['state']
    ==='P03_INTENTIONALLY_OFF_NO_NEW_ROUTE_OBSERVATION'
    &&$joint['recentVerifiedHandsets']===2
    &&$joint['verifiedAnonymousCorridorProofs24h']===0
    &&!$joint['twoResidentsDepartingTogetherProven']
    &&!$joint['personsCountableFromTheseSensors']
    &&!$joint['homeEmptyCertified']
    &&!$joint['alarmMayBeControlled']
    &&in_array('P03_KORRIDOR_IM_BETRIEB_NICHT_AKTIV',
        $joint['sourceGapPriorities'],true)
    &&in_array('ZWEI_WLAN_GERAETE_SIND_KEINE_ZWEI_PERSONEN',
        $joint['sourceGapPriorities'],true),
    'two phones plus raw room traffic and inactive P03 prove no human departure');
$active=$timing;$active['p03OperatingMode']='TEST_ACTIVE';
$activeProof=$p03;
$activeProof['directionProofs24h']['HOME_TO_LAGER']=2;
$jointActive=JVJointDepartureReadiness::evaluate(
    $out,$wlan,$active,$activeProof,$ready,$now);
routeLinearCheck($jointActive['state']
    ==='ANONYMOUS_ROUTE_AVAILABLE_NOT_TWO_PEOPLE'
    &&$jointActive['verifiedAnonymousCorridorProofs24h']===2
    &&!$jointActive['twoResidentsDepartingTogetherProven'],
    'even two P03 proofs are not two identifiable humans');
$badWlan=$wlan;$badWlan['freshMacConfirmedDeviceCount']=9;
$refused=JVJointDepartureReadiness::evaluate(
    $out,$badWlan,$timing,$p03,$ready,$now);
routeLinearCheck($refused['state']==='UNVERIFIED_SOURCES'
    &&$refused['recentVerifiedHandsets']===null,
    'invalid device count fail closed');
$stale=$timing;$stale['p03ModeTrustedFromFreshDiagnosis']=false;
$jointStale=JVJointDepartureReadiness::evaluate(
    $out,$wlan,$stale,$p03,$ready,$now);
routeLinearCheck($jointStale['state']==='P03_MODE_NEEDS_FRESH_DIAGNOSIS',
    'an old P03 OFF must not mask a later mode change');

// Site-observed anonymous room sequence from the 19 Oct test:
 // OG Schlafen 1 20:29:39 -> OG Flur 20:29:57 ->
 // EG Flur 20:30:05 -> EG Wohnen/Kueche 20:30:21.
 // Times independently come from the GUS report; never attach names.
$caseNow=(new DateTimeImmutable('2026-10-19 20:31:57',
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$caseTimes=[
    ['og_schlafen_1','OG Schlafen 1','20:29:39'],
    ['og_flur','OG Flur','20:29:57'],
    ['eg_flur','EG Flur','20:30:05'],
    ['eg_wohnen_kueche','EG Wohnen/Küche','20:30:21']
];
$caseEdges=[];
foreach($caseTimes as [$key,$label,$clock]){
    $at=(new DateTimeImmutable('2026-10-19 '.$clock,
        new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $caseEdges[]=['id'=>$key,'room'=>$label,'at'=>$at,
        'status'=>'SINGLE_UNCERTAIN'];
}
$caseInput=['schema'=>1,
    'authority'=>'LCN_ROOM_MOTION_PATHS_ANONYMOUS_ONLY',
    'coverageStatus'=>'AVAILABLE_ANONYMOUS_EDGES',
    'roomEdgeCount90m'=>count($caseEdges),
    'recentRoomEdges'=>array_reverse($caseEdges)];
$caseResult=JVAnonymousRoomRouteAtlas::evaluate(
    $caseInput,$caseEdges,$caseNow);
routeLinearCheck(in_array('OG_BEDROOM1_TO_KITCHEN',
    array_column($caseResult['latestPatterns'],'pattern'),true)
    &&$caseResult['personCountCertified']===false
    &&$caseResult['personIdentityCertified']===false,
    'actual 20:29:39-20:30:21 OG bedroom to EG kitchen GUS ordering recognized, human unknown');

// Randomized equivalence with exhaustive reference for existence of
// temporal patterns. Reproducible, no clock/network/camera needed.
$ref=new ReflectionClass('JVAnonymousRoomRouteAtlas');
$patternSet=$ref->getReflectionConstant('PATTERNS')->getValue();
mt_srand(123456);
for($round=0;$round<35;$round++){
    $ids=array_keys($names);
    $sample=[];$at=$now-5000;
    for($e=0;$e<135;$e++){
        $at+=mt_rand(1,26);
        $id=$ids[mt_rand(0,count($ids)-1)];
        $sample[]=['id'=>$id,'room'=>$names[$id],
            'at'=>$at,'status'=>'SINGLE_UNCERTAIN'];
    }
    $snapshot=['schema'=>1,
        'authority'=>'LCN_ROOM_MOTION_PATHS_ANONYMOUS_ONLY',
        'coverageStatus'=>'AVAILABLE_ANONYMOUS_EDGES',
        'roomEdgeCount90m'=>count($sample),
        'recentRoomEdges'=>array_reverse(array_slice($sample,-80))];
    $fast=JVAnonymousRoomRouteAtlas::evaluate($snapshot,$sample,$now);
    $expected=[];
    foreach($patternSet as $kind=>$wanted){
        // Independent exhaustive dynamic-programming reference:
        // every possible prior edge for every position is considered.
        // Unlike a greedy first-match implementation it cannot lose
        // alternatives under 180s hops and 600s total duration.
        $rows=array_values(array_filter($sample,
            static fn($event)=>in_array($event['id'],$wanted,true)));
        $n=count($rows);$count=count($wanted);
        $reach=array_fill(0,$count,array_fill(0,$n,null));
        for($i=0;$i<$n;$i++){
            if($rows[$i]['id']===$wanted[0])
                $reach[0][$i]=$rows[$i]['at'];
        }
        for($stage=1;$stage<$count;$stage++){
            for($i=0;$i<$n;$i++){
                if($rows[$i]['id']!==$wanted[$stage])continue;
                for($j=0;$j<$i;$j++){
                    if(!is_int($reach[$stage-1][$j]))continue;
                    $gap=$rows[$i]['at']-$rows[$j]['at'];
                    if($gap<1||$gap>180
                        ||$rows[$i]['at']-$reach[$stage-1][$j]>600)
                        continue;
                    $candidate=$reach[$stage-1][$j];
                    if($reach[$stage][$i]===null
                        ||$candidate>$reach[$stage][$i])
                        $reach[$stage][$i]=$candidate;
                }
            }
        }
        foreach($reach[$count-1] as $at){
            if($at!==null){$expected[]=$kind;break;}
        }
    }
    // The report caps listed patterns at five, but total count must
    // equal the brute-force reference across all templates.
    routeLinearCheck($fast['patternsFound']===count($expected),
        'random route count disagreed with exhaustive baseline '.$round);
}
echo "SHADOW 0.8.81 GUS linear route engine + 35 random replays and joint departure guards PASS\n";
