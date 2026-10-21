<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVShadowUpgradePreflight.php';
function criticalCheck(bool $ok,string $case):void{
    if(!$ok)throw new RuntimeException('CRITICAL_PROOF_UPGRADE '.$case);
}
$t=1900000000;
$proof=[
    'at'=>$t-60,
    'source'=>'P03_TWO_MAST_VERIFIED',
    'direction'=>'HOME_TO_LAGER','route'=>'P03_2CAM_JV_THEN_WORK',
    'localTime'=>'2030-03-17 14:00:00'
];
$valid=[
 'ResidentEventFusion'=>json_encode([
     'schema'=>1,'people'=>[
         'johannes'=>['eventHistory'=>[]],
         'carina'=>['eventHistory'=>[]]],
     'p03'=>['unackedRouteProofs'=>[]]
 ],JSON_THROW_ON_ERROR),
 'RouteEpisodeLedger'=>json_encode([
     'schema'=>1,'initialized'=>true,'lastScanAt'=>$t-30,
     'open'=>[$proof],'ambiguousOutstanding'=>0
 ],JSON_THROW_ON_ERROR),
 'P03IncrementalTimeline'=>json_encode([
     'initialized'=>true,'lastRaw'=>"P03 DIAG SOURCE\n",
     'events'=>[$proof],'lastObservedAt'=>$t-30
 ],JSON_THROW_ON_ERROR)
];
$run=static fn(array $set):array=>JVShadowUpgradePreflight::evaluate($set,$t);
$good=$run($valid);
criticalCheck($good['safeToRefresh']===true
    &&$good['status']==='EXISTING_STATE_VALIDATED'
    &&$good['p03CursorPresent']===true,
    'real schema-less P03 cursor, queued route and standard ledgers remain compatible');
$old=$valid;
$x=json_decode($old['P03IncrementalTimeline'],true);
unset($x['lastObservedAt']);
$old['P03IncrementalTimeline']=json_encode($x);
criticalCheck($run($old)['safeToRefresh'],
    'P03 cursor without v0.8.104 observation clock is migrated, not destroyed');
$broken=[];
$broken['bad P03 stored event direction']=function(array $s)use($t):array{
    $p=json_decode($s['P03IncrementalTimeline'],true);
    $p['events'][0]['direction']='UNSUPPORTED';
    $s['P03IncrementalTimeline']=json_encode($p);return $s;
};
$broken['bad P03 stored event source']=function(array $s):array{
    $p=json_decode($s['P03IncrementalTimeline'],true);
    $p['events'][0]['source']='UNKNOWN';
    $s['P03IncrementalTimeline']=json_encode($p);return $s;
};
$broken['bad P03 route']=function(array $s):array{
    $p=json_decode($s['P03IncrementalTimeline'],true);
    $p['events'][0]['route']='P03_2CAM_WORK_THEN_JV';
    $s['P03IncrementalTimeline']=json_encode($p);return $s;
};
$broken['bad P03 time']=function(array $s):array{
    $p=json_decode($s['P03IncrementalTimeline'],true);
    $p['events'][0]['at']=1900000000+100000;
    $s['P03IncrementalTimeline']=json_encode($p);return $s;
};
$broken['bad P03 textual time']=function(array $s):array{
    $p=json_decode($s['P03IncrementalTimeline'],true);
    $p['events'][0]['localTime']='BAD_TIMESTAMP';
    $s['P03IncrementalTimeline']=json_encode($p);return $s;
};
$broken['bad P03 initialized type']=function(array $s):array{
    $p=json_decode($s['P03IncrementalTimeline'],true);
    $p['initialized']='true';
    $s['P03IncrementalTimeline']=json_encode($p);return $s;
};
$broken['bad open route']=function(array $s):array{
    $p=json_decode($s['RouteEpisodeLedger'],true);
    $p['open'][0]['route']='P03_2CAM_WORK_THEN_JV';
    $s['RouteEpisodeLedger']=json_encode($p);return $s;
};
$broken['bad open future time']=function(array $s):array{
    $p=json_decode($s['RouteEpisodeLedger'],true);
    $p['open'][0]['at']=1900000000+99999;
    $s['RouteEpisodeLedger']=json_encode($p);return $s;
};
$broken['missing fusion schema']=function(array $s):array{
    $p=json_decode($s['ResidentEventFusion'],true);
    unset($p['schema']);
    $s['ResidentEventFusion']=json_encode($p);return $s;
};
$broken['missing route schema']=function(array $s):array{
    $p=json_decode($s['RouteEpisodeLedger'],true);
    unset($p['schema']);
    $s['RouteEpisodeLedger']=json_encode($p);return $s;
};
foreach($broken as $case=>$mutate){
    $invalid=$mutate($valid);
    $result=$run($invalid);
    criticalCheck(!$result['safeToRefresh']
        &&$result['status']==='BLOCKED_PRESERVE_CRITICAL_STATE'
        &&$result['criticalIssues']!==[]
        &&!$result['mayArmAlarm']
        &&!$result['maySwitchDevices']
        &&!$result['mayErasePreviousEvidence'],
        $case.' preserved for review, no repair or property action');
}
$noSource=$valid;
$noSource['P03IncrementalTimeline']=json_encode([
    'initialized'=>false,'lastRaw'=>null,'events'=>[]
]);
criticalCheck($run($noSource)['safeToRefresh'],
    'source unreadable quarantine baseline can be safely persisted');
echo "SHADOW critical P03 cursor, route open and schema upgrade protection PASS\n";
