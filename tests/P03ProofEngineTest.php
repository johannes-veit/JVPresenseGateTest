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

// OUT primary: JV-left before boundary, workshop-left after.
$r = P03ProofEngine::evaluate(
    c($base),
    [e('j1', P03ProofEngine::SRC_JV_LEFT, $base - 8), e('w1', P03ProofEngine::SRC_WORK_LEFT, $base + 6)],
    $base + 7
);
assertSameValue(P03ProofEngine::STATE_VERIFIED, $r['state'], 'OUT primary state');
assertSameValue(P03ProofEngine::ACTION_HOME_TO_LAGER, $r['action'], 'OUT primary action');
assertSameValue('P03_OUT_PRIMARY', $r['path'], 'OUT primary path');

// OUT strong: terrace also sees person on HOME side.
$r = P03ProofEngine::evaluate(
    c($base),
    [
        e('t1', P03ProofEngine::SRC_TERRACE, $base - 30),
        e('j1', P03ProofEngine::SRC_JV_LEFT, $base - 8),
        e('w1', P03ProofEngine::SRC_WORK_LEFT, $base + 6)
    ],
    $base + 7
);
assertSameValue(P03ProofEngine::STATE_STRONG_VERIFIED, $r['state'], 'OUT strong state');

// IN primary: workshop-left before boundary, JV-left after.
$r = P03ProofEngine::evaluate(
    c($base, 'LeftToRight'),
    [e('w1', P03ProofEngine::SRC_WORK_LEFT, $base - 7), e('j1', P03ProofEngine::SRC_JV_LEFT, $base + 5)],
    $base + 6
);
assertSameValue(P03ProofEngine::STATE_VERIFIED, $r['state'], 'IN primary state');
assertSameValue(P03ProofEngine::ACTION_LAGER_TO_HOME, $r['action'], 'IN primary action');
assertSameValue('P03_IN_PRIMARY', $r['path'], 'IN primary path');

// IN strong: terrace also confirms later on HOME side.
$r = P03ProofEngine::evaluate(
    c($base, 'LeftToRight'),
    [
        e('w1', P03ProofEngine::SRC_WORK_LEFT, $base - 7),
        e('j1', P03ProofEngine::SRC_JV_LEFT, $base + 5),
        e('t1', P03ProofEngine::SRC_TERRACE, $base + 25)
    ],
    $base + 26
);
assertSameValue(P03ProofEngine::STATE_STRONG_VERIFIED, $r['state'], 'IN strong state');

// OUT fallback: terrace before, JV-left misses, workshop-left after.
$r = P03ProofEngine::evaluate(
    c($base),
    [e('t1', P03ProofEngine::SRC_TERRACE, $base - 40), e('w1', P03ProofEngine::SRC_WORK_LEFT, $base + 8)],
    $base + 9
);
assertSameValue(P03ProofEngine::STATE_VERIFIED, $r['state'], 'OUT fallback state');
assertSameValue('P03_OUT_TERRACE_FALLBACK', $r['path'], 'OUT fallback path');

// IN fallback: workshop-left before, JV-left misses, terrace after.
$r = P03ProofEngine::evaluate(
    c($base, 'LeftToRight'),
    [e('w1', P03ProofEngine::SRC_WORK_LEFT, $base - 8), e('t1', P03ProofEngine::SRC_TERRACE, $base + 35)],
    $base + 36
);
assertSameValue(P03ProofEngine::STATE_VERIFIED, $r['state'], 'IN fallback state');
assertSameValue('P03_IN_TERRACE_FALLBACK', $r['path'], 'IN fallback path');

// Pending until post-side confirmation window has elapsed.
$r = P03ProofEngine::evaluate(
    c($base),
    [e('j1', P03ProofEngine::SRC_JV_LEFT, $base - 5)],
    $base + 10
);
assertSameValue(P03ProofEngine::STATE_PENDING, $r['state'], 'Pending state');

// Incomplete after timeout becomes provisional, never verified.
$r = P03ProofEngine::evaluate(
    c($base),
    [e('j1', P03ProofEngine::SRC_JV_LEFT, $base - 5)],
    $base + 61
);
assertSameValue(P03ProofEngine::STATE_PROVISIONAL, $r['state'], 'Provisional after timeout');

// No human evidence becomes unknown.
$r = P03ProofEngine::evaluate(c($base), [], $base + 61);
assertSameValue(P03ProofEngine::STATE_UNKNOWN, $r['state'], 'Unknown without human proof');

// Both directions match -> contradiction, no action.
$r = P03ProofEngine::evaluate(
    c($base),
    [
        e('j-pre', P03ProofEngine::SRC_JV_LEFT, $base - 7),
        e('w-pre', P03ProofEngine::SRC_WORK_LEFT, $base - 6),
        e('j-post', P03ProofEngine::SRC_JV_LEFT, $base + 6),
        e('w-post', P03ProofEngine::SRC_WORK_LEFT, $base + 7)
    ],
    $base + 8
);
assertSameValue(P03ProofEngine::STATE_CONTRADICTION, $r['state'], 'Contradiction with both proof paths');
assertSameValue(null, $r['action'], 'Contradiction has no action');

// Used events must not be reused for a second crossing.
$r = P03ProofEngine::evaluate(
    c($base),
    [
        e('j1', P03ProofEngine::SRC_JV_LEFT, $base - 5, true),
        e('w1', P03ProofEngine::SRC_WORK_LEFT, $base + 5, true)
    ],
    $base + 61
);
assertSameValue(P03ProofEngine::STATE_UNKNOWN, $r['state'], 'Consumed evidence ignored');

// Three-camera fallback without CrossLine: OUT.
$r = P03ProofEngine::evaluateThreeCameraSequence(
    [
        e('t1', P03ProofEngine::SRC_TERRACE, $base - 20),
        e('j1', P03ProofEngine::SRC_JV_LEFT, $base - 8),
        e('w1', P03ProofEngine::SRC_WORK_LEFT, $base)
    ],
    $base + 4
);
assertSameValue(P03ProofEngine::STATE_STRONG_VERIFIED, $r['state'], '3cam OUT no-line state');
assertSameValue(P03ProofEngine::ACTION_HOME_TO_LAGER, $r['action'], '3cam OUT no-line action');
assertSameValue('P03_3CAM_OUT_NO_LINE', $r['path'], '3cam OUT no-line path');

// Three-camera fallback without CrossLine: IN.
$r = P03ProofEngine::evaluateThreeCameraSequence(
    [
        e('w1', P03ProofEngine::SRC_WORK_LEFT, $base - 8),
        e('j1', P03ProofEngine::SRC_JV_LEFT, $base),
        e('t1', P03ProofEngine::SRC_TERRACE, $base + 20)
    ],
    $base + 24
);
assertSameValue(P03ProofEngine::STATE_STRONG_VERIFIED, $r['state'], '3cam IN no-line state');
assertSameValue(P03ProofEngine::ACTION_LAGER_TO_HOME, $r['action'], '3cam IN no-line action');
assertSameValue('P03_3CAM_IN_NO_LINE', $r['path'], '3cam IN no-line path');

// Before settle delay, no-line proof stays pending.
$r = P03ProofEngine::evaluateThreeCameraSequence(
    [
        e('t1', P03ProofEngine::SRC_TERRACE, $base - 20),
        e('j1', P03ProofEngine::SRC_JV_LEFT, $base - 8),
        e('w1', P03ProofEngine::SRC_WORK_LEFT, $base)
    ],
    $base + 1
);
assertSameValue(P03ProofEngine::STATE_PENDING, $r['state'], '3cam settle pending');

// Wrong/incomplete order may never become a verified transfer.
$r = P03ProofEngine::evaluateThreeCameraSequence(
    [
        e('t1', P03ProofEngine::SRC_TERRACE, $base - 20),
        e('w1', P03ProofEngine::SRC_WORK_LEFT, $base - 8),
        e('j1', P03ProofEngine::SRC_JV_LEFT, $base)
    ],
    $base + 10
);
assertSameValue(P03ProofEngine::STATE_UNKNOWN, $r['state'], '3cam wrong order rejected');

// Stale camera event outside the permitted gap is rejected.
$r = P03ProofEngine::evaluateThreeCameraSequence(
    [
        e('t1', P03ProofEngine::SRC_TERRACE, $base - 120),
        e('j1', P03ProofEngine::SRC_JV_LEFT, $base - 8),
        e('w1', P03ProofEngine::SRC_WORK_LEFT, $base)
    ],
    $base + 10
);
assertSameValue(P03ProofEngine::STATE_UNKNOWN, $r['state'], '3cam stale evidence rejected');

// Consumed human evidence may not create a second no-line transfer.
$r = P03ProofEngine::evaluateThreeCameraSequence(
    [
        e('t1', P03ProofEngine::SRC_TERRACE, $base - 20, true),
        e('j1', P03ProofEngine::SRC_JV_LEFT, $base - 8, true),
        e('w1', P03ProofEngine::SRC_WORK_LEFT, $base, true)
    ],
    $base + 10
);
assertSameValue(P03ProofEngine::STATE_UNKNOWN, $r['state'], '3cam consumed evidence ignored');

// Direction map requires two consistent proofs in both opposite directions.
$map = [];
$map = P03ProofEngine::learnDirection($map, 'RightToLeft', P03ProofEngine::ACTION_HOME_TO_LAGER);
$map = P03ProofEngine::learnDirection($map, 'RightToLeft', P03ProofEngine::ACTION_HOME_TO_LAGER);
$map = P03ProofEngine::learnDirection($map, 'LeftToRight', P03ProofEngine::ACTION_LAGER_TO_HOME);
$map = P03ProofEngine::learnDirection($map, 'LeftToRight', P03ProofEngine::ACTION_LAGER_TO_HOME);
$status = P03ProofEngine::directionStatus($map);
assertSameValue(true, $status['stable'], 'Direction map stable');
assertSameValue(P03ProofEngine::ACTION_HOME_TO_LAGER, $status['resolved']['RightToLeft'] ?? null, 'RightToLeft learned OUT');
assertSameValue(P03ProofEngine::ACTION_LAGER_TO_HOME, $status['resolved']['LeftToRight'] ?? null, 'LeftToRight learned IN');

// Same direction observed as both physical actions -> contradiction.
$map = [];
$map = P03ProofEngine::learnDirection($map, 'RightToLeft', P03ProofEngine::ACTION_HOME_TO_LAGER);
$map = P03ProofEngine::learnDirection($map, 'RightToLeft', P03ProofEngine::ACTION_LAGER_TO_HOME);
$status = P03ProofEngine::directionStatus($map);
assertSameValue(true, $status['contradiction'], 'Direction map contradiction');

echo "ALL P03 PROOF ENGINE TESTS PASSED" . PHP_EOL;
