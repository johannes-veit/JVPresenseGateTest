<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVResidentJourneyEvidence.php';
function journeyAssert(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('JOURNEY: '.$why);
}
$t=1800000000;
$route=['schema'=>1,'authority'=>'ANONYMOUS_ROUTE_EPISODES_READ_ONLY',
    'lastEpisode'=>['kind'=>'ANONYMOUS_OUT_IN_CORRIDOR_SEQUENCE',
        'outAt'=>$t-700,'inAt'=>$t-100,'durationSeconds'=>600,
        'rawStreetHumanNearby'=>true,'anonymousIndoorMotionBeforeOut'=>true,
        'anonymousIndoorMotionAfterIn'=>true,
        'vehicleVariableActiveNearby'=>false]];
$fusion=['schema'=>1,
    'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>[
        'johannes'=>['lastMacAt'=>$t-60,'lastEvent'=>null],
        'carina'=>['lastMacAt'=>$t-61,'lastEvent'=>null]
    ]];
$ssid=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'enabled'=>true,'people'=>[]];
$boundary=['schema'=>1,'authority'=>'READ_ONLY_BOUNDARY_CONTEXT',
    'vehicleSignals'=>0];
$run=static fn()=>JVResidentJourneyEvidence::evaluate(
    $route,$fusion,$ssid,$boundary,$t
);
$r=$run();
journeyAssert($r['status']==='ANONYMOUS_ROUTE_NO_PERSONAL_PHONE_SEQUENCE'
    &&$r['candidateCount']===0&&$r['candidatePerson']===null,
    'Johannes did walk, Carina stayed at home, both MAC refreshed');
journeyAssert($r['vehicleEvidence']==='NO_VEHICLE_SOURCE_MAPPED'
    &&$r['streetHumanOnly']===true
    &&!$r['vehicleJourneyCertified']&&!$r['identityAssigned'],
    'street human and no vehicle cannot prove a car');
$fusion['people']['johannes']['lastEvent']=[
    'code'=>'HOME_PHONE_REAPPEARED_AFTER_CONTACT_GAP','at'=>$t-90];
journeyAssert($run()['candidateCount']===0,
    'routine MAC reacquisition is NOT a personal return');
$fusion['people']['carina']['lastEvent']=[
    'code'=>'PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC','at'=>$t-55];
$r=$run();
journeyAssert($r['status']==='ONE_PHONE_TIMELINE_COMPATIBLE_NOT_IDENTIFIED'
    &&$r['candidateCount']===1&&$r['candidatePerson']==='carina'
    &&!$r['phoneCandidates'][0]['identityProof']
    &&!$r['phoneCandidates'][0]['vehicleProof'],
    'unique confirmed phone return is temporal context not camera identity');
$fusion['people']['johannes']['lastEvent']=[
    'code'=>'PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC','at'=>$t-48];
$r=$run();
journeyAssert($r['status']==='MULTIPLE_PHONE_TIMELINES_CANNOT_ATTRIBUTE_ROUTE'
    &&$r['candidateCount']===2 &&$r['candidatePerson']===null,
    'two phones near one anonymous camera route cannot assign identity');
$fusion['people']['johannes']['lastEvent']=[
    'code'=>'PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC','at'=>$t-3000];
journeyAssert($run()['candidateCount']===1,
    'old phone return not matched to new P03 episode');
// Vehicle boundary signal is context; even a matching ACTIVE contact
// does not establish vehicle direction or link a human identity.
$route['lastEpisode']['vehicleVariableActiveNearby']=true;
$boundary['vehicleSignals']=1;
$r=$run();
journeyAssert($r['vehicleEvidence']==='VEHICLE_SOURCE_ACTIVE_NEAR_ANONYMOUS_ROUTE_UNQUALIFIED'
    &&!$r['vehicleJourneyCertified']&&!$r['walkingCertified'],
    'vehicle variable near camera cannot classify travel mode');
$route['lastEpisode']['vehicleVariableActiveNearby']=false;
$fusion['people']['carina']['lastEvent']=null;
// Independent HOME MAC before P03 OUT and a current work SSID AFTER
// the crossing provides only a *handset* work progression, not identity.
$fusion['people']['carina']['lastMacAt']=$t-710;
$ssid['people']['carina']=['sourceVerified'=>true,'workRecent'=>true,
    'status'=>'WORK_FIRMEN_WLAN_PHONE','ageSeconds'=>80];
$workRoute=$run();
journeyAssert($workRoute['candidateCount']===1
    &&$workRoute['phoneCandidates'][0]['routeDirection']==='OUTBOUND'
    &&$workRoute['candidatePerson']==='carina',
    'verified personal work SSID after earlier home MAC is an OUT candidate');
$ssid['people']['carina']['ageSeconds']=500;
journeyAssert($run()['candidateCount']===0,
    'stale work SSID fails closed');
$ssid['people']['carina']['ageSeconds']=80;
$fusion['people']['carina']['lastMacAt']=$t-200;
journeyAssert($run()['candidateCount']===0,
    'home contact after P03 OUT defeats outbound evidence');
$fusion['people']['carina']['lastMacAt']=$t-710;
$ssid['people']['carina']['status']='WORK_OR_PRIVATE_PROPERTY_EXT_UNCERTAIN';
journeyAssert($run()['candidateCount']===0,
    'Privat-Veit-EXT is not a verified work location');
$ssid['people']['carina']['status']='WORK_FIRMEN_WLAN_PHONE';
$route['lastEpisode']['inAt']=$t-14600;
journeyAssert($run()['status']==='NO_RECENT_VERIFIED_ROUTE_EPISODE',
    'old P03 episode cannot create persistent false fresh trip');
echo "Resident journey evidence PASS\n";
