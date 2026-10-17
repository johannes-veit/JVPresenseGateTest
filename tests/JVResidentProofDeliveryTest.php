<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVResidentEventFusion.php';
require_once dirname(__DIR__).'/libs/JVRouteEpisodeLedger.php';
function assertDelivery(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('PROOF_DELIVERY '.$why);
}
$t=1800000000;
$w=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>[]];
$jg=[];$cg=[];
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'enabled'=>true,'people'=>[]];
$boundary=['schema'=>1,'authority'=>'READ_ONLY_BOUNDARY_CONTEXT',
    'vehicleSignals'=>0,'recentChanges'=>[]];
$rooms=['authority'=>'LCN_ROOM_MOTION_PATHS_ANONYMOUS_ONLY',
    'recentRoomEdges'=>[]];
$time=['authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
    'p03OperatingMode'=>'TEST_ACTIVE','p03ModeTrustedFromFreshDiagnosis'=>true];
$proof=static fn(string $dir,int $at):array=>[
    'source'=>'P03_TWO_MAST_VERIFIED',
    'direction'=>$dir,'at'=>$at,
    'route'=>$dir==='HOME_TO_LAGER'
        ?'P03_2CAM_JV_THEN_WORK':'P03_2CAM_WORK_THEN_JV'
];
$log=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'logContinuity'=>'OK','recentDirectionProofs'=>[],'warnings'=>[]];
$runFusion=static function(array $prior,int $now)use(
    &$w,&$jg,&$cg,&$work,&$log
):array{
    return JVResidentEventFusion::evaluate($w,$jg,$cg,$work,$log,$prior,$now);
};
$runRoute=static function(array $fusion,array $prior,int $now)use(
    &$boundary,&$rooms,&$time
):array{
    return JVRouteEpisodeLedger::evaluate(
        $fusion['report'],$boundary,$rooms,$time,$prior,$now
    );
};
$baseline=$runFusion([],$t);
$rtBaseline=$runRoute($baseline,[],$t);
assertDelivery(count($baseline['report']['p03']['routeDeliveryProofs'])===0,
    'initial current proofs only baseline');
$log['recentDirectionProofs']=[$proof('HOME_TO_LAGER',$t+120)];
$f1=$runFusion($baseline['next'],$t+130);
assertDelivery(count($f1['report']['p03']['newAnonymousProofs'])===1
    &&count($f1['next']['p03']['unackedRouteProofs'])===1,
    'new OUT durably journaled before P03 cursor checkpoint');
// Simulated crash immediately after storing fusion + cursor, before route.
$f2=$runFusion($f1['next'],$t+160);
assertDelivery(count($f2['report']['p03']['newAnonymousProofs'])===0
    &&count($f2['report']['p03']['routeDeliveryProofs'])===1,
    'P03 cursor advanced but pending proof redelivered from durable journal');
$r1=$runRoute($f2,$rtBaseline['next'],$t+160);
assertDelivery($r1['report']['openOutgoingPassages']===1
    &&$r1['report']['newPassagesThisRefresh']===1,
    'pending OUT enters route ledger after crash');
// Simulate crash after route state was persisted but before ack.
$f3=$runFusion($f2['next'],$t+190);
$r2=$runRoute($f3,$r1['next'],$t+190);
assertDelivery($r2['report']['newPassagesThisRefresh']===0
    &&$r2['report']['openOutgoingPassages']===1,
    'same delivery idempotent, cannot duplicate OUT');
$ack=JVResidentEventFusion::acknowledgeRouteDelivery(
    $f3['next'],$f3['report']['p03']['routeDeliveryProofs'],$t+190
);
assertDelivery(count($ack['p03']['unackedRouteProofs'])===0,
    'acknowledgement after durable route state clears only handled proof');
$doubleAck=JVResidentEventFusion::acknowledgeRouteDelivery(
    $ack,$f3['report']['p03']['routeDeliveryProofs'],$t+191
);
assertDelivery($doubleAck===$ack,'ack replay is idempotent');
$log['recentDirectionProofs'][]=$proof('LAGER_TO_HOME',$t+260);
$f4=$runFusion($ack,$t+270);
$r3=$runRoute($f4,$r2['next'],$t+270);
assertDelivery($r3['report']['newEpisodeThisRefresh']===true
    &&$r3['report']['lastEpisode']['durationSeconds']===140
    &&$r3['report']['lastEpisode']['identity']==='UNKNOWN',
    'IN after delivered OUT forms exactly one anonymous corridor episode');
$ack2=JVResidentEventFusion::acknowledgeRouteDelivery(
    $f4['next'],$f4['report']['p03']['routeDeliveryProofs'],$t+270
);
$f5=$runFusion($ack2,$t+310);
$r4=$runRoute($f5,$r3['next'],$t+310);
assertDelivery(!$r4['report']['newEpisodeThisRefresh']
    &&$r4['report']['newPassagesThisRefresh']===0,
    'subsequent scans cannot invent duplicate episode');
// Interrupted stream is not just a pause; any pending proof cannot safely
// traverse the lost log window.
$log['recentDirectionProofs'][]=$proof('HOME_TO_LAGER',$t+400);
$f6=$runFusion($ack2,$t+410);
assertDelivery(count($f6['next']['p03']['unackedRouteProofs'])===1,
    'new OUT present in unacknowledged journal');
$log['logContinuity']='UNKNOWN';
$log['warnings']=['P03_SOURCE_NOT_READABLE'];
$f7=$runFusion($f6['next'],$t+440);
$r5=$runRoute($f7,$r4['next'],$t+440);
assertDelivery($f7['report']['p03']['logInterrupted']===true
    &&count($f7['report']['p03']['routeDeliveryProofs'])===0
    &&count($f7['next']['p03']['unackedRouteProofs'])===0
    &&in_array('P03_LOG_GAP_CORRIDOR_CONTINUITY_INTERRUPTED',
        $r5['report']['warnings'],true),
    'unreadable P03 source invalidates pending route handoff');
// A journal whose oldest P03 proof passed its 10-minute delivery
// deadline is an interrupted route, not a silently discarded crossing.
$log['logContinuity']='OK';$log['warnings']=[];
$expiry=$runFusion($f6['next'],$t+1100);
assertDelivery(($expiry['report']['p03']['routeJournalExpired']??false)===true
    &&$expiry['report']['p03']['logInterrupted']===true
    &&count($expiry['report']['p03']['routeDeliveryProofs'])===0,
    'expired unacknowledged P03 proof forces incomplete-route state');
echo "Crash-safe proof delivery PASS\n";
