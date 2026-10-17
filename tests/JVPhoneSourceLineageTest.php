<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVExistingWlanPhoneHints.php';
require_once dirname(__DIR__).'/libs/JVJohannesEgiGeoZoneHints.php';
require_once dirname(__DIR__).'/libs/JVCarinaGeofencyHints.php';
require_once dirname(__DIR__).'/libs/JVResidentEventFusion.php';
function sourceCheck(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('SOURCE_LINEAGE '.$message);
}
$t=1800000000;
$wlan=[
    'johannes'=>['verified'=>true,'wlanValue'=>true,'lastContact'=>$t-30,
        'boolId'=>19980,'lastContactId'=>26497],
    'carina'=>['verified'=>true,'wlanValue'=>true,'lastContact'=>$t-40,
        'boolId'=>38430,'lastContactId'=>10760]
];
$geoJ=['verified'=>true,'inside'=>true,'timestamp'=>$t-20,
    'sourceKey'=>'EGI:35965:50000:41869:28055'];
$geoC=['verified'=>true,'inside'=>true,'timestamp'=>$t-35,
    'zoneName'=>'Zuhause',
    'sourceKey'=>'GEOFENCY:48903:45439:39169:45666'];
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'enabled'=>true,'people'=>[]];
$p03=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'logContinuity'=>'OK','recentDirectionProofs'=>[]];
$eval=static function(int $now,array $state=[])use(
    &$wlan,&$geoJ,&$geoC,&$work,&$p03
):array{
    $w=JVExistingWlanPhoneHints::evaluate($wlan,$now,true);
    $j=JVJohannesEgiGeoZoneHints::evaluate($geoJ,$now,true);
    $c=JVCarinaGeofencyHints::evaluate($geoC,$now,true);
    return JVResidentEventFusion::evaluate(
        $w,$j,$c,$work,$p03,$state,$now
    );
};
$first=$eval($t);
sourceCheck($first['next']['people']['johannes']['lastMacSourceKey']
    ==='WLAN:19980:26497'
    &&$first['next']['people']['carina']['lastGeoSourceKey']
        ==='GEOFENCY:48903:45439:39169:45666',
    'store exact verified source object identity, not guessed WiFi address');
$geoJ['inside']=false;$geoJ['timestamp']=$t+90;
$out=$eval($t+100,$first['next']);
sourceCheck($out['report']['people']['johannes']['newPersonalPhoneEvent']['code']
    ==='PHONE_GEOFENCE_OUT_OBSERVED','personal OUT from same geo object binding');
$geoJ['inside']=true;$geoJ['timestamp']=$t+180;
$in=$eval($t+190,$out['next']);
$wlan['johannes']['lastContact']=$t+220;
$return=$eval($t+240,$in['next']);
sourceCheck($return['report']['people']['johannes']['newPhoneReturn']===true
    &&count($return['report']['people']['johannes']['eventHistory'])===3,
    'existing verified personal OUT IN MAC lifecycle retained');
// Rebound WLAN object from wrong auto-created category: no inheritance,
// personal event ledger reset, no new return attributed to replacement.
$wlan['johannes']['boolId']=55000;
$wlan['johannes']['lastContactId']=55001;
$wlan['johannes']['lastContact']=$t+250;
$wlan['carina']['lastContact']=$t+230;
$replacement=$eval($t+260,$return['next']);
$j=$replacement['report']['people']['johannes'];
sourceCheck($j['macSourceRebound']===true
    &&$j['sourceRebaselineRequired']===true
    &&$j['newPhoneReturn']===false
    &&$j['newPersonalPhoneEvent']===null
    &&$j['pendingGeofenceOut']===null
    &&$j['pendingGeofenceIn']===null
    &&$j['eventHistoryCount']===0,
    'changed WLAN object pair does not inherit previous phone return chain');
sourceCheck($replacement['report']['people']['carina']['sourceRebindRecent']===false,
    'Johannes source maintenance does not affect Carina');
// Next new EgiGeoZone zone/timestamp pair must also rebaseline without
// assuming it emitted a real personal OUT or IN.
$geoJ['sourceKey']='EGI:35965:50000:55500:55501';
$geoJ['inside']=false;$geoJ['timestamp']=$t+290;
$geoChange=$eval($t+300,$replacement['next']);
$g=$geoChange['report']['people']['johannes'];
sourceCheck($g['geoSourceRebound']===true
    &&$g['newPersonalPhoneEvent']===null
    &&$g['pendingGeofenceOut']===null
    &&$g['eventHistoryCount']===0,
    'new verified geofence object pair is baseline, not travel event');
$unchanged=$eval($t+330,$geoChange['next']);
sourceCheck($unchanged['report']['people']['johannes']['geoSourceRebound']===false
    &&$unchanged['report']['people']['johannes']['sourceRebindRecent']===true,
    'one-shot rebind signal retained as 24-hour diagnostic');
$geoJ['timestamp']=$t+355;
$newOut=$eval($t+365,$unchanged['next']);
sourceCheck($newOut['report']['people']['johannes']['newPersonalPhoneEvent']['code']
    ==='PHONE_GEOFENCE_OUT_OBSERVED',
    'actual newly timestamped OUT after baseline is accepted');
$wlan['johannes']['verified']=false;
$unverified=$eval($t+380,$newOut['next']);
sourceCheck($unverified['report']['people']['johannes']['macSourceContinuous']===false,
    'invalidated network source cannot extend personal return');
$wlan['johannes']['verified']=true;
$afterOutage=$eval($t+410,$unverified['next']);
sourceCheck($afterOutage['report']['people']['johannes']['newPhoneReturn']===false
    &&$afterOutage['report']['people']['johannes']['newPersonalPhoneEvent']===null,
    'reappearance of old source after outage not a new arrival');
echo "Verified phone source lineage and rebind quarantine PASS\n";
