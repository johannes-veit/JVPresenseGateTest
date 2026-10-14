<?php
declare(strict_types=1);

/**
 * Property boundary SHADOW. A gate state, door contact or vehicle signal
 * neither identifies a person nor proves a direction or complete exit.
 * This evaluator never reads hardware or writes Symcon values.
 *
 * Only preselected, locally existing variable identifiers are observed.
 * No assumptions about positive polarity ("ACTIVE" need not mean "OPEN").
 */
final class JVBoundaryEvidence
{
    public const SCHEMA=1;
    private const KINDS=['CONTACT','GATE','VEHICLE'];
    private const MAX_EVENTS=192;
    private const MAX_SOURCES=64;
    private const WINDOW=86400;
    private const FRESH=120;

    public static function evaluate(
        array $readings,array $previous,array $p03,int $now
    ):array {
        $report=[
            'schema'=>self::SCHEMA,
            'authority'=>'READ_ONLY_BOUNDARY_CONTEXT',
            'coverageState'=>'NO_EXIT_SENSORS',
            'assessment'=>'NO_PROPERTY_EXIT_PROOF',
            'doorContacts'=>0,'gateStatuses'=>0,'vehicleSignals'=>0,
            'configuredBoundarySignals'=>0,
            'currentStates'=>[],
            'lastBoundaryChangeAt'=>null,
            'boundaryChanges24h'=>0,
            'recentChanges'=>[],
            'p03DirectionContext'=>null,
            'exitDirectionCertified'=>false,
            'allExitRoutesVerified'=>false,
            'peopleCountCertified'=>false,
            'identityCertified'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'personPresenceMayBeChanged'=>false,
            'alarmMayBeControlled'=>false,
            'unresolved'=>[
                'EXIT_ROUTE_COVERAGE_UNKNOWN',
                'DOOR_GATE_CONTACT_HAS_NO_HUMAN_DIRECTION',
                'MULTIPLE_PEOPLE_AND_VISITORS_UNCOUNTED'
            ],
            'warnings'=>[]
        ];
        if($now<=0) {
            $report['warnings'][]='INVALID_CLOCK';
            return ['report'=>$report,'next'=>$previous];
        }
        if(($p03['authority']??null)!=='P03_LIVE_PROOFS_DIRECTION_ONLY'
            ||($p03['schema']??null)!==1) {
            $report['warnings'][]='P03_SOURCE_INVALID';
        }
        $priorSeen=$previous['seen']??[];
        $priorEvents=$previous['events']??[];
        if(!is_array($priorSeen) || !is_array($priorEvents)
            || !array_is_list($priorEvents)
            || count($priorEvents)>self::MAX_EVENTS) {
            $report['warnings'][]='BAD_PREVIOUS_BUFFER';
            $priorSeen=[];$priorEvents=[];
        }
        $initialized=($previous['initialized']??false)===true;
        $current=[];$newSeen=[];
        $events=[];
        foreach($priorEvents as $event) {
            if(!is_array($event)
                || !is_string($event['sourceID']??null)
                || !is_int($event['at']??null)
                || $event['at']<=$now-self::WINDOW
                || $event['at']>$now+5
                || !in_array($event['kind']??null,self::KINDS,true)
                || !in_array($event['state']??null,['ACTIVE','INACTIVE'],true))
                continue;
            $key=$event['sourceID'].'|'.$event['at'].'|'.$event['state'];
            $events[$key]=$event;
        }
        foreach($readings as $id=>$reading) {
            if(!is_string($id)||!is_array($reading)
                || !in_array(($reading['kind']??null),self::KINDS,true))continue;
            if(count($current)>=self::MAX_SOURCES) {
                $report['warnings'][]='TOO_MANY_BOUNDARY_SOURCES';
                break;
            }
            $kind=$reading['kind'];
            $report['configuredBoundarySignals']++;
            if($kind==='CONTACT')$report['doorContacts']++;
            if($kind==='GATE')$report['gateStatuses']++;
            if($kind==='VEHICLE')$report['vehicleSignals']++;
            $at=$reading['changedAt']??null;
            $state=$reading['state']??null;
            $name=is_string($reading['name']??null)
                ?substr($reading['name'],0,120):$id;
            $valid=is_int($at)&&$at>0&&$at<=$now+5
                && in_array($state,['ACTIVE','INACTIVE'],true);
            $current[$id]=[
                'name'=>$name,'kind'=>$kind,
                'state'=>$valid?$state:'UNKNOWN',
                'changedAt'=>$valid?$at:null
            ];
            if(!$valid) {
                $report['warnings'][]='INVALID_BOUNDARY_SOURCE:'.$id;
                continue;
            }
            $last=$priorSeen[$id]??null;
            $known=is_array($last)
                && is_int($last['at']??null)
                && in_array($last['state']??null,['ACTIVE','INACTIVE'],true);
            $newSeen[$id]=['at'=>$at,'state'=>$state];
            // A newly attached device is always baselined; no invented
            // opening, crossing or vehicle event on installation/update.
            if(!$initialized || !$known)continue;
            if($at<=($last['at']??0)) {
                if($at===($last['at']??0)
                    && $state!==$last['state']) {
                    $report['warnings'][]='STATE_WITHOUT_NEW_TIMESTAMP:'.$id;
                }
                continue;
            }
            // No replay of stale contact transitions on long idle polls.
            if($at<$now-self::FRESH)continue;
            $event=[
                'sourceID'=>$id,
                'name'=>$name,'kind'=>$kind,
                'at'=>$at,'state'=>$state,
                'direction'=>'UNKNOWN',
                'personCount'=>'UNKNOWN'
            ];
            $events[$id.'|'.$at.'|'.$state]=$event;
        }
        // Source IDs removed from the configured list are not retained.
        $events=array_filter($events,static fn($e)=>
            isset($current[$e['sourceID']])
            && $current[$e['sourceID']]['kind']===$e['kind']);
        uasort($events,static fn($a,$b)=>($b['at']<=>$a['at'])
            ?:strcmp($a['sourceID'],$b['sourceID']));
        if(count($events)>self::MAX_EVENTS) {
            $report['warnings'][]='EVENT_CAP_REACHED';
            $events=array_slice($events,0,self::MAX_EVENTS,true);
        }
        $report['currentStates']=$current;
        $report['boundaryChanges24h']=count($events);
        $report['lastBoundaryChangeAt']=count($events)
            ?reset($events)['at']:null;
        $report['recentChanges']=array_slice(array_values($events),0,12);
        $report['coverageState']=$report['configuredBoundarySignals']>0
            ?'PARTIAL_UNVERIFIED':'NO_EXIT_SENSORS';
        $report['assessment']=$report['configuredBoundarySignals']>0
            ?($report['boundaryChanges24h']>0
                ?'BOUNDARY_ACTIVITY_DIRECTION_UNKNOWN'
                :'SENSORS_PRESENT_NO_EXIT_PROOF')
            :'NO_PROPERTY_EXIT_PROOF';
        if($report['configuredBoundarySignals']===0) {
            $report['unresolved'][]='NO_EXISTING_EXIT_VARIABLE_MAPPED';
        }
        if($report['doorContacts']===0) {
            $report['unresolved'][]='NO_DOOR_CONTACT_MAPPED';
        }
        if($report['gateStatuses']===0) {
            $report['unresolved'][]='NO_GATE_STATUS_MAPPED';
        }
        $p03Events=$p03['recentDirectionProofs']??[];
        if(is_array($p03Events)) {
            foreach($p03Events as $event) {
                if(!is_array($event)
                    || !in_array($event['direction']??null,
                        ['HOME_TO_LAGER','LAGER_TO_HOME'],true)
                    || !is_int($event['at']??null)
                    || $event['at']>$now+5
                    || $event['at']<$now-self::WINDOW)continue;
                if($report['p03DirectionContext']===null
                    || $event['at']>$report['p03DirectionContext']['at']) {
                    $report['p03DirectionContext']=[
                        'direction'=>$event['direction'],
                        'at'=>$event['at'],
                        'countsAsPropertyExit'=>false
                    ];
                }
            }
        }
        // Direction of a HOUSE<->WORK transfer cannot make a door status a
        // property exit. Even a correlated gate event does not prove that
        // the same human crossed both, or that others did not stay.
        $report['warnings']=array_values(array_unique($report['warnings']));
        return ['report'=>$report,'next'=>[
            'initialized'=>true,
            'seen'=>$newSeen,
            'events'=>array_values($events)
        ]];
    }
}
