<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVRouteEpisodeLedger.php';
require_once dirname(__DIR__).'/libs/JVResidentJourneyEvidence.php';
function assertMulti(bool $ok,string $reason):void{
    if(!$ok)throw new RuntimeException('MULTIPERSON_ROUTE '.$reason);
}
$t=1800000000;
$proof=static fn(string $direction,int $at):array=>[
    'source'=>'P03_TWO_MAST_VERIFIED',
    'direction'=>$direction,'at'=>$at,
    'route'=>$direction==='HOME_TO_LAGER'
        ?'P03_2CAM_JV_THEN_WORK':'P03_2CAM_WORK_THEN_JV'
];
$fusion=['schema'=>1,
    'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>[
        'johannes'=>['lastMacAt'=>$t-100,'eventHistory'=>[],
            'macSourceContinuous'=>true],
        'carina'=>['lastMacAt'=>$t-101,'eventHistory'=>[],
            'macSourceContinuous'=>true]
    ],
    'p03'=>['newAnonymousProofs'=>[]]];
$boundary=['schema'=>1,'authority'=>'READ_ONLY_BOUNDARY_CONTEXT',
    'vehicleSignals'=>1,'recentChanges'=>[
        ['kind'=>'VEHICLE','state'=>'ACTIVE','at'=>$t+315]
    ]];
$rooms=['authority'=>'LCN_ROOM_MOTION_PATHS_ANONYMOUS_ONLY',
    'recentRoomEdges'=>[]];
$timing=['authority'=>'READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY',
    'p03OperatingMode'=>'TEST_ACTIVE',
    'p03ModeTrustedFromFreshDiagnosis'=>true];
$work=['schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'enabled'=>true,'people'=>[]];
$run=static function(array $previous,int $now)use(
    &$fusion,&$boundary,&$rooms,&$timing
):array{
    return JVRouteEpisodeLedger::evaluate(
        $fusion,$boundary,$rooms,$timing,$previous,$now
    );
};
$phone=static function(array $route,int $now)use(
    &$fusion,&$work,&$boundary
):array{
    return JVResidentJourneyEvidence::evaluate(
        $route['report'],$fusion,$work,$boundary,$now
    );
};
$start=$run([],$t);
// Two potentially different anonymous travellers leave at separate times.
$fusion['p03']['newAnonymousProofs']=[
    $proof('HOME_TO_LAGER',$t+60),
    $proof('HOME_TO_LAGER',$t+75)
];
$twoOut=$run($start['next'],$t+90);
assertMulti($twoOut['report']['openOutgoingPassages']===2,
    'two distinct OUT proofs are not two identified residents');
// A single IN leaves the entire earlier sequence uncertain.
$fusion['p03']['newAnonymousProofs']=[$proof('LAGER_TO_HOME',$t+110)];
$oneIn=$run($twoOut['next'],$t+120);
assertMulti(!$oneIn['report']['newEpisodeThisRefresh']
    &&$oneIn['report']['ambiguityResetThisRefresh']
    &&$oneIn['report']['openOutgoingPassages']===0,
    'unpaired multiple human crossings never yield arbitrary FIFO route');
assertMulti($phone($oneIn,$t+120)['candidateCount']===0,
    'both home WLAN MACs remain online and identify neither walker');
// Another IN alone cannot inherit either ambiguous OUT.
$fusion['p03']['newAnonymousProofs']=[$proof('LAGER_TO_HOME',$t+155)];
$extraIn=$run($oneIn['next'],$t+165);
assertMulti(!$extraIn['report']['newEpisodeThisRefresh']
    &&in_array('IN_WITHOUT_OBSERVED_OUT',
        $extraIn['report']['warnings'],true),
    'second anonymous return does not retroactively resolve two-person route');
// Independent OUT→IN after the ambiguity is processed normally.
$fusion['p03']['newAnonymousProofs']=[
    $proof('HOME_TO_LAGER',$t+250),
    $proof('LAGER_TO_HOME',$t+365)
];
$clear=$run($extraIn['next'],$t+380);
assertMulti($clear['report']['newEpisodeThisRefresh']
    &&$clear['report']['lastEpisode']['outAt']===$t+250
    &&$clear['report']['lastEpisode']['inAt']===$t+365
    &&$clear['report']['lastEpisode']['humanCount']==='UNKNOWN'
    &&!$clear['report']['samePersonRoundTripCertified'],
    'later independent camera route works without identifying the walker');
$noPhone=$phone($clear,$t+380);
assertMulti($noPhone['candidateCount']===0
    &&!$noPhone['vehicleJourneyCertified']
    &&!$noPhone['humanPresenceCertified'],
    'nearby vehicle switch + two idle phones do not invent journey');
// Genuine CARINA handset OUT→IN→new-home-MAC near route is still only
// a candidate, not proof that this was the camera-visible person.
$fusion['people']['carina']['eventHistory']=[[
    'code'=>'PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC','at'=>$t+390
]];
$one=$phone($clear,$t+400);
assertMulti($one['candidateCount']===1
    &&$one['candidatePerson']==='carina'
    &&$one['phoneCandidates'][0]['identityProof']===false
    &&!$one['identityAssigned']
    &&!$one['vehicleJourneyCertified']&&!$one['mayArmAlarm'],
    'single later handset event may be temporally associated but never camera identified');
$fusion['people']['johannes']['eventHistory']=[[
    'code'=>'PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC','at'=>$t+391
]];
$both=$phone($clear,$t+400);
assertMulti($both['candidateCount']===2
    &&$both['candidatePerson']===null
    &&$both['status']==='MULTIPLE_PHONE_TIMELINES_CANNOT_ATTRIBUTE_ROUTE',
    'two phone returns near one anonymous route remain ambiguous');
$fusion['p03']['newAnonymousProofs']=[];
$repeat=$run($clear['next'],$t+420);
assertMulti(!$repeat['report']['newEpisodeThisRefresh']
    &&$repeat['report']['newPassagesThisRefresh']===0,
    'replaying same saved corridor does not create a second journey');
echo "Multiperson ambiguous route recovery and phone privacy PASS\n";
