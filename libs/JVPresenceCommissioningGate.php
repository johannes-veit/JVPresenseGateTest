<?php
declare(strict_types=1);

/**
 * Commissioning milestone: source qualification, NOT a sensor or extra
 * observer. Reads existing, already validated SHADOW reports only.
 *
 * Readiness of a phone/device must never be promoted to the physical
 * person's identity, a verified car journey, or an alarm authorization.
 */
final class JVPresenceCommissioningGate
{
    private const PEOPLE=['johannes','carina'];
    private const MAX_ACTIONS=8;

    public static function evaluate(
        array $wlan,array $fusion,array $work,array $readiness,
        array $boundary,array $supplemental,array $numberStat,
        array $integrity,int $now
    ):array {
        $out=[
            'schema'=>1,'authority'=>'PRESENCE_READ_ONLY_COMMISSIONING_GATE',
            'state'=>'INPUTS_UNVERIFIED','sources'=>[],
            'p03'=>'NOT_VERIFIED','mastStreams'=>'NOT_VERIFIED',
            'roomMotion'=>'NOT_VERIFIED','vehicle'=>'NOT_VERIFIED',
            'numberStat'=>'NOT_VERIFIED',
            'additionalCameras'=>'NOT_VERIFIED',
            'actions'=>[],'actionCount'=>0,
            'canClaimIdentifiedPerson'=>false,
            'canClaimPersonDrivingCar'=>false,
            'canCertifyPropertyEmpty'=>false,
            'mayArmAlarm'=>false,'maySwitchDevices'=>false,
            'canObserveInShadowReadOnly'=>false,
            'noNewSymconObjects'=>true
        ];
        if($now<=0)return $out;
        $wOk=($wlan['schema']??null)===1
            &&($wlan['authority']??null)==='EXISTING_WLAN_MAC_HINTS_READ_ONLY';
        $fOk=($fusion['schema']??null)===1
            &&($fusion['authority']??null)==='PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY';
        $workOk=($work['schema']??null)===1
            &&($work['authority']??null)==='PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT';
        $readyOk=($readiness['schema']??null)===1
            &&($readiness['authority']??null)==='READ_ONLY_PRESENCE_EVIDENCE_READINESS';
        $boundaryOk=($boundary['schema']??null)===1
            &&($boundary['authority']??null)==='READ_ONLY_BOUNDARY_CONTEXT';
        $integrityOk=($integrity['schema']??null)===1
            &&($integrity['authority']??null)==='SHADOW_INTEGRITY_AUDIT_READ_ONLY';
        $all=$wOk&&$fOk&&$workOk&&$readyOk&&$boundaryOk&&$integrityOk;
        $actions=[];
        foreach(self::PEOPLE as $person){
            $w=$wOk&&is_array($wlan['people'][$person]??null)
                ?$wlan['people'][$person]:[];
            $f=$fOk&&is_array($fusion['people'][$person]??null)
                ?$fusion['people'][$person]:[];
            $ssid=$workOk&&is_array($work['people'][$person]??null)
                ?$work['people'][$person]:[];
            $wState=$w['state']??'UNVERIFIED';
            $home=($wState==='SOURCE_IDENTITY_COLLISION')
                ?'DUPLICATE_OBJECT_SOURCE'
                :(($w['validatedExistingVariablePair']??false)===true
                    ?(($w['recentMacEvidence']??false)===true
                        ?'MAC_CONFIRMED_DEVICE_ONLY':'VALIDATED_PAIR_NO_LIVE_MAC')
                    :'NOT_VERIFIED');
            $geo=$f!==[]?
                (($f['geoSourceContinuous']??false)===true
                    ?'SOURCE_CONTINUOUS'
                    :(($f['sourceRebaselineRequired']??false)===true
                        ?'REBASELINE_NO_PERSONAL_EVENT'
                        :'NO_VERIFIED_CONTINUOUS_SEQUENCE'))
                :'UNVERIFIED';
            $ss=($ssid['sourceVerified']??false)!==true
                ?'PERSONAL_SSID_SOURCE_MISSING'
                :match($ssid['status']??'UNKNOWN'){
                    'WORK_FIRMEN_WLAN_PHONE'=>
                        (($ssid['workRecent']??false)===true
                            &&is_int($ssid['ageSeconds']??null)
                            &&$ssid['ageSeconds']<=300
                            &&$ssid['ageSeconds']>=0)
                                ?'CURRENT_WORK_HANDSET_HINT'
                                :'WORK_SSID_AGE_UNCONFIRMED',
                    'STALE_CURRENT_SSID_UNCONFIRMED'=>
                        'PERSONAL_SSID_STALE',
                    'WORK_OR_PRIVATE_PROPERTY_EXT_UNCERTAIN'=>
                        'SHARED_PROPERTY_SSID_AMBIGUOUS',
                    'HOME_SSID_PHONE_HINT'=>'CURRENT_HOME_SSID_NOT_WORK',
                    default=>'PERSONAL_SSID_NOT_WORK'
                };
            $out['sources'][$person]=[
                'homeMac'=>$home,'geofence'=>$geo,
                'personalWorkSSID'=>$ss,
                'sourceBindingChanged24h'=>($f['sourceRebindRecent']??false)===true,
                'handsetNotHuman'=>true
            ];
            $prefix=strtoupper($person);
            if($home==='DUPLICATE_OBJECT_SOURCE')
                $actions[]=$prefix.'_REPAIR_DUPLICATED_EXISTING_WLAN_IDS';
            elseif($home==='NOT_VERIFIED')
                $actions[]=$prefix.'_VERIFY_EXISTING_WLAN_VARIABLE_PAIR';
            if(($f['sourceRebindRecent']??false)===true)
                $actions[]=$prefix.'_OBSERVE_NEW_PHONE_EVENTS_AFTER_REBIND';
            if($ss==='PERSONAL_SSID_SOURCE_MISSING')
                $actions[]=$prefix.'_QUALIFY_EXISTING_PHONE_REPORTED_SSID_SOURCE';
            elseif($ss==='PERSONAL_SSID_STALE'||$ss==='WORK_SSID_AGE_UNCONFIRMED')
                $actions[]=$prefix.'_CHECK_PERSONAL_SSID_REFRESH_AGE';
            elseif($ss==='SHARED_PROPERTY_SSID_AMBIGUOUS')
                $actions[]=$prefix.'_SHARED_SSID_CANNOT_DETERMINE_WORK';
        }
        $out['p03']=$readyOk
            ?($readiness['p03Mode']??'MODE_NOT_VERIFIED'):'NOT_VERIFIED';
        $out['mastStreams']=$readyOk
            ?($readiness['mastStreams']??'NOT_VERIFIED'):'NOT_VERIFIED';
        $out['roomMotion']=$readyOk
            ?($readiness['roomMotionCoverage']??'NOT_VERIFIED'):'NOT_VERIFIED';
        if($out['mastStreams']!=='BOTH_OBSERVERS_LIVE_NOT_PROOF_OF_TRANSITION')
            $actions[]='VERIFY_EXISTING_P03_MAST_HEALTH_READ_ONLY';
        if($out['roomMotion']!=='FIFTEEN_GUS_SOURCES_VERIFIED_ANONYMOUS')
            $actions[]='VERIFY_EXISTING_FIFTEEN_GUS_SOURCE_MAPPING';
        if($out['p03']==='P03_INTENTIONALLY_OFF_NO_NEW_TRANSFERS'){
            $actions[]='P03_MODE_OFF_INTENTIONAL_NO_NEW_VERIFIED_ROUTE';
        }elseif($out['p03']!=='P03_TRANSFER_MODE_ACTIVE'){
            $actions[]='VERIFY_P03_MODE_DIAGNOSIS_READ_ONLY';
        }
        if($fOk &&($fusion['p03']['logInterrupted']??false)===true
            &&in_array($fusion['p03']['logContinuity']??null,
                ['LOG_GAP_OR_RESET','UNKNOWN','UNVERIFIED'],true))
            $actions[]='P03_LOG_CONTINUITY_INTERRUPTED';
        if($integrityOk &&($integrity['status']??null)==='REVIEW_REQUIRED')
            $actions[]='REVIEW_SHADOW_INTEGRITY_CODES';
        if($boundaryOk&&is_int($boundary['vehicleSignals']??null)
            &&$boundary['vehicleSignals']>0){
            $out['vehicle']='CONTACT_OR_BOOLEAN_DIRECTION_UNKNOWN';
        }else{
            $out['vehicle']='NO_VERIFIED_DIRECTIONAL_VEHICLE_EVENT';
        }
        $actions[]='QUALIFY_EXISTING_VEHICLE_CLASS_AND_DIRECTION_SOURCE';
        $out['additionalCameras']=($supplemental['authority']??null)
            ==='ADDITIONAL_IPC_EVENT_HINTS_ONLY'
                ?(($supplemental['supplementaryHumanHint']??false)===true
                    ?'IVS_HUMAN_ONLY_NOT_VEHICLE'
                    :'NO_QUALIFIED_DIRECTIONAL_VEHICLE_EVENT')
                :'NOT_VERIFIED';
        $out['numberStat']=($numberStat['authority']??null)
            ==='DAHUA_NUMBERSTAT_DIAGNOSTIC_ONLY'
                ?($numberStat['status']??'NOT_CONNECTED'):'NOT_VERIFIED';
        // A Dahua NumberStat threshold/cumulative counter cannot close
        // the gap for individual vehicle direction or distinct humans.
        if(!$all)$actions[]='RECHECK_UNVERIFIED_READ_ONLY_INPUT_REPORTS';
        $actions=array_values(array_unique($actions));
        $out['actionCount']=count($actions);
        $out['actions']=array_slice($actions,0,self::MAX_ACTIONS);
        $out['actionsTruncated']=count($actions)>self::MAX_ACTIONS;
        $out['canObserveInShadowReadOnly']=$all
            &&($integrity['status']??null)==='CONSISTENT';
        $out['state']=!$all?'INPUTS_UNVERIFIED':
            ((($integrity['status']??null)==='REVIEW_REQUIRED')
                ?'SOURCE_CONSISTENCY_REVIEW'
                :'CORE_READ_ONLY_VALIDATED_MISSING_IDENTITY_AND_VEHICLE_PROOFS');
        return $out;
    }
}
