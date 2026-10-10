<?php
declare(strict_types=1);

/**
 * Contract-level integration simulation for the actual P03 Aux Observer class.
 * No camera and no real IP-Symcon instance is accessed.
 * Simulates: Client Socket lifecycle, HTTP Digest 401->200, fragmented
 * multipart events, dedupe, human-only filtering and stream loss.
 */
$GLOBALS['mockSocket'] = [
    99001 => ['ConnectionID' => 0, 'InstanceStatus' => 104,
              'ModuleInfo' => ['ModuleID' => '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}'],
              'properties' => ['Host' => '', 'Port' => 80, 'Open' => false]],
];
$GLOBALS['mockValueById'] = [];
$GLOBALS['mockCount'] = 0;

function IPS_InstanceExists(int $id): bool
{
    return $id === 57215 || isset($GLOBALS['mockSocket'][$id]);
}
function IPS_GetInstance(int $id): array
{
    if ($id === 57215) {
        return ['ConnectionID' => 99001, 'InstanceStatus' => 102];
    }
    return $GLOBALS['mockSocket'][$id] ?? [];
}
function IPS_GetProperty(int $id, string $key): mixed
{
    return $GLOBALS['mockSocket'][$id]['properties'][$key] ?? null;
}
function IPS_SetProperty(int $id, string $key, mixed $value): void
{
    $GLOBALS['mockSocket'][$id]['properties'][$key] = $value;
}
function IPS_ApplyChanges(int $id): void
{
    $open = (bool) ($GLOBALS['mockSocket'][$id]['properties']['Open'] ?? false);
    $GLOBALS['mockSocket'][$id]['InstanceStatus'] = $open ? 102 : 104;
}
function IPS_VariableExists(int $id): bool
{
    return array_key_exists($id, $GLOBALS['mockValueById']);
}
function GetValue(int $id): mixed
{
    return $GLOBALS['mockValueById'][$id] ?? null;
}

class IPSModule
{
    public int $InstanceID = 57215;
    public array $properties = [];
    public array $attributes = [];
    public array $buffers = [];
    public array $values = [];
    public array $timers = [];
    public array $sent = [];
    public array $debug = [];
    private array $variableIds = [];

    public function Create(): void {}
    public function ApplyChanges(): void {}
    protected function RequireParent(string $guid): bool { return true; }

    protected function RegisterPropertyBoolean(string $k, bool $v): void { $this->properties[$k] ??= $v; }
    protected function RegisterPropertyInteger(string $k, int $v): void { $this->properties[$k] ??= $v; }
    protected function RegisterPropertyString(string $k, string $v): void { $this->properties[$k] ??= $v; }
    protected function ReadPropertyBoolean(string $k): bool { return (bool) ($this->properties[$k] ?? false); }
    protected function ReadPropertyInteger(string $k): int { return (int) ($this->properties[$k] ?? 0); }
    protected function ReadPropertyString(string $k): string { return (string) ($this->properties[$k] ?? ''); }

    protected function RegisterAttributeBoolean(string $k, bool $v): void { $this->attributes[$k] ??= $v; }
    protected function RegisterAttributeInteger(string $k, int $v): void { $this->attributes[$k] ??= $v; }
    protected function RegisterAttributeString(string $k, string $v): void { $this->attributes[$k] ??= $v; }
    protected function ReadAttributeBoolean(string $k): bool { return (bool) ($this->attributes[$k] ?? false); }
    protected function ReadAttributeInteger(string $k): int { return (int) ($this->attributes[$k] ?? 0); }
    protected function ReadAttributeString(string $k): string { return (string) ($this->attributes[$k] ?? ''); }
    protected function WriteAttributeBoolean(string $k, bool $v): void { $this->attributes[$k] = $v; }
    protected function WriteAttributeInteger(string $k, int $v): void { $this->attributes[$k] = $v; }
    protected function WriteAttributeString(string $k, string $v): void { $this->attributes[$k] = $v; }

    protected function RegisterVariableBoolean(string $k, string $label, string $profile, int $pos): void
    { $this->addVariable($k, false); }
    protected function RegisterVariableInteger(string $k, string $label, string $profile, int $pos): void
    { $this->addVariable($k, 0); }
    protected function RegisterVariableString(string $k, string $label, string $profile, int $pos): void
    { $this->addVariable($k, ''); }
    private function addVariable(string $k, mixed $v): void
    {
        if (isset($this->variableIds[$k])) return;
        $id = 100000 + count($this->variableIds);
        $this->variableIds[$k] = $id;
        $this->values[$k] = $v;
        $GLOBALS['mockValueById'][$id] = $v;
    }
    protected function SetValue(string $k, mixed $v): void
    {
        $this->values[$k] = $v;
        $GLOBALS['mockValueById'][$this->variableIds[$k]] = $v;
    }
    protected function GetValue(string $k): mixed { return $this->values[$k] ?? null; }
    protected function GetIDForIdent(string $k): int { return $this->variableIds[$k] ?? 0; }

    protected function RegisterTimer(string $k, int $interval, string $script): void { $this->timers[$k] ??= $interval; }
    protected function SetTimerInterval(string $k, int $interval): void { $this->timers[$k] = $interval; }
    protected function SetBuffer(string $k, string $v): void { $this->buffers[$k] = $v; }
    protected function GetBuffer(string $k): string { return $this->buffers[$k] ?? ''; }
    protected function RegisterMessage(int $id, int $event): void {}
    protected function UnregisterMessage(int $id, int $event): void {}
    protected function SendDataToParent(string $data): void { $this->sent[] = json_decode($data, true); }
    protected function SendDebug(string $topic, string $payload, int $format): void
    { $this->debug[] = [$topic, $payload]; }
}

require_once dirname(__DIR__) . '/JVPresenceP03AuxObserver/module.php';
$tests = 0;
function checkP03(bool $cond, string $description): void
{
    global $tests;
    $tests++;
    if (!$cond) throw new RuntimeException("FAIL #{$tests}: {$description}");
    echo "OK #{$tests} {$description}\n";
}
function rxP03(JVPresenceP03AuxObserver $observer, string $payload): void
{
    $observer->ReceiveData(json_encode([
        'DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}',
        'Buffer' => $payload
    ]));
}

$x = new JVPresenceP03AuxObserver();
$x->Create();
$x->properties['CameraHost'] = '192.0.2.5';
$x->properties['CameraPort'] = 80;
$x->properties['Username'] = 'sim-user';
$x->properties['Password'] = 'sim-password';
$x->properties['Role'] = 'JV_LEFT';
$x->properties['RuleIndex'] = 3;
$x->properties['RuleID'] = 4;
$x->ApplyChanges();
checkP03(($x->values['StreamOK'] ?? null) === false, 'No false-positive StreamOK before handshake');
checkP03(($x->attributes['SocketRestartStage'] ?? null) === 1, 'Reconnection staged after ApplyChanges');
$x->SocketRestartTimer(); // close
checkP03(($GLOBALS['mockSocket'][99001]['properties']['Open'] ?? true) === false, 'Socket explicitly closed before Digest retry');
$x->SocketRestartTimer(); // open
checkP03(($GLOBALS['mockSocket'][99001]['properties']['Open'] ?? false) === true, 'Dedicated Socket opened');
$x->HandshakeTimer();
checkP03(count($x->sent) === 1, 'Initial HTTP GET sent');
checkP03(str_contains((string) $x->sent[0]['Buffer'], 'codes=[All]&heartbeat=5'), 'Dahua eventManager subscription');

rxP03($x, "HTTP/1.1 401 Unauthorized\r\nWWW-Authenticate: Digest realm=\"Dahua\", nonce=\"abc123\", qop=\"auth\", algorithm=MD5\r\nContent-Length: 0\r\n\r\n");
checkP03(($x->attributes['AuthPending'] ?? null) === true, 'HTTP 401 accepted as Digest challenge');
checkP03(($x->attributes['SocketRestartStage'] ?? null) === 1, 'Authenticated reconnect scheduled');
$x->SocketRestartTimer();
$x->SocketRestartTimer();
$x->HandshakeTimer();
checkP03(count($x->sent) === 2, 'Authenticated HTTP GET sent');
checkP03(str_contains((string) $x->sent[1]['Buffer'], 'Authorization: Digest'), 'Digest authorization generated');
rxP03($x, "HTTP/1.1 200 OK\r\nContent-Type: multipart/x-mixed-replace; boundary=myboundary\r\n\r\n");
checkP03(($x->values['StreamOK'] ?? null) === true, 'HTTP 200 establishes live eventstream');
checkP03(($x->values['HumanEventCounter'] ?? null) === 0, 'HTTP 200 alone is not a human event');

$event = "Code=CrossRegionDetection;action=Start;index=0;data="
    . '{"Name":"P03_JV_LEFT_HUMAN","CfgRuleId":4,"RuleID":4,"RuleId":4,"EventID":801,"Object":{"ObjectType":"Human","ObjectID":3}}'
    . "\r\n";
$payload = "--myboundary\r\nContent-Type: text/plain\r\n\r\n" . $event;
for ($i = 0; $i < strlen($payload); $i += 7) {
    rxP03($x, substr($payload, $i, 7));
}
checkP03(($x->values['HumanEventCounter'] ?? null) === 1, 'Fragmented multipart Human increments exactly once');
checkP03(($x->values['PersonDetected'] ?? null) === true, 'Human pulse visible');
rxP03($x, $event);
checkP03(($x->values['HumanEventCounter'] ?? null) === 1, 'Repeated event ID deduplicated');
$stopped = str_replace('action=Start', 'action=Stop', $event);
rxP03($x, $stopped);
checkP03(($x->values['HumanEventCounter'] ?? null) === 1, 'STOP does not create a new proof');

$foreign = str_replace('P03_JV_LEFT_HUMAN', 'NOT_P03_RULE', str_replace('"EventID":801', '"EventID":802', $event));
rxP03($x, $foreign);
checkP03(($x->values['HumanEventCounter'] ?? null) === 1, 'Foreign rule ignored');

$vehicle = str_replace('"ObjectType":"Human"', '"ObjectType":"Vehicle"', str_replace('"EventID":801', '"EventID":803', $event));
rxP03($x, $vehicle);
checkP03(($x->values['HumanEventCounter'] ?? null) === 1, 'Explicit nonhuman payload fails closed');

$x->attributes['LastCameraRx'] = time() - 40;
$x->Watchdog();
checkP03(($x->values['StreamOK'] ?? null) === false, 'Heartbeat loss marks stream down');
checkP03(($x->attributes['SocketRestartStage'] ?? null) === 1, 'Heartbeat loss schedules reconnection');
$x->MessageSink(time(), 99001, 10505, [104]);
checkP03(($x->values['PersonDetected'] ?? null) === false, 'Closed parent clears PersonDetected');

echo "P03 OBSERVER INTEGRATION SIMULATION PASSED ({$tests} assertions)\n";
