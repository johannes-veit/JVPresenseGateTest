<?php
declare(strict_types=1);

/**
 * Build recent room paths from the EXISTING persisted LCN motion edge
 * ledger. Room sequence != person identity, purpose, or headcount.
 * No new Symcon entities, GPIO, cameras, power devices or network calls.
 */
final class JVResidentRoomPaths
{
    private const ROOM=[
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
    private const WINDOW=5400;  // 90m recent, no multi-status log
    private const MAX_EVENTS=80;

    public static function evaluate(array $state,array $activity,int $now):array
    {
        $out=['schema'=>1,'authority'=>'LCN_ROOM_MOTION_PATHS_ANONYMOUS_ONLY',
            'recentRoomEdges'=>[],'roomEdgeCount90m'=>0,
            'officeGuestWcReturn'=>null,'kitchenToUpperDressing'=>null,
            'kitchenLastMotionAt'=>null,'officeLastMotionAt'=>null,
            'upstairsLastMotionAt'=>null,
            'coverageStatus'=>'UNVERIFIED',
            'roomPathIsNotPersonalIdentity'=>true,
            'roomActivityCannotProveCookingOrChangingClothes'=>true,
            'powerMeterVerified'=>false,'cameraHumanVehicleLinkVerified'=>false,
            'personPresenceMayBeChanged'=>false,'alarmMayBeControlled'=>false];
        if($now<=0||($state['schema']??null)!==1
            ||!is_array($state['entries']??null)
            ||($activity['authority']??null)!=='MOTION_EDGE_HISTORY_ONLY'
            ||($activity['schema']??null)!==1)return $out;
        $source=$activity['perSource']??[];
        if(!is_array($source))return $out;
        $valid=[];$original=$state['entries'];
        if(count($original)>2048)return $out;
        foreach($original as $item){
            if(!is_array($item)||!is_string($item['id']??null)
                ||!isset(self::ROOM[$item['id']])
                ||!is_int($item['at']??null))continue;
            $at=$item['at'];$id=$item['id'];
            if($at>$now+5||$at<$now-self::WINDOW)continue;
            if(!is_array($source[$id]??null)
                ||($source[$id]['zone']??null)!=='HOME')continue;
            // Never trust just the source ID: confirm its known room name
            // with the stable live inventory's source mapping.
            $name=(string)($source[$id]['name']??'');
            $room=self::ROOM[$id];
            $normal=static fn(string $t):string=>(string)preg_replace(
                '/[^\\p{L}\\p{N}]/u','',mb_strtolower($t,'UTF-8'));
            if(!str_contains($normal($name),$normal(substr($room,3))))continue;
            $valid[$id.'@'.$at]=[
                'id'=>$id,'room'=>$room,'at'=>$at,
                // Preserve the already validated source quality so that
                // uncorroborated Kamin events remain diagnostic only.
                'status'=>is_string($item['status']??null)
                    ?$item['status']:'SINGLE_UNCERTAIN'];
        }
        usort($valid,static fn($a,$b)=>($a['at']<=>$b['at'])
            ?:strcmp($a['id'],$b['id']));
        $out['roomEdgeCount90m']=count($valid);
        $out['recentRoomEdges']=array_reverse(array_slice($valid,-self::MAX_EVENTS));
        $out['coverageStatus']='AVAILABLE_ANONYMOUS_EDGES';
        foreach($valid as $e){
            if($e['id']==='eg_wohnen_kueche')
                $out['kitchenLastMotionAt']=$e['at'];
            if($e['id']==='eg_buero')
                $out['officeLastMotionAt']=$e['at'];
            if(str_starts_with($e['id'],'og_'))
                $out['upstairsLastMotionAt']=$e['at'];
        }
        // Do not mix dates beyond the 90-minute window and do not
        // attribute anonymous GUS edges to the owner of a smartphone.
        $paths=[
            'officeGuestWcReturn'=>[
                'eg_buero','eg_flur','eg_gaeste_wc','eg_flur','eg_buero'],
            'kitchenToUpperDressing'=>[
                'eg_wohnen_kueche','og_flur','og_ankleide']
        ];
        foreach($paths as $key=>$wanted){
            $matching=[];
            foreach($valid as $e)
                if(in_array($e['id'],$wanted,true))$matching[]=$e;
            // Keep the latest completed chain. Unrelated sensors do not
            // invalidate it, but every chain hop has its own bounded time.
            for($start=0;$start<count($matching);$start++){
                if($matching[$start]['id']!==$wanted[0])continue;
                $chosen=[$matching[$start]];$position=1;
                for($k=$start+1;$k<count($matching)&&$position<count($wanted);$k++){
                    $candidate=$matching[$k];$previous=$chosen[count($chosen)-1];
                    $gap=$candidate['at']-$previous['at'];
                    if($gap>600)break;
                    if($candidate['id']===$wanted[$position]&&$gap>=1){
                        $chosen[]=$candidate;$position++;
                    }
                }
                if($position!==count($wanted))continue;
                $duration=$chosen[count($chosen)-1]['at']-$chosen[0]['at'];
                if($duration>1800)continue;
                $out[$key]=[
                    'at'=>$chosen[count($chosen)-1]['at'],
                    'startAt'=>$chosen[0]['at'],
                    'events'=>$chosen,
                    'humanIdentity'=>'UNKNOWN',
                    'activityPurpose'=>'UNKNOWN',
                    'verifiedSpatialOrder'=>true
                ];
            }
        }
        return $out;
    }
}
