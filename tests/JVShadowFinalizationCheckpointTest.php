<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVShadowStateCheckpoint.php';
require_once dirname(__DIR__).'/libs/JVPhoneContactContinuity.php';
require_once dirname(__DIR__).'/libs/JVP03IncrementalTimeline.php';

function finalCheckpoint(bool $v,string $reason):void{
    if(!$v)throw new RuntimeException('FINAL_CHECKPOINT '.$reason);
}
$t=1900000000;
$phone=[
    'schema'=>1,'people'=>[
        'johannes'=>[
            'startedAt'=>$t,'lastObservedAt'=>$t,'lastMacAt'=>$t-20,
            'lastGeoAt'=>$t-10,'pendingOutAt'=>null,'pendingInAt'=>null,
            'lastMacSourceKey'=>'WLAN:101:102','lastGeoSourceKey'=>'EGI:1:2:3:4',
            'lastMacSourceVerified'=>true,'lastGeoSourceVerified'=>true
        ],
        'carina'=>[
            'startedAt'=>$t,'lastObservedAt'=>$t,'lastMacAt'=>$t-25,
            'lastGeoAt'=>$t-15,'pendingOutAt'=>null,'pendingInAt'=>null,
            'lastMacSourceKey'=>'WLAN:201:202','lastGeoSourceKey'=>'GEOFENCY:5:6:7:8',
            'lastMacSourceVerified'=>true,'lastGeoSourceVerified'=>true
        ]
    ]
];
$p03=[
 'initialized'=>true,'lastObservedAt'=>$t,
 'lastRaw'=>"P03 DIAG: OK\n",'events'=>[]
];
foreach(['ResidentPhoneContinuity','P03IncrementalTimeline'] as $name){
    finalCheckpoint(JVShadowStateCheckpoint::useRuntimeCache($name),
        $name.' uses existing runtime cache and durable checkpoint');
    $origin=$name==='P03IncrementalTimeline'?$p03:$phone;
    $durable=json_encode($origin,JSON_THROW_ON_ERROR);
    $writes=1;
    $latest=$origin;
    // All of 24 hours without evidence changes; 10 minute durability
    // cadence, not 1440 writes, and no invented extra Symcon objects.
    for($m=1;$m<=1440;$m++){
        $n=$t+$m*60;
        if($name==='P03IncrementalTimeline')
            $latest['lastObservedAt']=$n;
        else foreach(['johannes','carina'] as $person)
            $latest['people'][$person]['lastObservedAt']=$n;
        $encoded=json_encode($latest,JSON_THROW_ON_ERROR);
        if(JVShadowStateCheckpoint::shouldPersist(
            $name,$durable,$encoded,$n)){
            $durable=$encoded;$writes++;
        }
    }
    finalCheckpoint($writes>=140&&$writes<=150,
        $name.' must write ~145 idle durable checkpoints/day, not 1440');
    // In a 60-second interval a semantic event MUST bypass the interval.
    $new=$latest;$n=$t+86460;
    if($name==='P03IncrementalTimeline'){
        $new['lastObservedAt']=$n;
        $new['lastRaw'].="P03 PROOF OBSERVED\n";
    }else{
        $new['people']['carina']['lastObservedAt']=$n;
        $new['people']['johannes']['lastObservedAt']=$n;
        $new['people']['johannes']['pendingOutAt']=$n;
    }
    finalCheckpoint(JVShadowStateCheckpoint::shouldPersist(
        $name,$durable,json_encode($new),$n),
        $name.' new source event is durable immediately');
    $rebound=$latest;
    if($name==='P03IncrementalTimeline')
        $rebound['lastObservedAt']=$t+86000;
    else foreach(['johannes','carina'] as $person)
        $rebound['people'][$person]['lastObservedAt']=$t+86000;
    finalCheckpoint(JVShadowStateCheckpoint::shouldPersist(
        $name,$durable,json_encode($rebound),$n),
        $name.' backward time is never concealed by cache');
}
$missing=$phone;
unset($missing['people']['carina']['lastObservedAt']);
finalCheckpoint(JVShadowStateCheckpoint::shouldPersist(
    'ResidentPhoneContinuity',json_encode($phone),json_encode($missing),$t+60),
    'missing one person clock cannot silently skip a persistence update');

// A source-missing phone history is stable after initialization,
// instead of starting a new epoch on every one-minute refresh.
$unverifiedW=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>[]];
$unverifiedJ=['schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
    'enabled'=>true,'validatedSource'=>false];
$unverifiedC=['schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
    'enabled'=>true,'validatedSource'=>false];
$first=JVPhoneContactContinuity::evaluate($unverifiedW,$unverifiedJ,
    $unverifiedC,[],$t);
$second=JVPhoneContactContinuity::evaluate($unverifiedW,$unverifiedJ,
    $unverifiedC,$first['next'],$t+60);
$third=JVPhoneContactContinuity::evaluate($unverifiedW,$unverifiedJ,
    $unverifiedC,$second['next'],$t+120);
finalCheckpoint(
    $second['next']['people']['johannes']['startedAt']===$t
    &&$third['next']['people']['johannes']['startedAt']===$t
    &&$third['next']['people']['carina']['startedAt']===$t,
    'missing sources do not force new phone epoch at each poll');
finalCheckpoint(!JVShadowStateCheckpoint::shouldPersist(
    'ResidentPhoneContinuity',json_encode($first['next']),
    json_encode($second['next']),$t+60),
    'no durable write solely for missing-source observation heartbeat');
$verifiedW=$unverifiedW;
$verifiedW['people']['johannes']=[
    'validatedExistingVariablePair'=>true,'wlanBoolean'=>true,
    'recentMacEvidence'=>true,'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
    'lastMacContactAt'=>$t+140,'sourceKey'=>'WLAN:101:102'
];
$bound=JVPhoneContactContinuity::evaluate(
    $verifiedW,$unverifiedJ,$unverifiedC,$third['next'],$t+150);
finalCheckpoint($bound['next']['people']['johannes']['startedAt']===$t+150
    &&JVShadowStateCheckpoint::shouldPersist(
        'ResidentPhoneContinuity',json_encode($third['next']),
        json_encode($bound['next']),$t+150)
    &&!$bound['report']['anyHumanArrivalCertified'],
    'rebound verified source updates checkpoint immediately, no human arrival');

echo "Final phone/P03 24h flash simulation and durable semantic event checkpoints PASS\n";
