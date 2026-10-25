<?php
declare(strict_types=1);

/**
 * Conservative, read-only household "likely away" visual HINT.
 *
 * THIS IS NOT AN EMPTY-HOUSE CERTIFICATE. Do not wire this result to
 * heating setback, security, locks, alarms, shutters or automations.
 * Phones can be left behind and a sleeping person can make no motion.
 *
 * Both individually verified phones must have separately time-stamped
 * OUTSIDE events for the shared home/work geofence, and 15 GUS plus
 * verified light/TV observations must remain quiet. Existing IP-Symcon
 * sources are read by upstream code, never altered here.
 */
final class JVHouseholdAwayIndicator
{
    public const QUIET_SECONDS=1800; // 30 min, motion AND last light changes.
    public const HOLD_SECONDS=600; // further 10 min continuous clean polls.
    public const MAX_OUT_AGE=21600; // 6h; not permanent "away" from old events.

    public static function evaluate(
        array $phones,array $jGeo,array $cGeo,array $house,array $indoor,
        array $previous,int $now
    ):array {
        $reasons=[];
        $out=[
            'schema'=>1,'authority'=>'HOUSEHOLD_AWAY_VISUAL_HINT_ONLY',
            'status'=>'UNKNOWN',
            'display'=>'Unklar',
            'eligible'=>false,'likelyAway'=>false,
            'quietRequiredSeconds'=>self::QUIET_SECONDS,
            'stableRequiredSeconds'=>self::HOLD_SECONDS,
            'outsideEventMaxAgeSeconds'=>self::MAX_OUT_AGE,
            'observedStableSeconds'=>0,
            'geofenceOutside'=>['johannes'=>false,'carina'=>false],
            'checkedLights'=>0,'checkedTVs'=>0,
            'mayConfirmPeopleAway'=>false,'mayCertifyHouseEmpty'=>false,
            'mayArmAlarm'=>false,'mayChangeOtherModules'=>false,
            'reasons'=>[]
        ];
        $next=['schema'=>1,'eligibleSince'=>null,'sources'=>null];
        if($now<=0){$reasons[]='CLOCK_INVALID';return self::finish($out,$next,$reasons);}
        if(($phones['schema']??null)!==1
            ||($phones['authority']??null)!=='RESIDENT_PHONE_VISU_HINTS_READ_ONLY'
            ||!is_array($phones['people']??null)){
            $reasons[]='PHONE_VISU_SOURCE_UNVERIFIED';
            return self::finish($out,$next,$reasons);
        }
        $keys=[];
        foreach(['johannes'=>$jGeo,'carina'=>$cGeo] as $person=>$geo){
            $p=$phones['people'][$person]??null;
            $expected=$person==='johannes'
                ?'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY'
                :'EXISTING_CARINA_GEOFENCY_READ_ONLY';
            if(!is_array($p)||in_array($p['decision']??null,[
                'HOME_PHONE_CONFIRMED','WORK_PHONE_CONFIRMED','CONFLICT'
            ],true)||($p['deviceZone']??null)!=='UNKNOWN'
                ||($p['wifiConflict']??false)===true){
                $reasons[]='PHONE_'.$person.'_HOME_WORK_OR_CONFLICT';
                continue;
            }
            $at=$geo['lastEventAt']??null;
            $verified=($geo['schema']??null)===1
                &&($geo['authority']??null)===$expected
                &&($geo['enabled']??false)===true
                &&($geo['validatedSource']??false)===true
                &&($geo['outsideRecent']??false)===true
                &&($geo['insideRecent']??true)===false
                &&($geo['geofenceReportedInside']??null)===false
                &&is_string($geo['sourceKey']??null)
                &&preg_match('/^(?:EGI|GEOFENCY):(?:[1-9][0-9]*:){3}[1-9][0-9]*$/D',
                    $geo['sourceKey'])===1
                &&is_int($at)&&$at>0&&$at<=$now+5
                &&$at>=$now-self::MAX_OUT_AGE;
            // A later current home MAC/SSID, even just before the 300s
            // display freshness boundary, supersedes a previous OUT.
            $lastHome=$p['homeMacAt']??null;
            $lastSSID=$p['homeSSIDAt']??null;
            $lastFirm=$p['workSSIDAt']??null;
            foreach([$lastHome,$lastSSID,$lastFirm] as $later){
                if(is_int($later)&&is_int($at)&&$later>=$at)
                    $verified=false;
            }
            if(!$verified){
                $reasons[]='PHONE_'.$person.'_FRESH_GEOFENCE_OUT_REQUIRED';
                continue;
            }
            $out['geofenceOutside'][$person]=true;
            $keys[$person]=$geo['sourceKey'].':'.$at;
        }
        if(($house['schema']??null)!==1
            ||($house['authority']??null)!=='ANONYMOUS_HOME_OBSERVATION_ONLY'
            ||($house['sourcesHealthy']??false)!==true
            ||($house['observedHomeGUS']??0)<15
            ||($house['baselineEstablished']??false)!==true
            ||($house['houseStatus']??null)!=='HOUSE_QUIET_NO_ABSENCE_PROOF'
            ||!is_int($house['quietForSeconds']??null)
            ||$house['quietForSeconds']<self::QUIET_SECONDS
            ||($house['lastHomeMotionAgeSeconds']??self::QUIET_SECONDS)
                <self::QUIET_SECONDS) {
            $reasons[]='HOUSE_15_GUS_30MIN_CONTINUOUS_QUIET_REQUIRED';
        }
        if(($indoor['schema']??null)!==1
            ||($indoor['authority']??null)!=='INDOOR_DEVICE_HINTS_ONLY'
            ||!is_array($indoor['sources']??null)
            ||!is_array($indoor['warnings']??null)
            ||$indoor['warnings']!==[]
            ||($indoor['sourceCount']??-1)!==($indoor['readableCount']??-2)
            ||($indoor['sourceCount']??0)!==count($indoor['sources'])) {
            $reasons[]='INDOOR_LIGHT_TV_COVERAGE_UNVERIFIED';
        }else{
            $changed=false;$on=false;$missingTime=false;
            foreach($indoor['sources'] as $id=>$source){
                if(!is_array($source)){$missingTime=true;continue;}
                $kind=$source['kind']??null;
                if($kind==='LIGHT_INTERIOR')$out['checkedLights']++;
                elseif($kind==='TV_POWER')$out['checkedTVs']++;
                else{$missingTime=true;continue;}
                if(($source['on']??null)!==false)$on=true;
                $last=$source['lastValueChangeAt']??null;
                if(!is_int($last)||$last<=0||$last>$now+5){
                    $missingTime=true;
                }elseif($last>$now-self::QUIET_SECONDS){
                    // Includes switching OFF: old light state alone is not
                    // proof there has been no light dynamics.
                    $changed=true;
                }
            }
            if($out['checkedLights']<1)$reasons[]='INDOOR_LIGHT_SOURCE_REQUIRED';
            if($out['checkedTVs']<1)$reasons[]='INDOOR_TV_SOURCE_REQUIRED';
            if($missingTime)$reasons[]='INDOOR_LAST_CHANGE_TIME_REQUIRED';
            if($on)$reasons[]='INDOOR_LIGHT_OR_TV_IS_ON';
            if($changed)$reasons[]='INDOOR_LIGHT_OR_TV_CHANGED_WITHIN_30MIN';
        }
        if($reasons!==[])return self::finish($out,$next,$reasons);

        $out['eligible']=true;
        // Never trust an older eligible window across a reboot, source
        // reassignment, invalid time or failed/interrupted poll.
        $sourceSig=hash('sha256',implode('|',[
            $keys['johannes'],$keys['carina'],
            (string)$out['checkedLights'],(string)$out['checkedTVs']
        ]));
        $since=$now;
        if(($previous['schema']??null)===1
            &&($previous['sources']??null)===$sourceSig
            &&is_int($previous['eligibleSince']??null)
            &&$previous['eligibleSince']>0
            &&$previous['eligibleSince']<=$now
            &&is_int($previous['lastEvaluatedAt']??null)
            &&$previous['lastEvaluatedAt']>0
            &&$previous['lastEvaluatedAt']<=$now
            &&$now-$previous['lastEvaluatedAt']<=150)
            $since=$previous['eligibleSince'];
        $out['observedStableSeconds']=$now-$since;
        $next=['schema'=>1,'eligibleSince'=>$since,
            'sources'=>$sourceSig,'lastEvaluatedAt'=>$now];
        if($out['observedStableSeconds']>=self::HOLD_SECONDS){
            $out['status']='LIKELY_AWAY';
            $out['display']='Abwesend';
            $out['likelyAway']=true;
            $reasons[]='TWO_VERIFIED_GEOFENCE_OUT_AND_QUIET_HOUSE_LIGHT_TV';
        }else{
            $out['status']='WAITING_STABLE_HOUSE_OBSERVATION';
            $reasons[]='WAIT_10MIN_STABLE_AFTER_30MIN_QUIET';
        }
        return self::finish($out,$next,$reasons);
    }

    private static function finish(array $out,array $next,array $reasons):array
    {
        $out['reasons']=$reasons;
        return ['report'=>$out,'next'=>$next];
    }
}
