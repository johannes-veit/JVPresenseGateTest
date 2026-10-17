<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVExistingWlanPhoneHints.php';
require_once dirname(__DIR__).'/libs/JVCompanyWiFiEvidence.php';
require_once dirname(__DIR__).'/libs/JVJohannesEgiGeoZoneHints.php';
require_once dirname(__DIR__).'/libs/JVCarinaGeofencyHints.php';
require_once dirname(__DIR__).'/libs/JVResidentVisuState.php';
require_once dirname(__DIR__).'/libs/JVResidentDepartureInference.php';
require_once dirname(__DIR__).'/libs/JVResidentTileDashboard.php';

function latestAssert(bool $condition,string $reason):void{
    if(!$condition)throw new RuntimeException('LATEST_SIGNAL: '.$reason);
}
$now=(new DateTimeImmutable('2026-10-17 16:29:50',
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$lastJoh=(new DateTimeImmutable('2026-10-17 16:25:06',
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$lastCarina=(new DateTimeImmutable('2026-10-17 15:25:00',
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$geoCarina=(new DateTimeImmutable('2026-10-17 16:29:32',
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$oldGeo=(new DateTimeImmutable('2026-10-15 21:27:00',
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$wlan=JVExistingWlanPhoneHints::evaluate([
    'johannes'=>['verified'=>true,'wlanValue'=>true,'lastContact'=>$lastJoh],
    'carina'=>['verified'=>true,'wlanValue'=>false,'lastContact'=>$lastCarina]
],$now,true);
$company=JVCompanyWiFiEvidence::evaluate([],$now,true);
$jGeo=JVJohannesEgiGeoZoneHints::evaluate([
    'verified'=>true,'inside'=>true,'timestamp'=>$oldGeo
],$now,true,150);
$cGeo=JVCarinaGeofencyHints::evaluate([
    'verified'=>true,'inside'=>true,'timestamp'=>$geoCarina,'zoneName'=>'Zuhause'
],$now,true,'AUTO_READ_ONLY');
$prior=['schema'=>1,'people'=>['carina'=>[
    'zone'=>'HOME','at'=>$lastCarina
]]];
$visu=JVResidentVisuState::evaluate(
    $wlan,$company,$jGeo,$cGeo,[],[],$prior,$now
);
$person=$visu['report']['people'];
latestAssert($person['johannes']['decision']==='HOME_PHONE_CONFIRMED'
    &&$person['carina']['decision']==='LAST_KNOWN_UNCONFIRMED',
    'real report: Johannes home, Carina not confirmed');
latestAssert($person['carina']['geofenceLastEventAt']===$geoCarina
    &&$person['carina']['geofenceLastEventKind']==='IN'
    &&$person['johannes']['geofenceLastEventAt']===null,
    'capture Carina real Geo-IN 16:29:32 but not old Johannes geofence');
$depart=JVResidentDepartureInference::evaluate(
    $wlan,$company,$jGeo,$cGeo,[],$now
);
latestAssert($depart['people']['carina']['assessment']==='NOT_ESTABLISHED',
    'new geo IN invalidates the older OUT departure inference');
$tile=JVResidentTileDashboard::evaluate(
    $visu['report'],$wlan,$company,[],[],$now,$depart
);
$carina=$tile['people']['carina'];$joh=$tile['people']['johannes'];
$html=JVResidentTileDashboard::render($tile);
latestAssert($carina['status']==='NICHT BESTÄTIGT'
    &&$carina['at']===$geoCarina
    &&str_contains($carina['timeMeaning'],'Geofence-IN')
    &&str_contains($carina['qualifier'],'Zuhause nicht bestätigt'),
    'newest Carina phone event is Geo IN, no false HOME');
latestAssert($joh['status']==='ANWESEND'
    &&$joh['at']===$lastJoh
    &&str_contains($joh['wlanHome'],'nächste WLAN-Prüfung abwarten'),
    'Johannes 284 sec home MAC is fresh but approaching exact five-minute poll');
latestAssert(str_contains($carina['wlanHome'],'15:25:00')
    &&str_contains($carina['wlanHome'],'nicht aktuell')
    &&str_contains($html,'16:29:32')
    &&!str_contains($html,'Carina: <span style="color:#1f8b55">ANWESEND'),
    'latest geo time visible in expanded tile and true older MAC history explicit');
latestAssert($tile['mayArmAlarm']===false
    &&$tile['mayTriggerAbsenceActions']===false,
    'no alarm or external absence permission');
$cGeoStale=JVCarinaGeofencyHints::evaluate([
    'verified'=>true,'inside'=>true,'timestamp'=>$oldGeo,'zoneName'=>'Zuhause'
],$now,true);
$visuStale=JVResidentVisuState::evaluate($wlan,$company,
    $jGeo,$cGeoStale,[],[],$prior,$now
);
$tileStale=JVResidentTileDashboard::evaluate(
    $visuStale['report'],$wlan,$company,[],[],$now,$depart
);
latestAssert($tileStale['people']['carina']['at']===$lastCarina
    &&!str_contains($tileStale['people']['carina']['timeMeaning'],'Geofence-IN'),
    'stale geofence never overrides last real contact');
$cGeoBad=JVCarinaGeofencyHints::evaluate([
    'verified'=>false,'inside'=>true,'timestamp'=>$geoCarina,'zoneName'=>'Zuhause'
],$now,true);
$visuBad=JVResidentVisuState::evaluate($wlan,$company,
    $jGeo,$cGeoBad,[],[],$prior,$now
);
$tileBad=JVResidentTileDashboard::evaluate(
    $visuBad['report'],$wlan,$company,[],[],$now,$depart
);
latestAssert($tileBad['people']['carina']['at']===$lastCarina,
    'unverified wrong geo instance ID rejected');
echo "Actual 17.10 16:29 report: latest Carina Geofence IN shown precisely; Johannes MAC edge note; safe no HOME or AWAY certification PASS\n";
