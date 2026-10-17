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
require_once dirname(__DIR__).'/libs/JVResidentTileDashboard.php';
$w['enabled']=true;
$w['people']['carina']['validatedExistingVariablePair']=true;
$firm['people']['carina']=['sourceVerified'=>false];
$ca['validatedSource']=true;
$ca['lastEventAt']=$geo;
$ca['outsideRecent']=true;
$ca['geofenceReportedInside']=false;
$journey=evaluateDeparture();
$visu=['schema'=>1,'authority'=>'RESIDENT_PHONE_VISU_HINTS_READ_ONLY',
 'people'=>[
   'johannes'=>['decision'=>'UNKNOWN'],
   'carina'=>['decision'=>'LAST_KNOWN_UNCONFIRMED',
     'deviceEvidenceAt'=>null,'lastKnownDeviceAt'=>$mac,
     'geofenceEventFresh'=>true,'geofenceInsideSharedHomeWorkZone'=>false,
     'geofenceLastEventAgeSeconds'=>140]
 ]];
$h=JVResidentTileDashboard::evaluate($visu,$w,$firm,$p03,[],$now,$journey);
$html=JVResidentTileDashboard::render($h);
depOK($h['people']['carina']['status']==='ABWESEND (WAHRSCHEINLICH)'
    &&$h['people']['carina']['at']===$geo
    &&$h['people']['johannes']['status']==='NICHT BESTÄTIGT',
    'same live report yields Carina likely absent, Johannes unconfirmed');
depOK(str_contains($html,'ABWESEND (WAHRSCHEINLICH)')
    &&str_contains($html,'Letztes bestätigtes Geofence-OUT')
    &&str_contains($html,'Zu Fuß / Auto / Filzener Weg: nicht personenbezogen nachgewiesen')
    &&!str_contains($html,'Mit Auto abgefahren'),
    'current-status tile reports exact last phone OUT but does not invent driving');
depOK($h['mayArmAlarm']===false
    &&$h['mayTriggerAbsenceActions']===false,
    'combined tile cannot control physical presence or alarms');

// Production integration no longer trusts a current OUT snapshot alone.
// Require an individually observed OUT transition in the persistent ledger.
$authoritative=['schema'=>1,
    'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>['carina'=>['pendingGeofenceOut'=>null]]];
$withoutObservedOut=JVResidentDepartureInference::evaluate(
    $w,$firm,$jo,$ca,$p03,$now,$authoritative
);
depOK($withoutObservedOut['people']['carina']['assessment']==='NOT_ESTABLISHED'
    &&$withoutObservedOut['people']['carina']['reason']
        ==='NO_OBSERVED_PERSONAL_GEOFENCE_OUT_SEQUENCE',
    'stale OUT status without persistent per-device OUT is not departure');
$corrupt=JVResidentDepartureInference::evaluate(
    $w,$firm,$jo,$ca,$p03,$now,['schema'=>99,'authority'=>'UNTRUSTED']
);
depOK($corrupt['people']['carina']['assessment']==='NOT_ESTABLISHED'
    &&$corrupt['people']['carina']['reason']==='PERSONAL_EVENT_LEDGER_UNVERIFIED',
    'bad personal event ledger cannot fall back to older snapshot departure');
$authoritative['people']['carina']['pendingGeofenceOut']=$geo;
$verifiedPhoneOut=JVResidentDepartureInference::evaluate(
    $w,$firm,$jo,$ca,$p03,$now,$authoritative
);
depOK($verifiedPhoneOut['people']['carina']['assessment']==='LIKELY_PHONE_DEPARTED'
    &&!$verifiedPhoneOut['people']['carina']['humanAbsenceCertified'],
    'observed individual phone OUT enables phone-only departure hint');
$authoritative['people']['carina']['macSourceContinuous']=false;
$disconnectedMac=JVResidentDepartureInference::evaluate(
    $w,$firm,$jo,$ca,$p03,$now,$authoritative
);
depOK($disconnectedMac['people']['carina']['assessment']==='NOT_ESTABLISHED'
    &&$disconnectedMac['people']['carina']['reason']==='PHONE_SOURCE_SEQUENCE_INTERRUPTED',
    'WLAN source outage cannot establish personal departure from old MAC');
$authoritative['people']['carina']['macSourceContinuous']=true;
$authoritative['people']['carina']['geoSourceContinuous']=false;
$disconnectedGeo=JVResidentDepartureInference::evaluate(
    $w,$firm,$jo,$ca,$p03,$now,$authoritative
);
depOK($disconnectedGeo['people']['carina']['assessment']==='NOT_ESTABLISHED'
    &&$disconnectedGeo['people']['carina']['reason']==='PHONE_SOURCE_SEQUENCE_INTERRUPTED',
    'Geofence source outage prevents false personal departure');
$authoritative['people']['carina']['geoSourceContinuous']=true;
$authoritative['people']['carina']['lastSourceRebindAt']=$geo-1;
$changedSource=JVResidentDepartureInference::evaluate(
    $w,$firm,$jo,$ca,$p03,$now,$authoritative
);
depOK($changedSource['people']['carina']['assessment']==='NOT_ESTABLISHED'
    &&$changedSource['people']['carina']['reason']
        ==='PHONE_EVENTS_PRECEDE_CURRENT_SOURCE_BINDING',
    'historical home MAC predating new phone source cannot assert departure');
unset($authoritative['people']['carina']['lastSourceRebindAt']);
$authoritative['people']['carina']['pendingGeofenceOut']=$geo-60;
$disagreeingOut=JVResidentDepartureInference::evaluate(
    $w,$firm,$jo,$ca,$p03,$now,$authoritative
);
depOK($disagreeingOut['people']['carina']['assessment']==='NOT_ESTABLISHED',
    'unmatched event timestamp must not confirm departure');
echo "Simplified resident WLAN->Geofence departure and HTML tile safety scenarios PASS\n";
