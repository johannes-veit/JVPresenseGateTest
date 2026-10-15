<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVParallelPassageGuard.php';

$checked=0;
function ppCheck(mixed $actual,mixed $expected,string $label):void{
    global $checked;$checked++;
    if($actual!==$expected)throw new RuntimeException(
        "$label expected ".var_export($expected,true)." got ".var_export($actual,true)
    );
}
$now=1792040000;
$street=['directionMappingCertified'=>true,'out'=>'RightToLeft',
    'in'=>'LeftToRight'];
function timeline(array $proofs,array $warnings=[]):array {
    return [
        'schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
        'recentDirectionProofs'=>$proofs,'warnings'=>$warnings,
        'logContinuity'=>'OK'
    ];
}
function proof(int $at,string $direction='HOME_TO_LAGER'):array {
    return ['at'=>$at,'direction'=>$direction,
        'route'=>$direction==='HOME_TO_LAGER'
            ?'P03_2CAM_JV_THEN_WORK':'P03_2CAM_WORK_THEN_JV'];
}
function invariant(array $r):void{
    ppCheck($r['individualPeopleCount'],'UNKNOWN','never count people');
    ppCheck($r['individualIdentities'],'UNKNOWN','never identify persons');
    ppCheck($r['countedResidentsLeavingProperty'],'UNKNOWN','no resident count');
    ppCheck($r['propertyEmptyMayBeCertified'],false,'never certify property empty');
    ppCheck($r['houseEmptyMayBeCertified'],false,'never certify house empty');
    ppCheck($r['personPresenceMayBeChanged'],false,'no person writes');
    ppCheck($r['alarmMayBeControlled'],false,'no alarms');
    ppCheck($r['liveStreetPersonExitCountAvailable'],false,'no street live count');
    ppCheck($r['houseToWorkIsPropertyExit'],false,'house↔lager not street');
    ppCheck($r['peopleCountingModeChanged'],false,'never touch cameras');
}
$empty=JVParallelPassageGuard::evaluate(timeline([]),$street,$now);
ppCheck($empty['status'],'NO_LIVE_PASSAGES','initial baseline clean');
ppCheck($empty['propertyStreetMappingLearned'],true,
    'learning street orientation not live street exit');
invariant($empty);

// Two simultaneous humans in real life, but just ONE P03 IVS proof: unknown
// group size; must NOT invent precisely 1 or certify resident departure.
$one=JVParallelPassageGuard::evaluate(
    timeline([proof($now-1)]),$street,$now);
ppCheck($one['status'],'DIRECTION_KNOWN_PEOPLE_COUNT_UNKNOWN',
    'single crossing proof not individual count');
ppCheck(count($one['recentDirectionalGroups']),1,'one time cluster');
ppCheck($one['recentDirectionalGroups'][0]['numberOfPeople'],'UNKNOWN',
    'one crossing could contain two side by side');
ppCheck($one['recentDirectionalGroups'][0]['exactlyOnePersonProven'],false,
    'not exactly one person');
ppCheck($one['recentDirectionalGroups'][0]['exactlyTwoPeopleProven'],false,
    'not exactly two people');
ppCheck($one['recentDirectionalGroups'][0]['interpretation'],
    'ONE_PROOF_CAN_CONTAIN_MULTIPLE_PEOPLE','counterexample one event two people');
ppCheck($one['recentDirectionalGroups'][0]['isPropertyStreetExit'],false,
    'HOME→LAGER proof cannot be called street departure');
invariant($one);

// Two nearby P03 proof events might be same person reported twice, OR two
// different people. Deliberately cluster by time without a headcount.
$two=JVParallelPassageGuard::evaluate(
    timeline([proof($now-3),proof($now-10)]),$street,$now);
ppCheck(count($two['recentDirectionalGroups']),1,'nearby proofs one time cluster');
ppCheck($two['recentDirectionalGroups'][0]['directionProofEvents'],2,
    'two route events remain visible as events');
ppCheck($two['recentDirectionalGroups'][0]['numberOfPeople'],'UNKNOWN',
    'two events not two persons');
ppCheck($two['recentDirectionalGroups'][0]['interpretation'],
    'MULTIPLE_PROOFS_NOT_MULTIPLE_PEOPLE','no duplicate-person assignment');
invariant($two);

// One direction back soon after another = different directional contexts.
$opposite=JVParallelPassageGuard::evaluate(
    timeline([proof($now-3),proof($now-6,'LAGER_TO_HOME')]),$street,$now);
ppCheck(count($opposite['recentDirectionalGroups']),2,
    'opposite transfer directions not conflated');

// Two different events >15 seconds apart are independent directional
// clusters, still not two unique humans.
$apart=JVParallelPassageGuard::evaluate(
    timeline([proof($now-1),proof($now-25)]),$street,$now);
ppCheck(count($apart['recentDirectionalGroups']),2,
    'far-apart directional proof windows separate');
invariant($apart);

// P03 same-second key collisions must not silently suppress ambiguity.
$sameSecond=JVParallelPassageGuard::evaluate(
    timeline([proof($now-1)],
        ['P03_SAME_SECOND_PROOF_AMBIGUOUS']),$street,$now);
ppCheck($sameSecond['sameSecondEventAmbiguity'],true,
    'same-second event collision explicitly surfaced');
ppCheck(in_array('MULTIPLE_PERSONS_MAY_BE_HIDDEN_IN_SAME_SECOND',
    $sameSecond['warnings'],true),true,'group ambiguity visible');
invariant($sameSecond);

// Stale event, commissioning date, route mismatch, invalid direction must
// never become a live exit or create a group.
$untrusted=JVParallelPassageGuard::evaluate(timeline([
    proof($now-86401),
    ['at'=>$now-4,'direction'=>'HOME_TO_LAGER',
        'route'=>'P03_2CAM_WORK_THEN_JV'],
    ['at'=>$now-4,'direction'=>'PROPERTY_TO_STREET',
        'route'=>'P03_2CAM_JV_THEN_WORK'],
    ['at'=>$now+1000,'direction'=>'HOME_TO_LAGER',
        'route'=>'P03_2CAM_JV_THEN_WORK']
]),$street,$now);
ppCheck($untrusted['recentDirectionalGroups'],[],
    'invalid/stale/future/foreign event ignored');
invariant($untrusted);
$gap=timeline([proof($now-3)]);
$gap['logContinuity']='LOG_GAP_OR_RESET';
$broken=JVParallelPassageGuard::evaluate($gap,$street,$now);
ppCheck($broken['status'],'EVENT_HISTORY_INCOMPLETE_COUNT_UNKNOWN',
    'broken P03 stream/log cannot certify a group');
invariant($broken);
$invalid=JVParallelPassageGuard::evaluate([],[],$now);
ppCheck($invalid['status'],'INPUT_NOT_TRUSTED',
    'untrusted P03 report fails closed');
invariant($invalid);
echo "Parallel-person P03 and property boundary guard PASS ($checked checks)\n";
