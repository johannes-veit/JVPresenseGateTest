<?php
declare(strict_types=1);

/**
 * Cross-source observations for a house<->lager route.
 *
 * One verified camera corridor may coincide with zero, one, or several
 * individually observed PHONE transitions. A matching phone is a temporal
 * candidate only: cameras cannot identify a person; a phone can be left
 * behind. Vehicle contact and raw street Human IVS are NOT proof of a car.
 *
 * Pure and read-only. Nothing here grants occupancy, alarm or device actions.
 */
final class JVResidentJourneyEvidence
{
    private const MAX_ROUTE_AGE=14400; // useful recent route within four hours
    private const PHONE_AFTER_IN=1200;
    private const PHONE_AFTER_OUT=900;
    private const HOME_BEFORE_OUT=900;
    private const CLOCK_SLACK=5;

    public static function evaluate(
        array $routes,array $fusion,array $work,array $boundary,int $now
    ):array {
        $out=[
            'schema'=>1,'authority'=>'READ_ONLY_RESIDENT_ROUTE_PHONE_ASSOCIATIONS',
            'status'=>'NO_RECENT_VERIFIED_ROUTE_EPISODE',
            'episode'=>null,'phoneCandidates'=>[],
            'candidateCount'=>0,'candidatePerson'=>null,
            'vehicleEvidence'=>'NO_VERIFIED_VEHICLE_DIRECTION',
            'streetHumanOnly'=>false,'indoorMotionContext'=>false,
            'blockers'=>[],'identityAssigned'=>false,
            'humanPresenceCertified'=>false,'personDepartureCertified'=>false,
            'propertyEmptyCertified'=>false,'vehicleJourneyCertified'=>false,
            'walkingCertified'=>false,'maySwitchDevices'=>false,
            'mayArmAlarm'=>false
        ];
        if($now<=0)return $out;
        $routesOK=($routes['schema']??null)===1
            &&($routes['authority']??null)==='ANONYMOUS_ROUTE_EPISODES_READ_ONLY';
        $fusionOK=($fusion['schema']??null)===1
            &&($fusion['authority']??null)==='PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY';
        $workOK=($work['schema']??null)===1
            &&($work['authority']??null)==='PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT'
            &&($work['enabled']??false)===true;
        $boundaryOK=($boundary['schema']??null)===1
            &&($boundary['authority']??null)==='READ_ONLY_BOUNDARY_CONTEXT';
        if(!$routesOK||!$fusionOK){
            $out['status']='SOURCE_UNVERIFIED';
            $out['blockers'][]='ROUTE_OR_PHONE_LEDGER_NOT_VERIFIED';
            return $out;
        }
        $r=$routes['lastEpisode']??null;
        if(!is_array($r)
            ||($r['kind']??null)!=='ANONYMOUS_OUT_IN_CORRIDOR_SEQUENCE'
            ||!is_int($r['outAt']??null)||!is_int($r['inAt']??null)
            ||$r['outAt']<=0||$r['outAt']>=$r['inAt']
            ||$r['inAt']>$now+self::CLOCK_SLACK
            ||$r['inAt']<$now-self::MAX_ROUTE_AGE) {
            $out['blockers'][]='NO_CURRENT_MATCHABLE_ANONYMOUS_OUT_IN';
            return $out;
        }
        $outAt=$r['outAt'];$inAt=$r['inAt'];
        $out['episode']=[
            'outAt'=>$outAt,'inAt'=>$inAt,
            'durationSeconds'=>$inAt-$outAt,
            'cameraCorridorOnly'=>true
        ];
        $out['streetHumanOnly']=($r['rawStreetHumanNearby']??false)===true;
        $out['indoorMotionContext']=($r['anonymousIndoorMotionBeforeOut']??false)===true
            ||($r['anonymousIndoorMotionAfterIn']??false)===true;
        $vehicleNearby=($r['vehicleVariableActiveNearby']??false)===true;
        $vehicleSources=$boundaryOK&&is_int($boundary['vehicleSignals']??null)
            ?$boundary['vehicleSignals']:0;
        if($vehicleSources<=0){
            $out['vehicleEvidence']='NO_VEHICLE_SOURCE_MAPPED';
            $out['blockers'][]='VEHICLE_SIGNAL_SOURCE_MISSING';
        }elseif($vehicleNearby){
            $out['vehicleEvidence']='VEHICLE_SOURCE_ACTIVE_NEAR_ANONYMOUS_ROUTE_UNQUALIFIED';
            $out['blockers'][]='VEHICLE_DIRECTION_AND_IDENTITY_UNVERIFIED';
        }else{
            $out['vehicleEvidence']='VEHICLE_SOURCE_PRESENT_NO_MATCHING_EVENT';
            $out['blockers'][]='NO_TIMED_VEHICLE_EVENT';
        }
        if(!$workOK)$out['blockers'][]='PERSONAL_WORK_SSID_NOT_VERIFIED';
        foreach(['johannes','carina'] as $person){
            $p=$fusion['people'][$person]??null;
            if(!is_array($p))continue;
            $candidate=null;
            // The last event might only be a routine WLAN reconnection.
            // Inspect the bounded personal history to recover a previously
            // verified OUT->IN->MAC phone-return event. Pre-upgrade reports
            // without eventHistory retain the legacy lastEvent fallback.
            $history=is_array($p['eventHistory']??null)
                ?array_slice($p['eventHistory'],0,24)
                :[$p['lastEvent']??null];
            $bestAt=null;
            foreach($history as $event){
                if(!is_array($event)
                    ||($event['code']??null)!=='PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC'
                    ||!is_int($event['at']??null))continue;
                $when=$event['at'];
                $rebind=$p['lastSourceRebindAt']??null;
                // A personal event predating a reconfigured handset
                // source must not match a newer anonymous camera trip.
                if(is_int($rebind)&&$rebind>0&&$when<$rebind)continue;
                if($when>=$inAt-self::CLOCK_SLACK
                    &&$when<=$inAt+self::PHONE_AFTER_IN
                    &&$when<=$now+self::CLOCK_SLACK
                    &&($bestAt===null||$when>$bestAt))$bestAt=$when;
            }
            if($bestAt!==null){
                $candidate=[
                    'person'=>$person,'signal'=>'PHONE_RETURN_AFTER_OWN_OUT_IN',
                    'at'=>$bestAt,'routeDirection'=>'RETURN',
                    'identityProof'=>false,'vehicleProof'=>false
                ];
            }
            if($candidate===null&&$workOK){
                $wp=$work['people'][$person]??null;
                $age=is_array($wp)?($wp['ageSeconds']??null):null;
                $workAt=is_int($age)&&$age>=0&&$age<=300
                    ?$now-$age:null;
                $mac=$p['lastMacAt']??null;
                // The prior HOME-MAC hint must still belong to the
                // verified WLAN source currently tracked by the fusion.
                // Legacy reports without these fields remain readable.
                $macContinuous=($p['macSourceContinuous']??true)===true
                    &&($p['sourceRebaselineRequired']??false)!==true;
                $rebind=$p['lastSourceRebindAt']??null;
                $macAfterBinding=!is_int($rebind)||$rebind<=0
                    ||(is_int($mac)&&$mac>=$rebind);
                if($macContinuous&&$macAfterBinding&&is_array($wp)
                    &&($wp['sourceVerified']??false)===true
                    &&($wp['workRecent']??false)===true
                    &&($wp['status']??null)==='WORK_FIRMEN_WLAN_PHONE'
                    &&is_int($workAt)
                    &&$workAt>=$outAt&&$workAt<=$outAt+self::PHONE_AFTER_OUT
                    &&is_int($mac)&&$mac<=$outAt
                    &&$mac>=$outAt-self::HOME_BEFORE_OUT){
                    $candidate=[
                        'person'=>$person,'signal'=>'HOME_MAC_BEFORE_AND_WORK_SSID_AFTER_OUT',
                        'at'=>$workAt,'routeDirection'=>'OUTBOUND',
                        'identityProof'=>false,'vehicleProof'=>false
                    ];
                }
            }
            if($candidate!==null)$out['phoneCandidates'][]=$candidate;
        }
        $out['candidateCount']=count($out['phoneCandidates']);
        if($out['candidateCount']===0){
            $out['status']='ANONYMOUS_ROUTE_NO_PERSONAL_PHONE_SEQUENCE';
            $out['blockers'][]='NO_INDIVIDUAL_PHONE_TRANSITION_MATCH';
        }elseif($out['candidateCount']===1){
            $out['status']='ONE_PHONE_TIMELINE_COMPATIBLE_NOT_IDENTIFIED';
            // candidatePerson is explicitly a phone account tag ONLY, never
            // a camera-person attribution or physical occupancy statement.
            $out['candidatePerson']=$out['phoneCandidates'][0]['person'];
            $out['blockers'][]='TEMPORAL_ASSOCIATION_CANNOT_IDENTIFY_CAMERA_PERSON';
        }else{
            $out['status']='MULTIPLE_PHONE_TIMELINES_CANNOT_ATTRIBUTE_ROUTE';
            $out['blockers'][]='MULTIPLE_SIMULTANEOUS_PHONE_CANDIDATES';
        }
        $out['blockers']=array_values(array_unique($out['blockers']));
        return $out;
    }
}
