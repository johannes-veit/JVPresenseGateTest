<?php
declare(strict_types=1);

/**
 * Final, read-only safety case for human presence, car/walk and an empty
 * property. No absence-by-silence and no inferred vehicle classification.
 * All flags are advisory; no physical identity or actuation authorization.
 */
final class JVPresenceSafetyCase
{
    public static function evaluate(
        array $home,array $wlan,array $work,array $boundary,
        array $route,array $journey,array $commissioning,
        array $integrity,array $upgrade,int $now
    ):array {
        $out=[
            'schema'=>1,'authority'=>'READ_ONLY_FINAL_PRESENCE_SAFETY_CASE',
            'status'=>'UNVERIFIED_SOURCES',
            'homeActivity'=>'UNKNOWN','homePhoneHints'=>0,
            'workPhoneHints'=>0,'cameraRoute'=>'UNVERIFIED',
            'vehicle'=>'NOT_VERIFIED',
            'footTravel'=>'NOT_CERTIFIABLE',
            'identifiedHumanAtHome'=>'UNKNOWN',
            'humanCountAtHome'=>'UNKNOWN',
            'allResidentsAway'=>'UNKNOWN',
            'propertyEmpty'=>'UNKNOWN',
            'whyNotEmpty'=>[],
            'whyNotDriving'=>[],
            'safeToEvaluate'=>false,
            'humanIdentityCertified'=>false,
            'humanAbsentCertified'=>false,
            'vehicleTravelCertified'=>false,
            'walkingCertified'=>false,
            'propertyEmptyCertified'=>false,
            'mayArmAlarm'=>false,'maySwitchDevices'=>false
        ];
        if($now<=0)return $out;
        $checks=[
            ($home['schema']??null)===1
                &&($home['authority']??null)==='ANONYMOUS_HOME_OBSERVATION_ONLY',
            ($wlan['schema']??null)===1
                &&($wlan['authority']??null)==='EXISTING_WLAN_MAC_HINTS_READ_ONLY',
            ($work['schema']??null)===1
                &&($work['authority']??null)==='PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
            ($boundary['schema']??null)===1
                &&($boundary['authority']??null)==='READ_ONLY_BOUNDARY_CONTEXT',
            ($route['schema']??null)===1
                &&($route['authority']??null)==='ANONYMOUS_ROUTE_EPISODES_READ_ONLY',
            ($journey['schema']??null)===1
                &&($journey['authority']??null)==='READ_ONLY_RESIDENT_ROUTE_PHONE_ASSOCIATIONS',
            ($commissioning['schema']??null)===1
                &&($commissioning['authority']??null)==='PRESENCE_READ_ONLY_COMMISSIONING_GATE',
            ($integrity['schema']??null)===1
                &&($integrity['authority']??null)==='SHADOW_INTEGRITY_AUDIT_READ_ONLY',
            ($upgrade['schema']??null)===1
                &&($upgrade['authority']??null)==='READ_ONLY_SHADOW_UPGRADE_PREFLIGHT'
        ];
        if(in_array(false,$checks,true)){
            $out['whyNotEmpty'][]='SOURCE_AUTHORITY_OR_SCHEMA_NOT_VERIFIED';
            $out['whyNotDriving'][]='SOURCE_AUTHORITY_OR_SCHEMA_NOT_VERIFIED';
            return $out;
        }
        $out['safeToEvaluate']=($integrity['status']??null)==='CONSISTENT'
            &&($upgrade['safeToRefresh']??false)===true;
        if(!$out['safeToEvaluate']){
            $out['status']='INTEGRITY_OR_UPGRADE_REVIEW';
            $out['whyNotEmpty'][]='SOURCE_INTEGRITY_OR_PERSISTENCE_REVIEW';
            $out['whyNotDriving'][]='SOURCE_INTEGRITY_OR_PERSISTENCE_REVIEW';
            return $out;
        }
        $out['status']='READ_ONLY_EVIDENCE_EVALUATED_NO_HUMAN_CERTIFICATE';
        $motionAt=$home['lastHomeMotionAt']??null;
        $motionRecent=is_int($motionAt)&&$motionAt>0
            &&$motionAt<=$now+5&&$now-$motionAt<=300
            &&($home['sourcesHealthy']??false)===true;
        $out['homeActivity']=$motionRecent
            ?'ANONYMOUS_INDOOR_MOTION_RECENT'
            :(($home['sourcesHealthy']??false)===true
                ?'QUIET_NO_ABSENCE_PROOF':'ROOM_SENSORS_NOT_VERIFIED');
        foreach(['johannes','carina'] as $person){
            $phone=$wlan['people'][$person]??null;
            if(is_array($phone)
                &&($phone['state']??null)==='MAC_CONFIRMED_RECENT_DEVICE_HINT'
                &&($phone['validatedExistingVariablePair']??false)===true
                &&($phone['recentMacEvidence']??false)===true)
                $out['homePhoneHints']++;
            $workPhone=$work['people'][$person]??null;
            if(is_array($workPhone)
                &&($workPhone['status']??null)==='WORK_FIRMEN_WLAN_PHONE'
                &&($workPhone['sourceVerified']??false)===true
                &&($workPhone['workRecent']??false)===true
                &&is_int($workPhone['ageSeconds']??null)
                &&$workPhone['ageSeconds']>=0
                &&$workPhone['ageSeconds']<=300)
                $out['workPhoneHints']++;
        }
        $episode=$route['lastEpisode']??null;
        $out['cameraRoute']=is_array($episode)
            &&($episode['kind']??null)==='ANONYMOUS_OUT_IN_CORRIDOR_SEQUENCE'
            ?'ANONYMOUS_HOUSE_LAGER_SEQUENCE'
            :'NO_COMPLETE_ANONYMOUS_SEQUENCE';
        if(($route['ambiguousOutstanding']??0)>0)
            $out['cameraRoute']='AMBIGUOUS_MULTIPERSON_QUARANTINE';
        $vehicleSources=$boundary['vehicleSignals']??0;
        if(is_int($vehicleSources)&&$vehicleSources>0){
            $hasVehicle=false;
            foreach(array_slice(is_array($boundary['recentChanges']??null)
                ?$boundary['recentChanges']:[],0,12) as $e){
                if(is_array($e)&&($e['kind']??null)==='VEHICLE'
                    &&($e['state']??null)==='ACTIVE'
                    &&is_int($e['at']??null)&&$e['at']>0
                    &&$e['at']<=$now+5&&$now-$e['at']<=300)
                    $hasVehicle=true;
            }
            $out['vehicle']=$hasVehicle
                ?'RECENT_VEHICLE_CONTACT_DIRECTION_UNKNOWN'
                :'VEHICLE_VARIABLE_NO_QUALIFIED_DIRECTION';
        }else{
            $out['vehicle']='NO_DIRECTIONAL_VEHICLE_SOURCE';
        }
        $out['whyNotDriving']=[
            'VEHICLE_DIRECTION_NOT_VERIFIED',
            'PERSON_TO_VEHICLE_ASSOCIATION_NOT_VERIFIED'
        ];
        if($out['vehicle']==='NO_DIRECTIONAL_VEHICLE_SOURCE')
            $out['whyNotDriving'][]='DIRECTIONAL_VEHICLE_EVENT_MISSING';
        $out['whyNotEmpty']=[
            'ALL_OCCUPANTS_NOT_COUNTED_OR_IDENTIFIED',
            'ALL_PROPERTY_EXITS_NOT_INDEPENDENTLY_COVERED',
            'PHONE_SILENCE_OR_GEOFENCE_OUT_IS_NOT_HUMAN_ABSENCE'
        ];
        if($motionRecent)
            $out['whyNotEmpty'][]='RECENT_ANONYMOUS_INDOOR_MOTION';
        if($out['homePhoneHints']>0)
            $out['whyNotEmpty'][]='RECENT_HOME_PHONE_CONTACT';
        return $out;
    }
}
