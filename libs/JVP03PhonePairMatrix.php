<?php
declare(strict_types=1);

/**
 * Deterministic one-to-one time correlation of ANONYMOUS verified P03
 * corridor events against disjoint phone-area episode PAIRS.
 *
 * A match only means timestamps are compatible. Cameras cannot identify
 * either phone or count people. P03 HOME->LAGER is no property exit.
 * Read-only and stateless, no new Symcon buffers, commands or devices.
 */
final class JVP03PhonePairMatrix
{
    public const AUTHORITY='READ_ONLY_P03_PHONE_PAIR_ONE_TO_ONE_NOT_IDENTITY';
    private const MAX_PAIRS=8;
    private const MAX_EVENTS=256;
    private const GAP=300;
    private const AGE=86400;

    private static function distance(array $pair,int $at,int $now):?int
    {
        // A crossing AFTER either phone's observed geofence IN cannot
        // be correlated with a joint reported-out interval.
        $outStart=$pair['start']??null;
        $outEnd=$pair['end']??null;
        $areaEnd=$pair['phoneAreaOverlapEndAt']??null;
        if(!is_int($outStart)||!is_int($outEnd)
            ||!is_int($areaEnd)
            ||$areaEnd<=$outEnd||$areaEnd>$now+5
            ||$at>$areaEnd)return null;
        $gap=$at<$outStart?$outStart-$at
            :($at>$outEnd?$at-$outEnd:0);
        return $gap<=self::GAP?$gap:null;
    }

    public static function evaluate(array $cycles,array $p03,int $now):array
    {
        $out=[
            'schema'=>1,'authority'=>self::AUTHORITY,
            'status'=>'SOURCES_UNVERIFIED','scopedPhonePairs'=>0,
            'scopedP03Proofs'=>0,'matchedPhonePairs'=>0,
            'matchedEvents'=>[], 'latestPhonePairP03'=>null,
            'nearestPreviousPairP03'=>null,
            'unmatchedPhonePairs'=>0,
            'reusedP03Proofs'=>false,
            'latestPairP03Ambiguous'=>false,
            'latestPairP03CandidateCount'=>0,
            'latestPairMatchedButAmbiguous'=>false,
            'inputUntrusted'=>true,
            'scopeLimitedToLastEightPhonePairs'=>true,
            'noHumanIdentity'=>true,'noPeopleCount'=>true,
            'noPropertyExit'=>true,'noVehicleProof'=>true,
            'noAlarmAction'=>true
        ];
        if($now<=0
            ||($cycles['schema']??null)!==1
            ||($cycles['authority']??null)
                !=='READ_ONLY_PHONE_AREA_CYCLES_ONE_TO_ONE_NO_PEOPLE'
            ||($cycles['qualifiedSources']??null)!==2
            ||($p03['schema']??null)!==1
            ||($p03['authority']??null)!=='P03_LIVE_PROOFS_DIRECTION_ONLY'
            ||!in_array($p03['logContinuity']??null,[
                'OK','TAIL_OVERLAP_AFTER_ROTATION'],true)
            ||($p03['baselineOnly']??false)===true
            ||($p03['verifiedTimelineTruncated']??false)===true
            ||($p03['newProofBatchOverflow']??false)===true
            ||count(array_intersect((array)($p03['warnings']??[]),[
                'P03_LOG_BATCH_INVALID','INVALID_CLOCK_OR_LOG_SIZE',
                'P03_SOURCE_NOT_READABLE','P03_LOG_GAP_OR_RESET',
                'P03_NEW_PROOF_BATCH_OVERFLOW']))>0)
            return $out;

        $source=$cycles['recentPairs']??null;
        $events=$p03['verifiedDirectionTimeline24h']??null;
        if(!is_array($source)||!array_is_list($source)
            ||count($source)>self::MAX_PAIRS
            ||!is_array($events)||!array_is_list($events)
            ||count($events)>self::MAX_EVENTS)return $out;
        $pairs=array_reverse($source);
        $lastEnd=0;
        foreach($pairs as $p){
            if(!is_array($p)||!is_int($p['start']??null)
                ||!is_int($p['end']??null)
                ||$p['start']<$now-self::AGE
                ||$p['end']>$now+5||$p['start']>$p['end']
                ||$p['end']<$lastEnd
                ||!is_int($p['phoneAreaOverlapStartAt']??null)
                ||!is_int($p['phoneAreaOverlapEndAt']??null)
                ||$p['phoneAreaOverlapStartAt']>$p['phoneAreaOverlapEndAt'])
                return $out;
            $lastEnd=$p['end'];
        }
        $unique=[];
        foreach($events as $e){
            if(!is_array($e)||($e['source']??null)
                !=='P03_TWO_MAST_VERIFIED'
                ||!is_int($e['at']??null)
                ||!in_array($e['direction']??null,[
                    'HOME_TO_LAGER','LAGER_TO_HOME'],true))
                return $out;
            if($e['at']<$now-self::AGE||$e['at']>$now+5)continue;
            if($e['direction']==='HOME_TO_LAGER')
                $unique[$e['at']]=$e['at']; // identical-second proof one event
        }
        $proofs=array_values($unique);
        sort($proofs,SORT_NUMERIC);
        // An apparently one-to-one match may still be ambiguous: more
        // than one anonymous crossing can fit the SAME phone interval.
        // Never pretend that the chosen DP representative is unique.
        if($pairs!==[]){
            $lastPair=$pairs[count($pairs)-1];
            $cameraCouldBelongToOlderPair=false;
            foreach($proofs as $at){
                if(self::distance($lastPair,$at,$now)===null)continue;
                $out['latestPairP03CandidateCount']++;
                foreach(array_slice($pairs,0,-1) as $olderPair){
                    if(self::distance($olderPair,$at,$now)!==null){
                        $cameraCouldBelongToOlderPair=true;
                        break;
                    }
                }
            }
            // Even when the chosen DP attributes an ambiguous camera
            // crossing to the OLD pair, this does not establish that
            // the latest phone pair definitively lacked that crossing.
            $out['latestPairP03Ambiguous']=
                $out['latestPairP03CandidateCount']>1
                ||$cameraCouldBelongToOlderPair;
        }
        $out['scopedPhonePairs']=count($pairs);
        $out['scopedP03Proofs']=count($proofs);
        $out['inputUntrusted']=false;
        if($pairs===[]||$proofs===[]){
            $out['unmatchedPhonePairs']=count($pairs);
            $out['status']='NO_MATCHABLE_ANONYMOUS_OUT_CORRIDOR';
            return $out;
        }

        // DP maximizes number of distinct phone-pair/P03-proof matches,
        // then minimizes total timestamp distance. Every proof consumed
        // at most once. At most 9*257 small cells, pure PHP in memory.
        $n=count($pairs);$m=count($proofs);$dp=[];
        for($i=$n;$i>=0;$i--){
            for($j=$m;$j>=0;$j--){
                if($i===$n||$j===$m){
                    $dp[$i][$j]=[0,0,'END'];continue;
                }
                $skipP=$dp[$i+1][$j];$skipE=$dp[$i][$j+1];
                $skipP[2]='SKIP_PAIR';$skipE[2]='SKIP_PROOF';
                $winner=$skipP;
                if($skipE[0]>$winner[0]
                    ||($skipE[0]===$winner[0]&&$skipE[1]<$winner[1]))
                    $winner=$skipE;
                $dist=self::distance($pairs[$i],$proofs[$j],$now);
                if($dist!==null){
                    $candidate=$dp[$i+1][$j+1];
                    $candidate=[1+$candidate[0],$dist+$candidate[1],'MATCH'];
                    if($candidate[0]>$winner[0]
                        ||($candidate[0]===$winner[0]
                            &&$candidate[1]<=$winner[1]))
                        $winner=$candidate;
                }
                $dp[$i][$j]=$winner;
            }
        }
        $matched=[];$i=0;$j=0;
        while($i<$n&&$j<$m){
            $choice=$dp[$i][$j][2];
            if($choice==='MATCH'){
                $p=$pairs[$i];$at=$proofs[$j];
                $matched[]=[
                    'phoneOutStartAt'=>$p['start'],
                    'phoneOutEndAt'=>$p['end'],
                    'p03At'=>$at,
                    'gapSeconds'=>self::distance($p,$at,$now),
                    'bothPhonesAreaOverlapAt'=>$p['phoneAreaOverlapStartAt'],
                    'anonymousCorridorOnly'=>true,
                    'personIdentificationImpossible'=>true
                ];
                $i++;$j++;
            }elseif($choice==='SKIP_PAIR')$i++;
            else $j++;
        }
        $out['matchedPhonePairs']=count($matched);
        $out['unmatchedPhonePairs']=$n-count($matched);
        $out['matchedEvents']=array_reverse($matched);
        $out['status']=$matched===[]?'NO_CORRIDOR_NEAR_PHONE_PAIRS'
            :'ANONYMOUS_CORRIDOR_NEAR_DISJOINT_PHONE_PAIRS';
        if($matched!==[]){
            $latest=$matched[count($matched)-1];
            $out['nearestPreviousPairP03']=$latest;
            if($latest['phoneOutStartAt']===$pairs[$n-1]['start']
                &&$latest['phoneOutEndAt']===$pairs[$n-1]['end']){
                // This is an anonymous time alignment, not a person.
                // Competition was checked independent of the chosen DP
                // representative, including when it preferred an old pair.
                $ambiguous=$out['latestPairP03Ambiguous'];
                $out['latestPairP03Ambiguous']=$ambiguous;
                $out['latestPairMatchedButAmbiguous']=$ambiguous;
                if(!$ambiguous)$out['latestPhonePairP03']=$latest;
                else $out['status']='AMBIGUOUS_ANONYMOUS_P03_FOR_LATEST_PHONE_PAIR';
            }
        }
        return $out;
    }
}
