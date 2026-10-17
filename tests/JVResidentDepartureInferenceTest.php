<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVResidentDepartureInference.php';
function depOK(bool $ok,string $text):void {
    if(!$ok)throw new RuntimeException('DEPARTURE_LOGIC: '.$text);
}
$now=(new DateTimeImmutable('2026-10-17 15:30:23',
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$mac=$now-323; // confirmed Carina home WLAN at 15:25:00
$geo=$now-140; // phone Geofence OUT at 15:28:03
$w=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>[
        'carina'=>['validatedExistingVariablePair'=>true,
            'lastMacContactAt'=>$mac,'wlanBoolean'=>true,
            'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT'],
        'johannes'=>['validatedExistingVariablePair'=>true,
            'lastMacContactAt'=>$now-3916,'wlanBoolean'=>false]
    ]];
$firm=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'enabled'=>true,'people'=>[
        'carina'=>['sourceVerified'=>false,'status'=>'NOT_CONFIGURED_OR_UNVERIFIED'],
        'johannes'=>['sourceVerified'=>false]
    ]];
$jo=['schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,'outsideRecent'=>false,
    'lastEventAt'=>$now-151403,'geofenceReportedInside'=>true];
$ca=['schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,'outsideRecent'=>true,
    'lastEventAt'=>$geo,'geofenceReportedInside'=>false];
$p03=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'recentDirectionProofs'=>[]];
function evaluateDeparture():array{
    global $w,$firm,$jo,$ca,$p03,$now;
    return JVResidentDepartureInference::evaluate($w,$firm,$jo,$ca,$p03,$now);
}
$me=evaluateDeparture();
depOK($me['people']['carina']['assessment']==='LIKELY_PHONE_DEPARTED'
    &&$me['people']['carina']['eventAt']===$geo
    &&$me['people']['johannes']['assessment']==='NOT_ESTABLISHED',
    'user supplied verified real 15:30 case correctly infers Carina phone OUT, Johannes unknown');
depOK(($me['vehicleSourceAvailable']??false)===false
    &&($me['humanAbsenceCertified']??true)===false
    &&($me['mayChangePresenceOrAlarm']??true)===false
    &&$me['people']['carina']['transport']==='UNVERIFIED',
    'no auto, name-by-camera, occupancy certificate or alarm permission');
$p03['recentDirectionProofs']=[
    ['at'=>$geo-180,'source'=>'P03_TWO_MAST_VERIFIED',
        'direction'=>'HOME_TO_LAGER']
];
$hasP03=evaluateDeparture();
depOK($hasP03['people']['carina']['p03AnonymousExitNearby']===true
    &&$hasP03['people']['carina']['transport']==='UNVERIFIED',
    'anonymous real mast direction adds context but does not certify vehicle');
$p03['recentDirectionProofs']=[
    ['at'=>$geo-180,'source'=>'P03_TWO_MAST_VERIFIED',
        'direction'=>'LAGER_TO_HOME']
];
depOK(evaluateDeparture()['people']['carina']['p03AnonymousExitNearby']===false,
    'opposite P03 direction cannot corroborate exit');
$p03['recentDirectionProofs']=[];
$w['people']['carina']['lastMacContactAt']=$geo+15;
depOK(evaluateDeparture()['people']['carina']['assessment']!=='LIKELY_PHONE_DEPARTED',
    'home WiFi after reported geo OUT prevents false departure');
$w['people']['carina']['lastMacContactAt']=$mac;
$ca['geofenceReportedInside']=true;
$ca['outsideRecent']=false;
depOK(evaluateDeparture()['people']['carina']['assessment']!=='LIKELY_PHONE_DEPARTED',
    'geofence IN is not OUT');
$ca['geofenceReportedInside']=false;$ca['outsideRecent']=true;
$ca['lastEventAt']=$now-60;
depOK(evaluateDeparture()['people']['carina']['assessment']!=='LIKELY_PHONE_DEPARTED',
    'fresh GPS out jitter waits at least 120 seconds');
$ca['lastEventAt']=$geo;
$w['people']['carina']['lastMacContactAt']=$now-200;
depOK(evaluateDeparture()['people']['carina']['assessment']!=='LIKELY_PHONE_DEPARTED',
    'recent MAC confirmation still validates HOME');
$w['people']['carina']['lastMacContactAt']=$now-3600;
depOK(evaluateDeparture()['people']['carina']['assessment']!=='LIKELY_PHONE_DEPARTED',
    'unrelated ancient home WiFi cannot combine with later geofence');
$w['people']['carina']['lastMacContactAt']=$mac;
$ca['lastEventAt']=$now-15000;
depOK(evaluateDeparture()['people']['carina']['assessment']!=='LIKELY_PHONE_DEPARTED',
    '4 hour expired OUT not permanent away');
$ca['lastEventAt']=$geo;
$firm['people']['carina']=['sourceVerified'=>true,
    'status'=>'HOME_SSID_PHONE_HINT','homeSsidRecent'=>true,'ageSeconds'=>20];
depOK(evaluateDeparture()['people']['carina']['assessment']!=='LIKELY_PHONE_DEPARTED',
    'fresh personal HOME SSID contradicts stale earlier geo OUT');
$firm['people']['carina']=['sourceVerified'=>true,
    'status'=>'WORK_FIRMEN_WLAN_PHONE','workRecent'=>true,'ageSeconds'=>20];
depOK(evaluateDeparture()['people']['carina']['assessment']!=='LIKELY_PHONE_DEPARTED',
    'direct WORK SSID takes precedence over general away inference');
$firm['people']['carina']=['sourceVerified'=>false];
$ca['validatedSource']=false;
depOK(evaluateDeparture()['people']['carina']['assessment']!=='LIKELY_PHONE_DEPARTED',
    'wrong Geofency ID or parent metadata fails closed');
$ca['validatedSource']=true;
$w['people']['carina']['validatedExistingVariablePair']=false;
depOK(evaluateDeparture()['people']['carina']['assessment']!=='LIKELY_PHONE_DEPARTED',
    'wrong WiFi variable metadata fails closed');
$w['people']['carina']['validatedExistingVariablePair']=true;
$w['enabled']=false;
depOK(evaluateDeparture()['people']['carina']['assessment']!=='LIKELY_PHONE_DEPARTED',
    'disabled read source cannot assert away');
echo "Simplified resident WLAN->Geofence departure 15 real-case and safety scenarios PASS\n";
