<?php
declare(strict_types=1);

/**
 * Passive HOME occupancy-evidence evaluator. Uses only already present
 * 15 LCN GUS and P03 directional proofs. NEVER establishes persons, names,
 * a complete household, property absence, arming rights or alarm actions.
 *
 * No clock extrapolation from "INACTIVE" flags. No counting P03 proofs
 * as persons and no assumption that HOME -> WORK leaves the property.
 */
final class JVHomeOccupancyEvidence
{
    public const SCHEMA=1;
    public const REQUIRED_HOME_GUS=15;
    public const HINT_TTL=300;       // Five minutes of recent observations
    public const CORROBORATED_TTL=180; // Only recent independent movement
    public const QUIET_AFTER=1800;   // Silence is a diagnostic, NOT an exit
    private const ZONES=['HOME','WORK','PROPERTY_OTHER'];
    private const PROOF_DIRECTIONS=['HOME_TO_LAGER','LAGER_TO_HOME'];
    private const POSITIVE_STATUSES=[
        'SINGLE_UNCERTAIN','KAMIN_UNCORROBORATED',
        'KAMIN_KITCHEN_CORROBORATED','MULTI_SENSOR_CORROBORATED'
    ];

    /**
     * Inputs: JVLocalSensorEvidence report, JVLocalActivityLedger report,
     * JVP03IncrementalTimeline report; each is READ ONLY.
     * Values must be current and match source schema. Invalid, missing,
     * future-dated or truncated data fails to UNKNOWN.
     */
    public static function evaluate(
        array $local,array $activity,array $p03,int $now,array $indoorHints=[]
    ):array {
        $out=[
            'schema'=>self::SCHEMA,
            'authority'=>'ANONYMOUS_HOME_OBSERVATION_ONLY',
            'houseStatus'=>'UNKNOWN',
            'houseStatusDetail'=>'Eingangsquellen noch nicht verifiziert',
            'absenceControlState'=>'NOT_CERTIFIED',
            'personCount'=>'UNKNOWN',
            'personIdentities'=>'UNKNOWN',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'alarmMayBeControlled'=>false,
            'observedHomeGUS'=>0,
            'requiredHomeGUS'=>self::REQUIRED_HOME_GUS,
            'sourcesHealthy'=>false,
            'baselineEstablished'=>false,
            'lastHomeMotionAt'=>null,
            'lastHomeMotionAgeSeconds'=>null,
            'quietForSeconds'=>null,
            'lastP03Direction'=>null,
            'lastP03DirectionAt'=>null,
            'recentP03Direction'=>null,
            'indoorDevicesOn'=>0,
            'indoorDeviceHintLevel'=>'NONE',
            'reasons'=>[],
            'warnings'=>[]
        ];
        if(($indoorHints['authority']??null)==='INDOOR_DEVICE_HINTS_ONLY'
            && ($indoorHints['schema']??null)===1){
            $out['indoorDevicesOn']=max(0,(int)($indoorHints['onCount']??0));
            $out['indoorDeviceHintLevel']=$indoorHints['hintLevel']??'NONE';
        }
        if($now<=0) {
            $out['warnings'][]='INVALID_CLOCK';return $out;
        }
        if(($local['schema']??null)!==1
            ||($local['authority']??null)!=='LOCAL_SENSOR_HINTS_ONLY'
            ||($activity['schema']??null)!==1
            ||($activity['authority']??null)!=='MOTION_EDGE_HISTORY_ONLY'
            ||($p03['schema']??null)!==1
            ||($p03['authority']??null)!=='P03_LIVE_PROOFS_DIRECTION_ONLY') {
            $out['warnings'][]='INPUT_SCHEMA_OR_AUTHORITY_INVALID';
            return $out;
        }
        $out['baselineEstablished']=($local['baselineInitialized']??false)===true;
        $readings=$local['readings']??[];
        $validHomeGUS=0;
        $missingHomeGUS=0;
        if(!is_array($readings)) {
            $out['warnings'][]='LCN_READINGS_INVALID';return $out;
        }
        foreach($readings as $row) {
            if(!is_array($row) || ($row['zone']??null)!=='HOME'
                || !in_array(($row['kind']??null),[
                    'MOTION','MOTION_KAMIN','MOTION_KITCHEN'
                ],true)) continue;
            if(in_array(($row['state']??null),['ACTIVE','INACTIVE'],true))
                $validHomeGUS++;
            else $missingHomeGUS++;
        }
        $out['observedHomeGUS']=$validHomeGUS;
        $lWarnings=$local['warnings']??[];
        $aWarnings=$activity['warnings']??[];
        $pWarnings=$p03['warnings']??[];
        if(!is_array($lWarnings)||!is_array($aWarnings)||!is_array($pWarnings)) {
            $out['warnings'][]='INPUT_WARNINGS_INVALID';return $out;
        }
        $out['sourcesHealthy']=$out['baselineEstablished']
            && $validHomeGUS>=self::REQUIRED_HOME_GUS
            && $missingHomeGUS===0
            && !$lWarnings && !$aWarnings && !$pWarnings;
        if(!$out['sourcesHealthy']) {
            $out['warnings'][]='SENSOR_COVERAGE_OR_HEALTH_INCOMPLETE';
        }

        $startedAt=$activity['startedAt']??null;
        if(!is_int($startedAt)||$startedAt<=0||$startedAt>$now) {
            $out['warnings'][]='ACTIVITY_WINDOW_BASELINE_UNAVAILABLE';
            $startedAt=null;
        }
        $lastHome=null;
        $latestStrong=null;
        $seenCount=0;
        $history=$activity['recentMotionEdges']??[];
        if(!is_array($history)) {
            $out['warnings'][]='ACTIVITY_HISTORY_INVALID';return $out;
        }
        foreach(array_slice($history,0,15) as $evt) {
            if(!is_array($evt)||($evt['zone']??null)!=='HOME')continue;
            $at=$evt['at']??null;
            $confidence=$evt['confidence']??null;
            if(!is_int($at)||$at>$now+5||$at<=0
                ||!in_array($confidence,self::POSITIVE_STATUSES,true))continue;
            if($startedAt!==null && $at<$startedAt-5)continue;
            $seenCount++;
            if($lastHome===null || $at>$lastHome)$lastHome=$at;
            if(in_array($confidence,[
                'KAMIN_KITCHEN_CORROBORATED','MULTI_SENSOR_CORROBORATED'
            ],true)) {
                if($latestStrong===null||$at>$latestStrong)$latestStrong=$at;
            }
        }
        // Ledger already stores a per-source durable, deduplicated last edge.
        // recentMotionEdges is capped to 15 across ALL zones; therefore use
        // perSource for the latest real HOME motion (avoids losing house time
        // when many WORK / outside events crowded the recent list).
        $bySource=$activity['perSource']??[];
        if(is_array($bySource)) foreach($bySource as $entry) {
            if(!is_array($entry)||($entry['zone']??null)!=='HOME')continue;
            $at=$entry['lastMotionAt']??null;
            if(!is_int($at)||$at<=0||$at>$now+5)continue;
            if($startedAt!==null && $at<$startedAt-5)continue;
            if($lastHome===null||$at>$lastHome)$lastHome=$at;
        }
        $out['lastHomeMotionAt']=$lastHome;
        $out['lastHomeMotionAgeSeconds']=$lastHome===null
            ?null:max(0,$now-$lastHome);
        if($startedAt!==null) {
            $out['quietForSeconds']=$lastHome!==null
                ?max(0,$now-$lastHome):max(0,$now-$startedAt);
        }

        $directions=$p03['recentDirectionProofs']??[];
        if(!is_array($directions)) {
            $out['warnings'][]='P03_TIMELINE_INVALID';
            $directions=[];
        }
        foreach(array_slice($directions,0,15) as $evt) {
            if(!is_array($evt)||!in_array(
                ($evt['direction']??null),self::PROOF_DIRECTIONS,true))continue;
            $at=$evt['at']??null;
            if(!is_int($at)||$at<=0||$at>$now+5)continue;
            if($out['lastP03DirectionAt']===null
                ||$at>$out['lastP03DirectionAt']) {
                $out['lastP03Direction']=$evt['direction'];
                $out['lastP03DirectionAt']=$at;
            }
        }
        if($out['lastP03DirectionAt']!==null
            && $now-$out['lastP03DirectionAt']<=self::HINT_TTL) {
            $out['recentP03Direction']=$out['lastP03Direction'];
        }

        if(!$out['sourcesHealthy'] || $startedAt===null) {
            $out['houseStatus']='UNKNOWN';
            $out['houseStatusDetail']='Quellenzustand oder Startbaseline unvollständig';
            $out['reasons'][]='FALLBACK_FAIL_CLOSED';
            return $out;
        }

        $age=$out['lastHomeMotionAgeSeconds'];
        $strongRecent=$latestStrong!==null
            && $now-$latestStrong<=self::CORROBORATED_TTL;
        $singleRecent=$age!==null && $age<=self::HINT_TTL;
        if($strongRecent) {
            $out['houseStatus']='HOUSE_MOVEMENT_CORROBORATED';
            $out['houseStatusDetail']='Bewegung im Haus durch unterschiedliche GUS gestützt';
            $out['reasons'][]='LCN_MULTIPLE_DISTINCT_GUS';
        } elseif($singleRecent) {
            $out['houseStatus']='HOUSE_MOVEMENT_HINT';
            $out['houseStatusDetail']='Aktueller Bewegungsimpuls im Haus, Identität offen';
            $out['reasons'][]='LCN_RECENT_MOTION';
        } elseif($out['recentP03Direction']==='LAGER_TO_HOME') {
            $out['houseStatus']='HOME_ENTRY_DIRECTION_HINT';
            $out['houseStatusDetail']='P03 meldet Richtung Lager nach Haus; keine Identität oder Personenzahl';
            $out['reasons'][]='P03_WORK_TO_HOME';
        } elseif($out['indoorDeviceHintLevel']==='WEAK_INDOOR_DEVICE_ON'
            && $out['indoorDevicesOn']>0) {
            $out['houseStatus']='HOME_DEVICE_ON_HINT';
            $out['houseStatusDetail']='Innenlicht oder TV eingeschaltet: schwacher Hinweis, auch automatischer Betrieb möglich';
            $out['reasons'][]='INDOOR_LIGHT_OR_TV_ON_NOT_INDEPENDENT_MOTION';
        } elseif($out['recentP03Direction']==='HOME_TO_LAGER') {
            $out['houseStatus']='HOME_EXIT_DIRECTION_HINT';
            $out['houseStatusDetail']='P03 meldet Richtung Haus nach Lager; andere Hausbewohner bleiben möglich';
            $out['reasons'][]='P03_HOME_TO_WORK_NOT_PROPERTY_EXIT';
        } elseif($out['quietForSeconds']!==null
            && $out['quietForSeconds']>=self::QUIET_AFTER) {
            $out['houseStatus']='HOUSE_QUIET_NO_ABSENCE_PROOF';
            $out['houseStatusDetail']='Seit mindestens 30 Minuten kein nachgewiesener GUS-Impuls – Schlaf/Ruhe möglich';
            $out['reasons'][]='NO_POSITIVE_MOTION_DOES_NOT_PROVE_AWAY';
        } else {
            $out['houseStatus']='UNKNOWN';
            $out['houseStatusDetail']='Noch keine aktuellen sicheren Bewegungshinweise';
            $out['reasons'][]='NO_RECENT_PROOF';
        }
        // Deliberately fixed hard safety invariant. Other systems can display
        // these hints but MUST NOT arm alarms or auto-declare empty premises.
        $out['absenceControlState']='NOT_CERTIFIED';
        return $out;
    }
}
