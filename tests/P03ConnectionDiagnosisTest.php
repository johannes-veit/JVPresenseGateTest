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
function IPS_GetInstanceListByModuleID(string $id): array { return [57215,27938]; }
function IPS_GetProperty(int $id,string $name): mixed { return $GLOBALS['props'][$id][$name] ?? null; }
function IPS_GetObjectIDByIdent(string $ident,int $id): int { return $GLOBALS['byIdent'][$id][$ident] ?? 0; }
function IPS_VariableExists(int $id): bool { return array_key_exists($id,$GLOBALS['variables']) || in_array($id,[20001,20002],true); }
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
    public array $values=['P03ConnectionDiagnosis'=>'','Result'=>''];
    public bool $reload=false;
    public function Create():void {}
    public function ApplyChanges():void {}
    protected function ReadAttributeInteger(string $name):int { return (int) ($this->attributes[$name]??0); }
    protected function ReadPropertyString(string $name):string { return (string)($this->properties[$name]??''); }
    protected function GetIDForIdent(string $ident):int {return ['P03ConnectionDiagnosis'=>20001,'Result'=>20002][$ident]??0;}
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
echo "P03 READ-ONLY CONNECTION DIAGNOSIS TEST PASSED\n";
