<?php
declare(strict_types=1);

/**
 * A phone departure hint, never person identification or alarm permission.
 * A confirmed home Wi-Fi contact must precede a stable geofence OUT, without
 * any later home contact. Route and transport remain unverified.
 */
final class JVResidentDepartureInference
{
    public static function evaluate(array $wlan,array $work,array $jGeo,array $cGeo,array $p03,int $now,array $fusion=[]):array
    {
        $out=['schema'=>1,'authority'=>'PHONE_DEPARTURE_CORRELATION_READ_ONLY',
            'people'=>[],'vehicleSourceAvailable'=>false,
            'humanAbsenceCertified'=>false,'houseEmptyCertified'=>false,
            'mayChangePresenceOrAlarm'=>false];
        $wifiValid=($wlan['authority']??null)==='EXISTING_WLAN_MAC_HINTS_READ_ONLY'
            &&($wlan['enabled']??false)===true;
        foreach(['johannes','carina'] as $person){
            $w=$wifiValid?($wlan['people'][$person]??[]):[];
            $g=$person==='johannes'?$jGeo:$cGeo;
            $expected=$person==='johannes'
                ?'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY'
                :'EXISTING_CARINA_GEOFENCY_READ_ONLY';
            $home=$w['lastMacContactAt']??null;
            $geo=$g['lastEventAt']??null;
            $s=$work['people'][$person]??[];
            $personal=($fusion['schema']??null)===1
                &&($fusion['authority']??null)==='PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY'
                &&is_array($fusion['people'][$person]??null)
                ?$fusion['people'][$person]:null;
            $entry=['assessment'=>'NOT_ESTABLISHED',
                'reason'=>'MISSING_OR_UNRELATED_SIGNALS',
                'homeWlanAt'=>is_int($home)?$home:null,
                'geoOutAt'=>null,'eventAt'=>null,
                'p03AnonymousExitNearby'=>false,
                'transport'=>'UNVERIFIED','humanAbsenceCertified'=>false];
            $outside=($g['authority']??null)===$expected
                &&($g['enabled']??false)===true
                &&($g['validatedSource']??false)===true
                &&($g['outsideRecent']??false)===true
                &&($g['geofenceReportedInside']??null)===false;
            if($outside&&is_int($geo))$entry['geoOutAt']=$geo;
            // A snapshot of an OUT Geofence is not enough: the normal
            // production path supplies the persistent, individually observed
            // OUT event. No event -> no new phone departure diagnosis.
            if(($fusion['authority']??null)==='PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY'){
                if(!is_array($personal)
                    ||($personal['pendingGeofenceOut']??null)!==$geo){
                    $entry['reason']='NO_OBSERVED_PERSONAL_GEOFENCE_OUT_SEQUENCE';
                    $out['people'][$person]=$entry;
                    continue;
                }
            }
            if(!$wifiValid||($w['validatedExistingVariablePair']??false)!==true
                ||!$outside||!is_int($home)||!is_int($geo)
                ||$home<=0||$home>$now||$geo<=0||$geo>$now){
                $out['people'][$person]=$entry;continue;
            }
            if($now-$home<=300||$home>$geo-30){
                $entry['reason']='NEWER_OR_FRESH_HOME_WIFI';
                $out['people'][$person]=$entry;continue;
            }
            if(($s['sourceVerified']??false)===true
                &&is_int($s['ageSeconds']??null)
                &&$s['ageSeconds']>=0&&$s['ageSeconds']<=300
                &&(($s['homeSsidRecent']??false)===true
                    ||($s['workRecent']??false)===true)){
                $entry['reason']='NEWER_CONFIRMED_WIFI_ZONE_TAKES_PRECEDENCE';
                $out['people'][$person]=$entry;continue;
            }
            if($geo-$home<30||$geo-$home>1800
                ||$now-$geo<120||$now-$geo>14400){
                $entry['reason']='OUT_NOT_STABLE_OR_TIME_WINDOW_MISMATCH';
                $out['people'][$person]=$entry;continue;
            }
            $entry['assessment']='LIKELY_PHONE_DEPARTED';
            $entry['reason']='HOME_WIFI_THEN_GEO_OUT_NO_NEW_HOME_WIFI';
            $entry['eventAt']=$geo;
            if(($p03['authority']??null)==='P03_LIVE_PROOFS_DIRECTION_ONLY')
                foreach(($p03['recentDirectionProofs']??[]) as $proof){
                    if(is_array($proof)
                        &&($proof['source']??null)==='P03_TWO_MAST_VERIFIED'
                        &&($proof['direction']??null)==='HOME_TO_LAGER'
                        &&is_int($proof['at']??null)
                        &&abs($proof['at']-$geo)<=600){
                        $entry['p03AnonymousExitNearby']=true;break;
                    }
                }
            $out['people'][$person]=$entry;
        }
        return $out;
    }
}
