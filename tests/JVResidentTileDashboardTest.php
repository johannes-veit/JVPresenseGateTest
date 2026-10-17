<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVResidentTileDashboard.php';
function tileAssert(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('RESIDENT TILE: '.$why);
}
$now=(new DateTimeImmutable('2026-10-17 14:10:30',
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$people=['schema'=>1,
    'authority'=>'RESIDENT_PHONE_VISU_HINTS_READ_ONLY',
    'people'=>[
     'johannes'=>[
        'decision'=>'HOME_PHONE_CONFIRMED',
        'homeMacAt'=>$now-20,'workSSIDAt'=>null,
        'homeSSIDAt'=>null,'deviceEvidenceAt'=>$now-20,
        'lastKnownDeviceAt'=>$now-20
     ],
     'carina'=>[
        'decision'=>'UNKNOWN','homeMacAt'=>null,
        'workSSIDAt'=>null,'homeSSIDAt'=>null,
        'deviceEvidenceAt'=>null,'lastKnownDeviceAt'=>null
     ]
    ]];
$wlan=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
 'enabled'=>true,'people'=>[
    'johannes'=>['recentMacEvidence'=>true,'lastMacContactAt'=>$now-20],
    'carina'=>['recentMacEvidence'=>false]
 ]];
$ssid=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'enabled'=>true,'people'=>[]];
$p03=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'recentDirectionProofs'=>[
        ['source'=>'P03_TWO_MAST_VERIFIED','direction'=>'LAGER_TO_HOME',
         'at'=>$now-120],
        ['source'=>'P03_TWO_MAST_VERIFIED','direction'=>'HOME_TO_LAGER',
         'at'=>$now-3600]
    ]];
$house=['authority'=>'ANONYMOUS_HOME_OBSERVATION_ONLY',
    'sourcesHealthy'=>true,'lastHomeMotionAgeSeconds'=>30];
$r=JVResidentTileDashboard::evaluate($people,$wlan,$ssid,$p03,$house,$now);
$h=JVResidentTileDashboard::render($r);
$people['people']['johannes']['geofenceInsideSharedHomeWorkZone']=true;
$people['people']['johannes']['geofenceEventFresh']=false;
$people['people']['johannes']['geofenceLastEventAgeSeconds']=82800;
$staleGeoTile=JVResidentTileDashboard::render(
    JVResidentTileDashboard::evaluate($people,$wlan,$ssid,$p03,$house,$now)
);
tileAssert(str_contains($staleGeoTile,'veraltet')
    &&!str_contains($staleGeoTile,'Geofence meldete Haus-/Firmenbereich'),
    'a 23h old shared Geofence TRUE cannot be shown as current HOME evidence');
tileAssert($r['people']['johannes']['status']==='ANWESEND'
    &&$r['people']['carina']['status']==='NICHT BESTÄTIGT',
    'individually confirmed home phone vs unknown person');
tileAssert(substr_count($h,'<details')===2&&substr_count($h,'</details>')===2
    &&substr_count($h,'<summary')===2
    &&substr_count($h,'Johannes:')===1
    &&substr_count($h,'Carina:')===1,
    'exactly two expandable resident rows in ONE HTMLBox');
tileAssert(str_contains($h,'Person nicht identifiziert')
    &&str_contains($h,'Lagerplatz')
    &&str_contains($h,'Zu Fuß / Auto / Filzener Weg: nicht personenbezogen nachgewiesen'),
    'anonymous P03 never attributed to any specific resident or transport');
tileAssert(!str_contains($h,'19:04:23')
    &&!str_contains($h,'Verlassen mit Auto'),
    'no fabricated travel modes or multi-status history');
tileAssert($r['noHistoricalMultiStatusLog']
    && !$r['mayArmAlarm'] && !$r['mayTriggerAbsenceActions']
    &&$r['dataIsPhoneBasedNotHumanCertificate'],
    'no alarm/absence control permission and no fictitious person identity');
$people['people']['carina']=[
 'decision'=>'WORK_PHONE_CONFIRMED','homeMacAt'=>null,
 'workSSIDAt'=>$now-5,'homeSSIDAt'=>null,
 'deviceEvidenceAt'=>$now-5,'lastKnownDeviceAt'=>$now-5
];
$ssid['people']['carina']=[
 'sourceVerified'=>true,'ssid'=>'Intern-Veit',
 'status'=>'WORK_FIRMEN_WLAN_PHONE','workRecent'=>true
];
$r2=JVResidentTileDashboard::evaluate($people,$wlan,$ssid,[],$house,$now);
$h2=JVResidentTileDashboard::render($r2);
tileAssert($r2['people']['carina']['status']==='ABWESEND'
    &&str_contains($h2,'ABWESEND')
    &&str_contains($h2,'Intern-Veit')
    &&str_contains($h2,'Handy im Firmen-WLAN erkannt'),
    'independently confirmed phone at firm is labelled absent as PHONE only');
$people['people']['carina']['decision']='LAST_KNOWN_UNCONFIRMED';
$people['people']['carina']['deviceEvidenceAt']=null;
$ssid['people']['carina']['sourceVerified']=false;
$r3=JVResidentTileDashboard::evaluate($people,$wlan,$ssid,[],$house,$now);
tileAssert($r3['people']['carina']['status']==='NICHT BESTÄTIGT'
    &&$r3['people']['carina']['at']===$now-5,
    'old phone status not falsely treated as physical away');
$ssid['people']['carina']['sourceVerified']=true;
$ssid['people']['carina']['ssid']='<script>alert(1)</script>';
$h3=JVResidentTileDashboard::render(JVResidentTileDashboard::evaluate(
    $people,$wlan,$ssid,[],$house,$now
));
tileAssert(!str_contains($h3,'<script>')
    &&str_contains($h3,'&lt;script&gt;'),
    'HTML escaping prevents SSID-fed script injection');
$phoneEvents=['authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>[
        'johannes'=>['lastEvent'=>[
            'code'=>'HOME_PHONE_REAPPEARED_AFTER_CONTACT_GAP','at'=>$now-30]],
        'carina'=>['lastEvent'=>null]
    ]];
$eventTile=JVResidentTileDashboard::evaluate(
    $people,$wlan,$ssid,[],$house,$now,[],[],$phoneEvents
);
$eventHtml=JVResidentTileDashboard::render($eventTile);
tileAssert(str_contains($eventHtml,'Letztes Handy-Ereignis:')
    &&str_contains($eventHtml,'keine Heimkehr bewiesen')
    &&str_contains($eventHtml,'Kein neuer bestaetigter Handy-Zustandswechsel')
    &&substr_count($eventHtml,'<details')===2,
    'one last phone event per person inside existing single tile');
echo "Single resident current-state expandable HTML Tile PASS\n";
