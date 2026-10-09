<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/P03AuxHumanRule.php';
require_once dirname(__DIR__) . '/libs/DahuaEventParser.php';
require_once dirname(__DIR__) . '/libs/P03AuxObserverLogic.php';

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

$carry = '';
$rawEvent = "Code=CrossRegionDetection;action=Start;index=0;data={\"RuleID\":4,\"EventID\":101,\"Object\":{\"ObjectType\":\"Human\",\"ObjectID\":99}}\r\n";
$events = DahuaEventParser::feed($rawEvent, $carry);
expectAux(count($events) === 1, 'CrossRegion event parsed');
expectAux(($events[0]['ruleId'] ?? null) === 4, 'CrossRegion RuleID parsed');
expectAux(($events[0]['human'] ?? false) === true, 'CrossRegion Human classification parsed');
expectAux(($events[0]['classification'] ?? null) === 'Human', 'CrossRegion Human classification exposed');



$matchingWithoutHumanPayload = [
    'code' => 'CrossRegionDetection',
    'action' => 'Start',
    // Event RuleID is the Dahua rule Id, NOT the VideoAnalyseRule array index.
    'ruleId' => 0,
    'human' => false,
    'classification' => null
];
expectAux(
    P03AuxObserverLogic::isHumanProofStart($matchingWithoutHumanPayload, 0),
    'Dahua rule Id 0 matches even when table index is different'
);

$wrongRule = $matchingWithoutHumanPayload;
$wrongRule['ruleId'] = 3; // typical table index; must NOT be mistaken for Dahua Id=0
expectAux(
    !P03AuxObserverLogic::isHumanProofStart($wrongRule, 0),
    'VideoAnalyseRule array index is not accepted as Dahua RuleID'
);

$stopEvent = $matchingWithoutHumanPayload;
$stopEvent['action'] = 'Stop';
expectAux(
    !P03AuxObserverLogic::isHumanProofStart($stopEvent, 0),
    'STOP event does not create a new Human proof'
);

echo "P03 auxiliary Human IVS rule tests PASS\n";
