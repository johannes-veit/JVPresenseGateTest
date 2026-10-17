<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVResidentVisuState.php';
function rv(bool $ok,string $case):void {
    if(!$ok)throw new RuntimeException('Resident VISU: '.$case);
}
$now=1792233000;
$mac=[
 'schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
 'enabled'=>true,'people'=>[
 'johannes'=>['validatedExistingVariablePair'=>true,
     'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT','recentMacEvidence'=>true,
     'lastMacContactAt'=>$now-6],
 'carina'=>['validatedExistingVariablePair'=>true,
     'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT','recentMacEvidence'=>true,
     'lastMacContactAt'=>$now-7]
 ]];
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
 'enabled'=>false,'people'=>[]];
$geo=['schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
 'enabled'=>false,'validatedSource'=>false,'insideRecent'=>false];
$cGeo=['schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
 'enabled'=>false,'validatedSource'=>false,'insideRecent'=>false];
$home=['authority'=>'ANONYMOUS_HOME_OBSERVATION_ONLY',
 'sourcesHealthy'=>true,'lastHomeMotionAgeSeconds'=>2];
$p03=['authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
 'directionProofs24h'=>['HOME_TO_LAGER'=>2,'LAGER_TO_HOME'=>2]];
// Capture the mutable scenario fixtures BY REFERENCE. An arrow function
// would capture their initial values and silently invalidate later cases.
$eval=static function(array $prior=[],?int $clock=null) use (
    &$mac,&$work,&$geo,&$cGeo,&$home,&$p03,$now
):array {
    return JVResidentVisuState::evaluate(
        $mac,$work,$geo,$cGeo,$home,$p03,$prior,$clock??$now
    );
};
$r=$eval();
rv($r['report']['people']['johannes']['decision']==='HOME_PHONE_CONFIRMED'
    &&$r['report']['people']['carina']['decision']==='HOME_PHONE_CONFIRMED',
    'both fresh home mac reports are separate handset HOME hints');
rv($r['report']['people']['johannes']['display']==='ZUHAUSE (Handy-WLAN bestätigt)'
    &&$r['report']['anonymousHouseMotionRecent']
    &&$r['report']['people']['johannes']['cameraAssignedThisPerson']===false,
    'user-facing text and no anonymous camera-person identity');
rv($r['report']['allResidentsAwayCertified']===false
    &&$r['report']['futureAbsenceActionsApproved']===false
    &&$r['report']['mayArmAlarm']===false
    &&$r['report']['people']['carina']['mayBeUsedForAbsenceAutomation']===false,
    'never approve automatic alarm or away');
$prior=$r['next'];
$mac['people']['carina']['recentMacEvidence']=false;
$mac['people']['carina']['state']='NO_RECENT_MAC_CONFIRMATION_NOT_ABSENCE';
$sleep=$eval($prior,$now+700);
rv($sleep['report']['people']['carina']['decision']==='LAST_KNOWN_UNCONFIRMED'
    &&$sleep['report']['people']['carina']['display']==='UNBESTÄTIGT (zuletzt Zuhause)'
    &&$sleep['report']['people']['carina']['deviceEvidenceAt']===null,
    'iPhone standby after old MAC is NOT a live HOME and NOT AWAY');
$old=$eval($sleep['next'],$now+86500);
rv($old['report']['people']['carina']['decision']==='UNKNOWN'
    &&!isset($old['next']['people']['carina']),
    'old last-known automatically expires after 24h');
$geo['enabled']=true;$geo['validatedSource']=true;
$geo['geofenceReportedInside']=true;$geo['insideRecent']=true;
$cGeo['enabled']=true;$cGeo['validatedSource']=true;
$cGeo['geofenceReportedInside']=true;$cGeo['insideRecent']=true;
$stillNo=$eval([],$now+100);
rv($stillNo['report']['people']['carina']['decision']==='UNKNOWN'
    &&$stillNo['report']['people']['carina']['geofenceUsedToDecideHomeOrWork']===false,
    '150m shared HOME/WORK geofence alone cannot locate home');
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
 'enabled'=>true,'people'=>[
  'johannes'=>['sourceVerified'=>true,'workRecent'=>false,
    'homeSsidRecent'=>false,'status'=>'OTHER_OR_UNKNOWN_SSID','ageSeconds'=>20],
  'carina'=>['sourceVerified'=>true,'workRecent'=>true,
    'homeSsidRecent'=>false,'status'=>'WORK_FIRMEN_WLAN_PHONE','ageSeconds'=>25]
 ]];
$firm=$eval([],$now+100);
rv($firm['report']['people']['johannes']['decision']==='UNKNOWN'
    ||$firm['report']['people']['johannes']['decision']==='HOME_PHONE_CONFIRMED',
    'Johannes not inferred WORK from Carina phone');
rv($firm['report']['people']['carina']['decision']==='WORK_PHONE_CONFIRMED'
    &&$firm['report']['people']['carina']['display']==='FIRMA (Handy-WLAN bestätigt)',
    'Carina independently on exact firm SSID even with 150m inside');
$at=$now+100;
$work['people']['carina']['ageSeconds']=15;  // at=now+85
$mac['people']['carina']=['validatedExistingVariablePair'=>true,
    'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT','recentMacEvidence'=>true,
    'lastMacContactAt'=>$at-25];
$conf=JVResidentVisuState::evaluate(
    $mac,$work,$geo,$cGeo,$home,$p03,$firm['next'],$at);
rv($conf['report']['people']['carina']['decision']==='CONFLICT'
    &&$conf['report']['people']['carina']['personPhysicallyAwayCertified']===false
    &&$conf['next']['people']['carina']['zone']==='WORK',
    'same minute contradictory networks -> flag without changing last-known');
$mac['people']['carina']['lastMacContactAt']=$at-1;
$work['people']['carina']['ageSeconds']=90; // older firm
$back=JVResidentVisuState::evaluate(
    $mac,$work,$geo,$cGeo,$home,$p03,$conf['next'],$at);
rv($back['report']['people']['carina']['decision']==='HOME_PHONE_CONFIRMED'
    &&$back['next']['people']['carina']['zone']==='HOME',
    'newer genuine HOME proof supersedes previous firm SSID');
$switchNow=$at+60;
$mac['people']['carina']['lastMacContactAt']=$at-400;
$work['people']['carina']['ageSeconds']=2;
$go=JVResidentVisuState::evaluate(
    $mac,$work,$geo,$cGeo,$home,$p03,$back['next'],$switchNow);
rv($go['report']['people']['carina']['decision']==='WORK_PHONE_CONFIRMED'
    &&$go['next']['people']['carina']['zone']==='WORK',
    'fresh firm SSID supersedes lingering 11-min home MAC grace');
// Simulate an older 11-minute MAC-held HOME event that arrives AFTER the
// already captured WORK evidence. Never roll back to HOME.
$mac['people']['carina']['lastMacContactAt']=$at-350;
$mac['people']['carina']['recentMacEvidence']=true;
$mac['people']['carina']['state']='MAC_CONFIRMED_RECENT_DEVICE_HINT';
$work['people']['carina']['ageSeconds']=901;
$outOfOrder=JVResidentVisuState::evaluate(
    $mac,$work,$geo,$cGeo,$home,$p03,$go['next'],$switchNow);
rv($outOfOrder['report']['people']['carina']['decision']==='LAST_KNOWN_UNCONFIRMED'
    &&$outOfOrder['report']['people']['carina']['rejectedOlderOppositeZoneSource']===true
    &&$outOfOrder['next']['people']['carina']['zone']==='WORK',
    'older stale HOME MAC cannot undo more recent WORK in retained ledger');
$mac['enabled']=false;
$work['people']['carina']['ageSeconds']=901;
$after=JVResidentVisuState::evaluate(
    $mac,$work,$geo,$cGeo,$home,$p03,$go['next'],$switchNow);
rv($after['report']['people']['carina']['decision']==='LAST_KNOWN_UNCONFIRMED'
    &&$after['report']['people']['carina']['deviceEvidenceAt']===null,
    'stale firm update stays last-known, no permanent WORK');
$work['people']['carina']['sourceVerified']=false;
$fail=JVResidentVisuState::evaluate(
    $mac,$work,$geo,$cGeo,$home,$p03,[],$switchNow);
rv($fail['report']['people']['carina']['decision']==='UNKNOWN',
    'unverified source never creates location');
$work['people']['carina']['sourceVerified']=true;
$work['people']['carina']['status']='HOME_SSID_PHONE_HINT';
$work['people']['carina']['workRecent']=false;
$work['people']['carina']['homeSsidRecent']=true;
$work['people']['carina']['ageSeconds']=7;
$homeSSID=JVResidentVisuState::evaluate(
    $mac,$work,$geo,$cGeo,$home,$p03,[],$switchNow);
rv($homeSSID['report']['people']['carina']['decision']==='HOME_PHONE_CONFIRMED'
    &&$homeSSID['report']['people']['carina']['deviceEvidenceReason']==='PHONE_HOME_SSID',
    'phone actively reports exact home SSID even if MAC polling off');
echo "Per-phone home/work/unknown/conflict, standby and safe VISU state PASS\n";
