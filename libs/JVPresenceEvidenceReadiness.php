<?php
declare(strict_types=1);

/**
 * One deterministic readiness matrix for current handset / route / vehicle
 * evidence. "Ready" means a source is currently usable as a HINT only.
 *
 * None of these diagnostics authorizes person-count certification, alarms,
 * camera writes, or vehicle attribution. Does not scan any private network.
 */
final class JVPresenceEvidenceReadiness
{
    public static function evaluate(
        array $p03Timing,array $mast,array $fusion,array $work,
        array $boundary,array $house,array $journey,int $now
    ):array {
        $report=[
            'schema'=>1,'authority'=>'READ_ONLY_PRESENCE_EVIDENCE_READINESS',
            'p03Mode'=>'UNKNOWN_NOT_VERIFIED',
            'mastStreams'=>'NOT_VERIFIED',
            'roomMotionCoverage'=>'NOT_VERIFIED',
            'phones'=>[],
            'vehicleEvidenceSource'=>'NOT_VERIFIED',
            'carJourneyAssessment'=>'INSUFFICIENT_DATA',
            'personIdentification'=>'NOT_AVAILABLE_FROM_ANONYMOUS_CAMERAS',
            'sourceBlocks'=>[],'nextEvidencePriority'=>[],
            'noNewDevicesRequired'=>true,
            'canClaimVehicleTravel'=>false,
            'canCertifyHumanIdentity'=>false,
            'canCertifyHouseEmpty'=>false,'mayArmAlarm'=>false,
            'maySwitchDevices'=>false
        ];
        if($now<=0)return $report;
        $modeGood=($p03Timing['schema']??null)===1
            &&($p03Timing['authority']??null)
                ==='READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY'
            &&($p03Timing['p03ModeTrustedFromFreshDiagnosis']??false)===true;
        if($modeGood){
            $mode=$p03Timing['p03OperatingMode']??null;
            $report['p03Mode']=$mode==='TEST_AND_PRODUCTION_OFF'
                ?'P03_INTENTIONALLY_OFF_NO_NEW_TRANSFERS'
                :($mode==='TEST_ACTIVE'||$mode==='PRODUCTION_ACTIVE'
                    ?'P03_TRANSFER_MODE_ACTIVE':'MODE_UNKNOWN_NOT_CERTIFIED');
        }
        if($report['p03Mode']!=='P03_TRANSFER_MODE_ACTIVE')
            $report['sourceBlocks'][]='P03_CANNOT_PROVE_NEW_ROUTE_CURRENTLY';
        $mastGood=($mast['schema']??null)===1
            &&($mast['authority']??null)==='P03_EXISTING_MAST_OBSERVER_HEALTH_ONLY';
        $report['mastStreams']=$mastGood
            &&($mast['bothMastsLive']??false)===true
            &&($mast['mainP03ProofBlocked']??true)===false
            ?'BOTH_OBSERVERS_LIVE_NOT_PROOF_OF_TRANSITION'
            :'ONE_OR_BOTH_OBSERVERS_NOT_VERIFIED';
        if($report['mastStreams']!=='BOTH_OBSERVERS_LIVE_NOT_PROOF_OF_TRANSITION')
            $report['sourceBlocks'][]='MAST_STREAM_HEALTH_UNKNOWN';
        $houseOK=($house['schema']??null)===1
            &&($house['authority']??null)==='ANONYMOUS_HOME_OBSERVATION_ONLY'
            &&($house['sourcesHealthy']??false)===true
            &&is_int($house['observedHomeGUS']??null)
            &&$house['observedHomeGUS']>=15;
        $report['roomMotionCoverage']=$houseOK
            ?'FIFTEEN_GUS_SOURCES_VERIFIED_ANONYMOUS'
            :'GUS_COVERAGE_NOT_FULLY_VERIFIED';
        if(!$houseOK)$report['sourceBlocks'][]='ROOM_SENSOR_COVERAGE_INCOMPLETE';
        $fusionValid=($fusion['schema']??null)===1
            &&($fusion['authority']??null)==='PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY';
        $workValid=($work['schema']??null)===1
            &&($work['authority']??null)==='PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT'
            &&($work['enabled']??false)===true;
        foreach(['johannes','carina'] as $person){
            $fp=$fusionValid?($fusion['people'][$person]??null):null;
            $state=is_array($fp)?($fp['state']??null):null;
            $wp=$workValid?($work['people'][$person]??null):null;
            $workAvailable=is_array($wp)
                &&($wp['sourceVerified']??false)===true
                &&($wp['status']??null)==='WORK_FIRMEN_WLAN_PHONE'
                &&($wp['workRecent']??false)===true
                &&is_int($wp['ageSeconds']??null)
                &&$wp['ageSeconds']>=0&&$wp['ageSeconds']<=300;
            $report['phones'][$person]=[
                'currentPhoneState'=>is_string($state)?$state:'PHONE_NOT_VERIFIED',
                'personalWorkSSIDCurrent'=>$workAvailable,
                'phoneNotHuman'=>true
            ];
            if(!$workAvailable){
                $report['sourceBlocks'][]=strtoupper($person).'_PERSONAL_WORK_SSID_NOT_CURRENT';
                $report['nextEvidencePriority'][]=strtoupper($person)
                    .'_VALIDATE_EXISTING_CURRENT_PERSONAL_SSID_SOURCE';
            }
        }
        $boundOK=($boundary['schema']??null)===1
            &&($boundary['authority']??null)==='READ_ONLY_BOUNDARY_CONTEXT';
        $count=$boundOK?($boundary['vehicleSignals']??null):null;
        $report['vehicleEvidenceSource']=is_int($count)&&$count>0
            ?'VEHICLE_VARIABLES_PRESENT_BUT_DIRECTION_NOT_CERTIFIED'
            :'NO_VERIFIED_VEHICLE_DIRECTION_SOURCE';
        // A mere VEHICLE Boolean, SMD blob, tor opening or roadway HUMAN
        // never supplies trajectory direction or a resident's vehicle ID.
        $report['sourceBlocks'][]='VEHICLE_CLASS_AND_DIRECTION_NOT_PROVEN';
        $report['nextEvidencePriority'][]='VERIFY_EXISTING_CAMERA_VEHICLE_EVENT_WITH_DIRECTION_READ_ONLY';
        $jOk=($journey['schema']??null)===1
            &&($journey['authority']??null)==='READ_ONLY_RESIDENT_ROUTE_PHONE_ASSOCIATIONS';
        $jState=$jOk?($journey['status']??null):null;
        $report['carJourneyAssessment']=$jState
            ==='ONE_PHONE_TIMELINE_COMPATIBLE_NOT_IDENTIFIED'
            ?'PHONE_TIMELINE_NEAR_ROUTE_BUT_AUTO_NOT_PROVEN'
            :'NO_IDENTIFIABLE_VEHICLE_JOURNEY';
        $report['sourceBlocks'][]='ANONYMOUS_CAMERA_CANNOT_IDENTIFY_PERSON';
        $report['sourceBlocks'][]='PROPERTY_EMPTY_NOT_CERTIFIABLE';
        $report['sourceBlocks']=array_values(array_unique($report['sourceBlocks']));
        return $report;
    }
}
