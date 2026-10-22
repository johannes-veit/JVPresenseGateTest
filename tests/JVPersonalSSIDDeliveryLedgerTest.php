<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVPersonalSSIDDeliveryLedger.php';
function deliveryCheck(bool $v,string $why):void {
    if(!$v)throw new RuntimeException('DELIVERY_EPOCH '.$why);
}
$now=1800000000;
$work=['schema'=>1,
 'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
 'people'=>[
  'johannes'=>['sourceVerified'=>true,'sourceKey'=>'SSID:56338',
      'ageSeconds'=>5711,'sourceUpdatedAt'=>$now-5711],
  'carina'=>['sourceVerified'=>true,'sourceKey'=>'SSID:24058',
      'ageSeconds'=>5711,'sourceUpdatedAt'=>$now-5711]
 ]];
$baseline=JVPersonalSSIDDeliveryLedger::evaluate([],$work,$now);
deliveryCheck($baseline['people']['johannes']['state']
    ==='BASELINE_NO_NEW_UPDATE_PROVEN'
    &&$baseline['people']['carina']['observedUpdatesThisEpoch']===0
    &&!$baseline['isAuthenticatedPhonePostProof'],
    'first read of old values cannot invent phone POST');
$work['people']['johannes']['ageSeconds']=5771;
$work['people']['carina']['ageSeconds']=5771;
$unchanged=JVPersonalSSIDDeliveryLedger::evaluate($baseline['next'],
    $work,$now+60);
deliveryCheck($unchanged['people']['johannes']['state']
    ==='SOURCE_VALUE_UNCHANGED_HEARTBEAT_OVERDUE'
    &&$unchanged['sourceUpdatesObservedThisEpoch']===0,
    'unchanged old source not repeatedly counted');
$then=$now+61;
$work['people']['johannes']['ageSeconds']=0;
$work['people']['johannes']['sourceUpdatedAt']=$then;
$work['people']['carina']['ageSeconds']=5772;
$one=JVPersonalSSIDDeliveryLedger::evaluate($unchanged['next'],
    $work,$then);
deliveryCheck($one['people']['johannes']['state']
    ==='SOURCE_VARIABLE_UPDATED_THIS_EPOCH'
    &&$one['people']['johannes']['observedUpdatesThisEpoch']===1
    &&$one['people']['carina']['observedUpdatesThisEpoch']===0,
    'first verified independent source string update is counted once');
$work['people']['johannes']['ageSeconds']=1;
$work['people']['carina']['ageSeconds']=5773;
$same=JVPersonalSSIDDeliveryLedger::evaluate($one['next'],$work,$then+1);
deliveryCheck($same['people']['johannes']['state']
    ==='SOURCE_VALUE_UNCHANGED_STILL_RECENT'
    &&$same['people']['johannes']['observedUpdatesThisEpoch']===1,
    'same source at next second never causes a synthetic second event');
$work['people']['carina']['ageSeconds']=0;
$work['people']['carina']['sourceUpdatedAt']=$then+2;
$work['people']['johannes']['ageSeconds']=2;
$two=JVPersonalSSIDDeliveryLedger::evaluate($same['next'],
    $work,$then+2);
deliveryCheck($two['sourceUpdatesObservedThisEpoch']===2
    &&$two['people']['carina']['observedUpdatesThisEpoch']===1,
    'Carina receives independent update without shared person attribution');
$work['people']['johannes']['sourceKey']='SSID:77777';
$work['people']['johannes']['sourceUpdatedAt']=$then+3;
$work['people']['johannes']['ageSeconds']=0;
$work['people']['carina']['ageSeconds']=1;
$moved=JVPersonalSSIDDeliveryLedger::evaluate($two['next'],
    $work,$then+3);
deliveryCheck($moved['people']['johannes']['state']
    ==='SOURCE_CHANGED_BASELINE_NO_UPDATE_PROVEN'
    &&$moved['people']['johannes']['observedUpdatesThisEpoch']===0,
    'source ID change rebaselines and never becomes a phone movement');
$work['people']['johannes']['sourceUpdatedAt']=$then-300;
$work['people']['johannes']['ageSeconds']=303;
$work['people']['carina']['ageSeconds']=2;
$regressed=JVPersonalSSIDDeliveryLedger::evaluate($moved['next'],
    $work,$then+4);
deliveryCheck($regressed['people']['johannes']['state']
    ==='SOURCE_TIMESTAMP_REGRESSED_NO_EVENT_PROOF'
    &&$regressed['people']['johannes']['observedUpdatesThisEpoch']===0,
    'regressed source clock quarantined');
$work['people']['johannes']['sourceUpdatedAt']=$then+7;
$work['people']['johannes']['ageSeconds']=0;
$work['people']['carina']['ageSeconds']=0;
$work['people']['carina']['sourceUpdatedAt']=$then+7;
$recovered=JVPersonalSSIDDeliveryLedger::evaluate($regressed['next'],
    $work,$then+7);
deliveryCheck($recovered['people']['johannes']['state']
    ==='SOURCE_VARIABLE_UPDATED_THIS_EPOCH'
    &&$recovered['people']['johannes']['observedUpdatesThisEpoch']===1,
    'higher exact timestamp resumes counting after regression');
$restart=JVPersonalSSIDDeliveryLedger::evaluate([],$work,$then+7);
deliveryCheck($restart['people']['johannes']['state']
    ==='BASELINE_NO_NEW_UPDATE_PROVEN'
    &&$restart['sourceUpdatesObservedThisEpoch']===0,
    'restart always clears stale RAM delivery ledger');
$work['people']['johannes']['sourceUpdatedAt']=$now-1;
$work['people']['johannes']['ageSeconds']=0;
$bad=JVPersonalSSIDDeliveryLedger::evaluate([],$work,$then+8);
deliveryCheck($bad['people']['johannes']['state']==='SOURCE_NOT_VERIFIED',
    'inconsistent age vs actual source timestamp fails closed');
$work['authority']='FORGED_PHONE_SOURCE';
$bad=JVPersonalSSIDDeliveryLedger::evaluate([],$work,$then+8);
deliveryCheck($bad['people']['carina']['state']==='SOURCE_NOT_VERIFIED',
    'untrusted work source authority cannot fabricate phone update');
echo "Exact phone SSID source epochs, stale baselines, new events, rebind, regress and restart PASS\n";
