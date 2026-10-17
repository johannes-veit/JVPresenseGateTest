<?php
declare(strict_types=1);

/**
 * Time-aligned PHONE DEVICE observations only.
 *
 * A geofence exit following a known home-WLAN contact is a plausible
 * phone transition out of a shared 150m HOME+WORK circle, not proof
 * that the owner left home or that they walked/drove a given route.
 *
 * No subscription/foreign reads or writes. Evaluation is pure and
 * clears automatically once an event becomes stale.
 */
final class JVResidentPhoneTransitionAudit
{
    private const HOME_CONFIRMED_SECONDS=300;
    private const HOME_MAC_HOLD_SECONDS=660;
    private const GEOFENCE_EXIT_RECENT_SECONDS=1800;
    private const CROSS_SOURCE_WINDOW_SECONDS=600;

    public static function evaluate(
        array $wlan,array $jGeo,array $cGeo,array $work,int $now
    ):array {
        $validWlan=($wlan['schema']??null)===1
            &&($wlan['authority']??null)==='EXISTING_WLAN_MAC_HINTS_READ_ONLY'
            &&($wlan['enabled']??false)===true;
        $output=[
            'schema'=>1,'authority'=>'PHONE_TRANSITION_TIME_HINTS_ONLY',
            'people'=>[],
            'wifiPollSeconds'=>300,
            'wifiHomeConfirmedMaxAgeSeconds'=>self::HOME_CONFIRMED_SECONDS,
            'legacyWlanBooleanHoldSeconds'=>self::HOME_MAC_HOLD_SECONDS,
            'geoExitObservationWindowSeconds'=>self::GEOFENCE_EXIT_RECENT_SECONDS,
            'sharedGeofenceCoversBothHomeAndCompany'=>true,
            'geoOutsideDoesNotIdentifyHuman'=>true,
            'wlanNegativeDoesNotCertifyAbsence'=>true,
            'camerasDoNotIdentifyThisPhone'=>true,
            'travelMethodCertified'=>false,
            'routeCertified'=>false,
            'personHomeOrAwayCertified'=>false,
            'mayTriggerAbsenceActions'=>false,
            'mayArmAlarm'=>false
        ];
        foreach(['johannes','carina'] as $person){
            $w=$validWlan&&is_array($wlan['people'][$person]??null)
                ?$wlan['people'][$person]:[];
            $mac=$w['lastMacContactAt']??null;
            $macValid=($w['validatedExistingVariablePair']??false)===true
                &&is_int($mac)&&$mac>0
                &&$mac<=$now+5&&$mac>=$now-86400;
            $mac=$macValid?$mac:null;
            $age=$macValid?$now-$mac:null;
            $boolean=($w['wlanBoolean']??null)===true;
            $hold=$boolean&&is_int($age)
                &&$age>self::HOME_CONFIRMED_SECONDS
                &&$age<=self::HOME_MAC_HOLD_SECONDS;
            $g=$person==='johannes'?$jGeo:$cGeo;
            $authority=$person==='johannes'
                ?'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY'
                :'EXISTING_CARINA_GEOFENCY_READ_ONLY';
            $geoValid=($g['schema']??null)===1
                &&($g['authority']??null)===$authority
                &&($g['enabled']??false)===true
                &&($g['validatedSource']??false)===true;
            $exitAt=$g['lastEventAt']??null;
            $exit=$geoValid
                &&($g['outsideRecent']??false)===true
                &&($g['geofenceReportedInside']??null)===false
                &&is_int($exitAt)&&$exitAt>0&&$exitAt<=$now+5
                &&$exitAt>=$now-self::GEOFENCE_EXIT_RECENT_SECONDS;
            if(!$exit)$exitAt=null;
            $status='NO_RECENT_PHONE_TRANSITION';
            $gap=null;
            $eventAt=null;
            $description='Kein neuer geprüfter Smartphone-Bereichswechsel';
            if($exitAt!==null){
                $eventAt=$exitAt;
                if($mac!==null){
                    $gap=$exitAt-$mac;
                    if($gap>=0&&$gap<=self::CROSS_SOURCE_WINDOW_SECONDS){
                        $status='HOME_MAC_THEN_SHARED_GEOFENCE_OUT_CANDIDATE';
                        $description='Handy: Geofence OUT nach letztem Heim-WLAN-Kontakt';
                    }elseif($gap<0&&$gap>=-self::CROSS_SOURCE_WINDOW_SECONDS){
                        $status='GEOFENCE_OUT_THEN_HOME_MAC_SOURCE_CONFLICT';
                        $description='Handy: Geofence OUT, danach Heim-WLAN-Kontakt (unklar)';
                    }else{
                        $status='SHARED_GEOFENCE_OUT_NO_NEAR_HOME_MAC';
                        $description='Handy: Geofence OUT, kein zeitnaher Heim-WLAN-Kontakt';
                    }
                }else{
                    $status='SHARED_GEOFENCE_OUT_NO_HOME_MAC';
                    $description='Handy: Geofence OUT, Heim-WLAN-Zeit nicht verfügbar';
                }
            }elseif($hold){
                $status='HOME_WLAN_MAC_PENDING_NEXT_5MIN_CHECK';
                $description='Heim-WLAN AN, echter MAC-Kontakt über 5 Min. alt – Nachprüfung ausstehend';
            }
            $output['people'][$person]=[
                'status'=>$status,
                'description'=>$description,
                'eventAt'=>$eventAt,
                'lastHomeMacAt'=>$mac,
                'lastHomeMacAgeSeconds'=>$age,
                'wlanBooleanStillOn'=>$boolean,
                'homeMacPastStrict300sWithin660sHold'=>$hold,
                'geofenceOutEventAt'=>$exitAt,
                'geofenceAfterLastHomeMacSeconds'=>$gap,
                'possiblePhoneTravelTransitionOnly'=>$exitAt!==null,
                'matchedNamedHuman'=>false,
                'humanHomeOrAwayCertified'=>false,
                'walkingOrDrivingCertified'=>false,
                'filzenerWegRouteCertified'=>false,
                'lagerRouteCertified'=>false,
                'mayTriggerActions'=>false
            ];
        }
        return $output;
    }
}
