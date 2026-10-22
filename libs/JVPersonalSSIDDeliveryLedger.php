<?php
declare(strict_types=1);

/**
 * Distinguish "source variable updated during THIS uninterrupted observer
 * epoch" from a first-run baseline or an unchanged old String value.
 *
 * IMPORTANT: a Symcon VariableUpdated transition is NOT proof of an
 * authenticated phone POST. An installer, script or administrator could
 * also have assigned that value. Never infer a human or house-empty state.
 *
 * State is RAM-only in the calling module. Reboot/reconfiguration resets it.
 */
final class JVPersonalSSIDDeliveryLedger
{
    public static function evaluate(array $previous,array $work,int $now):array
    {
        $out=[
            'schema'=>1,
            'authority'=>'TWO_PHONE_SSID_VARIABLE_UPDATE_EPOCH_READ_ONLY',
            'people'=>[],
            'next'=>['schema'=>1,'people'=>[],'lastCheckAt'=>$now],
            'sourceUpdatesObservedThisEpoch'=>0,
            'isAuthenticatedPhonePostProof'=>false,
            'isPersonLocationProof'=>false,
            'mayAuthorizeAbsence'=>false,
            'newSymconVariables'=>0
        ];
        $validSource=($work['schema']??null)===1
            &&($work['authority']??null)
                ==='PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT';
        $priorOK=($previous['schema']??null)===1
            &&is_array($previous['people']??null)
            &&is_int($previous['lastCheckAt']??null)
            &&$previous['lastCheckAt']>0
            &&$previous['lastCheckAt']<=$now+5;
        foreach(['johannes','carina'] as $person){
            $row=$validSource&&is_array($work['people'][$person]??null)
                ?$work['people'][$person]:[];
            $key=$row['sourceKey']??null;
            $age=$row['ageSeconds']??null;
            $sourceOK=($row['sourceVerified']??false)===true
                &&is_string($key)
                &&preg_match('/^SSID:[1-9][0-9]{0,9}$/D',$key)===1
                &&is_int($age)&&$age>=0&&$age<=86400
                &&$now>0;
            if(!$sourceOK){
                $out['people'][$person]=[
                    'state'=>'SOURCE_NOT_VERIFIED',
                    'observedUpdatesThisEpoch'=>0,
                    'lastObservedAt'=>null,'sourceChanged'=>false,
                    'notAnAuthenticatedPostCertificate'=>true
                ];
                $out['next']['people'][$person]=null;
                continue;
            }
            $updatedAt=$now-$age;
            $prev=$priorOK&&is_array($previous['people'][$person]??null)
                ?$previous['people'][$person]:null;
            $priorSame=is_array($prev)
                &&($prev['sourceKey']??null)===$key
                &&is_int($prev['lastVariableUpdateAt']??null)
                &&$prev['lastVariableUpdateAt']>0
                &&is_int($prev['observedUpdates']??null)
                &&$prev['observedUpdates']>=0
                &&$prev['observedUpdates']<=1000000;
            $changed=is_array($prev)&&is_string($prev['sourceKey']??null)
                &&$prev['sourceKey']!==$key;
            $count=$priorSame?$prev['observedUpdates']:0;
            $lastObserved=$priorSame&&is_int($prev['lastObservedAt']??null)
                ?$prev['lastObservedAt']:null;
            $checkpoint=$updatedAt;
            $status='BASELINE_NO_NEW_UPDATE_PROVEN';
            if($changed)$status='SOURCE_CHANGED_BASELINE_NO_UPDATE_PROVEN';
            if($priorSame){
                if($updatedAt>$prev['lastVariableUpdateAt']){
                    $count=min(1000000,$count+1);
                    $lastObserved=$now;
                    $status='SOURCE_VARIABLE_UPDATED_THIS_EPOCH';
                }elseif($updatedAt<$prev['lastVariableUpdateAt']){
                    $status='SOURCE_TIMESTAMP_REGRESSED_NO_EVENT_PROOF';
                    // Never interpret lower timestamps as a brand new POST.
                    $checkpoint=$prev['lastVariableUpdateAt'];
                }elseif($age>300){
                    $status='SOURCE_VALUE_UNCHANGED_HEARTBEAT_OVERDUE';
                }else{
                    $status='SOURCE_VALUE_UNCHANGED_STILL_RECENT';
                }
            }
            $out['people'][$person]=[
                'state'=>$status,'observedUpdatesThisEpoch'=>$count,
                'lastObservedAt'=>$lastObserved,
                'sourceChanged'=>$changed,
                'currentValueAgeSeconds'=>$age,
                'notAnAuthenticatedPostCertificate'=>true
            ];
            $out['next']['people'][$person]=[
                'sourceKey'=>$key,
                'lastVariableUpdateAt'=>$checkpoint,
                'observedUpdates'=>$count,
                'lastObservedAt'=>$lastObserved
            ];
            $out['sourceUpdatesObservedThisEpoch']+=$count;
        }
        return $out;
    }
}
