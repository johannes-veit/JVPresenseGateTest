<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/P03ProofEngine.php';

function e(string $id, string $source, float $ts, bool $used = false): array
{
    return ['id' => $id, 'source' => $source, 'ts' => $ts, 'used' => $used];
}

function c(float $ts, string $direction = 'RightToLeft'): array
{
    return ['id' => 'x-' . $ts, 'ts' => $ts, 'direction' => $direction];
}

function assertSameValue($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL $label expected=" . var_export($expected, true) . " actual=" . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
    echo "OK   $label" . PHP_EOL;
}

$base = 1000.0;

// Primary truth: two mast cameras, sequential order determines direction.
$r = P03ProofEngine::evaluateTwoCameraSequence(
    [e('j1', P03ProofEngine::SRC_JV_LEFT, $base - 8), e('w1', P03ProofEngine::SRC_WORK_LEFT, $base)],
    $base + 3,
    30.0,
    2.0
);
assertSameValue(P03ProofEngine::STATE_VERIFIED, $r['state'], '2cam OUT state');
assertSameValue(P03ProofEngine::ACTION_HOME_TO_LAGER, $r['action'], '2cam OUT action');
assertSameValue('P03_2CAM_JV_THEN_WORK', $r['path'], '2cam OUT path');
assertSameValue(['j1', 'w1'], $r['usedEventIds'], '2cam OUT consumes both cameras');

$r = P03ProofEngine::evaluateTwoCameraSequence(
    [e('w1', P03ProofEngine::SRC_WORK_LEFT, $base - 8), e('j1', P03ProofEngine::SRC_JV_LEFT, $base)],
    $base + 3,
    30.0,
    2.0
);
assertSameValue(P03ProofEngine::STATE_VERIFIED, $r['state'], '2cam IN state');
assertSameValue(P03ProofEngine::ACTION_LAGER_TO_HOME, $r['action'], '2cam IN action');
assertSameValue('P03_2CAM_WORK_THEN_JV', $r['path'], '2cam IN path');

// One camera alone or repeated same camera may never create a transfer.
$r = P03ProofEngine::evaluateTwoCameraSequence(
    [e('j1', P03ProofEngine::SRC_JV_LEFT, $base)],
    $base + 10
);
assertSameValue(P03ProofEngine::STATE_UNKNOWN, $r['state'], 'single camera rejected');

$r = P03ProofEngine::evaluateTwoCameraSequence(
    [
        e('j1', P03ProofEngine::SRC_JV_LEFT, $base - 5),
        e('j2', P03ProofEngine::SRC_JV_LEFT, $base)
    ],
    $base + 10
);
assertSameValue(P03ProofEngine::STATE_UNKNOWN, $r['state'], 'same camera twice rejected');

// Exact max-gap accepted, just outside rejected.
$r = P03ProofEngine::evaluateTwoCameraSequence(
    [
        e('j-edge', P03ProofEngine::SRC_JV_LEFT, $base - 30),
        e('w-edge', P03ProofEngine::SRC_WORK_LEFT, $base)
    ],
    $base + 3,
    30.0,
    2.0
);
assertSameValue(P03ProofEngine::STATE_VERIFIED, $r['state'], 'exact 30s gap accepted');

$r = P03ProofEngine::evaluateTwoCameraSequence(
    [
        e('j-stale', P03ProofEngine::SRC_JV_LEFT, $base - 30.01),
        e('w-new', P03ProofEngine::SRC_WORK_LEFT, $base)
    ],
    $base + 10,
    30.0,
    2.0
);
assertSameValue(P03ProofEngine::STATE_UNKNOWN, $r['state'], 'over-gap rejected');

// The two detections must really be sequential, not simultaneous.
$r = P03ProofEngine::evaluateTwoCameraSequence(
    [
        e('j0', P03ProofEngine::SRC_JV_LEFT, $base),
        e('w0', P03ProofEngine::SRC_WORK_LEFT, $base)
    ],
    $base + 5
);
assertSameValue(P03ProofEngine::STATE_UNKNOWN, $r['state'], 'simultaneous timestamps rejected');

// Settle delay prevents committing before an immediate reversal can be seen.
$r = P03ProofEngine::evaluateTwoCameraSequence(
    [
        e('j1', P03ProofEngine::SRC_JV_LEFT, $base - 5),
        e('w1', P03ProofEngine::SRC_WORK_LEFT, $base)
    ],
    $base + 1,
    30.0,
    2.0
);
assertSameValue(P03ProofEngine::STATE_PENDING, $r['state'], 'settle pending');

$r = P03ProofEngine::evaluateTwoCameraSequence(
    [
        e('j1', P03ProofEngine::SRC_JV_LEFT, $base - 4),
        e('w1', P03ProofEngine::SRC_WORK_LEFT, $base - 2),
        e('j2', P03ProofEngine::SRC_JV_LEFT, $base)
    ],
    $base + 1,
    30.0,
    2.0
);
assertSameValue(P03ProofEngine::STATE_CONTRADICTION, $r['state'], 'quick reversal fails closed');
assertSameValue(null, $r['action'], 'quick reversal no action');

// Repeated first-camera detections select the latest useful pair.
$r = P03ProofEngine::evaluateTwoCameraSequence(
    [
        e('j-old', P03ProofEngine::SRC_JV_LEFT, $base - 20),
        e('j-near', P03ProofEngine::SRC_JV_LEFT, $base - 4),
        e('w1', P03ProofEngine::SRC_WORK_LEFT, $base)
    ],
    $base + 3,
    30.0,
    2.0
);
assertSameValue(P03ProofEngine::STATE_VERIFIED, $r['state'], 'repeated first camera still verifies');
assertSameValue(['j-near', 'w1'], $r['usedEventIds'], 'nearest ordered pair consumed');

// Consumed proof may not be reused.
$r = P03ProofEngine::evaluateTwoCameraSequence(
    [
        e('j-used', P03ProofEngine::SRC_JV_LEFT, $base - 5, true),
        e('w-new', P03ProofEngine::SRC_WORK_LEFT, $base)
    ],
    $base + 5
);
assertSameValue(P03ProofEngine::STATE_UNKNOWN, $r['state'], 'consumed evidence ignored');

// Terrace Human does not participate in the primary sequence.
$r = P03ProofEngine::evaluateTwoCameraSequence(
    [
        e('t1', P03ProofEngine::SRC_TERRACE, $base - 8),
        e('w1', P03ProofEngine::SRC_WORK_LEFT, $base)
    ],
    $base + 5
);
assertSameValue(P03ProofEngine::STATE_UNKNOWN, $r['state'], 'terrace cannot replace JV_LEFT');

// CrossLine likewise can only strengthen a pair where BOTH mast cameras exist.
$r = P03ProofEngine::evaluate(
    c($base),
    [
        e('t1', P03ProofEngine::SRC_TERRACE, $base - 20),
        e('w1', P03ProofEngine::SRC_WORK_LEFT, $base + 5)
    ],
    $base + 61,
    30.0,
    60.0
);
assertSameValue(P03ProofEngine::STATE_PROVISIONAL, $r['state'], 'crossline plus terrace/work incomplete');
assertSameValue(null, $r['action'], 'crossline incomplete no action');

$r = P03ProofEngine::evaluate(
    c($base),
    [
        e('j1', P03ProofEngine::SRC_JV_LEFT, $base - 5),
        e('w1', P03ProofEngine::SRC_WORK_LEFT, $base + 5)
    ],
    $base + 6,
    30.0,
    60.0
);
assertSameValue(P03ProofEngine::STATE_STRONG_VERIFIED, $r['state'], 'crossline strengthens OUT pair');
assertSameValue(P03ProofEngine::ACTION_HOME_TO_LAGER, $r['action'], 'crossline OUT pair action');
assertSameValue('P03_OUT_MAST_PAIR_PLUS_CROSSLINE', $r['path'], 'crossline OUT path');

$r = P03ProofEngine::evaluate(
    c($base, 'LeftToRight'),
    [
        e('w1', P03ProofEngine::SRC_WORK_LEFT, $base - 5),
        e('j1', P03ProofEngine::SRC_JV_LEFT, $base + 5)
    ],
    $base + 6,
    30.0,
    60.0
);
assertSameValue(P03ProofEngine::STATE_STRONG_VERIFIED, $r['state'], 'crossline strengthens IN pair');
assertSameValue(P03ProofEngine::ACTION_LAGER_TO_HOME, $r['action'], 'crossline IN pair action');

// CrossLine without both mast detections must never create a transfer.
$r = P03ProofEngine::evaluate(c($base), [], $base + 61);
assertSameValue(P03ProofEngine::STATE_UNKNOWN, $r['state'], 'crossline alone rejected');

$r = P03ProofEngine::evaluate(
    c($base),
    [e('j1', P03ProofEngine::SRC_JV_LEFT, $base - 5)],
    $base + 61
);
assertSameValue(P03ProofEngine::STATE_PROVISIONAL, $r['state'], 'crossline plus one mast rejected');

// Direction-learning remains diagnostic only.
$map = [];
$map = P03ProofEngine::learnDirection($map, 'RightToLeft', P03ProofEngine::ACTION_HOME_TO_LAGER);
$map = P03ProofEngine::learnDirection($map, 'RightToLeft', P03ProofEngine::ACTION_HOME_TO_LAGER);
$map = P03ProofEngine::learnDirection($map, 'LeftToRight', P03ProofEngine::ACTION_LAGER_TO_HOME);
$map = P03ProofEngine::learnDirection($map, 'LeftToRight', P03ProofEngine::ACTION_LAGER_TO_HOME);
$status = P03ProofEngine::directionStatus($map);
assertSameValue(true, $status['stable'], 'diagnostic CrossLine direction map stable');

echo "ALL P03 2-CAMERA PROOF TESTS PASSED" . PHP_EOL;


// Real JV Terrasse: single accepted LeftToRight Human RuleID=5 can
// indicate OUT in SHADOW only. Never turn RightToLeft into an IN proof.
$terraceRule=['name'=>'IVS-1','id'=>5,'humanOnly'=>true];
$terraceEvent=[
    'code'=>'CrossLineDetection','action'=>'Start',
    'ruleName'=>'IVS-1','ruleIdUpper'=>5,'classification'=>'Human',
    'decision'=>'STAIR_HUMAN_START_COUNTED','rejectionReason'=>'MATCHED_FILTER',
    'direction'=>'LeftToRight'
];
$t=P03ProofEngine::terraceOutOnlyShadow($terraceEvent,$terraceRule);
assertSameValue('OUT_CANDIDATE_ONLY',$t['status'],'terrace LeftToRight classified as OUT-only shadow');
assertSameValue(P03ProofEngine::ACTION_HOME_TO_LAGER,$t['action'],'shadow never forms IN direction');
$t=P03ProofEngine::terraceOutOnlyShadow(array_replace(
    $terraceEvent,['direction'=>'RightToLeft']),$terraceRule);
assertSameValue('IGNORED',$t['status'],'Terrace opposite IVS direction rejected, not IN');
assertSameValue(null,$t['action'],'terrace opposite event action null');
foreach([
    ['action'=>'Stop'],
    ['classification'=>'Vehicle'],
    ['ruleIdUpper'=>6],
    ['cfgRuleId'=>6],
    ['ruleIdUpper'=>null],
    ['ruleName'=>'IVS-2'],
    ['code'=>'SmartMotionHuman'],
    ['decision'=>'STAIR_IVS_NOT_MAPPED_OR_NOT_HUMAN'],
    ['rejectionReason'=>'NOT_START_OR_PULSE'],
    ['direction'=>''],
    ['direction'=>'Both']
] as $reject) {
    $t=P03ProofEngine::terraceOutOnlyShadow(
        array_replace($terraceEvent,$reject),$terraceRule);
    assertSameValue('IGNORED',$t['status'],
        'terrace out-only shadow refuses invalid event '.json_encode($reject));
    assertSameValue(null,$t['action'],
        'invalid terrace event cannot create zone action');
}
$t=P03ProofEngine::terraceOutOnlyShadow(
    array_replace($terraceEvent,['classification'=>null]),
    ['name'=>'IVS-1','id'=>5,'humanOnly'=>false]);
assertSameValue('IGNORED',$t['status'],
    'mixed human+vehicle camera filter requires explicit Human for OUT shadow');
$t=P03ProofEngine::terraceOutOnlyShadow(
    array_replace($terraceEvent,['classification'=>null]),$terraceRule);
assertSameValue('OUT_CANDIDATE_ONLY',$t['status'],
    'verified Human-only rule supports accepted camera CrossLine without class');

// Independent real two-mast IN must work while an OUT-only terrace signal
// exists. Main module still uses evaluateTwoCameraSequence exclusively.
$r=P03ProofEngine::evaluateTwoCameraSequence([
    e('terrace-proposal',P03ProofEngine::SRC_TERRACE,$base-9),
    e('work-in',P03ProofEngine::SRC_WORK_LEFT,$base-6),
    e('jv-in',P03ProofEngine::SRC_JV_LEFT,$base)
],$base+3,30,2);
assertSameValue(P03ProofEngine::ACTION_LAGER_TO_HOME,$r['action'],
    'OUT-only optional terrace cannot suppress valid mast pair IN');
assertSameValue(['work-in','jv-in'],$r['usedEventIds'],
    'IN consumes only two mast events, never optional terrace');
