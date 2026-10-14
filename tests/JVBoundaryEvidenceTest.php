<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVBoundaryEvidence.php';
require_once dirname(__DIR__).'/libs/JVExitCandidateInventory.php';

$checks=0;
function sameB(mixed $got,mixed $want,string $msg):void {
    global $checks; $checks++;
    if ($got!==$want) throw new RuntimeException(
        $msg.' expected '.var_export($want,true).' actual '.var_export($got,true)
    );
}
$now=1792000000;
$p03=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
      'recentDirectionProofs'=>[],'warnings'=>[]];
$sources=[];
for($i=1;$i<=15;$i++) {
    $sources['gus'.$i]=['kind'=>'MOTION','zone'=>'HOME','state'=>'INACTIVE',
        'changedAt'=>$now-60,'name'=>'Haus GUS '.$i];
}
$base=JVBoundaryEvidence::evaluate($sources,[],$p03,$now);
sameB($base['report']['coverageState'],'NO_EXIT_SENSORS',
      '15 GUS do not claim property exits monitored');
sameB($base['report']['doorContacts'],0,'zero door contact inputs');
sameB($base['report']['gateStatuses'],0,'zero gate signals');
sameB($base['report']['configuredBoundarySignals'],0,
      'P03 house-transfer and house GUS are not perimeter inputs');
sameB($base['report']['allExitRoutesVerified'],false,'no full property coverage');
sameB($base['report']['propertyEmptyMayBeCertified'],false,
      'quiet house does not prove property empty');
sameB($base['report']['alarmMayBeControlled'],false,
      'no alarm switching');
sameB($base['report']['personPresenceMayBeChanged'],false,
      'no presence output writes');
sameB($base['report']['boundaryChanges24h'],0,
      'startup does not manufacture opening');

$p03['recentDirectionProofs']=[
    ['at'=>$now-9,'direction'=>'HOME_TO_LAGER']
];
$homeWork=JVBoundaryEvidence::evaluate($sources,$base['next'],$p03,$now+1);
sameB($homeWork['report']['p03DirectionContext']['countsAsPropertyExit'],false,
      'P03 HOME-to-WORK not property exit');
sameB($homeWork['report']['assessment'],'NO_PROPERTY_EXIT_PROOF',
      'P03 proof cannot replace exit sensor');

$sources['front_door']=['kind'=>'CONTACT','zone'=>'HOME',
    'state'=>'ACTIVE','changedAt'=>$now-2,'name'=>'Haustür Kontakt'];
$sources['gate']=['kind'=>'GATE','zone'=>'PROPERTY_OTHER',
    'state'=>'INACTIVE','changedAt'=>$now-3,'name'=>'Garagentor Status'];
$sources['car']=['kind'=>'VEHICLE','zone'=>'PROPERTY_OTHER',
    'state'=>'ACTIVE','changedAt'=>$now-7,'name'=>'Auto Hof'];
$attach=JVBoundaryEvidence::evaluate($sources,$homeWork['next'],$p03,$now+2);
sameB($attach['report']['configuredBoundarySignals'],3,'three optional sources');
sameB($attach['report']['doorContacts'],1,'one contact');
sameB($attach['report']['gateStatuses'],1,'one gate');
sameB($attach['report']['vehicleSignals'],1,'one vehicle');
sameB($attach['report']['coverageState'],'PARTIAL_UNVERIFIED',
      'three mapped sources do not equal complete property coverage');
sameB($attach['report']['boundaryChanges24h'],0,
      'new source with currently ACTIVE contact is only baselined');
sameB($attach['report']['currentStates']['front_door']['state'],'ACTIVE',
      'do not assume ACTIVE means door OPEN');
sameB($attach['report']['exitDirectionCertified'],false,
      'Boolean contact does not tell entry versus exit');

$sources['front_door']['state']='INACTIVE';
$sources['front_door']['changedAt']=$now+4;
$door=JVBoundaryEvidence::evaluate($sources,$attach['next'],$p03,$now+4);
sameB($door['report']['boundaryChanges24h'],1,'first real contact state edge');
sameB($door['report']['recentChanges'][0]['state'],'INACTIVE',
      'falling status counted as status change');
sameB($door['report']['recentChanges'][0]['direction'],'UNKNOWN',
      'door close/open direction unknown');
sameB($door['report']['assessment'],'BOUNDARY_ACTIVITY_DIRECTION_UNKNOWN',
      'a contact edge is context, not a departure');
sameB($door['report']['propertyEmptyMayBeCertified'],false,
      'door state edge cannot prove empty');

$again=JVBoundaryEvidence::evaluate($sources,$door['next'],$p03,$now+5);
sameB($again['report']['boundaryChanges24h'],1,
      'repeated 60-second read does not double count');
sameB($again['next']['events'],$door['next']['events'],
      'stable baseline buffer after polling');
$sources['gate']['state']='ACTIVE';
$sources['gate']['changedAt']=$now+6;
$gate=JVBoundaryEvidence::evaluate($sources,$again['next'],$p03,$now+6);
sameB($gate['report']['boundaryChanges24h'],2,
      'garage gate status adds one contextual edge');
sameB($gate['report']['recentChanges'][0]['kind'],'GATE',
      'latest gate state shown in chronology');
sameB($gate['report']['recentChanges'][0]['personCount'],'UNKNOWN',
      'gate can be moved without any person');
sameB($gate['report']['allExitRoutesVerified'],false,
      'garage action is not complete route surveillance');

$sources['car']['state']='INACTIVE';
$sources['car']['changedAt']=$now+7;
$car=JVBoundaryEvidence::evaluate($sources,$gate['next'],$p03,$now+7);
sameB($car['report']['boundaryChanges24h'],3,
      'vehicle signal change not overcounted');
sameB($car['report']['identityCertified'],false,
      'car leaving does not identify driver');
sameB($car['report']['peopleCountCertified'],false,
      'car cannot certify number of persons');
sameB($car['report']['propertyEmptyMayBeCertified'],false,
      'door + gate + car + P03 still not enough to certify empty');
sameB($car['report']['alarmMayBeControlled'],false,
      'no production alarm action allowed');
sameB($car['report']['unresolved'][0],'EXIT_ROUTE_COVERAGE_UNKNOWN',
      'unseen possible routes acknowledged');

$stale=$sources;
$stale['gate']['state']='INACTIVE';
$stale['gate']['changedAt']=$now-10000;
$staleResult=JVBoundaryEvidence::evaluate($stale,$car['next'],$p03,$now+8);
sameB($staleResult['report']['boundaryChanges24h'],3,
      'an old timestamp is not a new gate event');

$added=$sources;
$added['rear_door']=['kind'=>'CONTACT','zone'=>'HOME',
    'state'=>'ACTIVE','changedAt'=>$now+9,'name'=>'Nebentür'];
$extra=JVBoundaryEvidence::evaluate($added,$car['next'],$p03,$now+10);
sameB($extra['report']['doorContacts'],2,'new source seen');
sameB($extra['report']['boundaryChanges24h'],3,
      'new active source is baseline only, no fake person');
$future=$sources;
$future['front_door']['changedAt']=$now+100000;
$invalid=JVBoundaryEvidence::evaluate($future,$car['next'],$p03,$now+9);
sameB($invalid['report']['currentStates']['front_door']['state'],'UNKNOWN',
      'future state metadata rejected');
sameB(in_array('INVALID_BOUNDARY_SOURCE:front_door',
    $invalid['report']['warnings'],true),true,
    'unknown contact state flagged');
sameB($invalid['report']['propertyEmptyMayBeCertified'],false,
      'invalid future state never certifies property empty');

$expired=JVBoundaryEvidence::evaluate($sources,$car['next'],$p03,
    $now+86410);
sameB($expired['report']['boundaryChanges24h'],0,
      'older than 24 hours contacts removed');
sameB($expired['report']['propertyEmptyMayBeCertified'],false,
      'all gates quiet for 24h still not empty');

$list=JVExitCandidateInventory::select([
 ['variableId'=>100,'type'=>0,'variableName'=>'Status',
    'parent'=>'Garagentor','grandparent'=>'EG Garage'],
 ['variableId'=>101,'type'=>0,'variableName'=>'Kontakt',
    'parent'=>'Haustür','grandparent'=>'Eingang'],
 ['variableId'=>102,'type'=>1,'variableName'=>'Status',
    'parent'=>'Fahrzeug Einfahrt','grandparent'=>'Hof'],
 ['variableId'=>103,'type'=>0,'variableName'=>'Status',
    'parent'=>'Temperatur Wohnzimmer','grandparent'=>'EG'],
 ['variableId'=>104,'type'=>0,'variableName'=>'WLAN erreichbar',
    'parent'=>'WLAN Johannes','grandparent'=>'Identity Shadow'],
 ['variableId'=>101,'type'=>0,'variableName'=>'Kontakt',
    'parent'=>'Haustür','grandparent'=>'Eingang'],
 ['variableId'=>105,'type'=>3,'variableName'=>'Garagentor',
    'parent'=>'Garage','grandparent'=>'EG']
]);
sameB($list['candidateCount'],3,
      'metadata scan finds garage door car but not GUS WLAN strings');
sameB(array_column($list['listedCandidates'],'variableId'),[101,100,102],
      'higher priority door contact before generic status, no duplicates');
sameB($list['configurationChanged'],false,'candidate scan never saves sources');
sameB($list['devicesChanged'],false,'candidate scan never switches devices');
sameB($list['candidatesAreNotVerifiedExitRoutes'],true,
      'names cannot certify exits');
sameB($list['listedCandidates'][0]['directionVerified'],false,
      'garage name cannot certify entry versus exit');
$huge=[];
for($i=1;$i<=100;$i++)$huge[]=[
    'variableId'=>$i,'type'=>0,'variableName'=>'Status',
    'parent'=>'Garagentor '.$i,'grandparent'=>'Hof'
];
$limit=JVExitCandidateInventory::select($huge,true);
sameB($limit['candidateCount'],100,'counts all within candidate scan');
sameB(count($limit['listedCandidates']),80,
      'limit JSON output to 80 candidates');
sameB($limit['candidateListLimited'],true,
      'candidate output truncation explicit');
sameB($limit['symconScanLimited'],true,
      'partial Symcon scan explicitly reported');
echo "Read-only boundary/door/gate/vehicle diagnostics PASS ($checks assertions)\n";
