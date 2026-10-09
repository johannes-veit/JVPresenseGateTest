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
    'FlashEnable' => true,
    'LightEnable' => true,
    'FTPEnable' => true,
    'MsgtoNetEnable' => true,
    'OnVideoMessageEnable' => true,
    'LightingLink' => ['Enable' => true, 'LightDuration' => 10],
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
expectAux(($rule['EventHandler']['FlashEnable'] ?? true) === false, 'flash side effect disabled');
expectAux(($rule['EventHandler']['LightEnable'] ?? true) === false, 'light side effect disabled');
expectAux(($rule['EventHandler']['FTPEnable'] ?? true) === false, 'FTP side effect disabled');
expectAux(($rule['EventHandler']['MsgtoNetEnable'] ?? true) === false, 'network linkage disabled');
expectAux(($rule['EventHandler']['OnVideoMessageEnable'] ?? true) === false, 'video message linkage disabled');
expectAux(($rule['EventHandler']['LightingLink']['Enable'] ?? true) === false, 'LightingLink disabled');
expectAux(($rule['EventHandler']['TrigerHttp']['TrigerHttpEnable'] ?? true) === false, 'HTTP side effect disabled');
expectAux(($rule['EventHandler']['TrigerHttp']['TrigerHttpCommand'] ?? 'x') === '', 'HTTP command cleared');
expectAux(($rule['EventHandler']['KeepUnrelated'] ?? null) === 123, 'unrelated event handler fields preserved');
expectAux(P03AuxHumanRule::matches($rule, 'P03_JV_LEFT_HUMAN'), 'valid rule matches');

$normalizedSchedule = $rule;
for ($day = 0; $day < 7; $day++) {
    $normalizedSchedule['EventHandler']['TimeSection'][$day][0] = '1 00:00:00-24:00:00';
}
expectAux(P03AuxHumanRule::matches($normalizedSchedule, 'P03_JV_LEFT_HUMAN'), 'Dahua 24:00 full-day normalization accepted');

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
$bad['Config']['Sensitivity'] = 5;
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'wrong rule sensitivity rejected');

$bad = $rule;
unset($bad['Config']['AccuracySnap']['HumanBody']);
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'missing HumanBody accuracy snap rejected');

$bad = $rule;
$bad['Config']['MinDuration'] = 0;
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'wrong minimum duration rejected');

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

$explicitVehicle = $event;
$explicitVehicle['classification'] = 'Vehicle';
$explicitVehicle['human'] = false;
expectAux(
    !P03AuxObserverLogic::isHumanProofStart($explicitVehicle, 3, 'P03_JV_LEFT_HUMAN'),
    'explicit Vehicle classification rejected fail closed'
);

$classificationOmitted = $event;
$classificationOmitted['classification'] = null;
$classificationOmitted['human'] = false;
expectAux(
    P03AuxObserverLogic::isHumanProofStart($classificationOmitted, 3, 'P03_JV_LEFT_HUMAN'),
    'missing classification may rely on Human-only camera rule'
);

$genericNameStrongId = $event;
$genericNameStrongId['ruleName'] = 'IVS-1';
expectAux(
    P03AuxObserverLogic::isHumanProofStart($genericNameStrongId, 3, 'P03_JV_LEFT_HUMAN'),
    'strong CfgRuleId/RuleID still identifies rule if firmware reports generic Name'
);

$foreignWeakOnly = $event;
$foreignWeakOnly['ruleName'] = 'FOREIGN_RULE';
$foreignWeakOnly['cfgRuleId'] = 8;
$foreignWeakOnly['ruleIdPrimary'] = 8;
$foreignWeakOnly['ruleIdLegacy'] = 3;
$foreignWeakOnly['ruleIds'] = [8, 3];
expectAux(
    !P03AuxObserverLogic::isHumanProofStart($foreignWeakOnly, 3, 'P03_JV_LEFT_HUMAN'),
    'foreign Name cannot be rescued by weak legacy RuleId'
);

$noName = $event;
$noName['ruleName'] = null;
expectAux(
    P03AuxObserverLogic::isHumanProofStart($noName, 1, 'P03_JV_LEFT_HUMAN'),
    'fallback accepts any known Dahua rule id when Name is absent'
);

$noName['cfgRuleId'] = 8;
$noName['ruleIdPrimary'] = 8;
$noName['ruleIdLegacy'] = 9;
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

// TCP fragmentation: Code= and JSON may be split across arbitrary chunks.
$carry = '';
$fragmented = "Code=CrossRegionDetection;action=Start;index=0;data={\"CfgRuleId\":4,\"RuleID\":4,\"RuleId\":2,\"Name\":\"P03_JV_LEFT_HUMAN\",\"Object\":{\"ObjectType\":\"Human\"}}\r\n";
$parts = [
    substr($fragmented, 0, 3),
    substr($fragmented, 3, 41),
    substr($fragmented, 44, 37),
    substr($fragmented, 81)
];
$parsed = [];
foreach ($parts as $part) {
    $parsed = array_merge($parsed, DahuaEventParser::feed($part, $carry));
}
expectAux(count($parsed) === 1, 'fragmented CrossRegion event reassembled exactly once');
expectAux(($parsed[0]['ruleIdPrimary'] ?? null) === 4, 'fragmented RuleID preserved');
expectAux(($parsed[0]['ruleIdLegacy'] ?? null) === 2, 'fragmented RuleId preserved separately');
expectAux(($parsed[0]['ruleName'] ?? null) === 'P03_JV_LEFT_HUMAN', 'fragmented Name preserved');

// Multiple events in one stream chunk must stay separate.
$carry = '';
$multi = "Code=VideoMotion;action=Start;index=0\r\n"
    . "Code=CrossRegionDetection;action=Start;index=0;data={\"RuleID\":9,\"Name\":\"P03_WORK_LEFT_HUMAN\",\"Object\":{\"ObjectType\":\"Human\"}}\r\n";
$parsed = DahuaEventParser::feed($multi, $carry);
expectAux(count($parsed) === 2, 'multiple events in one chunk parsed separately');
expectAux(($parsed[1]['ruleName'] ?? null) === 'P03_WORK_LEFT_HUMAN', 'second event identity intact');

echo "P03 auxiliary Human IVS rule tests PASS\n";
