<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVP03IncrementalTimeline.php';

$checks=0;
function checkTimeline(mixed $got,mixed $want,string $label):void {
    global $checks;
    $checks++;
    if($got!==$want)throw new RuntimeException($label.
        ' expected '.var_export($want,true).' got '.var_export($got,true));
}
$tz=new DateTimeZone('Europe/Berlin');
$now=(new DateTimeImmutable('2026-10-13 19:35:00',$tz))->getTimestamp();
$past=[
 '[2026-10-12 19:05:43] P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_JV_THEN_WORK',
 '[2026-10-12 19:06:55] P03 PROOF VERIFIED LAGER_TO_HOME via P03_2CAM_WORK_THEN_JV',
 '[2026-10-12 19:09:47] P03 COMMISSIONING COMPLETE: OUT=2, IN=2'
];
$historic=implode("\n",$past);
$baseline=JVP03IncrementalTimeline::evaluate($historic,[],$now);
checkTimeline($baseline['report']['baselineOnly'],true,
    'already commissioned P03 is baseline only');
checkTimeline($baseline['report']['directionProofs24h'],
    ['HOME_TO_LAGER'=>0,'LAGER_TO_HOME'=>0],
    'do not revive October 12 test as new walk');
checkTimeline($baseline['report']['historicCommissioningReplayedAsLive'],false,
    'no historic commissioning replays');
$repeat=JVP03IncrementalTimeline::evaluate($historic,$baseline['next'],$now+1);
checkTimeline($repeat['report']['newDirectionProofsThisRead'],0,
    're-reading identical log does not invent new motion');
checkTimeline($repeat['report']['logContinuity'],'OK',
    'unchanged full log is continuous');

$newOut="[2026-10-13 19:35:04] P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_JV_THEN_WORK";
$newIn="[2026-10-13 19:35:08] P03 PROOF STRONG_VERIFIED LAGER_TO_HOME via P03_2CAM_WORK_THEN_JV";
$one=JVP03IncrementalTimeline::evaluate($historic."\n".$newOut,
    $repeat['next'],$now+4);
checkTimeline($one['report']['newDirectionProofsThisRead'],1,
    'one genuinely appended P03 proof observed');
checkTimeline($one['report']['directionProofs24h']['HOME_TO_LAGER'],1,
    'LIVE HOME->LAGER event');
checkTimeline($one['report']['directionProofs24h']['LAGER_TO_HOME'],0,
    'no invented reverse event');
checkTimeline($one['report']['personCountCertified'],false,
    'one proof cannot certify one person');
$two=JVP03IncrementalTimeline::evaluate(
    $historic."\n".$newOut."\n".$newIn,$one['next'],$now+8);
checkTimeline($two['report']['newDirectionProofsThisRead'],1,
    'reverse proof appended to existing log');
checkTimeline($two['report']['directionProofs24h']['LAGER_TO_HOME'],1,
    'LIVE LAGER->HOME event');
checkTimeline($two['report']['lastDirectionAt'],$now+8,
    'reverse last proof timestamp');
checkTimeline($two['report']['identityCertified'],false,
    'proof source never identifies Johannes/Carina');
$unchanged=JVP03IncrementalTimeline::evaluate(
    $historic."\n".$newOut."\n".$newIn,$two['next'],$now+9);
checkTimeline($unchanged['report']['directionProofs24h'],
    ['HOME_TO_LAGER'=>1,'LAGER_TO_HOME'=>1],
    'same log after another poll does not double count');
checkTimeline($unchanged['report']['newDirectionProofsThisRead'],0,
    'no new proofs after stable repeated poll');

$other="[2026-10-13 19:35:09] P03 PROOF VERIFIED HOME_TO_LAGER via P03_UNSUPPORTED_BACKUP";
$otherResult=JVP03IncrementalTimeline::evaluate(
    $historic."\n".$newOut."\n".$newIn."\n".$other,
    $unchanged['next'],$now+9);
checkTimeline($otherResult['report']['newDirectionProofsThisRead'],0,
    'unsupported alternate backup route is not guessed');
checkTimeline($otherResult['report']['directionProofs24h'],
    ['HOME_TO_LAGER'=>1,'LAGER_TO_HOME'=>1],
    'unknown route does not manipulate counts');

$conflicting="[2026-10-13 19:35:10] P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_WORK_THEN_JV";
$conflicted=JVP03IncrementalTimeline::evaluate(
    $historic."\n".$newOut."\n".$newIn."\n".$conflicting,
    $unchanged['next'],$now+10);
checkTimeline($conflicted['report']['warnings'],
    ['P03_DIRECTION_ROUTE_MISMATCH'],'wrong route cannot pass');
checkTimeline($conflicted['report']['directionProofs24h'],
    ['HOME_TO_LAGER'=>0,'LAGER_TO_HOME'=>0],
    'direction-route mismatch quarantines all previously retained proofs');
checkTimeline($conflicted['report']['logContinuity'],
    'INVALID_PROOF_EPOCH',
    'contradictory proof cannot claim P03 continuity');
checkTimeline($conflicted['next']['events'],[],
    'contradictory proof cannot persist previous valid epoch');

$duplicate=JVP03IncrementalTimeline::evaluate(
    $historic."\n".$newOut."\n".$newIn."\n".$newOut,
    $two['next'],$now+11);
checkTimeline($duplicate['report']['warnings'],
    ['P03_SAME_SECOND_PROOF_AMBIGUOUS'],
    'same-second replay detected and never converted to people');
checkTimeline($duplicate['report']['directionProofs24h']['HOME_TO_LAGER'],1,
    'same-second duplicate does not count twice');

$reset=JVP03IncrementalTimeline::evaluate(
    '[2026-10-13 19:35:11] P03 reset after reboot',
    $two['next'],$now+11);
checkTimeline($reset['report']['warnings'],['P03_LOG_GAP_OR_RESET'],
    'P03 reset must be highlighted');
checkTimeline($reset['report']['newDirectionProofsThisRead'],0,
    'reset cannot replay a field test');
$afterReset=JVP03IncrementalTimeline::evaluate(
    "[2026-10-13 19:35:11] P03 reset after reboot\n"
    ."[2026-10-13 19:35:12] P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_JV_THEN_WORK",
    $reset['next'],$now+12);
checkTimeline($afterReset['report']['newDirectionProofsThisRead'],1,
    'new real proof after log reset can be observed again');

$fakeHistory='[2026-10-12 19:05:43] P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_JV_THEN_WORK';
$rotRaw=str_repeat('x',54900)."\n".$fakeHistory;
$rotStart=JVP03IncrementalTimeline::evaluate($rotRaw,[],$now);
$rotAppended=substr($rotRaw."\n".$newOut,-55000);
$rot=JVP03IncrementalTimeline::evaluate($rotAppended,
    $rotStart['next'],$now+4);
checkTimeline($rot['report']['logContinuity'],'TAIL_OVERLAP_AFTER_ROTATION',
    'tail-overlap handles P03 max55k log rollover');
checkTimeline($rot['report']['newDirectionProofsThisRead'],1,
    'new appended proof survives actual-style P03 log rollover');
checkTimeline($rot['report']['directionProofs24h']['HOME_TO_LAGER'],1,
    'old 12th commissioning line not replayed at rotation');

$staleLog=JVP03IncrementalTimeline::evaluate(
    $historic."\n".$newOut,$repeat['next'],$now+90000);
checkTimeline($staleLog['report']['directionProofs24h']['HOME_TO_LAGER'],0,
    'proof events older than 24h are not fresh');
checkTimeline($staleLog['report']['warnings'],
    ['P03_EVENT_TIME_UNTRUSTED'],'aged proof is explicitly ignored');

$oversized=JVP03IncrementalTimeline::evaluate(str_repeat('X',262145),
    $two['next'],$now+10);
checkTimeline($oversized['report']['warnings'],
    ['INVALID_CLOCK_OR_LOG_SIZE','P03_SOURCE_LOG_TOO_LARGE'],
    'oversized log rejected with explicit source continuity break');
checkTimeline($oversized['next']['events'],[],
    'invalid oversized log quarantines historical P03 proof cursor');
checkTimeline(strlen($oversized['next']['lastRaw']),65536,
    'only bounded raw tail retained as a new P03 restart anchor');

$future="[2026-10-13 22:00:00] P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_JV_THEN_WORK";
$futureLog=JVP03IncrementalTimeline::evaluate(
    $historic."\n".$future,$repeat['next'],$now+12);
checkTimeline($futureLog['report']['warnings'],
    ['P03_EVENT_TIME_UNTRUSTED'],'untrusted future timestamp rejected');

checkTimeline($two['report']['propertyEmptyMayBeCertified'],false,
    'P03 zone crossing never implies property vacated');
checkTimeline($two['report']['personPresenceMayBeChanged'],false,
    'P03 timeline cannot change user presence');
echo "Passive incremental P03 shadow timeline PASS ($checks assertions)\n";
