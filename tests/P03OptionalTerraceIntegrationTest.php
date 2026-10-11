<?php
declare(strict_types=1);

/**
 * Complete optional TERRACE monitor integration simulation: an immutable
 * two-mast system and separate P03 terrace ownership, no camera writes,
 * safe ignored mode, ambiguous IVS, reactivation and shared IO protection.
 */
$GLOBALS['terInstances']=[];
$GLOBALS['terProps']=[];
$GLOBALS['terChanges']=[];
$GLOBALS['terNextID']=96000;
$GLOBALS['terGetCount']=0;
$GLOBALS['terCreateCount']=0;
$GLOBALS['terOwnSocketIDs']=[];
$GLOBALS['terAutoParentLink']=true;
$GLOBALS['terFailConnectOnce']=false;
$GLOBALS['terVarIds']=[];
$GLOBALS['terVarValues']=[];
$GLOBALS['terVarNext']=85000;
function IPS_InstanceExists(int $id):bool { return isset($GLOBALS['terInstances'][$id]); }
function IPS_GetInstance(int $id):array { return $GLOBALS['terInstances'][$id]??[]; }
function IPS_GetInstanceList():array { return array_keys($GLOBALS['terInstances']); }
function IPS_GetInstanceListByModuleID(string $guid):array {
    return array_values(array_filter(array_keys($GLOBALS['terInstances']),
        static fn(int $id):bool => ($GLOBALS['terInstances'][$id]['ModuleInfo']['ModuleID']??'')===$guid));
}
function IPS_GetProperty(int $id,string $key):mixed {
    return $GLOBALS['terProps'][$id][$key]??null;
}
function IPS_GetObjectIDByIdent(string $ident,int $instanceID):int {
    if (!isset($GLOBALS['terInstances'][$instanceID])) {
        return 0;
    }
    if (($GLOBALS['terProps'][$instanceID]['Role']??'')!=='TERRACE') {
        return 0;
    }
    if (!in_array($ident,['PersonDetected','HumanEventCounter','StreamOK','LastIVSEvent','ObserverStatus','EventAudit'],true)) {
        return 0;
    }
    if (!isset($GLOBALS['terVarIds'][$instanceID][$ident])) {
        $id=++$GLOBALS['terVarNext'];
        $GLOBALS['terVarIds'][$instanceID][$ident]=$id;
        $GLOBALS['terVarValues'][$id]=
            $ident==='HumanEventCounter' ? 0
            : ($ident==='PersonDetected'||$ident==='StreamOK' ? false : '');
    }
    return $GLOBALS['terVarIds'][$instanceID][$ident];
}
function IPS_VariableExists(int $id):bool {
    return array_key_exists($id,$GLOBALS['terVarValues']);
}
function GetValue(int $id):mixed {
    return $GLOBALS['terVarValues'][$id]??null;
}
function IPS_SetProperty(int $id,string $key,mixed $value):void {
    if (!isset($GLOBALS['terInstances'][$id])
        || (!(bool)($GLOBALS['terOwnSocketIDs'][$id]??false)
            && ($GLOBALS['terInstances'][$id]['ModuleInfo']['ModuleID']??'')
                !=='{8F4A3A57-3A5B-4F6E-9A64-9E1D326FF7B4}')) {
        throw new RuntimeException('Attempt to mutate non-terrace instance');
    }
    $GLOBALS['terChanges'][]=[$id,$key,$value];
    $GLOBALS['terProps'][$id][$key]=$value;
}
function IPS_CreateInstance(string $guid):int {
    if ($guid==='{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}') {
        $id=++$GLOBALS['terNextID'];
        $GLOBALS['terInstances'][$id]=[
            'ConnectionID'=>0,'InstanceStatus'=>104,
            'ModuleInfo'=>['ModuleID'=>$guid]
        ];
        $GLOBALS['terProps'][$id]=['Host'=>'','Port'=>80,'Open'=>false];
        $GLOBALS['terOwnSocketIDs'][$id]=true;
        return $id;
    }
    if ($guid!=='{8F4A3A57-3A5B-4F6E-9A64-9E1D326FF7B4}') {
        throw new RuntimeException('Wrong or foreign observer type');
    }
    $GLOBALS['terCreateCount']++;
    $id=++$GLOBALS['terNextID'];
    $GLOBALS['terInstances'][$id]=[
        'ConnectionID'=>0,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>$guid]
    ];
    $GLOBALS['terProps'][$id]=['Role'=>'','Enabled'=>false];
    return $id;
}
function IPS_SetName(int $id,string $name):bool { return isset($GLOBALS['terInstances'][$id]); }
function IPS_ApplyChanges(int $id):void {
    if (!empty($GLOBALS['terOwnSocketIDs'][$id])) {
        return;
    }
    if (($GLOBALS['terProps'][$id]['Role']??'')!=='TERRACE') {
        throw new RuntimeException('Unexpected ApplyChanges outside optional terrace');
    }
    if ($GLOBALS['terAutoParentLink']
        && ($GLOBALS['terInstances'][$id]['ConnectionID']??0)===0) {
        $socket=++$GLOBALS['terNextID'];
        $GLOBALS['terInstances'][$socket]=[
            'ConnectionID'=>0,'InstanceStatus'=>102,
            'ModuleInfo'=>['ModuleID'=>'{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}']
        ];
        $GLOBALS['terProps'][$socket]=['Host'=>'192.0.2.40','Port'=>80,'Open'=>true];
        $GLOBALS['terInstances'][$id]['ConnectionID']=$socket;
    }
}

function IPS_ConnectInstance(int $childID,int $socketID):bool {
    if (empty($GLOBALS['terOwnSocketIDs'][$socketID])
        || ($GLOBALS['terProps'][$childID]['Role']??'')!=='TERRACE'
        || (int)($GLOBALS['terInstances'][$childID]['ConnectionID']??0)!==0) {
        throw new RuntimeException('Unsafe attempt to link foreign P03 socket');
    }
    if ($GLOBALS['terFailConnectOnce']) {
        $GLOBALS['terFailConnectOnce']=false;
        return false;
    }
    $GLOBALS['terInstances'][$childID]['ConnectionID']=$socketID;
    return true;
}
class IPSModule {
    public int $InstanceID=53879;
    public array $properties=[];
    public array $attributes=[];
    public array $values=[];
    public function Create():void {}
    public function ApplyChanges():void {}
    protected function SendDebug(string $caption,string $message,int $format):void {}
    protected function ReadPropertyString(string $key):string {return (string)($this->properties[$key]??'');}
    protected function ReadPropertyInteger(string $key):int {return (int)($this->properties[$key]??0);}
    protected function ReadPropertyBoolean(string $key):bool {return (bool)($this->properties[$key]??false);}
    protected function ReadAttributeInteger(string $key):int {return (int)($this->attributes[$key]??0);}
    protected function ReadAttributeBoolean(string $key):bool {return (bool)($this->attributes[$key]??false);}
    protected function ReadAttributeString(string $key):string {return (string)($this->attributes[$key]??'');}
    protected function WriteAttributeInteger(string $key,int $value):void {$this->attributes[$key]=$value;}
    protected function WriteAttributeString(string $key,string $value):void {$this->attributes[$key]=$value;}
    public array $buffers=[];
    protected function SetBuffer(string $key,string $value):void {$this->buffers[$key]=$value;}
    protected function GetBuffer(string $key):string {return (string)($this->buffers[$key]??'');}
    protected function GetValue(string $key):mixed {return $this->values[$key]??'';}
    protected function SetValue(string $key,mixed $value):void {$this->values[$key]=$value;}
    public array $subscribed=[];
    protected function RegisterMessage(int $id,int $message):void {$this->subscribed[$id]=$message;}
    protected function UnregisterMessage(int $id,int $message):void {unset($this->subscribed[$id]);}
}
require_once dirname(__DIR__).'/JVPresenceP03TerraceTest/module.php';

class MockTerraceMain extends JVPresenceP03MultiCamera {
    public string $cgiBody='';
    public int $autoDiagnosisCount=0;
    public function DiagnoseP03Connections(bool $automatic=false):void {
        $this->autoDiagnosisCount++;
        $this->values['P03ConnectionDiagnosis']='Terrace callback diagnosis '
            .$this->autoDiagnosisCount;
    }
    protected function genericCameraGet(
        string $host,int $port,string $username,string $password,
        string $uri,int $timeoutSeconds=6
    ):array {
        if (!in_array($host,['192.0.2.40','192.0.2.41','192.0.2.42','192.0.2.43'],true)
            || $username!=='mockuser'
            || $password!=='mockpass'
            || $uri!=='/cgi-bin/configManager.cgi?action=getConfig&name=VideoAnalyseRule') {
            throw new RuntimeException('Unexpected external camera operation');
        }
        $GLOBALS['terGetCount']++;
        return ['ok'=>true,'http'=>200,'error'=>'','body'=>$this->cgiBody];
    }
}
function tCheck(bool $value,string $what):void {
    if (!$value) throw new RuntimeException('Terrace test failed: '.$what);
    echo 'OK '.$what.PHP_EOL;
}
$oneLine=implode("\r\n",[
    'table.VideoAnalyseRule[0][2].Name=Terrasse Grenzlinie',
    'table.VideoAnalyseRule[0][2].Enable=true',
    'table.VideoAnalyseRule[0][2].Type=CrossLineDetection',
    'table.VideoAnalyseRule[0][2].Id=8',
    'table.VideoAnalyseRule[0][2].ObjectTypes[0]=Human'
])."\r\n";
$camera=new MockTerraceMain();
$camera->properties=[
 'TerraceHost'=>'192.0.2.40','AuxJVHost'=>'192.168.107.95',
 'AuxWorkHost'=>'192.168.107.94','CameraPort'=>80,
 'Username'=>'mockuser','Password'=>'mockpass',
 'TerraceDiagnosticsEnabled'=>false,'TerraceIVSRuleName'=>''
];
$camera->cgiBody=$oneLine;
$camera->ConfigureTerraceMonitor();
tCheck(($camera->buffers['P03Internal_P03TerraceMonitor']??'')==='IGNORIERT – Terrasse AUS'
    && $GLOBALS['terCreateCount']===0 && $GLOBALS['terGetCount']===0,
    'disabled by default: neither camera read nor new socket/observer');
$camera->properties['TerraceDiagnosticsEnabled']=true;
$camera->cgiBody=$oneLine
    .'table.VideoAnalyseRule[0][3].Name=Terrasse zweite'.chr(13).chr(10)
    .'table.VideoAnalyseRule[0][3].Enable=true'.chr(13).chr(10)
    .'table.VideoAnalyseRule[0][3].Type=CrossLineDetection'.chr(13).chr(10)
    .'table.VideoAnalyseRule[0][3].Id=9'.chr(13).chr(10)
    .'table.VideoAnalyseRule[0][3].ObjectTypes[0]=Human'.chr(13).chr(10);
$camera->ConfigureTerraceMonitor();
tCheck($GLOBALS['terCreateCount']===0
    && str_contains((string)$camera->buffers['P03Internal_P03TerraceMonitor'],'GESPERRT'),
    'ambiguous two-line terrace camera produces no P03 observer');
$camera->properties['TerraceIVSRuleName']='Terrasse Grenzlinie';
$camera->ConfigureTerraceMonitor();
$id=$camera->attributes['TerraceObserverInstanceID']??0;
tCheck($id>0 && $GLOBALS['terCreateCount']===1
    && $GLOBALS['terProps'][$id]['Role']==='TERRACE'
    && $GLOBALS['terProps'][$id]['ProofMode']==='EXISTING_STAIR_IVS'
    && $GLOBALS['terProps'][$id]['AllowedIVSRules']!=='[]',
    'explicit selected unique Human line yields dedicated third P03 observer');
$ids=array_values($GLOBALS['terVarIds'][$id]??[]);
tCheck(count($camera->subscribed)===4
    && count(array_intersect($ids,array_keys($camera->subscribed)))===4,
    'P03 main subscribes exactly four owned terrace variables for instant read-only diagnosis');
$diagBefore=$camera->autoDiagnosisCount;
$terraceCountVar=$GLOBALS['terVarIds'][$id]['HumanEventCounter'];
$GLOBALS['terVarValues'][$terraceCountVar]=1;
$camera->MessageSink(time(),$terraceCountVar,10603,[1,true]);
tCheck($camera->autoDiagnosisCount===$diagBefore+1
    && !isset($camera->attributes['AuxJVLastSeenHumanCounter'])
    && !isset($camera->attributes['AuxWorkLastSeenHumanCounter'])
    && !isset($camera->attributes['P03HumanEvents']),
    'terrace counter VM_UPDATE refreshes #35115 but cannot enter mast proof or create transfer');
$lastIVS=$GLOBALS['terVarIds'][$id]['LastIVSEvent'];
$diagBefore=$camera->autoDiagnosisCount;
$camera->MessageSink(time(),$lastIVS,10603,['[{"code":"CrossLineDetection"}]',true]);
tCheck($camera->autoDiagnosisCount===$diagBefore+1,
    'both accepted and rejected terrace IVS diagnostic updates refresh main immediately');
$diagBefore=$camera->autoDiagnosisCount;
$camera->MessageSink(time(),123456,10603,[1,true]);
tCheck($camera->autoDiagnosisCount===$diagBefore,
    'unsubscribed foreign Symcon variable cannot fake optional camera event');
$allow=json_decode($GLOBALS['terProps'][$id]['AllowedIVSRules'],true);
tCheck(count($allow)===1 && $allow[0]['id']===8 && $allow[0]['tableIndex']===2,
    'third observer enforces exact existing camera rule identity');
tCheck(!isset($camera->attributes['AuxJVEventCounterVarID'])
    && !isset($camera->attributes['AuxWorkEventCounterVarID'])
    && str_contains((string)$camera->buffers['P03Internal_P03TerraceMonitor'],'KEIN Einfluss'),
    'third observer is not wired as a mast source or zone transition');
$camera->ConfigureTerraceMonitor();
tCheck($GLOBALS['terCreateCount']===1,
    'repeated setup reuses existing owned terrace observer; no duplicates');
$camera->properties['TerraceDiagnosticsEnabled']=false;
$camera->ConfigureTerraceMonitor();
tCheck($GLOBALS['terProps'][$id]['Enabled']===false
    && ($camera->buffers['P03Internal_P03TerraceMonitor']??'')==='IGNORIERT – Terrasse AUS',
    'turn OFF disables only optional terrace observer');
tCheck(count($camera->subscribed)===0
    && (int)($camera->attributes['TerraceHumanCounterVarID']??-1)===0
    && (int)($camera->attributes['TerraceLastIVSVarID']??-1)===0,
    'turn OFF unregisters terrace event triggers and drops all diagnostic subscriptions');
$diagBefore=$camera->autoDiagnosisCount;
$camera->MessageSink(time(),$terraceCountVar,10603,[99,true]);
tCheck($camera->autoDiagnosisCount===$diagBefore,
    'disabled terrace event may never refresh or alter any zone proof');
$camera->properties['TerraceDiagnosticsEnabled']=true;
$camera->ConfigureTerraceMonitor();
tCheck($GLOBALS['terCreateCount']===1 && $GLOBALS['terProps'][$id]['Enabled']===true,
    're-enable reuses same observer and own socket');
$extra=++$GLOBALS['terNextID'];
$GLOBALS['terInstances'][$extra]=[
    'ConnectionID'=>$GLOBALS['terInstances'][$id]['ConnectionID'],
    'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>'FOREIGN']
];
$before=count($GLOBALS['terChanges']);
$camera->ConfigureTerraceMonitor();
tCheck(count($GLOBALS['terChanges'])===$before
    && str_contains((string)$camera->buffers['P03Internal_P03TerraceMonitor'],'kein exklusiver P03 Client Socket'),
    'shared P03 socket cannot be touched, even for existing optional observer');
unset($GLOBALS['terInstances'][$extra]);
$camera->properties['TerraceDiagnosticsEnabled']=false;
$camera->ConfigureTerraceMonitor();
tCheck($GLOBALS['terProps'][$id]['Enabled']===false,
    'after removing simulated foreign sharer optional monitor can be disabled');

 
// Reproduce Oct 11 18:22: genuine Symcon 9 observer with ConnectionID=0.
// This MUST use exactly one brand-new P03-owned socket. No mast/foreign IO.
$GLOBALS['terAutoParentLink']=false;
$orphan=new MockTerraceMain();
$orphan->properties=$camera->properties;
$orphan->properties['TerraceDiagnosticsEnabled']=true;
$orphan->properties['TerraceHost']='192.0.2.41';
$orphan->properties['TerraceIVSRuleName']='Terrasse Grenzlinie';
$orphan->cgiBody=$oneLine;
$oldCreates=$GLOBALS['terCreateCount'];
$oldSockets=count($GLOBALS['terOwnSocketIDs']);
$orphan->ConfigureTerraceMonitor();
$orphanID=(int)($orphan->attributes['TerraceObserverInstanceID']??0);
$ownedSocket=(int)($orphan->attributes['TerraceManagedSocketID']??0);
tCheck($GLOBALS['terCreateCount']===$oldCreates+1
    && count($GLOBALS['terOwnSocketIDs'])===$oldSockets+1
    && $orphanID>0 && $ownedSocket>0
    && ($GLOBALS['terInstances'][$orphanID]['ConnectionID']??0)===$ownedSocket
    && ($GLOBALS['terProps'][$ownedSocket]['Host']??'')==='192.0.2.41',
    'Symcon9 orphaned terrace observer gets exactly one explicit owned parent socket');
tCheck(str_contains((string)$orphan->buffers['P03Internal_P03TerraceMonitor'],'AKTIV')
    && $GLOBALS['terProps'][$orphanID]['Enabled']===true,
    'fixed orphaned TERRACE becomes active without changing mast sockets');
$orphan->ConfigureTerraceMonitor();
tCheck($GLOBALS['terCreateCount']===$oldCreates+1
    && count($GLOBALS['terOwnSocketIDs'])===$oldSockets+1,
    'repeated orphan repair is idempotent; no redundant sockets');

$GLOBALS['terFailConnectOnce']=true;
$retry=new MockTerraceMain();
$retry->properties=$orphan->properties;
$retry->properties['TerraceHost']='192.0.2.42';
$retry->cgiBody=$oneLine;
$retry->ConfigureTerraceMonitor();
$retryID=(int)($retry->attributes['TerraceObserverInstanceID']??0);
$retrySocket=(int)($retry->attributes['TerraceManagedSocketID']??0);
tCheck($retryID>0 && $retrySocket>0
    && ($GLOBALS['terInstances'][$retryID]['ConnectionID']??-1)===0
    && str_contains((string)$retry->buffers['P03Internal_P03TerraceMonitor'],'GESPERRT'),
    'failed initial IPS_ConnectInstance preserves exact observer/socket IDs');
$socketCount=count($GLOBALS['terOwnSocketIDs']);
$retry->ConfigureTerraceMonitor();
tCheck(count($GLOBALS['terOwnSocketIDs'])===$socketCount
    && ($GLOBALS['terInstances'][$retryID]['ConnectionID']??0)===$retrySocket
    && str_contains((string)$retry->buffers['P03Internal_P03TerraceMonitor'],'AKTIV'),
    'retry reconnects same pending P03 socket without creating any duplicate');

$retry->properties['TerraceDiagnosticsEnabled']=false;
$retry->ConfigureTerraceMonitor();
tCheck($GLOBALS['terProps'][$retryID]['Enabled']===false,
    'optional explicit-parent TERRACE disables without touching mast clients');

// Persisted pending socket belonging to another instance must never be reused.
$blocked=new MockTerraceMain();
$blocked->properties=$retry->properties;
$blocked->properties['TerraceHost']='192.0.2.43';
$blocked->properties['TerraceDiagnosticsEnabled']=true;
$blocked->cgiBody=$oneLine;
$blocked->attributes['TerraceManagedSocketID']=$retrySocket;
$blocked->ConfigureTerraceMonitor();
tCheck(str_contains((string)$blocked->buffers['P03Internal_P03TerraceMonitor'],'GESPERRT')
    && str_contains((string)$blocked->buffers['P03Internal_P03TerraceMonitor'],'bereits')
    && ($GLOBALS['terInstances'][$retryID]['ConnectionID']??0)===$retrySocket,
    'old managed socket attached to another observer cannot be hijacked');
echo 'P03 OPTIONAL TERRACE INTEGRATION SIMULATION PASSED'.PHP_EOL;
