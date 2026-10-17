<?php
declare(strict_types=1);

/**
 * Persisted, read-only observations of each resident's handset.
 *
 * This is an observation ledger, not a physical human presence state.
 * In particular an ARP/WLAN silence is never a departure, and a renewed
 * MAC timestamp alone is never a human arrival.
 */
final class JVPhoneContactContinuity
{
    private const HISTORY_SECONDS=86400;
    private const GEO_WINDOW_SECONDS=21600;
    private const HOME_FRESH_SECONDS=300;
    private const GAP_SECONDS=660;

    public static function evaluate(
        array $wlan,array $jGeo,array $cGeo,array $prior,int $now
    ):array {
        $report=['schema'=>1,'authority'=>'READ_ONLY_PHONE_OBSERVATION_CONTINUITY',
            'people'=>[],'anyHumanArrivalCertified'=>false,
            'anyHumanDepartureCertified'=>false,'mayArmAlarm'=>false];
        $next=['schema'=>1,'people'=>[]];
        $wlanValid=($wlan['schema']??null)===1
            &&($wlan['authority']??null)==='EXISTING_WLAN_MAC_HINTS_READ_ONLY'
            &&($wlan['enabled']??false)===true;
        if($now<=0)return ['report'=>$report,'next'=>$next];
        foreach(['johannes','carina'] as $person) {
            $previous=is_array($prior['people'][$person]??null)
                ?$prior['people'][$person]:[];
            $start=self::time($previous['startedAt']??null,$now,self::HISTORY_SECONDS);
            $hadBaseline=$start!==null;
            if($start===null)$start=$now;
            $w=$wlanValid&&is_array($wlan['people'][$person]??null)
                ?$wlan['people'][$person]:[];
            $paired=($w['validatedExistingVariablePair']??false)===true;
            $mac=self::time($w['lastMacContactAt']??null,$now,self::HISTORY_SECONDS);
            $oldMac=self::time($previous['lastMacAt']??null,$now,self::HISTORY_SECONDS);
            $true=($w['wlanBoolean']??null)===true;
            $fresh=$paired&&$true
                &&($w['recentMacEvidence']??false)===true
                &&($w['state']??null)==='MAC_CONFIRMED_RECENT_DEVICE_HINT'
                &&$mac!==null && $mac>=$now-self::HOME_FRESH_SECONDS;
            $advanced=$fresh && $oldMac!==null && $mac>$oldMac;
            $gap=$advanced ? $mac-$oldMac : null;
            $status=$fresh?'FRESH_HOME_MAC_CONTACT':
                ($paired&&$true&&$mac!==null
                    ?'WLAN_ON_BUT_MAC_CONTACT_STALE_NOT_AWAY'
                    :'HOME_PHONE_NOT_CURRENTLY_CONFIRMED');
            if($advanced&&$gap>self::GAP_SECONDS)
                $status='HOME_MAC_RECOVERED_AFTER_GAP_NOT_ARRIVAL';

            $g=$person==='johannes'?$jGeo:$cGeo;
            $authority=$person==='johannes'
                ?'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY'
                :'EXISTING_CARINA_GEOFENCY_READ_ONLY';
            $geoOK=($g['schema']??null)===1
                &&($g['authority']??null)===$authority
                &&($g['enabled']??false)===true
                &&($g['validatedSource']??false)===true;
            $geoAt=$geoOK
                ?self::time($g['lastEventAt']??null,$now,self::GEO_WINDOW_SECONDS)
                :null;
            $kind=null;
            if($geoAt!==null){
                if(($g['insideRecent']??false)===true
                    &&($g['outsideRecent']??false)===false
                    &&($g['geofenceReportedInside']??null)===true)$kind='IN';
                if(($g['outsideRecent']??false)===true
                    &&($g['insideRecent']??false)===false
                    &&($g['geofenceReportedInside']??null)===false)$kind='OUT';
            }
            $priorGeo=self::time($previous['lastGeoAt']??null,$now,self::GEO_WINDOW_SECONDS);
            $outAt=self::time($previous['pendingOutAt']??null,$now,self::GEO_WINDOW_SECONDS);
            $inAt=self::time($previous['pendingInAt']??null,$now,self::GEO_WINDOW_SECONDS);
            $lastReturn=self::time($previous['lastPhoneReturnAt']??null,$now,self::HISTORY_SECONDS);
            $recoveredAt=self::time($previous['lastRecoveryAt']??null,
                $now,self::HISTORY_SECONDS);
            $recoveredGap=$previous['lastRecoveryGapSeconds']??null;
            if(!is_int($recoveredGap)||$recoveredGap<=self::GAP_SECONDS
                ||$recoveredGap>self::HISTORY_SECONDS||$recoveredAt===null)
                $recoveredGap=null;
            if($advanced&&$gap>self::GAP_SECONDS){
                $recoveredAt=$mac;
                $recoveredGap=$gap;
            }
            $newGeo=false;
            // Initial read is only a baseline; never backfill an old
            // Geofence event as a newly observed departure/return.
            if($geoAt!==null&&$kind!==null){
                if($priorGeo===null)
                    $priorGeo=$hadBaseline&&$geoAt>$start?$start:$geoAt;
                if($geoAt>$priorGeo){
                    $newGeo=true;$priorGeo=$geoAt;
                    if($kind==='OUT'){
                        $outAt=$oldMac!==null&&$oldMac<$geoAt
                            &&$geoAt-$oldMac<=1800 ?$geoAt:null;
                        $inAt=null;
                    }elseif($kind==='IN'){
                        $inAt=$outAt!==null&&$outAt<$geoAt
                            &&$geoAt-$outAt<=self::GEO_WINDOW_SECONDS
                            ?$geoAt:null;
                    }
                }
            }
            $returnNew=false;
            // Only a previously observed, ordered OUT->IN sequence,
            // followed by a fresh verified MAC contact, is a phone-return
            // HINT. This never identifies a person or the travel mode.
            if($inAt!==null && $outAt!==null &&$fresh
                &&$mac>=$inAt &&$mac-$inAt<=1800
                &&($lastReturn===null||$lastReturn<$inAt)){
                $lastReturn=$mac;$returnNew=true;
                $outAt=null;$inAt=null;
            }
            $lastMac=$mac!==null&&($oldMac===null||$mac>$oldMac)
                ?$mac:$oldMac;
            $next['people'][$person]=[
                'startedAt'=>$start,
                'lastMacAt'=>$lastMac,'lastGeoAt'=>$priorGeo,
                'pendingOutAt'=>$outAt,'pendingInAt'=>$inAt,
                'lastPhoneReturnAt'=>$lastReturn,
                'lastRecoveryAt'=>$recoveredAt,
                'lastRecoveryGapSeconds'=>$recoveredGap
            ];
            $report['people'][$person]=[
                'status'=>$status,'freshHomeMac'=>$fresh,
                'lastActualMacAt'=>$lastMac,
                'lastActualMacAgeSeconds'=>$lastMac!==null?$now-$lastMac:null,
                'contactResumedAfterGap'=>$advanced&&$gap>self::GAP_SECONDS,
                'observedMacGapSeconds'=>$gap,
                'lastRecoveredHomeMacAt'=>$recoveredAt,
                'lastObservedRecoveryGapSeconds'=>$recoveredGap,
                'lastRecoveredHomeMacAgeSeconds'=>$recoveredAt!==null?$now-$recoveredAt:null,
                'lastPhoneReturnAt'=>$lastReturn,
                'newGeofenceEventObserved'=>$newGeo,
                'phoneReturnAfterObservedOutIn'=>$returnNew,
                'phoneReturnAt'=>$returnNew?$lastReturn:null,
                'geofenceOutInRequired'=>true,
                'anonymousCameraNotAssignedToPerson'=>true,
                'humanPresenceCertified'=>false,
                'humanAwayCertified'=>false
            ];
        }
        return ['report'=>$report,'next'=>$next];
    }

    private static function time(mixed $v,int $now,int $ttl):?int
    {
        return is_int($v)&&$v>0&&$v<=$now+5&&$v>=$now-$ttl
            ?$v:null;
    }
}
