<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVCombinedPresenceEvidence.php';
$n=0;
function combinedOK(bool $ok,string $what):void{
    global $n;$n++;
    if(!$ok)throw new RuntimeException('FAIL: '.$what);
}
$now=1792098700;
$wlan=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'freshMacConfirmedDeviceCount'=>2];
$home=['schema'=>1,'authority'=>'ANONYMOUS_HOME_OBSERVATION_ONLY',
    'sourcesHealthy'=>true,'observedHomeGUS'=>15,
    'baselineEstablished'=>true,'lastHomeMotionAgeSeconds'=>940,
    'quietForSeconds'=>940,'houseStatus'=>'UNKNOWN',
    'recentP03Direction'=>null,'lastP03DirectionAt'=>null];
$p03=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'recentDirectionProofs'=>[],'directionProofs24h'=>[
        'HOME_TO_LAGER'=>0,'LAGER_TO_HOME'=>0]];
$boundary=['schema'=>1,'authority'=>'READ_ONLY_BOUNDARY_CONTEXT',
    'allExitRoutesVerified'=>false,'exitDirectionCertified'=>false,
    'peopleCountCertified'=>false,'identityCertified'=>false];
$gate=['schema'=>1,'authority'=>'EXISTING_SCHIEBETOR_DISPLAY_HINT_ONLY',
    'positionHint'=>'ZU','endZu'=>true,'endAuf'=>false];
$indoor=['schema'=>1,'authority'=>'INDOOR_DEVICE_HINTS_ONLY',
    'sourceCount'=>0,'onCount'=>0];
$evaluate=static fn(?array $w=null,?array $h=null,?array $p=null,
    ?array $b=null,?array $g=null,?array $i=null):array=>
    JVCombinedPresenceEvidence::evaluate(
        $w??$wlan,$h??$home,$p??$p03,$b??$boundary,
        $g??$gate,$i??$indoor,$now
    );
// Snapshot 15 Oct 2026: 2 devices, house last motion 940 sec ago.
// Do NOT upgrade this to active physical home presence.
$a=$evaluate();
combinedOK($a['status']==='WLAN_DEVICES_ONLY_NO_RECENT_HOME_MOTION',
    'two WLAN devices with 16-minute-old movement are device-only context');
combinedOK($a['freshWlanDeviceCount']===2
    &&$a['houseMotionAndWlanCorrelated']===false,
    'no false GUS/WLAN correlation over a stale 16-minute interval');
combinedOK($a['gatePosition']==='ZU'
    &&$a['gatePositionIsHumanExitProof']===false,
    'gate closed does not certify people stay or leave');
combinedOK($a['personPresenceMayBeChanged']===false
    &&$a['propertyEmptyMayBeCertified']===false
    &&$a['alarmMayBeControlled']===false,
    'combined hints never issue any control instruction');
$recent=$home;
$recent['lastHomeMotionAgeSeconds']=24;
$recent['quietForSeconds']=24;
$recent['houseStatus']='HOUSE_MOVEMENT_CORROBORATED';
$b=$evaluate($wlan,$recent);
combinedOK($b['status']==='HOUSE_ACTIVITY_WITH_WLAN_DEVICE_CORROBORATED'
    &&$b['houseMotionAndWlanCorrelated']===true,
    'genuine recent multi-GUS home activity + fresh WLAN yields strong anonymous context');
combinedOK($b['individualResidentsAtHome']==='UNKNOWN'
    &&$b['personCount']==='UNKNOWN',
    'two networked smartphones plus motion cannot identify two residents');
$noWlan=$wlan;$noWlan['freshMacConfirmedDeviceCount']=0;
$c=$evaluate($noWlan,$recent);
combinedOK($c['status']==='HOUSE_ACTIVITY_WITHOUT_FRESH_WLAN',
    'phone absent from network does not defeat fresh GUS indoor presence hint');
combinedOK($c['wlanOfflineIsHumanAway']===false
    &&$c['individualResidentsAway']==='UNKNOWN',
    'phone offline is never individual absence');
$quiet=$home;$quiet['lastHomeMotionAgeSeconds']=4000;
$quiet['quietForSeconds']=4000;
$quiet['houseStatus']='HOUSE_QUIET_NO_ABSENCE_PROOF';
$d=$evaluate($noWlan,$quiet);
combinedOK($d['status']==='HOUSE_QUIET_WLAN_UNCONFIRMED',
    'two unavailable phones and a quiet house must not be empty-certified');
combinedOK(in_array('ALL_PROPERTY_EXIT_ROUTES_NOT_VERIFIED',
    $d['absenceReview']['missingRequirements'],true)
    &&in_array('VERIFIED_PROPERTY_EXIT_DIRECTION_MISSING',
        $d['absenceReview']['missingRequirements'],true)
    &&in_array('PER_PERSON_EXIT_PROOF_MISSING',
        $d['absenceReview']['missingRequirements'],true),
    'explicitly list missing property-boundary/individual exit requirements');
combinedOK($d['absenceReview']['mayDeclareAway']===false,
    'no absence declaration after prolonged silence and no WLAN');
$exit=$quiet;
$exit['lastP03DirectionAt']=$now-30;
$exit['recentP03Direction']='HOME_TO_LAGER';
$e=$evaluate($noWlan,$exit);
combinedOK($e['p03RecentDirection']==='HOME_TO_LAGER'
    &&$e['p03HouseLagerIsPropertyExit']===false
    &&$e['absenceReview']['mayDeclareAway']===false,
    'P03 home to Lager is not a confirmed exit from property');
$one=$wlan;$one['freshMacConfirmedDeviceCount']=1;
$f=$evaluate($one,$quiet);
combinedOK($f['deviceHint']==='ONE_RECENT_WLAN_DEVICE'
    &&$f['individualResidentsAtHome']==='UNKNOWN',
    'one WLAN device is one device not a named person at home');
$off=$wlan;$off['enabled']=false;$off['freshMacConfirmedDeviceCount']=2;
$g=$evaluate($off,$quiet);
combinedOK($g['freshWlanDeviceCount']===0
    &&$g['deviceHint']==='WLAN_NOT_ENABLED',
    'disabled WLAN cannot create a two-device presence hint');
$unhealthy=$home;$unhealthy['sourcesHealthy']=false;
$h=$evaluate($wlan,$unhealthy);
combinedOK($h['homeActivityHint']==='LCN_SOURCE_INCOMPLETE'
    &&!$h['houseMotionAndWlanCorrelated'],
    'invalid LCN sensor coverage cannot corroborate WLAN');
$withLight=$indoor;$withLight['sourceCount']=4;$withLight['onCount']=1;
$i=$evaluate($noWlan,$quiet,$p03,$boundary,$gate,$withLight);
combinedOK($i['status']==='INDOOR_DEVICE_ON_WEAK_HINT'
    &&$i['indoorDevicesOffProveAbsence']===false,
    'light left on is weak device hint, no person identification');
$withLight['onCount']=0;
$j=$evaluate($noWlan,$quiet,$p03,$boundary,$gate,$withLight);
combinedOK($j['absenceReview']['mayDeclareAway']===false,
    'all indoor lights off and no WLAN never certify vacancy');
$futureBoundary=$boundary;
$futureBoundary['allExitRoutesVerified']=true;
$futureBoundary['exitDirectionCertified']=true;
$futureBoundary['peopleCountCertified']=true;
$futureBoundary['identityCertified']=true;
$futureBoundary['lastVerifiedHumanPropertyExitAt']=$now-120;
$k=$evaluate($noWlan,$quiet,$p03,$futureBoundary,$gate,$indoor);
combinedOK($k['absenceReview']['status']==='REVIEW_ONLY_NOT_CERTIFIED'
    &&$k['absenceReview']['mayDeclareAway']===false,
    'even hypothetical full future exit evidence remains human review only');
$unknown=$evaluate($wlan,$home,$p03,[], $gate,$indoor);
combinedOK($unknown['status']==='UNKNOWN'
    &&in_array('SOURCE_AUTHORITY_OR_SCHEMA_MISMATCH',$unknown['warnings'],true),
    'unknown boundary schema fails closed');
echo "WLAN + LCN + P03 + gate + lights combined context PASS ($n assertions)\n";
