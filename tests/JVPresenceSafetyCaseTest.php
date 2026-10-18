<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPresenceSafetyCase.php';
function safetyOK(bool $x,string $why):void {
    if(!$x)throw new RuntimeException('SAFETY_CASE '.$why);
}
$t=1800000000;
$home=['schema'=>1,'authority'=>'ANONYMOUS_HOME_OBSERVATION_ONLY',
    'sourcesHealthy'=>true,'lastHomeMotionAt'=>$t-30];
$wlan=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'people'=>[
        'johannes'=>['state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
            'validatedExistingVariablePair'=>true,'recentMacEvidence'=>true],
        'carina'=>['state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
            'validatedExistingVariablePair'=>true,'recentMacEvidence'=>true]]];
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'people'=>[]];
$boundary=['schema'=>1,'authority'=>'READ_ONLY_BOUNDARY_CONTEXT',
    'vehicleSignals'=>0,'recentChanges'=>[]];
$route=['schema'=>1,'authority'=>'ANONYMOUS_ROUTE_EPISODES_READ_ONLY',
    'ambiguousOutstanding'=>0,'lastEpisode'=>null];
$journey=['schema'=>1,'authority'=>'READ_ONLY_RESIDENT_ROUTE_PHONE_ASSOCIATIONS',
    'candidateCount'=>0,'candidatePerson'=>null,'phoneCandidates'=>[]];
$gate=['schema'=>1,'authority'=>'PRESENCE_READ_ONLY_COMMISSIONING_GATE'];
$audit=['schema'=>1,'authority'=>'SHADOW_INTEGRITY_AUDIT_READ_ONLY',
    'status'=>'CONSISTENT'];
$upgrade=['schema'=>1,'authority'=>'READ_ONLY_SHADOW_UPGRADE_PREFLIGHT',
    'safeToRefresh'=>true];
$run=static function()use(
    &$home,&$wlan,&$work,&$boundary,&$route,&$journey,&$gate,
    &$audit,&$upgrade,$t
):array {
    return JVPresenceSafetyCase::evaluate(
        $home,$wlan,$work,$boundary,$route,$journey,$gate,
        $audit,$upgrade,$t
    );
};
$a=$run();
safetyOK($a['status']==='READ_ONLY_EVIDENCE_EVALUATED_NO_HUMAN_CERTIFICATE'
    &&$a['homeActivity']==='ANONYMOUS_INDOOR_MOTION_RECENT'
    &&$a['homePhoneHints']===2
    &&$a['propertyEmpty']==='UNKNOWN'
    &&!$a['mayArmAlarm'],'two home handsets plus GUS only positive context');
foreach(['johannes','carina'] as $p)
    $wlan['people'][$p]['recentMacEvidence']=false;
$home['lastHomeMotionAt']=$t-7200;
$quiet=$run();
safetyOK($quiet['homeActivity']==='QUIET_NO_ABSENCE_PROOF'
    &&$quiet['homePhoneHints']===0
    &&$quiet['allResidentsAway']==='UNKNOWN'
    &&$quiet['propertyEmpty']==='UNKNOWN'
    &&!$quiet['propertyEmptyCertified'],
    'no wifi or GUS for two hours does not mean nobody home');
$work['people']['carina']=['sourceVerified'=>true,
    'status'=>'WORK_FIRMEN_WLAN_PHONE','workRecent'=>true,
    'ageSeconds'=>40];
$boundary['vehicleSignals']=1;
$boundary['recentChanges']=[[
    'kind'=>'VEHICLE','state'=>'ACTIVE','at'=>$t-15]];
$route['lastEpisode']=[
    'kind'=>'ANONYMOUS_OUT_IN_CORRIDOR_SEQUENCE',
    'outAt'=>$t-160,'inAt'=>$t-45
];
$journey['candidateCount']=1;$journey['candidatePerson']='carina';
$journey['phoneCandidates']=[['person'=>'carina']];
$vehicle=$run();
safetyOK($vehicle['workPhoneHints']===1
    &&$vehicle['cameraRoute']==='ANONYMOUS_HOUSE_LAGER_SEQUENCE'
    &&$vehicle['vehicle']==='RECENT_VEHICLE_CONTACT_DIRECTION_UNKNOWN'
    &&!$vehicle['vehicleTravelCertified']
    &&!$vehicle['walkingCertified']
    &&$vehicle['identifiedHumanAtHome']==='UNKNOWN'
    &&$vehicle['propertyEmpty']==='UNKNOWN',
    'work SSID, P03 route and gate vehicle contact still do not identify driver');
$route['ambiguousOutstanding']=1;
safetyOK($run()['cameraRoute']==='AMBIGUOUS_MULTIPERSON_QUARANTINE',
    'unresolved anonymous people cannot be route-certified');
$audit['status']='REVIEW_REQUIRED';
$blocked=$run();
safetyOK($blocked['status']==='INTEGRITY_OR_UPGRADE_REVIEW'
    &&!$blocked['safeToEvaluate']&&!$blocked['maySwitchDevices'],
    'cross-ledger contradiction halts evidence assessment');
$audit['status']='CONSISTENT';$upgrade['safeToRefresh']=false;
safetyOK($run()['status']==='INTEGRITY_OR_UPGRADE_REVIEW',
    'corrupted upgrade ledger blocks all downstream claims');
$upgrade['safeToRefresh']=true;
$work['authority']='MALFORMED';
$untrusted=$run();
safetyOK($untrusted['status']==='UNVERIFIED_SOURCES'
    &&!$untrusted['mayArmAlarm']&&!$untrusted['humanIdentityCertified'],
    'untrusted work SSID input never establishes property vacancy');
echo "FINAL home vehicle and absence safety case PASS\n";
