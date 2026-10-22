<?php
declare(strict_types=1);
class IPSModule {
    public int $InstanceID=32969;
    public array $props=['Enabled'=>true];
    public array $attributes=[
        'PersonalSSIDSubscriptions'=>'[]',
        'GateSubscriptions'=>'[]',
        'SensorSubscriptions'=>'[]'];
    public array $buffers=[];
    public array $subscriptions=[];
    public int $registerCalls=0;
    public int $writes=0;
    public function ReadPropertyBoolean(string $key):bool{return $this->props[$key]??false;}
    public function ReadAttributeString(string $key):string{return $this->attributes[$key]??'[]';}
    public function WriteAttributeString(string $key,string $value):void{$this->attributes[$key]=$value;$this->writes++;}
    public function GetBuffer(string $key):string{return $this->buffers[$key]??'';}
    public function SetBuffer(string $key,string $value):void{$this->buffers[$key]=$value;}
    public function RegisterMessage(int $id,int $message):void{
        if($message!==10603)throw new RuntimeException('unexpected message id');
        $this->subscriptions[$id]=$message;$this->registerCalls++;
    }
    public function UnregisterMessage(int $id,int $message):void {
        if($message!==10603)throw new RuntimeException('unexpected remove message');
        unset($this->subscriptions[$id]);
    }
}
require_once dirname(__DIR__).'/JVPresenceIdentityShadow/module.php';
class ShadowPushHarness extends JVPresenceIdentityShadow {
    public int $refreshCalls=0;
    public function Refresh():void{$this->refreshCalls++;}
}
function pushCheck(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('SSID_PUSH '.$why);
}
$GLOBALS['pushMeta']=[
    56338=>['parent'=>56881,'type'=>3,'name'=>'WifiSSID'],
    24058=>['parent'=>32081,'type'=>3,'name'=>'WifiSSID']
];
function IPS_VariableExists(int $id):bool{return isset($GLOBALS['pushMeta'][$id]);}
function IPS_GetVariable(int $id):array{return ['VariableType'=>$GLOBALS['pushMeta'][$id]['type']??null];}
function IPS_GetObject(int $id):array{
    if(isset($GLOBALS['pushMeta'][$id]))
        return ['ParentID'=>$GLOBALS['pushMeta'][$id]['parent']];
    if($id===56881||$id===32081)return ['ParentID'=>36587];
    throw new RuntimeException('Unknown object');
}
function IPS_GetName(int $id):string{
    if(isset($GLOBALS['pushMeta'][$id]))return $GLOBALS['pushMeta'][$id]['name'];
    if($id===56881)return 'Johannes Smartphone SSID Quelle';
    if($id===32081)return 'Carina Smartphone SSID Quelle';
    throw new RuntimeException('Unknown name');
}
$work=['schema'=>1,
    'authority'=>'PHONE_CURRENT_SSID_READ_ONLY_WORK_CONTEXT',
    'people'=>[
        'johannes'=>['sourceVerified'=>true,'sourceKey'=>'SSID:56338',
            'status'=>'STALE_CURRENT_SSID_UNCONFIRMED'],
        'carina'=>['sourceVerified'=>true,'sourceKey'=>'SSID:24058',
            'status'=>'STALE_CURRENT_SSID_UNCONFIRMED']
    ]];
$mod=new ShadowPushHarness();
$sync=new ReflectionMethod(JVPresenceIdentityShadow::class,
    'syncPersonalSSIDSubscriptions');
$sync->invoke($mod,$work);
pushCheck(array_keys($mod->subscriptions)===[24058,56338]
        || array_keys($mod->subscriptions)===[56338,24058],
    'two separate verified phone String sources, even while stale');
pushCheck($mod->attributes['PersonalSSIDSubscriptions']==='[24058,56338]',
    'two explicit separate source subscriptions persisted');
$mod->MessageSink(time(),56338,10603,[]);
$mod->MessageSink(time(),24058,10603,[]);
$mod->MessageSink(time(),11111,10603,[]);
$mod->MessageSink(time(),56338,999,[]);
pushCheck($mod->refreshCalls===2,
    'two genuine personal SSID updates trigger immediate read; others ignored');
$register=$mod->registerCalls;$writes=$mod->writes;
$sync->invoke($mod,$work);
pushCheck($mod->registerCalls===$register
    &&$mod->writes===$writes,
    'unchanged 60 sec poll does not repeatedly register or flash attribute');
$mod->SetBuffer('PersonalSSIDRuntimeSubscriptionList','');
$sync->invoke($mod,$work);
pushCheck($mod->registerCalls===$register+2
    &&$mod->writes===$writes,
    'restart re-registers both VM_UPDATE handlers without flash churn');
$GLOBALS['pushMeta'][24058]['type']=1;
$sync->invoke($mod,$work);
pushCheck(!isset($mod->subscriptions[24058])
    &&isset($mod->subscriptions[56338]),
    'source changed type cannot keep an active phone listener');
$GLOBALS['pushMeta'][24058]['type']=3;
$sync->invoke($mod,$work);
pushCheck(isset($mod->subscriptions[24058]),
    'valid independent phone source can reconnect automatically');
$mod->props['Enabled']=false;
$sync->invoke($mod,$work);
pushCheck($mod->subscriptions===[]
    &&$mod->attributes['PersonalSSIDSubscriptions']==='[]',
    'disabled instance unsubscribes and never triggers phone observer');
$mod->MessageSink(time(),56338,10603,[]);
pushCheck($mod->refreshCalls===2,
    'no live event wakeups when disabled');
echo "Live personal SSID event subscriptions: two sources, restart, role/type and disable PASS\n";
