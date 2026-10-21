<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPhoneContactContinuity.php';

function strictReturnCheck(bool $ok,string $case):void {
    if(!$ok)throw new RuntimeException('STRICT_PHONE_RETURN '.$case);
}
$t=1900000000; // synthetic clock, not a household observation
$basePerson=static fn(int $macAt):array=>[
    'startedAt'=>$t-400,'lastMacAt'=>$macAt,
    'lastGeoAt'=>$t-20,'pendingOutAt'=>$t-100,
    'pendingInAt'=>$t-20,'lastPhoneReturnAt'=>null,
    'lastMacSourceKey'=>'WLAN:101:102',
    'lastGeoSourceKey'=>'EGI:1:2:3:4',
    'lastMacSourceVerified'=>true,'lastGeoSourceVerified'=>true
];
$prior=['schema'=>1,'people'=>[
    'johannes'=>$basePerson($t-40)
]];
$wlan=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>[
        'johannes'=>[
            'validatedExistingVariablePair'=>true,
            'wlanBoolean'=>true,'recentMacEvidence'=>true,
            'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
            'lastMacContactAt'=>$t-20,
            'sourceKey'=>'WLAN:101:102'
        ]
    ]];
$jGeo=['schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,
    'insideRecent'=>true,'outsideRecent'=>false,
    'geofenceReportedInside'=>true,'lastEventAt'=>$t-20,
    'sourceKey'=>'EGI:1:2:3:4'];
$cGeo=[];
$run=static function(int $now,array $prev)use(&$wlan,&$jGeo,&$cGeo):array{
    return JVPhoneContactContinuity::evaluate($wlan,$jGeo,$cGeo,$prev,$now);
};

// MAC contact advanced but is in the same SECOND as geo IN. No
// trustworthy ordering, not a newly observed post-IN phone contact.
$same=$run($t,$prior);
strictReturnCheck(!$same['report']['people']['johannes']['phoneReturnAfterObservedOutIn']
    &&($same['next']['people']['johannes']['pendingInAt']??null)===$t-20,
    'same-second evidence remains pending, not confirmed');
strictReturnCheck(!$same['report']['anyHumanArrivalCertified']
    &&!$same['report']['mayArmAlarm'],'same-second event cannot authorize alarm');
// Same contact published on the next refresh is NOT a fresh mac edge.
$again=$run($t+5,$same['next']);
strictReturnCheck(!$again['report']['people']['johannes']['phoneReturnAfterObservedOutIn'],
    'unchanged MAC timestamp cannot become a new return on polling');

// MAC time after IN but already present in the prior checkpoint:
// a late-arriving geo record is not a subsequent MAC observation.
$seen=['schema'=>1,'people'=>[
    'johannes'=>$basePerson($t-10)
]];
$wlan['people']['johannes']['lastMacContactAt']=$t-10;
$old=$run($t,$seen);
strictReturnCheck(!$old['report']['people']['johannes']['phoneReturnAfterObservedOutIn']
    &&!$old['report']['people']['johannes']['contactResumedAfterGap'],
    'already seen MAC, delayed geo IN is not fresh phone return');

// New MAC AFTER observed IN on the same verified ID is allowed,
// still only as a HANDSET event, never as a human/vehicle inference.
$wlan['people']['johannes']['lastMacContactAt']=$t-8;
$new=$run($t,$old['next']);
strictReturnCheck($new['report']['people']['johannes']['phoneReturnAfterObservedOutIn']
    &&$new['report']['people']['johannes']['phoneReturnAt']===$t-8
    &&($new['next']['people']['johannes']['pendingOutAt']??null)===null
    &&($new['next']['people']['johannes']['pendingInAt']??null)===null,
    'actual new MAC strictly after IN completes one phone return');
strictReturnCheck(!$new['report']['people']['johannes']['humanPresenceCertified']
    &&!$new['report']['people']['johannes']['humanAwayCertified']
    &&!$new['report']['anyHumanDepartureCertified'],
    'post-IN MAC never certifies human movement or arrival');
$repeat=$run($t+10,$new['next']);
strictReturnCheck(!$repeat['report']['people']['johannes']['phoneReturnAfterObservedOutIn'],
    'one new MAC return cannot replay indefinitely');

// Another resident's phone changes nothing in Johannes' pending proof.
// No accidental shared event between distinct personally bound sources.
$shared=$prior;
$wlan['people']['johannes']['lastMacContactAt']=$t-40;
$wlan['people']['carina']=[
    'validatedExistingVariablePair'=>true,
    'wlanBoolean'=>true,'recentMacEvidence'=>true,
    'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
    'lastMacContactAt'=>$t-5,
    'sourceKey'=>'WLAN:201:202'
];
$other=$run($t,$shared);
strictReturnCheck(!$other['report']['people']['johannes']['phoneReturnAfterObservedOutIn']
    &&!$other['report']['people']['carina']['phoneReturnAfterObservedOutIn'],
    'two handsets cannot borrow each others return sequence');

// Invalid or changed source keys must reset the personal event history.
$wlan['people']['johannes']['lastMacContactAt']=$t-8;
$wlan['people']['johannes']['sourceKey']='WLAN:103:104';
$switched=$run($t,$prior);
strictReturnCheck($switched['report']['people']['johannes']['sourceRebaselineRequired']
    &&!$switched['report']['people']['johannes']['phoneReturnAfterObservedOutIn'],
    'new handset source cannot finish old pending IN');
$wlan['people']['johannes']['sourceKey']=null;
$missing=$run($t,$prior);
strictReturnCheck($missing['report']['people']['johannes']['sourceRebaselineRequired']
    &&!$missing['report']['people']['johannes']['phoneReturnAfterObservedOutIn'],
    'unbound handset source cannot finish old pending IN');

// Complete source loss never means the resident is absent.
$wlan['enabled']=false;
$down=$run($t,$prior);
strictReturnCheck(!$down['report']['anyHumanArrivalCertified']
    &&!$down['report']['anyHumanDepartureCertified']
    &&!$down['report']['mayArmAlarm'],
    'source outage remains UNKNOWN for physical persons');
echo "Strict new MAC after geofence IN and no same-second or replay return PASS\n";
