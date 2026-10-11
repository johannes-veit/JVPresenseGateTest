<?php
declare(strict_types=1);

/**
 * ACTUAL SwitchToVerifiedLeftCameras() method executed against an isolated
 * Symcon virtual object tree. Camera operations/network access forbidden.
 */
const P03_OBS_GUID='{8F4A3A57-3A5B-4F6E-9A64-9E1D326FF7B4}';
const P03_SOCKET_GUID='{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
function resetLeftFixture():void {
    $GLOBALS['instances']=[
        53879=>['ConnectionID'=>0,'ModuleInfo'=>['ModuleID'=>'P03_MAIN']],
        57215=>['ConnectionID'=>17785,'ModuleInfo'=>['ModuleID'=>P03_OBS_GUID]],
        27938=>['ConnectionID'=>55478,'ModuleInfo'=>['ModuleID'=>P03_OBS_GUID]],
        17785=>['ConnectionID'=>0,'ModuleInfo'=>['ModuleID'=>P03_SOCKET_GUID]],
        55478=>['ConnectionID'=>0,'ModuleInfo'=>['ModuleID'=>P03_SOCKET_GUID]],
        60000=>['ConnectionID'=>0,'ModuleInfo'=>['ModuleID'=>'EXTERNAL_ALARM']]
    ];
    $GLOBALS['props']=[
        57215=>['CameraHost'=>'192.168.107.96','CameraPort'=>80,
            'Role'=>'JV_LEFT','ProofMode'=>'LEGACY_OWNED','AllowedIVSRules'=>'["OLD"]'],
        27938=>['CameraHost'=>'192.168.107.99','CameraPort'=>80,
            'Role'=>'WORK_LEFT','ProofMode'=>'LEGACY_OWNED','AllowedIVSRules'=>'["OLD"]'],
        17785=>['Host'=>'192.168.107.96','Port'=>80],
        55478=>['Host'=>'192.168.107.99','Port'=>80],
        60000=>['Host'=>'192.168.107.250','Port'=>80]
    ];
    $GLOBALS['byIdent']=[
        57215=>['HumanEventCounter'=>42076,'PersonDetected'=>55806,
            'LastEvent'=>25854,'LastIVSEvent'=>43904],
        27938=>['HumanEventCounter'=>34838,'PersonDetected'=>33071,
            'LastEvent'=>50069,'LastIVSEvent'=>48490]
    ];
    $GLOBALS['vars']=[42076=>0,55806=>false,25854=>'old right',
        43904=>'old IVS right',34838=>6,33071=>false,
        50069=>'old right 09:14',48490=>'old IVS right'];
    $GLOBALS['setProps']=[];$GLOBALS['applies']=[];$GLOBALS['writes']=[];
    $GLOBALS['created']=0;
}
function IPS_InstanceExists(int $id):bool { return isset($GLOBALS['instances'][$id]); }
function IPS_GetInstance(int $id):array { return $GLOBALS['instances'][$id]; }
function IPS_GetInstanceList():array { return array_keys($GLOBALS['instances']); }
function IPS_GetProperty(int $id,string $key):mixed {
    return $id===53879 ? ($GLOBALS['main']->properties[$key]??null)
        : ($GLOBALS['props'][$id][$key]??null);
}
function IPS_SetProperty(int $id,string $key,mixed $value):void {
    if (!in_array($id,[53879,57215,27938,17785,55478],true)) {
        throw new RuntimeException('FOREIGN INSTANCE MUTATION: '.$id);
    }
    $GLOBALS['setProps'][]=[$id,$key,$value];
    if ($id===53879) {
        $GLOBALS['main']->properties[$key]=$value;
    } else {
        $GLOBALS['props'][$id][$key]=$value;
    }
}
function IPS_ApplyChanges(int $id):void {
    if (!in_array($id,[53879,57215,27938,17785,55478],true)) {
        throw new RuntimeException('FOREIGN APPLY: '.$id);
    }
    $GLOBALS['applies'][]=$id;
}
function IPS_GetObjectIDByIdent(string $ident,int $parent):int {
    return (int)($GLOBALS['byIdent'][$parent][$ident]??0);
}
function IPS_VariableExists(int $id):bool { return array_key_exists($id,$GLOBALS['vars']); }
function GetValue(int $id):mixed { return $GLOBALS['vars'][$id]??null; }
function SetValue(int $id,mixed $value):void {
    $GLOBALS['vars'][$id]=$value;
    $GLOBALS['writes'][]=['variable'=>$id,'value'=>$value];
}
function IPS_CreateInstance(string $guid):int { $GLOBALS['created']++; throw new RuntimeException('NO INSTANCE CREATE'); }
function IPS_ConnectInstance(int $a,int $b):void { throw new RuntimeException('NO NEW CONNECTION'); }
class IPSModule {
    public int $InstanceID=53879;
    public array $properties=['AuxJVHost'=>'192.168.107.96',
        'AuxWorkHost'=>'192.168.107.99','CameraPort'=>80];
    public array $attributes=[
        'AuxJVInstanceID'=>57215,'AuxWorkInstanceID'=>27938,
        'AuxJVManagedSocketID'=>17785,'AuxWorkManagedSocketID'=>55478,
        'TestActive'=>true,'ProductionEnabled'=>true,'P03MultiAuditPassed'=>true,
        'P03SimulationPassed'=>true,'P03VerifiedCount'=>4,
        'P03HumanEvents'=>'[{"id":"old"}]',
        'P03PendingCrossings'=>'[{"id":"old"}]',
        'P03DirectionMap'=>'{"old":"OUT"}',
        'AuxJVAllowedIVSRules'=>'[1]','AuxWorkAllowedIVSRules'=>'[2]',
        'AuxJVLastSeenHumanCounter'=>0,'AuxWorkLastSeenHumanCounter'=>6,
        'AuxJVEventCounterVarID'=>42076,'AuxWorkEventCounterVarID'=>34838
    ];
    public array $values=['P03ConnectionDiagnosis'=>'','Protocol'=>'',
        'P03ProofState'=>'','P03DirectionStatus'=>''];
    public array $timers=[];
    public function Create():void {}
    public function ApplyChanges():void {}
    protected function GetIDForIdent(string $ident):int { return 0; }
    protected function ReadPropertyString(string $key):string { return (string)($this->properties[$key]??'');}
    protected function ReadPropertyInteger(string $key):int { return (int)($this->properties[$key]??0);}
    protected function ReadPropertyBoolean(string $key):bool { return (bool)($this->properties[$key]??false);}
    protected function ReadAttributeInteger(string $key):int { return (int)($this->attributes[$key]??0);}
    protected function ReadAttributeBoolean(string $key):bool { return (bool)($this->attributes[$key]??false);}
    protected function ReadAttributeString(string $key):string { return (string)($this->attributes[$key]??'');}
    protected function WriteAttributeInteger(string $key,int $value):void { $this->attributes[$key]=$value; }
    protected function WriteAttributeBoolean(string $key,bool $value):void { $this->attributes[$key]=$value; }
    protected function WriteAttributeString(string $key,string $value):void { $this->attributes[$key]=$value; }
    protected function SetValue(string $key,mixed $value):void { $this->values[$key]=$value; }
    protected function GetValue(string $key):mixed {return $this->values[$key]??null;}
    protected function SetTimerInterval(string $key,int $v):void {$this->timers[$key]=$v;}
    protected function SendDebug(string $t,string $d,int $f):void{}
}
require_once dirname(__DIR__).'/JVPresenceP03TerraceTest/module.php';
$passed=0;
function checkLeft(bool $result,string $message):void {
    global $passed;
    if (!$result)throw new RuntimeException('LEFT MIGRATION FAIL: '.$message);
    $passed++;
    echo 'PASS '.$message."\n";
}
function newLeftFixture():JVPresenceP03MultiCamera {
    resetLeftFixture();
    $GLOBALS['main']=new JVPresenceP03MultiCamera();
    return $GLOBALS['main'];
}
$main=newLeftFixture();
$main->SwitchToVerifiedLeftCameras();
checkLeft(($GLOBALS['props'][57215]['CameraHost']??'')==='192.168.107.95'
    && ($GLOBALS['props'][27938]['CameraHost']??'')==='192.168.107.94',
    'Existing P03 observer IDs re-targeted to actual LEFT cameras');
checkLeft(($GLOBALS['props'][17785]['Host']??'')==='192.168.107.95'
    && ($GLOBALS['props'][55478]['Host']??'')==='192.168.107.94',
    'Only existing EXCLUSIVE P03 socket hosts re-targeted');
checkLeft($main->properties['AuxJVHost']==='192.168.107.95'
    && $main->properties['AuxWorkHost']==='192.168.107.94',
    'Existing main instance host properties persisted');
checkLeft(($GLOBALS['props'][60000]['Host']??'')==='192.168.107.250'
    && $GLOBALS['created']===0
    && array_values(array_unique(array_column($GLOBALS['setProps'],0)))
        ===[17785,57215,55478,27938,53879],
    'No new observer/socket, no foreign module/socket mutation');
checkLeft($GLOBALS['vars'][42076]===0 && $GLOBALS['vars'][34838]===0
    && $GLOBALS['vars'][50069]==='' && $GLOBALS['vars'][25854]==='',
    'Old JV/WORK right camera counters and stale proof timestamps cleared');
checkLeft($main->attributes['TestActive']===false
    && $main->attributes['ProductionEnabled']===false
    && $main->attributes['P03MultiAuditPassed']===false
    && $main->attributes['P03DirectionMap']==='{}'
    && $main->attributes['P03HumanEvents']==='[]'
    && $main->attributes['P03VerifiedCount']===0,
    'Old right camera transfer and production evidence fully invalidated');
checkLeft(str_contains($main->values['P03ConnectionDiagnosis'],'192.168.107.95')
    && str_contains($main->values['P03ConnectionDiagnosis'],'192.168.107.94')
    && !str_contains($main->values['P03ConnectionDiagnosis'],'FEHLER'),
    'Successful configuration and socket migration visible in diagnosis');
$prior=count($GLOBALS['setProps']);
$main->SwitchToVerifiedLeftCameras();
checkLeft(count($GLOBALS['setProps'])===$prior+14
    && $GLOBALS['props'][17785]['Host']==='192.168.107.95'
    && $GLOBALS['props'][55478]['Host']==='192.168.107.94',
    'Re-running safe migration remains idempotent without moving the dedicated sockets');

$main=newLeftFixture();
$GLOBALS['instances'][60000]['ConnectionID']=55478;
$main->SwitchToVerifiedLeftCameras();
checkLeft($GLOBALS['setProps']===[]
    && str_contains($main->values['P03ConnectionDiagnosis'],'ABBRUCH'),
    'Shared WORK socket blocks both migrations before ANY write');

$main=newLeftFixture();
$main->properties['AuxJVHost']='192.168.107.196';
$main->SwitchToVerifiedLeftCameras();
checkLeft($GLOBALS['setProps']===[],
    'Custom unrelated camera host is NEVER silently overwritten');

$main=newLeftFixture();
unset($GLOBALS['instances'][27938]);
$main->SwitchToVerifiedLeftCameras();
checkLeft($GLOBALS['setProps']===[],
    'Missing camera observer prevents ALL host/socket changes');

$main=newLeftFixture();
$GLOBALS['instances'][57215]['ModuleInfo']['ModuleID']='ALA2';
$main->SwitchToVerifiedLeftCameras();
checkLeft($GLOBALS['setProps']===[],
    'Foreign module at historical observer ID is never modified');

$main=newLeftFixture();
$GLOBALS['instances'][17785]['ModuleInfo']['ModuleID']='OTHER_SOCKET';
$main->SwitchToVerifiedLeftCameras();
checkLeft($GLOBALS['setProps']===[],
    'Wrong socket module GUID blocks migration and prevents foreign IO changes');

$main=newLeftFixture();
$GLOBALS['props'][55478]['Host']='192.168.107.15';
$main->SwitchToVerifiedLeftCameras();
checkLeft($GLOBALS['setProps']===[],
    'Unexpected socket target triggers safe abort');

$main=newLeftFixture();
$main->InstanceID=50000;
$main->SwitchToVerifiedLeftCameras();
checkLeft($GLOBALS['setProps']===[],
    'Cloned main P03 instance cannot take over historical user instance/socket IDs');

$src=file_get_contents(dirname(__DIR__).'/JVPresenceP03TerraceTest/module.php');
$form=json_decode(file_get_contents(dirname(__DIR__).'/JVPresenceP03TerraceTest/form.json'),true);
checkLeft(str_contains($src,"AUX_JV_DEFAULT_HOST = '192.168.107.95'")
    && str_contains($src,"AUX_WORK_DEFAULT_HOST = '192.168.107.94'"),
    'New installation defaults point to JV LEFT and WORK LEFT, not right cameras');
checkLeft(in_array('JVP03MC_SwitchToVerifiedLeftCameras($id);',
    array_column($form['actions']??[],'onClick'),true),
    'Existing-instance migration button is wired to actual module method');
echo "P03 LEFT CAMERA IDENTITY/MIGRATION: $passed scenarios passed\n";
