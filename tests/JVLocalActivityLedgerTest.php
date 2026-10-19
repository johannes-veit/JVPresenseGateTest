<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVLocalActivityLedger.php';
$checks=0;
function eqActivity(mixed $actual,mixed $expected,string $message):void {
    global $checks;$checks++;
    if($actual!==$expected)throw new RuntimeException($message.' expected='
        .var_export($expected,true).' actual='.var_export($actual,true));
}
$now=1791921000;
$readings=[
 'eg_flur'=>['name'=>'EG Flur','zone'=>'HOME'],
 'eg_wohnen_kamin'=>['name'=>'EG Wohnen Kamin','zone'=>'HOME'],
 'eg_wohnen_kueche'=>['name'=>'EG Wohnen Küche','zone'=>'HOME'],
 'lager_flur'=>['name'=>'Lager Flur','zone'=>'WORK']
];
$start=JVLocalActivityLedger::evaluate([], $readings, [], $now);
eqActivity($start['report']['totalMotionEdges24h'],0,'at startup zero motion edges');
eqActivity($start['report']['lastMotionAt'],null,'no last event invented at startup');
eqActivity($start['report']['full24hWindowObserved'],false,
 'new ledger cannot claim full-day observations');
eqActivity($start['report']['personCountCertified'],false,'edges not humans');
eqActivity($start['report']['identityCertified'],false,'edges cannot identify humans');
eqActivity($start['report']['propertyEmptyMayBeCertified'],false,
 'no evidence of empty property');
$first=[
 ['id'=>'eg_flur','at'=>$now+2,'zone'=>'HOME','status'=>'SINGLE_UNCERTAIN'],
 ['id'=>'eg_wohnen_kamin','at'=>$now+5,'zone'=>'HOME','status'=>'KAMIN_UNCORROBORATED']
];
$a=JVLocalActivityLedger::evaluate($first,$readings,$start['next'],$now+5);
eqActivity($a['report']['totalMotionEdges24h'],2,'two source edges');
eqActivity($a['report']['perZoneMotionEdges24h']['HOME'],2,
 'both edges belong home');
eqActivity($a['report']['perZoneCorroboratedEdges24h']['HOME'],0,
 'two uncorroborated edges not magically confirmed');
eqActivity($a['report']['lastMotionAt'],$now+5,'latest edge timestamp');
eqActivity(count($a['report']['recentMotionEdges']),2,'two recent entries');
$dup=JVLocalActivityLedger::evaluate($first,$readings,$a['next'],$now+6);
eqActivity($dup['report']['totalMotionEdges24h'],2,
 'repeated 60-s polling must not duplicate events');
eqActivity($dup['next']['entries'],$a['next']['entries'],
 'same sensor report retains stable buffer');
$upgrade=[
 ['id'=>'eg_flur','at'=>$now+2,'zone'=>'HOME','status'=>'MULTI_SENSOR_CORROBORATED'],
 ['id'=>'eg_wohnen_kamin','at'=>$now+5,'zone'=>'HOME','status'=>'KAMIN_KITCHEN_CORROBORATED'],
 ['id'=>'eg_wohnen_kueche','at'=>$now+7,'zone'=>'HOME','status'=>'MULTI_SENSOR_CORROBORATED']
];
$b=JVLocalActivityLedger::evaluate($upgrade,$readings,$dup['next'],$now+7);
eqActivity($b['report']['totalMotionEdges24h'],3,
 'later kitchen edge adds only ONE distinct event');
eqActivity($b['report']['perZoneCorroboratedEdges24h']['HOME'],3,
 'strengthened GUS signal upgrades confidence, not raw count');
eqActivity($b['report']['perSource']['eg_wohnen_kamin']['motionEdges24h'],1,
 'same Kamin signal never counted twice after kitchen confirmation');
eqActivity($b['report']['perSource']['eg_wohnen_kamin']['corroboratedEdges24h'],1,
 'same Kamin event upgraded in place');
$c=JVLocalActivityLedger::evaluate([
 ['id'=>'lager_flur','at'=>$now+8,'zone'=>'WORK','status'=>'SINGLE_UNCERTAIN']
],$readings,$b['next'],$now+8);
eqActivity($c['report']['totalMotionEdges24h'],4,'WORK motion counted as edge');
eqActivity($c['report']['perZoneMotionEdges24h']['HOME'],3,
 'WORK motion not incorrectly added to HOME');
eqActivity($c['report']['perZoneMotionEdges24h']['WORK'],1,
 'WORK motion separately visible');
eqActivity($c['report']['personPresenceMayBeChanged'],false,
 'four motion edges cannot publish persons');

$expired=JVLocalActivityLedger::evaluate([],$readings,$c['next'],$now+86409);
eqActivity($expired['report']['totalMotionEdges24h'],0,'older-than-24h edges expire');
eqActivity($expired['report']['full24hWindowObserved'],false,
 'a single poll after a 24h runtime interruption is not continuous observation');
eqActivity(in_array('LEDGER_OBSERVATION_GAP_REBASELINED',
 $expired['report']['warnings'],true),true,
 'long monitoring gap is explicitly diagnosed rather than silently accepted');
eqActivity($expired['report']['propertyEmptyMayBeCertified'],false,
 'one day silence does not prove empty');
eqActivity($expired['report']['lastMotionAt'],null,
 'expired last event not misrepresented as fresh');

$clockCandidate=[
 ['id'=>'eg_wohnen_kamin','at'=>$now+3,'zone'=>'HOME',
  'status'=>'CLOCK_WINDOW_IGNORED']
];
$ignored=JVLocalActivityLedger::evaluate($clockCandidate,$readings,$start['next'],$now+5);
eqActivity($ignored['report']['totalMotionEdges24h'],0,
 'filtered wall clock event is not a human motion record');
eqActivity($ignored['report']['warnings'],['INVALID_MOTION_SIGNAL'],
 'unrecognized event status gets diagnostic warning');

$unknown=JVLocalActivityLedger::evaluate([
 ['id'=>'hidden','at'=>$now+3,'zone'=>'HOME','status'=>'SINGLE_UNCERTAIN']
],$readings,$start['next'],$now+5);
eqActivity($unknown['report']['totalMotionEdges24h'],0,
 'unknown sensor ID rejected');
eqActivity($unknown['report']['warnings'],['INVALID_MOTION_SIGNAL'],
 'unknown sensor warning');

$future=JVLocalActivityLedger::evaluate([
 ['id'=>'eg_flur','at'=>$now+100,'zone'=>'HOME','status'=>'SINGLE_UNCERTAIN']
],$readings,$start['next'],$now+5);
eqActivity($future['report']['totalMotionEdges24h'],0,
 'future-dated event not counted');

$backwards=JVLocalActivityLedger::evaluate([
 ['id'=>'eg_flur','at'=>$now-90,'zone'=>'HOME','status'=>'SINGLE_UNCERTAIN']
],$readings,$start['next'],$now);
eqActivity($backwards['report']['totalMotionEdges24h'],0,
 'old event from before initialization not replayed');

$missing=JVLocalActivityLedger::evaluate([],[
 'eg_wohnen_kueche'=>['name'=>'EG Wohnen Küche','zone'=>'HOME']
],$b['next'],$now+20);
eqActivity($missing['report']['totalMotionEdges24h'],1,
 'removed sensor source is discarded from ledger');
eqActivity(array_keys($missing['report']['perSource']),['eg_wohnen_kueche'],
 'removed sensors never persist as current input');
eqActivity($missing['report']['personCountCertified'],false,
 'source changes cannot produce persons');

$corrupt=JVLocalActivityLedger::evaluate([],$readings,[
 'schema'=>999,'entries'=>[['garbage'=>1]],'startedAt'=>$now
],$now+5);
eqActivity($corrupt['report']['warnings'],[
    'LEDGER_OBSERVATION_GAP_REBASELINED','CORRUPT_LEDGER_BUFFER'],
 'corrupt legacy state records both observation gap and corrupt buffer');
eqActivity($corrupt['report']['totalMotionEdges24h'],0,
 'corrupt buffer never fabricates retained events');
$replay=JVLocalActivityLedger::evaluate($first,$readings,$corrupt['next'],$now+6);
eqActivity($replay['report']['totalMotionEdges24h'],2,
 'new valid retained event edges may be observed again after corruption but never people');

$invalidTime=JVLocalActivityLedger::evaluate([],$readings,[],-1);
eqActivity($invalidTime['report']['warnings'],['INVALID_CLOCK'],'invalid current clock');
eqActivity($invalidTime['report']['propertyEmptyMayBeCertified'],false,
 'invalid clock cannot prove empty');

echo "Passive local motion edge history PASS ($checks assertions)\n";
