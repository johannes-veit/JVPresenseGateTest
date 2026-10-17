<?php
declare(strict_types=1);

/**
 * Read-only, incremental correlation of verified handset signals and anonymous
 * P03 crossings. An observed handset is NOT a person or property occupancy.
 * No replay of old evidence at installation, restart, or timer refresh.
 */
final class JVResidentEventFusion
{
    private const MAX_AGE=86400;
    private const PHONE_FRESH=300;
    private const GEO_MAX_AGE=21600;
    private const GEO_TO_WIFI=1800;
    private const HOME_TO_OUT=1800;
    private const CLOCK_SKEW=5;
    private const MAX_P03_KEYS=48;
    private const MAX_PHONE_EVENTS=24;
    private const MAX_OBSERVATION_GAP=3600;

    public static function evaluate(
        array $wlan, array $jGeo, array $cGeo, array $work,
        array $p03, array $prior, int $now
    ): array {
        $out=[
            'schema'=>1, 'authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
            'people'=>[], 'p03'=>[], 'identityFromCamera'=>false,
            'vehicleAttributionCertified'=>false, 'allAwayCertified'=>false,
            'propertyEmptyCertified'=>false, 'mayChangeExternalPresence'=>false,
            'mayArmAlarm'=>false, 'maySwitchDevices'=>false
        ];
        if($now<=0)return ['report'=>$out,'next'=>['schema'=>1,'people'=>[],'p03'=>[]]];
        $validPrior=($prior['schema']??null)===1;
        $next=['schema'=>1,'people'=>[],'p03'=>[]];
        $okWlan=($wlan['schema']??null)===1
            &&($wlan['authority']??null)==='EXISTING_WLAN_MAC_HINTS_READ_ONLY'
            &&($wlan['enabled']??false)===true;
        $okWork=($work['schema']??null)===1
            &&($work['authority']??null)==='PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT'
            &&($work['enabled']??false)===true;
        foreach(['johannes','carina'] as $person){
            $prev=$validPrior&&is_array($prior['people'][$person]??null)
                ?$prior['people'][$person]:[];
            $observedAt=self::ts($prev['lastObservedAt']??null,$now,self::MAX_AGE);
            $initialAt=self::ts($prev['initializedAt']??null,$now,self::MAX_AGE);
            $boot=($prev['initialized']??false)!==true
                ||$observedAt===null
                ||$now-$observedAt>self::MAX_OBSERVATION_GAP;
            if($initialAt===null)$initialAt=$now;
            $wp=$okWlan&&is_array($wlan['people'][$person]??null)
                ?$wlan['people'][$person]:[];
            $verifiedPair=($wp['validatedExistingVariablePair']??false)===true;
            // Do not persist arbitrary timestamps from failed/unverified
            // WLAN source pairs. A forged MAC could unlock an OUT/IN chain.
            $mac=$verifiedPair
                ?self::ts($wp['lastMacContactAt']??null,$now,self::MAX_AGE)
                :null;
            $oldMac=self::ts($prev['lastMacAt']??null,$now,self::MAX_AGE);
            $macSourceContinuous=($prev['lastMacSourceVerified']??false)===true
                &&$verifiedPair&&!$boot;
            $fresh=$verifiedPair
                &&($wp['wlanBoolean']??null)===true
                &&($wp['recentMacEvidence']??false)===true
                &&($wp['state']??null)==='MAC_CONFIRMED_RECENT_DEVICE_HINT'
                &&$mac!==null&&$mac>=$now-self::PHONE_FRESH;
            $newMac=$fresh&&$macSourceContinuous
                &&$oldMac!==null&&$mac>$oldMac;
            $gap=$newMac?$mac-$oldMac:null;
            $lastMac=$mac!==null&&($oldMac===null||$mac>$oldMac)?$mac:$oldMac;
            $geo=$person==='johannes'?$jGeo:$cGeo;
            $expected=$person==='johannes'
                ?'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY'
                :'EXISTING_CARINA_GEOFENCY_READ_ONLY';
            $geoValid=($geo['schema']??null)===1
                &&($geo['authority']??null)===$expected
                &&($geo['enabled']??false)===true
                &&($geo['validatedSource']??false)===true;
            $geoSourceContinuous=$geoValid
                &&($prev['lastGeoSourceVerified']??false)===true
                &&!$boot;
            $geoAt=$geoValid?self::ts($geo['lastEventAt']??null,$now,self::GEO_MAX_AGE):null;
            $geoKind=null;
            if($geoAt!==null){
                if(($geo['insideRecent']??false)===true
                    &&($geo['outsideRecent']??false)===false
                    &&($geo['geofenceReportedInside']??null)===true)$geoKind='IN';
                if(($geo['outsideRecent']??false)===true
                    &&($geo['insideRecent']??false)===false
                    &&($geo['geofenceReportedInside']??null)===false)$geoKind='OUT';
            }
            $lastGeo=self::ts($prev['lastGeoAt']??null,$now,self::GEO_MAX_AGE);
            $outAt=$geoSourceContinuous
                ?self::ts($prev['pendingOutAt']??null,$now,self::GEO_MAX_AGE)
                :null;
            $inAt=$geoSourceContinuous
                ?self::ts($prev['pendingInAt']??null,$now,self::GEO_MAX_AGE)
                :null;
            $returnAt=self::ts($prev['lastPhoneReturnAt']??null,$now,self::MAX_AGE);
            $event=self::safeEvent($prev['lastEvent']??null,$now);
            $history=[];
            foreach(array_slice(is_array($prev['eventHistory']??null)
                ?$prev['eventHistory']:[],0,self::MAX_PHONE_EVENTS*2) as $row){
                $valid=self::safeEvent($row,$now);
                if($valid!==null)$history[$valid['at'].'|'.$valid['code']]=$valid;
            }
            // Seamless migration of the legacy one-event state; no replay.
            if($event!==null)
                $history[$event['at'].'|'.$event['code']]=$event;
            $newEvent=null;
            $ssid=$okWork&&is_array($work['people'][$person]??null)
                ?$work['people'][$person]:[];
            $workFresh=($ssid['sourceVerified']??false)===true
                &&is_int($ssid['ageSeconds']??null)
                &&$ssid['ageSeconds']>=0&&$ssid['ageSeconds']<=self::PHONE_FRESH
                &&($ssid['workRecent']??false)===true
                &&($ssid['status']??null)==='WORK_FIRMEN_WLAN_PHONE';
            // First sample is a baseline only. Old P03 and geofence events
            // must never be turned into new personal journeys on an update.
            if($geoSourceContinuous && $geoAt!==null &&$geoAt>=$initialAt
                && $geoKind!==null
                &&($lastGeo===null||$geoAt>$lastGeo)){
                if($geoKind==='OUT'){
                    $outAt=$oldMac!==null &&$oldMac<$geoAt
                        &&$geoAt-$oldMac<=self::HOME_TO_OUT?$geoAt:null;
                    $inAt=null;
                    $newEvent=['code'=>'PHONE_GEOFENCE_OUT_OBSERVED',
                        'at'=>$geoAt,'certainty'=>'PHONE_AREA_ONLY'];
                }else{
                    $inAt=$outAt!==null&&$outAt<$geoAt?$geoAt:null;
                    $newEvent=['code'=>'PHONE_GEOFENCE_IN_OBSERVED',
                        'at'=>$geoAt,'certainty'=>'PHONE_AREA_ONLY'];
                }
            }
            if($geoAt!==null&&($lastGeo===null||$geoAt>$lastGeo))
                $lastGeo=$geoAt;
            $returnNew=false;
            if(!$boot &&$fresh&&$newMac&&$outAt!==null&&$inAt!==null
                &&$outAt<$inAt&&$mac>=$inAt
                &&$mac-$inAt<=self::GEO_TO_WIFI
                &&($returnAt===null||$returnAt<$inAt)){
                $returnAt=$mac;
                $returnNew=true;
                $outAt=null;$inAt=null;
                $newEvent=['code'=>'PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC',
                    'at'=>$mac,'certainty'=>'DEVICE_CORRELATION_NOT_PERSON'];
            }
            // A long gap can reflect iPhone standby or an inaccessible WLAN;
            // it may NEVER on its own create a departure or arrival.
            if(!$boot &&$newMac &&$gap>660 &&$newEvent===null)
                $newEvent=['code'=>'HOME_PHONE_REAPPEARED_AFTER_CONTACT_GAP',
                    'at'=>$mac,'certainty'=>'NETWORK_CONTACT_ONLY'];
            if($newEvent!==null){
                $valid=self::safeEvent($newEvent,$now);
                if($valid!==null)
                    $history[$valid['at'].'|'.$valid['code']]=$valid;
                if($event===null||$newEvent['at']>=$event['at'])
                    $event=$newEvent;
            }
            uasort($history,static fn(array $a,array $b)=>
                ($b['at']<=>$a['at'])?:strcmp($a['code'],$b['code']));
            $history=array_slice(array_values($history),0,self::MAX_PHONE_EVENTS);
            $phoneState=$workFresh?'PHONE_WORK_SSID_RECENT':($fresh
                ?'PHONE_HOME_MAC_RECENT':($verifiedPair&&($wp['wlanBoolean']??null)===true
                    ?'PHONE_HOME_MAC_STALE_NOT_AWAY':'PHONE_LOCATION_UNCONFIRMED'));
            if($workFresh&&$fresh){
                $workAt=$now-$ssid['ageSeconds'];
                $diff=$workAt-$mac;
                $phoneState=abs($diff)<=45?'HOME_WORK_PHONE_SOURCE_CONFLICT'
                    :($diff>0?'PHONE_WORK_SSID_RECENT':'PHONE_HOME_MAC_RECENT');
            }
            $next['people'][$person]=[
                'initialized'=>true,
                'initializedAt'=>$initialAt,'lastObservedAt'=>$now,
                'lastMacAt'=>$lastMac,
                'lastMacSourceVerified'=>$verifiedPair,
                'lastGeoSourceVerified'=>$geoValid,
                'lastGeoAt'=>$lastGeo,'pendingOutAt'=>$outAt,
                'pendingInAt'=>$inAt,'lastPhoneReturnAt'=>$returnAt,
                'lastEvent'=>$event,
                'eventHistory'=>$history
            ];
            $out['people'][$person]=[
                'state'=>$phoneState,'lastMacAt'=>$lastMac,
                'lastMacAgeSeconds'=>$lastMac!==null?$now-$lastMac:null,
                'lastGeoAt'=>$lastGeo,'lastGeoKind'=>$geoKind,
                'geoSourceContinuous'=>$geoSourceContinuous,
                'macSourceContinuous'=>$macSourceContinuous,
                'lastEvent'=>$event,'eventHistory'=>$history,
                'eventHistoryCount'=>count($history),
                'lastEventIsHistorical'=>$newEvent===null,
                'newPersonalPhoneEvent'=>$newEvent,
                'newPhoneReturn'=>$returnNew,
                'wlanContactGapSeconds'=>$gap,
                'workSsidSourceVerified'=>$workFresh,
                'pendingGeofenceOut'=>$outAt,
                'pendingGeofenceIn'=>$inAt,
                'handsetNotHuman'=>true,'humanAbsentCertified'=>false,
                'humanAtHomeCertified'=>false,'vehicleCertified'=>false
            ];
        }
        $p03Valid=($p03['schema']??null)===1
            &&($p03['authority']??null)==='P03_LIVE_PROOFS_DIRECTION_ONLY';
        $logContinuity=$p03Valid&&is_string($p03['logContinuity']??null)
            ?$p03['logContinuity']:'UNVERIFIED';
        $logInterrupted=in_array($logContinuity,
            ['LOG_GAP_OR_RESET','BASELINE_ONLY'],true);
        $oldP03=$validPrior&&is_array($prior['p03']??null)?$prior['p03']:[];
        $seen=is_array($oldP03['seen']??null)?$oldP03['seen']:[];
        $clean=[];
        foreach($seen as $key=>$at)
            if(is_string($key)&&strlen($key)<=96
                &&self::ts($at,$now,self::MAX_AGE)!==null)$clean[$key]=$at;
        $latest=self::safeProof($oldP03['lastAnonymous']??null,$now);
        $highWater=self::ts($oldP03['highWaterAt']??null,$now,self::MAX_AGE);
        $oldHighWater=$highWater;
        $lastP03Read=self::ts($oldP03['lastReadAt']??null,$now,self::MAX_AGE);
        $p03Continuous=($oldP03['initialized']??false)===true
            &&$lastP03Read!==null
            &&$now-$lastP03Read<=self::MAX_OBSERVATION_GAP;
        $newProofs=[];
        if($p03Valid&&!$logInterrupted){
            $proofs=$p03['recentDirectionProofs']??[];
            if(is_array($proofs)){
                foreach(array_slice($proofs,0,64) as $proof){
                    $checked=self::safeProof($proof,$now);
                    if($checked===null)continue;
                    $key=$checked['at'].'|'.$checked['direction'].'|'.$checked['route'];
                    if(!isset($clean[$key]) &&$validPrior
                        &&$p03Continuous
                        &&($oldHighWater===null||$checked['at']>$oldHighWater))
                        $newProofs[]=$checked;
                    if($highWater===null||$checked['at']>$highWater)
                        $highWater=$checked['at'];
                    $clean[$key]=$checked['at'];
                    if($latest===null||$checked['at']>$latest['at'])$latest=$checked;
                }
            }
        }
        arsort($clean,SORT_NUMERIC);
        $clean=array_slice($clean,0,self::MAX_P03_KEYS,true);
        usort($newProofs,static fn(array $a,array $b)=>$a['at']<=>$b['at']);
        $next['p03']=['initialized'=>true,'lastReadAt'=>$now,
            'seen'=>$clean,
            'lastAnonymous'=>$latest,'highWaterAt'=>$highWater];
        $out['p03']=[
            'newAnonymousProofs'=>$newProofs,'lastAnonymousProof'=>$latest,
            'identityConfirmed'=>false,'phoneAssociatedWithCrossing'=>false,
            'homeLagerIsNotPropertyExit'=>true,
            'oldProofReplayed'=>false,
            'logContinuity'=>$logContinuity,
            'logInterrupted'=>$logInterrupted,
            'proofSourceValidated'=>$p03Valid
        ];
        return ['report'=>$out,'next'=>$next];
    }

    private static function ts(mixed $at,int $now,int $ttl):?int
    {
        return is_int($at)&&$at>0&&$at<=$now+self::CLOCK_SKEW
            &&$at>=$now-$ttl?$at:null;
    }
    private static function safeEvent(mixed $event,int $now):?array
    {
        if(!is_array($event)||!in_array($event['code']??null,[
            'PHONE_GEOFENCE_OUT_OBSERVED','PHONE_GEOFENCE_IN_OBSERVED',
            'PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC',
            'HOME_PHONE_REAPPEARED_AFTER_CONTACT_GAP'
        ],true)||self::ts($event['at']??null,$now,self::MAX_AGE)===null)return null;
        return ['code'=>$event['code'],'at'=>$event['at'],
            'certainty'=>'PHONE_OR_NETWORK_HINT_ONLY'];
    }
    private static function safeProof(mixed $proof,int $now):?array
    {
        if(!is_array($proof)
            ||($proof['source']??null)!=='P03_TWO_MAST_VERIFIED'
            ||!in_array(($proof['direction']??null),['HOME_TO_LAGER','LAGER_TO_HOME'],true)
            ||self::ts($proof['at']??null,$now,self::MAX_AGE)===null)return null;
        $d=$proof['direction'];
        $route=$d==='HOME_TO_LAGER'?'P03_2CAM_JV_THEN_WORK':'P03_2CAM_WORK_THEN_JV';
        if(($proof['route']??$route)!==$route)return null;
        return ['source'=>'P03_TWO_MAST_VERIFIED','at'=>$proof['at'],
            'direction'=>$d,'route'=>$route];
    }
}
