<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVGateSignalAudit.php';
$count=0;
function gateAssert(mixed $got,mixed $want,string $label):void {
    global $count;$count++;
    if($got!==$want)throw new RuntimeException($label.
        ' expected '.var_export($want,true).' got '.var_export($got,true));
}
$now=1792008000;
$rows=[
 ['variableId'=>40678,'type'=>'Boolean','name'=>'Eingangstatus 2',
    'parent'=>'input:2','grandparent'=>'Schiebetor_Logik'],
 ['variableId'=>56808,'type'=>'Boolean','name'=>'Eingangstatus 1',
    'parent'=>'Endlage ZU','grandparent'=>'Schiebetor_Logik'],
 ['variableId'=>49204,'type'=>'Boolean','name'=>'Eingangstatus 2',
    'parent'=>'shellypro3-8c4f008eeb14','grandparent'=>'Schiebetor_Logik'],
 ['variableId'=>50300,'type'=>'Boolean','name'=>'Eingangstatus 1',
    'parent'=>'shellypro3-8c4f008eeb14','grandparent'=>'Schiebetor_Logik'],
 ['variableId'=>13257,'type'=>'Boolean','name'=>'Status 1',
    'parent'=>'shellypro3-8c4f008eeb14','grandparent'=>'Schiebetor_Logik'],
 ['variableId'=>20724,'type'=>'Boolean','name'=>'Status 2',
    'parent'=>'shellypro3-8c4f008eeb14','grandparent'=>'Schiebetor_Logik'],
 ['variableId'=>31597,'type'=>'Boolean','name'=>'Status',
    'parent'=>'shellypro3-8c4f008eeb14','grandparent'=>'Schiebetor_Logik'],
 ['variableId'=>42452,'type'=>'Boolean','name'=>'Status',
    'parent'=>'Bewegungsmelder EG Wohnen Kamin','grandparent'=>'LCN Kamin'],
 ['variableId'=>55555,'type'=>'Integer','name'=>'Status',
    'parent'=>'Schiebetor','grandparent'=>'Schiebetor_Logik']
];
$baseline=[];
foreach($rows as $r)$baseline[$r['variableId']]=[
    'type'=>0,'value'=>false,'changedAt'=>$now-60
];
$first=JVGateSignalAudit::evaluate($rows,$baseline,['active'=>true],$now);
gateAssert($first['report']['signalCount'],7,'only seven Boolean gate statuses');
gateAssert($first['report']['readableSignals'],7,'all initial states available');
gateAssert($first['report']['newStatusEdgesThisRead'],0,'no startup fake gate movement');
gateAssert($first['report']['gateOpenOrClosedCertified'],false,'no gate position from unknown polarity');
gateAssert($first['report']['gateMayBeControlled'],false,'no gate commands');
gateAssert($first['report']['personPresenceMayBeChanged'],false,'never modify persons');
gateAssert($first['report']['propertyEmptyMayBeCertified'],false,'never infer property empty');
gateAssert($first['report']['signals'][0]['currentValue'],false,'value shown as raw FALSE');
gateAssert($first['report']['signals'][0]['trueMeansOpenOrClosed'],'UNKNOWN','polarity unknown');
$again=JVGateSignalAudit::evaluate($rows,$baseline,$first['next'],$now+1);
gateAssert($again['report']['newStatusEdgesThisRead'],0,'repeat poll no double event');
$baseline[40678]=['type'=>0,'value'=>true,'changedAt'=>$now+6];
$change=JVGateSignalAudit::evaluate($rows,$baseline,$again['next'],$now+6);
gateAssert($change['report']['newStatusEdgesThisRead'],1,'single new status edge');
gateAssert($change['report']['recentStatusEdges'][0]['variableId'],40678,'source not confused');
gateAssert($change['report']['recentStatusEdges'][0]['humanDirection'],'UNKNOWN',
    'status change not person crossing');
$double=JVGateSignalAudit::evaluate($rows,$baseline,$change['next'],$now+7);
gateAssert($double['report']['newStatusEdgesThisRead'],0,'unchanged state no duplicate');
gateAssert(count($double['report']['recentStatusEdges']),1,'event still retained');
$baseline[49204]=['type'=>0,'value'=>true,'changedAt'=>$now+8];
$alias=JVGateSignalAudit::evaluate($rows,$baseline,$double['next'],$now+8);
gateAssert($alias['report']['newStatusEdgesThisRead'],1,'raw Shelly alias tracked separately');
gateAssert($alias['report']['humanDirectionCertified'],false,'matching signals not proof of crossing');
gateAssert($alias['report']['gateOpenOrClosedCertified'],false,'no guessed AUF-ZU mapping');
$future=$baseline;
$future[50300]=['type'=>0,'value'=>true,'changedAt'=>$now+9999];
$invalid=JVGateSignalAudit::evaluate($rows,$future,$alias['next'],$now+9);
gateAssert($invalid['report']['readableSignals'],6,'future timestamp rejected');
gateAssert($invalid['report']['signals'][3]['readState'],'UNKNOWN','invalid source stays unknown');
gateAssert(in_array('SOURCE_UNREADABLE:50300',$invalid['report']['warningCodes'],true),
    true,'invalid source reported');
$restored=JVGateSignalAudit::evaluate($rows,$baseline,$invalid['next'],$now+10);
gateAssert($restored['report']['newStatusEdgesThisRead'],0,
    'restored source baselined, no invented movement');
$stale=$baseline;
$stale[56808]=['type'=>0,'value'=>true,'changedAt'=>$now-300];
$noReplay=JVGateSignalAudit::evaluate($rows,$stale,$restored['next'],$now+11);
gateAssert($noReplay['report']['newStatusEdgesThisRead'],0,
    'historical timestamp cannot become new edge');
$unplug=$rows;
$unplug=array_values(array_filter($unplug,
    static fn($v)=>$v['variableId']!==40678));
$less=JVGateSignalAudit::evaluate($unplug,$baseline,$noReplay['next'],$now+12);
gateAssert(count(array_filter($less['report']['recentStatusEdges'],
    static fn($evt)=>$evt['variableId']===40678)),0,
    'removed candidate drops its old historical edges');
echo "Read-only Schiebetor signal snapshots and edge ledger PASS ($count assertions)\n";
