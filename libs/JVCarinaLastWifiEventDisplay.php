<?php
declare(strict_types=1);

/**
 * Carina iOS Shortcut: event based, not periodic heartbeats.
 *
 * The last valid personal SSID *change report* can be displayed as a
 * labelled last-observed handset WLAN until a newer contradicting event.
 * It must never be promoted to a fresh work/home proof, human location,
 * occupancy or security action. Johannes continues the 4-min heartbeat.
 */
final class JVCarinaLastWifiEventDisplay
{
    public static function evaluate(array $work,array $resident,array $geo,int $now):array
    {
        $out=[
            'schema'=>1,
            'authority'=>'CARINA_IOS_LAST_WIFI_EVENT_DISPLAY_ONLY',
            'status'=>'NOT_AVAILABLE','zone'=>null,
            'eventAt'=>null,'eventAgeSeconds'=>null,
            'reason'=>'NO_VERIFIED_PERSONAL_WIFI_CHANGE_EVENT',
            'mayOverrideCurrentWLAN'=>false,
            'mayChangeOccupancy'=>false,
            'mayAuthorizeAbsence'=>false,
            'mayArmAlarm'=>false,
            'isContinuousHeartbeat'=>false,
            'isCurrentDeviceLocationProof'=>false,
            'newSymconObjects'=>0
        ];
        if($now<=0)return $out;
        if(($work['schema']??null)!==1
            ||($work['authority']??null)
                !=='PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT'
            ||($work['enabled']??false)!==true
            ||($resident['schema']??null)!==1
            ||($resident['authority']??null)
                !=='RESIDENT_PHONE_VISU_HINTS_READ_ONLY')
            return $out;
        $row=$work['people']['carina']??null;
        $p=$resident['people']['carina']??null;
        if(!is_array($row)||!is_array($p)
            ||($row['sourceVerified']??false)!==true
            ||!is_string($row['sourceKey']??null)
            ||preg_match('/^SSID:[1-9][0-9]*$/D',$row['sourceKey'])!==1
            ||($row['reportedSSIDKind']??null)!=='NONEMPTY_OBSERVED'
            ||!in_array(($row['lastWifiChangeZone']??null),['HOME','WORK'],true)
            ||!is_int($row['lastWifiChangeAt']??null)
            ||!is_int($row['sourceUpdatedAt']??null)
            ||$row['lastWifiChangeAt']!==$row['sourceUpdatedAt']
            ||$row['lastWifiChangeAt']<=0
            ||$row['lastWifiChangeAt']>$now+5){
            return $out;
        }
        $at=$row['lastWifiChangeAt'];
        $zone=$row['lastWifiChangeZone'];
        $out['zone']=$zone;
        $out['eventAt']=$at;
        $out['eventAgeSeconds']=max(0,$now-$at);
        $out['status']='LAST_EVENT_SUPERSEDED_OR_ALREADY_LIVE';
        $out['reason']='RECENT_OR_CONTRADICTING_EVIDENCE_TAKES_PRIORITY';
        // A current MAC or a fresh SSID is ALWAYS evaluated first in
        // the normal state machine and should never be replaced here.
        if(!in_array(($p['decision']??null),[
            'UNKNOWN','LAST_KNOWN_UNCONFIRMED'
        ],true) ||($p['wifiConflict']??false)===true
            ||($p['deviceZone']??null)!=='UNKNOWN')return $out;
        // An explicit later Geofency OUT supersedes a historical home/work
        // SSID even if the OUT itself has aged out of the "recent" window.
        // This is a comparison of observed handset EVENTS, not people.
        if(($geo['schema']??null)===1
            &&($geo['authority']??null)
                ==='EXISTING_CARINA_GEOFENCY_READ_ONLY'
            &&($geo['enabled']??false)===true
            &&($geo['validatedSource']??false)===true
            &&($geo['geofenceReportedInside']??null)===false
            &&is_int($geo['lastEventAt']??null)
            &&$geo['lastEventAt']>$at
            &&$geo['lastEventAt']<=$now+5){
            $out['status']='SUPERSEDED_BY_NEWER_GEOFENCE_OUT';
            $out['reason']='LAST_IPHONE_GEOFENCE_OUT_AFTER_WIFI_CHANGE';
            return $out;
        }
        // A verified newer home MAC observation also overrides a previously
        // reported firm WLAN, even after that MAC observation has expired.
        $lastZone=$p['lastKnownDeviceZone']??null;
        $lastAt=$p['lastKnownDeviceAt']??null;
        if(in_array($lastZone,['HOME','WORK'],true)
            &&$lastZone!==$zone
            &&is_int($lastAt)&&$lastAt>$at&&$lastAt<=$now+5){
            $out['status']='SUPERSEDED_BY_NEWER_OPPOSITE_WIFI';
            $out['reason']='NEWER_VERIFIED_WLAN_OVERRIDES_LAST_EVENT';
            return $out;
        }
        if($zone==='WORK'){
            $home=$p['homeMacAt']??null;
            if(is_int($home)&&$home>$at&&$home<=$now+5){
                $out['status']='SUPERSEDED_BY_NEWER_HOME_MAC';
                return $out;
            }
        }
        $out['status']=$zone==='HOME'
            ?'LAST_WIFI_EVENT_HOME':'LAST_WIFI_EVENT_WORK';
        $out['reason']='IOS_WIFI_CHANGE_EVENT_NOT_PERIODIC_HEARTBEAT';
        return $out;
    }
}
