<?php
declare(strict_types=1);

/**
 * Existing IP-Symcon EgiGeoZone Android device values only.
 * This observes the handset's app-reported 150 m geofence, NOT the user's
 * physical presence or an independently measured distance. No coordinates,
 * network calls, smartphone actions, or new Symcon variables are needed.
 * Entry/exit events can be late, missed or stale.
 */
final class JVJohannesEgiGeoZoneHints
{
    public const EVENT_FRESH_SECONDS=21600; // 6 h. Old zone flags are not live proof.

    public static function evaluate(array $snapshot,int $now,bool $enabled,
        int $radiusMeters=150):array
    {
        $report=[
            'schema'=>1,
            'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
            'enabled'=>$enabled,
            'state'=>$enabled?'SOURCE_NOT_VERIFIED':'DISABLED',
            'zoneName'=>'Zuhause',
            'radiusMetersConfiguredInPhoneApp'=>$radiusMeters,
            'radiusVerifiedViaSymcon'=>false,
            'radiusIsAppSettingNotMeasuredDistance'=>true,
            'geofenceReportedInside'=>null,
            'lastEventAt'=>null,
            'lastEventAgeSeconds'=>null,
            'freshEventMaxAgeSeconds'=>self::EVENT_FRESH_SECONDS,
            'insideRecent'=>false,
            'outsideRecent'=>false,
            'validatedSource'=>false,
            'appCanMissOrDelayTransitions'=>true,
            'handsetIsNotPerson'=>true,
            'outsideIsNotVerifiedResidentAway'=>true,
            'locationCoordinatesRead'=>false,
            'phoneSettingsModified'=>false,
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'alarmMayBeControlled'=>false,
            'warnings'=>[]
        ];
        if(!$enabled)return $report;
        if($now<=0||$radiusMeters<50||$radiusMeters>10000){
            $report['state']='INVALID_CONFIGURATION';
            $report['warnings'][]='INVALID_CLOCK_OR_RADIUS';
            return $report;
        }
        if(($snapshot['verified']??false)!==true
            ||!is_bool($snapshot['inside']??null)
            ||!is_int($snapshot['timestamp']??null)){
            $report['warnings'][]='SOURCE_IDS_NAMES_TYPES_OR_PARENT_NOT_VERIFIED';
            return $report;
        }
        $report['validatedSource']=true;
        $report['geofenceReportedInside']=$snapshot['inside'];
        $at=$snapshot['timestamp'];
        if($at<=0||$at>$now+5){
            $report['state']='INVALID_LAST_EVENT_TIMESTAMP';
            $report['warnings'][]='GEOFENCE_TIMESTAMP_INVALID';
            return $report;
        }
        $age=max(0,$now-$at);
        $report['lastEventAt']=$at;
        $report['lastEventAgeSeconds']=$age;
        if($age>self::EVENT_FRESH_SECONDS){
            $report['state']=$snapshot['inside']
                ?'STALE_INSIDE_NOT_LIVE_PRESENCE'
                :'STALE_OUTSIDE_NOT_LIVE_ABSENCE';
            return $report;
        }
        if($snapshot['inside']){
            $report['state']='RECENT_INSIDE_150M_DEVICE_HINT';
            $report['insideRecent']=true;
        }else{
            $report['state']='RECENT_OUTSIDE_150M_DEVICE_HINT_NOT_ABSENCE';
            $report['outsideRecent']=true;
        }
        return $report;
    }
}
