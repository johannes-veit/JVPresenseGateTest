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

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Enabled', true);
        $this->RegisterPropertyBoolean('AutoDiscover', true);
        $this->RegisterPropertyInteger('SourceCameraInstanceID', 0);

        // Vorbereitet anhand des vom Nutzer gelieferten unveränderten Bildes "JV Hof Garage".
        // Dahua-IVS-Koordinaten sind normiert auf 0..8191.
        $this->RegisterPropertyInteger('LineAX', 4400);
        $this->RegisterPropertyInteger('LineAY', 3000);
        $this->RegisterPropertyInteger('LineBX', 7600);
        $this->RegisterPropertyInteger('LineBY', 4300);

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
        $this->setResult('Installiert. Einmal „Test vorbereiten & starten“ drücken.');

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

    public function PrepareAndStartTest(): void
    {
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
        $globalType = $globalRaw['ok']
            ? GateTestLogic::configValue($globalRaw['body'], 'VideoAnalyseGlobal[0].Scene.Type')
            : null;
        $this->WriteAttributeString('OriginalGlobalSceneType', $globalType ?? '');
        $this->WriteAttributeBoolean('GlobalChangedByModule', false);

        if ($globalType !== null) {
            $this->appendProtocol('VideoAnalyseGlobal Scene.Type vorher: ' . ($globalType === '' ? '<leer>' : $globalType));
            $normalized = strtolower(trim($globalType));
            if ($normalized === '' || $normalized === '0') {
                $setGlobal = $this->cameraSet(['VideoAnalyseGlobal[0].Scene.Type' => 'Normal']);
                if ($setGlobal['ok']) {
                    $this->WriteAttributeBoolean('GlobalChangedByModule', true);
                    $this->appendProtocol('IVS Smart-Plan temporär auf Scene.Type=Normal gesetzt.');
                } else {
                    $this->appendProtocol('WARNUNG: Smart-Plan konnte nicht automatisch auf Normal gesetzt werden: ' . $setGlobal['error']);
                }
            } elseif ($normalized !== 'normal') {
                $this->setResult('STOP – Kamera nutzt bereits einen anderen AI-Smart-Plan (' . $globalType . '). Es wurde nichts umgestellt.');
                $this->appendProtocol('Abbruch zum Schutz vorhandener AI-Konfiguration.');
                return;
            }
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
            $this->probeModernVideoAnalyse();
            $this->setResult('EINMALIGE KAMERA-EINSTELLUNG NÖTIG – in JV Hof Garage unter KI/IVS eine Tripwire anlegen, Richtung Beide, Ziel Mensch. Danach hier erneut „Test vorbereiten & starten“ drücken.');
            $this->appendProtocol('Keine CrossLineDetection vorhanden. Kamera bleibt unverändert. Bitte einmal im Dahua-Webinterface eine Tripwire anlegen.');
            return;
        }

        $rule = $rules[$idx] ?? [];
        if (strtolower((string) ($rule['Enable'] ?? 'false')) !== 'true') {
            $this->setResult('IVS-TRIPWIRE GEFUNDEN, ABER DEAKTIVIERT – bitte die Tripwire in der Kamera aktivieren und danach erneut starten.');
            $this->appendProtocol('CrossLineDetection gefunden auf Index ' . $idx . ', aber Enable=' . (string) ($rule['Enable'] ?? '<fehlt>'));
            return;
        }

        $this->WriteAttributeInteger('RuleIndex', $idx);
        $this->WriteAttributeBoolean('RuleCreatedByModule', false);
        $this->appendProtocol('Vorhandene Tripwire wird verwendet: Index ' . $idx . ', Name=' . (string) ($rule['Name'] ?? '<ohne Name>'));
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
        $this->appendProtocol('P05-Test nutzt vorhandene CrossLineDetection auf Index ' . $idx . '.');
        $this->appendProtocol('Die Liniengeometrie wird von der Kamera übernommen; das Testmodul schreibt keine IVS-Regel.');
        $this->appendProtocol('TESTFOLGE: 1 OUT, 2 IN, 3 OUT, 4 IN. Jeweils normal vollständig über die Linie gehen.');

        if (!$this->ReadAttributeBoolean('Streaming')) {
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
        $idx = $this->ReadAttributeInteger('RuleIndex');
        if ($idx >= 0) {
            $result = $this->cameraSet(["VideoAnalyseRule[0][$idx].Enable" => 'false']);
            $this->appendProtocol($result['ok']
                ? 'P05-Testregel deaktiviert.'
                : 'WARNUNG: Testregel konnte nicht deaktiviert werden: ' . $result['error']);
        }
        $this->restoreGlobalSceneType();
        $this->WriteAttributeBoolean('TestActive', false);
        $this->SetValue('TestActive', false);
        $this->setReady(false);
        $this->setResult('Testregel deaktiviert / Smart-Plan soweit durch das Modul verändert zurückgesetzt.');
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
            if (strcasecmp((string) $event['code'], 'CrossLineDetection') !== 0) {
                continue;
            }

            $selectedRule = $this->ReadAttributeInteger('RuleIndex');
            $eventIndex = isset($event['index']) && $event['index'] !== '' ? (int) $event['index'] : null;
            if ($selectedRule >= 0 && $eventIndex !== null && $eventIndex !== $selectedRule) {
                continue;
            }

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
        $restoreValue = $old === '' ? '0' : $old;
        $r = $this->cameraSet(['VideoAnalyseGlobal[0].Scene.Type' => $restoreValue]);
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
