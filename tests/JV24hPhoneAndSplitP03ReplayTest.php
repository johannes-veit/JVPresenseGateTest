<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVResidentEventFusion.php';
require_once dirname(__DIR__).'/libs/JVP03IncrementalTimeline.php';

function day92(bool $condition,string $reason):void {
    if(!$condition)throw new RuntimeException('SHADOW_DAYBOUND_0_8_92 '.$reason);
}
$tz=new DateTimeZone('Europe/Berlin');
$now=(new DateTimeImmutable('2026-10-20 13:00:00',$tz))->getTimestamp();
$phone=static fn(int $at):array=>[
    'validatedExistingVariablePair'=>true,'wlanBoolean'=>true,
    'recentMacEvidence'=>true,'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
    'lastMacContactAt'=>$at
];
$w=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>[
        'johannes'=>$phone($now-15),'carina'=>$phone($now-15)
    ]];
$geoJ=['schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,
    'insideRecent'=>false,'outsideRecent'=>true,
    'geofenceReportedInside'=>false,'lastEventAt'=>$now-5];
$geoC=['schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,
    'insideRecent'=>false,'outsideRecent'=>false,
    'geofenceReportedInside'=>null,'lastEventAt'=>null];
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'enabled'=>true,'people'=>[]];
$p03=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'logContinuity'=>'OK','recentDirectionProofs'=>[]];
$personState=static fn(int $init):array=>[
    'initialized'=>true,'initializedAt'=>$init,
    'lastObservedAt'=>$now-60,
    'lastMacAt'=>$now-80,'lastMacSourceVerified'=>true,
    'lastGeoSourceVerified'=>true,'lastGeoAt'=>$now-70,
    'lastMacSourceKey'=>null,'lastGeoSourceKey'=>null,
    'lastSourceRebindAt'=>null,'eventHistory'=>[],
    'lastEvent'=>null,'pendingOutAt'=>null,'pendingInAt'=>null,
    'lastPhoneReturnAt'=>null
];
$prior=['schema'=>1,'people'=>[
    'johannes'=>$personState($now-86480),
    'carina'=>$personState($now-86480)
],'p03'=>[]];
$new=JVResidentEventFusion::evaluate(
    $w,$geoJ,$geoC,$work,$p03,$prior,$now);
$j=$new['report']['people']['johannes'];
day92(($j['newPersonalPhoneEvent']['code']??null)==='PHONE_GEOFENCE_OUT_OBSERVED'
    &&$j['eventHistoryCount']===1
    &&!$j['sourceRebaselineRequired'],
    '24h initializedAt expiration must NOT discard a fresh event received 5s before healthy poll');
$again=JVResidentEventFusion::evaluate(
    $w,$geoJ,$geoC,$work,$p03,$new['next'],$now+60);
day92($again['report']['people']['johannes']['eventHistoryCount']===1
    &&$again['report']['people']['johannes']['newPersonalPhoneEvent']===null,
    'same 24h-boundary Geofence OUT must not replay next minute');
$bootPrior=$prior;
$bootPrior['people']['johannes']=$personState($now);
$boot=JVResidentEventFusion::evaluate(
    $w,$geoJ,$geoC,$work,$p03,$bootPrior,$now);
day92($boot['report']['people']['johannes']['newPersonalPhoneEvent']===null,
    'an event preceding first actual initialization still cannot be retroactively counted');

$proof=static fn(string $at):string=>
    '['.$at.'] P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_JV_THEN_WORK';
$base=JVP03IncrementalTimeline::evaluate("P03 LOG\n",[],$now);
$full=$proof('2026-10-20 13:00:02');
$prefix="P03 LOG\n".substr($full,0,43);
$first=JVP03IncrementalTimeline::evaluate($prefix,$base['next'],$now+3);
day92($first['report']['newDirectionProofsThisRead']===0,
    'partial P03 proof must never prematurely count');
$joined=JVP03IncrementalTimeline::evaluate(
    "P03 LOG\n".$full,$first['next'],$now+4);
day92($joined['report']['newDirectionProofsThisRead']===1
    &&$joined['report']['verifiedTimeline24hCount']===1,
    'P03 evidence split between TWO refreshes must be assembled once');
$stable=JVP03IncrementalTimeline::evaluate(
    "P03 LOG\n".$full,$joined['next'],$now+5);
day92($stable['report']['newDirectionProofsThisRead']===0,
    'fully joined P03 proof must not replay');

$second=$proof('2026-10-20 13:00:06');
$stampPartial=substr($second,0,12);
$twoStep=JVP03IncrementalTimeline::evaluate(
    "P03 LOG\n".$full."\n".$stampPartial,$stable['next'],$now+7);
day92($twoStep['report']['newDirectionProofsThisRead']===0,
    'truncated P03 timestamp is not a proof');
$twoComplete=JVP03IncrementalTimeline::evaluate(
    "P03 LOG\n".$full."\n".$second,$twoStep['next'],$now+8);
day92($twoComplete['report']['newDirectionProofsThisRead']===1
    &&$twoComplete['report']['verifiedTimeline24hCount']===2,
    'split timestamp at P03 polling boundary must be recoverable');

$third=$proof('2026-10-20 13:00:10');
$normal=JVP03IncrementalTimeline::evaluate(
    "P03 LOG\n".$full."\n".$second."\n".$third,
    $twoComplete['next'],$now+11);
day92($normal['report']['newDirectionProofsThisRead']===1
    &&$normal['report']['verifiedTimeline24hCount']===3,
    'regular unsplit P03 proof remains processed normally');

// An unanchored P03 source restart must never join an old partial proof
// with a new epoch. Source continuity is more important than completion.
$partialBeforeReset=JVP03IncrementalTimeline::evaluate(
    "P03 LOG\n".$full."\n".substr($third,0,32),
    $normal['next'],$now+12);
$reset=JVP03IncrementalTimeline::evaluate(
    "NEW P03 PROCESS\n".substr($third,32),
    $partialBeforeReset['next'],$now+13);
day92($reset['report']['newDirectionProofsThisRead']===0
    &&$reset['report']['verifiedTimeline24hCount']===0
    &&$reset['report']['logContinuity']==='LOG_GAP_OR_RESET',
    'P03 reset between two fragments can never complete a pre-reset proof');

$futureInit=$prior;
$futureInit['people']['johannes']['initializedAt']=$now+3600;
$future=JVResidentEventFusion::evaluate(
    $w,$geoJ,$geoC,$work,$p03,$futureInit,$now);
day92($future['report']['people']['johannes']['newPersonalPhoneEvent']===null
    &&$future['next']['people']['johannes']['initializedAt']===$now,
    'future-corrupted phone initialization timestamp safely rebaselined');

// A standalone complete P03 proof must never be counted a second time
// when ordinary status text arrives without any new PROOF line.
$withNote=JVP03IncrementalTimeline::evaluate(
    "P03 LOG\n".$full."\n".$second."\n".$third." status ok",
    $normal['next'],$now+12);
day92($withNote['report']['newDirectionProofsThisRead']===0
    &&$withNote['report']['verifiedTimeline24hCount']===3
    &&!$withNote['report']['propertyEmptyMayBeCertified'],
    'non-proof status suffix after complete P03 must not create fourth proof');
echo "SHADOW v0.8.92: 24h phone initializedAt boundary and two split P03 proofs PASS\n";
