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
    73112=>json_encode(['time'=>'18:01:33','role'=>'WORK_LEFT','decision'=>'HUMAN_START_COUNTED','code'=>'CrossRegionDetection','action'=>'Start','ruleName'=>'P03_WORK_LEFT_HUMAN','ruleId'=>4,'eventId'=>17,'objectId'=>3,'eventsTotal'=>40,'crossRegionTotal'=>8,'ruleMatches'=>5,'ruleRejected'=>3,'dedupeRejected'=>2,'humanCount'=>3]),
    73113=>json_encode([
        ['time'=>'17:20:01','code'=>'CrossLineDetection','action'=>'Start',
            'ruleName'=>'Überquerung zur Treppe','cfgRuleId'=>4,'ruleIdUpper'=>3,
            'streamIndex'=>0,'classification'=>'Human','expectedRuleId'=>4,
            'decision'=>'STAIR_IVS_NOT_MAPPED_OR_NOT_HUMAN',
            'rejectionReason'=>'CAMERA_EVENT_RULE_ID_CONFLICT']
    ],JSON_UNESCAPED_UNICODE)
];
$GLOBALS['byIdent'] = [
    57215=>['StreamOK'=>54893,'PersonDetected'=>55806,'HumanEventCounter'=>42076,'ObserverStatus'=>26298,'EventAudit'=>73111,'LastIVSEvent'=>73113],
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

// P03-only documents, never foreign module/camera changes. Document APIs
// mimic real Symcon document media type 5 and immutable parent ownership.
$GLOBALS['media']=[]; $GLOBALS['mediaByIdent']=[]; $GLOBALS['nextMediaID']=91000;
function IPS_CreateMedia(int $type): int {
    if ($type!==5) throw new RuntimeException('Only document media allowed');
    $id=++$GLOBALS['nextMediaID'];
    $GLOBALS['media'][$id]=['MediaType'=>$type,'MediaFile'=>'','content'=>'','ParentID'=>0];
    return $id;
}
function IPS_SetParent(int $id,int $parent): bool {
    if ($parent!==53879 || !isset($GLOBALS['media'][$id]))
        throw new RuntimeException('Foreign parent mutation');
    $GLOBALS['media'][$id]['ParentID']=$parent;
    return true;
}
function IPS_SetIdent(int $id,string $ident): bool {
    if (!isset($GLOBALS['media'][$id])) throw new RuntimeException('Foreign media ident');
    $GLOBALS['mediaByIdent'][$ident]=$id;
    $GLOBALS['byIdent'][53879][$ident]=$id;
    return true;
}
function IPS_SetName(int $id,string $title): bool {
    if (!isset($GLOBALS['media'][$id])) throw new RuntimeException('Foreign media rename');
    $GLOBALS['media'][$id]['name']=$title;
    return true;
}
function IPS_MediaExists(int $id): bool { return isset($GLOBALS['media'][$id]); }
function IPS_GetMedia(int $id): array { return $GLOBALS['media'][$id]; }
function IPS_GetChildrenIDs(int $id): array {
    if ($id!==53879) throw new RuntimeException('Unexpected foreign child scan');
    return array_keys(array_filter($GLOBALS['media'],static fn($m)=>($m['ParentID']??0)===53879));
}
function IPS_GetObject(int $id): array {
    if (!isset($GLOBALS['media'][$id])) throw new RuntimeException('Foreign IPS_GetObject');
    $ident=array_search($id,$GLOBALS['mediaByIdent'],true);
    return ['ParentID'=>$GLOBALS['media'][$id]['ParentID'],
        'ObjectIdent'=>$ident===false?'':(string)$ident];
}
function IPS_SetMediaFile(int $id,string $filename,bool $mustExist): bool {
    if (!isset($GLOBALS['media'][$id]) || !str_starts_with($filename,'media/P03_IPC5442_'))
        throw new RuntimeException('Foreign media path');
    $GLOBALS['media'][$id]['MediaFile']=$filename;
    return true;
}
function IPS_SetMediaContent(int $id,string $base64): bool {
    if (!isset($GLOBALS['media'][$id])) throw new RuntimeException('Foreign media content');
    $text=base64_decode($base64,true);
    if ($text===false) throw new RuntimeException('Invalid base64');
    $GLOBALS['media'][$id]['content']=$text;
    return true;
}



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
    protected function ReadAttributeBoolean(string $name):bool { return (bool) ($this->attributes[$name]??false); }
    protected function ReadPropertyBoolean(string $name):bool { return (bool) ($this->properties[$name]??false); }
    protected function ReadPropertyInteger(string $name):int { return (int) ($this->properties[$name]??0); }
    protected function ReadAttributeString(string $name):string { return (string) ($this->attributes[$name]??''); }
    protected function WriteAttributeString(string $name,string $value):void { $this->attributes[$name]=$value; }
    protected function WriteAttributeInteger(string $name,int $value):void { $this->attributes[$name]=$value; }
    protected function ReadPropertyString(string $name):string { return (string)($this->properties[$name]??''); }
    protected function WriteAttributeBoolean(string $name,bool $value):void { $this->attributes[$name]=$value; }
    protected function GetIDForIdent(string $ident):int {return ['P03ConnectionDiagnosis'=>20001,'Result'=>20002,'P03IPC5442Audit'=>20003,'P03OwnershipAudit'=>20004][$ident]??0;}
    protected function GetValue(string $ident):mixed { return $this->values[$ident]??null; }
    protected function SetValue(string $ident,mixed $value):void {
        $this->values[$ident]=$value;
        $var=$this->GetIDForIdent($ident);
        if ($var) $GLOBALS['variables'][$var]=$value;
    }
    protected function ReloadForm():void {$this->reload=true;}
    protected function SendDebug(string $caption,string $message,int $format):void {}
    public array $timers=[];
    protected function SetTimerInterval(string $name,int $ms):void {$this->timers[$name]=$ms;}
}

require_once dirname(__DIR__).'/JVPresenceP03TerraceTest/module.php';

function verifyDiag(bool $passed,string $what):void
{
    if (!$passed) throw new RuntimeException('FAIL: '.$what);
    echo 'OK: '.$what."\n";
}
// Real 5442 configuration dumps have an enabled foreign people counting
// NumberStat1 rule in BOTH cameras alongside the owned P03 IVS rule.
// The normal SCENE TYPE is not proof of IVS Smart-Plan activation.
$planInspector=new ReflectionMethod(JVPresenceP03MultiCamera::class,'p03ForeignAIPlans');
$observedRules=[
    ['Name'=>'HeatMap1','Type'=>'HeatMap','Class'=>'HeatMap','Enable'=>false],
    ['Name'=>'FaceDetection','Type'=>'FaceDetection','Class'=>'FaceDetection','Enable'=>false],
    ['Name'=>'NumberStat1','Type'=>'NumberStat','Class'=>'NumberStat','Enable'=>true],
    ['Name'=>'P03_JV_LEFT_HUMAN','Type'=>'CrossRegionDetection','Class'=>'Normal','Enable'=>true],
];
$foreign=$planInspector->invoke(null,$observedRules,'P03_JV_LEFT_HUMAN');
verifyDiag(str_contains($foreign,'NumberStat1 (NumberStat/Class=NumberStat)'),
    'enabled foreign NumberStat people counting is flagged as competing AI-plan candidate');
verifyDiag(!str_contains($foreign,'P03_JV_LEFT_HUMAN')
    && !str_contains($foreign,'FaceDetection') && !str_contains($foreign,'HeatMap1'),
    'owned IVS rule and inactive camera templates are never misreported as foreign active AI');
$observedRules[2]['Enable']=false;
verifyDiag($planInspector->invoke(null,$observedRules,'P03_JV_LEFT_HUMAN')==='KEINE',
    'disabled foreign NumberStat does not generate a false AI-resource warning');

$instance=new JVPresenceP03MultiCamera();
$instance->DiagnoseP03Connections();
$report=(string) $instance->values['P03ConnectionDiagnosis'];
verifyDiag(str_contains($report,'JV_LEFT') && str_contains($report,'WORK_LEFT'),'both mast roles included');
verifyDiag(str_contains($report,'JV TERRASSE: AUS / IGNORIERT')
    && !str_contains($report,'TERRACE Observer-ID:'),
    'default-disabled third terrace source stays ignored and does not enter mast proof');
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
verifyDiag(str_contains($report,'LETZTE CROSSLINE-EREIGNISSE: 1')
    && str_contains($report,'CAMERA_EVENT_RULE_ID_CONFLICT')
    && str_contains($report,'cfgRuleId=4')
    && str_contains($report,'RuleID=3'),
    'frozen CrossLine evidence survives later generic EventAudit and explains exact reject reason');
verifyDiag(str_contains($report,'Duplikate=2'),
    'rejected duplicate counter included in one-click diagnostic');
// Regression for live 17:40: histogram iteration must not overwrite
// the current camera role and therefore mislabel #55806 as #33071.
$originalJvDiag=$GLOBALS['variables'][26298];
$GLOBALS['variables'][26298]=json_encode([
    'streaming'=>true,'streamOK'=>true,
    'observedCodeCounts'=>json_encode(['CrossLineDetection'=>3,'SmartMotionHuman'=>2]),
    'smdHumanRawCount'=>2,'lastSmdHumanStartAt'=>time()-25,
    'proofMode'=>'EXISTING_STAIR_IVS','allowedStairRules'=>[
        ['name'=>'Überquerung zur Treppe','id'=>4,'tableIndex'=>3,'humanOnly'=>true]
    ]
],JSON_UNESCAPED_UNICODE);
$instance->DiagnoseP03Connections();
$withCodes=(string)$instance->values['P03ConnectionDiagnosis'];
$jvPart=explode('--- WORK_LEFT',$withCodes)[0];
verifyDiag(str_contains($jvPart,'CrossLineDetection=3')
    && str_contains($jvPart,'letzter Human-START')
    && str_contains($jvPart,'Variable #55806 schaltet')
    && !str_contains($jvPart,'Variable #33071 schaltet'),
    '17:40 JV_LEFT histogram does not overwrite correct visible PersonDetected ID');
$GLOBALS['variables'][26298]=$originalJvDiag;
verifyDiag(!str_contains($report,'secret-jv') && !str_contains($report,'secret-work'),'credentials not exposed');
verifyDiag(!str_contains($report,'do-not-leak-jv') && !str_contains($report,'do-not-leak-work'),'username not exposed');
verifyDiag(str_contains($report,'Nur Istzustand erfasst'),'read-only report is explicit');
verifyDiag(!$instance->reload,
    'diagnosis never reloads the configuration form or interrupts button access');
verifyDiag($GLOBALS['writes']===[],'no state mutations in unrelated modules');
$ui=json_decode($instance->GetConfigurationForm(),true);
verifyDiag(is_array($ui),'configuration form returns valid JSON');
verifyDiag(!str_contains((string)json_encode($ui),'P03 SOCKET-DIAGNOSE')
    && !str_contains((string)json_encode($ui),'P03 Socket-Diagnose – Bericht'),
    'large periodically updated socket diagnosis is never embedded in the configuration form');
verifyDiag(!str_contains((string)json_encode($ui),'P03 HUMAN ROH'),
    'changing P03 protocol entries cannot appear inside the static configuration form');
// Even a very large auto-refreshing diagnosis/protocol must never affect
// the static form content or change button positions while the user clicks.
$originalForm=$instance->GetConfigurationForm();
$instance->values['P03ConnectionDiagnosis']=str_repeat('P03 LIVE REPORT ' . time() . "\n",2000);
$instance->values['P03IPC5442Audit']=str_repeat('KAMERA-AUDIT\n',2000);
$instance->values['P03OwnershipAudit']='P03 OWNERSHIP REPORT';
$instance->values['Protocol']='P03 HUMAN ROH JV_LEFT counter 7000';
$instance->values['Result']='Changing status after UI was opened';
verifyDiag($instance->GetConfigurationForm()===$originalForm,
    'changing diagnoses, log and status cannot rebuild or resize the form');
verifyDiag(!str_contains($originalForm,'ExpansionPanel') || 
    !str_contains($originalForm,'P03 Socket-Diagnose – Bericht'),
    'diagnostic report expansions removed without removing ordinary settings');
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
verifyDiag(!str_contains((string)json_encode($ipcUI,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'SMD/IVS-SPEZIALDIAGNOSE'),
    'even manually requested large model audit remains exclusively in instance variables');
verifyDiag(!$ipc->reload,'model audit never triggers a form rebuild');
$buttons=array_column($ipcUI['actions']??[],'onClick');
verifyDiag(in_array('JVP03MC_DiagnoseIPC5442Configuration($id);',$buttons,true),
    'one-click model-specific read-only button correctly wired');

// v0.7.9: live 16:59 field test shows SMD Human on both cameras but
// ZERO CrossLine events. Inspect the REAL staircase IVS, not the deprecated
// P03 CrossRegion rectangle; surface notification settings without writes.
$savedJvHost=$GLOBALS['props'][57215]['CameraHost'];
$savedWorkHost=$GLOBALS['props'][27938]['CameraHost'];
$GLOBALS['props'][57215]['CameraHost']='192.168.107.95';
$GLOBALS['props'][27938]['CameraHost']='192.168.107.94';
$liveStairAudit=new class extends SimulatedIPC5442Main {
    protected function readIPC5442Camera(
        string $host,int $port,string $username,string $password,string $uri
    ):array {
        if (str_contains($uri,'name=VideoAnalyseRule')) {
            $this->uris[]=$host.' '.$uri;
            $lines=[
                'table.VideoAnalyseRule[0][0].Name=HeatMap1',
                'table.VideoAnalyseRule[0][0].Type=HeatMap',
                'table.VideoAnalyseRule[0][0].Enable=false',
                'table.VideoAnalyseRule[0][3].Name=Überquerung zur Treppe',
                'table.VideoAnalyseRule[0][3].Type=CrossLineDetection',
                'table.VideoAnalyseRule[0][3].Class=Normal',
                'table.VideoAnalyseRule[0][3].Enable=true',
                'table.VideoAnalyseRule[0][3].Id=4',
                'table.VideoAnalyseRule[0][3].ObjectTypes[0]=Human',
                'table.VideoAnalyseRule[0][3].EventHandler.MessageEnable='
                    .($host==='192.168.107.95'?'false':'true'),
                'table.VideoAnalyseRule[0][3].Config.DetectLine[0][0]=1000',
                'table.VideoAnalyseRule[0][3].Config.DetectLine[0][1]=2000',
                'table.VideoAnalyseRule[0][3].EventHandler.TimeSection[0][0]=1 00:00:00-23:59:59',
            ];
            if ($host==='192.168.107.94') {
                $lines[]='table.VideoAnalyseRule[0][3].ObjectTypes[1]=Vehicle';
                $lines[]='table.VideoAnalyseRule[0][4].Name=Überquerung zur Straße';
                $lines[]='table.VideoAnalyseRule[0][4].Type=CrossLineDetection';
                $lines[]='table.VideoAnalyseRule[0][4].Enable=true';
                $lines[]='table.VideoAnalyseRule[0][4].Id=5';
                $lines[]='table.VideoAnalyseRule[0][4].ObjectTypes[0]=Human';
            }
            return ['ok'=>true,'http'=>200,'body'=>implode("\r\n",$lines)."\r\n",'error'=>''];
        }
        return parent::readIPC5442Camera($host,$port,$username,$password,$uri);
    }
};
$liveStairAudit->properties['AuxJVHost']='192.168.107.95';
$liveStairAudit->properties['AuxWorkHost']='192.168.107.94';
$liveStairAudit->attributes['AuxJVAllowedIVSRules']=json_encode([
    ['name'=>'Überquerung zur Treppe','id'=>4,'tableIndex'=>3,
        'humanOnly'=>true,'objects'=>['human']]
],JSON_UNESCAPED_UNICODE);
$liveStairAudit->attributes['AuxWorkAllowedIVSRules']=json_encode([
    ['name'=>'Überquerung zur Treppe','id'=>4,'tableIndex'=>3,
        'humanOnly'=>false,'objects'=>['human','vehicle']]
],JSON_UNESCAPED_UNICODE);
$liveStairAudit->DiagnoseIPC5442Configuration();
$stairReport=(string)$liveStairAudit->values['P03IPC5442Audit'];
verifyDiag(substr_count($stairReport,'P03 AKTUELLES TREPPEN-PRUEFZIEL: Überquerung zur Treppe')===2
    && substr_count($stairReport,'P03 AKTUELLER CGI-GEGENCHECK: IDENTISCH')===2
    && substr_count($stairReport,'ABGLEICH: RuleName=PASST | Enable=JA | Type=PASST')===2,
    'actual 16:59 left-camera stair CrossLine rule ID4 verified on both, never obsolete rectangle');
verifyDiag(substr_count($stairReport,'IVS EREIGNISMELDUNG EventHandler.MessageEnable=')===2
    && str_contains($stairReport,'EventHandler.MessageEnable=false | WARNUNG:')
    && str_contains($stairReport,'EventHandler.MessageEnable=true'),
    'read-only camera audit reveals possible disabled IVS event notification on the correct rule');
verifyDiag(str_contains($stairReport,'Mensch/Fahrzeug-Treppenlinie')
    && str_contains($stairReport,'Überquerung zur Straße')
    && !str_contains($stairReport,'P03_WORK_LEFT_HUMAN'),
    'mixed Human/Vehicle stair evidence flagged and street remains non-target');
verifyDiag((string)$liveStairAudit->values['P03ConnectionDiagnosis']===$stairReport
    && count($liveStairAudit->uris)===16 && $GLOBALS['writes']===[],
    'corrected diagnostic still uses exactly eight camera GETs per camera and no write APIs');
$GLOBALS['props'][57215]['CameraHost']=$savedJvHost;
$GLOBALS['props'][27938]['CameraHost']=$savedWorkHost;

// Explicit user demand: ALL original camera config values, no relevance
// whitelist and no first-48/80-line truncation. Store FULL redacted texts in
// two Symcon DOCUMENT objects under P03 #53879; known #35115 gives IDs.
class SimulatedFullIPC5442Main extends SimulatedIPC5442Main
{
    public array $fullRequests=[];
    protected function readIPC5442CompleteConfiguration(
        string $host,int $port,string $username,string $password,string $uri
    ): array {
        if ($username==='' || $password==='') throw new RuntimeException('No credentials');
        $this->fullRequests[]=$host.' '.$uri;
        if ($host==='192.168.107.99' && str_contains($uri,'getConfig&name=All')) {
            return ['ok'=>false,'http'=>404,'error'=>'unsupported','body'=>'ERROR: unsupported'];
        }
        if (str_contains($uri,'Config.backup?action=All')) {
            $json=['General'=>['Language'=>'de','Password'=>'VERY_PRIVATE'],
                'VideoAnalyseRule'=>[[['Enable'=>true,
                    'Config'=>['UnknownNewField'=>['Alpha','Beta']]]]],
                'UnrelatedSettings'=>['NeverCurated'=>'backup-surprise']];
            return ['ok'=>true,'http'=>200,'error'=>'',
                'body'=>json_encode($json,JSON_UNESCAPED_UNICODE)];
        }
        $lines=[
            'table.SmartMotionDetect[0].Enable=true',
            'table.VideoAnalyseRule[0][3].Enable=true',
            'table.UnrelatedSettings.DeviceFoo=unlisted-by-all-our-other-filters',
            'table.General.Password=PASSWORD_SHOULD_NOT_LEAK',
            'table.Network.Ethernet.PhysicalAddress=AA:BB:CC:DD:EE:FF',
            'table.UnusualString.EqualSign=x=y=z'
        ];
        for ($i=0;$i<750;$i++) {
            $lines[]='table.ArbitraryValue['.$i.'].Anything='.$i;
        }
        return ['ok'=>true,'http'=>200,'error'=>'','body'=>implode("\r\n",$lines)."\r\n"];
    }
}
$full=new SimulatedFullIPC5442Main();
// Warnings are errors in the test. First export has no existing TXT documents.
set_error_handler(static function(int $severity,string $message):bool {
    throw new ErrorException($message,0,$severity);
});
try {
    $full->DiagnoseIPC5442AllValues();
} finally {
    restore_error_handler();
}
$summary=(string)$full->values['P03ConnectionDiagnosis'];
verifyDiag(str_contains($summary,'IP-Symcon DOKUMENT-ID: 91001')
    && str_contains($summary,'IP-Symcon DOKUMENT-ID: 91002'),
    'known report #35115 lists EXACT generated TXT document IDs');
verifyDiag(count($GLOBALS['media'])===2
    && count($full->fullRequests)===3,
    'read-only two-camera all-config: one primary GET each and one backup fallback');
verifyDiag(str_contains($GLOBALS['media'][91001]['content'],'ArbitraryValue[749].Anything=749')
    && str_contains($GLOBALS['media'][91001]['content'],'UnrelatedSettings.DeviceFoo=unlisted-by-all-our-other-filters'),
    'all 756 camera values kept WITHOUT thematic whitelist or first-48/80 truncation');
verifyDiag(str_contains($GLOBALS['media'][91001]['content'],'UnusualString.EqualSign=x=y=z'),
    'camera field values containing equals signs preserved exactly');
verifyDiag(str_contains($GLOBALS['media'][91001]['content'],'General.Password=[GESCHWAERZT')
    && !str_contains($GLOBALS['media'][91001]['content'],'PASSWORD_SHOULD_NOT_LEAK')
    && !str_contains($GLOBALS['media'][91001]['content'],'AA:BB:CC:DD:EE:FF'),
    'passwords and physical IDs REDACTED while retaining field names');
verifyDiag(str_contains($GLOBALS['media'][91002]['content'],'NeverCurated=backup-surprise')
    && str_contains($GLOBALS['media'][91002]['content'],'UnknownNewField[1]=Beta')
    && !str_contains($GLOBALS['media'][91002]['content'],'VERY_PRIVATE'),
    'full Config.backup fallback flattens ALL JSON fields, masks secrets');
verifyDiag(str_contains($GLOBALS['media'][91001]['content'],'NICHT ABGESCHNITTEN: JA')
    && str_contains($GLOBALS['media'][91002]['content'],'NICHT ABGESCHNITTEN: JA'),
    'both full TXT documents explicitly confirm no silent truncation');
verifyDiag($GLOBALS['writes']===[],'no socket/foreign instance/camera config modifications');
$mediaIds=array_keys($GLOBALS['media']);
set_error_handler(static function(int $severity,string $message):bool {
    throw new ErrorException($message,0,$severity);
});
try {
    $full->DiagnoseIPC5442AllValues();
} finally {
    restore_error_handler();
}
verifyDiag(array_keys($GLOBALS['media'])===$mediaIds
    && count($GLOBALS['media'])===2,
    'repeat export UPDATES existing two owned documents without duplicating media');
verifyDiag(!in_array('JVP03MC_DiagnoseIPC5442AllValues($id);',
    array_column(json_decode($full->GetConfigurationForm(),true)['actions']??[],'onClick'),true),
    'obsolete full-dump button removed from lean UI; safe backend export remains available');

// A read-only 30-second wire trace must update the test protocol even if no
// new Human event arrives, showing distinguishable camera-level motion traffic.
$trace = new JVPresenceP03MultiCamera();
$trace->attributes['TestActive']=true;
$trace->attributes['AuxJVInstanceID']=57215;
$trace->attributes['AuxWorkInstanceID']=27938;
$trace->attributes['AuxJVEventCounterVarID']=42076;
$trace->attributes['AuxWorkEventCounterVarID']=34838;
$trace->values['Protocol']='';
$oldJvObserverStatus=$GLOBALS['variables'][26298];
$oldWorkObserverStatus=$GLOBALS['variables'][56881];
$GLOBALS['variables'][26298]=json_encode([
    'streaming'=>true,'streamOK'=>true,'wireCodeCount'=>100,
    'observedCodeCounts'=>json_encode(['VideoMotionInfo'=>70,'VideoMotion'=>10]),
    'crossRegionTotal'=>0,'smdHumanRawCount'=>0
]);
$GLOBALS['variables'][56881]=json_encode([
    'streaming'=>true,'streamOK'=>true,'wireCodeCount'=>30,
    'observedCodeCounts'=>json_encode(['VideoMotion'=>3]),
    'crossRegionTotal'=>6,'smdHumanRawCount'=>0
]);
$readTrace=new ReflectionMethod(JVPresenceP03MultiCamera::class,'readP03TestWireSnapshot');
$appendTrace=new ReflectionMethod(JVPresenceP03MultiCamera::class,'appendP03TestWireTraceIfDue');
$trace->attributes['P03TestWireTraceBaseline']=json_encode($readTrace->invoke($trace));
$trace->attributes['P03TestWireTraceAt']=time()-31;
$GLOBALS['variables'][26298]=json_encode([
    'streaming'=>true,'streamOK'=>true,'wireCodeCount'=>110,
    'observedCodeCounts'=>json_encode(['VideoMotionInfo'=>78,'VideoMotion'=>12]),
    'crossRegionTotal'=>0,'smdHumanRawCount'=>0
]);
$appendTrace->invoke($trace);
$liveReport=(string)($trace->values['Protocol']??'');
verifyDiag(str_contains($liveReport,'P03 ROHMONITOR 30s')
    && str_contains($liveReport,'JV_LEFT Stream=LIVE, Wire+=10, Motion+=2, MotionInfo+=8, CrossRegion+=0, CrossLine+=0, SMDHuman+=0, HumanCounter=7'),
    'silent real-world test now logs live motion activity but correctly reports ZERO positive AI events');
verifyDiag(str_contains($liveReport,'WORK_LEFT Stream=LIVE, Wire+=0, Motion+=0'),
    'no motion traffic from second camera is explicitly visible, not hidden');
$appendTrace->invoke($trace);
verifyDiag($trace->values['Protocol']===$liveReport,
    '30-second throttle prevents SymBox flood when no new evidence arrived');
verifyDiag(($trace->attributes['P03HumanEvents']??'[]')==='[]',
    'passive wire monitor cannot invent a human proof or zone transition');
$trace->attributes['TestActive']=false;
$trace->attributes['P03TestWireTraceAt']=time()-31;
$appendTrace->invoke($trace);
verifyDiag($trace->values['Protocol']===$liveReport,
    'wire monitor stops when commissioning test is no longer armed');
$GLOBALS['variables'][26298]=$oldJvObserverStatus;
$GLOBALS['variables'][56881]=$oldWorkObserverStatus;

// Counter reset/replayed VM_UPDATE handling: the input is a monotonically
// increasing P03-aux variable (HumanEventCounter) and existing nonzero
// values after Symcon module reload are NOT new people.
$counterMain=new JVPresenceP03MultiCamera();
$counterMain->attributes['AuxJVEventCounterVarID']=42076;
$counterMain->attributes['AuxWorkEventCounterVarID']=34838;
$counterMain->attributes['AuxJVLastSeenHumanCounter']=-1;
$counterMain->attributes['AuxWorkLastSeenHumanCounter']=-1;
$baseline=new ReflectionMethod(JVPresenceP03MultiCamera::class,'baselineP03Counter');
$change=new ReflectionMethod(JVPresenceP03MultiCamera::class,'acceptP03NewCounterValue');
$baseline->invoke($counterMain,'AuxJVEventCounterVarID','AuxJVLastSeenHumanCounter');
$baseline->invoke($counterMain,'AuxWorkEventCounterVarID','AuxWorkLastSeenHumanCounter');
verifyDiag($counterMain->attributes['AuxJVLastSeenHumanCounter']===7
    && $counterMain->attributes['AuxWorkLastSeenHumanCounter']===0,
    'baseline existing P03 IVS/SMD counters after upgrade – do NOT replay 7 old hits');
verifyDiag($change->invoke($counterMain,'AuxJVLastSeenHumanCounter',8,'JV_LEFT')===true,
    'one positive counter increment is new proof signal');
verifyDiag($change->invoke($counterMain,'AuxJVLastSeenHumanCounter',8,'JV_LEFT')===false,
    'duplicate VM_UPDATE same counter value cannot create a second person');
$counterMain->attributes['P03HumanEvents']='[{"source":"JV_LEFT","id":"old","ts":1}]';
verifyDiag($change->invoke($counterMain,'AuxJVLastSeenHumanCounter',0,'JV_LEFT')===false,
    'counter reset 8 to 0 is NEVER new person detection');
verifyDiag($counterMain->attributes['P03HumanEvents']==='[]'
    && ($counterMain->timers['P03ProofTimer']??-1)===0,
    'counter reset cancels pending cross-camera proof to prevent stale transfer');
verifyDiag($change->invoke($counterMain,'AuxJVLastSeenHumanCounter',1,'JV_LEFT')===true,
    'first real Human Start after legitimate counter reset 0 to 1 accepted');
verifyDiag($change->invoke($counterMain,'AuxJVLastSeenHumanCounter',5,'JV_LEFT')===true,
    'counter jump is logged but emits only one new proof signal');

// Without armed test or production, accepted person observations MUST be
// visible in the protocol/report; nevertheless they NEVER create a transfer.
$idle=new JVPresenceP03MultiCamera();
$idle->attributes['AuxJVEventCounterVarID']=42076;
$idle->attributes['AuxJVLastSeenHumanCounter']=7;
$idle->values['Result']='User working; do not overwrite result';
$idle->MessageSink(time(),42076,10603,[8,true]);
verifyDiag(str_contains((string)($idle->values['Protocol']??''),'P03 HUMAN ROH JV_LEFT')
    && str_contains((string)($idle->values['Protocol']??''),'Test/Produktion AUS'),
    'actual human counter input is logged despite test/production disabled');
verifyDiag(($idle->attributes['P03HumanEvents']??'[]')==='[]',
    'unarmed observed Human signal does not book or queue a HOME/LAGER crossing');
verifyDiag(str_contains((string)($idle->values['P03ConnectionDiagnosis']??''),'TEST: AUS | PRODUKTION: AUS')
    && str_contains((string)($idle->values['P03ConnectionDiagnosis']??''),'AUTOMATISCH'),
    'counter notification immediately refreshes live report with correct arming state');
verifyDiag(($idle->values['Result']??'')==='User working; do not overwrite result'
    && !$idle->reload,'automatic diagnosis never overwrites user result or reloads form');

// Passive 30-second timer refresh must never turn into a busy-loop.
$auto=new JVPresenceP03MultiCamera();
$auto->attributes['LastP03AutomaticDiagnosticAt']=0;
$auto->values['Result']='retain';
$auto->P03MastHealthTimer();
verifyDiag(str_contains((string)($auto->values['P03ConnectionDiagnosis']??''),'AUTOMATISCH'),
    'health timer generates the first fresh passive diagnostic automatically');
$auto->values['P03ConnectionDiagnosis']='SENTINEL: refresh is throttled';
$auto->P03MastHealthTimer();
verifyDiag($auto->values['P03ConnectionDiagnosis']==='SENTINEL: refresh is throttled',
    '30-second cooldown prevents expensive repeated diagnostic reads');
$auto->attributes['LastP03AutomaticDiagnosticAt']=time()-31;
$auto->P03MastHealthTimer();
verifyDiag(str_contains((string)$auto->values['P03ConnectionDiagnosis'],'AUTOMATISCH')
    && $auto->values['Result']==='retain',
    'automatic diagnostic resumes after cooldown without overwriting user status');

// Regression from P05: multiple unique counter increments while visible
// PersonDetected=TRUE must not collapse into a 1-second Boolean edge.
$counterMain->attributes['TestActive']=true;
$counterMain->attributes['AuxJVInstanceID']=57215;
$counterMain->attributes['AuxWorkInstanceID']=27938;
$counterMain->attributes['AuxJVManagedSocketID']=9001;
$counterMain->attributes['AuxWorkManagedSocketID']=9002;
$counterMain->attributes['AuxJVStreamVarID']=54893;
$counterMain->attributes['AuxWorkStreamVarID']=51090;
$GLOBALS['instances'][9001]['InstanceStatus']=102;
$GLOBALS['instances'][9002]['InstanceStatus']=102;
$GLOBALS['props'][9001]['Open']=true;
$GLOBALS['props'][9002]['Open']=true;
$GLOBALS['variables'][54893]=true;
$GLOBALS['variables'][51090]=true;
$addProof=new ReflectionMethod(JVPresenceP03MultiCamera::class,'recordP03HumanEvent');
$addProof->invoke($counterMain,P03ProofEngine::SRC_JV_LEFT,['counter'=>901]);
$addProof->invoke($counterMain,P03ProofEngine::SRC_JV_LEFT,['counter'=>902]);
$records=json_decode((string)($counterMain->attributes['P03HumanEvents']??'[]'),true);
verifyDiag(is_array($records)&&count($records)===2
    && ($records[0]['id']??'')!==($records[1]['id']??''),
    'two independent camera Human counter increments within 1s are both retained');
$addProof->invoke($counterMain,P03ProofEngine::SRC_JV_LEFT,['counter'=>902]);
$records=json_decode((string)($counterMain->attributes['P03HumanEvents']??'[]'),true);
verifyDiag(count($records)===2,
    'identical same-source counter event ID is not duplicated in proof ledger');
verifyDiag((int)($counterMain->attributes['P03VerifiedCount']??0)===0,
    'same mast camera twice must NEVER authorize HOME/LAGER transition');
// Regression: queued VM_UPDATE notifications must use their OWN counter
// snapshots. A late GetValue() already holds 12 while messages for 6 and
// 7 are being dispatched; both are separate positive Human observations.
$counterMain->attributes['P03HumanEvents']='[]';
$counterMain->attributes['AuxJVLastSeenHumanCounter']=5;
$GLOBALS['variables'][42076]=12;
$counterMain->MessageSink(100,42076,10603,[6,true]);
$counterMain->MessageSink(101,42076,10603,[7,true]);
$queued=json_decode((string)($counterMain->attributes['P03HumanEvents']??'[]'),true);
verifyDiag(is_array($queued) && count($queued)===2
    && ($queued[0]['id']??'')==='JV_LEFT:counter:6'
    && ($queued[1]['id']??'')==='JV_LEFT:counter:7',
    'queued VM_UPDATE snapshots 6 and 7 remain distinct although live GetValue already reads 12');
verifyDiag($counterMain->attributes['AuxJVLastSeenHumanCounter']===7,
    'accepted counter baseline follows notification value rather than future live state');
$counterMain->MessageSink(102,42076,10603,[7,true]);
$queued=json_decode((string)($counterMain->attributes['P03HumanEvents']??'[]'),true);
verifyDiag(count($queued)===2,
    'replayed VM_UPDATE snapshot is not a second Human event');
$counterMain->MessageSink(103,42076,10603,[0,true]);
verifyDiag($counterMain->attributes['P03HumanEvents']==='[]',
    'queued counter reset clears old proof without creating an artificial crossing');
$counterMain->MessageSink(104,42076,10603,[1,true]);
$queued=json_decode((string)($counterMain->attributes['P03HumanEvents']??'[]'),true);
verifyDiag(count($queued)===1 && ($queued[0]['id']??'')==='JV_LEFT:counter:1',
    'new positive edge after an actual counter reset is accepted exactly once');

// Raw actor lost stream: cancel remaining pending proof and both camera order.
$counterMain->attributes['P03HumanEvents']='[{"id":"pending","source":"JV_LEFT","ts":1}]';
$counterMain->attributes['AuxJVPersonVarID']=55806;
$counterMain->attributes['AuxWorkPersonVarID']=33071;
$GLOBALS['variables'][54893]=false;
$counterMain->MessageSink(time(),54893,10603,[false,true]);
verifyDiag(($counterMain->attributes['P03HumanEvents']??'')==='[]'
    && ($counterMain->timers['P03ProofTimer']??-1)===0,
    'lost observer StreamOK discards stale camera proof instead of combining across reconnection');

// Confirm a real previously permanent 500-ms proof timer is stopped
// when no two-camera sequence exists, and when test/production are OFF.
$activeP03 = new JVPresenceP03MultiCamera();
$activeP03->attributes['TestActive']=true;
$activeP03->attributes['ProductionEnabled']=false;
$activeP03->attributes['P03HumanEvents']='[]';
$activeP03->properties['NearProofWindowSeconds']=30;
$activeP03->timers['P03ProofTimer']=500;
$evalP03=new ReflectionMethod(JVPresenceP03MultiCamera::class,'evaluateP03MastSequence');
$evalP03->invoke($activeP03);
verifyDiag(($activeP03->timers['P03ProofTimer']??-1)===0,
    'actual P03 main stops stale 500ms settle timer after no valid two-camera pair');
verifyDiag(str_contains((string)$activeP03->values['P03ProofState'],'UNVOLLSTÄNDIG'),
    'actual P03 main resets stale PENDING text after proof expires');
$activeP03->attributes['TestActive']=false;
$activeP03->timers['P03ProofTimer']=500;
$evalP03->invoke($activeP03);
verifyDiag(($activeP03->timers['P03ProofTimer']??-1)===0,
    'actual P03 main stops stale settle timer when test and production disabled');

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

// Terrace field replay 18:37: LIVE but zero CrossLine, SMD present.
// #35115 must show the actual observer variable and full code histogram,
// not just an apparently working socket.
$GLOBALS['instances'][33107]=[
 'ConnectionID'=>51876,'InstanceStatus'=>102,
 'ModuleInfo'=>['ModuleID'=>'{8F4A3A57-3A5B-4F6E-9A64-9E1D326FF7B4}']
];
$GLOBALS['instances'][51876]=[
 'ConnectionID'=>0,'InstanceStatus'=>102,
 'ModuleInfo'=>['ModuleID'=>'{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}']
];
$GLOBALS['props'][33107]=['CameraHost'=>'192.168.107.110','Role'=>'TERRACE'];
$GLOBALS['props'][51876]=['Host'=>'192.168.107.110','Port'=>80,'Open'=>true];
$GLOBALS['byIdent'][33107]=[
 'PersonDetected'=>20965,'StreamOK'=>39780,
 'HumanEventCounter'=>47497,'ObserverStatus'=>18714,'LastIVSEvent'=>22341
];
$GLOBALS['variables'][20965]=false;
$GLOBALS['variables'][39780]=true;
$GLOBALS['variables'][47497]=0;
$GLOBALS['variables'][22341]='[]';
$GLOBALS['variables'][18714]=json_encode([
    'streamOK'=>true,'observedCodeCounts'=>json_encode([
        'VideoMotionInfo'=>20,'VideoMotion'=>6,
        'SmartMotionHuman'=>4,'InterVideoAccess'=>5
    ]),
    'smdHumanRawCount'=>4,'lastSmdHumanStartAt'=>time()-30,
    'ruleMatches'=>0,'ruleRejected'=>31,
    'allowedStairRules'=>[
        ['name'=>'IVS-1','id'=>5,'tableIndex'=>3,
          'humanOnly'=>false,'objects'=>['human','vehicle']]
    ]
],JSON_UNESCAPED_UNICODE);
$instance->attributes['TerraceObserverInstanceID']=33107;
$instance->attributes['TerraceManagedSocketID']=51876;
$instance->attributes['TerraceRuleReadAt']=time()-120;
$instance->properties['TerraceDiagnosticsEnabled']=true;
$instance->properties['TerraceHost']='192.168.107.110';
$instance->values['P03TerraceMonitor']='AKTIV – NUR DIAGNOSE';
$instance->DiagnoseP03Connections(true);
$terraceReport=(string)$instance->values['P03ConnectionDiagnosis'];
verifyDiag(str_contains($terraceReport,'TERRACE Person erkannt: AUS | Variable #20965')
    && str_contains($terraceReport,'SmartMotionHuman=4')
    && str_contains($terraceReport,'CrossLine-Events=0')
    && str_contains($terraceReport,'RuleID=5 | Nur Human=NEIN')
    && str_contains($terraceReport,'Nach Kamera-IVS-Aenderungen erneut'),
    'real 18:37 terrace SMD vs missing IVS and stale rule filter are explicit in #35115');
$GLOBALS['variables'][22341]=json_encode([[
 'time'=>'18:51:01','code'=>'CrossLineDetection','action'=>'Start','ruleName'=>'IVS-1',
 'ruleIdUpper'=>5,'classification'=>'Human',
 'direction'=>'LeftToRight','decision'=>'STAIR_HUMAN_START_COUNTED',
 'rejectionReason'=>'MATCHED_FILTER'
]],JSON_UNESCAPED_UNICODE);
$GLOBALS['variables'][47497]=1;
$instance->DiagnoseP03Connections(true);
$terraceReport=(string)$instance->values['P03ConnectionDiagnosis'];
verifyDiag(str_contains($terraceReport,'TERRACE Human-CrossLine-Zaehler: 1')
    && str_contains($terraceReport,'TERRACE IVS 18:51:01')
    && str_contains($terraceReport,'Richtung=LeftToRight')
    && str_contains($terraceReport,'TERRACE OUT-SHADOW: 1 OUT-Kandidat(en)')
    && str_contains($terraceReport,'OUT-Schatten=OUT_CANDIDATE_ONLY')
    && str_contains($terraceReport,'LeftToRight=OUT, RightToLeft=IN')
    && str_contains($terraceReport,'DREI-KAMERA-FUSION'),
    'actual main report reads accepted terrace IVS, counter and direction after update');
// Real 20:06:02 WORK stair + 20:06:23 terrace Human 21s later:
// exercise actual main P03 callback and proof result with no zone booking.
$GLOBALS['variables'][39780]=true;
$GLOBALS['instances'][57215]['ConnectionID']=9001;
$GLOBALS['instances'][27938]['ConnectionID']=9002;
$GLOBALS['instances'][9001]['InstanceStatus']=102;
$GLOBALS['instances'][9002]['InstanceStatus']=102;
$GLOBALS['props'][9001]=['Host'=>'192.168.107.96','Port'=>80,'Open'=>true];
$GLOBALS['props'][9002]=['Host'=>'192.168.107.99','Port'=>80,'Open'=>true];
$GLOBALS['variables'][54893]=true;
$GLOBALS['variables'][51090]=true;
$inBackup=new JVPresenceP03MultiCamera();
$inBackup->properties=[
 'AuxJVHost'=>'192.168.107.96','AuxWorkHost'=>'192.168.107.99',
 'TerraceDiagnosticsEnabled'=>true,'TerraceHost'=>'192.168.107.110',
 'NearProofWindowSeconds'=>30
];
$inBackup->attributes=[
 'AuxJVInstanceID'=>57215,'AuxJVManagedSocketID'=>9001,
 'AuxJVStreamVarID'=>54893,'AuxWorkInstanceID'=>27938,
 'AuxWorkManagedSocketID'=>9002,'AuxWorkStreamVarID'=>51090,
 'TerraceObserverInstanceID'=>33107,'TerraceStreamVarID'=>39780,
 'TerracePersonVarID'=>20965,'TerraceLastIVSVarID'=>22341,
 'TerraceAllowedIVSRules'=>json_encode([[
    'name'=>'IVS-1','id'=>5,'humanOnly'=>true
 ]]),
 'P03HumanEvents'=>'[]','P03TerraceInSignals'=>'[]',
 'P03TerraceInSeenIVS'=>'[]',
 'TestActive'=>true,'ProductionEnabled'=>false,
 'P03VerifiedCount'=>3,
 'P03ActionCounts'=>'{"HOME_TO_LAGER":2,"LAGER_TO_HOME":1}'
];
$inBackup->MessageSink(time(),20965,10603,[true,true]);
$signals=json_decode($inBackup->attributes['P03TerraceInSignals'],true);
verifyDiag(count($signals)===1 && ($signals[0]['kind']??'')==='PERSON'
    && ($signals[0]['direction']??null)===null,
    'real terrace Boolean Human TRUE triggers distinct IN backup status without requiring terrace IVS');
$inBackup->MessageSink(time(),20965,10603,[false,true]);
verifyDiag(count(json_decode($inBackup->attributes['P03TerraceInSignals'],true))===1,
    'terrace 5s Human pulse OFF does not count as independent IN observation');
$ts=microtime(true);
$inBackup->attributes['P03HumanEvents']=json_encode([[
 'id'=>'WORK_LEFT:counter:13','source'=>'WORK_LEFT',
 'ts'=>$ts-31,'used'=>false
]]);
$inBackup->attributes['P03TerraceInSignals']=json_encode([[
 'id'=>'person:20:06:23','kind'=>'PERSON','direction'=>null,
 'ts'=>$ts-10,'used'=>false
]]);
$evaluate=new ReflectionMethod(JVPresenceP03MultiCamera::class,'evaluateP03MastSequence');
$evaluate->invoke($inBackup);
$ledger=json_decode($inBackup->attributes['P03HumanEvents'],true);
verifyDiag($inBackup->attributes['P03TerraceInBackupCount']===1
    && !empty($ledger[0]['used'])
    && str_contains((string)$inBackup->values['P03TerraceInBackupStatus'],
        'WORK_LEFT_STAIR_THEN_TERRACE_IN'),
    '21s WORK_LEFT+terrace field replay confirms fourth IN via actual module evaluator');
verifyDiag((int)$inBackup->attributes['P03VerifiedCount']===3
    && $inBackup->attributes['P03ActionCounts']
       ==='{"HOME_TO_LAGER":2,"LAGER_TO_HOME":1}'
    && !$inBackup->attributes['ProductionEnabled'],
    'terrace IN backup does not forge fourth mast proof, production or zone change');
$evaluate->invoke($inBackup);
verifyDiag($inBackup->attributes['P03TerraceInBackupCount']===1,
    'replayed same terrace/mast IN backup never increments twice');

// OUT fallback only after accepted Terrace Human-IVS LeftToRight followed by
// ONE valid real mast stair-IVS. Never count SMD as directed OUT.
$now=microtime(true);
$inBackup->attributes['P03HumanEvents']=json_encode([[
  'id'=>'WORK_LEFT:counter:14','source'=>'WORK_LEFT',
  'ts'=>$now-32,'used'=>false
]]);
$inBackup->attributes['P03TerraceInSignals']=json_encode([[
  'id'=>'ivs:terrace-ltr','kind'=>'IVS','direction'=>'LeftToRight',
  'ts'=>$now-46,'used'=>false
]]);
$evaluate->invoke($inBackup);
$outLedger=json_decode($inBackup->attributes['P03HumanEvents'],true);
verifyDiag($inBackup->attributes['P03TerraceOutBackupCount']===1
    && !empty($outLedger[0]['used'])
    && str_contains($inBackup->attributes['P03ThreeCameraLastDecision'],
        'TERRACE_OUT_IVS_THEN_WORK_LEFT_STAIR'),
    'real 3-camera dispatcher confirms OUT with Terrassen-Human-IVS plus WORK stair');
verifyDiag($inBackup->attributes['P03VerifiedCount']===3
    && $inBackup->attributes['P03TerraceInBackupCount']===1
    && !$inBackup->attributes['ProductionEnabled'],
    'OUT backup does not forge ordinary mast proofs, IN or production');
$evaluate->invoke($inBackup);
verifyDiag($inBackup->attributes['P03TerraceOutBackupCount']===1,
    'consumed terrace+mast OUT cannot be double-counted');

// A complete pair is always primary, with available terrace SMD/IVS only
// reported as direction-consistent or contradictory supplemental evidence.
$now=microtime(true);
$inBackup->attributes['P03HumanEvents']=json_encode([
 ['id'=>'JV_LEFT:counter:16','source'=>'JV_LEFT','ts'=>$now-15,'used'=>false],
 ['id'=>'WORK_LEFT:counter:16','source'=>'WORK_LEFT','ts'=>$now-3,'used'=>false]
]);
$inBackup->attributes['P03TerraceInSignals']=json_encode([[
 'id'=>'ivs:primary-out','kind'=>'IVS','direction'=>'LeftToRight',
 'ts'=>$now-14,'used'=>false
]]);
$evaluate->invoke($inBackup);
$primaryT=json_decode($inBackup->attributes['P03TerraceInSignals'],true);
verifyDiag(!empty($primaryT[0]['used'])
    && str_contains($inBackup->attributes['P03ThreeCameraLastDecision'],
       '2CAM PRIMARY OUT BESTAETIGT')
    && str_contains($inBackup->attributes['P03ThreeCameraLastDecision'],
       'LeftToRight passt zu OUT'),
    'complete 2-mast OUT takes priority, logs compatible 3rd-camera IVS and consumes it');
verifyDiag($inBackup->attributes['P03TerraceOutBackupCount']===1
    && $inBackup->attributes['P03TerraceInBackupCount']===1,
    'three-camera support must not also count fallback');


unset($GLOBALS['instances'][33107],$GLOBALS['instances'][51876],
    $GLOBALS['props'][33107],$GLOBALS['props'][51876]);
$instance->properties['TerraceDiagnosticsEnabled']=false;

// Regression for real 2026-10-11 20:02:28 failed street learning:
// an initial WORK_LEFT StreamOK=false during the audit socket restart
// must WAIT instead of instantly aborting OUT-IN-OUT-IN training.
unset($GLOBALS['instances'][77777]); // end of foreign-socket-sharing test fixture
$GLOBALS['instances'][57215]['ConnectionID']=9001;
$GLOBALS['instances'][27938]['ConnectionID']=9002;
$GLOBALS['instances'][9001]['InstanceStatus']=102;
$GLOBALS['instances'][9002]['InstanceStatus']=102;
$GLOBALS['props'][9001]=['Host'=>'192.168.107.96','Open'=>true,'Port'=>80];
$GLOBALS['props'][9002]=['Host'=>'192.168.107.99','Open'=>true,'Port'=>80];
$GLOBALS['props'][57215]['Role']='JV_LEFT';
$GLOBALS['props'][27938]['Role']='WORK_LEFT';
$GLOBALS['props'][27938]['CameraHost']='192.168.107.99';
$GLOBALS['variables'][48490]='[]';
$GLOBALS['byIdent'][27938]['LastIVSEvent']=48490;
$GLOBALS['variables'][51090]=false;
$GLOBALS['variables'][54893]=true;
$streetMain=new JVPresenceP03MultiCamera();
$streetMain->attributes['AuxJVInstanceID']=57215;
$streetMain->attributes['AuxJVManagedSocketID']=9001;
$streetMain->attributes['AuxJVStreamVarID']=54893;
$streetMain->attributes['AuxWorkInstanceID']=27938;
$streetMain->attributes['AuxWorkManagedSocketID']=9002;
$streetMain->attributes['AuxWorkStreamVarID']=51090;
$streetMain->attributes['AuxWorkStreetIVSVarID']=48490;
$streetMain->attributes['P03VerifiedCount']=3;
$streetMain->attributes['P03HumanEvents']='[{"id":"already-seen"}]';
$beginStreet=new ReflectionMethod(JVPresenceP03MultiCamera::class,
    'startP03StreetLearning');
$beginStreet->invoke($streetMain);
verifyDiag($streetMain->attributes['P03StreetLearningActive']===true
    && $streetMain->attributes['P03StreetLearningStreamWasLive']===false
    && str_contains((string)$streetMain->values['P03StreetDirectionStatus'],
        'WARTE AUF WORK_LEFT-LIVE'),
    '20:02 street training waits while audit reopens WORK_LEFT TCP');
$streetMain->MessageSink(time(),51090,10603,[false,true]);
verifyDiag($streetMain->attributes['P03StreetLearningActive']===true
    && !str_contains((string)$streetMain->values['P03StreetDirectionStatus'],'ABGEBROCHEN'),
    'initial false StreamOK cannot cancel four-walk street training');
$GLOBALS['variables'][51090]=true;
$streetMain->MessageSink(time(),51090,10603,[true,true]);
verifyDiag($streetMain->attributes['P03StreetLearningStreamWasLive']===true
    && str_contains((string)$streetMain->values['P03StreetDirectionStatus'],'LERNEN 0/4'),
    'once live WORK_LEFT stream arms street learning without resetting proof');
$streetEvent=[
 'time'=>'20:05:20','code'=>'CrossLineDetection','action'=>'Start',
 'ruleName'=>'Überquerung zur Straße','ruleIdUpper'=>5,
 'classification'=>'Human','direction'=>'LeftToRight','eventId'=>1011
];
$GLOBALS['variables'][48490]=json_encode([$streetEvent],JSON_UNESCAPED_UNICODE);
$streetMain->MessageSink(time(),48490,10603,[$GLOBALS['variables'][48490],true]);
verifyDiag(str_contains((string)$streetMain->values['P03StreetDirectionStatus'],'LERNEN 1/4')
    && str_contains((string)$streetMain->values['P03WorkBoundaryStatus'],'LeftToRight')
    && (int)$streetMain->attributes['P03StreetRawCount']===1,
    'real street Human RuleID5 records learning 1/4 and visible raw direction');
$streetMain->MessageSink(time(),48490,10603,[$GLOBALS['variables'][48490],true]);
verifyDiag((int)$streetMain->attributes['P03StreetRawCount']===1,
    'duplicate last-eight WORK street history cannot replay Human');
$GLOBALS['variables'][54893]=false;
$streetMain->MessageSink(time(),54893,10603,[false,true]);
verifyDiag($streetMain->attributes['P03StreetLearningActive']===true,
    'JV_LEFT stream interruption cannot abort independent WORK street learning');
$GLOBALS['variables'][51090]=false;
$streetMain->MessageSink(time(),51090,10603,[false,true]);
verifyDiag($streetMain->attributes['P03StreetLearningActive']===false
    && str_contains((string)$streetMain->values['P03StreetDirectionStatus'],'ABGEBROCHEN'),
    'true WORK_LEFT loss AFTER stream was live aborts incomplete street learning');
$GLOBALS['variables'][51090]=true;
$GLOBALS['variables'][54893]=true;
// A REAL stream dropout intentionally removes in-flight mast evidence;
// populate the fixture again only AFTER reconnect to verify that the
// stand-alone street-only command itself never erases unrelated state.
verifyDiag($streetMain->attributes['P03HumanEvents']==='[]',
    'real WORK/JV stream loss correctly invalidates pending mast proof');
$streetMain->attributes['P03HumanEvents']='[{"id":"already-seen"}]';
$streetMain->StartStreetDirectionOnly();
verifyDiag($streetMain->attributes['P03StreetLearningActive']===true
    && $streetMain->attributes['P03StreetLearningStreamWasLive']===true
    && $streetMain->attributes['P03VerifiedCount']===3
    && $streetMain->attributes['P03HumanEvents']==='[{"id":"already-seen"}]',
    'standalone WORK street training preserves historical mast proof and reboots no socket');
verifyDiag(str_contains((string)$streetMain->values['P03StreetDirectionStatus'],'LERNEN 0/4'),
    'street-only button bases history without claiming previous crossing as new');

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
verifyDiag(!in_array('JVP03MC_DiagnoseP03Ownership($id);',
    array_column($ui2['actions']??[],'onClick'),true)
    && count($ui2['actions']??[])===10
    && in_array('JVP03MC_StartTerraceInBackupOnly($id);',
        array_column($ui2['actions']??[],'onClick'),true)
    && in_array('JVP03MC_StartStreetDirectionOnly($id);',
        array_column($ui2['actions']??[],'onClick'),true),
    'main UI includes safe street-only learning, preserving existing commands');

echo "P03 READ-ONLY CONNECTION DIAGNOSIS TEST PASSED\n";
