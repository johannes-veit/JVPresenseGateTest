<?php
declare(strict_types=1);

/**
 * Short, passive commissioning summary for the REAL SSID-POST path and
 * strictly manual P03 proof gate. Data is verified in upstream modules.
 *
 * No HTTP request, no smartphone command, no secret lookup, no P03 write,
 * no resident identification, no automatic alarm/absence authority.
 */
final class JVResidentSourceCommissioning
{
    public static function evaluate(array $work,array $operational,array $hook):array
    {
        $out=[
            'schema'=>1,'authority'=>'READ_ONLY_SOURCE_DELIVERY_AND_P03_NEXT_ACTION',
            'ssidHook'=>'UNKNOWN',
            'ssidPeople'=>[],
            'sharedSourceUpdateSecond'=>false,
            'bothPhoneReportsStale'=>false,
            'sourceFreshnessSeconds'=>300,
            'ssidAction'=>'VERIFY_HOOK_AND_SMARTPHONE_POSTS',
            'p03Actions'=>[],
            'p03ProductionMayBeEnabledByShadow'=>false,
            'mayChangeDevices'=>false,'mayAuthorizeHumanAbsence'=>false,
            'mayArmAlarm'=>false,'newSymconVariables'=>0
        ];
        $authorized=($work['schema']??null)===1
            &&($work['authority']??null)==='PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT';
        $hookStatus=(string)($hook['status']??'UNKNOWN');
        if(($hook['authority']??null)==='LOCAL_WIFI_BRIDGE_HOOK_METADATA_READ_ONLY'
            &&in_array($hookStatus,[
                'HOOK_ASSIGNED_VALID_SCRIPT','HOOK_NOT_REGISTERED',
                'HOOK_TARGET_INVALID','HOOK_CONFLICT','HOOK_UNVERIFIABLE'
            ],true))
            $out['ssidHook']=$hookStatus;
        $validAges=[];
        foreach(['johannes','carina'] as $person){
            $row=$authorized&&is_array($work['people'][$person]??null)
                ?$work['people'][$person]:[];
            $age=$row['ageSeconds']??null;
            $sourceOk=($row['sourceVerified']??false)===true
                &&is_string($row['sourceKey']??null)
                &&preg_match('/^SSID:[1-9][0-9]*$/D',$row['sourceKey'])===1;
            $ageValid=is_int($age)&&$age>=0&&$age<=86400;
            $fresh=$sourceOk&&$ageValid&&$age<=300
                &&in_array(($row['status']??null),[
                    'WORK_FIRMEN_WLAN_PHONE','HOME_SSID_PHONE_HINT',
                    'WORK_OR_PRIVATE_PROPERTY_EXT_UNCERTAIN',
                    'OTHER_OR_UNKNOWN_SSID'
                ],true);
            $code=(!$sourceOk)
                ?'PERSONAL_SOURCE_MISSING_OR_REJECTED'
                :(!$ageValid?'PERSONAL_SOURCE_TIMESTAMP_INVALID'
                    :(!$fresh?($age>300?'PHONE_POST_NO_LONGER_FRESH'
                        :'PHONE_SSID_VALUE_NOT_CONFIRMED')
                        :'PHONE_CURRENT_SSID_RECEIVED'));
            $out['ssidPeople'][$person]=[
                'sourceAccepted'=>$sourceOk,
                'latestUpdateAgeSeconds'=>$ageValid?$age:null,
                'freshPhoneUpdate'=>$fresh,
                'code'=>$code,
                'isLiveHumanProof'=>false
            ];
            if($sourceOk&&$ageValid)$validAges[$person]=$age;
        }
        $bothStale=count($validAges)===2
            &&$validAges['johannes']>300&&$validAges['carina']>300;
        $out['bothPhoneReportsStale']=$bothStale;
        // This is only a timing COINCIDENCE. Never claim it proves
        // a common sender, a missing phone, or bridge setup failure.
        $out['sharedSourceUpdateSecond']=$bothStale
            &&$validAges['johannes']===$validAges['carina'];
        if($out['ssidHook']==='HOOK_NOT_REGISTERED'){
            $out['ssidAction']='VERIFY_WEBHOOK_MAPPING_TO_EXISTING_SCRIPT';
        }elseif($out['ssidHook']==='HOOK_CONFLICT'
            ||$out['ssidHook']==='HOOK_TARGET_INVALID'){
            $out['ssidAction']='RESOLVE_WEBHOOK_TARGET_WITHOUT_OVERWRITING_FOREIGN_HOOK';
        }elseif($bothStale){
            $out['ssidAction']='CHECK_BOTH_PHONE_AUTOMATIONS_AND_ACCEPTED_POST_RESPONSE';
        }elseif(!$out['ssidPeople']['johannes']['freshPhoneUpdate']
            ||!$out['ssidPeople']['carina']['freshPhoneUpdate']){
            $out['ssidAction']='CHECK_INDIVIDUAL_PHONE_POST_AND_FRESH_SSID';
        }else{
            $out['ssidAction']='BOTH_PERSONAL_SSID_POSTS_CURRENT';
        }

        $p=($operational['authority']??null)==='SOURCE_COMMISSIONING_READ_ONLY'
            &&is_array($operational['p03']??null)
            ?$operational['p03']:[];
        $pre=is_array($p['productionPreflight']??null)
            ?$p['productionPreflight']:[];
        if(($pre['verifiedFromFreshDiagnostic']??false)!==true){
            $out['p03Actions']=['READ_CURRENT_P03_MAIN_PREFLIGHT_DIAGNOSTIC'];
        }else{
            // Only evaluate *stored* commissioning prerequisites; this
            // cannot physically perform the required mast traversals.
            if(($pre['auditPassed']??false)!==true)
                $out['p03Actions'][]='P03_AUDIT_MAPPING_NOT_VERIFIED_CHECK_READ_ONLY_AUDIT';
            if(($pre['simulationPassed']??false)!==true)
                $out['p03Actions'][]='P03_SIMULATION_REQUIRED';
            $count=$pre['homeToLagerCount']??null;
            if(!is_int($count)||$count<2)
                $out['p03Actions'][]='P03_HOME_TO_LAGER_NEEDS_'.max(0,2-(is_int($count)?$count:0));
            $count=$pre['lagerToHomeCount']??null;
            if(!is_int($count)||$count<2)
                $out['p03Actions'][]='P03_LAGER_TO_HOME_NEEDS_'.max(0,2-(is_int($count)?$count:0));
            if(($pre['counterSourcesReady']??false)!==true)
                $out['p03Actions'][]='P03_TWO_AUX_COUNTER_SOURCES_REQUIRED';
            if(($pre['socketAndMastSourcesReady']??false)!==true)
                $out['p03Actions'][]='P03_BOTH_MAST_SOCKETS_REQUIRED';
            if($out['p03Actions']===[]){
                $out['p03Actions'][]=
                    ($p['operatingMode']??'')==='PRODUCTION_ACTIVE'
                    ?'P03_PRODUCTION_RUNNING_AWAIT_NEW_ROUTE'
                    :'P03_MANUAL_ENABLE_ONLY_AFTER_USER_APPROVAL';
            }
        }
        return $out;
    }
}
