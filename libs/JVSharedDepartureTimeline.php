<?php
declare(strict_types=1);

/**
 * Bounded, stateless 24h review of TWO separately observed PHONE geofence
 * OUT events and independent ANONYMOUS verified P03 transfer timestamps.
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
            'pairedPhoneAreaOutObserved'=>false,
            'pairedOutStartAt'=>null,'pairedOutEndAt'=>null,
            'pairedPhoneGapSeconds'=>null,
            'pairedEventsWithin24h'=>0,
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

        $out['status']='NO_TWO_QUALIFIED_PHONE_AREA_EXITS_24H';
        $outs=[];$sources=[];
        foreach(['johannes','carina'] as $person){
            $p=$fusion['people'][$person]??null;
            if(!is_array($p)){
                $out['sourceGaps'][]=strtoupper($person).'_PHONE_LEDGER_MISSING';
                continue;
            }
            $continuous=($p['geoSourceContinuous']??false)===true
                &&($p['macSourceContinuous']??false)===true
                &&($p['sourceRebaselineRequired']??true)===false;
            if(!$continuous){
                $out['sourceGaps'][]=strtoupper($person).'_PHONE_SOURCE_NOT_CONTINUOUS';
                continue;
            }
            $history=$p['eventHistory']??null;
            if(!is_array($history)||!array_is_list($history)
                ||count($history)>self::MAX_HISTORY){
                $out['sourceGaps'][]=strtoupper($person).'_PHONE_EVENT_HISTORY_INVALID';
                continue;
            }
            $rebind=$p['lastSourceRebindAt']??null;
            if($rebind!==null&&(!is_int($rebind)||$rebind<=0
                ||$rebind>$now+5)){
                $out['sourceGaps'][]=strtoupper($person).'_SOURCE_REBIND_INVALID';
                continue;
            }
            $seen=[];
            foreach($history as $event){
                if(!is_array($event)
                    ||($event['code']??null)!=='PHONE_GEOFENCE_OUT_OBSERVED'
                    ||!is_int($event['at']??null))continue;
                $at=$event['at'];
                if($at<$now-self::WINDOW||$at>$now+5
                    ||($rebind!==null&&$at<=$rebind))continue;
                $seen[$at]=$at;
            }
            rsort($seen,SORT_NUMERIC);
            $outs[$person]=array_values($seen);
            $sources[$person]=true;
            $out['latestPhoneOut'][$person]=$outs[$person][0]??null;
        }
        $out['phoneOutSourceCount']=count($sources);
        if(count($sources)!==2){
            $out['status']='ONE_OR_BOTH_PHONE_SOURCES_UNVERIFIED';
            return $out;
        }
        if($outs['johannes']===[]||$outs['carina']===[]){
            $out['sourceGaps'][]='NO_INDEPENDENT_GEOFENCE_OUT_FOR_BOTH_PHONES';
        }else{
            // Compare every independently observed pair; never count a
            // recurring event by MAC reacquisition or by duplicate records.
            $pairs=[];
            foreach($outs['johannes'] as $j){
                foreach($outs['carina'] as $c){
                    $gap=abs($j-$c);
                    if($gap>self::PAIR_SECONDS)continue;
                    $pairs[min($j,$c).'|'.max($j,$c)]=[
                        'start'=>min($j,$c),'end'=>max($j,$c),'gap'=>$gap];
                }
            }
            $out['pairedEventsWithin24h']=count($pairs);
            if($pairs!==[]){
                $latest=null;
                foreach($pairs as $p){
                    if($latest===null||$p['end']>$latest['end']
                        ||($p['end']===$latest['end']&&$p['gap']<$latest['gap']))
                        $latest=$p;
                }
                $out['pairedPhoneAreaOutObserved']=true;
                $out['pairedOutStartAt']=$latest['start'];
                $out['pairedOutEndAt']=$latest['end'];
                $out['pairedPhoneGapSeconds']=$latest['gap'];
                $out['status']='TWO_PHONE_AREA_OUT_EVENTS_NOT_RESIDENT_DEPARTURE';
            }
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
            $out['status']='TWO_PHONE_AREA_OUT_EVENTS_P03_OFF';
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
        $out['status']='TWO_PHONE_AREA_EXITS_AND_ANONYMOUS_P03_NEARBY';
        return $out;
    }
}
