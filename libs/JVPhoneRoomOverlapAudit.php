<?php
declare(strict_types=1);

/**
 * Positive-only room-event correlation during separately observed overlap
 * of two PHONE geofence-out episodes. This is NOT resident attribution.
 *
 * An LCN GUS event may come from a guest, one of the residents without
 * a phone, a pet, sensor interference, or another cause. ZERO events cannot
 * establish an empty home even with 24h continuous polling.
 */
final class JVPhoneRoomOverlapAudit
{
    public const AUTHORITY='READ_ONLY_PHONE_AREA_VS_ANONYMOUS_GUS_ACTIVITY';
    private const MAX_EDGES=2048;
    private const MAX_PAIRS=8;
    private const ROOMS=[
        'eg_buero'=>true,'eg_flur'=>true,'eg_gaeste_wc'=>true,
        'eg_wohnen_kueche'=>true,'eg_wohnen_kamin'=>true,
        'eg_bad'=>true,'eg_schlafen'=>true,'og_flur'=>true,
        'og_ankleide'=>true,'og_ankleide_decke'=>true,
        'og_bad'=>true,'og_bad_dusche'=>true,
        'og_bad_waschtisch'=>true,'og_schlafen_1'=>true,
        'og_schlafen_2'=>true
    ];

    public static function evaluate(array $cycles,array $activity,
        array $ledger,int $now):array
    {
        $out=[
            'schema'=>1,'authority'=>self::AUTHORITY,
            'status'=>'SOURCES_UNVERIFIED',
            'scopedPhoneAreaPairs'=>0,'totalAnonymousGusEdgesDuringPairs'=>0,
            'pairsWithAnonymousHomeMotion'=>0,
            'latestPairHomeMotionCount'=>0,
            'latestPairHomeMotionRooms'=>0,
            'lastMotionInsideLatestPairAt'=>null,
            'historicRoomAndPhoneOverlap'=>[],
            'continuous24hGusMonitoring'=>false,
            'motionCannotBeAttributedToPhoneOwner'=>true,
            'noMotionCannotProveEmptyHouse'=>true,
            'phoneOutsideSharedHomeWorkGeofenceNotPersonAway'=>true,
            'personCountCertified'=>false,
            'presenceCertified'=>false,
            'mayControlAlarm'=>false,'mayControlDevices'=>false
        ];
        if($now<=0||($cycles['schema']??null)!==1
            ||($cycles['authority']??null)
                !=='READ_ONLY_PHONE_AREA_CYCLES_ONE_TO_ONE_NO_PEOPLE'
            ||($cycles['qualifiedSources']??null)!==2
            ||($activity['schema']??null)!==1
            ||($activity['authority']??null)!=='MOTION_EDGE_HISTORY_ONLY'
            ||($ledger['schema']??null)!==1
            ||!is_array($ledger['entries']??null)
            ||!array_is_list($ledger['entries'])
            ||count($ledger['entries'])>self::MAX_EDGES
            ||!is_array($activity['perSource']??null)
            ||!is_array($cycles['recentPairs']??null)
            ||!array_is_list($cycles['recentPairs'])
            ||count($cycles['recentPairs'])>self::MAX_PAIRS)
            return $out;
        $out['continuous24hGusMonitoring']=
            ($activity['full24hWindowObserved']??false)===true;
        $pairs=$cycles['recentPairs'];
        $counts=[];$roomSet=[];$lastAt=[];
        foreach($pairs as $i=>$pair){
            if(!is_array($pair)
                ||!is_int($pair['phoneAreaOverlapStartAt']??null)
                ||!is_int($pair['phoneAreaOverlapEndAt']??null)
                ||$pair['phoneAreaOverlapStartAt']<=$now-86400
                ||$pair['phoneAreaOverlapEndAt']>$now+5
                ||$pair['phoneAreaOverlapStartAt']
                    >=$pair['phoneAreaOverlapEndAt'])
                return $out;
            $counts[$i]=0;$roomSet[$i]=[];$lastAt[$i]=null;
        }
        foreach($ledger['entries'] as $event){
            if(!is_array($event)||!is_string($event['id']??null)
                ||!isset(self::ROOMS[$event['id']])
                ||!is_int($event['at']??null)
                ||$event['at']<=$now-86400
                ||$event['at']>$now+5
                ||!in_array($event['status']??null,[
                    'SINGLE_UNCERTAIN','MULTI_SENSOR_CORROBORATED',
                    'KAMIN_UNCORROBORATED','KAMIN_KITCHEN_CORROBORATED'],true))
                continue;
            $id=$event['id'];
            $source=$activity['perSource'][$id]??null;
            if(!is_array($source)||($source['zone']??null)!=='HOME')
                continue;
            // The known Kamin time filter can suppress spurious half-hour
            // motion: an UNCORROBORATED Kamin event is never corroboration.
            if($event['status']==='KAMIN_UNCORROBORATED')
                continue;
            $at=$event['at'];
            foreach($pairs as $i=>$pair){
                if($at<$pair['phoneAreaOverlapStartAt']
                    ||$at>$pair['phoneAreaOverlapEndAt'])continue;
                $counts[$i]++;$roomSet[$i][$id]=true;
                if($lastAt[$i]===null||$at>$lastAt[$i])
                    $lastAt[$i]=$at;
            }
        }
        $out['scopedPhoneAreaPairs']=count($pairs);
        $out['status']=$pairs===[]?'NO_PAIR_INTERVALS':
            'ANONYMOUS_ROOM_EVENTS_CORRELATED_TO_PHONE_INTERVALS';
        foreach($pairs as $i=>$pair){
            $out['totalAnonymousGusEdgesDuringPairs']+=$counts[$i];
            if($counts[$i]>0)$out['pairsWithAnonymousHomeMotion']++;
            if($i===0){
                $out['latestPairHomeMotionCount']=$counts[$i];
                $out['latestPairHomeMotionRooms']=count($roomSet[$i]);
                $out['lastMotionInsideLatestPairAt']=$lastAt[$i];
            }
            $out['historicRoomAndPhoneOverlap'][]=[
                'phonePairOutAt'=>$pair['start'],
                'anonymRoomEdges'=>$counts[$i],
                'distinctHomeSensors'=>count($roomSet[$i]),
                'lastAnonymousMotionAt'=>$lastAt[$i]
            ];
        }
        return $out;
    }
}
