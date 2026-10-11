<?php
declare(strict_types=1);

/**
 * IP-Symcon API contract:
 * RegisterAttribute*() and RegisterTimer() are Create()-only APIs.
 * Previously real Symcon instances emitted >20 runtime warnings because
 * P03 re-registered ten observer attributes inside ApplyChanges().
 *
 * Unlike legacy mocks, this test rejects incorrect registration LOCATION
 * in both primary P03 modules and catches duplicate attribute names.
 */
function lifecycleAssert(bool $result, string $message): void
{
    if (!$result) {
        throw new RuntimeException('P03 LIFECYCLE FAIL: ' . $message);
    }
    echo 'OK: ' . $message . "\n";
}

foreach ([
    'JVPresenceP03AuxObserver/module.php',
    'JVPresenceP03TerraceTest/module.php'
] as $path) {
    $contents = file_get_contents(dirname(__DIR__) . '/' . $path);
    lifecycleAssert(is_string($contents) && $contents !== '', $path . ': load');

    $a = strpos($contents, '    public function Create(): void');
    $b = strpos($contents, '    public function ApplyChanges(): void', $a + 1);
    $c = strpos($contents, '    public function ', $b + 25);
    lifecycleAssert($a !== false && $b !== false && $c !== false && $a < $b && $b < $c,
        $path . ': method boundaries');
    $create = substr($contents,$a,$b-$a);
    $apply = substr($contents,$b,$c-$b);

    preg_match_all('/\$this->RegisterAttribute(?:Boolean|Integer|Float|String)\s*\(\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]/', $create, $attrs);
    lifecycleAssert(count($attrs[1]) >= ($path === 'JVPresenceP03AuxObserver/module.php' ? 24 : 30),
        $path . ': all attributes registered in Create');
    lifecycleAssert(count($attrs[1])===count(array_unique($attrs[1])),
        $path . ': no duplicate attribute names in Create');
    lifecycleAssert(!preg_match('/\$this->RegisterAttribute(?:Boolean|Integer|Float|String)\s*\(/', $apply),
        $path . ': ZERO RegisterAttribute calls in ApplyChanges');

    preg_match_all('/\$this->RegisterTimer\s*\(\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]/', $create, $timers);
    lifecycleAssert(count($timers[1])>=3,
        $path . ': timers registered during Create');
    lifecycleAssert(count($timers[1])===count(array_unique($timers[1])),
        $path . ': no duplicate timers in Create');
    lifecycleAssert(!preg_match('/\$this->RegisterTimer\s*\(/', $apply),
        $path . ': ZERO RegisterTimer calls in ApplyChanges');
    lifecycleAssert(str_contains($apply,'parent::ApplyChanges()'),
        $path . ': base ApplyChanges preserved');
}
$observer=file_get_contents(dirname(__DIR__).'/JVPresenceP03AuxObserver/module.php');
$prefix=json_decode(file_get_contents(dirname(__DIR__).'/JVPresenceP03AuxObserver/module.json'),true);
lifecycleAssert(($prefix['prefix']??'')==='JVP03AUX'
    && str_contains($observer,"JVP03AUX_OnvifPoll("),
    'ONVIF event polling timer uses the module actual registered function prefix');
lifecycleAssert(str_contains($observer,"RegisterTimer('OnvifPullTimer'")
    && str_contains($observer,"SetTimerInterval('OnvifPullTimer'")
    && str_contains($observer,"public function OnvifPoll(): void"),
    'ONVIF registration, ApplyChanges activation and method callback are connected');
$start=strpos($observer,'    public function OnvifPoll(): void');
$end=strpos($observer,'    public function GetConfigurationForParent(): string',$start);
$onvif=substr($observer,$start,$end-$start);
lifecycleAssert(str_contains($onvif,'OnvifPollBusyUntil')
    && str_contains($onvif,'OnvifNextAttemptAt')
    && str_contains($onvif,'finally'),
    'ONVIF poll serialized; error/backoff and lock release are present');
lifecycleAssert(!str_contains($onvif,'publishHumanProof(')
    && !str_contains($onvif,"SetValue('HumanEventCounter'"),
    'ONVIF passive diagnosis cannot inject unverified person counters or zone transfers');
echo "P03 CREATE/APPLYCHANGES REGISTRATION CONTRACT PASSED\n";
