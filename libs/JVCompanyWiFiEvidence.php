<?php
declare(strict_types=1);

/**
 * Two independently validated handset-reported CURRENT Wi-Fi SSIDs.
 *
 * Unlike MAC/IP reachability or an oversized shared HOME+WORK geofence,
 * a recent exact SSID report can distinguish the work LAN from the home
 * WLAN. This class NEVER discovers the SSID by scanning the network:
 * a current phone-specific String variable must first EXIST in Symcon
 * and be refreshed by a reliable smartphone/controller source.
 *
 * "Privat-Veit-EXT" reaches the private outer property; therefore it
 * is explicitly NOT a confirmed WORK location.
 */
final class JVCompanyWiFiEvidence
{
    public const MAX_AGE=900;

    public static function evaluate(array $snapshots,int $now,bool $enabled):array
    {
        $out=[
            'schema'=>1,
            'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
            'enabled'=>$enabled,
            'people'=>[],
            'workPhoneCount'=>0,
            'noAPOrPhoneSettingsChanged'=>true,
            'sourceIsRequiredForLiveWork'=>true,
            'workPhoneIsNotHumanIdentification'=>true,
            'workNeverCertifiesHomeEmpty'=>true,
            'mayChangeExternalPresence'=>false,
            'mayArmAlarm'=>false,
            'warnings'=>[]
        ];
        foreach(['johannes','carina'] as $person) {
            $row=$snapshots[$person]??null;
            $entry=[
                'status'=>$enabled?'NOT_CONFIGURED_OR_UNVERIFIED':'DISABLED',
                'ssid'=>null,'ageSeconds'=>null,
                'workRecent'=>false,'homeSsidRecent'=>false,
                'boundarySSIDOnly'=>false,'sourceVerified'=>false,
                'sourceKey'=>null
            ];
            if(!$enabled){$out['people'][$person]=$entry;continue;}
            if(!is_array($row)
                ||($row['verified']??false)!==true
                ||!is_string($row['ssid']??null)
                ||!is_int($row['updatedAt']??null)) {
                $out['people'][$person]=$entry;continue;
            }
            $entry['sourceVerified']=true;
            // The same Symcon String ID cannot identify two separate
            // resident smartphones. Keep the exact existing object ID
            // out of public reports except for a non-sensitive source key.
            $id=$row['sourceVariableID']??null;
            if(is_int($id)&&$id>0)
                $entry['sourceKey']='SSID:'.$id;
            $at=$row['updatedAt'];
            if($now<=0||$at<=0||$at>$now+5) {
                $entry['status']='INVALID_UPDATE_TIME';
                $out['people'][$person]=$entry;continue;
            }
            $age=max(0,$now-$at);
            $entry['ageSeconds']=$age;
            if($age>self::MAX_AGE) {
                $entry['status']='STALE_CURRENT_SSID_UNCONFIRMED';
                $out['people'][$person]=$entry;continue;
            }
            $ssid=trim($row['ssid']);
            if($ssid===''||strlen($ssid)>64){
                $entry['status']='INVALID_CURRENT_SSID';
                $out['people'][$person]=$entry;continue;
            }
            $entry['ssid']=$ssid;
            // SSIDs are exact case-sensitive names, NOT friendly aliases.
            if(in_array($ssid,['Intern-Veit','Privat-Veit'],true)){
                $entry['status']='WORK_FIRMEN_WLAN_PHONE';
                $entry['workRecent']=true;
                $out['workPhoneCount']++;
            }elseif($ssid==='Privat-Veit-EXT'){
                // Work radio coverage overlaps private property.
                $entry['status']='WORK_OR_PRIVATE_PROPERTY_EXT_UNCERTAIN';
                $entry['boundarySSIDOnly']=true;
            }elseif(in_array($ssid,['Veit-FilzenerWeg','filzenerweg'],true)){
                $entry['status']='HOME_SSID_PHONE_HINT';
                $entry['homeSsidRecent']=true;
            }else{
                $entry['status']='OTHER_OR_UNKNOWN_SSID';
            }
            $out['people'][$person]=$entry;
        }
        return self::rejectSharedSource($out);
    }

    /**
     * After manual and automatic SSID discovery are merged, repeat source
     * identity checking on the FINAL pair. This routine is pure, and also
     * protects raw evaluate() from a duplicated source object.
     */
    public static function rejectSharedSource(array $out):array
    {
        $j=$out['people']['johannes']??[];
        $c=$out['people']['carina']??[];
        if(($j['sourceVerified']??false)===true
            &&($c['sourceVerified']??false)===true
            &&is_string($j['sourceKey']??null)
            &&$j['sourceKey']===$c['sourceKey']){
            foreach(['johannes','carina'] as $person){
                $row=&$out['people'][$person];
                $row['sourceVerified']=false;
                $row['status']='SHARED_SSID_VARIABLE_SOURCE_COLLISION';
                $row['ssid']=null;
                $row['workRecent']=false;
                $row['homeSsidRecent']=false;
                $row['boundarySSIDOnly']=false;
                $row['sourceKey']=null;
                unset($row);
            }
            // Recalculate the count rather than subtracting an obsolete
            // pre-merge value from a manually overridden source.
            $out['workPhoneCount']=0;
            $out['warnings'][]='BOTH_PHONES_SHARE_ONE_SSID_VARIABLE_ID';
        }
        return $out;
    }
}
