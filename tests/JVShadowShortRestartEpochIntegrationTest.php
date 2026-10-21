<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVShadowStartupEpoch.php';
require_once dirname(__DIR__).'/libs/JVP03IncrementalTimeline.php';
require_once dirname(__DIR__).'/libs/JVPhoneContactContinuity.php';
require_once dirname(__DIR__).'/libs/JVResidentEventFusion.php';
require_once dirname(__DIR__).'/libs/JVLocalActivityLedger.php';
require_once dirname(__DIR__).'/libs/JVIndoorPresenceHints.php';
require_once dirname(__DIR__).'/libs/JVLocalSensorEvidence.php';

function restartGuard(bool $ok,string $what):void {
    if(!$ok)throw new RuntimeException('SHADOW_SHORT_RESTART '.$what);
}
$t=1900000000;
$tz=new DateTimeZone('Europe/Berlin');
$line=static function(int $seconds)use($t,$tz):string{
    $stamp=(new DateTimeImmutable('@'.($t+$seconds)))
        ->setTimezone($tz)->format('Y-m-d H:i:s');
    return '['.$stamp.'] P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_JV_THEN_WORK';
};
$original=JVP03IncrementalTimeline::evaluate("P03 LOG START\n",[],$t-240);
$previous=$original['next'];
$raw="P03 LOG START\n".$line(-45)."\n";
$unsafe=JVP03IncrementalTimeline::evaluate($raw,$previous,$t);
restartGuard($unsafe['report']['newDirectionProofsThisRead']===1,
    'regression really reproduces old false fresh proof after a 4-minute restart');
$prior=JVShadowStartupEpoch::fence('P03IncrementalTimeline',$previous,true);
$safe=JVP03IncrementalTimeline::evaluate($raw,$prior,$t);
restartGuard($safe['report']['newDirectionProofsThisRead']===0
    &&$safe['report']['baselineOnly']===true
    &&$safe['report']['logContinuity']==='BASELINE_ONLY'
    &&$safe['report']['identityCertified']===false,
    'first post-restart P03 log baselined instead of replaying unseen proof');
$unfenced=JVShadowStartupEpoch::fence('P03IncrementalTimeline',
    $previous,false);
restartGuard($unfenced===$previous,
    'normal hot refresh retains previous time without unwanted reset');
// A TRULY new proof after successful baseline can be observed next.
$newRaw=$raw.$line(10)."\n";
$now=$t+15;
$fresh=JVP03IncrementalTimeline::evaluate($newRaw,$safe['next'],$now);
restartGuard($fresh['report']['newDirectionProofsThisRead']===1
    &&$fresh['report']['personCountCertified']===false,
    'new proof after baseline is still recorded once, as anonymous');

$w=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>[]];
$oldPhone=[
 'schema'=>1,'people'=>[
    'johannes'=>[
        'startedAt'=>$t-3600,'lastObservedAt'=>$t-240,
        'pendingOutAt'=>$t-1800,'pendingInAt'=>$t-150,
        'lastMacAt'=>$t-240,
        'lastMacSourceKey'=>'WLAN:101:102',
        'lastGeoSourceKey'=>'EGI:1:2:3:4',
        'lastMacSourceVerified'=>true,'lastGeoSourceVerified'=>true
    ],
    'carina'=>[
        'startedAt'=>$t-3600,'lastObservedAt'=>$t-240,
        'pendingOutAt'=>$t-1200,'pendingInAt'=>null,
        'lastMacSourceKey'=>'WLAN:201:202',
        'lastGeoSourceKey'=>'GEOFENCY:5:6:7:8',
        'lastMacSourceVerified'=>true,'lastGeoSourceVerified'=>true
    ]
 ]
];
$geoJ=['schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,
    'sourceKey'=>'EGI:1:2:3:4',
    'lastEventAt'=>$t-150,'insideRecent'=>true,
    'outsideRecent'=>false,'geofenceReportedInside'=>true];
$geoC=['schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,
    'sourceKey'=>'GEOFENCY:5:6:7:8',
    'lastEventAt'=>$t-1200,'insideRecent'=>false,
    'outsideRecent'=>true,'geofenceReportedInside'=>false];
$w['people']['johannes']=[
    'validatedExistingVariablePair'=>true,'wlanBoolean'=>true,
    'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
    'recentMacEvidence'=>true,'lastMacContactAt'=>$t-20,
    'sourceKey'=>'WLAN:101:102'
];
$w['people']['carina']=[
    'validatedExistingVariablePair'=>true,'wlanBoolean'=>false,
    'state'=>'NO_CURRENT_MAC',
    'recentMacEvidence'=>false,'lastMacContactAt'=>$t-240,
    'sourceKey'=>'WLAN:201:202'
];
$phone=JVPhoneContactContinuity::evaluate($w,$geoJ,$geoC,
    JVShadowStartupEpoch::fence('ResidentPhoneContinuity',$oldPhone,true),$t);
restartGuard($phone['report']['people']['johannes']['observationInterrupted']===true
    &&$phone['report']['people']['johannes']['phoneReturnAfterObservedOutIn']===false
    &&$phone['next']['people']['johannes']['pendingInAt']===null
    &&$phone['next']['people']['carina']['pendingOutAt']===null
    &&$phone['report']['anyHumanArrivalCertified']===false,
    'both handset chains cannot bridge short unobserved OS restart');

$priorFusion=['schema'=>1,'people'=>[
    'johannes'=>['initialized'=>true,'lastObservedAt'=>$t-240],
    'carina'=>['initialized'=>true,'lastObservedAt'=>$t-240]
], 'p03'=>[
    'initialized'=>true,'lastReadAt'=>$t-240,
    'unackedRouteProofs'=>[[
        'source'=>'P03_TWO_MAST_VERIFIED','at'=>$t-100,
        'direction'=>'HOME_TO_LAGER','route'=>'P03_2CAM_JV_THEN_WORK'
    ]]
]];
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'enabled'=>true,'people'=>[]];
$fusion=JVResidentEventFusion::evaluate(
    $w,$geoJ,$geoC,$work,$safe['report'],
    JVShadowStartupEpoch::fence('ResidentEventFusion',$priorFusion,true),$t
);
restartGuard($fusion['report']['p03']['logInterrupted']===true
    &&$fusion['report']['p03']['routeDeliveryProofs']===[]
    &&$fusion['next']['p03']['unackedRouteProofs']===[],
    'old interrupted P03 route cannot be delivered as freshly observed');

$ledger=['schema'=>1,'startedAt'=>$t-90000,
    'lastScanAt'=>$t-240,'continuousFromAt'=>$t-90000,
    'entries'=>[['id'=>'eg_flur','at'=>$t-350,
        'status'=>'SINGLE_UNCERTAIN']]];
$readings=['eg_flur'=>['zone'=>'HOME','name'=>'EG Flur']];
$before=JVLocalActivityLedger::evaluate([],$readings,$ledger,$t);
restartGuard($before['report']['full24hWindowObserved']===true,
    'a 4-minute gap had previously still been marked complete');
$after=JVLocalActivityLedger::evaluate([],$readings,
    JVShadowStartupEpoch::fence('LocalActivityLedger',$ledger,true),$t);
restartGuard($after['report']['full24hWindowObserved']===false
    &&$after['report']['totalMotionEdges24h']===1,
    'new GUS coverage begins after restart, old anonymous event retained');

$sources=[['Enabled'=>true,'SourceID'=>'eg_flur',
    'Name'=>'EG Flur','Kind'=>'MOTION','Zone'=>'HOME','VariableID'=>111]];
$oldMotion=['initialized'=>true,'seen'=>['eg_flur'=>$t-240],
    'history'=>[]];
$snapshot=[111=>['value'=>true,'type'=>0,'changedAt'=>$t-35]];
$motionPrevious=JVLocalSensorEvidence::evaluate(
    $sources,$snapshot,$oldMotion,$t);
$motionFresh=JVLocalSensorEvidence::evaluate(
    $sources,$snapshot,
    JVShadowStartupEpoch::fence('LocalSensorState',$oldMotion,true),$t);
restartGuard(count($motionPrevious['report']['motionSignals'])===1
    &&count($motionFresh['report']['motionSignals'])===0,
    'motion that happened while SymBox was stopped is only startup baseline');

$priorRoute=['schema'=>1,'initialized'=>true,'lastScanAt'=>$t-240,
    'seen'=>[],'open'=>[['source'=>'P03_TWO_MAST_VERIFIED',
    'at'=>$t-300,'direction'=>'HOME_TO_LAGER',
    'route'=>'P03_2CAM_JV_THEN_WORK']]];
$bounded=JVShadowStartupEpoch::fence('RouteEpisodeLedger',$priorRoute,true);
restartGuard($bounded['lastScanAt']===null
    &&count($bounded['open'])===1,
    'existing anonymous route stays stored but continuity is fenced');
restartGuard(JVShadowStartupEpoch::fence(
    'RouteEpisodeLedger',$priorRoute,false)===$priorRoute,
    'normal run does not discard legitimate ongoing route history');
echo "SHADOW sub-15min restart P03/phone/GUS/route epoch guard PASS\n";
