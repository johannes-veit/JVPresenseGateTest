<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPhoneAreaCycleMatcher.php';
require_once dirname(__DIR__).'/libs/JVP03PhonePairMatrix.php';
require_once dirname(__DIR__).'/libs/JVSharedDepartureTimeline.php';
require_once dirname(__DIR__).'/libs/JVPhoneRoomOverlapAudit.php';
require_once dirname(__DIR__).'/libs/JVShadowCrossSourceIntegrityGate.php';
function candidate95(bool $okay,string $why):void {
    if(!$okay)throw new RuntimeException('SHADOW_0_8_95 '.$why);
}
$t=1900000000;
$event=static fn(string $action,int $offset):array=>[
    'code'=>'PHONE_GEOFENCE_'.$action.'_OBSERVED','at'=>$t+$offset
];
$person=static fn(array $rows):array=>[
    'geoSourceContinuous'=>true,'macSourceContinuous'=>true,
    'sourceRebaselineRequired'=>false,'lastSourceRebindAt'=>null,
    'eventHistory'=>$rows
];
$two=static fn(array $j,array $c):array=>[
    'schema'=>1,'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>['johannes'=>$person($j),'carina'=>$person($c)]
];
$cross=static fn(int $seconds,string $dir='HOME_TO_LAGER'):array=>[
    'source'=>'P03_TWO_MAST_VERIFIED','direction'=>$dir,
    'at'=>$t+$seconds
];
$camera=static fn(array $entries):array=>[
    'schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'logContinuity'=>'OK','baselineOnly'=>false,
    'newProofBatchOverflow'=>false,'verifiedTimelineTruncated'=>false,
    'verifiedDirectionTimeline24h'=>$entries,'warnings'=>[]
];
$mode=['schema'=>1,
    'authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
    'p03ModeTrustedFromFreshDiagnosis'=>true,
    'p03OperatingMode'=>'TEST_ACTIVE'
];
$activity=['schema'=>1,'authority'=>'MOTION_EDGE_HISTORY_ONLY',
    'full24hWindowObserved'=>false,'perSource'=>[]];
$ledger=['schema'=>1,'entries'=>[]];
$one=$two(
    [$event('OUT',-900),$event('IN',-500)],
    [$event('OUT',-860),$event('IN',-490)]
);
$c=JVPhoneAreaCycleMatcher::evaluate($one,$t);
candidate95($c['pairedCycleCount']===1,
    'one authentic overlap of both smartphone area events');
$single=$camera([$cross(-830)]);
$a=JVP03PhonePairMatrix::evaluate($c,$single,$t);
candidate95($a['matchedPhonePairs']===1
    &&$a['latestPairP03CandidateCount']===1
    &&!$a['latestPairP03Ambiguous']
    &&($a['latestPhonePairP03']['p03At']??null)===$t-830,
    'single anonymous P03 candidate can still be time-aligned');
$valid=JVSharedDepartureTimeline::evaluate($one,$single,$mode,$t);
candidate95($valid['verifiedP03NearPair']
    &&!$valid['p03LatestPairAmbiguous']
    &&$valid['p03ProofGapSeconds']===30,
    'ordinary unique P03 association remains backwards compatible');

// Two distinct P03 crossings 20s apart plausibly fit the same two
// handset reports. A 1-to-1 DP selects one, but the selection is NOT
// an externally observed unique physical event.
$competing=$camera([$cross(-830),$cross(-810)]);
$m=JVP03PhonePairMatrix::evaluate($c,$competing,$t);
candidate95($m['matchedPhonePairs']===1
    &&$m['latestPairP03CandidateCount']===2
    &&$m['latestPairP03Ambiguous']
    &&$m['latestPairMatchedButAmbiguous']
    &&$m['latestPhonePairP03']===null
    &&!$m['noHumanIdentity']===false,
    'two potential camera crossings quarantine latest P03 timestamp');
$tl=JVSharedDepartureTimeline::evaluate($one,$competing,$mode,$t);
candidate95($tl['pairedPhoneAreaOutObserved']
    &&$tl['p03UniquePairMatchesRecent']===1
    &&$tl['p03LatestPairAmbiguous']
    &&$tl['p03LatestPairCandidateCount']===2
    &&!$tl['verifiedP03NearPair']
    &&!$tl['p03MatchPreviousNotLatest']
    &&in_array('MULTIPLE_P03_CANDIDATES_FOR_LATEST_PHONE_PAIR',
        $tl['sourceGaps'],true)
    &&!$tl['personDepartureCertified']
    &&!$tl['mayControlAlarm'],
    'phone pair still known but a chosen anonymous camera timestamp is not');
$room=JVPhoneRoomOverlapAudit::evaluate($c,$activity,$ledger,$t);
$gate=JVShadowCrossSourceIntegrityGate::evaluate(
    $c,$tl,$competing,$room,$t,$activity,$ledger);
candidate95($gate['status']==='CROSS_SOURCE_CONSISTENT_ANONYMOUS_ONLY'
    &&$gate['p03ProofAssignmentReconciled']
    &&!$gate['latestP03LinkConsistent']
    &&!$gate['mayChangePresence'],
    'ambiguity is a consistent insufficient-proof condition not damaged data');

// A forged declaration of a single preferred timestamp must be
// rejected against the actual two source proofs.
$fake=$tl;
$fake['verifiedP03NearPair']=true;
$fake['verifiedP03NearPairAt']=$t-830;
$fake['p03ProofGapSeconds']=30;
$fake['p03LatestPairAmbiguous']=false;
$bad=JVShadowCrossSourceIntegrityGate::evaluate(
    $c,$fake,$competing,$room,$t,$activity,$ledger);
candidate95(in_array('P03_FULL_ASSIGNMENT_DIFFERS_FROM_SOURCE',
        $bad['violations'],true)
    &&$bad['status']==='CROSS_SOURCE_CONTRADICTION_NO_ACTION',
    'claimed preferred camera crossing rejected by independent replay');

// Opposite direction is not a competing OUT corridor proof.
$reverse=$camera([$cross(-830),$cross(-810,'LAGER_TO_HOME')]);
$rev=JVP03PhonePairMatrix::evaluate($c,$reverse,$t);
candidate95($rev['latestPairP03CandidateCount']===1
    &&!$rev['latestPairP03Ambiguous']
    &&$rev['latestPhonePairP03']['p03At']===$t-830,
    'one inbound camera direction does not create fake outgoing ambiguity');

// Competing *phone* episodes. One camera crossing could be assigned
// to an older phone pair or to the latest; tie-breaker must not
// transform this ambiguity into a statement about a specific visit.
$twoTrips=$two(
    [$event('OUT',-1000),$event('IN',-900),
     $event('OUT',-850),$event('IN',-720)],
    [$event('OUT',-990),$event('IN',-890),
     $event('OUT',-840),$event('IN',-710)]
);
$cycles=JVPhoneAreaCycleMatcher::evaluate($twoTrips,$t);
candidate95($cycles['pairedCycleCount']===2,
    'two true observed phone-OUT episodes remain distinct');
$oneBetween=$camera([$cross(-910)]);
$overlap=JVP03PhonePairMatrix::evaluate($cycles,$oneBetween,$t);
candidate95($overlap['matchedPhonePairs']===1
    &&$overlap['latestPairP03CandidateCount']===1
    &&$overlap['latestPairP03Ambiguous']
    &&$overlap['latestPhonePairP03']===null,
    'one camera timestamp fits older or newer phone pair, so neither is uniquely connected');
$timeline=JVSharedDepartureTimeline::evaluate(
    $twoTrips,$oneBetween,$mode,$t);
candidate95($timeline['p03LatestPairAmbiguous']
    &&!$timeline['verifiedP03NearPair']
    &&!$timeline['p03MatchPreviousNotLatest']
    &&!$timeline['homeEmptyCertified'],
    'latest departure must not inherit competing anonymous camera proof');

// The optimal DP chooses the OLD pair when its distance is smaller,
// but the same proof is also within 300s of the latest episode.
// A historical attribution is therefore not uniquely proven either.
$oldPreferred=$camera([$cross(-995)]);
$oldChoice=JVP03PhonePairMatrix::evaluate($cycles,$oldPreferred,$t);
candidate95($oldChoice['matchedPhonePairs']===1
    &&$oldChoice['latestPairP03CandidateCount']===1
    &&$oldChoice['latestPairP03Ambiguous']
    &&$oldChoice['latestPhonePairP03']===null,
    'ambiguous candidate is quarantined even if DP prefers old phone pair');
$oldTimeline=JVSharedDepartureTimeline::evaluate(
    $twoTrips,$oldPreferred,$mode,$t);
candidate95($oldTimeline['p03LatestPairAmbiguous']
    &&!$oldTimeline['p03MatchPreviousNotLatest']
    &&!$oldTimeline['verifiedP03NearPair'],
    'older preferred P03 selection still does not prove latest pair had no matching corridor');

// P03 intentionally OFF and unreadable data cannot be promoted even
// if two candidate source timestamps happen to exist historically.
$off=$mode;$off['p03OperatingMode']='TEST_AND_PRODUCTION_OFF';
$noProof=JVSharedDepartureTimeline::evaluate(
    $one,$competing,$off,$t);
candidate95(!$noProof['verifiedP03NearPair']
    &&$noProof['p03UniquePairMatchesRecent']===0,
    'P03 OFF is still a hard read-only barrier');

echo "SHADOW v0.8.95: competing P03 proofs/pairs, genuine unique proof, inverse direction, false certification and OFF replay PASS\n";
