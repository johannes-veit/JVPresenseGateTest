<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPhoneContactContinuity.php';
function lineageCheck(bool $ok,string $case):void {
    if(!$ok)throw new RuntimeException('PHONE_SOURCE_EPOCH '.$case);
}
$t=1900000000; // artificial time: no stored household movements
$w=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>[
        'johannes'=>[
            'validatedExistingVariablePair'=>true,'wlanBoolean'=>true,
            'recentMacEvidence'=>true,'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
            'lastMacContactAt'=>$t-40,'sourceKey'=>'WLAN:101:102'
        ],
        'carina'=>[
            'validatedExistingVariablePair'=>true,'wlanBoolean'=>true,
            'recentMacEvidence'=>true,'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
            'lastMacContactAt'=>$t-45,'sourceKey'=>'WLAN:201:202'
        ]
    ]];
$j=['schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,'sourceKey'=>'EGI:1:2:3:4',
    'insideRecent'=>true,'outsideRecent'=>false,
    'geofenceReportedInside'=>true,'lastEventAt'=>$t-50];
$c=['schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,'sourceKey'=>'GEOFENCY:5:6:7:8',
    'insideRecent'=>true,'outsideRecent'=>false,
    'geofenceReportedInside'=>true,'lastEventAt'=>$t-55];
$run=static function(int $now,array $prior)use(&$w,&$j,&$c):array {
    return JVPhoneContactContinuity::evaluate($w,$j,$c,$prior,$now);
};
$first=$run($t,[]);
lineageCheck(($first['report']['people']['johannes']['sourceRebaselineRequired']??false)===true,
    'first observation creates baseline only');
lineageCheck(!$first['report']['people']['johannes']['phoneReturnAfterObservedOutIn'],
    'first observed IN is not an arrival');
$prev=$first['next'];
$j['insideRecent']=false;$j['outsideRecent']=true;
$j['geofenceReportedInside']=false;$j['lastEventAt']=$t+100;
$a=$run($t+110,$prev);$prev=$a['next'];
lineageCheck($a['report']['people']['johannes']['newGeofenceEventObserved'],
    'stable epoch accepts real OUT');
$j['insideRecent']=true;$j['outsideRecent']=false;
$j['geofenceReportedInside']=true;$j['lastEventAt']=$t+160;
$a=$run($t+170,$prev);$prev=$a['next'];
$w['people']['johannes']['lastMacContactAt']=$t+180;
$a=$run($t+190,$prev);$prev=$a['next'];
lineageCheck($a['report']['people']['johannes']['phoneReturnAfterObservedOutIn'],
    'ordered OUT IN and fresh MAC creates handset-only return');
lineageCheck(!$a['report']['anyHumanArrivalCertified']&&!$a['report']['mayArmAlarm'],
    'no human or alarm authorization');
lineageCheck(!$a['report']['people']['carina']['phoneReturnAfterObservedOutIn'],
    'distinct other handset never borrows Johannes events');
// Prepare a second OUT/IN on old verified sources.
$j['insideRecent']=false;$j['outsideRecent']=true;
$j['geofenceReportedInside']=false;$j['lastEventAt']=$t+240;
$a=$run($t+250,$prev);$prev=$a['next'];
$j['insideRecent']=true;$j['outsideRecent']=false;
$j['geofenceReportedInside']=true;$j['lastEventAt']=$t+280;
$a=$run($t+285,$prev);$prev=$a['next'];
lineageCheck(($prev['people']['johannes']['pendingInAt']??null)===$t+280,
    'pending ordered phone IN exists before rebind');
// Change phone source between IN and next MAC: this must NOT be a return.
$w['people']['johannes']['sourceKey']='WLAN:103:104';
$w['people']['johannes']['lastMacContactAt']=$t+290;
$a=$run($t+300,$prev);$prev=$a['next'];
lineageCheck($a['report']['people']['johannes']['sourceRebaselineRequired']
    &&!$a['report']['people']['johannes']['phoneReturnAfterObservedOutIn']
    &&($prev['people']['johannes']['pendingInAt']??null)===null,
    'MAC source change invalidates old OUT IN and restarts safely');
lineageCheck(($prev['people']['johannes']['lastMacSourceKey']??null)==='WLAN:103:104',
    'new verified source key persisted');
$a=$run($t+310,$prev);$prev=$a['next'];
lineageCheck($a['report']['people']['johannes']['sourceContinuityVerified'],
    'second observation on same source is continuous');
// A genuinely new cycle on the rebound source is permitted.
$w['people']['johannes']['lastMacContactAt']=$t+320;
$a=$run($t+325,$prev);$prev=$a['next'];
$j['insideRecent']=false;$j['outsideRecent']=true;
$j['geofenceReportedInside']=false;$j['lastEventAt']=$t+350;
$a=$run($t+360,$prev);$prev=$a['next'];
$j['insideRecent']=true;$j['outsideRecent']=false;
$j['geofenceReportedInside']=true;$j['lastEventAt']=$t+410;
$a=$run($t+415,$prev);$prev=$a['next'];
$w['people']['johannes']['lastMacContactAt']=$t+440;
$a=$run($t+450,$prev);$prev=$a['next'];
lineageCheck($a['report']['people']['johannes']['phoneReturnAfterObservedOutIn'],
    'new post-rebind observations yield handset-only return');
// New geo binding between a different ordered OUT/IN and MAC.
$j['insideRecent']=false;$j['outsideRecent']=true;
$j['geofenceReportedInside']=false;$j['lastEventAt']=$t+500;
$a=$run($t+505,$prev);$prev=$a['next'];
$j['insideRecent']=true;$j['outsideRecent']=false;
$j['geofenceReportedInside']=true;$j['lastEventAt']=$t+540;
$a=$run($t+545,$prev);$prev=$a['next'];
$j['sourceKey']='EGI:9:10:11:12';
$w['people']['johannes']['lastMacContactAt']=$t+550;
$a=$run($t+555,$prev);$prev=$a['next'];
lineageCheck($a['report']['people']['johannes']['sourceRebaselineRequired']
    &&!$a['report']['people']['johannes']['phoneReturnAfterObservedOutIn'],
    'geofence source replacement invalidates already pending IN');
$j['validatedSource']=false;
$a=$run($t+565,$prev);$prev=$a['next'];
lineageCheck(!$a['report']['people']['johannes']['sourceContinuityVerified'],
    'missing geofence breaks continuity');
$j['validatedSource']=true;
$a=$run($t+575,$prev);$prev=$a['next'];
lineageCheck($a['report']['people']['johannes']['sourceRebaselineRequired']
    &&!$a['report']['people']['johannes']['newGeofenceEventObserved']
    &&!$a['report']['people']['johannes']['phoneReturnAfterObservedOutIn'],
    'recovered source with old IN is only baseline');
// Upgrade from old checkpoints with pending legacy IN must also be safe.
$legacy=['schema'=>1,'people'=>['johannes'=>[
    'startedAt'=>$t,'lastMacAt'=>$t+540,'lastGeoAt'=>$t+540,
    'pendingOutAt'=>$t+500,'pendingInAt'=>$t+540,
    'lastPhoneReturnAt'=>null
]]];
$w['people']['johannes']['lastMacContactAt']=$t+590;
$a=$run($t+600,$legacy);
lineageCheck($a['report']['people']['johannes']['sourceRebaselineRequired']
    &&!$a['report']['people']['johannes']['phoneReturnAfterObservedOutIn']
    &&($a['next']['people']['johannes']['pendingOutAt']??null)===null,
    'existing checkpoint without source identity cannot replay IN');
lineageCheck(!$a['report']['people']['carina']['phoneReturnAfterObservedOutIn']
    &&!$a['report']['anyHumanDepartureCertified']&&!$a['report']['mayArmAlarm'],
    'two residents isolated and absolutely read-only');
echo "Phone continuity source epoch and rebind regression PASS\n";
