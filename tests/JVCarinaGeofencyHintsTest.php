<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVCarinaGeofencyHints.php';
function carinaGeoCheck(bool $yes,string $label):void{
    if(!$yes)throw new RuntimeException('Geofency Carina: '.$label);
}
$now=1792093000;
$guid=JVCarinaGeofencyHints::GEOFENCY_GUID;
$rows=[
    ['id'=>63000,'deviceID'=>62000,'deviceName'=>'Device','rootID'=>61000,
        'rootGUID'=>$guid,'type'=>0,'name'=>'Zuhause'],
    ['id'=>63001,'deviceID'=>62000,'deviceName'=>'Device','rootID'=>61000,
        'rootGUID'=>$guid,'type'=>1,'name'=>'Timestamp'],
    ['id'=>63002,'deviceID'=>62000,'deviceName'=>'Device','rootID'=>61000,
        'rootGUID'=>$guid,'type'=>0,'name'=>'Arbeit'],
    ['id'=>63003,'deviceID'=>62000,'deviceName'=>'Device','rootID'=>61000,
        'rootGUID'=>$guid,'type'=>2,'name'=>'Latitude'],
    ['id'=>63100,'deviceID'=>62001,'deviceName'=>'Device','rootID'=>61000,
        'rootGUID'=>$guid,'type'=>0,'name'=>'Sonstiges'],
    ['id'=>63101,'deviceID'=>62001,'deviceName'=>'Device','rootID'=>61000,
        'rootGUID'=>$guid,'type'=>1,'name'=>'Timestamp'],
    ['id'=>63102,'deviceID'=>62001,'deviceName'=>'Device','rootID'=>61000,
        'rootGUID'=>'{wrong}','type'=>0,'name'=>'Zuhause'],
];
$inv=JVCarinaGeofencyHints::inventory($rows,false,879);
carinaGeoCheck($inv['deviceCount']===2,'only genuine Geofency dummy devices');
carinaGeoCheck($inv['candidateCount']===5,'zone/status/timestamp only, never coordinates');
carinaGeoCheck($inv['allDataMetadataOnly']===true &&$inv['scannedVariables']===879,
    'inventory is metadata only');
carinaGeoCheck(JVCarinaGeofencyHints::select($inv,0,0,0,'Zuhause')['status']
    ==='NO_UNIQUE_CARINA_HOME_LOCATION',
    'anonymous Device must not guess Carina ownership');
$sel=JVCarinaGeofencyHints::select($inv,62000,63000,63001,'Zuhause');
carinaGeoCheck($sel['status']==='SELECTED_VERIFIED_METADATA'
    &&$sel['selected']['rootID']===61000,
    'manual exact three IDs select one verified source');
carinaGeoCheck(JVCarinaGeofencyHints::select($inv,62001,63000,63001,'Zuhause')['status']
    ==='MANUAL_IDS_NOT_VERIFIED','cross device mix rejected');
carinaGeoCheck(JVCarinaGeofencyHints::select($inv,62000,63000,0,'Zuhause')['status']
    ==='MANUAL_IDS_INCOMPLETE','incomplete input rejected');
$partial=$inv;$partial['scanLimited']=true;
carinaGeoCheck(JVCarinaGeofencyHints::select($partial,62000,63000,63001,'Zuhause')['status']
    ==='INVENTORY_REQUIRED','partial scan never trusted');
$inside=['verified'=>true,'inside'=>true,'timestamp'=>$now-120,
    'zoneName'=>'Zuhause'];
$in=JVCarinaGeofencyHints::evaluate($inside,$now,true,'SELECTED_VERIFIED_METADATA');
carinaGeoCheck($in['state']==='RECENT_INSIDE_HOME_GEOFENCE_DEVICE_HINT'
    &&$in['insideRecent'] && !$in['outsideRecent'],'fresh iOS enter hint');
carinaGeoCheck(!$in['personPresenceMayBeChanged']
    &&!$in['propertyEmptyMayBeCertified']
    &&!$in['alarmMayBeControlled']
    &&!$in['coordinatesRead'],'strict safety flags and no coordinates');
$outside=$inside;$outside['inside']=false;
$out=JVCarinaGeofencyHints::evaluate($outside,$now,true);
carinaGeoCheck($out['outsideRecent']&&$out['state']
    ==='RECENT_OUTSIDE_HOME_GEOFENCE_NOT_PERSON_AWAY',
    'outside is phone hint never resident away');
$old=$inside;$old['timestamp']=$now-21601;
$stale=JVCarinaGeofencyHints::evaluate($old,$now,true);
carinaGeoCheck(!$stale['insideRecent']&&$stale['state']
    ==='STALE_INSIDE_NOT_CURRENT_LOCATION','geofence TRUE stale after six hours');
$old['inside']=false;
carinaGeoCheck(JVCarinaGeofencyHints::evaluate($old,$now,true)['state']
    ==='STALE_OUTSIDE_NOT_PERSON_AWAY','stale outside not physical absence');
$bad=$inside;$bad['timestamp']=$now+60;
carinaGeoCheck(JVCarinaGeofencyHints::evaluate($bad,$now,true)['state']
    ==='INVALID_LAST_EVENT_TIMESTAMP','future timestamp invalid');
$bad=$inside;$bad['verified']=false;
carinaGeoCheck(JVCarinaGeofencyHints::evaluate($bad,$now,true)['state']
    ==='SOURCE_NOT_VERIFIED','unverified paths fail closed');
carinaGeoCheck(JVCarinaGeofencyHints::evaluate($inside,$now,false)['state']
    ==='DISABLED','default opt-out');
echo "Carina Geofency metadata and safe iPhone device evidence PASS\n";
