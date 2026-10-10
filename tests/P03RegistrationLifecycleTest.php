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
echo "P03 CREATE/APPLYCHANGES REGISTRATION CONTRACT PASSED\n";
