<?php
declare(strict_types=1);

/**
 * Read-only, restart-safe phone-based VISU state, one state per named
 * PHONE. Nobody is identified by the camera or GUS; no occupancy, human
 * presence or absence is certified and no device/alarm action is authorized.
 *
 * Current HOME: actual verified phone in home VLAN via existing MAC
 * confirmation, or a separately authorized fresh home SSID report.
 * Current WORK: actual independently verified firm SSID report.
 * The broad 150 m "Zuhause" geofence includes WORK and does not distinguish
 * HOME vs WORK. No signal alone implies AWAY.
 *
 * A last-known phone location is *only* displayed as UNBESTÄTIGT, never
 * extended as an active HOME/WORK state after source expiry.
 */
final class JVResidentVisuState
{
    private const PAST_CONTEXT_SECONDS=86400;
    private const CLOCK_TOLERANCE=5;
    private const CONFLICT_WINDOW_SECONDS=45;
    private const PEOPLE=['johannes','carina'];

    public static function evaluate(
        array $wlan,array $work,array $jGeo,array $cGeo,
        array $home,array $p03,array $prior,int $now
    ):array {
        $out=[
            'schema'=>1,
            'authority'=>'RESIDENT_PHONE_VISU_HINTS_READ_ONLY',
            'people'=>[],
            'allHouseSourcesDoNotIdentifyPeople'=>true,
            'broadHomeGeofenceAlsoContainsWork'=>true,
            'phoneIsNotProofOfPersonLocation'=>true,
            'phoneLeftBehindOrBorrowedPossible'=>true,
            'noPhoneSignalNeverProvesAway'=>true,
            'allResidentsAwayCertified'=>false,
            'houseEmptyCertified'=>false,
            'futureAbsenceActionsApproved'=>false,
            'mayWriteOtherModules'=>false,'mayArmAlarm'=>false,
            'warnings'=>[]
        ];
        $next=['schema'=>1,'people'=>[]];
        $wlanAuthorized=($wlan['schema']??null)===1
            &&($wlan['authority']??null)==='EXISTING_WLAN_MAC_HINTS_READ_ONLY'
            &&($wlan['enabled']??false)===true;
        $ssidAuthorized=($work['schema']??null)===1
            &&($work['authority']??null)==='PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT'
            &&($work['enabled']??false)===true;
        foreach(self::PEOPLE as $person) {
            $wp=$wlanAuthorized&&is_array($wlan['people'][$person]??null)
                ?$wlan['people'][$person]:[];
            $homeMacAt=null;
            if(($wp['validatedExistingVariablePair']??false)===true
                &&($wp['state']??null)==='MAC_CONFIRMED_RECENT_DEVICE_HINT'
                &&($wp['recentMacEvidence']??false)===true)
                $homeMacAt=self::validTime($wp['lastMacContactAt']??null,$now,660);
            $ssid=$ssidAuthorized&&is_array($work['people'][$person]??null)
                ?$work['people'][$person]:[];
            $ssidAt=null;
            if(($ssid['sourceVerified']??false)===true
                &&is_int($ssid['ageSeconds']??null)
                &&$ssid['ageSeconds']>=0&&$ssid['ageSeconds']<=900)
                $ssidAt=self::validTime($now-$ssid['ageSeconds'],$now,900);
            $workAt=$ssidAt!==null
                &&($ssid['workRecent']??false)===true
                &&($ssid['status']??null)==='WORK_FIRMEN_WLAN_PHONE'
                ?$ssidAt:null;
            $homeSsidAt=$ssidAt!==null
                &&($ssid['homeSsidRecent']??false)===true
                &&($ssid['status']??null)==='HOME_SSID_PHONE_HINT'
                ?$ssidAt:null;
            $homeAt=max($homeMacAt??0,$homeSsidAt??0);
            if($homeAt===0)$homeAt=null;

            $conflict=false;
            $zone='UNKNOWN';$at=null;$basis='NONE';
            if($homeAt!==null &&$workAt!==null){
                $gap=$homeAt-$workAt;
                if(abs($gap)<=self::CONFLICT_WINDOW_SECONDS) {
                    $conflict=true;
                }elseif($gap>0){
                    $zone='HOME';$at=$homeAt;
                    $basis=$homeSsidAt!==null
                        &&$homeSsidAt===$homeAt?'PHONE_HOME_SSID':'HOME_MAC_CONFIRMED';
                }else{
                    $zone='WORK';$at=$workAt;$basis='PHONE_COMPANY_SSID';
                }
            }elseif($homeAt!==null){
                $zone='HOME';$at=$homeAt;
                $basis=$homeSsidAt!==null &&$homeSsidAt===$homeAt
                    ?'PHONE_HOME_SSID':'HOME_MAC_CONFIRMED';
            }elseif($workAt!==null){
                $zone='WORK';$at=$workAt;$basis='PHONE_COMPANY_SSID';
            }
            $previous=$prior['people'][$person]??null;
            $previous=is_array($previous)?$previous:[];
            $lastZone=$previous['zone']??null;
            $lastAt=$previous['at']??null;
            if(!in_array($lastZone,['HOME','WORK'],true)
                ||self::validTime($lastAt,$now,self::PAST_CONTEXT_SECONDS)===null){
                $lastZone=null;$lastAt=null;
            }
            // A newer verified phone observation replaces the saved one.
            // Contradictions never overwrite a valid last-known location.
            if(!$conflict&&$at!==null
                &&($lastAt===null||$at>=$lastAt)){
                $lastZone=$zone;$lastAt=$at;
            }
            if($lastZone!==null)
                $next['people'][$person]=['zone'=>$lastZone,'at'=>$lastAt];
            if($conflict){
                $decision='CONFLICT';
                $label='WIDERSPRUCH (Heim-/Firmen-WLAN)';
                $reason='SIMULTANEOUS_OPPOSING_PHONE_NETWORK_HINTS';
            }elseif($zone==='HOME'){
                $decision='HOME_PHONE_CONFIRMED';
                $label='ZUHAUSE (Handy-WLAN bestätigt)';
                $reason=$basis;
            }elseif($zone==='WORK'){
                $decision='WORK_PHONE_CONFIRMED';
                $label='FIRMA (Handy-WLAN bestätigt)';
                $reason=$basis;
            }elseif($lastZone!==null){
                $decision='LAST_KNOWN_UNCONFIRMED';
                $label=$lastZone==='HOME'
                    ?'UNBESTÄTIGT (zuletzt Zuhause)'
                    :'UNBESTÄTIGT (zuletzt Firma)';
                $reason='LAST_KNOWN_IS_NOT_LIVE';
            }else{
                $decision='UNKNOWN';
                $label='NICHT BESTÄTIGT';
                $reason='NO_CURRENT_DEVICE_LOCATION_EVIDENCE';
            }
            $geo=$person==='johannes'?$jGeo:$cGeo;
            $expected=$person==='johannes'
                ?'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY'
                :'EXISTING_CARINA_GEOFENCY_READ_ONLY';
            $geoValid=($geo['schema']??null)===1
                &&($geo['authority']??null)===$expected
                &&($geo['enabled']??false)===true
                &&($geo['validatedSource']??false)===true;
            $out['people'][$person]=[
                'decision'=>$decision,
                'display'=>$label,
                'deviceZone'=>$zone,
                'deviceEvidenceAt'=>$at,
                'deviceEvidenceAgeSeconds'=>$at!==null?$now-$at:null,
                'deviceEvidenceReason'=>$reason,
                'homeMacAt'=>$homeMacAt,
                'workSSIDAt'=>$workAt,
                'homeSSIDAt'=>$homeSsidAt,
                'wifiConflict'=>$conflict,
                'lastKnownDeviceZone'=>$lastZone,
                'lastKnownDeviceAt'=>$lastAt,
                'lastKnownDeviceAgeSeconds'=>$lastAt!==null?$now-$lastAt:null,
                'geofenceInsideSharedHomeWorkZone'=>$geoValid
                    ?($geo['geofenceReportedInside']??null):null,
                'geofenceUsedToDecideHomeOrWork'=>false,
                'cameraAssignedThisPerson'=>false,
                'lcnAssignedThisPerson'=>false,
                'personPhysicallyAtHomeCertified'=>false,
                'personPhysicallyAwayCertified'=>false,
                'mayBeUsedForAbsenceAutomation'=>false
            ];
        }
        $out['anonymousHouseMotionRecent']=($home['authority']??null)
            ==='ANONYMOUS_HOME_OBSERVATION_ONLY'
            &&($home['sourcesHealthy']??false)===true
            &&is_int($home['lastHomeMotionAgeSeconds']??null)
            &&$home['lastHomeMotionAgeSeconds']<=300
            &&$home['lastHomeMotionAgeSeconds']>=0;
        $out['anonymousP03RecentTransfers24h']=($p03['authority']??null)
            ==='P03_LIVE_PROOFS_DIRECTION_ONLY'
            &&is_array($p03['directionProofs24h']??null)
            ?$p03['directionProofs24h']:null;
        return ['report'=>$out,'next'=>$next];
    }

    private static function validTime(mixed $at,int $now,int $ttl):?int
    {
        return is_int($at)&&$now>0&&$at>0
            &&$at<=$now+self::CLOCK_TOLERANCE&&$at>=$now-$ttl
            ?$at:null;
    }
}
