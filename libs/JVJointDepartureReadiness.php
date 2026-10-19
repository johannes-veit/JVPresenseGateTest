<?php
declare(strict_types=1);

/**
 * Explicitly explains what an observed "both residents left together"
 * scenario CAN and CANNOT be reconstructed from the already available
 * read-only sources. Never turns 2 phones or 2 sensor triggers into
 * 2 identifiable humans. No persisted user truth is injected as proof.
 */
final class JVJointDepartureReadiness
{
    public const AUTHORITY='JOINT_DEPARTURE_READINESS_NO_PERSON_COUNT_OR_ACTION';
    public static function evaluate(
        array $atlas,array $wlan,array $timing,array $p03,array $readiness,
        int $now
    ):array {
        $out=[
            'schema'=>1,'authority'=>self::AUTHORITY,
            'state'=>'UNVERIFIED_SOURCES',
            'recentVerifiedHandsets'=>null,
            'p03OperatingMode'=>'UNKNOWN_NOT_VERIFIED',
            'verifiedAnonymousCorridorProofs24h'=>null,
            'routeAtlasPatterns'=>null,
            'roomCrossTrafficObserved'=>false,
            'twoResidentsDepartingTogetherProven'=>false,
            'personsCountableFromTheseSensors'=>false,
            'twoPhoneDevicesAreNotTwoPeople'=>true,
            'rawStreetIVSNotCorridorProof'=>true,
            'phoneMacContactNotExitTime'=>true,
            'homeToWorkNotPropertyExit'=>true,
            'sourceGapPriorities'=>[],
            'humanIdentityCertified'=>false,
            'homeEmptyCertified'=>false,
            'alarmMayBeControlled'=>false,
            'foreignModulesMayBeChanged'=>false
        ];
        if($now<=0
            ||($atlas['schema']??null)!==1
            ||($atlas['authority']??null)
                !=='ANONYMOUS_LCN_TEMPORAL_ROOM_SEQUENCE_ONLY'
            ||($atlas['status']??null)!=='ANONYMOUS_TEMPORAL_HISTORY_AVAILABLE'
            ||($wlan['schema']??null)!==1
            ||($wlan['authority']??null)!=='EXISTING_WLAN_MAC_HINTS_READ_ONLY'
            ||($timing['schema']??null)!==1
            ||($timing['authority']??null)
                !=='READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY'
            ||($p03['schema']??null)!==1
            ||($p03['authority']??null)!=='P03_LIVE_PROOFS_DIRECTION_ONLY'
            ||($readiness['schema']??null)!==1
            ||($readiness['authority']??null)
                !=='READ_ONLY_PRESENCE_EVIDENCE_READINESS')return $out;

        $devices=$wlan['freshMacConfirmedDeviceCount']??null;
        if(!is_int($devices)||$devices<0||$devices>2)return $out;
        foreach(['johannes','carina'] as $person){
            if(!is_array($wlan['people'][$person]??null))return $out;
        }
        $proofs=$p03['directionProofs24h']??null;
        if(!is_array($proofs)
            ||!is_int($proofs['HOME_TO_LAGER']??null)
            ||!is_int($proofs['LAGER_TO_HOME']??null)
            ||$proofs['HOME_TO_LAGER']<0||$proofs['LAGER_TO_HOME']<0
            ||$proofs['HOME_TO_LAGER']>10000||$proofs['LAGER_TO_HOME']>10000)
            return $out;
        $out['recentVerifiedHandsets']=$devices;
        $out['verifiedAnonymousCorridorProofs24h']=array_sum($proofs);
        $out['routeAtlasPatterns']=is_int($atlas['patternsFound']??null)
            ?$atlas['patternsFound']:null;
        $out['roomCrossTrafficObserved']=is_int(
                $atlas['patternsWithInterleavedRooms']??null)
            &&$atlas['patternsWithInterleavedRooms']>0;
        $fresh=($timing['p03ModeTrustedFromFreshDiagnosis']??false)===true;
        $mode=$fresh?($timing['p03OperatingMode']??null):null;
        $out['p03OperatingMode']=in_array($mode,[
                'TEST_AND_PRODUCTION_OFF','TEST_ACTIVE','PRODUCTION_ACTIVE'
            ],true)?$mode:'UNKNOWN_NOT_VERIFIED';
        $gaps=[];
        if($out['p03OperatingMode']==='TEST_AND_PRODUCTION_OFF'){
            $out['state']='P03_INTENTIONALLY_OFF_NO_NEW_ROUTE_OBSERVATION';
            $gaps[]='P03_KORRIDOR_IM_BETRIEB_NICHT_AKTIV';
        }elseif($out['p03OperatingMode']==='UNKNOWN_NOT_VERIFIED'){
            $out['state']='P03_MODE_NEEDS_FRESH_DIAGNOSIS';
            $gaps[]='P03_MODUS_QUELLE_NICHT_AKTUELL';
        }elseif($out['verifiedAnonymousCorridorProofs24h']===0){
            $out['state']='P03_ACTIVE_BUT_NO_VERIFIED_PASSAGE';
            $gaps[]='KEIN_GEPRUEFTER_TREPPENUEBERGANG_IM_ZEITFENSTER';
        }else{
            $out['state']='ANONYMOUS_ROUTE_AVAILABLE_NOT_TWO_PEOPLE';
        }
        if(($readiness['roomMotionCoverage']??null)
            !=='FIFTEEN_GUS_SOURCES_VERIFIED_ANONYMOUS')
            $gaps[]='15_GUS_ABDECKUNG_NICHT_BESTAETIGT';
        if($devices===2)
            $gaps[]='ZWEI_WLAN_GERAETE_SIND_KEINE_ZWEI_PERSONEN';
        if($out['roomCrossTrafficObserved'])
            $gaps[]='RAUMFOLGEN_KOENNEN_SICH_UEBERLAGERN';
        foreach(['johannes','carina'] as $person){
            if(($readiness['phones'][$person]['personalWorkSSIDCurrent']??false)!==true)
                $gaps[]=strtoupper($person).'_PERSOENLICHE_FIRMEN_SSID_FEHLT';
        }
        $gaps[]='KEINE_PERSONENZAEHLUNG_AN_DEN_AUSGAENGEN';
        $gaps[]='KEIN_GEPRUEFTER_GRUNDSTUECKSAUSGANG_FUER_BEIDE';
        $out['sourceGapPriorities']=array_slice(array_values(array_unique($gaps)),0,9);
        return $out;
    }
}
