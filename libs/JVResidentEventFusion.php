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
    private const MAX_ROUTE_PENDING=128;
    private const MAX_ROUTE_DELIVERY=32;
    private const MAX_PHONE_EVENTS=24;
    private const MAX_OBSERVATION_GAP=3600;
    private const MAX_PHONE_OBSERVATION_GAP=900; // 15m pause quarantines personal event chain

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
            // initializedAt is the start of the CURRENT source lineage,
            // not a 24h observation itself. Expiring it after one day
            // silently lost fresh geofence events just before a poll.
            $init=$prev['initializedAt']??null;
            $initialAt=is_int($init)&&$init>0
                &&$init<=$now+self::CLOCK_SKEW?$init:null;
            $boot=($prev['initialized']??false)!==true
                ||$observedAt===null
                ||$now-$observedAt>self::MAX_PHONE_OBSERVATION_GAP;
            if($initialAt===null||$boot)$initialAt=$now;
            $wp=$okWlan&&is_array($wlan['people'][$person]??null)
                ?$wlan['people'][$person]:[];
            $verifiedPair=($wp['validatedExistingVariablePair']??false)===true;
            $oldMacKey=self::trustedSourceKey($prev['lastMacSourceKey']??null);
            $macKey=$verifiedPair
                ?self::trustedSourceKey($wp['sourceKey']??null):null;
            $macKeyChanged=$oldMacKey!==null&&$macKey!==null
                &&$oldMacKey!==$macKey;
            // Missing source key in legacy tests is supported. In production
            // a newly introduced/rebound exact object pair is baselined.
            $macKeyStable=($oldMacKey===$macKey);
            $macRebaseline=$macKeyChanged||($macKey!==null
                &&$oldMacKey===null&&($prev['initialized']??false)===true);
            // Do not persist arbitrary timestamps from failed/unverified
            // WLAN source pairs. A forged MAC could unlock an OUT/IN chain.
            $mac=$verifiedPair
                ?self::ts($wp['lastMacContactAt']??null,$now,self::MAX_AGE)
                :null;
            $oldMac=$macRebaseline?null:
                self::ts($prev['lastMacAt']??null,$now,self::MAX_AGE);
            $macSourceContinuous=($prev['lastMacSourceVerified']??false)===true
                &&$verifiedPair&&!$boot&&$macKeyStable;
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
            $oldGeoKey=self::trustedSourceKey($prev['lastGeoSourceKey']??null);
            $geoKey=$geoValid
                ?self::trustedSourceKey($geo['sourceKey']??null):null;
            $geoKeyChanged=$oldGeoKey!==null&&$geoKey!==null
                &&$oldGeoKey!==$geoKey;
            $geoKeyStable=($oldGeoKey===$geoKey);
            $geoRebaseline=$geoKeyChanged||($geoKey!==null
                &&$oldGeoKey===null&&($prev['initialized']??false)===true);
            // A timer interruption, unqualified source, or changed
            // source binding invalidates the *continuity* of every old
            // phone OUT/IN chain. Keep the normal phone hints, but never
            // join pre-gap events with post-gap observations.
            // No additional persisted state or Symcon variables required.
            $resetPhoneChain=$boot||$macRebaseline||$geoRebaseline
                ||!$verifiedPair||!$geoValid
                ||($prev['lastMacSourceVerified']??false)!==true
                ||($prev['lastGeoSourceVerified']??false)!==true;
            $geoSourceContinuous=$geoValid
                &&($prev['lastGeoSourceVerified']??false)===true
                &&!$boot&&$geoKeyStable;
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
            $lastGeo=$geoRebaseline?null:
                self::ts($prev['lastGeoAt']??null,$now,self::GEO_MAX_AGE);
            $outAt=$geoSourceContinuous&&!$resetPhoneChain
                ?self::ts($prev['pendingOutAt']??null,$now,self::GEO_MAX_AGE)
                :null;
            $inAt=$geoSourceContinuous&&!$resetPhoneChain
                ?self::ts($prev['pendingInAt']??null,$now,self::GEO_MAX_AGE)
                :null;
            // A source change cannot inherit the other source's pending
            // personal sequence. No cross-binding OUT->IN->MAC proof.
            if($geoRebaseline){
                $oldMac=null;$newMac=false;$gap=null;
            }
            $lastRebind=self::ts($prev['lastSourceRebindAt']??null,$now,self::MAX_AGE);
            if($macKeyChanged||$geoKeyChanged)$lastRebind=$now;
            $returnAt=$resetPhoneChain?null:
                self::ts($prev['lastPhoneReturnAt']??null,$now,self::MAX_AGE);
            $event=$resetPhoneChain?null:
                self::safeEvent($prev['lastEvent']??null,$now);
            $history=[];
            foreach(array_slice($resetPhoneChain?[]:(is_array($prev['eventHistory']??null)
                ?$prev['eventHistory']:[]),0,self::MAX_PHONE_EVENTS*2) as $row){
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
            if(!$resetPhoneChain&&$geoSourceContinuous
                &&$geoAt!==null &&$geoAt>=$initialAt
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
            if(!$resetPhoneChain &&$newMac &&$gap>660 &&$newEvent===null)
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
                'lastMacSourceKey'=>$macKey??$oldMacKey,
                'lastGeoSourceKey'=>$geoKey??$oldGeoKey,
                'lastSourceRebindAt'=>$lastRebind,
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
                'macSourceRebound'=>$macKeyChanged,
                'geoSourceRebound'=>$geoKeyChanged,
                'sourceRebaselineRequired'=>$resetPhoneChain,
                'lastSourceRebindAt'=>$lastRebind,
                'sourceRebindRecent'=>$lastRebind!==null
                    &&$now-$lastRebind<=86400,
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
        // Only proved append-only or anchored rotation can continue a
        // two-mast route. UNKNOWN/missing source and malformed batches
        // interrupt the anonymous corridor just as a proven reset does.
        $logWarnings=$p03Valid&&is_array($p03['warnings']??null)
            ?$p03['warnings']:[];
        $logInterrupted=!in_array($logContinuity,
            ['OK','TAIL_OVERLAP_AFTER_ROTATION'],true)
            ||count(array_intersect($logWarnings,[
                'P03_LOG_BATCH_INVALID','INVALID_CLOCK_OR_LOG_SIZE',
                'P03_SOURCE_NOT_READABLE','P03_LOG_GAP_OR_RESET',
                'P03_NEW_PROOF_BATCH_OVERFLOW','P03_PROOF_SYNTAX_UNTRUSTED'
            ]))>0
            ||($p03['newProofBatchOverflow']??false)===true;
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
        // P03 log resets and long observer gaps start a NEW anonymous
        // evidence epoch. The parser already cleared its old history;
        // its downstream fusion must not retain old high-water or proof.
        if($logInterrupted||!$p03Continuous){
            $clean=[];$latest=null;
            $highWater=null;$oldHighWater=null;
        }
        // Persisted delivery journal protects the gap between P03 cursor
        // advance and successful route processing. The journal is replayed
        // after a crash and removed only after the route ledger is durable.
        $pending=[];
        $queueExpired=false;
        if($p03Continuous&&$p03Valid&&!$logInterrupted){
            foreach(array_slice(is_array($oldP03['unackedRouteProofs']??null)
                ?$oldP03['unackedRouteProofs']:[],0,self::MAX_ROUTE_PENDING) as $oldProof){
                $valid=self::safeProof($oldProof,$now);
                if($valid===null||$now-$valid['at']>600){
                    $queueExpired=true;continue;
                }
                $pending[self::proofKey($valid)]=$valid;
            }
        }
        $newProofs=[];
        if($p03Valid&&!$logInterrupted){
            $proofs=is_array($p03['newlyVerifiedDirectionProofs']??null)
                ?$p03['newlyVerifiedDirectionProofs']
                :($p03['recentDirectionProofs']??[]);
            if(is_array($proofs)){
                foreach(array_slice($proofs,0,self::MAX_ROUTE_PENDING) as $proof){
                    $checked=self::safeProof($proof,$now);
                    if($checked===null)continue;
                    $key=$checked['at'].'|'.$checked['direction'].'|'.$checked['route'];
                    $genuinelyNew=!isset($clean[$key])&&$validPrior
                        &&$p03Continuous
                        &&($oldHighWater===null||$checked['at']>$oldHighWater);
                    if($genuinelyNew)$newProofs[]=$checked;
                    if($highWater===null||$checked['at']>$highWater)
                        $highWater=$checked['at'];
                    $clean[$key]=$checked['at'];
                    // A first-poll baseline suppresses later duplicate
                    // replay but may never be shown as current passage.
                    if($genuinelyNew
                        &&($latest===null||$checked['at']>$latest['at']))
                        $latest=$checked;
                }
            }
        }
        foreach($newProofs as $proof){
            if($now-$proof['at']<=600)
                $pending[self::proofKey($proof)]=$proof;
        }
        uasort($pending,static fn(array $a,array $b)=>$a['at']<=>$b['at']);
        $queueOverflow=count($pending)>self::MAX_ROUTE_PENDING
            ||(is_array($oldP03['unackedRouteProofs']??null)
                &&count($oldP03['unackedRouteProofs'])>self::MAX_ROUTE_PENDING);
        if($queueOverflow||$queueExpired){
            // Neither overflow nor expired delivery may be presented as
            // continuous route evidence: discard the incomplete corridor.
            $logInterrupted=true;$pending=[];$newProofs=[];
        }
        $allPending=array_slice(array_values($pending),0,self::MAX_ROUTE_PENDING);
        $delivery=array_slice($allPending,0,self::MAX_ROUTE_DELIVERY);
        arsort($clean,SORT_NUMERIC);
        $clean=array_slice($clean,0,self::MAX_P03_KEYS,true);
        usort($newProofs,static fn(array $a,array $b)=>$a['at']<=>$b['at']);
        $next['p03']=['initialized'=>true,'lastReadAt'=>$now,
            'seen'=>$clean,
            'lastAnonymous'=>$latest,'highWaterAt'=>$highWater,
            'unackedRouteProofs'=>$allPending];
        $out['p03']=[
            'newAnonymousProofs'=>$newProofs,
            'routeDeliveryProofs'=>$delivery,
            'unackedRouteProofCount'=>count($allPending),
            'routeJournalOverflow'=>$queueOverflow,
            'routeJournalExpired'=>$queueExpired,
            'lastAnonymousProof'=>$latest,
            'identityConfirmed'=>false,'phoneAssociatedWithCrossing'=>false,
            'homeLagerIsNotPropertyExit'=>true,
            'oldProofReplayed'=>false,
            'logContinuity'=>$logContinuity,
            'logInterrupted'=>$logInterrupted,
            'proofSourceValidated'=>$p03Valid
        ];
        return ['report'=>$out,'next'=>$next];
    }

    /**
     * Acknowledge only proofs already durably processed in RouteEpisodeLedger.
     * A second acknowledgement after a restart is idempotent.
     */
    public static function acknowledgeRouteDelivery(
        array $state,array $delivered,int $now
    ):array {
        if(($state['schema']??null)!==1
            ||!is_array($state['p03']??null))return $state;
        $keys=[];
        foreach(array_slice($delivered,0,self::MAX_ROUTE_DELIVERY) as $proof){
            $valid=self::safeProof($proof,$now);
            if($valid!==null)$keys[self::proofKey($valid)]=true;
        }
        $rest=[];
        foreach(($state['p03']['unackedRouteProofs']??[]) as $proof){
            $valid=self::safeProof($proof,$now);
            if($valid===null)continue;
            if(!isset($keys[self::proofKey($valid)]))$rest[]=$valid;
        }
        $state['p03']['unackedRouteProofs']=array_slice(
            $rest,0,self::MAX_ROUTE_PENDING);
        return $state;
    }

    private static function proofKey(array $proof):string
    {
        return $proof['at'].'|'.$proof['direction'].'|'.$proof['route'];
    }

    private static function trustedSourceKey(mixed $key):?string
    {
        if(!is_string($key)||strlen($key)>96)return null;
        return preg_match('/^(?:WLAN:[1-9][0-9]*:[1-9][0-9]*'
            .'|EGI:(?:[1-9][0-9]*:){3}[1-9][0-9]*'
            .'|GEOFENCY:(?:[1-9][0-9]*:){3}[1-9][0-9]*)$/D',$key)
            ?$key:null;
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
