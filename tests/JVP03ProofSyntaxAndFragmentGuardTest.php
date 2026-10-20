<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVP03IncrementalTimeline.php';
require_once dirname(__DIR__).'/libs/JVSharedDepartureTimeline.php';
require_once dirname(__DIR__).'/libs/JVPhoneAreaCycleMatcher.php';
require_once dirname(__DIR__).'/libs/JVP03PhonePairMatrix.php';
function proof98(bool $truth,string $context):void{
    if(!$truth)throw new RuntimeException('P03_PROOF_BOUNDARY_0_8_98 '.$context);
}
$tz=new DateTimeZone('Europe/Berlin');
$now=(new DateTimeImmutable('2026-10-20 19:25:00',$tz))->getTimestamp();
$stamp=static fn(int $sec):string=>
    (new DateTimeImmutable('@'.($now+$sec)))->setTimezone($tz)->format('Y-m-d H:i:s');
$proof=static fn(int $sec,string $dir='HOME_TO_LAGER'):string=>
    '['.$stamp($sec).'] P03 PROOF VERIFIED '.$dir.' via '
    .($dir==='HOME_TO_LAGER'
        ?'P03_2CAM_JV_THEN_WORK':'P03_2CAM_WORK_THEN_JV');
$base=JVP03IncrementalTimeline::evaluate("P03 98 BASELINE\n",[],$now-60);
$goodRaw="P03 98 BASELINE\n".$proof(-30)."\n";
$good=JVP03IncrementalTimeline::evaluate($goodRaw,$base['next'],$now);
proof98($good['report']['logContinuity']==='OK'
    &&$good['report']['verifiedTimeline24hCount']===1,
    'normal complete proof is eligible');

$malformed=[
    '[%s] P03 PROOF VERIFIED HOME_TO_LAGER via P03_UNSUPPORTED_BACKUP',
    '[%s] P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_UNKNOWN',
    '[%s] P03 PROOF MAYBE HOME_TO_LAGER via P03_2CAM_JV_THEN_WORK',
    '[%s] P03 PROOF VERIFIED UNKNOWN_DIRECTION via P03_2CAM_JV_THEN_WORK',
    '[%s] P03 PROOF VERIFIED HOME_TO_LAGER via',
    '[%s] P03 PROOF VERIFIED HOME_TO_LAGER',
];
foreach($malformed as $index=>$row){
    $line=sprintf($row,$stamp(-20));
    $batch=$goodRaw.$line."\n".$proof(-10)."\n";
    $result=JVP03IncrementalTimeline::evaluate(
        $batch,$good['next'],$now+1);
    proof98($result['report']['logContinuity']==='INVALID_PROOF_EPOCH'
        &&in_array('P03_PROOF_SYNTAX_UNTRUSTED',
            $result['report']['warnings'],true)
        &&$result['report']['verifiedTimeline24hCount']===0
        &&$result['report']['newDirectionProofsThisRead']===0
        &&$result['next']['events']===[],
        'malformed P03 proof '.$index.' invalidates whole new batch and old epoch');
    $again=JVP03IncrementalTimeline::evaluate(
        $batch,$result['next'],$now+2);
    proof98($again['report']['verifiedTimeline24hCount']===0,
        'same bad batch cannot resurrect old proofs '.$index);
    $recovered=JVP03IncrementalTimeline::evaluate(
        $batch.$proof(3)."\n",$result['next'],$now+4);
    proof98($recovered['report']['verifiedTimeline24hCount']===1
        &&$recovered['report']['lastDirectionAt']===$now+3,
        'only a newly observed post-anomaly proof resumes '.$index);
}

// Incomplete P03 proof exactly when SymBox reads its shared log,
// followed by a newline terminating the malformed line.
$fragment='['.$stamp(-17).'] P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_';
$partial=JVP03IncrementalTimeline::evaluate(
    $goodRaw.$fragment,$good['next'],$now+1);
proof98($partial['report']['verifiedTimeline24hCount']===1
    &&$partial['report']['newDirectionProofsThisRead']===0,
    'in-flight partial proof must not count prematurely');
$terminated=JVP03IncrementalTimeline::evaluate(
    $goodRaw.$fragment."\n".$proof(-8)."\n",
    $partial['next'],$now+2);
proof98($terminated['report']['logContinuity']==='INVALID_PROOF_EPOCH'
    &&$terminated['report']['verifiedTimeline24hCount']===0
    &&$terminated['next']['events']===[],
    'dangling last P03 proof ended by newline poisons full interrupted epoch');

// Real split proof, including a suffix completed in a separate poll,
// should still join once and must not be falsely invalid.
$latest=$proof(-12);
// Replay every possible single-byte split, not just a few hand-picked
// split positions. This includes the opening bracket, timestamp,
// P03 PROOF marker, verification level, route and the last route byte.
for($cut=1;$cut<strlen($latest);$cut++){
    $prefix=substr($latest,0,$cut);
    $snap1=JVP03IncrementalTimeline::evaluate(
        $goodRaw.$prefix,$good['next'],$now+1);
    proof98($snap1['report']['newDirectionProofsThisRead']===0,
        'partial proof is not a proof '.$cut);
    $snap2=JVP03IncrementalTimeline::evaluate(
        $goodRaw.$latest."\n",$snap1['next'],$now+2);
    proof98($snap2['report']['logContinuity']==='OK'
        &&$snap2['report']['verifiedTimeline24hCount']===2
        &&$snap2['report']['newDirectionProofsThisRead']===1,
        'completed split proof accepted once '.$cut);
    $repeat=JVP03IncrementalTimeline::evaluate(
        $goodRaw.$latest."\n",$snap2['next'],$now+3);
    proof98($repeat['report']['newDirectionProofsThisRead']===0,
        'fully processed fragment never replays '.$cut);
}

// A completed malformed PROOF ending at EOF was ALREADY quarantined.
// On the next poll its tail must not be reparsed as an unfinished proof
// and suppress a genuinely new valid camera event.
$badAtEof=$goodRaw.'['.$stamp(-19).'] P03 PROOF VERIFIED '
    .'HOME_TO_LAGER via P03_UNKNOWN_ROUTE';
$quarantined=JVP03IncrementalTimeline::evaluate(
    $badAtEof,$good['next'],$now+1);
proof98($quarantined['report']['logContinuity']==='INVALID_PROOF_EPOCH'
    &&$quarantined['next']['events']===[],
    'malformed complete EOF proof is quarantined immediately');
$afterMalformed=JVP03IncrementalTimeline::evaluate(
    $badAtEof."\n".$proof(-7)."\n",
    $quarantined['next'],$now+2);
proof98($afterMalformed['report']['logContinuity']==='OK'
    &&$afterMalformed['report']['newDirectionProofsThisRead']===1
    &&$afterMalformed['report']['lastDirectionAt']===$now-7,
    'after rejected EOF proof, separately appended valid line can start clean epoch');

// The line may be unfinished but next append begins a NEW timestamp
// without ever finishing the previous proof. That must break the epoch,
// not silently skip the dangling fragment and keep prior proofs.
$orphaned=JVP03IncrementalTimeline::evaluate(
    $goodRaw.$fragment,$good['next'],$now+1);
$skipToNew=JVP03IncrementalTimeline::evaluate(
    $goodRaw.$fragment.$proof(-6)."\n",
    $orphaned['next'],$now+2);
proof98($skipToNew['report']['logContinuity']==='INVALID_PROOF_EPOCH'
    &&$skipToNew['report']['verifiedTimeline24hCount']===0,
    'new timestamp cannot conceal a broken earlier partial proof');

// Ordinary status and diagnostics that do not assert P03 PROOF must
// remain harmless, even when they mention a route.
$plain=JVP03IncrementalTimeline::evaluate(
    $goodRaw.'['.$stamp(-20).'] P03 STATUS no proof, health ok'."\n",
    $good['next'],$now+1);
proof98($plain['report']['logContinuity']==='OK'
    &&$plain['report']['verifiedTimeline24hCount']===1,
    'non-proof logs do not poison trusted P03 data');

// Downstream cannot pair a camera from an invalidated P03 epoch.
$fusion=['schema'=>1,'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>[
      'johannes'=>['geoSourceContinuous'=>true,
          'macSourceContinuous'=>true,'sourceRebaselineRequired'=>false,
          'lastSourceRebindAt'=>null,
          'eventHistory'=>[['at'=>$now-34,
              'code'=>'PHONE_GEOFENCE_OUT_OBSERVED']]],
      'carina'=>['geoSourceContinuous'=>true,
          'macSourceContinuous'=>true,'sourceRebaselineRequired'=>false,
          'lastSourceRebindAt'=>null,
          'eventHistory'=>[['at'=>$now-32,
              'code'=>'PHONE_GEOFENCE_OUT_OBSERVED']]]
    ]
];
$cycles=JVPhoneAreaCycleMatcher::evaluate($fusion,$now);
proof98($cycles['pairedCycleCount']===1,'qualified two device-area OUTs');
$mode=['schema'=>1,'authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
    'p03ModeTrustedFromFreshDiagnosis'=>true,
    'p03OperatingMode'=>'TEST_ACTIVE'];
$badRaw=$goodRaw.sprintf($malformed[0],$stamp(-20))."\n";
$invalid=JVP03IncrementalTimeline::evaluate(
    $badRaw,$good['next'],$now+1);
$blocked=JVSharedDepartureTimeline::evaluate(
    $fusion,$invalid['report'],$mode,$now+1);
$matrix=JVP03PhonePairMatrix::evaluate($cycles,$invalid['report'],$now+1);
proof98(!$blocked['verifiedP03NearPair']
    &&($matrix['inputUntrusted']??false)===true
    &&($invalid['report']['newDirectionProofsThisRead']??-1)===0
    &&!$blocked['personDepartureCertified']
    &&!$blocked['homeEmptyCertified'],
    'malformed P03 evidence cannot be converted into a personal absence claim');

echo "SHADOW v0.8.98: six malformed P03 PROOF variants, all byte splits, newline and timestamp interruptions, exact recovery and downstream quarantine PASS\n";
