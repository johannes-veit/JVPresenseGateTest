<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPhoneAreaCycleMatcher.php';
require_once dirname(__DIR__).'/libs/JVP03PhonePairMatrix.php';
require_once dirname(__DIR__).'/libs/JVPhoneRoomOverlapAudit.php';
require_once dirname(__DIR__).'/libs/JVSharedDepartureTimeline.php';
function hugeCheck(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException('SHADOW_0_8_85 '.$message);
}
$now=1900000000;
$ev=static fn(string $state,int $offset):array=>[
    'code'=>'PHONE_GEOFENCE_'.$state.'_OBSERVED','at'=>$now+$offset
];
$person=static fn(array $history):array=>[
    'geoSourceContinuous'=>true,'macSourceContinuous'=>true,
    'sourceRebaselineRequired'=>false,'lastSourceRebindAt'=>null,
    'eventHistory'=>$history
];
$fusion=static fn(array $j,array $c):array=>[
    'schema'=>1,'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>['johannes'=>$person($j),'carina'=>$person($c)]
];
$proof=static fn(int $seconds):array=>[
    'source'=>'P03_TWO_MAST_VERIFIED',
    'direction'=>'HOME_TO_LAGER','at'=>$now+$seconds
];
$p03=static fn(array $events):array=>[
    'schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'logContinuity'=>'OK','baselineOnly'=>false,
    'newProofBatchOverflow'=>false,'verifiedTimelineTruncated'=>false,
    'verifiedDirectionTimeline24h'=>$events,'warnings'=>[]
];
$timing=['schema'=>1,
    'authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
    'p03ModeTrustedFromFreshDiagnosis'=>true,
    'p03OperatingMode'=>'TEST_ACTIVE'];

// Two close OUTs but J returned BEFORE Carina's OUT:
// time differences alone must never imply both phones outside together.
$nonOverlap=$fusion(
    [$ev('OUT',-900),$ev('IN',-820)],
    [$ev('OUT',-800)]
);
$c=JVPhoneAreaCycleMatcher::evaluate($nonOverlap,$now);
hugeCheck($c['pairedCycleCount']===0
    &&$c['nearButNonOverlappingCandidates']===1
    &&$c['status']==='NEAR_PHONE_OUTS_BUT_NO_SHARED_AREA_INTERVAL',
    'early phone return blocks false shared-out label');
$shared=JVSharedDepartureTimeline::evaluate(
    $nonOverlap,$p03([$proof(-825)]),$timing,$now);
hugeCheck(!$shared['pairedPhoneAreaOutObserved']
    &&$shared['status']==='PHONE_AREA_INTERVALS_DO_NOT_OVERLAP'
    &&!$shared['verifiedP03NearPair'],
    'no phone overlap cannot be rescued by nearby anonymous P03');
$sameBoundary=$fusion(
    [$ev('OUT',-900),$ev('IN',-800)],
    [$ev('OUT',-800)]
);
hugeCheck(JVPhoneAreaCycleMatcher::evaluate(
    $sameBoundary,$now)['pairedCycleCount']===0,
    'IN and second phone OUT exactly same second has no strict overlap');

// Actual overlapping phone-area intervals, followed by two INs.
$one=$fusion(
    [$ev('OUT',-900),$ev('IN',-700)],
    [$ev('OUT',-850),$ev('IN',-680)]
);
$c=JVPhoneAreaCycleMatcher::evaluate($one,$now);
hugeCheck($c['pairedCycleCount']===1
    &&$c['latestPair']['phoneAreaOverlapStartAt']===$now-850
    &&$c['latestPair']['phoneAreaOverlapEndAt']===$now-700
    &&$c['latestPair']['phoneAreaOverlapSeconds']===150,
    'overlap clock bounded by first return of either handset');

// A P03 event after J's IN (but within 300s of both OUTs) must NOT
// be associated with this older joint phone-out interval.
$late=JVP03PhonePairMatrix::evaluate($c,$p03([$proof(-690)]),$now);
hugeCheck($late['matchedPhonePairs']===0
    &&!$late['inputUntrusted'],
    'camera proof after first phone area-IN cannot match earlier interval');
$valid=JVP03PhonePairMatrix::evaluate($c,$p03([$proof(-820)]),$now);
hugeCheck($valid['matchedPhonePairs']===1
    &&$valid['latestPhonePairP03']['p03At']===$now-820
    &&$valid['latestPhonePairP03']['gapSeconds']===30
    &&!$valid['reusedP03Proofs']
    &&$valid['noHumanIdentity']===true,
    'anonymous outbound P03 crossing can match one real device pair only');
$timeline=JVSharedDepartureTimeline::evaluate(
    $one,$p03([$proof(-690),$proof(-820)]),$timing,$now);
hugeCheck($timeline['verifiedP03NearPair']
    &&$timeline['verifiedP03NearPairAt']===$now-820
    &&$timeline['p03UniquePairMatchesRecent']===1
    &&$timeline['p03RecentPhonePairsChecked']===1
    &&$timeline['pairedOutSupersededByAreaIn']
    &&!$timeline['personDepartureCertified']
    &&!$timeline['homeEmptyCertified'],
    'current P03 mode does not misassign a later anonymous proof');

// Two true cycles per handset, whose windows straddle one P03 proof.
// Same P03 timestamp must not appear in both pair correlations.
$double=$fusion(
    [$ev('OUT',-900),$ev('IN',-700),
     $ev('OUT',-690),$ev('IN',-500)],
    [$ev('OUT',-890),$ev('IN',-710),
     $ev('OUT',-685),$ev('IN',-490)]
);
$multi=JVPhoneAreaCycleMatcher::evaluate($double,$now);
hugeCheck($multi['pairedCycleCount']===2,
    'two honest handsets with intervening IN each yield two cycles');
$once=JVP03PhonePairMatrix::evaluate(
    $multi,$p03([$proof(-715),$proof(-715)]),$now);
hugeCheck($once['matchedPhonePairs']===1
    &&$once['scopedP03Proofs']===1
    &&$once['unmatchedPhonePairs']===1,
    'duplicate exact-time P03 proof cannot be reused for two phone pairs');
$twice=JVP03PhonePairMatrix::evaluate(
    $multi,$p03([$proof(-715),$proof(-680)]),$now);
hugeCheck($twice['matchedPhonePairs']===2
    &&$twice['unmatchedPhonePairs']===0
    &&$twice['latestPhonePairP03']===null
    &&$twice['latestPairP03Ambiguous']
    &&$twice['latestPairP03CandidateCount']===2,
    'both camera events are observed but either could fit latest phone interval');
$onlyOld=JVP03PhonePairMatrix::evaluate(
    $multi,$p03([$proof(-890)]),$now);
hugeCheck($onlyOld['matchedPhonePairs']===1
    &&$onlyOld['latestPhonePairP03']===null
    &&$onlyOld['nearestPreviousPairP03']['p03At']===$now-890,
    'historical P03 match cannot certify latest phone interval');
$noLatest=JVSharedDepartureTimeline::evaluate(
    $double,$p03([$proof(-890)]),$timing,$now);
hugeCheck(!$noLatest['verifiedP03NearPair']
    &&$noLatest['p03MatchPreviousNotLatest']
    &&$noLatest['p03UniquePairMatchesRecent']===1,
    'latest pair cannot inherit old corridor proof');
$badP03=$p03([$proof(-820)]);
$badP03['warnings']=['P03_LOG_BATCH_INVALID'];
hugeCheck(JVP03PhonePairMatrix::evaluate(
    $c,$badP03,$now)['inputUntrusted']
    &&!JVSharedDepartureTimeline::evaluate(
        $one,$badP03,$timing,$now)['verifiedP03NearPair'],
    'P03 parser failure blocks pairing despite healthy mode label');
$off=$timing;$off['p03OperatingMode']='TEST_AND_PRODUCTION_OFF';
hugeCheck(!JVSharedDepartureTimeline::evaluate(
    $one,$p03([$proof(-820)]),$off,$now)['verifiedP03NearPair'],
    'P03 currently intentionally off retains safety lock');

// Compare household GUS against the same anonymous phone intervals.
// No new sensors: consume only validated existing LocalActivityLedger.
$activity=['schema'=>1,'authority'=>'MOTION_EDGE_HISTORY_ONLY',
    'full24hWindowObserved'=>false,
    'perSource'=>[
        'eg_flur'=>['zone'=>'HOME','name'=>'GUS EG Flur'],
        'eg_wohnen_kamin'=>['zone'=>'HOME','name'=>'GUS EG Wohnen Kamin'],
        'eg_buero'=>['zone'=>'HOME','name'=>'GUS EG Büro']
    ]];
$ledger=['schema'=>1,'entries'=>[
    ['id'=>'eg_flur','at'=>$now-840,'status'=>'SINGLE_UNCERTAIN'],
    ['id'=>'eg_buero','at'=>$now-730,'status'=>'MULTI_SENSOR_CORROBORATED'],
    ['id'=>'eg_wohnen_kamin','at'=>$now-725,'status'=>'KAMIN_UNCORROBORATED'],
    ['id'=>'eg_flur','at'=>$now-650,'status'=>'SINGLE_UNCERTAIN']
]];
$overlap=JVPhoneRoomOverlapAudit::evaluate($c,$activity,$ledger,$now);
hugeCheck($overlap['status']==='ANONYMOUS_ROOM_EVENTS_CORRELATED_TO_PHONE_INTERVALS'
    &&$overlap['latestPairHomeMotionCount']===2
    &&$overlap['latestPairHomeMotionRooms']===2
    &&$overlap['lastMotionInsideLatestPairAt']===$now-730
    &&!$overlap['personCountCertified']
    &&!$overlap['presenceCertified']
    &&!$overlap['mayControlAlarm']
    &&!$overlap['continuous24hGusMonitoring'],
    'real HOME GUS during two phone-area OUT periods stays anonymous');
$empty=['schema'=>1,'entries'=>[]];
$none=JVPhoneRoomOverlapAudit::evaluate($c,$activity,$empty,$now);
hugeCheck($none['latestPairHomeMotionCount']===0
    &&$none['noMotionCannotProveEmptyHouse']
    &&!$none['presenceCertified'],
    'no GUS is unknown, never home empty');
$spoof=$activity;
$spoof['perSource']['eg_flur']['name']='Wrong sensor mapped to office';
$guard=JVPhoneRoomOverlapAudit::evaluate($c,$spoof,$ledger,$now);
hugeCheck($guard['latestPairHomeMotionCount']===1,
    'mismatched named source cannot provide home-room corroboration');

// Independent reference for 80 generated phone-cycle intervals. The test
// enumerates nearest potential pair by timestamp and overlap, and never
// permits source reset or identical-second fabricated overlapping cycles.
mt_srand(20261020);
for($r=0;$r<80;$r++){
    $ju=$now-10000;$ca=$now-10000;
    $jEvents=[];$cEvents=[];
    $jIntervals=[];$cIntervals=[];
    for($i=0,$n=mt_rand(1,5);$i<$n;$i++){
        $ju+=mt_rand(120,350);$duration=mt_rand(20,230);
        $jEvents[]=$ev('OUT',$ju-$now);
        $jEvents[]=$ev('IN',$ju+$duration-$now);
        $jIntervals[]=['out'=>$ju,'in'=>$ju+$duration];
        $ju+=$duration+1;
    }
    for($i=0,$n=mt_rand(1,5);$i<$n;$i++){
        $ca+=mt_rand(120,350);$duration=mt_rand(20,230);
        $cEvents[]=$ev('OUT',$ca-$now);
        $cEvents[]=$ev('IN',$ca+$duration-$now);
        $cIntervals[]=['out'=>$ca,'in'=>$ca+$duration];
        $ca+=$duration+1;
    }
    $got=JVPhoneAreaCycleMatcher::evaluate(
        $fusion(array_reverse($jEvents),array_reverse($cEvents)),$now);
    $ref=null;
    $ref=function(int $i,int $k)use(&$ref,$jIntervals,$cIntervals):array{
        if($i>=count($jIntervals)||$k>=count($cIntervals))return [0,0];
        $a=$ref($i+1,$k);$b=$ref($i,$k+1);
        if($b[0]>$a[0]||($b[0]===$a[0]&&$b[1]<$a[1]))$a=$b;
        $ja=$jIntervals[$i];$co=$cIntervals[$k];
        $gap=abs($ja['out']-$co['out']);
        if($gap<=180&&max($ja['out'],$co['out'])<min($ja['in'],$co['in'])){
            $m=$ref($i+1,$k+1);
            $m=[$m[0]+1,$m[1]+$gap];
            if($m[0]>$a[0]||($m[0]===$a[0]&&$m[1]<$a[1]))$a=$m;
        }
        return $a;
    };
    $expected=$ref(0,0);
    $observedCost=0;
    foreach($got['recentPairs'] as $pair){
        $observedCost+=$pair['gap'];
        hugeCheck($pair['phoneAreaOverlapSeconds']>0,
            'no accepted pair may lack positive interval intersection');
    }
    hugeCheck($got['pairedCycleCount']===$expected[0]
        &&$observedCost===$expected[1]
        &&!$got['mayClaimHumanDeparture'],
        'independent 80-scenario overlap-aware cycle reference '.$r);
}
echo "SHADOW v0.8.85: 80 independent overlap assignments, P03 one-use and post-IN guards, positive-only GUS cross-check PASS\n";
