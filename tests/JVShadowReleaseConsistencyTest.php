<?php
declare(strict_types=1);
/**
 * Release gate: no misleading version or forgotten PHP import during a major
 * SHADOW upgrade, and exactly the expected seven display variables.
 */
$root=dirname(__DIR__);
$meta=json_decode((string)file_get_contents($root.'/library.json'),true);
if(!is_array($meta)||($meta['version']??null)!=='0.8.72'
    ||($meta['build']??null)!==153)
    throw new RuntimeException('WRONG_RELEASE_META');
$handoff=(string)file_get_contents($root.'/libs/JVResidentEveningHandoff.php');
$form=json_decode((string)file_get_contents(
    $root.'/JVPresenceIdentityShadow/form.json'),true);
$changelog=(string)file_get_contents($root.'/CHANGELOG.md');
$main=(string)file_get_contents($root.'/JVPresenceIdentityShadow/module.php');
if(!str_contains($handoff,"SHADOW v0.8.72 Build 153")
    ||!str_starts_with($changelog,"# v0.8.72 (Build 153)"))
    throw new RuntimeException('MISMATCHED_REPORT_OR_CHANGELOG_VERSION');
$found=false;
foreach(($form['elements']??[]) as $item)
    if(($item['type']??null)==='Label'
        &&str_starts_with($item['caption']??'','SHADOW v0.8.72:'))$found=true;
if(!$found)throw new RuntimeException('STALE_USER_VISIBLE_HELP');
foreach([
    'JVShadowStateCheckpoint',
    'JVShadowIntegrityAudit',
    'JVResidentEventFusion',
    'JVRouteEpisodeLedger',
    'JVResidentJourneyEvidence',
    'JVPresenceEvidenceReadiness',
    'JVResidentArrivalInference',
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
echo "SHADOW v0.8.72 release contracts PASS (7 variables, serialized Refresh, durable events)\n";
