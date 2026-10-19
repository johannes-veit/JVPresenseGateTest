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
// High-traffic camera log exposes only newest 15 of 24-hour proof history.
// A field reference older than those visible 15 is NOT a negative observation.
$truncated=$report;
$truncated['p03LiveTimeline']['logContinuity']='OK';
$truncated['p03LiveTimeline']['directionProofs24h']=[
    'HOME_TO_LAGER'=>20,'LAGER_TO_HOME'=>15
];
$truncated['p03LiveTimeline']['recentDirectionProofs']=[];
for($i=0;$i<15;$i++){
    $truncated['p03LiveTimeline']['recentDirectionProofs'][]=[
        'source'=>'P03_TWO_MAST_VERIFIED',
        'direction'=>'HOME_TO_LAGER',
        'route'=>'P03_2CAM_JV_THEN_WORK','at'=>$at+1900+$i
    ];
}
$recentOnly=JVFieldObservationComparator::compare(
    $reference,$truncated,$at+3000
);
fieldCheck($recentOnly['p03HistoryCoverage']
    ==='REFERENCE_OLDER_THAN_VISIBLE_15'
    &&$recentOnly['p03AnonymousOutNearReference']===0
    &&$recentOnly['p03Total24h']===35
    &&in_array('OLDER_CAMERA_EVENTS_NOT_IN_15_ITEM_REPORT',
        $recentOnly['notes'],true)
    &&!$recentOnly['houseExitVerifiedBySensors'],
    'bounded 15 P03 events cannot disprove earlier two-person departure');
$truncated['p03LiveTimeline']['logContinuity']='LOG_GAP_OR_RESET';
$logGap=JVFieldObservationComparator::compare(
    $reference,$truncated,$at+3000
);
fieldCheck(in_array('P03_PROOF_LOG_CONTINUITY_NOT_CERTIFIED',
    $logGap['notes'],true)
    &&!$logGap['peopleIdentifiedBySensors'],
    'live streams alone do not certify continuity across lost proof log');
$truncated['p03LiveTimeline']['recentDirectionProofs'][14]['at']=$at+90;
$partialPositive=JVFieldObservationComparator::compare(
    $reference,$truncated,$at+3000
);
fieldCheck($partialPositive['p03AnonymousOutNearReference']===1
    &&!$partialPositive['carTripVerifiedBySensors']
    &&$partialPositive['sameNumberOfPeopleObserved']==='NOT_PROVABLE',
    'even one matched event amid truncated list does not identify two people');
$noPhoneHistory=$report;
unset($noPhoneHistory['residentEventFusion']['people']['carina']['eventHistory']);
$missingPhone=JVFieldObservationComparator::compare(
    $reference,$noPhoneHistory,$at+3000
);
fieldCheck($missingPhone['phoneHistoryCoverage']['carina']
    ==='NO_PERSONAL_EVENT_HISTORY_RECORDED'
    &&$missingPhone['phoneEventNearReference']['carina']
        ==='NO_COMPARABLE_PERSONAL_PHONE_OUT_NOT_DISPROOF',
    'never equate nonexistent historic geofence ledger with stayed home');
echo "Privacy-safe field truth versus anonymous evidence comparison PASS\n";
