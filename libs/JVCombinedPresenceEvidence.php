<?php
declare(strict_types=1);

/**
 * Combines existing read-only WLAN, LCN, P03, gate and optional interior
 * devices into one ANONYMOUS DIAGNOSTIC. Deliberately no Symcon APIs.
 *
 * No smartphone signal is a resident identity or a physical HOME proof.
 * P03 HOME<->LAGER is not a PROPERTY exit. Gate ZU/AUF is not human travel.
 * Even a verified individual exit never directly sets HOME/AWAY.
 */
final class JVCombinedPresenceEvidence
{
    public const SCHEMA=1;
    private const RECENT_MOTION=300;
    private const VERY_RECENT_MOTION=180;
    private const QUIET_FOR_ABSENCE_REVIEW=1800;

    public static function evaluate(
        array $wlan,array $home,array $p03,array $boundary,
        array $gate,array $indoor,int $now,array $geofence=[],array $carinaGeofency=[]
    ):array {
        $data=[
            'schema'=>self::SCHEMA,
            'authority'=>'ANONYMOUS_COMBINED_CONTEXT_ONLY',
            'status'=>'UNKNOWN',
            'description'=>'Quellen werden geprüft',
            'homeActivityHint'=>'UNKNOWN',
            'deviceHint'=>'UNKNOWN',
            'houseMotionAndWlanCorrelated'=>false,
            'freshWlanDeviceCount'=>0,
            'freshWlanDevicesAreNotPeople'=>true,
            'individualResidentsAtHome'=>'UNKNOWN',
            'individualResidentsAway'=>'UNKNOWN',
            'personCount'=>'UNKNOWN',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'alarmMayBeControlled'=>false,
            'allExitRoutesProven'=>false,
            'p03HouseLagerIsPropertyExit'=>false,
            'gatePositionIsHumanExitProof'=>false,
            'wlanOfflineIsHumanAway'=>false,
            'lastHouseMotionAgeSeconds'=>null,
            'p03RecentDirection'=>null,
            'p03DirectionIsIndividualIdentity'=>false,
            'gatePosition'=>'UNKNOWN',
            'indoorReadOnlySourceCount'=>0,
            'indoorOnCount'=>0,
            'indoorDevicesOffProveAbsence'=>false,
            'carinaGeofencyContext'=>'DISABLED_OR_UNVERIFIED',
            'carinaWlanGeofencyCrossCheck'=>'NOT_AVAILABLE',
            'bothPhoneGeofencesNearHome'=>false,
            'carinaGeofencyOutsideNeverCertifiesAbsence'=>true,
            'johannesGeofenceContext'=>'DISABLED_OR_UNVERIFIED',
            'johannesGeofenceRadiusMeters'=>null,
            'johannesGeofenceLastEventAgeSeconds'=>null,
            'johannesWlanGeofenceCrossCheck'=>'NOT_AVAILABLE',
            'geofenceDeviceNotHuman'=>true,
            'geofenceOutsideNeverCertifiesAbsence'=>true,
            'absenceReview'=>[
                'status'=>'INSUFFICIENT_EVIDENCE',
                'mayDeclareAway'=>false,
                'mayArmAlarm'=>false,
                'missingRequirements'=>[]
            ],
            'warnings'=>[]
        ];
        if($now<=0){
            $data['warnings'][]='INVALID_CLOCK';
            return $data;
        }
        if(($wlan['schema']??null)!==1
            ||($wlan['authority']??null)!=='EXISTING_WLAN_MAC_HINTS_READ_ONLY'
            ||($home['schema']??null)!==1
            ||($home['authority']??null)!=='ANONYMOUS_HOME_OBSERVATION_ONLY'
            ||($p03['schema']??null)!==1
            ||($p03['authority']??null)!=='P03_LIVE_PROOFS_DIRECTION_ONLY'
            ||($boundary['schema']??null)!==1
            ||($boundary['authority']??null)!=='READ_ONLY_BOUNDARY_CONTEXT'
            ||($gate['schema']??null)!==1
            ||($gate['authority']??null)!=='EXISTING_SCHIEBETOR_DISPLAY_HINT_ONLY'
            ||($indoor['schema']??null)!==1
            ||($indoor['authority']??null)!=='INDOOR_DEVICE_HINTS_ONLY'){
            $data['warnings'][]='SOURCE_AUTHORITY_OR_SCHEMA_MISMATCH';
            return $data;
        }
        $wlanActive=($wlan['enabled']??false)===true;
        $n=$wlan['freshMacConfirmedDeviceCount']??null;
        $count=$wlanActive&&is_int($n)&&$n>=0&&$n<=2?$n:0;
        $data['freshWlanDeviceCount']=$count;
        $data['deviceHint']=!$wlanActive
            ?'WLAN_NOT_ENABLED'
            :($count===2?'TWO_RECENT_WLAN_DEVICES'
                :($count===1?'ONE_RECENT_WLAN_DEVICE'
                    :'NO_FRESH_WLAN_DEVICE_NOT_ABSENCE'));
        $age=$home['lastHomeMotionAgeSeconds']??null;
        $age=is_int($age)&&$age>=0?$age:null;
        $data['lastHouseMotionAgeSeconds']=$age;
        $healthy=($home['sourcesHealthy']??false)===true
            &&($home['observedHomeGUS']??0)>=15
            &&($home['baselineEstablished']??false)===true;
        $recent=$healthy&&$age!==null&&$age<=self::RECENT_MOTION;
        $strong=$recent&&$age<=self::VERY_RECENT_MOTION
            &&($home['houseStatus']??null)==='HOUSE_MOVEMENT_CORROBORATED';
        $data['homeActivityHint']=!$healthy
            ?'LCN_SOURCE_INCOMPLETE'
            :($strong?'CORROBORATED_RECENT_GUS'
                :($recent?'RECENT_GUS_HINT':'NO_RECENT_GUS'));
        $p03Direction=$home['recentP03Direction']??null;
        if(in_array($p03Direction,['HOME_TO_LAGER','LAGER_TO_HOME'],true)){
            $when=$home['lastP03DirectionAt']??null;
            if(is_int($when)&&$when>0&&$when<=$now+5
                &&$now-$when<=300){
                $data['p03RecentDirection']=$p03Direction;
            }
        }
        $position=$gate['positionHint']??null;
        if(is_string($position)&&in_array($position,[
            'ZU','AUF','ZWISCHENSTELLUNG_ODER_FAHRT','UNBEKANNT'
        ],true))$data['gatePosition']=$position;
        $sourceN=$indoor['sourceCount']??0;
        $on=$indoor['onCount']??0;
        $data['indoorReadOnlySourceCount']=is_int($sourceN)&&$sourceN>=0?$sourceN:0;
        $data['indoorOnCount']=is_int($on)&&$on>=0&&$on<=$data['indoorReadOnlySourceCount']
            ?$on:0;
        // EgiGeoZone is an Android handset geofence event, not a person,
        // property-boundary crossing, measured distance or continuous GPS.
        // Only explicitly enabled, schema-authorized, recent events can
        // enrich the context. Never use it to assert individual HOME/AWAY.
        $geoAuthorized=($geofence['schema']??null)===1
            &&($geofence['authority']??null)==='EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY'
            &&($geofence['enabled']??null)===true
            &&($geofence['validatedSource']??null)===true
            &&($geofence['radiusVerifiedViaSymcon']??true)===false
            &&is_int($geofence['radiusMetersConfiguredInPhoneApp']??null);
        $geoInside=$geoAuthorized &&($geofence['insideRecent']??null)===true
            &&($geofence['outsideRecent']??null)===false;
        $geoOutside=$geoAuthorized &&($geofence['outsideRecent']??null)===true
            &&($geofence['insideRecent']??null)===false;
        $johannesWlan=($wlan['people']['johannes']['recentMacEvidence']??false)===true;
        if($geoAuthorized){
            $data['johannesGeofenceRadiusMeters']=$geofence['radiusMetersConfiguredInPhoneApp'];
            $data['johannesGeofenceLastEventAgeSeconds']=$geofence['lastEventAgeSeconds'];
            $data['johannesGeofenceContext']=$geoInside
                ?'JOHANNES_PHONE_REPORTED_WITHIN_HOME_GEOFENCE'
                :($geoOutside?'JOHANNES_PHONE_REPORTED_OUTSIDE_HOME_GEOFENCE'
                    :'GEOFENCE_EVENT_TOO_OLD_OR_INVALID_NOT_CURRENT');
            $data['johannesWlanGeofenceCrossCheck']=$geoInside
                ?($johannesWlan?'GEOFENCE_AND_WLAN_PHONE_NEAR_HOME'
                    :'GEOFENCE_IN_WLAN_NOT_CONFIRMED')
                :($geoOutside
                    ?($johannesWlan?'WLAN_ON_GEOFENCE_OUT_REVIEW_ONLY'
                        :'GEOFENCE_OUT_WLAN_NOT_CONFIRMED_NOT_ABSENCE')
                    :'STALE_GEOFENCE_NOT_USED');
        }
        // Carina iPhone Geofency is an event-backed handset location flag.
        // Do not treat it as a continuously measured location or proof
        // that Carina is physically inside/outside the property.
        $carinaAuthorized=($carinaGeofency['schema']??null)===1
            &&($carinaGeofency['authority']??null)==='EXISTING_CARINA_GEOFENCY_READ_ONLY'
            &&($carinaGeofency['enabled']??null)===true
            &&($carinaGeofency['validatedSource']??null)===true;
        $carinaInside=$carinaAuthorized
            &&($carinaGeofency['insideRecent']??null)===true
            &&($carinaGeofency['outsideRecent']??null)===false;
        $carinaOutside=$carinaAuthorized
            &&($carinaGeofency['outsideRecent']??null)===true
            &&($carinaGeofency['insideRecent']??null)===false;
        $carinaWlan=($wlan['people']['carina']['recentMacEvidence']??false)===true;
        if($carinaAuthorized){
            $data['carinaGeofencyContext']=$carinaInside
                ?'CARINA_PHONE_REPORTED_INSIDE_HOME_GEOFENCE'
                :($carinaOutside?'CARINA_PHONE_REPORTED_OUTSIDE_HOME_GEOFENCE'
                    :'CARINA_GEOFENCY_STALE_OR_INVALID');
            $data['carinaWlanGeofencyCrossCheck']=$carinaInside
                ?($carinaWlan?'GEOFENCY_AND_WLAN_CARINA_PHONE_NEAR_HOME'
                    :'GEOFENCY_IN_WLAN_NOT_CONFIRMED')
                :($carinaOutside
                    ?($carinaWlan?'WLAN_ON_GEOFENCY_OUT_REVIEW_ONLY'
                        :'GEOFENCY_OUT_WLAN_NOT_CONFIRMED_NOT_ABSENCE')
                    :'STALE_GEOFENCY_NOT_USED');
        }
        $data['bothPhoneGeofencesNearHome']=$geoInside&&$carinaInside;
        if($count>0&&$recent) {
            $data['status']=$strong
                ?'HOUSE_ACTIVITY_WITH_WLAN_DEVICE_CORROBORATED'
                :'HOUSE_ACTIVITY_WITH_WLAN_DEVICE_HINT';
            $data['description']=$strong
                ?'Aktuelle GUS-Bewegung durch verschiedene Melder gestützt; WLAN-Handy gleichzeitig bestätigt. Keine Personenidentität.'
                :'Aktueller GUS-Bewegungshinweis und mindestens ein bestätigtes WLAN-Handy. Keine Personenidentität.';
            $data['houseMotionAndWlanCorrelated']=true;
        }elseif($recent){
            $data['status']='HOUSE_ACTIVITY_WITHOUT_FRESH_WLAN';
            $data['description']='GUS-Bewegung im Haus trotz fehlendem aktuellem WLAN-Handy-Nachweis. Das Handy darf nicht als abwesende Person gelten.';
        }elseif($count>0){
            $data['status']=$geoInside&&$carinaInside&&$johannesWlan&&$carinaWlan
                ?'BOTH_PHONES_WLAN_AND_GEOFENCE_NEAR_HOME_NO_MOTION'
                :($carinaInside&&$carinaWlan
                    ?'CARINA_WLAN_GEOFENCY_DEVICE_NEAR_HOME_NO_RECENT_MOTION'
                    :($geoInside&&$johannesWlan
                ?'JOHANNES_WLAN_GEOFENCE_DEVICE_NEAR_HOME_NO_RECENT_MOTION'
                :'WLAN_DEVICES_ONLY_NO_RECENT_HOME_MOTION'));
            $data['description']=$geoInside&&$johannesWlan
                ?'Johannes Smartphone via WLAN und gemeldetem 150-m-Geofence nahe Haus; keine aktuelle GUS-Bewegung. Telefon ist kein Personenbeweis.'
                :'Smartphone(s) im WLAN, aber keine aktuelle bestätigte Hausbewegung. Auch liegengebliebene Handys möglich.';
        }elseif($data['p03RecentDirection']==='LAGER_TO_HOME'){
            $data['status']='P03_HOME_ENTRY_DIRECTION_ONLY';
            $data['description']='Frischer P03-Richtungsnachweis Lager nach Haus, ohne Identität oder Personenzahl.';
        }elseif($carinaInside){
            $data['status']='CARINA_GEOFENCY_PHONE_NEAR_HOME_ONLY';
            $data['description']='Geofency hat das iPhone in der Zuhause-Zone gemeldet, ohne aktuelle GUS-/WLAN-Bestaetigung. Keine sichere Personenerkennung.';
        }elseif($geoInside){
            $data['status']='JOHANNES_GEOFENCE_PHONE_NEAR_HOME_ONLY';
            $data['description']='EgiGeoZone hat das Smartphone innerhalb des Zuhause-Geofences gemeldet, ohne aktuellen WLAN-/GUS-Nachweis. Keine Bewohneridentitaet oder sichere Anwesenheit.';
        }elseif($data['indoorOnCount']>0){
            $data['status']='INDOOR_DEVICE_ON_WEAK_HINT';
            $data['description']='Innenlicht oder TV aktiv; auch automatischer Betrieb möglich. Keine sichere Personenerkennung.';
        }elseif($healthy&&is_int($home['quietForSeconds']??null)
            &&$home['quietForSeconds']>=self::QUIET_FOR_ABSENCE_REVIEW){
            $data['status']='HOUSE_QUIET_WLAN_UNCONFIRMED';
            $data['description']='Längere Hausruhe, kein frischer WLAN-Handy-Nachweis. Schlaf, WLAN-Standby und andere Personen bleiben möglich.';
        }elseif($data['p03RecentDirection']==='HOME_TO_LAGER'){
            $data['status']='P03_HOUSE_TO_WORK_DIRECTION_ONLY';
            $data['description']='P03 meldet Haus nach Lager, NICHT das Verlassen des Grundstücks.';
        }else{
            $data['status']='CURRENT_OCCUPANCY_UNKNOWN';
            $data['description']='Zurzeit kein gemeinsam verifizierter Aufenthaltsnachweis. Abwesenheit ist nicht bestätigt.';
        }
        $missing=[];
        if($geoInside)
            $missing[]='JOHANNES_GEOFENCE_PHONE_NEAR_HOME_NOT_ABSENCE';
        if($carinaInside)
            $missing[]='CARINA_GEOFENCY_PHONE_NEAR_HOME_NOT_ABSENCE';
        if(!$wlanActive)$missing[]='WLAN_PHONE_OBSERVATION_DISABLED';
        elseif($count>0)$missing[]='WLAN_DEVICES_STILL_CONFIRMED';
        if(!$healthy)$missing[]='LCN_FULL_COVERAGE_NOT_VERIFIED';
        if(!is_int($home['quietForSeconds']??null)
            ||$home['quietForSeconds']<self::QUIET_FOR_ABSENCE_REVIEW){
            $missing[]='HOUSE_QUIET_30_MIN_NOT_ESTABLISHED';
        }
        if(($boundary['allExitRoutesVerified']??false)!==true)
            $missing[]='ALL_PROPERTY_EXIT_ROUTES_NOT_VERIFIED';
        if(($boundary['exitDirectionCertified']??false)!==true)
            $missing[]='VERIFIED_PROPERTY_EXIT_DIRECTION_MISSING';
        if(($boundary['peopleCountCertified']??false)!==true)
            $missing[]='PER_PERSON_EXIT_PROOF_MISSING';
        if(($boundary['identityCertified']??false)!==true)
            $missing[]='RESIDENT_IDENTITIES_AT_EXIT_UNVERIFIED';
        // Future route coverage may improve, but an observable verified
        // individual-exit timestamp must ALSO be exposed separately.
        $exitAt=$boundary['lastVerifiedHumanPropertyExitAt']??null;
        if(!is_int($exitAt)||$exitAt<=0||$exitAt>$now+5
            ||$now-$exitAt>86400)
            $missing[]='FRESH_VERIFIED_PROPERTY_EXIT_MISSING';
        $data['allExitRoutesProven']=($boundary['allExitRoutesVerified']??false)===true;
        $data['absenceReview']=[
            'status'=>$missing===[]?'REVIEW_ONLY_NOT_CERTIFIED':'INSUFFICIENT_EVIDENCE',
            'mayDeclareAway'=>false,'mayArmAlarm'=>false,
            'missingRequirements'=>$missing,
            'note'=>'P03-Haus/Lager, geschlossene Tore, WLAN-FALSE und GUS-Ruhe sind einzeln oder kombiniert kein Leer-Nachweis.'
        ];
        return $data;
    }
}
