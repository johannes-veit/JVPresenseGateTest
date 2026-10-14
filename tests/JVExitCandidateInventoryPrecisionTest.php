<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVExitCandidateInventory.php';

$assertions=0;
function eqExit(mixed $got,mixed $expected,string $message):void {
    global $assertions; $assertions++;
    if($got!==$expected)throw new RuntimeException($message
        .' expected '.var_export($expected,true)
        .' got '.var_export($got,true));
}
function rowExit(int $id,string $name,string $parent,string $grand,
    int $type=0):array {
    return ['variableId'=>$id,'variableName'=>$name,
        'parent'=>$parent,'grandparent'=>$grand,'type'=>$type];
}
// Field-derived regression examples from the 14.10.2026 0.8.25 readout.
// The room controller *name* includes "Tür", but the actual variable is a
// light output, GUS, temperature or unrelated device: never an exit sensor.
$items=[
    rowExit(10870,'Status','Ausgang (4)','OG Schlafen1 Tür (000,111)'),
    rowExit(11674,'Intensität','Ausgang (3)','OG Bad Tür (000,141)',1),
    rowExit(11701,'Status','Bewegungsmelder EG Schlafen','EG Schlafen Tür (000,041)'),
    rowExit(18533,'Zieltemperatur S1','LCN Variable Soll-Temperatur','EG Schlafen Tür (000,041)'),
    rowExit(27137,'Status','Treppe Haustür LED','Licht Außen'),
    rowExit(31296,'Status','Hof Einfahrt','Licht Außen'),
    rowExit(15863,'Test bereit','Presence Gate Test (Schiebetor)','Abwesenheiterkennung'),
    rowExit(27549,'Status','Befehl AUF','Schiebetor_Logik'),
    rowExit(30180,'Status 1','Befehl ZU','Schiebetor_Logik'),
    rowExit(18612,'Erreichbar','Endlage AUF','Schiebetor_Logik'),
    rowExit(42826,'Erreichbar','Endlage ZU','Schiebetor_Logik'),
    rowExit(34080,'Erreichbar','Befehl AUF','Schiebetor_Logik'),
    rowExit(16679,'Cloud Status','shellypro3-8c4f008eeb14','Schiebetor_Logik'),
    rowExit(25498,'Nachtfreigabe','JV Hof Garage','Dahua Kameras'),
    rowExit(40678,'Eingangstatus 2','input:2','Schiebetor_Logik'),
    rowExit(13257,'Status 1','shellypro3-8c4f008eeb14','Schiebetor_Logik'),
    rowExit(20724,'Status 2','shellypro3-8c4f008eeb14','Schiebetor_Logik'),
    rowExit(31597,'Status','shellypro3-8c4f008eeb14','Schiebetor_Logik'),
    rowExit(25000,'Status','Endlage AUF','Schiebetor_Logik'),
    rowExit(42777,'Status','Endlage ZU','Schiebetor_Logik'),
    rowExit(40679,'Kontakt','Haustür','Haus'),
    rowExit(44000,'Position','Garagentor','Hof',1),
    rowExit(46000,'Status','Fahrzeug Einfahrt','Hof'),
    rowExit(46001,'Status','WLAN Johannes','Identity Shadow'),
    rowExit(47000,'Status','Bewegungsmelder OG Schlafen1','OG Schlafen1 Tür (000,111)'),
    rowExit(48000,'Status','Ausgang (1)','EG Bad Tür (000,051)'),
];
$out=JVExitCandidateInventory::select($items);
eqExit($out['candidateCount'],9,'only plausible sensor status names selected');
eqExit(array_column($out['listedCandidates'],'variableId'),[
    25000,40678,40679,42777,44000,46000,13257,20724,31597
], 'prioritizes physical input/endstop/contact over generic Shelly outputs');
$byId=[];
foreach($out['listedCandidates'] as $v)$byId[$v['variableId']]=$v;
eqExit($byId[40678]['reviewPriority'],'HIGH','input:2 status first review');
eqExit($byId[40678]['candidateBasis'],'INPUT_STATUS_NAME_ONLY',
    'input status remains name-only, not certified');
eqExit($byId[25000]['candidateBasis'],'ENDSTOP_STATUS_NAME_ONLY',
    'actual endstop STATUS candidate differs from Erreichbar');
eqExit($byId[13257]['reviewPriority'],'CHECK_FUNCTION',
    'Shelly generic output must not be described as verified contact');
eqExit($byId[40679]['signalKindHint'],'CONTACT',
    'existing real door contact name recognized');
eqExit($byId[46000]['signalKindHint'],'VEHICLE',
    'vehicle status is a hint, no driver identified');
eqExit($out['excludedBroadMatches']>0,true,
    'broad false positives explicitly counted');
eqExit($out['configurationChanged'],false,'does not modify sources');
eqExit($out['devicesChanged'],false,'does not control devices');
eqExit($out['personPresenceMayBeChanged'],false,'cannot publish persons');
eqExit($out['propertyEmptyMayBeCertified'],false,'cannot certify absence');
foreach($out['listedCandidates'] as $row) {
    eqExit($row['hintOnly'],true,'all candidates tentative');
    eqExit($row['directionVerified'],false,'cannot infer human direction');
    eqExit($row['polarityVerified'],false,'cannot infer contact polarity');
}

// Even after 80 low-priority Shelly hits, a later/greater-ID real input
// cannot disappear merely because the output is bounded.
$many=[];
for($i=0;$i<100;$i++)
    $many[]=rowExit(1000+$i,'Status 1','shellypro3-8c4f008eeb14',
        'Schiebetor_Logik');
$many[]=rowExit(99999,'Eingangstatus 2','input:2','Schiebetor_Logik');
$truncated=JVExitCandidateInventory::select($many,true);
eqExit($truncated['candidateCount'],101,'all plausible rows counted');
eqExit(count($truncated['listedCandidates']),80,'80-row JSON output cap');
eqExit($truncated['listedCandidates'][0]['variableId'],99999,
    'high-priority boundary signal not lost behind lower IDs');
eqExit($truncated['candidateListLimited'],true,'truncation explicit');
eqExit($truncated['symconScanLimited'],true,'source scan cap explicit');
echo "Exit candidate precision and ranking PASS ($assertions assertions)\n";
