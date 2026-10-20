<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVResidentEventFusion.php';
require_once dirname(__DIR__).'/libs/JVPhoneAreaCycleMatcher.php';

function gap88(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('SHADOW_0_8_88 '.$why);
}
$t=1900000000;
$w=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>[
        'johannes'=>['validatedExistingVariablePair'=>true,
            'wlanBoolean'=>true,'recentMacEvidence'=>true,
            'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
            'lastMacContactAt'=>$t-30],
        'carina'=>['validatedExistingVariablePair'=>true,
            'wlanBoolean'=>true,'recentMacEvidence'=>true,
            'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
            'lastMacContactAt'=>$t-30]
    ]];
$j=['schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,
    'insideRecent'=>false,'outsideRecent'=>false,
    'geofenceReportedInside'=>null,'lastEventAt'=>null];
$c=$j;$c['authority']='EXISTING_CARINA_GEOFENCY_READ_ONLY';
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'enabled'=>true,'people'=>[]];
$p03=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'logContinuity'=>'OK','recentDirectionProofs'=>[]];
$run=static function(int $now,array $before)
    use(&$w,&$j,&$c,$work,$p03):array {
    return JVResidentEventFusion::evaluate($w,$j,$c,$work,$p03,
        $before,$now);
};
$boot=$run($t,[]);
$warm=$run($t+60,$boot['next']);
$j['outsideRecent']=true;$j['geofenceReportedInside']=false;
$j['lastEventAt']=$t+90;
$c['outsideRecent']=true;$c['geofenceReportedInside']=false;
$c['lastEventAt']=$t+100;
$out=$run($t+110,$warm['next']);
gap88(count($out['report']['people']['johannes']['eventHistory'])===1
    &&count($out['report']['people']['carina']['eventHistory'])===1,
    'two individually qualified pre-interruption geofence OUT events');
$baseCycles=JVPhoneAreaCycleMatcher::evaluate($out['report'],$t+110);
gap88($baseCycles['pairedCycleCount']===1,
    'a legitimate synchronous pair before offline gap remains visible');

// An entire 60+ minute SymBox gap is unobserved. Source IDs are still
// the same; retaining old OUT would incorrectly imply a continuous
// two-phone outside period. After reentry, old OUTs are history only,
// not a living chain to a new P03 crossing.
$paused=$run($t+4210,$out['next']);
foreach(['johannes','carina'] as $person){
    $s=$paused['report']['people'][$person];
    gap88($s['eventHistory']===[]
        &&$s['lastEvent']===null
        &&$s['pendingGeofenceOut']===null
        &&$s['pendingGeofenceIn']===null
        &&$s['sourceRebaselineRequired']===true
        &&$s['newPersonalPhoneEvent']===null,
        $person.' event history quarantined on actual long observation gap');
}
$next=$run($t+4270,$paused['next']);
gap88(JVPhoneAreaCycleMatcher::evaluate(
    $next['report'],$t+4270)['pairedCycleCount']===0
    &&$next['report']['people']['johannes']['eventHistoryCount']===0,
    'post-reboot continuous polling cannot resurrect the pre-reboot pair');

// An IN received during offline time becomes a baseline, not a
// reconstructed return or an alarm-related identity claim.
$j['insideRecent']=true;$j['outsideRecent']=false;
$j['geofenceReportedInside']=true;$j['lastEventAt']=$t+6200;
$c['insideRecent']=true;$c['outsideRecent']=false;
$c['geofenceReportedInside']=true;$c['lastEventAt']=$t+6200;
$offlineIn=$run($t+6400,$next['next']);
gap88($offlineIn['report']['people']['johannes']['newPersonalPhoneEvent']===null
    &&$offlineIn['report']['people']['carina']['newPersonalPhoneEvent']===null
    &&$offlineIn['report']['people']['johannes']['eventHistoryCount']===0,
    'geofence IN while runtime was offline may not fabricate a completed trip');
$after=$run($t+6460,$offlineIn['next']);
gap88($after['report']['people']['johannes']['eventHistoryCount']===0,
    'offline baseline cannot replay on next 60-second poll');

// A temporary loss of ONE personal geofence source must invalidate
// its chain without destroying the independent healthy person's past.
$valid0=$run($t+6520,$after['next']);
$j['insideRecent']=false;$j['outsideRecent']=true;
$j['geofenceReportedInside']=false;$j['lastEventAt']=$t+6550;
$c['insideRecent']=false;$c['outsideRecent']=true;
$c['geofenceReportedInside']=false;$c['lastEventAt']=$t+6560;
$validOut=$run($t+6570,$valid0['next']);
gap88($validOut['report']['people']['johannes']['eventHistoryCount']===1
    &&$validOut['report']['people']['carina']['eventHistoryCount']===1,
    'new post-gap events may qualify when truly observed');
// Precise pause boundary: ordinary 899-second timer delay preserves
// the established chain; a 901-second absence must quarantine it.
$edgeOk=$run($t+7469,$validOut['next']);
gap88($edgeOk['report']['people']['johannes']['eventHistoryCount']===1
    &&$edgeOk['report']['people']['carina']['eventHistoryCount']===1,
    '899-second pause retains verified source history');
$edgeStale=$run($t+7471,$validOut['next']);
gap88($edgeStale['report']['people']['johannes']['eventHistoryCount']===0
    &&$edgeStale['report']['people']['carina']['eventHistoryCount']===0,
    '901-second pause clears unverifiable time gap');
$clockBack=$run($t+6500,$validOut['next']);
gap88($clockBack['report']['people']['johannes']['eventHistoryCount']===0
    &&$clockBack['report']['people']['carina']['eventHistoryCount']===0,
    'clock rollback cannot carry future-stamped personal OUT history');

$c['validatedSource']=false;
$lostC=$run($t+6630,$validOut['next']);
gap88($lostC['report']['people']['carina']['eventHistoryCount']===0
    &&$lostC['report']['people']['johannes']['eventHistoryCount']===1
    &&$lostC['report']['people']['carina']['sourceRebaselineRequired'],
    'temporary Carina phone source loss does not erase Johannes history');
$c['validatedSource']=true;
$recoveredC=$run($t+6690,$lostC['next']);
gap88($recoveredC['report']['people']['carina']['eventHistoryCount']===0,
    'restored Carina source starts from fresh baseline without old trip');

// Missing WLAN source invalidates the old OUT/IN/MAC cross-chain too.
$w['people']['johannes']['validatedExistingVariablePair']=false;
$lostWlan=$run($t+6750,$recoveredC['next']);
gap88($lostWlan['report']['people']['johannes']['eventHistoryCount']===0
    &&$lostWlan['report']['people']['johannes']['pendingGeofenceOut']===null
    &&!$lostWlan['report']['people']['johannes']['humanAbsentCertified'],
    'invalidated WLAN binding cannot preserve cross-source chain');
$w['people']['johannes']['validatedExistingVariablePair']=true;
$regained=$run($t+6810,$lostWlan['next']);
gap88($regained['report']['people']['johannes']['eventHistoryCount']===0
    &&!$regained['report']['allAwayCertified']
    &&!$regained['report']['mayArmAlarm'],
    'WLAN returns, but old phone OUT must never requalify');

// Critical short-outage replay: an already verified Geofence changes
// during the exact 60-second poll in which its WLAN binding is INVALID.
// The old fusion accepted the fresh Geofence event despite clearing
// the old chain, then made it appear historical after WLAN recovered.
$w['people']['johannes']['validatedExistingVariablePair']=false;
$j['insideRecent']=true;$j['outsideRecent']=false;
$j['geofenceReportedInside']=true;$j['lastEventAt']=$t+6590;
$lostDuringEvent=$run($t+6600,$validOut['next']);
gap88($lostDuringEvent['report']['people']['johannes']['newPersonalPhoneEvent']===null
    &&$lostDuringEvent['report']['people']['johannes']['eventHistoryCount']===0
    &&$lostDuringEvent['report']['people']['johannes']['sourceRebaselineRequired'],
    'unqualified WLAN cannot record a new personal Geofence IN');
$w['people']['johannes']['validatedExistingVariablePair']=true;
$j['insideRecent']=false;$j['outsideRecent']=true;
$j['geofenceReportedInside']=false;$j['lastEventAt']=$t+6650;
$restoreDuringEvent=$run($t+6660,$lostDuringEvent['next']);
gap88($restoreDuringEvent['report']['people']['johannes']['newPersonalPhoneEvent']===null
    &&$restoreDuringEvent['report']['people']['johannes']['eventHistoryCount']===0
    &&$restoreDuringEvent['report']['people']['johannes']['sourceRebaselineRequired'],
    'first restored WLAN poll is baseline, not a replayed Geofence OUT');
$j['insideRecent']=true;$j['outsideRecent']=false;
$j['geofenceReportedInside']=true;$j['lastEventAt']=$t+6710;
$realAfterRestore=$run($t+6720,$restoreDuringEvent['next']);
gap88(($realAfterRestore['report']['people']['johannes']['newPersonalPhoneEvent']['code']??null)
    ==='PHONE_GEOFENCE_IN_OBSERVED'
    &&$realAfterRestore['report']['people']['johannes']['eventHistoryCount']===1,
    'new event after BOTH sources restored is retained, without the earlier forged OUT');

// Same issue in reverse: a qualified WLAN source may retain its last MAC
// timestamp while the Geofency object is briefly unverified. A restored
// valid Geofence must establish its own baseline before new journeys.
$c['validatedSource']=false;
$c['insideRecent']=true;$c['outsideRecent']=false;
$c['geofenceReportedInside']=true;$c['lastEventAt']=$t+6600;
$lostGeofency=$run($t+6630,$validOut['next']);
gap88($lostGeofency['report']['people']['carina']['eventHistoryCount']===0,
    'Carina stale geofence source clears only her personal chain');
$c['validatedSource']=true;
$c['insideRecent']=false;$c['outsideRecent']=true;
$c['geofenceReportedInside']=false;$c['lastEventAt']=$t+6650;
$restoreGeofency=$run($t+6690,$lostGeofency['next']);
gap88($restoreGeofency['report']['people']['carina']['eventHistoryCount']===0
    &&$restoreGeofency['report']['people']['carina']['newPersonalPhoneEvent']===null
    &&$restoreGeofency['report']['people']['johannes']['eventHistoryCount']===1,
    'Carina new geofence timestamp on recovery does not revive old area OUT');
echo "SHADOW 0.8.89 long restart and single-source recovery replay PASS (no new IPS variables)\n";
