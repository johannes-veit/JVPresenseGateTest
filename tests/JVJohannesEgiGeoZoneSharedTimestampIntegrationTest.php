<?php
declare(strict_types=1);

// Real module adapter integration test. EgiGeoZone has ONE device-global
// Timestamp and separate zone booleans. Its webhook writes Timestamp BEFORE
// the zone boolean of the actual event. A different zone must not lend its
// timestamp to an older Zuhause flag, even just a few seconds later.
class IPSModule {
    private array $properties=[];
    public array $registered=[];
    public function Create():void {}
    public function RegisterPropertyBoolean(string $n,bool $v):void{$this->properties[$n]=$v;}
    public function RegisterPropertyInteger(string $n,int $v):void{$this->properties[$n]=$v;}
    public function RegisterPropertyString(string $n,string $v):void{$this->properties[$n]=$v;}
    public function ReadPropertyInteger(string $n):int{return (int)($this->properties[$n]??0);}
    public function ReadPropertyString(string $n):string{return (string)($this->properties[$n]??'');}
    public function RegisterAttributeString(string $n,string $v):void{}
    public function RegisterVariableString(string $n,string $label,string $profile,int $sort):void{
        $this->registered[]=$n;
    }
    public function RegisterVariableBoolean(string $n,string $label,string $profile,int $sort):void{
        $this->registered[]=$n;
    }
    public function RegisterTimer(string $n,int $time,string $code):void{}
}
function IPS_VariableExists(int $id):bool{
    return in_array($id,[41869,28055],true);
}
function IPS_GetObject(int $id):array{
    return ['ParentID'=>$GLOBALS['egiz_objects'][$id]??0];
}
function IPS_GetName(int $id):string{
    return $GLOBALS['egiz_names'][$id]??'';
}
function IPS_GetVariable(int $id):array{
    return $GLOBALS['egiz_variables'][$id]??[];
}
function GetValue(int $id):mixed{
    return $GLOBALS['egiz_values'][$id]??null;
}
require_once dirname(__DIR__).'/JVPresenceIdentityShadow/module.php';
function egiShareAssert(bool $valid,string $case):void{
    if(!$valid)throw new RuntimeException('EGI_SHARED_TIMESTAMP '.$case);
}
$GLOBALS['egiz_objects']=[50000=>35965,41869=>50000,28055=>50000];
$GLOBALS['egiz_names']=[41869=>'Zuhause',28055=>'Timestamp'];
$GLOBALS['egiz_values']=[41869=>true,28055=>1792700002];
$GLOBALS['egiz_variables']=[
    41869=>['VariableType'=>0,'VariableUpdated'=>1792700000],
    28055=>['VariableType'=>1,'VariableUpdated'=>1792700002]
];
$m=new JVPresenceIdentityShadow();
$m->Create();
$read=new ReflectionMethod(JVPresenceIdentityShadow::class,
    'readExistingJohannesEgiGeoZone');

// Home event t=0, OTHER ZONE "Schiebetor" event t=2.
// Old symmetric 60-second rule wrongly accepts the OTHER zone timestamp as
// a fresh HOME timestamp. The new rule rejects it, fail-closed.
$result=$read->invoke($m);
egiShareAssert(($result['verified']??false)===false,
    'other-zone Timestamp newer than Zuhause boolean must never be paired');

// Genuine Zuhause event: timestamp SetValue is executed first, then HOME
// boolean SetValue, preserving object metadata order.
$GLOBALS['egiz_variables'][41869]['VariableUpdated']=1792700003;
$result=$read->invoke($m);
egiShareAssert(($result['verified']??false)===true
    &&$result['inside']===true
    &&$result['timestamp']===1792700002
    &&$result['sourceKey']==='EGI:35965:50000:41869:28055',
    'genuine ordered HOME SetValue pair accepted, existing IDs preserved');

// Legitimate zone can take a little processing time but cannot borrow
// a timestamp from many seconds earlier.
$GLOBALS['egiz_variables'][41869]['VariableUpdated']=1792700007;
egiShareAssert(($read->invoke($m)['verified']??false)===true,
    'sequential real webhook updates within five seconds accepted');
$GLOBALS['egiz_variables'][41869]['VariableUpdated']=1792700008;
egiShareAssert(($read->invoke($m)['verified']??false)===false,
    'six-second mismatch is not a trusted same-request pair');

// The historical adapter accepted missing update metadata. That is
// unsafe with two zones, so fail closed rather than invent event lineage.
unset($GLOBALS['egiz_variables'][41869]['VariableUpdated']);
egiShareAssert(($read->invoke($m)['verified']??false)===false,
    'missing zone update metadata is not verified');
$GLOBALS['egiz_variables'][41869]['VariableUpdated']=1792700003;
unset($GLOBALS['egiz_variables'][28055]['VariableUpdated']);
egiShareAssert(($read->invoke($m)['verified']??false)===false,
    'missing global Timestamp metadata is not verified');
$GLOBALS['egiz_variables'][28055]['VariableUpdated']=1792700002;

// A removed, renamed or reassigned object cannot be trusted.
$GLOBALS['egiz_names'][41869]='TestGeoZone';
egiShareAssert(($read->invoke($m)['verified']??false)===false,
    'wrong renamed geofence ID does not silently change source identity');
$GLOBALS['egiz_names'][41869]='Zuhause';
$GLOBALS['egiz_objects'][41869]=90000;
egiShareAssert(($read->invoke($m)['verified']??false)===false,
    'reparented geofence variable is rejected');
$GLOBALS['egiz_objects'][41869]=50000;
$GLOBALS['egiz_variables'][41869]['VariableType']=1;
egiShareAssert(($read->invoke($m)['verified']??false)===false,
    'wrong boolean type rejected');

egiShareAssert(count($m->registered)===7,
    'no new Symcon variables, events, timers or foreign writes');
echo "SHADOW EgiGeoZone shared-Timestamp real adapter nine scenarios PASS\n";
