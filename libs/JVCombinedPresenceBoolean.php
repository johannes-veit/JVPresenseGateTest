<?php
declare(strict_types=1);

/**
 * Read-only Boolean: "Anwesenheit erkannt".
 *
 * JA means a recent, validated POSITIVE occupancy INDICATION was detected.
 * NEIN means no sufficient positive indication at this instant. NEIN can
 * NEVER certify an empty building, departed individuals or arm any alarm.
 * P03 HOME<->LAGER is not a property exit. An open/closed gate and
 * motion-controlled lights are context, not independent human evidence.
 */
final class JVCombinedPresenceBoolean
{
    private const HOME_MOTION_SECONDS=300;
    private const P03_ENTRY_SECONDS=300;

    public static function evaluate(
        array $combined,array $home,array $wlan,array $johannesGeo,
        array $carinaGeo,array $p03,array $gate,array $indoor,int $now
    ):array {
        $result=[
            'schema'=>1,
            'authority'=>'READ_ONLY_POSITIVE_HOME_EVIDENCE_BOOLEAN',
            'detected'=>false,
            'status'=>'NEIN_KEIN_AKTUELLER_NACHWEIS',
            'positiveReasons'=>[],
            'supportingContext'=>[],
            'sources'=>[
                'lcnRecentMotion'=>false,
                'lcnMotionAgeSeconds'=>null,
                'p03RecentHouseEntry'=>false,
                'johannesWlanAndHomeGeofence'=>false,
                'carinaWlanAndHomeGeofence'=>false,
                'johannesWlanRecent'=>false,
                'carinaWlanRecent'=>false,
                'johannesHomeGeofenceRecent'=>false,
                'carinaHomeGeofenceRecent'=>false,
                'indoorLightsOrTvOn'=>0,
                'gatePosition'=>'UNKNOWN',
                'gateIsNotEntryOrExitProof'=>true
            ],
            'notDetectedDoesNotMeanEmpty'=>true,
            'residentIdentityCertified'=>false,
            'houseEmptyCertified'=>false,
            'individualsAwayCertified'=>false,
            'mayWriteExternalHomeAway'=>false,
            'mayArmAlarm'=>false,
            'maySwitchDevices'=>false,
            'warnings'=>[]
        ];
        if($now<=0
            ||($combined['schema']??null)!==1
            ||($combined['authority']??null)!=='ANONYMOUS_COMBINED_CONTEXT_ONLY'
            ||in_array('SOURCE_AUTHORITY_OR_SCHEMA_MISMATCH',
                is_array($combined['warnings']??null)?$combined['warnings']:[],true)
            ||($combined['personPresenceMayBeChanged']??null)!==false
            ||($combined['propertyEmptyMayBeCertified']??null)!==false
            ||($combined['alarmMayBeControlled']??null)!==false){
            $result['status']='QUELLEN_NICHT_VERIFIZIERT';
            $result['warnings'][]='INVALID_COMBINED_EVIDENCE';
            return $result;
        }
        // The positive-reason evaluation must never invent proof from the
        // combined diagnostic's descriptive status or past observations.
        $homeOK=($home['schema']??null)===1
            &&($home['authority']??null)==='ANONYMOUS_HOME_OBSERVATION_ONLY'
            &&($home['sourcesHealthy']??false)===true
            &&($home['baselineEstablished']??false)===true
            &&is_int($home['observedHomeGUS']??null)
            &&$home['observedHomeGUS']>=15;
        $motionAge=$home['lastHomeMotionAgeSeconds']??null;
        if($homeOK&&is_int($motionAge)&&$motionAge>=0)
            $result['sources']['lcnMotionAgeSeconds']=$motionAge;
        $recentMotion=$homeOK&&is_int($motionAge)
            &&$motionAge>=0&&$motionAge<=self::HOME_MOTION_SECONDS
            &&in_array(($home['houseStatus']??null),[
                'HOUSE_MOVEMENT_CORROBORATED','HOUSE_MOVEMENT_HINT'
            ],true);
        $result['sources']['lcnRecentMotion']=$recentMotion;
        if($recentMotion)$result['positiveReasons'][]='LCN_HOUSE_MOTION_LAST_5_MIN';

        $p03OK=($p03['schema']??null)===1
            &&($p03['authority']??null)==='P03_LIVE_PROOFS_DIRECTION_ONLY';
        $entryAt=$home['lastP03DirectionAt']??null;
        $entry=$homeOK&&$p03OK
            &&($home['recentP03Direction']??null)==='LAGER_TO_HOME'
            &&($combined['p03RecentDirection']??null)==='LAGER_TO_HOME'
            &&is_int($entryAt)&&$entryAt>0&&$entryAt<=$now+5
            &&$now-$entryAt<=self::P03_ENTRY_SECONDS;
        $result['sources']['p03RecentHouseEntry']=$entry;
        if($entry)$result['positiveReasons'][]='P03_RECENT_LAGER_TO_HOME_ENTRY';

        $wlanOK=($wlan['schema']??null)===1
            &&($wlan['authority']??null)==='EXISTING_WLAN_MAC_HINTS_READ_ONLY'
            &&($wlan['enabled']??false)===true;
        foreach([
            'johannes'=>['geo'=>$johannesGeo,
                'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY'],
            'carina'=>['geo'=>$carinaGeo,
                'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY']
        ] as $person=>$spec){
            $phone=$wlan['people'][$person]??null;
            $onWlan=$wlanOK&&is_array($phone)
                &&($phone['validatedExistingVariablePair']??false)===true
                &&($phone['recentMacEvidence']??false)===true
                &&($phone['state']??null)==='MAC_CONFIRMED_RECENT_DEVICE_HINT';
            $geo=$spec['geo'];
            $onGeo=($geo['schema']??null)===1
                &&($geo['authority']??null)===$spec['authority']
                &&($geo['enabled']??false)===true
                &&($geo['validatedSource']??false)===true
                &&($geo['insideRecent']??false)===true
                &&($geo['outsideRecent']??false)===false;
            $result['sources'][$person.'WlanRecent']=$onWlan;
            $result['sources'][$person.'HomeGeofenceRecent']=$onGeo;
            $pair=$onWlan&&$onGeo;
            $result['sources'][$person.'WlanAndHomeGeofence']=$pair;
            if($pair)$result['positiveReasons'][]=strtoupper($person)
                .'_WLAN_AND_GEOFENCE_HOME_PHONE_HINT';
            if($onWlan&&!$onGeo)
                $result['supportingContext'][]=strtoupper($person).'_WLAN_ONLY';
            if($onGeo&&!$onWlan)
                $result['supportingContext'][]=strtoupper($person).'_GEOFENCE_ONLY';
            if(($geo['outsideRecent']??false)===true&&$onWlan)
                $result['warnings'][]=strtoupper($person).'_WLAN_GEO_CONFLICT';
        }
        $indoorOK=($indoor['schema']??null)===1
            &&($indoor['authority']??null)==='INDOOR_DEVICE_HINTS_ONLY';
        if($indoorOK){
            $on=$indoor['onCount']??null;
            $count=$indoor['sourceCount']??null;
            if(is_int($on)&&is_int($count)&&$on>=0&&$on<=$count){
                $result['sources']['indoorLightsOrTvOn']=$on;
                if($on>0)$result['supportingContext'][]='INTERIOR_LIGHT_OR_TV_ON_WEAK';
            }
        }
        $gateOK=($gate['schema']??null)===1
            &&($gate['authority']??null)==='EXISTING_SCHIEBETOR_DISPLAY_HINT_ONLY'
            &&($gate['signalPathAndBooleanVerified']??false)===true;
        if($gateOK&&in_array(($gate['positionHint']??null),[
            'ZU','AUF','ZWISCHENSTELLUNG_ODER_FAHRT',
            'CONTRADICTORY_ENDSTOPS'
        ],true))$result['sources']['gatePosition']=$gate['positionHint'];
        // Gate positions, lights and WLAN-only are intentionally NOT enough
        // to create an anonymous human-present assertion by themselves.
        $result['detected']=$result['positiveReasons']!==[];
        $result['status']=$result['detected']
            ?'JA_AKTUELLER_ANWESENHEITSHINWEIS'
            :'NEIN_KEIN_AKTUELLER_NACHWEIS';
        return $result;
    }
}
