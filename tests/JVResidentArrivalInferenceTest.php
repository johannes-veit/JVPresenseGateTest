<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVResidentArrivalInference.php';
function checkArrival(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('ARRIVAL '.$label);
}
$now=1792200000;
$w=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>['carina'=>[
        'validatedExistingVariablePair'=>true,'recentMacEvidence'=>true,
        'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
        'lastMacContactAt'=>$now-30]]];
$j=[];$g=['schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,'insideRecent'=>true,
    'outsideRecent'=>false,'lastEventAt'=>$now-180];
$p=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'recentDirectionProofs'=>[['source'=>'P03_TWO_MAST_VERIFIED',
    'direction'=>'LAGER_TO_HOME','at'=>$now-70]]];
$run=static function()use(&$w,&$j,&$g,&$p,$now):array {
    return JVResidentArrivalInference::evaluate($w,$j,$g,$p,$now);
};
$r=$run();checkArrival($r['people']['carina']['assessment']==='LIKELY_PHONE_ASSOCIATED_HOME_ARRIVAL'
    &&$r['people']['carina']['vehicle']==='UNVERIFIED'
    &&!$r['mayArmAlarm'],'ordered phone and anonymous p03 IN');
$p['recentDirectionProofs']=[];
$r=$run();checkArrival($r['people']['carina']['assessment']==='POSSIBLE_PHONE_RETURN_HOME',
    'broad geofence alone does not prove property arrival');
$g['insideRecent']=false;
$r=$run();checkArrival($r['people']['carina']['assessment']==='HOME_PHONE_CURRENT_ARRIVAL_NOT_PROVEN',
    'home wifi without entry is not an arrival');
$w['people']['carina']['lastMacContactAt']=$now-310;
$r=$run();checkArrival($r['people']['carina']['assessment']==='NOT_ESTABLISHED',
    'stale WiFi cannot establish arrival');
$w['people']['carina']['lastMacContactAt']=$now-30;
$p['recentDirectionProofs']=[['source'=>'P03_TWO_MAST_VERIFIED',
    'direction'=>'HOME_TO_LAGER','at'=>$now-70]];
$r=$run();checkArrival($r['people']['carina']['assessment']==='HOME_PHONE_CURRENT_ARRIVAL_NOT_PROVEN',
    'opposite route cannot prove return');
echo "Arrival correlation tests PASS\n";
