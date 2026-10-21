<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPhoneContactContinuity.php';
require_once dirname(__DIR__).'/libs/JVResidentArrivalInference.php';
require_once dirname(__DIR__).'/libs/JVResidentDepartureInference.php';

/**
 * Anonymized field-pattern regression: two household phones stop producing
 * home-MAC observations at nearly the same time, then both reappear after
 * a long gap. A shared-radius geofence reports IN for only one handset;
 * P03 is inactive and street-Human IVS cannot identify anyone.
 *
 * The actual household account is intentionally NOT committed here.
 * A synchronized phone gap is context, never a certified human departure,
 * and two renewed MACs are not two certified human arrivals.
 */
function jointGapCheck(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('JOINT_PHONE_GAP '.$message);
}
$t=1800000000; // artificial clock, not a household activity timestamp
$gapJ=22500;
$gapC=22494;
$macJ=$t-17;
$macC=$t-23;
$lastBefore=$t-22517;
jointGapCheck($macJ-$gapJ===$lastBefore
    &&$macC-$gapC===$lastBefore,
    'both calculated old MAC contact times are near-identical');

$oldWlan=[
    'schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>[
        'johannes'=>[
            'validatedExistingVariablePair'=>true,
            'wlanBoolean'=>true,'recentMacEvidence'=>true,
            'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
            'lastMacContactAt'=>$lastBefore,'sourceKey'=>'WLAN:1001:1002'
        ],
        'carina'=>[
            'validatedExistingVariablePair'=>true,
            'wlanBoolean'=>true,'recentMacEvidence'=>true,
            'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
            'lastMacContactAt'=>$lastBefore,'sourceKey'=>'WLAN:2001:2002'
        ]
    ]
];
$jGeo=[
    'schema'=>1,'authority'=>'EXISTING_JOHANNES_EGIGEOZONE_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,
    'insideRecent'=>false,'outsideRecent'=>false,
    'geofenceReportedInside'=>null,'lastEventAt'=>null,
    'sourceKey'=>'EGI:101:102:103:104'
];
$cGeo=[
    'schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,
    'insideRecent'=>false,'outsideRecent'=>false,
    'geofenceReportedInside'=>null,'lastEventAt'=>null,
    'sourceKey'=>'GEOFENCY:201:202:203:204'
];
$baseline=JVPhoneContactContinuity::evaluate(
    $oldWlan,$jGeo,$cGeo,[],$t-22500
);
$live=$oldWlan;
$live['people']['johannes']['lastMacContactAt']=$macJ;
$live['people']['carina']['lastMacContactAt']=$macC;
$cGeo['insideRecent']=true;
$cGeo['geofenceReportedInside']=true;
$cGeo['lastEventAt']=$t-312; // a broad home/work radius, no prior OUT
$reported=JVPhoneContactContinuity::evaluate(
    $live,$jGeo,$cGeo,$baseline['next'],$t
);
foreach(['johannes','carina'] as $person){
    $p=$reported['report']['people'][$person];
    jointGapCheck($p['contactResumedAfterGap']===true,
        $person.': long gap should be recorded as a handset event');
    jointGapCheck(!$p['phoneReturnAfterObservedOutIn']
        &&!$p['humanPresenceCertified']&&!$p['humanAwayCertified'],
        $person.': long gap without observed OUT-IN is not human movement proof');
}
jointGapCheck(
    $reported['report']['people']['johannes']['observedMacGapSeconds']
        ===$gapJ
    &&$reported['report']['people']['carina']['observedMacGapSeconds']
        ===$gapC,
    'independent personal MAC histories retain exact observation gaps'
);
jointGapCheck(!$reported['report']['anyHumanArrivalCertified']
    &&!$reported['report']['anyHumanDepartureCertified']
    &&!$reported['report']['mayArmAlarm'],
    'synchronous smartphone silence cannot grant identity or arm alarms');
$p03=[
    'schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'recentDirectionProofs'=>[], 'logContinuity'=>'UNKNOWN'
];
$arrival=JVResidentArrivalInference::evaluate(
    $live,$jGeo,$cGeo,$p03,$t
);
jointGapCheck(
    $arrival['people']['johannes']['assessment']
        ==='HOME_PHONE_CURRENT_ARRIVAL_NOT_PROVEN'
    &&$arrival['people']['carina']['assessment']
        ==='GEOFENCE_IN_NOT_SUFFICIENT_FOR_HOME_RETURN'
    &&!$arrival['people']['johannes']['phoneAssociatedArrivalOnly']
    &&!$arrival['people']['carina']['phoneAssociatedArrivalOnly'],
    'shared-radius geo IN and two renewed MACs never prove personal arrival'
);
jointGapCheck(!$arrival['vehicleTripsCertified']
    &&!$arrival['mayArmAlarm'],
    'inactive P03 plus street-Human hint cannot prove a car journey');
echo "Two-handset synchronized MAC-gap field-pattern replay PASS\n";
