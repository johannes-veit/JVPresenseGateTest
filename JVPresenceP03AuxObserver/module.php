<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/DahuaDigest.php';
require_once dirname(__DIR__) . '/libs/DahuaEventParser.php';
require_once dirname(__DIR__) . '/libs/P03AuxObserverLogic.php';

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

        $this->RegisterVariableBoolean('PersonDetected', 'Person erkannt', '~Switch', 10);
        $this->RegisterVariableBoolean('StreamOK', 'Dahua Eventstream OK', '~Switch', 20);
        $this->RegisterVariableInteger('HumanEventCounter', 'Human-Ereignisse', '', 25);
        $this->RegisterVariableString('LastEvent', 'Letztes Human-IVS-Ereignis', '', 30);
        $this->RegisterVariableString('LastIVSEvent', 'Letztes CrossRegion-Ereignis roh', '', 40);

        $this->RegisterTimer('HandshakeTimer', 0, 'JVP03AUX_HandshakeTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('SocketRestartTimer', 0, 'JVP03AUX_SocketRestartTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('Watchdog', 15000, 'JVP03AUX_Watchdog($_IPS["TARGET"]);');
        $this->RegisterTimer('PersonPulseTimer', 0, 'JVP03AUX_PersonPulseTimer($_IPS["TARGET"]);');

        $this->RequireParent(self::CLIENT_SOCKET_GUID);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Existing observer instances from v0.6.5-v0.6.8 must receive variables
        // added by later versions as well; Create() is not relied upon for migration.
        $this->RegisterVariableBoolean('PersonDetected', 'Person erkannt', '~Switch', 10);
        $this->RegisterVariableBoolean('StreamOK', 'Dahua Eventstream OK', '~Switch', 20);
        $this->RegisterVariableInteger('HumanEventCounter', 'Human-Ereignisse', '', 25);
        $this->RegisterVariableString('LastEvent', 'Letztes Human-IVS-Ereignis', '', 30);
        $this->RegisterVariableString('LastIVSEvent', 'Letztes CrossRegion-Ereignis roh', '', 40);

        $this->SetTimerInterval('HandshakeTimer', 0);
        $this->SetTimerInterval('SocketRestartTimer', 0);
        $this->SetTimerInterval('PersonPulseTimer', 0);
        $this->SetBuffer('HttpBuffer', '');
        $this->SetBuffer('EventCarry', '');
        $this->WriteAttributeBoolean('Streaming', false);
        $this->WriteAttributeBoolean('AuthPending', false);
        $this->WriteAttributeBoolean('LastRequestAuthenticated', false);
        $this->WriteAttributeBoolean('AuthBlocked', false);
        $this->WriteAttributeInteger('AuthFailureCount', 0);
        $this->WriteAttributeInteger('DigestNC', 0);
        $this->WriteAttributeString('DigestChallenge', '{}');
        $this->WriteAttributeInteger('HumanPulseUntil', 0);
        $this->WriteAttributeString('SeenEventKeys', '{}');
        $this->SetValue('PersonDetected', false);
        $this->SetValue('StreamOK', false);

        $this->syncParentSocket();
        $this->updateParentSubscription($this->getParentID());

        if ($this->ReadPropertyBoolean('Enabled') && $this->cameraConfigurationReady()) {
            $this->scheduleSocketRestart(300);
        }
    }

    public function GetConfigurationForParent(): string
    {
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
            return '';
        }

        $status = (int) $m[1];
        if ($status === 401) {
            $challenge = $this->extractDigestChallenge($header);
            if ($challenge === []) {
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
            $this->SetValue('StreamOK', false);
            return '';
        }

        if ($status === 200) {
            $this->WriteAttributeBoolean('Streaming', true);
            $this->WriteAttributeBoolean('AuthPending', false);
            $this->WriteAttributeBoolean('AuthBlocked', false);
            $this->WriteAttributeInteger('AuthFailureCount', 0);
            $this->SetValue('StreamOK', true);
            if ($body !== '') {
                $this->processEventData($body);
            }
            return '';
        }

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
            if (!$this->ReadAttributeBoolean('AuthBlocked')) {
                $this->SetTimerInterval('HandshakeTimer', 250);
            }
            return;
        }

        $this->WriteAttributeBoolean('Streaming', false);
        $this->SetValue('StreamOK', false);
        $this->clearPersonPulse();
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

            try {
                IPS_SetProperty($parentID, 'Host', trim($this->ReadPropertyString('CameraHost')));
                IPS_SetProperty($parentID, 'Port', max(1, $this->ReadPropertyInteger('CameraPort')));
                IPS_SetProperty($parentID, 'Open', true);
                IPS_ApplyChanges($parentID);
            } catch (Throwable $e) {
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
            $this->SetValue('StreamOK', false);
            $this->clearPersonPulse();
            if ($this->ReadAttributeInteger('SocketRestartStage') === 0
                && ($now - $this->ReadAttributeInteger('LastSocketRestart')) >= 20) {
                $this->scheduleSocketRestart(100);
            }
            return;
        }

        $lastRx = $this->ReadAttributeInteger('LastCameraRx');
        if ($this->ReadAttributeBoolean('Streaming') && $lastRx > 0 && ($now - $lastRx) > 25) {
            $this->WriteAttributeBoolean('Streaming', false);
            $this->SetValue('StreamOK', false);
            $this->clearPersonPulse();
            $this->WriteAttributeBoolean('AuthPending', false);
            $this->WriteAttributeBoolean('LastRequestAuthenticated', false);
            $this->WriteAttributeInteger('DigestNC', 0);
            $this->WriteAttributeString('DigestChallenge', '{}');
            $this->scheduleSocketRestart(100);
        }
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

    public function Reconnect(): void
    {
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
        $this->scheduleSocketRestart(100);
    }

    private function processEventData(string $chunk): void
    {
        $carry = $this->GetBuffer('EventCarry');
        $events = JVP03JVP03DahuaEventParser::feed($chunk, $carry);
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

            if (!P03AuxObserverLogic::isMatchingRuleEvent($event, $wantedName, $wantedIndex, $wantedId)) {
                continue;
            }

            $eventId = trim((string) ($event['eventId'] ?? ''));
            $key = $eventId !== ''
                ? ('event:' . $eventId . ':' . $action)
                : ('raw:' . sha1((string) ($event['raw'] ?? '') . ':' . $action));

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

            $summary = [
                'time' => date('H:i:s'),
                'role' => $this->ReadPropertyString('Role'),
                'code' => $code,
                'action' => $event['action'] ?? '',
                'ruleName' => $event['ruleName'] ?? null,
                'cfgRuleId' => $event['cfgRuleId'] ?? null,
                'ruleIdUpper' => $event['ruleIdUpper'] ?? null,
                'ruleIdLower' => $event['ruleIdLower'] ?? null,
                'human' => true,
                'humanFromRule' => true,
                'payloadHuman' => (bool) ($event['human'] ?? false),
                'classification' => $event['classification'] ?? null,
                'eventId' => $event['eventId'] ?? null,
                'objectId' => $event['objectId'] ?? null
            ];
            $this->SetValue('LastEvent', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            if (P03AuxObserverLogic::isHumanProofStart($event, $wantedName, $wantedIndex, $wantedId)) {
                $counterID = $this->GetIDForIdent('HumanEventCounter');
                if ($counterID > 0 && IPS_VariableExists($counterID)) {
                    $this->SetValue('HumanEventCounter', ((int) GetValue($counterID)) + 1);
                }

                $until = time() + 5;
                $this->WriteAttributeInteger('HumanPulseUntil', $until);
                $this->SetValue('PersonDetected', true);
                $this->SetTimerInterval('PersonPulseTimer', 5000);
                continue;
            }

            // Keep the 5-second pulse even if Dahua sends a STOP immediately.
            if (in_array($action, ['stop', 'off'], true)
                && $this->ReadAttributeInteger('HumanPulseUntil') <= time()) {
                $this->clearPersonPulse();
            }
        }
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
            'User-Agent: IP-Symcon-JVPresenceP03Aux/0.6.9',
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
        } catch (Throwable $e) {
        }
    }

    private function extractDigestChallenge(string $header): array
    {
        if (!preg_match('/^WWW-Authenticate:\s*(Digest\s+.+)$/im', $header, $m)) {
            return [];
        }
        return JVP03JVP03DahuaDigest::parseChallenge(trim((string) $m[1]));
    }

    private function cameraConfigurationReady(): bool
    {
        return trim($this->ReadPropertyString('CameraHost')) !== ''
            && trim($this->ReadPropertyString('Username')) !== ''
            && $this->ReadPropertyString('Password') !== '';
    }

    private function syncParentSocket(): void
    {
        $parentID = $this->getParentID();
        if ($parentID <= 0 || !IPS_InstanceExists($parentID)) {
            return;
        }

        try {
            IPS_SetProperty($parentID, 'Host', trim($this->ReadPropertyString('CameraHost')));
            IPS_SetProperty($parentID, 'Port', max(1, $this->ReadPropertyInteger('CameraPort')));
            IPS_SetProperty($parentID, 'Open', $this->ReadPropertyBoolean('Enabled') && $this->cameraConfigurationReady());
            IPS_ApplyChanges($parentID);
        } catch (Throwable $e) {
        }
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
