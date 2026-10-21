<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPhoneContactContinuity.php';
require_once dirname(__DIR__).'/libs/JVResidentEventFusion.php';

function observationCheck(bool $condition,string $case):void {
    if(!$condition)throw new RuntimeException('OBSERVATION_GAP_CLOCK '.$case);
}
$t=1900000000; // synthetic seconds, no real home observations
$wlan=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>[
        'johannes'=>[
            'validatedExistingVariablePair'=>true,'wlanBoolean'=>true,
            'recentMacEvidence'=>true,'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
            'lastMacContactAt'=>$t-45,'sourceKey'=>'WLAN:101:102'
        ],
        'carina'=>[
            'validatedExistingVariablePair'=>true,'wlanBoolean'=>true,
            'recentMacEvidence'=>true,'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
            'lastMacContactAt'=>$t-60,'sourceKey'=>'WLAN:201:202'
        ]
    ]];
$j=['schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,'sourceKey'=>'EGI:1:2:3:4',
    'insideRecent'=>true,'outsideRecent'=>false,
    'geofenceReportedInside'=>true,'lastEventAt'=>$t-70];
$c=['schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,'sourceKey'=>'GEOFENCY:5:6:7:8',
    'insideRecent'=>true,'outsideRecent'=>false,
    'geofenceReportedInside'=>true,'lastEventAt'=>$t-75];
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'enabled'=>false,'people'=>[]];
$p03=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'logContinuity'=>'OK','recentDirectionProofs'=>[]];
$contact=static function(int $at,array $old)use(&$wlan,&$j,&$c):array{
    return JVPhoneContactContinuity::evaluate($wlan,$j,$c,$old,$at);
};
$fusion=static function(int $at,array $old)use(&$wlan,&$j,&$c,&$work,&$p03):array{
    return JVResidentEventFusion::evaluate(
        $wlan,$j,$c,$work,$p03,$old,$at
    );
};
// Build real, independent source-bound baselines and then a qualified
// phone OUT/IN. Neither is a physical person crossing.
$a=$contact($t,[]);
$f=$fusion($t,[]);
$j['insideRecent']=false;$j['outsideRecent']=true;
$j['geofenceReportedInside']=false;$j['lastEventAt']=$t+80;
$a=$contact($t+90,$a['next']);
$f=$fusion($t+90,$f['next']);
$j['insideRecent']=true;$j['outsideRecent']=false;
$j['geofenceReportedInside']=true;$j['lastEventAt']=$t+120;
$a=$contact($t+130,$a['next']);
$f=$fusion($t+130,$f['next']);
observationCheck(($a['next']['people']['johannes']['pendingInAt']??null)===$t+120
    &&($f['next']['people']['johannes']['pendingInAt']??null)===$t+120,
    'both independent ledgers saw same ordered OUT IN');

// SHADOW is not refreshed for >15 minutes. No source-ID change.
// New MAC is observed AFTER IN, but the observer was offline through
// that event interval. This is diagnostic reconnection, NOT return.
$wlan['people']['johannes']['lastMacContactAt']=$t+1015;
$now=$t+1050; // 920 seconds after prior refresh: >900
$lost=$contact($now,$a['next']);
$lostF=$fusion($now,$f['next']);
observationCheck(($lost['report']['people']['johannes']['observationInterrupted']??false)===true
    &&($lost['report']['people']['johannes']['sourceRebaselineRequired']??true)===false,
    'timed gap can break history even with identical source IDs');
observationCheck(!$lost['report']['people']['johannes']['phoneReturnAfterObservedOutIn']
    &&($lost['next']['people']['johannes']['pendingOutAt']??null)===null
    &&($lost['next']['people']['johannes']['pendingInAt']??null)===null,
    'interrupted OLD IN not paired with later fresh MAC');
observationCheck(($lostF['report']['people']['johannes']['sourceRebaselineRequired']??false)===true
    &&!$lostF['report']['people']['johannes']['newPhoneReturn'],
    'event fusion agrees no return through observer downtime');
observationCheck(!$lost['report']['mayArmAlarm']
    &&!$lost['report']['anyHumanArrivalCertified']
    &&!$lostF['report']['allAwayCertified'],
    'gap has absolutely no alarm or human-certifying effect');
observationCheck($lost['report']['people']['carina']['observationInterrupted']===true
    &&!$lost['report']['people']['carina']['phoneReturnAfterObservedOutIn'],
    'same downtime handled independently for second handset');
$again=$contact($now+60,$lost['next']);
observationCheck(!$again['report']['people']['johannes']['observationInterrupted']
    &&!$again['report']['people']['johannes']['phoneReturnAfterObservedOutIn'],
    'restored observation continuity has no replay');

// A new clean OUT->IN->MAC sequence after recovery is permitted.
$j['insideRecent']=false;$j['outsideRecent']=true;
$j['geofenceReportedInside']=false;$j['lastEventAt']=$now+100;
$cleanOut=$contact($now+110,$again['next']);
$j['insideRecent']=true;$j['outsideRecent']=false;
$j['geofenceReportedInside']=true;$j['lastEventAt']=$now+170;
$cleanIn=$contact($now+180,$cleanOut['next']);
$wlan['people']['johannes']['lastMacContactAt']=$now+210;
$returned=$contact($now+220,$cleanIn['next']);
observationCheck($returned['report']['people']['johannes']['phoneReturnAfterObservedOutIn']
    &&!$returned['report']['people']['johannes']['humanPresenceCertified'],
    'new fully observed sequence qualifies handset only');

// An older checkpoint without lastObservedAt cannot guarantee that
// no polling gap occurred; it is a baseline, not a trusted OUT/IN.
$legacy=$cleanIn['next'];
unset($legacy['people']['johannes']['lastObservedAt']);
$legacy['people']['johannes']['pendingOutAt']=$now+100;
$legacy['people']['johannes']['pendingInAt']=$now+170;
$wlan['people']['johannes']['lastMacContactAt']=$now+240;
$old=$contact($now+250,$legacy);
observationCheck($old['report']['people']['johannes']['observationInterrupted']
    &&!$old['report']['people']['johannes']['phoneReturnAfterObservedOutIn'],
    'pre-upgrade timestamp-less old checkpoint cannot replay return');

// Same-second test on *actual* fusion state constructed from fresh
// observations, not an invented direct return flag.
$u=$t+5000;
$wlan['people']['johannes']['lastMacContactAt']=$u-55;
$j['insideRecent']=false;$j['outsideRecent']=false;
$j['lastEventAt']=null;$j['geofenceReportedInside']=null;
$init=$fusion($u,[]);
$j['outsideRecent']=true;$j['geofenceReportedInside']=false;
$j['lastEventAt']=$u+80;
$out=$fusion($u+90,$init['next']);
$j['outsideRecent']=false;$j['insideRecent']=true;
$j['geofenceReportedInside']=true;$j['lastEventAt']=$u+130;
$in=$fusion($u+140,$out['next']);
observationCheck(($in['next']['people']['johannes']['pendingInAt']??null)===$u+130,
    'fusion has valid pending in before equal-second MAC');
// MAC equals IN but timestamp advanced since prior poll. There is no
// reliably observed ordering at one-second resolution.
$wlan['people']['johannes']['lastMacContactAt']=$u+130;
$equal=$fusion($u+150,$in['next']);
observationCheck(!$equal['report']['people']['johannes']['newPhoneReturn']
    &&($equal['next']['people']['johannes']['pendingInAt']??null)===$u+130
    &&!$equal['report']['mayArmAlarm'],
    'equal second accepted by neither fusion nor contact history');
$same=$fusion($u+180,$equal['next']);
observationCheck(!$same['report']['people']['johannes']['newPhoneReturn'],
    'unchanged MAC cannot replay phone return');
// Valid new later MAC now legitimately completes pending fusion chain.
$wlan['people']['johannes']['lastMacContactAt']=$u+190;
$good=$fusion($u+200,$same['next']);
observationCheck($good['report']['people']['johannes']['newPhoneReturn']
    &&($good['report']['people']['johannes']['lastEvent']['code']??null)
        ==='PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC'
    &&!$good['report']['people']['johannes']['humanAtHomeCertified'],
    'strict later MAC yields handset-only return exactly once');
$repeat=$fusion($u+230,$good['next']);
observationCheck(!$repeat['report']['people']['johannes']['newPhoneReturn'],
    'completed event cannot replay after another 30-second refresh');

// Clock moves backwards more than five seconds after a valid state.
// Pending evidence must not survive an untrusted timestamp rollback.
$clockPrior=$in['next'];
$clockBack=$contact($u+110,[
    'schema'=>1,'people'=>[
        'johannes'=>array_merge($cleanIn['next']['people']['johannes'],[
            'lastObservedAt'=>$u+140,'pendingOutAt'=>$u+80,
            'pendingInAt'=>$u+130,'lastGeoAt'=>$u+130
        ])
    ]
]);
observationCheck($clockBack['report']['people']['johannes']['observationInterrupted']
    &&!$clockBack['report']['people']['johannes']['phoneReturnAfterObservedOutIn'],
    'clock moved backward so prior event chain is quarantined');
echo "Observer gap, reboot and simultaneous MAC-IN across ledgers PASS\n";
