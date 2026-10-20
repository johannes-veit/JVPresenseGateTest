<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVP03IncrementalTimeline.php';
require_once dirname(__DIR__).'/libs/JVPhoneAreaCycleMatcher.php';
require_once dirname(__DIR__).'/libs/JVP03PhonePairMatrix.php';
require_once dirname(__DIR__).'/libs/JVSharedDepartureTimeline.php';
require_once dirname(__DIR__).'/libs/JVResidentEventFusion.php';
function epoch97(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('SHADOW_0_8_97 '.$why);
}
$tz=new DateTimeZone('Europe/Berlin');
$now=(new DateTimeImmutable('2026-10-20 18:45:00',$tz))->getTimestamp();
$stamp=static fn(int $offset):string=>
    (new DateTimeImmutable('@'.($now+$offset)))
        ->setTimezone($tz)->format('Y-m-d H:i:s');
$proof=static fn(string $date,string $direction='HOME_TO_LAGER',
    ?string $route=null):string=>
    '['.$date.'] P03 PROOF VERIFIED '.$direction.' via '
    .($route??($direction==='HOME_TO_LAGER'
        ?'P03_2CAM_JV_THEN_WORK':'P03_2CAM_WORK_THEN_JV'));
$base=JVP03IncrementalTimeline::evaluate('P03 97 BASELINE',[],$now-50);
$goodText="P03 97 BASELINE\n".$proof($stamp(-20));
$good=JVP03IncrementalTimeline::evaluate($goodText,$base['next'],$now);
epoch97($good['report']['logContinuity']==='OK'
    &&$good['report']['verifiedTimeline24hCount']===1
    &&$good['report']['newDirectionProofsThisRead']===1,
    'ordinary current two-mast verification works before an anomaly');

$fusion=['schema'=>1,
    'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>[
        'johannes'=>[
            'geoSourceContinuous'=>true,
            'macSourceContinuous'=>true,
            'sourceRebaselineRequired'=>false,
            'eventHistory'=>[
                ['code'=>'PHONE_GEOFENCE_OUT_OBSERVED','at'=>$now-22]],
            'lastSourceRebindAt'=>null
        ],
        'carina'=>[
            'geoSourceContinuous'=>true,
            'macSourceContinuous'=>true,
            'sourceRebaselineRequired'=>false,
            'eventHistory'=>[
                ['code'=>'PHONE_GEOFENCE_OUT_OBSERVED','at'=>$now-21]],
            'lastSourceRebindAt'=>null
        ]
    ]
];
$cycles=JVPhoneAreaCycleMatcher::evaluate($fusion,$now);
epoch97($cycles['pairedCycleCount']===1,
    'two continuously observed anonymous device-area OUTs near P03 proof');
$mode=['schema'=>1,
    'authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
    'p03ModeTrustedFromFreshDiagnosis'=>true,
    'p03OperatingMode'=>'TEST_ACTIVE'];
$near=JVSharedDepartureTimeline::evaluate(
    $fusion,$good['report'],$mode,$now);
epoch97($near['verifiedP03NearPair']
    &&$near['verifiedP03NearPairAt']===$now-20
    &&!$near['personDepartureCertified'],
    'healthy input keeps accepted anonymous temporal correlation');

// A contradictory direction/route and a valid second proof arrive in
// the SAME input delta: neither is a sound basis for a 24h continuous
// proof episode. Previously the older proof was left in 24h history.
$contradiction=$goodText."\n".$proof($stamp(-10),
    'HOME_TO_LAGER','P03_2CAM_WORK_THEN_JV')
    ."\n".$proof($stamp(-8));
$bad=JVP03IncrementalTimeline::evaluate(
    $contradiction,$good['next'],$now+1);
epoch97($bad['report']['logContinuity']==='INVALID_PROOF_EPOCH'
    &&in_array('P03_DIRECTION_ROUTE_MISMATCH',
        $bad['report']['warnings'],true)
    &&$bad['report']['newlyVerifiedDirectionProofs']===[]
    &&$bad['report']['verifiedDirectionTimeline24h']===[]
    &&$bad['next']['events']===[],
    'contradictory P03 batch must discard both valid and invalid crossings');
$blocked=JVSharedDepartureTimeline::evaluate(
    $fusion,$bad['report'],$mode,$now+1);
epoch97(!$blocked['verifiedP03NearPair']
    &&in_array('P03_LIVE_EVENT_CONTINUITY_UNVERIFIED',
        $blocked['sourceGaps'],true)
    &&!$blocked['mayControlAlarm'],
    'source contradiction propagates to shared phone correlation');

// Subsequent fully verified new line is eligible, not older session.
$post=JVP03IncrementalTimeline::evaluate(
    $contradiction."\n".$proof($stamp(3)),$bad['next'],$now+4);
epoch97($post['report']['logContinuity']==='OK'
    &&$post['report']['newDirectionProofsThisRead']===1
    &&$post['report']['verifiedTimeline24hCount']===1
    &&$post['report']['lastDirectionAt']===$now+3,
    'new P03 epoch accepts only subsequent genuinely observed proof');
$again=JVP03IncrementalTimeline::evaluate(
    $contradiction."\n".$proof($stamp(3)),$post['next'],$now+5);
epoch97($again['report']['newDirectionProofsThisRead']===0,
    'no stale proof replay after epoch recovery');

// Impossible future date must clear earlier valid proof rather than
// merely ignoring this line while keeping a seemingly healthy window.
$future=$goodText."\n".$proof('2099-10-20 18:45:00');
$wrongClock=JVP03IncrementalTimeline::evaluate(
    $future,$good['next'],$now+1);
epoch97($wrongClock['report']['logContinuity']==='INVALID_PROOF_EPOCH'
    &&in_array('P03_EVENT_TIME_UNTRUSTED',
        $wrongClock['report']['warnings'],true)
    &&$wrongClock['report']['verifiedTimeline24hCount']===0
    &&$wrongClock['next']['events']===[],
    'impossible time breaks source evidence continuity');
$macRows=['schema'=>1,
    'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>[]];
$geoJ=['schema'=>1,
    'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY'];
$geoC=['schema'=>1,
    'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY'];
$work=['schema'=>1,
    'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'enabled'=>true,'people'=>[]];
$fused=JVResidentEventFusion::evaluate(
    $macRows,$geoJ,$geoC,$work,$wrongClock['report'],[],$now+1);
epoch97($fused['report']['p03']['logInterrupted']===true
    &&$fused['report']['p03']['newAnonymousProofs']===[]
    &&$fused['report']['p03']['lastAnonymousProof']===null
    &&!$fused['report']['mayArmAlarm'],
    'downstream resident fusion rejects invalid proof epoch');

// Clock ambiguity at actual Europe/Berlin fallback hour is an invalid
// P03 source epoch, even when the preceding 24h contained valid data.
$fall=(new DateTimeImmutable('2026-10-25 04:30:00',$tz))->getTimestamp();
$old=JVP03IncrementalTimeline::evaluate('DST-SESSION',[],$fall-180);
$oldGood=JVP03IncrementalTimeline::evaluate(
    'DST-SESSION'."\n".$proof('2026-10-25 03:30:00'),
    $old['next'],$fall-100);
epoch97($oldGood['report']['verifiedTimeline24hCount']===1,
    'nonambiguous winter-time proof exists prior to bad fold timestamp');
$fold=JVP03IncrementalTimeline::evaluate(
    'DST-SESSION'."\n".$proof('2026-10-25 03:30:00')
        ."\n".$proof('2026-10-25 02:30:00'),
    $oldGood['next'],$fall);
epoch97($fold['report']['logContinuity']==='INVALID_PROOF_EPOCH'
    &&$fold['report']['verifiedTimeline24hCount']===0,
    'ambiguous DST fold invalidates the current camera evidence epoch');

echo "SHADOW v0.8.97: route mismatch, future clock, DST fold, epoch restart, resident fusion and independent phone pairing PASS\n";
