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
function IPS_SetProperty(int $id,string $key,mixed $value):void {
    if (!isset($GLOBALS['terInstances'][$id]) ||
        ($GLOBALS['terInstances'][$id]['ModuleInfo']['ModuleID']??'')
            !=='{8F4A3A57-3A5B-4F6E-9A64-9E1D326FF7B4}') {
        throw new RuntimeException('Attempt to mutate non-terrace instance');
    }
    $GLOBALS['terChanges'][]=[$id,$key,$value];
    $GLOBALS['terProps'][$id][$key]=$value;
}
function IPS_CreateInstance(string $guid):int {
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
    if (($GLOBALS['terProps'][$id]['Role']??'')!=='TERRACE') {
        throw new RuntimeException('Unexpected ApplyChanges outside optional terrace');
    }
    if (($GLOBALS['terInstances'][$id]['ConnectionID']??0)===0) {
        $socket=++$GLOBALS['terNextID'];
        $GLOBALS['terInstances'][$socket]=[
            'ConnectionID'=>0,'InstanceStatus'=>102,
            'ModuleInfo'=>['ModuleID'=>'{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}']
        ];
        $GLOBALS['terProps'][$socket]=['Host'=>'192.0.2.40','Port'=>80,'Open'=>true];
        $GLOBALS['terInstances'][$id]['ConnectionID']=$socket;
    }
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
    protected function ReadAttributeString(string $key):string {return (string)($this->attributes[$key]??'');}
    protected function WriteAttributeInteger(string $key,int $value):void {$this->attributes[$key]=$value;}
    protected function WriteAttributeString(string $key,string $value):void {$this->attributes[$key]=$value;}
    protected function GetValue(string $key):mixed {return $this->values[$key]??'';}
    protected function SetValue(string $key,mixed $value):void {$this->values[$key]=$value;}
}
require_once dirname(__DIR__).'/JVPresenceP03TerraceTest/module.php';

class MockTerraceMain extends JVPresenceP03MultiCamera {
    public string $cgiBody='';
    protected function genericCameraGet(
        string $host,int $port,string $username,string $password,
        string $uri,int $timeoutSeconds=6
    ):array {
        if ($host!=='192.0.2.40' || $username!=='mockuser'
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
tCheck(($camera->values['P03TerraceMonitor']??'')==='IGNORIERT – Terrasse AUS'
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
    && str_contains((string)$camera->values['P03TerraceMonitor'],'GESPERRT'),
    'ambiguous two-line terrace camera produces no P03 observer');
$camera->properties['TerraceIVSRuleName']='Terrasse Grenzlinie';
$camera->ConfigureTerraceMonitor();
$id=$camera->attributes['TerraceObserverInstanceID']??0;
tCheck($id>0 && $GLOBALS['terCreateCount']===1
    && $GLOBALS['terProps'][$id]['Role']==='TERRACE'
    && $GLOBALS['terProps'][$id]['ProofMode']==='EXISTING_STAIR_IVS'
    && $GLOBALS['terProps'][$id]['AllowedIVSRules']!=='[]',
    'explicit selected unique Human line yields dedicated third P03 observer');
$allow=json_decode($GLOBALS['terProps'][$id]['AllowedIVSRules'],true);
tCheck(count($allow)===1 && $allow[0]['id']===8 && $allow[0]['tableIndex']===2,
    'third observer enforces exact existing camera rule identity');
tCheck(!isset($camera->attributes['AuxJVEventCounterVarID'])
    && !isset($camera->attributes['AuxWorkEventCounterVarID'])
    && str_contains((string)$camera->values['P03TerraceMonitor'],'KEIN Einfluss'),
    'third observer is not wired as a mast source or zone transition');
$camera->ConfigureTerraceMonitor();
tCheck($GLOBALS['terCreateCount']===1,
    'repeated setup reuses existing owned terrace observer; no duplicates');
$camera->properties['TerraceDiagnosticsEnabled']=false;
$camera->ConfigureTerraceMonitor();
tCheck($GLOBALS['terProps'][$id]['Enabled']===false
    && ($camera->values['P03TerraceMonitor']??'')==='IGNORIERT – Terrasse AUS',
    'turn OFF disables only optional terrace observer');
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
    && str_contains((string)$camera->values['P03TerraceMonitor'],'Socket nicht sicher exklusiv'),
    'shared P03 socket cannot be touched, even for existing optional observer');
unset($GLOBALS['terInstances'][$extra]);
$camera->properties['TerraceDiagnosticsEnabled']=false;
$camera->ConfigureTerraceMonitor();
tCheck($GLOBALS['terProps'][$id]['Enabled']===false,
    'after removing simulated foreign sharer optional monitor can be disabled');
echo 'P03 OPTIONAL TERRACE INTEGRATION SIMULATION PASSED'.PHP_EOL;
