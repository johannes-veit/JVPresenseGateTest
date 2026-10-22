<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVCompanyWiFiEvidence.php';
function ssidCheck(bool $pass,string $name):void{
    if(!$pass)throw new RuntimeException('WORK_WIFI '.$name);
}
$now=1792162800;
$live=[
 'johannes'=>['verified'=>true,'ssid'=>'Intern-Veit','updatedAt'=>$now-30],
 'carina'=>['verified'=>true,'ssid'=>'Privat-Veit','updatedAt'=>$now-10]
];
$two=JVCompanyWiFiEvidence::evaluate($live,$now,true);
ssidCheck($two['workPhoneCount']===2
 &&$two['people']['johannes']['workRecent']===true
 &&$two['people']['carina']['workRecent']===true,
 'both exact work SSIDs classify own handset WORK');
ssidCheck($two['workNeverCertifiesHomeEmpty']&& !$two['mayArmAlarm']
 &&!$two['mayChangeExternalPresence'],'no external alarm/absence writes');
$live['johannes']['ssid']='Privat-Veit-EXT';
$ext=JVCompanyWiFiEvidence::evaluate($live,$now,true);
ssidCheck($ext['workPhoneCount']===1
 &&$ext['people']['johannes']['boundarySSIDOnly']===true
 &&$ext['people']['johannes']['workRecent']===false,
 'EXT coverage crosses private boundary, never unequivocally WORK');
$live['johannes']['ssid']='Veit-FilzenerWeg';
$home=JVCompanyWiFiEvidence::evaluate($live,$now,true);
ssidCheck($home['people']['johannes']['homeSsidRecent']===true
 &&$home['people']['johannes']['workRecent']===false,
 'known home SSID does not indicate WORK');
$live['johannes']['ssid']='Work-Veit'; // friendly alias must not match
$wrong=JVCompanyWiFiEvidence::evaluate($live,$now,true);
ssidCheck($wrong['people']['johannes']['status']==='OTHER_OR_UNKNOWN_SSID',
 'SSID names are exact, not guessed');
$live['johannes']['ssid']='Intern-Veit';
$live['johannes']['updatedAt']=$now-901;
$stale=JVCompanyWiFiEvidence::evaluate($live,$now,true);
ssidCheck(!$stale['people']['johannes']['workRecent']
 &&$stale['people']['johannes']['status']==='STALE_CURRENT_SSID_UNCONFIRMED',
 'last-known work SSID cannot remain eternally true');
ssidCheck($stale['people']['johannes']['reportedSSIDKind']
    ==='NONEMPTY_OBSERVED'
    &&$stale['people']['johannes']['ssid']===null,
    'stale nonempty value is classified without leaking old network or establishing location');
$live['johannes']['ssid']='';
$empty=JVCompanyWiFiEvidence::evaluate($live,$now,true);
ssidCheck($empty['people']['johannes']['reportedSSIDKind']
    ==='EMPTY_OR_DISCONNECTED'
    &&$empty['people']['johannes']['status']==='STALE_CURRENT_SSID_UNCONFIRMED'
    &&$empty['people']['johannes']['workRecent']===false,
    'initialized/disconnected empty phone source remains unknown even if stale');
$live['johannes']['ssid']='Intern-Veit';
$live['johannes']['updatedAt']=$now+20;
$future=JVCompanyWiFiEvidence::evaluate($live,$now,true);
ssidCheck(!$future['people']['johannes']['workRecent']
 &&$future['people']['johannes']['status']==='INVALID_UPDATE_TIME',
 'future smartphone report denied');
$live['johannes']['updatedAt']=$now-20;$live['johannes']['verified']=false;
$unverified=JVCompanyWiFiEvidence::evaluate($live,$now,true);
ssidCheck(!$unverified['people']['johannes']['workRecent']
 &&$unverified['people']['johannes']['status']==='NOT_CONFIGURED_OR_UNVERIFIED',
 'unverified source ID cannot certify WORK');
$off=JVCompanyWiFiEvidence::evaluate($live,$now,false);
ssidCheck($off['workPhoneCount']===0
 &&$off['people']['carina']['status']==='DISABLED',
 'default opt-out preserves old behavior');
echo "Explicit work SSID classification, expiry and property overlap PASS\n";
