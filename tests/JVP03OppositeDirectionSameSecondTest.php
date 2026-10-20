<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPhoneAreaCycleMatcher.php';
require_once dirname(__DIR__).'/libs/JVP03PhonePairMatrix.php';
require_once dirname(__DIR__).'/libs/JVSharedDepartureTimeline.php';
require_once dirname(__DIR__).'/libs/JVPhoneRoomOverlapAudit.php';
require_once dirname(__DIR__).'/libs/JVShadowCrossSourceIntegrityGate.php';
function opp96(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('SHADOW_0_8_96 '.$why);
}
$t=1900000000;
$event=static fn(string $direction,int $seconds):array=>[
    'code'=>'PHONE_GEOFENCE_'.$direction.'_OBSERVED',
    'at'=>$t+$seconds
];
$person=static fn(array $history):array=>[
    'geoSourceContinuous'=>true,'macSourceContinuous'=>true,
    'sourceRebaselineRequired'=>false,'lastSourceRebindAt'=>null,
    'eventHistory'=>$history
];
$two=static fn(array $j,array $c):array=>[
    'schema'=>1,'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>['johannes'=>$person($j),'carina'=>$person($c)]
];
$p03Event=static fn(int $at,string $direction):array=>[
    'source'=>'P03_TWO_MAST_VERIFIED','direction'=>$direction,
    'at'=>$t+$at
];
$camera=static fn(array $rows):array=>[
    'schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'logContinuity'=>'OK','baselineOnly'=>false,
    'verifiedTimelineTruncated'=>false,'newProofBatchOverflow'=>false,
    'verifiedDirectionTimeline24h'=>$rows,'warnings'=>[]
];
$mode=['schema'=>1,
    'authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
    'p03ModeTrustedFromFreshDiagnosis'=>true,
    'p03OperatingMode'=>'TEST_ACTIVE'];
$activity=['schema'=>1,'authority'=>'MOTION_EDGE_HISTORY_ONLY',
    'perSource'=>[],'full24hWindowObserved'=>false];
$ledger=['schema'=>1,'entries'=>[]];
$phones=$two(
    [$event('OUT',-900),$event('IN',-500)],
    [$event('OUT',-860),$event('IN',-490)]
);
$cycles=JVPhoneAreaCycleMatcher::evaluate($phones,$t);
opp96($cycles['pairedCycleCount']===1,'verified two-phone area interval');

// Previously this contained one apparently unique HOME_TO_LAGER
// crossing although an IN crossing at the very same timestamp exists.
// Without ordering information, the directional proof is ambiguous.
$simultaneous=$camera([
    $p03Event(-830,'HOME_TO_LAGER'),
    $p03Event(-830,'LAGER_TO_HOME')
]);
$a=JVP03PhonePairMatrix::evaluate($cycles,$simultaneous,$t);
opp96(!$a['inputUntrusted']
    &&$a['oppositeDirectionSameSecondIgnored']===1
    &&$a['scopedP03Proofs']===0
    &&$a['matchedPhonePairs']===0
    &&$a['latestPairP03CandidateCount']===0
    &&$a['latestPairP03DirectionConflict']
    &&$a['latestPairP03Ambiguous']
    &&$a['latestPhonePairP03']===null,
    'same-second opposite directions never uniquely confirm latest exit');
$timeline=JVSharedDepartureTimeline::evaluate(
    $phones,$simultaneous,$mode,$t);
opp96($timeline['pairedPhoneAreaOutObserved']
    &&!$timeline['verifiedP03NearPair']
    &&$timeline['p03OppositeDirectionSecondsIgnored']===1
    &&$timeline['p03LatestPairDirectionConflict']
    &&in_array('P03_OPPOSITE_DIRECTIONS_SAME_SECOND_NEAR_LATEST_PAIR',
        $timeline['sourceGaps'],true)
    &&!$timeline['mayControlAlarm']
    &&!$timeline['personCountCertified'],
    'phone interval remains visible, P03 conflict yields precise blocker');
$room=JVPhoneRoomOverlapAudit::evaluate($cycles,$activity,$ledger,$t);
$gate=JVShadowCrossSourceIntegrityGate::evaluate(
    $cycles,$timeline,$simultaneous,$room,$t,$activity,$ledger);
opp96($gate['status']==='CROSS_SOURCE_CONSISTENT_ANONYMOUS_ONLY'
    &&$gate['p03ProofAssignmentReconciled']
    &&!$gate['latestP03LinkConsistent']
    &&!$gate['mayChangePresence'],
    'independent audit reproduces conflict without classifying missing proof as forged');

// Distinct times are fine: reverse crossing one second later is a
// DIFFERENT physical observation, not a contradiction.
$ordered=$camera([
    $p03Event(-830,'HOME_TO_LAGER'),
    $p03Event(-829,'LAGER_TO_HOME')
]);
$b=JVP03PhonePairMatrix::evaluate($cycles,$ordered,$t);
opp96($b['latestPhonePairP03']['p03At']===$t-830
    &&$b['oppositeDirectionSameSecondIgnored']===0
    &&!$b['latestPairP03Ambiguous'],
    'legitimate reverse traversal in a distinct second is not blocked');

// One unambiguous departure plus a contradictory same-second one:
// the clean timestamp may be matched as an aggregate, but the latest
// personal-phone-to-camera association must remain ambiguous.
$mixed=$camera([
    $p03Event(-830,'HOME_TO_LAGER'),
    $p03Event(-830,'LAGER_TO_HOME'),
    $p03Event(-820,'HOME_TO_LAGER')
]);
$c=JVP03PhonePairMatrix::evaluate($cycles,$mixed,$t);
opp96($c['matchedPhonePairs']===1
    &&$c['latestPairP03CandidateCount']===1
    &&$c['latestPairP03DirectionConflict']
    &&$c['latestPhonePairP03']===null,
    'another acceptable candidate cannot erase nearby directional conflict');
$bad=$timeline;
$bad['p03OppositeDirectionSecondsIgnored']=0;
$invalid=JVShadowCrossSourceIntegrityGate::evaluate(
    $cycles,$bad,$simultaneous,$room,$t,$activity,$ledger);
opp96($invalid['status']==='CROSS_SOURCE_CONTRADICTION_NO_ACTION'
    &&in_array('P03_FULL_ASSIGNMENT_DIFFERS_FROM_SOURCE',
        $invalid['violations'],true),
    'forged zero-conflict summary is rejected independently');

// Conflicting proof elsewhere in the daily history must NOT suppress
// an independent genuinely clean crossing near latest phone episode.
$remote=$camera([
    $p03Event(-4000,'HOME_TO_LAGER'),
    $p03Event(-4000,'LAGER_TO_HOME'),
    $p03Event(-830,'HOME_TO_LAGER')
]);
$d=JVP03PhonePairMatrix::evaluate($cycles,$remote,$t);
opp96($d['oppositeDirectionSameSecondIgnored']===1
    &&!$d['latestPairP03DirectionConflict']
    &&!$d['latestPairP03Ambiguous']
    &&$d['latestPhonePairP03']['p03At']===$t-830,
    'historical far-away direction conflict does not block real nearby proof');

// 120 reproducible different cross-direction collision placements,
// validated with an independent one-pair reference (no DP reuse).
mt_srand(20261020);
for($scenario=0;$scenario<120;$scenario++){
    $proofs=[];$outTimes=[];$inTimes=[];
    for($k=0,$n=mt_rand(1,9);$k<$n;$k++){
        $sec=mt_rand(-1310,-470);
        $dir=mt_rand(0,1)===1
            ?'HOME_TO_LAGER':'LAGER_TO_HOME';
        $proofs[]=$p03Event($sec,$dir);
        if($dir==='HOME_TO_LAGER')$outTimes[$sec]=true;
        else $inTimes[$sec]=true;
        if(mt_rand(0,4)===0){
            $other=$dir==='HOME_TO_LAGER'
                ?'LAGER_TO_HOME':'HOME_TO_LAGER';
            $proofs[]=$p03Event($sec,$other);
            if($other==='HOME_TO_LAGER')$outTimes[$sec]=true;
            else $inTimes[$sec]=true;
        }
    }
    $refClean=0;$refColliding=0;$nearConflict=false;
    foreach($outTimes as $sec=>$yes){
        $collided=isset($inTimes[$sec]);
        if($collided){
            $refColliding++;
            if($sec>=-1200&&$sec<=-500)$nearConflict=true;
        }elseif($sec>=-1200&&$sec<=-500)$refClean++;
    }
    $result=JVP03PhonePairMatrix::evaluate(
        $cycles,$camera($proofs),$t);
    opp96(!$result['inputUntrusted']
        &&$result['latestPairP03CandidateCount']===$refClean
        &&$result['oppositeDirectionSameSecondIgnored']===$refColliding
        &&$result['latestPairP03DirectionConflict']===$nearConflict
        &&$result['matchedPhonePairs']===($refClean>0?1:0)
        &&($result['latestPhonePairP03']!==null)
            ===($refClean===1&&!$nearConflict),
        'independent 120-case opposing-direction replay '.$scenario);
}
echo "SHADOW v0.8.96: same-second opposite P03 directions fail closed; 120 independent collision replays PASS\n";
