<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/P03AuxHumanRule.php';

function expectAux(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$template = [
    'RecordEnable' => true,
    'SnapshotEnable' => true,
    'MailEnable' => true,
    'VoiceEnable' => true,
    'TrigerHttp' => [
        'TrigerHttpEnable' => true,
        'TrigerHttpCommand' => 'http://example.invalid',
    ],
    'KeepUnrelated' => 123,
];

$rule = P03AuxHumanRule::build('P03_JV_LEFT_HUMAN', 7, $template);

expectAux(($rule['Type'] ?? null) === 'CrossRegionDetection', 'rule type');
expectAux(($rule['ObjectTypes'] ?? null) === ['Human'], 'Human-only object filter');
expectAux(($rule['Config']['DetectRegion'] ?? null) === P03AuxHumanRule::REGION, 'fixed region');
expectAux(($rule['Config']['SizeFilter']['MinSize'] ?? null) === [0, 0], 'min size');
expectAux(($rule['Config']['SizeFilter']['MaxSize'] ?? null) === [8191, 8191], 'max size');
expectAux(($rule['EventHandler']['RecordEnable'] ?? true) === false, 'record side effect disabled');
expectAux(($rule['EventHandler']['SnapshotEnable'] ?? true) === false, 'snapshot side effect disabled');
expectAux(($rule['EventHandler']['MailEnable'] ?? true) === false, 'mail side effect disabled');
expectAux(($rule['EventHandler']['VoiceEnable'] ?? true) === false, 'voice side effect disabled');
expectAux(($rule['EventHandler']['TrigerHttp']['TrigerHttpEnable'] ?? true) === false, 'HTTP side effect disabled');
expectAux(($rule['EventHandler']['TrigerHttp']['TrigerHttpCommand'] ?? 'x') === '', 'HTTP command cleared');
expectAux(($rule['EventHandler']['KeepUnrelated'] ?? null) === 123, 'unrelated event handler fields preserved');
expectAux(P03AuxHumanRule::matches($rule, 'P03_JV_LEFT_HUMAN'), 'valid rule matches');

$bad = $rule;
$bad['ObjectTypes'] = ['Unknown'];
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'Unknown object filter rejected');

$bad = $rule;
$bad['Config']['DetectRegion'][1][0] = 7000;
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'wrong region rejected');

$bad = $rule;
$bad['Enable'] = false;
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'disabled rule rejected');

$bad = $rule;
$bad['Type'] = 'CrossLineDetection';
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'wrong rule type rejected');

echo "P03 auxiliary Human IVS rule tests PASS\n";
