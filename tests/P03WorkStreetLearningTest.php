<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/P03WorkStreetLearning.php';
function okStreet(bool $value,string $why):void {
    if (!$value) {fwrite(STDERR,"FAIL $why\n"); exit(1);}
    echo "OK $why\n";
}
$street=[
 'time'=>'19:39:21','code'=>'CrossLineDetection','action'=>'Start',
 'ruleName'=>'Überquerung zur Straße','ruleIdUpper'=>5,
 'classification'=>'Human','direction'=>'LeftToRight','eventId'=>911
];
okStreet(P03WorkStreetLearning::validStreetStart($street),
 'WORK_LEFT street Human RuleID5 accepted independently of stair RuleID4');
$encodedName="Ã".chr(0xC2).chr(0x9C)."berquerung zur StraÃ".chr(0xC2).chr(0x9F)."e";
$badEncoding=array_replace($street,['ruleName'=>$encodedName]);
okStreet(P03WorkStreetLearning::validStreetStart($badEncoding),
 'real Dahua Latin1 mojibake street label is reconstructed with exact camera RuleID5');
foreach([
 ['action'=>'Stop'],['ruleIdUpper'=>4],
 ['cfgRuleId'=>4],['ruleIdUpper'=>null],['ruleName'=>'Überquerung zur Treppe'],
 ['classification'=>'Vehicle'],['classification'=>null],['direction'=>''],
 ['direction'=>'Both'],['code'=>'SmartMotionHuman']
] as $bad) {
 okStreet(!P03WorkStreetLearning::validStreetStart(array_replace($street,$bad)),
 'street fail-closed rejects '.json_encode($bad,JSON_UNESCAPED_UNICODE));
}
$r=P03WorkStreetLearning::ingest([array_replace($street,['action'=>'Stop']),$street],[]);
okStreet(count($r['new'])===1 && $r['rejected']===1,
 'street Start counted while Stop ignored from same observer history');
$r2=P03WorkStreetLearning::ingest([$street],$r['seen']);
okStreet($r2['new']===[] && count($r2['seen'])===2,
 'unchanged last-eight IVS buffer never produces duplicate street passage');
$r3=P03WorkStreetLearning::ingest([
 array_replace($street,['time'=>'19:41:09','eventId'=>912,'direction'=>'RightToLeft']),
 array_replace($street,['time'=>'19:43:08','eventId'=>913,'direction'=>'LeftToRight']),
 array_replace($street,['time'=>'19:45:40','eventId'=>914,'direction'=>'RightToLeft'])
],$r['seen']);
okStreet(count($r3['new'])===3 && count($r3['seen'])===5,
 'three additional distinct street crossings processed without replaying old keys');
$all=array_merge($r['new'],$r3['new']);
$l=P03WorkStreetLearning::evaluateTraining(array_slice($all,0,1));
okStreet($l['status']==='LEARNING'&&$l['progress']===1,'OUT first learning pending');
$l=P03WorkStreetLearning::evaluateTraining($all);
okStreet($l['status']==='VERIFIED' && $l['progress']===4
    && $l['out']==='LeftToRight' && $l['in']==='RightToLeft',
 'OUT-IN-OUT-IN learns actual street camera direction after four clean crossings');
okStreet(str_contains(P03WorkStreetLearning::boundaryMessage($all[2],$l),
    'ARBEIT → AUSSERHALB'),'learned OUT marks latest street boundary departure only');
okStreet(str_contains(P03WorkStreetLearning::boundaryMessage($all[3],$l),
    'AUSSERHALB → ARBEIT'),'learned IN marks latest street boundary return only');
okStreet(str_contains(P03WorkStreetLearning::boundaryMessage($all[0],[]),
    'UNBEKANNT'),'no WORK status change without verified direction training');
$l=P03WorkStreetLearning::evaluateTraining([
 ['direction'=>'LeftToRight'],['direction'=>'RightToLeft'],
 ['direction'=>'RightToLeft'],['direction'=>'LeftToRight']
]);
okStreet($l['status']==='CONTRADICTION'&&$l['out']===null
    && $l['in']===null,'contradictory walks fail closed instead of assuming a direction');
$l=P03WorkStreetLearning::evaluateTraining([
 ['direction'=>'LeftToRight'],['direction'=>'LeftToRight'],
 ['direction'=>'LeftToRight'],['direction'=>'LeftToRight']
]);
okStreet($l['status']==='CONTRADICTION','same direction four times is not OUT/IN learning');
// Keep legacy independent HOME<->WORK evaluator unchanged.
require_once dirname(__DIR__).'/libs/P03ProofEngine.php';
$r=P03ProofEngine::evaluateTwoCameraSequence([
 ['id'=>'w-in','source'=>P03ProofEngine::SRC_WORK_LEFT,'ts'=>1000,'used'=>false],
 ['id'=>'j-in','source'=>P03ProofEngine::SRC_JV_LEFT,'ts'=>1009,'used'=>false]
],1012,30,2);
okStreet($r['action']===P03ProofEngine::ACTION_LAGER_TO_HOME,
 'street learning does not alter WORK_LEFT->JV_LEFT HOME/WORK proof');
echo "P03 WORK STREET TESTS PASSED\n";
