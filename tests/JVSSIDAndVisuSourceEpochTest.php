<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVCompanyWiFiEvidence.php';
require_once dirname(__DIR__).'/libs/JVResidentVisuState.php';

function boundPhoneCheck(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('SSID_AND_VISU_SOURCE_EPOCH '.$why);
}
$t=1900000000; // simulation time, not resident tracking data
$snaps=[
    'johannes'=>['verified'=>true,'ssid'=>'Intern-Veit',
        'updatedAt'=>$t-20,'sourceVariableID'=>15101],
    'carina'=>['verified'=>true,'ssid'=>'Privat-Veit',
        'updatedAt'=>$t-25,'sourceVariableID'=>15102]
];
$good=JVCompanyWiFiEvidence::evaluate($snaps,$t,true);
boundPhoneCheck($good['workPhoneCount']===2
    &&$good['people']['johannes']['sourceKey']==='SSID:15101'
    &&$good['people']['carina']['sourceKey']==='SSID:15102',
    'two independent bound SSIDs');
$dup=$snaps;
$dup['carina']['sourceVariableID']=15101;
$collision=JVCompanyWiFiEvidence::evaluate($dup,$t,true);
boundPhoneCheck($collision['workPhoneCount']===0
    &&!$collision['people']['carina']['sourceVerified']
    &&!$collision['people']['johannes']['sourceVerified']
    &&!$collision['people']['carina']['homeSsidRecent']
    &&!$collision['people']['johannes']['workRecent'],
    'same variable reused across residents fails closed');
boundPhoneCheck(in_array('BOTH_PHONES_SHARE_ONE_SSID_VARIABLE_ID',
    $collision['warnings'],true)
    &&$collision['people']['johannes']['status']==='SHARED_SSID_VARIABLE_SOURCE_COLLISION',
    'shared SSID source produces explicit diagnostics');

// Simulate independent auto and manual discovery that checked separate
// snapshots before combining them. Final recheck is mandatory.
$manual=JVCompanyWiFiEvidence::evaluate([
 'johannes'=>['verified'=>true,'ssid'=>'Intern-Veit',
    'updatedAt'=>$t-10,'sourceVariableID'=>15102]
],$t,true);
$merged=$good;
$merged['people']['johannes']=$manual['people']['johannes'];
$merged=JVCompanyWiFiEvidence::rejectSharedSource($merged);
boundPhoneCheck($merged['workPhoneCount']===0
    &&!$merged['people']['carina']['sourceVerified']
    &&!$merged['people']['johannes']['sourceVerified'],
    'manual plus AUTO cross-person reuse rejected at final handoff');
$correct=JVCompanyWiFiEvidence::evaluate([
 'johannes'=>['verified'=>true,'ssid'=>'Privat-Veit-EXT',
    'updatedAt'=>$t-10,'sourceVariableID'=>15103]
],$t,true);
$merged=$good;
$merged['people']['johannes']=$correct['people']['johannes'];
$merged=JVCompanyWiFiEvidence::rejectSharedSource($merged);
boundPhoneCheck($merged['workPhoneCount']===1
    &&$merged['people']['carina']['workRecent']
    &&!$merged['people']['johannes']['workRecent']
    &&$merged['people']['johannes']['boundarySSIDOnly'],
    'final work count after replacing AUTO with manual source');

$wlan=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
 'enabled'=>true,'people'=>[
 'johannes'=>[
   'validatedExistingVariablePair'=>true,
   'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
   'recentMacEvidence'=>true,
   'wlanBoolean'=>true,
   'sourceKey'=>'WLAN:101:102',
   'lastMacContactAt'=>$t-30
 ],
 'carina'=>[
   'validatedExistingVariablePair'=>true,
   'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
   'recentMacEvidence'=>true,
   'wlanBoolean'=>true,
   'sourceKey'=>'WLAN:201:202',
   'lastMacContactAt'=>$t-35
 ]] ];
$offWork=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
 'enabled'=>false,'people'=>[]];
$check=static fn(array $w,array $s,array $old,int $at):
    array=>JVResidentVisuState::evaluate($w,$s,[],[],[],[], $old,$at);
$first=$check($wlan,$offWork,[],$t);
boundPhoneCheck($first['report']['people']['johannes']['decision']==='HOME_PHONE_CONFIRMED'
    &&($first['next']['people']['johannes']['sourceKey']??null)==='WLAN:101:102'
    &&($first['next']['people']['carina']['sourceKey']??null)==='WLAN:201:202',
    'both current residents have independently bound last-known zone');
$stale=$wlan;
$stale['people']['johannes']['lastMacContactAt']=$t-30;
$stale['people']['carina']['lastMacContactAt']=$t-35;
$wait=$check($stale,$offWork,$first['next'],$t+800);
boundPhoneCheck($wait['report']['people']['johannes']['decision']==='LAST_KNOWN_UNCONFIRMED'
    &&$wait['report']['people']['carina']['decision']==='LAST_KNOWN_UNCONFIRMED',
    'phone standby keeps historical zone unconfirmed, never AWAY');

// A change of object mapping must never inherit a last-known HOME from
// another physical handset or recycled Symcon variable.
$rebound=$stale;
$rebound['people']['johannes']['sourceKey']='WLAN:103:104';
$changed=$check($rebound,$offWork,$wait['next'],$t+810);
boundPhoneCheck($changed['report']['people']['johannes']['decision']==='UNKNOWN'
    &&$changed['report']['people']['johannes']['displaySourceRebaseline']
    &&!isset($changed['next']['people']['johannes'])
    &&$changed['report']['people']['carina']['decision']==='LAST_KNOWN_UNCONFIRMED',
    'only changed handset source loses old last-known location');
// New contact from rebound ID is positive but not evidence of physical arrival.
$rebound['people']['johannes']['lastMacContactAt']=$t+830;
$rebuilt=$check($rebound,$offWork,$changed['next'],$t+840);
boundPhoneCheck($rebuilt['report']['people']['johannes']['decision']==='HOME_PHONE_CONFIRMED'
    &&($rebuilt['next']['people']['johannes']['sourceKey']??null)==='WLAN:103:104'
    &&!$rebuilt['report']['people']['johannes']['personPhysicallyAtHomeCertified'],
    'new verified bound phone hints are accepted after rebaseline');
// Missing source (not replacement) can retain historical state, but not live.
$drop=$rebound;$drop['enabled']=false;
$down=$check($drop,$offWork,$rebuilt['next'],$t+1200);
boundPhoneCheck($down['report']['people']['johannes']['decision']==='LAST_KNOWN_UNCONFIRMED'
    &&(!$down['report']['people']['johannes']['displaySourceRebaseline']),
    'temporary source outage leaves only unconfirmed history');
$recover=$check($rebound,$offWork,$down['next'],$t+1205);
boundPhoneCheck(!$recover['report']['people']['johannes']['displaySourceRebaseline'],
    'unchanged restored object ID does not invent a source replacement');
// Legacy stored state has no source key: require safe new baseline.
$legacy=['schema'=>1,'people'=>[
    'johannes'=>['zone'=>'WORK','at'=>$t+1100],
    'carina'=>['zone'=>'HOME','at'=>$t+1100]
]];
$old=$check($stale,$offWork,$legacy,$t+1300);
boundPhoneCheck($old['report']['people']['johannes']['decision']==='UNKNOWN'
    &&$old['report']['people']['carina']['decision']==='UNKNOWN'
    &&$old['report']['people']['johannes']['displaySourceRebaseline'],
    'old unbound saved VISU must not claim source lineage');

// In the SSID case, the source ID is persisted separately from the
// smartphone HOME MAC. A changed phone SSID source cannot replay WORK.
$wifiOff=$stale;$wifiOff['enabled']=false;
$work=$good;
$work['enabled']=true;
$ws=$check($wifiOff,$work,[],$t);
boundPhoneCheck($ws['report']['people']['carina']['decision']==='WORK_PHONE_CONFIRMED'
    &&($ws['next']['people']['carina']['sourceKey']??null)==='SSID:15102',
    'fresh company SSID is bound in VISU');
$work['people']['carina']['ageSeconds']=700;
$ss=$check($wifiOff,$work,$ws['next'],$t+700);
boundPhoneCheck($ss['report']['people']['carina']['decision']==='LAST_KNOWN_UNCONFIRMED',
    'stale SSID cannot stay WORK');
$work['people']['carina']['sourceKey']='SSID:15109';
$change=$check($wifiOff,$work,$ss['next'],$t+710);
boundPhoneCheck($change['report']['people']['carina']['decision']==='UNKNOWN'
    &&$change['report']['people']['carina']['displaySourceRebaseline'],
    'new SSID variable cannot inherit previous work location');
boundPhoneCheck(!$change['report']['mayArmAlarm']
    &&!$change['report']['allResidentsAwayCertified']
    &&!$change['report']['mayWriteOtherModules'],
    'no actual human absence, alarm or controls');
// Integration contract: HTML tile, two named resident display variables,
// status line and public report must use the FINAL source-reconciled view.
// A separate earlier explicit-only diagnostic cannot be shown as final.
$main=(string)file_get_contents(dirname(__DIR__)
    .'/JVPresenceIdentityShadow/module.php');
boundPhoneCheck(str_contains($main,
    '$tileWork=JVCompanyWiFiEvidence::rejectSharedSource($tileWork);'),
    'manual and auto sources are reconciled before resident tile');
boundPhoneCheck(str_contains($main,
    "'residentVisuState'=>"+'
+"tileResident['report']"),
    'published resident visu uses final tile source pair');
boundPhoneCheck(str_contains($main,
    '$this->setResidentDisplayValues('."\n            ".
    '$tileResident['."'report'".']['."'people'".']['."'johannes'".']['."'display'".']'),
    'named resident variables use final tile view');
boundPhoneCheck(str_contains($main,
    ".' | Firmen-WLAN Handys: '."+'
+"tileWork['workPhoneCount']"),
    'human-facing summary counts only final reconciled work-phone sources');
echo "SSID merged collisions and source-bound two-resident VISU epochs PASS\n";
