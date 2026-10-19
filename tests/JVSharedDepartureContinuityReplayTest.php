<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVSharedDepartureTimeline.php';
require_once dirname(__DIR__).'/libs/JVLocalActivityLedger.php';
require_once dirname(__DIR__).'/libs/JVRoomDayDigest.php';
function independentEvidenceCheck(bool $ok,string $reason):void{
    if(!$ok)throw new RuntimeException('V0_8_82: '.$reason);
}
$now=1900000000;
$makeHistory=static fn(int $at):array=>[
    ['code'=>'PHONE_GEOFENCE_OUT_OBSERVED','at'=>$at,
        'certainty'=>'PHONE_OR_NETWORK_HINT_ONLY']];
$personal=static fn(array $history):array=>[
    'macSourceContinuous'=>true,'geoSourceContinuous'=>true,
    'sourceRebaselineRequired'=>false,'lastSourceRebindAt'=>null,
    'eventHistory'=>$history
];
$fusion=['schema'=>1,'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>[
        'johannes'=>$personal($makeHistory($now-820)),
        'carina'=>$personal($makeHistory($now-760))
    ]];
$p03=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'logContinuity'=>'OK','baselineOnly'=>false,
    'newProofBatchOverflow'=>false,'verifiedTimelineTruncated'=>false,
    'verifiedDirectionTimeline24h'=>[
        ['source'=>'P03_TWO_MAST_VERIFIED',
            'direction'=>'HOME_TO_LAGER','at'=>$now-780]],
    'directionProofs24h'=>['HOME_TO_LAGER'=>1,'LAGER_TO_HOME'=>0]
];
$timing=['schema'=>1,
    'authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
    'p03ModeTrustedFromFreshDiagnosis'=>true,'p03OperatingMode'=>'TEST_ACTIVE'];
$r=JVSharedDepartureTimeline::evaluate($fusion,$p03,$timing,$now);
independentEvidenceCheck(
    $r['status']==='TWO_PHONE_AREA_EXITS_AND_ANONYMOUS_P03_NEARBY'
    &&$r['pairedPhoneAreaOutObserved']===true
    &&$r['pairedPhoneGapSeconds']===60
    &&$r['verifiedP03NearPair']===true
    &&$r['p03ProofGapSeconds']===0
    &&$r['pairedEventsWithin24h']===1
    &&!$r['personDepartureCertified']
    &&!$r['personCountCertified']
    &&!$r['homeEmptyCertified']
    &&!$r['mayChangePresence']
    &&!$r['mayControlAlarm']
    &&$r['geofenceEnclosesHomeAndWork'],
    'two independently observed phone OUT + P03 near do not prove two people left');
$off=$timing;$off['p03OperatingMode']='TEST_AND_PRODUCTION_OFF';
$offR=JVSharedDepartureTimeline::evaluate($fusion,$p03,$off,$now);
independentEvidenceCheck($offR['status']==='TWO_PHONE_AREA_OUT_EVENTS_P03_OFF'
    &&!$offR['verifiedP03NearPair'],
    'P03 OFF cannot upgrade retained anonymous P03 history to live proof');
$stale=$timing;$stale['p03ModeTrustedFromFreshDiagnosis']=false;
$staleR=JVSharedDepartureTimeline::evaluate($fusion,$p03,$stale,$now);
independentEvidenceCheck($staleR['p03Mode']==='UNKNOWN_NOT_VERIFIED'
    &&!$staleR['verifiedP03NearPair'],
    'stale P03 operating mode is not trusted');
$gap=$p03;$gap['logContinuity']='LOG_GAP_OR_RESET';
$gapR=JVSharedDepartureTimeline::evaluate($fusion,$gap,$timing,$now);
independentEvidenceCheck(!$gapR['verifiedP03NearPair']
    &&in_array('P03_LIVE_EVENT_CONTINUITY_UNVERIFIED',$gapR['sourceGaps'],true),
    'lost P03 continuity must not connect timelines');
$overflow=$p03;$overflow['newProofBatchOverflow']=true;
independentEvidenceCheck(!JVSharedDepartureTimeline::evaluate(
    $fusion,$overflow,$timing,$now)['verifiedP03NearPair'],
    'P03 batch overflow destroys safe pairing');
$otherDirection=$p03;
$otherDirection['verifiedDirectionTimeline24h'][0]['direction']='LAGER_TO_HOME';
independentEvidenceCheck(!JVSharedDepartureTimeline::evaluate(
    $fusion,$otherDirection,$timing,$now)['verifiedP03NearPair'],
    'HOME bound P03 passage not counted as outward corridor');
$outside=$p03;
$outside['verifiedDirectionTimeline24h'][0]['at']=$now-1500;
independentEvidenceCheck(!JVSharedDepartureTimeline::evaluate(
    $fusion,$outside,$timing,$now)['verifiedP03NearPair'],
    'old P03 outside 300sec window not correlated');

$wide=$fusion;$wide['people']['carina']=$personal($makeHistory($now-500));
$wideR=JVSharedDepartureTimeline::evaluate($wide,$p03,$timing,$now);
independentEvidenceCheck(!$wideR['pairedPhoneAreaOutObserved']
    &&$wideR['status']==='NO_TWO_QUALIFIED_PHONE_AREA_EXITS_24H',
    'events 320 seconds apart not forced into a joint departure');
$standby=$fusion;
$standby['people']['carina']=$personal([[
    'code'=>'HOME_PHONE_REAPPEARED_AFTER_CONTACT_GAP','at'=>$now-790
]]);
$standbyR=JVSharedDepartureTimeline::evaluate(
    $standby,$p03,$timing,$now);
independentEvidenceCheck(!$standbyR['pairedPhoneAreaOutObserved']
    &&!$standbyR['personDepartureCertified'],
    'iPhone standby followed by MAC rediscovery is not a departure');
$badSource=$fusion;
$badSource['people']['carina']['geoSourceContinuous']=false;
$badR=JVSharedDepartureTimeline::evaluate($badSource,$p03,$timing,$now);
independentEvidenceCheck($badR['status']==='ONE_OR_BOTH_PHONE_SOURCES_UNVERIFIED',
    'gap in personal source continuity denies pair');
$rebinding=$fusion;
$rebinding['people']['carina']['lastSourceRebindAt']=$now-700;
$rebindR=JVSharedDepartureTimeline::evaluate(
    $rebinding,$p03,$timing,$now);
independentEvidenceCheck(!$rebindR['pairedPhoneAreaOutObserved'],
    'events older than Symcon phone source rebind rejected');
$future=$fusion;
$future['people']['johannes']['eventHistory'][0]['at']=$now+3600;
independentEvidenceCheck(!JVSharedDepartureTimeline::evaluate(
    $future,$p03,$timing,$now)['pairedPhoneAreaOutObserved'],
    'future phone geofence event cannot be part of history');
$old=$fusion;
$old['people']['johannes']['eventHistory'][0]['at']=$now-86401;
independentEvidenceCheck(!JVSharedDepartureTimeline::evaluate(
    $old,$p03,$timing,$now)['pairedPhoneAreaOutObserved'],
    'older than 24h is unavailable, not disproven');
$repeat=$fusion;
$repeat['people']['johannes']['eventHistory'][]=
    $repeat['people']['johannes']['eventHistory'][0];
$repeatR=JVSharedDepartureTimeline::evaluate($repeat,$p03,$timing,$now);
independentEvidenceCheck($repeatR['pairedEventsWithin24h']===1,
    'repeated same-time OUT does not create a second event');
$twoRounds=$fusion;
$twoRounds['people']['johannes']['eventHistory'][]=[
    'code'=>'PHONE_GEOFENCE_OUT_OBSERVED','at'=>$now-1210];
$twoRounds['people']['carina']['eventHistory'][]=[
    'code'=>'PHONE_GEOFENCE_OUT_OBSERVED','at'=>$now-1200];
$roundR=JVSharedDepartureTimeline::evaluate(
    $twoRounds,$p03,$timing,$now);
independentEvidenceCheck($roundR['pairedEventsWithin24h']>=2
    &&$roundR['pairedOutEndAt']===$now-760,
    'latest paired observation wins, older pair still counted');
$tooMany=$fusion;$tooMany['people']['carina']['eventHistory']=
    array_fill(0,25,$makeHistory($now-760)[0]);
independentEvidenceCheck(JVSharedDepartureTimeline::evaluate(
    $tooMany,$p03,$timing,$now)['status']
    ==='ONE_OR_BOTH_PHONE_SOURCES_UNVERIFIED',
    'oversized phone journal rejected rather than partially trusted');

// A true 24-hour observation requires repeated polls; continuous time in
// one stored attribute is not enough to claim uninterrupted observation.
$readings=['eg_flur'=>['zone'=>'HOME','name'=>'EG Flur']];
$start=JVLocalActivityLedger::evaluate([],$readings,[],$now);
independentEvidenceCheck(!$start['report']['full24hWindowObserved']
    &&$start['next']['continuousFromAt']===$now
    &&$start['next']['lastScanAt']===$now,
    'first passive LCN sample starts a fresh continuity window');
$prior=$start['next'];
for($t=$now+300;$t<=$now+86400;$t+=300){
    $sample=JVLocalActivityLedger::evaluate([],$readings,$prior,$t);
    $prior=$sample['next'];
}
independentEvidenceCheck($sample['report']['full24hWindowObserved']
    &&$sample['report']['continuityWindowSeconds']===86400,
    '288 contiguous five-minute polls permit 24h coverage');
$down=JVLocalActivityLedger::evaluate([],$readings,$prior,$now+87000);
independentEvidenceCheck(!$down['report']['full24hWindowObserved']
    &&$down['next']['continuousFromAt']===$now+87000
    &&in_array('LEDGER_OBSERVATION_GAP_REBASELINED',
        $down['report']['warnings'],true),
    'ten-minute SymBox gap invalidates full 24h claim');
$next=JVLocalActivityLedger::evaluate(
    [],$readings,$down['next'],$now+87180);
independentEvidenceCheck(!$next['report']['full24hWindowObserved']
    &&$next['report']['continuityWindowSeconds']===180,
    'next 180s poll does not erase earlier outage');
$upgrade=[
    'schema'=>1,'startedAt'=>$now-86000,
    'entries'=>[['id'=>'eg_flur','at'=>$now-30,
        'status'=>'SINGLE_UNCERTAIN']]
];
$legacy=JVLocalActivityLedger::evaluate([],$readings,$upgrade,$now);
independentEvidenceCheck(!$legacy['report']['full24hWindowObserved']
    &&$legacy['report']['totalMotionEdges24h']===1
    &&$legacy['next']['continuousFromAt']===$now,
    'upgrade retains valid event but rebaselines unverified polling');
$day=JVRoomDayDigest::evaluate(
    $legacy['next'],$legacy['report'],$now);
independentEvidenceCheck($day['status']==='PARTIAL_WINDOW_NO_SILENCE_CONCLUSION'
    &&!$day['complete24hCoverage'],
    'digest explicitly denies uninterrupted-day coverage after upgrade');
echo "SHADOW 0.8.82 dual-phone OUT/P03 alignment, stale/rebind/standby and 24h gap-replay PASS\n";
