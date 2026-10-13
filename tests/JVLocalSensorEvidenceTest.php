<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVLocalSensorEvidence.php';

$checks=0;
function assertEq(mixed $actual,mixed $expected,string $label):void {
    global $checks;$checks++;
    if($actual!==$expected) {
        throw new RuntimeException("$label: got ".var_export($actual,true)
            .", expected ".var_export($expected,true));
    }
}
function ts(string $local):int {
    return (new DateTimeImmutable($local,new DateTimeZone('Europe/Berlin')))->getTimestamp();
}
function source(string $id,int $variable,string $kind,string $zone='HOME'):array {
    return [
        'Enabled'=>true,'SourceID'=>$id,'Name'=>ucfirst($id),
        'VariableID'=>$variable,'Kind'=>$kind,'Zone'=>$zone
    ];
}
function snap(bool|int $value,int $at):array {
    return ['type'=>is_bool($value)?0:1,'value'=>$value,'changedAt'=>$at];
}
function sample(array $sources,array $data,array $prior,int $at):array {
    return JVLocalSensorEvidence::evaluate($sources,$data,$prior,$at);
}
$initial=ts('2026-10-13 14:24:00');
$sources=[
    source('kamin',100,'MOTION_KAMIN'),
    source('kueche',101,'MOTION_KITCHEN'),
    source('flur',102,'MOTION'),
    source('licht',103,'LIGHT'),
    source('tor',104,'GATE','PROPERTY_OTHER'),
    source('fahrzeug',105,'VEHICLE','PROPERTY_OTHER')
];
$base=[
    100=>snap(true,$initial-5), // active flag at startup MUST NOT count
    101=>snap(false,$initial-5),
    102=>snap(false,$initial-5),
    103=>snap(false,$initial-2500), // historic OFF state still only a hint
    104=>snap(false,$initial-2500),
    105=>snap(false,$initial-2500)
];
$r=sample($sources,$base,[],$initial);
assertEq($r['report']['motionSignals'],[],'startup warmup does not fabricate motion');
assertEq($r['report']['baselineInitialized'],false,'startup reports baseline phase');
assertEq($r['report']['allConfiguredLightsOff'],true,'only configured lamp is OFF');
assertEq($r['report']['propertyEmptyMayBeCertified'],false,'off gates cars and lights cannot certify absence');
assertEq($r['report']['personPresenceMayBeChanged'],false,'LCN is hints only');
assertEq($r['next']['initialized'],true,'baseline activated on next read');
$nochange=sample($sources,$base,$r['next'],$initial+2);
assertEq($nochange['report']['motionSignals'],[],'active true without changedAt delta does not re-trigger');
assertEq($nochange['report']['zones']['HOME']['activity'],'UNKNOWN','no historical motion reused');
// Adding a fresh source to an already running instance must baseline that
// source; an old ON flag cannot be misread as a just-detected person.
$running=sample([],[],$nochange['next'],$initial+2);
$added=sample([source('new_motion',700,'MOTION')],
    [700=>snap(true,$initial+1)],$running['next'],$initial+2);
assertEq($added['report']['motionSignals'],[],
    'new GUS added to running observer does not invent motion');
$addedAgain=sample([source('new_motion',700,'MOTION')],
    [700=>snap(true,$initial+1)],$added['next'],$initial+3);
assertEq($addedAgain['report']['motionSignals'],[],
    'new GUS same timestamp never replays');

$base[100]=snap(false,$initial+3);
$r=sample($sources,$base,$nochange['next'],$initial+3);
assertEq($r['report']['motionSignals'],[],'falling edge ignored');
$base[100]=snap(true,$initial+4);
$r=sample($sources,$base,$r['next'],$initial+4);
assertEq($r['report']['motionSignals'][0]['status'],'KAMIN_UNCORROBORATED',
    'Kamin alone outside clock window cannot be confirmed');
assertEq($r['report']['zones']['HOME']['activity'],'SINGLE_MOTION_HINT',
    'single event only weaker hint');
$repeat=sample($sources,$base,$r['next'],$initial+5);
assertEq(count($repeat['report']['motionSignals']),1,'no repeated Kamin event on same VariableChanged');
$base[101]=snap(true,$initial+20);
$r=sample($sources,$base,$repeat['next'],$initial+20);
$signalStatus=array_column($r['report']['motionSignals'],'status','id');
assertEq($signalStatus['kamin'],'KAMIN_KITCHEN_CORROBORATED',
    'kitchen confirms Kamin within sixty seconds');
assertEq($signalStatus['kueche'],'MULTI_SENSOR_CORROBORATED',
    'kitchen corroboration recognized');
assertEq($r['report']['zones']['HOME']['activity'],'CORROBORATED_MOTION_HINT',
    'positive GUS corroboration limited to hint');
assertEq($r['report']['personPresenceMayBeChanged'],false,
    'confirmed motion still cannot name person');
assertEq($r['report']['propertyEmptyMayBeCertified'],false,
    'confirmed motion still never publishes presence');

$clock=ts('2026-10-13 14:30:00');
$clockbase=[
  100=>snap(false,$clock-3),101=>snap(false,$clock-3)
];
$mini=array_slice($sources,0,2);
$r=sample($mini,$clockbase,[],$clock);
$clockbase[100]=snap(true,$clock+1);
$r=sample($mini,$clockbase,$r['next'],$clock+1);
assertEq($r['report']['motionSignals'],[],'clock at half-hour filtered');
assertEq($r['report']['zones']['HOME']['activity'],'UNKNOWN','clock alone does not indicate person');
$clockbase[101]=snap(true,$clock+15);
$r=sample($mini,$clockbase,$r['next'],$clock+15);
assertEq(count($r['report']['motionSignals']),1,'kitchen remains a single valid hint');
assertEq($r['report']['zones']['HOME']['activity'],'SINGLE_MOTION_HINT',
    'clock-window Kamin cannot strengthen kitchen');
$edge=ts('2026-10-13 14:25:00');
$edgeBase=[100=>snap(false,$edge-20)];
$r=sample([source('kamin',100,'MOTION_KAMIN')],$edgeBase,[],$edge);
$edgeBase[100]=snap(true,$edge);
$r=sample([source('kamin',100,'MOTION_KAMIN')],$edgeBase,$r['next'],$edge);
assertEq($r['report']['motionSignals'],[],'25 minute exactly excluded');
$end=ts('2026-10-13 14:35:00');
$r=sample([source('kamin',100,'MOTION_KAMIN')],[100=>snap(false,$end-20)],[],$end);
$r=sample([source('kamin',100,'MOTION_KAMIN')],[100=>snap(true,$end)],$r['next'],$end);
assertEq($r['report']['motionSignals'],[],'35 minute exactly excluded');
$after=ts('2026-10-13 14:36:00');
$r=sample([source('kamin',100,'MOTION_KAMIN')],[100=>snap(false,$after-20)],[],$after);
$r=sample([source('kamin',100,'MOTION_KAMIN')],[100=>snap(true,$after)],$r['next'],$after);
assertEq($r['report']['motionSignals'][0]['status'],'KAMIN_UNCORROBORATED','36 minute not artificially excluded');

$t=ts('2026-10-13 15:01:00');
$two=[
    source('flur',301,'MOTION'),source('bad',302,'MOTION'),
    source('lager',303,'MOTION','WORK')
];
$base=[301=>snap(false,$t-5),302=>snap(false,$t-5),303=>snap(false,$t-5)];
$r=sample($two,$base,[],$t);
$base[301]=snap(true,$t+1);
$r=sample($two,$base,$r['next'],$t+1);
assertEq($r['report']['zones']['HOME']['activity'],'SINGLE_MOTION_HINT','one movement uncertain');
$base[303]=snap(true,$t+2);
$r=sample($two,$base,$r['next'],$t+2);
assertEq($r['report']['zones']['HOME']['activity'],'SINGLE_MOTION_HINT',
    'different zones not considered corroboration');
assertEq($r['report']['zones']['WORK']['activity'],'SINGLE_MOTION_HINT',
    'work motion independent');
$base[302]=snap(true,$t+35);
$r=sample($two,$base,$r['next'],$t+35);
assertEq($r['report']['zones']['HOME']['activity'],'CORROBORATED_MOTION_HINT',
    'two independent home GUS corroborate within 60 secs');
$base[301]=snap(false,$t+40);
$r=sample($two,$base,$r['next'],$t+40);
$base[301]=snap(true,$t+49);
$r=sample($two,$base,$r['next'],$t+49);
assertEq(count($r['report']['motionSignals']),4,'edges collected without duplicate at same stamp');
$old=sample($two,$base,$r['next'],$t+250);
assertEq($old['report']['zones']['HOME']['activity'],'UNKNOWN',
    'old motion cannot imply continuous occupancy');
assertEq($old['report']['propertyEmptyMayBeCertified'],false,
    'long quiet period still not a property vacancy certificate');
assertEq($old['report']['allConfiguredLightsOff'],false,
    'no light sources does not imply all lights off');

$invalid=[
  source('dup1',400,'LIGHT'),source('dup2',400,'MOTION')
];
$r=sample($invalid,[400=>snap(false,$t)],[],$t);
assertEq($r['report']['warnings'][0],'DUPLICATE_SOURCE_OR_VARIABLE:dup2',
    'duplicate Symcon input blocked');
assertEq($r['next']['initialized'],false,'invalid config cannot initialize');
$invalid=[source('kamin',401,'MOTION_KAMIN','WORK')];
$r=sample($invalid,[401=>snap(true,$t)],[],$t);
assertEq($r['report']['warnings'][0],'INVALID_SPECIAL_ZONE:kamin',
    'Kamin may not be mapped to wrong zone');
$r=sample([source('licht',402,'LIGHT')],[402=>['type'=>3,'value'=>'ON','changedAt'=>$t]],[],$t);
assertEq($r['report']['allConfiguredLightsOff'],false,'invalid light state not counted as OFF');
assertEq($r['report']['readings']['licht']['state'],'UNKNOWN','mismatched type yields unknown');
$r=sample([source('licht',402,'LIGHT')],[402=>snap(true,$t+600)],[],$t);
assertEq($r['report']['readings']['licht']['state'],'UNKNOWN','future-dated change invalid');
$r=sample([source('licht',402,'LIGHT')],[402=>snap(-2,$t)],[],$t);
assertEq($r['report']['readings']['licht']['state'],'UNKNOWN','negative integer state rejected');
$r=sample([source('flur',402,'MOTION')],[402=>snap(false,$t-1000)],[],$t);
$r=sample([source('flur',402,'MOTION')],[402=>snap(true,$t-500)],$r['next'],$t);
assertEq($r['report']['motionSignals'],[],'old timestamp never mistaken for new motion');
$r=sample([source('tor',402,'GATE','PROPERTY_OTHER')],[402=>snap(true,$t)],[],$t);
assertEq($r['report']['gates']['tor'],'ACTIVE','gate reports activity, not driver');
assertEq($r['report']['propertyEmptyMayBeCertified'],false,'active garage gate not proof of human');

echo "LCN/GUS existing-sensor hints PASS ($checks assertions)\n";
