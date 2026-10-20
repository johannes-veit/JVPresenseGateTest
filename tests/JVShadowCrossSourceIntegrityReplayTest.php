<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPhoneAreaCycleMatcher.php';
require_once dirname(__DIR__).'/libs/JVPhoneRoomOverlapAudit.php';
require_once dirname(__DIR__).'/libs/JVSharedDepartureTimeline.php';
require_once dirname(__DIR__).'/libs/JVShadowCrossSourceIntegrityGate.php';

function integrity86(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('SHADOW_0_8_86 '.$why);
}
$now=1900000000;
$phone=static fn(array $h):array=>[
    'geoSourceContinuous'=>true,
    'macSourceContinuous'=>true,
    'sourceRebaselineRequired'=>false,
    'lastSourceRebindAt'=>null,'eventHistory'=>$h
];
$ev=static fn(string $direction,int $offset):array=>[
    'code'=>'PHONE_GEOFENCE_'.$direction.'_OBSERVED',
    'at'=>$now+$offset
];
$fusion=static fn(array $j,array $c):array=>[
    'schema'=>1,'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>['johannes'=>$phone($j),'carina'=>$phone($c)]
];
$proof=static fn(int $offset):array=>[
    'at'=>$now+$offset,
    'source'=>'P03_TWO_MAST_VERIFIED',
    'direction'=>'HOME_TO_LAGER'
];
$p03=static fn(array $events):array=>[
    'schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'baselineOnly'=>false,'logContinuity'=>'OK',
    'newProofBatchOverflow'=>false,'verifiedTimelineTruncated'=>false,
    'warnings'=>[],'verifiedDirectionTimeline24h'=>$events
];
$timing=['schema'=>1,
    'authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
    'p03ModeTrustedFromFreshDiagnosis'=>true,
    'p03OperatingMode'=>'TEST_ACTIVE'
];
$activity=['schema'=>1,'authority'=>'MOTION_EDGE_HISTORY_ONLY',
    'full24hWindowObserved'=>true,'perSource'=>[
        'eg_flur'=>['zone'=>'HOME','name'=>'GUS EG Flur'],
        'eg_buero'=>['zone'=>'HOME','name'=>'GUS EG Büro']
    ]
];
$ledger=['schema'=>1,'entries'=>[
    ['id'=>'eg_flur','at'=>$now-820,'status'=>'SINGLE_UNCERTAIN']
]];

$make=static function(array $fusion,array $cam,array $timing)
    use($now,$activity,$ledger):array{
    $cycles=JVPhoneAreaCycleMatcher::evaluate($fusion,$now);
    $tl=JVSharedDepartureTimeline::evaluate($fusion,$cam,$timing,$now);
    $room=JVPhoneRoomOverlapAudit::evaluate($cycles,$activity,$ledger,$now);
    return [$cycles,$tl,$cam,$room];
};
$inputs=$make($fusion(
    [$ev('OUT',-900),$ev('IN',-700)],
    [$ev('OUT',-870),$ev('IN',-680)]
),$p03([$proof(-850)]),$timing);
$clean=JVShadowCrossSourceIntegrityGate::evaluate(...[...$inputs,$now]);
integrity86($clean['status']==='CROSS_SOURCE_CONSISTENT_ANONYMOUS_ONLY'
    &&$clean['verifiedPhonePairCount']===1
    &&$clean['uniqueP03Matches']===1
    &&$clean['latestP03LinkConsistent']
    &&$clean['anonymousRoomOverlayConsistent']
    &&$clean['phoneIntervalsStrictlyPositive']
    &&$clean['p03SourceReady']
    &&$clean['testedInvariants']>=7
    &&!$clean['inputDataRejected']
    &&!$clean['homeEmptyCertifiable']
    &&!$clean['mayControlAlarm'],
    'end-to-end normal 3-source pipeline must be anonymous and internally consistent');

// Independently tamper with a single downstream report. The gate must
// reject each forged or inconsistent claim without affecting other modules.
$cases=[
    ['latest phone reused across modules',1,function(&$t){
        $t['pairedPhoneOutAt']['johannes']+=60;
    },'LATEST_PHONE_PAIR_DIFFERS_ACROSS_MODULES'],
    ['fabricated phone count',0,function(&$c){
        $c['pairedCycleCount']=2;
    },'PHONE_PAIR_INDEX_OR_CAPACITY_INCONSISTENT'],
    ['non-positive phone overlap',0,function(&$c){
        $c['recentPairs'][0]['phoneAreaOverlapSeconds']=0;
    },'PHONE_PAIR_INTERVAL_INVALID_OR_REUSED'],
    ['recycled P03 proof after OFF',1,function(&$t){
        $t['p03Mode']='TEST_AND_PRODUCTION_OFF';
    },'P03_LATEST_PROOF_NOT_QUALIFIED'],
    ['P03 claimed without timestamp',1,function(&$t){
        $t['verifiedP03NearPairAt']=null;
    },'P03_LATEST_PROOF_NOT_QUALIFIED'],
    ['P03 timestamp after both phone returns',1,function(&$t){
        $t['verifiedP03NearPairAt']=1900000000-600;
    },'P03_LATEST_PROOF_NOT_QUALIFIED'],
    ['P03 negative gap',1,function(&$t){
        $t['p03ProofGapSeconds']=-1;
    },'P03_LATEST_PROOF_NOT_QUALIFIED'],
    ['fabricated P03 timestamp not found in independent proof history',1,function(&$t){
        $t['verifiedP03NearPairAt']=1900000000-860;
        $t['p03ProofGapSeconds']=10;
    },'P03_LINK_TIMESTAMP_NOT_IN_VERIFIED_HISTORY'],
    ['P03 proof without active link',1,function(&$t){
        $t['verifiedP03NearPair']=false;
    },'P03_GHOST_PROOF_WITHOUT_LINK'],
    ['P03 parser failure while retaining proof',2,function(&$p){
        $p['warnings'][]='P03_LOG_BATCH_INVALID';
    },'P03_LATEST_PROOF_NOT_QUALIFIED'],
    ['anonymous GUS more rooms than edges',3,function(&$r){
        $r['latestPairHomeMotionRooms']=5;
    },'ANONYMOUS_ROOM_PAIR_COUNTER_MISMATCH'],
    ['GUS reports real resident occupancy',3,function(&$r){
        $r['presenceCertified']=true;
    },'ANONYMOUS_ROOM_PAIR_COUNTER_MISMATCH'],
    ['forged alarm permission',1,function(&$t){
        $t['mayControlAlarm']=true;
    },'UNAUTHORIZED_PHYSICAL_OR_CONTROL_CLAIM'],
    ['negative event cap',1,function(&$t){
        $t['p03RecentPhonePairsChecked']=-5;
    },'PHONE_P03_AGGREGATE_MISMATCH'],
    ['claim more P03 matches than pairs',1,function(&$t){
        $t['p03UniquePairMatchesRecent']=17;
    },'PHONE_P03_AGGREGATE_MISMATCH']
];
foreach($cases as [$name,$index,$change,$expected]){
    $altered=$inputs;$change($altered[$index]);
    $tested=JVShadowCrossSourceIntegrityGate::evaluate(
        ...[...$altered,$now]);
    integrity86($tested['status']==='CROSS_SOURCE_CONTRADICTION_NO_ACTION'
        &&in_array($expected,$tested['violations'],true)
        &&$tested['inputDataRejected']
        &&!$tested['mayControlAlarm']
        &&!$tested['homeEmptyCertifiable'],
        'adversarial independent tampering: '.$name);
}

// Source gap is not corruption: no qualifying geofence/source remains
// open for troubleshooting, but alarm remains explicitly locked.
$missingPhone=$fusion(
    [$ev('OUT',-900)],
    [$ev('OUT',-880)]);
$missingPhone['people']['carina']['geoSourceContinuous']=false;
$missing=$make($missingPhone,$p03([]),$timing);
$miss=JVShadowCrossSourceIntegrityGate::evaluate(
    ...[...$missing,$now]);
integrity86($miss['status']==='SOURCE_INCOMPLETE_NO_ACTION'
    &&$miss['violations']===[]
    &&$miss['priorityChecks']===['ONE_OR_BOTH_PHONE_AREA_SOURCES_UNQUALIFIED']
    &&!$miss['mayChangePresence'],
    'missing phone source must not be diagnosed as forged data');
$withoutP03=$make(
    $fusion(
        [$ev('OUT',-900),$ev('IN',-700)],
        [$ev('OUT',-870),$ev('IN',-680)]
    ),$p03([]),$timing
);
$nop=JVShadowCrossSourceIntegrityGate::evaluate(
    ...[...$withoutP03,$now]);
integrity86($nop['status']==='CROSS_SOURCE_CONSISTENT_ANONYMOUS_ONLY'
    &&!$nop['latestP03LinkConsistent']
    &&in_array('NO_UNIQUE_P03_PROOF_FOR_LATEST_PHONE_PAIR',
        $nop['priorityChecks'],true),
    'absence of real camera transfer is a gap, not a fraudulent proof');
$intentionallyOff=$timing;
$intentionallyOff['p03OperatingMode']='TEST_AND_PRODUCTION_OFF';
$offInputs=$make($fusion(
    [$ev('OUT',-900),$ev('IN',-700)],
    [$ev('OUT',-870),$ev('IN',-680)]
),$p03([$proof(-850)]),$intentionallyOff);
$offGate=JVShadowCrossSourceIntegrityGate::evaluate(
    ...[...$offInputs,$now]);
integrity86($offGate['status']==='CROSS_SOURCE_CONSISTENT_ANONYMOUS_ONLY'
    &&!$offGate['latestP03LinkConsistent']
    &&!$offGate['p03SourceReady']
    &&$offGate['verifiedPhonePairCount']===1,
    'P03 intentionally OFF must never grant an old camera proof');

// Repeated calls are deterministic and cannot modify outside buffers.
for($i=0;$i<100;$i++){
    $again=JVShadowCrossSourceIntegrityGate::evaluate(
        ...[...$inputs,$now]);
    integrity86($again===$clean,
        '100 identical passive refreshes never replay or alter evidence '.$i);
}
echo "SHADOW v0.8.86: cross-source full audit, 15 adversarial mutations, read-only gaps, 100 deterministic refreshes PASS\n";
