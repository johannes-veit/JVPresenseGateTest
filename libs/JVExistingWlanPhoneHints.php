<?php
declare(strict_types=1);

/**
 * Integrates the ALREADY RUNNING standalone WLAN-Johannes v1.2 and
 * WLAN-Carina v1.5 scripts as read-only device-presence hints.
 *
 * The devices run 5-minute cycles with a 660-second grace period.
 * TRUE with a fresh MAC-confirmed last-contact timestamp is a positive
 * device-near-WLAN hint, NEVER proof that a human is inside.
 * FALSE/STALE/INVALID can NEVER prove human absence.
 */
final class JVExistingWlanPhoneHints
{
    public const GRACE_SECONDS=660;

    public static function evaluate(array $snapshots,int $now,bool $enabled,
        bool $motionHint=false):array
    {
        $report=[
            'schema'=>1,
            'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
            'enabled'=>$enabled,
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'houseEmptyMayBeCertified'=>false,
            'alarmMayBeControlled'=>false,
            'wlanScriptsModified'=>false,
            'wlanCategoriesOrEventsCreated'=>false,
            'readsOnlyExistingValues'=>true,
            'cycleSeconds'=>300,
            'holdSeconds'=>self::GRACE_SECONDS,
            'people'=>[],
            'freshMacConfirmedDeviceCount'=>0,
            'phonesUnavailableOrUnconfirmed'=>0,
            'combinedContext'=>'DISABLED',
            'actualResidentsPresent'=>'UNKNOWN',
            'exactPersonsAtHome'=>'UNKNOWN',
            'warnings'=>[]
        ];
        foreach(['johannes','carina'] as $person){
            $snapshot=is_array($snapshots[$person]??null)
                ?$snapshots[$person]:[];
            $entry=[
                'device'=>$person,
                'state'=>$enabled?'SOURCE_NOT_VERIFIED':'DISABLED',
                'recentMacEvidence'=>false,
                'wlanBoolean'=>null,
                'lastMacContactAt'=>null,
                'lastMacContactAgeSeconds'=>null,
                'validatedExistingVariablePair'=>false,
                'sourceKey'=>null,
                'humanPresentCertified'=>false,
                'humanAbsentCertified'=>false,
                'warnings'=>[]
            ];
            if(!$enabled){$report['people'][$person]=$entry;continue;}
            if(($snapshot['verified']??false)!==true){
                $entry['warnings'][]='VARIABLE_ID_TYPE_NAME_OR_PARENT_NOT_VERIFIED';
            }elseif(!is_bool($snapshot['wlanValue']??null)
                ||!is_int($snapshot['lastContact']??null)){
                $entry['warnings'][]='INVALID_WLAN_VALUE_TYPES';
            }else{
                $entry['validatedExistingVariablePair']=true;
                $bid=$snapshot['boolId']??null;
                $tid=$snapshot['lastContactId']??null;
                if(is_int($bid)&&$bid>0&&is_int($tid)&&$tid>0&&$bid!==$tid)
                    $entry['sourceKey']='WLAN:'.$bid.':'.$tid;
                $entry['wlanBoolean']=$snapshot['wlanValue'];
                $last=$snapshot['lastContact'];
                if($last<=0||$last>$now+5){
                    $entry['state']='UNKNOWN_NO_VALID_CONTACT_TIMESTAMP';
                    $entry['warnings'][]='INVALID_LAST_CONTACT';
                }else{
                    $age=max(0,$now-$last);
                    $entry['lastMacContactAt']=$last;
                    $entry['lastMacContactAgeSeconds']=$age;
                    if($snapshot['wlanValue']===true && $age<=self::GRACE_SECONDS){
                        $entry['state']='MAC_CONFIRMED_RECENT_DEVICE_HINT';
                        $entry['recentMacEvidence']=true;
                        $report['freshMacConfirmedDeviceCount']++;
                    }elseif($snapshot['wlanValue']===true){
                        $entry['state']='STALE_TRUE_CANNOT_CONFIRM_PRESENCE';
                        $entry['warnings'][]='WLAN_TRUE_BUT_CONTACT_STALE';
                    }else{
                        $entry['state']='NO_RECENT_MAC_CONFIRMATION_NOT_ABSENCE';
                    }
                }
            }
            if(!$entry['recentMacEvidence'])$report['phonesUnavailableOrUnconfirmed']++;
            foreach($entry['warnings'] as $warning)
                $report['warnings'][]=$person.':'.$warning;
            $report['people'][$person]=$entry;
        }
        // The same existing WLAN variable pair must never count as two
        // residents, e.g. after an incorrect duplicated Symcon mapping.
        $j=$report['people']['johannes']??[];
        $c=$report['people']['carina']??[];
        if(($j['validatedExistingVariablePair']??false)===true
            &&($c['validatedExistingVariablePair']??false)===true
            &&is_string($j['sourceKey']??null)
            &&$j['sourceKey']===$c['sourceKey']){
            foreach(['johannes','carina'] as $person){
                $row=&$report['people'][$person];
                if($row['recentMacEvidence']){
                    $report['freshMacConfirmedDeviceCount']--;
                    $report['phonesUnavailableOrUnconfirmed']++;
                }
                $row['state']='SOURCE_IDENTITY_COLLISION';
                $row['validatedExistingVariablePair']=false;
                $row['recentMacEvidence']=false;
                $row['sourceKey']=null;
                $row['wlanBoolean']=null;
                $row['lastMacContactAt']=null;
                $row['lastMacContactAgeSeconds']=null;
                $row['warnings'][]='SAME_WLAN_SOURCE_MAPPED_TO_BOTH_RESIDENTS';
                unset($row);
            }
            $report['warnings'][]='BOTH_RESIDENTS_SHARE_ONE_WLAN_SOURCE';
        }
        if($enabled){
            $confirmed=$report['freshMacConfirmedDeviceCount'];
            $report['combinedContext']=$confirmed===2
                ?($motionHint?'HOUSE_MOTION_AND_TWO_WLAN_DEVICES':'TWO_WLAN_DEVICES_ON_NETWORK_HINT')
                :($confirmed===1
                    ?($motionHint?'HOUSE_MOTION_AND_ONE_WLAN_DEVICE':'ONE_WLAN_DEVICE_ON_NETWORK_HINT')
                    :($motionHint?'HOUSE_MOTION_WITHOUT_FRESH_WLAN':'NO_FRESH_WLAN_NOT_ABSENCE'));
        }
        return $report;
    }
}
