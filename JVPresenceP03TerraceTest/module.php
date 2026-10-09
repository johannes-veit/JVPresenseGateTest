<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/DahuaDigest.php';
require_once dirname(__DIR__) . '/libs/DahuaEventParser.php';
require_once dirname(__DIR__) . '/libs/GateTestLogic.php';
require_once dirname(__DIR__) . '/libs/P03ProofEngine.php';
require_once dirname(__DIR__) . '/libs/P03DahuaTemplate.php';
require_once dirname(__DIR__) . '/libs/P03AuxHumanRule.php';

class JVPresenceP03MultiCamera extends IPSModule
{
    private const CLIENT_SOCKET_GUID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const SOCKET_TX_GUID = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const AUX_OBSERVER_MODULE_GUID = '{8F4A3A57-3A5B-4F6E-9A64-9E1D326FF7B4}';
    private const TERRACE_DEFAULT_HOST = '192.168.107.110';
    private const VM_UPDATE_ID = 10603;
    private const IM_CHANGESTATUS_ID = 10505;
    private const AUX_JV_DEFAULT_HOST = '192.168.107.96';
    private const AUX_WORK_DEFAULT_HOST = '192.168.107.99';
    private const AUX_JV_ROLE = 'JV_LEFT';
    private const AUX_WORK_ROLE = 'WORK_LEFT';
    private const RULE_NAME = 'P03_HOME_LAGER';
    // Aktuelle rote P03-Grenzlinie aus dem Bild vom 07.10.2026:
    // Sie deckt jetzt neben dem P03-Grenze auch die links liegende Personentür ab.
    // Bild 1536x691 px, per Skeleton/RDP auf die gezeichnete rote Polylinie reduziert.
    // Pixel ca.: (911,78) -> (923,79) -> (930,96) -> (973,129) -> (1104,203)
    // Dahua-IVS-Normkoordinaten 0..8191:
    private const P03_LINE = [
        // Tatsächliche vom Nutzer markierte HOME↔Lagerplatz-Grenze.
        // Die SmartMotionHuman-Erkennung setzt dort erst später ein; deshalb
        // darf die Tripwire nicht künstlich zur späteren Human-Rect verschoben werden.
        [5720, 2208],
        [5960, 2232],
        [6057, 2172],
        [6286, 2303],
        [6505, 2552]
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Enabled', true);

        // P03 owns all three camera connections. The former AussenlichtAutomatik2
        // source selection remains registered only so an update does not lose old
        // instance configuration; it is deliberately not read or called.
        $this->RegisterPropertyBoolean('AutoDiscover', true); // legacy, unused
        $this->RegisterPropertyInteger('SourceCameraInstanceID', 0); // legacy, unused
        $this->RegisterPropertyBoolean('AutoCreateWorkObserver', true); // legacy, unused

        $this->RegisterPropertyString('TerraceHost', self::TERRACE_DEFAULT_HOST);
        $this->RegisterPropertyBoolean('TerraceDiagnosticsEnabled', false);
        $this->RegisterPropertyInteger('CameraPort', 80);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('AuxJVHost', self::AUX_JV_DEFAULT_HOST);
        $this->RegisterPropertyString('AuxWorkHost', self::AUX_WORK_DEFAULT_HOST);
        $this->RegisterPropertyInteger('NearProofWindowSeconds', 30);
        $this->RegisterPropertyInteger('TerraceProofWindowSeconds', 60);

        // Exakt aus der vom Nutzer rot markierten P03-Linie im markierten Livebild der Kamera JV Terrasse abgeleitet.
        // Bild 1536x691 px -> Dahua-IVS-Koordinaten 0..8191.
        // Aktuelle Polylinie umfasst HOME↔Lagerplatz-Grenze.
        // Legacy-Endpunkt-Properties bleiben nur aus Kompatibilitätsgründen vorhanden;
        // der RPC2-Writer verwendet ausschließlich self::P03_LINE.
        // Die Richtungszuordnung HOME→LAGER / LAGER→HOME wird erst durch den 4-Schritt-Test gelernt.
        $this->RegisterPropertyInteger('LineAX', 5720);
        $this->RegisterPropertyInteger('LineAY', 2208);
        $this->RegisterPropertyInteger('LineBX', 6505);
        $this->RegisterPropertyInteger('LineBY', 2552);

        $this->RegisterAttributeInteger('SourceInstanceID', 0);
        $this->RegisterAttributeInteger('RegisteredParentID', 0);
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
        $this->RegisterAttributeInteger('RuleIndex', -1);
        $this->RegisterAttributeInteger('RuleID', -1);
        $this->RegisterAttributeBoolean('RuleCreatedByModule', false);
        $this->RegisterAttributeBoolean('GlobalChangedByModule', false);
        $this->RegisterAttributeString('OriginalGlobalSceneType', '');
        $this->RegisterAttributeString('OriginalSmartMotionEnable', '');
        $this->RegisterAttributeString('SeenEventKeys', '{}');
        $this->RegisterAttributeString('Crossings', '[]');
        $this->RegisterAttributeBoolean('TestActive', false);
        $this->RegisterAttributeString('OriginalVideoAnalyseRuleRpc2', '');
        $this->RegisterAttributeBoolean('Rpc2RuleCreatedByModule', false);
        $this->RegisterAttributeString('OriginalVideoAnalyseGlobalRpc2', '');
        $this->RegisterAttributeBoolean('Rpc2GlobalChangedByModule', false);
        $this->RegisterAttributeBoolean('AuditOnlyNextRun', false);
        $this->RegisterAttributeString('OriginalVideoAnalyseModuleRpc2', '');
        $this->RegisterAttributeBoolean('Rpc2ModuleChangedByModule', false);

        // P03 portal state. P03 never changes the property occupant count by itself.
        $this->RegisterAttributeBoolean('ProductionEnabled', false);
        $this->RegisterAttributeInteger('LastProductionEvent', 0);

        // P03 multi-camera proof state.
        $this->RegisterAttributeInteger('AuxJVInstanceID', 0);
        $this->RegisterAttributeInteger('AuxWorkInstanceID', 0);
        $this->RegisterAttributeInteger('AuxJVPersonVarID', 0);
        $this->RegisterAttributeInteger('AuxWorkPersonVarID', 0);
        $this->RegisterAttributeInteger('AuxJVEventCounterVarID', 0);
        $this->RegisterAttributeInteger('AuxWorkEventCounterVarID', 0);
        $this->RegisterAttributeInteger('AuxJVStreamVarID', 0);
        $this->RegisterAttributeInteger('AuxWorkStreamVarID', 0);
        $this->RegisterAttributeString('AuxJVModel', '');
        $this->RegisterAttributeString('AuxWorkModel', '');
        $this->RegisterAttributeString('AuxJVFirmware', '');
        $this->RegisterAttributeString('AuxWorkFirmware', '');
        $this->RegisterAttributeString('AuxJVSensitivity', '');
        $this->RegisterAttributeString('AuxWorkSensitivity', '');
        $this->RegisterAttributeInteger('AuxJVHumanRuleIndex', -1);
        $this->RegisterAttributeInteger('AuxWorkHumanRuleIndex', -1);
        $this->RegisterAttributeInteger('AuxJVHumanRuleID', -1);
        $this->RegisterAttributeInteger('AuxWorkHumanRuleID', -1);
        $this->RegisterAttributeBoolean('AuxJVHumanRuleCreatedByModule', false);
        $this->RegisterAttributeBoolean('AuxWorkHumanRuleCreatedByModule', false);
        $this->RegisterAttributeString('AuxJVOriginalVideoAnalyseRuleRpc2', '');
        $this->RegisterAttributeString('AuxWorkOriginalVideoAnalyseRuleRpc2', '');
        $this->RegisterAttributeBoolean('AuxJVGlobalChangedByModule', false);
        $this->RegisterAttributeBoolean('AuxWorkGlobalChangedByModule', false);
        $this->RegisterAttributeString('AuxJVOriginalVideoAnalyseGlobalRpc2', '');
        $this->RegisterAttributeString('AuxWorkOriginalVideoAnalyseGlobalRpc2', '');
        $this->RegisterAttributeString('P03HumanEvents', '[]');
        $this->RegisterAttributeString('P03PendingCrossings', '[]');
        $this->RegisterAttributeString('P03DirectionMap', '{}');
        $this->RegisterAttributeInteger('P03VerifiedCount', 0);
        $this->RegisterAttributeString('P03ActionCounts', '{"HOME_TO_LAGER":0,"LAGER_TO_HOME":0}');
        $this->RegisterAttributeBoolean('P03SimulationPassed', false);
        $this->RegisterAttributeBoolean('P03MultiAuditPassed', false);

        $this->RegisterVariableString('PresenceSystemState', 'Presence Systemstatus', '', 1);
        $this->RegisterVariableString('HouseStatus', 'Hausstatus', '', 2);
        $this->RegisterVariableString('PresentPersons', 'Anwesende Personen', '', 3);
        $this->RegisterVariableString('PresenceLastEvent', 'Letztes Presence-Ereignis', '', 4);
        $this->RegisterVariableString('P03CameraStatus', 'P03 Kamerastatus', '', 5);
        $this->RegisterVariableString('P03ProofState', 'P03 Beweisstatus', '', 6);
        $this->RegisterVariableString('P03LastProof', 'P03 letzter Beweis', '', 7);
        $this->RegisterVariableInteger('P03VerifiedTransfers', 'P03 verifizierte Übergänge', '', 8);
        $this->RegisterVariableString('P03DirectionStatus', 'P03 Richtungslernen', '', 9);

        $this->RegisterVariableBoolean('StreamOK', 'Dahua Eventstream OK', '~Switch', 10);
        $this->RegisterVariableBoolean('Ready', 'Test bereit', '~Switch', 20);
        $this->RegisterVariableBoolean('TestActive', 'Test läuft', '~Switch', 30);
        $this->RegisterVariableInteger('CrossingCount', 'Grenzübertritte erkannt', '', 40);
        $this->RegisterVariableString('Result', 'Ergebnis / Nächster Schritt', '', 50);
        $this->RegisterVariableString('LastEvent', 'Letztes IVS-Ereignis', '', 60);
        $this->RegisterVariableString('Protocol', 'Testprotokoll', '', 70);

        $this->RegisterTimer('HandshakeTimer', 0, 'JVP03MC_HandshakeTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('SocketRestartTimer', 0, 'JVP03MC_SocketRestartTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('Watchdog', 15000, 'JVP03MC_Watchdog($_IPS["TARGET"]);');
        $this->RegisterTimer('P03ProofTimer', 0, 'JVP03MC_P03ProofTimer($_IPS["TARGET"]);');

        $this->RequireParent(self::CLIENT_SOCKET_GUID);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('HandshakeTimer', 0);
        $this->SetTimerInterval('SocketRestartTimer', 0);
        $this->SetTimerInterval('P03ProofTimer', 0);
        $this->SetBuffer('HttpBuffer', '');
        $this->SetBuffer('EventCarry', '');
        $this->WriteAttributeString('P03HumanEvents', '[]');
        $this->WriteAttributeString('P03PendingCrossings', '[]');
        $this->WriteAttributeBoolean('Streaming', false);
        $this->WriteAttributeBoolean('AuthPending', false);
        $this->WriteAttributeBoolean('LastRequestAuthenticated', false);
        $this->WriteAttributeBoolean('AuthBlocked', false);
        $this->WriteAttributeInteger('AuthFailureCount', 0);
        $this->WriteAttributeInteger('DigestNC', 0);
        $this->WriteAttributeString('DigestChallenge', '{}');
        $this->setStreamOK(false);
        $this->setReady(false);
        $this->refreshProductionState();

        $this->WriteAttributeInteger('SourceInstanceID', 0);
        $this->syncParentSocket();
        $this->updateParentSubscription($this->getParentID());
        $this->discoverP03AuxSources(false);
        $this->subscribeP03AuxVariables();
        $this->refreshP03CameraStatus();

        if (!$this->credentialsReady()) {
            $this->setResult('NICHT BEREIT – P03 Dahua-Benutzername/Passwort direkt im P03-Modul eintragen.');
            return;
        }

        $this->setResult('Installiert. P03 arbeitet vollständig unabhängig von der Außenlichtautomatik.');
        if ($this->ReadPropertyBoolean('Enabled') && $this->ReadPropertyBoolean('TerraceDiagnosticsEnabled')) {
            $this->scheduleSocketRestart(500);
        }
    }

    public function GetConfigurationForm(): string
    {
        $raw = @file_get_contents(__DIR__ . '/form.json');
        $form = json_decode((string) $raw, true);
        if (!is_array($form)) {
            return (string) $raw;
        }

        $jvHost = trim($this->ReadPropertyString('AuxJVHost'));
        $workHost = trim($this->ReadPropertyString('AuxWorkHost'));
        $sourceCaption = 'P03 Primär: JV_LEFT ' . ($jvHost !== '' ? $jvHost : '<IP fehlt>')
            . ' ↔ WORK_LEFT ' . ($workHost !== '' ? $workHost : '<IP fehlt>')
            . ' – Terrasse nur optional';

        $result = '';
        $resultID = $this->GetIDForIdent('Result');
        if ($resultID > 0 && IPS_VariableExists($resultID)) {
            $result = (string) GetValue($resultID);
        }
        if ($result === '') {
            $result = 'Noch kein Test gestartet.';
        }

        $live = [
            ['type' => 'Label', 'caption' => $sourceCaption],
            ['type' => 'Label', 'caption' => 'Aktueller Teststatus: ' . $result]
        ];
        array_splice($form['elements'], 1, 0, $live);
        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function GetConfigurationForParent(): string
    {
        $cfg = $this->cameraConfiguration();
        return json_encode([
            'Host' => $cfg['host'] ?? '',
            'Port' => $cfg['port'] ?? 80,
            'Open' => $this->ReadPropertyBoolean('Enabled')
                && $this->ReadPropertyBoolean('TerraceDiagnosticsEnabled')
                && ($cfg['host'] ?? '') !== ''
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
            $this->setStreamOK(true);
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
            $this->appendProtocol('HTTP: ungültiger Antwortkopf');
            return '';
        }
        $status = (int) $m[1];
        if ($status === 401) {
            $challenge = $this->extractDigestChallenge($header);
            if ($challenge === []) {
                $this->appendProtocol('HTTP 401 ohne Digest-Challenge');
                return '';
            }
            $this->WriteAttributeString('DigestChallenge', json_encode($challenge));
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
            $this->setResult('FEHLER – Dahua-Digest-Anmeldung wurde abgewiesen.');
            return '';
        }

        if ($status === 200) {
            $this->WriteAttributeBoolean('Streaming', true);
            $this->WriteAttributeBoolean('AuthPending', false);
            $this->WriteAttributeBoolean('AuthBlocked', false);
            $this->WriteAttributeInteger('AuthFailureCount', 0);
            $this->setStreamOK(true);
            $this->refreshProductionState();
            $this->appendProtocol('Dahua Eventstream verbunden (codes=[All])');
            $this->refreshReadyState();
            if ($body !== '') {
                $this->processEventData($body);
            }
            return '';
        }

        $this->appendProtocol('HTTP: unerwarteter Status ' . $status);
        return '';
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ((int) $Message === self::VM_UPDATE_ID) {
            $sender = (int) $SenderID;
            $jvVar = $this->ReadAttributeInteger('AuxJVPersonVarID');
            $workVar = $this->ReadAttributeInteger('AuxWorkPersonVarID');
            $jvCounter = $this->ReadAttributeInteger('AuxJVEventCounterVarID');
            $workCounter = $this->ReadAttributeInteger('AuxWorkEventCounterVarID');
            $jvStreamVar = $this->ReadAttributeInteger('AuxJVStreamVarID');
            $workStreamVar = $this->ReadAttributeInteger('AuxWorkStreamVarID');

            if ($sender === $jvStreamVar || $sender === $workStreamVar) {
                $this->refreshProductionState();
                $this->refreshReadyState();
                return;
            }

            // Internal proof uses the monotonic counter, not the visible Boolean.
            // Every IVS Human event increments it, even while PersonDetected is
            // already TRUE from the previous 5-second pulse.
            if ($sender === $jvCounter || $sender === $workCounter) {
                $changed = true;
                if (is_array($Data) && array_key_exists(1, $Data)) {
                    $changed = (bool) $Data[1];
                }
                if ($changed) {
                    $source = ($sender === $jvCounter)
                        ? P03ProofEngine::SRC_JV_LEFT
                        : P03ProofEngine::SRC_WORK_LEFT;
                    $this->recordP03HumanEvent($source, [
                        'sender' => $sender,
                        'counter' => GetValue($sender),
                        'messageTimestamp' => (int) $TimeStamp
                    ]);
                }
                return;
            }

            // PersonDetected remains subscribed only as a visible health/status
            // signal. It must never create a second proof beside the counter.
            if ($sender === $jvVar || $sender === $workVar) {
                return;
            }
        }

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
            $this->setStreamOK(false);
            $this->SetBuffer('HttpBuffer', '');
            $this->SetBuffer('EventCarry', '');
            if (!$this->ReadAttributeBoolean('AuthBlocked')) {
                $this->SetTimerInterval('HandshakeTimer', 250);
            }
        } else {
            $this->WriteAttributeBoolean('Streaming', false);
            $this->setStreamOK(false);
            $this->setReady(false);
        }
    }

    public function HandshakeTimer(): void
    {
        $this->SetTimerInterval('HandshakeTimer', 0);
        if (!$this->ReadPropertyBoolean('Enabled')
            || !$this->ReadPropertyBoolean('TerraceDiagnosticsEnabled')
            || !$this->cameraConfigurationReady()
            || $this->ReadAttributeBoolean('AuthBlocked')) {
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
            return;
        }

        $stage = $this->ReadAttributeInteger('SocketRestartStage');
        if ($stage === 1) {
            try {
                IPS_SetProperty($parentID, 'Open', false);
                IPS_ApplyChanges($parentID);
            } catch (Throwable $e) {
                $this->WriteAttributeInteger('SocketRestartStage', 0);
                $this->appendProtocol('Socket schließen fehlgeschlagen: ' . $e->getMessage());
                return;
            }
            $this->WriteAttributeInteger('SocketRestartStage', 2);
            $this->SetTimerInterval('SocketRestartTimer', 300);
            return;
        }

        if ($stage === 2) {
            $this->WriteAttributeInteger('SocketRestartStage', 0);
            if (!$this->ReadPropertyBoolean('Enabled')
            || !$this->ReadPropertyBoolean('TerraceDiagnosticsEnabled')
            || !$this->cameraConfigurationReady()
            || $this->ReadAttributeBoolean('AuthBlocked')) {
                return;
            }
            $cfg = $this->cameraConfiguration();
            try {
                IPS_SetProperty($parentID, 'Host', (string) $cfg['host']);
                IPS_SetProperty($parentID, 'Port', (int) $cfg['port']);
                IPS_SetProperty($parentID, 'Open', true);
                IPS_ApplyChanges($parentID);
            } catch (Throwable $e) {
                $this->appendProtocol('Socket öffnen fehlgeschlagen: ' . $e->getMessage());
                return;
            }
            $this->WriteAttributeInteger('LastSocketRestart', time());
            $this->SetTimerInterval('HandshakeTimer', 1000);
        }
    }

    public function Watchdog(): void
    {
        if (!$this->ReadPropertyBoolean('Enabled')
            || !$this->ReadPropertyBoolean('TerraceDiagnosticsEnabled')
            || !$this->cameraConfigurationReady()
            || $this->ReadAttributeBoolean('AuthBlocked')) {
            return;
        }
        $parentID = $this->getParentID();
        if ($parentID <= 0 || !IPS_InstanceExists($parentID)) {
            return;
        }

        $now = time();
        $status = (int) IPS_GetInstance($parentID)['InstanceStatus'];
        if ($status !== 102) {
            $this->WriteAttributeBoolean('Streaming', false);
            $this->setStreamOK(false);
            $this->setReady(false);
            if ($this->ReadAttributeInteger('SocketRestartStage') === 0
                && ($now - $this->ReadAttributeInteger('LastSocketRestart')) >= 20) {
                $this->scheduleSocketRestart(100);
            }
            return;
        }

        $lastRx = $this->ReadAttributeInteger('LastCameraRx');
        if ($this->ReadAttributeBoolean('Streaming') && $lastRx > 0 && ($now - $lastRx) > 25) {
            $this->appendProtocol('Watchdog: kein Dahua-Heartbeat >25 s, Stream wird neu aufgebaut');
            $this->WriteAttributeBoolean('Streaming', false);
            $this->setStreamOK(false);
            $this->setReady(false);
            $this->WriteAttributeBoolean('AuthPending', false);
            $this->WriteAttributeBoolean('LastRequestAuthenticated', false);
            $this->WriteAttributeInteger('DigestNC', 0);
            $this->WriteAttributeString('DigestChallenge', '{}');
            $this->scheduleSocketRestart(100);
        }
    }

    public function Reconnect(): void
    {
        $this->WriteAttributeBoolean('Streaming', false);
        $this->setStreamOK(false);
        $this->setReady(false);
        $this->WriteAttributeBoolean('AuthPending', false);
        $this->WriteAttributeBoolean('AuthBlocked', false);
        $this->WriteAttributeBoolean('LastRequestAuthenticated', false);
        $this->WriteAttributeInteger('AuthFailureCount', 0);
        $this->WriteAttributeInteger('DigestNC', 0);
        $this->WriteAttributeString('DigestChallenge', '{}');
        $this->WriteAttributeInteger('LastCameraRx', 0);
        $this->SetBuffer('HttpBuffer', '');
        $this->SetBuffer('EventCarry', '');
        $this->scheduleSocketRestart(100);
        $this->setResult('Eventstream wird neu aufgebaut …');
    }

    public function AuditCameraConfiguration(): void
    {
        $this->WriteAttributeBoolean('AuditOnlyNextRun', true);
        try {
            $this->PrepareAndStartTest();
        } finally {
            $this->WriteAttributeBoolean('AuditOnlyNextRun', false);
        }
    }

    public function PrepareAndStartTest(): void
    {
        $auditOnly = $this->ReadAttributeBoolean('AuditOnlyNextRun');
        if ($auditOnly) {
            // Audit must not erase already verified field evidence.
            $this->WriteAttributeString('SeenEventKeys', '{}');
            $this->WriteAttributeString('P03HumanEvents', '[]');
            $this->WriteAttributeString('P03PendingCrossings', '[]');
            $this->WriteAttributeBoolean('TestActive', false);
            $this->SetTimerInterval('P03ProofTimer', 0);
            $this->SetValue('TestActive', false);
            $this->SetValue('CrossingCount', 0);
            $this->SetValue('LastEvent', '');
            $this->SetValue('Protocol', '');
            $this->setReady(false);
        } else {
            $this->ResetTest();
        }

        // Terrace is diagnostics-only. Keep its socket closed unless the optional
        // diagnostic switch is explicitly enabled.
        $this->syncParentSocket();
        $this->updateParentSubscription($this->getParentID());

        $username = trim($this->ReadPropertyString('Username'));
        $password = $this->ReadPropertyString('Password');
        $jvHost = trim($this->ReadPropertyString('AuxJVHost'));
        $workHost = trim($this->ReadPropertyString('AuxWorkHost'));
        if ($username === '' || $password === '' || $jvHost === '' || $workHost === '') {
            $this->setResult('FEHLER – P03 Dahua-Zugangsdaten oder eine der beiden Mastkamera-IP-Adressen fehlen.');
            return;
        }

        $this->appendProtocol('=== P03 MASTKAMERA HOME-LAGER TEST ===');
        $this->appendProtocol('Primärsensoren: JV_LEFT=' . $jvHost . ' | WORK_LEFT=' . $workHost . '.');
        $this->appendProtocol('Richtung: JV_LEFT→WORK_LEFT = HOME→LAGER; WORK_LEFT→JV_LEFT = LAGER→HOME.');
        $this->appendProtocol('JV Terrasse/CrossLine ist optional und wird für Audit/Test/Produktion nicht benötigt.');
        $this->appendProtocol('Keine Zugangsdaten werden im Protokoll ausgegeben.');

        // v0.6.8 and earlier could have created/changed terrace IVS test config.
        // Restore only those changes that P03 itself recorded as owned. Never
        // create or modify terrace IVS as part of the new mast-camera workflow.
        $legacyCleanup = $this->restoreLegacyTerraceP03Config();
        if (!($legacyCleanup['ok'] ?? false)) {
            $this->setResult(
                'FEHLER – alte P03-Terrassen-Testkonfiguration konnte nicht sicher zurückgesetzt werden: '
                . (string) ($legacyCleanup['error'] ?? 'unbekannt')
            );
            return;
        }

        $auxDiscovery = $this->discoverP03AuxSources(true);
        $this->subscribeP03AuxVariables();
        $this->refreshP03CameraStatus();
        if (!($auxDiscovery['ok'] ?? false)) {
            $this->setResult(
                'FEHLER – P03 Mastkamera-Observer nicht vollständig verfügbar. '
                . 'Beide Observer benötigen PersonDetected, HumanEventCounter und StreamOK.'
            );
            $this->appendProtocol('P03 PREFLIGHT FEHLER: Mastkamera-Observer/Counter/Streamvariablen fehlen.');
            return;
        }

        $auxAudit = $this->auditP03AuxCameras();
        if (!($auxAudit['ok'] ?? false)) {
            $this->setResult('FEHLER – P03 Mastkamera-Audit: ' . (string) ($auxAudit['error'] ?? 'unbekannt'));
            return;
        }

        $simulation = $this->runP03SimulationInternal();
        $simOK = (bool) ($simulation['ok'] ?? false);
        $this->WriteAttributeBoolean('P03SimulationPassed', $simOK);
        $this->appendProtocol(
            'P03 2-KAMERA-SIMULATION: ' . ($simOK ? 'PASS' : 'FAIL')
                . ' – ' . implode(' | ', $simulation['details'] ?? [])
        );
        if (!$simOK) {
            $this->setResult('FEHLER – interne P03-2-Kamera-Simulation fehlgeschlagen. Kein Lauftest.');
            return;
        }

        if ($auditOnly) {
            $this->WriteAttributeBoolean('TestActive', false);
            $this->SetValue('TestActive', false);
            $this->setReady(false);
            $this->setResult(
                'P03 MASTKAMERA-AUDIT OK – beide Human-IVS-Regeln + Observer + 2-Kamera-Simulation geprüft. '
                . 'Terrasse wurde nicht als Voraussetzung verwendet.'
            );
            $this->appendProtocol(
                'AUDIT-ONLY: Mastkameras strukturell OK. HumanEventCounter muss im kurzen Einzeltest real hochzählen; '
                . 'Terrasse/CrossLine bleibt Diagnose.'
            );
            return;
        }

        $this->WriteAttributeString('P03HumanEvents', '[]');
        $this->WriteAttributeString('P03PendingCrossings', '[]');
        $this->SetTimerInterval('P03ProofTimer', 0);
        $this->WriteAttributeBoolean('TestActive', true);
        $this->SetValue('TestActive', true);

        $this->appendProtocol(
            'TESTFOLGE: 1 HOME→LAGER, 2 LAGER→HOME, 3 HOME→LAGER, 4 LAGER→HOME. '
            . 'Nur die Reihenfolge der zwei Mastkamera-Human-Ereignisse zählt.'
        );
        $this->setResult('P03 MASTTEST vorbereitet – warte auf beide P03-Mastkamera-Eventstreams …');
        $this->refreshReadyState();
    }

    /** @return array{ok:bool,error:string} */
    private function restoreLegacyTerraceP03Config(): array
    {
        $errors = [];
        $changed = false;

        if ($this->ReadAttributeBoolean('Rpc2ModuleChangedByModule')) {
            $changed = true;
            $r = $this->restoreOriginalVideoAnalyseModuleViaRpc2();
            if (!($r['ok'] ?? false)) {
                $errors[] = 'VideoAnalyseModule: ' . (string) ($r['error'] ?? 'Restore fehlgeschlagen');
            }
        }

        if ($this->ReadAttributeBoolean('Rpc2GlobalChangedByModule')) {
            $changed = true;
            $r = $this->restoreOriginalVideoAnalyseGlobalViaRpc2();
            if (!($r['ok'] ?? false)) {
                $errors[] = 'VideoAnalyseGlobal RPC2: ' . (string) ($r['error'] ?? 'Restore fehlgeschlagen');
            }
        }

        if ($this->ReadAttributeBoolean('GlobalChangedByModule')) {
            $changed = true;
            $this->restoreGlobalSceneType();
            if ($this->ReadAttributeBoolean('GlobalChangedByModule')) {
                $errors[] = 'Scene.Type: Restore nicht bestätigt';
            }
        }

        if ($this->ReadAttributeBoolean('Rpc2RuleCreatedByModule')) {
            $changed = true;
            $r = $this->restoreOriginalVideoAnalyseRuleViaRpc2();
            if (!($r['ok'] ?? false)) {
                $errors[] = 'VideoAnalyseRule: ' . (string) ($r['error'] ?? 'Restore fehlgeschlagen');
            }
        }

        if ($errors !== []) {
            $this->appendProtocol('LEGACY TERRASSE RESTORE FEHLER: ' . implode(' | ', $errors));
            return ['ok' => false, 'error' => implode(' | ', $errors)];
        }

        if ($changed) {
            $this->appendProtocol(
                'LEGACY TERRASSE: frühere P03-Teständerungen vollständig auf gesicherten Ausgangszustand zurückgesetzt.'
            );
        } else {
            $this->appendProtocol('LEGACY TERRASSE: keine von P03 zu restaurierenden Altänderungen vorhanden.');
        }

        return ['ok' => true, 'error' => ''];
    }

    private function rollbackPreparedP03Config(string $reason): void
    {
        $this->WriteAttributeBoolean('TestActive', false);
        $this->SetValue('TestActive', false);
        $this->setReady(false);

        if ($this->ReadAttributeBoolean('Rpc2RuleCreatedByModule')) {
            $r = $this->restoreOriginalVideoAnalyseRuleViaRpc2();
            $this->appendProtocol('ROLLBACK P03 Rule: ' . (($r['ok'] ?? false) ? 'OK' : 'FEHLER ' . (string) ($r['error'] ?? '')));
        }
        if ($this->ReadAttributeBoolean('Rpc2ModuleChangedByModule')) {
            $r = $this->restoreOriginalVideoAnalyseModuleViaRpc2();
            $this->appendProtocol('ROLLBACK P03 Sensitivity: ' . (($r['ok'] ?? false) ? 'OK' : 'FEHLER ' . (string) ($r['error'] ?? '')));
        }
        if ($this->ReadAttributeBoolean('GlobalChangedByModule')) {
            $this->restoreGlobalSceneType();
            $this->appendProtocol('ROLLBACK P03 Scene.Type: ' . ($this->ReadAttributeBoolean('GlobalChangedByModule') ? 'FEHLER' : 'OK'));
        }

        $this->appendProtocol('SICHERHEITSROLLBACK: ' . $reason);
    }

    public function EnableProductionP03(): void
    {
        $counts = json_decode($this->ReadAttributeString('P03ActionCounts'), true);
        if (!is_array($counts)) {
            $counts = [];
        }
        $out = (int) ($counts[P03ProofEngine::ACTION_HOME_TO_LAGER] ?? 0);
        $in = (int) ($counts[P03ProofEngine::ACTION_LAGER_TO_HOME] ?? 0);

        $ready = $this->ReadAttributeBoolean('P03MultiAuditPassed')
            && $this->ReadAttributeBoolean('P03SimulationPassed')
            && $out >= 2
            && $in >= 2
            && $this->ReadAttributeInteger('P03VerifiedCount') >= 4
            && $this->ReadAttributeInteger('AuxJVEventCounterVarID') > 0
            && $this->ReadAttributeInteger('AuxWorkEventCounterVarID') > 0
            && $this->p03AuxStreamsReady();

        if (!$ready) {
            $this->WriteAttributeBoolean('ProductionEnabled', false);
            $this->refreshProductionState();
            $this->setResult('P03 Produktivbetrieb gesperrt – Gesamtaudit, Simulation, 2× HOME→LAGER, 2× LAGER→HOME und beide laufenden P03-Mastkamera-Eventstreams sind erforderlich. Terrasse/CrossLine ist nur Zusatzdiagnose.');
            return;
        }

        $this->WriteAttributeBoolean('TestActive', false);
        $this->SetValue('TestActive', false);
        $this->WriteAttributeBoolean('ProductionEnabled', true);
        $this->subscribeP03AuxVariables();
        $this->refreshProductionState();
        $this->setResult('P03 PRODUKTIV – 2-Kamera-Sequenz aktiv. JV_LEFT→WORK_LEFT=OUT, WORK_LEFT→JV_LEFT=IN.');
        $this->appendProtocol('PRODUKTION: P03 2-Kamera-Sequenz aktiviert; Terrasse/CrossLine nur Zusatzdiagnose.');
    }

    public function DisableProductionP03(): void
    {
        $this->WriteAttributeBoolean('ProductionEnabled', false);
        $this->CleanupCameraTestConfig();
        $this->refreshProductionState();
        $this->appendProtocol('PRODUKTION: P03 deaktiviert und Testkonfiguration zurückgesetzt.');
    }

    public function ResetTest(): void
    {
        $this->WriteAttributeString('SeenEventKeys', '{}');
        $this->WriteAttributeString('Crossings', '[]');
        $this->WriteAttributeString('P03HumanEvents', '[]');
        $this->WriteAttributeString('P03PendingCrossings', '[]');
        $this->WriteAttributeString('P03DirectionMap', '{}');
        $this->WriteAttributeInteger('P03VerifiedCount', 0);
        $this->WriteAttributeString('P03ActionCounts', '{"HOME_TO_LAGER":0,"LAGER_TO_HOME":0}');
        $this->WriteAttributeBoolean('P03MultiAuditPassed', false);
        $this->WriteAttributeBoolean('P03SimulationPassed', false);
        $this->WriteAttributeBoolean('TestActive', false);
        $this->SetTimerInterval('P03ProofTimer', 0);
        $this->SetValue('TestActive', false);
        $this->SetValue('CrossingCount', 0);
        $this->SetValue('P03VerifiedTransfers', 0);
        $this->SetValue('P03ProofState', 'RESET');
        $this->SetValue('P03LastProof', '');
        $this->SetValue('P03DirectionStatus', 'noch nicht gelernt');
        $this->SetValue('LastEvent', '');
        $this->SetValue('Protocol', '');
        $this->setReady(false);
        $this->setResult('Zurückgesetzt. „P03 Test vorbereiten & starten“ drücken.');
    }

    public function CleanupCameraTestConfig(): void
    {
        if ($this->ReadAttributeBoolean('Rpc2ModuleChangedByModule')) {
            $restoreModule = $this->restoreOriginalVideoAnalyseModuleViaRpc2();
            $this->appendProtocol(($restoreModule['ok'] ?? false)
                ? 'P03 Sensitivity-Teständerung entfernt; ursprüngliche VideoAnalyseModule-Tabelle vollständig wiederhergestellt.'
                : 'WARNUNG: ursprüngliche VideoAnalyseModule-Tabelle konnte nicht automatisch wiederhergestellt werden: ' . (string) ($restoreModule['error'] ?? 'unbekannt'));
        }
        // Ein expliziter Cleanup bedeutet auch: kein produktiver P03-Betrieb.
        $this->WriteAttributeBoolean('ProductionEnabled', false);
        if ($this->ReadAttributeBoolean('Rpc2GlobalChangedByModule')) {
            $restoreGlobal = $this->restoreOriginalVideoAnalyseGlobalViaRpc2();
            $this->appendProtocol(($restoreGlobal['ok'] ?? false)
                ? 'IVS-Smart-Plan entfernt; ursprüngliche VideoAnalyseGlobal-Tabelle vollständig wiederhergestellt.'
                : 'WARNUNG: ursprüngliche VideoAnalyseGlobal-Tabelle konnte nicht automatisch wiederhergestellt werden: ' . (string) ($restoreGlobal['error'] ?? 'unbekannt'));
        }
        if ($this->ReadAttributeBoolean('Rpc2RuleCreatedByModule')) {
            $restore = $this->restoreOriginalVideoAnalyseRuleViaRpc2();
            $this->appendProtocol(($restore['ok'] ?? false)
                ? 'P03-Testregel entfernt; ursprüngliche VideoAnalyseRule-Tabelle vollständig wiederhergestellt.'
                : 'WARNUNG: ursprüngliche VideoAnalyseRule-Tabelle konnte nicht automatisch wiederhergestellt werden: ' . (string) ($restore['error'] ?? 'unbekannt'));
        } else {
            $this->appendProtocol('Keine vom Testmodul erzeugte IVS-Regel vorhanden; bestehende Kamera-Regeln bleiben unverändert.');
        }
        $this->restoreGlobalSceneType();

        $jvRestore = $this->restoreP03AuxHumanRule(
            $this->ReadAttributeInteger('AuxJVInstanceID'),
            self::AUX_JV_ROLE
        );
        $workRestore = $this->restoreP03AuxHumanRule(
            $this->ReadAttributeInteger('AuxWorkInstanceID'),
            self::AUX_WORK_ROLE
        );
        $this->appendProtocol(($jvRestore['ok'] ?? false)
            ? 'P03 JV_LEFT Human-IVS sauber zurückgesetzt.'
            : 'WARNUNG: ' . (string) ($jvRestore['error'] ?? 'JV_LEFT Restore fehlgeschlagen'));
        $this->appendProtocol(($workRestore['ok'] ?? false)
            ? 'P03 WORK_LEFT Human-IVS sauber zurückgesetzt.'
            : 'WARNUNG: ' . (string) ($workRestore['error'] ?? 'WORK_LEFT Restore fehlgeschlagen'));

        $this->WriteAttributeBoolean('TestActive', false);
        $this->SetTimerInterval('P03ProofTimer', 0);
        $this->WriteAttributeString('P03PendingCrossings', '[]');
        $this->WriteAttributeString('P03HumanEvents', '[]');
        $this->SetValue('TestActive', false);
        $this->setReady(false);
        $this->setResult('Test beendet. Vom Modul erzeugte Testkonfiguration wurde soweit vorhanden zurückgesetzt.');
    }

    public function DumpState(): void
    {
        $state = [
            'sourceInstanceID' => $this->ReadAttributeInteger('SourceInstanceID'),
            'parentID' => $this->getParentID(),
            'streaming' => $this->ReadAttributeBoolean('Streaming'),
            'lastCameraRx' => $this->ReadAttributeInteger('LastCameraRx'),
            'ruleIndex' => $this->ReadAttributeInteger('RuleIndex'),
            'ruleID' => $this->ReadAttributeInteger('RuleID'),
            'ruleCreatedByModule' => $this->ReadAttributeBoolean('RuleCreatedByModule'),
            'globalChangedByModule' => $this->ReadAttributeBoolean('GlobalChangedByModule'),
            'rpc2GlobalChangedByModule' => $this->ReadAttributeBoolean('Rpc2GlobalChangedByModule'),
            'testActive' => $this->ReadAttributeBoolean('TestActive'),
            'auxJVInstanceID' => $this->ReadAttributeInteger('AuxJVInstanceID'),
            'auxWorkInstanceID' => $this->ReadAttributeInteger('AuxWorkInstanceID'),
            'auxJVPersonVarID' => $this->ReadAttributeInteger('AuxJVPersonVarID'),
            'auxWorkPersonVarID' => $this->ReadAttributeInteger('AuxWorkPersonVarID'),
            'auxJVEventCounterVarID' => $this->ReadAttributeInteger('AuxJVEventCounterVarID'),
            'auxWorkEventCounterVarID' => $this->ReadAttributeInteger('AuxWorkEventCounterVarID'),
            'auxJVStreamVarID' => $this->ReadAttributeInteger('AuxJVStreamVarID'),
            'auxWorkStreamVarID' => $this->ReadAttributeInteger('AuxWorkStreamVarID'),
            'terraceDiagnosticsEnabled' => $this->ReadPropertyBoolean('TerraceDiagnosticsEnabled'),
            'p03HumanEvents' => $this->getP03HumanEvents(),
            'p03PendingCrossings' => $this->getP03PendingCrossings(),
            'p03DirectionMap' => json_decode($this->ReadAttributeString('P03DirectionMap'), true),
            'p03VerifiedCount' => $this->ReadAttributeInteger('P03VerifiedCount'),
            'p03ActionCounts' => json_decode($this->ReadAttributeString('P03ActionCounts'), true),
            'p03SimulationPassed' => $this->ReadAttributeBoolean('P03SimulationPassed'),
            'p03MultiAuditPassed' => $this->ReadAttributeBoolean('P03MultiAuditPassed'),
            'crossings' => $this->getCrossings()
        ];
        $this->SendDebug('GateTestState', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
    }

    /** @param array<string,mixed> $event */
    private function matchesP03TerraceRuleEvent(array $event): bool
    {
        if (strcasecmp((string) ($event['code'] ?? ''), 'CrossLineDetection') !== 0) {
            return false;
        }

        $name = trim((string) ($event['ruleName'] ?? ''));
        if ($name !== '') {
            return strcasecmp($name, self::RULE_NAME) === 0;
        }

        $accepted = [];
        foreach ([$this->ReadAttributeInteger('RuleID'), $this->ReadAttributeInteger('RuleIndex')] as $candidate) {
            if ($candidate >= 0) {
                $accepted[(string) $candidate] = true;
            }
        }
        foreach (['cfgRuleId', 'ruleIdUpper', 'ruleIdLower', 'ruleId'] as $field) {
            $value = $event[$field] ?? null;
            if ($value !== null && $value !== '' && isset($accepted[(string) $value])) {
                return true;
            }
        }
        return false;
    }

    private function processEventData(string $chunk): void
    {
        $carry = $this->GetBuffer('EventCarry');
        $events = JVP03JVP03DahuaEventParser::feed($chunk, $carry);
        $this->SetBuffer('EventCarry', $carry);

        foreach ($events as $event) {
            $code = (string) ($event['code'] ?? '');
            $action = strtolower(trim((string) ($event['action'] ?? '')));

            if (strcasecmp($code, 'CrossLineDetection') !== 0) {
                if (strcasecmp($code, 'SmartMotionHuman') === 0
                    && in_array($action, ['start', 'on', 'pulse'], true)
                    && ($this->ReadAttributeBoolean('TestActive') || $this->ReadAttributeBoolean('ProductionEnabled'))) {
                    $this->recordP03HumanEvent(P03ProofEngine::SRC_TERRACE, [
                        'eventId' => $event['eventId'] ?? null,
                        'objectId' => $event['objectId'] ?? null,
                        'classification' => $event['classification'] ?? null
                    ]);
                }

                if ($this->ReadAttributeBoolean('TestActive')
                    && in_array($action, ['start', 'on', 'pulse'], true)
                    && in_array(strtolower($code), [
                        'smartmotionhuman',
                        'videomotion',
                        'smartmotionvehicle',
                        'crossregiondetection'
                    ], true)) {
                    $diag = 'DIAG EVENT code=' . $code . ' action=' . (string) ($event['action'] ?? '') . ' index=' . (string) ($event['index'] ?? '');
                    if (strcasecmp($code, 'SmartMotionHuman') === 0) {
                        $rect = $this->findFirstRectRecursive(is_array($event['data'] ?? null) ? $event['data'] : []);
                        if ($rect !== null) {
                            $diag .= ' Rect=' . json_encode($rect, JSON_UNESCAPED_SLASHES);
                        }
                    }
                    $this->appendProtocol($diag);
                }
                continue;
            }

            // Rule name is authoritative. Dahua may emit CfgRuleId, RuleID
            // and RuleId with different values; comparing one of them blindly to
            // the table index caused the old false rejects.
            if (!$this->matchesP03TerraceRuleEvent($event)) {
                if ($this->ReadAttributeBoolean('TestActive')) {
                    $this->appendProtocol(
                        'DIAG IVS ignoriert: fremde CrossLine '
                        . json_encode([
                            'name' => $event['ruleName'] ?? null,
                            'cfgRuleId' => $event['cfgRuleId'] ?? null,
                            'RuleID' => $event['ruleIdUpper'] ?? null,
                            'RuleId' => $event['ruleIdLower'] ?? null,
                            'expectedName' => self::RULE_NAME,
                            'expectedIndex' => $this->ReadAttributeInteger('RuleIndex'),
                            'expectedId' => $this->ReadAttributeInteger('RuleID')
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    );
                }
                continue;
            }

            $direction = GateTestLogic::directionFromEvent($event);
            $summary = [
                'time' => date('H:i:s'),
                'code' => $event['code'],
                'action' => $event['action'],
                'index' => $event['index'],
                'human' => $event['human'],
                'classification' => $event['classification'] ?? null,
                'direction' => $direction,
                'eventId' => $event['eventId'],
                'ruleName' => $event['ruleName'] ?? null,
                'cfgRuleId' => $event['cfgRuleId'] ?? null,
                'ruleIdUpper' => $event['ruleIdUpper'] ?? null,
                'ruleIdLower' => $event['ruleIdLower'] ?? null,
                'ruleId' => $event['ruleId'],
                'groupId' => $event['groupId'],
                'objectId' => $event['objectId']
            ];
            $this->SetValue('LastEvent', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->appendProtocol('IVS ' . json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->SendDebug(
                'CrossLineDetection',
                json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . ' | RAW=' . $this->singleLine((string) $event['raw']),
                0
            );

            if (!in_array($action, ['start', 'on', 'pulse'], true)) {
                continue;
            }
            if (!$this->ReadAttributeBoolean('TestActive') && !$this->ReadAttributeBoolean('ProductionEnabled')) {
                continue;
            }

            $key = $this->eventKey($event, $direction);
            $seen = json_decode($this->ReadAttributeString('SeenEventKeys'), true);
            if (!is_array($seen)) {
                $seen = [];
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = time();
            if (count($seen) > 200) {
                $seen = array_slice($seen, -200, null, true);
            }
            $this->WriteAttributeString('SeenEventKeys', json_encode($seen));

            $count = (int) $this->GetValue('CrossingCount') + 1;
            $this->SetValue('CrossingCount', $count);
            $this->recordP03Crossing($event, $direction);
        }
    }

    public function P03ProofTimer(): void
    {
        // Only the ordered mast-camera pair may commit a transfer.
        $this->evaluateP03MastSequence();
    }

    public function RunP03Simulation(): void
    {
        $result = $this->runP03SimulationInternal();
        $this->WriteAttributeBoolean('P03SimulationPassed', (bool) ($result['ok'] ?? false));
        $this->appendProtocol(
            'P03 SIMULATION: ' . (($result['ok'] ?? false) ? 'PASS' : 'FAIL')
                . ' – ' . implode(' | ', $result['details'] ?? [])
        );
        $this->SetValue('P03ProofState', ($result['ok'] ?? false) ? 'SIMULATION PASS' : 'SIMULATION FAIL');
        $this->setResult(($result['ok'] ?? false)
            ? 'P03 interne Simulation bestanden.'
            : 'P03 interne Simulation FEHLER – nicht testen.');
    }

    /** @return array{ok:bool,details:array<int,string>} */
    private function runP03SimulationInternal(): array
    {
        $details = [];
        $ok = true;
        $base = 1000.0;

        $cases = [
            [
                'name' => '2CAM OUT order',
                'events' => [
                    ['id' => 'j1', 'source' => P03ProofEngine::SRC_JV_LEFT, 'ts' => $base - 8],
                    ['id' => 'w1', 'source' => P03ProofEngine::SRC_WORK_LEFT, 'ts' => $base]
                ],
                'now' => $base + 3,
                'state' => P03ProofEngine::STATE_VERIFIED,
                'action' => P03ProofEngine::ACTION_HOME_TO_LAGER
            ],
            [
                'name' => '2CAM IN order',
                'events' => [
                    ['id' => 'w1', 'source' => P03ProofEngine::SRC_WORK_LEFT, 'ts' => $base - 8],
                    ['id' => 'j1', 'source' => P03ProofEngine::SRC_JV_LEFT, 'ts' => $base]
                ],
                'now' => $base + 3,
                'state' => P03ProofEngine::STATE_VERIFIED,
                'action' => P03ProofEngine::ACTION_LAGER_TO_HOME
            ],
            [
                'name' => 'single camera no transfer',
                'events' => [
                    ['id' => 'j1', 'source' => P03ProofEngine::SRC_JV_LEFT, 'ts' => $base]
                ],
                'now' => $base + 10,
                'state' => P03ProofEngine::STATE_UNKNOWN,
                'action' => null
            ],
            [
                'name' => 'same camera twice no transfer',
                'events' => [
                    ['id' => 'j1', 'source' => P03ProofEngine::SRC_JV_LEFT, 'ts' => $base - 5],
                    ['id' => 'j2', 'source' => P03ProofEngine::SRC_JV_LEFT, 'ts' => $base]
                ],
                'now' => $base + 10,
                'state' => P03ProofEngine::STATE_UNKNOWN,
                'action' => null
            ],
            [
                'name' => 'over time limit rejected',
                'events' => [
                    ['id' => 'j1', 'source' => P03ProofEngine::SRC_JV_LEFT, 'ts' => $base - 31],
                    ['id' => 'w1', 'source' => P03ProofEngine::SRC_WORK_LEFT, 'ts' => $base]
                ],
                'now' => $base + 10,
                'state' => P03ProofEngine::STATE_UNKNOWN,
                'action' => null
            ],
            [
                'name' => 'settle delay pending',
                'events' => [
                    ['id' => 'j1', 'source' => P03ProofEngine::SRC_JV_LEFT, 'ts' => $base - 5],
                    ['id' => 'w1', 'source' => P03ProofEngine::SRC_WORK_LEFT, 'ts' => $base]
                ],
                'now' => $base + 1,
                'state' => P03ProofEngine::STATE_PENDING,
                'action' => null
            ],
            [
                'name' => 'quick reversal contradiction',
                'events' => [
                    ['id' => 'j1', 'source' => P03ProofEngine::SRC_JV_LEFT, 'ts' => $base - 4],
                    ['id' => 'w1', 'source' => P03ProofEngine::SRC_WORK_LEFT, 'ts' => $base - 2],
                    ['id' => 'j2', 'source' => P03ProofEngine::SRC_JV_LEFT, 'ts' => $base]
                ],
                'now' => $base + 1,
                'state' => P03ProofEngine::STATE_CONTRADICTION,
                'action' => null
            ],
            [
                'name' => 'consumed evidence ignored',
                'events' => [
                    ['id' => 'j1', 'source' => P03ProofEngine::SRC_JV_LEFT, 'ts' => $base - 5, 'used' => true],
                    ['id' => 'w1', 'source' => P03ProofEngine::SRC_WORK_LEFT, 'ts' => $base]
                ],
                'now' => $base + 5,
                'state' => P03ProofEngine::STATE_UNKNOWN,
                'action' => null
            ]
        ];

        foreach ($cases as $case) {
            $result = P03ProofEngine::evaluateTwoCameraSequence(
                $case['events'],
                (float) $case['now'],
                30.0,
                2.0
            );
            $pass = ($result['state'] ?? null) === $case['state']
                && ($result['action'] ?? null) === $case['action'];
            $details[] = $case['name'] . '=' . ($pass ? 'OK' : 'FAIL');
            $ok = $ok && $pass;
        }

        // Terrace/CrossLine must never substitute one of the two mast cameras.
        $cross = P03ProofEngine::evaluate(
            ['ts' => $base, 'direction' => 'RightToLeft'],
            [
                ['id' => 't1', 'source' => P03ProofEngine::SRC_TERRACE, 'ts' => $base - 5],
                ['id' => 'w1', 'source' => P03ProofEngine::SRC_WORK_LEFT, 'ts' => $base + 5]
            ],
            $base + 61,
            30.0,
            60.0
        );
        $crossPass = ($cross['action'] ?? null) === null
            && !in_array((string) ($cross['state'] ?? ''), [P03ProofEngine::STATE_VERIFIED, P03ProofEngine::STATE_STRONG_VERIFIED], true);
        $details[] = 'terrace cannot replace mast camera=' . ($crossPass ? 'OK' : 'FAIL');
        $ok = $ok && $crossPass;

        // With both mast cameras, CrossLine is optional strengthening only.
        $cross = P03ProofEngine::evaluate(
            ['ts' => $base, 'direction' => 'RightToLeft'],
            [
                ['id' => 'j1', 'source' => P03ProofEngine::SRC_JV_LEFT, 'ts' => $base - 5],
                ['id' => 'w1', 'source' => P03ProofEngine::SRC_WORK_LEFT, 'ts' => $base + 5]
            ],
            $base + 6,
            30.0,
            60.0
        );
        $crossPass = ($cross['state'] ?? null) === P03ProofEngine::STATE_STRONG_VERIFIED
            && ($cross['action'] ?? null) === P03ProofEngine::ACTION_HOME_TO_LAGER;
        $details[] = 'crossline only strengthens mast pair=' . ($crossPass ? 'OK' : 'FAIL');
        $ok = $ok && $crossPass;

        return ['ok' => $ok, 'details' => $details];
    }

    /** @param array<string,mixed> $meta */
    private function recordP03HumanEvent(string $source, array $meta = []): void
    {
        if (!$this->ReadAttributeBoolean('TestActive') && !$this->ReadAttributeBoolean('ProductionEnabled')) {
            return;
        }

        $events = $this->getP03HumanEvents();
        $now = microtime(true);
        $externalId = trim((string) ($meta['eventId'] ?? ''));
        if ($externalId !== '') {
            $id = $source . ':event:' . $externalId;
            foreach ($events as $event) {
                if (($event['id'] ?? '') === $id) {
                    return;
                }
            }
        } else {
            $id = $source . ':' . sprintf('%.6f', $now);
            // Boolean PersonDetected can occasionally be re-published. Collapse
            // same-source STARTs arriving within one second.
            for ($i = count($events) - 1; $i >= 0; $i--) {
                if (($events[$i]['source'] ?? '') !== $source) {
                    continue;
                }
                if (($now - (float) ($events[$i]['ts'] ?? 0.0)) < 1.0) {
                    return;
                }
                break;
            }
        }

        $events[] = [
            'id' => $id,
            'source' => $source,
            'ts' => $now,
            'used' => false,
            'meta' => $meta
        ];

        $cutoff = $now - 180.0;
        $events = array_values(array_filter($events, static fn($e) => (float) ($e['ts'] ?? 0.0) >= $cutoff));
        if (count($events) > 120) {
            $events = array_slice($events, -120);
        }
        $this->setP03HumanEvents($events);

        $this->appendProtocol('P03 HUMAN ' . $source . ' @' . sprintf('%.3f', $now));
        $this->evaluateP03MastSequence();
    }

    /** @param array<string,mixed> $event */
    private function recordP03Crossing(array $event, ?string $direction): void
    {
        // Terrace CrossLine is diagnostics-only. It may never enter the pending
        // proof queue, consume mast events or book a HOME/LAGER transfer.
        $this->appendProtocol(
            'P03 TERRASSE DIAG CrossLine Direction=' . ($direction ?? '<fehlt>')
                . ' EventID=' . (string) ($event['eventId'] ?? '<fehlt>')
                . ' – kein Einfluss auf 2-Kamera-Beweis'
        );
    }

    public function P03AuxRescan(): void
    {
        $result = $this->discoverP03AuxSources(true);
        $this->subscribeP03AuxVariables();
        $audit = $this->auditP03AuxCameras();
        $this->refreshP03CameraStatus();
        $this->setResult(($result['ok'] ?? false) && ($audit['ok'] ?? false)
            ? 'P03 Zusatzkameras gefunden und geprüft.'
            : 'P03 Zusatzkameras noch nicht vollständig bereit – Protokoll prüfen.');
    }

    private function evaluateP03Pending(): void
    {
        // Migration safety only: old v0.6.x CrossLine pending entries must never
        // commit after upgrading to the mast-only architecture.
        if ($this->getP03PendingCrossings() !== []) {
            $this->WriteAttributeString('P03PendingCrossings', '[]');
            $this->appendProtocol('LEGACY CrossLine-Pending verworfen – Terrasse ist nur Diagnose.');
        }
        $this->SetTimerInterval('P03ProofTimer', 0);
    }

    private function evaluateP03MastSequence(): void
    {
        if (!$this->ReadAttributeBoolean('TestActive') && !$this->ReadAttributeBoolean('ProductionEnabled')) {
            return;
        }

        $events = $this->getP03HumanEvents();
        $maxGap = max(5, $this->ReadPropertyInteger('NearProofWindowSeconds'));
        $result = P03ProofEngine::evaluateTwoCameraSequence(
            $events,
            microtime(true),
            (float) $maxGap,
            2.0
        );

        $state = (string) ($result['state'] ?? P03ProofEngine::STATE_UNKNOWN);
        if ($state === P03ProofEngine::STATE_PENDING) {
            $this->SetValue('P03ProofState', 'PENDING – zweite Mastkamera erkannt; 2 s Plausibilitätswartezeit');
            $this->SetTimerInterval('P03ProofTimer', 500);
            return;
        }

        if ($state === P03ProofEngine::STATE_VERIFIED) {
            $proofId = '2cam:' . sprintf('%.6f', microtime(true));
            $pseudoCross = ['id' => $proofId, 'direction' => null, 'ts' => microtime(true)];
            $events = $this->commitP03VerifiedResult($events, $pseudoCross, $result, $proofId);
            $this->setP03HumanEvents($events);
            $this->checkP03CommissioningComplete();
            return;
        }

        if ($state === P03ProofEngine::STATE_CONTRADICTION) {
            $this->SetValue('P03ProofState', 'CONTRADICTION – überlappende Gegenrichtung; kein Übergang');
            $this->appendProtocol('P03 2CAM CONTRADICTION – keine Zonenänderung.');
        }
    }

    /**
     * @param array<int,array<string,mixed>> $events
     * @param array<string,mixed> $cross
     * @param array<string,mixed> $result
     * @return array<int,array<string,mixed>>
     */
    private function commitP03VerifiedResult(array $events, array $cross, array $result, string $proofId): array
    {
        $events = $this->markP03HumanEventsUsed($events, $result['usedEventIds'] ?? [], $proofId);

        $direction = isset($cross['direction']) ? (string) $cross['direction'] : null;
        $action = isset($result['action']) ? (string) $result['action'] : null;
        if ($direction !== null && $direction !== '') {
            $map = json_decode($this->ReadAttributeString('P03DirectionMap'), true);
            if (!is_array($map)) {
                $map = [];
            }
            $map = P03ProofEngine::learnDirection($map, $direction, $action);
            $this->WriteAttributeString('P03DirectionMap', json_encode($map));
        }

        $counts = json_decode($this->ReadAttributeString('P03ActionCounts'), true);
        if (!is_array($counts)) {
            $counts = [];
        }
        if (in_array($action, [P03ProofEngine::ACTION_HOME_TO_LAGER, P03ProofEngine::ACTION_LAGER_TO_HOME], true)) {
            $counts[$action] = (int) ($counts[$action] ?? 0) + 1;
            $this->WriteAttributeString('P03ActionCounts', json_encode($counts));
        }

        $verified = $this->ReadAttributeInteger('P03VerifiedCount') + 1;
        $this->WriteAttributeInteger('P03VerifiedCount', $verified);
        $this->SetValue('P03VerifiedTransfers', $verified);

        $proofText = $this->formatP03Proof($cross, $result);
        $this->SetValue('P03ProofState', (string) ($result['state'] ?? P03ProofEngine::STATE_VERIFIED));
        $this->SetValue('P03LastProof', $proofText);
        $this->appendProtocol('P03 PROOF ' . (string) ($result['state'] ?? '') . ' ' . $proofText);
        $this->onP03VerifiedTransfer($cross, $result);
        $this->refreshP03DirectionStatus();
        return $events;
    }

    private function checkP03CommissioningComplete(): void
    {
        if (!$this->ReadAttributeBoolean('TestActive')) {
            return;
        }

        $counts = json_decode($this->ReadAttributeString('P03ActionCounts'), true);
        if (!is_array($counts)) {
            $counts = [];
        }
        $out = (int) ($counts[P03ProofEngine::ACTION_HOME_TO_LAGER] ?? 0);
        $in = (int) ($counts[P03ProofEngine::ACTION_LAGER_TO_HOME] ?? 0);

        if ($out >= 2 && $in >= 2 && $this->ReadAttributeInteger('P03VerifiedCount') >= 4) {
            $this->WriteAttributeBoolean('TestActive', false);
            $this->SetValue('TestActive', false);
            $this->setReady(false);
            $this->setResult('P03 2-KAMERA-SEQUENZ VERIFIZIERT – 2× HOME→LAGER und 2× LAGER→HOME durch Reihenfolge JV_LEFT/WORK_LEFT bestätigt.');
            $this->appendProtocol('P03 COMMISSIONING COMPLETE: OUT=' . $out . ', IN=' . $in . ' – ausschließlich Mastkamera-Reihenfolge.');
        }
    }

    /** @param array<int,array<string,mixed>> $events */
    private function markP03HumanEventsUsed(array $events, array $ids, string $crossId): array
    {
        $wanted = array_fill_keys(array_map('strval', $ids), true);
        foreach ($events as &$event) {
            $id = (string) ($event['id'] ?? '');
            if ($id !== '' && isset($wanted[$id])) {
                $event['used'] = true;
                $event['usedBy'] = $crossId;
            }
        }
        unset($event);
        return $events;
    }

    /** @return array<int,array<string,mixed>> */
    private function getP03HumanEvents(): array
    {
        $decoded = json_decode($this->ReadAttributeString('P03HumanEvents'), true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @param array<int,array<string,mixed>> $events */
    private function setP03HumanEvents(array $events): void
    {
        $this->WriteAttributeString(
            'P03HumanEvents',
            json_encode(array_values($events), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function getP03PendingCrossings(): array
    {
        $decoded = json_decode($this->ReadAttributeString('P03PendingCrossings'), true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @param array<int,array<string,mixed>> $crossings */
    private function setP03PendingCrossings(array $crossings): void
    {
        $this->WriteAttributeString(
            'P03PendingCrossings',
            json_encode(array_values($crossings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'
        );
    }

    /** @param array<string,mixed> $cross @param array<string,mixed> $result */
    private function formatP03Proof(array $cross, array $result): string
    {
        $action = (string) ($result['action'] ?? 'NONE');
        $path = (string) ($result['path'] ?? '');
        $direction = (string) ($cross['direction'] ?? '<fehlt>');
        return $action . ' via ' . $path . ($direction !== '' && $direction !== '<fehlt>' ? ' / Zusatz-CrossLine=' . $direction : '');
    }

    /** @param array<string,mixed> $cross @param array<string,mixed> $result */
    private function onP03VerifiedTransfer(array $cross, array $result): void
    {
        $action = (string) ($result['action'] ?? '');
        if ($action === '') {
            return;
        }

        // P03 is an internal HOME <-> LAGER/WORK zone transfer. It must never
        // create or remove property occupants by itself.
        $this->SetValue(
            'PresenceLastEvent',
            date('H:i:s') . ' P03 ' . $action . ' VERIFIED (' . (string) ($result['path'] ?? '') . ')'
        );

        if (!$this->ReadAttributeBoolean('ProductionEnabled')) {
            return;
        }

        $this->WriteAttributeInteger('LastProductionEvent', time());
        $this->appendProtocol(
            'PRESENCE P03 VERIFIED ' . $action
                . ' Direction=' . (string) ($cross['direction'] ?? '')
                . ' – nur Zonenwechsel, keine Occupant-Anzahl geändert.'
        );
        $this->refreshProductionState();
    }

    private function refreshP03DirectionStatus(): void
    {
        $map = json_decode($this->ReadAttributeString('P03DirectionMap'), true);
        if (!is_array($map)) {
            $map = [];
        }
        $status = P03ProofEngine::directionStatus($map);
        if (($status['contradiction'] ?? false) === true) {
            $this->SetValue('P03DirectionStatus', 'WIDERSPRUCH – Richtungslernen gesperrt');
            return;
        }

        $resolved = $status['resolved'] ?? [];
        if (($status['stable'] ?? false) === true) {
            $this->SetValue(
                'P03DirectionStatus',
                'STABIL: L→R=' . (string) ($resolved['LeftToRight'] ?? '?')
                    . ' / R→L=' . (string) ($resolved['RightToLeft'] ?? '?')
            );
            return;
        }

        $this->SetValue(
            'P03DirectionStatus',
            'LERNEND: ' . json_encode($status['counts'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /** @return array{ok:bool,jv:int,work:int} */
    private function discoverP03AuxSources(bool $allowCreate): array
    {
        $jvHost = trim($this->ReadPropertyString('AuxJVHost'));
        $workHost = trim($this->ReadPropertyString('AuxWorkHost'));

        $jv = $this->findP03AuxObserver($jvHost, self::AUX_JV_ROLE);
        $work = $this->findP03AuxObserver($workHost, self::AUX_WORK_ROLE);

        if ($allowCreate && $this->cameraConfigurationReady()) {
            if ($jv <= 0) {
                $jv = $this->createP03AuxObserver($jvHost, self::AUX_JV_ROLE, 'P03 – Lagerplatz JV links');
            }
            if ($work <= 0) {
                $work = $this->createP03AuxObserver($workHost, self::AUX_WORK_ROLE, 'P03 – Lagerplatz Werkstatt links');
            }
        }

        $this->WriteAttributeInteger('AuxJVInstanceID', $jv);
        $this->WriteAttributeInteger('AuxWorkInstanceID', $work);

        $jvVar = $jv > 0 ? $this->findPersonDetectedVariable($jv) : 0;
        $workVar = $work > 0 ? $this->findPersonDetectedVariable($work) : 0;
        $jvCounter = $jv > 0 ? $this->findAuxVariable($jv, 'HumanEventCounter') : 0;
        $workCounter = $work > 0 ? $this->findAuxVariable($work, 'HumanEventCounter') : 0;
        $jvStream = $jv > 0 ? $this->findAuxVariable($jv, 'StreamOK') : 0;
        $workStream = $work > 0 ? $this->findAuxVariable($work, 'StreamOK') : 0;
        $this->updateP03AuxSubscription('AuxJVPersonVarID', $jvVar);
        $this->updateP03AuxSubscription('AuxWorkPersonVarID', $workVar);
        $this->updateP03AuxSubscription('AuxJVEventCounterVarID', $jvCounter);
        $this->updateP03AuxSubscription('AuxWorkEventCounterVarID', $workCounter);
        $this->updateP03AuxSubscription('AuxJVStreamVarID', $jvStream);
        $this->updateP03AuxSubscription('AuxWorkStreamVarID', $workStream);

        return [
            'ok' => $jvVar > 0 && $workVar > 0
                && $jvCounter > 0 && $workCounter > 0
                && $jvStream > 0 && $workStream > 0,
            'jv' => $jv,
            'work' => $work
        ];
    }

    private function findP03AuxObserver(string $host, string $role): int
    {
        foreach (IPS_GetInstanceListByModuleID(self::AUX_OBSERVER_MODULE_GUID) as $id) {
            $id = (int) $id;
            if ($id <= 0 || !IPS_InstanceExists($id)) {
                continue;
            }
            try {
                if (trim((string) IPS_GetProperty($id, 'CameraHost')) === $host
                    && (string) IPS_GetProperty($id, 'Role') === $role) {
                    return $id;
                }
            } catch (Throwable $e) {
            }
        }
        return 0;
    }

    private function createP03AuxObserver(string $host, string $role, string $name): int
    {
        if ($host === '' || !$this->credentialsReady()) {
            return 0;
        }

        try {
            $id = IPS_CreateInstance(self::AUX_OBSERVER_MODULE_GUID);
            IPS_SetName($id, $name);
            IPS_SetProperty($id, 'Enabled', true);
            IPS_SetProperty($id, 'CameraHost', $host);
            IPS_SetProperty($id, 'CameraPort', max(1, $this->ReadPropertyInteger('CameraPort')));
            IPS_SetProperty($id, 'Username', $this->ReadPropertyString('Username'));
            IPS_SetProperty($id, 'Password', $this->ReadPropertyString('Password'));
            IPS_SetProperty($id, 'Role', $role);
            IPS_SetProperty($id, 'RuleIndex', -1);
            IPS_SetProperty($id, 'RuleID', -1);
            IPS_ApplyChanges($id);
            $this->appendProtocol($role . ': eigener P03-Kameraobserver angelegt (#' . $id . ').');
            return $id;
        } catch (Throwable $e) {
            $this->appendProtocol($role . ': P03-Kameraobserver konnte nicht angelegt werden: ' . $e->getMessage());
            return 0;
        }
    }

    private function findPersonDetectedVariable(int $instanceID): int
    {
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return 0;
        }
        try {
            $id = (int) IPS_GetObjectIDByIdent('PersonDetected', $instanceID);
            return ($id > 0 && IPS_VariableExists($id)) ? $id : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function findAuxVariable(int $instanceID, string $ident): int
    {
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return 0;
        }
        try {
            $id = (int) IPS_GetObjectIDByIdent($ident, $instanceID);
            return ($id > 0 && IPS_VariableExists($id)) ? $id : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function p03AuxStreamsReady(): bool
    {
        foreach (['AuxJVStreamVarID', 'AuxWorkStreamVarID'] as $attr) {
            $id = $this->ReadAttributeInteger($attr);
            if ($id <= 0 || !IPS_VariableExists($id)) {
                return false;
            }
            try {
                if (!(bool) GetValue($id)) {
                    return false;
                }
            } catch (Throwable $e) {
                return false;
            }
        }
        return true;
    }

    private function subscribeP03AuxVariables(): void
    {
        foreach ([
            'AuxJVPersonVarID', 'AuxWorkPersonVarID',
            'AuxJVEventCounterVarID', 'AuxWorkEventCounterVarID',
            'AuxJVStreamVarID', 'AuxWorkStreamVarID'
        ] as $attr) {
            $id = $this->ReadAttributeInteger($attr);
            if ($id > 0 && IPS_VariableExists($id)) {
                try {
                    $this->RegisterMessage($id, self::VM_UPDATE_ID);
                } catch (Throwable $e) {
                }
            }
        }
    }

    private function updateP03AuxSubscription(string $attribute, int $newID): void
    {
        $oldID = $this->ReadAttributeInteger($attribute);
        if ($oldID > 0 && $oldID !== $newID && IPS_VariableExists($oldID)) {
            try {
                $this->UnregisterMessage($oldID, self::VM_UPDATE_ID);
            } catch (Throwable $e) {
            }
        }
        if ($newID > 0 && IPS_VariableExists($newID)) {
            try {
                $this->RegisterMessage($newID, self::VM_UPDATE_ID);
            } catch (Throwable $e) {
            }
        }
        $this->WriteAttributeInteger($attribute, $newID);
    }

    /** @return array{ok:bool,error:string,http:int,body:string} */
    private function genericCameraGet(string $host, int $port, string $username, string $password, string $uri): array
    {
        if ($host === '' || $username === '' || $password === '') {
            return ['ok' => false, 'error' => 'Konfiguration unvollständig', 'http' => 0, 'body' => ''];
        }

        $ch = curl_init('http://' . $host . ':' . $port . $uri);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init fehlgeschlagen', 'http' => 0, 'body' => ''];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_DIGEST,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $http < 200 || $http >= 300) {
            return [
                'ok' => false,
                'error' => $error !== '' ? $error : ('HTTP ' . $http),
                'http' => $http,
                'body' => is_string($body) ? $body : ''
            ];
        }

        return ['ok' => true, 'error' => '', 'http' => $http, 'body' => (string) $body];
    }

    /** @return array<string,string> */
    private function p03AuxHumanRuleMeta(string $role): array
    {
        if ($role === self::AUX_JV_ROLE) {
            return [
                'name' => 'P03_JV_LEFT_HUMAN',
                'ruleIndex' => 'AuxJVHumanRuleIndex',
                'ruleId' => 'AuxJVHumanRuleID',
                'ruleCreated' => 'AuxJVHumanRuleCreatedByModule',
                'ruleBackup' => 'AuxJVOriginalVideoAnalyseRuleRpc2',
                'globalChanged' => 'AuxJVGlobalChangedByModule',
                'globalBackup' => 'AuxJVOriginalVideoAnalyseGlobalRpc2'
            ];
        }

        return [
            'name' => 'P03_WORK_LEFT_HUMAN',
            'ruleIndex' => 'AuxWorkHumanRuleIndex',
            'ruleId' => 'AuxWorkHumanRuleID',
            'ruleCreated' => 'AuxWorkHumanRuleCreatedByModule',
            'ruleBackup' => 'AuxWorkOriginalVideoAnalyseRuleRpc2',
            'globalChanged' => 'AuxWorkGlobalChangedByModule',
            'globalBackup' => 'AuxWorkOriginalVideoAnalyseGlobalRpc2'
        ];
    }

    /** @return array{host:string,port:int,username:string,password:string}|array{} */
    private function p03AuxCameraConfiguration(int $instanceID): array
    {
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return [];
        }

        try {
            return [
                'host' => trim((string) IPS_GetProperty($instanceID, 'CameraHost')),
                'port' => max(1, (int) IPS_GetProperty($instanceID, 'CameraPort')),
                'username' => (string) IPS_GetProperty($instanceID, 'Username'),
                'password' => (string) IPS_GetProperty($instanceID, 'Password')
            ];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function reconnectP03AuxObserver(int $instanceID, string $role): void
    {
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return;
        }

        $meta = $this->p03AuxHumanRuleMeta($role);
        $ruleIndex = $this->ReadAttributeInteger($meta['ruleIndex']);
        $ruleId = $this->ReadAttributeInteger($meta['ruleId']);
        try {
            IPS_SetProperty($instanceID, 'RuleIndex', $ruleIndex);
            IPS_SetProperty($instanceID, 'RuleID', $ruleId);
            IPS_ApplyChanges($instanceID);
            $this->appendProtocol($role . ': eigener P03-Eventstream nach IVS-Änderung neu aufgebaut.');
        } catch (Throwable $e) {
            $this->appendProtocol($role . ': WARNUNG – P03-Eventstream-Neuaufbau fehlgeschlagen: ' . $e->getMessage());
        }
    }

    /**
     * Fetches a CrossRegionDetection template from the target camera itself.
     * No hand-built fallback is allowed: if the firmware does not expose a
     * usable native template, P03 fails closed and writes no IVS rule.
     *
     * @return array{ok:bool,error:string,template?:array,source?:string}
     */
    private function getP03AuxNativeCrossRegionTemplate(
        string $host,
        int $port,
        string $username,
        string $password,
        string $session,
        string $role
    ): array {
        // 1) Documented HTTP API (channel numbering starts at 1).
        $cgi = $this->genericCameraGet(
            $host,
            $port,
            $username,
            $password,
            '/cgi-bin/devVideoAnalyse.cgi?action=getTemplateRule&Class=Normal&Channel=1'
        );
        if ($cgi['ok'] ?? false) {
            $template = P03DahuaTemplate::crossRegionFromFlat((string) ($cgi['body'] ?? ''));
            if ($template !== null) {
                return ['ok' => true, 'error' => '', 'template' => $template, 'source' => 'HTTP getTemplateRule'];
            }
        }

        // 2) Native config default table.
        $default = $this->rpc2Call(
            $host,
            $port,
            $session,
            201,
            'configManager.getDefault',
            ['name' => 'VideoAnalyseRule']
        );
        if ($default['ok'] ?? false) {
            $template = P03DahuaTemplate::findCrossRegion($default['json']['params']['table'] ?? $default['json']);
            if ($template !== null) {
                return ['ok' => true, 'error' => '', 'template' => $template, 'source' => 'RPC2 VideoAnalyseRule default'];
            }
        }

        // 3) Modern Web5 analysis object. Different firmware generations accept
        // either the type string or a minimal rule object.
        $factory = $this->rpc2Call(
            $host,
            $port,
            $session,
            202,
            'devVideoAnalyse.factory.instance',
            ['channel' => 0]
        );
        $object = $factory['json']['result'] ?? null;
        if (($factory['ok'] ?? false) && $object !== null && $object !== false && $object !== '') {
            $shapes = [
                'CrossRegionDetection',
                ['Type' => 'CrossRegionDetection'],
                ['Class' => 'Normal', 'Type' => 'CrossRegionDetection']
            ];
            $id = 203;
            foreach ($shapes as $shape) {
                $result = $this->rpc2CallWithObject(
                    $host,
                    $port,
                    $session,
                    $id++,
                    'devVideoAnalyse.getTemplateRule',
                    ['rule' => $shape],
                    $object
                );
                if (!($result['ok'] ?? false)) {
                    continue;
                }
                $template = P03DahuaTemplate::findCrossRegion($result['json'] ?? null);
                if ($template !== null) {
                    return ['ok' => true, 'error' => '', 'template' => $template, 'source' => 'Web5 getTemplateRule'];
                }
            }
        }

        return [
            'ok' => false,
            'error' => $role . ': Kamera liefert kein sicher auswertbares natives CrossRegionDetection-Template'
        ];
    }

    /**
     * Ensures a P03-owned Human-only CrossRegion rule on an auxiliary mast IPC.
     * Existing foreign IVS rules are never modified. The complete original rule
     * table and, if necessary, the original smart-plan table are kept for cleanup.
     *
     * @return array{ok:bool,error:string,index?:int,id?:int,changed?:bool}
     */
    private function ensureP03AuxHumanRule(int $instanceID, string $role): array
    {
        $cfg = $this->p03AuxCameraConfiguration($instanceID);
        if (($cfg['host'] ?? '') === '' || ($cfg['username'] ?? '') === '' || ($cfg['password'] ?? '') === '') {
            return ['ok' => false, 'error' => $role . ': Kamerakonfiguration unvollständig'];
        }

        $meta = $this->p03AuxHumanRuleMeta($role);
        $login = $this->rpc2Login(
            (string) $cfg['host'],
            (int) $cfg['port'],
            (string) $cfg['username'],
            (string) $cfg['password']
        );
        if (!($login['ok'] ?? false)) {
            return ['ok' => false, 'error' => $role . ': RPC2-Login fehlgeschlagen'];
        }

        $host = (string) $cfg['host'];
        $port = (int) $cfg['port'];
        $session = (string) ($login['session'] ?? '');
        $changed = false;
        $globalChangedThisRun = false;
        $globalOriginalThisRun = null;

        // Fetch the firmware's own CrossRegion rule structure BEFORE changing
        // anything. v0.6.4-v0.6.8 built a guessed rule and could therefore be
        // accepted by setConfig without ever becoming an active IVS detector.
        $native = $this->getP03AuxNativeCrossRegionTemplate(
            $host,
            $port,
            (string) $cfg['username'],
            (string) $cfg['password'],
            $session,
            $role
        );
        if (!($native['ok'] ?? false) || !is_array($native['template'] ?? null)) {
            $this->rpc2Call($host, $port, $session, 299, 'global.logout', null);
            return ['ok' => false, 'error' => (string) ($native['error'] ?? ($role . ': natives IVS-Template fehlt'))];
        }
        $nativeTemplate = $native['template'];
        $nativeSource = (string) ($native['source'] ?? '<unbekannt>');
        $this->appendProtocol($role . ': natives CrossRegion-Template geladen via ' . $nativeSource . '.');

        $globalRead = $this->rpc2Call($host, $port, $session, 210, 'configManager.getConfig', ['name' => 'VideoAnalyseGlobal']);
        $globalTable = $globalRead['json']['params']['table'] ?? null;
        if (!($globalRead['ok'] ?? false) || !is_array($globalTable) || !isset($globalTable[0]['Scene']) || !is_array($globalTable[0]['Scene'])) {
            $this->rpc2Call($host, $port, $session, 299, 'global.logout', null);
            return ['ok' => false, 'error' => $role . ': VideoAnalyseGlobal nicht sicher lesbar'];
        }

        $sceneType = $globalTable[0]['Scene']['Type'] ?? null;
        $normalizedScene = strtolower(trim((string) ($sceneType ?? '')));
        if ($normalizedScene !== '' && $normalizedScene !== '0' && $normalizedScene !== 'normal') {
            $this->rpc2Call($host, $port, $session, 299, 'global.logout', null);
            return ['ok' => false, 'error' => $role . ': anderer AI-Smart-Plan aktiv (' . (string) $sceneType . '), keine Änderung'];
        }

        if ($normalizedScene === '' || $normalizedScene === '0') {
            $globalOriginalThisRun = $globalTable;
            if ($this->ReadAttributeString($meta['globalBackup']) === '') {
                $backup = json_encode($globalTable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($backup) || $backup === '') {
                    $this->rpc2Call($host, $port, $session, 299, 'global.logout', null);
                    return ['ok' => false, 'error' => $role . ': VideoAnalyseGlobal-Backup fehlgeschlagen'];
                }
                $this->WriteAttributeString($meta['globalBackup'], $backup);
            }

            $candidateGlobal = $globalTable;
            $candidateGlobal[0]['Scene']['Type'] = 'Normal';
            $writeGlobal = $this->rpc2Call(
                $host, $port, $session, 211, 'configManager.setConfig',
                ['name' => 'VideoAnalyseGlobal', 'table' => $candidateGlobal, 'options' => []]
            );
            $verifyGlobal = $this->rpc2Call($host, $port, $session, 212, 'configManager.getConfig', ['name' => 'VideoAnalyseGlobal']);
            $verifyType = $verifyGlobal['json']['params']['table'][0]['Scene']['Type'] ?? null;
            if (!($writeGlobal['ok'] ?? false) || !($verifyGlobal['ok'] ?? false) || $verifyType !== 'Normal') {
                if (is_array($globalOriginalThisRun)) {
                    $this->rpc2Call(
                        $host, $port, $session, 213, 'configManager.setConfig',
                        ['name' => 'VideoAnalyseGlobal', 'table' => $globalOriginalThisRun, 'options' => []]
                    );
                }
                $this->rpc2Call($host, $port, $session, 299, 'global.logout', null);
                return ['ok' => false, 'error' => $role . ': Scene.Type=Normal nicht sicher setzbar'];
            }

            $this->WriteAttributeBoolean($meta['globalChanged'], true);
            $globalChangedThisRun = true;
            $changed = true;
            $this->appendProtocol($role . ': IVS-Szene Normal aktiviert und rückgelesen.');
        }

        $read = $this->rpc2Call($host, $port, $session, 220, 'configManager.getConfig', ['name' => 'VideoAnalyseRule']);
        $rules = $read['json']['params']['table'] ?? null;
        if (!($read['ok'] ?? false) || !is_array($rules) || !isset($rules[0]) || !is_array($rules[0])) {
            if ($globalChangedThisRun && is_array($globalOriginalThisRun)) {
                $this->rpc2Call($host, $port, $session, 221, 'configManager.setConfig', ['name' => 'VideoAnalyseGlobal', 'table' => $globalOriginalThisRun, 'options' => []]);
                $this->WriteAttributeBoolean($meta['globalChanged'], false);
            }
            $this->rpc2Call($host, $port, $session, 299, 'global.logout', null);
            return ['ok' => false, 'error' => $role . ': VideoAnalyseRule nicht sicher lesbar'];
        }

        $ownIndex = -1;
        foreach ($rules[0] as $i => $rule) {
            if (!is_array($rule) || strcasecmp((string) ($rule['Name'] ?? ''), $meta['name']) !== 0) {
                continue;
            }
            $ownIndex = (int) $i;
            if (P03AuxHumanRule::matches($rule, $meta['name'])) {
                $ownId = (isset($rule['Id']) && is_numeric($rule['Id'])) ? (int) $rule['Id'] : -1;
                if ($ownId < 0) {
                    $this->rpc2Call($host, $port, $session, 299, 'global.logout', null);
                    return ['ok' => false, 'error' => $role . ': P03 Human-IVS hat keine gültige Dahua Id'];
                }
                $this->WriteAttributeInteger($meta['ruleIndex'], $ownIndex);
                $this->WriteAttributeInteger($meta['ruleId'], $ownId);
                $this->rpc2Call($host, $port, $session, 299, 'global.logout', null);
                $this->reconnectP03AuxObserver($instanceID, $role);
                return ['ok' => true, 'error' => '', 'index' => $ownIndex, 'id' => $ownId, 'actions' => $rule['Config']['Action'] ?? [], 'direction' => $rule['Config']['Direction'] ?? null, 'changed' => $changed];
            }

            if (!$this->ReadAttributeBoolean($meta['ruleCreated'])) {
                $this->rpc2Call($host, $port, $session, 299, 'global.logout', null);
                return ['ok' => false, 'error' => $role . ': Regelname ' . $meta['name'] . ' existiert fremd/abweichend; kein Überschreiben'];
            }
            break;
        }

        if ($this->ReadAttributeString($meta['ruleBackup']) === '') {
            $backup = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($backup) || $backup === '') {
                $this->rpc2Call($host, $port, $session, 299, 'global.logout', null);
                return ['ok' => false, 'error' => $role . ': IVS-Regelbackup fehlgeschlagen'];
            }
            $this->WriteAttributeString($meta['ruleBackup'], $backup);
        }

        $eventHandler = [];
        if ($ownIndex >= 0 && is_array($rules[0][$ownIndex]['EventHandler'] ?? null)) {
            $eventHandler = $rules[0][$ownIndex]['EventHandler'];
        } else {
            foreach ($rules[0] as $rule) {
                if (is_array($rule) && is_array($rule['EventHandler'] ?? null)) {
                    $eventHandler = $rule['EventHandler'];
                    break;
                }
            }
        }
        if ($eventHandler === []) {
            $this->rpc2Call($host, $port, $session, 299, 'global.logout', null);
            return ['ok' => false, 'error' => $role . ': keine sichere EventHandler-Vorlage für IVS-Regel vorhanden'];
        }

        $ids = [];
        foreach ($rules[0] as $rule) {
            if (is_array($rule) && isset($rule['Id']) && is_numeric($rule['Id'])) {
                $ids[(int) $rule['Id']] = true;
            }
        }
        $newId = 0;
        while (isset($ids[$newId])) {
            $newId++;
        }
        if ($ownIndex >= 0 && isset($rules[0][$ownIndex]['Id']) && is_numeric($rules[0][$ownIndex]['Id'])) {
            $newId = (int) $rules[0][$ownIndex]['Id'];
        }

        try {
            $desired = P03AuxHumanRule::buildFromTemplate(
                $meta['name'],
                $newId,
                $nativeTemplate,
                $eventHandler
            );
        } catch (Throwable $e) {
            $this->rpc2Call($host, $port, $session, 299, 'global.logout', null);
            return ['ok' => false, 'error' => $role . ': natives IVS-Template unbrauchbar – ' . $e->getMessage()];
        }
        $candidate = $rules;
        if ($ownIndex >= 0) {
            $candidate[0][$ownIndex] = $desired;
        } else {
            $candidate[0][] = $desired;
            $ownIndex = count($candidate[0]) - 1;
        }

        // Same safe pattern as the verified terrace writer: a no-op full-table
        // write must work before any semantic change is attempted.
        $noop = $this->rpc2Call(
            $host, $port, $session, 230, 'configManager.setConfig',
            ['name' => 'VideoAnalyseRule', 'table' => $rules, 'options' => []]
        );
        if (!($noop['ok'] ?? false)) {
            if ($globalChangedThisRun && is_array($globalOriginalThisRun)) {
                $this->rpc2Call($host, $port, $session, 231, 'configManager.setConfig', ['name' => 'VideoAnalyseGlobal', 'table' => $globalOriginalThisRun, 'options' => []]);
                $this->WriteAttributeBoolean($meta['globalChanged'], false);
            }
            $this->rpc2Call($host, $port, $session, 299, 'global.logout', null);
            return ['ok' => false, 'error' => $role . ': Firmware lehnt sicheren IVS-NO-OP-Write ab'];
        }

        $write = $this->rpc2Call(
            $host, $port, $session, 232, 'configManager.setConfig',
            ['name' => 'VideoAnalyseRule', 'table' => $candidate, 'options' => []]
        );
        $verify = $this->rpc2Call($host, $port, $session, 233, 'configManager.getConfig', ['name' => 'VideoAnalyseRule']);
        $verifiedTable = $verify['json']['params']['table'] ?? null;
        $verifiedIndex = -1;
        $verifiedId = -1;
        $verified = false;
        if (($write['ok'] ?? false) && ($verify['ok'] ?? false) && is_array($verifiedTable) && is_array($verifiedTable[0] ?? null)) {
            foreach ($verifiedTable[0] as $i => $rule) {
                if (is_array($rule) && P03AuxHumanRule::matches($rule, $meta['name'])) {
                    $candidateId = $rule['Id'] ?? null;
                    if ($candidateId !== null && is_numeric($candidateId)) {
                        $verified = true;
                        $verifiedIndex = (int) $i;
                        $verifiedId = (int) $candidateId;
                    }
                    break;
                }
            }
        }

        // Foreign rules must keep their Name/Type/Enable tuple.
        if ($verified) {
            foreach ($rules[0] as $i => $before) {
                if ((int) $i === $ownIndex || !is_array($before)) {
                    continue;
                }
                $after = $verifiedTable[0][$i] ?? null;
                if (!is_array($after)
                    || (string) ($before['Name'] ?? '') !== (string) ($after['Name'] ?? '')
                    || (string) ($before['Type'] ?? '') !== (string) ($after['Type'] ?? '')
                    || (bool) ($before['Enable'] ?? false) !== (bool) ($after['Enable'] ?? false)) {
                    $verified = false;
                    break;
                }
            }
        }

        if (!$verified) {
            $this->rpc2Call(
                $host, $port, $session, 234, 'configManager.setConfig',
                ['name' => 'VideoAnalyseRule', 'table' => $rules, 'options' => []]
            );
            if ($globalChangedThisRun && is_array($globalOriginalThisRun)) {
                $this->rpc2Call(
                    $host, $port, $session, 235, 'configManager.setConfig',
                    ['name' => 'VideoAnalyseGlobal', 'table' => $globalOriginalThisRun, 'options' => []]
                );
                $this->WriteAttributeBoolean($meta['globalChanged'], false);
            }
            $this->rpc2Call($host, $port, $session, 299, 'global.logout', null);
            return ['ok' => false, 'error' => $role . ': Human-IVS-Regel Readback fehlgeschlagen; Rollback ausgeführt'];
        }

        $this->WriteAttributeBoolean($meta['ruleCreated'], true);
        $this->WriteAttributeInteger($meta['ruleIndex'], $verifiedIndex);
        $this->WriteAttributeInteger($meta['ruleId'], $verifiedId);
        $this->rpc2Call($host, $port, $session, 299, 'global.logout', null);
        $this->appendProtocol(
            $role . ': P03 Human-IVS aktiv – ' . $meta['name']
                . ', CrossRegionDetection, ObjectTypes=Human, Index=' . $verifiedIndex . ', Id=' . $verifiedId . ', Region='
                . json_encode(P03AuxHumanRule::REGION, JSON_UNESCAPED_SLASHES)
        );
        $this->reconnectP03AuxObserver($instanceID, $role);
        return ['ok' => true, 'error' => '', 'index' => $verifiedIndex, 'id' => $verifiedId, 'actions' => $verifiedTable[0][$verifiedIndex]['Config']['Action'] ?? [], 'direction' => $verifiedTable[0][$verifiedIndex]['Config']['Direction'] ?? null, 'changed' => true];
    }

    /** @return array{ok:bool,error:string} */
    private function restoreP03AuxHumanRule(int $instanceID, string $role): array
    {
        $meta = $this->p03AuxHumanRuleMeta($role);
        $needRule = $this->ReadAttributeBoolean($meta['ruleCreated']);
        $needGlobal = $this->ReadAttributeBoolean($meta['globalChanged']);
        if (!$needRule && !$needGlobal) {
            return ['ok' => true, 'error' => ''];
        }

        $cfg = $this->p03AuxCameraConfiguration($instanceID);
        if (($cfg['host'] ?? '') === '' || ($cfg['username'] ?? '') === '' || ($cfg['password'] ?? '') === '') {
            return ['ok' => false, 'error' => $role . ': Kamerakonfiguration für Restore fehlt'];
        }

        $login = $this->rpc2Login(
            (string) $cfg['host'],
            (int) $cfg['port'],
            (string) $cfg['username'],
            (string) $cfg['password']
        );
        if (!($login['ok'] ?? false)) {
            return ['ok' => false, 'error' => $role . ': RPC2-Login für Restore fehlgeschlagen'];
        }

        $host = (string) $cfg['host'];
        $port = (int) $cfg['port'];
        $session = (string) ($login['session'] ?? '');
        $errors = [];

        if ($needRule) {
            $ruleBackup = json_decode($this->ReadAttributeString($meta['ruleBackup']), true);
            if (!is_array($ruleBackup)) {
                $errors[] = 'IVS-Regelbackup fehlt';
            } else {
                $write = $this->rpc2Call(
                    $host, $port, $session, 250, 'configManager.setConfig',
                    ['name' => 'VideoAnalyseRule', 'table' => $ruleBackup, 'options' => []]
                );
                $verify = $this->rpc2Call($host, $port, $session, 251, 'configManager.getConfig', ['name' => 'VideoAnalyseRule']);
                $current = $verify['json']['params']['table'] ?? null;
                if (!($write['ok'] ?? false) || !($verify['ok'] ?? false) || !is_array($current) || $current != $ruleBackup) {
                    $errors[] = 'IVS-Regelrestore nicht eindeutig bestätigt';
                } else {
                    $this->WriteAttributeBoolean($meta['ruleCreated'], false);
                    $this->WriteAttributeInteger($meta['ruleIndex'], -1);
                    $this->WriteAttributeInteger($meta['ruleId'], -1);
                    $this->WriteAttributeString($meta['ruleBackup'], '');
                }
            }
        }

        if ($needGlobal) {
            $globalBackup = json_decode($this->ReadAttributeString($meta['globalBackup']), true);
            if (!is_array($globalBackup)) {
                $errors[] = 'VideoAnalyseGlobal-Backup fehlt';
            } else {
                $write = $this->rpc2Call(
                    $host, $port, $session, 252, 'configManager.setConfig',
                    ['name' => 'VideoAnalyseGlobal', 'table' => $globalBackup, 'options' => []]
                );
                $verify = $this->rpc2Call($host, $port, $session, 253, 'configManager.getConfig', ['name' => 'VideoAnalyseGlobal']);
                $current = $verify['json']['params']['table'] ?? null;
                if (!($write['ok'] ?? false) || !($verify['ok'] ?? false) || !is_array($current) || $current != $globalBackup) {
                    $errors[] = 'VideoAnalyseGlobal-Restore nicht eindeutig bestätigt';
                } else {
                    $this->WriteAttributeBoolean($meta['globalChanged'], false);
                    $this->WriteAttributeString($meta['globalBackup'], '');
                }
            }
        }

        $this->rpc2Call($host, $port, $session, 299, 'global.logout', null);
        if ($errors !== []) {
            return ['ok' => false, 'error' => $role . ': ' . implode('; ', $errors)];
        }

        $this->reconnectP03AuxObserver($instanceID, $role);
        return ['ok' => true, 'error' => ''];
    }

    /** @return array{ok:bool,error:string,model:string,firmware:string,sensitivity:string} */
    private function auditP03AuxCamera(int $instanceID, string $role): array
    {
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return ['ok' => false, 'error' => $role . ': Instanz fehlt', 'model' => '', 'firmware' => '', 'sensitivity' => ''];
        }

        $cfg = $this->p03AuxCameraConfiguration($instanceID);
        if (($cfg['host'] ?? '') === '') {
            return ['ok' => false, 'error' => $role . ': Kamerakonfiguration nicht lesbar', 'model' => '', 'firmware' => '', 'sensitivity' => ''];
        }

        $host = (string) $cfg['host'];
        $port = (int) $cfg['port'];
        $username = (string) $cfg['username'];
        $password = (string) $cfg['password'];

        $type = $this->genericCameraGet($host, $port, $username, $password, '/cgi-bin/magicBox.cgi?action=getDeviceType');
        $model = '';
        if ($type['ok'] ?? false) {
            if (preg_match('/(?:^|\r?\n)type=([^\r\n]+)/i', (string) $type['body'], $m)) {
                $model = trim((string) $m[1]);
            }
        }

        $version = $this->genericCameraGet($host, $port, $username, $password, '/cgi-bin/magicBox.cgi?action=getSoftwareVersion');
        $firmware = '';
        if ($version['ok'] ?? false) {
            if (preg_match('/(?:^|\r?\n)version=([^\r\n]+)/i', (string) $version['body'], $m)) {
                $firmware = trim((string) $m[1]);
            }
        }

        // SMD is kept as diagnostic/fallback only. Field testing on both
        // IPC-HFW5442E-ZE cameras showed VideoMotion/VideoMotionInfo without
        // SmartMotionHuman, despite SMD=Human. P03 therefore no longer trusts
        // the SMD configuration as proof that a Human event will be emitted.
        $enable = null;
        $human = null;
        $sensitivity = '';
        $smart = $this->genericCameraGet($host, $port, $username, $password, '/cgi-bin/configManager.cgi?action=getConfig&name=SmartMotionDetect');
        if ($smart['ok'] ?? false) {
            $raw = (string) $smart['body'];
            $enable = GateTestLogic::configValue($raw, 'SmartMotionDetect[0].Enable');
            $human = GateTestLogic::configValue($raw, 'SmartMotionDetect[0].ObjectTypes.Human');
            if ($human === null) {
                $object0 = GateTestLogic::configValue($raw, 'SmartMotionDetect[0].ObjectTypes[0]');
                $human = (strcasecmp((string) $object0, 'Human') === 0) ? 'true' : null;
            }
            $sensitivity = (string) (GateTestLogic::configValue($raw, 'SmartMotionDetect[0].Sensitivity') ?? '');
        }

        $ivs = $this->ensureP03AuxHumanRule($instanceID, $role);
        $personVar = $this->findPersonDetectedVariable($instanceID);
        $counterVar = $this->findAuxVariable($instanceID, 'HumanEventCounter');
        $streamVar = $this->findAuxVariable($instanceID, 'StreamOK');
        $ok = ($ivs['ok'] ?? false) && $personVar > 0 && $counterVar > 0 && $streamVar > 0;

        $this->appendProtocol(
            'P03 AUX AUDIT ' . $role
                . ': host=' . $host
                . ', model=' . ($model !== '' ? $model : '<nicht gemeldet>')
                . ', firmware=' . ($firmware !== '' ? $firmware : '<nicht gemeldet>')
                . ', SMD=' . ($enable === null ? '<nur Diagnose/nicht gemeldet>' : (string) $enable)
                . ', SMD-Human=' . ($human === null ? '<nur Diagnose/nicht gemeldet>' : (string) $human)
                . ', Sensitivity=' . ($sensitivity !== '' ? $sensitivity : '<nicht gemeldet>')
                . ', IVS-Human=' . (($ivs['ok'] ?? false) ? 'OK' : 'FEHLER')
                . ', IVS-RuleIndex=' . (string) ($ivs['index'] ?? -1)
                . ', IVS-RuleID=' . (string) ($ivs['id'] ?? -1)
                . ', IVS-Actions=' . json_encode($ivs['actions'] ?? null, JSON_UNESCAPED_SLASHES)
                . ', IVS-Direction=' . (string) ($ivs['direction'] ?? '<fehlt>')
                . ', PersonVar=' . $personVar
                . ', CounterVar=' . $counterVar
                . ', StreamVar=' . $streamVar
                . ' -> ' . ($ok ? 'OK' : 'FEHLER')
        );

        return [
            'ok' => $ok,
            'error' => $ok
                ? ''
                : ($role . ': ' . (string) ($ivs['error'] ?? 'Human-IVS/Observervariablen nicht vollständig bereit')),
            'model' => $model,
            'firmware' => $firmware,
            'sensitivity' => $sensitivity
        ];
    }

    /** @return array{ok:bool,error:string} */
    private function auditP03AuxCameras(): array
    {
        $jv = $this->ReadAttributeInteger('AuxJVInstanceID');
        $work = $this->ReadAttributeInteger('AuxWorkInstanceID');
        $jvAudit = $this->auditP03AuxCamera($jv, self::AUX_JV_ROLE);
        $workAudit = $this->auditP03AuxCamera($work, self::AUX_WORK_ROLE);

        $this->WriteAttributeString('AuxJVModel', (string) ($jvAudit['model'] ?? ''));
        $this->WriteAttributeString('AuxWorkModel', (string) ($workAudit['model'] ?? ''));
        $this->WriteAttributeString('AuxJVFirmware', (string) ($jvAudit['firmware'] ?? ''));
        $this->WriteAttributeString('AuxWorkFirmware', (string) ($workAudit['firmware'] ?? ''));
        $this->WriteAttributeString('AuxJVSensitivity', (string) ($jvAudit['sensitivity'] ?? ''));
        $this->WriteAttributeString('AuxWorkSensitivity', (string) ($workAudit['sensitivity'] ?? ''));

        $ok = ($jvAudit['ok'] ?? false) && ($workAudit['ok'] ?? false);
        $this->WriteAttributeBoolean('P03MultiAuditPassed', $ok);
        $this->refreshP03CameraStatus();

        return [
            'ok' => $ok,
            'error' => $ok ? '' : trim(
                (string) ($jvAudit['error'] ?? '') . ' | ' . (string) ($workAudit['error'] ?? ''),
                ' |'
            )
        ];
    }

    private function refreshP03CameraStatus(): void
    {
        $jv = $this->ReadAttributeInteger('AuxJVInstanceID');
        $work = $this->ReadAttributeInteger('AuxWorkInstanceID');
        $jvCounter = $this->ReadAttributeInteger('AuxJVEventCounterVarID');
        $workCounter = $this->ReadAttributeInteger('AuxWorkEventCounterVarID');
        $jvStream = $this->ReadAttributeInteger('AuxJVStreamVarID');
        $workStream = $this->ReadAttributeInteger('AuxWorkStreamVarID');

        $streamState = static function (int $id): string {
            if ($id <= 0 || !IPS_VariableExists($id)) {
                return 'STREAM FEHLT';
            }
            try {
                return (bool) GetValue($id) ? 'STREAM OK' : 'STREAM AUS';
            } catch (Throwable $e) {
                return 'STREAM ?';
            }
        };

        $terrace = $this->ReadPropertyBoolean('TerraceDiagnosticsEnabled')
            ? 'Diagnose EIN'
            : 'Diagnose AUS';

        $this->SetValue(
            'P03CameraStatus',
            'JV-links=' . ($jvCounter > 0 ? 'P03#' . $jv . ' Counter#' . $jvCounter : 'FEHLT')
                . ' ' . $streamState($jvStream)
                . ($this->ReadAttributeString('AuxJVModel') !== '' ? ' ' . $this->ReadAttributeString('AuxJVModel') : '')
                . ' | Werkstatt-links=' . ($workCounter > 0 ? 'P03#' . $work . ' Counter#' . $workCounter : 'FEHLT')
                . ' ' . $streamState($workStream)
                . ($this->ReadAttributeString('AuxWorkModel') !== '' ? ' ' . $this->ReadAttributeString('AuxWorkModel') : '')
                . ' | Terrasse=' . $terrace
        );
    }

    /** @param array<string|int,mixed> $node */
    private function findFirstRectRecursive(array $node): ?array
    {
        foreach ($node as $key => $value) {
            if (strcasecmp((string) $key, 'Rect') === 0 && is_array($value) && count($value) >= 4) {
                return array_values(array_slice($value, 0, 4));
            }
            if (is_array($value)) {
                $found = $this->findFirstRectRecursive($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function refreshProductionState(): void
    {
        $enabled = $this->ReadAttributeBoolean('ProductionEnabled');
        if (!$enabled) {
            $this->SetValue('PresenceSystemState', 'P03 INAKTIV / TEST');
            $this->SetValue('HouseStatus', 'P03 ist nur Zonenportal');
            $this->SetValue('PresentPersons', 'Personenzahl wird durch P03 nicht verändert');
            return;
        }

        $auxReady = $this->ReadAttributeInteger('AuxJVEventCounterVarID') > 0
            && $this->ReadAttributeInteger('AuxWorkEventCounterVarID') > 0
            && $this->p03AuxStreamsReady();
        $proofReady = $this->ReadAttributeBoolean('P03MultiAuditPassed')
            && $this->ReadAttributeBoolean('P03SimulationPassed');

        $state = ($auxReady && $proofReady)
            ? 'P03 PRODUKTIV – 2-Kamera-Sequenz JV_LEFT ↔ WORK_LEFT'
            : 'P03 DEGRADED – kein Übergang ohne beide Mastkamera-Eventstreams';

        $this->SetValue('PresenceSystemState', $state);
        $this->SetValue('HouseStatus', 'unverändert – P03 verschiebt nur HOME↔WORK');
        $this->SetValue('PresentPersons', 'unverändert durch P03');
    }

    private function beginHandshake(): void
    {
        if ($this->ReadAttributeBoolean('AuthBlocked')) {
            return;
        }
        $this->WriteAttributeBoolean('Streaming', false);
        $this->setStreamOK(false);
        $this->SetBuffer('HttpBuffer', '');
        $this->SetBuffer('EventCarry', '');
        $this->sendEventRequest($this->ReadAttributeBoolean('AuthPending'));
    }

    private function sendEventRequest(bool $authenticated): void
    {
        $cfg = $this->cameraConfiguration();
        $uri = '/cgi-bin/eventManager.cgi?action=attach&codes=[All]&heartbeat=5';
        $host = (string) ($cfg['host'] ?? '');
        $port = (int) ($cfg['port'] ?? 80);
        if ($host === '') {
            return;
        }
        $hostHeader = $port === 80 ? $host : ($host . ':' . $port);
        $headers = [
            'GET ' . $uri . ' HTTP/1.1',
            'Host: ' . $hostHeader,
            'User-Agent: IP-Symcon-JVPresenceGateTest/0.1.0',
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
                $headers[] = 'Authorization: ' . JVP03JVP03DahuaDigest::buildAuthorization(
                    (string) ($cfg['username'] ?? ''),
                    (string) ($cfg['password'] ?? ''),
                    'GET',
                    $uri,
                    $challenge,
                    $nc,
                    $cnonce
                );
            } catch (Throwable $e) {
                $this->appendProtocol('Digest-Fehler: ' . $e->getMessage());
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
        } catch (Throwable $e) {
            $this->appendProtocol('Eventstream-Anfrage fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private function scheduleSocketRestart(int $delayMs = 100): void
    {
        if (!$this->ReadPropertyBoolean('Enabled')
            || !$this->ReadPropertyBoolean('TerraceDiagnosticsEnabled')
            || !$this->cameraConfigurationReady()) {
            return;
        }
        if ($this->ReadAttributeInteger('SocketRestartStage') !== 0) {
            return;
        }
        $this->WriteAttributeInteger('SocketRestartStage', 1);
        $this->SetTimerInterval('SocketRestartTimer', max(50, $delayMs));
    }

    /** @return array<string,string> */
    private function extractDigestChallenge(string $header): array
    {
        if (!preg_match('/^WWW-Authenticate:\s*(Digest\s+.+)$/im', $header, $m)) {
            return [];
        }
        return JVP03JVP03DahuaDigest::parseChallenge(trim((string) $m[1]));
    }

    /** @return array{host:string,port:int,username:string,password:string}|array{} */
    private function cameraConfiguration(): array
    {
        return [
            'host' => trim($this->ReadPropertyString('TerraceHost')),
            'port' => max(1, $this->ReadPropertyInteger('CameraPort')),
            'username' => $this->ReadPropertyString('Username'),
            'password' => $this->ReadPropertyString('Password')
        ];
    }

    private function credentialsReady(): bool
    {
        return trim($this->ReadPropertyString('Username')) !== ''
            && $this->ReadPropertyString('Password') !== '';
    }

    private function cameraConfigurationReady(): bool
    {
        $cfg = $this->cameraConfiguration();
        return $this->credentialsReady() && ($cfg['host'] ?? '') !== '';
    }

    /** @return array{ok:bool,body:string,error:string,http:int} */
    private function cameraGet(string $uri): array
    {
        $cfg = $this->cameraConfiguration();
        if (($cfg['host'] ?? '') === '') {
            return ['ok' => false, 'body' => '', 'error' => 'keine Kamera', 'http' => 0];
        }
        $scheme = 'http';
        $url = $scheme . '://' . $cfg['host'] . ':' . $cfg['port'] . $uri;
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'body' => '', 'error' => 'curl_init fehlgeschlagen', 'http' => 0];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_DIGEST,
            CURLOPT_USERPWD => $cfg['username'] . ':' . $cfg['password'],
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $http < 200 || $http >= 300) {
            return ['ok' => false, 'body' => is_string($body) ? $body : '', 'error' => $error !== '' ? $error : ('HTTP ' . $http), 'http' => $http];
        }
        return ['ok' => true, 'body' => (string) $body, 'error' => '', 'http' => $http];
    }

    /** @return array{ok:bool,error:string,index?:int} */
    private function createP03TripwireViaRpc2(): array
    {
        $cfg = $this->cameraConfiguration();
        if (($cfg['host'] ?? '') === '' || ($cfg['username'] ?? '') === '' || ($cfg['password'] ?? '') === '') {
            return ['ok' => false, 'error' => 'Kamerakonfiguration fehlt'];
        }

        $login = $this->rpc2Login(
            (string) $cfg['host'],
            (int) $cfg['port'],
            (string) $cfg['username'],
            (string) $cfg['password']
        );
        if (!($login['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'RPC2-Login fehlgeschlagen: ' . (string) ($login['error'] ?? '')];
        }

        $host = (string) $cfg['host'];
        $port = (int) $cfg['port'];
        $session = (string) ($login['session'] ?? '');

        $read = $this->rpc2Call($host, $port, $session, 10, 'configManager.getConfig', ['name' => 'VideoAnalyseRule']);
        if (!($read['ok'] ?? false) || !is_array($read['json']['params']['table'] ?? null)) {
            $this->rpc2Call($host, $port, $session, 99, 'global.logout', null);
            return ['ok' => false, 'error' => 'aktuelle VideoAnalyseRule-Tabelle konnte nicht gelesen werden'];
        }

        $original = $read['json']['params']['table'];
        if (!isset($original[0]) || !is_array($original[0])) {
            $this->rpc2Call($host, $port, $session, 99, 'global.logout', null);
            return ['ok' => false, 'error' => 'unerwartete VideoAnalyseRule-Struktur'];
        }

        foreach ($original[0] as $i => $rule) {
            if (is_array($rule)
                && strcasecmp((string) ($rule['Type'] ?? ''), 'CrossLineDetection') === 0
                && strcasecmp((string) ($rule['Name'] ?? ''), self::RULE_NAME) === 0) {
                $existingId = (isset($rule['Id']) && is_numeric($rule['Id'])) ? (int) $rule['Id'] : -1;
                $this->rpc2Call($host, $port, $session, 99, 'global.logout', null);
                return ['ok' => true, 'error' => '', 'index' => (int) $i, 'id' => $existingId];
            }
        }

        $backup = json_encode($original, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($backup) || $backup === '') {
            $this->rpc2Call($host, $port, $session, 99, 'global.logout', null);
            return ['ok' => false, 'error' => 'Backup der IVS-Tabelle konnte nicht erzeugt werden'];
        }
        $this->WriteAttributeString('OriginalVideoAnalyseRuleRpc2', $backup);
        $this->WriteAttributeBoolean('Rpc2RuleCreatedByModule', false);

        $ids = [];
        foreach ($original[0] as $rule) {
            if (is_array($rule) && isset($rule['Id']) && is_numeric($rule['Id'])) {
                $ids[(int) $rule['Id']] = true;
            }
        }
        $newId = 0;
        while (isset($ids[$newId])) {
            $newId++;
        }

        $eventHandler = [];
        foreach ($original[0] as $rule) {
            if (is_array($rule) && is_array($rule['EventHandler'] ?? null)) {
                $eventHandler = $rule['EventHandler'];
                break;
            }
        }
        if ($eventHandler === []) {
            $this->rpc2Call($host, $port, $session, 99, 'global.logout', null);
            return ['ok' => false, 'error' => 'keine EventHandler-Vorlage in der Kamera gefunden'];
        }

        // Für den Presence-Test keinerlei Alarm-/Aufzeichnungs-Nebenwirkung.
        foreach ([
            'AlarmOutEnable','BeepEnable','ExAlarmOutEnable','LogEnable','MMSEnable',
            'MailEnable','MatrixEnable','MessageEnable','PtzLinkEnable','RecordEnable',
            'SnapshotEnable','TipEnable','TourEnable','VoiceEnable'
        ] as $flag) {
            if (array_key_exists($flag, $eventHandler)) {
                $eventHandler[$flag] = false;
            }
        }
        if (isset($eventHandler['TrigerHttp']) && is_array($eventHandler['TrigerHttp'])) {
            $eventHandler['TrigerHttp']['TrigerHttpEnable'] = false;
            $eventHandler['TrigerHttp']['TrigerHttpCommand'] = '';
        }

        $newRule = [
            'Class' => 'Normal',
            'Config' => [
                'DetectLine' => self::P03_LINE,
                'Direction' => 'Both',
                'LaneNumber' => null,
                'SizeFilter' => [
                    // Kontrollierter OUT-Test: nur die Mindestgröße wird
                    // reduziert. Type=ByLength bleibt ausdrücklich erhalten,
                    // weil das Entfernen dieses Feldes im vorherigen Test zu
                    // 0/4 CrossLine-Ereignissen führte.
                    'MaxSize' => [8191, 8191],
                    'MinSize' => [0, 0],
                    'Type' => 'ByLength'
                ],
                // Kamera-Caps melden TriggerPosition=false für CrossLineDetection.
            ],
            'Enable' => true,
            'EventHandler' => $eventHandler,
            'Id' => $newId,
            'Name' => self::RULE_NAME,
            // Generische Dahua-Tripwire: "Unknown" bedeutet nicht auf Human/Vehicle
            // vorfiltern. Damit kann die Linie bereits auslösen, bevor SMD die weit
            // entfernte Person als Human klassifiziert.
            'ObjectTypes' => ['Unknown'],
            'PtzPresetId' => 0,
            'TrackEnable' => false,
            'Type' => 'CrossLineDetection'
        ];

        $candidate = $original;
        $candidate[0][] = $newRule;
        $newIndex = count($candidate[0]) - 1;

        // Vor dem echten Add zuerst exakt dieselbe Tabelle zurückschreiben.
        // Damit beweisen wir, dass diese Firmware den vollständigen RPC2-Write akzeptiert,
        // ohne semantisch etwas zu ändern.
        $noop = $this->rpc2Call(
            $host, $port, $session, 11, 'configManager.setConfig',
            ['name' => 'VideoAnalyseRule', 'table' => $original, 'options' => []]
        );
        $this->appendRpc2Result('NO-OP setConfig VideoAnalyseRule', $noop);
        if (!($noop['ok'] ?? false)) {
            $this->rpc2Call($host, $port, $session, 99, 'global.logout', null);
            return ['ok' => false, 'error' => 'Firmware lehnt vollständigen RPC2-Write der VideoAnalyseRule-Tabelle ab'];
        }

        $write = $this->rpc2Call(
            $host, $port, $session, 12, 'configManager.setConfig',
            ['name' => 'VideoAnalyseRule', 'table' => $candidate, 'options' => []]
        );
        $this->appendRpc2Result('ADD P03 setConfig VideoAnalyseRule', $write);
        if (!($write['ok'] ?? false)) {
            $this->rpc2Call($host, $port, $session, 13, 'configManager.setConfig', ['name' => 'VideoAnalyseRule', 'table' => $original, 'options' => []]);
            $this->rpc2Call($host, $port, $session, 99, 'global.logout', null);
            return ['ok' => false, 'error' => 'Kamera hat das Hinzufügen der P03-Regel abgelehnt'];
        }

        $verify = $this->rpc2Call($host, $port, $session, 14, 'configManager.getConfig', ['name' => 'VideoAnalyseRule']);
        $ok = false;
        $verifiedRule = null;
        $verifiedTable = $verify['json']['params']['table'] ?? null;
        if (($verify['ok'] ?? false) && is_array($verifiedTable) && isset($verifiedTable[0]) && is_array($verifiedTable[0])) {
            foreach ($verifiedTable[0] as $i => $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                if (strcasecmp((string) ($rule['Name'] ?? ''), self::RULE_NAME) === 0
                    && strcasecmp((string) ($rule['Type'] ?? ''), 'CrossLineDetection') === 0) {
                    $line = $rule['Config']['DetectLine'] ?? null;
                    $objects = $rule['ObjectTypes'] ?? [];
                    $ok = is_array($line)
                        && $line === self::P03_LINE
                        && strtolower((string) ($rule['Config']['Direction'] ?? '')) === 'both'
                        && in_array('Unknown', is_array($objects) ? $objects : [], true)
                        && (($rule['Enable'] ?? false) === true);
                    $verifiedRule = $rule;
                    $newIndex = (int) $i;
                    break;
                }
            }
        }

        // Zusätzlich sicherstellen, dass die drei vorhandenen Regeln unverändert geblieben sind.
        if ($ok && is_array($verifiedTable[0] ?? null)) {
            for ($i = 0; $i < count($original[0]); $i++) {
                $before = $original[0][$i] ?? null;
                $after = $verifiedTable[0][$i] ?? null;
                if (!is_array($before) || !is_array($after)
                    || (string) ($before['Name'] ?? '') !== (string) ($after['Name'] ?? '')
                    || (string) ($before['Type'] ?? '') !== (string) ($after['Type'] ?? '')
                    || (bool) ($before['Enable'] ?? false) !== (bool) ($after['Enable'] ?? false)) {
                    $ok = false;
                    break;
                }
            }
        }

        if (!$ok) {
            $rollback = $this->rpc2Call(
                $host, $port, $session, 15, 'configManager.setConfig',
                ['name' => 'VideoAnalyseRule', 'table' => $original, 'options' => []]
            );
            $this->appendRpc2Result('ROLLBACK VideoAnalyseRule', $rollback);
            $this->rpc2Call($host, $port, $session, 99, 'global.logout', null);
            return ['ok' => false, 'error' => 'Rückleseprüfung der neuen P03-Regel fehlgeschlagen; Rollback ausgeführt'];
        }

        // Bestehende SMD-Personenerkennung muss erhalten bleiben.
        $smartAfterRaw = $this->cameraGet('/cgi-bin/configManager.cgi?action=getConfig&name=SmartMotionDetect');
        $smartAfter = $smartAfterRaw['ok']
            ? GateTestLogic::configValue($smartAfterRaw['body'], 'SmartMotionDetect[0].Enable')
            : null;
        $smartBefore = $this->ReadAttributeString('OriginalSmartMotionEnable');
        if ($smartBefore !== '' && strtolower($smartBefore) === 'true'
            && $smartAfter !== null && strtolower($smartAfter) !== 'true') {
            $rollback = $this->rpc2Call(
                $host, $port, $session, 16, 'configManager.setConfig',
                ['name' => 'VideoAnalyseRule', 'table' => $original, 'options' => []]
            );
            $this->appendRpc2Result('ROLLBACK wegen SMD-Konflikt', $rollback);
            $this->rpc2Call($host, $port, $session, 99, 'global.logout', null);
            return ['ok' => false, 'error' => 'SMD wurde durch IVS beeinflusst; Ausgangszustand wiederhergestellt'];
        }

        $this->rpc2Call($host, $port, $session, 99, 'global.logout', null);
        $this->WriteAttributeBoolean('Rpc2RuleCreatedByModule', true);
        $this->WriteAttributeBoolean('RuleCreatedByModule', true);
        $this->WriteAttributeInteger('RuleIndex', $newIndex);
        $this->WriteAttributeInteger('RuleID', $newId);
        $this->appendProtocol('RPC2 P03 VERIFY: OK, Index=' . $newIndex . ', Id=' . $newId . ', ObjectTypes=Unknown, MinSize=0, Type=ByLength, Direction=Both.');
        $this->appendProtocol('HINWEIS P03: Tripwire läuft generisch mit ObjectTypes=Unknown. SmartMotionHuman wird separat als zeitversetzte Personenbestätigung protokolliert.');
        $this->appendProtocol('P03 Geometrie (HOME↔Lagerplatz-Grenze): ' . json_encode(self::P03_LINE, JSON_UNESCAPED_SLASHES) . '.');
        return ['ok' => true, 'error' => '', 'index' => $newIndex, 'id' => $newId];
    }

    /** @return array{ok:bool,error:string} */
    private function restoreOriginalVideoAnalyseRuleViaRpc2(): array
    {
        $raw = $this->ReadAttributeString('OriginalVideoAnalyseRuleRpc2');
        $table = json_decode($raw, true);
        if (!is_array($table)) {
            return ['ok' => false, 'error' => 'kein gültiges IVS-Backup vorhanden'];
        }

        $cfg = $this->cameraConfiguration();
        $login = $this->rpc2Login(
            (string) ($cfg['host'] ?? ''),
            (int) ($cfg['port'] ?? 80),
            (string) ($cfg['username'] ?? ''),
            (string) ($cfg['password'] ?? '')
        );
        if (!($login['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'RPC2-Login fehlgeschlagen'];
        }

        $host = (string) $cfg['host'];
        $port = (int) $cfg['port'];
        $session = (string) ($login['session'] ?? '');
        $restore = $this->rpc2Call(
            $host, $port, $session, 70, 'configManager.setConfig',
            ['name' => 'VideoAnalyseRule', 'table' => $table, 'options' => []]
        );
        if (!($restore['ok'] ?? false)) {
            $this->rpc2Call($host, $port, $session, 99, 'global.logout', null);
            return ['ok' => false, 'error' => 'setConfig Restore wurde abgelehnt'];
        }

        $verify = $this->rpc2Call($host, $port, $session, 71, 'configManager.getConfig', ['name' => 'VideoAnalyseRule']);
        $this->rpc2Call($host, $port, $session, 99, 'global.logout', null);
        $current = $verify['json']['params']['table'] ?? null;
        if (!($verify['ok'] ?? false) || !is_array($current) || count($current[0] ?? []) !== count($table[0] ?? [])) {
            return ['ok' => false, 'error' => 'Restore konnte nicht eindeutig rückgelesen werden'];
        }

        $this->WriteAttributeBoolean('Rpc2RuleCreatedByModule', false);
        $this->WriteAttributeBoolean('RuleCreatedByModule', false);
        $this->WriteAttributeInteger('RuleIndex', -1);
        $this->WriteAttributeInteger('RuleID', -1);
        return ['ok' => true, 'error' => ''];
    }

    /**
     * Liest VideoAnalyseGlobal robust. Manche Taurus/Web5-Firmwares lassen
     * Scene.Type im CGI-Flat-Output vollständig weg, solange kein Smart-Plan
     * aktiv ist. RPC2 liefert denselben Zustand strukturiert als null.
     *
     * @return array{ok:bool,type:mixed,table?:array,error:string,cgiType:?string}
     */
    private function readVideoAnalyseSceneState(): array
    {
        $cgi = $this->cameraGet('/cgi-bin/configManager.cgi?action=getConfig&name=VideoAnalyseGlobal');
        if (!($cgi['ok'] ?? false)) {
            return ['ok' => false, 'type' => null, 'error' => 'CGI VideoAnalyseGlobal nicht lesbar: ' . (string) ($cgi['error'] ?? ''), 'cgiType' => null];
        }

        $cgiType = GateTestLogic::configValue((string) ($cgi['body'] ?? ''), 'VideoAnalyseGlobal[0].Scene.Type');

        $cfg = $this->cameraConfiguration();
        $login = $this->rpc2Login(
            (string) ($cfg['host'] ?? ''),
            (int) ($cfg['port'] ?? 80),
            (string) ($cfg['username'] ?? ''),
            (string) ($cfg['password'] ?? '')
        );
        if (!($login['ok'] ?? false)) {
            return ['ok' => false, 'type' => null, 'error' => 'RPC2-Login fehlgeschlagen: ' . (string) ($login['error'] ?? ''), 'cgiType' => $cgiType];
        }

        $host = (string) $cfg['host'];
        $port = (int) $cfg['port'];
        $session = (string) ($login['session'] ?? '');
        $rpc = $this->rpc2Call($host, $port, $session, 120, 'configManager.getConfig', ['name' => 'VideoAnalyseGlobal']);
        $this->rpc2Call($host, $port, $session, 129, 'global.logout', null);

        $table = $rpc['json']['params']['table'] ?? null;
        if (!($rpc['ok'] ?? false) || !is_array($table) || !isset($table[0]['Scene']) || !is_array($table[0]['Scene'])) {
            return ['ok' => false, 'type' => null, 'error' => 'RPC2 VideoAnalyseGlobal-Struktur nicht lesbar', 'cgiType' => $cgiType];
        }

        $rpcType = $table[0]['Scene']['Type'] ?? null;
        // Falls CGI einen expliziten Wert liefert, müssen beide APIs übereinstimmen.
        if ($cgiType !== null && $rpcType !== null && (string) $cgiType !== (string) $rpcType) {
            return [
                'ok' => false,
                'type' => $rpcType,
                'table' => $table,
                'error' => 'CGI/RPC2 Scene.Type widersprüchlich: CGI=' . json_encode($cgiType) . ', RPC2=' . json_encode($rpcType),
                'cgiType' => $cgiType
            ];
        }

        return ['ok' => true, 'type' => $rpcType, 'table' => $table, 'error' => '', 'cgiType' => $cgiType];
    }

    /**
     * Unabhängige P03-Konfigurationsprüfung ohne Lauftest.
     * Prüft dieselben Daten über die dokumentierte CGI-API, RPC2 und Web5-Caps.
     * @return array{ok:bool,error:string}
     */
    private function auditP03CameraConfiguration(int $idx): array
    {
        $errors = [];
        $checks = 0;

        // 1) Offizielle Dahua HTTP API: aktive IVS-Szene.
        $global = $this->cameraGet('/cgi-bin/configManager.cgi?action=getConfig&name=VideoAnalyseGlobal');
        $sceneType = $global['ok']
            ? GateTestLogic::configValue($global['body'], 'VideoAnalyseGlobal[0].Scene.Type')
            : null;
        if ($sceneType === 'Normal') {
            $checks++;
            $this->appendProtocol('AUDIT OK 1: CGI VideoAnalyseGlobal[0].Scene.Type=Normal.');
        } else {
            $errors[] = 'CGI Scene.Type!=' . json_encode($sceneType);
        }

        // 2) Offizielle Dahua HTTP API: Regel-Basisdaten und komplette 5-Punkt-Linie.
        $rulesRaw = $this->cameraGet('/cgi-bin/configManager.cgi?action=getConfig&name=VideoAnalyseRule');
        if (!$rulesRaw['ok']) {
            $errors[] = 'CGI VideoAnalyseRule nicht lesbar';
        } else {
            $prefix = "VideoAnalyseRule[0][$idx]";
            $name = GateTestLogic::configValue($rulesRaw['body'], $prefix . '.Name');
            $type = GateTestLogic::configValue($rulesRaw['body'], $prefix . '.Type');
            $enable = GateTestLogic::configValue($rulesRaw['body'], $prefix . '.Enable');
            $direction = GateTestLogic::configValue($rulesRaw['body'], $prefix . '.Config.Direction');
            $class = GateTestLogic::configValue($rulesRaw['body'], $prefix . '.Class');
            $human = GateTestLogic::configValue($rulesRaw['body'], $prefix . '.ObjectTypes[0]');

            if ($name === self::RULE_NAME
                && $type === 'CrossLineDetection'
                && strtolower((string) $enable) === 'true'
                && strtolower((string) $direction) === 'both'
                && ($class === null || $class === 'Normal')
                && $human === 'Unknown') {
                $checks++;
                $this->appendProtocol('AUDIT OK 2: CGI P03 Name/Type/Enable/Direction/Class und ObjectTypes=Unknown korrekt.');
            } else {
                $errors[] = 'CGI P03-Basisdaten abweichend';
                $this->appendProtocol('AUDIT FEHLER 2: ' . json_encode([
                    'name' => $name, 'type' => $type, 'enable' => $enable,
                    'direction' => $direction, 'class' => $class, 'object0' => $human
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            $lineOk = true;
            foreach (self::P03_LINE as $p => $xy) {
                $x = GateTestLogic::configValue($rulesRaw['body'], $prefix . '.Config.DetectLine[' . $p . '][0]');
                $y = GateTestLogic::configValue($rulesRaw['body'], $prefix . '.Config.DetectLine[' . $p . '][1]');
                if ((string) $x !== (string) $xy[0] || (string) $y !== (string) $xy[1]) {
                    $lineOk = false;
                    break;
                }
            }
            if ($lineOk) {
                $checks++;
                $this->appendProtocol('AUDIT OK 3: CGI P03-Polylinie vollständig 5/5 Punkte korrekt.');
            } else {
                $errors[] = 'CGI P03-Polylinie weicht ab';
            }
        }

        // 3) Bestehende SMD-Personenerkennung muss erhalten bleiben.
        $smart = $this->cameraGet('/cgi-bin/configManager.cgi?action=getConfig&name=SmartMotionDetect');
        $smartEnable = $smart['ok']
            ? GateTestLogic::configValue($smart['body'], 'SmartMotionDetect[0].Enable')
            : null;
        if (strtolower((string) $smartEnable) === 'true') {
            $checks++;
            $this->appendProtocol('AUDIT OK 4: SmartMotionDetect bleibt aktiv.');
        } else {
            $errors[] = 'SmartMotionDetect nicht aktiv/lesbar';
        }

        // 4) RPC2: dieselbe Konfiguration strukturiert rücklesen.
        $cfg = $this->cameraConfiguration();
        $login = $this->rpc2Login(
            (string) ($cfg['host'] ?? ''),
            (int) ($cfg['port'] ?? 80),
            (string) ($cfg['username'] ?? ''),
            (string) ($cfg['password'] ?? '')
        );
        if (!($login['ok'] ?? false)) {
            $errors[] = 'RPC2-Login für Audit fehlgeschlagen';
        } else {
            $host = (string) $cfg['host'];
            $port = (int) $cfg['port'];
            $session = (string) ($login['session'] ?? '');

            $rpcGlobal = $this->rpc2Call($host, $port, $session, 90, 'configManager.getConfig', ['name' => 'VideoAnalyseGlobal']);
            $rpcScene = $rpcGlobal['json']['params']['table'][0]['Scene']['Type'] ?? null;
            if (($rpcGlobal['ok'] ?? false) && $rpcScene === 'Normal') {
                $checks++;
                $this->appendProtocol('AUDIT OK 5: RPC2 Scene.Type=Normal.');
            } else {
                $errors[] = 'RPC2 Scene.Type!=' . json_encode($rpcScene);
            }

            $rpcRules = $this->rpc2Call($host, $port, $session, 91, 'configManager.getConfig', ['name' => 'VideoAnalyseRule']);
            $table = $rpcRules['json']['params']['table'][0] ?? null;
            $rpcRule = null;
            if (($rpcRules['ok'] ?? false) && is_array($table)) {
                foreach ($table as $candidate) {
                    if (is_array($candidate)
                        && (string) ($candidate['Name'] ?? '') === self::RULE_NAME
                        && (string) ($candidate['Type'] ?? '') === 'CrossLineDetection') {
                        $rpcRule = $candidate;
                        break;
                    }
                }
            }

            $rpcRuleOk = is_array($rpcRule)
                && (($rpcRule['Enable'] ?? false) === true)
                && (($rpcRule['Class'] ?? 'Normal') === 'Normal')
                && (($rpcRule['Config']['Direction'] ?? '') === 'Both')
                && (($rpcRule['Config']['DetectLine'] ?? null) === self::P03_LINE)
                && (($rpcRule['Config']['SizeFilter']['MinSize'] ?? null) === [0, 0])
                && (($rpcRule['Config']['SizeFilter']['Type'] ?? '') === 'ByLength')
                && in_array('Unknown', is_array($rpcRule['ObjectTypes'] ?? null) ? $rpcRule['ObjectTypes'] : [], true);

            if ($rpcRuleOk) {
                $checks++;
                $this->appendProtocol('AUDIT OK 6: RPC2 P03 ObjectTypes=Unknown, MinSize=0, Type=ByLength und 5-Punkt-Geometrie korrekt.');
            } else {
                $errors[] = 'RPC2 P03-Regel abweichend';
                if (is_array($rpcRule)) {
                    $this->appendProtocol('AUDIT RPC2 P03 IST: ' . json_encode($rpcRule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }

            // Alarm-/Sirenen-/Aufzeichnungs-Nebenwirkungen dürfen für P03 nicht aktiv sein.
            $handlerOk = is_array($rpcRule);
            if ($handlerOk) {
                $handler = is_array($rpcRule['EventHandler'] ?? null) ? $rpcRule['EventHandler'] : [];
                foreach ([
                    'AlarmOutEnable','BeepEnable','ExAlarmOutEnable','MailEnable',
                    'MatrixEnable','MessageEnable','PtzLinkEnable','RecordEnable',
                    'SnapshotEnable','TourEnable','VoiceEnable'
                ] as $flag) {
                    if (($handler[$flag] ?? false) === true) {
                        $handlerOk = false;
                        break;
                    }
                }
            }
            if ($handlerOk) {
                $checks++;
                $this->appendProtocol('AUDIT OK 7: P03 hat keine Alarm-/Sirenen-/Record-/Snapshot-Nebenwirkung.');
            } else {
                $errors[] = 'P03 EventHandler enthält unerwünschte Nebenwirkung';
            }

            // Web5-Capabilities: Kamera muss genau diese Kombination unterstützen.
            $factory = $this->rpc2Call($host, $port, $session, 92, 'devVideoAnalyse.factory.instance', ['channel' => 0]);
            $object = $factory['json']['result'] ?? null;
            $caps = ($factory['ok'] ?? false) && $object !== null
                ? $this->rpc2CallWithObject($host, $port, $session, 93, 'devVideoAnalyse.getCaps', null, $object)
                : ['ok' => false];

            $cap = $caps['json']['params']['caps'] ?? null;
            $crossCap = is_array($cap)
                ? ($cap['SupportedScenes']['Normal']['SupportedRules']['CrossLineDetection'] ?? null)
                : null;
            $capOk = is_array($cap)
                && in_array('Normal', is_array($cap['SupportedScene'] ?? null) ? $cap['SupportedScene'] : [], true)
                && is_array($crossCap)
                && ((int) ($cap['MaxPointOfLine'] ?? 0) >= count(self::P03_LINE))
                && (($crossCap['TriggerPosition'] ?? null) === false);

            if ($capOk) {
                $checks++;
                $this->appendProtocol('AUDIT OK 8: Web5-Caps bestätigen Normal + CrossLine + mindestens 5 Linienpunkte; TriggerPosition=false. ObjectTypes=Unknown wurde separat per CGI/RPC2 bestätigt.');
            } else {
                $errors[] = 'Web5-Capabilities passen nicht zur P03-Konfiguration';
                if (is_array($cap)) {
                    $this->appendProtocol('AUDIT WEB5 CAPS: ' . json_encode([
                        'SupportedScene' => $cap['SupportedScene'] ?? null,
                        'MaxPointOfLine' => $cap['MaxPointOfLine'] ?? null,
                        'CrossLineDetection' => $crossCap
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }

            // P03: Normal-IVS-Sensitivity muss exakt 10 sein.
            $module = $this->rpc2Call($host, $port, $session, 94, 'configManager.getConfig', ['name' => 'VideoAnalyseModule']);
            $moduleTable = $module['json']['params']['table'] ?? null;
            $moduleSensitivity = is_array($moduleTable) ? $this->findNormalSensitivityRecursive($moduleTable) : null;
            if (($module['ok'] ?? false) && $moduleSensitivity === 10) {
                $checks++;
                $this->appendProtocol('AUDIT OK 9: VideoAnalyseModule Normal Sensitivity=10.');
            } else {
                $errors[] = 'VideoAnalyseModule Sensitivity ist nicht 10 (IST=' . var_export($moduleSensitivity, true) . ')';
            }

            $this->rpc2Call($host, $port, $session, 99, 'global.logout', null);
        }

        if ($errors === []) {
            $this->appendProtocol('AUDIT GESAMT: OK (' . $checks . ' Prüfungen). Kamera hat die P03-Konfiguration vollständig übernommen.');
            return ['ok' => true, 'error' => ''];
        }

        $this->appendProtocol('AUDIT GESAMT: FEHLER – ' . implode(' | ', $errors));
        return ['ok' => false, 'error' => implode('; ', $errors)];
    }

    /**
     * Moderne Dahua-Web5-IVS-API: factory.instance -> getCaps/getTemplateRule.
     * Ausschließlich lesend.
     * @return array{ok:bool,error:string}
     */
    private function probeModernIvsFactoryViaRpc2(): array
    {
        $cfg = $this->cameraConfiguration();
        $login = $this->rpc2Login(
            (string) ($cfg['host'] ?? ''),
            (int) ($cfg['port'] ?? 80),
            (string) ($cfg['username'] ?? ''),
            (string) ($cfg['password'] ?? '')
        );
        if (!($login['ok'] ?? false)) {
            $this->appendProtocol('WEB5 IVS LOGIN FEHLER: ' . (string) ($login['error'] ?? 'unbekannt'));
            return ['ok' => false, 'error' => 'RPC2-Login fehlgeschlagen'];
        }

        $host = (string) $cfg['host'];
        $port = (int) $cfg['port'];
        $session = (string) ($login['session'] ?? '');

        $factory = $this->rpc2Call(
            $host, $port, $session, 80,
            'devVideoAnalyse.factory.instance',
            ['channel' => 0]
        );
        $this->appendRpc2Result('WEB5 devVideoAnalyse.factory.instance', $factory);

        $object = $factory['json']['result'] ?? null;
        if (!($factory['ok'] ?? false) || $object === null || $object === false || $object === '') {
            $this->rpc2Call($host, $port, $session, 89, 'global.logout', null);
            return ['ok' => false, 'error' => 'devVideoAnalyse.factory.instance fehlgeschlagen'];
        }

        $caps = $this->rpc2CallWithObject(
            $host, $port, $session, 81,
            'devVideoAnalyse.getCaps',
            null,
            $object
        );
        $this->appendRpc2Result('WEB5 devVideoAnalyse.getCaps', $caps);

        // Mehrere ausschließlich lesende Template-Varianten. Je nach Web5-
        // Generation erwartet Dahua entweder eine Minimalregel oder die bereits
        // angelegte Regelstruktur.
        $templateShapes = [
            // Die dahua-rpc-Web5-Signatur typisiert "rule" absichtlich als unknown.
            // Auf dieser Firmware haben Objektvarianten bisher INVALID_PARAM geliefert.
            // Deshalb zuerst die wahrscheinliche Web5-Form: reiner Regeltyp als String.
            'STRING' => 'CrossLineDetection',
            'TYPE_ONLY' => ['Type' => 'CrossLineDetection'],
            'MINIMAL' => ['Class' => 'Normal', 'Type' => 'CrossLineDetection'],
            'NAMED' => [
                'Class' => 'Normal',
                'Type' => 'CrossLineDetection',
                'Name' => self::RULE_NAME,
                'ObjectTypes' => ['Human']
            ],
            'CURRENT' => [
                'Class' => 'Normal',
                'Config' => [
                    'DetectLine' => self::P03_LINE,
                    'Direction' => 'Both'
                ],
                'Enable' => true,
                'Name' => self::RULE_NAME,
                'ObjectTypes' => ['Human'],
                'Type' => 'CrossLineDetection'
            ]
        ];

        $templateOk = false;
        $id = 82;
        foreach ($templateShapes as $label => $rule) {
            $r = $this->rpc2CallWithObject(
                $host, $port, $session, $id++,
                'devVideoAnalyse.getTemplateRule',
                ['rule' => $rule],
                $object
            );
            $this->appendRpc2Result('WEB5 getTemplateRule ' . $label, $r);
            if ($r['ok'] ?? false) {
                $templateOk = true;
                break;
            }
        }

        $this->rpc2Call($host, $port, $session, 89, 'global.logout', null);
        $this->appendProtocol(
            'WEB5 IVS DIAGNOSE: Caps=' . (($caps['ok'] ?? false) ? 'OK' : 'FEHLER') .
            ', CrossLine-Template=' . ($templateOk ? 'OK' : 'nicht geliefert') .
            '. Es wurde nichts über devVideoAnalyse geschrieben.'
        );

        return [
            'ok' => ($caps['ok'] ?? false) === true,
            'error' => ($caps['ok'] ?? false) === true ? '' : 'getCaps fehlgeschlagen'
        ];
    }

    /** @return array{ok:bool,json?:array,error?:string,http?:int,raw?:string} */
    private function rpc2CallWithObject(
        string $host,
        int $port,
        string $session,
        int $id,
        string $method,
        $params,
        $object
    ): array {
        $payload = [
            'method' => $method,
            'id' => $id,
            'session' => $session,
            'object' => $object
        ];
        if ($params !== null) {
            $payload['params'] = $params;
        }
        return $this->rpc2Post('http://' . $host . ':' . $port . '/RPC2', $payload);
    }

    /** @return array{ok:bool,error:string,changed?:bool} */
    private function enableNormalIvsSmartPlanViaRpc2(): array
    {
        $cfg = $this->cameraConfiguration();
        $login = $this->rpc2Login(
            (string) ($cfg['host'] ?? ''),
            (int) ($cfg['port'] ?? 80),
            (string) ($cfg['username'] ?? ''),
            (string) ($cfg['password'] ?? '')
        );
        if (!($login['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'RPC2-Login fehlgeschlagen'];
        }

        $host = (string) $cfg['host'];
        $port = (int) $cfg['port'];
        $session = (string) ($login['session'] ?? '');

        $read = $this->rpc2Call($host, $port, $session, 40, 'configManager.getConfig', ['name' => 'VideoAnalyseGlobal']);
        if (!($read['ok'] ?? false) || !is_array($read['json']['params']['table'] ?? null)) {
            $this->rpc2Call($host, $port, $session, 49, 'global.logout', null);
            return ['ok' => false, 'error' => 'VideoAnalyseGlobal konnte nicht gelesen werden'];
        }

        $original = $read['json']['params']['table'];
        if (!isset($original[0]['Scene']) || !is_array($original[0]['Scene'])) {
            $this->rpc2Call($host, $port, $session, 49, 'global.logout', null);
            return ['ok' => false, 'error' => 'unerwartete VideoAnalyseGlobal-Struktur'];
        }

        // Dahua aktiviert die laufende IVS-Szene über Scene.Type.
        // Scene.TypeList beschreibt nicht den aktiven Analysemodus. Der bisherige
        // Ansatz TypeList=["Normal"] wurde zwar gespeichert, startete aber die
        // IVS-Runtime nicht. Ein funktionierender Dahua-Client setzt explizit
        // VideoAnalyseGlobal[0].Scene.Type=Normal.
        $currentType = $original[0]['Scene']['Type'] ?? null;
        if ($currentType === 'Normal') {
            $this->appendProtocol('IVS Smart Plan: Scene.Type=Normal ist bereits aktiv.');
            $this->rpc2Call($host, $port, $session, 49, 'global.logout', null);
            return ['ok' => true, 'error' => '', 'changed' => false];
        }

        // Keine fremde aktive Szene automatisch überschreiben.
        if ($currentType !== null && $currentType !== '') {
            $this->appendProtocol('IVS Smart Plan Sicherheitsabbruch: vorhandene Scene.Type=' . json_encode($currentType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->rpc2Call($host, $port, $session, 49, 'global.logout', null);
            return ['ok' => false, 'error' => 'bereits andere IVS-Szene aktiv'];
        }

        $backup = json_encode($original, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($backup) || $backup === '') {
            $this->rpc2Call($host, $port, $session, 49, 'global.logout', null);
            return ['ok' => false, 'error' => 'Global-Backup konnte nicht erzeugt werden'];
        }
        $this->WriteAttributeString('OriginalVideoAnalyseGlobalRpc2', $backup);
        $this->WriteAttributeBoolean('Rpc2GlobalChangedByModule', false);

        $noop = $this->rpc2Call(
            $host, $port, $session, 41, 'configManager.setConfig',
            ['name' => 'VideoAnalyseGlobal', 'table' => $original, 'options' => []]
        );
        $this->appendRpc2Result('SMARTPLAN NO-OP VideoAnalyseGlobal', $noop);
        if (!($noop['ok'] ?? false)) {
            $this->rpc2Call($host, $port, $session, 49, 'global.logout', null);
            return ['ok' => false, 'error' => 'NO-OP-Write VideoAnalyseGlobal abgelehnt'];
        }

        $candidate = $original;
        $candidate[0]['Scene']['Type'] = 'Normal';

        $write = $this->rpc2Call(
            $host, $port, $session, 42, 'configManager.setConfig',
            ['name' => 'VideoAnalyseGlobal', 'table' => $candidate, 'options' => []]
        );
        $this->appendRpc2Result('SMARTPLAN ENABLE Normal', $write);
        if (!($write['ok'] ?? false)) {
            $this->rpc2Call($host, $port, $session, 43, 'configManager.setConfig', ['name' => 'VideoAnalyseGlobal', 'table' => $original, 'options' => []]);
            $this->rpc2Call($host, $port, $session, 49, 'global.logout', null);
            return ['ok' => false, 'error' => 'Kamera hat Scene.Type=Normal abgelehnt'];
        }

        $verify = $this->rpc2Call($host, $port, $session, 44, 'configManager.getConfig', ['name' => 'VideoAnalyseGlobal']);
        $verifiedType = $verify['json']['params']['table'][0]['Scene']['Type'] ?? null;
        if (!($verify['ok'] ?? false) || $verifiedType !== 'Normal') {
            $rollback = $this->rpc2Call($host, $port, $session, 45, 'configManager.setConfig', ['name' => 'VideoAnalyseGlobal', 'table' => $original, 'options' => []]);
            $this->appendRpc2Result('SMARTPLAN ROLLBACK', $rollback);
            $this->rpc2Call($host, $port, $session, 49, 'global.logout', null);
            return ['ok' => false, 'error' => 'Smart-Plan-Rückleseprüfung fehlgeschlagen'];
        }

        // Bestehende SMD-Human-Erkennung darf nicht verloren gehen.
        $smartRaw = $this->cameraGet('/cgi-bin/configManager.cgi?action=getConfig&name=SmartMotionDetect');
        $smartAfter = $smartRaw['ok']
            ? GateTestLogic::configValue($smartRaw['body'], 'SmartMotionDetect[0].Enable')
            : null;
        $smartBefore = strtolower($this->ReadAttributeString('OriginalSmartMotionEnable'));
        if ($smartBefore === 'true' && $smartAfter !== null && strtolower($smartAfter) !== 'true') {
            $rollback = $this->rpc2Call($host, $port, $session, 46, 'configManager.setConfig', ['name' => 'VideoAnalyseGlobal', 'table' => $original, 'options' => []]);
            $this->appendRpc2Result('SMARTPLAN ROLLBACK wegen SMD-Konflikt', $rollback);
            $this->rpc2Call($host, $port, $session, 49, 'global.logout', null);
            return ['ok' => false, 'error' => 'SMD wurde durch IVS-Smart-Plan deaktiviert'];
        }

        $this->WriteAttributeBoolean('Rpc2GlobalChangedByModule', true);
        $this->appendProtocol('IVS Smart Plan erfolgreich aktiviert: Scene.Type=Normal.');
        $this->appendProtocol('SmartMotionDetect nach Smart-Plan-Aktivierung: ' . ($smartAfter ?? '<nicht lesbar>'));
        $this->rpc2Call($host, $port, $session, 49, 'global.logout', null);
        return ['ok' => true, 'error' => '', 'changed' => true];
    }

    /** @return array{ok:bool,error:string} */
    private function restoreOriginalVideoAnalyseGlobalViaRpc2(): array
    {
        $raw = $this->ReadAttributeString('OriginalVideoAnalyseGlobalRpc2');
        $table = json_decode($raw, true);
        if (!is_array($table)) {
            return ['ok' => false, 'error' => 'kein gültiges Global-Backup vorhanden'];
        }

        $cfg = $this->cameraConfiguration();
        $login = $this->rpc2Login(
            (string) ($cfg['host'] ?? ''),
            (int) ($cfg['port'] ?? 80),
            (string) ($cfg['username'] ?? ''),
            (string) ($cfg['password'] ?? '')
        );
        if (!($login['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'RPC2-Login fehlgeschlagen'];
        }

        $host = (string) $cfg['host'];
        $port = (int) $cfg['port'];
        $session = (string) ($login['session'] ?? '');

        $restore = $this->rpc2Call(
            $host, $port, $session, 60, 'configManager.setConfig',
            ['name' => 'VideoAnalyseGlobal', 'table' => $table, 'options' => []]
        );
        if (!($restore['ok'] ?? false)) {
            $this->rpc2Call($host, $port, $session, 69, 'global.logout', null);
            return ['ok' => false, 'error' => 'Global-Restore wurde abgelehnt'];
        }

        $verify = $this->rpc2Call($host, $port, $session, 61, 'configManager.getConfig', ['name' => 'VideoAnalyseGlobal']);
        $this->rpc2Call($host, $port, $session, 69, 'global.logout', null);
        if (!($verify['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'Global-Restore konnte nicht rückgelesen werden'];
        }

        $this->WriteAttributeBoolean('Rpc2GlobalChangedByModule', false);
        return ['ok' => true, 'error' => ''];
    }

    /** @return array{ok:bool,error:string} */
    private function probeIvsRuntimeViaRpc2(): array
    {
        $cfg = $this->cameraConfiguration();
        if (($cfg['host'] ?? '') === '' || ($cfg['username'] ?? '') === '' || ($cfg['password'] ?? '') === '') {
            return ['ok' => false, 'error' => 'Kamerakonfiguration fehlt'];
        }

        $login = $this->rpc2Login(
            (string) $cfg['host'],
            (int) $cfg['port'],
            (string) $cfg['username'],
            (string) $cfg['password']
        );
        if (!($login['ok'] ?? false)) {
            $this->appendProtocol('IVS RUNTIME RPC2 LOGIN FEHLER: ' . (string) ($login['error'] ?? 'unbekannt'));
            return ['ok' => false, 'error' => 'RPC2-Login fehlgeschlagen'];
        }

        $host = (string) $cfg['host'];
        $port = (int) $cfg['port'];
        $session = (string) ($login['session'] ?? '');

        $queries = [
            30 => ['CURRENT VideoAnalyseGlobal', 'configManager.getConfig', ['name' => 'VideoAnalyseGlobal']],
            31 => ['DEFAULT VideoAnalyseGlobal', 'configManager.getDefault', ['name' => 'VideoAnalyseGlobal']],
            32 => ['CURRENT VideoAnalyseModule', 'configManager.getConfig', ['name' => 'VideoAnalyseModule']],
            33 => ['DEFAULT VideoAnalyseModule', 'configManager.getDefault', ['name' => 'VideoAnalyseModule']],
            34 => ['CURRENT VideoAnalyseRule', 'configManager.getConfig', ['name' => 'VideoAnalyseRule']]
        ];

        $ok = true;
        foreach ($queries as $id => $q) {
            [$label, $rpcMethod, $params] = $q;
            $r = $this->rpc2Call($host, $port, $session, $id, $rpcMethod, $params);
            $this->appendRpc2Result('IVS ' . $label, $r);
            if (!($r['ok'] ?? false)) {
                $ok = false;
            }
        }

        $this->rpc2Call($host, $port, $session, 39, 'global.logout', null);
        $this->appendProtocol('IVS RUNTIME DIAGNOSE: ' . ($ok ? 'vollständig gelesen' : 'teilweise fehlgeschlagen') . '. Es wurde nichts zusätzlich geschrieben.');
        return ['ok' => $ok, 'error' => $ok ? '' : 'mindestens ein Read fehlgeschlagen'];
    }

    /** @return array{ok:bool,error:string} */
    private function setNormalVideoAnalyseSensitivityViaRpc2(int $sensitivity): array
    {
        $cfg = $this->cameraConfiguration();
        $login = $this->rpc2Login(
            (string) ($cfg['host'] ?? ''),
            (int) ($cfg['port'] ?? 80),
            (string) ($cfg['username'] ?? ''),
            (string) ($cfg['password'] ?? '')
        );
        if (!($login['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'RPC2-Login fehlgeschlagen'];
        }

        $host = (string) $cfg['host'];
        $port = (int) $cfg['port'];
        $session = (string) ($login['session'] ?? '');

        $read = $this->rpc2Call($host, $port, $session, 201, 'configManager.getConfig', ['name' => 'VideoAnalyseModule']);
        $table = $read['json']['params']['table'] ?? null;
        if (!($read['ok'] ?? false) || !is_array($table)) {
            $this->rpc2Call($host, $port, $session, 209, 'global.logout', null);
            return ['ok' => false, 'error' => 'VideoAnalyseModule nicht lesbar'];
        }

        if (!$this->ReadAttributeBoolean('Rpc2ModuleChangedByModule')) {
            $this->WriteAttributeString('OriginalVideoAnalyseModuleRpc2', json_encode($table));
        }

        $candidate = $table;
        if (!$this->setNormalSensitivityRecursive($candidate, $sensitivity)) {
            $this->rpc2Call($host, $port, $session, 209, 'global.logout', null);
            return ['ok' => false, 'error' => 'Normal-Modul mit Sensitivity nicht gefunden'];
        }

        $write = $this->rpc2Call(
            $host, $port, $session, 202, 'configManager.setConfig',
            ['name' => 'VideoAnalyseModule', 'table' => $candidate, 'options' => []]
        );
        if (!($write['ok'] ?? false)) {
            $this->rpc2Call($host, $port, $session, 209, 'global.logout', null);
            return ['ok' => false, 'error' => 'setConfig VideoAnalyseModule abgelehnt'];
        }

        $verify = $this->rpc2Call($host, $port, $session, 203, 'configManager.getConfig', ['name' => 'VideoAnalyseModule']);
        $verifyTable = $verify['json']['params']['table'] ?? null;
        $actual = is_array($verifyTable) ? $this->findNormalSensitivityRecursive($verifyTable) : null;

        if (!($verify['ok'] ?? false) || $actual !== $sensitivity) {
            $original = json_decode($this->ReadAttributeString('OriginalVideoAnalyseModuleRpc2'), true);
            if (is_array($original)) {
                $this->rpc2Call(
                    $host, $port, $session, 204, 'configManager.setConfig',
                    ['name' => 'VideoAnalyseModule', 'table' => $original, 'options' => []]
                );
            }
            $this->rpc2Call($host, $port, $session, 209, 'global.logout', null);
            return ['ok' => false, 'error' => 'Sensitivity-Rückleseprüfung fehlgeschlagen (IST=' . var_export($actual, true) . ')'];
        }

        $this->rpc2Call($host, $port, $session, 209, 'global.logout', null);
        $this->WriteAttributeBoolean('Rpc2ModuleChangedByModule', true);
        return ['ok' => true, 'error' => ''];
    }

    private function setNormalSensitivityRecursive(array &$node, int $sensitivity): bool
    {
        if (($node['Type'] ?? null) === 'Normal' && array_key_exists('Sensitivity', $node)) {
            $node['Sensitivity'] = $sensitivity;
            return true;
        }
        foreach ($node as &$child) {
            if (is_array($child) && $this->setNormalSensitivityRecursive($child, $sensitivity)) {
                unset($child);
                return true;
            }
        }
        unset($child);
        return false;
    }

    private function findNormalSensitivityRecursive(array $node): ?int
    {
        if (($node['Type'] ?? null) === 'Normal' && array_key_exists('Sensitivity', $node)) {
            return (int) $node['Sensitivity'];
        }
        foreach ($node as $child) {
            if (!is_array($child)) {
                continue;
            }
            $found = $this->findNormalSensitivityRecursive($child);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /** @return array{ok:bool,error:string} */
    private function restoreOriginalVideoAnalyseModuleViaRpc2(): array
    {
        $raw = $this->ReadAttributeString('OriginalVideoAnalyseModuleRpc2');
        $table = json_decode($raw, true);
        if (!is_array($table)) {
            return ['ok' => false, 'error' => 'kein gültiges VideoAnalyseModule-Backup vorhanden'];
        }

        $cfg = $this->cameraConfiguration();
        $login = $this->rpc2Login(
            (string) ($cfg['host'] ?? ''),
            (int) ($cfg['port'] ?? 80),
            (string) ($cfg['username'] ?? ''),
            (string) ($cfg['password'] ?? '')
        );
        if (!($login['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'RPC2-Login fehlgeschlagen'];
        }

        $host = (string) $cfg['host'];
        $port = (int) $cfg['port'];
        $session = (string) ($login['session'] ?? '');

        $restore = $this->rpc2Call(
            $host, $port, $session, 211, 'configManager.setConfig',
            ['name' => 'VideoAnalyseModule', 'table' => $table, 'options' => []]
        );
        if (!($restore['ok'] ?? false)) {
            $this->rpc2Call($host, $port, $session, 219, 'global.logout', null);
            return ['ok' => false, 'error' => 'Restore VideoAnalyseModule wurde abgelehnt'];
        }

        $verify = $this->rpc2Call($host, $port, $session, 212, 'configManager.getConfig', ['name' => 'VideoAnalyseModule']);
        $this->rpc2Call($host, $port, $session, 219, 'global.logout', null);
        if (!($verify['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'Restore konnte nicht rückgelesen werden'];
        }

        $this->WriteAttributeBoolean('Rpc2ModuleChangedByModule', false);
        return ['ok' => true, 'error' => ''];
    }

    /** @return array{ok:bool,error:string} */
    private function probeRpc2VideoAnalyse(): array
    {
        $cfg = $this->cameraConfiguration();
        if (($cfg['host'] ?? '') === '' || ($cfg['username'] ?? '') === '' || ($cfg['password'] ?? '') === '') {
            $this->appendProtocol('RPC2: Kamerakonfiguration fehlt.');
            return ['ok' => false, 'error' => 'Kamerakonfiguration fehlt'];
        }

        $login = $this->rpc2Login(
            (string) $cfg['host'],
            (int) $cfg['port'],
            (string) $cfg['username'],
            (string) $cfg['password']
        );
        if (!($login['ok'] ?? false)) {
            $this->appendProtocol('RPC2 LOGIN FEHLER: ' . (string) ($login['error'] ?? 'unbekannt'));
            return ['ok' => false, 'error' => (string) ($login['error'] ?? 'Login fehlgeschlagen')];
        }

        $session = (string) ($login['session'] ?? '');
        $this->appendProtocol('RPC2 LOGIN: OK, Session aufgebaut (ID wird nicht protokolliert).');

        $current = $this->rpc2Call(
            (string) $cfg['host'],
            (int) $cfg['port'],
            $session,
            3,
            'configManager.getConfig',
            ['name' => 'VideoAnalyseRule']
        );
        $this->appendRpc2Result('getConfig VideoAnalyseRule', $current);

        $default = $this->rpc2Call(
            (string) $cfg['host'],
            (int) $cfg['port'],
            $session,
            4,
            'configManager.getDefault',
            ['name' => 'VideoAnalyseRule']
        );
        $this->appendRpc2Result('getDefault VideoAnalyseRule', $default);

        $global = $this->rpc2Call(
            (string) $cfg['host'],
            (int) $cfg['port'],
            $session,
            5,
            'configManager.getConfig',
            ['name' => 'VideoAnalyseGlobal']
        );
        $this->appendRpc2Result('getConfig VideoAnalyseGlobal', $global);

        // Session höflich schließen; Fehler beim Logout ändert das Diagnoseergebnis nicht.
        $logout = $this->rpc2Call(
            (string) $cfg['host'],
            (int) $cfg['port'],
            $session,
            6,
            'global.logout',
            null
        );
        $this->appendProtocol('RPC2 LOGOUT: ' . (($logout['ok'] ?? false) ? 'OK' : 'nicht bestätigt'));

        $ok = ($current['ok'] ?? false) === true && ($default['ok'] ?? false) === true;
        if ($ok) {
            $this->appendProtocol('RPC2-DIAGNOSE: VideoAnalyseRule current+default erfolgreich gelesen. Es wurde NICHT geschrieben.');
            return ['ok' => true, 'error' => ''];
        }

        $this->appendProtocol('RPC2-DIAGNOSE: mindestens ein notwendiger Read wurde abgelehnt. Es wurde NICHT geschrieben.');
        return ['ok' => false, 'error' => 'RPC2-Read unvollständig'];
    }

    /** @return array{ok:bool,session?:string,error?:string} */
    private function rpc2Login(string $host, int $port, string $username, string $password): array
    {
        $base = 'http://' . $host . ':' . $port;

        // Dahua-Web5/RPC2 arbeitet beim ersten Login absichtlich zweistufig:
        // Die Challenge-Antwort kann HTTP 200 + result=false enthalten und ist
        // trotzdem ERFOLGREICH, sofern session/realm/random geliefert werden.
        // Genau dieses Verhalten zeigt die installierte Firmware 3.140...21.R.
        $first = $this->rpc2Post($base . '/RPC2_Login', [
            'method' => 'global.login',
            'id' => 1,
            'params' => [
                'userName' => $username,
                'password' => '',
                'clientType' => 'Web5.0'
            ]
        ], true);

        if (!is_array($first['json'] ?? null)) {
            return ['ok' => false, 'error' => 'Login-Challenge fehlgeschlagen: ' . (string) ($first['error'] ?? '')];
        }

        $j1 = $first['json'];
        $session = (string) ($j1['session'] ?? '');
        $realm = (string) ($j1['params']['realm'] ?? '');
        $random = (string) ($j1['params']['random'] ?? '');
        $authorityType = (string) (($j1['params']['encryption'] ?? '') ?: 'Default');
        $this->appendProtocol(
            'RPC2 CHALLENGE: HTTP ' . (int) ($first['http'] ?? 0) .
            ', result=' . json_encode($j1['result'] ?? null) .
            ', session=' . ($session !== '' ? 'vorhanden' : 'fehlt') .
            ', realm=' . ($realm !== '' ? 'vorhanden' : 'fehlt') .
            ', random=' . ($random !== '' ? 'vorhanden' : 'fehlt')
        );
        if ($session === '' || $realm === '' || $random === '') {
            return ['ok' => false, 'error' => 'Login-Challenge unvollständig'];
        }

        $pwdHash = strtoupper(md5($username . ':' . $realm . ':' . $password));
        $passHash = strtoupper(md5($username . ':' . $random . ':' . $pwdHash));

        $second = $this->rpc2Post($base . '/RPC2_Login', [
            'method' => 'global.login',
            'id' => 2,
            'session' => $session,
            'params' => [
                'userName' => $username,
                'password' => $passHash,
                'clientType' => 'Web5.0',
                'realm' => $realm,
                'random' => $random,
                'passwordType' => 'Default',
                'authorityType' => $authorityType
            ]
        ]);

        if (!($second['ok'] ?? false) || !is_array($second['json'] ?? null) || (($second['json']['result'] ?? false) !== true)) {
            $err = is_array($second['json'] ?? null) ? json_encode($second['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) ($second['error'] ?? '');
            return ['ok' => false, 'error' => 'Authentifizierung abgelehnt: ' . $err];
        }

        $session2 = (string) ($second['json']['session'] ?? $session);
        if ($session2 === '') {
            return ['ok' => false, 'error' => 'keine Session nach Login'];
        }
        return ['ok' => true, 'session' => $session2];
    }

    /** @return array{ok:bool,json?:array,error?:string,http?:int,raw?:string} */
    private function rpc2Call(string $host, int $port, string $session, int $id, string $method, $params): array
    {
        $payload = [
            'method' => $method,
            'id' => $id,
            'session' => $session
        ];
        if ($params !== null) {
            $payload['params'] = $params;
        }
        return $this->rpc2Post('http://' . $host . ':' . $port . '/RPC2', $payload);
    }

    /** @param array<string,mixed> $payload
     *  @return array{ok:bool,json?:array,error?:string,http?:int,raw?:string}
     */
    private function rpc2Post(string $url, array $payload, bool $allowResultFalse = false): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init fehlgeschlagen', 'http' => 0];
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => $error !== '' ? $error : 'curl_exec fehlgeschlagen', 'http' => $http];
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'Antwort ist kein JSON', 'http' => $http, 'raw' => (string) $body];
        }

        $result = ($decoded['result'] ?? null);
        if ($http < 200 || $http >= 300 || ($result === false && !$allowResultFalse)) {
            return [
                'ok' => false,
                'error' => 'HTTP ' . $http . ' / result=' . json_encode($result),
                'http' => $http,
                'json' => $decoded,
                'raw' => (string) $body
            ];
        }

        return ['ok' => true, 'http' => $http, 'json' => $decoded, 'raw' => (string) $body];
    }

    /** @param array<string,mixed> $result */
    private function appendRpc2Result(string $label, array $result): void
    {
        $safe = $result['json'] ?? null;
        $text = is_array($safe)
            ? json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) ($result['raw'] ?? ($result['error'] ?? '<leer>'));
        if (strlen($text) > 30000) {
            $text = substr($text, 0, 30000) . '…';
        }
        $this->appendProtocol(
            'RPC2 ' . $label .
            ': ok=' . (($result['ok'] ?? false) ? 'true' : 'false') .
            ' HTTP=' . (int) ($result['http'] ?? 0) .
            ' body=' . $this->singleLine($text)
        );
    }

    private function probeModernVideoAnalyse(): void
    {
        $requests = [
            'VideoAnalyseGlobal' => '/cgi-bin/configManager.cgi?action=getConfig&name=VideoAnalyseGlobal',
            'SceneList' => '/cgi-bin/devVideoAnalyse.cgi?action=getSceneList',
            'AnalyseCapsCh1' => '/cgi-bin/devVideoAnalyse.cgi?action=getCaps&channel=1',
            'TemplateNormalCh1' => '/cgi-bin/devVideoAnalyse.cgi?action=getTemplateRule&Class=Normal&Channel=1',
            'SoftwareVersion' => '/cgi-bin/magicBox.cgi?action=getSoftwareVersion',
            'DeviceType' => '/cgi-bin/magicBox.cgi?action=getDeviceType'
        ];

        foreach ($requests as $label => $uri) {
            $r = $this->cameraGet($uri);
            $body = $this->singleLine((string) ($r['body'] ?? ''));
            if (strlen($body) > 12000) {
                $body = substr($body, 0, 12000) . '…';
            }
            $this->appendProtocol(
                'PROBE ' . $label .
                ': ok=' . (($r['ok'] ?? false) ? 'true' : 'false') .
                ' HTTP=' . (int) ($r['http'] ?? 0) .
                ' body=' . ($body === '' ? '<leer>' : $body)
            );
        }

        $this->appendProtocol('HINWEIS: Die PROBE-Aufrufe sind ausschließlich lesend. Es wurde nach der abgelehnten Regelanlage keine weitere Kameraeinstellung geschrieben.');
    }

    /** @param array<int,array<string,mixed>> $rules */
    private function summarizeRules(array $rules): string
    {
        $out = [];
        foreach ($rules as $idx => $rule) {
            $out[] = [
                'index' => (int) $idx,
                'name' => (string) ($rule['Name'] ?? ''),
                'type' => (string) ($rule['Type'] ?? ''),
                'enable' => (string) ($rule['Enable'] ?? '')
            ];
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,string> $params
     *  @return array{ok:bool,body:string,error:string,http:int}
     */
    private function cameraSetTransport(array $params): array
    {
        if ($params === []) {
            return ['ok' => true, 'body' => '', 'error' => '', 'http' => 200];
        }
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key . '=' . rawurlencode($value);
        }
        return $this->cameraGet('/cgi-bin/configManager.cgi?action=setConfig&' . implode('&', $parts));
    }

    /** @param array<string,string> $allParams
     *  @return array{ok:bool,body:string,error:string,http:int}
     */
    private function configureTripwireStepwise(int $idx, array $allParams): array
    {
        $steps = [
            'Basis Name/Typ' => [
                "VideoAnalyseRule[0][$idx].Name" => (string) ($allParams["VideoAnalyseRule[0][$idx].Name"] ?? self::RULE_NAME),
                "VideoAnalyseRule[0][$idx].Type" => 'CrossLineDetection'
            ],
            'Geometrie/Richtung' => [
                "VideoAnalyseRule[0][$idx].Config.Direction" => 'Both',
                "VideoAnalyseRule[0][$idx].Config.DetectLine[0][0]" => (string) $this->ReadPropertyInteger('LineAX'),
                "VideoAnalyseRule[0][$idx].Config.DetectLine[0][1]" => (string) $this->ReadPropertyInteger('LineAY'),
                "VideoAnalyseRule[0][$idx].Config.DetectLine[1][0]" => (string) $this->ReadPropertyInteger('LineBX'),
                "VideoAnalyseRule[0][$idx].Config.DetectLine[1][1]" => (string) $this->ReadPropertyInteger('LineBY')
            ],
            'Aktivieren' => [
                "VideoAnalyseRule[0][$idx].Enable" => 'true'
            ]
        ];

        foreach ($steps as $label => $params) {
            $r = $this->cameraSetTransport($params);
            $reply = $this->singleLine((string) ($r['body'] ?? ''));
            $this->appendProtocol('IVS WRITE ' . $label . ': HTTP ' . (int) ($r['http'] ?? 0) . ' Antwort=' . ($reply === '' ? '<leer>' : $reply));
            if (!$r['ok']) {
                return ['ok' => false, 'body' => (string) ($r['body'] ?? ''), 'error' => $label . ': ' . (string) ($r['error'] ?? 'HTTP-Fehler'), 'http' => (int) ($r['http'] ?? 0)];
            }

            $verify = $this->cameraGet('/cgi-bin/configManager.cgi?action=getConfig&name=VideoAnalyseRule');
            if (!$verify['ok']) {
                return ['ok' => false, 'body' => '', 'error' => $label . ': Rücklesen fehlgeschlagen (' . $verify['error'] . ')', 'http' => $verify['http']];
            }
            $rules = GateTestLogic::parseRules($verify['body']);
            $rule = $rules[$idx] ?? [];

            if ($label === 'Basis Name/Typ') {
                $ok = strcasecmp((string) ($rule['Name'] ?? ''), self::RULE_NAME) === 0
                    && strcasecmp((string) ($rule['Type'] ?? ''), 'CrossLineDetection') === 0;
            } elseif ($label === 'Geometrie/Richtung') {
                $ok = strcasecmp((string) ($rule['Config.Direction'] ?? ''), 'Both') === 0
                    && (string) ($rule['Config.DetectLine[0][0]'] ?? '') === (string) $this->ReadPropertyInteger('LineAX')
                    && (string) ($rule['Config.DetectLine[0][1]'] ?? '') === (string) $this->ReadPropertyInteger('LineAY')
                    && (string) ($rule['Config.DetectLine[1][0]'] ?? '') === (string) $this->ReadPropertyInteger('LineBX')
                    && (string) ($rule['Config.DetectLine[1][1]'] ?? '') === (string) $this->ReadPropertyInteger('LineBY');
            } else {
                $ok = strtolower((string) ($rule['Enable'] ?? 'false')) === 'true';
            }

            if (!$ok) {
                $this->appendProtocol('IVS VERIFY ' . $label . ' FEHLER: ' . json_encode($rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return [
                    'ok' => false,
                    'body' => (string) ($r['body'] ?? ''),
                    'error' => $label . ' wurde von der Kamera nicht übernommen; Antwort=' . ($reply === '' ? '<leer>' : $reply),
                    'http' => (int) ($r['http'] ?? 0)
                ];
            }
            $this->appendProtocol('IVS VERIFY ' . $label . ': OK');
        }

        return ['ok' => true, 'body' => 'verified', 'error' => '', 'http' => 200];
    }

    /** @param array<string,string> $params
     *  @return array{ok:bool,body:string,error:string,http:int}
     */
    private function cameraSet(array $params): array
    {
        if ($params === []) {
            return ['ok' => true, 'body' => 'OK', 'error' => '', 'http' => 200];
        }
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key . '=' . rawurlencode($value);
        }
        $result = $this->cameraGet('/cgi-bin/configManager.cgi?action=setConfig&' . implode('&', $parts));
        if ($result['ok'] && stripos(trim($result['body']), 'OK') === false) {
            return ['ok' => false, 'body' => $result['body'], 'error' => 'Kamera antwortete nicht mit OK', 'http' => $result['http']];
        }
        return $result;
    }

    /** @param array<int,mixed> $table
     *  @return array{ok:bool,error:string}
     */
    private function setVideoAnalyseGlobalTableViaRpc2(array $table): array
    {
        $cfg = $this->cameraConfiguration();
        $login = $this->rpc2Login(
            (string) ($cfg['host'] ?? ''),
            (int) ($cfg['port'] ?? 80),
            (string) ($cfg['username'] ?? ''),
            (string) ($cfg['password'] ?? '')
        );
        if (!($login['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'RPC2-Login fehlgeschlagen'];
        }

        $host = (string) $cfg['host'];
        $port = (int) $cfg['port'];
        $session = (string) ($login['session'] ?? '');

        $write = $this->rpc2Call(
            $host, $port, $session, 140,
            'configManager.setConfig',
            ['name' => 'VideoAnalyseGlobal', 'table' => $table, 'options' => []]
        );
        $this->appendRpc2Result('SET VideoAnalyseGlobal Scene.Type', $write);
        if (!($write['ok'] ?? false)) {
            $this->rpc2Call($host, $port, $session, 149, 'global.logout', null);
            return ['ok' => false, 'error' => 'RPC2 setConfig wurde abgelehnt'];
        }

        $verify = $this->rpc2Call($host, $port, $session, 141, 'configManager.getConfig', ['name' => 'VideoAnalyseGlobal']);
        $this->rpc2Call($host, $port, $session, 149, 'global.logout', null);
        $current = $verify['json']['params']['table'] ?? null;

        if (!($verify['ok'] ?? false) || !is_array($current)) {
            return ['ok' => false, 'error' => 'RPC2 Readback fehlgeschlagen'];
        }

        $wantedType = $table[0]['Scene']['Type'] ?? null;
        $currentType = $current[0]['Scene']['Type'] ?? null;
        if ($currentType !== $wantedType) {
            return ['ok' => false, 'error' => 'RPC2 Readback Scene.Type=' . json_encode($currentType)];
        }

        return ['ok' => true, 'error' => ''];
    }

    /** @param array<int,mixed> $table
     *  @return array{ok:bool,error:string}
     */
    private function restoreVideoAnalyseGlobalTable(array $table): array
    {
        $cfg = $this->cameraConfiguration();
        $login = $this->rpc2Login(
            (string) ($cfg['host'] ?? ''),
            (int) ($cfg['port'] ?? 80),
            (string) ($cfg['username'] ?? ''),
            (string) ($cfg['password'] ?? '')
        );
        if (!($login['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'RPC2-Login fehlgeschlagen'];
        }

        $host = (string) $cfg['host'];
        $port = (int) $cfg['port'];
        $session = (string) ($login['session'] ?? '');
        $restore = $this->rpc2Call(
            $host, $port, $session, 130,
            'configManager.setConfig',
            ['name' => 'VideoAnalyseGlobal', 'table' => $table, 'options' => []]
        );
        if (!($restore['ok'] ?? false)) {
            $this->rpc2Call($host, $port, $session, 139, 'global.logout', null);
            return ['ok' => false, 'error' => 'RPC2 Global-Restore abgelehnt'];
        }

        $verify = $this->rpc2Call($host, $port, $session, 131, 'configManager.getConfig', ['name' => 'VideoAnalyseGlobal']);
        $this->rpc2Call($host, $port, $session, 139, 'global.logout', null);
        $current = $verify['json']['params']['table'] ?? null;
        if (!($verify['ok'] ?? false) || !is_array($current) || $current !== $table) {
            return ['ok' => false, 'error' => 'RPC2 Global-Restore nicht identisch rückgelesen'];
        }
        return ['ok' => true, 'error' => ''];
    }

    private function restoreGlobalSceneType(): void
    {
        if (!$this->ReadAttributeBoolean('GlobalChangedByModule')) {
            return;
        }

        $raw = $this->ReadAttributeString('OriginalVideoAnalyseGlobalRpc2');
        $table = json_decode($raw, true);
        if (is_array($table)) {
            $r = $this->restoreVideoAnalyseGlobalTable($table);
            $this->appendProtocol(($r['ok'] ?? false)
                ? 'VideoAnalyseGlobal exakt auf die gesicherte Originaltabelle zurückgesetzt.'
                : 'WARNUNG: exakter VideoAnalyseGlobal-Restore fehlgeschlagen: ' . (string) ($r['error'] ?? ''));
            if ($r['ok'] ?? false) {
                $this->WriteAttributeBoolean('GlobalChangedByModule', false);
            }
            return;
        }

        // Nur für sehr alte Instanzen ohne Tabellen-Backup.
        $old = $this->ReadAttributeString('OriginalGlobalSceneType');
        $r = $this->cameraSet(['VideoAnalyseGlobal[0].Scene.Type' => $old]);
        $this->appendProtocol($r['ok']
            ? 'VideoAnalyseGlobal Scene.Type (Legacy-Restore) zurückgesetzt.'
            : 'WARNUNG: Legacy-Scene.Type-Restore fehlgeschlagen: ' . $r['error']);
        if ($r['ok']) {
            $this->WriteAttributeBoolean('GlobalChangedByModule', false);
        }
    }

    private function syncParentSocket(): void
    {
        $parentID = $this->getParentID();
        $cfg = $this->cameraConfiguration();
        if ($parentID <= 0 || !IPS_InstanceExists($parentID) || ($cfg['host'] ?? '') === '') {
            return;
        }
        try {
            IPS_SetProperty($parentID, 'Host', (string) $cfg['host']);
            IPS_SetProperty($parentID, 'Port', (int) $cfg['port']);
            IPS_SetProperty(
                $parentID,
                'Open',
                $this->ReadPropertyBoolean('Enabled') && $this->ReadPropertyBoolean('TerraceDiagnosticsEnabled')
            );
            IPS_ApplyChanges($parentID);
        } catch (Throwable $e) {
            $this->setResult('Client Socket konnte nicht automatisch konfiguriert werden: ' . $e->getMessage());
        }
    }

    private function updateParentSubscription(int $newParentID): void
    {
        $oldParentID = $this->ReadAttributeInteger('RegisteredParentID');
        if ($oldParentID > 0 && $oldParentID !== $newParentID && IPS_InstanceExists($oldParentID)) {
            try {
                $this->UnregisterMessage($oldParentID, self::IM_CHANGESTATUS_ID);
            } catch (Throwable $e) {
            }
        }
        if ($newParentID > 0 && IPS_InstanceExists($newParentID)) {
            try {
                $this->RegisterMessage($newParentID, self::IM_CHANGESTATUS_ID);
            } catch (Throwable $e) {
            }
        }
        $this->WriteAttributeInteger('RegisteredParentID', $newParentID);
    }

    private function getParentID(): int
    {
        try {
            return (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** @param array<string,mixed> $event */
    private function eventKey(array $event, ?string $direction): string
    {
        $eventId = $event['eventId'] ?? null;
        if ($eventId !== null && $eventId !== '') {
            return 'event:' . (string) $eventId;
        }
        $ruleId = $event['ruleId'] ?? '';
        $objectId = $event['objectId'] ?? '';
        return 'fallback:' . sha1((string) $event['code'] . '|' . (string) $event['index'] . '|' . (string) $ruleId . '|' . (string) $objectId . '|' . (string) $direction . '|' . date('YmdHis'));
    }

    /** @return array<int,array<string,mixed>> */
    private function getCrossings(): array
    {
        $decoded = json_decode($this->ReadAttributeString('Crossings'), true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function refreshReadyState(): void
    {
        $active = $this->ReadAttributeBoolean('TestActive');
        $auxReady = $this->ReadAttributeInteger('AuxJVEventCounterVarID') > 0
            && $this->ReadAttributeInteger('AuxWorkEventCounterVarID') > 0
            && $this->p03AuxStreamsReady();
        $preflight = $this->ReadAttributeBoolean('P03MultiAuditPassed')
            && $this->ReadAttributeBoolean('P03SimulationPassed');

        // Terrace/CrossLine is deliberately absent from readiness.
        $ready = $active && $auxReady && $preflight;
        $this->setReady($ready);

        if ($ready && $this->GetValue('CrossingCount') === 0) {
            $this->setResult(
                'BEREIT – P03 2-Kamera-Test aktiv. JV_LEFT→WORK_LEFT=HOME→LAGER; '
                . 'WORK_LEFT→JV_LEFT=LAGER→HOME. Terrasse ist nicht erforderlich.'
            );
        } elseif ($active && !$ready) {
            $this->setResult('P03 MASTTEST wartet auf beide P03-Mastkamera-Eventstreams.');
        }
    }

    private function setStreamOK(bool $value): void
    {
        $id = $this->GetIDForIdent('StreamOK');
        if ($id > 0 && GetValueBoolean($id) !== $value) {
            SetValueBoolean($id, $value);
        }
    }

    private function setReady(bool $value): void
    {
        $id = $this->GetIDForIdent('Ready');
        if ($id > 0 && GetValueBoolean($id) !== $value) {
            SetValueBoolean($id, $value);
        }
    }

    private function setResult(string $text): void
    {
        $this->SetValue('Result', $text);
        try {
            $this->ReloadForm();
        } catch (Throwable $e) {
            // Die Ergebnisvariable bleibt auch dann korrekt gesetzt.
        }
    }

    private function appendProtocol(string $line): void
    {
        $stamp = date('Y-m-d H:i:s');
        $old = (string) $this->GetValue('Protocol');
        $new = $old . ($old === '' ? '' : "\n") . '[' . $stamp . '] ' . $line;
        if (strlen($new) > 60000) {
            $new = substr($new, -55000);
        }
        $this->SetValue('Protocol', $new);
        $this->SendDebug('Protocol', $line, 0);
    }

    private function singleLine(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
    }
}
