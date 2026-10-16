<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVCombinedPresenceBoolean.php';
function detectionCheck(bool $pass,string $why):void{
    if(!$pass)throw new RuntimeException('DETECTION_BOOL '.$why);
}
$now=1792093000;
$combined=['schema'=>1,'authority'=>'ANONYMOUS_COMBINED_CONTEXT_ONLY',
    'personPresenceMayBeChanged'=>false,'propertyEmptyMayBeCertified'=>false,
    'alarmMayBeControlled'=>false,'p03RecentDirection'=>null,'warnings'=>[]];
$home=['schema'=>1,'authority'=>'ANONYMOUS_HOME_OBSERVATION_ONLY',
    'sourcesHealthy'=>true,'baselineEstablished'=>true,'observedHomeGUS'=>15,
    'houseStatus'=>'HOUSE_QUIET_NO_ABSENCE_PROOF',
    'lastHomeMotionAgeSeconds'=>4000,
    'recentP03Direction'=>null,'lastP03DirectionAt'=>null];
$wlan=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>[
        'johannes'=>['validatedExistingVariablePair'=>true,'recentMacEvidence'=>false,
            'state'=>'NO_RECENT_MAC_CONFIRMATION_NOT_ABSENCE'],
        'carina'=>['validatedExistingVariablePair'=>true,'recentMacEvidence'=>false,
            'state'=>'NO_RECENT_MAC_CONFIRMATION_NOT_ABSENCE']]];
$j=['schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,
    'insideRecent'=>false,'outsideRecent'=>false];
$c=['schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,
    'insideRecent'=>false,'outsideRecent'=>false];
$p=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY'];
$gate=['schema'=>1,'authority'=>'EXISTING_SCHIEBETOR_DISPLAY_HINT_ONLY',
    'signalPathAndBooleanVerified'=>true,'positionHint'=>'ZU'];
$indoor=['schema'=>1,'authority'=>'INDOOR_DEVICE_HINTS_ONLY',
    'onCount'=>0,'sourceCount'=>21];
$eval=static fn() => JVCombinedPresenceBoolean::evaluate(
    $combined,$home,$wlan,$j,$c,$p,$gate,$indoor,$now
);
$a=$eval();
detectionCheck($a['detected']===false&&$a['status']==='NEIN_KEIN_AKTUELLER_NACHWEIS',
    'house quiet 4000s is no current positive detection');
detectionCheck($a['notDetectedDoesNotMeanEmpty']&& !$a['houseEmptyCertified']
    &&!$a['mayWriteExternalHomeAway']&&!$a['mayArmAlarm'],
    'false is not empty or alarm permission');
$gate['positionHint']='AUF';
$indoor['onCount']=21;
$b=$eval();
detectionCheck($b['detected']===false
    &&$b['sources']['gatePosition']==='AUF'
    &&$b['sources']['indoorLightsOrTvOn']===21
    &&in_array('INTERIOR_LIGHT_OR_TV_ON_WEAK',$b['supportingContext'],true),
    'open gate and all lights ON still cannot by themselves prove persons');
$gate['positionHint']='ZU';$indoor['onCount']=0;
$wlan['people']['johannes']['recentMacEvidence']=true;
$wlan['people']['johannes']['state']='MAC_CONFIRMED_RECENT_DEVICE_HINT';
$c1=$eval();
detectionCheck($c1['detected']===false
    &&in_array('JOHANNES_WLAN_ONLY',$c1['supportingContext'],true),
    'lone WLAN signal can be forgotten phone');
$j['insideRecent']=true;
$d=$eval();
detectionCheck($d['detected']===true
    &&$d['sources']['johannesWlanAndHomeGeofence']===true
    &&!$d['residentIdentityCertified'],'positive double-source Johannes handset near home');
$j['insideRecent']=false;$j['outsideRecent']=true;
$e=$eval();
detectionCheck($e['detected']===false
    &&in_array('JOHANNES_WLAN_GEO_CONFLICT',$e['warnings'],true),
    'WLAN/geofence contradiction does not force positive');
$j['outsideRecent']=false;
$wlan['people']['johannes']['recentMacEvidence']=false;
$wlan['people']['johannes']['state']='NO_RECENT_MAC_CONFIRMATION_NOT_ABSENCE';
$c['insideRecent']=true;
$wlan['people']['carina']['recentMacEvidence']=true;
$wlan['people']['carina']['state']='MAC_CONFIRMED_RECENT_DEVICE_HINT';
$f=$eval();
detectionCheck($f['detected']===true&&$f['sources']['carinaWlanAndHomeGeofence'],
    'Carina phone confirms matching WLAN and Geofency');
$c['insideRecent']=false;$wlan['people']['carina']['recentMacEvidence']=false;
$wlan['people']['carina']['state']='NO_RECENT_MAC_CONFIRMATION_NOT_ABSENCE';
$home['lastHomeMotionAgeSeconds']=50;
$home['houseStatus']='HOUSE_MOVEMENT_HINT';
$g=$eval();
detectionCheck($g['detected']===true
    &&in_array('LCN_HOUSE_MOTION_LAST_5_MIN',$g['positiveReasons'],true),
    'person movement GUS works even without smartphone');
$home['sourcesHealthy']=false;
$h=$eval();
detectionCheck($h['detected']===false,'incomplete LCN sources never confirm');
$home['sourcesHealthy']=true;
$home['houseStatus']='HOUSE_QUIET_NO_ABSENCE_PROOF';
$home['lastHomeMotionAgeSeconds']=9000;
$home['recentP03Direction']='LAGER_TO_HOME';
$home['lastP03DirectionAt']=$now-120;
$combined['p03RecentDirection']='LAGER_TO_HOME';
$i=$eval();
detectionCheck($i['detected']===true &&$i['sources']['p03RecentHouseEntry'],
    'valid recent P03 direction into house can positively detect anonymous entry');
$home['lastP03DirectionAt']=$now-900;
$j2=$eval();
detectionCheck($j2['detected']===false,
    'P03 old entry is not forever present');
$home['lastP03DirectionAt']=$now-30;
$home['recentP03Direction']='HOME_TO_LAGER';
$combined['p03RecentDirection']='HOME_TO_LAGER';
$k=$eval();
detectionCheck($k['detected']===false,'P03 going to Lager is not property absence');
$home['recentP03Direction']=null;$combined['p03RecentDirection']=null;
$wlan['people']['johannes']['recentMacEvidence']=true;
$wlan['people']['johannes']['state']='MAC_CONFIRMED_RECENT_DEVICE_HINT';
$j['insideRecent']=true;
$combined['warnings']=['SOURCE_AUTHORITY_OR_SCHEMA_MISMATCH'];
$l=$eval();
detectionCheck($l['detected']===false
    &&$l['status']==='QUELLEN_NICHT_VERIFIZIERT',
    'failed combined-evidence source schema fails closed');
$combined['warnings']=[];
$combined['authority']='WRONG';
$m=$eval();
detectionCheck($m['detected']===false,'forged combined evidence is ignored');
echo "Combined Anwesenheit erkannt YES/NO scenarios PASS\n";
