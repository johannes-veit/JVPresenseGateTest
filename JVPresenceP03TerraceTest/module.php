<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/DahuaDigest.php';
require_once dirname(__DIR__) . '/libs/DahuaEventParser.php';
require_once dirname(__DIR__) . '/libs/GateTestLogic.php';
require_once dirname(__DIR__) . '/libs/P03ProofEngine.php';

class JVPresenceP03TerraceTest extends IPSModule
{
    private const CLIENT_SOCKET_GUID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const SOCKET_TX_GUID = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const ALA2_MODULE_GUID = '{5E08D4EE-9727-4682-A23E-E8625EB2337E}';
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
        $this->RegisterPropertyBoolean('AutoDiscover', true);
        $this->RegisterPropertyInteger('SourceCameraInstanceID', 0);
        $this->RegisterPropertyString('AuxJVHost', self::AUX_JV_DEFAULT_HOST);
        $this->RegisterPropertyString('AuxWorkHost', self::AUX_WORK_DEFAULT_HOST);
        $this->RegisterPropertyBoolean('AutoCreateWorkObserver', true);
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

        // PresenceManager – produktiver P03-Grundbaustein.
        $this->RegisterAttributeBoolean('ProductionEnabled', false);
        $this->RegisterAttributeInteger('UnknownOccupants', 0);
        $this->RegisterAttributeInteger('PortalBalance', 0);
        $this->RegisterAttributeString('SeenProductionEventKeys', '{}');
        $this->RegisterAttributeInteger('LastProductionEvent', 0);
        $this->RegisterAttributeBoolean('CoverageDebt', true);

        // P03 multi-camera proof state.
        $this->RegisterAttributeInteger('AuxJVInstanceID', 0);
        $this->RegisterAttributeInteger('AuxWorkInstanceID', 0);
        $this->RegisterAttributeInteger('AuxJVPersonVarID', 0);
        $this->RegisterAttributeInteger('AuxWorkPersonVarID', 0);
        $this->RegisterAttributeString('P03HumanEvents', '[]');
        $this->RegisterAttributeString('P03PendingCrossings', '[]');
        $this->RegisterAttributeString('P03DirectionMap', '{}');
        $this->RegisterAttributeInteger('P03VerifiedCount', 0);
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

        $this->RegisterTimer('HandshakeTimer', 0, 'JVP03_HandshakeTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('SocketRestartTimer', 0, 'JVP03_SocketRestartTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('Watchdog', 15000, 'JVP03_Watchdog($_IPS["TARGET"]);');
        $this->RegisterTimer('ProductionCommitTimer', 0, 'JVP03_ProductionCommitTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('P03ProofTimer', 0, 'JVP03_P03ProofTimer($_IPS["TARGET"]);');

        $this->RequireParent(self::CLIENT_SOCKET_GUID);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('HandshakeTimer', 0);
        $this->SetTimerInterval('SocketRestartTimer', 0);
        $this->SetTimerInterval('ProductionCommitTimer', 0);
        $this->SetTimerInterval('P03ProofTimer', 0);
        $this->SetBuffer('ProductionPending', '[]');
        $this->SetBuffer('HttpBuffer', '');
        $this->SetBuffer('EventCarry', '');
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

        $sourceID = $this->resolveSourceInstance();
        $this->WriteAttributeInteger('SourceInstanceID', $sourceID);
        if ($sourceID <= 0) {
            $this->setResult('NICHT BEREIT – bestehende Instanz JV Terrasse wurde nicht gefunden.');
            return;
        }

        $this->syncParentSocket();
        $this->updateParentSubscription($this->getParentID());
        $this->discoverP03AuxSources(false);
        $this->subscribeP03AuxVariables();
        $this->refreshP03CameraStatus();
        $this->setResult('Installiert. Nachts: „Kamera-Konfiguration prüfen“. Tagsüber: „Test vorbereiten & starten“.');

        if ($this->ReadPropertyBoolean('Enabled') && $this->cameraConfigurationReady()) {
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

        $sourceID = $this->ReadAttributeInteger('SourceInstanceID');
        if ($sourceID <= 0 || !IPS_InstanceExists($sourceID)) {
            $sourceID = $this->resolveSourceInstance();
        }

        $sourceCaption = 'Automatisch erkannt: NICHT GEFUNDEN';
        if ($sourceID > 0 && IPS_InstanceExists($sourceID)) {
            $host = '';
            try {
                $host = trim((string) IPS_GetProperty($sourceID, 'CameraHost'));
            } catch (Throwable $e) {
            }
            $sourceCaption = 'Automatisch erkannt: ' . IPS_GetName($sourceID) . ' (#' . $sourceID . ')' . ($host !== '' ? ' – ' . $host : '');
        }

        $result = '';
        $resultID = $this->GetIDForIdent('Result');
        if ($resultID > 0 && IPS_VariableExists($resultID)) {
            $result = (string) GetValue($resultID);
        }
        if ($result === '') {
            $result = 'Noch kein Test gestartet.';
        }

        $live = [
            [
                'type' => 'Label',
                'caption' => $sourceCaption
            ],
            [
                'type' => 'Label',
                'caption' => 'Aktueller Teststatus: ' . $result
            ]
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
            'Open' => $this->ReadPropertyBoolean('Enabled') && ($cfg['host'] ?? '') !== ''
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

            if ($sender === $jvVar || $sender === $workVar) {
                $changed = true;
                if (is_array($Data) && array_key_exists(1, $Data)) {
                    $changed = (bool) $Data[1];
                }

                $active = false;
                try {
                    $active = (bool) GetValue($sender);
                } catch (Throwable $e) {
                }

                if ($changed && $active) {
                    $source = ($sender === $jvVar) ? P03ProofEngine::SRC_JV_LEFT : P03ProofEngine::SRC_WORK_LEFT;
                    $this->recordP03HumanEvent($source, [
                        'sender' => $sender,
                        'messageTimestamp' => (int) $TimeStamp
                    ]);
                }
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
            if (!$this->ReadPropertyBoolean('Enabled') || !$this->cameraConfigurationReady() || $this->ReadAttributeBoolean('AuthBlocked')) {
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
        if (!$this->ReadPropertyBoolean('Enabled') || !$this->cameraConfigurationReady() || $this->ReadAttributeBoolean('AuthBlocked')) {
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
        $this->ResetTest();

        // Bei jedem Tastendruck frisch auflösen. Dadurch funktioniert der Test auch,
        // wenn die Instanz nach dem ersten ApplyChanges umbenannt/verschoben wurde
        // oder der Anwender die Konfigurationsform ohne erneutes Übernehmen geöffnet hat.
        $sourceID = $this->resolveSourceInstance();
        $this->WriteAttributeInteger('SourceInstanceID', $sourceID);
        if ($sourceID <= 0) {
            $this->setResult('FEHLER – JV Terrasse konnte nicht automatisch gefunden werden. Im Feld darunter kann die Instanz notfalls manuell gewählt werden.');
            return;
        }

        $this->syncParentSocket();
        $this->updateParentSubscription($this->getParentID());

        $cfg = $this->cameraConfiguration();
        if (($cfg['host'] ?? '') === '' || ($cfg['username'] ?? '') === '' || ($cfg['password'] ?? '') === '') {
            $this->setResult('FEHLER – Kamerakonfiguration konnte nicht automatisch aus JV Terrasse übernommen werden.');
            return;
        }

        $this->appendProtocol('=== P03 HOME-LAGER TEST ===');
        $this->appendProtocol('Quelle: JV Terrasse / ' . $cfg['host'] . ':' . $cfg['port']);
        $this->appendProtocol('Keine Zugangsdaten werden im Protokoll ausgegeben.');

        // Multi-camera preflight: JV Terrasse provides the physical boundary,
        // the two mast cameras provide human confirmation on both sides.
        $auxDiscovery = $this->discoverP03AuxSources(true);
        $this->subscribeP03AuxVariables();
        $this->refreshP03CameraStatus();
        if (!($auxDiscovery['ok'] ?? false)) {
            $this->setResult('FEHLER – P03 Zusatzkameras nicht vollständig verfügbar. JV-links und Werkstatt-links müssen PersonDetected liefern.');
            $this->appendProtocol('P03 PREFLIGHT FEHLER: Zusatzkameras/PersonDetected fehlen.');
            return;
        }

        $auxAudit = $this->auditP03AuxCameras();
        if (!($auxAudit['ok'] ?? false)) {
            $this->setResult('FEHLER – P03 Zusatzkamera-Audit: ' . (string) ($auxAudit['error'] ?? 'unbekannt'));
            return;
        }

        $simulation = $this->runP03SimulationInternal();
        $simOK = (bool) ($simulation['ok'] ?? false);
        $this->WriteAttributeBoolean('P03SimulationPassed', $simOK);
        $this->appendProtocol(
            'P03 SIMULATION VOR LAUFTEST: ' . ($simOK ? 'PASS' : 'FAIL')
                . ' – ' . implode(' | ', $simulation['details'] ?? [])
        );
        if (!$simOK) {
            $this->setResult('FEHLER – interne P03-Simulation fehlgeschlagen. Kein Lauftest.');
            return;
        }

        if ($this->ReadAttributeBoolean('Rpc2ModuleChangedByModule')) {
            $this->appendProtocol('Vorherige P03-Sensitivity-Teständerung wird zuerst auf den Ausgangswert zurückgesetzt.');
            $restoreModule = $this->restoreOriginalVideoAnalyseModuleViaRpc2();
            if (!($restoreModule['ok'] ?? false)) {
                $this->setResult('FEHLER – vorherige P03-Sensitivity konnte nicht sicher zurückgesetzt werden: ' . (string) ($restoreModule['error'] ?? 'unbekannt'));
                return;
            }
        }

        // Altstände bis v0.2.4 können VideoAnalyseGlobal per RPC2 verändert haben.
        // Diese Änderung zuerst exakt zurücksetzen.
        if ($this->ReadAttributeBoolean('Rpc2GlobalChangedByModule')) {
            $this->appendProtocol('Vorherige RPC2-IVS-Smart-Plan-Konfiguration wird zuerst vollständig zurückgesetzt.');
            $restoreGlobal = $this->restoreOriginalVideoAnalyseGlobalViaRpc2();
            if (!($restoreGlobal['ok'] ?? false)) {
                $this->setResult('FEHLER – alter RPC2-IVS-Smart-Plan konnte nicht sicher zurückgesetzt werden: ' . (string) ($restoreGlobal['error'] ?? 'unbekannt') . '. Test abgebrochen.');
                return;
            }
            $this->appendProtocol('Originale VideoAnalyseGlobal-Tabelle (RPC2-Altstand) wiederhergestellt.');
        }

        // Ab v0.2.6 wird die aktive Dahua-IVS-Szene ausschließlich über den
        // dokumentierten HTTP-API-Pfad Scene.Type gesteuert.
        if ($this->ReadAttributeBoolean('GlobalChangedByModule')) {
            $this->appendProtocol('Vorherige Scene.Type-Teständerung wird zuerst auf den Ausgangswert zurückgesetzt.');
            $this->restoreGlobalSceneType();
            if ($this->ReadAttributeBoolean('GlobalChangedByModule')) {
                $this->setResult('FEHLER – vorherige Scene.Type-Teständerung konnte nicht sicher zurückgesetzt werden.');
                return;
            }
        }

        if ($this->ReadAttributeBoolean('Rpc2RuleCreatedByModule')) {
            $this->appendProtocol('Vorhandene, vom Testmodul erzeugte P03-Regel wird vor dem neuen Lauf auf den gesicherten Originalzustand zurückgesetzt.');
            $restore = $this->restoreOriginalVideoAnalyseRuleViaRpc2();
            if (!($restore['ok'] ?? false)) {
                $this->setResult('FEHLER – alte Testregel konnte nicht sicher zurückgesetzt werden: ' . (string) ($restore['error'] ?? 'unbekannt') . '. Test abgebrochen.');
                return;
            }
            $this->appendProtocol('Originale VideoAnalyseRule-Tabelle wiederhergestellt.');
        }

        $rulesRaw = $this->cameraGet('/cgi-bin/configManager.cgi?action=getConfig&name=VideoAnalyseRule');
        if (!$rulesRaw['ok']) {
            $this->setResult('FEHLER – IVS-Konfiguration konnte nicht gelesen werden: ' . $rulesRaw['error']);
            return;
        }

        $smartRaw = $this->cameraGet('/cgi-bin/configManager.cgi?action=getConfig&name=SmartMotionDetect');
        $smartBefore = $smartRaw['ok']
            ? GateTestLogic::configValue($smartRaw['body'], 'SmartMotionDetect[0].Enable')
            : null;
        $this->WriteAttributeString('OriginalSmartMotionEnable', $smartBefore ?? '');
        if ($smartBefore !== null) {
            $this->appendProtocol('SmartMotionDetect vorher: ' . $smartBefore);
        }

        $sceneState = $this->readVideoAnalyseSceneState();
        if (!($sceneState['ok'] ?? false)) {
            $this->setResult('FEHLER – VideoAnalyseGlobal konnte nicht eindeutig gelesen werden: ' . (string) ($sceneState['error'] ?? ''));
            $this->appendProtocol('SICHERHEITSABBRUCH: ' . (string) ($sceneState['error'] ?? 'Scene.Type unbekannt'));
            return;
        }

        $globalType = $sceneState['type'] ?? null;
        $cgiTypeBefore = $sceneState['cgiType'] ?? null;
        $this->appendProtocol(
            'VideoAnalyseGlobal Scene.Type vorher: RPC2=' . ($globalType === null ? '<null>' : (string) $globalType) .
            ', CGI=' . ($cgiTypeBefore === null ? '<nicht ausgegeben>' : ((string) $cgiTypeBefore === '' ? '<leer>' : (string) $cgiTypeBefore))
        );

        $normalized = strtolower(trim((string) ($globalType ?? '')));
        if ($normalized === '' || $normalized === '0') {
            // Exakte Originaltabelle sichern. Auf dieser Taurus/Web5-Firmware
            // repräsentiert RPC2 den inaktiven Smart-Plan als Scene.Type=null,
            // während CGI die Zeile häufig komplett weglässt.
            $originalGlobalTable = $sceneState['table'] ?? null;
            if (!is_array($originalGlobalTable)) {
                $this->setResult('FEHLER – vollständiges VideoAnalyseGlobal-Backup fehlt. Keine IVS-Änderung vorgenommen.');
                return;
            }
            $backupJson = json_encode($originalGlobalTable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($backupJson) || $backupJson === '') {
                $this->setResult('FEHLER – VideoAnalyseGlobal-Backup konnte nicht erzeugt werden.');
                return;
            }
            $this->WriteAttributeString('OriginalVideoAnalyseGlobalRpc2', $backupJson);
            $this->WriteAttributeString('OriginalGlobalSceneType', (string) ($globalType ?? ''));

            // Diese Taurus/Web5-Firmware hängt beim CGI-setConfig-Aufruf für
            // Scene.Type teilweise fest. Der bereits erfolgreich verifizierte
            // RPC2-configManager.setConfig-Weg wird deshalb für den Write benutzt.
            // CGI bleibt als unabhängiger Readback bestehen.
            $candidateGlobalTable = $originalGlobalTable;
            $candidateGlobalTable[0]['Scene']['Type'] = 'Normal';
            $setGlobal = $this->setVideoAnalyseGlobalTableViaRpc2($candidateGlobalTable);
            if (!($setGlobal['ok'] ?? false)) {
                $this->setResult('FEHLER – RPC2 konnte Scene.Type=Normal nicht setzen: ' . (string) ($setGlobal['error'] ?? 'unbekannt'));
                return;
            }
            $this->appendProtocol('RPC2 WRITE: VideoAnalyseGlobal Scene.Type=Normal -> akzeptiert.');

            // Primär muss RPC2 den geschriebenen Wert exakt bestätigen.
            // CGI dient zusätzlich als Gegenprüfung, darf bei dieser Firmware
            // aber nicht mehr den Ablauf blockieren.
            $verifyState = $this->readVideoAnalyseSceneState();
            $verifyRpcType = $verifyState['type'] ?? null;
            $verifyCgiType = $verifyState['cgiType'] ?? null;
            if (!($verifyState['ok'] ?? false) || $verifyRpcType !== 'Normal') {
                $restore = $this->restoreVideoAnalyseGlobalTable($originalGlobalTable);
                $this->setResult('FEHLER – Scene.Type=Normal wurde per RPC2 nicht bestätigt.');
                $this->appendProtocol(
                    'SICHERHEITSABBRUCH: Readback RPC2=' . json_encode($verifyRpcType) .
                    ', CGI=' . json_encode($verifyCgiType) .
                    ', Rollback=' . (($restore['ok'] ?? false) ? 'OK' : 'FEHLER')
                );
                return;
            }

            $this->WriteAttributeBoolean('GlobalChangedByModule', true);
            $this->appendProtocol(
                'DAHUA VERIFY: Scene.Type=Normal per RPC2 bestätigt; CGI=' .
                ($verifyCgiType === null ? '<nicht ausgegeben>' : (string) $verifyCgiType) . '.'
            );
        } elseif ($normalized === 'normal') {
            $this->appendProtocol('DAHUA VERIFY: Scene.Type=Normal war bereits aktiv.');
        } else {
            $this->setResult('STOP – Kamera nutzt bereits einen anderen AI-Smart-Plan (' . (string) $globalType . '). Es wurde nichts umgestellt.');
            $this->appendProtocol('Abbruch zum Schutz vorhandener AI-Konfiguration.');
            return;
        }

        $sens = $this->setNormalVideoAnalyseSensitivityViaRpc2(10);
        if (!($sens['ok'] ?? false)) {
            $error = 'FEHLER – P03 Sensitivity=10 konnte nicht sicher gesetzt/verifiziert werden: ' . (string) ($sens['error'] ?? 'unbekannt');
            $this->rollbackPreparedP03Config($error);
            $this->setResult($error);
            return;
        }
        $this->appendProtocol('P03 Sensitivity=10 gesetzt und per RPC2 rückgelesen.');

        $rules = GateTestLogic::parseRules($rulesRaw['body']);
        $this->appendProtocol('IVS-Regeln vor Test: ' . $this->summarizeRules($rules));

        // Diese Taurus/Web5-Firmware (u.a. 3.140.0000000.21.R auf
        // IPC-PDW3849-A180-AS-PV) lässt neue IVS-Regeln über den alten
        // configManager-Schreibweg nicht zuverlässig anlegen. Vorhandene
        // CrossLineDetection-Regeln können jedoch sauber gelesen und ihre
        // Events über eventManager.cgi empfangen werden.
        //
        // Deshalb: vorhandene Tripwire automatisch wiederverwenden. Ist noch
        // keine vorhanden, keinerlei weitere Schreibversuche an der Kamera.
        $createdNow = false;
        // Ausschließlich die eigene P03-Regel wiederverwenden.
        // Fremde CrossLine-Regeln dürfen weder übernommen noch verändert werden.
        $idx = GateTestLogic::findRuleIndex($rules, self::RULE_NAME);

        if ($idx === null) {
            $this->appendProtocol('Keine CrossLineDetection vorhanden. Lege P03 über den bestätigten Web5/RPC2-Konfigurationsweg an.');
            $createdRpc = $this->createP03TripwireViaRpc2();
            if (!($createdRpc['ok'] ?? false)) {
                $error = 'FEHLER – automatische RPC2-Tripwire konnte nicht sicher angelegt werden: ' . (string) ($createdRpc['error'] ?? 'unbekannt');
                $this->rollbackPreparedP03Config($error);
                $this->setResult($error . '. Kamera wurde auf den Ausgangszustand zurückgesetzt.');
                return;
            }
            $createdNow = true;
            $idx = (int) ($createdRpc['index'] ?? -1);
            if ($idx < 0) {
                $error = 'FEHLER – RPC2-Tripwire wurde bestätigt, aber der Regelindex konnte nicht bestimmt werden.';
                $this->rollbackPreparedP03Config($error);
                $this->setResult($error);
                return;
            }
            $rulesRaw = $this->cameraGet('/cgi-bin/configManager.cgi?action=getConfig&name=VideoAnalyseRule');
            $rules = $rulesRaw['ok'] ? GateTestLogic::parseRules($rulesRaw['body']) : [];
            $this->appendProtocol('P03 wurde automatisch über RPC2 angelegt und per CGI rückgelesen.');
        }

        $rule = $rules[$idx] ?? [];
        if (strtolower((string) ($rule['Enable'] ?? 'false')) !== 'true') {
            $error = 'IVS-TRIPWIRE GEFUNDEN, ABER DEAKTIVIERT – P03 wird nicht getestet.';
            $this->appendProtocol('CrossLineDetection gefunden auf Index ' . $idx . ', aber Enable=' . (string) ($rule['Enable'] ?? '<fehlt>'));
            $this->rollbackPreparedP03Config($error);
            $this->setResult($error);
            return;
        }

        $this->WriteAttributeInteger('RuleIndex', $idx);
        $this->WriteAttributeBoolean('RuleCreatedByModule', $createdNow || $this->ReadAttributeBoolean('Rpc2RuleCreatedByModule'));
        $this->appendProtocol(($createdNow ? 'Neu erzeugte' : 'Vorhandene') . ' Tripwire wird verwendet: Index ' . $idx . ', Name=' . (string) ($rule['Name'] ?? '<ohne Name>'));

        // Scene.Type wurde oben bereits über den von Dahua dokumentierten
        // HTTP-API-Pfad gesetzt und rückgelesen. Jetzt erfolgt eine unabhängige
        // Vollprüfung über CGI + RPC2 + Web5-Capabilities.
        $smartPlanChangedNow = $this->ReadAttributeBoolean('GlobalChangedByModule');
        $audit = $this->auditP03CameraConfiguration($idx);
        if (!($audit['ok'] ?? false)) {
            $error = 'KAMERA-KONFIGURATION NICHT FREIGEGEBEN – ' . (string) ($audit['error'] ?? 'Audit fehlgeschlagen') . '. Nicht laufen.';
            $this->rollbackPreparedP03Config($error);
            $this->setResult($error);
            return;
        }

        if ($auditOnly) {
            $this->WriteAttributeBoolean('TestActive', false);
            $this->SetValue('TestActive', false);
            $this->setReady(false);
            $this->setResult('KAMERA-KONFIGURATION OK – P03, Scene.Type=Normal, ObjectTypes=Unknown, Sensitivity=10 und Geometrie wurden mehrfach rückgelesen. Noch nicht laufen; erst Test starten.');
            $this->appendProtocol('AUDIT-ONLY: Konfiguration ist vorbereitet und geprüft; Zähler bleibt absichtlich 0.');
            return;
        }
        // Prüfen, ob das Aktivieren von IVS die bestehende SmartMotion-Personenerkennung ausgeschaltet hat.
        if ($smartBefore !== null && strtolower($smartBefore) === 'true') {
            $smartAfterRaw = $this->cameraGet('/cgi-bin/configManager.cgi?action=getConfig&name=SmartMotionDetect');
            $smartAfter = $smartAfterRaw['ok']
                ? GateTestLogic::configValue($smartAfterRaw['body'], 'SmartMotionDetect[0].Enable')
                : null;
            if ($smartAfter !== null && strtolower($smartAfter) !== 'true') {
                $error = 'STOP – IVS und bestehende SMD-Personenerkennung kollidieren auf dieser Firmware.';
                $this->appendProtocol('SICHERHEITSABBRUCH: SmartMotionDetect wurde durch IVS deaktiviert.');
                $this->rollbackPreparedP03Config($error);
                $this->setResult($error . ' Ausgangszustand wiederhergestellt.');
                return;
            }
        }

        $this->WriteAttributeBoolean('TestActive', true);
        $this->SetValue('TestActive', true);
        $this->setResult('REGEL BEREIT – Eventstream verbindet noch …');
        $this->appendProtocol('P03-Test nutzt CrossLineDetection auf Index ' . $idx . '.');
        $this->appendProtocol($createdNow
            ? 'Die P03-Linie wurde durch das Testmodul über RPC2 angelegt und vollständig rückgelesen.'
            : 'Die vorhandene Liniengeometrie der Kamera wird unverändert verwendet.');
        $this->appendProtocol('TESTVARIANTE P03: generische CrossLine mit ObjectTypes=Unknown, MinSize=0, Type=ByLength, Sensitivity=10. Die Linie liegt exakt auf der markierten HOME↔Lagerplatz-Grenze. SmartMotionHuman dient nur als zeitlich versetzte Personenbestätigung.');
        $this->appendProtocol('TESTFOLGE: 1 HOME→LAGER, 2 LAGER→HOME, 3 HOME→LAGER, 4 LAGER→HOME. Jeweils normal vollständig über die rote P03-Grenze gehen.');
        $this->appendProtocol('Eventzuordnung: Dahua event.index ist Kanalindex 0 und wird nicht mit dem IVS-Regelindex verwechselt.');

        // Wenn die Tripwire gerade neu angelegt wurde, MUSS der Dahua-
        // Eventstream neu verbunden werden. Bei dieser Firmware wurde der Stream
        // bisher bereits vor dem RPC2-ADD aufgebaut; ein laufendes codes=[All]
        // Abonnement übernimmt neu hinzugekommene IVS-Regeln nicht zuverlässig.
        if ($createdNow || $smartPlanChangedNow || !$this->ReadAttributeBoolean('Streaming')) {
            if ($createdNow || $smartPlanChangedNow) {
                $this->appendProtocol('Eventstream wird nach P03-/Smart-Plan-Änderung zwingend neu aufgebaut.');
            }
            $this->Reconnect();
            $this->WriteAttributeBoolean('TestActive', true);
            $this->SetValue('TestActive', true);
        }
        $this->refreshReadyState();
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
        $direction = P03ProofEngine::directionStatus(
            json_decode($this->ReadAttributeString('P03DirectionMap'), true) ?: []
        );

        $ready = $this->ReadAttributeBoolean('P03MultiAuditPassed')
            && $this->ReadAttributeBoolean('P03SimulationPassed')
            && ($direction['stable'] ?? false)
            && $this->ReadAttributeInteger('P03VerifiedCount') >= 4
            && $this->ReadAttributeInteger('RuleIndex') >= 0
            && $this->ReadAttributeInteger('AuxJVPersonVarID') > 0
            && $this->ReadAttributeInteger('AuxWorkPersonVarID') > 0;

        if (!$ready) {
            $this->WriteAttributeBoolean('ProductionEnabled', false);
            $this->refreshProductionState();
            $this->setResult('P03 Produktivbetrieb gesperrt – zuerst Gesamtaudit + mindestens 4 verifizierte Mehrkamera-Übergänge mit stabiler Richtungszuordnung.');
            return;
        }

        $this->WriteAttributeBoolean('TestActive', false);
        $this->SetValue('TestActive', false);
        $this->WriteAttributeBoolean('ProductionEnabled', true);
        $this->subscribeP03AuxVariables();
        $this->refreshProductionState();
        $this->setResult('P03 PRODUKTIV – Mehrkamera-Proof aktiv. Nur VERIFIED/STRONG_VERIFIED erzeugt einen Zonenwechsel.');
        $this->appendProtocol('PRODUKTION: P03 Mehrkamera-Proof aktiviert.');
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
        $this->WriteAttributeBoolean('TestActive', false);
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
            'ruleCreatedByModule' => $this->ReadAttributeBoolean('RuleCreatedByModule'),
            'globalChangedByModule' => $this->ReadAttributeBoolean('GlobalChangedByModule'),
            'rpc2GlobalChangedByModule' => $this->ReadAttributeBoolean('Rpc2GlobalChangedByModule'),
            'testActive' => $this->ReadAttributeBoolean('TestActive'),
            'auxJVInstanceID' => $this->ReadAttributeInteger('AuxJVInstanceID'),
            'auxWorkInstanceID' => $this->ReadAttributeInteger('AuxWorkInstanceID'),
            'auxJVPersonVarID' => $this->ReadAttributeInteger('AuxJVPersonVarID'),
            'auxWorkPersonVarID' => $this->ReadAttributeInteger('AuxWorkPersonVarID'),
            'p03HumanEvents' => $this->getP03HumanEvents(),
            'p03PendingCrossings' => $this->getP03PendingCrossings(),
            'p03DirectionMap' => json_decode($this->ReadAttributeString('P03DirectionMap'), true),
            'p03VerifiedCount' => $this->ReadAttributeInteger('P03VerifiedCount'),
            'p03SimulationPassed' => $this->ReadAttributeBoolean('P03SimulationPassed'),
            'p03MultiAuditPassed' => $this->ReadAttributeBoolean('P03MultiAuditPassed'),
            'crossings' => $this->getCrossings()
        ];
        $this->SendDebug('GateTestState', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
    }

    private function processEventData(string $chunk): void
    {
        $carry = $this->GetBuffer('EventCarry');
        $events = DahuaEventParser::feed($chunk, $carry);
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

            // Dahua eventManager "index" is the video channel. RuleID identifies
            // the concrete P03 tripwire on this camera.
            $ruleIndex = $this->ReadAttributeInteger('RuleIndex');
            $eventRuleId = $event['ruleId'] ?? null;
            if ($eventRuleId !== null && is_numeric($eventRuleId) && $ruleIndex >= 0
                && (int) $eventRuleId !== $ruleIndex) {
                if ($this->ReadAttributeBoolean('TestActive')) {
                    $this->appendProtocol('DIAG IVS ignoriert: fremde CrossLine RuleID=' . (string) $eventRuleId . ', erwartet=' . $ruleIndex);
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
        $this->evaluateP03Pending();
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
                'name' => 'OUT primary',
                'cross' => ['ts' => $base, 'direction' => 'RightToLeft'],
                'events' => [
                    ['id' => 'j1', 'source' => P03ProofEngine::SRC_JV_LEFT, 'ts' => $base - 8],
                    ['id' => 'w1', 'source' => P03ProofEngine::SRC_WORK_LEFT, 'ts' => $base + 6]
                ],
                'now' => $base + 7,
                'state' => P03ProofEngine::STATE_VERIFIED,
                'action' => P03ProofEngine::ACTION_HOME_TO_LAGER
            ],
            [
                'name' => 'IN primary',
                'cross' => ['ts' => $base, 'direction' => 'LeftToRight'],
                'events' => [
                    ['id' => 'w1', 'source' => P03ProofEngine::SRC_WORK_LEFT, 'ts' => $base - 7],
                    ['id' => 'j1', 'source' => P03ProofEngine::SRC_JV_LEFT, 'ts' => $base + 5]
                ],
                'now' => $base + 6,
                'state' => P03ProofEngine::STATE_VERIFIED,
                'action' => P03ProofEngine::ACTION_LAGER_TO_HOME
            ],
            [
                'name' => 'OUT terrace fallback',
                'cross' => ['ts' => $base, 'direction' => 'RightToLeft'],
                'events' => [
                    ['id' => 't1', 'source' => P03ProofEngine::SRC_TERRACE, 'ts' => $base - 40],
                    ['id' => 'w1', 'source' => P03ProofEngine::SRC_WORK_LEFT, 'ts' => $base + 8]
                ],
                'now' => $base + 9,
                'state' => P03ProofEngine::STATE_VERIFIED,
                'action' => P03ProofEngine::ACTION_HOME_TO_LAGER
            ],
            [
                'name' => 'false crossline without human',
                'cross' => ['ts' => $base, 'direction' => 'RightToLeft'],
                'events' => [],
                'now' => $base + 61,
                'state' => P03ProofEngine::STATE_UNKNOWN,
                'action' => null
            ],
            [
                'name' => 'incomplete one-side proof',
                'cross' => ['ts' => $base, 'direction' => 'RightToLeft'],
                'events' => [
                    ['id' => 'j1', 'source' => P03ProofEngine::SRC_JV_LEFT, 'ts' => $base - 5]
                ],
                'now' => $base + 61,
                'state' => P03ProofEngine::STATE_PROVISIONAL,
                'action' => null
            ],
            [
                'name' => 'contradictory simultaneous traffic',
                'cross' => ['ts' => $base, 'direction' => 'RightToLeft'],
                'events' => [
                    ['id' => 'jpre', 'source' => P03ProofEngine::SRC_JV_LEFT, 'ts' => $base - 7],
                    ['id' => 'wpre', 'source' => P03ProofEngine::SRC_WORK_LEFT, 'ts' => $base - 6],
                    ['id' => 'jpost', 'source' => P03ProofEngine::SRC_JV_LEFT, 'ts' => $base + 6],
                    ['id' => 'wpost', 'source' => P03ProofEngine::SRC_WORK_LEFT, 'ts' => $base + 7]
                ],
                'now' => $base + 8,
                'state' => P03ProofEngine::STATE_CONTRADICTION,
                'action' => null
            ]
        ];

        foreach ($cases as $case) {
            $result = P03ProofEngine::evaluate(
                $case['cross'],
                $case['events'],
                (float) $case['now'],
                30.0,
                60.0
            );
            $pass = ($result['state'] ?? null) === $case['state']
                && ($result['action'] ?? null) === $case['action'];
            $details[] = $case['name'] . '=' . ($pass ? 'OK' : 'FAIL');
            $ok = $ok && $pass;
        }

        $map = [];
        $map = P03ProofEngine::learnDirection($map, 'RightToLeft', P03ProofEngine::ACTION_HOME_TO_LAGER);
        $map = P03ProofEngine::learnDirection($map, 'RightToLeft', P03ProofEngine::ACTION_HOME_TO_LAGER);
        $map = P03ProofEngine::learnDirection($map, 'LeftToRight', P03ProofEngine::ACTION_LAGER_TO_HOME);
        $map = P03ProofEngine::learnDirection($map, 'LeftToRight', P03ProofEngine::ACTION_LAGER_TO_HOME);
        $direction = P03ProofEngine::directionStatus($map);
        $dirPass = ($direction['stable'] ?? false) === true;
        $details[] = 'direction learning=' . ($dirPass ? 'OK' : 'FAIL');
        $ok = $ok && $dirPass;

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
        $this->evaluateP03Pending();
    }

    /** @param array<string,mixed> $event */
    private function recordP03Crossing(array $event, ?string $direction): void
    {
        $pending = $this->getP03PendingCrossings();
        $now = microtime(true);
        $eventId = trim((string) ($event['eventId'] ?? ''));
        $id = $eventId !== '' ? ('cross:event:' . $eventId) : ('cross:' . sprintf('%.6f', $now));

        foreach ($pending as $candidate) {
            if (($candidate['id'] ?? '') === $id) {
                return;
            }
        }

        $candidate = [
            'id' => $id,
            'ts' => $now,
            'direction' => $direction,
            'eventId' => $event['eventId'] ?? null,
            'ruleId' => $event['ruleId'] ?? null,
            'objectId' => $event['objectId'] ?? null
        ];
        $pending[] = $candidate;
        if (count($pending) > 20) {
            $pending = array_slice($pending, -20);
        }
        $this->setP03PendingCrossings($pending);

        $this->SetValue('P03ProofState', 'PENDING – CrossLine wartet auf Kamerabestätigung');
        $this->appendProtocol(
            'P03 CROSS id=' . $id
                . ' Direction=' . ($direction ?? '<fehlt>')
                . ' – wartet auf HOME/LAGER Human-Beweis'
        );
        $this->SetTimerInterval('P03ProofTimer', 1000);
        $this->evaluateP03Pending();
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
        $pending = $this->getP03PendingCrossings();
        if ($pending === []) {
            $this->SetTimerInterval('P03ProofTimer', 0);
            return;
        }

        $events = $this->getP03HumanEvents();
        $remaining = [];
        $now = microtime(true);
        $near = max(5, $this->ReadPropertyInteger('NearProofWindowSeconds'));
        $terrace = max($near, $this->ReadPropertyInteger('TerraceProofWindowSeconds'));

        foreach ($pending as $cross) {
            $result = P03ProofEngine::evaluate($cross, $events, $now, (float) $near, (float) $terrace);
            $state = (string) ($result['state'] ?? P03ProofEngine::STATE_UNKNOWN);

            if ($state === P03ProofEngine::STATE_PENDING) {
                $remaining[] = $cross;
                continue;
            }

            if (in_array($state, [P03ProofEngine::STATE_VERIFIED, P03ProofEngine::STATE_STRONG_VERIFIED], true)) {
                $events = $this->markP03HumanEventsUsed($events, $result['usedEventIds'] ?? [], (string) ($cross['id'] ?? ''));

                $map = json_decode($this->ReadAttributeString('P03DirectionMap'), true);
                if (!is_array($map)) {
                    $map = [];
                }
                $map = P03ProofEngine::learnDirection(
                    $map,
                    isset($cross['direction']) ? (string) $cross['direction'] : null,
                    isset($result['action']) ? (string) $result['action'] : null
                );
                $this->WriteAttributeString('P03DirectionMap', json_encode($map));

                $verified = $this->ReadAttributeInteger('P03VerifiedCount') + 1;
                $this->WriteAttributeInteger('P03VerifiedCount', $verified);
                $this->SetValue('P03VerifiedTransfers', $verified);

                $proofText = $this->formatP03Proof($cross, $result);
                $this->SetValue('P03ProofState', $state);
                $this->SetValue('P03LastProof', $proofText);
                $this->appendProtocol('P03 PROOF ' . $state . ' ' . $proofText);
                $this->onP03VerifiedTransfer($cross, $result);
                continue;
            }

            if ($state === P03ProofEngine::STATE_CONTRADICTION) {
                $this->WriteAttributeBoolean('CoverageDebt', true);
                $this->SetValue('P03ProofState', 'CONTRADICTION – kein Übergang gebucht');
                $this->SetValue('P03LastProof', $this->formatP03Proof($cross, $result));
                $this->appendProtocol('P03 CONTRADICTION – CrossLine verworfen; keine Presence-Änderung.');
                continue;
            }

            // PROVISIONAL / UNKNOWN are explicitly non-committing.
            $this->SetValue('P03ProofState', $state . ' – kein Übergang gebucht');
            $this->SetValue('P03LastProof', $this->formatP03Proof($cross, $result));
            $this->appendProtocol('P03 ' . $state . ' – unvollständiger Beweis; keine Presence-Änderung.');
        }

        $this->setP03HumanEvents($events);
        $this->setP03PendingCrossings($remaining);
        $this->refreshP03DirectionStatus();

        if ($remaining === []) {
            $this->SetTimerInterval('P03ProofTimer', 0);
        } else {
            $this->SetTimerInterval('P03ProofTimer', 1000);
        }

        $dir = P03ProofEngine::directionStatus(
            json_decode($this->ReadAttributeString('P03DirectionMap'), true) ?: []
        );
        if ($this->ReadAttributeBoolean('TestActive')
            && ($dir['stable'] ?? false)
            && $this->ReadAttributeInteger('P03VerifiedCount') >= 4) {
            $this->WriteAttributeBoolean('TestActive', false);
            $this->SetValue('TestActive', false);
            $this->setReady(false);
            $this->setResult('P03 MEHRKAMERA VERIFIZIERT – mindestens 4 bestätigte Übergänge und stabile CrossLine-Richtungen. Produktivbetrieb kann freigegeben werden.');
            $this->appendProtocol('P03 COMMISSIONING COMPLETE: Mehrkamera-Proof + Richtungslernen stabil.');
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
        return $action . ' via ' . $path . ' / CrossLine=' . $direction;
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

        $jv = $this->findAla2CameraInstance($jvHost, ['JV Links (Lagerplatz)', 'Lagerplatz JV links', 'JV Links Lagerplatz']);
        $work = $this->findAla2CameraInstance($workHost, ['Lagerplatz Werkstatt links', 'Werkstatt links', 'P03 – Lagerplatz Werkstatt links']);

        if ($work <= 0 && $allowCreate && $this->ReadPropertyBoolean('AutoCreateWorkObserver') && $jv > 0) {
            $work = $this->createWorkObserverFromCamera($jv, $workHost);
        }

        $this->WriteAttributeInteger('AuxJVInstanceID', $jv);
        $this->WriteAttributeInteger('AuxWorkInstanceID', $work);

        $jvVar = $jv > 0 ? $this->findPersonDetectedVariable($jv) : 0;
        $workVar = $work > 0 ? $this->findPersonDetectedVariable($work) : 0;
        $this->updateP03AuxSubscription('AuxJVPersonVarID', $jvVar);
        $this->updateP03AuxSubscription('AuxWorkPersonVarID', $workVar);

        return ['ok' => $jvVar > 0 && $workVar > 0, 'jv' => $jv, 'work' => $work];
    }

    private function findAla2CameraInstance(string $host, array $names): int
    {
        $fallback = 0;
        foreach (IPS_GetInstanceListByModuleID(self::ALA2_MODULE_GUID) as $id) {
            $id = (int) $id;
            if ($id <= 0 || !IPS_InstanceExists($id)) {
                continue;
            }
            try {
                $candidateHost = trim((string) IPS_GetProperty($id, 'CameraHost'));
                if ($host !== '' && $candidateHost === $host) {
                    return $id;
                }
            } catch (Throwable $e) {
            }

            $name = trim((string) IPS_GetName($id));
            foreach ($names as $expected) {
                if (strcasecmp($name, (string) $expected) === 0) {
                    $fallback = $id;
                    break;
                }
            }
        }
        return $fallback;
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

    private function subscribeP03AuxVariables(): void
    {
        foreach (['AuxJVPersonVarID', 'AuxWorkPersonVarID'] as $attr) {
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

    private function createWorkObserverFromCamera(int $credentialSourceID, string $host): int
    {
        if ($credentialSourceID <= 0 || !IPS_InstanceExists($credentialSourceID) || $host === '') {
            return 0;
        }

        try {
            $port = max(1, (int) IPS_GetProperty($credentialSourceID, 'CameraPort'));
            $username = (string) IPS_GetProperty($credentialSourceID, 'Username');
            $password = (string) IPS_GetProperty($credentialSourceID, 'Password');
        } catch (Throwable $e) {
            return 0;
        }

        if ($username === '' || $password === '') {
            return 0;
        }

        // Exactly one credential probe. No brute-force retries against Dahua.
        $probe = $this->genericCameraGet($host, $port, $username, $password, '/cgi-bin/magicBox.cgi?action=getDeviceType');
        if (!($probe['ok'] ?? false)) {
            $this->appendProtocol('P03 Werkstatt-links: vorhandene JV-IPC-Zugangsdaten passen nicht auf ' . $host . '; keine weiteren Login-Versuche.');
            return 0;
        }

        try {
            $id = IPS_CreateInstance(self::ALA2_MODULE_GUID);
            IPS_SetName($id, 'P03 – Lagerplatz Werkstatt links');
            IPS_SetProperty($id, 'Enabled', true);
            IPS_SetProperty($id, 'CameraHost', $host);
            IPS_SetProperty($id, 'CameraPort', $port);
            IPS_SetProperty($id, 'Username', $username);
            IPS_SetProperty($id, 'Password', $password);
            IPS_SetProperty($id, 'LightAutomationEnabled', false);
            IPS_SetProperty($id, 'DebugEvents', false);
            IPS_ApplyChanges($id);
            $this->appendProtocol('P03 Werkstatt-links: reine Personenerkennungs-Instanz automatisch angelegt (#' . $id . ', Lichtautomatik AUS).');
            return $id;
        } catch (Throwable $e) {
            $this->appendProtocol('P03 Werkstatt-links Instanz konnte nicht automatisch angelegt werden: ' . $e->getMessage());
            return 0;
        }
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

    /** @return array{ok:bool,error:string,model:string,sensitivity:string} */
    private function auditP03AuxCamera(int $instanceID, string $role): array
    {
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return ['ok' => false, 'error' => $role . ': Instanz fehlt', 'model' => '', 'sensitivity' => ''];
        }

        try {
            $host = trim((string) IPS_GetProperty($instanceID, 'CameraHost'));
            $port = max(1, (int) IPS_GetProperty($instanceID, 'CameraPort'));
            $username = (string) IPS_GetProperty($instanceID, 'Username');
            $password = (string) IPS_GetProperty($instanceID, 'Password');
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $role . ': Kamerakonfiguration nicht lesbar', 'model' => '', 'sensitivity' => ''];
        }

        $type = $this->genericCameraGet($host, $port, $username, $password, '/cgi-bin/magicBox.cgi?action=getDeviceType');
        $model = '';
        if ($type['ok'] ?? false) {
            if (preg_match('/(?:^|\r?\n)type=([^\r\n]+)/i', (string) $type['body'], $m)) {
                $model = trim((string) $m[1]);
            }
        }

        $smart = $this->genericCameraGet($host, $port, $username, $password, '/cgi-bin/configManager.cgi?action=getConfig&name=SmartMotionDetect');
        if (!($smart['ok'] ?? false)) {
            return ['ok' => false, 'error' => $role . ': SmartMotionDetect nicht lesbar', 'model' => $model, 'sensitivity' => ''];
        }

        $raw = (string) $smart['body'];
        $enable = GateTestLogic::configValue($raw, 'SmartMotionDetect[0].Enable');
        $human = GateTestLogic::configValue($raw, 'SmartMotionDetect[0].ObjectTypes.Human');
        if ($human === null) {
            $object0 = GateTestLogic::configValue($raw, 'SmartMotionDetect[0].ObjectTypes[0]');
            $human = (strcasecmp((string) $object0, 'Human') === 0) ? 'true' : $human;
        }
        $sensitivity = (string) (GateTestLogic::configValue($raw, 'SmartMotionDetect[0].Sensitivity') ?? '');

        // Dahua Web 3.x documents Motion Detection as prerequisite for SMD.
        // Newer firmware may not expose the same flat key, so only an explicit
        // "false" blocks the audit; a missing key is logged but not guessed.
        $motion = $this->genericCameraGet($host, $port, $username, $password, '/cgi-bin/configManager.cgi?action=getConfig&name=MotionDetect');
        $motionEnable = null;
        if ($motion['ok'] ?? false) {
            $motionEnable = GateTestLogic::configValue((string) $motion['body'], 'MotionDetect[0].Enable');
        }

        $personVar = $this->findPersonDetectedVariable($instanceID);
        $motionOK = $motionEnable === null || strtolower((string) $motionEnable) === 'true';
        $ok = strtolower((string) $enable) === 'true'
            && strtolower((string) $human) === 'true'
            && $motionOK
            && $personVar > 0;

        $this->appendProtocol(
            'P03 AUX AUDIT ' . $role
                . ': host=' . $host
                . ', model=' . ($model !== '' ? $model : '<nicht gemeldet>')
                . ', SMD=' . (string) $enable
                . ', Human=' . (string) $human
                . ', Sensitivity=' . ($sensitivity !== '' ? $sensitivity : '<nicht gemeldet>')
                . ', MotionDetect=' . ($motionEnable === null ? '<nicht gemeldet>' : (string) $motionEnable)
                . ', PersonVar=' . $personVar
                . ' -> ' . ($ok ? 'OK' : 'FEHLER')
        );

        return [
            'ok' => $ok,
            'error' => $ok ? '' : ($role . ': SMD Human/PersonDetected nicht vollständig aktiv'),
            'model' => $model,
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
        $terrace = $this->ReadAttributeInteger('SourceInstanceID');
        $jv = $this->ReadAttributeInteger('AuxJVInstanceID');
        $work = $this->ReadAttributeInteger('AuxWorkInstanceID');
        $jvVar = $this->ReadAttributeInteger('AuxJVPersonVarID');
        $workVar = $this->ReadAttributeInteger('AuxWorkPersonVarID');

        $this->SetValue(
            'P03CameraStatus',
            'Terrasse=' . ($terrace > 0 ? 'OK#' . $terrace : 'FEHLT')
                . ' | JV-links=' . ($jvVar > 0 ? 'OK#' . $jv : 'FEHLT')
                . ' | Werkstatt-links=' . ($workVar > 0 ? 'OK#' . $work : 'FEHLT')
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

    /** @param array<string,mixed> $event */
    private function processProductionCrossLine(array $event, ?string $direction): void
    {
        $action = strtolower(trim((string) ($event['action'] ?? '')));
        if (!in_array($action, ['start', 'on', 'pulse'], true)) {
            return;
        }

        $portalAction = null;
        if ($direction === 'RightToLeft') {
            $portalAction = 'OUT';
        } elseif ($direction === 'LeftToRight') {
            $portalAction = 'IN';
        }
        if ($portalAction === null) {
            $this->SetValue('PresenceLastEvent', 'P03 Richtung unbekannt: ' . ($direction ?? '<fehlt>'));
            $this->WriteAttributeBoolean('CoverageDebt', true);
            $this->refreshProductionState();
            return;
        }

        // Nur die produktiv angelegte P03-Regel verarbeiten.
        $ruleId = $event['ruleId'] ?? null;
        $ruleIndex = $this->ReadAttributeInteger('RuleIndex');
        if ($ruleId !== null && is_numeric($ruleId) && $ruleIndex >= 0 && (int) $ruleId !== $ruleIndex) {
            return;
        }

        $key = $this->eventKey($event, $direction);
        $seen = json_decode($this->ReadAttributeString('SeenProductionEventKeys'), true);
        if (!is_array($seen)) {
            $seen = [];
        }
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = time();
        if (count($seen) > 200) {
            $seen = array_slice($seen, -200, null, true);
        }
        $this->WriteAttributeString('SeenProductionEventKeys', json_encode($seen));

        // 3-s-Reorder/Contradiction-Schutz. Beim Test trat einmal für dasselbe
        // Objekt im selben Moment LeftToRight UND RightToLeft auf. Solche Paare
        // dürfen den Presence-Ledger nicht verändern.
        $pending = json_decode($this->GetBuffer('ProductionPending'), true);
        if (!is_array($pending)) {
            $pending = [];
        }

        $now = microtime(true);
        $objectId = $event['objectId'] ?? null;
        if ($objectId !== null && $objectId !== '') {
            foreach ($pending as $idx => $candidate) {
                if ((string) ($candidate['objectId'] ?? '') !== (string) $objectId) {
                    continue;
                }
                $age = $now - (float) ($candidate['queuedAt'] ?? 0);
                if ($age > 3.5) {
                    continue;
                }

                if (($candidate['portalAction'] ?? '') === $portalAction) {
                    // Gleiche Richtung für dasselbe Objekt = Dublette.
                    return;
                }

                // Gegensätzliche Richtungen im selben Reorder-Fenster:
                // beide verwerfen und Beobachtungsschuld setzen.
                unset($pending[$idx]);
                $pending = array_values($pending);
                $this->SetBuffer('ProductionPending', json_encode($pending));
                $this->WriteAttributeBoolean('CoverageDebt', true);
                $this->SetValue(
                    'PresenceLastEvent',
                    date('H:i:s') . ' P03 CONTRADICTION – Objekt ' . (string) $objectId .
                    ' meldete IN und OUT innerhalb 3 s'
                );
                $this->appendProtocol(
                    'PRESENCE P03 CONTRADICTION objectId=' . (string) $objectId .
                    ' – beide Richtungsereignisse verworfen, CoverageDebt=true'
                );
                $this->refreshProductionState();
                if ($pending === []) {
                    $this->SetTimerInterval('ProductionCommitTimer', 0);
                }
                return;
            }
        }

        $pending[] = [
            'queuedAt' => $now,
            'portalAction' => $portalAction,
            'direction' => $direction,
            'human' => (bool) ($event['human'] ?? false),
            'classification' => $event['classification'] ?? null,
            'eventId' => $event['eventId'] ?? null,
            'ruleId' => $event['ruleId'] ?? null,
            'objectId' => $objectId
        ];
        $this->SetBuffer('ProductionPending', json_encode($pending));
        $this->SetTimerInterval('ProductionCommitTimer', 1000);
    }

    public function ProductionCommitTimer(): void
    {
        $pending = json_decode($this->GetBuffer('ProductionPending'), true);
        if (!is_array($pending) || $pending === []) {
            $this->SetTimerInterval('ProductionCommitTimer', 0);
            return;
        }

        $now = microtime(true);
        $remaining = [];
        foreach ($pending as $candidate) {
            if (($now - (float) ($candidate['queuedAt'] ?? 0)) < 3.0) {
                $remaining[] = $candidate;
                continue;
            }
            $this->commitProductionCrossing($candidate);
        }

        $this->SetBuffer('ProductionPending', json_encode($remaining));
        if ($remaining === []) {
            $this->SetTimerInterval('ProductionCommitTimer', 0);
        }
    }

    /** @param array<string,mixed> $candidate */
    private function commitProductionCrossing(array $candidate): void
    {
        if (!$this->ReadAttributeBoolean('ProductionEnabled')) {
            return;
        }

        $portalAction = (string) ($candidate['portalAction'] ?? '');
        if (!in_array($portalAction, ['IN', 'OUT'], true)) {
            return;
        }

        $coverageDebt = $this->ReadAttributeBoolean('CoverageDebt');
        $unknown = max(0, $this->ReadAttributeInteger('UnknownOccupants'));
        $balance = $this->ReadAttributeInteger('PortalBalance');

        if ($coverageDebt) {
            // Solange der Startbestand nicht verifiziert ist, dürfen Portalereignisse
            // nicht als reale Personenanzahl interpretiert werden. Wir führen nur eine
            // technische Netto-Bilanz seit Aktivierung/Resync.
            $balance += ($portalAction === 'IN') ? 1 : -1;
            $this->WriteAttributeInteger('PortalBalance', $balance);
        } else {
            if ($portalAction === 'IN') {
                $unknown++;
            } elseif ($unknown > 0) {
                $unknown--;
            }
            $this->WriteAttributeInteger('UnknownOccupants', $unknown);
        }

        $this->WriteAttributeInteger('LastProductionEvent', time());

        $classification = trim((string) ($candidate['classification'] ?? ''));
        $suffix = $classification !== '' ? ' / ' . $classification : '';
        $detail = $coverageDebt
            ? 'Portalbilanz=' . (($balance >= 0) ? '+' : '') . $balance . ', Resync offen'
            : 'UnknownOccupants=' . $unknown;
        $this->SetValue(
            'PresenceLastEvent',
            date('H:i:s') . ' P03 ' . $portalAction . $suffix . ' (' . $detail . ')'
        );
        $this->appendProtocol(
            'PRESENCE P03 ' . $portalAction .
            ' Direction=' . (string) ($candidate['direction'] ?? '') .
            ' Human=' . (($candidate['human'] ?? false) ? 'true' : 'false') .
            ' ' . $detail
        );

        $this->refreshProductionState();
    }

    private function refreshProductionState(): void
    {
        $enabled = $this->ReadAttributeBoolean('ProductionEnabled');
        $unknown = max(0, $this->ReadAttributeInteger('UnknownOccupants'));

        if (!$enabled) {
            $this->SetValue('PresenceSystemState', 'P03 INAKTIV');
            $this->SetValue('HouseStatus', 'UNBEKANNT');
            $this->SetValue('PresentPersons', '–');
            return;
        }

        $streaming = $this->ReadAttributeBoolean('Streaming');
        $coverageDebt = $this->ReadAttributeBoolean('CoverageDebt');
        $this->SetValue(
            'PresenceSystemState',
            $streaming
                ? ($coverageDebt ? 'P03 AKTIV – Resync/weitere Beweise nötig' : 'P03 AKTIV – Grundbetrieb')
                : 'P03 AKTIV – Eventstream wird aufgebaut'
        );

        if ($coverageDebt) {
            $balance = $this->ReadAttributeInteger('PortalBalance');
            $this->SetValue('HouseStatus', 'UNBEKANNT – Resync erforderlich');
            $this->SetValue(
                'PresentPersons',
                'Startbestand unbekannt; Portalbilanz ' . (($balance >= 0) ? '+' : '') . $balance
            );
            return;
        }

        if ($unknown > 0) {
            $this->SetValue('HouseStatus', 'BELEGT – mindestens ' . $unknown . ' unbekannte Person(en)');
            $this->SetValue('PresentPersons', 'Unbekannt × ' . $unknown);
        } else {
            // Sicherheitsregel: 0 anonyme Tokens ist noch KEIN Leerstandsnachweis.
            $this->SetValue('HouseStatus', 'UNBEKANNT – Leerstand nicht zertifiziert');
            $this->SetValue('PresentPersons', 'keine sicher bestätigten Personen');
        }
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
                $headers[] = 'Authorization: ' . DahuaDigest::buildAuthorization(
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
        if (!$this->ReadPropertyBoolean('Enabled') || !$this->cameraConfigurationReady()) {
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
        return DahuaDigest::parseChallenge(trim((string) $m[1]));
    }

    private function resolveSourceInstance(): int
    {
        $manual = $this->ReadPropertyInteger('SourceCameraInstanceID');
        if ($manual > 0 && IPS_InstanceExists($manual)) {
            $inst = IPS_GetInstance($manual);
            if (($inst['ModuleInfo']['ModuleID'] ?? '') === self::ALA2_MODULE_GUID) {
                return $manual;
            }
        }
        if (!$this->ReadPropertyBoolean('AutoDiscover')) {
            return 0;
        }

        $fallback = 0;
        foreach (IPS_GetInstanceListByModuleID(self::ALA2_MODULE_GUID) as $id) {
            if (!IPS_InstanceExists((int) $id)) {
                continue;
            }
            $name = IPS_GetName((int) $id);
            if (strcasecmp(trim($name), 'JV Terrasse') === 0) {
                return (int) $id;
            }
            try {
                $host = trim((string) IPS_GetProperty((int) $id, 'CameraHost'));
                if ($host === '192.168.107.110') {
                    $fallback = (int) $id;
                }
            } catch (Throwable $e) {
            }
        }
        if ($fallback > 0) {
            return $fallback;
        }

        // Sicherheitsnetz: nicht nur nach Modul-GUID suchen. Damit bleibt die
        // Ein-Klick-Erkennung auch dann funktionsfähig, wenn eine lokale Kopie
        // des Außenlichtmoduls mit anderer GUID verwendet wird.
        if (function_exists('IPS_GetInstanceList')) {
            foreach (IPS_GetInstanceList() as $id) {
                $id = (int) $id;
                if ($id <= 0 || !IPS_InstanceExists($id)) {
                    continue;
                }

                $name = trim((string) IPS_GetName($id));
                if (strcasecmp($name, 'JV Terrasse') === 0) {
                    try {
                        $host = trim((string) IPS_GetProperty($id, 'CameraHost'));
                        if ($host !== '') {
                            return $id;
                        }
                    } catch (Throwable $e) {
                    }
                }

                try {
                    $host = trim((string) IPS_GetProperty($id, 'CameraHost'));
                    if ($host === '192.168.107.110') {
                        return $id;
                    }
                } catch (Throwable $e) {
                }
            }
        }

        return 0;
    }

    /** @return array{host:string,port:int,username:string,password:string}|array{} */
    private function cameraConfiguration(): array
    {
        $sourceID = $this->ReadAttributeInteger('SourceInstanceID');
        if ($sourceID <= 0 || !IPS_InstanceExists($sourceID)) {
            $sourceID = $this->resolveSourceInstance();
            if ($sourceID > 0) {
                $this->WriteAttributeInteger('SourceInstanceID', $sourceID);
            }
        }
        if ($sourceID <= 0 || !IPS_InstanceExists($sourceID)) {
            return [];
        }
        try {
            return [
                'host' => trim((string) IPS_GetProperty($sourceID, 'CameraHost')),
                'port' => max(1, (int) IPS_GetProperty($sourceID, 'CameraPort')),
                'username' => (string) IPS_GetProperty($sourceID, 'Username'),
                'password' => (string) IPS_GetProperty($sourceID, 'Password')
            ];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function cameraConfigurationReady(): bool
    {
        $cfg = $this->cameraConfiguration();
        return ($cfg['host'] ?? '') !== '' && ($cfg['username'] ?? '') !== '' && ($cfg['password'] ?? '') !== '';
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
                $this->rpc2Call($host, $port, $session, 99, 'global.logout', null);
                return ['ok' => true, 'error' => '', 'index' => (int) $i];
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
        $this->appendProtocol('RPC2 P03 VERIFY: OK, Index=' . $newIndex . ', Id=' . $newId . ', ObjectTypes=Unknown, MinSize=0, Type=ByLength, Direction=Both.');
        $this->appendProtocol('HINWEIS P03: Tripwire läuft generisch mit ObjectTypes=Unknown. SmartMotionHuman wird separat als zeitversetzte Personenbestätigung protokolliert.');
        $this->appendProtocol('P03 Geometrie (HOME↔Lagerplatz-Grenze): ' . json_encode(self::P03_LINE, JSON_UNESCAPED_SLASHES) . '.');
        return ['ok' => true, 'error' => '', 'index' => $newIndex];
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
            IPS_SetProperty($parentID, 'Open', $this->ReadPropertyBoolean('Enabled'));
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
        $ruleReady = $this->ReadAttributeInteger('RuleIndex') >= 0;
        $stream = $this->ReadAttributeBoolean('Streaming');
        $active = $this->ReadAttributeBoolean('TestActive');
        $auxReady = $this->ReadAttributeInteger('AuxJVPersonVarID') > 0
            && $this->ReadAttributeInteger('AuxWorkPersonVarID') > 0;
        $preflight = $this->ReadAttributeBoolean('P03MultiAuditPassed')
            && $this->ReadAttributeBoolean('P03SimulationPassed');

        $ready = $ruleReady && $stream && $active && $auxReady && $preflight;
        $this->setReady($ready);
        if ($ready && $this->GetValue('CrossingCount') === 0) {
            $this->setResult(
                'BEREIT – P03 Mehrkamera-Test aktiv: CrossLine JV Terrasse + Human-Bestätigung JV-links/Werkstatt-links. Normal HOME→LAGER und zurück gehen.'
            );
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
