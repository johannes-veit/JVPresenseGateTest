<?php
declare(strict_types=1);
require_once __DIR__.'/JVPhoneAreaCycleMatcher.php';

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
            $out['status']='NO_TWO_QUALIFIED_PHONE_AREA_EXITS_24H';
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
            ||($p03['verifiedTimelineTruncated']??false)===true){
            $out['sourceGaps'][]='P03_LIVE_EVENT_CONTINUITY_UNVERIFIED';
            return $out;
        }
        $history=$p03['verifiedDirectionTimeline24h']??null;
        if(!is_array($history)||!array_is_list($history)
            ||count($history)>self::MAX_P03){
            $out['sourceGaps'][]='P03_VERIFIED_TIMELINE_INVALID';
            return $out;
        }
        $nearest=null;$nearestAt=null;
        foreach($history as $event){
            if(!is_array($event)||($event['source']??null)
                !=='P03_TWO_MAST_VERIFIED'
                ||($event['direction']??null)!=='HOME_TO_LAGER'
                ||!is_int($event['at']??null))continue;
            $at=$event['at'];
            if($at<$now-self::WINDOW||$at>$now+5)continue;
            $gap=$at<$out['pairedOutStartAt']
                ?$out['pairedOutStartAt']-$at
                :($at>$out['pairedOutEndAt']
                    ?$at-$out['pairedOutEndAt']:0);
            if($gap>self::P03_NEAR_SECONDS)continue;
            if($nearest===null||$gap<$nearest){
                $nearest=$gap;$nearestAt=$at;
            }
        }
        if($nearest===null){
            $out['sourceGaps'][]='NO_VERIFIED_OUT_P03_PROOF_NEAR_PHONE_PAIR';
            return $out;
        }
        $out['verifiedP03NearPair']=true;
        $out['verifiedP03NearPairAt']=$nearestAt;
        $out['p03ProofGapSeconds']=$nearest;
        $out['status']=$out['pairedOutSupersededByAreaIn']
            ?'HISTORIC_PHONE_AREA_OUT_AND_ANONYMOUS_P03_NEARBY'
            :'TWO_PHONE_AREA_EXITS_AND_ANONYMOUS_P03_NEARBY';
        return $out;
    }
}
