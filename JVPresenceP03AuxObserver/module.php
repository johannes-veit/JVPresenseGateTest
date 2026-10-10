<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/DahuaDigest.php';
require_once dirname(__DIR__) . '/libs/DahuaEventParser.php';
require_once dirname(__DIR__) . '/libs/P03AuxObserverLogic.php';
require_once dirname(__DIR__) . '/libs/P03IPC5442Audit.php';

class JVPresenceP03AuxObserver extends IPSModule
{
    private const CLIENT_SOCKET_GUID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const SOCKET_TX_GUID = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const IM_CHANGESTATUS_ID = 10505;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Enabled', true);
        $this->RegisterPropertyString('CameraHost', '');
        $this->RegisterPropertyInteger('CameraPort', 80);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('Role', '');
        $this->RegisterPropertyInteger('RuleIndex', -1); // Diagnose/Fallback
        $this->RegisterPropertyInteger('RuleID', -1);    // Diagnose/Fallback

        $this->RegisterAttributeBoolean('Streaming', false);
        $this->RegisterAttributeInteger('LastCameraRx', 0);
        $this->RegisterAttributeInteger('LastHttpRequest', 0);
        $this->RegisterAttributeBoolean('AuthPending', false);
        $this->RegisterAttributeBoolean('LastRequestAuthenticated', false);
        $this->RegisterAttributeBoolean('AuthBlocked', false);
        $this->RegisterAttributeInteger('AuthFailureCount', 0);
        $this->RegisterAttributeInteger('DigestNC', 0);
        $this->RegisterAttributeString('DigestChallenge', '{}');
        $this->RegisterAttributeInteger('SocketRestartStage', 0);
        $this->RegisterAttributeInteger('LastSocketRestart', 0);
        $this->RegisterAttributeInteger('RegisteredParentID', 0);
        $this->RegisterAttributeInteger('HumanPulseUntil', 0);
        $this->RegisterAttributeString('SeenEventKeys', '{}');
        $this->RegisterAttributeInteger('ParsedEventCount', 0);
        $this->RegisterAttributeInteger('CrossRegionEventCount', 0);
        $this->RegisterAttributeInteger('RuleMatchEventCount', 0);
        $this->RegisterAttributeInteger('DedupeRejectCount', 0);
        $this->RegisterAttributeInteger('RuleRejectCount', 0);
        $this->RegisterAttributeInteger('WireCodeCount', 0);
        $this->RegisterAttributeInteger('WireHeartbeatCount', 0);
        $this->RegisterAttributeInteger('LastHealthTick', 0);
        $this->RegisterAttributeString('LastHealthAction', 'UNTESTED');
        $this->RegisterAttributeInteger('LastStaleRx', 0);

        $this->RegisterVariableBoolean('PersonDetected', 'Person erkannt', '~Switch', 10);
        $this->RegisterVariableBoolean('StreamOK', 'Dahua Eventstream OK', '~Switch', 20);
        $this->RegisterVariableInteger('HumanEventCounter', 'Human-Ereignisse', '', 25);
        $this->RegisterVariableString('LastEvent', 'Letztes Human-IVS-Ereignis', '', 30);
        $this->RegisterVariableString('LastIVSEvent', 'Letztes CrossRegion-Ereignis roh', '', 40);
        $this->RegisterVariableString('ObserverStatus', 'Observer Diagnose', '', 50);
        $this->RegisterVariableString('EventAudit', 'P03 IVS-Ereignisdiagnose', '', 60);
        $this->RegisterVariableString('WireProbe', 'IPC5442 Wire-Rohereignisse vor Parser', '', 65);

        $this->RegisterTimer('HandshakeTimer', 0, 'JVP03AUX_HandshakeTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('SocketRestartTimer', 0, 'JVP03AUX_SocketRestartTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('Watchdog', 15000, 'JVP03AUX_Watchdog($_IPS["TARGET"]);');
        $this->RegisterTimer('PersonPulseTimer', 0, 'JVP03AUX_PersonPulseTimer($_IPS["TARGET"]);');

        $this->RequireParent(self::CLIENT_SOCKET_GUID);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // A migrated or manually disconnected observer may have no Parent.
        // Symcon documents RequireParent as creating a NEW dedicated socket,
        // rather than reusing an existing ALA2 or other compatible socket.
        if ($this->getParentID() <= 0 || !IPS_InstanceExists($this->getParentID())) {
            $this->RequireParent(self::CLIENT_SOCKET_GUID);
        }

        // RegisterAttribute* is permitted ONLY in Create() on IP-Symcon.
        // All observer attributes are declared there and remain persistent
        // across ApplyChanges. Re-registration here caused live warnings and
        // prevented the existing P03 observer from initializing cleanly.

        // Existing observer instances from v0.6.5-v0.6.8 must receive variables
        // added by later versions as well; Create() is not relied upon for migration.
        $this->RegisterVariableBoolean('PersonDetected', 'Person erkannt', '~Switch', 10);
        $this->RegisterVariableBoolean('StreamOK', 'Dahua Eventstream OK', '~Switch', 20);
        $this->RegisterVariableInteger('HumanEventCounter', 'Human-Ereignisse', '', 25);
        $this->RegisterVariableString('LastEvent', 'Letztes Human-IVS-Ereignis', '', 30);
        $this->RegisterVariableString('LastIVSEvent', 'Letztes CrossRegion-Ereignis roh', '', 40);
        $this->RegisterVariableString('ObserverStatus', 'Observer Diagnose', '', 50);
        $this->RegisterVariableString('EventAudit', 'P03 IVS-Ereignisdiagnose', '', 60);
        $this->RegisterVariableString('WireProbe', 'IPC5442 Wire-Rohereignisse vor Parser', '', 65);

        $this->SetTimerInterval('HandshakeTimer', 0);
        $this->SetTimerInterval('SocketRestartTimer', 0);
        $this->SetTimerInterval('PersonPulseTimer', 0);
        $this->SetTimerInterval('Watchdog', 15000);
        $this->SetBuffer('HttpBuffer', '');
        $this->SetBuffer('EventCarry', '');
        $this->SetBuffer('WireProbeTail', '');
        $this->WriteAttributeBoolean('Streaming', false);
        $this->WriteAttributeBoolean('AuthPending', false);
        $this->WriteAttributeBoolean('LastRequestAuthenticated', false);
        $this->WriteAttributeBoolean('AuthBlocked', false);
        $this->WriteAttributeInteger('AuthFailureCount', 0);
        $this->WriteAttributeInteger('SocketRestartStage', 0);
        $this->WriteAttributeInteger('LastSocketRestart', 0);
        $this->WriteAttributeInteger('LastCameraRx', 0);
        $this->WriteAttributeInteger('LastHttpRequest', 0);
        $this->WriteAttributeInteger('DigestNC', 0);
        $this->WriteAttributeString('DigestChallenge', '{}');
        $this->WriteAttributeInteger('HumanPulseUntil', 0);
        $this->WriteAttributeString('SeenEventKeys', '{}');
        $this->SetValue('PersonDetected', false);
        $this->SetValue('StreamOK', false);

        // Proven Dahua lifecycle from AussenlichtAutomatik2/v0.6.12:
        // RequireParent()+GetConfigurationForParent() owns the parent config.
        // Do NOT ApplyChanges() the parent here; that races the staged
        // 401 -> fresh-socket -> authenticated Digest reconnect.
        $this->updateParentSubscription($this->getParentID());

        if ($this->ReadPropertyBoolean('Enabled') && $this->cameraConfigurationReady()) {
            $this->scheduleSocketRestart(500);
        }
        $this->refreshObserverStatus('ApplyChanges / Neustart angefordert');
    }

    public function GetConfigurationForParent(): string
    {
        $parentID = $this->getParentID();
        if ($parentID > 0 && IPS_InstanceExists($parentID)
            && !$this->hasExclusiveParentSocket($parentID)) {
            // Never impose P03 host/port/open settings onto a foreign socket.
            return '{}';
        }
        return json_encode([
            'Host' => trim($this->ReadPropertyString('CameraHost')),
            'Port' => max(1, $this->ReadPropertyInteger('CameraPort')),
            'Open' => $this->ReadPropertyBoolean('Enabled') && $this->cameraConfigurationReady()
        ]);
    }

    public function ReceiveData($JSONString): string
    {
        $data = json_decode((string) $JSONString, true);
        if (!is_array($data) || !isset($data['Buffer'])) {
            return '';
        }

        $chunk = (string) $data['Buffer'];
        if ($chunk === '') {
            return '';
        }

        $this->WriteAttributeInteger('LastCameraRx', time());

        if ($this->ReadAttributeBoolean('Streaming')) {
            $this->SetValue('StreamOK', true);
            $this->processEventData($chunk);
            return '';
        }

        $http = $this->GetBuffer('HttpBuffer') . $chunk;
        if (strlen($http) > 262144) {
            $http = substr($http, -131072);
        }

        $headerEnd = strpos($http, "\r\n\r\n");
        if ($headerEnd === false) {
            $this->SetBuffer('HttpBuffer', $http);
            return '';
        }

        $header = substr($http, 0, $headerEnd + 4);
        $body = substr($http, $headerEnd + 4);
        $this->SetBuffer('HttpBuffer', '');

        if (!preg_match('#^HTTP/\d\.\d\s+(\d{3})#i', $header, $m)) {
            $this->refreshObserverStatus('HTTP-Antwortkopf ungültig');
            return '';
        }

        $status = (int) $m[1];
        if ($status === 401) {
            $challenge = $this->extractDigestChallenge($header);
            if ($challenge === []) {
                $this->refreshObserverStatus('HTTP 401 ohne Digest-Challenge');
                return '';
            }

            $this->WriteAttributeString('DigestChallenge', json_encode($challenge));
            $this->refreshObserverStatus('HTTP 401');
            $wasAuthenticated = $this->ReadAttributeBoolean('LastRequestAuthenticated');
            if (!$wasAuthenticated) {
                $this->WriteAttributeBoolean('AuthPending', true);
                $this->scheduleSocketRestart(100);
                return '';
            }

            $stale = strtolower((string) ($challenge['stale'] ?? 'false')) === 'true';
            $failures = $this->ReadAttributeInteger('AuthFailureCount') + 1;
            $this->WriteAttributeInteger('AuthFailureCount', $failures);
            if ($stale && $failures <= 2) {
                $this->WriteAttributeBoolean('AuthPending', true);
                $this->scheduleSocketRestart(100);
                return '';
            }

            $this->WriteAttributeBoolean('AuthBlocked', true);
            $this->SetValue('StreamOK', false);
            $this->refreshObserverStatus('Digest-Anmeldung abgewiesen');
            return '';
        }

        if ($status === 200) {
            $this->WriteAttributeBoolean('Streaming', true);
            $this->WriteAttributeBoolean('AuthPending', false);
            $this->WriteAttributeBoolean('AuthBlocked', false);
            $this->WriteAttributeInteger('AuthFailureCount', 0);
            $this->SetValue('StreamOK', true);
            $this->refreshObserverStatus('HTTP 200 / Stream aktiv');
            if ($body !== '') {
                $this->processEventData($body);
            }
            return '';
        }

        $this->refreshObserverStatus('HTTP Status ' . $status . ' unerwartet');
        return '';
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ((int) $Message !== self::IM_CHANGESTATUS_ID || (int) $SenderID !== $this->getParentID()) {
            return;
        }

        $status = 0;
        if (is_array($Data) && isset($Data[0])) {
            $status = (int) $Data[0];
        } elseif (IPS_InstanceExists((int) $SenderID)) {
            $status = (int) IPS_GetInstance((int) $SenderID)['InstanceStatus'];
        }

        if ($status === 102 && $this->ReadPropertyBoolean('Enabled') && $this->cameraConfigurationReady()) {
            $this->WriteAttributeBoolean('Streaming', false);
            $this->SetValue('StreamOK', false);
            $this->SetBuffer('HttpBuffer', '');
            $this->SetBuffer('EventCarry', '');
        $this->SetBuffer('WireProbeTail', '');
            if (!$this->ReadAttributeBoolean('AuthBlocked')) {
                $this->SetTimerInterval('HandshakeTimer', 250);
            }
            $this->refreshObserverStatus('Parent aktiv');
            return;
        }

        $this->WriteAttributeBoolean('Streaming', false);
        $this->SetValue('StreamOK', false);
        $this->clearPersonPulse();
        $this->refreshObserverStatus('Parent nicht aktiv');
    }

    public function HandshakeTimer(): void
    {
        $this->SetTimerInterval('HandshakeTimer', 0);
        if (!$this->ReadPropertyBoolean('Enabled') || !$this->cameraConfigurationReady() || $this->ReadAttributeBoolean('AuthBlocked')) {
            return;
        }

        $parentID = $this->getParentID();
        if ($parentID <= 0 || !IPS_InstanceExists($parentID) || (int) IPS_GetInstance($parentID)['InstanceStatus'] !== 102) {
            return;
        }

        $this->beginHandshake();
    }

    public function SocketRestartTimer(): void
    {
        $this->SetTimerInterval('SocketRestartTimer', 0);
        $parentID = $this->getParentID();
        if ($parentID <= 0 || !IPS_InstanceExists($parentID)) {
            $this->WriteAttributeInteger('SocketRestartStage', 0);
            $this->refreshObserverStatus('Kein P03 Client Socket – SocketRestart abgebrochen');
            return;
        }
        if (!$this->hasExclusiveParentSocket($parentID)) {
            $this->WriteAttributeInteger('SocketRestartStage', 0);
            $this->SetValue('StreamOK', false);
            $this->refreshObserverStatus('SICHERHEIT: Client Socket mit Fremdmodul geteilt – kein Eingriff');
            return;
        }

        $stage = $this->ReadAttributeInteger('SocketRestartStage');
        if ($stage === 1) {
            try {
                IPS_SetProperty($parentID, 'Open', false);
                IPS_ApplyChanges($parentID);
            } catch (Throwable $e) {
                $this->WriteAttributeInteger('SocketRestartStage', 0);
                $this->refreshObserverStatus('Socket schließen FEHLER: ' . $e->getMessage());
                return;
            }
            $this->WriteAttributeInteger('SocketRestartStage', 2);
            $this->SetTimerInterval('SocketRestartTimer', 300);
            $this->refreshObserverStatus('Socket geschlossen');
            return;
        }

        if ($stage === 2) {
            $this->WriteAttributeInteger('SocketRestartStage', 0);
            if (!$this->ReadPropertyBoolean('Enabled') || !$this->cameraConfigurationReady() || $this->ReadAttributeBoolean('AuthBlocked')) {
                return;
            }

            try {
                IPS_SetProperty($parentID, 'Host', trim($this->ReadPropertyString('CameraHost')));
                IPS_SetProperty($parentID, 'Port', max(1, $this->ReadPropertyInteger('CameraPort')));
                IPS_SetProperty($parentID, 'Open', true);
                IPS_ApplyChanges($parentID);
            } catch (Throwable $e) {
                $this->refreshObserverStatus('Socket öffnen FEHLER: ' . $e->getMessage());
                return;
            }

            $this->WriteAttributeInteger('LastSocketRestart', time());
            $this->SetTimerInterval('HandshakeTimer', 1000);
            $this->refreshObserverStatus('Socket geöffnet');
        }
    }

    public function Watchdog(): void
    {
        // The observer's timer and the independent P03-main health timer
        // use the same guard. No other module or IO is ever touched.
        $this->HealthTick();
    }

    /**
     * Real health check based on the age of the LAST RECEIVED TCP DATA,
     * not a remembered HTTP-200 flag. Safe for duplicate timer invocations.
     * Reports every invocation, so a scheduler failure can be distinguished
     * from an idle Dahua eventstream.
     */
    public function HealthTick(): string
    {
        $now = time();
        $this->WriteAttributeInteger('LastHealthTick', $now);

        if (!$this->ReadPropertyBoolean('Enabled') || !$this->cameraConfigurationReady()) {
            $this->WriteAttributeString('LastHealthAction', 'DISABLED_OR_UNCONFIGURED');
            $this->refreshObserverStatus('HEALTH: Observer deaktiviert oder unkonfiguriert');
            return 'DISABLED_OR_UNCONFIGURED';
        }
        if ($this->ReadAttributeBoolean('AuthBlocked')) {
            $this->SetValue('StreamOK', false);
            $this->WriteAttributeString('LastHealthAction', 'AUTH_BLOCKED');
            $this->refreshObserverStatus('HEALTH: Digest-Anmeldung gesperrt');
            return 'AUTH_BLOCKED';
        }

        $parentID = $this->getParentID();
        if ($parentID <= 0 || !IPS_InstanceExists($parentID)) {
            $this->SetValue('StreamOK', false);
            $this->WriteAttributeString('LastHealthAction', 'NO_PARENT');
            $this->refreshObserverStatus('HEALTH: P03 Client Socket fehlt');
            return 'NO_PARENT';
        }
        $parent = IPS_GetInstance($parentID);
        if (!$this->hasExclusiveParentSocket($parentID)
            || strcasecmp((string) ($parent['ModuleInfo']['ModuleID'] ?? ''),
                self::CLIENT_SOCKET_GUID) !== 0) {
            $this->SetValue('StreamOK', false);
            $this->WriteAttributeString('LastHealthAction', 'UNSAFE_PARENT');
            $this->refreshObserverStatus('HEALTH: Socket geteilt oder falscher Modultyp');
            return 'UNSAFE_PARENT';
        }

        $streaming = $this->ReadAttributeBoolean('Streaming');
        $lastRx = $this->ReadAttributeInteger('LastCameraRx');
        $lastRequest = $this->ReadAttributeInteger('LastHttpRequest');
        $age = $lastRx > 0 ? $now - $lastRx : -1;
        $tcpActive = (int) ($parent['InstanceStatus'] ?? 0) === 102
            && (bool) IPS_GetProperty($parentID, 'Open');
        if ($tcpActive && $streaming && $age >= 0 && $age <= 25) {
            $this->WriteAttributeString('LastHealthAction', 'LIVE');
            $this->refreshObserverStatus('HEALTH: LIVE – neuer Kameraempfang innerhalb von 25 s');
            return 'LIVE';
        }

        $stale = $streaming && ($lastRx <= 0 || $age > 25);
        if ($stale) {
            $this->WriteAttributeInteger('LastStaleRx', $lastRx);
            $this->WriteAttributeBoolean('Streaming', false);
            $this->SetValue('StreamOK', false);
            $this->clearPersonPulse();
            $this->WriteAttributeString('LastHealthAction', 'STALE');
            $this->refreshObserverStatus('HEALTH: kein Kameraempfang seit '
                . ($age >= 0 ? $age . ' s' : 'unbekannter Zeit')
                . '; HTTP-200-Flag verworfen');
        } elseif (!$tcpActive) {
            $this->WriteAttributeBoolean('Streaming', false);
            $this->SetValue('StreamOK', false);
            $this->clearPersonPulse();
        }

        // Do not disturb an ongoing request or authenticated Digest retry.
        // A stale stream or timed-out challenge is explicitly rebuilt with a
        // FRESH TCP socket, unlike the previous "already active" shortcut.
        $needRetry = $stale || !$tcpActive ||
            (!$streaming && ($lastRequest === 0 || $now - $lastRequest > 12));
        if (!$needRetry) {
            $this->WriteAttributeString('LastHealthAction', 'AWAITING_HTTP');
            $this->refreshObserverStatus('HEALTH: warte auf neue HTTP-Antwort');
            return 'AWAITING_HTTP';
        }
        $lastRestart = $this->ReadAttributeInteger('LastSocketRestart');
        if ($lastRestart > 0 && $now - $lastRestart < 20) {
            $this->WriteAttributeString('LastHealthAction', 'COOLDOWN');
            $this->refreshObserverStatus('HEALTH: Neuverbindung in 20-s-Sperrfrist');
            return 'COOLDOWN';
        }
        $this->WriteAttributeString('LastHealthAction', 'RECONNECT_ATTEMPT');
        $success = $this->StartSocketNow();
        $result = $success ? 'RECONNECT_GET_SENT' : 'RECONNECT_PENDING';
        $this->WriteAttributeString('LastHealthAction', $result);
        $this->refreshObserverStatus('HEALTH: ' . $result);
        return $result;
    }

    public function PersonPulseTimer(): void
    {
        $until = $this->ReadAttributeInteger('HumanPulseUntil');
        if ($until > time()) {
            $this->SetTimerInterval('PersonPulseTimer', max(250, ($until - time()) * 1000));
            return;
        }
        $this->clearPersonPulse();
    }

    /**
     * Immediate, explicit commissioning of one P03-owned Client Socket.
     * Useful after a new socket is linked but its timer stays at stage 1.
     * Does NOT rely on a scheduled SocketRestartTimer to open the socket or
     * to issue the initial HTTP request. Digest retries remain asynchronous.
     *
     * Never change shared I/O or any Dahua camera configuration.
     */
    public function StartSocketNow(): bool
    {
        if (!$this->ReadPropertyBoolean('Enabled') || !$this->cameraConfigurationReady()) {
            $this->refreshObserverStatus('Sofortstart verweigert: P03 Observer deaktiviert/unkonfiguriert');
            return false;
        }
        $parentID = $this->getParentID();
        if ($parentID <= 0 || !IPS_InstanceExists($parentID)
            || !$this->hasExclusiveParentSocket($parentID)) {
            $this->refreshObserverStatus('Sofortstart verweigert: kein exklusiver P03 Client Socket');
            return false;
        }
        $parent = IPS_GetInstance($parentID);
        if (strcasecmp((string) ($parent['ModuleInfo']['ModuleID'] ?? ''),
            self::CLIENT_SOCKET_GUID) !== 0) {
            $this->refreshObserverStatus('Sofortstart verweigert: falscher Parent-Modultyp');
            return false;
        }

        $host = trim($this->ReadPropertyString('CameraHost'));
        $port = max(1, $this->ReadPropertyInteger('CameraPort'));
        if (trim((string) IPS_GetProperty($parentID, 'Host')) !== $host
            || (int) IPS_GetProperty($parentID, 'Port') !== $port) {
            $this->refreshObserverStatus('Sofortstart verweigert: Client Socket Host/Port abweichend');
            return false;
        }

        $now = time();
        $lastRx = $this->ReadAttributeInteger('LastCameraRx');
        $lastRequest = $this->ReadAttributeInteger('LastHttpRequest');
        $streaming = $this->ReadAttributeBoolean('Streaming');
        $fresh = $streaming && (bool) $this->GetValue('StreamOK')
            && $lastRx > 0 && $now - $lastRx <= 25;
        if ($fresh) {
            $this->refreshObserverStatus('Sofortstart: Kameraempfang aktuell, kein Neustart nötig');
            return true;
        }
        // On any old/half-dead eventstream, a second GET on the same TCP
        // connection is unsafe. Close the P03-owned socket first.
        $needsFreshTcp = (bool) IPS_GetProperty($parentID, 'Open')
            && ($streaming || $lastRequest > 0 || $lastRx > 0);

        // Cancel previously scheduled stage 1/2, which could otherwise
        // race with the direct startup and close the freshly opened socket.
        $this->SetTimerInterval('SocketRestartTimer', 0);
        $this->SetTimerInterval('HandshakeTimer', 0);
        $this->WriteAttributeInteger('SocketRestartStage', 0);
        $this->WriteAttributeBoolean('Streaming', false);
        $this->SetValue('StreamOK', false);
        $this->clearPersonPulse();
        $this->WriteAttributeBoolean('AuthBlocked', false);
        $this->WriteAttributeBoolean('AuthPending', false);
        $this->WriteAttributeBoolean('LastRequestAuthenticated', false);
        $this->WriteAttributeInteger('AuthFailureCount', 0);
        $this->WriteAttributeInteger('DigestNC', 0);
        $this->WriteAttributeString('DigestChallenge', '{}');
        $this->WriteAttributeInteger('LastHttpRequest', 0);
        $this->WriteAttributeInteger('LastCameraRx', 0);
        $this->SetBuffer('HttpBuffer', '');
        $this->SetBuffer('EventCarry', '');
        $this->SetBuffer('WireProbeTail', '');

        try {
            if ($needsFreshTcp) {
                IPS_SetProperty($parentID, 'Open', false);
                IPS_ApplyChanges($parentID);
            }
            if (!(bool) IPS_GetProperty($parentID, 'Open')
                || (int) (IPS_GetInstance($parentID)['InstanceStatus'] ?? 0) !== 102) {
                IPS_SetProperty($parentID, 'Open', true);
                IPS_ApplyChanges($parentID);
            }
        } catch (Throwable $e) {
            $this->refreshObserverStatus('Sofortstart: Client Socket konnte nicht geöffnet werden ('
                . get_class($e) . ')');
            return false;
        }

        $this->WriteAttributeInteger('LastSocketRestart', time());
        if ((int) (IPS_GetInstance($parentID)['InstanceStatus'] ?? 0) !== 102) {
            $this->refreshObserverStatus('Sofortstart: Client Socket geöffnet, TCP noch nicht aktiv');
            return false;
        }

        // A parent status notification may also have scheduled a handshake.
        // Cancel that callback before making the explicit request.
        $this->SetTimerInterval('HandshakeTimer', 0);
        // Synchronously issue the initial Dahua eventManager GET. This
        // distinguishes a broken timer from the real Digest/TCP problem.
        $this->HandshakeTimer();
        $sent = $this->ReadAttributeInteger('LastHttpRequest') > 0;
        $this->refreshObserverStatus($sent
            ? 'Sofortstart: HTTP GET angestoßen, warte auf Dahua 401/200'
            : 'Sofortstart: Socket aktiv, aber HTTP GET nicht gesendet');
        return $sent;
    }

    public function Reconnect(): void
    {
        $this->SetTimerInterval('HandshakeTimer', 0);
        $this->SetTimerInterval('SocketRestartTimer', 0);
        $this->WriteAttributeInteger('SocketRestartStage', 0);
        $this->WriteAttributeInteger('LastHttpRequest', 0);
        $this->WriteAttributeBoolean('Streaming', false);
        $this->SetValue('StreamOK', false);
        $this->clearPersonPulse();
        $this->WriteAttributeBoolean('AuthPending', false);
        $this->WriteAttributeBoolean('AuthBlocked', false);
        $this->WriteAttributeBoolean('LastRequestAuthenticated', false);
        $this->WriteAttributeInteger('AuthFailureCount', 0);
        $this->WriteAttributeInteger('DigestNC', 0);
        $this->WriteAttributeString('DigestChallenge', '{}');
        $this->WriteAttributeInteger('LastCameraRx', 0);
        $this->SetBuffer('HttpBuffer', '');
        $this->SetBuffer('EventCarry', '');
        $this->SetBuffer('WireProbeTail', '');
        $this->scheduleSocketRestart(100);
        $this->refreshObserverStatus('Reconnect angefordert');
    }

    private function processEventData(string $chunk): void
    {
        // Independent wire-level evidence BEFORE JSON/event parsing or the
        // strict P03 IVS rule filter. Do not copy camera payload/credentials.
        $this->trackWireEvidence($chunk);
        $carry = $this->GetBuffer('EventCarry');
        $events = JVP03DahuaEventParser::feed($chunk, $carry);
        $this->SetBuffer('EventCarry', $carry);

        $wantedName = $this->expectedRuleName();
        $wantedIndex = $this->ReadPropertyInteger('RuleIndex');
        $wantedId = $this->ReadPropertyInteger('RuleID');
        if ($wantedName === '' && $wantedIndex < 0 && $wantedId < 0) {
            return;
        }

        foreach ($events as $event) {
            $code = trim((string) ($event['code'] ?? ''));
            $action = strtolower(trim((string) ($event['action'] ?? '')));

            if (strcasecmp($code, 'CrossRegionDetection') === 0) {
                $rawSummary = [
                    'time' => date('H:i:s'),
                    'role' => $this->ReadPropertyString('Role'),
                    'action' => $event['action'] ?? '',
                    'ruleName' => $event['ruleName'] ?? null,
                    'cfgRuleId' => $event['cfgRuleId'] ?? null,
                    'ruleIdUpper' => $event['ruleIdUpper'] ?? null,
                    'ruleIdLower' => $event['ruleIdLower'] ?? null,
                    'expectedRuleName' => $wantedName,
                    'expectedIndex' => $wantedIndex,
                    'expectedId' => $wantedId,
                    'payloadHuman' => (bool) ($event['human'] ?? false),
                    'classification' => $event['classification'] ?? null,
                    'eventId' => $event['eventId'] ?? null
                ];
                $this->SetValue('LastIVSEvent', json_encode($rawSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->SendDebug('CrossRegionDetection', json_encode($rawSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
            }

            $this->WriteAttributeInteger('ParsedEventCount',
                $this->ReadAttributeInteger('ParsedEventCount') + 1);
            if (strcasecmp($code, 'CrossRegionDetection') === 0) {
                $this->WriteAttributeInteger('CrossRegionEventCount',
                    $this->ReadAttributeInteger('CrossRegionEventCount') + 1);
            }

            if (!P03AuxObserverLogic::isMatchingRuleEvent($event, $wantedName, $wantedIndex, $wantedId)) {
                $this->WriteAttributeInteger('RuleRejectCount',
                    $this->ReadAttributeInteger('RuleRejectCount') + 1);
                $this->recordEventAudit($event, 'RULE_OR_EVENTCODE_REJECTED');
                continue;
            }
            $this->WriteAttributeInteger('RuleMatchEventCount',
                $this->ReadAttributeInteger('RuleMatchEventCount') + 1);

            $seen = json_decode($this->ReadAttributeString('SeenEventKeys'), true);
            if (!is_array($seen)) {
                $seen = [];
            }
            // Never treat a reused Dahua EventID as permanently consumed.
            // A delivery repeated in 3 seconds is a duplicate; the same
            // event identity from a later genuine detection may be counted.
            $fresh = P03AuxObserverLogic::isFreshDelivery($event, $seen, time(), 3);
            $this->WriteAttributeString('SeenEventKeys', json_encode($seen));
            if (!$fresh) {
                $this->WriteAttributeInteger('DedupeRejectCount',
                    $this->ReadAttributeInteger('DedupeRejectCount') + 1);
                $this->recordEventAudit($event, 'DUPLICATE_WITHIN_3_SECONDS');
                continue;
            }

            $isHumanStart = P03AuxObserverLogic::isHumanProofStart(
                $event, $wantedName, $wantedIndex, $wantedId);
            $summary = [
                'time' => date('H:i:s'),
                'role' => $this->ReadPropertyString('Role'),
                'code' => $code,
                'action' => $event['action'] ?? '',
                'ruleName' => $event['ruleName'] ?? null,
                'cfgRuleId' => $event['cfgRuleId'] ?? null,
                'ruleIdUpper' => $event['ruleIdUpper'] ?? null,
                'ruleIdLower' => $event['ruleIdLower'] ?? null,
                'human' => $isHumanStart,
                'humanFromRule' => true,
                'payloadHuman' => (bool) ($event['human'] ?? false),
                'classification' => $event['classification'] ?? null,
                'eventId' => $event['eventId'] ?? null,
                'objectId' => $event['objectId'] ?? null
            ];
            if ($isHumanStart) {
                // "Letztes Human-IVS-Ereignis" must never be overwritten by
                // a STOP, a vehicle, or an event that did not count.
                $this->SetValue('LastEvent', json_encode($summary,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $counterID = $this->GetIDForIdent('HumanEventCounter');
                if ($counterID > 0 && IPS_VariableExists($counterID)) {
                    $this->SetValue('HumanEventCounter', ((int) GetValue($counterID)) + 1);
                }

                $until = time() + 5;
                $this->WriteAttributeInteger('HumanPulseUntil', $until);
                $this->SetValue('PersonDetected', true);
                $this->SetTimerInterval('PersonPulseTimer', 5000);
                $this->recordEventAudit($event, 'HUMAN_START_COUNTED');
                continue;
            }
            $this->recordEventAudit($event, in_array($action, ['stop', 'off'], true)
                ? 'STOP_NO_NEW_PROOF' : 'MATCHED_BUT_NOT_HUMAN_START');

            // Keep the 5-second pulse even if Dahua sends a STOP immediately.
            if (in_array($action, ['stop', 'off'], true)
                && $this->ReadAttributeInteger('HumanPulseUntil') <= time()) {
                $this->clearPersonPulse();
            }
        }
    }

    /**
     * Compact, privacy-minimal event trace. User can inspect whether the
     * camera emits no IVS data, a wrong rule, a duplicate or valid Human START.
     * No camera credentials or full payload are persisted.
     *
     * @param array<string,mixed> $event
     */
    /**
     * Records the camera's real eventManager.cgi event headers independently
     * of any P03 parser/rule match. Handles headers split across TCP chunks.
     * Important: a LIVE heartbeat alone says nothing about detection.
     */
    private function trackWireEvidence(string $chunk): void
    {
        $heartbeatCount = preg_match_all('/Heartbeat(?:\r?\n|$)/i', $chunk);
        if ($heartbeatCount > 0) {
            $this->WriteAttributeInteger('WireHeartbeatCount',
                $this->ReadAttributeInteger('WireHeartbeatCount') + $heartbeatCount);
        }
        $data = $this->GetBuffer('WireProbeTail') . $chunk;
        if (strlen($data) > 131072) {
            $data = substr($data, -131072);
        }
        $pattern = '/Code\s*=\s*([A-Za-z0-9_+-]+)\s*;\s*action\s*=\s*([A-Za-z0-9_+-]+)\s*;\s*index\s*=\s*([0-9]+)/i';
        $matches = [];
        $found = preg_match_all($pattern, $data, $matches, PREG_OFFSET_CAPTURE);
        $cut = 0;
        if ($found > 0) {
            foreach ($matches[0] as $i => $hit) {
                $end = (int) $hit[1] + strlen((string) $hit[0]);
                $cut = max($cut, $end);
                $count = $this->ReadAttributeInteger('WireCodeCount') + 1;
                $this->WriteAttributeInteger('WireCodeCount', $count);
                $name = (string) $matches[1][$i][0];
                $action = (string) $matches[2][$i][0];
                $index = (string) $matches[3][$i][0];
                $safeHeader = P03IPC5442Audit::wireHeader($name, $action, $index, $count);
                if ($safeHeader !== '') {
                    $this->SetValue('WireProbe', date('H:i:s') . ' ' . $safeHeader
                        . ' | Heartbeats=' . $this->ReadAttributeInteger('WireHeartbeatCount'));
                }
            }
        }
        // Keep only incomplete header fragments for the next TCP chunk,
        // not the JSON data of already recorded events.
        $remainder = substr($data, $cut);
        $this->SetBuffer('WireProbeTail', substr($remainder, -96));
    }

    private function recordEventAudit(array $event, string $decision): void
    {
        $summary = [
            'time' => date('H:i:s'),
            'role' => $this->ReadPropertyString('Role'),
            'decision' => $decision,
            'code' => (string) ($event['code'] ?? ''),
            'action' => (string) ($event['action'] ?? ''),
            'ruleName' => $event['ruleName'] ?? null,
            'ruleId' => $event['cfgRuleId'] ?? $event['ruleIdUpper'] ?? $event['ruleIdLower'] ?? null,
            'eventId' => $event['eventId'] ?? null,
            'groupId' => $event['groupId'] ?? null,
            'objectId' => $event['objectId'] ?? null,
            'classification' => $event['classification'] ?? null,
            'eventsTotal' => $this->ReadAttributeInteger('ParsedEventCount'),
            'crossRegionTotal' => $this->ReadAttributeInteger('CrossRegionEventCount'),
            'ruleMatches' => $this->ReadAttributeInteger('RuleMatchEventCount'),
            'ruleRejected' => $this->ReadAttributeInteger('RuleRejectCount'),
            'dedupeRejected' => $this->ReadAttributeInteger('DedupeRejectCount'),
            'humanCount' => (int) $this->GetValue('HumanEventCounter')
        ];
        $this->SetValue('EventAudit', json_encode($summary,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function expectedRuleName(): string
    {
        return match ($this->ReadPropertyString('Role')) {
            'JV_LEFT' => 'P03_JV_LEFT_HUMAN',
            'WORK_LEFT' => 'P03_WORK_LEFT_HUMAN',
            default => ''
        };
    }

    private function clearPersonPulse(): void
    {
        $this->WriteAttributeInteger('HumanPulseUntil', 0);
        $this->SetTimerInterval('PersonPulseTimer', 0);
        $this->SetValue('PersonDetected', false);
    }

    private function beginHandshake(): void
    {
        if ($this->ReadAttributeBoolean('AuthBlocked')) {
            return;
        }
        $this->WriteAttributeBoolean('Streaming', false);
        $this->SetValue('StreamOK', false);
        $this->SetBuffer('HttpBuffer', '');
        $this->SetBuffer('EventCarry', '');
        $this->SetBuffer('WireProbeTail', '');
        $this->sendEventRequest($this->ReadAttributeBoolean('AuthPending'));
    }

    private function sendEventRequest(bool $authenticated): void
    {
        $host = trim($this->ReadPropertyString('CameraHost'));
        $port = max(1, $this->ReadPropertyInteger('CameraPort'));
        $uri = '/cgi-bin/eventManager.cgi?action=attach&codes=[All]&heartbeat=5';
        if ($host === '') {
            return;
        }

        $hostHeader = $port === 80 ? $host : ($host . ':' . $port);
        $headers = [
            'GET ' . $uri . ' HTTP/1.1',
            'Host: ' . $hostHeader,
            'User-Agent: IP-Symcon-JVPresenceP03Aux/0.6.14',
            'Accept: multipart/x-mixed-replace, */*',
            'Connection: keep-alive'
        ];

        if ($authenticated) {
            $challenge = json_decode($this->ReadAttributeString('DigestChallenge'), true);
            if (!is_array($challenge)) {
                $challenge = [];
            }

            $nc = $this->ReadAttributeInteger('DigestNC') + 1;
            $this->WriteAttributeInteger('DigestNC', $nc);
            $cnonce = substr(hash('sha256', $this->InstanceID . ':' . microtime(true) . ':' . mt_rand()), 0, 16);
            try {
                $headers[] = 'Authorization: ' . JVP03DahuaDigest::buildAuthorization(
                    $this->ReadPropertyString('Username'),
                    $this->ReadPropertyString('Password'),
                    'GET',
                    $uri,
                    $challenge,
                    $nc,
                    $cnonce
                );
            } catch (Throwable $e) {
                return;
            }
        }

        $payload = json_encode([
            'DataID' => self::SOCKET_TX_GUID,
            'Buffer' => implode("\r\n", $headers) . "\r\n\r\n"
        ]);
        $this->WriteAttributeBoolean('LastRequestAuthenticated', $authenticated);
        $this->WriteAttributeInteger('LastHttpRequest', time());

        try {
            $this->SendDataToParent($payload);
            $this->refreshObserverStatus($authenticated ? 'Digest-GET gesendet' : 'Initial-GET gesendet');
        } catch (Throwable $e) {
            $this->refreshObserverStatus('Eventstream-GET FEHLER: ' . $e->getMessage());
        }
    }

    private function extractDigestChallenge(string $header): array
    {
        if (!preg_match('/^WWW-Authenticate:\s*(Digest\s+.+)$/im', $header, $m)) {
            return [];
        }
        return JVP03DahuaDigest::parseChallenge(trim((string) $m[1]));
    }

    private function cameraConfigurationReady(): bool
    {
        return trim($this->ReadPropertyString('CameraHost')) !== ''
            && trim($this->ReadPropertyString('Username')) !== ''
            && $this->ReadPropertyString('Password') !== '';
    }

    private function refreshObserverStatus(string $reason = ''): void
    {
        $parentID = $this->getParentID();
        $parentStatus = -1;
        $parentOpen = null;
        if ($parentID > 0 && IPS_InstanceExists($parentID)) {
            try {
                $parentStatus = (int) IPS_GetInstance($parentID)['InstanceStatus'];
                $parentOpen = (bool) IPS_GetProperty($parentID, 'Open');
            } catch (Throwable $e) {
            }
        }

        $status = [
            'reason' => $reason,
            'role' => $this->ReadPropertyString('Role'),
            'configReady' => $this->cameraConfigurationReady(),
            'host' => trim($this->ReadPropertyString('CameraHost')),
            'userSet' => trim($this->ReadPropertyString('Username')) !== '',
            'passwordSet' => $this->ReadPropertyString('Password') !== '',
            'parentID' => $parentID,
            'parentStatus' => $parentStatus,
            'parentOpen' => $parentOpen,
            'parentExclusive' => $parentID > 0 && IPS_InstanceExists($parentID)
                ? $this->hasExclusiveParentSocket($parentID) : false,
            'streaming' => $this->ReadAttributeBoolean('Streaming'),
            'restartStage' => $this->ReadAttributeInteger('SocketRestartStage'),
            'authPending' => $this->ReadAttributeBoolean('AuthPending'),
            'authBlocked' => $this->ReadAttributeBoolean('AuthBlocked'),
            'lastHttpRequest' => $this->ReadAttributeInteger('LastHttpRequest'),
            'lastCameraRx' => $this->ReadAttributeInteger('LastCameraRx'),
            'lastHealthTick' => $this->ReadAttributeInteger('LastHealthTick'),
            'lastHealthAction' => $this->ReadAttributeString('LastHealthAction'),
            'lastStaleRx' => $this->ReadAttributeInteger('LastStaleRx'),
            'rxAge' => $this->ReadAttributeInteger('LastCameraRx') > 0
                ? max(0, time() - $this->ReadAttributeInteger('LastCameraRx')) : -1,
            'streamOK' => (bool) $this->GetValue('StreamOK'),
            'counter' => (int) $this->GetValue('HumanEventCounter'),
            'wireCodeCount' => $this->ReadAttributeInteger('WireCodeCount'),
            'wireHeartbeatCount' => $this->ReadAttributeInteger('WireHeartbeatCount'),
            'eventsTotal' => $this->ReadAttributeInteger('ParsedEventCount'),
            'crossRegionTotal' => $this->ReadAttributeInteger('CrossRegionEventCount'),
            'ruleMatches' => $this->ReadAttributeInteger('RuleMatchEventCount'),
            'ruleRejected' => $this->ReadAttributeInteger('RuleRejectCount'),
            'dedupeRejected' => $this->ReadAttributeInteger('DedupeRejectCount')
        ];
        $json = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->SetValue('ObserverStatus', $json === false ? '' : $json);
    }

    /**
     * A connection created by RequireParent is normally exclusive. If the
     * observer was manually connected to an existing ALA2/foreign socket,
     * refuse to close or reconfigure it. Only P03's own socket may be touched.
     */
    private function hasExclusiveParentSocket(int $parentID): bool
    {
        if (!function_exists('IPS_GetInstanceList')) {
            return false;
        }
        try {
            foreach (IPS_GetInstanceList() as $otherID) {
                $otherID = (int) $otherID;
                if ($otherID <= 0 || $otherID === $this->InstanceID) {
                    continue;
                }
                $other = IPS_GetInstance($otherID);
                if ((int) ($other['ConnectionID'] ?? 0) === $parentID) {
                    return false;
                }
            }
        } catch (Throwable $e) {
            return false;
        }
        return true;
    }

    private function getParentID(): int
    {
        $instance = IPS_GetInstance($this->InstanceID);
        return (int) ($instance['ConnectionID'] ?? 0);
    }

    private function updateParentSubscription(int $newID): void
    {
        $oldID = $this->ReadAttributeInteger('RegisteredParentID');
        if ($oldID > 0 && $oldID !== $newID && IPS_InstanceExists($oldID)) {
            try {
                $this->UnregisterMessage($oldID, self::IM_CHANGESTATUS_ID);
            } catch (Throwable $e) {
            }
        }

        if ($newID > 0 && IPS_InstanceExists($newID)) {
            try {
                $this->RegisterMessage($newID, self::IM_CHANGESTATUS_ID);
            } catch (Throwable $e) {
            }
        }
        $this->WriteAttributeInteger('RegisteredParentID', $newID);
    }

    private function scheduleSocketRestart(int $delayMs = 100): void
    {
        if (!$this->ReadPropertyBoolean('Enabled') || !$this->cameraConfigurationReady()) {
            return;
        }
        if ($this->ReadAttributeInteger('SocketRestartStage') !== 0) {
            return;
        }
        $this->WriteAttributeInteger('SocketRestartStage', 1);
        $this->SetTimerInterval('SocketRestartTimer', max(50, $delayMs));
    }
}
