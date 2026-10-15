<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVKnownIpcMetadata.php';
require_once dirname(__DIR__).'/libs/JVCameraCapabilityPriority.php';
require_once dirname(__DIR__).'/libs/JVSupplementaryIpcEvidence.php';
$checks=0;
function chkExtra(bool $ok,string $msg):void{
    global $checks;$checks++;
    if(!$ok)throw new RuntimeException($msg);
}
$r=JVKnownIpcMetadata::evaluate([
 ['instanceId'=>211,'ip'=>'192.168.107.98','label'=>'Torhalle Strasse Kamera'],
 ['instanceId'=>213,'ip'=>'192.168.107.97','label'=>'Torhalle Einfahrt'],
 ['instanceId'=>217,'ip'=>'192.168.107.96','label'=>'JV rechts','eventVariableId'=>42117],
 ['instanceId'=>219,'ip'=>'192.168.107.99','label'=>'P03 – Lagerplatz Werkstatt links'],
 ['instanceId'=>225,'ip'=>'192.168.107.94','label'=>'WORK_LEFT'],
 ['instanceId'=>229,'ip'=>'192.168.107.110','label'=>'TiOC Terrasse'],
 ['instanceId'=>231,'ip'=>'192.168.107.196','label'=>'andere noch unbekannt'],
 ['instanceId'=>232,'ip'=>'192.168.107.196','label'=>'anderer Adapter gleiche Kamera'],
 ['instanceId'=>240,'ip'=>'192.168.103.45','label'=>'nicht Kamera VLAN'],
 ['instanceId'=>241,'ip'=>'192.168.2.150','label'=>'Telefon'],
 ['instanceId'=>242,'ip'=>'192.168.107.999','label'=>'ungueltige Adresse'],
 ['instanceId'=>243,'ip'=>'abc.example','label'=>'Host Name'],
 ['instanceId'=>-1,'ip'=>'192.168.107.98','label'=>'untrusted'],
],false);
chkExtra($r['authority']==='ADDITIONAL_IPC_METADATA_ONLY','metadata-only authority');
chkExtra($r['otherIpcFoundInSymcon']===5,'only .98,.97,.96,.99,.196 configured extras');
chkExtra($r['otherIpcCount']===6,'5 historical extra IPs plus newly discovered unknown');
chkExtra($r['otherIpcCandidates'][0]['ip']==='192.168.107.98',
    'Torhalle Strasse first for potential property boundary');
chkExtra($r['otherIpcCandidates'][1]['ip']==='192.168.107.97',
    'Torhalle Einfahrt second for property boundary');
chkExtra($r['otherIpcCandidates'][2]['ip']==='192.168.107.96',
    'spare JV right suitable for review without touching current JV left');
chkExtra($r['otherIpcCandidates'][3]['ip']==='192.168.107.99',
    'spare WORK right suitable for review without touching main WORK left');
chkExtra($r['otherIpcCandidates'][3]['roleNameConflictNeedsReview']===true,
    'IP historically WORK RIGHT but configured Symcon instance named left; review before use');
chkExtra($r['otherIpcCandidates'][2]['peopleCountingSupportedByModel']===true,
    'older HFW5442 inventory is only model-conditional capability');
chkExtra($r['otherIpcCandidates'][2]['modelReadFromCameraNow']===false,
    'no live model claim from metadata');
chkExtra(($r['otherIpcCandidates'][2]['existingP03AuxEventSources'][0]['eventVariableId']??null)
    ===42117,'reuse only existing observer IVS event variable by exact metadata ID');
chkExtra($r['otherIpcCandidates'][2]['existingP03AuxEventSources'][0]['peopleCountVerified']
    ===false,'observer event cannot count people');
chkExtra($r['otherIpcCandidates'][0]['peopleCountingSupportedByModel']==='NOT_VERIFIED',
    'Torhalle camera model remains unknown, cannot assert counting');
chkExtra($r['otherIpcCandidates'][5]['historicalRole']==='UNIDENTIFIED_IPC_OR_DEVICE',
    'other VLAN107 device must be verified as camera');
chkExtra($r['otherIpcCandidates'][5]['configuredSymconInstanceCount']===2,
    'dedup same IP and preserve both existing instance IDs');
chkExtra(count($r['protectedP03Masts'])===2,
    'never merge JV_LEFT/WORK_LEFT into freely reconfigurable cameras');
chkExtra($r['protectedP03Masts'][0]['usesP03ProtectedMast']===true,
    'P03 protected mast tag');
chkExtra(count($r['existingTiocCameras'])===2,
    'TiOC panorama cameras stay separate from additional IPC list');
chkExtra($r['cameraSettingsChanged']===false&&$r['cameraNetworkConnectionsPerformed']===false,
    'pure metadata scan never accesses camera');
chkExtra($r['createdSymconObjects']===false&&$r['foreignModulesChanged']===false,
    'no object changes or foreign modules');
chkExtra($r['personCount']==='UNKNOWN'&&$r['propertyEmptyMayBeCertified']===false,
    'inventory cannot become property exit proof');
$cam=JVCameraCapabilityPriority::evaluate(
 ['authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
  'logContinuity'=>'OK','recentDirectionProofs'=>[]],
 ['proofState'=>'BLOCKIERT – Mastkamera-Stream abgebrochen'],
 ['directionMappingCertified'=>true,'out'=>'RightToLeft','in'=>'LeftToRight'],
 [],$r);
chkExtra($cam['additionalIpcMetadataAuditAvailable']===true,
    'live Shadow report sees stored IPC metadata audit');
chkExtra(count($cam['additionalIpcCandidatesConfiguredInSymcon'])===5,
    'only currently configured candidate IPs ranked');
chkExtra($cam['additionalIpcCandidatesConfiguredInSymcon'][0]['ip']==='192.168.107.98',
    'prioritize physical property boundary candidate if visible');
chkExtra($cam['additionalIpcCandidatesConfiguredInSymcon'][0]['viewVerified']===false,
    'camera view not proven just by name');
chkExtra($cam['additionalIpcPeopleCountingCanBeUsedNow']===false,
    'no automatic counting even if people-counting-capable hardware exists');
chkExtra($cam['liveStreetExitPersonCount']==='UNKNOWN'
    &&$cam['propertyEmptyMayBeCertified']===false,
    'no inventing resident/property exits');
$none=JVKnownIpcMetadata::evaluate([]);
chkExtra($none['otherIpcFoundInSymcon']===0,
    'no Symcon candidates must not trigger a live camera claim');
chkExtra($none['otherIpcCandidates'][0]['configuredInSymcon']===false,
    'historical list clearly distinguished from detected instances');

$now=time();
$ivs=[[
 'code'=>'CrossLineDetection','action'=>'Start',
 'decision'=>'STAIR_HUMAN_START_COUNTED','rejectionReason'=>'MATCHED_FILTER',
 'payloadHuman'=>true,'classification'=>'Human',
 'eventId'=>49,'objectId'=>9,'time'=>date('H:i:s',$now-5)
]];
$evt=JVSupplementaryIpcEvidence::evaluate([
 ['ip'=>'192.168.107.96','raw'=>json_encode($ivs),
  'ageSeconds'=>5,'updatedAt'=>$now-5],
]);
chkExtra($evt['sourceCount']===1&&$evt['camerasWithFreshIvSHumanHint']===1,
    'existing passive IVS array yields at most a context hint');
chkExtra($evt['supplementaryHumanHint']===true,
    'recent verified Human IVS is supplementary only');
chkExtra($evt['numberOfPeople']==='UNKNOWN'
    &&$evt['propertyExitCount']==='UNKNOWN'
    &&$evt['propertyEmptyMayBeCertified']===false,
    'one or several events from a camera do not certify a human count or exit');
chkExtra($evt['freshIvSHumanHints'][0]['humanEventDateUnverified']===true,
    'time-only legacy P03 event never becomes a proven today timestamp');
$two=JVSupplementaryIpcEvidence::evaluate([
 ['ip'=>'192.168.107.96','raw'=>json_encode($ivs),
  'ageSeconds'=>3,'updatedAt'=>$now-5],
 ['ip'=>'192.168.107.99','raw'=>json_encode($ivs),
  'ageSeconds'=>3,'updatedAt'=>$now-5]
]);
chkExtra($two['camerasWithFreshIvSHumanHint']===2
    &&$two['numberOfPeople']==='UNKNOWN',
    'two cameras may see same person so never infer two humans');
$oldRowReplayed=JVSupplementaryIpcEvidence::evaluate([
 ['ip'=>'192.168.107.96','raw'=>json_encode($ivs),
  'ageSeconds'=>2,'updatedAt'=>$now+350]
]);
chkExtra($oldRowReplayed['supplementaryHumanHint']===false,
    'old Human array row cannot be resurrected by unrelated recent variable update');
$missingTime=JVSupplementaryIpcEvidence::evaluate([
 ['ip'=>'192.168.107.96',
  'raw'=>json_encode([[
   'code'=>'CrossLineDetection','action'=>'Start',
   'decision'=>'STAIR_HUMAN_START_COUNTED','rejectionReason'=>'MATCHED_FILTER',
   'payloadHuman'=>true
  ]]),'ageSeconds'=>2,'updatedAt'=>$now-2]
]);
chkExtra($missingTime['supplementaryHumanHint']===false,
    'without a validated matching event time no fresh human hint');

$bad=JVSupplementaryIpcEvidence::evaluate([
 ['ip'=>'192.168.107.96','raw'=>json_encode($ivs),
  'ageSeconds'=>220,'updatedAt'=>$now-220],
 ['ip'=>'192.168.107.99','raw'=>'not JSON','ageSeconds'=>3],
 ['ip'=>'192.168.107.98','raw'=>json_encode([[
  'code'=>'CrossLineDetection','action'=>'Start',
  'decision'=>'STAIR_IVS_NOT_MAPPED_OR_NOT_HUMAN',
  'rejectionReason'=>'NOT_HUMAN','payloadHuman'=>false,
  'classification'=>'Vehicle'
 ]]),'ageSeconds'=>3],
]);
chkExtra($bad['camerasWithFreshIvSHumanHint']===0
    &&$bad['supplementaryHumanHint']===false,
    'reject stale, bad JSON and car IVS event');
chkExtra($bad['alarmMayBeControlled']===false,'no alarms ever controlled');
require_once dirname(__DIR__).'/libs/JVP03SourceHealth.php';
$mast=[
 'JV_LEFT'=>['instanceId'=>57215,'guidVerified'=>true,'host'=>'192.168.107.95',
     'role'=>'JV_LEFT','streamOK'=>true,
     'diagnostic'=>['rxAge'=>8,'streaming'=>true]],
 'WORK_LEFT'=>['instanceId'=>27938,'guidVerified'=>true,'host'=>'192.168.107.94',
     'role'=>'WORK_LEFT','streamOK'=>true,
     'diagnostic'=>['rxAge'=>8,'streaming'=>true]]
];
$mh=JVP03SourceHealth::evaluate($mast,'BLOCKIERT',time());
chkExtra($mh['bothMastsLive']===true,'both observers show fresh camera RX');
chkExtra($mh['finding']==='P03_MAIN_BLOCKED_DESPITE_LIVE_AUX_STREAMS',
 'main P03 blocked despite both mast streams live');
$mast['WORK_LEFT']['diagnostic']['rxAge']=600;
$mh=JVP03SourceHealth::evaluate($mast,'BLOCKIERT',time());
chkExtra($mh['mastObservers']['WORK_LEFT']['streamState']==='STALE_CAMERA_RX',
 'old StreamOK true does not override stale reception');
$mast['WORK_LEFT']['host']='192.168.107.99';
$mh=JVP03SourceHealth::evaluate($mast,'BLOCKIERT',time());
chkExtra($mh['mastObservers']['WORK_LEFT']['sourceVerified']===false,
 'a mislabeled or repointed mast host must not be trusted');
chkExtra($mh['propertyEmptyMayBeCertified']===false
 && $mh['camerasContacted']===false,'diagnosis cannot act or assert absence');

echo "Additional Dahua IPC candidates, safe street precedence, metadata only PASS ($checks)\n";
