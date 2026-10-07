<?php
require_once __DIR__ . '/../libs/GateTestLogic.php';

function ok(bool $v, string $name): void {
    if (!$v) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    echo "PASS: $name\n";
}

$raw = implode("\n", [
    'table.VideoAnalyseRule[0][0].Name=Other',
    'table.VideoAnalyseRule[0][0].Type=CrossRegionDetection',
    'table.VideoAnalyseRule[0][3].Name=P05_HOME_STREET',
    'table.VideoAnalyseRule[0][3].Type=CrossLineDetection',
    'table.VideoAnalyseRule[0][3].Enable=true'
]);
$rules = GateTestLogic::parseRules($raw);
ok(isset($rules[3]), 'parse rule index');
ok(GateTestLogic::findRuleIndex($rules, 'P05_HOME_STREET') === 3, 'find named rule');
ok(GateTestLogic::firstFreeRuleIndex($rules, 5) === 1, 'find free rule');

$cfg = "table.VideoAnalyseGlobal[0].Scene.Type=Normal\n";
ok(GateTestLogic::configValue($cfg, 'VideoAnalyseGlobal[0].Scene.Type') === 'Normal', 'config value');

$event = ['data' => ['Object' => ['ObjectType' => 'Human'], 'Direction' => 'LeftToRight'], 'raw' => ''];
ok(GateTestLogic::directionFromEvent($event) === 'LeftToRight', 'direction recursive');
$event2 = ['data' => null, 'raw' => 'Code=CrossLineDetection;data={"MoveDirection":"RightToLeft"}'];
ok(GateTestLogic::directionFromEvent($event2) === 'RightToLeft', 'direction raw');

$ev = [
    ['direction'=>'LeftToRight'],
    ['direction'=>'RightToLeft'],
    ['direction'=>'LeftToRight'],
    ['direction'=>'RightToLeft']
];
$r = GateTestLogic::evaluateFourCrossings($ev);
ok(($r['valid'] ?? false) === true, 'stable OUT/IN mapping');
ok($r['outDirection'] === 'LeftToRight', 'OUT mapping');
ok($r['inDirection'] === 'RightToLeft', 'IN mapping');

$bad = $ev;
$bad[2]['direction'] = 'RightToLeft';
$r = GateTestLogic::evaluateFourCrossings($bad);
ok(($r['valid'] ?? true) === false, 'reject inconsistent mapping');

echo "ALL TESTS PASSED\n";
