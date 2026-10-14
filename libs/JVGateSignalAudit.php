<?php
declare(strict_types=1);

/**
 * Passive snapshot/edge recorder for EXPLICITLY inspected Symcon variable IDs.
 * It never drives a gate, binds sources, certifies a limit switch, or infers
 * a person's movement. Source metadata and value freshness are mandatory.
 */
final class JVGateSignalAudit
{
    private const MAX_SOURCES=16;
    private const MAX_EVENTS=48;
    private const WINDOW=86400;
    private const MAX_EDGE_AGE=120;

    public static function evaluate(array $candidates,array $snapshots,
        array $prior,int $now):array
    {
        $report=[
            'schema'=>1,
            'authority'=>'READ_ONLY_GATE_SIGNAL_SNAPSHOTS',
            'scope'=>'EXISTING_SCHIEBETOR_LOGIK_VARIABLES_ONLY',
            'observedAt'=>$now,
            'signalCount'=>0,
            'readableSignals'=>0,
            'timedSignals'=>0,
            'untimedSignals'=>0,
            'signals'=>[],
            'newStatusEdgesThisRead'=>0,
            'recentStatusEdges'=>[],
            'warningCodes'=>[],
            'polarityVerified'=>false,
            'gateOpenOrClosedCertified'=>false,
            'humanDirectionCertified'=>false,
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'alarmMayBeControlled'=>false,
            'gateMayBeControlled'=>false
        ];
        if($now<=0) {
            $report['warningCodes'][]='INVALID_CLOCK';
            return ['report'=>$report,'next'=>$prior];
        }
        $seen=$prior['seen']??[];
        $events=$prior['events']??[];
        if(!is_array($seen) || !is_array($events) || !array_is_list($events)) {
            $seen=[];$events=[];
            $report['warningCodes'][]='INVALID_PRIOR_BUFFER';
        }
        $events=array_values(array_filter($events,static function($event)use($now):bool{
            return is_array($event) && is_int($event['at']??null)
                && $event['at']>$now-self::WINDOW && $event['at']<=$now+5
                && is_int($event['variableId']??null)
                && is_bool($event['value']??null);
        }));
        $used=[];$nextSeen=[];$monitored=[];
        foreach($candidates as $entry) {
            if(count($monitored)>=self::MAX_SOURCES) {
                $report['warningCodes'][]='SOURCE_CAP_REACHED';
                break;
            }
            if(!is_array($entry)
                || !is_int($entry['variableId']??null)
                || ($entry['variableId']??0)<=0
                || ($entry['type']??null)!=='Boolean'
                || ($entry['grandparent']??null)!=='Schiebetor_Logik'
                || !is_string($entry['name']??null)
                || !is_string($entry['parent']??null))continue;
            $id=$entry['variableId'];
            if(isset($used[$id]))continue;
            $used[$id]=true;
            $monitored[$id]=true;
            $report['signalCount']++;
            $snapshot=$snapshots[$id]??null;
            // VariableChanged means last VALUE CHANGE, not last update.
            // It may be zero for an otherwise readable Boolean that has not
            // changed since creation. Show that raw value, but never treat it
            // as a timed edge or a certified live physical gate signal.
            $raw=is_array($snapshot)
                && ($snapshot['type']??null)===0
                && is_bool($snapshot['value']??null);
            $changed=$snapshot['changedAt']??null;
            $timed=$raw && is_int($changed)
                && $changed>0 && $changed<=$now+5;
            $untimed=$raw && ($changed===null || $changed===0);
            $valid=$timed||$untimed;
            $readState=$timed?'OK':($untimed?'VALUE_ONLY_UNTIMED':'UNKNOWN');
            $value=$valid?$snapshot['value']:null;
            $at=$timed?$changed:null;
            $updated=$snapshot['updatedAt']??null;
            if(!is_int($updated)||$updated<=0||$updated>$now+5)
                $updated=null;
            $reason=is_array($snapshot)&&is_string($snapshot['errorCode']??null)
                ?$snapshot['errorCode']:($untimed?'NO_CHANGE_TIMESTAMP':
                    ($timed?'NONE':'READ_OR_METADATA_INVALID'));
            $report['signals'][]=[
                'variableId'=>$id,
                'name'=>$entry['name'],
                'parent'=>$entry['parent'],
                'currentValue'=>$value,
                'variableChangedAt'=>$at,
                'variableUpdatedAt'=>$updated,
                'secondsSinceValueChanged'=>$at!==null?max(0,$now-$at):null,
                'secondsSinceLastUpdate'=>$updated!==null?max(0,$now-$updated):null,
                'readState'=>$readState,
                'diagnosticCode'=>$reason,
                'physicalFunctionConfirmed'=>false,
                'trueMeansOpenOrClosed'=>'UNKNOWN'
            ];
            if(!$valid) {
                $report['warningCodes'][]='SOURCE_UNREADABLE:'.$id;
                continue;
            }
            $report['readableSignals']++;
            if($untimed) {
                $report['untimedSignals']++;
                $report['warningCodes'][]='VALUE_UNTIMED:'.$id;
                // Clear any previous seen state. On the next legitimate
                // timestamp, use it only as baseline, not a new movement.
                continue;
            }
            $report['timedSignals']++;
            $last=$seen[$id]??null;
            $nextSeen[$id]=['at'=>$at,'value'=>$value];
            // One physical input can be exported through several alias IDs:
            // each ID is shown separately; NEVER translate edge count to people.
            if(!is_array($last)
                || !is_bool($last['value']??null)
                || !is_int($last['at']??null))continue;
            if($at<=($last['at']??0)) {
                if($at===$last['at'] && $value!==$last['value'])
                    $report['warningCodes'][]='VALUE_WITHOUT_NEW_TIMESTAMP:'.$id;
                continue;
            }
            if($at<$now-self::MAX_EDGE_AGE)continue;
            $events[]=[
                'variableId'=>$id,
                'name'=>$entry['name'],
                'at'=>$at,'value'=>$value,
                'physicalFunctionConfirmed'=>false,
                'humanDirection'=>'UNKNOWN'
            ];
            $report['newStatusEdgesThisRead']++;
        }
        // Drop historical edges for IDs no longer in the selected candidate set.
        $events=array_values(array_filter($events,static fn($e)=>
            isset($monitored[$e['variableId']??0])));
        $dedup=[];
        foreach($events as $evt)
            $dedup[$evt['variableId'].'|'.$evt['at'].'|'.($evt['value']?'1':'0')]=$evt;
        $events=array_values($dedup);
        usort($events,static fn($a,$b)=>
            ($b['at']<=>$a['at'])?:($a['variableId']<=>$b['variableId']));
        if(count($events)>self::MAX_EVENTS) {
            $report['warningCodes'][]='EVENT_CAP_REACHED';
            $events=array_slice($events,0,self::MAX_EVENTS);
        }
        $report['recentStatusEdges']=array_slice($events,0,16);
        $report['warningCodes']=array_values(array_unique($report['warningCodes']));
        return ['report'=>$report,'next'=>[
            'active'=>true,
            'seen'=>$nextSeen,
            'events'=>$events
        ]];
    }
}
