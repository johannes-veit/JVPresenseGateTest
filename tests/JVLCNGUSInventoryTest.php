<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVLCNGUSInventory.php';
require_once dirname(__DIR__).'/libs/JVLocalSensorEvidence.php';
$n=0;
function check15($got,$want,string $label):void {
 global $n;$n++;
 if($got!==$want)throw new RuntimeException($label.': got '
    .var_export($got,true).', want '.var_export($want,true));
}
function inv15():array {
 $rows=[];
 foreach(JVLCNGUSInventory::KNOWN as $k) {
  $rows[]=['variableId'=>$k['id'],'unitId'=>$k['unit'],
    'name'=>$k['name'],'moduleGUID'=>JVLCNGUSInventory::LCN_UNIT_GUID,
    'variableName'=>'Status','variableType'=>0];
 }
 return $rows;
}
$inventory=inv15();
$old=[];
foreach(array_slice(JVLCNGUSInventory::KNOWN,0,6) as $k) {
 $old[]=['Enabled'=>false,'SourceID'=>$k['source'],
   'Name'=>'Alt: '.$k['name'],'Kind'=>$k['kind'],
   'Zone'=>'HOME','VariableID'=>$k['id']];
}
$proposal=JVLCNGUSInventory::propose($inventory,$old);
check15($proposal['ok'],true,'15/15 live-verified source import passes');
check15($proposal['count'],15,'exactly 15 imported');
check15(count($proposal['rows']),15,'no duplicate six historic rows');
check15(count(array_filter($proposal['rows'],fn($r)=>$r['Enabled']===true)),15,
    'one click marks 15 GUS for read-only monitoring');
check15($proposal['unchanged'],false,'historic six are updated');
$kitchen=array_values(array_filter($proposal['rows'],
    fn($r)=>$r['VariableID']===51040))[0];
check15($kitchen['Kind'],'MOTION_KITCHEN','real kitchen GUS correctly classified');
check15($kitchen['SourceID'],'eg_wohnen_kueche','kitchen stable source');
check15($kitchen['Zone'],'HOME','kitchen belongs to house, not work');
$kamin=array_values(array_filter($proposal['rows'],
    fn($r)=>$r['VariableID']===42452))[0];
check15($kamin['Kind'],'MOTION_KAMIN','clock-filter exception retained');
check15(JVLocalSensorEvidence::validate($proposal['rows'])['errors'],[],
    'all configured GUS accepted by local evidence engine');
$again=JVLCNGUSInventory::propose($inventory,$proposal['rows']);
check15($again['unchanged'],true,'one-click re-import idempotent');
check15($again['rows'],$proposal['rows'],'second import makes no config changes');

$custom=$old;
$custom[]=['Enabled'=>true,'SourceID'=>'garten_tor','Name'=>'Gartentor Status',
    'Kind'=>'GATE','Zone'=>'PROPERTY_OTHER','VariableID'=>55555];
$custom[]=['Enabled'=>false,'SourceID'=>'licht_og','Name'=>'OG Flur Licht',
    'Kind'=>'LIGHT','Zone'=>'HOME','VariableID'=>44444];
$res=JVLCNGUSInventory::propose($inventory,$custom);
check15($res['count'],15,'15 GUS plus custom hints');
check15(count($res['rows']),17,'user added light and gate sources not erased');
check15($res['rows'][15],$custom[6],'existing enabled gate preserved exactly');
check15($res['rows'][16],$custom[7],'disabled light remains disabled');
check15(JVLocalSensorEvidence::validate($res['rows'])['errors'],[],
    'merged 15 GUS plus optional gate typed safely');

foreach(JVLCNGUSInventory::KNOWN as $idx=>$k){
 $bad=$inventory;
 $bad[$idx]['variableType']=1;
 $r=JVLCNGUSInventory::propose($bad,$old);
 check15($r['ok'],false,'GUS #'.$k['id'].' type mismatch blocks ALL import');
 check15($r['count'],0,'GUS #'.$k['id'].' partial import forbidden');
}
$bad=$inventory;
$bad[6]['unitId']=99999;
check15(JVLCNGUSInventory::propose($bad,$old)['ok'],false,
   'kitchen reassigned to wrong LCN module is rejected');
$bad=$inventory;
$bad[6]['name']='Bewegungsmelder OG Ankleide';
check15(JVLCNGUSInventory::propose($bad,$old)['ok'],false,
   'wrong parent name cannot become kitchen source');
$bad=$inventory;
$bad[6]['moduleGUID']='{00000000-0000-0000-0000-000000000000}';
check15(JVLCNGUSInventory::propose($bad,$old)['ok'],false,
   'foreign camera or module GUID blocked');
$bad=$inventory;
$bad[6]['variableName']='Alarm';
check15(JVLCNGUSInventory::propose($bad,$old)['ok'],false,
   'sensor output must actually be named Status');
$bad=$inventory;
array_pop($bad);
check15(JVLCNGUSInventory::propose($bad,$old)['ok'],false,
   'incomplete inventory blocks entire batch');
$bad=$inventory;
$bad[]=$bad[0];
check15(JVLCNGUSInventory::propose($bad,$old)['ok'],false,
   'duplicate live GUS ID blocks import');
$conflict=$old;
$conflict[0]['VariableID']=51040;
check15(JVLCNGUSInventory::propose($inventory,$conflict)['ok'],false,
   'user changed historic GUS binding to another known ID – abort');
$conflict=$old;
$conflict[0]['SourceID']='alter_kamin';
check15(JVLCNGUSInventory::propose($inventory,$conflict)['ok'],false,
   'same physical GUS under wrong source key – abort');
$conflict=$old;
$conflict[]=['Enabled'=>true,'SourceID'=>'eg_wohnen_kueche',
 'Name'=>'FAKE','Kind'=>'MOTION','Zone'=>'HOME','VariableID'=>999999];
check15(JVLCNGUSInventory::propose($inventory,$conflict)['ok'],false,
   'custom entry squatting on kitchen key – abort');
$broken=[['Enabled'=>true,'VariableID'=>'51040','SourceID'=>'eg_wohnen_kueche']];
check15(JVLCNGUSInventory::propose($inventory,$broken)['ok'],false,
   'string-typed Symcon ID rejected');

$stamp=(new DateTimeImmutable('2026-10-13 19:03:00',
    new DateTimeZone('Europe/Berlin')))->getTimestamp();
$baseline=[];
foreach($proposal['rows'] as $r) {
 $baseline[$r['VariableID']]=[
   'type'=>0,'value'=>false,'changedAt'=>$stamp-10
 ];
}
$state=JVLocalSensorEvidence::evaluate($proposal['rows'],$baseline,[],$stamp);
check15($state['report']['sourceCount'],15,'15 configured GUS seen');
check15($state['report']['motionSignals'],[],
  'warm-up of 15 sensors never invents anyone');
$baseState=$state['next'];
$baseline[51040]['value']=true;
$baseline[51040]['changedAt']=$stamp+4;
$next=JVLocalSensorEvidence::evaluate($proposal['rows'],$baseline,$baseState,$stamp+4);
check15($next['report']['zones']['HOME']['activity'],'SINGLE_MOTION_HINT',
    'real kitchen positive edge is a hint only');
check15($next['report']['personPresenceMayBeChanged'],false,
    'kitchen GUS cannot identify any person');
check15($next['report']['propertyEmptyMayBeCertified'],false,
    '15 inactive GUS cannot certify away');
check15($next['report']['readings']['eg_wohnen_kueche']['state'],'ACTIVE',
    'kitchen snapshot observable');
check15($next['report']['sourceCount'],15,
    'all 15 real GUS remain included after update');

echo "Verified 15 real LCN GUS batch import PASS ($n assertions)\n";
