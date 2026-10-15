<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVJohannesEgiGeoZoneHints.php';
function geoAssert(bool $yes,string $name):void{
    if(!$yes)throw new RuntimeException('EGIGEOZONE '.$name);
}
$now=1792093000;
$inside=['verified'=>true,'inside'=>true,'timestamp'=>$now-85];
$good=JVJohannesEgiGeoZoneHints::evaluate($inside,$now,true,150);
geoAssert($good['insideRecent']===true
    &&$good['state']==='RECENT_INSIDE_150M_DEVICE_HINT'
    &&$good['radiusVerifiedViaSymcon']===false,'fresh inside handset');
geoAssert($good['personPresenceMayBeChanged']===false
    &&$good['propertyEmptyMayBeCertified']===false
    &&$good['alarmMayBeControlled']===false,'no person or alarm actions');
$outside=JVJohannesEgiGeoZoneHints::evaluate(
    ['verified'=>true,'inside'=>false,'timestamp'=>$now-100],$now,true);
geoAssert($outside['outsideRecent']===true
    &&$outside['outsideIsNotVerifiedResidentAway']===true,
    'recent outside is not resident away');
$stale=JVJohannesEgiGeoZoneHints::evaluate(
    ['verified'=>true,'inside'=>true,'timestamp'=>$now-21601],$now,true);
geoAssert($stale['state']==='STALE_INSIDE_NOT_LIVE_PRESENCE'
    &&$stale['insideRecent']===false,'stale true not live');
$staleAway=JVJohannesEgiGeoZoneHints::evaluate(
    ['verified'=>true,'inside'=>false,'timestamp'=>$now-21601],$now,true);
geoAssert($staleAway['state']==='STALE_OUTSIDE_NOT_LIVE_ABSENCE'
    &&$staleAway['outsideRecent']===false,'stale false not away');
$bad=JVJohannesEgiGeoZoneHints::evaluate(
    ['verified'=>false,'inside'=>true,'timestamp'=>$now-1],$now,true);
geoAssert($bad['state']==='SOURCE_NOT_VERIFIED','unverified IDs not trusted');
$future=JVJohannesEgiGeoZoneHints::evaluate(
    ['verified'=>true,'inside'=>true,'timestamp'=>$now+100],$now,true);
geoAssert($future['state']==='INVALID_LAST_EVENT_TIMESTAMP',
    'future timestamp rejected');
$disabled=JVJohannesEgiGeoZoneHints::evaluate($inside,$now,false);
geoAssert(!$disabled['insideRecent']&&$disabled['state']==='DISABLED',
    'default disabled no signal');
$invalid=JVJohannesEgiGeoZoneHints::evaluate($inside,$now,true,25);
geoAssert($invalid['state']==='INVALID_CONFIGURATION',
    'invalid smaller than Egi minimum radius not trusted');
echo "EgiGeoZone Johannes read-only geofence evidence PASS\n";
