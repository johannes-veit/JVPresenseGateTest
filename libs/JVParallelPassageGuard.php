<?php
declare(strict_types=1);

/**
 * Explicit MULTI-PERSON SAFETY BOUNDARY for P03 direction observations.
 *
 * A 2-mast P03 proof represents a verified route direction, NEVER one
 * uniquely tracked person. Two humans walking together can create a single
 * IVS alarm; the same human can also produce repeated IVS alarms.
 *
 * No residents, human count or external perimeter exits may be inferred from
 * an event cluster. This class is pure: no I/O, no IPS writes, no commands.
 */
final class JVParallelPassageGuard
{
    private const SAME_PASSAGE_SECONDS=15;
    private const OBSERVATION_WINDOW=86400;
    private const MAX_GROUPS=24;
    private const DIRECTIONS=['HOME_TO_LAGER','LAGER_TO_HOME'];

    public static function evaluate(array $timeline,array $street,int $now):array
    {
        $result=[
            'schema'=>1,
            'authority'=>'PARALLEL_PERSON_PASSAGE_SHADOW_ONLY',
            'individualPeopleCount'=>'UNKNOWN',
            'individualIdentities'=>'UNKNOWN',
            'countedResidentsLeavingHouse'=>'UNKNOWN',
            'countedResidentsLeavingProperty'=>'UNKNOWN',
            'groupOfTwoMayBeOneIVSProof'=>true,
            'twoIVSProofsMayBeSamePerson'=>true,
            'oneProofDoesNotMeanOneHuman'=>true,
            'independentPerPersonTargetEvidence'=>false,
            'peopleCountingTelemetryConnected'=>false,
            'peopleCountingModeChanged'=>false,
            'personCountingFeatureNotAutomaticallyActivated'=>true,
            'parallelPeopleCoveredForAbsenceCertification'=>false,
            'propertyStreetMappingLearned'=>false,
            'liveStreetPersonExitCountAvailable'=>false,
            'houseToWorkIsPropertyExit'=>false,
            'lastDirectionalGroup'=>null,
            'recentDirectionalGroups'=>[],
            'sameSecondEventAmbiguity'=>false,
            'status'=>'NO_LIVE_PASSAGES',
            'houseEmptyMayBeCertified'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'personPresenceMayBeChanged'=>false,
            'alarmMayBeControlled'=>false,
            'warnings'=>[]
        ];
        if($now<=0
            ||($timeline['authority']??null)!=='P03_LIVE_PROOFS_DIRECTION_ONLY'
            ||($timeline['schema']??null)!==1
            ||!is_array($timeline['recentDirectionProofs']??null)
            ||!is_array($timeline['warnings']??null)){
            $result['status']='INPUT_NOT_TRUSTED';
            $result['warnings'][]='P03_TIMELINE_INVALID';
            return $result;
        }
        $result['propertyStreetMappingLearned']=
            ($street['directionMappingCertified']??false)===true
            && in_array($street['out']??null,
                ['LeftToRight','RightToLeft'],true)
            && in_array($street['in']??null,
                ['LeftToRight','RightToLeft'],true)
            && ($street['out']??null)!==($street['in']??null);
        $result['sameSecondEventAmbiguity']=in_array(
            'P03_SAME_SECOND_PROOF_AMBIGUOUS',
            $timeline['warnings'],true
        );
        // Read only genuine live proof entries from the incremental log.
        // Archived commissioning events are never interpreted as current.
        $events=[];
        foreach(array_slice($timeline['recentDirectionProofs'],0,256) as $e){
            if(!is_array($e)||!in_array(
                $e['direction']??null,self::DIRECTIONS,true)
                ||!is_int($e['at']??null)
                ||$e['at']>$now+5
                ||$e['at']<=$now-self::OBSERVATION_WINDOW
                ||!is_string($e['route']??null)
            )continue;
            $route=$e['route'];
            if(($e['direction']==='HOME_TO_LAGER'
                    && $route!=='P03_2CAM_JV_THEN_WORK')
                ||($e['direction']==='LAGER_TO_HOME'
                    && $route!=='P03_2CAM_WORK_THEN_JV'))continue;
            $key=$e['at'].'|'.$e['direction'].'|'.$route;
            $events[$key]=[
                'at'=>$e['at'],'direction'=>$e['direction'],'route'=>$route
            ];
        }
        $events=array_values($events);
        usort($events,static fn($a,$b)=>($a['at']<=>$b['at'])
            ?:strcmp($a['direction'],$b['direction']));
        // Group nearby proof events only as a TIME WINDOW. The group is
        // neither a list of human identities nor an estimated headcount.
        $clusters=[];
        foreach($events as $e){
            $last=count($clusters)-1;
            if($last>=0
                && $clusters[$last]['direction']===$e['direction']
                && $e['at']-$clusters[$last]['lastAt']
                    <=self::SAME_PASSAGE_SECONDS){
                $clusters[$last]['lastAt']=$e['at'];
                $clusters[$last]['directionProofEvents']++;
            }else{
                $clusters[]=[
                    'direction'=>$e['direction'],
                    'firstAt'=>$e['at'],
                    'lastAt'=>$e['at'],
                    'directionProofEvents'=>1
                ];
            }
        }
        $groups=[];
        foreach(array_reverse($clusters) as $c){
            $c['numberOfPeople']='UNKNOWN';
            $c['exactlyOnePersonProven']=false;
            $c['exactlyTwoPeopleProven']=false;
            $c['groupPassageCannotBeExcluded']=true;
            $c['isPropertyStreetExit']=false;
            $c['evidenceScope']='HOUSE_TO_WORK_OR_WORK_TO_HOUSE_ONLY';
            $c['interpretation']=$c['directionProofEvents']>1
                ?'MULTIPLE_PROOFS_NOT_MULTIPLE_PEOPLE'
                :'ONE_PROOF_CAN_CONTAIN_MULTIPLE_PEOPLE';
            $groups[]=$c;
            if(count($groups)>=self::MAX_GROUPS)break;
        }
        $result['recentDirectionalGroups']=$groups;
        $result['lastDirectionalGroup']=$groups[0]??null;
        if($result['sameSecondEventAmbiguity']){
            $result['warnings'][]='MULTIPLE_PERSONS_MAY_BE_HIDDEN_IN_SAME_SECOND';
        }
        if($groups){
            $result['status']='DIRECTION_KNOWN_PEOPLE_COUNT_UNKNOWN';
            $result['warnings'][]='NO_PER_PERSON_CAMERA_COUNT_IN_P03_PROTOCOL';
            $result['warnings'][]='HOUSE_LAGER_IS_NOT_PROPERTY_EXIT';
        }
        if(($timeline['logContinuity']??'OK')==='LOG_GAP_OR_RESET'){
            $result['warnings'][]='P03_LOG_GAP_OR_RESET';
            $result['status']='EVENT_HISTORY_INCOMPLETE_COUNT_UNKNOWN';
        }
        return $result;
    }
}
