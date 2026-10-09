<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/P03DahuaTemplate.php';
require_once dirname(__DIR__) . '/libs/P03AuxHumanRule.php';
require_once dirname(__DIR__) . '/libs/DahuaEventParser.php';
require_once dirname(__DIR__) . '/libs/P03AuxObserverLogic.php';

function expectAux(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "OK   {$message}\n";
}

$handler = [
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

$nativeTemplate = [
    'Class' => 'Normal',
    'Type' => 'CrossRegionDetection',
    'Enable' => false,
    'Id' => 0,
    'PtzPresetId' => 0,
    'TrackEnable' => true,
    'ObjectTypes' => ['Unknown'],
    'FirmwarePrivate' => ['Mode' => 17],
    'TimeSection' => [['0 00:00:00-23:59:59']],
    'Config' => [
        'Action' => 'Cross',
        'Direction' => 'Enter',
        'DetectRegion' => [[100,100],[7000,100],[7000,7000],[100,7000]],
        'NativeOnly' => ['Keep' => true],
        'SizeFilter' => [
            'MaxSize' => [4096,4096],
            'MinSize' => [32,32],
            'Type' => 'ByLength',
        ],
    ],
];

$rule = P03AuxHumanRule::buildFromTemplate(
    'P03_JV_LEFT_HUMAN',
    7,
    $nativeTemplate,
    $handler
);

$incompleteRejected = false;
try {
    $badTemplate = $nativeTemplate;
    unset($badTemplate['Config']['Action']);
    P03AuxHumanRule::buildFromTemplate('P03_BAD', 9, $badTemplate, $handler);
} catch (InvalidArgumentException $e) {
    $incompleteRejected = true;
}
expectAux($incompleteRejected, 'incomplete native template fails closed');

expectAux(($rule['Type'] ?? null) === 'CrossRegionDetection', 'rule type');
expectAux(($rule['ObjectTypes'] ?? null) === ['Human'], 'Human-only object filter');
expectAux(($rule['Config']['DetectRegion'] ?? null) === P03AuxHumanRule::REGION, 'fixed broad region');
expectAux(($rule['Config']['Action'] ?? null) === 'Appear', 'native string Action becomes Appear');
expectAux(($rule['Config']['Direction'] ?? null) === 'Both', 'direction is non-restrictive');
expectAux(($rule['Config']['NativeOnly']['Keep'] ?? null) === true, 'unknown Config fields preserved');
expectAux(($rule['FirmwarePrivate']['Mode'] ?? null) === 17, 'unknown rule fields preserved');
expectAux(($rule['TimeSection'] ?? null) === $nativeTemplate['TimeSection'], 'native schedule preserved');
expectAux(($rule['Config']['SizeFilter']['MinSize'] ?? null) === [0,0], 'min size opened');
expectAux(($rule['Config']['SizeFilter']['MaxSize'] ?? null) === [8191,8191], 'max size opened');
expectAux(($rule['EventHandler']['RecordEnable'] ?? true) === false, 'record side effect disabled');
expectAux(($rule['EventHandler']['SnapshotEnable'] ?? true) === false, 'snapshot side effect disabled');
expectAux(($rule['EventHandler']['MailEnable'] ?? true) === false, 'mail side effect disabled');
expectAux(($rule['EventHandler']['VoiceEnable'] ?? true) === false, 'voice side effect disabled');
expectAux(($rule['EventHandler']['TrigerHttp']['TrigerHttpEnable'] ?? true) === false, 'HTTP side effect disabled');
expectAux(($rule['EventHandler']['KeepUnrelated'] ?? null) === 123, 'unrelated handler fields preserved');
expectAux(P03AuxHumanRule::matches($rule, 'P03_JV_LEFT_HUMAN'), 'valid native-template rule matches');

$arrayTemplate = $nativeTemplate;
$arrayTemplate['Config']['Action'] = ['Cross', 'Inside'];
$arrayRule = P03AuxHumanRule::buildFromTemplate('P03_WORK_LEFT_HUMAN', 8, $arrayTemplate, $handler);
expectAux(($arrayRule['Config']['Action'] ?? null) === ['Appear', 'Cross'], 'native array Action enables Appear+Cross');
expectAux(P03AuxHumanRule::matches($arrayRule, 'P03_WORK_LEFT_HUMAN'), 'array-action rule matches');

$bad = $rule;
$bad['Config']['Action'] = 'Cross';
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'Cross-only rule rejected');
$bad = $rule;
$bad['Config']['Direction'] = 'Enter';
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'restrictive direction rejected');
$bad = $rule;
$bad['ObjectTypes'] = ['Unknown'];
expectAux(!P03AuxHumanRule::matches($bad, 'P03_JV_LEFT_HUMAN'), 'Unknown object filter rejected');

// Documented getTemplateRule flat response must be converted into a native rule.
$flat = implode("\r\n", [
    'Rule.Normal.CrossRegionDetection.Class=Normal',
    'Rule.Normal.CrossRegionDetection.Type=CrossRegionDetection',
    'Rule.Normal.CrossRegionDetection.Enable=false',
    'Rule.Normal.CrossRegionDetection.Id=0',
    'Rule.Normal.CrossRegionDetection.ObjectTypes[0]=Unknown',
    'Rule.Normal.CrossRegionDetection.Config.Action=Cross',
    'Rule.Normal.CrossRegionDetection.Config.Direction=Both',
    'Rule.Normal.CrossRegionDetection.Config.SizeFilter.MinSize[0]=0',
    'Rule.Normal.CrossRegionDetection.Config.SizeFilter.MinSize[1]=0',
    'Rule.Normal.CrossRegionDetection.Config.SizeFilter.MaxSize[0]=8191',
    'Rule.Normal.CrossRegionDetection.Config.SizeFilter.MaxSize[1]=8191',
    'Rule.Normal.CrossRegionDetection.Config.NativeFlag=true',
]);
$parsedTemplate = P03DahuaTemplate::crossRegionFromFlat($flat);
expectAux(is_array($parsedTemplate), 'documented flat getTemplateRule parsed');
expectAux(($parsedTemplate['Type'] ?? null) === 'CrossRegionDetection', 'flat template type');
expectAux(($parsedTemplate['Config']['NativeFlag'] ?? null) === true, 'flat native field retained');
expectAux(($parsedTemplate['Config']['SizeFilter']['MaxSize'] ?? null) === [8191,8191], 'flat arrays reconstructed');

// Real Dahua events can carry three different rule-id fields simultaneously.
$carry = '';
$raw = 'Code=CrossRegionDetection;action=Pulse;index=0;data='
    . '{"Name":"P03_JV_LEFT_HUMAN","CfgRuleId":2,"RuleID":2,"RuleId":1,'
    . '"EventID":102,"Object":{"ObjectType":"Human","ObjectID":9}}' . "\r\n";
$events = JVP03DahuaEventParser::feed($raw, $carry);
expectAux(count($events) === 1, 'CrossRegion event parsed');
$event = $events[0];
expectAux(($event['ruleName'] ?? null) === 'P03_JV_LEFT_HUMAN', 'rule name parsed');
expectAux(($event['cfgRuleId'] ?? null) === 2, 'CfgRuleId preserved');
expectAux(($event['ruleIdUpper'] ?? null) === 2, 'RuleID preserved');
expectAux(($event['ruleIdLower'] ?? null) === 1, 'RuleId preserved');
expectAux(($event['human'] ?? false) === true, 'Human object parsed');

// Name is authoritative even when the camera's numeric identifiers disagree.
expectAux(
    P03AuxObserverLogic::isHumanProofStart($event, 'P03_JV_LEFT_HUMAN', 3, 0),
    'matching rule name wins over conflicting numeric ids'
);
expectAux(
    !P03AuxObserverLogic::isHumanProofStart($event, 'P03_OTHER', 2, 2),
    'wrong explicit rule name rejected even if numeric id matches'
);

// Firmware without Name may fall back to either actual rule id or table index.
$noName = $event;
$noName['ruleName'] = null;
expectAux(
    P03AuxObserverLogic::isHumanProofStart($noName, 'P03_JV_LEFT_HUMAN', 3, 2),
    'missing name falls back to numeric identity'
);
$noName['cfgRuleId'] = 99;
$noName['ruleIdUpper'] = 99;
$noName['ruleIdLower'] = 3;
$noName['ruleId'] = 99;
expectAux(
    P03AuxObserverLogic::isHumanProofStart($noName, 'P03_JV_LEFT_HUMAN', 3, 2),
    'table-index fallback accepted only when name is absent'
);

$stop = $event;
$stop['action'] = 'Stop';
expectAux(
    !P03AuxObserverLogic::isHumanProofStart($stop, 'P03_JV_LEFT_HUMAN', 3, 0),
    'STOP never creates a new Human proof'
);

echo "P03 AUX NATIVE TEMPLATE / EVENT IDENTITY TESTS PASSED\n";
