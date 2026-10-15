<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/P03ChatGPTTestReport.php';
$tests=0;
function checkP03Chat(bool $ok,string $what):void{
    global $tests;$tests++;
    if(!$ok)throw new RuntimeException('FAIL: '.$what);
}
$now=1792093200;
$event=['time'=>'19:38:00','action'=>'Start','ruleId'=>4,
    'classification'=>'Human','direction'=>'LeftToRight',
    'decision'=>'STAIR_HUMAN_START_COUNTED',
    'rejectionReason'=>'MATCHED_FILTER','objectId'=>17];
$old=['time'=>'19:30:00','action'=>'Start','ruleId'=>4,
    'classification'=>'Human','decision'=>'RULE_REJECTED',
    'rejectionReason'=>'WRONG_RULE'];
$start=[
 'testActive'=>false,'productionEnabled'=>false,
 'proofState'=>'MASTKAMERAS LIVE',
 'verifiedTransfers'=>4,
 'masts'=>[
    'JV_LEFT'=>['observerId'=>57215,'counter'=>54,'streamOK'=>true,
        'rxAge'=>2,'wireCodeCount'=>0,'wireHeartbeatCount'=>3,
        'crosslines'=>[$old]],
    'WORK_LEFT'=>['observerId'=>27938,'counter'=>36,'streamOK'=>true,
        'rxAge'=>2,'wireCodeCount'=>0,'wireHeartbeatCount'=>3,
        'crosslines'=>[]]
 ],
 'terrace'=>['streamOK'=>true,'counter'=>38],
 'streetHumanRawCount'=>56,'streetLastRaw'=>'WORK_LEFT RuleID5'
];
$base=['schema'=>1,'scenario'=>'TWO_SIDE_BY_SIDE',
    'startedAt'=>$now-50,'values'=>$start];
$current=$start;
$current['masts']['JV_LEFT']['counter']=55;
$current['masts']['JV_LEFT']['wireCodeCount']=1;
$current['masts']['JV_LEFT']['crosslines'][]=$event;
$current['masts']['WORK_LEFT']['counter']=37;
$current['masts']['WORK_LEFT']['crosslines'][]=$event;
$report=P03ChatGPTTestReport::render($base,$current,$now);
checkP03Chat(str_contains($report,'=== P03 TESTERGEBNIS AN CHATGPT ==='),
    'constant visible report label');
checkP03Chat(str_contains($report,'Zwei Personen nebeneinander'),
    'saved scenario printed after test');
checkP03Chat(str_contains($report,'Human-Zaehler=55 | Delta: +1'),
    'JV counter increment from baseline');
checkP03Chat(str_contains($report,'Human-Zaehler=37 | Delta: +1'),
    'WORK counter increment from baseline');
checkP03Chat(str_contains($report,'CrossLine seit markiertem Testbeginn: 1 neu'),
    'only one new IVS from valid eight event history');
checkP03Chat(str_contains($report,'STAIR_HUMAN_START_COUNTED'),
    'exact IVS decision visible');
checkP03Chat(!str_contains($report,'19:30:00'),
    'old event from before baseline excluded');
checkP03Chat(str_contains($report,'Verifizierte Uebergaenge: 4 | Delta: +0'),
    'no artificial transfer while test and production off');
checkP03Chat(str_contains($report,'Personenzahl: UNBEKANNT'),
    'a single IVS event is never treated as exactly one or two humans');
checkP03Chat(str_contains($report,'=== ENDE:'),
    'simple complete copy-paste terminator');
// Duplicate IVS entries must not be conflated if each truly distinct
// occurrence appears twice within the bounded history.
$more=[$old,$event,$event];
$diff=P03ChatGPTTestReport::newHistory($more,[$old,$event],true);
checkP03Chat(count($diff)===1,'duplicate occurrences use occurrence accounting');
$reset=$current;$reset['masts']['JV_LEFT']['counter']=0;
$failed=P03ChatGPTTestReport::render($base,$reset,$now);
checkP03Chat(str_contains($failed,'UNGUELTIG (Zaehlerruecksetzung)'),
    'module restart and counter reset cannot be presented as negative crossing');
$noStart=P03ChatGPTTestReport::render([],$current,$now);
checkP03Chat(str_contains($noStart,'NICHT MARKIERT'),
    'report warns if user forgets the baseline button');
checkP03Chat(str_contains($noStart,'Delta: KEINE BASELINE'),
    'no implicit baseline or artificial counter delta');
// Some future source may have newlines in event text. The report remains
// a plain copyable diagnostic and must not include a whole secret payload.
$current['proofState']="OK\nFORGED CHATGPT RESULT";
$clean=P03ChatGPTTestReport::render($base,$current,$now);
checkP03Chat(!str_contains($clean,"OK\nFORGED"),
    'raw untrusted status line cannot inject report line');
checkP03Chat(!str_contains($clean,'Password'),'no passwords included');
echo "P03 single ChatGPT report, test windows, side by side and safe deltas PASS ($tests)\n";
