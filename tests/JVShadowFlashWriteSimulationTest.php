<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVLocalActivityLedger.php';
require_once dirname(__DIR__).'/libs/JVShadowStateCheckpoint.php';
function flash86(bool $pass,string $why):void {
    if(!$pass)throw new RuntimeException('SHADOW_FLASH_0_8_86 '.$why);
}
$t=1900000000;
$reading=['eg_flur'=>['zone'=>'HOME','name'=>'EG Flur']];
$first=JVLocalActivityLedger::evaluate([],$reading,[],$t);
$runtime=json_encode($first['next'],JSON_THROW_ON_ERROR);
$durable=$runtime;$writes=1;
flash86(JVShadowStateCheckpoint::useRuntimeCache('LocalActivityLedger'),
    'existing GUS state now uses safely bounded runtime cache');
flash86(JVShadowStateCheckpoint::useRuntimeCache('P03IncrementalTimeline')
    &&JVShadowStateCheckpoint::useRuntimeCache('ResidentPhoneContinuity'),
    'P03 cursor and phone events persist on change, idle heartbeat is checkpointed');

// Simulate a normally polling SymBox at 60-second intervals for 24h.
// Previously lastScanAt alone caused 1440 durable writes/day, even
// without ANY GUS activity. Semantic events still persist immediately.
for($m=1;$m<=1440;$m++){
    $n=$t+$m*60;
    $old=json_decode($runtime,true,512,JSON_THROW_ON_ERROR);
    $result=JVLocalActivityLedger::evaluate([],$reading,$old,$n);
    $new=json_encode($result['next'],JSON_THROW_ON_ERROR);
    if(JVShadowStateCheckpoint::shouldPersist(
        'LocalActivityLedger',$durable,$new,$n)){
        $durable=$new;$writes++;
    }
    $runtime=$new;
}
flash86($writes>=120&&$writes<=150,
    'ten-minute checkpoint reduces idle GUS flash writes to ca. 145/day');
flash86($result['report']['full24hWindowObserved']
    &&$result['report']['continuityWindowSeconds']===86400,
    'runtime cache retains correctly uninterrupted 24h coverage');

// A new GUS edge must immediately become durable despite an in-memory
// poll timestamp and before the ten-minute heartbeat checkpoint.
$n=$t+86460;
$event=['id'=>'eg_flur','at'=>$n,'zone'=>'HOME',
    'status'=>'SINGLE_UNCERTAIN'];
$before=json_decode($runtime,true,512,JSON_THROW_ON_ERROR);
$updated=JVLocalActivityLedger::evaluate([$event],$reading,$before,$n);
$new=json_encode($updated['next'],JSON_THROW_ON_ERROR);
flash86(JVShadowStateCheckpoint::shouldPersist(
    'LocalActivityLedger',$durable,$new,$n),
    'new GUS evidence durably committed without waiting 10 minutes');
$durable=$new;$runtime=$new;
flash86(count($updated['next']['entries'])===1,
    'recent anonymous GUS event is in durable checkpoint');

// A ten-minute stale durable heartbeat after a process restart never
// constitutes proof of continuous polling. Runtime buffer is gone;
// fail closed by rebaselining the 24h completeness claim.
$restartNow=$n+700;
$fromDisk=json_decode($durable,true,512,JSON_THROW_ON_ERROR);
$restart=JVLocalActivityLedger::evaluate([],$reading,$fromDisk,
    $restartNow);
$reset=json_encode($restart['next'],JSON_THROW_ON_ERROR);
flash86(!$restart['report']['full24hWindowObserved']
    &&in_array('LEDGER_OBSERVATION_GAP_REBASELINED',
        $restart['report']['warnings'],true)
    &&count($restart['next']['entries'])===1,
    'post-reboot lost runtime cache conservatively rebaselines coverage but retains events');
flash86(JVShadowStateCheckpoint::shouldPersist(
    'LocalActivityLedger',$durable,$reset,$restartNow),
    'new continuity epoch persists instantly, not after 10 minutes');

// Overflow flags, source changes, true event changes are semantic.
$baseline=$restart['next'];
$changed=$baseline;$changed['capacityTruncatedAt']=$restartNow;
flash86(JVShadowStateCheckpoint::shouldPersist(
    'LocalActivityLedger',json_encode($baseline),json_encode($changed),
    $restartNow),
    'overflow truncation cannot be silently deferred');
$changed=$baseline;$changed['entries']=[];
flash86(JVShadowStateCheckpoint::shouldPersist(
    'LocalActivityLedger',json_encode($baseline),json_encode($changed),
    $restartNow),
    'removing invalid old sensor entries is durable');
$backward=$baseline;$backward['lastScanAt']=$restartNow-500;
flash86(JVShadowStateCheckpoint::shouldPersist(
    'LocalActivityLedger',json_encode($baseline),json_encode($backward),
    $restartNow),
    'clock regression not swallowed by runtime optimization');
echo "SHADOW v0.8.86 SymBox flash/write simulation PASS; durable writes over idle 24h: $writes instead of 1440 minute heartbeats\n";
