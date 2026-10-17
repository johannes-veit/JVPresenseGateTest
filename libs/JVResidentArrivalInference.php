<?php
declare(strict_types=1);

/**
 * Time-aligned arrival correlation. Does not identify anyone in camera video.
 * Vehicle use is never derived from gate operation or unverified raw counters.
 */
final class JVResidentArrivalInference
{
    public static function evaluate(array $wlan,array $jGeo,array $cGeo,array $p03,int $now):array
    {
        $out=['schema'=>1,'authority'=>'PHONE_ARRIVAL_CORRELATION_READ_ONLY',
            'people'=>[],'vehicleTripsCertified'=>false,
            'mayChangeExternalPresence'=>false,'mayArmAlarm'=>false];
        $wifiOK=($wlan['schema']??null)===1
            &&($wlan['authority']??null)==='EXISTING_WLAN_MAC_HINTS_READ_ONLY'
            &&($wlan['enabled']??false)===true;
        $p03OK=($p03['schema']??null)===1
            &&($p03['authority']??null)==='P03_LIVE_PROOFS_DIRECTION_ONLY';
        foreach(['johannes','carina'] as $person){
            $w=$wifiOK&&is_array($wlan['people'][$person]??null)
                ?$wlan['people'][$person]:[];
            $g=$person==='johannes'?$jGeo:$cGeo;
            $expected=$person==='johannes'
                ?'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY'
                :'EXISTING_CARINA_GEOFENCY_READ_ONLY';
            $mac=$w['lastMacContactAt']??null;
            $fresh=($w['validatedExistingVariablePair']??false)===true
                &&($w['recentMacEvidence']??false)===true
                &&($w['state']??null)==='MAC_CONFIRMED_RECENT_DEVICE_HINT'
                &&is_int($mac)&&$mac>0&&$mac<=$now+5
                &&$now-$mac>=0&&$now-$mac<=300;
            $entry=['assessment'=>'NOT_ESTABLISHED','reason'=>'NO_FRESH_HOME_PHONE_CONTACT',
                'homeMacAt'=>is_int($mac)?$mac:null,'geoInAt'=>null,
                'p03AnonymousEntryAt'=>null,'vehicle'=>'UNVERIFIED',
                'phoneAssociatedArrivalOnly'=>true,'personIdentifiedByCamera'=>false];
            $geoAt=$g['lastEventAt']??null;
            $geoIn=($g['schema']??null)===1
                &&($g['authority']??null)===$expected
                &&($g['enabled']??false)===true
                &&($g['validatedSource']??false)===true
                &&($g['insideRecent']??false)===true
                &&($g['outsideRecent']??false)===false
                &&is_int($geoAt)&&$geoAt>0&&$geoAt<=$now+5
                &&$geoAt<=$mac &&$mac-$geoAt<=1800;
            if($geoIn)$entry['geoInAt']=$geoAt;
            $p03At=null;
            if($p03OK&&$fresh){
                foreach(($p03['recentDirectionProofs']??[]) as $proof){
                    if(!is_array($proof)
                        ||($proof['source']??null)!=='P03_TWO_MAST_VERIFIED'
                        ||($proof['direction']??null)!=='LAGER_TO_HOME'
                        ||!is_int($proof['at']??null))continue;
                    $at=$proof['at'];
                    if($at>$now+5||$at<$now-1800
                        ||$at>$mac+90||$mac-$at>600)continue;
                    if($p03At===null||$at>$p03At)$p03At=$at;
                }
            }
            $entry['p03AnonymousEntryAt']=$p03At;
            if($fresh){
                if($p03At!==null){
                    $entry['assessment']='LIKELY_PHONE_ASSOCIATED_HOME_ARRIVAL';
                    $entry['reason']='RECENT_HOME_WIFI_AFTER_VERIFIED_ANONYMOUS_P03_IN';
                }elseif($geoIn){
                    $entry['assessment']='POSSIBLE_PHONE_RETURN_HOME';
                    $entry['reason']='GEOFENCE_IN_THEN_RECENT_HOME_WIFI_SHARED_HOME_WORK_RADIUS';
                }else{
                    $entry['assessment']='HOME_PHONE_CURRENT_ARRIVAL_NOT_PROVEN';
                    $entry['reason']='FRESH_HOME_WIFI_WITHOUT_VERIFIED_ENTRY_SEQUENCE';
                }
            }
            $out['people'][$person]=$entry;
        }
        return $out;
    }
}
