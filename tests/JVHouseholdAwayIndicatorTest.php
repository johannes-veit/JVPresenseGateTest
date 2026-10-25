<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVHouseholdAwayIndicator.php';
require_once dirname(__DIR__).'/libs/JVResidentTileDashboard.php';
function awayCheck(bool $pass,string $why):void {
    if(!$pass)throw new RuntimeException('HOUSEHOLD_AWAY '.$why);
}
$now=1800000000;
$phone=[
    'schema'=>1,'authority'=>'RESIDENT_PHONE_VISU_HINTS_READ_ONLY',
    'people'=>[
        'johannes'=>['decision'=>'LAST_KNOWN_UNCONFIRMED',
            'deviceZone'=>'UNKNOWN','wifiConflict'=>false,
            'homeMacAt'=>null,'homeSSIDAt'=>null,'workSSIDAt'=>null],
        'carina'=>['decision'=>'UNKNOWN',
            'deviceZone'=>'UNKNOWN','wifiConflict'=>false,
            'homeMacAt'=>null,'homeSSIDAt'=>null,'workSSIDAt'=>null]
    ]
];
$geoJ=[
    'schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,'outsideRecent'=>true,
    'insideRecent'=>false,'geofenceReportedInside'=>false,
    'lastEventAt'=>$now-3200,'sourceKey'=>'EGI:35965:50000:41869:28055'
];
$geoC=[
    'schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,'outsideRecent'=>true,
    'insideRecent'=>false,'geofenceReportedInside'=>false,
    'lastEventAt'=>$now-3100,'sourceKey'=>'GEOFENCY:100:200:300:400'
];
$house=[
    'schema'=>1,'authority'=>'ANONYMOUS_HOME_OBSERVATION_ONLY',
    'sourcesHealthy'=>true,'observedHomeGUS'=>15,
    'baselineEstablished'=>true,
    'houseStatus'=>'HOUSE_QUIET_NO_ABSENCE_PROOF',
    'quietForSeconds'=>1900,'lastHomeMotionAgeSeconds'=>1900
];
$indoor=[
    'schema'=>1,'authority'=>'INDOOR_DEVICE_HINTS_ONLY',
    'warnings'=>[],'sourceCount'=>2,'readableCount'=>2,
    'sources'=>[
        'light_a'=>['kind'=>'LIGHT_INTERIOR',
            'on'=>false,'lastValueChangeAt'=>$now-1850],
        'tv_a'=>['kind'=>'TV_POWER',
            'on'=>false,'lastValueChangeAt'=>$now-1850]
    ]
];
$f=static function(array $p,array $j,array $c,array $h,array $i,
    array $old,int $time):array{
    return JVHouseholdAwayIndicator::evaluate(
        $p,$j,$c,$h,$i,$old,$time);
};
$first=$f($phone,$geoJ,$geoC,$house,$indoor,[],$now);
awayCheck($first['report']['eligible']
    &&!$first['report']['likelyAway']
    &&$first['report']['status']==='WAITING_STABLE_HOUSE_OBSERVATION',
    '30m quiet with both positive OUT requires 10m continuous coverage');
$prior=$first['next'];$last=$first;
for($i=1;$i<=10;$i++){
    $tick=$now+$i*60;
    $house['quietForSeconds']+=60;
    $house['lastHomeMotionAgeSeconds']+=60;
    $last=$f($phone,$geoJ,$geoC,$house,$indoor,$prior,$tick);
    $prior=$last['next'];
}
awayCheck($last['report']['likelyAway']
    &&$last['report']['status']==='LIKELY_AWAY'
    &&$last['report']['checkedLights']===1
    &&$last['report']['checkedTVs']===1
    &&!$last['report']['mayArmAlarm']
    &&!$last['report']['mayCertifyHouseEmpty']
    &&!$last['report']['mayConfirmPeopleAway'],
    'only sustained two-phone-out, 15 GUS, dark stable light and TV yields visual hint');

$tile=['schema'=>1,'authority'=>'CURRENT_STATUS_ONLY_SAFE_HTML_TILE',
 'people'=>[
   'johannes'=>['name'=>'Johannes','status'=>'NICHT BESTÄTIGT',
       'decision'=>'LAST_KNOWN_UNCONFIRMED','at'=>null],
   'carina'=>['name'=>'Carina','status'=>'NICHT BESTÄTIGT',
       'decision'=>'UNKNOWN','at'=>null]],
 'householdAwayIndicator'=>$last['report']
];
$state=JVResidentTileDashboard::compactState($tile,$now+600);
awayCheck($state['people']['johannes']['status']==='Abwesend'
    &&$state['people']['carina']['status']==='Abwesend'
    &&str_contains($state['people']['johannes']['reason'],'Vermutet')
    &&!$state['mayArmAlarm']
    &&!$state['people']['carina']['humanPresenceOrAbsenceCertified'],
    'two compact native rows show Abwesend without human location certificate');
$active=$phone;
$active['people']['carina']['decision']='HOME_PHONE_CONFIRMED';
$active['people']['carina']['deviceZone']='HOME';
$blocked=$f($active,$geoJ,$geoC,$house,$indoor,$prior,$now+660);
awayCheck(!$blocked['report']['likelyAway']
    &&$blocked['next']['eligibleSince']===null,
    'any current home phone immediately stops all-residents-away');
$tile['people']['carina']['decision']='HOME_PHONE_CONFIRMED';
$tile['people']['carina']['at']=$now+650;
$tile['householdAwayIndicator']=$blocked['report'];
$state=JVResidentTileDashboard::compactState($tile,$now+660);
awayCheck($state['people']['carina']['status']==='Zuhause'
    &&$state['people']['johannes']['status']==='Unklar',
    'home phone keeps priority over earlier tentative absence');
$base=[[$phone,$geoJ,$geoC,$house,$indoor,'all ready']];
foreach([
    'one OUT missing'=>function(&$p,&$j,&$c,&$h,&$i){$c['outsideRecent']=false;},
    'old OUT'=>function(&$p,&$j,&$c,&$h,&$i)use($now){$j['lastEventAt']=$now-21601;},
    'geofence INSIDE'=>function(&$p,&$j,&$c,&$h,&$i){$j['geofenceReportedInside']=true;$j['outsideRecent']=false;},
    'source invalid'=>function(&$p,&$j,&$c,&$h,&$i){$j['sourceKey']=null;},
    'work phone present'=>function(&$p,&$j,&$c,&$h,&$i){$p['people']['johannes']['decision']='WORK_PHONE_CONFIRMED';},
    'opposing phone conflicts'=>function(&$p,&$j,&$c,&$h,&$i){$p['people']['carina']['decision']='CONFLICT';},
    'only 14 GUS'=>function(&$p,&$j,&$c,&$h,&$i){$h['observedHomeGUS']=14;},
    'house motion happened'=>function(&$p,&$j,&$c,&$h,&$i){$h['houseStatus']='HOUSE_MOVEMENT_HINT';},
    'house insufficient quiet'=>function(&$p,&$j,&$c,&$h,&$i){$h['quietForSeconds']=1799;},
    'no TV'=>function(&$p,&$j,&$c,&$h,&$i){unset($i['sources']['tv_a']);$i['sourceCount']=1;$i['readableCount']=1;},
    'TV switched on'=>function(&$p,&$j,&$c,&$h,&$i){$i['sources']['tv_a']['on']=true;},
    'light switched on'=>function(&$p,&$j,&$c,&$h,&$i){$i['sources']['light_a']['on']=true;},
    'light off toggled recently'=>function(&$p,&$j,&$c,&$h,&$i)use($now){$i['sources']['light_a']['lastValueChangeAt']=$now-40;},
    'TV off toggled recently'=>function(&$p,&$j,&$c,&$h,&$i)use($now){$i['sources']['tv_a']['lastValueChangeAt']=$now-100;},
    'indoor unreadable'=>function(&$p,&$j,&$c,&$h,&$i){$i['readableCount']=1;},
    'indoor unknown changed time'=>function(&$p,&$j,&$c,&$h,&$i){$i['sources']['tv_a']['lastValueChangeAt']=null;},
    'indoor warned'=>function(&$p,&$j,&$c,&$h,&$i){$i['warnings']=['INVALID_VALUE'];},
    'source clock future'=>function(&$p,&$j,&$c,&$h,&$i)use($now){$c['lastEventAt']=$now+625;},
    'late home contact'=>function(&$p,&$j,&$c,&$h,&$i)use($now){$p['people']['johannes']['homeMacAt']=$now-2;},
] as $name=>$mutate){
    $p=$phone;$j=$geoJ;$c=$geoC;$h=$house;$i=$indoor;
    $mutate($p,$j,$c,$h,$i);
    $reject=$f($p,$j,$c,$h,$i,$prior,$now+600);
    awayCheck(!$reject['report']['likelyAway']
        &&$reject['next']['eligibleSince']===null,
        'hard-block on '.$name);
}
$restart=$f($phone,$geoJ,$geoC,$house,$indoor,[],$now+600);
awayCheck(!$restart['report']['likelyAway'],
    'reboot does not inherit old 10 minute quiet hold');
$gap=$f($phone,$geoJ,$geoC,$house,$indoor,$prior,$now+900);
awayCheck(!$gap['report']['likelyAway'],
    'missed poll interval >150s restarts evidence continuity');
$future=$f($phone,$geoJ,$geoC,$house,$indoor,$prior,$now+420);
awayCheck(!$future['report']['likelyAway'],
    'clock moved backwards resets eligibility');
$tile['authority']='UNTRUSTED';
$state=JVResidentTileDashboard::compactState($tile,$now+660);
awayCheck($state['people']['johannes']['status']==='Unklar'
    &&$state['people']['carina']['status']==='Unklar',
    'forged tile authority can never claim away');
echo "Household away: dual OUT, 15 GUS, lights/TV, quiet/hold, 23 negative cases and native tile PASS\n";
