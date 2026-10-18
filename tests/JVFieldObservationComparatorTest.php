<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVFieldObservationComparator.php';
function fieldCheck(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('FIELD_CASE '.$why);
}
// Synthetic test only. Real residents' travel times and vehicle details are
// not committed to the repository or injected as sensor observations.
$at=(new DateTimeImmutable('2026-10-20 11:00:00',
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$reference=[
    'schema'=>1,'authority'=>'USER_REPORTED_FIELD_OBSERVATION',
    'localTime'=>'2026-10-20 11:00',
    'reportedHouseLeavers'=>['johannes','carina'],
    'knownVehicleContext'=>['ownerOrDriver'=>'carina',
        'makeModel'=>'Example Vehicle','color'=>'sample-color']
];
$p03=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'recentDirectionProofs'=>[
        ['source'=>'P03_TWO_MAST_VERIFIED','direction'=>'HOME_TO_LAGER',
            'route'=>'P03_2CAM_JV_THEN_WORK','at'=>$at+90],
        ['source'=>'P03_TWO_MAST_VERIFIED','direction'=>'LAGER_TO_HOME',
            'route'=>'P03_2CAM_WORK_THEN_JV','at'=>$at+2000]
    ]];
$phones=['schema'=>1,
    'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>[
        'johannes'=>['eventHistory'=>[[
            'code'=>'PHONE_GEOFENCE_OUT_OBSERVED','at'=>$at+110
        ]]],
        'carina'=>['eventHistory'=>[]]
    ]];
$report=['schema'=>1,'authority'=>'SHADOW_DIRECTION_PHONE_AND_LOCAL_HINTS',
    'p03LiveTimeline'=>$p03,'residentEventFusion'=>$phones];
$x=JVFieldObservationComparator::compare($reference,$report,$at+3000);
fieldCheck($x['status']==='COMPARED_AS_ANONYMOUS_HINTS_ONLY'
    &&$x['p03AnonymousOutNearReference']===1
    &&$x['p03AnonymousInNearReference']===0
    &&$x['sameNumberOfPeopleObserved']==='NOT_PROVABLE'
    &&count($x['referencePeople'])===2,
    'one directional P03 event cannot count or identify two field-reported humans');
fieldCheck($x['phoneEventNearReference']['johannes']
    ==='PHONE_GEOFENCE_OUT_NEAR_REFERENCE_NOT_HUMAN_PROOF'
    &&$x['phoneEventNearReference']['carina']
        ==='NO_COMPARABLE_PERSONAL_PHONE_OUT_NOT_DISPROOF',
    'missing a phone event does not contradict an independent departure report');
fieldCheck(($x['reportedVehicle']['descriptionHeldByObserver']??false)===true
    &&!str_contains(json_encode($x), 'Example Vehicle')
    &&!str_contains(json_encode($x), 'sample-color')
    &&!$x['carTripVerifiedBySensors']
    &&!$x['peopleIdentifiedBySensors']
    &&!$x['allOtherOccupantsAbsentCertified']
    &&!$x['alarmMayBeArmed'],
    'user-provided vehicle description is private reference, not verified camera identity');
$missing=$report;
$missing['p03LiveTimeline']['recentDirectionProofs']=[];
$nullP03=JVFieldObservationComparator::compare($reference,$missing,$at+3000);
fieldCheck($nullP03['p03AnonymousOutNearReference']===0
    &&in_array('NO_P03_OUT_NEAR_REFERENCE_DOES_NOT_REFUTE_HOUSE_EXIT',
        $nullP03['notes'],true),
    'no corridor proof does not refute a house exit through another door');
$untrusted=$report;
$untrusted['authority']='UNTRUSTED';
fieldCheck(JVFieldObservationComparator::compare($reference,
    $untrusted,$at+3000)['status']==='NOT_COMPARABLE',
    'unknown EvidenceJSON authority not used as positive proof');
$old=JVFieldObservationComparator::compare($reference,
    $report,$at+100000);
fieldCheck($old['status']==='REFERENCE_OUTSIDE_24H_SOURCE_WINDOW'
    &&!$old['houseExitVerifiedBySensors'],
    'late submitted snapshots cannot reconstruct unavailable old telemetry');
echo "Privacy-safe field truth versus anonymous evidence comparison PASS\n";
