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
$GLOBALS['mockChildParent'] = 99001;
$GLOBALS['mockForeignChild'] = false;
$GLOBALS['socketWrites'] = [];
$GLOBALS['mockCount'] = 0;

function IPS_InstanceExists(int $id): bool
{
    return $id === 57215 || isset($GLOBALS['mockSocket'][$id]);
}
function IPS_GetInstance(int $id): array
{
    if ($id === 57215) {
        return ['ConnectionID' => $GLOBALS['mockChildParent'], 'InstanceStatus' => 102];
    }
    if ($id === 80808 && $GLOBALS['mockForeignChild']) {
        return ['ConnectionID' => 99001, 'InstanceStatus' => 102];
    }
    return $GLOBALS['mockSocket'][$id] ?? [];
}
function IPS_GetInstanceList(): array
{
    return $GLOBALS['mockForeignChild'] ? [57215, 99001, 80808] : [57215, 99001];
}
function IPS_GetProperty(int $id, string $key): mixed
{
    return $GLOBALS['mockSocket'][$id]['properties'][$key] ?? null;
}
function IPS_SetProperty(int $id, string $key, mixed $value): void
{
    $GLOBALS['socketWrites'][] = [$id, $key, $value];
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
    public int $status = 101;
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
    protected function SetStatus(int $status): bool
    {
        $this->status = $status;
        return true;
    }
    protected function RequireParent(string $guid): bool
    {
        // Models Symcon creating a dedicated parent if the device has none.
        if ($GLOBALS['mockChildParent'] === 0) {
            $GLOBALS['mockChildParent'] = 99001;
        }
        return true;
    }

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
checkP03($x->status === 102,
    'P03 observer transitions from IS_CREATING to IS_ACTIVE after successful ApplyChanges');
checkP03(($x->values['StreamOK'] ?? null) === false,
    'IS_ACTIVE is not a false positive for StreamOK before HTTP handshake');
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
checkP03(($x->values['EventAudit'] ?? '') !== '' &&
    str_contains((string) $x->values['EventAudit'], 'RULE_OR_EVENTCODE_REJECTED') === false,
    'Matched Vehicle event has a visible noncounting reason');

// Simulate the Dahua IPC reusing EventID=801 for a completely new crossing
// after the short duplicate transport window. Before v0.6.19 this would
// be PERMANENTLY discarded, explaining "counter stuck at 3".
$seen = json_decode((string) ($x->attributes['SeenEventKeys'] ?? '{}'), true);
foreach ($seen as $key => $value) $seen[$key] = time() - 20;
$x->attributes['SeenEventKeys'] = json_encode($seen);
rxP03($x, $event);
checkP03(($x->values['HumanEventCounter'] ?? null) === 2,
    'Recycled Dahua EventID counts new Human Start after expiry');
$lastHuman = (string) ($x->values['LastEvent'] ?? '');
checkP03(str_contains((string) ($x->values['EventAudit'] ?? ''), 'HUMAN_START_COUNTED'),
    'Recycled EventID reports actual Human count');
rxP03($x, $event);
checkP03(($x->values['HumanEventCounter'] ?? null) === 2,
    'Immediate same EventID delivery still deduplicated');
checkP03(str_contains((string) ($x->values['EventAudit'] ?? ''), 'DUPLICATE_WITHIN_3_SECONDS'),
    'Duplicate rejection is explicitly visible');
rxP03($x, $stopped);
checkP03((string) ($x->values['LastEvent'] ?? '') === $lastHuman,
    'Stop cannot overwrite last valid Human proof');
checkP03(($x->values['HumanEventCounter'] ?? null) === 2,
    'Stop never increments Human counter');

// Same EventID can refer to different tracked persons (ObjectIDs).
$otherObject = str_replace('"ObjectID":3', '"ObjectID":4', $event);
rxP03($x, $otherObject);
checkP03(($x->values['HumanEventCounter'] ?? null) === 3,
    'Same EventID with different ObjectID counts independent Human Start');
rxP03($x, $otherObject);
checkP03(($x->values['HumanEventCounter'] ?? null) === 3,
    'Repeat packet for second person remains deduplicated');

$x->attributes['LastCameraRx'] = time() - 40;
$x->attributes['LastSocketRestart'] = time() - 60;
$beforeWatchdogRequests = count($x->sent);
$x->Watchdog();
checkP03(($x->values['StreamOK'] ?? null) === false, 'Heartbeat loss marks stream down');
checkP03(($x->attributes['SocketRestartStage'] ?? null) === 0
    && count($x->sent) === $beforeWatchdogRequests + 1,
    'Heartbeat loss directly reconnects instead of leaving phase 1 pending');
$x->MessageSink(time(), 99001, 10505, [104]);
checkP03(($x->values['PersonDetected'] ?? null) === false, 'Closed parent clears PersonDetected');

// A manually shared parent socket must never be closed or rewritten by P03.
$GLOBALS['mockForeignChild'] = true;
$GLOBALS['mockSocket'][99001]['properties']['Open'] = true;
$x->Reconnect();
$x->SocketRestartTimer();
checkP03(($GLOBALS['mockSocket'][99001]['properties']['Open'] ?? false) === true,
    'Foreign ALA2 socket is not closed by P03 restart');
checkP03($x->GetConfigurationForParent() === '{}',
    'P03 does not override a shared foreign socket configuration');
checkP03(($x->attributes['SocketRestartStage'] ?? -1) === 0,
    'Shared parent aborts reconnect fail-closed');
checkP03(str_contains((string) ($x->values['ObserverStatus'] ?? ''), 'geteilt'),
    'Shared parent reports actionable diagnosis');

// Missing parent must produce visible status; cannot be mistaken for live TCP.
$GLOBALS['mockForeignChild'] = false;
$GLOBALS['mockChildParent'] = 0;
$x->Reconnect();
$x->SocketRestartTimer();
checkP03(str_contains((string) ($x->values['ObserverStatus'] ?? ''), 'Client Socket'),
    'Missing parent has explicit diagnostic');
checkP03(($x->values['StreamOK'] ?? true) === false,
    'Missing parent never emits StreamOK=true');

$x->ApplyChanges();
checkP03($GLOBALS['mockChildParent'] === 99001,
    'ApplyChanges recreates dedicated parent when orphaned');
checkP03(($x->attributes['SocketRestartStage'] ?? null) === 1,
    'Recreated parent is scheduled for fresh Digest handshake');


// Test actual new direct-start path, bypassing a stuck scheduled stage 1.
// This is the live snapshot from Symcon 9: dedicated socket is assigned,
// but status=104, Open=false and restartStage=1.
$GLOBALS['mockChildParent'] = 99001;
$GLOBALS['mockForeignChild'] = false;
$GLOBALS['mockSocket'][99001]['InstanceStatus'] = 104;
$GLOBALS['mockSocket'][99001]['properties']['Open'] = false;
$direct = new JVPresenceP03AuxObserver();
$direct->Create();
$direct->properties['CameraHost'] = '192.0.2.5';
$direct->properties['Username'] = 'sim-user';
$direct->properties['Password'] = 'sim-password';
$direct->properties['Role'] = 'JV_LEFT';
$direct->properties['RuleID'] = 4;
$direct->ApplyChanges();
checkP03(($direct->attributes['SocketRestartStage'] ?? null) === 1,
    'Direct start: first stage pending as in actual Symcon diagnostic');
$ok = $direct->StartSocketNow();
checkP03($ok, 'Direct start completes without timer scheduler');
checkP03(($GLOBALS['mockSocket'][99001]['properties']['Open'] ?? false) === true,
    'Direct start switches dedicated socket Open=true');
checkP03(($GLOBALS['mockSocket'][99001]['InstanceStatus'] ?? 0) === 102,
    'Direct start activates socket');
checkP03(($direct->attributes['SocketRestartStage'] ?? -1) === 0,
    'Direct start cancels stale stage 1');
checkP03(($direct->timers['SocketRestartTimer'] ?? -1) === 0,
    'Direct start cancels pending socket restart callback');
checkP03(count($direct->sent) === 1 &&
    str_contains((string) $direct->sent[0]['Buffer'], '/cgi-bin/eventManager.cgi'),
    'Direct start transmits initial Dahua HTTP GET');

$GLOBALS['mockForeignChild'] = true;
$foreignReq = count($direct->sent);
checkP03($direct->StartSocketNow() === false,
    'Direct start refuses socket connected to foreign instance');
checkP03(count($direct->sent) === $foreignReq,
    'Foreign socket guard sends no HTTP request');
$GLOBALS['mockForeignChild'] = false;
// Heartbeat-age checks: the actual Symcon report showed StreamOK=true while
// LastCameraRx was 156 seconds stale. HTTP 200 must not remain proof of life.
rxP03($direct, "HTTP/1.1 200 OK\r\nContent-Type: multipart/x-mixed-replace\r\n\r\n");
$direct->attributes['LastCameraRx'] = time() - 156;
$direct->attributes['LastSocketRestart'] = time() - 60;
$initialSent = count($direct->sent);
$beforeWrites = count($GLOBALS['socketWrites']);
$outcome = $direct->HealthTick();
checkP03($outcome === 'RECONNECT_GET_SENT',
    'Stale HTTP-200 stream cannot remain healthy; direct reconnect attempted');
checkP03($direct->values['StreamOK'] === false,
    'HealthTick fails closed on 156s stale camera response');
checkP03($direct->attributes['LastHealthTick'] > 0 &&
    $direct->attributes['LastHealthAction'] === 'RECONNECT_GET_SENT',
    'HealthTick records successful invocation and outcome');
checkP03(count($direct->sent) === $initialSent+1,
    'Stale socket generates a fresh initial GET');
$writes=array_slice($GLOBALS['socketWrites'],$beforeWrites);
$openWrites=array_values(array_filter($writes,static fn($w)=>$w[1]==='Open'));
checkP03(count($openWrites)>=2 && $openWrites[0][2]===false
    && end($openWrites)[2]===true,
    'Stale TCP socket explicitly closes then reopens before new HTTP GET');

rxP03($direct, "HTTP/1.1 200 OK\r\nContent-Type: multipart/x-mixed-replace\r\n\r\n");
$before=count($direct->sent);
checkP03($direct->HealthTick()==='LIVE' && count($direct->sent)===$before,
    'Current response with HTTP-200 is healthy, no unnecessary reconnect');
$healthReported = json_decode((string)($direct->values['ObserverStatus']??''),true);
checkP03(is_array($healthReported)
    && ($healthReported['lastHealthAction']??'')==='LIVE'
    && (int)($healthReported['lastHealthTick']??0)>0,
    'Successful live HealthTick is visible in the copyable observer diagnostic');

$direct->attributes['LastCameraRx'] = time() - 45;
$direct->attributes['LastSocketRestart'] = time();
$before=count($direct->sent);
checkP03($direct->HealthTick()==='COOLDOWN',
    'Stale stream with recent restart respects retry cooldown');
checkP03($direct->values['StreamOK']===false &&
    count($direct->sent)===$before,
    'Cooldown keeps stale stream OFF and sends no extra HTTP request');

$GLOBALS['mockForeignChild']=true;
checkP03($direct->HealthTick()==='UNSAFE_PARENT',
    'Heartbeat repair refuses shared socket without side effects');
$GLOBALS['mockForeignChild']=false;

// Observer watchdog timer and main watchdog both use same verified checker.
$direct->attributes['LastCameraRx']=time()-90;
$direct->attributes['Streaming']=true;
$direct->values['StreamOK']=true;
$direct->attributes['LastSocketRestart']=time()-60;
$direct->Watchdog();
checkP03($direct->attributes['LastHealthAction']==='RECONNECT_GET_SENT',
    'Old observer Watchdog method delegates to guarded HealthTick');

// A P03 observer with missing camera configuration must never be ACTIVE.
$disabled = new JVPresenceP03AuxObserver();
$disabled->Create();
$disabled->properties['Enabled'] = false;
$disabled->ApplyChanges();
checkP03($disabled->status === 104,
    'Explicitly disabled P03 observer transitions to IS_INACTIVE');

// A shared parent can still be connected, but must not be marked ACTIVE.
$GLOBALS['mockForeignChild'] = true;
$unsafe = new JVPresenceP03AuxObserver();
$unsafe->Create();
$unsafe->properties['CameraHost'] = '192.0.2.5';
$unsafe->properties['Username'] = 'sim-user';
$unsafe->properties['Password'] = 'sim-password';
$unsafe->ApplyChanges();
checkP03($unsafe->status === 104,
    'Observer on foreign/shared socket is not marked IS_ACTIVE');
$GLOBALS['mockForeignChild'] = false;

echo "P03 OBSERVER INTEGRATION SIMULATION PASSED ({$tests} assertions)\n";
