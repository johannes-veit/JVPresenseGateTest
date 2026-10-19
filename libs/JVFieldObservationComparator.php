<?php
declare(strict_types=1);

/**
 * Compare a separately reported real-world observation with already
 * published, read-only SHADOW evidence. A user-reported fact is NOT a
 * sensor-derived fact: never inject it into the runtime presence model.
 */
final class JVFieldObservationComparator
{
    private const MAX_WINDOW_SECONDS=900;
    private const MAX_HISTORY_SECONDS=86400;
    private const ALLOWED_PEOPLE=['johannes','carina'];

    public static function compare(array $field,array $evidence,int $now):array
    {
        $out=[
            'schema'=>1,'authority'=>'USER_FIELD_TRUTH_VS_SHADOW_EVIDENCE_READ_ONLY',
            'status'=>'NOT_COMPARABLE','referenceAt'=>null,
            'referencePeople'=>[],
            'p03AnonymousOutNearReference'=>0,
            'p03AnonymousInNearReference'=>0,
            'p03CorridorCoverage'=>'NOT_VERIFIED',
            'p03VisibleHistoryCount'=>0,
            'p03Total24h'=>null,
            'p03HistoryCoverage'=>'UNVERIFIED',
            'p03LogContinuity'=>'UNVERIFIED',
            'sourceSnapshotAt'=>null,
            'sourceSnapshotFreshness'=>'UNVERIFIED',
            'phoneHistoryCoverage'=>[],
            'phoneEventNearReference'=>[],
            'vehicleIdentity'=>'USER_REPORTED_NOT_CAMERA_IDENTIFIED',
            'reportedVehicle'=>null,
            'sameNumberOfPeopleObserved'=>'NOT_PROVABLE',
            'houseExitVerifiedBySensors'=>false,
            'peopleIdentifiedBySensors'=>false,
            'carTripVerifiedBySensors'=>false,
            'allOtherOccupantsAbsentCertified'=>false,
            'alarmMayBeArmed'=>false,
            'notes'=>[]
        ];
        if($now<=0||($field['schema']??null)!==1
            ||($field['authority']??null)!=='USER_REPORTED_FIELD_OBSERVATION')
            return $out;
        $local=$field['localTime']??null;
        if(!is_string($local)
            ||!preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2} [0-2][0-9]:[0-5][0-9]$/D',$local))
            return $out;
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i',
            $local,new DateTimeZone('Europe/Berlin'));
        if(!$dt||$dt->format('Y-m-d H:i')!==$local)return $out;
        $at=$dt->getTimestamp();
        if($at>$now+300)return $out;
        $people=$field['reportedHouseLeavers']??null;
        if(!is_array($people)||!array_is_list($people)
            ||count($people)===0||count($people)>2
            ||count(array_unique($people))!==count($people))return $out;
        foreach($people as $p)if(!in_array($p,self::ALLOWED_PEOPLE,true))
            return $out;
        $out['referenceAt']=$at;
        $out['referencePeople']=$people;
        $car=$field['knownVehicleContext']??null;
        if(is_array($car)
            &&in_array($car['ownerOrDriver']??null,
                self::ALLOWED_PEOPLE,true)
            &&is_string($car['makeModel']??null)
            &&is_string($car['color']??null)
            &&strlen($car['makeModel'])>0
            &&strlen($car['makeModel'])<=80
            &&strlen($car['color'])>0
            &&strlen($car['color'])<=40)
            $out['reportedVehicle']=[
                'person'=>$car['ownerOrDriver'],
                // Do not put a resident's vehicle details into a public
                // repo fixture, diagnostic report or returned proof.
                'descriptionHeldByObserver'=>true,
                'usedForThisDeparture'=>'NOT_REPORTED'
            ];
        if($now-$at>self::MAX_HISTORY_SECONDS){
            $out['status']='REFERENCE_OUTSIDE_24H_SOURCE_WINDOW';
            $out['notes'][]='NO_COMPARABLE_HISTORICAL_TELEMETRY';
            return $out;
        }
        if(($evidence['schema']??null)!==1
            ||($evidence['authority']??null)
                !=='SHADOW_DIRECTION_PHONE_AND_LOCAL_HINTS'){
            $out['notes'][]='SHADOW_EVIDENCE_SOURCE_UNVERIFIED';
            return $out;
        }
        $capturedAt=$evidence['snapshotAt']??null;
        if(is_int($capturedAt)&&$capturedAt>0
            &&$capturedAt<=$now+300){
            $out['sourceSnapshotAt']=$capturedAt;
            if($capturedAt<$at){
                $out['sourceSnapshotFreshness']='PREDATES_REPORTED_EVENT';
                $out['status']='SNAPSHOT_TOO_OLD_FOR_REFERENCE';
                $out['notes'][]='REQUEST_NEWER_EVIDENCE_NOT_RETROACTIVE_CERTIFICATION';
                return $out;
            }
            $out['sourceSnapshotFreshness']='RECORDED_AFTER_REFERENCE';
        }else{
            $out['sourceSnapshotFreshness']='LEGACY_SNAPSHOT_TIME_NOT_AVAILABLE';
        }
        $p03=$evidence['p03LiveTimeline']??null;
        $p03OK=is_array($p03)&&($p03['schema']??null)===1
            &&($p03['authority']??null)==='P03_LIVE_PROOFS_DIRECTION_ONLY';
        if($p03OK &&is_array($p03['recentDirectionProofs']??null)){
            $out['p03CorridorCoverage']='ONLY_CAMERA_CORRIDOR_NOT_HOUSE_EXIT';
            $continuity=$p03['logContinuity']??null;
            $out['p03LogContinuity']=is_string($continuity)
                &&in_array($continuity,[
                    'OK','TAIL_OVERLAP_AFTER_ROTATION','BASELINE_ONLY',
                    'LOG_GAP_OR_RESET','UNKNOWN'
                ],true)?$continuity:'UNVERIFIED';
            $visible=$p03['recentDirectionProofs'];
            $out['p03VisibleHistoryCount']=min(count($visible),15);
            $counter=$p03['directionProofs24h']??null;
            $countOK=is_array($counter)
                &&is_int($counter['HOME_TO_LAGER']??null)
                &&is_int($counter['LAGER_TO_HOME']??null)
                &&$counter['HOME_TO_LAGER']>=0
                &&$counter['LAGER_TO_HOME']>=0;
            $total=$countOK
                ?$counter['HOME_TO_LAGER']+$counter['LAGER_TO_HOME']:null;
            $out['p03Total24h']=$total;
            $limited=$total!==null&&$total>$out['p03VisibleHistoryCount'];
            $out['p03HistoryCoverage']=$limited
                ?'TRUNCATED_TO_LATEST_15'
                :'NO_TRUNCATION_REPORTED';
            $oldest=null;
            foreach(array_slice($visible,0,15) as $proof){
                if(is_array($proof)&&($proof['source']??null)
                    ==='P03_TWO_MAST_VERIFIED'
                    &&is_int($proof['at']??null)
                    &&$proof['at']>0&&$proof['at']<=$now+5)
                    $oldest=$oldest===null
                        ?$proof['at']:min($oldest,$proof['at']);
            }
            if($limited&&$oldest!==null
                &&$at+self::MAX_WINDOW_SECONDS<$oldest){
                $out['p03HistoryCoverage']='REFERENCE_OLDER_THAN_VISIBLE_15';
                $out['notes'][]='CAMERA_HISTORY_TRUNCATED_REFERENCE_MAY_BE_MISSING';
            }
            $historyOnly=($p03['baselineOnly']??false)===true;
            if($historyOnly){
                $out['p03HistoryCoverage']='BASELINE_ARCHIVE_NOT_LIVE_EVIDENCE';
                $out['notes'][]='P03_INITIAL_LOG_BASELINE_NOT_LIVE_PROOF';
            }
            if(!in_array($out['p03LogContinuity'],
                ['OK','TAIL_OVERLAP_AFTER_ROTATION'],true))
                $out['notes'][]='P03_PROOF_LOG_CONTINUITY_NOT_CERTIFIED';
            foreach(array_slice($visible,0,15) as $proof){
                if($historyOnly||!is_array($proof)
                    ||($proof['source']??null)!=='P03_TWO_MAST_VERIFIED'
                    ||!is_int($proof['at']??null)
                    ||abs($proof['at']-$at)>self::MAX_WINDOW_SECONDS)continue;
                if(($proof['direction']??null)==='HOME_TO_LAGER')
                    $out['p03AnonymousOutNearReference']++;
                elseif(($proof['direction']??null)==='LAGER_TO_HOME')
                    $out['p03AnonymousInNearReference']++;
            }
        }else{
            $out['p03CorridorCoverage']='NO_VERIFIED_P03_EVENT_LIST';
            $out['notes'][]='CAMERA_HISTORY_SOURCE_UNAVAILABLE';
        }
        $fusion=$evidence['residentEventFusion']??null;
        $fusionOK=is_array($fusion)&&($fusion['schema']??null)===1
            &&($fusion['authority']??null)
                ==='PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY';
        foreach($people as $person){
            $matched=[];
            if($fusionOK){
                $p=$fusion['people'][$person]??null;
                if(is_array($p)){
                    $history=$p['eventHistory']??null;
                    $out['phoneHistoryCoverage'][$person]=
                        is_array($history)
                        ?(count($history)>=24
                            ?'BOUNDED_HISTORY_MAY_HAVE_DROPPED_OLDER_EVENTS'
                            :'RECORDED_EVENTS_ONLY_NOT_CONTINUOUS')
                        :'NO_PERSONAL_EVENT_HISTORY_RECORDED';
                    if(is_array($history)){
                        foreach(array_slice($history,0,24) as $e){
                            if(is_array($e)
                                &&($e['code']??null)==='PHONE_GEOFENCE_OUT_OBSERVED'
                                &&is_int($e['at']??null)
                                &&abs($e['at']-$at)<=self::MAX_WINDOW_SECONDS){
                                $matched[]=$e['at'];
                            }
                        }
                    }
                }
            }
            $out['phoneHistoryCoverage'][$person]??=
                'SOURCE_NOT_VERIFIED_OR_HISTORY_UNAVAILABLE';
            $out['phoneEventNearReference'][$person]=
                $matched!==[]?'PHONE_GEOFENCE_OUT_NEAR_REFERENCE_NOT_HUMAN_PROOF'
                    :'NO_COMPARABLE_PERSONAL_PHONE_OUT_NOT_DISPROOF';
        }
        $out['status']='COMPARED_AS_ANONYMOUS_HINTS_ONLY';
        if($out['p03AnonymousOutNearReference']===0){
            if($out['p03HistoryCoverage']==='REFERENCE_OLDER_THAN_VISIBLE_15')
                $out['notes'][]='OLDER_CAMERA_EVENTS_NOT_IN_15_ITEM_REPORT';
            else
                $out['notes'][]='NO_P03_OUT_NEAR_REFERENCE_DOES_NOT_REFUTE_HOUSE_EXIT';
        }else
            $out['notes'][]='P03_OUT_NEAR_REFERENCE_IS_ANONYMOUS_CORRIDOR_ONLY';
        $out['notes'][]='ONE_P03_HUMAN_EVENT_MAY_INCLUDE_MULTIPLE_PEOPLE';
        $out['notes'][]='USER_VEHICLE_DESCRIPTION_NOT_CAMERA_RECOGNITION';
        $out['notes'][]='TWO_REPORTED_LEAVERS_DO_NOT_CERTIFY_PROPERTY_EMPTY';
        return $out;
    }
}
