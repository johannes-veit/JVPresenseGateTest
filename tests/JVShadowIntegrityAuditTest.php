<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVShadowIntegrityAudit.php';
function auditOK(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException('INTEGRITY '.$reason);
}
$t=1793000000;
$f=['schema'=>1,'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>['johannes'=>['eventHistory'=>[
        ['code'=>'PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC','at'=>$t-20],
        ['code'=>'PHONE_GEOFENCE_IN_OBSERVED','at'=>$t-90],
        ['code'=>'PHONE_GEOFENCE_OUT_OBSERVED','at'=>$t-300]
    ]],'carina'=>['eventHistory'=>[]]],
    'p03'=>['logContinuity'=>'OK','logInterrupted'=>false,
        'newAnonymousProofs'=>[]]];
$r=['schema'=>1,'authority'=>'ANONYMOUS_ROUTE_EPISODES_READ_ONLY',
    'lastEpisode'=>['kind'=>'ANONYMOUS_OUT_IN_CORRIDOR_SEQUENCE',
        'outAt'=>$t-500,'inAt'=>$t-50],
    'vehicleJourneyCertified'=>false,'personIdentityCertified'=>false];
$j=['schema'=>1,'authority'=>'READ_ONLY_RESIDENT_ROUTE_PHONE_ASSOCIATIONS',
    'candidateCount'=>1,'candidatePerson'=>'johannes',
    'phoneCandidates'=>[['person'=>'johannes']]];
$ready=['schema'=>1,'authority'=>'READ_ONLY_PRESENCE_EVIDENCE_READINESS',
    'mayArmAlarm'=>false,'maySwitchDevices'=>false];
$run=static function()use(&$f,&$r,&$j,&$ready,$t):array {
    return JVShadowIntegrityAudit::evaluate($f,$r,$j,$ready,$t);
};
$a=$run();
auditOK($a['status']==='CONSISTENT'&&$a['issues']===[],
    'ordered validated event history and anonymous route is consistent');
auditOK(!$a['humanIdentityCertified']&&!$a['mayArmAlarm'],
    'read-only consistency is never an occupancy authorization');
$f['people']['johannes']['eventHistory'][1]['at']=$t-10;
$a=$run();
auditOK(in_array('JOHANNES_EVENT_ORDER_INVALID',$a['issues'],true),
    'unsorted personal events are detected');
$f['people']['johannes']['eventHistory'][1]['at']=$t-90;
$f['p03']['logInterrupted']=true;
$f['p03']['logContinuity']='LOG_GAP_OR_RESET';
$f['p03']['newAnonymousProofs']=[['at'=>$t-5]];
$a=$run();
auditOK(in_array('P03_PROOF_ACROSS_LOG_GAP',$a['issues'],true)
    &&$a['p03LogInterrupted'],'no fresh P03 proof during source gap');
$f['p03']['newAnonymousProofs']=[];
$j['candidateCount']=2;
$a=$run();
auditOK(in_array('PERSONAL_JOURNEY_CANDIDATES_INCONSISTENT',$a['issues'],true),
    'invalid correlated phone cardinality noticed');
$j['candidateCount']=1;
$r['houseEmptyCertified']=true;
$a=$run();
auditOK(in_array('READ_ONLY_SAFETY_CONTRACT_VIOLATED',$a['issues'],true)
    &&in_array('UNAUTHORIZED_CAMERA_PERSON_OR_VEHICLE_CLAIM',$a['issues'],true),
    'illegal occupancy release never goes unnoticed');
$r['houseEmptyCertified']=false;
$f['people']['johannes']['eventHistory'][]=[
    'code'=>'PHONE_GEOFENCE_OUT_OBSERVED','at'=>$t-300
];
$a=$run();
auditOK(in_array('JOHANNES_EVENT_DUPLICATE',$a['issues'],true),
    'replayed individual event is flagged');
echo "SHADOW integrity audit PASS\n";
