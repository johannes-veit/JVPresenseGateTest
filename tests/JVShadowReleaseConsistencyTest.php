<?php
declare(strict_types=1);
/**
 * Release gate: no misleading version or forgotten PHP import during a major
 * SHADOW upgrade, and exactly the expected seven display variables.
 */
$root=dirname(__DIR__);
$meta=json_decode((string)file_get_contents($root.'/library.json'),true);
if(!is_array($meta)||($meta['version']??null)!=='0.8.82'
    ||($meta['build']??null)!==163)
    throw new RuntimeException('WRONG_RELEASE_META');
$handoff=(string)file_get_contents($root.'/libs/JVResidentEveningHandoff.php');
$form=json_decode((string)file_get_contents(
    $root.'/JVPresenceIdentityShadow/form.json'),true);
$changelog=(string)file_get_contents($root.'/CHANGELOG.md');
$main=(string)file_get_contents($root.'/JVPresenceIdentityShadow/module.php');
if(!str_contains($handoff,"SHADOW v0.8.82 Build 163")
    ||!str_starts_with($changelog,"# v0.8.82 (Build 163)"))
    throw new RuntimeException('MISMATCHED_REPORT_OR_CHANGELOG_VERSION');
$found=false;
foreach(($form['elements']??[]) as $item)
    if(($item['type']??null)==='Label'
        &&str_starts_with($item['caption']??'','SHADOW v0.8.82:'))$found=true;
if(!$found)throw new RuntimeException('STALE_USER_VISIBLE_HELP');
foreach([
    'JVShadowStateCheckpoint',
    'JVShadowIntegrityAudit',
    'JVResidentEventFusion',
    'JVRouteEpisodeLedger',
    'JVResidentJourneyEvidence',
    'JVPresenceEvidenceReadiness',
    'JVPresenceCommissioningGate',
    'JVShadowUpgradePreflight',
    'JVPresenceSafetyCase',
    'JVShadowReportBudget',
    'JVResidentArrivalInference',
    'JVRoomMotionInsight',
    'JVHousePowerContext',
    'JVRoomDayDigest',
    'JVAnonymousRoomRouteAtlas',
    'JVJointDepartureReadiness',
    'JVSharedDepartureTimeline',
    'JVResidentDepartureInference'
] as $class){
    if(!str_contains($main,"/libs/".$class.".php"))
        throw new RuntimeException('REQUIRED_HELPER_NOT_LOADED: '.$class);
}
if(!str_contains($main,'IPS_SemaphoreEnter(')
    ||!str_contains($main,'IPS_SemaphoreLeave(')
    ||!str_contains($main,"RefreshPending")
    ||!str_contains($main,"RefreshDraining"))
    throw new RuntimeException('CONCURRENT_GUS_REFRESH_COALESCING_MISSING');
if(!str_contains($main,"JVShadowStateCheckpoint::shouldPersist(")
    ||!str_contains($main,"JVShadowStateCheckpoint::useRuntimeCache("))
    throw new RuntimeException('EVENT_CHECKPOINT_PERSISTENCE_MISSING');
$fusion=(string)file_get_contents($root.'/libs/JVResidentEventFusion.php');
$journey=(string)file_get_contents($root.'/libs/JVResidentJourneyEvidence.php');
$routes=(string)file_get_contents($root.'/libs/JVRouteEpisodeLedger.php');
if(!str_contains($fusion,"'eventHistory'=>$history")
    ||!str_contains($fusion,"'lastGeoSourceVerified'=>$geoValid")
    ||!str_contains($fusion,"'logInterrupted'=>$logInterrupted")
    ||!str_contains($journey,"$" . "p['eventHistory']")
    ||!str_contains($routes,'P03_LOG_GAP_CORRIDOR_CONTINUITY_INTERRUPTED'))
    throw new RuntimeException('SYNCHRONIZED_EVENT_HISTORY_OR_SOURCE_REBASELINE_MISSING');
if(!str_contains($main,"'shadowIntegrityAudit'=>$integrityAudit")
    ||!str_contains($handoff,'SELBSTPRUEFUNG: ')
    ||!str_contains($handoff,'HANDY-EVENT/24h: '))
    throw new RuntimeException('INTEGRITY_AND_HISTORY_REPORT_NOT_CONNECTED');
$timeline=(string)file_get_contents($root.'/libs/JVP03IncrementalTimeline.php');
$delivery=(string)file_get_contents($root.'/libs/JVResidentEventFusion.php');
if(!str_contains($timeline,"'newlyVerifiedDirectionProofs'")
    ||!str_contains($timeline,"'newProofBatchOverflow'")
    ||!str_contains($delivery,"'unackedRouteProofs'")
    ||!str_contains($delivery,"'routeDeliveryProofs'")
    ||!str_contains($delivery,"'routeJournalExpired'"))
    throw new RuntimeException('P03_DURABLE_DELIVERY_JOURNAL_MISSING');
$commitPositions=[
    strpos($main,"$" . "this->writeShadowState('ResidentEventFusion',$" . "fusionNext)"),
    strpos($main,"$" . "this->writeShadowState('P03IncrementalTimeline',$" . "newState)"),
    strpos($main,"WriteAttributeString('PersistRouteEpisodeLedger',$" . "routeNext)"),
    strpos($main,"JVResidentEventFusion::acknowledgeRouteDelivery(")
];
if(in_array(false,$commitPositions,true)
    ||$commitPositions!==array_values(array_unique($commitPositions))
)
    throw new RuntimeException('MISSING_P03_DELIVERY_CHECKPOINTS');
for($i=1;$i<count($commitPositions);$i++)
    if($commitPositions[$i]<=$commitPositions[$i-1])
        throw new RuntimeException('P03_DELIVERY_TRANSACTION_ORDER_UNSAFE');
$wifi=(string)file_get_contents($root.'/libs/JVExistingWlanPhoneHints.php');
$commissioning=(string)file_get_contents(
    $root.'/libs/JVPresenceCommissioningGate.php');
if(!str_contains($wifi,'SOURCE_IDENTITY_COLLISION')
    ||!str_contains($fusion,"'lastMacSourceKey'")
    ||!str_contains($fusion,"'lastGeoSourceKey'")
    ||!str_contains($fusion,"'sourceRebindRecent'")
    ||!str_contains($main,"'presenceCommissioningGate'=>$commissioningGate")
    ||!str_contains($handoff,'MEILENSTEIN QUELLENQUALIFIKATION')
    ||!str_contains($commissioning,'canClaimPersonDrivingCar'))
    throw new RuntimeException('VERIFIED_SOURCE_LINEAGE_OR_COMMISSIONING_GATE_MISSING');
$departure=(string)file_get_contents(
    $root.'/libs/JVResidentDepartureInference.php');
$arrival=(string)file_get_contents(
    $root.'/libs/JVResidentArrivalInference.php');
if(!str_contains($routes,'ambiguousOutstanding')
    ||!str_contains($routes,'UNRESOLVED_MULTIPERSON_CROSSINGS_QUARANTINED')
    ||!str_contains($journey,'macSourceContinuous')
    ||!str_contains($journey,'lastSourceRebindAt')
    ||!str_contains($departure,'PHONE_SOURCE_SEQUENCE_INTERRUPTED')
    ||!str_contains($arrival,'lastSourceRebindAt')
    ||!str_contains($handoff,'MEHRPERSONEN-KONFLIKT: ')
    ||!str_contains($main,'JVShadowIntegrityAudit::evaluate('))
    throw new RuntimeException('MULTIPERSON_RECOVERY_OR_PERSONAL_SOURCE_GUARDS_MISSING');
$preflight=(string)file_get_contents(
    $root.'/libs/JVShadowUpgradePreflight.php');
$safety=(string)file_get_contents(
    $root.'/libs/JVPresenceSafetyCase.php');
$budget=(string)file_get_contents(
    $root.'/libs/JVShadowReportBudget.php');
if(!str_contains($main,'JVShadowUpgradePreflight::evaluate(')
    ||!str_contains($main,'JVPresenceSafetyCase::evaluate(')
    ||!str_contains($main,"'shadowUpgradePreflight'=>$upgradePreflight")
    ||!str_contains($main,"'presenceSafetyCase'=>$presenceSafetyCase")
    ||!str_contains($main,'SHADOW_UPGRADE_READ_ONLY_BLOCK')
    ||!str_contains($main,"$" . "this->SetStatus(201);")
    ||!str_contains($handoff,'JVShadowReportBudget::fit(')
    ||!str_contains($handoff,'ENDPRUEFUNG AUTO/FUSSWEG:')
    ||!str_contains($handoff,'UPGRADE-SPEICHERPRUEFUNG:')
    ||!str_contains($preflight,'BLOCKED_PRESERVE_CRITICAL_STATE')
    ||!str_contains($safety,"'propertyEmpty'=>'UNKNOWN'")
    ||!str_contains($budget,'ABWESENHEIT-AKTIONEN / ALARMFREIGABE:'))
    throw new RuntimeException('UPGRADE_OR_SAFETY_CASE_NOT_FAIL_CLOSED_OR_REPORT_NOT_BOUNDED');
$fieldComparator=(string)file_get_contents(
    $root.'/libs/JVFieldObservationComparator.php');
if(!str_contains($fieldComparator,'USER_REPORTED_FIELD_OBSERVATION')
    ||!str_contains($fieldComparator,'houseExitVerifiedBySensors')
    ||!str_contains($handoff,'P03-UEBERGANG/24h:')
    ||!str_contains($handoff,'HANDY-EVENT/24h:'))
    throw new RuntimeException('FIELD_OBSERVATION_COMPARISON_OR_TIMESTAMP_DIAGNOSTIC_MISSING');
$comparator=(string)file_get_contents(
    $root.'/libs/JVFieldObservationComparator.php');
if(!str_contains($main,"'snapshotAt'=>time()")
    ||!str_contains($comparator,'REFERENCE_OLDER_THAN_VISIBLE_15')
    ||!str_contains($comparator,'BASELINE_ARCHIVE_NOT_LIVE_EVIDENCE')
    ||!str_contains($comparator,'SNAPSHOT_TOO_OLD_FOR_REFERENCE')
    ||!str_contains($comparator,'sourceSnapshotAt')
    ||!str_contains($comparator,'sourceSnapshotFreshness'))
    throw new RuntimeException('FIELD_COMPARISON_PROVENANCE_GUARDS_MISSING');
$historyDigest=(string)file_get_contents(
    $root.'/libs/JVP03HistoricalWindowDigest.php');
if(!str_contains($timeline,"'verifiedDirectionTimeline24h'")
    ||!str_contains($timeline,"'verifiedTimelineTruncated'")
    ||!str_contains($fieldComparator,'p03FullPersistedHistoryUsed')
    ||!str_contains($fieldComparator,'FULL_PERSISTED_TIMELINE_24H')
    ||!str_contains($fieldComparator,'REFERENCE_OLDER_THAN_PERSISTED_256')
    ||!str_contains($historyDigest,'P03_HISTORY_BUCKETS_ANONYMOUS_ONLY')
    ||!str_contains($handoff,'JVP03HistoricalWindowDigest::summarize(')
    ||!str_contains($handoff,'P03-5MIN/24h:')
    ||!str_contains($budget,'P03-5MIN/24h:'))
    throw new RuntimeException('FIELD_P03_24H_HISTORY_COMPARISON_OR_REPORT_MISSING');
$identifiers=[];
if(preg_match_all("/RegisterVariable(?:String|Boolean|Integer|Float)\\('([^']+)'/",
    $main,$m))$identifiers=$m[1];
sort($identifiers);
$expected=['CarinaPresence','EvidenceJSON','JohannesPresence',
    'PresenceDetected','ResidentTile','Status','TestReport'];
sort($expected);
if($identifiers!==$expected)
    throw new RuntimeException('SYSCON_VARIABLE_BUDGET_REGRESSION: '.
        implode(',',$identifiers));
echo "SHADOW v0.8.82 release contracts PASS (7 variables, serialized Refresh, durable events)\n";
