<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVP03IncrementalTimeline.php';
function epoch92(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('P03_CONTINUITY_0_8_92 '.$why);
}
$tz=new DateTimeZone('Europe/Berlin');
$at=(new DateTimeImmutable('2026-10-20 14:40:00',$tz))->getTimestamp();
$line=static function(int $seconds,string $dir='HOME_TO_LAGER')use($at,$tz):string{
    $local=(new DateTimeImmutable('@'.($at+$seconds)))
        ->setTimezone($tz)->format('Y-m-d H:i:s');
    $route=$dir==='HOME_TO_LAGER'
        ?'P03_2CAM_JV_THEN_WORK':'P03_2CAM_WORK_THEN_JV';
    return '['.$local.'] P03 PROOF VERIFIED '.$dir.' via '.$route;
};
$healthy=JVP03IncrementalTimeline::evaluate('P03 BOOT',[],$at);
$one=JVP03IncrementalTimeline::evaluate(
    "P03 BOOT\n".$line(2),$healthy['next'],$at+3);
epoch92($one['report']['verifiedTimeline24hCount']===1,
    'initial P03 live proof recorded in original epoch');

// An unreadably large P03 value must NEVER preserve old camera
// evidence in durable state and then silently resurrect it after the
// logged text is trimmed. Simulate >256 KiB by one large source read.
$tooLarge="P03 BOOT\n".$line(2)."\n"
    .str_repeat('X',263000).'UNIQUE_LOG_CUT'
    .str_repeat('A',1024).str_repeat('B',1024);
$invalid=JVP03IncrementalTimeline::evaluate(
    $tooLarge,$one['next'],$at+5);
epoch92($invalid['report']['verifiedTimeline24hCount']===0
    &&$invalid['report']['logContinuity']==='UNKNOWN'
    &&in_array('P03_SOURCE_LOG_TOO_LARGE',
        $invalid['report']['warnings'],true)
    &&$invalid['next']['events']===[]
    &&$invalid['next']['initialized']
    &&strlen($invalid['next']['lastRaw'])===65536,
    'oversized source read quarantines old episode and only keeps bounded raw anchor');

// One truly appended P03 proof may be admitted AFTER the bounded
// truncated tail is uniquely identified. None from prior epoch return.
$tail=$invalid['next']['lastRaw'];
$recovered='NEW ROTATED P03 PROTOCOL\n'.$tail."\n".$line(6);
$post=JVP03IncrementalTimeline::evaluate(
    $recovered,$invalid['next'],$at+7);
epoch92($post['report']['newDirectionProofsThisRead']===1
    &&$post['report']['verifiedTimeline24hCount']===1
    &&$post['report']['lastDirectionAt']===$at+6
    &&$post['report']['directionProofs24h']['HOME_TO_LAGER']===1
    &&$post['report']['logContinuity']==='TAIL_OVERLAP_AFTER_ROTATION',
    'only uniquely anchored newly appended camera proof may resume');
$repeat=JVP03IncrementalTimeline::evaluate(
    $recovered,$post['next'],$at+8);
epoch92($repeat['report']['newDirectionProofsThisRead']===0,
    'new P03 epoch does not replay on unchanged refresh');

// The old 2048-byte rotation anchor MUST occur exactly once.
// If it exists more than once, neither occurrence supplies a
// trustworthy cursor. Fail closed, then accept a truly new append.
$anchor='SESSION-ID:'.str_repeat('K',80);
$boot2=JVP03IncrementalTimeline::evaluate($anchor,[],$at);
$old2=JVP03IncrementalTimeline::evaluate(
    $anchor."\n".$line(2),$boot2['next'],$at+3);
epoch92($old2['report']['verifiedTimeline24hCount']===1,
    'different healthy epoch contains one proof');
$repeatAnchor='SESSION-REBUILT\n'
    .$old2['next']['lastRaw']."\n"
    .$old2['next']['lastRaw']."\n".$line(7);
$ambig=JVP03IncrementalTimeline::evaluate(
    $repeatAnchor,$old2['next'],$at+8);
epoch92($ambig['report']['logContinuity']==='LOG_GAP_OR_RESET'
    &&in_array('P03_LOG_GAP_OR_RESET',$ambig['report']['warnings'],true)
    &&$ambig['report']['verifiedTimeline24hCount']===0
    &&$ambig['next']['events']===[],
    'repeated rotation anchor cannot select arbitrarily one of two cut points');
$newAfterAmbig=JVP03IncrementalTimeline::evaluate(
    $repeatAnchor."\n".$line(9),$ambig['next'],$at+10);
epoch92($newAfterAmbig['report']['newDirectionProofsThisRead']===1
    &&$newAfterAmbig['report']['verifiedTimeline24hCount']===1,
    'after duplicated-anchor quarantine fresh proof resumes normally');
$badClock=JVP03IncrementalTimeline::evaluate(
    $repeatAnchor,$newAfterAmbig['next'],-1);
epoch92($badClock['next']===$newAfterAmbig['next']
    &&$badClock['report']['verifiedTimeline24hCount']===0,
    'impossible system clock cannot mutate durable P03 state or produce proof');

echo "SHADOW 0.8.92 P03 oversized log, repeated anchor, isolated recovery, replay and invalid clock PASS\n";
