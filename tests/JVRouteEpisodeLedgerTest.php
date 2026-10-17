<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVRouteEpisodeLedger.php';
function routeCheck(bool $ok,string $case):void{
    if(!$ok)throw new RuntimeException('ROUTE '.$case);
}
$t=1792260000;
$f=['schema'=>1,'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>[],'p03'=>['newAnonymousProofs'=>[]]];
$b=['schema'=>1,'authority'=>'READ_ONLY_BOUNDARY_CONTEXT',
    'vehicleSignals'=>0,'recentChanges'=>[]];
$rooms=['authority'=>'LCN_ROOM_MOTION_PATHS_ANONYMOUS_ONLY',
    'recentRoomEdges'=>[]];
$raw=['authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
    'lastStreetIVS'=>null];
$run=static function(array $prior,int $at)use(&$f,&$b,&$rooms,&$raw):array{
    return JVRouteEpisodeLedger::evaluate($f,$b,$rooms,$raw,$prior,$at);
};
$proof=static fn(string $direction,int $at):array=>[
    'source'=>'P03_TWO_MAST_VERIFIED','direction'=>$direction,'at'=>$at,
    'route'=>$direction==='HOME_TO_LAGER'
        ?'P03_2CAM_JV_THEN_WORK':'P03_2CAM_WORK_THEN_JV'
];
$start=$run([],$t);
routeCheck($start['report']['lastEpisode']===null
    &&$start['report']['openOutgoingPassages']===0,'bootstrap');
$f['p03']['newAnonymousProofs']=[$proof('HOME_TO_LAGER',$t+90)];
$out=$run($start['next'],$t+100);
routeCheck($out['report']['openOutgoingPassages']===1
    &&$out['report']['lastEpisode']===null,'anonymous route out');
$f['p03']['newAnonymousProofs']=[];
$idle=$run($out['next'],$t+130);
routeCheck($idle['report']['openOutgoingPassages']===1
    &&$idle['report']['newPassagesThisRefresh']===0,'idle and persistence');
$f['p03']['newAnonymousProofs']=[$proof('LAGER_TO_HOME',$t+600)];
$rooms['recentRoomEdges']=[
    ['id'=>'eg_flur','at'=>$t+620],
    ['id'=>'eg_buero','at'=>$t+70]
];
$raw['lastStreetIVS']=['at'=>$t+610];
$in=$run($idle['next'],$t+630);
$ep=$in['report']['lastEpisode'];
routeCheck($in['report']['newEpisodeThisRefresh']
    &&$ep['durationSeconds']===510
    &&$ep['anonymousIndoorMotionBeforeOut']
    &&$ep['anonymousIndoorMotionAfterIn']
    &&$ep['rawStreetHumanNearby'],'sequence with anonymous contextual rooms');
routeCheck($ep['identity']==='UNKNOWN'
    &&$ep['transport']==='UNVERIFIED'
    &&!$ep['samePersonConfirmed']
    &&!$in['report']['vehicleJourneyCertified'],'no phone/camera identity');
$again=$run($in['next'],$t+660);
routeCheck(!$again['report']['newEpisodeThisRefresh']
    &&$again['report']['newPassagesThisRefresh']===0,'same proof cannot replay');
// No vehicle signals means no inferred vehicle use.
routeCheck($again['report']['vehicleSignalCoverage']==='NO_VERIFIED_VEHICLE_SIGNAL_CONFIGURED',
    'zero mapped vehicle sources');
// A mapped vehicle variable with ACTIVE state near a P03 event remains
// context only: direction / car journey are NOT certified.
$f['p03']['newAnonymousProofs']=[$proof('HOME_TO_LAGER',$t+700)];
$x=$run($again['next'],$t+710);
$b['vehicleSignals']=1;
$b['recentChanges']=[['kind'=>'VEHICLE','state'=>'ACTIVE','at'=>$t+800]];
$f['p03']['newAnonymousProofs']=[$proof('LAGER_TO_HOME',$t+820)];
$y=$run($x['next'],$t+830);
routeCheck(($y['report']['lastEpisode']['vehicleVariableActiveNearby']??false)
    &&!$y['report']['vehicleJourneyCertified']
    &&$y['report']['lastEpisode']['vehicleDirection']==='UNKNOWN',
    'vehicle sensor near passage does not prove car direction');
// Parallel passages: 2 OUT and then IN are never assigned FIFO.
$b['recentChanges']=[];
$f['p03']['newAnonymousProofs']=[$proof('HOME_TO_LAGER',$t+900)];
$m1=$run($y['next'],$t+905);
$f['p03']['newAnonymousProofs']=[$proof('HOME_TO_LAGER',$t+920)];
$m2=$run($m1['next'],$t+930);
$f['p03']['newAnonymousProofs']=[$proof('LAGER_TO_HOME',$t+945)];
$amb=$run($m2['next'],$t+950);
routeCheck(!$amb['report']['newEpisodeThisRefresh']
    &&$amb['report']['openOutgoingPassages']===0
    &&$amb['report']['ambiguousOutstanding']===1
    &&$amb['report']['ambiguityResetThisRefresh']===true
    &&in_array('MULTIPLE_OUT_CANNOT_PAIR_UNIQUE_RETURN',
        $amb['report']['warnings'],true)
    &&in_array('AMBIGUOUS_ROUTE_WINDOW_RESET',
        $amb['report']['warnings'],true),
    'two people parallel passage ambiguous: clear all unpairable OUTs');
// Until the second originally outward crossing is accounted for, a
// later individual OUT cannot be safely paired with an earlier IN.
$f['p03']['newAnonymousProofs']=[$proof('LAGER_TO_HOME',$t+960)];
$oldFinalIn=$run($amb['next'],$t+970);
routeCheck($oldFinalIn['report']['ambiguousOutstanding']===0
    &&!$oldFinalIn['report']['newEpisodeThisRefresh']
    &&in_array('UNRESOLVED_MULTIPERSON_CROSSINGS_QUARANTINED',
        $oldFinalIn['report']['warnings'],true),
    'late second IN clears ambiguity debt but never completes a claimed trip');
// New independent route may now start without waiting 12h.
$f['p03']['newAnonymousProofs']=[$proof('HOME_TO_LAGER',$t+980)];
$independentOut=$run($oldFinalIn['next'],$t+990);
routeCheck($independentOut['report']['openOutgoingPassages']===1,
    'after ambiguous returns the next independent OUT can start a clean route');
$f['p03']['newAnonymousProofs']=[$proof('LAGER_TO_HOME',$t+1040)];
$independentIn=$run($independentOut['next'],$t+1050);
routeCheck($independentIn['report']['newEpisodeThisRefresh']===true
    &&$independentIn['report']['lastEpisode']['outAt']===$t+980
    &&$independentIn['report']['lastEpisode']['inAt']===$t+1040
    &&$independentIn['report']['lastEpisode']['identity']==='UNKNOWN'
    &&!$independentIn['report']['samePersonRoundTripCertified'],
    'clean new anonymous route paired without stealing old ambiguous passages');
// Down time rebaseline: no pairing old OUT after an hour gap.
$f['p03']['newAnonymousProofs']=[];
$pause=$run($independentIn['next'],$t+5000);
routeCheck($pause['report']['openOutgoingPassages']===0
    &&in_array('AFTER_LONG_GAP_OPEN_ROUTES_RESET',
        $pause['report']['warnings'],true),'offline reset');
$f['p03']['newAnonymousProofs']=[$proof('LAGER_TO_HOME',$t+5100)];
$soloIn=$run($pause['next'],$t+5110);
routeCheck($soloIn['report']['lastEpisode']['inAt']===$t+1040
    &&in_array('IN_WITHOUT_OBSERVED_OUT',
        $soloIn['report']['warnings'],true),'unpaired in no new trip');
// Bad proof time / route cannot enter ledger.
$f['p03']['newAnonymousProofs']=[
    $proof('HOME_TO_LAGER',$t+200000),
    ['source'=>'P03_TWO_MAST_VERIFIED','direction'=>'HOME_TO_LAGER',
        'route'=>'P03_2CAM_WORK_THEN_JV','at'=>$t+5120]
];
$bad=$run($soloIn['next'],$t+5130);
routeCheck($bad['report']['newPassagesThisRefresh']===0,'reject wrong or future source');
// Verified P03 OFF breaks corridor continuity, even if observer packets
// or a later officially verified IN exist.
$raw['p03OperatingMode']='TEST_ACTIVE';
$raw['p03ModeTrustedFromFreshDiagnosis']=true;
$f['p03']['newAnonymousProofs']=[$proof('HOME_TO_LAGER',$t+5220)];
$openNow=$run($bad['next'],$t+5230);
routeCheck($openNow['report']['openOutgoingPassages']===1,'active P03 can start new route');
$raw['p03OperatingMode']='TEST_AND_PRODUCTION_OFF';
$f['p03']['newAnonymousProofs']=[];
$disabled=$run($openNow['next'],$t+5260);
routeCheck($disabled['report']['openOutgoingPassages']===0
    &&in_array('P03_OFF_CORRIDOR_CONTINUITY_INTERRUPTED',
        $disabled['report']['warnings'],true),'P03 mode off interrupts trip');
$raw['p03OperatingMode']='TEST_ACTIVE';
$f['p03']['newAnonymousProofs']=[$proof('LAGER_TO_HOME',$t+5300)];
$afterOn=$run($disabled['next'],$t+5310);
routeCheck(!$afterOn['report']['newEpisodeThisRefresh']
    &&in_array('IN_WITHOUT_OBSERVED_OUT',
        $afterOn['report']['warnings'],true),
    'new IN cannot pair with prior OUT across P03 off');
// Even with live observer packets, a P03 log gap must interrupt an
// opened route. Later IN cannot be paired across missing proof history.
$raw['p03OperatingMode']='TEST_ACTIVE';
$f['p03']['newAnonymousProofs']=[$proof('HOME_TO_LAGER',$t+5400)];
$openBeforeGap=$run($afterOn['next'],$t+5410);
routeCheck($openBeforeGap['report']['openOutgoingPassages']===1,
    'route opened before protocol discontinuity');
$f['p03']['newAnonymousProofs']=[];
$f['p03']['logInterrupted']=true;
$logGap=$run($openBeforeGap['next'],$t+5430);
routeCheck($logGap['report']['openOutgoingPassages']===0
    &&in_array('P03_LOG_GAP_CORRIDOR_CONTINUITY_INTERRUPTED',
        $logGap['report']['warnings'],true),
    'P03 proof-log gap clears incomplete route');
$f['p03']['logInterrupted']=false;
$f['p03']['newAnonymousProofs']=[$proof('LAGER_TO_HOME',$t+5480)];
$lateAfterGap=$run($logGap['next'],$t+5490);
routeCheck(!$lateAfterGap['report']['newEpisodeThisRefresh']
    &&in_array('IN_WITHOUT_OBSERVED_OUT',
        $lateAfterGap['report']['warnings'],true),
    'cannot connect a later IN across P03 lost-protocol window');
echo "Anonymous route episodes PASS\n";
