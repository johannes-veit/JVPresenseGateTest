<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/P03TerraceInBackup.php';
function bOk(bool $bool,string $label):void {
 if (!$bool) {fwrite(STDERR,"FAIL: ".$label."\n");exit(1);}
 echo "OK: ".$label."\n";
}
$work=['id'=>'w12','source'=>'WORK_LEFT','ts'=>1000.0,'used'=>false];
$jv=['id'=>'j12','source'=>'JV_LEFT','ts'=>1000.0,'used'=>false];
$person=['id'=>'p1','kind'=>'PERSON','ts'=>1021.0,'used'=>false,'direction'=>null];
$a=P03TerraceInBackup::evaluate([$work],[$person],1023.0);
bOk($a['status']==='PENDING'&&$a['path']==='WORK_LEFT_STAIR_THEN_TERRACE_IN'
    && $a['gap']===21.0,
    'real 20:06:02 WORK_LEFT then 20:06:23 terrace recognized as delayed IN pending at 21 s');
$a=P03TerraceInBackup::evaluate([$work],[$person],1026.0);
bOk($a['status']==='VERIFIED'&&$a['mastId']==='w12'&&$a['terraceId']==='p1',
    'real 4th IN candidate confirmed after 5-second contradiction settle (TEST only)');
bOk(P03TerraceInBackup::evaluate([$work],[array_replace($person,['ts'=>1026.0])],1032.0)['status']==='UNKNOWN',
    '26-second delayed person is too late');
bOk(P03TerraceInBackup::evaluate([$jv],[array_replace($person,['ts'=>1004.0])],1009.0)['status']==='VERIFIED',
    'backup for missed WORK stair: JV_LEFT then terrace 4s later');
bOk(P03TerraceInBackup::evaluate([$jv],[array_replace($person,['ts'=>1007.0])],1012.0)['status']==='UNKNOWN',
    'JV_LEFT→terrace seven seconds later rejected, requires near-immediate recognition');
bOk(P03TerraceInBackup::evaluate([$work],[array_replace($person,['direction'=>'RightToLeft'])],1026.0)['status']==='VERIFIED',
    'optional terrace Human IVS IN direction strengthens but is NOT mandatory');
bOk(P03TerraceInBackup::evaluate([$work],[array_replace($person,['direction'=>'LeftToRight'])],1026.0)['status']==='UNKNOWN',
    'terrace physical OUT IVS direction never qualifies as IN backup');
bOk(P03TerraceInBackup::evaluate([$work],[],1026.0)['status']==='UNKNOWN',
    'a single mast camera alone never proves IN');
bOk(P03TerraceInBackup::evaluate([],[array_replace($person,['direction'=>'RightToLeft'])],1026.0)['status']==='UNKNOWN',
    'a terrace IVS crossing alone never proves IN');
bOk(P03TerraceInBackup::evaluate([array_replace($work,['used'=>true])],[$person],1026.0)['status']==='UNKNOWN',
    'previously consumed work crossing cannot count a second time');
bOk(P03TerraceInBackup::evaluate([$work],[array_replace($person,['used'=>true])],1026.0)['status']==='UNKNOWN',
    'previously consumed terrace recognition cannot count twice');
bOk(P03TerraceInBackup::evaluate([$jv,array_replace($work,['ts'=>995.0])],
    [array_replace($person,['ts'=>1004.0])],1009.0)['status']==='UNKNOWN',
    'WORK_LEFT then JV_LEFT is existing full IN mast pair, so backup must not compete');
bOk(P03TerraceInBackup::evaluate([$work,array_replace($jv,['ts'=>990.0])],
    [$person],1026.0)['status']==='UNKNOWN',
    'JV_LEFT then WORK_LEFT is OUT: cannot be relabelled as IN when terrace later detects Human');
bOk(P03TerraceInBackup::evaluate([$work],[$person],1068.0)['status']==='UNKNOWN',
    'stale terrace and mast evidence cannot be replayed');
$rule=['name'=>'IVS-1','id'=>5,'humanOnly'=>true];
$ivs=[
 'code'=>'CrossLineDetection','action'=>'Start','decision'=>'STAIR_HUMAN_START_COUNTED',
 'rejectionReason'=>'MATCHED_FILTER','ruleName'=>'IVS-1','ruleIdUpper'=>5,
 'classification'=>'Human','direction'=>'RightToLeft'
];
bOk(P03TerraceInBackup::qualifiedTerraceIvs($ivs,$rule)==='RightToLeft',
    'real terrace RuleID5 IVS Human IN is accepted optionally');
bOk(P03TerraceInBackup::qualifiedTerraceIvs(array_replace($ivs,['direction'=>'LeftToRight']),$rule)==='LeftToRight',
    'real terrace OUT IVS is explicitly represented for rejection by backup engine');
foreach([
 ['decision'=>'STAIR_IVS_NOT_MAPPED_OR_NOT_HUMAN'],
 ['rejectionReason'=>'NOT_START_OR_PULSE'],['ruleIdUpper'=>4],
 ['ruleIdUpper'=>null],['ruleName'=>'OTHER'],
 ['classification'=>'Vehicle'],['action'=>'Stop'],
 ['direction'=>'Both'],['code'=>'SmartMotionHuman']
] as $bad) {
 bOk(P03TerraceInBackup::qualifiedTerraceIvs(array_replace($ivs,$bad),$rule)===null,
     'invalid terrace IVS cannot falsely confirm IN: '.json_encode($bad));
}
bOk(P03TerraceInBackup::qualifiedTerraceIvs(array_replace($ivs,['classification'=>null]),$rule)==='RightToLeft',
    'validated Human-only camera rule may classify accepted IVS without explicit class');
bOk(P03TerraceInBackup::qualifiedTerraceIvs(array_replace($ivs,['classification'=>null]),
    array_replace($rule,['humanOnly'=>false]))===null,
    'mixed Human/Vehicle rule without per-event Human class fails closed');
echo "P03 TERRACE IN BACKUP TESTS PASSED\n";
