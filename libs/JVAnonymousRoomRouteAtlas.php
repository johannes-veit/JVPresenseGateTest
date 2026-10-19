<?php
declare(strict_types=1);

/**
 * Bounded, anonymous room transition templates. Temporal order is not a
 * person track: independent people may trigger different PIRs.
 * Input is ONLY the in-memory, name-validated JVResidentRoomPaths ledger.
 */
final class JVAnonymousRoomRouteAtlas
{
    public const AUTHORITY='ANONYMOUS_LCN_TEMPORAL_ROOM_SEQUENCE_ONLY';
    private const MAX_EDGES=2048;
    private const HOP=180;
    private const TOTAL=600;
    private const PATTERNS=[
        'KITCHEN_EG_OG'=>['eg_wohnen_kueche','eg_flur','og_flur'],
        'OG_EG_KITCHEN'=>['og_flur','eg_flur','eg_wohnen_kueche'],
        'EG_TO_OG_BEDROOM1'=>['eg_flur','og_flur','og_schlafen_1'],
        'OG_BEDROOM1_TO_KITCHEN'=>[
            'og_schlafen_1','og_flur','eg_flur','eg_wohnen_kueche'],
        'OFFICE_TO_KITCHEN'=>['eg_buero','eg_flur','eg_wohnen_kueche'],
        'KITCHEN_TO_OFFICE'=>['eg_wohnen_kueche','eg_flur','eg_buero'],
        'KITCHEN_TO_OG_DRESSING'=>[
            'eg_wohnen_kueche','eg_flur','og_flur','og_ankleide']
    ];
    public static function evaluate(array $roomPaths,array $all,int $now):array
    {
        $out=[
            'schema'=>1,'authority'=>self::AUTHORITY,'status'=>'NOT_VERIFIED',
            'validatedRoomEvents90m'=>null,'patternsFound'=>0,
            'latestPatterns'=>[],
            'eventsFromDifferentPeopleMayOverlap'=>true,
            'sameIndividualNotVerified'=>true,
            'actualRoomTransitionNotVerified'=>true,
            'personCountCertified'=>false,
            'personIdentityCertified'=>false,
            'homeEmptyCertified'=>false,
            'alarmMayBeControlled'=>false
        ];
        if($now<=0 ||($roomPaths['schema']??null)!==1
            ||($roomPaths['authority']??null)!=='LCN_ROOM_MOTION_PATHS_ANONYMOUS_ONLY'
            ||($roomPaths['coverageStatus']??null)!=='AVAILABLE_ANONYMOUS_EDGES'
            ||!is_int($roomPaths['roomEdgeCount90m']??null)
            ||!is_array($all)||!array_is_list($all)
            ||count($all)!==$roomPaths['roomEdgeCount90m']
            ||count($all)>self::MAX_EDGES)return $out;
        $valid=[];$previous=-1;
        foreach($all as $row){
            if(!is_array($row)||!is_string($row['id']??null)
                ||!is_int($row['at']??null)
                ||$row['at']<$now-5400||$row['at']>$now+5
                ||$row['at']<$previous
                ||!in_array($row['status']??null,[
                    'SINGLE_UNCERTAIN','KAMIN_UNCORROBORATED',
                    'KAMIN_KITCHEN_CORROBORATED','MULTI_SENSOR_CORROBORATED'
                ],true))return $out;
            $previous=$row['at'];
            // Already name-validated by the room path producer. Only listed
            // keysets from the topology are allowed into route evidence.
            if(!in_array($row['id'],array_merge(...array_values(self::PATTERNS)),true))
                continue;
            if($row['status']==='KAMIN_UNCORROBORATED')continue;
            $valid[]=['id'=>$row['id'],'at'=>$row['at']];
        }
        $out['status']='ANONYMOUS_TEMPORAL_HISTORY_AVAILABLE';
        $out['validatedRoomEvents90m']=count($all);
        $found=[];
        foreach(self::PATTERNS as $kind=>$wanted){
            $matching=[];
            foreach($valid as $edge)
                if(in_array($edge['id'],$wanted,true))$matching[]=$edge;
            $latest=null;
            $n=count($matching);
            for($start=0;$start<$n;$start++){
                if($matching[$start]['id']!==$wanted[0])continue;
                $seq=[$matching[$start]];$next=1;
                for($j=$start+1;$j<$n&&$next<count($wanted);$j++){
                    $e=$matching[$j];
                    $last=$seq[count($seq)-1]['at'];
                    if($e['at']-$last>self::HOP
                        ||$e['at']-$seq[0]['at']>self::TOTAL)break;
                    if($e['at']>$last&&$e['id']===$wanted[$next]){
                        $seq[]=$e;$next++;
                    }
                }
                if($next===count($wanted)){
                    $candidate=[
                        'pattern'=>$kind,
                        'startAt'=>$seq[0]['at'],
                        'endAt'=>$seq[count($seq)-1]['at'],
                        'durationSeconds'=>$seq[count($seq)-1]['at']-$seq[0]['at'],
                        'observedKeys'=>$wanted,
                        'status'=>'TIME_ORDER_POSSIBLE_NOT_PERSON_ROUTE'
                    ];
                    if($latest===null||$candidate['endAt']>$latest['endAt'])
                        $latest=$candidate;
                }
            }
            if($latest!==null)$found[]=$latest;
        }
        usort($found,static fn(array $a,array $b):int =>
            ($b['endAt']<=>$a['endAt'])
            ?:strcmp($a['pattern'],$b['pattern']));
        $out['patternsFound']=count($found);
        $out['latestPatterns']=array_slice($found,0,5);
        return $out;
    }
}
