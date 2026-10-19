<?php
declare(strict_types=1);

/**
 * Compact rolling 24h summary from the existing persistent LCN edge ledger.
 * No additional event store or long-lived Symcon variable. Periods are
 * relative ages, not site-local clock hours; the room itself remains anonymous.
 */
final class JVRoomDayDigest
{
    public const AUTHORITY='LCN_24H_ANONYMOUS_ACTIVITY_DIGEST_ONLY';
    private const ROOM_IDS=[
        'eg_buero'=>1,'eg_flur'=>1,'eg_gaeste_wc'=>1,
        'eg_wohnen_kueche'=>1,'eg_wohnen_kamin'=>1,
        'eg_bad'=>1,'eg_schlafen'=>1,
        'og_flur'=>1,'og_ankleide'=>1,'og_ankleide_decke'=>1,
        'og_bad'=>1,'og_bad_dusche'=>1,'og_bad_waschtisch'=>1,
        'og_schlafen_1'=>1,'og_schlafen_2'=>1
    ];
    public static function evaluate(array $state,array $activity,int $now):array
    {
        $out=[
            'schema'=>1,'authority'=>self::AUTHORITY,
            'status'=>'NOT_VERIFIED','complete24hCoverage'=>false,
            'motionEvents24h'=>0,'homeRoomEvents24h'=>0,
            'motionEvents6h'=>0,'motionEvents1h'=>0,
            'distinctRooms24h'=>0,'distinctRooms6h'=>0,
            'hourlyAgeBuckets24h'=>array_fill(0,24,0),
            'mostRecentRoomAt'=>null,
            'uncorroboratedKaminExcluded'=>0,
            'ledgerCapacityLimitReached'=>false,
            'unknownResidentsNotInterpreted'=>true,
            'silenceDoesNotProveNobodyHome'=>true,
            'mayCertifyHomeEmpty'=>false,'mayControlAlarm'=>false
        ];
        $entries=$state['entries']??null;
        if($now<=0||($state['schema']??null)!==1
            ||($activity['schema']??null)!==1
            ||($activity['authority']??null)!=='MOTION_EDGE_HISTORY_ONLY'
            ||!is_array($activity['perSource']??null)
            ||!is_array($entries)||!array_is_list($entries)
            ||count($entries)>2048)return $out;
        $out['status']='BOUNDED_24H_HISTORY_AVAILABLE';
        $out['complete24hCoverage']=($activity['full24hWindowObserved']??false)===true;
        $out['ledgerCapacityLimitReached']=in_array(
            'LEDGER_CAPACITY_REACHED',$activity['warnings']??[],true);
        $names=[];$six=[];
        $perSource=$activity['perSource'];
        foreach($entries as $e){
            if(!is_array($e)||!is_string($e['id']??null)
                ||!isset(self::ROOM_IDS[$e['id']])
                ||!is_int($e['at']??null)
                ||!in_array($e['status']??null,[
                    'SINGLE_UNCERTAIN','KAMIN_UNCORROBORATED',
                    'KAMIN_KITCHEN_CORROBORATED','MULTI_SENSOR_CORROBORATED'
                ],true))continue;
            $id=$e['id'];$at=$e['at'];
            if($at>$now+5||$at<=$now-86400)continue;
            if(!is_array($perSource[$id]??null)
                ||($perSource[$id]['zone']??null)!=='HOME')continue;
            $out['motionEvents24h']++;
            if($id==='eg_wohnen_kamin'
                &&$e['status']==='KAMIN_UNCORROBORATED'){
                $out['uncorroboratedKaminExcluded']++;
                continue;
            }
            $out['homeRoomEvents24h']++;
            $names[$id]=true;
            $age=max(0,$now-$at);
            $hour=min(23,intdiv($age,3600));
            $out['hourlyAgeBuckets24h'][$hour]++;
            if($age<=21600){$out['motionEvents6h']++;$six[$id]=true;}
            if($age<=3600)$out['motionEvents1h']++;
            if($out['mostRecentRoomAt']===null||$at>$out['mostRecentRoomAt'])
                $out['mostRecentRoomAt']=$at;
        }
        $out['distinctRooms24h']=count($names);
        $out['distinctRooms6h']=count($six);
        if(!$out['complete24hCoverage'])$out['status']='PARTIAL_WINDOW_NO_SILENCE_CONCLUSION';
        if($out['ledgerCapacityLimitReached'])$out['status']='CAPACITY_LIMITED_HISTORY';
        return $out;
    }
}
