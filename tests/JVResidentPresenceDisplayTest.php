<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVResidentPresenceDisplay.php';
function presenceDisplayAssert(bool $ok,string $what):void{
    if(!$ok)throw new RuntimeException('PRESENCE_DISPLAY '.$what);
}
$wlan=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>[
        'johannes'=>['state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
            'recentMacEvidence'=>true,'validatedExistingVariablePair'=>true],
        'carina'=>['state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
            'recentMacEvidence'=>true,'validatedExistingVariablePair'=>true]
    ]];
$j=['schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,'insideRecent'=>true,
    'outsideRecent'=>false,'geofenceReportedInside'=>true];
$c=['schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,'insideRecent'=>true,
    'outsideRecent'=>false,'geofenceReportedInside'=>true];
$a=JVResidentPresenceDisplay::evaluate($wlan,$j,$c);
presenceDisplayAssert($a['people']['johannes']['display']==='ANWESEND (Handyhinweis)'
    &&$a['people']['carina']['display']==='ANWESEND (Handyhinweis)',
    'two independently geo and WLAN corroborated devices');
presenceDisplayAssert(!$a['personPresenceMayBeChanged']
    &&!$a['propertyEmptyMayBeCertified']&&!$a['alarmMayBeControlled']
    &&!$a['people']['carina']['personPhysicallyHomeCertified']
    &&!$a['people']['johannes']['statusCanArmAlarm'],
    'two displayed phones cannot control human occupancy or alarm');
$c['outsideRecent']=true;$c['insideRecent']=false;$c['geofenceReportedInside']=false;
$b=JVResidentPresenceDisplay::evaluate($wlan,$j,$c);
presenceDisplayAssert($b['people']['carina']['display']==='WIDERSPRUCH (WLAN / Geofence)'
    &&!$b['people']['carina']['personPhysicallyAwayCertified'],
    'WLAN and geofence conflict does not certify AWAY');
$c['outsideRecent']=false;$c['insideRecent']=false;$c['geofenceReportedInside']=true;
$d=JVResidentPresenceDisplay::evaluate($wlan,$j,$c);
presenceDisplayAssert($d['people']['carina']['display']==='WLAN ZUHAUSE (Geofence veraltet)',
    'old geofence must not masquerade as current');
$wlan['people']['carina']['recentMacEvidence']=false;
$e=JVResidentPresenceDisplay::evaluate($wlan,$j,$c);
presenceDisplayAssert($e['people']['carina']['display']==='NICHT BESTÄTIGT',
    'stale geo plus no MAC is UNKNOWN, not AWAY');
$c['insideRecent']=true;
$f=JVResidentPresenceDisplay::evaluate($wlan,$j,$c);
presenceDisplayAssert($f['people']['carina']['display']
    ==='GEOFENCE ZUHAUSE (WLAN unbestätigt)',
    'one fresh geo hint, no WLAN');
$c['validatedSource']=false;
$g=JVResidentPresenceDisplay::evaluate($wlan,$j,$c);
presenceDisplayAssert($g['people']['carina']['display']==='NICHT BESTÄTIGT',
    'wrong source blocked');
$h=JVResidentPresenceDisplay::evaluate([],[],[]);
presenceDisplayAssert($h['people']['johannes']['display']==='NICHT BESTÄTIGT'
    &&$h['people']['carina']['display']==='NICHT BESTÄTIGT',
    'empty/disabled sources unknown');
echo "Two resident handset-status display safety PASS\n";
