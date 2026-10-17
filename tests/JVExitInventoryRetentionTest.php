<?php
declare(strict_types=1);

// Runtime-light regression: the 60s SHADOW refresh must not erase a manual
// metadata-only exit inventory. No real Symcon/P03/LCN/WLAN actions.
class IPSModule {
    private array $buffers=[];
    private array $properties=[];
    private array $values=[];
    public array $registeredVariables=[];
    public function Create():void {}
    public function RegisterPropertyBoolean(string $id,bool $value):void {$this->properties[$id]=$value;}
    public function RegisterPropertyInteger(string $id,int $value):void {$this->properties[$id]=$value;}
    public function RegisterPropertyString(string $id,string $value):void {$this->properties[$id]=$value;}
    private array $attributes=[];
    public function RegisterAttributeString(string $id,string $value):void {
        if(!array_key_exists($id,$this->attributes))$this->attributes[$id]=$value;
    }
    public function ReadAttributeString(string $id):string {
        return $this->attributes[$id]??'';
    }
    public function WriteAttributeString(string $id,string $value):void {
        $this->attributes[$id]=$value;
    }
    public function clearRuntimeBuffers():void {$this->buffers=[];}
    public function RegisterTimer(string $id,int $interval,string $script):void {}
    public function RegisterVariableString(string $id,string $name,string $profile,int $position):void {
        $this->registeredVariables[]=$id;
        $this->values[$id]='';
    }
    public function RegisterVariableBoolean(string $id,string $name,string $profile,int $position):void {
        $this->registeredVariables[]=$id;
        $this->values[$id]=false;
    }
    public function ReadPropertyBoolean(string $id):bool {return (bool)$this->properties[$id];}
    public function ReadPropertyInteger(string $id):int {return (int)$this->properties[$id];}
    public function ReadPropertyString(string $id):string {return (string)$this->properties[$id];}
    public function setTestProperty(string $id,mixed $value):void {$this->properties[$id]=$value;}
    public function SetBuffer(string $key,string $value):void {$this->buffers[$key]=$value;}
    public function GetBuffer(string $key):string {return $this->buffers[$key]??'';}
    public function GetValue(string $id):mixed {return $this->values[$id]??null;}
    public function SetValue(string $id,mixed $value):void {$this->values[$id]=$value;}
}

function IPS_GetVariableList():array {return [555,556,557];}
function IPS_GetVariable(int $id):array {return ['VariableType'=>0];}
function IPS_GetObject(int $id):array {
    return ['ParentID'=>in_array($id,[555,556,557],true)?11:($id===11?22:0)];
}
function IPS_GetName(int $id):string {
    return [555=>'Tor Status',556=>'Temperatur',557=>'WLAN Johannes Status',
        11=>'Hof',22=>'Gebäude'][$id]??'';
}
require_once dirname(__DIR__).'/JVPresenceIdentityShadow/module.php';

function assertSnapshot(bool $truth,string $msg):void {
    if(!$truth)throw new RuntimeException($msg);
}
$m=new JVPresenceIdentityShadow();
$m->Create();
$m->setTestProperty('Enabled',true);
$m->setTestProperty('P03InstanceID',0);
$m->setTestProperty('SensorSources','[]');
$m->AuditExistingExitCandidates();
$initial=json_decode((string)$m->GetValue('EvidenceJSON'),true);
assertSnapshot(($initial['authority']??'')==='READ_ONLY_EXIT_SOURCE_CANDIDATES',
    'scan result published immediately');
assertSnapshot(($initial['candidateCount']??-1)===1,'only real gate candidate retained');
assertSnapshot(($initial['configurationChanged']??true)===false,
    'scan cannot change configuration');
assertSnapshot(is_int($initial['scannedAt']??null),'scan timestamp required');
$m->Refresh();
$first=json_decode((string)$m->GetValue('EvidenceJSON'),true);
assertSnapshot(($first['exitCandidateInventory']['candidateCount']??-1)===1,
    'timer-style Refresh must retain last candidate scan');
assertSnapshot(($first['exitCandidateInventory']['scannedAt']??null)===$initial['scannedAt'],
    'audit timestamp remains unchanged across refresh');
assertSnapshot(($first['boundaryEvidence']['configuredBoundarySignals']??-1)===0,
    'candidate scan never attaches sensors');
assertSnapshot(($first['personPresenceMayBeChanged']??null)===false,
    'candidate scan never confirms presence');
assertSnapshot(($first['propertyEmptyMayBeCertified']??null)===false,
    'candidate scan never confirms empty property');
$m->Refresh();
$again=json_decode((string)$m->GetValue('EvidenceJSON'),true);
assertSnapshot(($again['exitCandidateInventory']['candidateCount']??-1)===1,
    'second refresh still retains scan');
assertSnapshot($m->registeredVariables===['Status','EvidenceJSON','JohannesPresence','CarinaPresence','PresenceDetected','ResidentTile','TestReport'],
    'exactly five historical outputs plus one combined tile and one requested copy-paste report');
$m->clearRuntimeBuffers();
$m->Refresh();
$restarted=json_decode((string)$m->GetValue('EvidenceJSON'),true);
assertSnapshot(($restarted['exitCandidateInventory']['candidateCount']??null)===1,
    'erased runtime buffers do NOT erase persistent exit inventory');
assertSnapshot(($restarted['exitCandidateInventory']['scannedAt']??null)===$initial['scannedAt'],
    'original scan timestamp survives volatile buffer loss');
echo "Shadow exit-inventory refresh retention PASS\n";
