<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVP03IncrementalTimeline.php';
require_once dirname(__DIR__).'/libs/JVResidentEventFusion.php';
require_once dirname(__DIR__).'/libs/JVPresenceCommissioningGate.php';

function epoch92(bool $pass,string $reason):void {
    if(!$pass)throw new RuntimeException('P03_FUSION_EPOCH_0_8_92 '.$reason);
}
$zone=new DateTimeZone('Europe/Berlin');
$t=(new DateTimeImmutable('2026-10-20 16:00:00',$zone))->getTimestamp();
$stamp=static fn(int $at):string=>(new DateTimeImmutable('@'.$at))
    ->setTimezone(new DateTimeZone('Europe/Berlin'))
    ->format('Y-m-d H:i:s');
$proof=static fn(int $at):string=>'['.$stamp($at)
    .'] P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_JV_THEN_WORK';
$w=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>[]];
$g=['schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
    'enabled'=>true,'validatedSource'=>false];
$c=$g;$c['authority']='EXISTING_CARINA_GEOFENCY_READ_ONLY';
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'enabled'=>true,'people'=>[]];
$step=static fn(array $p03,array $previous,int $time):array=>
    JVResidentEventFusion::evaluate($w,$g,$c,$work,$p03,$previous,$time);
$initial=JVP03IncrementalTimeline::evaluate('P03 FIRST EPOCH',[],$t);
$f0=$step($initial['report'],[],$t);
$line1='P03 FIRST EPOCH'."\n".$proof($t+2);
$first=JVP03IncrementalTimeline::evaluate(
    $line1,$initial['next'],$t+3);
$f1=$step($first['report'],$f0['next'],$t+3);
epoch92(count($f1['report']['p03']['newAnonymousProofs'])===1
    &&($f1['report']['p03']['lastAnonymousProof']['at']??null)===$t+2
    &&count($f1['next']['p03']['seen'])===1,
    'first genuine new verified P03 proof published exactly once');
$noReplay=$step($first['report'],$f1['next'],$t+4);
epoch92(count($noReplay['report']['p03']['newAnonymousProofs'])===0,
    'repeat P03 report does not count old proof twice');

// Protocol reset starts a NEW camera epoch. The previously verified
// old event must vanish from the fusion output and durable cache.
$reset=JVP03IncrementalTimeline::evaluate(
    'P03 NEW CAMERA EPOCH',$first['next'],$t+5);
$fReset=$step($reset['report'],$noReplay['next'],$t+5);
epoch92(($reset['report']['logContinuity']??null)==='LOG_GAP_OR_RESET'
    &&($fReset['report']['p03']['logInterrupted']??false)
    &&($fReset['report']['p03']['lastAnonymousProof']??null)===null
    &&($fReset['next']['p03']['lastAnonymous']??null)===null
    &&($fReset['next']['p03']['highWaterAt']??null)===null
    &&($fReset['next']['p03']['seen']??[])===[]
    &&($fReset['next']['p03']['unackedRouteProofs']??[])===[]
    &&count($fReset['report']['p03']['newAnonymousProofs'])===0,
    'old high water, old last proof and pending route deliveries cleared on P03 reset');
$line2='P03 NEW CAMERA EPOCH'."\n".$proof($t+7);
$restored=JVP03IncrementalTimeline::evaluate(
    $line2,$reset['next'],$t+8);
$fNew=$step($restored['report'],$fReset['next'],$t+8);
epoch92(($fNew['report']['p03']['logInterrupted']??true)===false
    &&count($fNew['report']['p03']['newAnonymousProofs'])===1
    &&($fNew['report']['p03']['lastAnonymousProof']['at']??null)===$t+7
    &&count($fNew['next']['p03']['seen'])===1,
    'first truly new proof after reset accepted in isolated new epoch');
epoch92(($fNew['report']['allAwayCertified']??true)===false
    &&($fNew['report']['mayArmAlarm']??true)===false
    &&($fNew['report']['p03']['phoneAssociatedWithCrossing']??true)===false,
    'no personal travel or property absence promoted from anonymous cameras');

// A temporary parser failure and a missing timer both reset the cache.
// The affected handset-independent P03 proof must not persist.
$failed=$restored['report'];
$failed['logContinuity']='UNKNOWN';
$fFailed=$step($failed,$fNew['next'],$t+9);
epoch92(($fFailed['next']['p03']['lastAnonymous']??null)===null
    &&($fFailed['next']['p03']['seen']??[])===[]
    &&($fFailed['next']['p03']['highWaterAt']??null)===null,
    'unverified P03 source never retains last displayed camera proof');
$longGap=$step($restored['report'],$fNew['next'],$t+4000);
epoch92(($longGap['next']['p03']['lastAnonymous']??null)===null
    &&count($longGap['next']['p03']['seen']??[])===1
    &&($longGap['report']['p03']['newAnonymousProofs']??[])==[],
    'a reboot-length observation gap keeps only duplicate guard keys, no active proof');
$longGapAgain=$step($restored['report'],$longGap['next'],$t+4060);
epoch92(($longGapAgain['report']['p03']['newAnonymousProofs']??[])===[]
    &&($longGapAgain['report']['p03']['lastAnonymousProof']??null)===null,
    'first resumed poll cannot replay a baseline camera proof');

// Valid phone and LCN source states must not be erased by P03-only repair.
$phoneOnly=JVResidentEventFusion::evaluate(
    $w,$g,$c,$work,$failed,$fNew['next'],$t+9);
epoch92(isset($phoneOnly['next']['people']['johannes'])
    &&isset($phoneOnly['next']['people']['carina'])
    &&($phoneOnly['report']['maySwitchDevices']??true)===false,
    'camera epoch repair changes no independent hardware or source');
echo "SHADOW v0.8.92: P03 parser-to-fusion epoch reset, reboot gap and new proof resume PASS\n";
