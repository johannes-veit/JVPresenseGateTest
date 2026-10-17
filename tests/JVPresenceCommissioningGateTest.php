<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPresenceCommissioningGate.php';
function gateCheck(bool $yes,string $case):void{
    if(!$yes)throw new RuntimeException('COMMISSIONING '.$case);
}
$t=1800000000;
$wlan=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'people'=>[
        'johannes'=>['validatedExistingVariablePair'=>true,
            'recentMacEvidence'=>true,'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT'],
        'carina'=>['validatedExistingVariablePair'=>true,
            'recentMacEvidence'=>true,'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT']
    ]];
$fusion=['schema'=>1,'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>[
        'johannes'=>['geoSourceContinuous'=>true,'sourceRebindRecent'=>false],
        'carina'=>['geoSourceContinuous'=>true,'sourceRebindRecent'=>false]
    ],'p03'=>['logInterrupted'=>false,'logContinuity'=>'OK']];
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'people'=>[]];
$ready=['schema'=>1,'authority'=>'READ_ONLY_PRESENCE_EVIDENCE_READINESS',
    'p03Mode'=>'P03_INTENTIONALLY_OFF_NO_NEW_TRANSFERS'];
$boundary=['schema'=>1,'authority'=>'READ_ONLY_BOUNDARY_CONTEXT',
    'vehicleSignals'=>0];
$supp=['authority'=>'ADDITIONAL_IPC_EVENT_HINTS_ONLY',
    'supplementaryHumanHint'=>true];
$number=['authority'=>'DAHUA_NUMBERSTAT_DIAGNOSTIC_ONLY',
    'status'=>'THRESHOLD_COUNTER_SNAPSHOT_ONLY'];
$audit=['schema'=>1,'authority'=>'SHADOW_INTEGRITY_AUDIT_READ_ONLY',
    'status'=>'CONSISTENT'];
$run=static function()use(
    &$wlan,&$fusion,&$work,&$ready,&$boundary,&$supp,&$number,&$audit,$t
):array {
    return JVPresenceCommissioningGate::evaluate(
        $wlan,$fusion,$work,$ready,$boundary,$supp,$number,$audit,$t
    );
};
$a=$run();
gateCheck($a['sources']['johannes']['homeMac']==='MAC_CONFIRMED_DEVICE_ONLY'
    &&$a['sources']['carina']['personalWorkSSID']
        ==='PERSONAL_SSID_SOURCE_MISSING',
    'validated home handset never silently serves as work SSID');
gateCheck($a['additionalCameras']==='IVS_HUMAN_ONLY_NOT_VEHICLE'
    &&$a['numberStat']==='THRESHOLD_COUNTER_SNAPSHOT_ONLY'
    &&$a['vehicle']==='NO_VERIFIED_DIRECTIONAL_VEHICLE_EVENT',
    'street Human and NumberStat threshold are not a vehicle direction');
gateCheck(in_array('P03_MODE_OFF_INTENTIONAL_NO_NEW_VERIFIED_ROUTE',
    $a['actions'],true)
    &&in_array('QUALIFY_EXISTING_VEHICLE_CLASS_AND_DIRECTION_SOURCE',
        $a['actions'],true),
    'concrete no-hardware automatic commissioning worklist');
gateCheck(!$a['canClaimIdentifiedPerson']&&!$a['canClaimPersonDrivingCar']
    &&!$a['canCertifyPropertyEmpty']&&!$a['mayArmAlarm']
    &&!$a['maySwitchDevices']&&$a['noNewSymconObjects'],
    'nothing can enable persons, car journeys, property empty or alarms');
// A duplicated pair requires repair before treating either device as home.
$wlan['people']['johannes']['state']='SOURCE_IDENTITY_COLLISION';
$wlan['people']['johannes']['validatedExistingVariablePair']=false;
$wlan['people']['johannes']['recentMacEvidence']=false;
$collision=$run();
gateCheck(in_array('JOHANNES_REPAIR_DUPLICATED_EXISTING_WLAN_IDS',
    $collision['actions'],true)
    &&$collision['sources']['johannes']['homeMac']==='DUPLICATE_OBJECT_SOURCE',
    'existing wrongly duplicated Symcon variable IDs reported');
$wlan['people']['johannes']['state']='MAC_CONFIRMED_RECENT_DEVICE_HINT';
$wlan['people']['johannes']['validatedExistingVariablePair']=true;
$wlan['people']['johannes']['recentMacEvidence']=true;
$fusion['people']['carina']['sourceRebindRecent']=true;
$rebound=$run();
gateCheck($rebound['sources']['carina']['sourceBindingChanged24h']===true
    &&in_array('CARINA_OBSERVE_NEW_PHONE_EVENTS_AFTER_REBIND',
        $rebound['actions'],true),
    'source replaced: historical phone arrival does not carry forward');
$fusion['people']['carina']['sourceRebindRecent']=false;
$work['people']['carina']=['sourceVerified'=>true,'status'=>'WORK_FIRMEN_WLAN_PHONE',
    'workRecent'=>true,'ageSeconds'=>40];
$ready['p03Mode']='P03_TRANSFER_MODE_ACTIVE';
$boundary['vehicleSignals']=1;
$better=$run();
gateCheck($better['sources']['carina']['personalWorkSSID']
    ==='CURRENT_WORK_HANDSET_HINT'
    &&$better['vehicle']==='CONTACT_OR_BOOLEAN_DIRECTION_UNKNOWN'
    &&!$better['canClaimPersonDrivingCar'],
    'even with live work SSID and vehicle boolean no identified car trip');
$work['people']['carina']['status']='WORK_OR_PRIVATE_PROPERTY_EXT_UNCERTAIN';
$ext=$run();
gateCheck($ext['sources']['carina']['personalWorkSSID']
    ==='SHARED_PROPERTY_SSID_AMBIGUOUS'
    &&in_array('CARINA_SHARED_SSID_CANNOT_DETERMINE_WORK',
        $ext['actions'],true),
    'shared EXT property coverage not a work journey');
$audit['status']='REVIEW_REQUIRED';
$bad=$run();
gateCheck($bad['state']==='SOURCE_CONSISTENCY_REVIEW'
    &&in_array('REVIEW_SHADOW_INTEGRITY_CODES',$bad['actions'],true),
    'failed integrity is a prioritized blocker');
$wlan['authority']='UNTRUSTED';
$invalid=$run();
gateCheck($invalid['state']==='INPUTS_UNVERIFIED'
    &&in_array('RECHECK_UNVERIFIED_READ_ONLY_INPUT_REPORTS',
        $invalid['actions'],true),
    'malformed source never claims commissioning complete');
echo "SHADOW commissioning gate PASS\n";
