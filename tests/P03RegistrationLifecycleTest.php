<?php
declare(strict_types=1);

/**
 * IP-Symcon API contract:
 * RegisterAttribute*() and RegisterTimer() are Create()-only APIs.
 * Previously real Symcon instances emitted >20 runtime warnings because
 * P03 re-registered ten observer attributes inside ApplyChanges().
 *
 * Unlike legacy mocks, this test rejects incorrect registration LOCATION
 * in both primary P03 modules and catches duplicate attribute names.
 */
function lifecycleAssert(bool $result, string $message): void
{
    if (!$result) {
        throw new RuntimeException('P03 LIFECYCLE FAIL: ' . $message);
    }
    echo 'OK: ' . $message . "\n";
}

foreach ([
    'JVPresenceP03AuxObserver/module.php',
    'JVPresenceP03TerraceTest/module.php'
] as $path) {
    $contents = file_get_contents(dirname(__DIR__) . '/' . $path);
    lifecycleAssert(is_string($contents) && $contents !== '', $path . ': load');

    $a = strpos($contents, '    public function Create(): void');
    $b = strpos($contents, '    public function ApplyChanges(): void', $a + 1);
    $c = strpos($contents, '    public function ', $b + 25);
    lifecycleAssert($a !== false && $b !== false && $c !== false && $a < $b && $b < $c,
        $path . ': method boundaries');
    $create = substr($contents,$a,$b-$a);
    $apply = substr($contents,$b,$c-$b);

    preg_match_all('/\$this->RegisterAttribute(?:Boolean|Integer|Float|String)\s*\(\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]/', $create, $attrs);
    lifecycleAssert(count($attrs[1]) >= ($path === 'JVPresenceP03AuxObserver/module.php' ? 24 : 30),
        $path . ': all attributes registered in Create');
    lifecycleAssert(count($attrs[1])===count(array_unique($attrs[1])),
        $path . ': no duplicate attribute names in Create');
    lifecycleAssert(!preg_match('/\$this->RegisterAttribute(?:Boolean|Integer|Float|String)\s*\(/', $apply),
        $path . ': ZERO RegisterAttribute calls in ApplyChanges');

    preg_match_all('/\$this->RegisterTimer\s*\(\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]/', $create, $timers);
    lifecycleAssert(count($timers[1])>=3,
        $path . ': timers registered during Create');
    lifecycleAssert(count($timers[1])===count(array_unique($timers[1])),
        $path . ': no duplicate timers in Create');
    lifecycleAssert(!preg_match('/\$this->RegisterTimer\s*\(/', $apply),
        $path . ': ZERO RegisterTimer calls in ApplyChanges');
    lifecycleAssert(str_contains($apply,'parent::ApplyChanges()'),
        $path . ': base ApplyChanges preserved');
}
$observer=file_get_contents(dirname(__DIR__).'/JVPresenceP03AuxObserver/module.php');
$prefix=json_decode(file_get_contents(dirname(__DIR__).'/JVPresenceP03AuxObserver/module.json'),true);
lifecycleAssert(($prefix['prefix']??'')==='JVP03AUX'
    && str_contains($observer,"JVP03AUX_OnvifPoll("),
    'ONVIF event polling timer uses the module actual registered function prefix');
lifecycleAssert(str_contains($observer,"RegisterTimer('OnvifPullTimer'")
    && str_contains($observer,"SetTimerInterval('OnvifPullTimer'")
    && str_contains($observer,"public function OnvifPoll(): void"),
    'ONVIF registration, ApplyChanges activation and method callback are connected');
$start=strpos($observer,'    public function OnvifPoll(): void');
$end=strpos($observer,'    public function GetConfigurationForParent(): string',$start);
$onvif=substr($observer,$start,$end-$start);
lifecycleAssert(str_contains($onvif,'OnvifPollBusyUntil')
    && str_contains($onvif,'OnvifNextAttemptAt')
    && str_contains($onvif,'finally'),
    'ONVIF poll serialized; error/backoff and lock release are present');
lifecycleAssert(!str_contains($onvif,'publishHumanProof(')
    && !str_contains($onvif,"SetValue('HumanEventCounter'"),
    'ONVIF passive diagnosis cannot inject unverified person counters or zone transfers');

// Limited Symcon licenses: genuinely unregister obsolete status variables,
// retaining only the essential diagnostic/protocol and live transport IDs.
$main=file_get_contents(dirname(__DIR__).'/JVPresenceP03TerraceTest/module.php');
foreach ([$main,$observer] as $source) {
    lifecycleAssert(str_contains((string)$source,'UnregisterVariable(')
        && !str_contains((string)$source,'IPS_DeleteVariable(')
        && !str_contains((string)$source,'IPS_DeleteObject('),
        'obsolete status variables are removed only by own-module UnregisterVariable');
}
$form=json_decode(file_get_contents(dirname(__DIR__).'/JVPresenceP03TerraceTest/form.json'),true);
lifecycleAssert(is_array($form) && count($form['actions']??[])===13,
    'Symcon user form has eleven focused buttons including single-IN and street-only training');
$commands=array_column($form['actions'],'onClick');
foreach ([
    'JVP03MC_ConfigureTerraceMonitor($id);',
    'JVP03MC_DiagnoseP03Connections($id, false);',
    'JVP03MC_StartChatGPTTestCapture($id);',
    'JVP03MC_RefreshChatGPTTestResult($id);',
    'JVP03MC_DiagnoseIPC5442Configuration($id);',
    'JVP03MC_AuditCameraConfiguration($id);',
    'JVP03MC_DiagnoseIPC5442AllValues($id);',
    'JVP03MC_RunP03Simulation($id);',
    'JVP03MC_PrepareAndStartTest($id);',
    'JVP03MC_StartStreetDirectionOnly($id);',
    'JVP03MC_StartTerraceInBackupOnly($id);',
    'JVP03MC_EnableProductionP03($id);',
    'JVP03MC_DisableProductionP03($id);'
] as $command) {
    lifecycleAssert(in_array($command,$commands,true),
        'essential P03 test/production safety command remains visible: '.$command);
}
/**
 * PHP methods accept omitted optional arguments, but generated IP-Symcon
 * wrapper functions in __generated.inc.php require all declared parameters.
 * Therefore the ConfigurationForm onClick must include EVERY method
 * argument explicitly after the mandatory $id. Actual user regression:
 * JVP03MC_DiagnoseP03Connections($id) gave ArgumentCountError 1/2.
 */
function p03GeneratedWrapperArityOK(string $callback,string $source):bool
{
    if(!preg_match('/^JVP03MC_([A-Za-z_][A-Za-z0-9_]*)\\(\\$id(?:,\\s*(.*))?\\);$/D',
        $callback,$called))return false;
    $method=$called[1];
    if(!preg_match('/\\bpublic function\\s+'.preg_quote($method,'/').
        '\\s*\\(([^)]*)\\)/',$source,$declared))return false;
    preg_match_all('/\\$[A-Za-z_][A-Za-z0-9_]*/',$declared[1],$params);
    $provided=isset($called[2])&&trim($called[2])!==''
        ?array_map('trim',explode(',',$called[2])):[];
    if(in_array('',$provided,true))return false;
    return count($params[0])===count($provided);
}
foreach($commands as $command){
    lifecycleAssert(
        p03GeneratedWrapperArityOK((string)$command,$main),
        'P03 generated wrapper gets every method argument: '.$command
    );
}
lifecycleAssert(
    !p03GeneratedWrapperArityOK('JVP03MC_DiagnoseP03Connections($id);',$main)
    &&p03GeneratedWrapperArityOK(
        'JVP03MC_DiagnoseP03Connections($id, false);',$main
    ),
    'actual 1/2 argument regression rejected, explicit false accepted'
);
lifecycleAssert(
    substr_count((string)$main,
        'public function DiagnoseP03Connections(bool $automatic = false): void')===1,
    'automatic diagnostics default remains unchanged inside main P03 code'
);
lifecycleAssert(str_contains($main,
    "RegisterVariableString('ChatGPTTestResult'"),
    'stable single ChatGPT test-result variable ident');
lifecycleAssert(substr_count($main,
    "RegisterVariableString('ChatGPTTestResult'")===2,
    'new variable registered in Create and ApplyChanges with same ident');
lifecycleAssert(str_contains($main,
    "RegisterAttributeString('ChatGPTTestBaseline', '{}')"),
    'baseline persists via one attribute');
lifecycleAssert(str_contains($main,
    "public function StartChatGPTTestCapture(): void")
    &&str_contains($main,"public function RefreshChatGPTTestResult(): void"),
    'both report buttons connected to public methods');
lifecycleAssert(!in_array('JVP03MC_SwitchToVerifiedLeftCameras($id);',$commands,true)
    && !in_array('JVP03MC_RepairP03ClientSockets($id);',$commands,true),
    'completed migrations and repetitive emergency repair buttons removed from live UI');
lifecycleAssert(str_contains((string)$main, "RegisterPropertyBoolean('TerraceDiagnosticsEnabled', false)")
    && str_contains((string)$main, 'public function ConfigureTerraceMonitor(): void')
    && str_contains((string)$main, 'private function terraceObserverSocketExclusive(')
    && str_contains((string)$main, 'P03ExistingIvsLines::selectTerrace('),
    'optional terrace is OFF by default, read-only and guarded by exclusive socket ownership');
lifecycleAssert(str_contains((string)$main, 'TERRACE darf weder JV_LEFT noch WORK_LEFT')
    && str_contains((string)$main, "TERRACE_ROLE = 'TERRACE'"),
    'third-camera source stays outside the two-mast transfer proof path');
lifecycleAssert(str_contains((string)$observer, 'pulsePersonDetected()')
    && str_contains((string)$observer, 'SMD_PERSON_STATUS_ONLY_NO_STAIR_PROOF'),
    'SMD person status is independent of strict staircase transfer proof');

echo "P03 CREATE/APPLYCHANGES REGISTRATION CONTRACT PASSED\n";
