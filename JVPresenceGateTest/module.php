<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/DahuaDigest.php';
require_once dirname(__DIR__) . '/libs/DahuaEventParser.php';
require_once dirname(__DIR__) . '/libs/GateTestLogic.php';

class JVPresenceGateTest extends IPSModule
{
    private const CLIENT_SOCKET_GUID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const SOCKET_TX_GUID = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const ALA2_MODULE_GUID = '{5E08D4EE-9727-4682-A23E-E8625EB2337E}';
    private const IM_CHANGESTATUS_ID = 10505;
    private const RULE_NAME = 'P05_HOME_STREET';
    // Aktuelle rote P05-Grenzlinie aus dem Bild vom 07.10.2026:
    // Sie deckt jetzt neben dem Schiebetor auch die links liegende Personentür ab.
    // Bild 1536x691 px, per Skeleton/RDP auf die gezeichnete rote Polylinie reduziert.
    // Pixel ca.: (911,78) -> (923,79) -> (930,96) -> (973,129) -> (1104,203)
    // Dahua-IVS-Normkoordinaten 0..8191:
    private const P05_LINE = [
        [4858, 925],
        [4922, 936],
        [4959, 1138],
        [5189, 1529],
        [5887, 2406]
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Enabled', true);
        $this->RegisterPropertyBoolean('AutoDiscover', true);
        $this->RegisterPropertyInteger('SourceCameraInstanceID', 0);

        // Exakt aus der vom Nutzer rot markierten P05-Linie im aktuellen JV-Hof-Garage-Bild abgeleitet.
        // Bild 1536x691 px -> Dahua-IVS-Koordinaten 0..8191.
        // Aktuelle Polylinie umfasst Schiebetor + linke Personentür.
        // Legacy-Endpunkt-Properties bleiben nur aus Kompatibilitätsgründen vorhanden;
        // der RPC2-Writer verwendet ausschließlich self::P05_LINE.
        // Konzeptuell: OUT = Richtung Straße = rechts/oben; IN = links/unten.
        $this->RegisterPropertyInteger('LineAX', 4858);
        $this->RegisterPropertyInteger('LineAY', 925);
        $this->RegisterPropertyInteger('LineBX', 5887);
        $this->RegisterPropertyInteger('LineBY', 2406);

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

        $this->RegisterVariableBoolean('StreamOK', 'Dahua Eventstream OK', '~Switch', 10);
        $this->RegisterVariableBoolean('Ready', 'Test bereit', '~Switch', 20);
        $this->RegisterVariableBoolean('TestActive', 'Test läuft', '~Switch', 30);
        $this->RegisterVariableInteger('CrossingCount', 'Grenzübertritte erkannt', '', 40);
        $this->RegisterVariableString('Result', 'Ergebnis / Nächster Schritt', '', 50);
        $this->RegisterVariableString('LastEvent', 'Letztes IVS-Ereignis', '', 60);
        $this->RegisterVariableString('Protocol', 'Testprotokoll', '', 70);

        $this->RegisterTimer('HandshakeTimer', 0, 'JVGATE_HandshakeTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('SocketRestartTimer', 0, 'JVGATE_SocketRestartTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('Watchdog', 15000, 'JVGATE_Watchdog($_IPS["TARGET"]);');

        $this->RequireParent(self::CLIENT_SOCKET_GUID);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('HandshakeTimer', 0);
        $this->SetTimerInterval('SocketRestartTimer', 0);
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

        $sourceID = $this->resolveSourceInstance();
        $this->WriteAttributeInteger('SourceInstanceID', $sourceID);
        if ($sourceID <= 0) {
            $this->setResult('NICHT BEREIT – bestehende Instanz JV Hof Garage wurde nicht gefunden.');
            return;
        }

        $this->syncParentSocket();
        $this->updateParentSubscription($this->getParentID());
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
            $this->setResult('FEHLER – JV Hof Garage konnte nicht automatisch gefunden werden. Im Feld darunter kann die Instanz notfalls manuell gewählt werden.');
            return;
        }

        $this->syncParentSocket();
        $this->updateParentSubscription($this->getParentID());

        $cfg = $this->cameraConfiguration();
        if (($cfg['host'] ?? '') === '' || ($cfg['username'] ?? '') === '' || ($cfg['password'] ?? '') === '') {
            $this->setResult('FEHLER – Kamerakonfiguration konnte nicht automatisch aus JV Hof Garage übernommen werden.');
            return;
        }

        $this->appendProtocol('=== P05 SCHIEBETOR TEST ===');
        $this->appendProtocol('Quelle: JV Hof Garage / ' . $cfg['host'] . ':' . $cfg['port']);
        $this->appendProtocol('Keine Zugangsdaten werden im Protokoll ausgegeben.');

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
            $this->appendProtocol('Vorhandene, vom Testmodul erzeugte P05-Regel wird vor dem neuen Lauf auf den gesicherten Originalzustand zurückgesetzt.');
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

        $globalRaw = $this->cameraGet('/cgi-bin/configManager.cgi?action=getConfig&name=VideoAnalyseGlobal');
        if (!$globalRaw['ok']) {
            $this->setResult('FEHLER – VideoAnalyseGlobal konnte über die Dahua-HTTP-API nicht gelesen werden: ' . $globalRaw['error']);
            return;
        }
        $globalType = GateTestLogic::configValue($globalRaw['body'], 'VideoAnalyseGlobal[0].Scene.Type');
        if ($globalType === null) {
            $this->setResult('FEHLER – Dahua liefert VideoAnalyseGlobal[0].Scene.Type nicht zurück. Keine IVS-Änderung vorgenommen.');
            $this->appendProtocol('SICHERHEITSABBRUCH: Scene.Type konnte vor dem Schreiben nicht eindeutig gelesen werden.');
            return;
        }

        $this->appendProtocol('VideoAnalyseGlobal Scene.Type vorher: ' . ($globalType === '' ? '<leer>' : $globalType));
        $normalized = strtolower(trim($globalType));
        if ($normalized === '' || $normalized === '0') {
            // Laut Dahua HTTP API ist dies der dokumentierte Aktivierungspfad
            // für die Normal-IVS-Szene:
            // configManager.cgi?action=setConfig&VideoAnalyseGlobal[0].Scene.Type=Normal
            $this->WriteAttributeString('OriginalGlobalSceneType', $globalType);
            $setGlobal = $this->cameraSet(['VideoAnalyseGlobal[0].Scene.Type' => 'Normal']);
            if (!$setGlobal['ok']) {
                $this->setResult('FEHLER – Dahua hat Scene.Type=Normal nicht akzeptiert: ' . $setGlobal['error']);
                return;
            }

            $verifyGlobalRaw = $this->cameraGet('/cgi-bin/configManager.cgi?action=getConfig&name=VideoAnalyseGlobal');
            $verifyGlobalType = $verifyGlobalRaw['ok']
                ? GateTestLogic::configValue($verifyGlobalRaw['body'], 'VideoAnalyseGlobal[0].Scene.Type')
                : null;
            if ($verifyGlobalType !== 'Normal') {
                // Best effort sofort zurücksetzen, falls das Rücklesen nicht exakt stimmt.
                $this->cameraSet(['VideoAnalyseGlobal[0].Scene.Type' => $globalType]);
                $this->setResult('FEHLER – Scene.Type=Normal wurde nach dem Schreiben nicht eindeutig zurückgelesen.');
                $this->appendProtocol('SICHERHEITSABBRUCH: Scene.Type Readback=' . json_encode($verifyGlobalType));
                return;
            }

            $this->WriteAttributeBoolean('GlobalChangedByModule', true);
            $this->appendProtocol('DAHUA HTTP API VERIFY: VideoAnalyseGlobal[0].Scene.Type=Normal -> OK.');
        } elseif ($normalized === 'normal') {
            $this->appendProtocol('DAHUA HTTP API VERIFY: Scene.Type=Normal war bereits aktiv.');
        } else {
            $this->setResult('STOP – Kamera nutzt bereits einen anderen AI-Smart-Plan (' . $globalType . '). Es wurde nichts umgestellt.');
            $this->appendProtocol('Abbruch zum Schutz vorhandener AI-Konfiguration.');
            return;
        }

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
        $idx = GateTestLogic::findRuleIndex($rules, self::RULE_NAME);
        if ($idx === null) {
            foreach ($rules as $candidateIdx => $candidateRule) {
                if (strcasecmp((string) ($candidateRule['Type'] ?? ''), 'CrossLineDetection') === 0) {
                    $idx = (int) $candidateIdx;
                    break;
                }
            }
        }

        if ($idx === null) {
            $this->appendProtocol('Keine CrossLineDetection vorhanden. Lege P05 über den bestätigten Web5/RPC2-Konfigurationsweg an.');
            $createdRpc = $this->createP05TripwireViaRpc2();
            if (!($createdRpc['ok'] ?? false)) {
                $this->setResult('FEHLER – automatische RPC2-Tripwire konnte nicht sicher angelegt werden: ' . (string) ($createdRpc['error'] ?? 'unbekannt') . '. Kamera wurde soweit möglich auf den Ausgangszustand zurückgesetzt.');
                return;
            }
            $createdNow = true;
            $idx = (int) ($createdRpc['index'] ?? -1);
            if ($idx < 0) {
                $this->setResult('FEHLER – RPC2-Tripwire wurde bestätigt, aber der Regelindex konnte nicht bestimmt werden.');
                return;
            }
            $rulesRaw = $this->cameraGet('/cgi-bin/configManager.cgi?action=getConfig&name=VideoAnalyseRule');
            $rules = $rulesRaw['ok'] ? GateTestLogic::parseRules($rulesRaw['body']) : [];
            $this->appendProtocol('P05 wurde automatisch über RPC2 angelegt und per CGI rückgelesen.');
        }

        $rule = $rules[$idx] ?? [];
        if (strtolower((string) ($rule['Enable'] ?? 'false')) !== 'true') {
            $this->setResult('IVS-TRIPWIRE GEFUNDEN, ABER DEAKTIVIERT – bitte die Tripwire in der Kamera aktivieren und danach erneut starten.');
            $this->appendProtocol('CrossLineDetection gefunden auf Index ' . $idx . ', aber Enable=' . (string) ($rule['Enable'] ?? '<fehlt>'));
            return;
        }

        $this->WriteAttributeInteger('RuleIndex', $idx);
        $this->WriteAttributeBoolean('RuleCreatedByModule', $createdNow || $this->ReadAttributeBoolean('Rpc2RuleCreatedByModule'));
        $this->appendProtocol(($createdNow ? 'Neu erzeugte' : 'Vorhandene') . ' Tripwire wird verwendet: Index ' . $idx . ', Name=' . (string) ($rule['Name'] ?? '<ohne Name>'));

        // Scene.Type wurde oben bereits über den von Dahua dokumentierten
        // HTTP-API-Pfad gesetzt und rückgelesen. Jetzt erfolgt eine unabhängige
        // Vollprüfung über CGI + RPC2 + Web5-Capabilities.
        $smartPlanChangedNow = $this->ReadAttributeBoolean('GlobalChangedByModule');
        $audit = $this->auditP05CameraConfiguration($idx);
        if (!($audit['ok'] ?? false)) {
            $this->WriteAttributeBoolean('TestActive', false);
            $this->SetValue('TestActive', false);
            $this->setReady(false);
            $this->setResult('KAMERA-KONFIGURATION NICHT FREIGEGEBEN – ' . (string) ($audit['error'] ?? 'Audit fehlgeschlagen') . '. Nicht laufen.');
            return;
        }

        if ($auditOnly) {
            $this->WriteAttributeBoolean('TestActive', false);
            $this->SetValue('TestActive', false);
            $this->setReady(false);
            $this->setResult('KAMERA-KONFIGURATION OK – P05, Scene.Type=Normal, Human-Filter und Geometrie wurden mehrfach rückgelesen. Heute kein Lauftest nötig.');
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
                $this->appendProtocol('SICHERHEITSABBRUCH: SmartMotionDetect wurde durch IVS deaktiviert. Ausgangszustand wird zurückgesetzt.');
                $this->cameraSet(["VideoAnalyseRule[0][$idx].Enable" => 'false']);
                $this->restoreGlobalSceneType();
                $this->setResult('STOP – IVS und bestehende SMD-Personenerkennung kollidieren auf dieser Firmware. Ausgangszustand wiederhergestellt.');
                return;
            }
        }

        $this->WriteAttributeBoolean('TestActive', true);
        $this->SetValue('TestActive', true);
        $this->setResult('REGEL BEREIT – Eventstream verbindet noch …');
        $this->appendProtocol('P05-Test nutzt CrossLineDetection auf Index ' . $idx . '.');
        $this->appendProtocol($createdNow
            ? 'Die P05-Linie wurde durch das Testmodul über RPC2 angelegt und vollständig rückgelesen.'
            : 'Die vorhandene Liniengeometrie der Kamera wird unverändert verwendet.');
        $this->appendProtocol('TESTFOLGE: 1 OUT, 2 IN, 3 OUT, 4 IN. Jeweils normal vollständig über die Linie gehen.');
        $this->appendProtocol('Eventzuordnung: Dahua event.index ist Kanalindex 0 und wird nicht mit dem IVS-Regelindex verwechselt.');

        // Wenn die Tripwire gerade neu angelegt wurde, MUSS der Dahua-
        // Eventstream neu verbunden werden. Bei dieser Firmware wurde der Stream
        // bisher bereits vor dem RPC2-ADD aufgebaut; ein laufendes codes=[All]
        // Abonnement übernimmt neu hinzugekommene IVS-Regeln nicht zuverlässig.
        if ($createdNow || $smartPlanChangedNow || !$this->ReadAttributeBoolean('Streaming')) {
            if ($createdNow || $smartPlanChangedNow) {
                $this->appendProtocol('Eventstream wird nach P05-/Smart-Plan-Änderung zwingend neu aufgebaut.');
            }
            $this->Reconnect();
            $this->WriteAttributeBoolean('TestActive', true);
            $this->SetValue('TestActive', true);
        }
        $this->refreshReadyState();
    }

    public function ResetTest(): void
    {
        $this->WriteAttributeString('SeenEventKeys', '{}');
        $this->WriteAttributeString('Crossings', '[]');
        $this->WriteAttributeBoolean('TestActive', false);
        $this->SetValue('TestActive', false);
        $this->SetValue('CrossingCount', 0);
        $this->SetValue('LastEvent', '');
        $this->SetValue('Protocol', '');
        $this->setReady(false);
        $this->setResult('Zurückgesetzt. „Test vorbereiten & starten“ drücken.');
    }

    public function CleanupCameraTestConfig(): void
    {
        if ($this->ReadAttributeBoolean('Rpc2GlobalChangedByModule')) {
            $restoreGlobal = $this->restoreOriginalVideoAnalyseGlobalViaRpc2();
            $this->appendProtocol(($restoreGlobal['ok'] ?? false)
                ? 'IVS-Smart-Plan entfernt; ursprüngliche VideoAnalyseGlobal-Tabelle vollständig wiederhergestellt.'
                : 'WARNUNG: ursprüngliche VideoAnalyseGlobal-Tabelle konnte nicht automatisch wiederhergestellt werden: ' . (string) ($restoreGlobal['error'] ?? 'unbekannt'));
        }
        if ($this->ReadAttributeBoolean('Rpc2RuleCreatedByModule')) {
            $restore = $this->restoreOriginalVideoAnalyseRuleViaRpc2();
            $this->appendProtocol(($restore['ok'] ?? false)
                ? 'P05-Testregel entfernt; ursprüngliche VideoAnalyseRule-Tabelle vollständig wiederhergestellt.'
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
            if (strcasecmp($code, 'CrossLineDetection') !== 0) {
                // Diagnose nur während des laufenden Tests: damit erkennen wir,
                // ob der Eventstream nach dem Regelwechsel grundsätzlich weiter
                // Personen-/Bewegungsereignisse liefert, auch falls IVS selbst
                // noch nicht feuert.
                if ($this->ReadAttributeBoolean('TestActive')) {
                    $action = strtolower(trim((string) ($event['action'] ?? '')));
                    if (in_array($action, ['start', 'on', 'pulse'], true)
                        && in_array(strtolower($code), [
                            'smartmotionhuman',
                            'videomotion',
                            'smartmotionvehicle',
                            'crossregiondetection'
                        ], true)) {
                        $this->appendProtocol('DIAG EVENT code=' . $code . ' action=' . (string) ($event['action'] ?? '') . ' index=' . (string) ($event['index'] ?? ''));
                    }
                }
                continue;
            }

            // Dahua eventManager 'index' ist der Video-Kanalindex, nicht der
            // Index in VideoAnalyseRule. Bei dieser Kamera ist index=0, während
            // P05 als Regel [3] angelegt wird. Daher nicht gegen RuleIndex filtern.

            $direction = GateTestLogic::directionFromEvent($event);
            $summary = [
                'time' => date('H:i:s'),
                'code' => $event['code'],
                'action' => $event['action'],
                'index' => $event['index'],
                'human' => $event['human'],
                'direction' => $direction,
                'eventId' => $event['eventId'],
                'ruleId' => $event['ruleId'],
                'groupId' => $event['groupId'],
                'objectId' => $event['objectId']
            ];
            $this->SetValue('LastEvent', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->appendProtocol('IVS ' . json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->SendDebug('CrossLineDetection', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' | RAW=' . $this->singleLine((string) $event['raw']), 0);

            if (!$this->ReadAttributeBoolean('TestActive')) {
                continue;
            }
            $action = strtolower(trim((string) $event['action']));
            if (!in_array($action, ['start', 'on', 'pulse'], true)) {
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
            if (count($seen) > 100) {
                $seen = array_slice($seen, -100, null, true);
            }
            $this->WriteAttributeString('SeenEventKeys', json_encode($seen));

            $crossings = $this->getCrossings();
            $step = count($crossings) + 1;
            if ($step > 4) {
                continue;
            }
            $expected = ($step % 2 === 1) ? 'OUT' : 'IN';
            $crossings[] = [
                'step' => $step,
                'expected' => $expected,
                'time' => time(),
                'direction' => $direction,
                'eventId' => $event['eventId'],
                'ruleId' => $event['ruleId'],
                'index' => $event['index']
            ];
            $this->WriteAttributeString('Crossings', json_encode($crossings));
            $this->SetValue('CrossingCount', count($crossings));
            $this->appendProtocol('GEZÄHLT Schritt ' . $step . ' erwartet=' . $expected . ' Direction=' . ($direction ?? '<fehlt>'));

            $evaluation = GateTestLogic::evaluateFourCrossings($crossings);
            if (($evaluation['complete'] ?? false) === true) {
                $this->WriteAttributeBoolean('TestActive', false);
                $this->SetValue('TestActive', false);
                $this->setReady(false);

                if (($evaluation['valid'] ?? false) === true) {
                    $result = 'FERTIG – P05 eindeutig: OUT=' . $evaluation['outDirection'] . ', IN=' . $evaluation['inDirection'] . '. Testprotokoll hier hochladen.';
                    $this->setResult($result);
                    $this->appendProtocol($result);
                } else {
                    $result = 'TEST BEENDET, aber Zuordnung noch nicht eindeutig: ' . ($evaluation['message'] ?? 'unbekannt') . '. Testprotokoll hier hochladen.';
                    $this->setResult($result);
                    $this->appendProtocol($result);
                }
            } else {
                $next = count($crossings) + 1;
                $nextExpected = ($next % 2 === 1) ? 'OUT' : 'IN';
                $this->setResult('Schritt ' . count($crossings) . '/4 erkannt. Als Nächstes: ' . $nextExpected . ' durch das Schiebetor.');
            }
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
            if (strcasecmp(trim($name), 'JV Hof Garage') === 0) {
                return (int) $id;
            }
            try {
                $host = trim((string) IPS_GetProperty((int) $id, 'CameraHost'));
                if ($host === '192.168.107.111') {
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
                if (strcasecmp($name, 'JV Hof Garage') === 0) {
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
                    if ($host === '192.168.107.111') {
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
    private function createP05TripwireViaRpc2(): array
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
            if (is_array($rule) && strcasecmp((string) ($rule['Type'] ?? ''), 'CrossLineDetection') === 0) {
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
                'DetectLine' => self::P05_LINE,
                'Direction' => 'Both',
                'LaneNumber' => null,
                'SizeFilter' => [
                    'MaxSize' => [8191, 8191],
                    'MinSize' => [200, 200],
                    'Type' => 'ByLength'
                ],
                // Kamera-Caps melden TriggerPosition=false für CrossLineDetection.
            ],
            'Enable' => true,
            'EventHandler' => $eventHandler,
            'Id' => $newId,
            'Name' => self::RULE_NAME,
            'ObjectTypes' => ['Human'],
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
        $this->appendRpc2Result('ADD P05 setConfig VideoAnalyseRule', $write);
        if (!($write['ok'] ?? false)) {
            $this->rpc2Call($host, $port, $session, 13, 'configManager.setConfig', ['name' => 'VideoAnalyseRule', 'table' => $original, 'options' => []]);
            $this->rpc2Call($host, $port, $session, 99, 'global.logout', null);
            return ['ok' => false, 'error' => 'Kamera hat das Hinzufügen der P05-Regel abgelehnt'];
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
                        && $line === self::P05_LINE
                        && strtolower((string) ($rule['Config']['Direction'] ?? '')) === 'both'
                        && in_array('Human', is_array($objects) ? $objects : [], true)
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
            return ['ok' => false, 'error' => 'Rückleseprüfung der neuen P05-Regel fehlgeschlagen; Rollback ausgeführt'];
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
        $this->appendProtocol('RPC2 P05 VERIFY: OK, Index=' . $newIndex . ', Id=' . $newId . ', Human=true, Direction=Both.');
        $this->appendProtocol('P05 Geometrie (Schiebetor + Personentür): ' . json_encode(self::P05_LINE, JSON_UNESCAPED_SLASHES) . '.');
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
     * Unabhängige P05-Konfigurationsprüfung ohne Lauftest.
     * Prüft dieselben Daten über die dokumentierte CGI-API, RPC2 und Web5-Caps.
     * @return array{ok:bool,error:string}
     */
    private function auditP05CameraConfiguration(int $idx): array
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
                && $human === 'Human') {
                $checks++;
                $this->appendProtocol('AUDIT OK 2: CGI P05 Name/Type/Enable/Direction/Class/Human korrekt.');
            } else {
                $errors[] = 'CGI P05-Basisdaten abweichend';
                $this->appendProtocol('AUDIT FEHLER 2: ' . json_encode([
                    'name' => $name, 'type' => $type, 'enable' => $enable,
                    'direction' => $direction, 'class' => $class, 'object0' => $human
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            $lineOk = true;
            foreach (self::P05_LINE as $p => $xy) {
                $x = GateTestLogic::configValue($rulesRaw['body'], $prefix . '.Config.DetectLine[' . $p . '][0]');
                $y = GateTestLogic::configValue($rulesRaw['body'], $prefix . '.Config.DetectLine[' . $p . '][1]');
                if ((string) $x !== (string) $xy[0] || (string) $y !== (string) $xy[1]) {
                    $lineOk = false;
                    break;
                }
            }
            if ($lineOk) {
                $checks++;
                $this->appendProtocol('AUDIT OK 3: CGI P05-Polylinie vollständig 5/5 Punkte korrekt.');
            } else {
                $errors[] = 'CGI P05-Polylinie weicht ab';
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
                && (($rpcRule['Config']['DetectLine'] ?? null) === self::P05_LINE)
                && in_array('Human', is_array($rpcRule['ObjectTypes'] ?? null) ? $rpcRule['ObjectTypes'] : [], true);

            if ($rpcRuleOk) {
                $checks++;
                $this->appendProtocol('AUDIT OK 6: RPC2 P05-Regel inkl. Human-Filter und 5-Punkt-Geometrie korrekt.');
            } else {
                $errors[] = 'RPC2 P05-Regel abweichend';
                if (is_array($rpcRule)) {
                    $this->appendProtocol('AUDIT RPC2 P05 IST: ' . json_encode($rpcRule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }

            // Alarm-/Sirenen-/Aufzeichnungs-Nebenwirkungen dürfen für P05 nicht aktiv sein.
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
                $this->appendProtocol('AUDIT OK 7: P05 hat keine Alarm-/Sirenen-/Record-/Snapshot-Nebenwirkung.');
            } else {
                $errors[] = 'P05 EventHandler enthält unerwünschte Nebenwirkung';
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
                && in_array('Human', is_array($crossCap['SupportedObjectTypes'] ?? null) ? $crossCap['SupportedObjectTypes'] : [], true)
                && ((int) ($cap['MaxPointOfLine'] ?? 0) >= count(self::P05_LINE))
                && (($crossCap['TriggerPosition'] ?? null) === false);

            if ($capOk) {
                $checks++;
                $this->appendProtocol('AUDIT OK 8: Web5-Caps bestätigen Normal + CrossLine + Human + mindestens 5 Linienpunkte; TriggerPosition=false.');
            } else {
                $errors[] = 'Web5-Capabilities passen nicht zur P05-Konfiguration';
                if (is_array($cap)) {
                    $this->appendProtocol('AUDIT WEB5 CAPS: ' . json_encode([
                        'SupportedScene' => $cap['SupportedScene'] ?? null,
                        'MaxPointOfLine' => $cap['MaxPointOfLine'] ?? null,
                        'CrossLineDetection' => $crossCap
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }

            // VideoAnalyseModule ist firmwareabhängig aufgebaut; lesbar muss es sein.
            $module = $this->rpc2Call($host, $port, $session, 94, 'configManager.getConfig', ['name' => 'VideoAnalyseModule']);
            if ($module['ok'] ?? false) {
                $checks++;
                $this->appendProtocol('AUDIT OK 9: VideoAnalyseModule über RPC2 lesbar.');
            } else {
                $errors[] = 'VideoAnalyseModule nicht lesbar';
            }

            $this->rpc2Call($host, $port, $session, 99, 'global.logout', null);
        }

        if ($errors === []) {
            $this->appendProtocol('AUDIT GESAMT: OK (' . $checks . ' Prüfungen). Kamera hat die P05-Konfiguration vollständig übernommen.');
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
                    'DetectLine' => self::P05_LINE,
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

    private function restoreGlobalSceneType(): void
    {
        if (!$this->ReadAttributeBoolean('GlobalChangedByModule')) {
            return;
        }
        $old = $this->ReadAttributeString('OriginalGlobalSceneType');
        // Exakten Ausgangswert zurückschreiben. Die Dahua-HTTP-API akzeptiert
        // für "kein aktiver IVS-Smart-Plan" auch einen leeren Scene.Type-Wert.
        $r = $this->cameraSet(['VideoAnalyseGlobal[0].Scene.Type' => $old]);
        $this->appendProtocol($r['ok']
            ? 'VideoAnalyseGlobal Scene.Type auf Ausgangswert zurückgesetzt.'
            : 'WARNUNG: Scene.Type konnte nicht zurückgesetzt werden: ' . $r['error']);
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
        $ready = $ruleReady && $stream && $active;
        $this->setReady($ready);
        if ($ready && $this->GetValue('CrossingCount') === 0) {
            $this->setResult('BEREIT – jetzt OUT → IN → OUT → IN durch das Schiebetor gehen.');
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
