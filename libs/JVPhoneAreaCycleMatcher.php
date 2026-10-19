<?php
declare(strict_types=1);

/**
 * Pure, bounded review of independently observed phone-area OUT/IN cycles.
 *
 * One app's repeated OUT without an intervening IN is ONE observed episode,
 * NOT another departure. Pair two phones by monotone one-to-one assignment,
 * maximizing distinct pairs before minimizing total timestamp skew.
 *
 * No Geofency/EgiGeoZone writes, no handset location coordinates stored,
 * no cameras, no residents identified. The geofence covers HOME and WORK.
 */
final class JVPhoneAreaCycleMatcher
{
    public const AUTHORITY='READ_ONLY_PHONE_AREA_CYCLES_ONE_TO_ONE_NO_PEOPLE';
    private const AGE=86400;
    private const MAX_HISTORY=24;
    private const MAX_PAIR_SECONDS=180;
    private const MAX_PUBLISHED_PAIRS=8;

    public static function evaluate(array $fusion,int $now):array
    {
        $out=[
            'schema'=>1,'authority'=>self::AUTHORITY,
            'status'=>'SOURCES_UNVERIFIED',
            'qualifiedSources'=>0,
            'latestOut'=>['johannes'=>null,'carina'=>null],
            'latestIn'=>['johannes'=>null,'carina'=>null],
            'cycles'=>['johannes'=>0,'carina'=>0],
            'repeatedOutIgnored'=>['johannes'=>0,'carina'=>0],
            'orphanInIgnored'=>['johannes'=>0,'carina'=>0],
            'sameSecondConflicts'=>false,
            'pairedCycleCount'=>0,'latestPair'=>null,'recentPairs'=>[],
            'multiplePairingsPossible'=>false,
            'unmatchedCycles'=>['johannes'=>0,'carina'=>0],
            'sourceGaps'=>[],
            'samePhoneOutNeverCountedTwice'=>true,
            'matchingObjective'=>'MAX_CARDINALITY_THEN_MIN_TOTAL_ABSOLUTE_GAP',
            'geofenceSharedHomeWork'=>true,
            'sourceIsPhoneNotHuman'=>true,
            'mayClaimHumanDeparture'=>false,
            'mayCertifyEmpty'=>false,'mayControlAlarm'=>false
        ];
        if($now<=0
            ||($fusion['schema']??null)!==1
            ||($fusion['authority']??null)
                !=='PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY'
            ||!is_array($fusion['people']??null))return $out;

        $cycles=[];
        foreach(['johannes','carina'] as $person){
            $p=$fusion['people'][$person]??null;
            if(!is_array($p)||($p['geoSourceContinuous']??false)!==true
                ||($p['macSourceContinuous']??false)!==true
                ||($p['sourceRebaselineRequired']??true)!==false){
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
            $byTime=[];
            foreach($history as $e){
                if(!is_array($e)||!is_int($e['at']??null)
                    ||!in_array($e['code']??null,[
                        'PHONE_GEOFENCE_OUT_OBSERVED',
                        'PHONE_GEOFENCE_IN_OBSERVED'],true))continue;
                $at=$e['at'];
                if($at<$now-self::AGE||$at>$now+5
                    ||($rebind!==null&&$at<=$rebind))continue;
                $code=$e['code'];
                $byTime[$at][$code]=true;
                if($code==='PHONE_GEOFENCE_OUT_OBSERVED'){
                    if($out['latestOut'][$person]===null
                        ||$at>$out['latestOut'][$person])
                        $out['latestOut'][$person]=$at;
                }else{
                    if($out['latestIn'][$person]===null
                        ||$at>$out['latestIn'][$person])
                        $out['latestIn'][$person]=$at;
                }
            }
            // Same source cannot assert OUT and IN in the same second
            // with a defensible ordering. Refuse the whole phone history.
            $personalConflict=false;
            foreach($byTime as $types){
                if(count($types)<=1)continue;
                $personalConflict=true;
                $out['sameSecondConflicts']=true;
                $out['sourceGaps'][]=strtoupper($person)
                    .'_GEOFENCE_OUT_IN_SAME_SECOND_AMBIGUOUS';
                break;
            }
            if($personalConflict){
                $out['latestOut'][$person]=null;
                $out['latestIn'][$person]=null;
                continue;
            }
            ksort($byTime,SORT_NUMERIC);
            $finished=[];$open=null;
            foreach($byTime as $at=>$types){
                $at=(int)$at;
                if(isset($types['PHONE_GEOFENCE_OUT_OBSERVED'])){
                    if($open!==null){
                        $out['repeatedOutIgnored'][$person]++;
                    }else{
                        $open=['outAt'=>$at,'inAt'=>null];
                    }
                }else{
                    if($open===null){
                        $out['orphanInIgnored'][$person]++;
                    }else{
                        $open['inAt']=$at;
                        $finished[]=$open;
                        $open=null;
                    }
                }
            }
            if($open!==null)$finished[]=$open;
            $cycles[$person]=$finished;
            $out['cycles'][$person]=count($finished);
            $out['qualifiedSources']++;
        }
        if($out['qualifiedSources']!==2){
            $out['status']='ONE_OR_BOTH_PHONE_SOURCES_UNVERIFIED';
            return $out;
        }
        $j=$cycles['johannes'];$c=$cycles['carina'];
        $n=count($j);$m=count($c);
        $out['status']='NO_TWO_QUALIFIED_PHONE_AREA_EXITS_24H';
        if($n===0||$m===0){
            $out['sourceGaps'][]='NO_INDEPENDENT_GEOFENCE_OUT_FOR_BOTH_PHONES';
            $out['unmatchedCycles']=['johannes'=>$n,'carina'=>$m];
            return $out;
        }

        // Ordered bipartite DP. Select disjoint OUT episodes with maximum
        // cardinality, then the smallest sum of absolute time differences.
        // Scores and decision paths use at most 25x25 array entries.
        $dp=[];
        for($i=$n;$i>=0;$i--){
            for($k=$m;$k>=0;$k--){
                if($i===$n||$k===$m){
                    $dp[$i][$k]=['n'=>0,'cost'=>0,'step'=>'END'];
                    continue;
                }
                $choices=[
                    ['n'=>$dp[$i+1][$k]['n'],
                        'cost'=>$dp[$i+1][$k]['cost'],'step'=>'SKIP_J'],
                    ['n'=>$dp[$i][$k+1]['n'],
                        'cost'=>$dp[$i][$k+1]['cost'],'step'=>'SKIP_C']
                ];
                $gap=abs($j[$i]['outAt']-$c[$k]['outAt']);
                if($gap<=self::MAX_PAIR_SECONDS)
                    $choices[]=['n'=>1+$dp[$i+1][$k+1]['n'],
                        'cost'=>$gap+$dp[$i+1][$k+1]['cost'],
                        'step'=>'MATCH'];
                usort($choices,static function(array $a,array $b):int{
                    $order=['MATCH'=>0,'SKIP_J'=>1,'SKIP_C'=>2];
                    return ($b['n']<=>$a['n'])
                        ?:($a['cost']<=>$b['cost'])
                        ?:($order[$a['step']]<=>$order[$b['step']]);
                });
                $dp[$i][$k]=$choices[0];
            }
        }

        $matched=[];$i=0;$k=0;
        while($i<$n&&$k<$m){
            $step=$dp[$i][$k]['step'];
            if($step==='MATCH'){
                $jOut=$j[$i]['outAt'];$cOut=$c[$k]['outAt'];
                $matched[]=[
                    'johannesAt'=>$jOut,'carinaAt'=>$cOut,
                    'start'=>min($jOut,$cOut),'end'=>max($jOut,$cOut),
                    'gap'=>abs($jOut-$cOut),
                    'returnedAfterOut'=>[
                        'johannes'=>$j[$i]['inAt']!==null,
                        'carina'=>$c[$k]['inAt']!==null
                    ]
                ];
                $i++;$k++;
            }elseif($step==='SKIP_J')$i++;
            else $k++;
        }
        $out['pairedCycleCount']=count($matched);
        $out['unmatchedCycles']=[
            'johannes'=>$n-count($matched),'carina'=>$m-count($matched)];
        $out['latestPair']=$matched!==[]?$matched[count($matched)-1]:null;
        $out['recentPairs']=array_slice(array_reverse($matched),
            0,self::MAX_PUBLISHED_PAIRS);
        $out['status']=$matched!==[]
            ?'QUALIFIED_ONE_TO_ONE_PHONE_AREA_PAIRS_NOT_PEOPLE'
            :'NO_TWO_QUALIFIED_PHONE_AREA_EXITS_24H';

        // A near-window alternative does not prove any individual departed.
        // Distinct possible matchings may exist even if the chosen optimal
        // time assignment is unique. Surface that ambiguity explicitly.
        foreach($j as $row){
            $options=0;
            foreach($c as $other)
                if(abs($row['outAt']-$other['outAt'])
                    <=self::MAX_PAIR_SECONDS)$options++;
            if($options>1){$out['multiplePairingsPossible']=true;break;}
        }
        if(!$out['multiplePairingsPossible']){
            foreach($c as $row){
                $options=0;
                foreach($j as $other)
                    if(abs($row['outAt']-$other['outAt'])
                        <=self::MAX_PAIR_SECONDS)$options++;
                if($options>1){$out['multiplePairingsPossible']=true;break;}
            }
        }
        return $out;
    }
}
