<?php
declare(strict_types=1);

/**
 * Two human-readable SHADOW display variables for Johannes and Carina.
 * Deliberately strings: Boolean FALSE would wrongly imply a resident away.
 *
 * Evidence is of registered HANDSETS, never person identity, group size,
 * property exit, empty dwelling or permission to arm an alarm.
 */
final class JVResidentPresenceDisplay
{
    public static function evaluate(array $wlan,array $johannesGeo,
        array $carinaGeo,array $workWiFi=[]):array
    {
        $people=[];
        foreach(['johannes','carina'] as $person){
            $geo=$person==='johannes'?$johannesGeo:$carinaGeo;
            $geoAuthority=$person==='johannes'
                ?'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY'
                :'EXISTING_CARINA_GEOFENCY_READ_ONLY';
            $validWlan=($wlan['schema']??null)===1
                &&($wlan['authority']??null)==='EXISTING_WLAN_MAC_HINTS_READ_ONLY'
                &&($wlan['enabled']??null)===true;
            $wlanEntry=$validWlan&&is_array($wlan['people'][$person]??null)
                ?$wlan['people'][$person]:[];
            $wlanNear=($wlanEntry['recentMacEvidence']??false)===true
                &&($wlanEntry['validatedExistingVariablePair']??false)===true
                &&($wlanEntry['state']??null)==='MAC_CONFIRMED_RECENT_DEVICE_HINT';
            $geoOK=($geo['schema']??null)===1
                &&($geo['authority']??null)===$geoAuthority
                &&($geo['enabled']??false)===true
                &&($geo['validatedSource']??false)===true;
            $inside=$geoOK &&($geo['insideRecent']??false)===true
                &&($geo['outsideRecent']??false)===false;
            $outside=$geoOK &&($geo['outsideRecent']??false)===true
                &&($geo['insideRecent']??false)===false;
            $geofenceOld=$geoOK
                &&($geo['geofenceReportedInside']??null)===true
                &&!$inside;
            $verifiedWork=($workWiFi['schema']??null)===1
                &&($workWiFi['authority']??null)==='PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT'
                &&($workWiFi['enabled']??false)===true
                &&($workWiFi['people'][$person]['workRecent']??false)===true;
            if($verifiedWork){
                $status='WORK (Firmen-WLAN-Handy)';
                $evidence='RECENT_VERIFIED_WORK_SSID_HANDSET_ONLY';
            }elseif($wlanNear&&$outside){
                $status='WIDERSPRUCH (WLAN / Geofence)';
                $evidence='CONFLICT_NO_RESIDENT_LOCATION';
            }elseif($wlanNear&&$inside){
                $status='ANWESEND (Handyhinweis)';
                $evidence='WLAN_AND_GEOFENCE_PHONE_NEAR_HOME';
            }elseif($wlanNear&&$geofenceOld){
                $status='WLAN ZUHAUSE (Geofence veraltet)';
                $evidence='WLAN_ONLY_STALE_GEOFENCE';
            }elseif($wlanNear){
                $status='WLAN ZUHAUSE (Geofence unbestätigt)';
                $evidence='WLAN_PHONE_ONLY';
            }elseif($inside){
                $status='GEOFENCE ZUHAUSE (WLAN unbestätigt)';
                $evidence='GEOFENCE_PHONE_ONLY';
            }else{
                $status='NICHT BESTÄTIGT';
                $evidence=$outside?'PHONE_OUTSIDE_NO_HUMAN_AWAY_PROOF'
                    :'NO_CURRENT_JOINT_DEVICE_PROOF';
            }
            $people[$person]=[
                'display'=>$status,'evidence'=>$evidence,
                'freshWlanHandset'=>$wlanNear,
                'currentWorkWifiHandset'=>$verifiedWork,
                'freshInsideGeofenceHandset'=>$inside,
                'geofenceOutsideHandset'=>$outside,
                'personPhysicallyHomeCertified'=>false,
                'personPhysicallyAwayCertified'=>false,
                'statusCanArmAlarm'=>false
            ];
        }
        return [
            'schema'=>1,'authority'=>'PERSON_DISPLAY_HANDSET_HINTS_ONLY',
            'people'=>$people,
            'displayIsNotHouseOrPersonPresenceCertificate'=>true,
            'noEvidenceIsNeverResidentAway'=>true,
            'propertyEmptyMayBeCertified'=>false,
            'personPresenceMayBeChanged'=>false,
            'alarmMayBeControlled'=>false
        ];
    }
}
