<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPresenceEvidenceReadiness.php';
function readyAssert(bool $v,string $why):void{
    if(!$v)throw new RuntimeException('READINESS '.$why);
}
$mode=['schema'=>1,
    'authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
    'p03ModeTrustedFromFreshDiagnosis'=>true,
    'p03OperatingMode'=>'TEST_AND_PRODUCTION_OFF'];
$mast=['schema'=>1,'authority'=>'P03_EXISTING_MAST_OBSERVER_HEALTH_ONLY',
    'bothMastsLive'=>true,'mainP03ProofBlocked'=>false];
$home=['schema'=>1,'authority'=>'ANONYMOUS_HOME_OBSERVATION_ONLY',
    'sourcesHealthy'=>true,'observedHomeGUS'=>15];
$fusion=['schema'=>1,'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>['johannes'=>['state'=>'PHONE_HOME_MAC_RECENT'],
        'carina'=>['state'=>'PHONE_HOME_MAC_RECENT']]];
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'enabled'=>true,'people'=>[]];
$boundary=['schema'=>1,'authority'=>'READ_ONLY_BOUNDARY_CONTEXT',
    'vehicleSignals'=>0];
$journey=['schema'=>1,'authority'=>'READ_ONLY_RESIDENT_ROUTE_PHONE_ASSOCIATIONS',
    'status'=>'ANONYMOUS_ROUTE_NO_PERSONAL_PHONE_SEQUENCE'];
$run=static fn()=>JVPresenceEvidenceReadiness::evaluate(
    $mode,$mast,$fusion,$work,$boundary,$home,$journey,1800000000
);
$r=$run();
readyAssert($r['p03Mode']==='P03_INTENTIONALLY_OFF_NO_NEW_TRANSFERS'
    &&$r['mastStreams']==='BOTH_OBSERVERS_LIVE_NOT_PROOF_OF_TRANSITION',
    'P03 off distinct from two healthy streams');
readyAssert($r['roomMotionCoverage']==='FIFTEEN_GUS_SOURCES_VERIFIED_ANONYMOUS'
    &&$r['vehicleEvidenceSource']==='NO_VERIFIED_VEHICLE_DIRECTION_SOURCE',
    '15 GUS healthy and zero trusted vehicle direction sources');
readyAssert(!$r['canClaimVehicleTravel']&&!$r['mayArmAlarm']
    &&!$r['canCertifyHumanIdentity']&&!$r['canCertifyHouseEmpty'],
    'fail-closed controls and certifications');
readyAssert(count($r['nextEvidencePriority'])===3
    &&in_array('CARINA_VALIDATE_EXISTING_CURRENT_PERSONAL_SSID_SOURCE',
        $r['nextEvidencePriority'],true),
    'precise missing personal SSID and vehicle source worklist');
$mode['p03OperatingMode']='TEST_ACTIVE';
$work['people']['carina']=['sourceVerified'=>true,'status'=>'WORK_FIRMEN_WLAN_PHONE',
    'workRecent'=>true,'ageSeconds'=>20];
$journey['status']='ONE_PHONE_TIMELINE_COMPATIBLE_NOT_IDENTIFIED';
$boundary['vehicleSignals']=1;
$r=$run();
readyAssert($r['p03Mode']==='P03_TRANSFER_MODE_ACTIVE'
    &&$r['phones']['carina']['personalWorkSSIDCurrent']
    &&$r['vehicleEvidenceSource']
        ==='VEHICLE_VARIABLES_PRESENT_BUT_DIRECTION_NOT_CERTIFIED',
    'actual source improvements surfaced without allowing car claim');
readyAssert(!$r['canClaimVehicleTravel']
    &&$r['carJourneyAssessment']==='PHONE_TIMELINE_NEAR_ROUTE_BUT_AUTO_NOT_PROVEN',
    'single phone plus vehicle variable STILL no person car claim');
$mode['p03ModeTrustedFromFreshDiagnosis']=false;
readyAssert($run()['p03Mode']==='UNKNOWN_NOT_VERIFIED',
    'stale mode diagnosis never asserts P03 mode active');
echo "Presence evidence readiness PASS\n";
