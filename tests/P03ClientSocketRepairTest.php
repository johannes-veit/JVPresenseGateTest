<?php
declare(strict_types=1);

/**
 * Executes the ACTUAL main P03 class against a fake, immutable IP-Symcon
 * object tree. No network, camera, socket or module write action is possible.
 */
$GLOBALS['instances'] = [
    53879 => ['ConnectionID'=>0,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>'P03_MAIN']],
    57215 => ['ConnectionID'=>0,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>'{8F4A3A57-3A5B-4F6E-9A64-9E1D326FF7B4}']],
    27938 => ['ConnectionID'=>0,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>'{8F4A3A57-3A5B-4F6E-9A64-9E1D326FF7B4}']],
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
    56881=>json_encode(['reason'=>'WATCHDOG: HTTP/Digest-Handshake ausstehend','configReady'=>true,'streaming'=>false,'parentOpen'=>false,'authPending'=>true,'authBlocked'=>false,'restartStage'=>1,'lastHttpRequest'=>time()-19,'lastCameraRx'=>0])
];
$GLOBALS['byIdent'] = [
    57215=>['StreamOK'=>54893,'PersonDetected'=>55806,'HumanEventCounter'=>42076,'ObserverStatus'=>26298],
    27938=>['StreamOK'=>51090,'PersonDetected'=>33071,'HumanEventCounter'=>34838,'ObserverStatus'=>56881]
];
$GLOBALS['writes'] = [];
$GLOBALS['startCalls'] = [];
$GLOBALS['healthCalls'] = [];
function JVP03AUX_StartSocketNow(int $id):bool {
    $GLOBALS['startCalls'][] = $id;
    return true;
}
function JVP03AUX_HealthTick(int $id):string {
    $GLOBALS['healthCalls'][] = $id;
    return $id === 57215 ? 'LIVE' : 'RECONNECT_GET_SENT';
}


function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['instances'][$id]); }
function IPS_GetInstance(int $id): array { return $GLOBALS['instances'][$id]; }
function IPS_GetInstanceList(): array { return array_keys($GLOBALS['instances']); }
function IPS_GetInstanceListByModuleID(string $id): array { return [57215,27938]; }
function IPS_GetProperty(int $id,string $name): mixed { return $GLOBALS['props'][$id][$name] ?? null; }
function IPS_GetObjectIDByIdent(string $ident,int $id): int { return $GLOBALS['byIdent'][$id][$ident] ?? 0; }
function IPS_VariableExists(int $id): bool { return array_key_exists($id,$GLOBALS['variables']) || in_array($id,[20001,20002],true); }
function GetValue(int $id): mixed { return $GLOBALS['variables'][$id] ?? ''; }
function IPS_ApplyChanges(int $id): void { $GLOBALS['writes'][] = ['apply',$id]; }
function IPS_SetProperty(int $id,string $key,mixed $value): void {
    $GLOBALS['writes'][]=['property',$id,$key];
    $GLOBALS['props'][$id][$key]=$value;
}
function IPS_CreateInstance(string $guid): int {
    $id=93000+count($GLOBALS['created'] ?? []);
    $GLOBALS['created'][]=$id;
    $GLOBALS['instances'][$id]=[
        'ConnectionID'=>0,'InstanceStatus'=>104,
        'ModuleInfo'=>['ModuleID'=>$guid]
    ];
    $GLOBALS['props'][$id]=['Host'=>'','Port'=>80,'Open'=>false];
    $GLOBALS['writes'][]=['create',$id];
    return $id;
}
function IPS_SetName(int $id,string $name):void { $GLOBALS['writes'][]=['name',$id]; }
function IPS_ConnectInstance(int $childID,int $socketID):bool {
    $GLOBALS['writes'][]=['connect',$childID,$socketID];
    if (($GLOBALS['instances'][$childID]['ConnectionID']??0)>0)
        throw new RuntimeException('existing connection must not be replaced');
    $GLOBALS['instances'][$childID]['ConnectionID']=$socketID;
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
        'AuxWorkHost'=>'192.168.107.99',
        'CameraPort'=>80
    ];
    public array $values=['P03ConnectionDiagnosis'=>'','Result'=>''];
    public bool $reload=false;
    public function Create():void {}
    public function ApplyChanges():void {}
    protected function ReadAttributeInteger(string $name):int { return (int) ($this->attributes[$name]??0); }
    protected function WriteAttributeInteger(string $name,int $value):void {
        $this->attributes[$name]=$value;
    }
    protected function ReadPropertyInteger(string $name):int {
        return (int)($this->properties[$name]??0);
    }
    protected function ReadPropertyString(string $name):string { return (string)($this->properties[$name]??''); }
    protected function GetIDForIdent(string $ident):int {return ['P03ConnectionDiagnosis'=>20001,'Result'=>20002][$ident]??0;}
    protected function GetValue(string $ident):mixed { return $this->values[$ident]??null; }
    protected function SetValue(string $ident,mixed $value):void {
        $this->values[$ident]=$value;
        $var=$this->GetIDForIdent($ident);
        if ($var) $GLOBALS['variables'][$var]=$value;
    }
    protected function ReloadForm():void {$this->reload=true;}
    protected function SendDebug(string $topic,string $message,int $format):void {}

}

require_once dirname(__DIR__).'/JVPresenceP03TerraceTest/module.php';

function verifyDiag(bool $passed,string $what):void
{
    if (!$passed) throw new RuntimeException('FAIL: '.$what);
    echo 'OK: '.$what."\n";
}

// Initial observation: both P03 observers exist but have no Client Socket.
$instance=new JVPresenceP03MultiCamera();
verifyDiag((int)$GLOBALS['instances'][57215]['ConnectionID']===0,'JV_LEFT starts orphaned');
verifyDiag((int)$GLOBALS['instances'][27938]['ConnectionID']===0,'WORK_LEFT starts orphaned');
$instance->RepairP03ClientSockets();
verifyDiag(count($GLOBALS['created'])===2,'two dedicated Client Sockets created');
$jv=(int)$GLOBALS['instances'][57215]['ConnectionID'];
$work=(int)$GLOBALS['instances'][27938]['ConnectionID'];
verifyDiag($jv>0 && $work>0 && $jv!==$work,'each observer gets its own socket');
verifyDiag($GLOBALS['props'][$jv]['Host']==='192.168.107.96','JV socket configured for .96');
verifyDiag($GLOBALS['props'][$work]['Host']==='192.168.107.99','WORK socket configured for .99');
verifyDiag($GLOBALS['props'][$jv]['Port']===80 && $GLOBALS['props'][$work]['Port']===80,'both sockets HTTP port 80');
verifyDiag($GLOBALS['props'][$jv]['Open']===false && $GLOBALS['props'][$work]['Open']===false,'new sockets are initially closed for staged Digest retry');
verifyDiag($instance->attributes['AuxJVManagedSocketID']===$jv,'JV socket ID persisted for safe retry');
verifyDiag($instance->attributes['AuxWorkManagedSocketID']===$work,'WORK socket ID persisted for safe retry');
verifyDiag((bool)array_filter($GLOBALS['writes'],static fn($w)=>$w[0]==='apply' && $w[1]===57215),'JV observer applied');
verifyDiag((bool)array_filter($GLOBALS['writes'],static fn($w)=>$w[0]==='apply' && $w[1]===27938),'WORK observer applied');
verifyDiag(str_contains((string)$instance->values['P03ConnectionDiagnosis'],(string)$jv),'post-repair diagnostic includes JV socket');
verifyDiag(str_contains((string)$instance->values['P03ConnectionDiagnosis'],(string)$work),'post-repair diagnostic includes WORK socket');
verifyDiag(!str_contains((string)$instance->values['P03ConnectionDiagnosis'],'secret-jv'),'diagnosis never prints passwords');

$mutations=count($GLOBALS['writes']);
$instance->RepairP03ClientSockets();
verifyDiag(count($GLOBALS['created'])===2,'second button press creates no duplicate sockets');
verifyDiag(count($GLOBALS['writes'])===$mutations,'second button press performs no socket mutations');

$GLOBALS['instances'][57215]['ConnectionID']=0; // Simulate interrupted connection.
$before=count($GLOBALS['created']);
$instance->RepairP03ClientSockets();
verifyDiag(count($GLOBALS['created'])===$before,'interrupted connection reuses existing managed JV socket');
verifyDiag($GLOBALS['instances'][57215]['ConnectionID']===$jv,'JV observer restored to the original socket');
verifyDiag(($GLOBALS['instances'][77777]['ConnectionID']??0)===9002,'unrelated module remains attached to its own IO');

foreach ($GLOBALS['writes'] as $w) {
    if (in_array($w[0],['apply','property','name'],true)) {
        verifyDiag(!in_array($w[1],[9001,9002,77777],true),'no modification of pre-existing/foreign sockets');
    }
}
// Direct start button may call only P03 observers with sockets owned by P03.
$instance->StartP03EventstreamsNow();
verifyDiag($GLOBALS['startCalls']===[57215,27938],'main start button calls both P03 observers once');
verifyDiag(str_contains((string)$instance->values['P03ConnectionDiagnosis'],'Client Socket ID:'),
    'main start button refreshes read-only diagnosis');

// Corrupt the first socket's live relationship: must refuse this observer.
$GLOBALS['startCalls'] = [];
$GLOBALS['instances'][57215]['ConnectionID']=9001; // foreign socket
$instance->StartP03EventstreamsNow();
verifyDiag($GLOBALS['startCalls']===[27938],
    'main start button skips observer with socket mismatch');

// Corrupt P03 managed socket id: must refuse this role too.
$GLOBALS['startCalls'] = [];
$instance->attributes['AuxWorkManagedSocketID'] = 9002;
$instance->StartP03EventstreamsNow();
verifyDiag($GLOBALS['startCalls']===[],
    'main start button never invokes observer linked to foreign socket');

// Recover consistent main attributes before exercising the independent
// main watchdog. It may operate on own P03 IO only.
$GLOBALS['instances'][57215]['ConnectionID']=$jv;
$instance->attributes['AuxWorkManagedSocketID']=$work;
$GLOBALS['healthCalls']=[];
$instance->CheckP03MastHealthNow();
verifyDiag($GLOBALS['healthCalls']===[57215,27938],
    'manual heartbeat button checks both P03 observers');
verifyDiag(str_contains((string)$instance->values['P03MastHealth'],'JV_LEFT=LIVE')
    && str_contains((string)$instance->values['P03MastHealth'],'WORK_LEFT=RECONNECT_GET_SENT'),
    'manual heartbeat report includes actual outcome of each observer');
verifyDiag(str_contains((string)$instance->values['P03ConnectionDiagnosis'],'P03 SOCKET-DIAGNOSE'),
    'manual heartbeat button refreshes copyable connection report');

$GLOBALS['healthCalls']=[];
$instance->P03MastHealthTimer();
verifyDiag($GLOBALS['healthCalls']===[57215,27938],
    'independent main P03 timer checks both observers');
$GLOBALS['healthCalls']=[];
$GLOBALS['instances'][57215]['ConnectionID']=9001;
$instance->P03MastHealthTimer();
verifyDiag($GLOBALS['healthCalls']===[27938],
    'main watchdog never calls observer on foreign socket');

echo "P03 CLIENT SOCKET REPAIR SIMULATION PASSED\n";
