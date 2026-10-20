<?php
declare(strict_types=1);
require_once __DIR__.'/JVPhoneAreaCycleMatcher.php';
require_once __DIR__.'/JVP03PhonePairMatrix.php';

/**
 * Bounded, stateless 24h review of two independent PHONE geofence
 * OUT/IN cycles and separate ANONYMOUS verified P03 timestamps.
 * Every phone cycle is paired at most once; repeated OUT without IN is
 * never a new exit. No human/house ownership or travel claims.
 *
 * An app geofence includes both HOME and WORK in this installation; leaving
 * that shared area is NOT necessarily leaving the house. A phone can remain
 * at home, and P03 cameras cannot identify a phone's owner. This module
 * deliberately does not set anyone HOME/AWAY, occupant counts, or alarms.
 */
final class JVSharedDepartureTimeline
{
    public const AUTHORITY='READ_ONLY_SHARED_HANDSET_EXIT_SEQUENCE_NO_IDENTITY';
    private const WINDOW=86400;
    private const PAIR_SECONDS=180;
    private const P03_NEAR_SECONDS=300;
    private const MAX_HISTORY=24;
    private const MAX_P03=256;

    public static function evaluate(array $fusion,array $p03,array $timing,int $now):array
    {
        $out=[
            'schema'=>1,'authority'=>self::AUTHORITY,
            'status'=>'SOURCES_UNVERIFIED',
            'phoneOutSourceCount'=>0,
            'latestPhoneOut'=>['johannes'=>null,'carina'=>null],
            'latestPhoneAreaIn'=>['johannes'=>null,'carina'=>null],
            'pairedPhoneOutAt'=>['johannes'=>null,'carina'=>null],
            'returnedAfterPairedOut'=>['johannes'=>false,'carina'=>false],
            'pairedOutSupersededByAreaIn'=>false,
            'historicPairIsNotCurrentPhoneState'=>true,
            'pairedPhoneAreaOutObserved'=>false,
            'pairedOutStartAt'=>null,'pairedOutEndAt'=>null,
            'pairedPhoneGapSeconds'=>null,
            'pairedEventsWithin24h'=>0,
            'nearButDisjointPhonePeriods'=>0,
            'latestPhoneAreaOverlapSeconds'=>null,
            'latestPhoneAreaOverlapStartAt'=>null,
            'latestPhoneAreaOverlapEndAt'=>null,
            'phoneAreaOverlapIsNotHumanCoPresence'=>true,
            'p03UniquePairMatchesRecent'=>0,
            'p03RecentPhonePairsChecked'=>0,
            'p03CorrelatedProofsNotReused'=>true,
            'p03MatchPreviousNotLatest'=>false,
            'p03LatestPairAmbiguous'=>false,
            'p03LatestPairCandidateCount'=>0,
            'p03OppositeDirectionSecondsIgnored'=>0,
            'p03LatestPairDirectionConflict'=>false,
            'oneToOneCycleMatching'=>true,
            'phoneAreaCycles24h'=>['johannes'=>0,'carina'=>0],
            'repeatedPhoneOutIgnored'=>['johannes'=>0,'carina'=>0],
            'orphanPhoneInIgnored'=>['johannes'=>0,'carina'=>0],
            'competingPossiblePairings'=>false,
            'sameSecondPhoneStateConflict'=>false,
            'unmatchedPhoneCycles'=>['johannes'=>0,'carina'=>0],
            'verifiedP03NearPair'=>false,
            'verifiedP03NearPairAt'=>null,
            'p03ProofGapSeconds'=>null,
            'p03Mode'=>'UNKNOWN_NOT_VERIFIED',
            'p03Continuity'=>'NOT_VERIFIED',
            'sourceGaps'=>[],
            'geofenceEnclosesHomeAndWork'=>true,
            'phoneMacGapIsNotDeparture'=>true,
            'twoPhoneEventsAreNotTwoHumans'=>true,
            'cameraCannotIdentifyEitherResident'=>true,
            'p03HomeLagerIsNotPropertyExit'=>true,
            'personDepartureCertified'=>false,
            'personCountCertified'=>false,
            'homeEmptyCertified'=>false,
            'mayChangePresence'=>false,
            'mayControlAlarm'=>false,'mayControlDevices'=>false
        ];
        if($now<=0
            ||($fusion['schema']??null)!==1
            ||($fusion['authority']??null)
                !=='PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY'
            ||!is_array($fusion['people']??null)
            ||($p03['schema']??null)!==1
            ||($p03['authority']??null)!=='P03_LIVE_PROOFS_DIRECTION_ONLY'
            ||($timing['schema']??null)!==1
            ||($timing['authority']??null)
                !=='READ_ONLY_TIME_ALIGNMENT_DIAGNOSTIC_NOT_IDENTITY')
            return $out;

        // OUT events are reconstructed as individual phone-area cycles.
        // Without a matching intervening IN, repeated OUT records from
        // the same smartphone are NOT separate events or extra residents.
        $cycles=JVPhoneAreaCycleMatcher::evaluate($fusion,$now);
        $out['phoneOutSourceCount']=$cycles['qualifiedSources'];
        $out['latestPhoneOut']=$cycles['latestOut'];
        $out['latestPhoneAreaIn']=$cycles['latestIn'];
        $out['phoneAreaCycles24h']=$cycles['cycles'];
        $out['nearButDisjointPhonePeriods']=$cycles['nearButNonOverlappingCandidates'];
        $out['repeatedPhoneOutIgnored']=$cycles['repeatedOutIgnored'];
        $out['orphanPhoneInIgnored']=$cycles['orphanInIgnored'];
        $out['competingPossiblePairings']=$cycles['multiplePairingsPossible'];
        $out['sameSecondPhoneStateConflict']=$cycles['sameSecondConflicts'];
        $out['unmatchedPhoneCycles']=$cycles['unmatchedCycles'];
        $out['pairedEventsWithin24h']=$cycles['pairedCycleCount'];
        $out['sourceGaps']=array_merge(
            $out['sourceGaps'],$cycles['sourceGaps']);
        if($cycles['qualifiedSources']!==2){
            $out['status']='ONE_OR_BOTH_PHONE_SOURCES_UNVERIFIED';
            return $out;
        }
        $latest=$cycles['latestPair'];
        if(is_array($latest)){
            $out['pairedPhoneAreaOutObserved']=true;
            $out['pairedOutStartAt']=$latest['start'];
            $out['pairedOutEndAt']=$latest['end'];
            $out['pairedPhoneGapSeconds']=$latest['gap'];
            $out['latestPhoneAreaOverlapStartAt']=$latest['phoneAreaOverlapStartAt'];
            $out['latestPhoneAreaOverlapEndAt']=$latest['phoneAreaOverlapEndAt'];
            $out['latestPhoneAreaOverlapSeconds']=$latest['phoneAreaOverlapSeconds'];
            $out['pairedPhoneOutAt']=[
                'johannes'=>$latest['johannesAt'],
                'carina'=>$latest['carinaAt']
            ];
            $out['returnedAfterPairedOut']=$latest['returnedAfterOut'];
            $out['pairedOutSupersededByAreaIn']=
                $latest['returnedAfterOut']['johannes']
                ||$latest['returnedAfterOut']['carina'];
            $out['status']=$out['pairedOutSupersededByAreaIn']
                ?'HISTORIC_PHONE_AREA_OUT_PAIR_FOLLOWED_BY_IN'
                :'TWO_PHONE_AREA_OUT_EVENTS_NOT_RESIDENT_DEPARTURE';
        }else{
            $out['status']=$cycles['status']
                ==='NEAR_PHONE_OUTS_BUT_NO_SHARED_AREA_INTERVAL'
                    ?'PHONE_AREA_INTERVALS_DO_NOT_OVERLAP'
                    :'NO_TWO_QUALIFIED_PHONE_AREA_EXITS_24H';
        }
        $fresh=($timing['p03ModeTrustedFromFreshDiagnosis']??false)===true;
        $mode=$fresh?($timing['p03OperatingMode']??null):null;
        $out['p03Mode']=in_array($mode,[
            'TEST_AND_PRODUCTION_OFF','TEST_ACTIVE','PRODUCTION_ACTIVE'
        ],true)?$mode:'UNKNOWN_NOT_VERIFIED';
        $continuity=$p03['logContinuity']??null;
        $out['p03Continuity']=is_string($continuity)?$continuity:'NOT_VERIFIED';
        if(!$out['pairedPhoneAreaOutObserved'])return $out;
        if($out['p03Mode']==='TEST_AND_PRODUCTION_OFF'){
            $out['sourceGaps'][]='P03_INTENTIONALLY_OFF_NO_CURRENT_PROOF';
            $out['status']=$out['pairedOutSupersededByAreaIn']
                ?'HISTORIC_PHONE_AREA_OUT_PAIR_P03_OFF'
                :'TWO_PHONE_AREA_OUT_EVENTS_P03_OFF';
            return $out;
        }
        if($out['p03Mode']==='UNKNOWN_NOT_VERIFIED'){
            $out['sourceGaps'][]='P03_MODE_NOT_FRESH';
            return $out;
        }
        if(!in_array($continuity,['OK','TAIL_OVERLAP_AFTER_ROTATION'],true)
            ||($p03['newProofBatchOverflow']??false)===true
            ||($p03['baselineOnly']??false)===true
            ||($p03['verifiedTimelineTruncated']??false)===true
            ||count(array_intersect((array)($p03['warnings']??[]),[
                'P03_LOG_BATCH_INVALID','INVALID_CLOCK_OR_LOG_SIZE',
                'P03_SOURCE_NOT_READABLE','P03_LOG_GAP_OR_RESET',
                'P03_NEW_PROOF_BATCH_OVERFLOW','P03_PROOF_SYNTAX_UNTRUSTED']))>0){
            $out['sourceGaps'][]='P03_LIVE_EVENT_CONTINUITY_UNVERIFIED';
            return $out;
        }
        $history=$p03['verifiedDirectionTimeline24h']??null;
        if(!is_array($history)||!array_is_list($history)
            ||count($history)>self::MAX_P03){
            $out['sourceGaps'][]='P03_VERIFIED_TIMELINE_INVALID';
            return $out;
        }
        // Match *all* recently retained OUT-phone pairs against verified
        // P03 events using disjoint one-to-one references. The latest pair
        // is only credited if IT has its own non-reused anonymous proof.
        // A P03 event observed after either phone's recorded IN is excluded.
        $matrix=JVP03PhonePairMatrix::evaluate($cycles,$p03,$now);
        if(($matrix['inputUntrusted']??true)===true){
            $out['sourceGaps'][]='P03_PHONE_CORRELATION_MATRIX_UNVERIFIED';
            return $out;
        }
        $out['p03UniquePairMatchesRecent']=$matrix['matchedPhonePairs'];
        $out['p03RecentPhonePairsChecked']=$matrix['scopedPhonePairs'];
        $out['p03LatestPairAmbiguous']=$matrix['latestPairP03Ambiguous'];
        $out['p03LatestPairCandidateCount']=$matrix['latestPairP03CandidateCount'];
        $out['p03OppositeDirectionSecondsIgnored']=$matrix['oppositeDirectionSameSecondIgnored'];
        $out['p03LatestPairDirectionConflict']=$matrix['latestPairP03DirectionConflict'];
        $latestMatch=$matrix['latestPhonePairP03']??null;
        $out['p03MatchPreviousNotLatest']=$latestMatch===null
            &&!$matrix['latestPairP03Ambiguous']
            &&($matrix['matchedPhonePairs']??0)>0;
        if(!is_array($latestMatch)){
            $out['sourceGaps'][]=$matrix['latestPairP03DirectionConflict']
                ?'P03_OPPOSITE_DIRECTIONS_SAME_SECOND_NEAR_LATEST_PAIR'
                :($matrix['latestPairP03Ambiguous']
                    ?'MULTIPLE_P03_CANDIDATES_FOR_LATEST_PHONE_PAIR'
                    :'NO_DISTINCT_P03_PROOF_FOR_LATEST_PHONE_PAIR');
            return $out;
        }
        $out['verifiedP03NearPair']=true;
        $out['verifiedP03NearPairAt']=$latestMatch['p03At'];
        $out['p03ProofGapSeconds']=$latestMatch['gapSeconds'];
        $out['status']=$out['pairedOutSupersededByAreaIn']
            ?'HISTORIC_PHONE_AREA_OUT_AND_ANONYMOUS_P03_NEARBY'
            :'TWO_PHONE_AREA_EXITS_AND_ANONYMOUS_P03_NEARBY';
        return $out;
    }
}
