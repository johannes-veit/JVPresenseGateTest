<?php
declare(strict_types=1);

/**
 * Cross-source integrity audit of already computed SHADOW evidence.
 * Detects contradictory, stale or malformed reports. No new hardware,
 * server requests or user variables; cannot confer human identity.
 */
final class JVShadowIntegrityAudit
{
    private const MAX_EVENT_AGE=86400;
    private const MAX_HANDSET_EVENTS=24;

    public static function evaluate(
        array $fusion,array $routes,array $journey,array $readiness,int $now,
        array $crossSource=[]
    ):array {
        $issues=[];$checks=0;
        $okFusion=($fusion['schema']??null)===1
            &&($fusion['authority']??null)
                ==='PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY';
        $okRoutes=($routes['schema']??null)===1
            &&($routes['authority']??null)==='ANONYMOUS_ROUTE_EPISODES_READ_ONLY';
        $okJourney=($journey['schema']??null)===1
            &&($journey['authority']??null)
                ==='READ_ONLY_RESIDENT_ROUTE_PHONE_ASSOCIATIONS';
        $okReadiness=($readiness['schema']??null)===1
            &&($readiness['authority']??null)
                ==='READ_ONLY_PRESENCE_EVIDENCE_READINESS';
        // Only on builds that explicitly provide the new cross-source
        // audit. Legacy offline tests with the previous four-source contract
        // keep their existing behavior. This propagates true contradictions
        // to commissioning and safety-case review, without turning a
        // deliberately absent source into a fabricated corruption.
        if($crossSource!==[]){
            if(($crossSource['schema']??null)!==1
                ||($crossSource['authority']??null)
                    !=='SHADOW_CROSS_SOURCE_INVARIANT_AUDIT_NO_ACTION'){
                $issues[]='CROSS_SOURCE_AUDIT_REPORT_UNVERIFIED';
            }elseif(($crossSource['status']??null)
                    ==='CROSS_SOURCE_CONTRADICTION_NO_ACTION'
                &&($crossSource['inputDataRejected']??false)===true){
                $issues[]='CROSS_SOURCE_INTEGRITY_CONTRADICTION';
            }elseif(!in_array($crossSource['status']??null,[
                'CROSS_SOURCE_CONSISTENT_ANONYMOUS_ONLY',
                'SOURCE_INCOMPLETE_NO_ACTION'
            ],true)){
                $issues[]='CROSS_SOURCE_AUDIT_STATUS_UNTRUSTED';
            }
            $checks++;
        }
        if(!$okFusion)$issues[]='FUSION_SOURCE_UNVERIFIED';
        if(!$okRoutes)$issues[]='ROUTE_SOURCE_UNVERIFIED';
        if(!$okJourney)$issues[]='JOURNEY_SOURCE_UNVERIFIED';
        if(!$okReadiness)$issues[]='READINESS_SOURCE_UNVERIFIED';
        if($now<=0)$issues[]='INVALID_WALL_CLOCK';
        $checks++;

        if($okFusion&&$now>0){
            foreach(['johannes','carina'] as $person){
                $row=$fusion['people'][$person]??null;
                if(!is_array($row)){
                    $issues[]=strtoupper($person).'_EVENT_LEDGER_MISSING';
                    continue;
                }
                $events=$row['eventHistory']??null;
                if($events===null){
                    // Read-only upgrade from previous, single-event ledger.
                    // It is not a data corruption or a reason for alarm.
                    continue;
                }
                if(!is_array($events)||!array_is_list($events)
                    ||count($events)>self::MAX_HANDSET_EVENTS){
                    $issues[]=strtoupper($person).'_EVENT_LEDGER_SHAPE_INVALID';
                    continue;
                }
                $lastTime=PHP_INT_MAX;$seen=[];
                foreach($events as $event){
                    $checks++;
                    $code=is_array($event)?($event['code']??null):null;
                    $time=is_array($event)?($event['at']??null):null;
                    if(!in_array($code,[
                        'PHONE_GEOFENCE_OUT_OBSERVED','PHONE_GEOFENCE_IN_OBSERVED',
                        'PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC',
                        'HOME_PHONE_REAPPEARED_AFTER_CONTACT_GAP'
                    ],true)||!is_int($time)||$time<=0
                        ||$time>$now+5||$time<$now-self::MAX_EVENT_AGE){
                        $issues[]=strtoupper($person).'_EVENT_UNTRUSTED';
                        break;
                    }
                    if($time>$lastTime){
                        $issues[]=strtoupper($person).'_EVENT_ORDER_INVALID';
                        break;
                    }
                    $key=$time.'|'.$code;
                    if(isset($seen[$key])){
                        $issues[]=strtoupper($person).'_EVENT_DUPLICATE';
                        break;
                    }
                    $seen[$key]=true;$lastTime=$time;
                }
            }
            $p=$fusion['p03']??null;
            if(!is_array($p)){
                $issues[]='P03_FUSION_LEDGER_MISSING';
            }else{
                if(($p['logInterrupted']??false)===true
                    &&count(is_array($p['newAnonymousProofs']??null)
                        ?$p['newAnonymousProofs']:[])>0)
                    $issues[]='P03_PROOF_ACROSS_LOG_GAP';
                $delivery=$p['routeDeliveryProofs']??[];
                $queueCount=$p['unackedRouteProofCount']??0;
                if(!is_array($delivery)||count($delivery)>32
                    ||!is_int($queueCount)||$queueCount<0||$queueCount>128)
                    $issues[]='P03_ROUTE_DELIVERY_BOUNDS_INVALID';
                if(($p['logInterrupted']??false)===true
                    &&is_array($delivery)&&count($delivery)>0)
                    $issues[]='P03_DELIVERY_DURING_SOURCE_GAP';
                if(($p['routeJournalOverflow']??false)===true)
                    $issues[]='P03_ROUTE_JOURNAL_OVERFLOW';
                if(($p['routeJournalExpired']??false)===true)
                    $issues[]='P03_ROUTE_JOURNAL_EXPIRED';
                $checks++;
            }
        }
        if($okRoutes){
            $uncertain=$routes['ambiguousOutstanding']??0;
            $open=$routes['openOutgoingPassages']??0;
            if(!is_int($uncertain)||$uncertain<0||$uncertain>8
                ||!is_int($open)||$open<0||$open>8
                ||($uncertain>0&&$open>0))
                $issues[]='ANONYMOUS_MULTIPERSON_QUARANTINE_STATE_INVALID';
            $checks++;
            $r=$routes['lastEpisode']??null;
            if(is_array($r)&&($r['kind']??null)
                ==='ANONYMOUS_OUT_IN_CORRIDOR_SEQUENCE'){
                $a=$r['outAt']??null;$b=$r['inAt']??null;
                if(!is_int($a)||!is_int($b)||$a<=0||$a>=$b
                    ||($now>0&&$b>$now+5)
                    ||$b-$a>43200)
                    $issues[]='ANONYMOUS_ROUTE_TIME_INVALID';
                $checks++;
            }
            if(($routes['vehicleJourneyCertified']??false)===true
                ||($routes['personIdentityCertified']??false)===true
                ||($routes['houseEmptyCertified']??false)===true)
                $issues[]='UNAUTHORIZED_CAMERA_PERSON_OR_VEHICLE_CLAIM';
        }
        if($okJourney){
            $c=$journey['phoneCandidates']??[];
            $actual=is_array($c)?count($c):0;
            if(!is_array($c)||$actual>2
                ||($journey['candidateCount']??null)!==$actual)
                $issues[]='PERSONAL_JOURNEY_CANDIDATES_INCONSISTENT';
            if($actual!==1&&($journey['candidatePerson']??null)!==null)
                $issues[]='UNSUPPORTED_PERSONAL_JOURNEY_ATTRIBUTION';
            if(($journey['identityAssigned']??false)===true
                ||($journey['vehicleJourneyCertified']??false)===true
                ||($journey['propertyEmptyCertified']??false)===true)
                $issues[]='UNAUTHORIZED_PHYSICAL_IDENTITY_CLAIM';
            $checks++;
        }
        foreach([$fusion,$routes,$journey,$readiness] as $source){
            if(($source['mayArmAlarm']??false)===true
                ||($source['maySwitchDevices']??false)===true
                ||($source['propertyEmptyCertified']??false)===true
                ||($source['houseEmptyCertified']??false)===true){
                $issues[]='READ_ONLY_SAFETY_CONTRACT_VIOLATED';
                break;
            }
        }
        $issues=array_values(array_unique($issues));
        return [
            'schema'=>1,'authority'=>'SHADOW_INTEGRITY_AUDIT_READ_ONLY',
            'status'=>$issues===[]?'CONSISTENT':'REVIEW_REQUIRED',
            'verifiedChecks'=>$checks,'issues'=>$issues,
            'p03LogContinuity'=>$okFusion
                ?($fusion['p03']['logContinuity']??'UNVERIFIED'):'UNVERIFIED',
            'p03LogInterrupted'=>$okFusion
                &&($fusion['p03']['logInterrupted']??false)===true,
            'humanIdentityCertified'=>false,'houseEmptyCertified'=>false,
            'vehicleTripCertified'=>false,
            'mayArmAlarm'=>false,'maySwitchDevices'=>false
        ];
    }
}
