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

$GLOBALS['hiddenP03ObjectIDs']=[];
function IPS_SetHidden(int $id,bool $hidden): void
{
    if ($id<100000 || !$hidden) {
        throw new RuntimeException('P03 may only hide existing own diagnostic variable IDs');
    }
    $GLOBALS['hiddenP03ObjectIDs'][$id]=true;
}
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
    public array $properties = [];
    public array $attributes = [];
    public array $buffers = [];
    public array $values = [];
    public array $timers = [];
    public array $sent = [];
    public array $debug = [];
    private array $variableIds = [];
    private array $registeredAttributes = [];
    private array $registeredTimers = [];

    private function assertUniqueRegistration(array &$registry, string $key, string $kind): void
    {
        if (isset($registry[$key])) {
            throw new RuntimeException('Symcon WARNING duplicate ' . $kind . ': ' . $key);
        }
        $registry[$key] = true;
    }

    public function Create(): void {}
    public function ApplyChanges(): void {}
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

    protected function RegisterAttributeBoolean(string $k, bool $v): void {
        $this->assertUniqueRegistration($this->registeredAttributes, $k, 'Attribute');
        $this->attributes[$k] ??= $v;
    }
    protected function RegisterAttributeInteger(string $k, int $v): void {
        $this->assertUniqueRegistration($this->registeredAttributes, $k, 'Attribute');
        $this->attributes[$k] ??= $v;
    }
    protected function RegisterAttributeString(string $k, string $v): void {
        $this->assertUniqueRegistration($this->registeredAttributes, $k, 'Attribute');
        $this->attributes[$k] ??= $v;
    }
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

    protected function RegisterTimer(string $k, int $interval, string $script): void {
        $this->assertUniqueRegistration($this->registeredTimers, $k, 'Timer');
        $this->timers[$k] ??= $interval;
    }
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
$x->properties['ProofMode'] = 'LEGACY_OWNED';
$x->properties['CameraHost'] = '192.0.2.5';
$x->properties['CameraPort'] = 80;
$x->properties['Username'] = 'sim-user';
$x->properties['Password'] = 'sim-password';
$x->properties['Role'] = 'JV_LEFT';
$x->properties['RuleIndex'] = 3;
$x->properties['RuleID'] = 4;
$x->ApplyChanges();
checkP03(count($x->attributes) >= 24,
    'Observer Create registers all properties once; ApplyChanges adds no duplicate attributes');
checkP03(count($GLOBALS['hiddenP03ObjectIDs'])===8,
    'only eight internal observer diagnostic IDs are hidden; public person/status/counter IDs remain visible');
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
$direct->properties['ProofMode'] = 'LEGACY_OWNED';
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

// P03 previously ignored every native SMD Human positive event, even
// though both IPC5442 cameras have SMD Human enabled. Keep only human-coded
// events as positive observation (still requires TWO different cameras).
// Generic VideoMotion and Vehicle events must never create a proof.
$wire = new JVPresenceP03AuxObserver();
$wire->Create();
$wire->properties['ProofMode'] = 'LEGACY_OWNED';
$wire->properties['Enabled'] = true;
$wire->properties['Role'] = 'JV_LEFT';
$wire->properties['CameraHost'] = '192.0.2.5';
$wire->properties['Username'] = 'sim-user';
$wire->properties['Password'] = 'sim-pass';
$wire->properties['RuleID'] = 4;
$wire->ApplyChanges();
$wire->attributes['Streaming'] = true;
$wire->values['StreamOK'] = true;
$parts = [
    "Heartbeat\r\n--boundary\r\nContent-Type: text/plain\r\n\r\nCo",
    "de=SmartMotionHuman;action=St",
    "art;index=0;data={\"RegionName\":[\"Region1\"],\"object\":[{\"HumamID\":18}]}\r\n"
];
foreach ($parts as $part) {
    rxP03($wire,$part);
}
checkP03(($wire->attributes['WireCodeCount'] ?? -1)===1,
    'Raw wire probe independently captures fragmented SmartMotionHuman event');
checkP03(str_contains((string)($wire->values['WireProbe']??''),'Code=SmartMotionHuman'),
    'Wire probe exposes exact SMD event code on 5442');
checkP03(($wire->values['HumanEventCounter']??0)===1,
    'native SMD Human START increments exactly one camera-native human observation');
checkP03(($wire->attributes['SmdHumanRawCount']??0)===1
    && ($wire->attributes['SmdHumanProofCount']??0)===1,
    'separate SMD raw/accepted counters show real positive Human source');
checkP03(($wire->values['PersonDetected']??false)===true,
    'visible PersonDetected pulse reacts immediately to real SMD Human START');
checkP03(str_contains((string)($wire->values['LastEvent']??''),'SMD_HUMAN'),
    'LastEvent reports accepted Human source without pretending to have IVS direction');
rxP03($wire, "Code=SmartMotionVehicle;action=Start;index=0;data={\"ObjectType\":\"Vehicle\"}\r\n");
rxP03($wire, "Code=VideoMotion;action=Start;index=0\r\n");
rxP03($wire, "Code=VideoMotionInfo;action=State;index=0\r\n");
checkP03(($wire->values['HumanEventCounter']??0)===1,
    'Vehicle, VideoMotion and VideoMotionInfo never increment human proof');
$wire->attributes['LastHumanProofAt']=time()-3;
checkP03(($wire->attributes['WireHeartbeatCount']??0)>=1,
    'Wire probe distinguishes repeated Dahua heartbeats from actual code events');

rxP03($wire, "Code=CrossRegionDetection;action=Start;index=0;data="
    . "{\"Name\":\"P03_JV_LEFT_HUMAN\",\"CfgRuleId\":4,\"EventID\":701,"
    . "\"Object\":{\"ObjectType\":\"Human\",\"ObjectID\":77}}\r\n");
checkP03(($wire->attributes['WireCodeCount'] ?? -1)===5,
    'Raw wire captures IVS as fifth code including rejected Vehicle/Motion events');
checkP03(($wire->values['HumanEventCounter']??-1)===2,
    'separate valid IVS Human START increments same monotonic counter');
checkP03(($wire->attributes['SmdHumanProofCount']??0)===1,
    'IVS proof does not masquerade as a second SMD Human proof');
checkP03(($wire->attributes['LastHumanProofCode']??'')==='IVS_CROSSREGION',
    'latest proof source indicates IVS only when IVS really fired');
// Identical recycled IVS delivery is de-duplicated.
rxP03($wire,"Code=CrossRegionDetection;action=Start;index=0;data="
    . "{\"Name\":\"P03_JV_LEFT_HUMAN\",\"CfgRuleId\":4,\"EventID\":701,"
    . "\"Object\":{\"ObjectType\":\"Human\",\"ObjectID\":77}}\r\n");
checkP03(($wire->values['HumanEventCounter']??-1)===2,
    'same raw IVS event ID cannot be counted twice immediately');
rxP03($wire,"Code=SmartMotionHuman;action=Stop;index=0\r\n");
checkP03(($wire->values['HumanEventCounter']??-1)===2,
    'SMD STOP does not increment counter');
$wire->attributes['HumanPulseUntil']=time()-2;
$wire->values['PersonDetected']=true;
$wire->HealthTick();
checkP03(($wire->values['PersonDetected']??true)===false,
    'health watchdog clears stale PersonDetected even when pulse timer did not execute');
// Fresh SMD+IVS can arrive within the same second; it must produce exactly
// one positive observation rather than double-count one physical person.
$wire->attributes['Streaming']=true;
$wire->attributes['LastHumanProofAt']=time()-4;
rxP03($wire,"Code=SmartMotionHuman;action=Start;index=0;data={\"EventID\":720}\r\n");
$counter=(int)$wire->values['HumanEventCounter'];
rxP03($wire,"Code=CrossRegionDetection;action=Start;index=0;data="
    . "{\"Name\":\"P03_JV_LEFT_HUMAN\",\"CfgRuleId\":4,\"EventID\":721}\r\n");
checkP03((int)$wire->values['HumanEventCounter']===$counter,
    'simultaneous SMD+IVS modes from same camera do not double-count');
// Explicitly nonhuman object classification must never create SMD proof.
rxP03($wire,"Code=SmartMotionHuman;action=Start;index=0;data="
    . "{\"EventID\":722,\"ObjectType\":\"Vehicle\"}\r\n");
checkP03((int)$wire->values['HumanEventCounter']===$counter,
    'explicit SMD Vehicle classification fails closed');

// 5442 VideoMotionInfo may carry an object-class hint that was formerly
// invisible to P03 after the next generic motion overwrote EventAudit.
// Expose it explicitly but NEVER promote generic motion to zone evidence.
$prior=(int)($wire->values['HumanEventCounter'] ?? 0);
rxP03($wire,"Code=VideoMotionInfo;action=State;index=0;data="
    . "{\"Object\":{\"ObjectType\":\"Human\"},\"EventID\":811}\r\n");
checkP03((int)($wire->values['HumanEventCounter'] ?? 0)===$prior,
    'generic VideoMotionInfo with embedded Human label remains diagnostic-only');
checkP03((int)($wire->attributes['GenericHumanHintCount'] ?? 0)===1
    && str_contains((string)($wire->values['HumanHint']??''),'"classification":"human"'),
    'explicit Human class in generic Dahua event is visible independently of proof counter');
$codes=json_decode((string)($wire->attributes['ObservedCodeCounts']??'{}'),true);
checkP03(is_array($codes) && ($codes['VideoMotionInfo']??0)>=2
    && ($codes['SmartMotionHuman']??0)>=1,
    'persistent per-code histogram distinguishes ordinary motion from native SMD Human');
rxP03($wire,"Code=VideoMotionInfo;action=State;index=0;data="
    . "{\"Object\":{\"ObjectType\":\"Vehicle\"},\"EventID\":812}\r\n");
checkP03((int)($wire->attributes['GenericHumanHintCount'] ?? 0)===1,
    'nonhuman Vehicle MotionInfo never creates a Human candidate');
$wire->HealthTick();
checkP03((int)($wire->attributes['GenericHumanHintCount'] ?? 0)===1,
    'health check does not erase persistent event-code/human-hint diagnostics');

// Production traffic from IPC5442 includes thousands of VideoMotionInfo
// messages without any person. High-frequency diagnostics must not flood
// SymBox UI variables, yet raw counts and real AI positive events must remain.
$beforeGenericCount=(int)($wire->attributes['WireCodeCount']??0);
$beforeHumanProof=(int)($wire->values['HumanEventCounter']??0);
$wire->attributes['LastGenericWireProbeAt']=time();
$wire->attributes['LastGenericEventAuditAt']=time();
$wire->values['WireProbe']='NO REWRITE DURING GENERIC SPAM';
$wire->values['EventAudit']='NO EVENTAUDIT REWRITE DURING GENERIC SPAM';
for($i=0;$i<40;$i++){
    rxP03($wire,"Code=VideoMotionInfo;action=State;index=0\r\n");
}
checkP03(($wire->values['WireProbe']??'')==='NO REWRITE DURING GENERIC SPAM'
    && ($wire->values['EventAudit']??'')==='NO EVENTAUDIT REWRITE DURING GENERIC SPAM',
    '40 rapid generic VideoMotionInfo events do not rewrite P03 UI diagnosis variables');
checkP03((int)($wire->attributes['WireCodeCount']??0)===$beforeGenericCount+40,
    'throttling preserves every independently observed wire code counter');
checkP03((int)($wire->values['HumanEventCounter']??0)===$beforeHumanProof,
    'even a large generic motion burst NEVER becomes human evidence');
$wire->attributes['LastHumanProofAt']=time()-6;
rxP03($wire,"Code=SmartMotionHuman;action=Start;index=0;data="
    ."{\"EventID\":999,\"ObjectType\":\"Human\"}\r\n");
checkP03((int)($wire->values['HumanEventCounter']??0)===$beforeHumanProof+1
    && str_contains((string)($wire->values['WireProbe']??''),'SmartMotionHuman')
    && str_contains((string)($wire->values['EventAudit']??''),'SMD_HUMAN_START_COUNTED'),
    'real Human START bypasses generic diagnostics throttle and is counted immediately');


echo "P03 OBSERVER INTEGRATION SIMULATION PASSED ({$tests} assertions)\n";


// v0.6.37: camera UI can show both Treppe and Straße line crossing,
// but the API must corroborate their identity before ANY gate proof.
// One observer must never treat a vehicle, street event, P03 rectangle,
// anonymous ambiguous crossing or SMD anywhere in the frame as a transfer.
$sel=P03ExistingIvsLines::select([
    ['Enable'=>true,'Name'=>'Überquerung zur Treppe','Type'=>'CrossLineDetection',
        'Id'=>12,'ObjectTypes'=>['Human','MotorVehicle']],
    ['Enable'=>true,'Name'=>'Überquerung zur Straße','Type'=>'CrossLineDetection',
        'Id'=>13,'ObjectTypes'=>['Human','MotorVehicle']],
    ['Enable'=>true,'Name'=>'P03_WORK_LEFT_HUMAN','Type'=>'CrossRegionDetection',
        'Id'=>4,'ObjectTypes'=>['Human']]
]);
checkP03($sel['mapped']===true && count($sel['allowed'])===1
    && $sel['allowed'][0]['id']===12 && !$sel['allowed'][0]['humanOnly'],
    'only the existing staircase line is mapped, street line and old P03 rectangle excluded');
checkP03(!P03ExistingIvsLines::select([
    ['Enable'=>true,'Name'=>'P03_JV_LEFT_HUMAN','Type'=>'CrossRegionDetection',
        'Id'=>4,'ObjectTypes'=>['Human']]
])['mapped'],'old P03 rectangle is never silently substituted for missing manual stair line');
checkP03(!P03ExistingIvsLines::select([
    ['Enable'=>true,'Name'=>'Überquerung zur Treppe','Type'=>'CrossLineDetection',
        'Id'=>12,'ObjectTypes'=>['Human']],
    ['Enable'=>true,'Name'=>'Überquerung zur Treppe 2','Type'=>'CrossLineDetection',
        'Id'=>14,'ObjectTypes'=>['Human']]
])['mapped'],'two possible stair lines without unique identity fail closed');
$manual=new JVPresenceP03AuxObserver();
$manual->Create();
$manual->properties['Enabled']=true;
$manual->properties['Role']='WORK_LEFT';
$manual->properties['CameraHost']='192.0.2.6';
$manual->properties['Username']='mock';
$manual->properties['Password']='mock';
$manual->properties['ProofMode']='EXISTING_STAIR_IVS';
$manual->properties['AllowedIVSRules']=json_encode($sel['allowed']);
$manual->ApplyChanges();
$manual->attributes['Streaming']=true;
$manual->values['StreamOK']=true;
rxP03($manual,"HTTP/1.1 200 OK\r\nContent-Type: multipart/x-mixed-replace\r\n\r\n");
rxP03($manual,"Code=SmartMotionHuman;action=Start;index=0;data={\"EventID\":500}\r\n");
checkP03(($manual->values['PersonDetected']??false)===true
    && ($manual->attributes['LastSmdHumanStartAt']??0)>0
    && (int)($manual->values['HumanEventCounter']??0)===0,
    'P03 strict staircase mode: camera SMD Human immediately drives visible PersonDetected WITHOUT incrementing proof');
checkP03(str_contains((string)($manual->values['ObserverStatus']??''),'lastSmdHumanStartAt')
    && str_contains((string)($manual->values['EventAudit']??''),'SMD_PERSON_STATUS_ONLY_NO_STAIR_PROOF'),
    'SMD status trace explicitly differentiates Boolean person presence from proof counter');
rxP03($manual,"Code=SmartMotionHuman;action=Stop;index=0\r\n");
checkP03((int)($manual->values['HumanEventCounter']??0)===0,
    'SMD Stop also never creates a directional transfer');
$manual->attributes['HumanPulseUntil']=time()-1;
$manual->PersonPulseTimer();
checkP03(($manual->values['PersonDetected']??true)===false,
    '5-second status expires automatically; no false permanent person detection');
rxP03($manual,"Code=SmartMotionHuman;action=Start;index=0;data={\"EventID\":501,\"ObjectType\":\"Vehicle\"}\r\n");
checkP03(($manual->values['PersonDetected']??false)===false
    && (int)($manual->values['HumanEventCounter']??0)===0,
    'explicit vehicle classification in SmartMotionHuman cannot activate person presence or proof');
rxP03($manual,"Code=SmartMotionHuman;action=Start;index=0;data={\"EventID\":502}\r\n");
checkP03(($manual->values['PersonDetected']??false)===true
    && (int)($manual->values['HumanEventCounter']??0)===0,
    'subsequent SMD Human Start reactivates Boolean independently from forbidden SMD proof');
rxP03($manual,"Code=CrossRegionDetection;action=Start;index=0;data={\"Name\":\"P03_WORK_LEFT_HUMAN\",\"CfgRuleId\":4,\"ObjectType\":\"Human\"}\r\n");
rxP03($manual,"Code=CrossLineDetection;action=Start;index=0;data={\"Name\":\"Überquerung zur Straße\",\"CfgRuleId\":13,\"ObjectType\":\"Human\"}\r\n");
rxP03($manual,"Code=CrossLineDetection;action=Start;index=0;data={\"Name\":\"Überquerung zur Treppe\",\"CfgRuleId\":12,\"ObjectType\":\"MotorVehicle\"}\r\n");
rxP03($manual,"Code=CrossLineDetection;action=Start;index=0;data={\"Name\":\"Überquerung zur Treppe\",\"CfgRuleId\":12}\r\n");
checkP03((int)($manual->values['HumanEventCounter']??0)===0,
    'stair-ONLY mode refuses vehicle, street, SMD-anywhere, rectangle and mixed unclassified traffic');
rxP03($manual,"Code=CrossLineDetection;action=Start;index=0;data={\"Name\":\"Überquerung zur Treppe\",\"CfgRuleId\":12,\"ObjectType\":\"Human\",\"EventID\":501}\r\n");
checkP03((int)($manual->values['HumanEventCounter']??0)===1
    && str_contains((string)($manual->values['LastEvent']??''),'IVS_EXISTING_STAIR_LINE'),
    'positive human on selected existing stair line increments exactly one counter');
rxP03($manual,"Code=CrossLineDetection;action=Start;index=0;data={\"Name\":\"Überquerung zur Treppe\",\"CfgRuleId\":999,\"ObjectType\":\"Human\",\"EventID\":502}\r\n");
checkP03((int)($manual->values['HumanEventCounter']??0)===1,
    'conflicting RuleName/CfgRuleId identity does NOT create a proof');
$manual->properties['AllowedIVSRules']='[]';
rxP03($manual,"Code=CrossLineDetection;action=Start;index=0;data={\"Name\":\"Überquerung zur Treppe\",\"CfgRuleId\":12,\"ObjectType\":\"Human\",\"EventID\":503}\r\n");
checkP03((int)($manual->values['HumanEventCounter']??0)===1,
    'missing camera API mapping keeps existing-line proof strictly disabled');



/**
 * Camera firmware v2.840 may serialize VideoAnalyseRule differently over
 * JSON-RPC and CGI. Never count a staircase crossing merely because one
 * transport contains an unrelated ID or stale NVR-like rule.
 */
$cameraCgi = implode("\r\n", [
    'table.VideoAnalyseRule[0][0].Name=Überquerung zur Treppe',
    'table.VideoAnalyseRule[0][0].Type=CrossLineDetection',
    'table.VideoAnalyseRule[0][0].Enable=true',
    'table.VideoAnalyseRule[0][0].Id=17',
    'table.VideoAnalyseRule[0][0].ObjectTypes[0]=Human',
    'table.VideoAnalyseRule[0][0].ObjectTypes[1]=MotorVehicle',
    'table.VideoAnalyseRule[0][1].Name=Überquerung zur Straße',
    'table.VideoAnalyseRule[0][1].Type=CrossLineDetection',
    'table.VideoAnalyseRule[0][1].Enable=true',
    'table.VideoAnalyseRule[0][1].Id=18',
    'table.VideoAnalyseRule[0][1].ObjectTypes[0]=Human',
    'table.VideoAnalyseRule[0][1].ObjectTypes[1]=MotorVehicle',
    'table.VideoAnalyseRule[1][0].Name=Überquerung zur Treppe zweiter Kanal',
    'table.VideoAnalyseRule[1][0].Type=CrossLineDetection',
    'table.VideoAnalyseRule[1][0].Enable=true',
    'table.VideoAnalyseRule[1][0].Id=99'
]);
$cgiList=P03ExistingIvsLines::fromCgi($cameraCgi);
$rpcList=[
    ['Name'=>'Überquerung zur Treppe','Type'=>'CrossLineDetection',
        'Enable'=>true,'Id'=>17,'ObjectTypes'=>['Human','MotorVehicle']],
    ['Name'=>'Überquerung zur Straße','Type'=>'CrossLineDetection',
        'Enable'=>true,'Id'=>18,'ObjectTypes'=>['Human','MotorVehicle']]
];
$cg=P03ExistingIvsLines::select($cgiList);
$rp=P03ExistingIvsLines::select($rpcList);
checkP03(count($cgiList)===2
    && P03ExistingIvsLines::sameSelectedStair($rp,$cg),
    'Direct Dahua CGI agrees with RPC2 on exactly one existing staircase rule, ignoring channel 1');
$rpcList[0]['Id']=19;
checkP03(!P03ExistingIvsLines::sameSelectedStair(P03ExistingIvsLines::select($rpcList),$cg),
    'Different RPC2 vs CGI rule IDs must not be accepted as existing staircase evidence');
$rpcList[0]['Id']=17;
$rpcList[0]['ObjectTypes']=['Human'];
checkP03(!P03ExistingIvsLines::sameSelectedStair(P03ExistingIvsLines::select($rpcList),$cg),
    'Different object class filters between RPC2 and CGI fail closed');
checkP03(!P03ExistingIvsLines::sameSelectedStair(
    P03ExistingIvsLines::select([]),$cg),
    'Old v0.6.37 camera with zero RPC2 CrossLine cannot become verified from UI/CGI alone');
checkP03(P03ExistingIvsLines::fromCgi('table.VideoAnalyseRule[0][0].Name=Unknown')!==[],
    'Dahua CGI one-line rules read without requiring hardcoded field order');


// Regression v0.6.39: unfiltered camera inventory must reveal P03 and
// NumberStat rows even if CrossLine selection is empty.
$actualRpcShape = [
    ['Name'=>'HeatMap1','Type'=>'HeatMap','Class'=>'HeatMap','Enable'=>false,'Id'=>1],
    ['Name'=>'FaceDetection','Type'=>'FaceDetection','Class'=>'FaceDetection','Enable'=>false,'Id'=>2],
    ['Name'=>'NumberStat1','Type'=>'NumberStat','Class'=>'NumberStat','Enable'=>true,'Id'=>3,
        'ObjectTypes'=>['Human']],
    ['Name'=>'P03_WORK_LEFT_HUMAN','Type'=>'CrossRegionDetection','Class'=>'Normal',
        'Enable'=>true,'Id'=>4,'ObjectTypes'=>['Human'],
        'Config'=>['DetectRegion'=>[[256,256],[7935,256],[7935,7935],[256,7935]]]]
];
$unfiltered=P03ExistingIvsLines::inventory($actualRpcShape);
checkP03(count($unfiltered)===4
    && $unfiltered[2]['name']==='NumberStat1'
    && $unfiltered[3]['name']==='P03_WORK_LEFT_HUMAN'
    && $unfiltered[3]['hasRegion']===true,
    'unfiltered camera inventory shows all four real API rules even when no staircase line exists');
checkP03(P03ExistingIvsLines::select($actualRpcShape)['all']===[],
    'no CrossLine candidates is NOT the same as an empty RPC2 response');
$actualCgiShape=implode("\n", [
    'table.All.VideoAnalyseRule[0][0].Name=HeatMap1',
    'table.All.VideoAnalyseRule[0][0].Enable=false',
    'table.All.VideoAnalyseRule[0][0].Type=HeatMap',
    'table.All.VideoAnalyseRule[0][2].Name=NumberStat1',
    'table.All.VideoAnalyseRule[0][2].Enable=true',
    'table.All.VideoAnalyseRule[0][2].Type=NumberStat',
    'table.All.VideoAnalyseRule[0][3].Name=P03_WORK_LEFT_HUMAN',
    'table.All.VideoAnalyseRule[0][3].Enable=true',
    'table.All.VideoAnalyseRule[0][3].Type=CrossRegionDetection'
]);
$fromAll=P03ExistingIvsLines::fromCgi($actualCgiShape);
checkP03(count($fromAll)===3
    && count(P03ExistingIvsLines::inventory($fromAll))===3,
    'CGI inventory accepts the full getConfig&name=All prefix without discarding unrelated rules');
checkP03(P03ExistingIvsLines::wireReadSummary([
    'ok'=>true,'http'=>200,'json'=>['result'=>true,
        'params'=>['table'=>[$actualRpcShape]]]])['totalRules']===4,
    'transport summary distinguishes a populated RPC2 rule table from empty filtered results');

// v0.7.7: a camera/observer reconfiguration MUST NOT reuse the old camera's
// apparent SMD/IVS history. The native counter stays monotonic and is
// explicitly reset only by the guarded host migration method.
$wire->attributes['WireCodeCount']=900;
$wire->attributes['WireHeartbeatCount']=500;
$wire->attributes['ParsedEventCount']=300;
$wire->attributes['CrossRegionEventCount']=17;
$wire->attributes['RuleMatchEventCount']=6;
$wire->attributes['RuleRejectCount']=55;
$wire->attributes['SmdHumanRawCount']=28;
$wire->attributes['SmdHumanProofCount']=3;
$wire->attributes['ObservedCodeCounts']='{"SmartMotionHuman":28,"CrossRegionDetection":17}';
$wire->values['WireProbe']='16:22:10 Code=SmartMotionHuman;action=Start;index=0';
$wire->values['EventAudit']='{"time":"16:22:10","humanCount":6}';
$wire->values['LastIVSEvent']='{"oldCamera":true}';
$wire->values['LastEvent']='OLD CAMERA';
$wire->values['HumanHint']='OLD CAMERA HINT';
$humanBeforeApply=(int)($wire->values['HumanEventCounter']??0);
$wire->ApplyChanges();
checkP03(($wire->attributes['WireCodeCount']??-1)===0
    && ($wire->attributes['WireHeartbeatCount']??-1)===0
    && ($wire->attributes['ParsedEventCount']??-1)===0
    && ($wire->attributes['CrossRegionEventCount']??-1)===0
    && ($wire->attributes['RuleMatchEventCount']??-1)===0
    && ($wire->attributes['RuleRejectCount']??-1)===0
    && ($wire->attributes['SmdHumanRawCount']??-1)===0
    && ($wire->attributes['SmdHumanProofCount']??-1)===0,
    'camera reconfiguration resets old per-observer wire/AI/IVS diagnostic counters');
checkP03(($wire->attributes['ObservedCodeCounts']??'')==='{}'
    && ($wire->values['WireProbe']??'NOT EMPTY')===''
    && ($wire->values['EventAudit']??'NOT EMPTY')===''
    && ($wire->values['LastIVSEvent']??'NOT EMPTY')===''
    && ($wire->values['LastEvent']??'NOT EMPTY')===''
    && ($wire->values['HumanHint']??'NOT EMPTY')==='',
    'camera reconfiguration clears old camera code histogram and timestamped audit');
checkP03((int)($wire->values['HumanEventCounter']??-1)===$humanBeforeApply,
    'regular ApplyChanges never forges or silently resets the monotonic human counter');



// v0.7.8: reproduce the actual 16:50 left-camera RPC2 rules.
// Logging ALL enabled CrossLines must NEVER be confused with the proof
// allowlist. Both roles permit only ID 4 / index 3, NEVER street ID 5.
$liveJvRules=[
    3=>['Enable'=>true,'Name'=>'Überquerung zur Treppe','Type'=>'CrossLineDetection',
        'Id'=>4,'ObjectTypes'=>['Human']]
];
$liveWorkRules=[
    3=>['Enable'=>true,'Name'=>'Überquerung zur Treppe','Type'=>'CrossLineDetection',
        'Id'=>4,'ObjectTypes'=>['Human','Vehicle']],
    4=>['Enable'=>true,'Name'=>'Überquerung zur Straße','Type'=>'CrossLineDetection',
        'Id'=>5,'ObjectTypes'=>['Human','Vehicle']]
];
$jvSelected=P03ExistingIvsLines::select($liveJvRules);
$workSelected=P03ExistingIvsLines::select($liveWorkRules);
checkP03($jvSelected['mapped'] && count($jvSelected['allowed'])===1
    && $jvSelected['allowed'][0]['id']===4
    && $jvSelected['allowed'][0]['tableIndex']===3
    && $jvSelected['allowed'][0]['humanOnly'],
    'actual JV_LEFT 16:50 camera: one allowed human-only stair rule ID 4');
checkP03($workSelected['mapped'] && count($workSelected['all'])===2
    && count($workSelected['allowed'])===1
    && $workSelected['allowed'][0]['id']===4
    && $workSelected['allowed'][0]['tableIndex']===3
    && !$workSelected['allowed'][0]['humanOnly'],
    'actual WORK_LEFT 16:50 camera: street rule ID 5 is inventory only, never allowed');
checkP03(!P03ExistingIvsLines::matchingHumanStart([
    'code'=>'CrossLineDetection','action'=>'Start','ruleName'=>'Überquerung zur Straße',
    'cfgRuleId'=>5,'classification'=>'Human'
],$workSelected['allowed']),
    'explicit Human crossing on street line ID 5 is rejected');
checkP03(!P03ExistingIvsLines::matchingHumanStart([
    'code'=>'CrossLineDetection','action'=>'Start','ruleName'=>'Überquerung zur Treppe',
    'cfgRuleId'=>4
],$workSelected['allowed']),
    'mixed Human/Vehicle stair rule requires per-event Human classification');
checkP03(P03ExistingIvsLines::matchingHumanStart([
    'code'=>'CrossLineDetection','action'=>'Start','ruleName'=>'Überquerung zur Treppe',
    'cfgRuleId'=>4,'classification'=>'Human'
],$workSelected['allowed']),
    'mixed class stair rule accepts explicitly classified Human event only');
$mainText=file_get_contents(dirname(__DIR__).'/JVPresenceP03TerraceTest/module.php');
checkP03(is_string($mainText)
    && str_contains($mainText,'CROSSLINE-INVENTAR (KEINE FREIGABE)')
    && str_contains($mainText,'FREIGEGEBENE TREPPEN-CROSSLINE')
    && str_contains($mainText,'$mapped ? $rpcSelection[\'allowed\'] : []'),
    'read-only audit reports actual allowlist separately from all CrossLine inventory');


// v0.8.0 real field: JV_LEFT 17:20 had four CrossLineDetection
// but 0 authorized Human. Keep rejected per-line evidence even after
// ordinary motion messages; never relax fail-closed rule matching.
$forensic=new JVPresenceP03AuxObserver();
$forensic->Create();
$forensic->properties['Enabled']=true;
$forensic->properties['CameraHost']='192.0.2.11';
$forensic->properties['Username']='fake-user';
$forensic->properties['Password']='fake-password';
$forensic->properties['Role']='JV_LEFT';
$forensic->properties['ProofMode']='EXISTING_STAIR_IVS';
$forensic->properties['AllowedIVSRules']=json_encode([
    ['name'=>'Überquerung zur Treppe','id'=>4,'tableIndex'=>3,
        'humanOnly'=>true,'objects'=>['human']]
],JSON_UNESCAPED_UNICODE);
$forensic->ApplyChanges();
$forensic->attributes['Streaming']=true;
$forensic->values['StreamOK']=true;
rxP03($forensic,"HTTP/1.1 200 OK\r\nContent-Type: multipart/x-mixed-replace\r\n\r\n");
rxP03($forensic,"Code=CrossLineDetection;action=Start;index=0;data="
    ."{\"Name\":\"Überquerung zur Treppe\",\"CfgRuleId\":4,\"RuleID\":3,\"EventID\":2001,\"ObjectType\":\"Human\"}\r\n");
rxP03($forensic,"Code=CrossLineDetection;action=Stop;index=0;data="
    ."{\"Name\":\"Überquerung zur Treppe\",\"CfgRuleId\":4,\"EventID\":2001}\r\n");
rxP03($forensic,"Code=CrossLineDetection;action=Start;index=0;data="
    ."{\"Name\":\"Überquerung zur Straße\",\"CfgRuleId\":5,\"EventID\":2002,\"ObjectType\":\"Human\"}\r\n");
rxP03($forensic,"Code=VideoMotion;action=Stop;index=0\r\n");
$recent=json_decode((string)($forensic->values['LastIVSEvent']??''),true);
checkP03(is_array($recent) && count($recent)===3
    && $recent[0]['rejectionReason']==='CAMERA_EVENT_RULE_ID_CONFLICT'
    && $recent[1]['rejectionReason']==='NOT_START_OR_PULSE'
    && $recent[2]['rejectionReason']==='EVENT_RULE_NAME_NOT_STAIR',
    'real CrossLine conflict, Stop and excluded street each retain their reason beyond VideoMotion');
checkP03((int)($forensic->values['HumanEventCounter']??0)===0
    && ($recent[0]['cfgRuleId']??null)===4
    && ($recent[0]['ruleIdUpper']??null)===3,
    'conflicting CfgRuleId vs RuleID is exposed but never counted as Human');
checkP03(P03ExistingIvsLines::explainRejection([
    'code'=>'CrossLineDetection','action'=>'Start',
    'ruleName'=>'Überquerung zur Treppe','cfgRuleId'=>4
], [['name'=>'Überquerung zur Treppe','id'=>4,'humanOnly'=>false]])
    ==='MIXED_HUMAN_VEHICLE_REQUIRES_CLASSIFICATION',
    'mixed Person/Vehicle CrossLine event without class remains rejected');
rxP03($forensic,"Code=CrossLineDetection;action=Start;index=0;data="
    ."{\"Name\":\"Überquerung zur Treppe\",\"CfgRuleId\":4,\"EventID\":2003,\"ObjectType\":\"Human\"}\r\n");
$recent=json_decode((string)($forensic->values['LastIVSEvent']??''),true);
checkP03(count($recent)===4
    && $recent[3]['decision']==='STAIR_HUMAN_START_COUNTED'
    && $recent[3]['rejectionReason']==='MATCHED_FILTER'
    && (int)($forensic->values['HumanEventCounter']??0)===1,
    'valid explicit Human START still counts, recent IVS history includes acceptance');
for($i=0;$i<12;$i++) {
    rxP03($forensic,"Code=CrossLineDetection;action=Stop;index=0;data="
        ."{\"Name\":\"Überquerung zur Treppe\",\"CfgRuleId\":4,\"EventID\":".(3000+$i)."}\r\n");
}
$recent=json_decode((string)($forensic->values['LastIVSEvent']??''),true);
checkP03(count($recent)===8 && ($recent[7]['eventId']??null)===3011
    && (int)($forensic->values['HumanEventCounter']??0)===1,
    'CrossLine evidence trail bounded to last eight; Stop never increments counter');
$mainModuleSource=file_get_contents(dirname(__DIR__).'/JVPresenceP03TerraceTest/module.php');
checkP03(str_contains((string)$mainModuleSource, 'LETZTE CROSSLINE-EREIGNISSE:')
    && str_contains((string)$mainModuleSource, 'Ablehnungsgrund')===false
    && str_contains((string)$mainModuleSource, "['rejectionReason']"),
    'known P03 diagnostic includes parser-independent last-eight IVS reasons');

 
// Field reproduction: v0.8.1 observed actual camera RuleID=4 and Human,
// but the rule name began with UTF-8 mojibake instead of a German U-umlaut.
$actualRule=[['name'=>'Überquerung zur Treppe','id'=>4,'tableIndex'=>3,
    'humanOnly'=>true,'objects'=>['human']]];
$brokenLatin1='Ã'.chr(0xC2).chr(0x9C).'berquerung zur Treppe';
$brokenCp1252='Ã'.chr(0xC5).chr(0x93).'berquerung zur Treppe';
foreach([$brokenLatin1,$brokenCp1252] as $brokenName) {
    $event=['code'=>'CrossLineDetection','action'=>'Start',
        'ruleName'=>$brokenName,'ruleIdUpper'=>4,'classification'=>'Human'];
    checkP03(P03ExistingIvsLines::matchingHumanStart($event,$actualRule),
        'verified RuleID4 repairs the observed encoded Treppe name');
    checkP03(!P03ExistingIvsLines::matchingHumanStart(
        array_replace($event,['ruleIdUpper'=>5]),$actualRule),
        'street RuleID5 is never trusted');
    checkP03(!P03ExistingIvsLines::matchingHumanStart(
        array_replace($event,['cfgRuleId'=>5]),$actualRule),
        'conflicting camera RuleIDs remain blocked');
    checkP03(!P03ExistingIvsLines::matchingHumanStart(
        array_replace($event,['ruleIdUpper'=>null]),$actualRule),
        'misencoded name without a camera ID cannot be accepted');
    checkP03(!P03ExistingIvsLines::matchingHumanStart(
        array_replace($event,['classification'=>'Vehicle']),$actualRule),
        'explicit Vehicle class is not a Human');
    checkP03(!P03ExistingIvsLines::matchingHumanStart(
        array_replace($event,['action'=>'Stop']),$actualRule),
        'Stop does not qualify as a Human Start');
}

$evidence=new JVPresenceP03AuxObserver();
$evidence->Create();
$evidence->properties['Enabled']=true;
$evidence->properties['CameraHost']='192.0.2.20';
$evidence->properties['Username']='mock';
$evidence->properties['Password']='mock';
$evidence->properties['Role']='JV_LEFT';
$evidence->properties['ProofMode']='EXISTING_STAIR_IVS';
$evidence->properties['AllowedIVSRules']=json_encode($actualRule,JSON_UNESCAPED_UNICODE);
$evidence->ApplyChanges();
$evidence->attributes['Streaming']=true;
$evidence->values['StreamOK']=true;
$httpHeader="HTTP/1.1 200 OK".chr(13).chr(10).chr(13).chr(10);
rxP03($evidence,$httpHeader);
$eventJson=json_encode(['Name'=>$brokenLatin1,'RuleID'=>4,'EventID'=>924001,
    'Object'=>['ObjectType'=>'Human','ObjectID'=>2]],JSON_UNESCAPED_UNICODE);
$wireHeader="Code=CrossLineDetection;action=Start;index=0;data=";
rxP03($evidence,$wireHeader.$eventJson.chr(13).chr(10));
checkP03((int)($evidence->values['HumanEventCounter']??0)===1
    && ($evidence->values['PersonDetected']??false)===true,
    'full Dahua parser and P03 observer count encoded Treppe RuleID4 Human exactly once');
$evidenceHistory=json_decode((string)($evidence->values['LastIVSEvent']??''),true);
checkP03(count($evidenceHistory)===1
    && ($evidenceHistory[0]['decision']??'')==='STAIR_HUMAN_START_COUNTED',
    'accepted encoded CrossLine Human has correct audit decision');
rxP03($evidence,"Code=CrossLineDetection;action=Stop;index=0;data=".$eventJson.chr(13).chr(10));
checkP03((int)($evidence->values['HumanEventCounter']??0)===1,
    'encoded STOP never creates a second Human proof');


// Optional terrace read-only selection: no automatic right/mast or street
// inference, and never a 2-camera transfer on a terrace-only event.
$terraceTable=[
  0=>['Enable'=>false,'Type'=>'CrossLineDetection','Name'=>'Old disabled','Id'=>1,'ObjectTypes'=>['Human']],
  1=>['Enable'=>true,'Type'=>'CrossLineDetection','Name'=>'Terrasse Tor','Id'=>8,'ObjectTypes'=>['Human']]
];
$terrace=P03ExistingIvsLines::selectTerrace($terraceTable);
checkP03($terrace['mapped'] && count($terrace['allowed'])===1
    && $terrace['allowed'][0]['id']===8
    && $terrace['allowed'][0]['humanOnly'],
    'optional TERRACE accepts one active configured Human IVS line');
$terraceTable[2]=['Enable'=>true,'Type'=>'CrossLineDetection',
  'Name'=>'Terrasse Parkplatz','Id'=>9,'ObjectTypes'=>['Human','Vehicle']];
checkP03(!P03ExistingIvsLines::selectTerrace($terraceTable)['mapped'],
    'two terrace IVS lines without a chosen name fail closed');
$namedTerrace=P03ExistingIvsLines::selectTerrace($terraceTable,'Terrasse Tor');
checkP03($namedTerrace['mapped']
    && $namedTerrace['allowed'][0]['id']===8,
    'explicit exact terrace rule name safely resolves multiple lines');
checkP03(!P03ExistingIvsLines::selectTerrace($terraceTable,'Terrasse Falsch')['mapped'],
    'missing requested terrace line cannot fall back to another IVS');
$terraceTable[1]['ObjectTypes']=['Vehicle'];
checkP03(!P03ExistingIvsLines::selectTerrace($terraceTable,'Terrasse Tor')['mapped'],
    'vehicle-only terrace IVS must never be a Human trigger');
$allowedTerrace=$namedTerrace['allowed'];
checkP03(P03ExistingIvsLines::matchingHumanStart([
    'code'=>'CrossLineDetection','action'=>'Start','ruleName'=>'Terrasse Tor',
    'ruleIdUpper'=>8,'classification'=>'Human'
],$allowedTerrace),'correctly mapped optional terrace may count its own Human line');
checkP03(!P03ExistingIvsLines::matchingHumanStart([
    'code'=>'CrossLineDetection','action'=>'Start','ruleName'=>'Terrasse Parkplatz',
    'ruleIdUpper'=>9,'classification'=>'Human'
],$allowedTerrace),'other terrace IVS rule is rejected');
$terraceSensor=new JVPresenceP03AuxObserver();
$terraceSensor->Create();
$terraceSensor->properties['Enabled']=true;
$terraceSensor->properties['CameraHost']='192.0.2.30';
$terraceSensor->properties['Username']='test';
$terraceSensor->properties['Password']='test';
$terraceSensor->properties['Role']='TERRACE';
$terraceSensor->properties['ProofMode']='EXISTING_STAIR_IVS';
$terraceSensor->properties['AllowedIVSRules']=json_encode($allowedTerrace,JSON_UNESCAPED_UNICODE);
$terraceSensor->ApplyChanges();
$terraceSensor->attributes['Streaming']=true;
$terraceSensor->values['StreamOK']=true;
$eol=chr(13).chr(10);
rxP03($terraceSensor,"HTTP/1.1 200 OK".$eol.$eol);
$body=json_encode(['Name'=>'Terrasse Tor','RuleID'=>8,'EventID'=>8001,
    'Direction'=>'LeftToRight','ObjectType'=>'Human'],JSON_UNESCAPED_UNICODE);
rxP03($terraceSensor,"Code=CrossLineDetection;action=Start;index=0;data=".$body.$eol);
$events=json_decode((string)($terraceSensor->values['LastIVSEvent']??''),true);
checkP03((int)$terraceSensor->values['HumanEventCounter']===1
    && count($events)===1 && $events[0]['direction']==='LeftToRight',
    'optional terrace observer records its own Human crossing with native direction');
