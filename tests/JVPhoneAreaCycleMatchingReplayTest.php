<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPhoneAreaCycleMatcher.php';
require_once dirname(__DIR__).'/libs/JVSharedDepartureTimeline.php';

function cycleCheck(bool $ok,string $reason):void{
    if(!$ok)throw new RuntimeException('SHADOW_0_8_84 '.$reason);
}
$now=1900000000;
$ev=static fn(string $mode,int $at):array=>[
    'code'=>'PHONE_GEOFENCE_'.$mode.'_OBSERVED',
    'at'=>$at,'certainty'=>'PHONE_OR_NETWORK_HINT_ONLY'
];
$person=static fn(array $events):array=>[
    'geoSourceContinuous'=>true,'macSourceContinuous'=>true,
    'sourceRebaselineRequired'=>false,'lastSourceRebindAt'=>null,
    'eventHistory'=>$events
];
$fusion=static fn(array $j,array $c):array=>[
    'schema'=>1,'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>['johannes'=>$person($j),'carina'=>$person($c)]
];
$camera=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'logContinuity'=>'OK','baselineOnly'=>false,
    'newProofBatchOverflow'=>false,'verifiedTimelineTruncated'=>false,
    'verifiedDirectionTimeline24h'=>[[
        'source'=>'P03_TWO_MAST_VERIFIED',
        'direction'=>'HOME_TO_LAGER','at'=>$now-880]]];
$timing=['schema'=>1,
    'authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
    'p03ModeTrustedFromFreshDiagnosis'=>true,'p03OperatingMode'=>'TEST_ACTIVE'];

// Duplicate or delayed OUT state, even at a different second, is not
// proof of two distinct handset departures.
$f=$fusion(
    [$ev('OUT',$now-900),$ev('OUT',$now-850)],
    [$ev('OUT',$now-870),$ev('OUT',$now-870)]
);
$m=JVPhoneAreaCycleMatcher::evaluate($f,$now);
cycleCheck($m['pairedCycleCount']===1
    &&$m['repeatedOutIgnored']['johannes']===1
    &&$m['repeatedOutIgnored']['carina']===0
    &&$m['cycles']['johannes']===1
    &&$m['cycles']['carina']===1
    &&$m['latestPair']['johannesAt']===$now-900
    &&$m['samePhoneOutNeverCountedTwice']
    &&!$m['mayClaimHumanDeparture']
    &&!$m['mayCertifyEmpty']
    &&!$m['mayControlAlarm'],
    'repeated OUT and app duplicate push de-duplicated across one-to-one pairing');
$timeline=JVSharedDepartureTimeline::evaluate($f,$camera,$timing,$now);
cycleCheck($timeline['pairedEventsWithin24h']===1
    &&$timeline['oneToOneCycleMatching']
    &&$timeline['verifiedP03NearPair']
    &&!$timeline['personDepartureCertified'],
    'shared timeline uses one-to-one cycles not raw Cartesian event pairs');

// A real IN between two OUT observations is required for TWO cycles,
// even when older events are delivered out of order by an app.
$j=[
    $ev('OUT',$now-900),$ev('IN',$now-820),
    $ev('OUT',$now-500),$ev('IN',$now-420)
];
$c=[
    $ev('OUT',$now-880),$ev('IN',$now-800),
    $ev('OUT',$now-490),$ev('IN',$now-400)
];
$twice=JVPhoneAreaCycleMatcher::evaluate($fusion(
    array_reverse($j),array_reverse($c)),$now);
cycleCheck($twice['pairedCycleCount']===2
    &&$twice['cycles']['johannes']===2
    &&$twice['cycles']['carina']===2
    &&$twice['latestPair']['johannesAt']===$now-500
    &&$twice['latestPair']['returnedAfterOut']['johannes']
    &&$twice['latestPair']['returnedAfterOut']['carina'],
    'two sequential cycles after real IN remain distinct and closed');

// Current phone state without new IN cannot be established merely by
// intermittent MAC, and current P03 mode does not identify old walkers.
$plusMac=$j;$plusMac[]=[
    'code'=>'HOME_PHONE_REAPPEARED_AFTER_CONTACT_GAP','at'=>$now-5
];
$state=JVPhoneAreaCycleMatcher::evaluate($fusion($plusMac,$c),$now);
cycleCheck($state['latestIn']['johannes']===$now-420
    &&$state['cycles']['johannes']===2,
    'MAC recovery after geofence IN never creates a new geofence cycle');

$amb=JVPhoneAreaCycleMatcher::evaluate($fusion(
    [$ev('OUT',$now-900),$ev('IN',$now-880),$ev('OUT',$now-810)],
    [$ev('OUT',$now-830)]
),$now);
cycleCheck($amb['pairedCycleCount']===1
    &&!$amb['multiplePairingsPossible']
    &&$amb['latestPair']['johannesAt']===$now-810,
    'an already closed older OUT is not a competing overlapping option');
$conflict=JVPhoneAreaCycleMatcher::evaluate($fusion(
    [$ev('OUT',$now-900),$ev('IN',$now-900)],
    [$ev('OUT',$now-890)]
),$now);
cycleCheck($conflict['status']==='ONE_OR_BOTH_PHONE_SOURCES_UNVERIFIED'
    &&$conflict['pairedCycleCount']===0
    &&$conflict['sameSecondConflicts'],
    'a phone cannot be reliably simultaneously OUT and IN in same second');
$orphan=JVPhoneAreaCycleMatcher::evaluate($fusion(
    [$ev('IN',$now-1100),$ev('OUT',$now-900),$ev('IN',$now-800)],
    [$ev('OUT',$now-880)]
),$now);
cycleCheck($orphan['orphanInIgnored']['johannes']===1
    &&$orphan['pairedCycleCount']===1
    &&$orphan['latestPair']['returnedAfterOut']['johannes'],
    'orphan IN from truncated prior history cannot create extra departure');
$rebound=$fusion($j,$c);
$rebound['people']['johannes']['lastSourceRebindAt']=$now-450;
$rebind=JVPhoneAreaCycleMatcher::evaluate($rebound,$now);
cycleCheck($rebind['cycles']['johannes']===0
    &&$rebind['pairedCycleCount']===0,
    'old area cycles cannot survive a later Symcon source rebind');
$stale=$fusion($j,$c);
$stale['people']['carina']['geoSourceContinuous']=false;
cycleCheck(JVPhoneAreaCycleMatcher::evaluate(
    $stale,$now)['pairedCycleCount']===0,
    'unqualified carina location invalidates dual phone pairing');
$oversized=$fusion(array_fill(0,25,$ev('OUT',$now-900)),$c);
cycleCheck(JVPhoneAreaCycleMatcher::evaluate(
    $oversized,$now)['qualifiedSources']!==2,
    'oversized personal events fail closed');
$unknown=$fusion([[
    'code'=>'PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC','at'=>$now-900
]],$c);
cycleCheck(JVPhoneAreaCycleMatcher::evaluate(
    $unknown,$now)['pairedCycleCount']===0,
    'MAC-return composite without standalone geofence OUT cannot be invented');

// Independent brute-force ordered assignment (not the implementation DP).
// This is intentionally exponential only on the <=5-cycles test fixtures,
// with two choices to skip and one to match at every cell.
$brute=null;
$brute=function(array $ja,array $ca,int $i,int $k)
    use(&$brute):array {
    if($i>=count($ja)||$k>=count($ca))return [0,0];
    $x=$brute($ja,$ca,$i+1,$k);
    $y=$brute($ja,$ca,$i,$k+1);
    if($y[0]>$x[0]||($y[0]===$x[0]&&$y[1]<$x[1]))$x=$y;
    $gap=abs($ja[$i]['out']-$ca[$k]['out']);
    $begin=max($ja[$i]['out'],$ca[$k]['out']);
    $end=min($ja[$i]['in'],$ca[$k]['in']);
    if($gap<=180&&$begin<$end){
        $z=$brute($ja,$ca,$i+1,$k+1);
        $z=[$z[0]+1,$z[1]+$gap];
        if($z[0]>$x[0]||($z[0]===$x[0]&&$z[1]<$x[1]))$x=$z;
    }
    return $x;
};
mt_srand(840164);
$rounds=84;
for($round=0;$round<$rounds;$round++){
    $arr=[];$lists=[];
    foreach(['johannes','carina'] as $who){
        $cursor=$now-6000+mt_rand(0,120);
        $events=[];$times=[];
        for($k=0,$n=mt_rand(1,5);$k<$n;$k++){
            $cursor+=mt_rand(130,360);
            $events[]=$ev('OUT',$cursor);
            $inAt=$cursor+mt_rand(20,95);
            $times[]=['out'=>$cursor,'in'=>$inAt];
            $events[]=$ev('IN',$inAt);
            if(mt_rand(0,2)===0)$events[]=$ev('OUT',$cursor);
        }
        shuffle($events);
        $arr[$who]=$events;
        $lists[$who]=$times;
    }
    $actual=JVPhoneAreaCycleMatcher::evaluate(
        $fusion($arr['johannes'],$arr['carina']),$now);
    $expected=$brute($lists['johannes'],$lists['carina'],0,0);
    $cost=0;
    foreach($actual['recentPairs'] as $pair)$cost+=$pair['gap'];
    cycleCheck($actual['pairedCycleCount']===$expected[0]
        &&$cost===$expected[1]
        &&array_sum($actual['unmatchedCycles'])
            ===count($lists['johannes'])+count($lists['carina'])-2*$expected[0]
        &&!$actual['mayClaimHumanDeparture']
        &&!$actual['mayCertifyEmpty'],
        'independent exhaustive optimal pair comparison round '.$round);
}
echo "SHADOW v0.8.84 phone episodes + 84 exhaustive one-to-one optimizations and ambiguity/standby/rebind guards PASS\n";
