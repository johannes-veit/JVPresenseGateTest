<?php
declare(strict_types=1);
/**
 * Release gate: no misleading version or forgotten PHP import during a major
 * SHADOW upgrade, and exactly the expected seven display variables.
 */
$root=dirname(__DIR__);
$meta=json_decode((string)file_get_contents($root.'/library.json'),true);
if(!is_array($meta)||($meta['version']??null)!=='0.8.70'
    ||($meta['build']??null)!==151)
    throw new RuntimeException('WRONG_RELEASE_META');
$handoff=(string)file_get_contents($root.'/libs/JVResidentEveningHandoff.php');
$form=json_decode((string)file_get_contents(
    $root.'/JVPresenceIdentityShadow/form.json'),true);
$changelog=(string)file_get_contents($root.'/CHANGELOG.md');
$main=(string)file_get_contents($root.'/JVPresenceIdentityShadow/module.php');
if(!str_contains($handoff,"SHADOW v0.8.70 Build 151")
    ||!str_starts_with($changelog,"# v0.8.70 (Build 151)"))
    throw new RuntimeException('MISMATCHED_REPORT_OR_CHANGELOG_VERSION');
$found=false;
foreach(($form['elements']??[]) as $item)
    if(($item['type']??null)==='Label'
        &&str_starts_with($item['caption']??'','SHADOW v0.8.70:'))$found=true;
if(!$found)throw new RuntimeException('STALE_USER_VISIBLE_HELP');
foreach([
    'JVShadowStateCheckpoint',
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
echo "SHADOW v0.8.70 release contracts PASS (7 variables, serialized Refresh, durable events)\n";
