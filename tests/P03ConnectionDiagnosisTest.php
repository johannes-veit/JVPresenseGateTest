<?php
declare(strict_types=1);

/**
 * Executes the ACTUAL main P03 class against a fake, immutable IP-Symcon
 * object tree. No network, camera, socket or module write action is possible.
 */
$GLOBALS['instances'] = [
    53879 => ['ConnectionID'=>0,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>'P03_MAIN']],
    57215 => ['ConnectionID'=>9001,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>'{8F4A3A57-3A5B-4F6E-9A64-9E1D326FF7B4}']],
    27938 => ['ConnectionID'=>9002,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>'{8F4A3A57-3A5B-4F6E-9A64-9E1D326FF7B4}']],
    9001  => ['ConnectionID'=>0,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>'{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}']],
    9002  => ['ConnectionID'=>0,'InstanceStatus'=>104,'ModuleInfo'=>['ModuleID'=>'{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}']],
    77777 => ['ConnectionID'=>9002,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>'ANOTHER_MODULE']]
];
$GLOBALS['props'] = [
    57215=>['CameraHost'=>'192.168.107.96','CameraPort'=>80,'Role'=>'JV_LEFT','Enabled'=>true,'Username'=>'do-not-leak-jv','Password'=>'secret-jv'],
    27938=>['CameraHost'=>'192.168.107.99','CameraPort'=>80,'Role'=>'WORK_LEFT','Enabled'=>true,'Username'=>'do-not-leak-work','Password'=>'secret-work'],
    9001=>['Host'=>'192.168.107.96','Port'=>80,'Open'=>true],
    9002=>['Host'=>'192.168.107.99','Port'=>80,'Open'=>false]
];
$GLOBALS['variables'] = [
    54893=>true, 55806=>false, 42076=>7,
    51090=>false,33071=>false,34838=>0,
    26298=>json_encode(['reason'=>'HTTP 200 / Stream aktiv','configReady'=>true,'streaming'=>true,'parentOpen'=>true,'authPending'=>false,'authBlocked'=>false,'restartStage'=>0,'lastHttpRequest'=>time()-3,'lastCameraRx'=>time()-2]),
    56881=>json_encode(['reason'=>'WATCHDOG: HTTP/Digest-Handshake ausstehend','configReady'=>true,'streaming'=>false,'parentOpen'=>false,'authPending'=>true,'authBlocked'=>false,'restartStage'=>1,'lastHttpRequest'=>time()-19,'lastCameraRx'=>0]),
    73111=>json_encode(['time'=>'18:01:22','role'=>'JV_LEFT','decision'=>'RULE_OR_EVENTCODE_REJECTED','code'=>'CrossRegionDetection','action'=>'Start','ruleName'=>'OTHER_RULE','ruleId'=>7,'eventId'=>11,'objectId'=>5,'eventsTotal'=>9,'crossRegionTotal'=>2,'ruleMatches'=>0,'ruleRejected'=>2,'dedupeRejected'=>0,'humanCount'=>0]),
    73112=>json_encode(['time'=>'18:01:33','role'=>'WORK_LEFT','decision'=>'HUMAN_START_COUNTED','code'=>'CrossRegionDetection','action'=>'Start','ruleName'=>'P03_WORK_LEFT_HUMAN','ruleId'=>4,'eventId'=>17,'objectId'=>3,'eventsTotal'=>40,'crossRegionTotal'=>8,'ruleMatches'=>5,'ruleRejected'=>3,'dedupeRejected'=>2,'humanCount'=>3])
];
$GLOBALS['byIdent'] = [
    57215=>['StreamOK'=>54893,'PersonDetected'=>55806,'HumanEventCounter'=>42076,'ObserverStatus'=>26298,'EventAudit'=>73111],
    27938=>['StreamOK'=>51090,'PersonDetected'=>33071,'HumanEventCounter'=>34838,'ObserverStatus'=>56881,'EventAudit'=>73112]
];
$GLOBALS['writes'] = [];

function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['instances'][$id]); }
function IPS_GetInstance(int $id): array { return $GLOBALS['instances'][$id]; }
function IPS_GetInstanceList(): array { return array_keys($GLOBALS['instances']); }
function IPS_GetInstanceListByModuleID(string $id): array { return ($GLOBALS['emptyModuleLookup'] ?? false) ? [] : [57215,27938]; }
function IPS_GetProperty(int $id,string $name): mixed { return $GLOBALS['props'][$id][$name] ?? null; }
function IPS_GetObjectIDByIdent(string $ident,int $id): int { return $GLOBALS['byIdent'][$id][$ident] ?? 0; }
function IPS_VariableExists(int $id): bool { return array_key_exists($id,$GLOBALS['variables']) || in_array($id,[20001,20002,20003,20004],true); }
function GetValue(int $id): mixed { return $GLOBALS['variables'][$id] ?? ''; }
function IPS_ApplyChanges(int $id): void { throw new RuntimeException('Forbidden mutation: IPS_ApplyChanges'); }
function IPS_SetProperty(int $id,string $key,mixed $value): void { throw new RuntimeException('Forbidden mutation: IPS_SetProperty'); }
function IPS_CreateInstance(string $guid): int { throw new RuntimeException('Forbidden mutation: IPS_CreateInstance'); }

class IPSModule
{
    public int $InstanceID=53879;
    public array $attributes=[
        'AuxJVInstanceID'=>57215,
        'AuxWorkInstanceID'=>27938
    ];
    public array $properties=[
        'AuxJVHost'=>'192.168.107.96',
        'AuxWorkHost'=>'192.168.107.99'
    ];
    public array $values=['P03ConnectionDiagnosis'=>'','Result'=>'','P03IPC5442Audit'=>'','P03OwnershipAudit'=>''];
    public bool $reload=false;
    public function Create():void {}
    public function ApplyChanges():void {}
    protected function ReadAttributeInteger(string $name):int { return (int) ($this->attributes[$name]??0); }
    protected function ReadAttributeString(string $name):string { return (string) ($this->attributes[$name]??''); }
    protected function WriteAttributeString(string $name,string $value):void { $this->attributes[$name]=$value; }
    protected function WriteAttributeInteger(string $name,int $value):void { $this->attributes[$name]=$value; }
    protected function ReadPropertyString(string $name):string { return (string)($this->properties[$name]??''); }
    protected function GetIDForIdent(string $ident):int {return ['P03ConnectionDiagnosis'=>20001,'Result'=>20002,'P03IPC5442Audit'=>20003,'P03OwnershipAudit'=>20004][$ident]??0;}
    protected function GetValue(string $ident):mixed { return $this->values[$ident]??null; }
    protected function SetValue(string $ident,mixed $value):void {
        $this->values[$ident]=$value;
        $var=$this->GetIDForIdent($ident);
        if ($var) $GLOBALS['variables'][$var]=$value;
    }
    protected function ReloadForm():void {$this->reload=true;}
}

require_once dirname(__DIR__).'/JVPresenceP03TerraceTest/module.php';

function verifyDiag(bool $passed,string $what):void
{
    if (!$passed) throw new RuntimeException('FAIL: '.$what);
    echo 'OK: '.$what."\n";
}
$instance=new JVPresenceP03MultiCamera();
$instance->DiagnoseP03Connections();
$report=(string) $instance->values['P03ConnectionDiagnosis'];
verifyDiag(str_contains($report,'JV_LEFT') && str_contains($report,'WORK_LEFT'),'both mast roles included');
verifyDiag(str_contains($report,'57215') && str_contains($report,'27938'),'both observer IDs included');
verifyDiag(str_contains($report,'54893') && str_contains($report,'51090'),'both stream IDs included');
verifyDiag(str_contains($report,'9001') && str_contains($report,'9002'),'both client socket IDs included');
verifyDiag(str_contains($report,'Socket Status: 102') && str_contains($report,'Socket Status: 104'),'open and closed sockets distinguished');
verifyDiag(str_contains($report,'Socket geteilt mit Instanz(en) #77777'),'foreign socket owner flagged');
verifyDiag(str_contains($report,'authPending=JA'),'stalled Digest workflow identified');
verifyDiag(str_contains($report,'Human-Zähler: 7'),'real variable values read');
verifyDiag(str_contains($report,'Entscheidung=RULE_OR_EVENTCODE_REJECTED'),
    'JV raw IVS exists but wrong rule is explicitly identified');
verifyDiag(str_contains($report,'Entscheidung=HUMAN_START_COUNTED'),
    'WORK valid Human START explicitly identified');
verifyDiag(str_contains($report,'RegelAbgewiesen=2'),
    'rule mismatch counter included in one-click diagnostic');
verifyDiag(str_contains($report,'Duplikate=2'),
    'rejected duplicate counter included in one-click diagnostic');
verifyDiag(!str_contains($report,'secret-jv') && !str_contains($report,'secret-work'),'credentials not exposed');
verifyDiag(!str_contains($report,'do-not-leak-jv') && !str_contains($report,'do-not-leak-work'),'username not exposed');
verifyDiag(str_contains($report,'Nur Istzustand erfasst'),'read-only report is explicit');
verifyDiag($instance->reload,'configuration form reloaded after report generated');
verifyDiag($GLOBALS['writes']===[],'no state mutations in unrelated modules');
$ui=json_decode($instance->GetConfigurationForm(),true);
verifyDiag(is_array($ui),'configuration form returns valid JSON');
verifyDiag(str_contains(json_encode($ui),'P03 SOCKET-DIAGNOSE'),'generated report visible in configuration form');
$onClicks=array_column($ui['actions']??[],'onClick');
verifyDiag(in_array('JVP03MC_DiagnoseP03Connections($id);',$onClicks,true),'one-click button wired to diagnosis method');
// Read-only IPC-HFW5442E-ZE model-config audit: replace transport with
// camera-local simulated GETs. No camera / foreign module write is possible.
class SimulatedIPC5442Main extends JVPresenceP03MultiCamera
{
    public array $uris=[];
    protected function readIPC5442Camera(
        string $host, int $port, string $username, string $password, string $uri
    ): array {
        if ($username === '' || $password === '') {
            throw new RuntimeException('credentials must be read from P03 observer');
        }
        $this->uris[]=$host.' '.$uri;
        $body = match(true) {
            str_contains($uri, 'getDeviceType') => "type=IPC-HFW5442E-ZE\r\n",
            str_contains($uri, 'getSoftwareVersion') => "version=2.840.0000000.28.R\r\n",
            str_contains($uri, 'name=MotionDetect') =>
                "MotionDetect[0].Enable=true\r\nMotionDetect[0].Region[0].Threshold=11\r\nMotionDetect[0].EventHandler.TimeSection[0][0]=1 00:00:00-23:59:59\r\n",
            str_contains($uri, 'name=SmartMotionDetect') =>
                "SmartMotionDetect[0].Enable=true\r\nSmartMotionDetect[0].ObjectTypes.Human=true\r\nSmartMotionDetect[0].Sensitivity=High\r\nSmartMotionDetect[0].AdminPassword=never-log-this\r\n",
            str_contains($uri, 'name=SmartEncode') =>
                "table.SmartEncode[0].Enable=false\r\ntable.SmartEncode[0].Extra[0]=false\r\n",
            str_contains($uri, 'name=VideoAnalyseGlobal') =>
                "VideoAnalyseGlobal[0].Scene.Type=Normal\r\n",
            str_contains($uri, 'name=VideoAnalyseModule') =>
                "table.VideoAnalyseModule[0][0].Type=Normal\r\n"
                . "table.VideoAnalyseModule[0][0].Sensitivity=10\r\n"
                . "table.VideoAnalyseModule[0][1].Type=NumberStat\r\n"
                . "table.VideoAnalyseModule[0][1].Enable=false\r\n"
                . "table.VideoAnalyseModule[0][0].Password=NOT_FOR_LOGGING\r\n",
            str_contains($uri, 'name=VideoAnalyseRule') =>
                "table.VideoAnalyseRule[0][0].Class=HeatMap\r\n"
                . "table.VideoAnalyseRule[0][0].Enable=false\r\n"
                . "table.VideoAnalyseRule[0][3].Class=Normal\r\n"
                . "table.VideoAnalyseRule[0][3].Type=CrossRegionDetection\r\n"
                . "table.VideoAnalyseRule[0][3].Id=4\r\n"
                . "table.VideoAnalyseRule[0][3].Name="
                    . ($host === '192.168.107.96' ? 'P03_JV_LEFT_HUMAN' : 'P03_WORK_LEFT_HUMAN')
                    . "\r\n"
                . "table.VideoAnalyseRule[0][3].Enable=true\r\n"
                . "table.VideoAnalyseRule[0][3].ObjectTypes[0]=Human\r\n"
                . "table.VideoAnalyseRule[0][3].Config.DetectRegion[0][0]=256\r\n"
                . "table.VideoAnalyseRule[0][3].Config.DetectRegion[0][1]=7935\r\n",
            default => ''
        };
        return ['ok'=>true,'http'=>200,'body'=>$body,'error'=>''];
    }
}
$ipc=new SimulatedIPC5442Main();
$ipc->attributes['AuxJVHumanRuleIndex']=3;
$ipc->attributes['AuxWorkHumanRuleIndex']=3;
$ipc->attributes['AuxJVHumanRuleID']=4;
$ipc->attributes['AuxWorkHumanRuleID']=4;
$ipc->DiagnoseIPC5442Configuration();
$modelReport=(string)$ipc->values['P03IPC5442Audit'];
verifyDiag($modelReport !== '' && (string)$ipc->values['P03ConnectionDiagnosis'] === $modelReport,
    'model CGI audit also writes complete report into existing copyable socket diagnosis slot (#35115 on SymBox)');
verifyDiag(($GLOBALS['variables'][20001] ?? null) === $modelReport,
    'model audit writes known, mapped report variable ID directly, not an unknown ID');
verifyDiag(substr_count($modelReport, 'IPC-HFW5442E-ZE')>=2,
    'actual model readback for both P03 cameras');
verifyDiag(str_contains($modelReport,'SmartMotionDetect[0].ObjectTypes.Human=true'),
    'model SMD human detection exposed');
verifyDiag(str_contains($modelReport,'MotionDetect[0].Region[0].Threshold=11'),
    'model-specific MD detection region readback exposed');
verifyDiag(substr_count($modelReport,'Regel [0][3]: Enable=true')===2
    && substr_count($modelReport,'ABGLEICH: RuleName=PASST | Enable=JA | Type=PASST')===2,
    'both P03 IVS rules are visible and matched, despite prior HeatMap-first truncation');
verifyDiag(str_contains($modelReport, 'Config.DetectRegion[0][0]=256'),
    'P03 IVS region detail is shown, rather than only HeatMap TimeSections');
verifyDiag(substr_count($modelReport, 'KI-RESSOURCEN (VideoAnalyseModule')===2,
    'both 5442 cameras report Smart Plan resource table in model audit');
verifyDiag(str_contains($modelReport, 'VideoAnalyseModule[0][0].Type=Normal')
    && str_contains($modelReport, 'VideoAnalyseModule[0][1].Type=NumberStat'),
    'live model resource candidates reported without falsely selecting one');
verifyDiag(!str_contains($modelReport, 'NOT_FOR_LOGGING'),
    'camera secret in additional module resource table is redacted');
verifyDiag(substr_count($modelReport,'SMART-CODEC STATUS: AUS')===2
    && substr_count($modelReport,'SmartEncode[0].Enable=false')===2,
    'both 5442 IPCs independently report SmartCodec main stream state');
verifyDiag(count(array_filter($ipc->uris,static fn($uri) =>
    str_contains($uri, 'name=SmartEncode')))===2,
    'two exact read-only SmartEncode getConfig requests via SymBox');
verifyDiag(!str_contains($modelReport,'never-log-this'),
    'camera security fields redacted');
verifyDiag(count($ipc->uris)===16,'only eight GET requests for each camera (read only)');
verifyDiag(count(array_filter($ipc->uris,static fn($uri)=>
    str_contains($uri,'setConfig') || str_contains($uri,'setProperty') || str_contains($uri,'reboot')))===0,
    'only GET diagnostic commands, no camera modifications');
verifyDiag($GLOBALS['writes']===[],'no foreign module or camera writes');
$ipcUI=json_decode($ipc->GetConfigurationForm(),true);
verifyDiag(str_contains((string)json_encode($ipcUI,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'SMD/IVS-SPEZIALDIAGNOSE'),
    'one-click model report appears directly in Symcon form');
$buttons=array_column($ipcUI['actions']??[],'onClick');
verifyDiag(in_array('JVP03MC_DiagnoseIPC5442Configuration($id);',$buttons,true),
    'one-click model-specific read-only button correctly wired');

// Forensics must work even when the main P03 instance lost the stored IDs
// and IPS_GetInstanceListByModuleID returns EMPTY after module update.
$instance->DiagnoseP03Ownership();
$ownership=(string)$instance->values['P03OwnershipAudit'];
verifyDiag(str_contains($ownership,'Observer #57215: EXISTIERT')
    && str_contains($ownership,'Observer #27938: EXISTIERT'),
    'forensic read-only scan identifies both historical Observer IDs');
verifyDiag(str_contains($ownership,'Gespeicherte Observer-ID: 57215')
    && str_contains($ownership,'letzte bekannte ID: 27938'),
    'reports stored identity alongside historical identity');
verifyDiag(str_contains($ownership,'Keine Instanz-, Kamera-, Modul- oder Socketänderung'),
    'forensic tool advertises no-modification contract');
$GLOBALS['emptyModuleLookup']=true;
$instance->attributes['AuxJVInstanceID']=0;
$instance->attributes['AuxWorkInstanceID']=0;
$finder=new ReflectionMethod(JVPresenceP03MultiCamera::class,'findP03AuxObserver');
verifyDiag($finder->invoke($instance,'192.168.107.96','JV_LEFT')===57215
    && $finder->invoke($instance,'192.168.107.99','WORK_LEFT')===27938,
    'whole-instance fallback locates valid P03 Observer when module index is empty');
$discover=new ReflectionMethod(JVPresenceP03MultiCamera::class,'discoverP03AuxSources');
$found=$discover->invoke($instance,false);
verifyDiag($found['jv']===57215 && $found['work']===27938,
    'read-only discovery recovers known instances without creating new ones');
verifyDiag($instance->attributes['AuxJVInstanceID']===57215
    && $instance->attributes['AuxWorkInstanceID']===27938,
    'valid recovered Observer IDs stored after accurate GUID/host/role validation');

// After a module unload/deletion, never turn an old known Observer ID into
// 0, never create a new observer or trust leftover StreamOK Boolean.
unset($GLOBALS['instances'][57215],$GLOBALS['instances'][27938]);
$GLOBALS['variables'][54893]=true; $GLOBALS['variables'][51090]=true;
$beforeJV=$instance->attributes['AuxJVInstanceID'];
$beforeWork=$instance->attributes['AuxWorkInstanceID'];
$failed=$discover->invoke($instance,false);
verifyDiag($failed['jv']===0 && $failed['work']===0,
    'an absent P03 observer is not falsely discovered');
verifyDiag($instance->attributes['AuxJVInstanceID']===$beforeJV
    && $instance->attributes['AuxWorkInstanceID']===$beforeWork,
    'failed lookup NEVER destroys last saved Observer IDs');
$streamReady=new ReflectionMethod(JVPresenceP03MultiCamera::class,'p03AuxStreamsReady');
verifyDiag($streamReady->invoke($instance)===false,
    'orphaned camera fails closed even if StreamOK remains true');
$instance->DiagnoseP03Ownership();
$ownership=(string)$instance->values['P03OwnershipAudit'];
verifyDiag(str_contains($ownership,'Observer #57215: IPS_InstanceExists=NEIN')
    && str_contains($ownership,'Observer #27938: IPS_InstanceExists=NEIN'),
    'forensic report distinguishes confirmed missing instances from lookup mismatch');
verifyDiag($GLOBALS['writes']===[],
    'forensic and noncreating discovery issue NO camera, socket or foreign module writes');
$ui2=json_decode($instance->GetConfigurationForm(),true);
verifyDiag(in_array('JVP03MC_DiagnoseP03Ownership($id);',
    array_column($ui2['actions']??[],'onClick'),true),
    'ownership diagnostic one-click button wired on the real main module');

echo "P03 READ-ONLY CONNECTION DIAGNOSIS TEST PASSED\n";
