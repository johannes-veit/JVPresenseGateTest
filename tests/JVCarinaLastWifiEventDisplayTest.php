<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVCompanyWiFiEvidence.php';
require_once dirname(__DIR__).'/libs/JVCarinaLastWifiEventDisplay.php';
require_once dirname(__DIR__).'/libs/JVResidentTileDashboard.php';
require_once dirname(__DIR__).'/libs/JVPresenceOperationalReadiness.php';
require_once dirname(__DIR__).'/libs/JVResidentSourceCommissioning.php';
function checkCarina(bool $ok,string $reason):void{
    if(!$ok)throw new RuntimeException('CARINA_EVENT_ONLY '.$reason);
}
$now=1800000000;
$raw=[
    'johannes'=>['verified'=>true,'ssid'=>'Veit-FilzenerWeg',
        'updatedAt'=>$now-70,'sourceVariableID'=>21780],
    'carina'=>['verified'=>true,'ssid'=>'Privat-Veit',
        'updatedAt'=>$now-21600,'sourceVariableID'=>42876]
];
$work=JVCompanyWiFiEvidence::evaluate($raw,$now,true);
checkCarina($work['people']['carina']['status']==='STALE_CURRENT_SSID_UNCONFIRMED'
    &&$work['people']['carina']['workRecent']===false
    &&$work['workPhoneCount']===0
    &&$work['people']['carina']['lastWifiChangeZone']==='WORK'
    &&$work['people']['carina']['lastWifiChangeAt']===$now-21600,
    'iPhone last work zone kept only in separate event field, not fresh work count');
checkCarina($work['people']['johannes']['homeSsidRecent']===true
    &&$work['people']['johannes']['lastWifiChangeZone']===null,
    'Johannes retains periodic 4-minute heartbeat and does not use event hold');
$oper=JVPresenceOperationalReadiness::evaluate($work,[],[],$now);
checkCarina(($oper['ssid']['carina']['reason']??null)
    ==='IOS_CHANGE_ONLY_LAST_WIFI_EVENT_NOT_HEARTBEAT'
    &&($oper['ssid']['carina']['currentWithinFiveMinutes']??true)===false
    &&!in_array('CARINA:CHECK_PHONE_REPEATED_LIVE_SSID_POST',
        $oper['actions']??[],true),
    'iOS change-only WLAN source is not repeatedly marked as missing heartbeat');
$commission=JVResidentSourceCommissioning::evaluate($work,[],
    ['authority'=>'LOCAL_WIFI_BRIDGE_HOOK_METADATA_READ_ONLY',
     'status'=>'HOOK_ASSIGNED_VALID_SCRIPT']);
checkCarina($commission['ssidAction']
    ==='CARINA_IOS_WLAN_CHANGE_ONLY_NO_PERIODIC_POST_EXPECTED'
    &&($commission['ssidPeople']['carina']['iosChangeOnlyLastEvent']??false)===true
    &&($commission['ssidPeople']['carina']['freshPhoneUpdate']??true)===false,
    'commissioning knows event-only iPhone has last SSID without current live post');
$visu=['schema'=>1,'authority'=>'RESIDENT_PHONE_VISU_HINTS_READ_ONLY',
    'people'=>[
        'johannes'=>[
            'decision'=>'HOME_PHONE_CONFIRMED','deviceZone'=>'HOME',
            'at'=>$now-70,'lastKnownDeviceZone'=>'HOME','lastKnownDeviceAt'=>$now-70
        ],
        'carina'=>[
            'decision'=>'LAST_KNOWN_UNCONFIRMED','deviceZone'=>'UNKNOWN',
            'wifiConflict'=>false,'lastKnownDeviceZone'=>'WORK',
            'lastKnownDeviceAt'=>$now-21600,'homeMacAt'=>null
        ]
    ]];
$geo=['schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,'geofenceReportedInside'=>true,
    'lastEventAt'=>$now-30000];
$event=JVCarinaLastWifiEventDisplay::evaluate($work,$visu,$geo,$now);
checkCarina($event['status']==='LAST_WIFI_EVENT_WORK'
    &&$event['eventAgeSeconds']===21600
    &&!$event['isCurrentDeviceLocationProof']
    &&!$event['mayAuthorizeAbsence']&&!$event['mayArmAlarm'],
    'Carina old work SSID retained as labelled last event without proof');
$tile=['schema'=>1,'authority'=>'CURRENT_STATUS_ONLY_SAFE_HTML_TILE',
    'people'=>[
       'johannes'=>['name'=>'Johannes','decision'=>'HOME_PHONE_CONFIRMED',
           'status'=>'ANWESEND','at'=>$now-70],
       'carina'=>['name'=>'Carina','decision'=>'LAST_KNOWN_UNCONFIRMED',
           'status'=>'NICHT BESTÄTIGT','at'=>null]
    ],
    'carinaLastWifiEvent'=>$event
];
$t=JVResidentTileDashboard::compactState($tile,$now);
checkCarina($t['people']['johannes']['status']==='Zuhause'
    &&$t['people']['johannes']['eventOnlyLastWifiNotCurrentProof']===false
    &&$t['people']['carina']['status']==='In Firma'
    &&$t['people']['carina']['eventOnlyLastWifiNotCurrentProof']===true
    &&str_contains($t['people']['carina']['reason'],'nur bei WLAN-Wechsel')
    &&$t['people']['carina']['at']===$now-21600
    &&!$t['mayArmAlarm'],
    'native tile labels old event for Carina; Johannes current remains untouched');
$html=JVResidentTileDashboard::renderCompact($tile,$now);
checkCarina(str_contains($html,'nur bei WLAN-Wechsel')
    &&str_contains($html,'In Firma'),'native tile renders event-only qualifier');
$raw['carina']['ssid']='Veit-FilzenerWeg';
$raw['carina']['updatedAt']=$now-18000;
$work=JVCompanyWiFiEvidence::evaluate($raw,$now,true);
$visu['people']['carina']['lastKnownDeviceZone']='HOME';
$visu['people']['carina']['lastKnownDeviceAt']=$now-18000;
$home=JVCarinaLastWifiEventDisplay::evaluate($work,$visu,$geo,$now);
checkCarina($home['status']==='LAST_WIFI_EVENT_HOME',
    'Carina last home WLAN is also kept beyond 5 min');
$tile['carinaLastWifiEvent']=$home;
checkCarina(JVResidentTileDashboard::compactState($tile,$now)['people']['carina']['status']==='Zuhause',
    'last home SSID appears Zuhause, explicitly event-only');
$raw['carina']['ssid']='Privat-Veit-EXT';
$work=JVCompanyWiFiEvidence::evaluate($raw,$now,true);
$uncertain=JVCarinaLastWifiEventDisplay::evaluate($work,$visu,$geo,$now);
checkCarina($uncertain['status']==='NOT_AVAILABLE'
    &&$uncertain['zone']===null,
    'private extended coverage cannot establish home or work');
$raw['carina']['ssid']='';
$work=JVCompanyWiFiEvidence::evaluate($raw,$now,true);
checkCarina(JVCarinaLastWifiEventDisplay::evaluate($work,$visu,$geo,$now)['status']==='NOT_AVAILABLE',
    'empty/disconnected source never kept as location');
$raw['carina']['ssid']='Intern-Veit';
$raw['carina']['updatedAt']=$now-21600;
$work=JVCompanyWiFiEvidence::evaluate($raw,$now,true);
$visu['people']['carina']['lastKnownDeviceZone']='WORK';
$visu['people']['carina']['lastKnownDeviceAt']=$now-21600;
$geo['geofenceReportedInside']=false;
$geo['lastEventAt']=$now-500;
$blocked=JVCarinaLastWifiEventDisplay::evaluate($work,$visu,$geo,$now);
checkCarina($blocked['status']==='SUPERSEDED_BY_NEWER_GEOFENCE_OUT',
    'newer OUT immediately revokes old workplace event');
$tile['carinaLastWifiEvent']=$blocked;
checkCarina(JVResidentTileDashboard::compactState($tile,$now)['people']['carina']['status']==='Unklar',
    'superseded event no longer shown as currently in company');
$geo['validatedSource']=false;
$visu['people']['carina']['lastKnownDeviceZone']='HOME';
$visu['people']['carina']['lastKnownDeviceAt']=$now-400;
checkCarina(JVCarinaLastWifiEventDisplay::evaluate($work,$visu,$geo,$now)['status']
    ==='SUPERSEDED_BY_NEWER_OPPOSITE_WIFI',
    'newer MAC-home evidence supersedes old firm event');
$visu['people']['carina']['lastKnownDeviceZone']='WORK';
$visu['people']['carina']['lastKnownDeviceAt']=$now-21600;
$visu['people']['carina']['decision']='HOME_PHONE_CONFIRMED';
checkCarina(JVCarinaLastWifiEventDisplay::evaluate($work,$visu,$geo,$now)['status']
    ==='LAST_EVENT_SUPERSEDED_OR_ALREADY_LIVE',
    'fresh HOME always takes priority over last firm event');
$visu['people']['carina']['decision']='LAST_KNOWN_UNCONFIRMED';
$visu['people']['carina']['wifiConflict']=true;
checkCarina(JVCarinaLastWifiEventDisplay::evaluate($work,$visu,$geo,$now)['status']
    ==='LAST_EVENT_SUPERSEDED_OR_ALREADY_LIVE',
    'conflicts cannot be overwritten by stale event');
$visu['people']['carina']['wifiConflict']=false;
$work['people']['carina']['sourceVerified']=false;
checkCarina(JVCarinaLastWifiEventDisplay::evaluate($work,$visu,$geo,$now)['status']
    ==='NOT_AVAILABLE','unverified source cannot retain location');
$raw['carina']['updatedAt']=$now+10;
$future=JVCompanyWiFiEvidence::evaluate($raw,$now,true);
checkCarina(JVCarinaLastWifiEventDisplay::evaluate($future,$visu,$geo,$now)['status']
    ==='NOT_AVAILABLE','future dated source is invalid');
$raw['carina']['updatedAt']=$now-21600;
$raw['carina']['sourceVariableID']=21780;
$shared=JVCompanyWiFiEvidence::evaluate($raw,$now,true);
checkCarina(JVCarinaLastWifiEventDisplay::evaluate($shared,$visu,$geo,$now)['status']
    ==='NOT_AVAILABLE','shared source ID cannot be used for Carina event hold');
$raw['carina']['sourceVariableID']=42876;
$work=JVCompanyWiFiEvidence::evaluate($raw,$now,true);
$visu['people']['carina']['decision']='LAST_KNOWN_UNCONFIRMED';
$held=JVCarinaLastWifiEventDisplay::evaluate($work,$visu,$geo,$now);
$tile['carinaLastWifiEvent']=$held;
$tile['people']['carina']['decision']='CONFLICT';
checkCarina(JVResidentTileDashboard::compactState($tile,$now)['people']['carina']['status']
    ==='Unklar','tile independently blocks held event on conflict');
$tile['people']['carina']['decision']='LAST_KNOWN_UNCONFIRMED';
// In this scenario neither resident has a current HOME/WORK proof;
// if Johannes were still HOME, the existing independent group-away guard
// correctly forbids painting either resident as collectively away.
$tile['people']['johannes']['decision']='UNKNOWN';
$tile['people']['johannes']['at']=null;
$tile['people']['johannes']['status']='NICHT BESTÄTIGT';
$tile['householdAwayIndicator']=[
    'schema'=>1,'authority'=>'HOUSEHOLD_AWAY_VISUAL_HINT_ONLY',
    'status'=>'LIKELY_AWAY','likelyAway'=>true,
    'geofenceOutside'=>['johannes'=>true,'carina'=>true],
    'mayArmAlarm'=>false];
checkCarina(JVResidentTileDashboard::compactState($tile,$now)['people']['carina']['status']
    ==='Abwesend','new all-house away indication takes precedence over older event');
echo "Carina change-only SSID retained as last event, never fresh proof; 15 safety cases and tile PASS\n";
