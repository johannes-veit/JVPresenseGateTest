<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVTemporalPresenceCrossCheck.php';
date_default_timezone_set('Europe/Berlin');
function auditCheck(bool $v,string $msg):void{
    if(!$v)throw new RuntimeException('Temporal audit: '.$msg);
}
$now=(new DateTimeImmutable('2026-10-17 12:31:25',
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$ts=static fn($t)=>(new DateTimeImmutable('2026-10-17 '.$t,
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$log=implode("\n",[
'[2026-10-12 19:09:47] P03 PROOF VERIFIED LAGER_TO_HOME via P03_2CAM_WORK_THEN_JV',
'[2026-10-17 12:15:51] P03 HUMAN ROH JV_LEFT Zaehler=81 – Test/Produktion AUS; kein Zonenwechsel',
'[2026-10-17 12:16:13] P03 STRASSE ROH 12:16:13 | WORK_LEFT RuleID5 | Human START | Richtung=RightToLeft – allein keine Statusbuchung',
'[2026-10-17 12:19:08] P03 STRASSE ROH 12:19:08 | WORK_LEFT RuleID5 | Human START | Richtung=LeftToRight – allein keine Statusbuchung',
'[2026-10-17 12:19:30] P03 HUMAN ROH JV_LEFT Zaehler=82 – Test/Produktion AUS; kein Zonenwechsel'
]);
$diag="=== P03 SOCKET-DIAGNOSE (NUR LESEN) ===\nZeit: 2026-10-17 12:30:39 | AUTOMATISCH\nTEST: AUS | PRODUKTION: AUS";
$timeline=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'directionProofs24h'=>['HOME_TO_LAGER'=>0,'LAGER_TO_HOME'=>0]];
$wlan=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
 'enabled'=>true,
 'people'=>[
  'johannes'=>['validatedExistingVariablePair'=>true,
    'recentMacEvidence'=>true,'lastMacContactAt'=>$ts('12:30:06')],
  'carina'=>['validatedExistingVariablePair'=>true,
    'recentMacEvidence'=>true,'lastMacContactAt'=>$ts('12:30:00')]
 ]];
$lcn=['authority'=>'MOTION_EDGE_HISTORY_ONLY','lastMotionAt'=>$ts('12:30:09')];
$disabledGeo=['enabled'=>false];
$work=['enabled'=>false];
$e=JVTemporalPresenceCrossCheck::evaluate(
    $log,$diag,$timeline,$wlan,$lcn,$disabledGeo,
    $disabledGeo,$work,true,$now
);
auditCheck($e['status']==='P03_PROOF_OFF_RAW_IVS_PRESENT'
    &&$e['p03OperatingMode']==='TEST_AND_PRODUCTION_OFF',
    'fresh diagnostic detects independently gated P03 production/test');
auditCheck($e['rawStreetIVS24h']===['OUT'=>1,'IN'=>1]
    &&$e['rawMastHuman24h']['JV_LEFT']===2
    &&$e['rawHumanWhileTestAndProductionOff24h']===2,
    'realistic 12:16 OUT and 12:19 IN raw logs recognized');
auditCheck($e['hasLiveVerifiedP03Proof']===false
    &&$e['liveVerifiedP03Transfers24h']['LAGER_TO_HOME']===0,
    'old commissioning is NEVER live proof');
auditCheck(count($e['possibleStreetToJvSequences'])===2
    &&$e['possibleStreetToJvSequences'][0]['direction']==='IN'
    &&$e['possibleStreetToJvSequences'][0]['jvHumanLagSeconds']===22
    &&$e['possibleStreetToJvSequences'][1]['direction']==='OUT'
    &&$e['possibleStreetToJvSequences'][1]['jvHumanLagSeconds']===22,
    'two 22s directional raw-only alignments are diagnostic, not certified');
auditCheck($e['phones']['johannes']['secondsFromLastRawStreetEvent']===658
    &&$e['phones']['carina']['secondsFromLastRawStreetEvent']===652
    &&$e['phones']['carina']['correspondsToThisIVSIndividual']==='NOT_ESTABLISHED'
    &&$e['wlanLastContactIsNotJoinOrLeaveTime'],
    '10min later periodic MAC confirmation NEVER establishes IVS-person match');
auditCheck($e['lcn']['lastHouseMotionAt']===$ts('12:30:09')
    &&$e['lcn']['movementIsAnonymous']
    &&!$e['mayChangeExternalPresence']&&!$e['mayArmAlarm'],
    'LCN remains anonymous and no external actions');
auditCheck(in_array('GEOFENCE_SHADOW_INPUT_DISABLED',$e['warnings'],true)
    &&in_array('FIRMEN_SSID_LIVE_SOURCE_DISABLED',$e['warnings'],true),
    'disabled data sources reported explicitly');
$staleDiag=str_replace('2026-10-17 12:30:39',
    '2026-10-17 10:30:39',$diag);
$stale=JVTemporalPresenceCrossCheck::evaluate($log,$staleDiag,$timeline,
    $wlan,$lcn,$disabledGeo,$disabledGeo,$work,true,$now);
auditCheck($stale['p03OperatingMode']==='UNKNOWN_NOT_VERIFIED'
    &&$stale['status']==='RAW_CAMERA_ACTIVITY_WITHOUT_VERIFIED_P03_PROOF',
    'stale diagnostic may not claim production still OFF');
$prodDiag=str_replace('TEST: AUS | PRODUKTION: AUS',
    'TEST: AUS | PRODUKTION: AKTIV',$diag);
$prod=JVTemporalPresenceCrossCheck::evaluate($log,$prodDiag,$timeline,
    $wlan,$lcn,$disabledGeo,$disabledGeo,$work,true,$now);
auditCheck($prod['p03OperatingMode']==='PRODUCTION_ACTIVE'
    &&$prod['status']==='RAW_CAMERA_ACTIVITY_WITHOUT_VERIFIED_P03_PROOF',
    'historical RAW OFF lines cannot negate live active production');
$noMap=JVTemporalPresenceCrossCheck::evaluate($log,$diag,$timeline,
    $wlan,$lcn,$disabledGeo,$disabledGeo,$work,false,$now);
auditCheck(count($noMap['possibleStreetToJvSequences'])===0,
    'uncertified street mapping cannot form even diagnostic IN/OUT sequences');
$secondHuman="[2026-10-17 12:19:12] P03 HUMAN ROH WORK_LEFT Zaehler=59 – Test/Produktion AUS; kein Zonenwechsel\n";
$noPersons=JVTemporalPresenceCrossCheck::evaluate(
    $secondHuman,$diag,$timeline,$wlan,$lcn,
    $disabledGeo,$disabledGeo,$work,true,$now);
auditCheck($noPersons['rawStreetIVS24h']===['OUT'=>0,'IN'=>0]
    &&$noPersons['possibleStreetToJvSequences']===[],
    'work left human alone not an OUT or IN');
$timeline['directionProofs24h']['LAGER_TO_HOME']=1;
$live=JVTemporalPresenceCrossCheck::evaluate(
    $log,$prodDiag,$timeline,$wlan,$lcn,$disabledGeo,
    $disabledGeo,$work,true,$now);
auditCheck($live['status']==='LIVE_P03_DIRECTION_PROOFS_EXIST_IDENTITY_UNKNOWN'
    &&$live['hasLiveVerifiedP03Proof']
    &&$live['phones']['johannes']['residentHomeOrAwayCertified']===false,
    'real verified proof still cannot identify a human owner');
$tooLarge=JVTemporalPresenceCrossCheck::evaluate(
    str_repeat("a",75000),$diag,$timeline,$wlan,$lcn,
    $disabledGeo,$disabledGeo,$work,true,$now);
auditCheck($tooLarge['status']==='INVALID_CLOCK_OR_PROTOCOL_SIZE',
    'oversized protocol fails safely');
echo "Temporal P03/IVS/WLAN/LCN + mode stale and identity safety PASS\n";
