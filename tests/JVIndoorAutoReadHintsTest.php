<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVIndoorPresenceHints.php';
require_once dirname(__DIR__).'/libs/JVIndoorAutoReadHints.php';

function indoorAutoCheck(bool $passed,string $why):void{
    if(!$passed)throw new RuntimeException('FAIL '.$why);
}
$inventory=['schema'=>1,'authority'=>'INDOOR_LIGHT_TV_CANDIDATES_METADATA_ONLY',
    'symconScanLimited'=>false,'candidateListLimited'=>false,
    'listedCandidates'=>[]];
$add=static function(int $id,string $type,string $name,
    string $parent,string $grand)use(&$inventory):void{
    $inventory['listedCandidates'][]=[
        'variableId'=>$id,'type'=>$type,'name'=>$name,
        'parent'=>$parent,'grandparent'=>$grand,
        'recommendedKind'=>'LIGHT_INTERIOR',
        'isAutomaticallyLinked'=>false,'isVerifiedStatusOutput'=>false
    ];
};
$rooms=[
    [34552,'OG Flur Fenster','Licht'],
    [39745,'OG Bad Fenster','Licht'],
    [57207,'EG Gäste WC','Licht'],
    [25793,'RGB OG Ankleide','Licht RGB'],
    [51009,'EG Küche','Licht'],
    [38635,'EG Wohnen','Licht']
];
foreach($rooms as [$id,$parent,$grand])$add($id,'Boolean','Status',$parent,$grand);
$add(20207,'Boolean','Status','Relais (2) Jal. EG Gäste WC AUF','EG UV Kamin FI2');
$add(28959,'Integer','Status','EG Gäste WC','Fenster');
$add(18205,'Integer','Helligkeit','OG Bad Fenster','Licht');
$add(18058,'Boolean','Status','Ausgang (4)','EG Gäste WC (000,061)');
$add(34423,'Integer','Status','Panik','Licht');
$add(40001,'Boolean','Status','Terrasse','Licht');
$add(40002,'Boolean','Status','RGB OG Bad','Licht');
$add(40003,'Boolean','Status','OG Bad','Licht RGB');
$selected=JVIndoorAutoReadHints::select($inventory);
indoorAutoCheck(count($selected)===6,'six safe light statuses; false positives excluded');
indoorAutoCheck(count(JVIndoorAutoReadHints::select($inventory,[57207]))===5,
    'manually configured variable not counted twice');
$now=time();$snapshots=[];
foreach($selected as $r)$snapshots[$r['variableId']]=[
    'valid'=>true,'value'=>false,'changedAt'=>$now-100];
$snapshots[39745]=['valid'=>true,'value'=>true,'changedAt'=>$now-10];
$out=JVIndoorAutoReadHints::evaluate($selected,$snapshots,$now);
indoorAutoCheck($out['candidateCount']===6&&$out['readableCount']===6,
    'all six available in read-only hints');
$indoor=JVIndoorPresenceHints::evaluate($out['readings'],$now);
indoorAutoCheck($indoor['sourceCount']===6&&$indoor['onCount']===1,
    'one status on; no duplicate brightness or relay channel');
indoorAutoCheck($indoor['personPresenceMayBeChanged']===false
    &&$indoor['propertyEmptyMayBeCertified']===false
    &&$indoor['alarmMayBeControlled']===false,
    'light activity never triggers people or alarm state');
$snapshots[39745]=['valid'=>false];
$rejected=JVIndoorAutoReadHints::evaluate($selected,$snapshots,$now);
indoorAutoCheck($rejected['rejectedCount']===1
    &&!isset($rejected['readings']['auto_indoor_39745']),
    'stale/reparented light silently excluded');
$inventory['symconScanLimited']=true;
indoorAutoCheck(JVIndoorAutoReadHints::select($inventory)===[],
    'partial inventory cannot be treated as full auto read');
echo "Conservative read-only indoor status candidates PASS\\n";
