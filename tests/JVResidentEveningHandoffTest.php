<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVResidentEveningHandoff.php';
function expectHandoff(bool $good,string $explain):void{
    if(!$good)throw new RuntimeException('EVENING_HANDOFF: '.$explain);
}
$now=(new DateTimeImmutable('2026-10-17 20:01:05',
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$phoneState=['schema'=>1,'authority'=>'RESIDENT_PHONE_VISU_HINTS_READ_ONLY',
 'people'=>[
    'johannes'=>['decision'=>'HOME_PHONE_CONFIRMED',
        'deviceEvidenceReason'=>'HOME_MAC_CONFIRMED',
        'deviceEvidenceAt'=>$now-8,'lastKnownDeviceAt'=>$now-8,
        'wifiConflict'=>false,'rejectedOlderOppositeZoneSource'=>false],
    'carina'=>['decision'=>'LAST_KNOWN_UNCONFIRMED',
        'deviceEvidenceReason'=>'LAST_KNOWN_IS_NOT_LIVE',
        'deviceEvidenceAt'=>null,'lastKnownDeviceAt'=>$now-1600,
        'wifiConflict'=>false,'rejectedOlderOppositeZoneSource'=>true]
 ]];
$tile=['schema'=>1,'authority'=>'CURRENT_STATUS_ONLY_SAFE_HTML_TILE',
 'people'=>[
    'johannes'=>['status'=>'ANWESEND'],
    'carina'=>['status'=>'NICHT BESTÄTIGT']
 ]];
$wlan=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
 'enabled'=>true,'people'=>[
  'johannes'=>['state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
    'validatedExistingVariablePair'=>true,'wlanBoolean'=>true,
    'lastMacContactAt'=>$now-8,'lastMacContactAgeSeconds'=>8],
  'carina'=>['state'=>'STALE_TRUE_CANNOT_CONFIRM_PRESENCE',
    'validatedExistingVariablePair'=>true,'wlanBoolean'=>true,
    'lastMacContactAt'=>$now-1600,'lastMacContactAgeSeconds'=>1600]
 ]];
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
 'enabled'=>true,'people'=>[
   'johannes'=>['sourceVerified'=>false,'status'=>'NOT_CONFIGURED_OR_UNVERIFIED',
     'ageSeconds'=>null],
   'carina'=>['sourceVerified'=>true,'status'=>'STALE_CURRENT_SSID_UNCONFIRMED',
     'ageSeconds'=>1000]
 ]];
$jGeo=['schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
 'enabled'=>true,'validatedSource'=>true,
 'state'=>'STALE_INSIDE_NOT_LIVE_PRESENCE',
 'lastEventAt'=>$now-80000,'lastEventAgeSeconds'=>80000];
$cGeo=['schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
 'enabled'=>false,'validatedSource'=>false,
 'lastEventAt'=>null,'lastEventAgeSeconds'=>null];
$p03=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
 'directionProofs24h'=>['HOME_TO_LAGER'=>0,'LAGER_TO_HOME'=>0],
 'lastDirectionAt'=>null];
$timing=['schema'=>1,'authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
 'p03OperatingMode'=>'TEST_AND_PRODUCTION_OFF',
 'p03ModeTrustedFromFreshDiagnosis'=>true,
 'rawStreetIVS24h'=>['IN'=>8,'OUT'=>11],
 'lastStreetIVS'=>['at'=>$now-120]];
$mast=['schema'=>1,'authority'=>'P03_EXISTING_MAST_OBSERVER_HEALTH_ONLY',
 'bothMastsLive'=>true,'mainP03ProofBlocked'=>false,
 'mastObservers'=>[
 'JV_LEFT'=>['streamState'=>'LIVE_RX','lastCameraRxAgeSeconds'=>12],
 'WORK_LEFT'=>['streamState'=>'LIVE_RX','lastCameraRxAgeSeconds'=>10]
 ]];
$house=['schema'=>1,'authority'=>'ANONYMOUS_HOME_OBSERVATION_ONLY',
 'observedHomeGUS'=>15,'sourcesHealthy'=>true,
 'lastHomeMotionAgeSeconds'=>45];
$activity=['schema'=>1,'authority'=>'MOTION_EDGE_HISTORY_ONLY',
 'lastMotionAt'=>$now-45];
$local=['schema'=>1,'authority'=>'LOCAL_SENSOR_HINTS_ONLY'];
$boundary=['configuredBoundarySignals'=>0];
$gate=['positionHint'=>'ZU'];
$report=JVResidentEveningHandoff::build($tile,$phoneState,
    $wlan,$work,$jGeo,$cGeo,$p03,$timing,$mast,$house,$activity,
    $local,$boundary,$gate,[], $now,true);
$persistedContact=['authority'=>'READ_ONLY_PHONE_OBSERVATION_CONTINUITY',
    'people'=>['johannes'=>['status'=>'FRESH_HOME_MAC_CONTACT',
        'lastRecoveredHomeMacAt'=>$now-140,
        'lastObservedRecoveryGapSeconds'=>860]]];
$reportRecovery=JVResidentEveningHandoff::build($tile,$phoneState,
    $wlan,$work,$jGeo,$cGeo,$p03,$timing,$mast,$house,$activity,
    $local,$boundary,$gate,[],$now,true,[],[],[],$persistedContact);
expectHandoff(str_contains($reportRecovery,'LETZTE WLAN-WIEDERERKENNUNG:')
    &&str_contains($reportRecovery,'vorherige MAC-Kontaktluecke: 860 s')
    &&str_contains($reportRecovery,'KEIN Ankunftsnachweis'),
    'show retained recovery as diagnostic not human arrival');
expectHandoff(strlen($report)>1800 &&strlen($report)<13000
    &&str_starts_with($report,'=== TESTBERICHT AN CHATGPT')
    &&str_ends_with($report,'=== ENDE TESTBERICHT ==='),
    'single, bounded, complete human-readable copy-paste');
foreach([
 'Johannes','Carina','Heim-WLAN: ID #19980/#26497',
 'Heim-WLAN: ID #38430/#10760','20:01:05',
 'Alter: 1600 s','TEST_AND_PRODUCTION_OFF',
 'Strassen-IVS/24h','LCN GUS (erforderlich 15)',
 'Letzter ECHTER MAC-Kontakt','ROUTE_AUTO_FUSSWEG_NICHT_PERSONENBEZOGEN_BEWIESEN',
 'MENSCHLICHE_ABWESENHEIT_UND_HAUS_LEER_NICHT_ZERTIFIZIERT',
 'ABWESENHEIT-AKTIONEN / ALARMFREIGABE: NEIN'
] as $expected)
 expectHandoff(str_contains($report,$expected),'missing important label '.$expected);
expectHandoff(str_contains($report,'STALE_TRUE_CANNOT_CONFIRM_PRESENCE')
    &&str_contains($report,'KACHEL: NICHT BESTÄTIGT')
    &&str_contains($report,'KACHEL: ANWESEND'),
    'retained WLAN TRUE with ancient contact must stay unconfirmed');
expectHandoff(str_contains($report,'STALE_INSIDE_NOT_LIVE_PRESENCE')
    &&str_contains($report,'Kreis umfasst HAUS UND FIRMA'),
    'old Egi status and broad radius cannot become home');
expectHandoff(str_contains($report,'VERIFIZIERTE LIVE-P03-ZWEIMAST-UEBERGAENGE/24h: Haus->Lager 0')
    &&str_contains($report,'ROHE Strassen-IVS/24h (kein Hauswechsel!): IN 8 / OUT 11'),
    'raw street IVS and verified mast proofs remain distinct');
$untrusted="secretTOKEN=supersecret password=abc123 192.168.2.253 "
    ."Latitude=49.123 Longitude=6.234 99:88:77:66:55:44";
$work['people']['johannes']['status']=$untrusted;
$tile['people']['johannes']['status']=$untrusted;
$report2=JVResidentEveningHandoff::build(
    $tile,$phoneState,$wlan,$work,$jGeo,$cGeo,$p03,$timing,
    $mast,$house,$activity,$local,$boundary,$gate,[$untrusted],
    $now,true
);
foreach(['supersecret','abc123','49.123','6.234','99:88:77',
    '192.168.2.253'] as $secret)
    expectHandoff(!str_contains($report2,$secret),'secret/token/network data leaked');
$bad=JVResidentEveningHandoff::build(
    [],[],[],[],[],[],[],[],[],[],[],[],[],[],[], $now,false
);
expectHandoff(str_contains($bad,'SHADOW DEAKTIVIERT')
    &&str_contains($bad,'ABWESENHEIT-AKTIONEN / ALARMFREIGABE: NEIN'),
    'missing sources and disabled observation must fail closed');
echo "One compact comprehensive no-secret evening TestReport PASSED\n";
