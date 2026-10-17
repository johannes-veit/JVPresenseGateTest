<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVP03IncrementalTimeline.php';
require_once dirname(__DIR__).'/libs/JVResidentEventFusion.php';
function burstCheck(bool $ok,string $reason):void{
    if(!$ok)throw new RuntimeException('P03_BURST '.$reason);
}
$tz=new DateTimeZone('Europe/Berlin');
$t=(new DateTimeImmutable('2026-10-18 10:00:00',$tz))->getTimestamp();
$wifi=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>[]];
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'enabled'=>true,'people'=>[]];
$initialP03=JVP03IncrementalTimeline::evaluate('',[],$t);
$initialFusion=JVResidentEventFusion::evaluate(
    $wifi,[],[],$work,$initialP03['report'],[],$t
);
$makeRaw=static function(int $count,int $offset=0)use($t,$tz):string{
    $lines=[];
    for($i=1;$i<=$count;$i++){
        $when=(new DateTimeImmutable('@'.($t+$i+$offset)))
            ->setTimezone($tz)->format('Y-m-d H:i:s');
        $out=$i%2===1;
        $direction=$out?'HOME_TO_LAGER':'LAGER_TO_HOME';
        $route=$out?'P03_2CAM_JV_THEN_WORK':'P03_2CAM_WORK_THEN_JV';
        $lines[]='['.$when.'] P03 PROOF VERIFIED '.$direction.' via '.$route;
    }
    return implode("\n",$lines);
};
$raw=$makeRaw(70);
$p1=JVP03IncrementalTimeline::evaluate(
    $raw,$initialP03['next'],$t+100
);
burstCheck($p1['report']['newDirectionProofsThisRead']===70
    &&count($p1['report']['recentDirectionProofs'])===15
    &&count($p1['report']['newlyVerifiedDirectionProofs'])===70
    &&!$p1['report']['newProofBatchOverflow'],
    'retain 70 newly appended proofs although legacy display lists only 15');
$f1=JVResidentEventFusion::evaluate(
    $wifi,[],[],$work,$p1['report'],$initialFusion['next'],$t+100
);
burstCheck(count($f1['report']['p03']['newAnonymousProofs'])===70
    &&$f1['report']['p03']['unackedRouteProofCount']===70
    &&count($f1['report']['p03']['routeDeliveryProofs'])===32
    &&count($f1['next']['p03']['unackedRouteProofs'])===70,
    'persistent journal retains full burst while route deliveries are bounded');
$acked1=JVResidentEventFusion::acknowledgeRouteDelivery(
    $f1['next'],$f1['report']['p03']['routeDeliveryProofs'],$t+100
);
burstCheck(count($acked1['p03']['unackedRouteProofs'])===38,
    'first durable 32-item chunk removes only acknowledged proofs');
$p2=JVP03IncrementalTimeline::evaluate(
    $raw,$p1['next'],$t+130
);
burstCheck($p2['report']['newDirectionProofsThisRead']===0
    &&$p2['report']['newlyVerifiedDirectionProofs']===[],
    'unchanged P03 cursor offers no duplicate fresh batch');
$f2=JVResidentEventFusion::evaluate(
    $wifi,[],[],$work,$p2['report'],$acked1,$t+130
);
burstCheck(count($f2['report']['p03']['newAnonymousProofs'])===0
    &&count($f2['report']['p03']['routeDeliveryProofs'])===32,
    'remaining queued proofs are delivered even with no new camera packets');
$acked2=JVResidentEventFusion::acknowledgeRouteDelivery(
    $f2['next'],$f2['report']['p03']['routeDeliveryProofs'],$t+130
);
burstCheck(count($acked2['p03']['unackedRouteProofs'])===6,
    'second chunk preserves six outstanding proofs');
$f3=JVResidentEventFusion::evaluate(
    $wifi,[],[],$work,$p2['report'],$acked2,$t+160
);
burstCheck(count($f3['report']['p03']['routeDeliveryProofs'])===6,
    'final chunk delivered without loss');
$acked3=JVResidentEventFusion::acknowledgeRouteDelivery(
    $f3['next'],$f3['report']['p03']['routeDeliveryProofs'],$t+160
);
burstCheck(count($acked3['p03']['unackedRouteProofs'])===0,
    'all 70 verified passages acknowledged across three updates');
// A burst beyond bounded processing capacity must be visibly invalidated,
// never cut to the latest 15 and presented as a complete human movement.
$rawTooMany=$makeRaw(130);
$pTooMany=JVP03IncrementalTimeline::evaluate(
    $rawTooMany,$initialP03['next'],$t+150
);
burstCheck($pTooMany['report']['newProofBatchOverflow']===true
    &&count($pTooMany['report']['newlyVerifiedDirectionProofs'])===0
    &&in_array('P03_NEW_PROOF_BATCH_OVERFLOW',
        $pTooMany['report']['warnings'],true),
    '130-event batch not silently truncated');
$fTooMany=JVResidentEventFusion::evaluate(
    $wifi,[],[],$work,$pTooMany['report'],$initialFusion['next'],$t+150
);
burstCheck($fTooMany['report']['p03']['logInterrupted']===true
    &&count($fTooMany['report']['p03']['routeDeliveryProofs'])===0
    &&!$fTooMany['report']['mayArmAlarm'],
    'large incomplete route feed fails closed');
echo "P03 batch and durable backlog PASS\n";
