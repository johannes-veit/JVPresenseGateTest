<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVCameraCapabilityPriority.php';
require_once dirname(__DIR__).'/libs/JVDahuaNumberStatEvidence.php';
require_once dirname(__DIR__).'/libs/DahuaEventParser.php';
$tests=0;
function cpAssert(mixed $x,mixed $want,string $label):void {
    global $tests;$tests++;
    if($x!==$want) throw new RuntimeException(
        "$label got ".var_export($x,true)." expected ".var_export($want,true));
}
$street=['directionMappingCertified'=>true,'out'=>'RightToLeft',
    'in'=>'LeftToRight'];
$p03=['authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'logContinuity'=>'OK','recentDirectionProofs'=>[
       ['at'=>1792040000,'direction'=>'HOME_TO_LAGER',
        'route'=>'P03_2CAM_JV_THEN_WORK']
    ]];
$blocked=['proofState'=>'BLOCKIERT – Mastkamera-Stream abgebrochen'];
$cap=JVCameraCapabilityPriority::evaluate($p03,$blocked,$street);
cpAssert($cap['cameraPriorityForStreet'][0],'WORK_LEFT_IPC_STREET_IVS',
    'WORK_LEFT existing street rule highest street-direction source');
cpAssert($cap['cameraPriorityForHouseToWork'][0],'JV_LEFT',
    'HOME→LAGER starts at actual JV mast');
cpAssert($cap['cameraPriorityForHouseToWork'][1],'WORK_LEFT',
    'WORK_LEFT required for independent route corroboration');
cpAssert($cap['cameraPriorityForHouseToWork'][2],
    'TIOC_TERRASSE_BACKUP_ONLY','TiOC never overrides mast proof');
cpAssert($cap['installedCameras']['JV_LEFT']['model'],
    'IPC-HFW5442E-ZE','actual installed WizMind model');
cpAssert($cap['installedCameras']['WORK_LEFT']['peopleCountingHardwareCapable'],
    true,'Dahua confirms native people counting potential');
cpAssert($cap['installedCameras']['WORK_LEFT']['simultaneousIVSAndPeopleCountingProven'],
    false,'switchable Smart Plan cannot be assumed concurrent');
cpAssert($cap['installedCameras']['JV_TERRASSE']['peopleCountingHardwareCapable'],
    'NOT_VERIFIED','TiOC exact model cannot be guessed');
cpAssert($cap['streetDirectionMappingKnown'],true,
    'prior street orientation is learned');
cpAssert($cap['liveStreetPeopleCounterConnected'],false,
    'direction mapping does NOT mean per-person street counter');
cpAssert($cap['liveStreetExitPersonCount'],'UNKNOWN',
    'street crossing count must not be invented');
cpAssert($cap['p03LiveProofStateBlocked'],true,
    'existing camera stream disconnection must stay visible');
cpAssert($cap['physicalTwoPeopleRecognitionVerified'],false,
    'no two simultaneous person experiment yet');
cpAssert($cap['smartPlanMustRemainUnchanged'],true,'no camera config edits');
cpAssert($cap['exactPeopleCount'],'UNKNOWN','no specific person count');

// NumberStat emits statistical totals when a rule/alarm threshold fires.
// It does not contain identities, guaranteed per-person crossing events,
// or proof that two people were simultaneously detected.
$raw='Code=NumberStat;action=Start;index=0;data={"Number":120,"EnteredNumber":180,"ExitedNumber":60,"Type":"EnterOver"}';
$parsed=JVDahuaNumberStatEvidence::decode($raw,2);
cpAssert($parsed['status'],'THRESHOLD_COUNTER_SNAPSHOT_ONLY',
    'correctly parse Dahua NumberStat event');
cpAssert($parsed['enteredTotal'],180,'entered total parsed');
cpAssert($parsed['exitedTotal'],60,'exited total parsed');
cpAssert($parsed['insideTotal'],120,'inside total parsed');
cpAssert($parsed['peopleCount'],'UNKNOWN','cumulative totals are not current walkers');
cpAssert($parsed['countsPropertyExit'],false,'no street identity/direction inferred');
cpAssert($parsed['countingSmartPlanVerified'],false,
    'a raw event alone does not establish working counting installation');
cpAssert($parsed['alarmMayBeControlled'],false,'no alarm actions');
$capCount=JVCameraCapabilityPriority::evaluate($p03,$blocked,$street,$parsed);
cpAssert($capCount['numberStatTelemetryStatus'],'THRESHOLD_COUNTER_SNAPSHOT_ONLY',
    'surface observed threshold counters as diagnostic only');
cpAssert($capCount['liveStreetPeopleCounterConnected'],false,
    'even NumberStat without calibrated route is not street count');
cpAssert($capCount['exactPeopleCount'],'UNKNOWN',
    'cannot promote threshold totals into people count');

// Existing Dahua event parser may expose ObjectId/EventID on CrossLine,
// however an ID represents a target/tracker on ONE camera, not person
// identity across cameras nor proof that no second target is occluded.
$rawIVS='Code=CrossLineDetection;action=Start;index=0;data={"ObjectType":"Human","ObjectID":18,"EventID":6,"GroupID":3,"Direction":"RightToLeft"}'."\n";
$carry='';$parsedIVS=JVP03DahuaEventParser::feed($rawIVS,$carry);
cpAssert(count($parsedIVS),1,'one legitimate IVS event parsed');
cpAssert($parsedIVS[0]['human'],true,'human class extraction');
cpAssert($parsedIVS[0]['objectId']!==null,true,'object ID may exist');
cpAssert($cap['crossLineObjectIdIsNotCrossCameraIdentity'],true,
    'ObjectId never connects people across cameras');
cpAssert($cap['crossLineEventIdIsNotPersonIdentity'],true,
    'EventId never identifies a resident');

// Malformed, stale, future, integer-overflow, wrong event class and SMD
// must fail closed and never override current working P03 IVS.
foreach([
 [null,0,'NOT_CONNECTED'],
 [$raw,125,'STALE_TELEMETRY'],
 ['Code=SmartMotionHuman;action=Start;index=0;data={"ObjectType":"Human"}',0,
  'UNRECOGNIZED_MESSAGE'],
 ['Code=NumberStat;action=Start;index=0;data={"Number":3,"EnteredNumber":2,"ExitedNumber":1,"Type":"Invalid"}',0,
  'UNTRUSTED_DATA'],
 ['Code=NumberStat;action=Start;index=0;data={"Number":3,"EnteredNumber":-2,"ExitedNumber":1,"Type":"EnterOver"}',0,
  'UNTRUSTED_DATA'],
 ['Code=NumberStat;action=Start;index=0;data={"Number":3,"EnteredNumber":2,"ExitedNumber":1,"Type":"EnterOver"}',-1,
  'STALE_TELEMETRY'],
] as $case){
 cpAssert(JVDahuaNumberStatEvidence::decode($case[0],$case[1])['status'],
     $case[2],'unsupported or stale inputs never trusted');
}
echo "Dahua IPC priority, TiOC fallback and threshold-only NumberStat PASS ($tests checks)\n";
