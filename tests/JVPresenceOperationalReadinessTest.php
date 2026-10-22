<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPresenceOperationalReadiness.php';

function readinessAssert(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('OPERATIONAL_READINESS '.$why);
}
$now=1800000000;
$ssid=[
    'schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'people'=>[
        'johannes'=>['sourceVerified'=>true,'ageSeconds'=>1104,
            'status'=>'STALE_CURRENT_SSID_UNCONFIRMED'],
        'carina'=>['sourceVerified'=>true,'ageSeconds'=>300,
            'status'=>'HOME_SSID_PHONE_HINT']
    ]
];
$p03=[
    'schema'=>1,
    'authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
    'p03OperatingMode'=>'TEST_AND_PRODUCTION_OFF',
    'p03ModeTrustedFromFreshDiagnosis'=>true
];
$mast=[
    'schema'=>1,'authority'=>'P03_EXISTING_MAST_OBSERVER_HEALTH_ONLY',
    'bothMastsLive'=>true,'mainP03ProofBlocked'=>false
];
$off=JVPresenceOperationalReadiness::evaluate($ssid,$p03,$mast,$now);
readinessAssert($off['ssid']['johannes']['reason']==='PERSONAL_SSID_HEARTBEAT_TOO_OLD'
    &&!$off['ssid']['johannes']['currentWithinFiveMinutes'],
    'stale 1104 sec SSID stays unconfirmed');
readinessAssert($off['ssid']['carina']['currentWithinFiveMinutes']
    &&$off['ssid']['carina']['ssidClass']==='HOME_SSID_PHONE_HINT',
    'exact 300 sec is still current');
readinessAssert($off['p03']['operatingMode']==='TEST_AND_PRODUCTION_OFF'
    &&!$off['p03']['readyToObserveNewAnonymousRouteProofs']
    &&$off['p03']['productionEnableFunctionHasVerifiedGuards'],
    'P03 OFF cannot be silently activated');
readinessAssert(in_array('P03:CHECK_AUDIT_SIMULATION_TWO_OUT_TWO_IN_BEFORE_ENABLE',
    $off['actions'],true),'correct multi-camera commissioning route');

$ssid['people']['johannes']=['sourceVerified'=>true,'ageSeconds'=>301,
    'status'=>'WORK_FIRMEN_WLAN_PHONE'];
$edge=JVPresenceOperationalReadiness::evaluate($ssid,$p03,$mast,$now);
readinessAssert(!$edge['ssid']['johannes']['currentWithinFiveMinutes'],
    '301 sec must not remain current WORK');
$ssid['people']['johannes']['ageSeconds']=0;
$ssid['people']['carina']['sourceVerified']=false;
$unverified=JVPresenceOperationalReadiness::evaluate($ssid,$p03,$mast,$now);
readinessAssert($unverified['ssid']['johannes']['currentWithinFiveMinutes']
    &&!$unverified['ssid']['carina']['currentWithinFiveMinutes'],
    'one phone source never fills the other');
$p03['p03OperatingMode']='PRODUCTION_ACTIVE';
$active=JVPresenceOperationalReadiness::evaluate($ssid,$p03,$mast,$now);
readinessAssert($active['p03']['readyToObserveNewAnonymousRouteProofs']
    &&$active['p03']['newVerifiedDirectionProofsRequired'],
    'production active is only eligibility, not actual proof');
$mast['mainP03ProofBlocked']=true;
$blocked=JVPresenceOperationalReadiness::evaluate($ssid,$p03,$mast,$now);
readinessAssert(!$blocked['p03']['readyToObserveNewAnonymousRouteProofs']
    &&in_array('P03:MAIN_PROOF_BLOCKED_INSPECT_IVS_RULE_BINDING',
        $blocked['actions'],true),'main proof blocking wins');
$mast['mainP03ProofBlocked']=false;
// P03 multi-camera diagnostic matches the exact runtime eligibility.
$p03['p03ModeTrustedFromFreshDiagnosis']=true;
$p03['p03OperatingMode']='TEST_AND_PRODUCTION_OFF';
$missing='Zeit: 2026-10-22 21:40:00 | AUTOMATISCH'
    ."\nTEST: AUS | PRODUKTION: AUS"
    ."\nP03_VORFREIGABE: AUDIT=JA | SIMULATION=NEIN | OUT=1 | IN=0"
    .' | GESAMT=1 | AUX_ZAEHLER=JA | MASTS=JA | FREIGABE=NEIN';
$missingState=JVPresenceOperationalReadiness::evaluate(
    $ssid,$p03,$mast,$now,$missing
);
readinessAssert(
    $missingState['p03']['productionPreflight']['verifiedFromFreshDiagnostic']
    &&!$missingState['p03']['productionPreflight']['eligibleForManualProductionEnable']
    &&in_array('P03_SIMULATION',
        $missingState['p03']['productionPreflight']['missing'],true),
    'fresh exact missing preflight guard extracted');
$complete=str_replace(
    'SIMULATION=NEIN | OUT=1 | IN=0 | GESAMT=1',
    'SIMULATION=JA | OUT=2 | IN=2 | GESAMT=4',
    str_replace('FREIGABE=NEIN','FREIGABE=JA',$missing)
);
$readyManually=JVPresenceOperationalReadiness::evaluate(
    $ssid,$p03,$mast,$now,$complete
);
readinessAssert(
    $readyManually['p03']['reason']
    ==='P03_PRODUCTION_OFF_ELIGIBLE_FOR_MANUAL_ENABLE'
    &&!$readyManually['p03']['readyToObserveNewAnonymousRouteProofs'],
    'eligible only manual production, never active or proof');
$contradiction=str_replace('FREIGABE=JA','FREIGABE=NEIN',$complete);
$reject=JVPresenceOperationalReadiness::evaluate(
    $ssid,$p03,$mast,$now,$contradiction
);
readinessAssert(!$reject['p03']['productionPreflight']['verifiedFromFreshDiagnostic'],
    'contradictory claimed preflight fails closed');
$mast['mainP03ProofBlocked']=false;
$mast['bothMastsLive']=false;
$down=JVPresenceOperationalReadiness::evaluate($ssid,$p03,$mast,$now);
readinessAssert(!$down['p03']['readyToObserveNewAnonymousRouteProofs'],
    'both mast streams mandatory');
$mast['bothMastsLive']=true;
$p03['p03ModeTrustedFromFreshDiagnosis']=false;
$oldMode=JVPresenceOperationalReadiness::evaluate($ssid,$p03,$mast,$now);
readinessAssert($oldMode['p03']['operatingMode']==='UNKNOWN_NOT_VERIFIED'
    &&!$oldMode['p03']['readyToObserveNewAnonymousRouteProofs'],
    'stale mode is never a production proof');
readinessAssert($off['mayChangePresence']===false
    &&$off['mayCertifyEmptyHouse']===false
    &&$off['maySwitchP03OrCameras']===false
    &&$off['mayArmAlarm']===false
    &&$off['newSymconVariables']===0,'no actions or new variables');
echo "Read-only SSID heartbeat and guarded P03 commissioning PASS\n";
