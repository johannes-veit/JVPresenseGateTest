<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVP03IncrementalTimeline.php';
require_once dirname(__DIR__).'/libs/JVPhoneAreaCycleMatcher.php';
function epoch91(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('SHADOW_0_8_91 '.$why);
}
$tz=new DateTimeZone('Europe/Berlin');
$time=static fn(string $s):int=>(new DateTimeImmutable($s,$tz))->getTimestamp();
$at=$time('2026-10-20 12:30:00');
$proof=static fn(string $ts,string $direction='HOME_TO_LAGER'):string=>
    '['.$ts.'] P03 PROOF VERIFIED '.$direction.' via '.
    ($direction==='HOME_TO_LAGER'
        ?'P03_2CAM_JV_THEN_WORK':'P03_2CAM_WORK_THEN_JV');
$raw="P03 INIT";
$start=JVP03IncrementalTimeline::evaluate($raw,[],$at);
epoch91($start['report']['baselineOnly'],'P03 initial baseline only');
$firstRaw=$raw."\n".$proof('2026-10-20 12:30:02');
$first=JVP03IncrementalTimeline::evaluate($firstRaw,$start['next'],$at+3);
epoch91($first['report']['newDirectionProofsThisRead']===1
    &&$first['report']['directionProofs24h']['HOME_TO_LAGER']===1,
    'one fresh real P03 proof on original epoch');

// The older proof is STILL historically valid, but after a P03 log reset
// it is no longer part of a continuously verifiable P03 time chain.
$restarted=JVP03IncrementalTimeline::evaluate('P03 RESTART MARKER',
    $first['next'],$at+4);
epoch91($restarted['report']['logContinuity']==='LOG_GAP_OR_RESET'
    &&$restarted['report']['directionProofs24h']['HOME_TO_LAGER']===0
    &&$restarted['report']['verifiedDirectionTimeline24h']===[]
    &&$restarted['next']['events']===[]
    &&!$restarted['report']['propertyEmptyMayBeCertified'],
    'reset clears retained cross-epoch camera proofs without acting on hardware');
$postResetRaw='P03 RESTART MARKER'."\n".$proof('2026-10-20 12:30:05');
$newEpoch=JVP03IncrementalTimeline::evaluate(
    $postResetRaw,$restarted['next'],$at+6);
epoch91($newEpoch['report']['directionProofs24h']['HOME_TO_LAGER']===1
    &&$newEpoch['report']['verifiedTimeline24hCount']===1
    &&$newEpoch['report']['newDirectionProofsThisRead']===1,
    'truly new P03 proof after reset starts isolated current epoch');
$noReplay=JVP03IncrementalTimeline::evaluate(
    $postResetRaw,$newEpoch['next'],$at+7);
epoch91($noReplay['report']['newDirectionProofsThisRead']===0,
    'stable protocol never replays new epoch proofs');

// More than 512 protocol records in a single delta is an invalid stream.
// Its old verified proofs cannot silently remain a continuous 24h set.
$garbled=$firstRaw;
for($i=0;$i<513;$i++)
    $garbled.="\n[2026-10-20 12:30:04] NOTE $i";
$invalid=JVP03IncrementalTimeline::evaluate($garbled,$first['next'],$at+5);
epoch91(in_array('P03_LOG_BATCH_INVALID',$invalid['report']['warnings'],true)
    &&$invalid['report']['directionProofs24h']['HOME_TO_LAGER']===0
    &&$invalid['next']['events']===[],
    'unparseable oversized log batch erases old P03 cross-epoch alignment');
$validAfterInvalid=JVP03IncrementalTimeline::evaluate(
    $garbled."\n".$proof('2026-10-20 12:30:06'),
    $invalid['next'],$at+7);
epoch91($validAfterInvalid['report']['newDirectionProofsThisRead']===1
    &&$validAfterInvalid['report']['verifiedTimeline24hCount']===1,
    'valid new event after baseline reset may be observed');

// More than 128 distinct freshly verified proofs is not a reliable
// complete batch. Keep neither partial history nor 128/129 fake people.
$baseAt=$time('2026-10-20 13:00:00');
$init=JVP03IncrementalTimeline::evaluate('P03 SESSION',[],$baseAt);
$many='P03 SESSION';
for($i=0;$i<129;$i++){
    $stamp=(new DateTimeImmutable('@'.($baseAt-300+$i)))
        ->setTimezone($tz)->format('Y-m-d H:i:s');
    $many.="\n".$proof($stamp);
}
$overflow=JVP03IncrementalTimeline::evaluate(
    $many,$init['next'],$baseAt);
epoch91($overflow['report']['newProofBatchOverflow']
    &&$overflow['report']['newlyVerifiedDirectionProofs']===[]
    &&$overflow['report']['directionProofs24h']['HOME_TO_LAGER']===0
    &&$overflow['next']['events']===[],
    '129 newly verified camera events fail closed without storing partial story');
$afterOverflow=JVP03IncrementalTimeline::evaluate(
    $many."\n".$proof('2026-10-20 13:00:01'),
    $overflow['next'],$baseAt+2);
epoch91($afterOverflow['report']['newDirectionProofsThisRead']===1
    &&$afterOverflow['report']['directionProofs24h']['HOME_TO_LAGER']===1,
    'fresh proof after overflow can start clean new P03 epoch');

// Autumn Berlin local time 02:30 appears twice. P03 proof strings have
// no explicit time zone: neither UTC choice may be guessed.
$winterAt=$time('2026-10-25 04:00:00');
$winter0=JVP03IncrementalTimeline::evaluate('P03 DST BASELINE',[],$winterAt);
$winter1=JVP03IncrementalTimeline::evaluate(
    'P03 DST BASELINE'."\n".$proof('2026-10-25 02:30:00'),
    $winter0['next'],$winterAt);
epoch91(in_array('P03_EVENT_TIME_UNTRUSTED',$winter1['report']['warnings'],true)
    &&$winter1['report']['verifiedTimeline24hCount']===0,
    'ambiguous Berlin fallback hour cannot identify a P03 crossing');
$winter2=JVP03IncrementalTimeline::evaluate(
    'P03 DST BASELINE'."\n".$proof('2026-10-25 02:30:00')
    ."\n".$proof('2026-10-25 03:30:00'),
    $winter1['next'],$winterAt);
epoch91($winter2['report']['verifiedTimeline24hCount']===1,
    'unambiguous 03:30 crossing after winter fallback remains valid');
$springAt=$time('2027-03-28 04:00:00');
$spring0=JVP03IncrementalTimeline::evaluate('P03 SPRING',[],$springAt);
$spring1=JVP03IncrementalTimeline::evaluate(
    'P03 SPRING'."\n".$proof('2027-03-28 02:30:00'),
    $spring0['next'],$springAt);
epoch91($spring1['report']['verifiedTimeline24hCount']===0,
    'nonexistent Berlin spring-forward camera timestamp rejected');

// Two physically non-overlapping intervals may have close OUT times
// but must not be advertised as alternative possible phone matches.
$t=1900000000;
$ev=static fn(string $kind,int $offset):array=>[
    'code'=>'PHONE_GEOFENCE_'.$kind.'_OBSERVED','at'=>$t+$offset
];
$phone=static fn(array $history):array=>[
    'geoSourceContinuous'=>true,'macSourceContinuous'=>true,
    'sourceRebaselineRequired'=>false,'lastSourceRebindAt'=>null,
    'eventHistory'=>$history
];
$fusion=['schema'=>1,
    'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>[
        'johannes'=>$phone([
            $ev('OUT',-400),$ev('IN',-380),
            $ev('OUT',-300),$ev('IN',-280)
        ]),
        'carina'=>$phone([
            $ev('OUT',-390),$ev('IN',-370),
            $ev('OUT',-290),$ev('IN',-270)
        ])
    ]
];
$cycles=JVPhoneAreaCycleMatcher::evaluate($fusion,$t);
epoch91($cycles['pairedCycleCount']===2
    &&$cycles['multiplePairingsPossible']===false
    &&$cycles['nearButNonOverlappingCandidates']>0
    &&!$cycles['mayClaimHumanDeparture'],
    'two unambiguous paired phone intervals cannot inherit false options');
echo "SHADOW v0.8.91: camera log reset/invalid batch/overflow, DST and phone ambiguity replay PASS\n";
