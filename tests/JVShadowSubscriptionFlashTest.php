<?php
declare(strict_types=1);
class IPSModule {
    private array $properties=[],$attrs=[],$vars=[];
    public array $registered=[],$unsubscribed=[],$subscribed=[],$writes=[];
    public function Create():void{}
    public function RegisterPropertyBoolean(string $n,bool $v):void{$this->properties[$n]=$v;}
    public function RegisterPropertyInteger(string $n,int $v):void{$this->properties[$n]=$v;}
    public function RegisterPropertyString(string $n,string $v):void{$this->properties[$n]=$v;}
    public function SetTestProperty(string $n,mixed $v):void{$this->properties[$n]=$v;}
    public function ReadPropertyBoolean(string $n):bool{return (bool)($this->properties[$n]??false);}
    public function ReadPropertyString(string $n):string{return (string)($this->properties[$n]??'');}
    public function RegisterAttributeString(string $n,string $v):void{$this->attrs[$n]??=$v;}
    public function ReadAttributeString(string $n):string{return $this->attrs[$n]??'';}
    public function WriteAttributeString(string $n,string $v):void{$this->attrs[$n]=$v;$this->writes[$n]=($this->writes[$n]??0)+1;}
    public function RegisterVariableString(string $n,string $label,string $prof,int $pos):void{$this->vars[]=$n;}
    public function RegisterVariableBoolean(string $n,string $label,string $prof,int $pos):void{$this->vars[]=$n;}
    public function RegisterTimer(string $n,int $ms,string $php):void{}
    public function RegisterMessage(int $id,int $message):void{$this->subscribed[]=$id;}
    public function UnregisterMessage(int $id,int $message):void{$this->unsubscribed[]=$id;}
    public function variableCount():int{return count($this->vars);}
}
function IPS_VariableExists(int $id):bool{return in_array($id,[111,222],true);}
function IPS_GetVariable(int $id):array{return ['VariableType'=>$id===111?0:1];}
require_once dirname(__DIR__).'/JVPresenceIdentityShadow/module.php';
function subscriptionCheck(bool $condition,string $what):void{
    if(!$condition)throw new RuntimeException('GUS_SUBSCRIPTION_FLASH '.$what);
}
$m=new JVPresenceIdentityShadow();$m->Create();
$sync=new ReflectionMethod(JVPresenceIdentityShadow::class,'syncSensorSubscriptions');
// Disabled/empty configuration does not rewrite the same '[]' attribute.
for($n=0;$n<20;$n++)$sync->invoke($m);
subscriptionCheck(($m->writes['SensorSubscriptions']??0)===0,
    'no flash writes after 20 identical disabled applies');
$m->SetTestProperty('Enabled',true);
$m->SetTestProperty('SensorSources',json_encode([
    ['Enabled'=>true,'SourceID'=>'eg_flur','Name'=>'EG Flur',
     'Kind'=>'MOTION','Zone'=>'HOME','VariableID'=>111],
    ['Enabled'=>true,'SourceID'=>'og_flur','Name'=>'OG Flur',
     'Kind'=>'MOTION','Zone'=>'HOME','VariableID'=>222]
],JSON_THROW_ON_ERROR));
$sync->invoke($m);
subscriptionCheck($m->ReadAttributeString('SensorSubscriptions')==='[111,222]'
    &&($m->writes['SensorSubscriptions']??0)===1,
    'two verified GUS subscriptions are committed once');
for($n=0;$n<20;$n++)$sync->invoke($m);
subscriptionCheck(($m->writes['SensorSubscriptions']??0)===1
    &&count($m->subscribed)===42,
    '20 reconnects re-register VM_UPDATE but do not flash unchanged IDs');
$m->SetTestProperty('Enabled',false);
$sync->invoke($m);
subscriptionCheck(($m->writes['SensorSubscriptions']??0)===2
    &&$m->ReadAttributeString('SensorSubscriptions')==='[]'
    &&$m->unsubscribed===[111,222],
    'disabling GUS observer unsubscribes old IDs and stores exact change');
for($n=0;$n<20;$n++)$sync->invoke($m);
subscriptionCheck(($m->writes['SensorSubscriptions']??0)===2
    &&$m->variableCount()===7,
    'disabled idle applies never write and seven output variables stay');
echo "SHADOW GUS 61 ApplyChanges simulations, only 2 subscription flash writes PASS\n";
