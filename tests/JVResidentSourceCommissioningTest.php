<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVResidentSourceCommissioning.php';
function commissionCheck(bool $yes,string $why):void {
    if(!$yes)throw new RuntimeException('SOURCE_COMMISSIONING '.$why);
}
$work=[
    'schema'=>1,'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'people'=>[
        'johannes'=>['sourceVerified'=>true,'sourceKey'=>'SSID:56338',
            'ageSeconds'=>5711,'status'=>'STALE_CURRENT_SSID_UNCONFIRMED'],
        'carina'=>['sourceVerified'=>true,'sourceKey'=>'SSID:24058',
            'ageSeconds'=>5711,'status'=>'STALE_CURRENT_SSID_UNCONFIRMED']
    ]
];
$hook=['authority'=>'LOCAL_WIFI_BRIDGE_HOOK_METADATA_READ_ONLY',
       'status'=>'HOOK_ASSIGNED_VALID_SCRIPT','targetScriptID'=>21958];
$op=['authority'=>'SOURCE_COMMISSIONING_READ_ONLY',
     'p03'=>['operatingMode'=>'TEST_AND_PRODUCTION_OFF',
         'productionPreflight'=>[
           'verifiedFromFreshDiagnostic'=>true,
           'auditPassed'=>false,'simulationPassed'=>true,
           'homeToLagerCount'=>1,'lagerToHomeCount'=>0,
           'totalVerifiedCount'=>1,'counterSourcesReady'=>true,
           'socketAndMastSourcesReady'=>true,
           'eligibleForManualProductionEnable'=>false
         ]]];
$r=JVResidentSourceCommissioning::evaluate($work,$op,$hook);
commissionCheck($r['ssidHook']==='HOOK_ASSIGNED_VALID_SCRIPT'
    &&$r['bothPhoneReportsStale']
    &&$r['sharedSourceUpdateSecond']
    &&$r['ssidAction']==='CHECK_BOTH_PHONE_AUTOMATIONS_AND_ACCEPTED_POST_RESPONSE',
    'real field report: both phone SSID POST updates 5711 s stale at same second');
commissionCheck($r['p03Actions']===[
    'P03_AUDIT_MAPPING_NOT_VERIFIED_CHECK_READ_ONLY_AUDIT',
    'P03_HOME_TO_LAGER_NEEDS_1','P03_LAGER_TO_HOME_NEEDS_2'],
    'real P03 gap audit-no, simulation-yes, out1/2 in0/2');
commissionCheck(!$r['mayChangeDevices']&&!$r['mayArmAlarm']
    &&!$r['mayAuthorizeHumanAbsence']
    &&!$r['p03ProductionMayBeEnabledByShadow']
    &&$r['newSymconVariables']===0,
    'no actuation, human tracking or object overhead');
$work['people']['johannes']['reportedSSIDKind']='EMPTY_OR_DISCONNECTED';
$work['people']['carina']['reportedSSIDKind']='EMPTY_OR_DISCONNECTED';
$empty=JVResidentSourceCommissioning::evaluate($work,$op,$hook);
commissionCheck(
    $empty['sharedStaleSourcePattern']
    ==='TWO_EMPTY_VALUES_FROM_SAME_SECOND_POSSIBLE_INITIALIZATION'
    &&$empty['ssidAction']
    ==='CHECK_INITIAL_PHONE_POSTS_POSSIBLE_EMPTY_BOOTSTRAP'
    &&!$empty['ssidPeople']['johannes']['freshPhoneUpdate'],
    'two simultaneous empty 95min-old sources suggest but cannot prove installer initialization');
$work['people']['johannes']['reportedSSIDKind']='NONEMPTY_OBSERVED';
$one=JVResidentSourceCommissioning::evaluate($work,$op,$hook);
commissionCheck(
    $one['sharedStaleSourcePattern']
    ==='TWO_STALE_VALUES_SAME_SECOND_NO_TRANSPORT_PROOF'
    &&$one['ssidAction']
    ==='CHECK_BOTH_PHONE_AUTOMATIONS_AND_ACCEPTED_POST_RESPONSE',
    'one nonempty stale value proves no current phone location');
$work['people']['carina']['reportedSSIDKind']='NONEMPTY_OBSERVED';
$work['people']['carina']['ageSeconds']=5712;
$r=JVResidentSourceCommissioning::evaluate($work,$op,$hook);
commissionCheck($r['bothPhoneReportsStale']&&!$r['sharedSourceUpdateSecond'],
    'different phone POST timestamps do not qualify shared coincidence');
$work['people']['carina']['status']='HOME_SSID_PHONE_HINT';
$work['people']['carina']['ageSeconds']=0;
$r=JVResidentSourceCommissioning::evaluate($work,$op,$hook);
commissionCheck(!$r['bothPhoneReportsStale']
    &&$r['ssidAction']==='CHECK_INDIVIDUAL_PHONE_POST_AND_FRESH_SSID',
    'fresh Carina cannot excuse stale Johannes');
$work['people']['johannes']['ageSeconds']=300;
$work['people']['johannes']['status']='WORK_FIRMEN_WLAN_PHONE';
$r=JVResidentSourceCommissioning::evaluate($work,$op,$hook);
commissionCheck($r['ssidPeople']['johannes']['freshPhoneUpdate']
    &&$r['ssidAction']==='BOTH_PERSONAL_SSID_POSTS_CURRENT',
    'exact 300 sec considered fresh if valid reported phone SSID');
$work['people']['johannes']['ageSeconds']=301;
$r=JVResidentSourceCommissioning::evaluate($work,$op,$hook);
commissionCheck(!$r['ssidPeople']['johannes']['freshPhoneUpdate'],
    '301 seconds are NOT fresh');
$work['people']['johannes']['sourceKey']='SSID:24058';
$r=JVResidentSourceCommissioning::evaluate($work,$op,$hook);
commissionCheck($r['ssidPeople']['johannes']['sourceAccepted']
    &&!$r['mayAuthorizeHumanAbsence'],
    'pure output never authorizes person from sourceKey');
$work['people']['johannes']['sourceKey']='OTHER:24058';
$r=JVResidentSourceCommissioning::evaluate($work,$op,$hook);
commissionCheck(!$r['ssidPeople']['johannes']['sourceAccepted'],
    'nonpersonal source not implicitly trusted');
$work['people']['johannes']['sourceKey']='SSID:56338';
foreach([
  'HOOK_NOT_REGISTERED'=>'VERIFY_WEBHOOK_MAPPING_TO_EXISTING_SCRIPT',
  'HOOK_CONFLICT'=>'RESOLVE_WEBHOOK_TARGET_WITHOUT_OVERWRITING_FOREIGN_HOOK',
  'HOOK_TARGET_INVALID'=>'RESOLVE_WEBHOOK_TARGET_WITHOUT_OVERWRITING_FOREIGN_HOOK'
] as $hookStatus=>$action){
    $hook['status']=$hookStatus;
    $r=JVResidentSourceCommissioning::evaluate($work,$op,$hook);
    commissionCheck($r['ssidAction']===$action,
        'unhealthy Hook is distinct from phone-SSID age');
}
$hook['status']='HOOK_ASSIGNED_VALID_SCRIPT';
$op['p03']['productionPreflight']['auditPassed']=true;
$op['p03']['productionPreflight']['homeToLagerCount']=2;
$op['p03']['productionPreflight']['lagerToHomeCount']=2;
$r=JVResidentSourceCommissioning::evaluate($work,$op,$hook);
commissionCheck($r['p03Actions']===['P03_MANUAL_ENABLE_ONLY_AFTER_USER_APPROVAL']
    &&!$r['p03ProductionMayBeEnabledByShadow'],
    'complete gates still require conscious manual enable');
$op['p03']['productionPreflight']['verifiedFromFreshDiagnostic']=false;
$r=JVResidentSourceCommissioning::evaluate($work,$op,$hook);
commissionCheck($r['p03Actions']===['READ_CURRENT_P03_MAIN_PREFLIGHT_DIAGNOSTIC'],
    'stale P03 diagnostic cannot assert audit and counters');
$delivery=[
 'schema'=>1,'authority'=>'TWO_PHONE_SSID_VARIABLE_UPDATE_EPOCH_READ_ONLY',
 'people'=>[
   'johannes'=>['state'=>'SOURCE_VARIABLE_UPDATED_THIS_EPOCH',
       'observedUpdatesThisEpoch'=>3],
   'carina'=>['state'=>'BASELINE_NO_NEW_UPDATE_PROVEN',
       'observedUpdatesThisEpoch'=>0]
 ]
];
$report=JVResidentSourceCommissioning::evaluate($work,$op,$hook,$delivery);
commissionCheck(($report['ssidSourceUpdateEpoch']['johannes']['observedUpdatesThisEpoch']??null)===3
    &&($report['ssidSourceUpdateEpoch']['carina']['state']??null)
       ==='BASELINE_NO_NEW_UPDATE_PROVEN'
    &&$report['phonePostAuthenticatedByShadow']===false,
    'epoch observation does not establish genuine authenticated phone POST');
$delivery['authority']='UNTRUSTED';
$report=JVResidentSourceCommissioning::evaluate($work,$op,$hook,$delivery);
commissionCheck($report['ssidSourceUpdateEpoch']===[],
    'untrusted source epoch cannot contaminate commissioning report');
echo "Phone bridge POST diagnostics and P03 preflight in 15 fail-closed cases PASS\n";
