<?php
declare(strict_types=1);

/**
 * End-to-end read-only consistency gate across independently computed
 * phone cycle, P03 anonymous corridor and existing GUS motion diagnoses.
 *
 * An individually valid source can be inconsistent with another source.
 * This gate never grants human identity, property emptiness, headcount,
 * personal transportation, alarms, or hardware actions.
 *
 * The output is a diagnostic, not permission for downstream automation.
 */
final class JVShadowCrossSourceIntegrityGate
{
    public const AUTHORITY='SHADOW_CROSS_SOURCE_INVARIANT_AUDIT_NO_ACTION';
    private const MAX_PAIRS=8;
    private const MAX_AGE=86400;

    public static function evaluate(
        array $cycle,array $timeline,array $p03,array $room,int $now
    ):array {
        $out=[
            'schema'=>1,'authority'=>self::AUTHORITY,
            'status'=>'SOURCES_UNVERIFIED',
            'testedInvariants'=>0,'violations'=>[],
            'priorityChecks'=>[],
            'verifiedPhonePairCount'=>0,
            'uniqueP03Matches'=>0,
            'latestP03LinkConsistent'=>false,
            'anonymousRoomOverlayConsistent'=>false,
            'phoneIntervalsStrictlyPositive'=>false,
            'p03SourceReady'=>false,
            'actualPersonIdentityAvailable'=>false,
            'homeEmptyCertifiable'=>false,
            'twoPeopleDepartureCertifiable'=>false,
            'mayChangePresence'=>false,'mayControlAlarm'=>false,
            'mayControlDevices'=>false,
            'inputDataRejected'=>false
        ];
        $issues=[];$checks=0;
        $cycleOk=($cycle['schema']??null)===1
            &&($cycle['authority']??null)
                ==='READ_ONLY_PHONE_AREA_CYCLES_ONE_TO_ONE_NO_PEOPLE';
        $timelineOk=($timeline['schema']??null)===1
            &&($timeline['authority']??null)
                ==='READ_ONLY_SHARED_HANDSET_EXIT_SEQUENCE_NO_IDENTITY';
        $p03Ok=($p03['schema']??null)===1
            &&($p03['authority']??null)
                ==='P03_LIVE_PROOFS_DIRECTION_ONLY';
        $roomOk=($room['schema']??null)===1
            &&($room['authority']??null)
                ==='READ_ONLY_PHONE_AREA_VS_ANONYMOUS_GUS_ACTIVITY';
        if($now<=0)$issues[]='INVALID_CLOCK';
        if(!$cycleOk)$issues[]='PHONE_CYCLES_UNVERIFIED';
        if(!$timelineOk)$issues[]='PHONE_P03_TIMELINE_UNVERIFIED';
        if(!$p03Ok)$issues[]='P03_TIMELINE_UNVERIFIED';
        if(!$roomOk)$issues[]='ROOM_OVERLAY_UNVERIFIED';
        if($issues!==[]){
            $out['violations']=$issues;
            $out['inputDataRejected']=true;
            return $out;
        }

        if(($cycle['qualifiedSources']??null)!==2
            ||($room['status']??null)==='SOURCES_UNVERIFIED'){
            // Missing or deliberately disabled source data is not a
            // logical contradiction. Preserve an actionable source gap
            // without raising a fabricated integrity violation.
            $out['status']='SOURCE_INCOMPLETE_NO_ACTION';
            $out['priorityChecks']=[
                ($cycle['qualifiedSources']??null)!==2
                    ?'ONE_OR_BOTH_PHONE_AREA_SOURCES_UNQUALIFIED'
                    :'ANONYMOUS_GUS_LEDGER_UNVERIFIED'
            ];
            return $out;
        }

        // The same phone pair is reconstructed on a separate pure path.
        // A source label alone is not evidence that its returned values
        // agree with those of all other modules.
        $pairs=$cycle['recentPairs']??null;
        $count=$cycle['pairedCycleCount']??null;
        $latest=$cycle['latestPair']??null;
        $valid=is_array($pairs)&&array_is_list($pairs)
            &&count($pairs)<=self::MAX_PAIRS
            &&is_int($count)&&$count>=0&&$count<=24
            &&count($pairs)===min($count,self::MAX_PAIRS)
            &&($cycle['qualifiedSources']??null)===2;
        $checks++;
        if(!$valid)$issues[]='PHONE_PAIR_INDEX_OR_CAPACITY_INCONSISTENT';
        if($valid){
            $out['verifiedPhonePairCount']=$count;
            $seen=[];$previousStart=PHP_INT_MAX;
            foreach($pairs as $pair){
                $checks++;
                $j=$pair['johannesAt']??null;
                $c=$pair['carinaAt']??null;
                $start=$pair['start']??null;
                $end=$pair['end']??null;
                $areaStart=$pair['phoneAreaOverlapStartAt']??null;
                $areaEnd=$pair['phoneAreaOverlapEndAt']??null;
                $areaSeconds=$pair['phoneAreaOverlapSeconds']??null;
                if(!is_int($j)||!is_int($c)
                    ||!is_int($start)||!is_int($end)
                    ||!is_int($areaStart)||!is_int($areaEnd)
                    ||!is_int($areaSeconds)
                    ||$j<$now-self::MAX_AGE||$c<$now-self::MAX_AGE
                    ||$j>$now+5||$c>$now+5
                    ||$start!==min($j,$c)||$end!==max($j,$c)
                    ||($pair['gap']??null)!==abs($j-$c)
                    ||abs($j-$c)>180
                    ||$areaStart!==max($j,$c)
                    ||$areaEnd<=$areaStart||$areaEnd>$now+5
                    ||$areaEnd-$areaStart!==$areaSeconds
                    ||$start>$previousStart
                    ||isset($seen[$j.'|'.$c])){
                    $issues[]='PHONE_PAIR_INTERVAL_INVALID_OR_REUSED';
                    break;
                }
                $seen[$j.'|'.$c]=true;
                $previousStart=$start;
                foreach(['johannesInAt','carinaInAt'] as $field){
                    $when=$pair[$field]??null;
                    if($when!==null&&(!is_int($when)
                        ||$when<=$start||$when>$now+5))
                        $issues[]='PHONE_IN_TIME_INVALID';
                }
            }
            if($count===0&&($latest!==null||$pairs!==[]))
                $issues[]='PHONE_LATEST_PAIR_PRESENT_WITH_ZERO_COUNT';
            if($count>0&&($latest===null||$pairs===[]
                ||$latest!==$pairs[0]))
                $issues[]='PHONE_LATEST_PAIR_DOES_NOT_MATCH_JOURNAL';
            if(!in_array('PHONE_PAIR_INTERVAL_INVALID_OR_REUSED',
                $issues,true))
                $out['phoneIntervalsStrictlyPositive']=true;
        }

        $checks++;
        $hasPair=($timeline['pairedPhoneAreaOutObserved']??null);
        $matchedTotal=$timeline['pairedEventsWithin24h']??null;
        $matchedP03=$timeline['p03UniquePairMatchesRecent']??null;
        $checkedP03=$timeline['p03RecentPhonePairsChecked']??null;
        if(!is_bool($hasPair)
            ||!is_int($matchedTotal)||!is_int($matchedP03)
            ||!is_int($checkedP03)||$matchedP03<0
            ||$checkedP03<0||$matchedP03>$checkedP03
            ||$checkedP03>self::MAX_PAIRS
            ||$checkedP03>$count
            ||$matchedTotal!==$count||$hasPair!==($count>0)){
            $issues[]='PHONE_P03_AGGREGATE_MISMATCH';
        }
        if($valid&&$count>0&&is_array($latest)){
            if(($timeline['pairedOutStartAt']??null)!==$latest['start']
                ||($timeline['pairedOutEndAt']??null)!==$latest['end']
                ||($timeline['pairedPhoneGapSeconds']??null)!==$latest['gap']
                ||($timeline['latestPhoneAreaOverlapSeconds']??null)
                    !==$latest['phoneAreaOverlapSeconds']
                ||($timeline['pairedPhoneOutAt']['johannes']??null)
                    !==$latest['johannesAt']
                ||($timeline['pairedPhoneOutAt']['carina']??null)
                    !==$latest['carinaAt'])
                $issues[]='LATEST_PHONE_PAIR_DIFFERS_ACROSS_MODULES';
        }
        $mode=$timeline['p03Mode']??null;
        $ready=in_array($mode,['TEST_ACTIVE','PRODUCTION_ACTIVE'],true)
            &&in_array($p03['logContinuity']??null,[
                'OK','TAIL_OVERLAP_AFTER_ROTATION'],true)
            &&($p03['baselineOnly']??false)!==true
            &&($p03['newProofBatchOverflow']??false)!==true
            &&($p03['verifiedTimelineTruncated']??false)!==true
            &&count(array_intersect((array)($p03['warnings']??[]),[
                'P03_LOG_BATCH_INVALID','P03_LOG_GAP_OR_RESET',
                'INVALID_CLOCK_OR_LOG_SIZE','P03_SOURCE_NOT_READABLE',
                'P03_NEW_PROOF_BATCH_OVERFLOW']))===0;
        $out['p03SourceReady']=$ready;
        $p03Linked=($timeline['verifiedP03NearPair']??null)===true;
        // Verify that the claimed matched timestamp really appears in
        // the separately certified anonymous P03 directional history.
        $verifiedTimeline=$p03['verifiedDirectionTimeline24h']??null;
        $sourceTimelineValid=is_array($verifiedTimeline)
            &&array_is_list($verifiedTimeline)
            &&count($verifiedTimeline)<=256;
        $p03OutTimes=[];
        if($sourceTimelineValid){
            foreach($verifiedTimeline as $e){
                if(!is_array($e)||!is_int($e['at']??null)
                    ||($e['source']??null)!=='P03_TWO_MAST_VERIFIED'
                    ||!in_array($e['direction']??null,[
                        'HOME_TO_LAGER','LAGER_TO_HOME'],true)){
                    $sourceTimelineValid=false;break;
                }
                if($e['direction']==='HOME_TO_LAGER'
                    &&$e['at']>=$now-self::MAX_AGE
                    &&$e['at']<=$now+5)
                    $p03OutTimes[$e['at']]=true;
            }
        }
        $checks++;
        if(!$sourceTimelineValid)
            $issues[]='P03_VERIFIED_HISTORY_MALFORMED';
        if(is_int($matchedP03)&&$matchedP03>count($p03OutTimes))
            $issues[]='P03_MATCH_COUNT_EXCEEDS_UNIQUE_PROOFS';
        $checks++;
        if($p03Linked){
            $at=$timeline['verifiedP03NearPairAt']??null;
            $gap=$timeline['p03ProofGapSeconds']??null;
            $first=$timeline['pairedOutStartAt']??null;
            $last=$timeline['pairedOutEndAt']??null;
            $close=$timeline['latestPhoneAreaOverlapEndAt']??null;
            $expected=is_int($at)&&is_int($first)&&is_int($last)
                ?($at<$first?$first-$at:($at>$last?$at-$last:0)):null;
            if($sourceTimelineValid&&is_int($at)
                &&!isset($p03OutTimes[$at]))
                $issues[]='P03_LINK_TIMESTAMP_NOT_IN_VERIFIED_HISTORY';
            if(!$ready||!$sourceTimelineValid||!$valid
                ||$count===0||!is_int($at)
                ||$at<$now-self::MAX_AGE||$at>$now+5
                ||!is_int($close)||$at>$close
                ||!is_int($gap)||$gap<0||$gap>300
                ||$gap!==$expected||($matchedP03??0)<1){
                $issues[]='P03_LATEST_PROOF_NOT_QUALIFIED';
            }else{
                $out['latestP03LinkConsistent']=true;
            }
        }elseif(($timeline['verifiedP03NearPairAt']??null)!==null
            ||($timeline['p03ProofGapSeconds']??null)!==null){
            $issues[]='P03_GHOST_PROOF_WITHOUT_LINK';
        }
        $out['uniqueP03Matches']=is_int($matchedP03)
            ?max(0,min($matchedP03,256)):0;
        if(!$ready&&($matchedP03??0)>0)
            $issues[]='P03_HISTORICAL_COUNTER_PRESENT_WITH_UNREADY_MODE';
        if($p03Linked&&$mode==='TEST_AND_PRODUCTION_OFF')
            $issues[]='P03_PROOF_DURING_INTENTIONAL_OFF';

        $checks++;
        $scoped=$room['scopedPhoneAreaPairs']??null;
        $positive=$room['pairsWithAnonymousHomeMotion']??null;
        $roomEvents=$room['latestPairHomeMotionCount']??null;
        $distinct=$room['latestPairHomeMotionRooms']??null;
        if(!is_int($scoped)||$scoped<0||$scoped>self::MAX_PAIRS
            ||$scoped!==min($count,self::MAX_PAIRS)
            ||!is_int($positive)||$positive<0||$positive>$scoped
            ||!is_int($roomEvents)||$roomEvents<0||$roomEvents>2048
            ||!is_int($distinct)||$distinct<0||$distinct>15
            ||$distinct>$roomEvents
            ||($count===0&&($roomEvents>0||$distinct>0))
            ||(($room['presenceCertified']??false)===true)
            ||(($room['personCountCertified']??false)===true))
            $issues[]='ANONYMOUS_ROOM_PAIR_COUNTER_MISMATCH';
        else $out['anonymousRoomOverlayConsistent']=true;

        foreach([$cycle,$timeline,$room] as $source){
            $checks++;
            foreach([
                'mayClaimHumanDeparture','mayCertifyEmpty',
                'personDepartureCertified','personCountCertified',
                'presenceCertified','homeEmptyCertified',
                'mayChangePresence','mayControlAlarm','mayControlDevices'
            ] as $key){
                if(($source[$key]??false)===true){
                    $issues[]='UNAUTHORIZED_PHYSICAL_OR_CONTROL_CLAIM';
                    break 2;
                }
            }
        }
        $out['testedInvariants']=$checks;
        $out['violations']=array_slice(array_values(array_unique($issues)),0,12);
        $out['inputDataRejected']=$issues!==[];
        $out['status']=$issues===[]?'CROSS_SOURCE_CONSISTENT_ANONYMOUS_ONLY'
            :'CROSS_SOURCE_CONTRADICTION_NO_ACTION';
        $out['priorityChecks']=$issues!==[]
            ?array_slice($out['violations'],0,4)
            :($hasPair && !$ready
                ?['P03_PROOF_SOURCE_NOT_CURRENTLY_AVAILABLE']
                :(!$hasPair
                    ?['NO_SHARED_VERIFIED_PHONE_AREA_INTERVAL']
                    :(!$p03Linked
                        ?['NO_UNIQUE_P03_PROOF_FOR_LATEST_PHONE_PAIR']
                        :['ALL_TIME_MATCHES_STILL_ANONYMOUS_NO_HOUSE_EMPTY'])));

        return $out;
    }
}
