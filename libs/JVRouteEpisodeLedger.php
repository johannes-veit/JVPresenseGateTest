<?php
declare(strict_types=1);

/**
 * Restart-safe anonymous P03 trip episodes with separate room/vehicle context.
 * A camera "human" event can cover several people; pairing opposite passes
 * proves a route pattern, NEVER the same individual or a journey by car.
 * No Symcon, network, camera, gate or alarm writes.
 */
final class JVRouteEpisodeLedger
{
    private const WINDOW=86400;
    private const MAX_TRIP=43200;
    private const MAX_GAP=3600;
    private const MAX_OPEN=8;
    private const MAX_SEEN=64;
    private const VEHICLE_NEAR=180;
    private const ROOM_NEAR=240;

    public static function evaluate(
        array $fusion,array $boundary,array $rooms,array $timing,
        array $prior,int $now
    ):array {
        $report=[
            'schema'=>1,'authority'=>'ANONYMOUS_ROUTE_EPISODES_READ_ONLY',
            'lastEpisode'=>null,'lastPassage'=>null,
            'newPassagesThisRefresh'=>0,'openOutgoingPassages'=>0,
            'newEpisodeThisRefresh'=>false,'vehicleSignalCoverage'=>'UNKNOWN',
            'personIdentityCertified'=>false,'samePersonRoundTripCertified'=>false,
            'vehicleJourneyCertified'=>false,'travelModeCertified'=>false,
            'propertyExitCertified'=>false,'houseEmptyCertified'=>false,
            'mayArmAlarm'=>false,'maySwitchDevices'=>false,
            'warnings'=>[]
        ];
        if($now<=0)return ['report'=>$report,'next'=>$prior];
        $fusionOK=($fusion['schema']??null)===1
            &&($fusion['authority']??null)==='PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY';
        $p03=$fusionOK&&is_array($fusion['p03']??null)?$fusion['p03']:[];
        $boundaryOK=($boundary['schema']??null)===1
            &&($boundary['authority']??null)==='READ_ONLY_BOUNDARY_CONTEXT';
        $report['vehicleSignalCoverage']=$boundaryOK
            ?(($boundary['vehicleSignals']??0)>0
                ?'PARTIAL_VEHICLE_VARIABLES_UNQUALIFIED_DIRECTION'
                :'NO_VERIFIED_VEHICLE_SIGNAL_CONFIGURED')
            :'BOUNDARY_SOURCE_NOT_VERIFIED';
        $oldScan=self::time($prior['lastScanAt']??null,$now);
        $continuous=($prior['schema']??null)===1
            &&($prior['initialized']??false)===true
            &&$oldScan!==null&&$now-$oldScan<=self::MAX_GAP;
        $seen=[];$open=[];
        $episode=self::safeEpisode($prior['lastEpisode']??null,$now);
        $lastPassage=self::safePassage($prior['lastPassage']??null,$now);
        if($continuous){
            foreach(($prior['seen']??[]) as $key=>$at){
                if(is_string($key)&&strlen($key)<=64
                    &&self::time($at,$now)!==null)$seen[$key]=$at;
            }
            foreach(($prior['open']??[]) as $candidate){
                $passed=self::safePassage($candidate,$now);
                if($passed!==null &&$passed['direction']==='HOME_TO_LAGER'
                    &&$now-$passed['at']<=self::MAX_TRIP
                    &&count($open)<self::MAX_OPEN)$open[]=$passed;
            }
        }elseif(($prior['initialized']??false)===true){
            $report['warnings'][]='AFTER_LONG_GAP_OPEN_ROUTES_RESET';
        }

        $new=$fusionOK&&is_array($p03['newAnonymousProofs']??null)
            ?array_slice($p03['newAnonymousProofs'],0,32):[];
        usort($new,static fn($a,$b)=>($a['at']??0)<=>($b['at']??0));
        foreach($new as $proof){
            $pass=self::safePassage($proof,$now);
            if($pass===null)continue;
            // A new proof must be observed promptly; do not recreate trips
            // from an old log or after a long observer outage.
            if($now-$pass['at']>600)continue;
            $key=$pass['at'].'|'.$pass['direction'];
            if(isset($seen[$key]))continue;
            $seen[$key]=$pass['at'];
            $lastPassage=$pass;
            $report['newPassagesThisRefresh']++;
            if(!$continuous)continue; // initial snapshot is ONLY baseline
            if($pass['direction']==='HOME_TO_LAGER'){
                if(count($open)>=self::MAX_OPEN){
                    $report['warnings'][]='TOO_MANY_UNMATCHED_OUT_TRIPS';
                    continue;
                }
                $open[]=$pass;
                continue;
            }
            if(count($open)===1){
                $out=$open[0];
                $duration=$pass['at']-$out['at'];
                if($duration>0&&$duration<=self::MAX_TRIP){
                    $episode=self::buildEpisode(
                        $out,$pass,$boundary,$boundaryOK,$rooms,$timing,$now
                    );
                    $report['newEpisodeThisRefresh']=true;
                    $open=[];
                }else{
                    $report['warnings'][]='IN_OUT_TIME_ORDER_INVALID';
                }
            }elseif(count($open)>1){
                // No arbitrary FIFO attribution when several people/crossings
                // have overlapping OUT passages. No unique pairing possible.
                $report['warnings'][]='MULTIPLE_OUT_CANNOT_PAIR_UNIQUE_RETURN';
            }else{
                $report['warnings'][]='IN_WITHOUT_OBSERVED_OUT';
            }
        }
        arsort($seen,SORT_NUMERIC);
        $seen=array_slice($seen,0,self::MAX_SEEN,true);
        $report['lastEpisode']=$episode;
        $report['lastPassage']=$lastPassage;
        $report['openOutgoingPassages']=count($open);
        $report['warnings']=array_values(array_unique($report['warnings']));
        $next=['schema'=>1,'initialized'=>true,'lastScanAt'=>$now,
            'seen'=>$seen,'open'=>$open,
            'lastEpisode'=>$episode,'lastPassage'=>$lastPassage];
        return ['report'=>$report,'next'=>$next];
    }

    private static function buildEpisode(
        array $out,array $in,array $boundary,bool $boundaryOK,
        array $rooms,array $timing,int $now
    ):array {
        $vehicleNear=false;$gateNear=false;
        if($boundaryOK&&is_array($boundary['recentChanges']??null)){
            foreach(array_slice($boundary['recentChanges'],0,12) as $e){
                if(!is_array($e)||!is_int($e['at']??null)
                    ||($e['state']??null)!=='ACTIVE')continue;
                $distance=min(abs($e['at']-$out['at']),
                    abs($e['at']-$in['at']));
                if($distance>self::VEHICLE_NEAR)continue;
                if(($e['kind']??null)==='VEHICLE')$vehicleNear=true;
                if(($e['kind']??null)==='GATE')$gateNear=true;
            }
        }
        $beforeRoom=false;$afterRoom=false;
        if(($rooms['authority']??null)==='LCN_ROOM_MOTION_PATHS_ANONYMOUS_ONLY'
            &&is_array($rooms['recentRoomEdges']??null)){
            foreach(array_slice($rooms['recentRoomEdges'],0,80) as $edge){
                if(!is_array($edge)||!is_int($edge['at']??null))continue;
                $t=$edge['at'];$id=$edge['id']??null;
                if(!in_array($id,['eg_flur','eg_wohnen_kueche',
                    'eg_buero','eg_wohnen_kamin'],true))continue;
                if($t<=$out['at']&&$t>=$out['at']-self::ROOM_NEAR)
                    $beforeRoom=true;
                if($t>=$in['at']&&$t<=$in['at']+self::ROOM_NEAR)
                    $afterRoom=true;
            }
        }
        // Street Rule 5 is HUMAN raw only, never a verified vehicle count.
        $streetNear=false;
        if(($timing['authority']??null)==='READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY'
            &&is_array($timing['lastStreetIVS']??null)
            &&is_int($timing['lastStreetIVS']['at']??null)){
            $street=$timing['lastStreetIVS']['at'];
            $streetNear=min(abs($street-$out['at']),
                abs($street-$in['at']))<=self::VEHICLE_NEAR;
        }
        return [
            'kind'=>'ANONYMOUS_OUT_IN_CORRIDOR_SEQUENCE',
            'outAt'=>$out['at'],'inAt'=>$in['at'],
            'durationSeconds'=>$in['at']-$out['at'],
            'anonymousIndoorMotionBeforeOut'=>$beforeRoom,
            'anonymousIndoorMotionAfterIn'=>$afterRoom,
            'rawStreetHumanNearby'=>$streetNear,
            'vehicleVariableActiveNearby'=>$vehicleNear,
            'gateVariableActiveNearby'=>$gateNear,
            'humanCount'=>'UNKNOWN','samePersonConfirmed'=>false,
            'identity'=>'UNKNOWN','transport'=>'UNVERIFIED',
            'vehicleDirection'=>'UNKNOWN',
            'propertyExit'=>'UNVERIFIED','houseEmpty'=>false
        ];
    }

    private static function time(mixed $at,int $now):?int
    {
        return is_int($at)&&$at>0&&$at<=$now+5
            &&$at>=$now-self::WINDOW?$at:null;
    }

    private static function safePassage(mixed $p,int $now):?array
    {
        if(!is_array($p)
            ||($p['source']??null)!=='P03_TWO_MAST_VERIFIED'
            ||!in_array($p['direction']??null,
                ['HOME_TO_LAGER','LAGER_TO_HOME'],true)
            ||self::time($p['at']??null,$now)===null)return null;
        $route=$p['direction']==='HOME_TO_LAGER'
            ?'P03_2CAM_JV_THEN_WORK':'P03_2CAM_WORK_THEN_JV';
        if(($p['route']??$route)!==$route)return null;
        return ['source'=>'P03_TWO_MAST_VERIFIED',
            'direction'=>$p['direction'],'route'=>$route,'at'=>$p['at']];
    }

    private static function safeEpisode(mixed $ep,int $now):?array
    {
        if(!is_array($ep)||($ep['kind']??null)!=='ANONYMOUS_OUT_IN_CORRIDOR_SEQUENCE'
            ||self::time($ep['inAt']??null,$now)===null
            ||!is_int($ep['outAt']??null)
            ||$ep['outAt']>=$ep['inAt']
            ||$ep['inAt']-$ep['outAt']>self::MAX_TRIP)return null;
        // Reconstruct only whitelisted safe output fields.
        $allowed=[
            'anonymousIndoorMotionBeforeOut','anonymousIndoorMotionAfterIn',
            'rawStreetHumanNearby','vehicleVariableActiveNearby',
            'gateVariableActiveNearby'
        ];
        $out=[
            'kind'=>'ANONYMOUS_OUT_IN_CORRIDOR_SEQUENCE',
            'outAt'=>$ep['outAt'],'inAt'=>$ep['inAt'],
            'durationSeconds'=>$ep['inAt']-$ep['outAt'],
            'humanCount'=>'UNKNOWN','samePersonConfirmed'=>false,
            'identity'=>'UNKNOWN','transport'=>'UNVERIFIED',
            'vehicleDirection'=>'UNKNOWN','propertyExit'=>'UNVERIFIED',
            'houseEmpty'=>false
        ];
        foreach($allowed as $k)$out[$k]=($ep[$k]??false)===true;
        return $out;
    }
}
