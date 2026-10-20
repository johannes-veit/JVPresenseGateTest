<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPresenceCommissioningGate.php';
require_once dirname(__DIR__).'/libs/JVFieldObservationComparator.php';
function finalCheck90(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('SHADOW_0_8_90 '.$why);
}
$now=1900000000;
$wlan=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'people'=>[
        'johannes'=>['validatedExistingVariablePair'=>false,
            'state'=>'UNVERIFIED'],
        'carina'=>['validatedExistingVariablePair'=>false,
            'state'=>'UNVERIFIED']
    ]];
$fusion=['schema'=>1,'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>[
        'johannes'=>['geoSourceContinuous'=>false,
            'macSourceContinuous'=>false,'sourceRebaselineRequired'=>true],
        'carina'=>['geoSourceContinuous'=>false,
            'macSourceContinuous'=>false,'sourceRebaselineRequired'=>true]
    ],
    'p03'=>['logContinuity'=>'OK','logInterrupted'=>false]];
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'people'=>[]];
$ready=['schema'=>1,'authority'=>'READ_ONLY_PRESENCE_EVIDENCE_READINESS',
    'p03Mode'=>'P03_INTENTIONALLY_OFF_NO_NEW_TRANSFERS',
    'mastStreams'=>'ONE_OR_BOTH_OBSERVERS_NOT_VERIFIED',
    'roomMotionCoverage'=>'GUS_COVERAGE_NOT_FULLY_VERIFIED'];
$boundary=['schema'=>1,'authority'=>'READ_ONLY_BOUNDARY_CONTEXT',
    'vehicleSignals'=>0];
$audit=['schema'=>1,'authority'=>'SHADOW_INTEGRITY_AUDIT_READ_ONLY',
    'status'=>'REVIEW_REQUIRED'];
$check=static function()use(&$wlan,&$fusion,&$work,&$ready,&$boundary,&$audit,$now):array{
    return JVPresenceCommissioningGate::evaluate(
        $wlan,$fusion,$work,$ready,$boundary,[],[],$audit,$now
    );
};
$blocked=$check();
finalCheck90($blocked['state']==='SOURCE_CONSISTENCY_REVIEW'
    &&$blocked['actionCount']>8
    &&$blocked['actionsTruncated']
    &&$blocked['actions'][0]==='REVIEW_SHADOW_INTEGRITY_CODES'
    &&in_array('P03_MODE_OFF_INTENTIONAL_NO_NEW_VERIFIED_ROUTE',
        array_slice($blocked['actions'],0,3),true)
    &&in_array('QUALIFY_EXISTING_VEHICLE_CLASS_AND_DIRECTION_SOURCE',
        $blocked['actions'],true)
    &&!$blocked['mayArmAlarm'],
    'critical integrity/P03/vehicle blockers survive the 8-entry limit');
$wlan['authority']='UNTRUSTED';
$unverified=$check();
finalCheck90($unverified['state']==='INPUTS_UNVERIFIED'
    &&$unverified['actions'][0]==='REVIEW_SHADOW_INTEGRITY_CODES'
    &&in_array('RECHECK_UNVERIFIED_READ_ONLY_INPUT_REPORTS',
        array_slice($unverified['actions'],0,3),true),
    'unverified source and integrity review outrank optional SSIDs');
$wlan['authority']='EXISTING_WLAN_MAC_HINTS_READ_ONLY';
$audit['status']='CONSISTENT';
$ready['p03Mode']='P03_TRANSFER_MODE_ACTIVE';
foreach(['johannes','carina'] as $person){
    $wlan['people'][$person]=[
        'validatedExistingVariablePair'=>true,
        'recentMacEvidence'=>true,
        'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT'
    ];
    $fusion['people'][$person]['geoSourceContinuous']=true;
    $fusion['people'][$person]['macSourceContinuous']=true;
    $fusion['people'][$person]['sourceRebaselineRequired']=false;
}
$better=$check();
finalCheck90($better['state']
    ==='CORE_READ_ONLY_VALIDATED_MISSING_IDENTITY_AND_VEHICLE_PROOFS'
    &&$better['canObserveInShadowReadOnly']
    &&!in_array('P03_MODE_OFF_INTENTIONAL_NO_NEW_VERIFIED_ROUTE',
        $better['actions'],true)
    &&$better['actions'][0]==='VERIFY_EXISTING_P03_MAST_HEALTH_READ_ONLY'
    &&!$better['canCertifyPropertyEmpty'],
    'better telemetry removes obsolete P03 OFF warning but not safety limits');

// Berlin winter clock rollback: 02:30 on 2026-10-25 occurs twice.
// User-provided HH:MM without an offset must never pick a false
// matching camera event. Both ordinary weekdays remain comparable.
$truth=static fn(string $time):array=>[
    'schema'=>1,'authority'=>'USER_REPORTED_FIELD_OBSERVATION',
    'localTime'=>$time,'reportedHouseLeavers'=>['johannes','carina']
];
$after=(new DateTimeImmutable('2026-10-25 12:00:00',
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$dst=JVFieldObservationComparator::compare(
    $truth('2026-10-25 02:30'),[],$after);
finalCheck90($dst['status']==='AMBIGUOUS_LOCAL_TIME_DST_ROLLBACK'
    &&$dst['referenceAt']===null
    &&$dst['alarmMayBeArmed']===false
    &&in_array('LOCAL_TIME_NEEDS_EXPLICIT_OFFSET',
        $dst['notes'],true),
    'ambiguous real autumn 02:30 cannot select an invented historic event');
$before=(new DateTimeImmutable('2026-10-24 12:00:00',
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$ordinary=JVFieldObservationComparator::compare(
    $truth('2026-10-24 02:30'),[],$before);
finalCheck90($ordinary['referenceAt']!==null
    &&$ordinary['status']!=='AMBIGUOUS_LOCAL_TIME_DST_ROLLBACK',
    'ordinary local time remains accepted');
$afterNormal=JVFieldObservationComparator::compare(
    $truth('2026-10-25 03:30'),[],$after);
finalCheck90($afterNormal['referenceAt']!==null
    &&$afterNormal['status']!=='AMBIGUOUS_LOCAL_TIME_DST_ROLLBACK',
    '03:30 after Berlin clock fallback is unambiguous');
$spring=(new DateTimeImmutable('2027-03-28 12:00:00',
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$springGap=JVFieldObservationComparator::compare(
    $truth('2027-03-28 02:30'),[],$spring);
finalCheck90($springGap['status']==='NOT_COMPARABLE'
    &&$springGap['referenceAt']===null,
    'nonexistent spring 02:30 remains rejected');
echo "SHADOW v0.8.90: prioritized top3, action count/truncation, DST fallback/spring transitions PASS\n";
