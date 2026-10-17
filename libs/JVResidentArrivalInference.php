<?php
declare(strict_types=1);

/**
 * Time-aligned arrival correlation. Does not identify anyone in camera video.
 * Vehicle use is never derived from gate operation or unverified raw counters.
 */
final class JVResidentArrivalInference
{
    public static function evaluate(array $wlan,array $jGeo,array $cGeo,array $p03,int $now,array $fusion=[]):array
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
                'phoneAssociatedArrivalOnly'=>false,'personIdentifiedByCamera'=>false];
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
            $fp=($fusion['authority']??null)==='PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY'
                &&is_array($fusion['people'][$person]??null)
                ?$fusion['people'][$person]:[];
            $history=is_array($fp['eventHistory']??null)
                ?array_slice($fp['eventHistory'],0,24)
                :[$fp['lastEvent']??null];
            $retAt=null;
            foreach($history as $last){
                if(!is_array($last)
                    ||($last['code']??null)!=='PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC'
                    ||!is_int($last['at']??null))continue;
                $at=$last['at'];
                if($at>0&&$at<=$now+5&&$now-$at<=3600
                    &&($retAt===null||$at>$retAt))$retAt=$at;
            }
            $entry['verifiedPhoneReturnAt']=$retAt;
            if($fresh){
                if($retAt!==null){
                    $entry['assessment']='PHONE_RETURN_AFTER_OUT_IN_HOME_MAC';
                    $entry['reason']='PERSISTENT_PERSONAL_HANDSET_EVENT_NOT_HUMAN_IDENTITY';
                    $entry['phoneAssociatedArrivalOnly']=true;
                }elseif($p03At!==null){
                    $entry['assessment']='HOME_PHONE_CURRENT_ARRIVAL_NOT_PROVEN';
                    $entry['reason']='ANONYMOUS_P03_ENTRY_CANNOT_IDENTIFY_THIS_PHONE';
                }elseif($geoIn){
                    $entry['assessment']='GEOFENCE_IN_NOT_SUFFICIENT_FOR_HOME_RETURN';
                    $entry['reason']='SHARED_RADIUS_IN_WITHOUT_OBSERVED_PRIOR_OUT';
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
