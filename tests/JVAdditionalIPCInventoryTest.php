<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVAdditionalIPCInventory.php';
$r=JVAdditionalIPCInventory::evaluate([
 ['instanceId'=>10,'name'=>'Dahua IPC-HFW5442E-ZE JV rechts','parent'=>'Cameras'],
 ['instanceId'=>11,'name'=>'ONVIF Werkstatt','parent'=>'Dahua'],
 ['instanceId'=>12,'name'=>'LCN Beleuchtung','parent'=>'LCN'],
 ['instanceId'=>13,'name'=>'TiOC Terrasse','parent'=>'Cameras'],
 ['instanceId'=>14,'name'=>'IP Kamera Hof','parent'=>'Cameras']
],false);
function chk(bool $ok,string $what):void{if(!$ok)throw new RuntimeException($what);}
chk($r['candidateCount']===4,'camera-only names');
chk($r['listedCandidates'][0]['modelFromName']==='IPC-HFW5442E-ZE','model from instance name only');
chk($r['listedCandidates'][0]['actualModelVerified']===false,'name not hardware proof');
chk($r['listedCandidates'][0]['mayBePrioritizedForCounting']===false,'do not auto-prioritize extra camera');
chk($r['noCameraNetworkAccess']===true,'no camera contact');
chk($r['noDevicesOrSourcesChanged']===true,'no modifications');
chk($r['propertyEmptyMayBeCertified']===false,'no absence');
echo "Passive additional IPC inventory PASS\n";
