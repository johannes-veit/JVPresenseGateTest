<?php
declare(strict_types=1);

/**
 * Extra anonymous room-activity context from the already validated LCN
 * motion ledger. No sensor reads, Symcon objects, writes, person tracking,
 * occupancy certification or automatic commands.
 *
 * A close timing between two PIRs does not establish that the SAME person
 * walked between them; similarly two rooms firing together is not a
 * calibrated people counter. These are observation patterns only.
 */
final class JVRoomMotionInsight
{
    public const AUTHORITY='ANONYMOUS_LCN_ROOM_ACTIVITY_CONTEXT_ONLY';
    private const WINDOW=5400;
    private const MAX_INPUT=80;
    private const DEBOUNCE=20;
    private const ROOMS=[
        'eg_buero'=>'EG Büro',
        'eg_flur'=>'EG Flur',
        'eg_gaeste_wc'=>'EG Gäste-WC',
        'eg_wohnen_kueche'=>'EG Wohnen/Küche',
        'eg_wohnen_kamin'=>'EG Wohnen Kamin',
        'eg_bad'=>'EG Bad',
        'eg_schlafen'=>'EG Schlafen',
        'og_flur'=>'OG Flur',
        'og_ankleide'=>'OG Ankleide',
        'og_ankleide_decke'=>'OG Ankleide Decke',
        'og_bad'=>'OG Bad',
        'og_bad_dusche'=>'OG Bad Dusche',
        'og_bad_waschtisch'=>'OG Bad Waschtisch',
        'og_schlafen_1'=>'OG Schlafen 1',
        'og_schlafen_2'=>'OG Schlafen 2'
    ];

    public static function evaluate(array $rooms,int $now):array
    {
        $out=[
            'schema'=>1,'authority'=>self::AUTHORITY,
            'coverage'=>'NOT_VERIFIED',
            'observationsAvailable'=>false,
            'roomEdgesSeen90m'=>null,
            'usableDebouncedEdges90m'=>0,
            'weakKaminEdgesExcluded'=>0,
            'retriggerEdgesSuppressed'=>0,
            'events5m'=>0,'events15m'=>0,
            'distinctRooms15m'=>0,
            'activeFloors15m'=>[],
            'hotRooms15m'=>[],
            'lastEGMotionAt'=>null,'lastOGMotionAt'=>null,
            'recentMotionBursts'=>[],
            'officeKitchenCloseTiming'=>false,
            'officeKitchenLastAt'=>null,
            'egOgHallSequencePossible'=>false,
            'egOgHallLastAt'=>null,
            'simultaneousSignalsAreNotTwoPeople'=>true,
            'burstsAreNotIndividualRoutes'=>true,
            'cookingOrClothesChangeProven'=>false,
            'physicalPresenceOrAbsenceCertified'=>false,
            'personCountCertified'=>false,
            'identityCertified'=>false,
            'alarmMayBeControlled'=>false
        ];
        if($now<=0||($rooms['schema']??null)!==1
            ||($rooms['authority']??null)!=='LCN_ROOM_MOTION_PATHS_ANONYMOUS_ONLY'
            ||($rooms['coverageStatus']??null)!=='AVAILABLE_ANONYMOUS_EDGES'
            ||!is_array($rooms['recentRoomEdges']??null)
            ||!array_is_list($rooms['recentRoomEdges'])
            ||count($rooms['recentRoomEdges'])>self::MAX_INPUT
            ||!is_int($rooms['roomEdgeCount90m']??null)
            ||$rooms['roomEdgeCount90m']<0)return $out;
        $out['observationsAvailable']=true;
        $out['roomEdgesSeen90m']=$rooms['roomEdgeCount90m'];
        $out['coverage']=$rooms['roomEdgeCount90m']<=count($rooms['recentRoomEdges'])
            ?'ALL_REPORTED_EDGES_90M':'LATEST_80_ROOM_EDGES_ONLY';
        $valid=[];
        foreach($rooms['recentRoomEdges'] as $edge){
            if(!is_array($edge)||!is_string($edge['id']??null)
                ||!isset(self::ROOMS[$edge['id']])
                ||($edge['room']??null)!==self::ROOMS[$edge['id']]
                ||!is_int($edge['at']??null))continue;
            $at=$edge['at'];
            if($at<$now-self::WINDOW||$at>$now+5)continue;
            if($edge['id']==='eg_wohnen_kamin'
                &&($edge['status']??null)==='KAMIN_UNCORROBORATED'){
                $out['weakKaminEdgesExcluded']++;
                continue;
            }
            $key=$edge['id'].'@'.$at;
            $valid[$key]=['id'=>$edge['id'],'room'=>$edge['room'],'at'=>$at];
        }
        if($out['weakKaminEdgesExcluded']>0
            ||count($valid)<count($rooms['recentRoomEdges']))
            $out['coverage']=$out['coverage']==='ALL_REPORTED_EDGES_90M'
                ?'ALL_REPORTED_EDGES_WITH_EXCLUSIONS'
                :$out['coverage'];
        usort($valid,static fn(array $a,array $b):int =>
            ($a['at']<=>$b['at'])?:strcmp($a['id'],$b['id']));
        $filtered=[];$lastById=[];
        foreach($valid as $edge){
            $id=$edge['id'];$at=$edge['at'];
            if(isset($lastById[$id]) &&$at-$lastById[$id]<self::DEBOUNCE){
                $out['retriggerEdgesSuppressed']++;
                continue;
            }
            $filtered[]=$edge;$lastById[$id]=$at;
        }
        $out['usableDebouncedEdges90m']=count($filtered);
        $counts=[];$floors=[];
        foreach($filtered as $edge){
            $at=$edge['at'];$id=$edge['id'];
            $floor=str_starts_with($id,'eg_')?'EG':'OG';
            $field='last'.$floor.'MotionAt';
            $out[$field]=$at;
            if($at<$now-900)continue;
            $out['events15m']++;
            if($at>=$now-300)$out['events5m']++;
            $floors[$floor]=true;
            if(!isset($counts[$id]))
                $counts[$id]=['id'=>$id,'room'=>$edge['room'],
                    'events'=>0,'lastAt'=>null];
            $counts[$id]['events']++;
            $counts[$id]['lastAt']=$at;
        }
        $out['activeFloors15m']=array_keys($floors);
        $out['distinctRooms15m']=count($counts);
        $sorted=array_values($counts);
        usort($sorted,static fn(array $a,array $b):int =>
            ($b['events']<=>$a['events'])
            ?:($b['lastAt']<=>$a['lastAt'])
            ?:strcmp($a['id'],$b['id']));
        $out['hotRooms15m']=array_slice($sorted,0,5);

        // Near-time observations from separate areas are context. They are
        // deliberately NOT proof of simultaneous occupants or one route.
        $office=[];$kitchen=[];$groundHall=[];$upperHall=[];
        foreach($filtered as $e){
            if($e['at']<$now-900)continue;
            if($e['id']==='eg_buero')$office[]=$e['at'];
            if($e['id']==='eg_wohnen_kueche')$kitchen[]=$e['at'];
            if($e['id']==='eg_flur')$groundHall[]=$e['at'];
            if($e['id']==='og_flur')$upperHall[]=$e['at'];
        }
        foreach($office as $a){
            foreach($kitchen as $b){
                if(abs($a-$b)>30)continue;
                $out['officeKitchenCloseTiming']=true;
                $out['officeKitchenLastAt']=max(
                    $out['officeKitchenLastAt']??0,$a,$b);
            }
        }
        foreach($groundHall as $a){
            foreach($upperHall as $b){
                if($b<=$a||$b-$a>180)continue;
                $out['egOgHallSequencePossible']=true;
                $out['egOgHallLastAt']=max(
                    $out['egOgHallLastAt']??0,$b);
            }
        }

        // Short chronological clusters summarize household activity. A
        // cluster may consist of multiple residents or repeated PIR triggers.
        $recent=array_values(array_filter($filtered,
            static fn(array $e):bool => $e['at'] >= $now-900));
        $groups=[];$group=[];
        foreach($recent as $edge){
            if($group!==[]&&$edge['at']-$group[count($group)-1]['at']>90){
                $groups[]=$group;$group=[];
            }
            $group[]=$edge;
        }
        if($group!==[])$groups[]=$group;
        $bursts=[];
        foreach($groups as $group){
            $ids=array_unique(array_column($group,'id'));
            if(count($group)<2||count($ids)<2)continue;
            $burstFloors=[];
            foreach($group as $edge)
                $burstFloors[str_starts_with($edge['id'],'eg_')?'EG':'OG']=true;
            $bursts[]=[
                'startAt'=>$group[0]['at'],
                'endAt'=>$group[count($group)-1]['at'],
                'edges'=>count($group),
                'distinctRooms'=>count($ids),
                'floors'=>array_keys($burstFloors),
                'humanIdentity'=>'UNKNOWN',
                'personCount'=>'UNKNOWN'
            ];
        }
        $out['recentMotionBursts']=array_slice(array_reverse($bursts),0,4);
        return $out;
    }
}
