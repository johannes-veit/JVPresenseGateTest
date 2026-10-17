<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVExistingWlanPhoneHints.php';
$tests=0;
function wlanAssert(bool $v,string $why):void {
    global $tests;$tests++;
    if(!$v)throw new RuntimeException('FAIL: '.$why);
}
$now=1792097000;
$online=[
 'johannes'=>['verified'=>true,'wlanValue'=>true,'lastContact'=>$now-80],
 'carina'=>['verified'=>true,'wlanValue'=>true,'lastContact'=>$now-40]
];
$both=JVExistingWlanPhoneHints::evaluate($online,$now,true,true);
wlanAssert($both['freshMacConfirmedDeviceCount']===2,
    'both successful ARP+MAC smartphone sources are independent device hints');
wlanAssert($both['combinedContext']==='HOUSE_MOTION_AND_TWO_WLAN_DEVICES',
    'WLAN indicators supplement LCN GUS without claiming two people');
wlanAssert($both['people']['carina']['state']==='MAC_CONFIRMED_RECENT_DEVICE_HINT',
    'iPhone standby ping failure with MAC-validated ARP remains positive');
wlanAssert($both['actualResidentsPresent']==='UNKNOWN'
    &&$both['exactPersonsAtHome']==='UNKNOWN',
    'two devices never certify two residents at home');
wlanAssert($both['propertyEmptyMayBeCertified']===false
    &&$both['personPresenceMayBeChanged']===false
    &&$both['alarmMayBeControlled']===false,
    'no effect on presence, property absence or alarm');
// A duplicated/cross-wired Symcon mapping is one handset, never
// two different residents. Fail closed rather than double-count.
$duplicate=$online;
$duplicate['johannes']['boolId']=19980;
$duplicate['johannes']['lastContactId']=26497;
$duplicate['carina']['boolId']=19980;
$duplicate['carina']['lastContactId']=26497;
$dup=JVExistingWlanPhoneHints::evaluate($duplicate,$now,true);
wlanAssert($dup['freshMacConfirmedDeviceCount']===0
    &&$dup['phonesUnavailableOrUnconfirmed']===2
    &&$dup['combinedContext']==='NO_FRESH_WLAN_NOT_ABSENCE',
    'same source selected twice never presents two fresh resident phones');
wlanAssert($dup['people']['johannes']['state']==='SOURCE_IDENTITY_COLLISION'
    &&$dup['people']['carina']['state']==='SOURCE_IDENTITY_COLLISION'
    &&!$dup['people']['johannes']['validatedExistingVariablePair']
    &&!$dup['people']['carina']['validatedExistingVariablePair'],
    'both duplicated WLAN bindings isolated without inventing absence');
wlanAssert(in_array('BOTH_RESIDENTS_SHARE_ONE_WLAN_SOURCE',
    $dup['warnings'],true),'duplicate source is explicitly diagnosable');
$off=$online;
$off['carina']['wlanValue']=false;
$one=JVExistingWlanPhoneHints::evaluate($off,$now,true,false);
wlanAssert($one['freshMacConfirmedDeviceCount']===1
    &&$one['people']['carina']['state']==='NO_RECENT_MAC_CONFIRMATION_NOT_ABSENCE',
    'Carina WLAN off means no MAC confirmation, NOT human absent');
wlanAssert($one['people']['carina']['humanAbsentCertified']===false,
    'never infer Carina outside from bool false');
$stale=$online;
$stale['carina']['lastContact']=$now-661;
$expired=JVExistingWlanPhoneHints::evaluate($stale,$now,true,true);
wlanAssert($expired['freshMacConfirmedDeviceCount']===1
    &&$expired['people']['carina']['state']==='STALE_TRUE_CANNOT_CONFIRM_PRESENCE',
    'old TRUE cannot stay positive after 660-second time limit');
$border=$online;$border['carina']['lastContact']=$now-660;
wlanAssert(JVExistingWlanPhoneHints::evaluate($border,$now,true,false)
    ['people']['carina']['recentMacEvidence']===true,
    'last fresh second of grace is valid');
$bad=$online;$bad['johannes']['lastContact']=$now+90;
$future=JVExistingWlanPhoneHints::evaluate($bad,$now,true,false);
wlanAssert($future['people']['johannes']['recentMacEvidence']===false,
    'future Unix timestamp fails closed');
$bad['johannes']['verified']=false;
$unbound=JVExistingWlanPhoneHints::evaluate($bad,$now,true,true);
wlanAssert($unbound['people']['johannes']['state']==='SOURCE_NOT_VERIFIED',
    'reused/missing/wrong ID, name, type or parent is UNKNOWN');
$disabled=JVExistingWlanPhoneHints::evaluate($online,$now,false,true);
wlanAssert($disabled['freshMacConfirmedDeviceCount']===0
    &&$disabled['people']['carina']['state']==='DISABLED',
    'default OFF means no device readings may be promoted');
$none=JVExistingWlanPhoneHints::evaluate([],$now,true,false);
wlanAssert($none['combinedContext']==='NO_FRESH_WLAN_NOT_ABSENCE'
    &&$none['actualResidentsPresent']==='UNKNOWN',
    'total network outage never means empty house');
wlanAssert($both['wlanScriptsModified']===false
    &&$both['wlanCategoriesOrEventsCreated']===false,
    'pure evaluator never mutates scripts or creates Symcon objects');
echo "Two existing WLAN phone scripts, 660s standby safety, false != away PASS ($tests checks)\n";
