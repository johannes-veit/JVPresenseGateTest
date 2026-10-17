<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVResidentEventFusion.php';
function chk(bool $pass,string $message):void{if(!$pass)throw new RuntimeException($message);}
$now=1792260000;
$phone=static fn(int $time):array=>[
    'validatedExistingVariablePair'=>true,'wlanBoolean'=>true,
    'recentMacEvidence'=>true,'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
    'lastMacContactAt'=>$time
];
$w=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY','enabled'=>true,
    'people'=>['johannes'=>$phone($now-30),'carina'=>$phone($now-20)]];
$jg=['schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,'insideRecent'=>false,
    'outsideRecent'=>false,'geofenceReportedInside'=>null,'lastEventAt'=>null];
$cg=['schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,'insideRecent'=>true,
    'outsideRecent'=>false,'geofenceReportedInside'=>true,'lastEventAt'=>$now-900];
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'enabled'=>true,'people'=>[]];
$proof=['source'=>'P03_TWO_MAST_VERIFIED','direction'=>'LAGER_TO_HOME',
    'route'=>'P03_2CAM_WORK_THEN_JV','at'=>$now-20];
$p=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY','recentDirectionProofs'=>[$proof]];
$run=static function(int $at,array $prior=[])use(&$w,&$jg,&$cg,&$work,&$p):array {
    return JVResidentEventFusion::evaluate($w,$jg,$cg,$work,$p,$prior,$at);
};
$first=$run($now);
chk($first['report']['people']['johannes']['state']==='PHONE_HOME_MAC_RECENT','fresh Johannes');
chk($first['report']['people']['carina']['state']==='PHONE_HOME_MAC_RECENT','fresh Carina');
chk($first['report']['people']['johannes']['lastEvent']===null,'Johannes did not arrive');
chk($first['report']['people']['carina']['lastEvent']===null,'Carina stayed home');
chk(count($first['report']['p03']['newAnonymousProofs'])===0,'P03 baseline does not replay');
chk($first['report']['propertyEmptyCertified']===false&&!$first['report']['mayArmAlarm'],'no action');
$second=$run($now+60,$first['next']);
chk(count($second['report']['p03']['newAnonymousProofs'])===0,'repeat no replay');
// An actual new proof is anonymous, even when both phones later refresh.
$p['recentDirectionProofs'][]=['source'=>'P03_TWO_MAST_VERIFIED',
    'direction'=>'HOME_TO_LAGER','route'=>'P03_2CAM_JV_THEN_WORK','at'=>$now+75];
$third=$run($now+80,$second['next']);
chk(count($third['report']['p03']['newAnonymousProofs'])===1,'new proof');
chk($third['report']['people']['carina']['lastEvent']===null,'Carina still not assigned camera crossing');
chk($third['report']['people']['johannes']['lastEvent']===null,'Johannes still not assigned camera crossing');
$fourth=$run($now+100,$third['next']);
chk(count($fourth['report']['p03']['newAnonymousProofs'])===0,'P03 crossing deduplicated');
// Long WLAN gap is not a departure; recovered contact is not a human arrival.
$stale=$run($now+800,$fourth['next']);
chk($stale['report']['people']['johannes']['state']==='PHONE_HOME_MAC_STALE_NOT_AWAY','MAC stale, no away');
$w['people']['johannes']=$phone($now+850);
$recovered=$run($now+900,$stale['next']);
chk($recovered['report']['people']['johannes']['lastEvent']['code']==='HOME_PHONE_REAPPEARED_AFTER_CONTACT_GAP','recovery event');
chk(!$recovered['report']['people']['johannes']['newPhoneReturn'],'recovered not human return');
// Actually observed phone geofence OUT then IN followed by new home MAC.
$jg['outsideRecent']=true;$jg['geofenceReportedInside']=false;$jg['lastEventAt']=$now+960;
$out=$run($now+970,$recovered['next']);
chk($out['report']['people']['johannes']['newPersonalPhoneEvent']['code']==='PHONE_GEOFENCE_OUT_OBSERVED','phone out');
$jg['outsideRecent']=false;$jg['insideRecent']=true;
$jg['geofenceReportedInside']=true;$jg['lastEventAt']=$now+1100;
$in=$run($now+1110,$out['next']);
chk($in['report']['people']['johannes']['newPersonalPhoneEvent']['code']==='PHONE_GEOFENCE_IN_OBSERVED','phone in');
$w['people']['johannes']=$phone($now+1200);
$returned=$run($now+1230,$in['next']);
chk($returned['report']['people']['johannes']['newPhoneReturn'],'observed OUT IN then fresh MAC');
chk($returned['report']['people']['johannes']['lastEvent']['code']==='PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC','phone return recognized');
chk($returned['report']['people']['carina']['lastEvent']===null,'Carina still home and no spurious arrival');
chk(!$returned['report']['vehicleAttributionCertified'],'no inferred car');
$persist=$run($now+1270,$returned['next']);
chk(!$persist['report']['people']['johannes']['newPhoneReturn'],'no replay after refresh');
chk($persist['report']['people']['johannes']['lastEvent']['code']==='PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC','retained last real event');
// Restart keeps state, irrelevant old P03 proof cannot be reinterpreted as new.
$restart=$run($now+1300,$persist['next']);
chk(count($restart['report']['p03']['newAnonymousProofs'])===0,'restart idempotence');
// Untrusted time cannot enter the fusion ledger.
$p['recentDirectionProofs'][]=['source'=>'P03_TWO_MAST_VERIFIED',
    'direction'=>'HOME_TO_LAGER','route'=>'P03_2CAM_JV_THEN_WORK','at'=>$now+90000];
$future=$run($now+1350,$restart['next']);
chk(count($future['report']['p03']['newAnonymousProofs'])===0,'reject future P03 proof');
// Same proof must not be used as an arrival of BOTH residents.
$p['recentDirectionProofs'][]=['source'=>'P03_TWO_MAST_VERIFIED',
    'direction'=>'LAGER_TO_HOME','route'=>'P03_2CAM_WORK_THEN_JV','at'=>$now+1360];
$twoHome=$run($now+1370,$future['next']);
chk(count($twoHome['report']['p03']['newAnonymousProofs'])===1,'new P03 IN recognized');
chk(!$twoHome['report']['p03']['phoneAssociatedWithCrossing'],'P03 never phone identity');
chk($twoHome['report']['people']['carina']['lastEvent']===null,'Carina stays home despite P03 IN');
$work['people']['carina']=['sourceVerified'=>true,'ageSeconds'=>20,
    'workRecent'=>true,'status'=>'WORK_FIRMEN_WLAN_PHONE'];
$w['people']['carina']=$phone($now+1375);
$conflict=$run($now+1380,$twoHome['next']);
chk($conflict['report']['people']['carina']['state']==='HOME_WORK_PHONE_SOURCE_CONFLICT',
    'current opposite HOME and WORK sources report conflict, no forced location');
echo "Resident event fusion PASS\n";
