<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVResidentArrivalInference.php';
function checkArrival(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('ARRIVAL '.$label);
}
$now=1792200000;
$w=['schema'=>1,'authority'=>'EXISTING_WLAN_MAC_HINTS_READ_ONLY',
    'enabled'=>true,'people'=>['carina'=>[
        'validatedExistingVariablePair'=>true,'recentMacEvidence'=>true,
        'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
        'lastMacContactAt'=>$now-30]]];
$j=[];$g=['schema'=>1,'authority'=>'EXISTING_CARINA_GEOFENCY_READ_ONLY',
    'enabled'=>true,'validatedSource'=>true,'insideRecent'=>true,
    'outsideRecent'=>false,'lastEventAt'=>$now-180];
$p=['schema'=>1,'authority'=>'P03_LIVE_PROOFS_DIRECTION_ONLY',
    'recentDirectionProofs'=>[['source'=>'P03_TWO_MAST_VERIFIED',
    'direction'=>'LAGER_TO_HOME','at'=>$now-70]]];
$run=static function()use(&$w,&$j,&$g,&$p,$now):array {
    return JVResidentArrivalInference::evaluate($w,$j,$g,$p,$now);
};
$r=$run();checkArrival($r['people']['carina']['assessment']==='HOME_PHONE_CURRENT_ARRIVAL_NOT_PROVEN'
    &&$r['people']['carina']['vehicle']==='UNVERIFIED'
    &&!$r['mayArmAlarm'],'ordered phone and anonymous p03 IN');
$p['recentDirectionProofs']=[];
$r=$run();checkArrival($r['people']['carina']['assessment']==='GEOFENCE_IN_NOT_SUFFICIENT_FOR_HOME_RETURN',
    'broad geofence alone does not prove property arrival');
$g['insideRecent']=false;
$r=$run();checkArrival($r['people']['carina']['assessment']==='HOME_PHONE_CURRENT_ARRIVAL_NOT_PROVEN',
    'home wifi without entry is not an arrival');
$w['people']['carina']['lastMacContactAt']=$now-310;
$r=$run();checkArrival($r['people']['carina']['assessment']==='NOT_ESTABLISHED',
    'stale WiFi cannot establish arrival');
$w['people']['carina']['lastMacContactAt']=$now-30;
$p['recentDirectionProofs']=[['source'=>'P03_TWO_MAST_VERIFIED',
    'direction'=>'HOME_TO_LAGER','at'=>$now-70]];
$r=$run();checkArrival($r['people']['carina']['assessment']==='HOME_PHONE_CURRENT_ARRIVAL_NOT_PROVEN',
    'opposite route cannot prove return');

// A P03 passage next to two continuously home-connected phones is not an arrival.
// Field regression 17.10.2026: Johannes walked, Carina stayed home.
// Same anonymous P03 passage plus two refreshed MAC contacts must NOT
// create two personal arrivals.
$g['insideRecent']=false;
$w['people']['johannes']=$w['people']['carina'];
$p['recentDirectionProofs']=[['source'=>'P03_TWO_MAST_VERIFIED',
    'direction'=>'LAGER_TO_HOME','at'=>$now-70]];
$both=$run();
checkArrival(
    $both['people']['carina']['assessment']==='HOME_PHONE_CURRENT_ARRIVAL_NOT_PROVEN'
    &&$both['people']['johannes']['assessment']==='HOME_PHONE_CURRENT_ARRIVAL_NOT_PROVEN'
    &&!$both['people']['carina']['phoneAssociatedArrivalOnly']
    &&!$both['people']['johannes']['phoneAssociatedArrivalOnly'],
    'single anonymous crossing cannot identify either resident'
);

// A verified earlier phone OUT->IN->MAC sequence is allowed to report a
// HANDSET return; neither P03 nor a second resident receives human identity.
$confirmedReturn=['authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>['carina'=>['lastEvent'=>[
        'code'=>'PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC','at'=>$now-35]]]];
$individual=JVResidentArrivalInference::evaluate(
    $w,$j,$g,$p,$now,$confirmedReturn
);
checkArrival(
    $individual['people']['carina']['assessment']==='PHONE_RETURN_AFTER_OUT_IN_HOME_MAC'
    &&$individual['people']['carina']['phoneAssociatedArrivalOnly']===true
    &&$individual['people']['carina']['personIdentifiedByCamera']===false,
    'only personal verified phone sequence can be called phone return'
);
$confirmedReturn['people']['johannes']=$confirmedReturn['people']['carina'];
$w['people']['johannes']=['validatedExistingVariablePair'=>true,
    'recentMacEvidence'=>true,'state'=>'MAC_CONFIRMED_RECENT_DEVICE_HINT',
    'lastMacContactAt'=>$now-20];
$two=JVResidentArrivalInference::evaluate($w,$j,$g,$p,$now,$confirmedReturn);
checkArrival($two['people']['johannes']['assessment']==='PHONE_RETURN_AFTER_OUT_IN_HOME_MAC',
    'two independently observed phone return sequences may report two phones');

// A later WLAN recovery must not overwrite the personal return signal in
// a proper bounded event ledger. Never infer camera identity from it.
$withHistory=['authority'=>'PERSISTENT_RESIDENT_EVENT_FUSION_READ_ONLY',
    'people'=>['carina'=>[
        'lastEvent'=>['code'=>'HOME_PHONE_REAPPEARED_AFTER_CONTACT_GAP',
            'at'=>$now-8],
        'eventHistory'=>[
            ['code'=>'HOME_PHONE_REAPPEARED_AFTER_CONTACT_GAP','at'=>$now-8],
            ['code'=>'PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC','at'=>$now-35]
        ]
    ]]];
$historyArrival=JVResidentArrivalInference::evaluate($w,$j,$g,$p,$now,$withHistory);
checkArrival(
    $historyArrival['people']['carina']['assessment']==='PHONE_RETURN_AFTER_OUT_IN_HOME_MAC'
    &&$historyArrival['people']['carina']['verifiedPhoneReturnAt']===$now-35
    &&$historyArrival['people']['carina']['personIdentifiedByCamera']===false,
    'prior personal phone return remains visible after ordinary WLAN refresh'
);
$withHistory['people']['carina']['eventHistory']=[
    ['code'=>'HOME_PHONE_REAPPEARED_AFTER_CONTACT_GAP','at'=>$now-8]
];
$noReturn=JVResidentArrivalInference::evaluate($w,$j,$g,$p,$now,$withHistory);
checkArrival($noReturn['people']['carina']['assessment']!=='PHONE_RETURN_AFTER_OUT_IN_HOME_MAC',
    'explicit event history without return overrides lastEvent');
echo "Arrival correlation tests PASS\n";
