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
expectAux(($rule['Config']['Action'] ?? null) === ['Cross', 'Appear'], 'CrossRegion action is Cross+Appear');
expectAux(($rule['Config']['Direction'] ?? null) === 'Enter', 'CrossRegion direction is Enter');
expectAux(count(P03AuxHumanRule::REGION) === 4, 'polygon is not redundantly closed');
expectAux(($rule['Config']['SizeFilter']['MinSize'] ?? null) === [0, 0], 'min size');
expectAux(($rule['Config']['SizeFilter']['MaxSize'] ?? null) === [8191, 8191], 'max size');
expectAux(($rule['Config']['SizeFilter']['CalibrateBoxs'][0]['CenterPoint'] ?? null) === [4096, 4096], 'calibrate center');
expectAux(($rule['Config']['SizeFilter']['CalibrateBoxs'][0]['Ratio'] ?? null) === 1, 'calibrate ratio');
expectAux(count($rule['EventHandler']['TimeSection'] ?? []) === 7, '7-day arming schedule');
for ($day = 0; $day < 7; $day++) {
    expectAux(($rule['EventHandler']['TimeSection'][$day][0] ?? null) === '1 00:00:00-23:59:59', 'day ' . $day . ' full-day arming');
}
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
unset($bad['Config']['Action']);
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'missing Action rejected');

$bad = $rule;
$bad['Config']['Action'] = ['Cross'];
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'missing Appear action rejected');

$bad = $rule;
$bad['Config']['Direction'] = 'Leave';
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'wrong direction rejected');

$bad = $rule;
unset($bad['Config']['SizeFilter']['CalibrateBoxs']);
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'missing calibration rejected');

$bad = $rule;
$bad['EventHandler']['TimeSection'][3][0] = '0 00:00:00-23:59:59';
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'non-24x7 schedule rejected');

$bad = $rule;
$bad['Enable'] = false;
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'disabled rule rejected');

$bad = $rule;
$bad['Type'] = 'CrossLineDetection';
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'wrong rule type rejected');

$carry = '';
$rawEvent = "Code=CrossRegionDetection;action=Start;index=0;data={\"CfgRuleId\":3,\"RuleID\":3,\"RuleId\":1,\"Name\":\"P03_JV_LEFT_HUMAN\",\"EventID\":101,\"Action\":\"Appear\",\"Object\":{\"ObjectType\":\"Human\",\"ObjectID\":99}}\r\n";
$events = DahuaEventParser::feed($rawEvent, $carry);
expectAux(count($events) === 1, 'real-style CrossRegion event parsed');
$event = $events[0];
expectAux(($event['cfgRuleId'] ?? null) === 3, 'CfgRuleId kept separately');
expectAux(($event['ruleIdPrimary'] ?? null) === 3, 'RuleID kept separately');
expectAux(($event['ruleIdLegacy'] ?? null) === 1, 'RuleId kept separately');
expectAux(($event['ruleName'] ?? null) === 'P03_JV_LEFT_HUMAN', 'rule Name parsed');
expectAux(($event['ruleIds'] ?? null) === [3, 1], 'all unique Dahua rule ids exposed');
expectAux(($event['ruleId'] ?? null) === 3, 'compatibility ruleId uses primary RuleID');
expectAux(($event['human'] ?? false) === true, 'Human classification parsed');

expectAux(
    P03AuxObserverLogic::isHumanProofStart($event, 99, 'P03_JV_LEFT_HUMAN'),
    'matching rule name wins even if numeric mapping differs'
);

$foreignName = $event;
$foreignName['ruleName'] = 'FOREIGN_RULE';
expectAux(
    !P03AuxObserverLogic::isHumanProofStart($foreignName, 3, 'P03_JV_LEFT_HUMAN'),
    'explicit foreign rule name rejected even if id matches'
);

$noName = $event;
$noName['ruleName'] = null;
expectAux(
    P03AuxObserverLogic::isHumanProofStart($noName, 1, 'P03_JV_LEFT_HUMAN'),
    'fallback accepts any known Dahua rule id when Name is absent'
);

$noName['ruleIds'] = [8, 9];
$noName['ruleId'] = 8;
expectAux(
    !P03AuxObserverLogic::isHumanProofStart($noName, 1, 'P03_JV_LEFT_HUMAN'),
    'wrong fallback ids rejected'
);

$stopEvent = $event;
$stopEvent['action'] = 'Stop';
expectAux(
    !P03AuxObserverLogic::isHumanProofStart($stopEvent, 3, 'P03_JV_LEFT_HUMAN'),
    'STOP event does not create a new Human proof'
);

$carry = '';
$cfgOnly = "Code=CrossRegionDetection;action=Pulse;index=0;data={\"CfgRuleId\":7,\"Name\":\"P03_WORK_LEFT_HUMAN\",\"EventID\":102,\"Action\":\"Appear\",\"Object\":{\"ObjectType\":\"Human\"}}\r\n";
$events = DahuaEventParser::feed($cfgOnly, $carry);
expectAux(count($events) === 1, 'CfgRuleId-only event parsed');
expectAux(($events[0]['ruleIds'] ?? null) === [7], 'CfgRuleId-only candidate exposed');
expectAux(P03AuxObserverLogic::isHumanProofStart($events[0], 7, 'P03_WORK_LEFT_HUMAN'), 'CfgRuleId-only event matches');

echo "P03 auxiliary Human IVS rule tests PASS\n";
