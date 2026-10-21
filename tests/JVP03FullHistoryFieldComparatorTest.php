<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVP03IncrementalTimeline.php';
require_once dirname(__DIR__).'/libs/JVFieldObservationComparator.php';

function historicalFieldCheck(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('FULL_HISTORY_COMPARATOR '.$message);
}
$tz=new DateTimeZone('Europe/Berlin');
$t=(new DateTimeImmutable('2026-10-20 11:00:00',$tz))->getTimestamp();
// Entirely synthetic reference; no real residents' travel data stored.
$field=[
    'schema'=>1,'authority'=>'USER_REPORTED_FIELD_OBSERVATION',
    'localTime'=>'2026-10-20 11:00',
    'reportedHouseLeavers'=>['johannes','carina']
];
$start=JVP03IncrementalTimeline::evaluate('',[],$t-10);
$lines=[];
for($i=0;$i<25;$i++){
    // One earlier anonymous OUT in the 15-minute reference window,
    // followed by 24 much newer OUTs that evict it from the 15-item view.
    $at=$i===0?$t+40:$t+1800+$i*7;
    $local=(new DateTimeImmutable('@'.$at))->setTimezone($tz)
        ->format('Y-m-d H:i:s');
    $lines[]='['.$local.'] P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_JV_THEN_WORK';
}
// This is an uninterrupted observation test. A single dump 1 hour
// after baseline would now correctly be quarantined as an outage.
$state=$start['next'];$raw='';$lastRead=$t-10;$totalNew=0;
foreach($lines as $i=>$line){
    $at=$i===0?$t+40:$t+1800+$i*7;
    while($at-$lastRead>900){
        $lastRead+=600;
        $tick=JVP03IncrementalTimeline::evaluate($raw,$state,$lastRead);
        historicalFieldCheck($tick['report']['logContinuity']==='OK',
            'periodic synthetic P03 observer tick maintains source epoch');
        $state=$tick['next'];
    }
    $raw.=($raw===''?'':"\n").$line;
    $tick=JVP03IncrementalTimeline::evaluate($raw,$state,$at);
    $state=$tick['next'];
    $lastRead=$at;
    $totalNew+=$tick['report']['newDirectionProofsThisRead'];
}
$end=$t+3600;
while($end-$lastRead>900){
    $lastRead+=600;
    $tick=JVP03IncrementalTimeline::evaluate($raw,$state,$lastRead);
    $state=$tick['next'];
}
$p03=JVP03IncrementalTimeline::evaluate($raw,$state,$end);
$p=$p03['report'];
historicalFieldCheck($totalNew===25
    &&$p['newDirectionProofsThisRead']===0
    &&count($p['recentDirectionProofs'])===15
    &&count($p['verifiedDirectionTimeline24h'])===25
    &&$p['verifiedTimeline24hCount']===25
    &&$p['verifiedTimelineTruncated']===false,
    '15 visible passages do not replace all 25 valid stored proofs');

$evidence=[
    'schema'=>1,'authority'=>'SHADOW_DIRECTION_PHONE_AND_LOCAL_HINTS',
    'snapshotAt'=>$end,
    'p03LiveTimeline'=>$p,
    'residentEventFusion'=>[
        'schema'=>1,
        'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
        'people'=>['johannes'=>['eventHistory'=>[]],
            'carina'=>['eventHistory'=>[]]]
    ]
];
$full=JVFieldObservationComparator::compare($field,$evidence,$end+60);
historicalFieldCheck($full['status']==='COMPARED_AS_ANONYMOUS_HINTS_ONLY'
    &&$full['p03AnonymousOutNearReference']===1
    &&$full['p03VisibleHistoryCount']===15
    &&$full['p03CheckedHistoryCount']===25
    &&$full['p03FullPersistedHistoryUsed']===true
    &&$full['p03HistoryCoverage']==='FULL_PERSISTED_TIMELINE_24H'
    &&$full['sameNumberOfPeopleObserved']==='NOT_PROVABLE'
    &&!$full['peopleIdentifiedBySensors']
    &&!$full['houseExitVerifiedBySensors']
    &&!$full['alarmMayBeArmed'],
    'early anonymous OUT can be found in persisted 25 without identifying two humans');
$gap=$evidence;
$gap['p03LiveTimeline']['logContinuity']='LOG_GAP_OR_RESET';
$gapResult=JVFieldObservationComparator::compare($field,$gap,$end+60);
historicalFieldCheck(
    $gapResult['p03HistoryCoverage']
        ==='PERSISTED_HISTORY_LOG_CONTINUITY_UNCERTAIN'
    &&in_array('P03_PROOF_LOG_CONTINUITY_NOT_CERTIFIED',
        $gapResult['notes'],true)
    &&!$gapResult['peopleIdentifiedBySensors'],
    'P03 historical events alongside a log gap are hints, not complete corridor proof');
$old=$evidence;
unset($old['p03LiveTimeline']['verifiedDirectionTimeline24h'],
    $old['p03LiveTimeline']['verifiedTimeline24hCount'],
    $old['p03LiveTimeline']['verifiedTimelineTruncated']);
$legacy=JVFieldObservationComparator::compare($field,$old,$end+60);
historicalFieldCheck(!$legacy['p03FullPersistedHistoryUsed']
    &&$legacy['p03CheckedHistoryCount']===15
    &&$legacy['p03AnonymousOutNearReference']===0
    &&$legacy['p03HistoryCoverage']==='REFERENCE_OLDER_THAN_VISIBLE_15'
    &&in_array('OLDER_CAMERA_EVENTS_NOT_IN_15_ITEM_REPORT',
        $legacy['notes'],true),
    'older EvidenceJSON without full timeline is explicitly not proof of absent camera event');
$bad=$evidence;
$bad['p03LiveTimeline']['verifiedTimeline24hCount']=999;
$invalidCount=JVFieldObservationComparator::compare($field,$bad,$end+60);
historicalFieldCheck(!$invalidCount['p03FullPersistedHistoryUsed']
    &&$invalidCount['p03AnonymousOutNearReference']===0,
    'malformed full-history count cannot be accepted as verified');
$baseline=$evidence;
$baseline['p03LiveTimeline']['baselineOnly']=true;
$baseline['p03LiveTimeline']['logContinuity']='BASELINE_ONLY';
$noReplay=JVFieldObservationComparator::compare($field,$baseline,$end+60);
historicalFieldCheck($noReplay['p03AnonymousOutNearReference']===0
    &&$noReplay['p03HistoryCoverage']==='BASELINE_ARCHIVE_NOT_LIVE_EVIDENCE',
    'first archived P03 baseline can never be promoted to new live camera exit');
$future=$evidence;
$future['snapshotAt']=$t-5;
$invalidSnapshot=JVFieldObservationComparator::compare($field,$future,$end+60);
historicalFieldCheck($invalidSnapshot['status']==='SNAPSHOT_TOO_OLD_FOR_REFERENCE',
    'historical report from before departure cannot be used to compare');
$historyLimit=$evidence;
$historyLimit['p03LiveTimeline']['verifiedTimelineTruncated']=true;
$historyLimit['p03LiveTimeline']['verifiedDirectionTimeline24h']=array_slice(
    $p['verifiedDirectionTimeline24h'],0,15
);
$historyLimit['p03LiveTimeline']['verifiedTimeline24hCount']=15;
$truncated=JVFieldObservationComparator::compare($field,$historyLimit,$end+60);
historicalFieldCheck($truncated['p03HistoryCoverage']
        ==='REFERENCE_OLDER_THAN_PERSISTED_256'
    &&$truncated['p03AnonymousOutNearReference']===0
    &&in_array('OLDER_CAMERA_EVENTS_NOT_IN_PERSISTED_256',
        $truncated['notes'],true),
    'truncated stored history cannot contradict a user-observed departure');
echo "Complete persisted P03 history field comparison PASS\n";
