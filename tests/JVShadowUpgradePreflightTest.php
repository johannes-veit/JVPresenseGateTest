<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVShadowUpgradePreflight.php';
function preflightOK(bool $x,string $why):void {
    if(!$x)throw new RuntimeException('PREFLIGHT '.$why);
}
$t=1800000000;
$run=static fn(array $s)=>JVShadowUpgradePreflight::evaluate($s,$t);
$initial=$run([]);
preflightOK($initial['status']==='FIRST_RUN_BASELINE'
    &&$initial['safeToRefresh']
    &&$initial['baselineAttributes']===15
    &&!$initial['mayErasePreviousEvidence'],'first install is safe baseline');
$valid=[
    'ResidentEventFusion'=>json_encode([
        'schema'=>1,'people'=>[
            'johannes'=>['eventHistory'=>[]],
            'carina'=>['eventHistory'=>[]]],
        'p03'=>['unackedRouteProofs'=>[
            ['source'=>'P03_TWO_MAST_VERIFIED',
                'direction'=>'HOME_TO_LAGER','at'=>$t-120]
        ]]
    ],JSON_THROW_ON_ERROR),
    'RouteEpisodeLedger'=>json_encode(['schema'=>1,'initialized'=>true,
        'lastScanAt'=>$t-10,'open'=>[],
        'ambiguousOutstanding'=>1],JSON_THROW_ON_ERROR),
    'P03IncrementalTimeline'=>json_encode(['schema'=>1,'initialized'=>true,
        'lastRaw'=>'log-tail','events'=>[]],JSON_THROW_ON_ERROR)
];
$a=$run($valid);
preflightOK($a['status']==='EXISTING_STATE_VALIDATED'
    &&$a['safeToRefresh']&&$a['existingAttributes']===3
    &&$a['fusionJournalPending']===1
    &&$a['ambiguousRouteOutstanding']===1
    &&$a['p03CursorPresent'],'valid v0.8.74 ledgers retained without replay');
$partial=$valid;
$partial['LocalActivityLedger']='INVALID_JSON_PRIVATE_SECRET';
$p=$run($partial);
preflightOK($p['status']==='REVIEW_NONCRITICAL_STATE'
    &&$p['safeToRefresh'] &&count($p['criticalIssues'])===0
    &&count($p['reviewRequired'])===1
    &&!str_contains(json_encode($p),'PRIVATE_SECRET'),
    'bad noncritical state reported safely without raw data');
$bad=$valid;
$bad['ResidentEventFusion']='{this is not JSON';
$b=$run($bad);
preflightOK(!$b['safeToRefresh']
    &&$b['status']==='BLOCKED_PRESERVE_CRITICAL_STATE'
    &&str_contains(implode(',',$b['criticalIssues']),'ResidentEventFusion'),
    'never erase unreadable personal event ledger during upgrade');
$bad=$valid;
$bad['P03IncrementalTimeline']=json_encode([
    'schema'=>1,'initialized'=>true,'events'=>[]],JSON_THROW_ON_ERROR);
preflightOK(!$run($bad)['safeToRefresh'],
    'initialized cursor with missing log tail cannot be silently rewritten');
$bad=$valid;
$bad['RouteEpisodeLedger']=json_encode([
    'schema'=>1,'open'=>[['at'=>$t-20]],
    'ambiguousOutstanding'=>2],JSON_THROW_ON_ERROR);
preflightOK(!$run($bad)['safeToRefresh'],
    'critical route conflict preserves old evidence for review');
$bad=$valid;
$x=json_decode($bad['ResidentEventFusion'],true);
$x['p03']['unackedRouteProofs'][0]['at']=$t+99999;
$bad['ResidentEventFusion']=json_encode($x,JSON_THROW_ON_ERROR);
preflightOK(!$run($bad)['safeToRefresh'],
    'future-dated proof journal is not accepted as safe persistence');
$bad=$valid;$bad['RouteEpisodeLedger']=str_repeat('x',270000);
preflightOK(!$run($bad)['safeToRefresh'],
    'oversized critical persisted route state is blocked');
echo "SHADOW upgrade persisted-state preflight PASS\n";
