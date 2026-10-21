<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPhoneContactContinuity.php';
function ccheck(bool $ok,string $msg):void {
    if(!$ok)throw new RuntimeException('PHONE_CONTINUITY '.$msg);
}
$t=1792200000;
$w=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>['johannes'=>[
        'validatedExistingVariablePair'=>true,'wlanBoolean'=>true,
        'recentMacEvidence'=>true,'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
        'lastMacContactAt'=>$t-60,'sourceKey'=>'WLAN:1001:1002']]];
$j=['schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,'insideRecent'=>false,
    'outsideRecent'=>false,'geofenceReportedInside'=>null,'lastEventAt'=>null,
    'sourceKey'=>'EGI:101:102:103:104'];
$c=[];
$run=static function(int $now,array $prior)use(&$w,&$j,&$c):array {
    return JVPhoneContactContinuity::evaluate($w,$j,$c,$prior,$now);
};
$a=$run($t,[]);
ccheck($a['report']['people']['johannes']['status']==='FRESH_HOME_MAC_CONTACT'
    &&!$a['report']['people']['johannes']['phoneReturnAfterObservedOutIn'],
    'bootstrap is baseline, not arrival');
$b=$run($t+800,$a['next']);
ccheck($b['report']['people']['johannes']['status']==='WLAN_ON_BUT_MAC_CONTACT_STALE_NOT_AWAY'
    &&!$b['report']['people']['johannes']['humanAwayCertified'],
    'true WLAN with 860s stale contact is NOT away');
$w['people']['johannes']['lastMacContactAt']=$t+850;
$c1=$run($t+900,$b['next']);
ccheck($c1['report']['people']['johannes']['contactResumedAfterGap']
    &&$c1['report']['people']['johannes']['status']==='HOME_MAC_RECOVERED_AFTER_GAP_NOT_ARRIVAL',
    'reacquisition after quiet gap is not a return');
ccheck($c1['report']['people']['johannes']['lastRecoveredHomeMacAt']===$t+850
    &&$c1['report']['people']['johannes']['lastObservedRecoveryGapSeconds']===910,
    'new recovery timestamp and original real MAC gap are retained');
$persisted=$run($t+940,$c1['next']);
ccheck($persisted['report']['people']['johannes']['status']==='FRESH_HOME_MAC_CONTACT'
    &&!$persisted['report']['people']['johannes']['contactResumedAfterGap']
    &&$persisted['report']['people']['johannes']['lastRecoveredHomeMacAt']===$t+850
    &&$persisted['report']['people']['johannes']['lastObservedRecoveryGapSeconds']===910,
    'recovery survives following refresh without replaying as new arrival');

$j['outsideRecent']=true;$j['geofenceReportedInside']=false;
$j['lastEventAt']=$t+940;
$d=$run($t+950,$c1['next']);
ccheck(!$d['report']['people']['johannes']['phoneReturnAfterObservedOutIn'],
    'out alone not arrival');
$j['outsideRecent']=false;$j['insideRecent']=true;
$j['geofenceReportedInside']=true;$j['lastEventAt']=$t+1150;
$e=$run($t+1160,$d['next']);
ccheck(!$e['report']['people']['johannes']['phoneReturnAfterObservedOutIn'],
    'in alone not arrival');
$w['people']['johannes']['lastMacContactAt']=$t+1200;
$f=$run($t+1230,$e['next']);
ccheck($f['report']['people']['johannes']['phoneReturnAfterObservedOutIn']
    &&!$f['report']['anyHumanArrivalCertified']
    &&!$f['report']['mayArmAlarm'],
    'fresh MAC after ordered observed out in is only phone return hint');
$g=$run($t+1270,$f['next']);
ccheck(!$g['report']['people']['johannes']['phoneReturnAfterObservedOutIn'],
    'same IN cannot replay return');
echo "Phone contact continuity PASS\n";
