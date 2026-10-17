<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVShadowStateCheckpoint.php';
function checkpointOK(bool $v,string $reason):void{
    if(!$v)throw new RuntimeException('CHECKPOINT '.$reason);
}
$t=1793000000;
$make=static function(int $at):array{
    return [
        'schema'=>1,
        'people'=>[
            'johannes'=>['lastObservedAt'=>$at,'lastMacAt'=>$at-90,
                'lastEvent'=>null,'lastGeoAt'=>null],
            'carina'=>['lastObservedAt'=>$at,'lastMacAt'=>$at-120,
                'lastEvent'=>null,'lastGeoAt'=>null]
        ],
        'p03'=>['lastReadAt'=>$at,'seen'=>[],
            'highWaterAt'=>$at-200,'lastAnonymous'=>null]
    ];
};
$old=json_encode($make($t),JSON_THROW_ON_ERROR);
$new=json_encode($make($t+60),JSON_THROW_ON_ERROR);
checkpointOK(JVShadowStateCheckpoint::useRuntimeCache('ResidentEventFusion')
    &&JVShadowStateCheckpoint::useRuntimeCache('RouteEpisodeLedger')
    &&!JVShadowStateCheckpoint::useRuntimeCache('P03IncrementalTimeline'),
    'only safe bounded state ledgers use runtime cache');
checkpointOK(!JVShadowStateCheckpoint::shouldPersist(
    'ResidentEventFusion',$old,$old,$t+60),'equal state no write');
checkpointOK(!JVShadowStateCheckpoint::shouldPersist(
    'ResidentEventFusion',$old,$new,$t+60),'heartbeat only no flash write');
checkpointOK(JVShadowStateCheckpoint::shouldPersist(
    'ResidentEventFusion',$old,$new,$t+660),
    'after checkpoint timeout heartbeat becomes durable');
$event=$make($t+60);
$event['people']['johannes']['lastEvent']=[
    'code'=>'PHONE_GEOFENCE_OUT_OBSERVED','at'=>$t+60
];
checkpointOK(JVShadowStateCheckpoint::shouldPersist(
    'ResidentEventFusion',$old,json_encode($event),$t+60),
    'changed personal phone event persisted immediately');
$proof=$make($t+60);
$proof['p03']['highWaterAt']=$t+30;
$proof['p03']['seen']=['proof1'=>$t+30];
checkpointOK(JVShadowStateCheckpoint::shouldPersist(
    'ResidentEventFusion',$old,json_encode($proof),$t+60),
    'changed P03 proof watermark persisted immediately');
$routeOld=['schema'=>1,'initialized'=>true,'lastScanAt'=>$t,
    'seen'=>[],'open'=>[],'lastEpisode'=>null,'lastPassage'=>null];
$routeLater=$routeOld;
$routeLater['lastScanAt']=$t+60;
checkpointOK(!JVShadowStateCheckpoint::shouldPersist(
    'RouteEpisodeLedger',json_encode($routeOld),
    json_encode($routeLater),$t+60),'route heartbeat no write');
$routeLater['open']=[['at'=>$t+30,'source'=>'P03_TWO_MAST_VERIFIED',
    'direction'=>'HOME_TO_LAGER','route'=>'P03_2CAM_JV_THEN_WORK']];
checkpointOK(JVShadowStateCheckpoint::shouldPersist(
    'RouteEpisodeLedger',json_encode($routeOld),
    json_encode($routeLater),$t+60),'new route must be durable');
checkpointOK(JVShadowStateCheckpoint::shouldPersist(
    'RouteEpisodeLedger','{invalid',json_encode($routeLater),$t+60),
    'corrupt previous attribute recover by durable write');
checkpointOK(JVShadowStateCheckpoint::shouldPersist(
    'ResidentEventFusion',$old,'{"schema":2}', $t+60),
    'new schema never silently skipped');
checkpointOK(JVShadowStateCheckpoint::shouldPersist(
    'P03IncrementalTimeline','old','new',$t+60),
    'P03 raw changes are persisted immediately');
echo "SHADOW persistence policy PASS\n";
