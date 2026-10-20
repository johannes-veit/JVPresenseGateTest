<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPhoneAreaCycleMatcher.php';
require_once dirname(__DIR__).'/libs/JVSharedDepartureTimeline.php';
require_once dirname(__DIR__).'/libs/JVPhoneRoomOverlapAudit.php';
require_once dirname(__DIR__).'/libs/JVShadowCrossSourceIntegrityGate.php';

function reconcile87(bool $pass,string $why):void {
    if(!$pass)throw new RuntimeException('RECONCILIATION_0_8_87 '.$why);
}
$now=1900000000;
$event=static fn(string $type,int $at):array=>[
    'code'=>'PHONE_GEOFENCE_'.$type.'_OBSERVED','at'=>$now+$at
];
$p=static fn(array $history):array=>[
    'macSourceContinuous'=>true,'geoSourceContinuous'=>true,
    'sourceRebaselineRequired'=>false,
    'lastSourceRebindAt'=>null,'eventHistory'=>$history
];
$fusion=static fn(array $j,array $c):array=>[
    'schema'=>1,'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>['johannes'=>$p($j),'carina'=>$p($c)]
];
$camEvent=static fn(int $at,string $dir='HOME_TO_LAGER'):array=>[
    'source'=>'P03_TWO_MAST_VERIFIED','direction'=>$dir,'at'=>$now+$at
];
$camera=static fn(array $events):array=>[
    'schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'logContinuity'=>'OK','baselineOnly'=>false,
    'newProofBatchOverflow'=>false,'verifiedTimelineTruncated'=>false,
    'warnings'=>[],'verifiedDirectionTimeline24h'=>$events
];
$mode=['schema'=>1,
    'authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
    'p03ModeTrustedFromFreshDiagnosis'=>true,
    'p03OperatingMode'=>'TEST_ACTIVE'
];
$activity=['schema'=>1,'authority'=>'MOTION_EDGE_HISTORY_ONLY',
    'full24hWindowObserved'=>false,
    'perSource'=>[
        'eg_flur'=>['name'=>'GUS EG Flur','zone'=>'HOME'],
        'eg_buero'=>['name'=>'GUS EG Büro','zone'=>'HOME']
    ]];
$ledger=['schema'=>1,'entries'=>[
    ['id'=>'eg_flur','at'=>$now-830,'status'=>'SINGLE_UNCERTAIN'],
    ['id'=>'eg_buero','at'=>$now-790,'status'=>'SINGLE_UNCERTAIN']
]];
$base=$fusion([
    $event('OUT',-900),$event('IN',-700),
    $event('OUT',-630),$event('IN',-450)
],[
    $event('OUT',-875),$event('IN',-695),
    $event('OUT',-610),$event('IN',-430)
]);
// First corridor precedes the second phone episode by >300s:
 // the two reference pair assignments are genuinely unambiguous.
$source=$camera([$camEvent(-950),$camEvent(-590)]);
$create=static function(array $fusion,array $camera)
    use($now,$mode,$activity,$ledger):array {
    $cycles=JVPhoneAreaCycleMatcher::evaluate($fusion,$now);
    $time=JVSharedDepartureTimeline::evaluate(
        $fusion,$camera,$mode,$now);
    $room=JVPhoneRoomOverlapAudit::evaluate(
        $cycles,$activity,$ledger,$now);
    return [$cycles,$time,$camera,$room];
};
$reports=$create($base,$source);
$ok=JVShadowCrossSourceIntegrityGate::evaluate(
    ...[...$reports,$now,$activity,$ledger]);
reconcile87($ok['status']==='CROSS_SOURCE_CONSISTENT_ANONYMOUS_ONLY'
    &&$ok['sourceReconciliationActive']
    &&$ok['p03ProofAssignmentReconciled']
    &&$ok['rawGusSummaryReconciled']
    &&$ok['uniqueP03Matches']===2
    &&$ok['latestP03LinkConsistent']
    &&!$ok['mayChangePresence']
    &&!$ok['homeEmptyCertifiable'],
    'two distinct phone pairs and two unique P03 proofs plus raw GUS counts');

// An inflated earlier-matches summary was not caught by the previous
// v0.8.86 latest-proof-only audit, because both claimed totals were
// within the plausible 8-pair and 256-proof capacities.
$forged=$reports;
$forged[1]['p03UniquePairMatchesRecent']=1;
$x=JVShadowCrossSourceIntegrityGate::evaluate(
    ...[...$forged,$now,$activity,$ledger]);
reconcile87($x['status']==='CROSS_SOURCE_CONTRADICTION_NO_ACTION'
    &&in_array('P03_FULL_ASSIGNMENT_DIFFERS_FROM_SOURCE',
        $x['violations'],true)
    &&!$x['p03ProofAssignmentReconciled'],
    'forged plausible but wrong P03 counter now rejected');

$forged=$reports;
$forged[1]['p03RecentPhonePairsChecked']=1;
$x=JVShadowCrossSourceIntegrityGate::evaluate(
    ...[...$forged,$now,$activity,$ledger]);
reconcile87(in_array('P03_FULL_ASSIGNMENT_DIFFERS_FROM_SOURCE',
        $x['violations'],true),
    'valid-looking wrong P03 pair coverage count rejected');

$forged=$reports;
$forged[3]['latestPairHomeMotionCount']=1;
$forged[3]['latestPairHomeMotionRooms']=1;
$forged[3]['totalAnonymousGusEdgesDuringPairs']=1;
$forged[3]['pairsWithAnonymousHomeMotion']=1;
// These values are individually plausible but disagree with the actual
// GUS ledger and the baseline two-sensor summary.
$x=JVShadowCrossSourceIntegrityGate::evaluate(
    ...[...$forged,$now,$activity,$ledger]);
reconcile87(in_array('GUS_SUMMARY_DIFFERS_FROM_EVENT_LEDGER',
        $x['violations'],true)
    &&!$x['rawGusSummaryReconciled'],
    'plausible forged GUS counter fails full source replay');

$forged=$reports;
$forged[3]['historicRoomAndPhoneOverlap'][0]['anonymRoomEdges']=9;
$x=JVShadowCrossSourceIntegrityGate::evaluate(
    ...[...$forged,$now,$activity,$ledger]);
reconcile87(in_array('GUS_SUMMARY_DIFFERS_FROM_EVENT_LEDGER',
        $x['violations'],true),
    'forged older GUS summary fails raw ledger reconciliation');

// A missing source must remain informational, except that forbidden
// human identity / property empty / control claims are ALWAYS violations.
$missing=$base;
$missing['people']['carina']['geoSourceContinuous']=false;
$gap=$create($missing,$camera([]));
$goodGap=JVShadowCrossSourceIntegrityGate::evaluate(
    ...[...$gap,$now,$activity,$ledger]);
reconcile87($goodGap['status']==='SOURCE_INCOMPLETE_NO_ACTION'
    &&$goodGap['violations']===[],
    'source silence alone not a forged proof');
foreach([
    ['mayControlAlarm',1],
    ['mayControlDevices',3],
    ['mayClaimHumanDeparture',0],
    ['personCountCertified',3],
    ['homeEmptyCertified',1]
] as [$field,$index]){
    $bad=$gap;
    $bad[$index][$field]=true;
    $danger=JVShadowCrossSourceIntegrityGate::evaluate(
        ...[...$bad,$now,$activity,$ledger]);
    reconcile87(
        $danger['status']==='CROSS_SOURCE_CONTRADICTION_NO_ACTION'
        &&$danger['sourceContractViolationDuringMissingData']
        &&in_array('UNAUTHORIZED_PHYSICAL_OR_CONTROL_CLAIM',
            $danger['violations'],true)
        &&!$danger['mayControlAlarm'],
        'missing source cannot mask forbidden claim '.$field);
}

$off=$mode;
$off['p03OperatingMode']='TEST_AND_PRODUCTION_OFF';
$offTime=JVSharedDepartureTimeline::evaluate(
    $base,$source,$off,$now);
$offReports=$reports;$offReports[1]=$offTime;
$offAudit=JVShadowCrossSourceIntegrityGate::evaluate(
    ...[...$offReports,$now,$activity,$ledger]);
reconcile87($offAudit['status']==='CROSS_SOURCE_CONSISTENT_ANONYMOUS_ONLY'
    &&!$offAudit['p03SourceReady']
    &&!$offAudit['latestP03LinkConsistent']
    &&$offAudit['rawGusSummaryReconciled'],
    'intentional P03 OFF has no historical proof promotion');

$corrupt=$ledger;
$corrupt['entries'][]=['id'=>'eg_flur',
    'at'=>$now+100,'status'=>'SINGLE_UNCERTAIN'];
$wrong=JVShadowCrossSourceIntegrityGate::evaluate(
    ...[...$reports,$now,$activity,$corrupt]);
reconcile87($wrong['rawGusSummaryReconciled']
    &&$wrong['status']==='CROSS_SOURCE_CONSISTENT_ANONYMOUS_ONLY',
    'future GUS edge ignored rather than invented');

$badActivity=$activity;
$badActivity['authority']='UNVERIFIED';
$bad=JVShadowCrossSourceIntegrityGate::evaluate(
    ...[...$reports,$now,$badActivity,$ledger]);
reconcile87(in_array('GUS_REPLAY_SOURCE_INVALID',
    $bad['violations'],true)
    &&$bad['status']==='CROSS_SOURCE_CONTRADICTION_NO_ACTION',
    'untrusted raw GUS source cannot confirm published room motion');

// No P03 crossing may be recycled, even across two paired events.
$single=$create($base,$camera([$camEvent(-845)]));
$singleAudit=JVShadowCrossSourceIntegrityGate::evaluate(
    ...[...$single,$now,$activity,$ledger]);
reconcile87($singleAudit['p03ProofAssignmentReconciled']
    &&$singleAudit['uniqueP03Matches']===1
    &&!$singleAudit['latestP03LinkConsistent'],
    'one real P03 can match at most one of the two phone pairs');

// An identical set of real sources must yield the same bounded
// deterministic diagnosis over 200 successive dry-run Refresh calls.
for($i=0;$i<200;$i++){
    $again=JVShadowCrossSourceIntegrityGate::evaluate(
        ...[...$reports,$now,$activity,$ledger]);
    reconcile87($again===$ok,'deterministic independent replays '.$i);
}
echo "SHADOW v0.8.87 full P03/GUS reconciliation, 5 blocked authority spoofs, 200 repeat polls PASS\n";
