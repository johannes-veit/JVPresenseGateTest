<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVP03IncrementalTimeline.php';
require_once dirname(__DIR__).'/libs/JVResidentEventFusion.php';
function gapAssert(bool $valid,string $case):void {
    if(!$valid)throw new RuntimeException('P03_OBSERVER_GAP '.$case);
}
$tz=new DateTimeZone('Europe/Berlin');
$t=(new DateTimeImmutable('2026-10-21 13:00:00',$tz))->getTimestamp();
$proof=static function(int $at,string $direction='HOME_TO_LAGER'):string {
    $local=(new DateTimeImmutable('@'.$at))
        ->setTimezone(new DateTimeZone('Europe/Berlin'))
        ->format('Y-m-d H:i:s');
    $route=$direction==='HOME_TO_LAGER'
        ?'P03_2CAM_JV_THEN_WORK':'P03_2CAM_WORK_THEN_JV';
    return '['.$local.'] P03 PROOF VERIFIED '.$direction.' via '.$route;
};
$raw=$proof($t-300);
$baseline=JVP03IncrementalTimeline::evaluate($raw,[],$t);
gapAssert($baseline['report']['baselineOnly']
    &&$baseline['report']['newDirectionProofsThisRead']===0
    &&($baseline['next']['lastObservedAt']??0)===$t,
    'first raw camera log is baseline, never a fresh direction');
$raw.="\n".$proof($t+60);
$normal=JVP03IncrementalTimeline::evaluate($raw,$baseline['next'],$t+65);
gapAssert($normal['report']['newDirectionProofsThisRead']===1
    &&$normal['report']['logContinuity']==='OK',
    'normal verified appends still qualify');
// A P03 worker continues logging while SHADOW has no polling
// for >15 minutes. Even a proof only 10 seconds old does not have
// continuous SHADOW observation provenance.
$offlineNow=$t+1010;
$raw.="\n".$proof($offlineNow-10,'LAGER_TO_HOME');
$offline=JVP03IncrementalTimeline::evaluate(
    $raw,$normal['next'],$offlineNow);
gapAssert($offline['report']['baselineOnly']===true
    &&$offline['report']['logContinuity']==='BASELINE_ONLY'
    &&$offline['report']['newDirectionProofsThisRead']===0
    &&$offline['report']['directionProofs24h']===
        ['HOME_TO_LAGER'=>0,'LAGER_TO_HOME'=>0]
    &&$offline['next']['events']===[],
    'old and recently appended proof are quarantined after missed polls');
gapAssert(in_array('P03_SHADOW_OBSERVATION_GAP',
    $offline['report']['warnings'],true)
    &&($offline['next']['lastObservedAt']??0)===$offlineNow,
    'P03 observer gap explicitly visible in existing warnings');
$noDouble=JVP03IncrementalTimeline::evaluate(
    $raw,$offline['next'],$offlineNow+10);
gapAssert($noDouble['report']['newDirectionProofsThisRead']===0
    &&$noDouble['report']['logContinuity']==='OK',
    'same raw proof never reappears following new baseline');
$raw.="\n".$proof($offlineNow+20,'LAGER_TO_HOME');
$new=JVP03IncrementalTimeline::evaluate(
    $raw,$noDouble['next'],$offlineNow+30);
gapAssert($new['report']['newDirectionProofsThisRead']===1
    &&$new['report']['newlyVerifiedDirectionProofs'][0]['at']===
        $offlineNow+20
    &&!$new['report']['identityCertified'],
    'new observation after baseline still eligible as anonymous direction');
gapAssert(!$new['report']['personPresenceMayBeChanged']
    &&!$new['report']['propertyEmptyMayBeCertified'],
    'no individual, property-empty or alarm permission');
// At exactly the allowed 900-second edge the existing log cursor
// remains anchored; after 901s it must be baselined.
$edgeRaw=$raw."\n".$proof($offlineNow+930);
$edge=JVP03IncrementalTimeline::evaluate(
    $edgeRaw,$new['next'],$offlineNow+930);
gapAssert($edge['report']['newDirectionProofsThisRead']===1
    &&$edge['report']['logContinuity']==='OK',
    'exactly 900s continuity threshold is accepted');
$lateRaw=$edgeRaw."\n".$proof($offlineNow+1831);
$late=JVP03IncrementalTimeline::evaluate(
    $lateRaw,$edge['next'],$offlineNow+1831);
gapAssert($late['report']['newDirectionProofsThisRead']===0
    &&$late['report']['baselineOnly']
    &&in_array('P03_SHADOW_OBSERVATION_GAP',$late['report']['warnings'],true),
    '901s observer gap triggers fresh baseline');
// Migrating an old checkpoint that lacks the observation timestamp
// cannot imply continuous logging even if raw byte cursor matches.
$legacy=$new['next'];unset($legacy['lastObservedAt']);
$legacyResult=JVP03IncrementalTimeline::evaluate(
    $raw."\n".$proof($offlineNow+50),$legacy,$offlineNow+55);
gapAssert($legacyResult['report']['baselineOnly']
    &&$legacyResult['report']['newDirectionProofsThisRead']===0,
    'checkpoint without observation time migrates safely');
// Clock rollback larger than five seconds must discard old route proof.
$clock=$new['next'];
$clock['lastObservedAt']=$offlineNow+100;
$rollback=JVP03IncrementalTimeline::evaluate(
    $raw."\n".$proof($offlineNow+50),$clock,$offlineNow+40);
gapAssert($rollback['report']['baselineOnly']
    &&$rollback['report']['newDirectionProofsThisRead']===0,
    'backwards clock crossing cannot replay previous P03 epoch');
// A broken epoch must not be consumed by a different read-only
// resident fusion as if its camera history were continuous.
$f=JVResidentEventFusion::evaluate([],[],[],[],
    $offline['report'],[],$offlineNow);
gapAssert($f['report']['p03']['logInterrupted']
    &&$f['report']['p03']['newAnonymousProofs']===[]
    &&!$f['report']['mayArmAlarm'],
    'fusion quarantines camera gap, no anonymous route delivery');
echo "P03 observer gap and downstream route quarantine PASS\n";
