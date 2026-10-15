<?php
declare(strict_types=1);

/**
 * Passive audit of PRE-EXISTING P03 Aux LastIVSEvent string variables from
 * additional IPCs. Each camera's ObjectID is local, events are not people.
 * The LastIVSEvent array's 'time' has NO calendar date: only Symcon's own
 * VariableUpdated is a hint of recent variable updates. Never infer count,
 * identities, road-boundary crossing, house vacancy or whole group passage.
 */
final class JVSupplementaryIpcEvidence
{
    public static function evaluate(array $inputs):array
    {
        $report=[
            'schema'=>1,'authority'=>'ADDITIONAL_IPC_EVENT_HINTS_ONLY',
            'sourceCount'=>0,'readableSourceCount'=>0,
            'freshIvSHumanHints'=>[],
            'camerasWithFreshIvSHumanHint'=>0,
            'supplementaryHumanHint'=>false,
            'humanEventsAreNotUniquePeople'=>true,
            'objectIdsAreNotCrossCameraIds'=>true,
            'eventTimeHasNoVerifiedCalendarDate'=>true,
            'couldBeSamePersonAcrossCameras'=>true,
            'eachCameraMustHaveVerifiedPropertyBoundaryRule'=>true,
            'numberOfPeople'=>'UNKNOWN',
            'groupSize'=>'UNKNOWN','propertyExitCount'=>'UNKNOWN',
            'propertyEmptyMayBeCertified'=>false,
            'personPresenceMayBeChanged'=>false,
            'alarmMayBeControlled'=>false,
            'warnings'=>[]
        ];
        foreach(array_slice($inputs,0,16) as $x){
            if(!is_array($x)
                ||!is_string($x['ip']??null)
                ||!is_int($x['ageSeconds']??null)
                ||!is_string($x['raw']??null))continue;
            $report['sourceCount']++;
            $ip=$x['ip'];$age=$x['ageSeconds'];$raw=$x['raw'];
            if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)
                ||!str_starts_with($ip,'192.168.107.')
                ||$age<0||$age>180||strlen($raw)>8192){
                $report['warnings'][]='STALE_OR_INVALID_SOURCE';continue;
            }
            $decoded=json_decode($raw,true);
            if(!is_array($decoded)||!array_is_list($decoded)){
                $report['warnings'][]='INVALID_P03_IVS_AUDIT_ARRAY';continue;
            }
            $report['readableSourceCount']++;
            $found=false;
            foreach(array_slice($decoded,-8) as $event){
                if(!is_array($event)
                    ||($event['code']??null)!=='CrossLineDetection'
                    ||!in_array(strtolower((string)($event['action']??'')),
                        ['start','on','pulse'],true)
                    ||($event['decision']??null)!=='STAIR_HUMAN_START_COUNTED'
                    ||($event['rejectionReason']??null)!=='MATCHED_FILTER'
                )continue;
                $human=($event['payloadHuman']??false)===true
                    ||in_array(strtolower((string)($event['classification']??'')),
                        ['human','person','pedestrian'],true);
                if(!$human)continue;
                // Existing P03 Aux LastIVSEvent saves only HH:MM:SS. An
                // unrelated later camera event can update that array. Only
                // trust a Human row if its HH:MM:SS is synchronized with
                // the actual Symcon variable update time (same observer
                // system clock, 10s tolerance). Still not person identity.
                $updatedAt=$x['updatedAt']??null;
                $eventClock=$event['time']??null;
                if(!is_int($updatedAt)||$updatedAt<=0
                    ||!is_string($eventClock)
                    ||!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])$/D',
                        $eventClock,$mt))continue;
                $eventSeconds=(int)$mt[1]*3600+(int)$mt[2]*60+(int)$mt[3];
                $updatedSeconds=(int)date('G',$updatedAt)*3600
                    +(int)date('i',$updatedAt)*60+(int)date('s',$updatedAt);
                $delta=abs($eventSeconds-$updatedSeconds);
                if(min($delta,86400-$delta)>10)continue;
                $found=true;break;
            }
            if($found)$report['freshIvSHumanHints'][]=[
                'ip'=>$ip,'updatedAgeSeconds'=>$age,
                'proof'=>'LOCAL_CAMERA_IVS_HUMAN_EVENT_AUDIT_ONLY',
                'humanEventDateUnverified'=>true,
                'isKnownStreetCrossing'=>false,
                'countedPeople'=>'UNKNOWN',
                'cameraObjectIdentityCrossVerified'=>false
            ];
        }
        $report['camerasWithFreshIvSHumanHint']=count($report['freshIvSHumanHints']);
        $report['supplementaryHumanHint']=$report['camerasWithFreshIvSHumanHint']>0;
        if($report['supplementaryHumanHint'])
            $report['warnings'][]='CAMERA_EVENT_HINT_NOT_PEOPLE_COUNT_OR_STREET_PROOF';
        $report['warnings']=array_values(array_unique($report['warnings']));
        return $report;
    }
}
