<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVHomeOccupancyEvidence.php';

$checks=0;
function checkHouse(mixed $actual,mixed $wanted,string $label):void {
    global $checks;$checks++;
    if($actual!==$wanted) {
        throw new RuntimeException($label.': expected '
            .var_export($wanted,true).', got '.var_export($actual,true));
    }
}
$now=1791990000;
$readings=[];
for($i=0;$i<15;$i++) {
    $readings['gus'.($i+1)]=[
        'name'=>'Haus GUS '.($i+1),
        'kind'=>$i===0?'MOTION_KAMIN':($i===1?'MOTION_KITCHEN':'MOTION'),
        'zone'=>'HOME','state'=>'INACTIVE','changedAt'=>$now-10,
        'isFreshMotion'=>false
    ];
}
function sensors(array $rows,bool $baseline=true,array $warnings=[]):array {
    return [
        'schema'=>1,'authority'=>'LOCAL_SENSOR_HINTS_ONLY',
        'baselineInitialized'=>$baseline,
        'readings'=>$rows,'sourceCount'=>count($rows),'zones'=>[],
        'warnings'=>$warnings
    ];
}
function activity(int $startedAt,array $entries=[],array $perSource=[],array $warnings=[]):array {
    return [
        'schema'=>1,'authority'=>'MOTION_EDGE_HISTORY_ONLY',
        'startedAt'=>$startedAt,
        'recentMotionEdges'=>$entries,'perSource'=>$perSource,
        'warnings'=>$warnings
    ];
}
function p03(array $proofs=[],array $warnings=[]):array {
    return [
        'schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
        'recentDirectionProofs'=>$proofs,'warnings'=>$warnings
    ];
}
function edge(string $id,int $at,string $confidence='SINGLE_UNCERTAIN'):array {
    return ['sourceID'=>$id,'zone'=>'HOME','at'=>$at,'confidence'=>$confidence];
}
function countLine(mixed $report):void {
    checkHouse($report['personPresenceMayBeChanged'],false,'no person write');
    checkHouse($report['propertyEmptyMayBeCertified'],false,'never certifies empty');
    checkHouse($report['alarmMayBeControlled'],false,'never arms alarm');
    checkHouse($report['personCount'],'UNKNOWN','never estimates count');
    checkHouse($report['personIdentities'],'UNKNOWN','never assigns names');
    checkHouse($report['absenceControlState'],'NOT_CERTIFIED','never auto absent');
}

$normal=JVHomeOccupancyEvidence::evaluate(
    sensors($readings),activity($now-150),p03(),$now);
checkHouse($normal['houseStatus'],'UNKNOWN','no motion at initialized start => unknown');
checkHouse($normal['sourcesHealthy'],true,'all fifteen GUS currently healthy');
checkHouse($normal['observedHomeGUS'],15,'all configured motion sources');
checkHouse($normal['quietForSeconds'],150,'silence only since baseline');
checkHouse($normal['lastHomeMotionAt'],null,'startup creates no movement');
countLine($normal);

$one=JVHomeOccupancyEvidence::evaluate(
    sensors($readings),
    activity($now-1000,[edge('gus4',$now-44)],[ 'gus4'=>[
        'zone'=>'HOME','lastMotionAt'=>$now-44
    ]]),p03(),$now);
checkHouse($one['houseStatus'],'HOUSE_MOVEMENT_HINT','one independent GUS is weak hint');
checkHouse($one['lastHomeMotionAgeSeconds'],44,'accurate last seen age');
checkHouse($one['reasons'],['LCN_RECENT_MOTION'],'single source reason');
countLine($one);

$strong=JVHomeOccupancyEvidence::evaluate(
    sensors($readings),
    activity($now-2000,[
        edge('gus4',$now-12,'MULTI_SENSOR_CORROBORATED'),
        edge('gus5',$now-5,'MULTI_SENSOR_CORROBORATED')
    ]),p03(),$now);
checkHouse($strong['houseStatus'],'HOUSE_MOVEMENT_CORROBORATED',
    'recent two independent GUS means a strong movement hint');
checkHouse($strong['lastHomeMotionAt'],$now-5,'strong source latest timestamp');
countLine($strong);

// The clock-generated Kamin event is filtered upstream by JVLocalSensorEvidence.
// Corroboration without current independent confidence is not created here.
$clock=JVHomeOccupancyEvidence::evaluate(
    sensors($readings),activity($now-2000,[]),p03(),$now);
checkHouse($clock['houseStatus'],'HOUSE_QUIET_NO_ABSENCE_PROOF',
    'clock-only ignored event cannot suggest resident');
checkHouse($clock['quietForSeconds'],2000,
    'quiet clock event does not reset observation silence');
countLine($clock);

$old=JVHomeOccupancyEvidence::evaluate(
    sensors($readings),
    activity($now-7200,[edge('gus4',$now-3300)]),
    p03(),$now);
checkHouse($old['houseStatus'],'HOUSE_QUIET_NO_ABSENCE_PROOF',
    'quiet 55 minutes is not vacancy');
checkHouse($old['quietForSeconds'],3300,'quiet length uses last positive edge');
checkHouse($old['absenceControlState'],'NOT_CERTIFIED',
    '55-minute inactivity never enables away');
countLine($old);

$night=JVHomeOccupancyEvidence::evaluate(
    sensors($readings),
    activity($now-36000,[]),p03(),$now);
checkHouse($night['houseStatus'],'HOUSE_QUIET_NO_ABSENCE_PROOF',
    'sleeping household 10h silence cannot certify vacancy');
checkHouse($night['propertyEmptyMayBeCertified'],false,
    'overnight no movement is not a property exit');

$proofOut=[
    ['at'=>$now-16,'direction'=>'HOME_TO_LAGER',
        'localTime'=>'2026-10-14 19:00:00','route'=>'P03_2CAM_JV_THEN_WORK']
];
$exit=JVHomeOccupancyEvidence::evaluate(
    sensors($readings),activity($now-5000,[]),
    p03($proofOut),$now);
checkHouse($exit['houseStatus'],'HOME_EXIT_DIRECTION_HINT',
    'P03 HOME->LAGER is direction hint, not property away');
checkHouse($exit['recentP03Direction'],'HOME_TO_LAGER','correct OUT transfer');
countLine($exit);
$proofIn=[
    ['at'=>$now-8,'direction'=>'LAGER_TO_HOME',
        'localTime'=>'2026-10-14 19:00:05','route'=>'P03_2CAM_WORK_THEN_JV']
];
$entry=JVHomeOccupancyEvidence::evaluate(
    sensors($readings),activity($now-5000,[]),
    p03(array_merge($proofOut,$proofIn)),$now);
checkHouse($entry['houseStatus'],'HOME_ENTRY_DIRECTION_HINT',
    'later P03 arrival is house-entry hint only');
checkHouse($entry['lastP03Direction'],'LAGER_TO_HOME','latest direction wins');
countLine($entry);

$notPropertyExit=JVHomeOccupancyEvidence::evaluate(
    sensors($readings),activity($now-5000,[edge('gus8',$now-9)]),
    p03($proofOut),$now);
checkHouse($notPropertyExit['houseStatus'],'HOUSE_MOVEMENT_HINT',
    'house GUS movement overrides direction of someone else toward WORK');
countLine($notPropertyExit);

$pastProof=JVHomeOccupancyEvidence::evaluate(
    sensors($readings),activity($now-4000,[]),
    p03([['at'=>$now-1900,'direction'=>'LAGER_TO_HOME']]),$now);
checkHouse($pastProof['houseStatus'],'HOUSE_QUIET_NO_ABSENCE_PROOF',
    'old P03 incoming evidence cannot imply someone still inside');

$missing=$readings;
unset($missing['gus15']);
$partial=JVHomeOccupancyEvidence::evaluate(
    sensors($missing),activity($now-3000,[
        edge('gus3',$now-1,'MULTI_SENSOR_CORROBORATED')
    ]),p03(),$now);
checkHouse($partial['houseStatus'],'UNKNOWN',
    'one missing GUS blocks definite observation status');
checkHouse($partial['sourcesHealthy'],false,'14/15 GUS is degraded');
checkHouse($partial['warnings'],['SENSOR_COVERAGE_OR_HEALTH_INCOMPLETE'],
    'sensor health diagnostics visible');
countLine($partial);

$badType=$readings;
$badType['gus1']['state']='UNKNOWN';
$bad=JVHomeOccupancyEvidence::evaluate(
    sensors($badType),activity($now-3000),p03(),$now);
checkHouse($bad['sourcesHealthy'],false,'unreadable sensor breaks full coverage');

$noBaseline=JVHomeOccupancyEvidence::evaluate(
    sensors($readings,false),activity($now-3000,[
        edge('gus4',$now-5,'MULTI_SENSOR_CORROBORATED')
    ]),p03(),$now);
checkHouse($noBaseline['houseStatus'],'UNKNOWN',
    'initialization cannot invent historical occupancy');

$error=JVHomeOccupancyEvidence::evaluate(
    sensors($readings,true,['INVALID_SNAPSHOT:gus3']),
    activity($now-2000),p03(),$now);
checkHouse($error['houseStatus'],'UNKNOWN',
    'source warning fail closed rather than declaring house empty');

$p03Gap=JVHomeOccupancyEvidence::evaluate(
    sensors($readings),activity($now-3000),
    p03([],['P03_LOG_GAP_OR_RESET']),$now);
checkHouse($p03Gap['houseStatus'],'UNKNOWN',
    'P03 gap prevents high-confidence home observation');

$badSchema=JVHomeOccupancyEvidence::evaluate(
    ['schema'=>2,'authority'=>'FAKE'],
    activity($now-3000),p03(),$now);
checkHouse($badSchema['houseStatus'],'UNKNOWN','invalid schema fails closed');
checkHouse($badSchema['warnings'],['INPUT_SCHEMA_OR_AUTHORITY_INVALID'],
    'schema source invalid warning');

$future=JVHomeOccupancyEvidence::evaluate(
    sensors($readings),activity($now-5000,[
        edge('gus4',$now+100,'MULTI_SENSOR_CORROBORATED')
    ]),p03(),$now);
checkHouse($future['houseStatus'],'HOUSE_QUIET_NO_ABSENCE_PROOF',
    'future-dated GUS ignored');

$manyWork=[];
for($i=0;$i<20;$i++) {
    $manyWork[]=['sourceID'=>'work'.$i,'zone'=>'WORK',
       'confidence'=>'SINGLE_UNCERTAIN','at'=>$now-$i];
}
$clipped=JVHomeOccupancyEvidence::evaluate(
    sensors($readings),activity($now-5000,$manyWork,[
        'gus2'=>['zone'=>'HOME','lastMotionAt'=>$now-42]
    ]),p03(),$now);
checkHouse($clipped['houseStatus'],'HOUSE_MOVEMENT_HINT',
    'per-source home last motion survives recent-events list cap');
checkHouse($clipped['lastHomeMotionAt'],$now-42,
    'home motion survives unrelated zone flood');

$invalidClock=JVHomeOccupancyEvidence::evaluate(
    sensors($readings),activity($now-2000),p03(),0);
checkHouse($invalidClock['houseStatus'],'UNKNOWN',
    'invalid clock no occupancy evidence');
checkHouse($invalidClock['alarmMayBeControlled'],false,'invalid clock no alarm');

echo "HOME occupancy hints, P03, quiet & fail-closed PASS ($checks assertions)\n";
