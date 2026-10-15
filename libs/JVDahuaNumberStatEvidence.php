<?php
declare(strict_types=1);

/**
 * Optional READ-ONLY diagnostic adapter for an already existing Symcon
 * STRING containing a camera's CGI NumberStat event. This is NOT enabled
 * by merely having a NumberStat1 rule configured in camera metadata.
 *
 * Dahua HTTP API describes NumberStat Event: Number, EnteredNumber,
 * ExitedNumber, Type=EnterOver/ExitOver/InsideOver. Event is emitted when a
 * counting threshold triggers, not necessarily for EACH individual person.
 * Do not infer new crossings from cumulative totals or repeated events.
 */
final class JVDahuaNumberStatEvidence
{
    public static function decode(?string $input,int $ageSeconds):array
    {
        $o=[
            'schema'=>1,'authority'=>'DAHUA_NUMBERSTAT_DIAGNOSTIC_ONLY',
            'status'=>'NOT_CONNECTED',
            'sourceMessage'=>'UNKNOWN',
            'enteredTotal'=>null,'exitedTotal'=>null,'insideTotal'=>null,
            'thresholdType'=>null,'isLiveEventPerPerson'=>false,
            'counterDeltaIsNumberOfUniquePersons'=>false,
            'countingSmartPlanVerified'=>false,
            'countsPropertyExit'=>false,
            'peopleCount'=>'UNKNOWN','identity'=>'UNKNOWN',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'alarmMayBeControlled'=>false,
            'warnings'=>[]
        ];
        if($input===null||$input==='')return $o;
        if(strlen($input)>8192){
            $o['status']='UNTRUSTED_DATA';
            $o['warnings'][]='SOURCE_TOO_LARGE';return $o;
        }
        if($ageSeconds<0||$ageSeconds>120){
            $o['status']='STALE_TELEMETRY';
            $o['warnings'][]='NO_RECENT_NUMBERSTAT_EVENT';return $o;
        }
        $raw=trim($input);
        if(!preg_match('/^Code\s*=\s*NumberStat\s*;\s*action\s*=\s*(?:Start|pulse)\s*;\s*index\s*=\s*\d+\s*;\s*data\s*=\s*(\{.*\})$/isD',$raw,$m)){
            $o['status']='UNRECOGNIZED_MESSAGE';
            $o['warnings'][]='NOT_NUMBERSTAT_EVENT';return $o;
        }
        $data=json_decode($m[1],true);
        if(!is_array($data)){
            $o['status']='UNTRUSTED_DATA';
            $o['warnings'][]='INVALID_JSON';return $o;
        }
        foreach([
            'EnteredNumber'=>'enteredTotal',
            'ExitedNumber'=>'exitedTotal',
            'Number'=>'insideTotal'
        ] as $field=>$out){
            if(!is_int($data[$field]??null)||$data[$field]<0
                ||$data[$field]>1000000000){
                $o['status']='UNTRUSTED_DATA';
                $o['warnings'][]='INVALID_NUMBERSTAT_COUNTERS';return $o;
            }
            $o[$out]=$data[$field];
        }
        if(!in_array($data['Type']??null,['EnterOver','ExitOver','InsideOver'],true)){
            $o['status']='UNTRUSTED_DATA';
            $o['warnings'][]='INVALID_NUMBERSTAT_TYPE';return $o;
        }
        $o['status']='THRESHOLD_COUNTER_SNAPSHOT_ONLY';
        $o['sourceMessage']='NumberStat';
        $o['thresholdType']=$data['Type'];
        $o['warnings'][]='COUNTING_EVENT_IS_NOT_SINGLE_PERSON_PROOF';
        return $o;
    }
}
