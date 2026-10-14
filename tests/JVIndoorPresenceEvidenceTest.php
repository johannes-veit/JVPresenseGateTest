<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVIndoorPresenceHints.php';
require_once dirname(__DIR__).'/libs/JVIndoorHintInventory.php';
require_once dirname(__DIR__).'/libs/JVGateDisplayHint.php';
require_once dirname(__DIR__).'/libs/JVLocalSensorEvidence.php';
require_once dirname(__DIR__).'/libs/JVHomeOccupancyEvidence.php';

$checks=0;
function must(bool $result,string $label):void {
    global $checks;$checks++;
    if(!$result)throw new RuntimeException($label);
}
$now=1792009000;
$rows=[
 ['Enabled'=>true,'SourceID'=>'bad_fenster','Name'=>'OG Bad Fenster',
  'Kind'=>'LIGHT_INTERIOR','Zone'=>'HOME','VariableID'=>101],
 ['Enabled'=>true,'SourceID'=>'flur_fenster','Name'=>'OG Flur Fenster',
  'Kind'=>'LIGHT_INTERIOR','Zone'=>'HOME','VariableID'=>102],
 ['Enabled'=>true,'SourceID'=>'gaeste_wc','Name'=>'EG Gäste WC',
  'Kind'=>'LIGHT_INTERIOR','Zone'=>'HOME','VariableID'=>103],
 ['Enabled'=>true,'SourceID'=>'ankleide_rgb','Name'=>'OG Ankleide RGB',
  'Kind'=>'LIGHT_INTERIOR','Zone'=>'HOME','VariableID'=>104],
 ['Enabled'=>true,'SourceID'=>'tv','Name'=>'Samsung Wohnzimmer TV',
  'Kind'=>'TV_POWER','Zone'=>'HOME','VariableID'=>105],
 ['Enabled'=>true,'SourceID'=>'terrasse','Name'=>'Außenlicht Terrasse',
  'Kind'=>'LIGHT_INTERIOR','Zone'=>'HOME','VariableID'=>106],
 ['Enabled'=>true,'SourceID'=>'garage','Name'=>'Garage Licht',
  'Kind'=>'LIGHT_INTERIOR','Zone'=>'HOME','VariableID'=>107],
 ['Enabled'=>true,'SourceID'=>'werkstatt','Name'=>'Werkstatt Lampe',
  'Kind'=>'LIGHT_INTERIOR','Zone'=>'WORK','VariableID'=>108]
];
$valid=JVLocalSensorEvidence::validate($rows);
must($valid['errors']===[],'outside hints excluded without corrupting GUS config');
must(count($valid['sources'])===5,'only five safe HOME signals remain');
$snapshots=[
 101=>['type'=>1,'value'=>75,'changedAt'=>$now-60],
 102=>['type'=>0,'value'=>false,'changedAt'=>$now-60],
 103=>['type'=>0,'value'=>true,'changedAt'=>$now-25],
 104=>['type'=>1,'value'=>0,'changedAt'=>$now-200],
 105=>['type'=>0,'value'=>true,'changedAt'=>0],
];
$local=JVLocalSensorEvidence::evaluate($rows,$snapshots,[], $now)['report'];
must($local['sourceCount']===5,'old motion sources unaffected by optional device sensors');
must($local['readings']['tv']['state']==='ACTIVE','TV with no last-change timestamp still ON');
must($local['readings']['tv']['changedAt']===null,'missing change time is not a fresh activity');
$hint=JVIndoorPresenceHints::evaluate($local['readings'],$now);
must($hint['sourceCount']===5 && $hint['readableCount']===5,'five device status signals');
must($hint['onCount']===3,'two lights and TV ON');
must(count($hint['lightsOn'])===2 && count($hint['tvOn'])===1,'TV separate from lights');
must($hint['hintLevel']==='WEAK_INDOOR_DEVICE_ON','no occupancy assertion');
must($hint['propertyEmptyMayBeCertified']===false,'no absence from devices');
must($hint['motionControlledLightsAreNotIndependentGUS']===true,
    'multiple switched lights not separate human witnesses');
must($hint['sources']['tv']['isRecentlySwitchedOn']===false,
    'untimed ON status must not become recently switched');
$off=$local['readings'];
foreach($off as &$r)$r['state']='INACTIVE';
unset($r);
$zero=JVIndoorPresenceHints::evaluate($off,$now);
must($zero['hintLevel']==='NONE'&&$zero['propertyEmptyMayBeCertified']===false,
    'all lights and TV OFF never certify empty house');
$outside=JVIndoorPresenceHints::evaluate([
 'terrasse'=>['name'=>'Hoflicht Terrasse','kind'=>'LIGHT_INTERIOR',
             'zone'=>'HOME','state'=>'ACTIVE','changedAt'=>$now-1]
],$now);
must($outside['onCount']===0,'outside ON must not be counted');
must(in_array('EXTERIOR_SOURCE_REJECTED:terrasse',$outside['warnings'],true),
    'outside rejection reported in pure evaluator');
// Provide 15 healthy GUS but no motion and no P03 proof.
$homeReadings=$local['readings'];
for($i=0;$i<15;$i++){
 $homeReadings['gus_'.$i]=['name'=>'GUS '.$i,'zone'=>'HOME','kind'=>'MOTION',
      'state'=>'INACTIVE','changedAt'=>$now-10000,'isFreshMotion'=>false];
}
$localGus=[
 'schema'=>1,'authority'=>'LOCAL_SENSOR_HINTS_ONLY','baselineInitialized'=>true,
 'readings'=>$homeReadings,'warnings'=>[]
];
$activity=[
 'schema'=>1,'authority'=>'MOTION_EDGE_HISTORY_ONLY','startedAt'=>$now-4000,
 'recentMotionEdges'=>[],'perSource'=>[],'warnings'=>[]
];
$p03=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
      'recentDirectionProofs'=>[],'warnings'=>[]];
$house=JVHomeOccupancyEvidence::evaluate($localGus,$activity,$p03,$now,$hint);
must($house['houseStatus']==='HOME_DEVICE_ON_HINT',
    'device ON becomes weak hint after stronger GUS/P03 evidence exhausted');
must($house['indoorDevicesOn']===3,'device count visible');
must($house['personPresenceMayBeChanged']===false
    && $house['propertyEmptyMayBeCertified']===false
    && $house['alarmMayBeControlled']===false,
    'device hints cannot change presence or alarms');
$quiet=JVHomeOccupancyEvidence::evaluate($localGus,$activity,$p03,$now,$zero);
must($quiet['houseStatus']==='HOUSE_QUIET_NO_ABSENCE_PROOF',
    'quiet plus devices OFF never means empty');
// Known installed gate display S1=ZU and S2=AUF:
$gate=['authority'=>'READ_ONLY_GATE_SIGNAL_SNAPSHOTS',
       'signals'=>[
         ['variableId'=>56808,'parent'=>'Endlage ZU',
          'name'=>'Eingangstatus 1','readState'=>'OK','currentValue'=>true],
         ['variableId'=>40678,'parent'=>'input:2',
          'name'=>'Eingangstatus 2','readState'=>'VALUE_ONLY_UNTIMED','currentValue'=>false]
       ]];
$closed=JVGateDisplayHint::evaluate($gate);
must($closed['positionHint']==='ZU','known working display closed');
must($closed['exitDirectionCertified']===false
    && $closed['propertyEmptyMayBeCertified']===false,'closed gate is not absence proof');
$gate['signals'][0]['currentValue']=false;
$gate['signals'][1]['currentValue']=true;
must(JVGateDisplayHint::evaluate($gate)['positionHint']==='AUF','open gate state');
$gate['signals'][1]['currentValue']=false;
must(JVGateDisplayHint::evaluate($gate)['positionHint']==='ZWISCHENSTELLUNG_ODER_FAHRT',
    'intermediate never guessed as travel direction');
$gate['signals'][0]['currentValue']=true;
$gate['signals'][1]['currentValue']=true;
must(JVGateDisplayHint::evaluate($gate)['positionHint']==='CONTRADICTORY_ENDSTOPS',
    'two end stops simultaneously fail safely');
$gate['signals'][0]['parent']='ChangedObject';
must(JVGateDisplayHint::evaluate($gate)['positionHint']==='UNKNOWN',
    'changed source path rejected');
// Metadata-only inventory of requested named zones, TV, others; no outside.
$scan=JVIndoorHintInventory::select([
 ['variableId'=>301,'type'=>1,'variableName'=>'Ausgang 1 Intensität',
  'parent'=>'OG Bad Fenster','grandparent'=>'LCN OG'],
 ['variableId'=>302,'type'=>0,'variableName'=>'Status',
  'parent'=>'OG Flur Fenster','grandparent'=>'LCN OG'],
 ['variableId'=>303,'type'=>0,'variableName'=>'Status',
  'parent'=>'EG Gäste WC','grandparent'=>'LCN EG'],
 ['variableId'=>304,'type'=>1,'variableName'=>'Intensität',
  'parent'=>'OG Ankleide RGB','grandparent'=>'LCN OG'],
 ['variableId'=>305,'type'=>0,'variableName'=>'Power',
  'parent'=>'Samsung TV','grandparent'=>'Wohnzimmer'],
 ['variableId'=>306,'type'=>0,'variableName'=>'Status',
  'parent'=>'Terrasse Außenlicht','grandparent'=>'LCN EG'],
 ['variableId'=>307,'type'=>1,'variableName'=>'Ausgang 3 Intensität',
  'parent'=>'Innenlicht Diele','grandparent'=>'LCN EG']
],false);
must($scan['candidateCount']===6,'four named lights plus TV and other indoor light');
must($scan['excludedExteriorHits']===1,'outside lamp excluded from discovery');
must($scan['listedCandidates'][0]['target']==='og_bad_fenster',
    'named room appears ahead of generic indoor sources');
must($scan['configuredSourcesChanged']===false,
    'metadata audit never automatically links or controls devices');
echo "Indoor lights, TV, existing Schiebetor display, scanner safety PASS ($checks)\n";
