<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/DahuaDigest.php';
require_once dirname(__DIR__) . '/libs/DahuaEventParser.php';
require_once dirname(__DIR__) . '/libs/P03AuxObserverLogic.php';
require_once dirname(__DIR__) . '/libs/P03ExistingIvsLines.php';
require_once dirname(__DIR__) . '/libs/P03OnvifPullPoint.php';
require_once dirname(__DIR__) . '/libs/P03OnvifCorrelator.php';
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
        $this->RegisterPropertyInteger('RuleID', -1);    // Legacy-Diagnose/Fallback
        $this->RegisterPropertyString('ProofMode', 'EXISTING_STAIR_IVS');
        $this->RegisterPropertyString('AllowedIVSRules', '[]');

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
        $this->RegisterAttributeString('ObservedCodeCounts', '{}');
        $this->RegisterAttributeInteger('LastGenericWireProbeAt', 0);
        $this->RegisterAttributeInteger('LastGenericEventAuditAt', 0);
        $this->RegisterAttributeInteger('GenericHumanHintCount', 0);
        $this->RegisterAttributeInteger('SmdHumanRawCount', 0);
        $this->RegisterAttributeInteger('SmdHumanProofCount', 0);
        $this->RegisterAttributeInteger('LastHumanProofAt', 0);
        $this->RegisterAttributeString('LastHumanProofCode', '');
        $this->RegisterAttributeString('OnvifSubscriptionURL', '');
        $this->RegisterAttributeString('OnvifSubscriptionReference', '[]');
        $this->RegisterAttributeInteger('OnvifSubscriptionUntil', 0);
        $this->RegisterAttributeInteger('OnvifEventCount', 0);
        $this->RegisterAttributeInteger('OnvifHumanStairCount', 0);
        $this->RegisterAttributeString('OnvifSeenEventKeys', '{}');
        $this->RegisterAttributeString('OnvifCorrelationState', '{"classes":{},"crossings":{}}');
        $this->RegisterAttributeString('OnvifRecentEvents', '[]');
        $this->RegisterAttributeString('OnvifLastStatus', 'Noch nicht abgefragt');
        $this->RegisterAttributeInteger('OnvifNextAttemptAt', 0);
        $this->RegisterAttributeInteger('OnvifPollBusyUntil', 0);

        $this->RegisterVariableBoolean('PersonDetected', 'Person erkannt', '~Switch', 10);
        $this->RegisterVariableBoolean('StreamOK', 'Dahua Eventstream OK', '~Switch', 20);
        $this->RegisterVariableInteger('HumanEventCounter', 'Human-Ereignisse', '', 25);
        $this->RegisterVariableString('LastEvent', 'Letztes Personenerkennungs-Ereignis (SMD/IVS)', '', 30);
        $this->RegisterVariableString('LastIVSEvent', 'Letztes CrossRegion-Ereignis roh', '', 40);
        $this->RegisterVariableString('ObserverStatus', 'Observer Diagnose', '', 50);
        $this->RegisterVariableString('EventAudit', 'P03 IVS-Ereignisdiagnose', '', 60);
        $this->RegisterVariableString('WireProbe', 'IPC5442 Wire-Rohereignisse vor Parser', '', 65);
        $this->RegisterVariableString('HumanHint', 'P03 Personhinweis in anderem Dahua-Ereignis (nur Diagnose)', '', 66);
        $this->RegisterVariableString('OnvifStatus', 'ONVIF PullPoint – eigener Ereignisweg', '', 67);
        $this->RegisterVariableString('OnvifRecentEvents', 'ONVIF letzte Ereignisse (Metadaten)', '', 68);

        $this->RegisterTimer('HandshakeTimer', 0, 'JVP03AUX_HandshakeTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('SocketRestartTimer', 0, 'JVP03AUX_SocketRestartTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('Watchdog', 15000, 'JVP03AUX_Watchdog($_IPS["TARGET"]);');
        $this->RegisterTimer('PersonPulseTimer', 0, 'JVP03AUX_PersonPulseTimer($_IPS["TARGET"]);');
        $this->RegisterTimer('OnvifPullTimer', 15000, 'JVP03AUX_OnvifPoll($_IPS["TARGET"]);');

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
        $this->RegisterVariableString('LastEvent', 'Letztes Personenerkennungs-Ereignis (SMD/IVS)', '', 30);
        $this->RegisterVariableString('LastIVSEvent', 'Letztes CrossRegion-Ereignis roh', '', 40);
        $this->RegisterVariableString('ObserverStatus', 'Observer Diagnose', '', 50);
        $this->RegisterVariableString('EventAudit', 'P03 IVS-Ereignisdiagnose', '', 60);
        $this->RegisterVariableString('WireProbe', 'IPC5442 Wire-Rohereignisse vor Parser', '', 65);
        $this->RegisterVariableString('HumanHint', 'P03 Personhinweis in anderem Dahua-Ereignis (nur Diagnose)', '', 66);
        $this->RegisterVariableString('OnvifStatus', 'ONVIF PullPoint – eigener Ereignisweg', '', 67);
        $this->RegisterVariableString('OnvifRecentEvents', 'ONVIF letzte Ereignisse (Metadaten)', '', 68);

        $this->SetTimerInterval('HandshakeTimer', 0);
        $this->SetTimerInterval('SocketRestartTimer', 0);
        $this->SetTimerInterval('PersonPulseTimer', 0);
        $this->SetTimerInterval('Watchdog', 15000);
        $this->SetTimerInterval('OnvifPullTimer',
            $this->ReadPropertyBoolean('Enabled') && $this->cameraConfigurationReady() ? 15000 : 0);
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
        $this->WriteAttributeInteger('LastHumanProofAt', 0);
        $this->WriteAttributeString('LastHumanProofCode', '');
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


    /**
     * Passive independent ONVIF PullPoint, without camera configuration writes.
     * Human proof is allowed ONLY with both a named stair-crossing and
     * explicit Human classification. Generic ONVIF crossings are diagnostics.
     */
    public function OnvifPoll(): void
    {
        if (!$this->ReadPropertyBoolean('Enabled') || !$this->cameraConfigurationReady()
            || time() < $this->ReadAttributeInteger('OnvifNextAttemptAt')
            || time() < $this->ReadAttributeInteger('OnvifPollBusyUntil')) {
            return;
        }
        // Initial device + Events + Pull requests may take longer than one
        // 15-s timer tick. Never overlap two polling rounds.
        $this->WriteAttributeInteger('OnvifPollBusyUntil',time()+25);
        try {
            $result=P03OnvifPullPoint::poll(
                trim($this->ReadPropertyString('CameraHost')),
                $this->ReadPropertyInteger('CameraPort'),
                $this->ReadPropertyString('Username'),
                $this->ReadPropertyString('Password'),
                $this->ReadAttributeString('OnvifSubscriptionURL'),
                $this->ReadAttributeInteger('OnvifSubscriptionUntil'),
                $this->ReadAttributeString('OnvifSubscriptionReference')
            );
            $status=(string)($result['status']??'UNKNOWN');
            $this->WriteAttributeString('OnvifLastStatus',$status);
            $this->SetValue('OnvifStatus',date('Y-m-d H:i:s').' '.$status);
            if (empty($result['ok'])) {
                $this->WriteAttributeString('OnvifSubscriptionURL','');
                $this->WriteAttributeInteger('OnvifSubscriptionUntil',0);
                $this->WriteAttributeInteger('OnvifNextAttemptAt',time()+180);
                return;
            }
            $this->WriteAttributeInteger('OnvifNextAttemptAt',0);
            $this->WriteAttributeString('OnvifSubscriptionURL',(string)$result['url']);
            $this->WriteAttributeInteger('OnvifSubscriptionUntil',(int)$result['until']);
            $this->WriteAttributeString('OnvifSubscriptionReference',(string)($result['reference']??'[]'));
            $events=is_array($result['events']??null)?$result['events']:[];
            if ($events===[]) {
                return;
            }
            $recent=json_decode($this->ReadAttributeString('OnvifRecentEvents'),true);
            $seen=json_decode($this->ReadAttributeString('OnvifSeenEventKeys'),true);
            if (!is_array($recent)) {
                $recent=[];
            }
            if (!is_array($seen)) {
                $seen=[];
            }
            $correlation=json_decode($this->ReadAttributeString('OnvifCorrelationState'),true);
            if (!is_array($correlation)) {
                $correlation=['classes'=>[],'crossings'=>[]];
            }
            foreach ($events as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $summary=P03OnvifEvents::summarize($event);
                $summary['utcFresh']=P03OnvifEvents::isRecent($event,time(),120);
                $this->WriteAttributeInteger('OnvifEventCount',
                    $this->ReadAttributeInteger('OnvifEventCount')+1);
                $recent[]=array_merge([
                    'time'=>date('H:i:s'),'role'=>$this->ReadPropertyString('Role')
                ],$summary);
                if (count($recent)>15) {
                    $recent=array_slice($recent,-15);
                }
                // ONVIF subscriptions may replay old event states. Never
                // interpret non-recent device UTC as a fresh human proof.
                if (!$summary['utcFresh']) {
                    continue;
                }
                $positive=P03OnvifCorrelator::ingest($event,$correlation,time());
                if (!is_array($positive)) {
                    continue;
                }
                // De-duplication is based on the actual qualifying CROSSING,
                // not an unrelated ObjectDetection message.
                $evidence=['rule'=>$positive['rule'],
                    'objectId'=>$positive['objectId'],
                    'utc'=>$positive['utc'],
                    'origin'=>$positive['source']];
                if (!P03OnvifEvents::isFresh($evidence,$seen,time(),180)) {
                    continue;
                }
                $this->WriteAttributeInteger('OnvifHumanStairCount',
                    $this->ReadAttributeInteger('OnvifHumanStairCount')+1);
                // DIAGNOSTIC ONLY until event-time ordering is integrated
                // into P03ProofEngine. Existing HOME/LAGER logic currently
                // orders HumanEventCounter arrival timestamps. Independent
                // PullPoint polls (15 s) can reverse two real crossings.
                // Publishing as an ordinary HumanEventCounter now would risk
                // a WRONG DIRECTION and false HOUSE_EMPTY_CERTIFIED.
                $recent[]=[
                    'time'=>date('H:i:s'),
                    'role'=>$this->ReadPropertyString('Role'),
                    'decision'=>'ONVIF_HUMAN_STAIR_DIAGNOSTIC_ONLY',
                    'rule'=>$positive['rule'],
                    'objectId'=>$positive['objectId'],
                    'utc'=>$positive['utc'],
                    'source'=>$positive['source'],
                    'humanProofPublished'=>false
                ];
                if (count($recent)>15) {
                    $recent=array_slice($recent,-15);
                }
            }
            $this->WriteAttributeString('OnvifSeenEventKeys',json_encode($seen)?:'{}');
            $this->WriteAttributeString('OnvifCorrelationState',
                json_encode($correlation,JSON_UNESCAPED_UNICODE)?:'{"classes":{},"crossings":{}}');
            $encoded=json_encode($recent,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'[]';
            $this->WriteAttributeString('OnvifRecentEvents',$encoded);
            $this->SetValue('OnvifRecentEvents',$encoded);
        } catch (Throwable $e) {
            $this->WriteAttributeString('OnvifSubscriptionURL','');
            $this->WriteAttributeInteger('OnvifSubscriptionUntil',0);
            $this->WriteAttributeInteger('OnvifNextAttemptAt',time()+180);
            $this->SetValue('OnvifStatus',date('Y-m-d H:i:s')
                .' ONVIF Ausnahme '.get_class($e).' – '.
                substr((string)$e->getMessage(),0,120));
        } finally {
            $this->WriteAttributeInteger('OnvifPollBusyUntil',0);
        }
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
        // Timer callbacks can be delayed during Symcon load. A healthy
        // heartbeat must not keep PersonDetected stuck at TRUE forever.
        if ($this->ReadAttributeInteger('HumanPulseUntil')>0
            && $this->ReadAttributeInteger('HumanPulseUntil')<=$now) {
            $this->clearPersonPulse();
        }

        if (!$this->ReadPropertyBoolean('Enabled') || !$this->cameraConfigurationReady()) {
            $this->WriteAttributeString('LastHealthAction', 'DISABLED_OR_UNCONFIGURED');
            $this->refreshObserverStatus('HEALTH: Observer deaktiviert oder unkonfiguriert');
            return 'DISABLED_OR_UNCONFIGURED';
        }
        if ($this->ReadAttributeBoolean('AuthBlocked')) {
            $this->SetValue('StreamOK', false);
            $this->clearPersonPulse();
            $this->WriteAttributeString('LastHealthAction', 'AUTH_BLOCKED');
            $this->refreshObserverStatus('HEALTH: Digest-Anmeldung gesperrt');
            return 'AUTH_BLOCKED';
        }

        $parentID = $this->getParentID();
        if ($parentID <= 0 || !IPS_InstanceExists($parentID)) {
            $this->SetValue('StreamOK', false);
            $this->clearPersonPulse();
            $this->WriteAttributeString('LastHealthAction', 'NO_PARENT');
            $this->refreshObserverStatus('HEALTH: P03 Client Socket fehlt');
            return 'NO_PARENT';
        }
        $parent = IPS_GetInstance($parentID);
        if (!$this->hasExclusiveParentSocket($parentID)
            || strcasecmp((string) ($parent['ModuleInfo']['ModuleID'] ?? ''),
                self::CLIENT_SOCKET_GUID) !== 0) {
            $this->SetValue('StreamOK', false);
            $this->clearPersonPulse();
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
        $existingMode = $this->ReadPropertyString('ProofMode') !== 'LEGACY_OWNED';
        $allowedRules = json_decode($this->ReadPropertyString('AllowedIVSRules'), true);
        if (!is_array($allowedRules)) {
            $allowedRules = [];
        }
        if (!$existingMode && $wantedName === '' && $wantedIndex < 0 && $wantedId < 0) {
            return;
        }

        foreach ($events as $event) {
            $code = trim((string) ($event['code'] ?? ''));
            $action = strtolower(trim((string) ($event['action'] ?? '')));

            // Persistent code histogram distinguishes actual Dahua AI events
            // from heartbeat and ordinary motion even when EventAudit is
            // overwritten by the next VideoMotionInfo message.
            $observed = json_decode($this->ReadAttributeString('ObservedCodeCounts'), true);
            if (!is_array($observed)) {
                $observed = [];
            }
            $codeKey = preg_replace('/[^A-Za-z0-9_+-]/', '?', $code) ?: 'UNBEKANNT';
            $codeKey = substr($codeKey, 0, 48);
            $observed[$codeKey] = max(0, (int)($observed[$codeKey] ?? 0)) + 1;
            if (count($observed) > 32) {
                arsort($observed, SORT_NUMERIC);
                $observed = array_slice($observed, 0, 32, true);
            }
            $this->WriteAttributeString('ObservedCodeCounts', json_encode($observed) ?: '{}');

            // Some firmware transmits a structured Human classification in
            // VideoMotionInfo/VideoMotion, which our strict Human evidence path
            // purposely rejects. Surface that as a DIAGNOSTIC candidate, never
            // increment a HumanEventCounter from generic motion alone.
            if (in_array(strtolower($code), ['videomotioninfo','videomotion'], true)
                && (bool)($event['human'] ?? false)) {
                $cls = strtolower(trim((string)($event['classification'] ?? '')));
                if (in_array($cls, ['human','person','pedestrian'], true)) {
                    $this->WriteAttributeInteger('GenericHumanHintCount',
                        $this->ReadAttributeInteger('GenericHumanHintCount') + 1);
                    $this->SetValue('HumanHint', json_encode([
                        'time'=>date('H:i:s'),
                        'code'=>$code,'action'=>$action,
                        'classification'=>$cls,
                        'hint'=>'MENSCHENKLASSE IN BEWEGUNGSDATEN – nur Diagnose, KEIN Zonenbeweis'
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
                }
            }

            if (strcasecmp($code, 'CrossRegionDetection') === 0
                || strcasecmp($code, 'CrossLineDetection') === 0) {
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

            // Direct IPC5442 SMD Human events are camera-verified positive
            // person observations. This channel was mistakenly excluded by
            // the old CrossRegion-only parser, despite SMD Human being enabled
            // on BOTH cameras. Generic VideoMotion/Heartbeat NEVER qualifies.
            if (P03AuxObserverLogic::isSpecificSmdHumanEvent($event)) {
                $this->WriteAttributeInteger('SmdHumanRawCount',
                    $this->ReadAttributeInteger('SmdHumanRawCount') + 1);
                if ($existingMode) {
                    // SMD reports detection anywhere in the image; it does
                    // NOT prove the user-defined staircase boundary. Preserve
                    // raw evidence but never trigger a HOME/LAGER transfer.
                    $this->recordEventAudit($event, 'SMD_ONLY_DIAGNOSTIC_NOT_STAIR_CROSSING');
                    continue;
                }
                if (!P03AuxObserverLogic::isSmdHumanProofStart($event)) {
                    $this->recordEventAudit($event, 'SMD_NOT_HUMAN_START');
                    continue;
                }
                if (!$this->isUniquePersonDelivery($event)) {
                    $this->recordEventAudit($event, 'SMD_DUPLICATE');
                    continue;
                }
                if ($this->publishHumanProof($event, 'SMD_HUMAN')) {
                    $this->WriteAttributeInteger('SmdHumanProofCount',
                        $this->ReadAttributeInteger('SmdHumanProofCount') + 1);
                    $this->recordEventAudit($event, 'SMD_HUMAN_START_COUNTED');
                    $this->refreshObserverStatus('SMD HUMAN START empfangen');
                } else {
                    $this->recordEventAudit($event, 'SMD_CROSS_SOURCE_DUPLICATE');
                }
                continue;
            }

            if ($existingMode) {
                if (!P03ExistingIvsLines::matchingHumanStart($event, $allowedRules)) {
                    $this->WriteAttributeInteger('RuleRejectCount',
                        $this->ReadAttributeInteger('RuleRejectCount') + 1);
                    $this->recordEventAudit($event,
                        strcasecmp($code, 'CrossLineDetection') === 0
                            ? 'STAIR_IVS_NOT_MAPPED_OR_NOT_HUMAN'
                            : 'RULE_OR_EVENTCODE_REJECTED');
                    continue;
                }
                $this->WriteAttributeInteger('RuleMatchEventCount',
                    $this->ReadAttributeInteger('RuleMatchEventCount') + 1);
                if (!$this->isUniquePersonDelivery($event)) {
                    $this->recordEventAudit($event, 'DUPLICATE_WITHIN_3_SECONDS');
                    continue;
                }
                $summary = [
                    'time' => date('H:i:s'),
                    'role' => $this->ReadPropertyString('Role'),
                    'code' => $code,
                    'action' => $event['action'] ?? '',
                    'ruleName' => $event['ruleName'] ?? null,
                    'human' => true,
                    'humanFromRule' => true,
                    'classification' => $event['classification'] ?? null,
                    'eventId' => $event['eventId'] ?? null,
                    'objectId' => $event['objectId'] ?? null
                ];
                $this->publishHumanProof($event, 'IVS_EXISTING_STAIR_LINE', $summary);
                $this->recordEventAudit($event, 'STAIR_HUMAN_START_COUNTED');
                $this->refreshObserverStatus('Bestehende Treppen-IVS HUMAN START');
                continue;
            }

            if (!P03AuxObserverLogic::isMatchingRuleEvent($event, $wantedName, $wantedIndex, $wantedId)) {
                $this->WriteAttributeInteger('RuleRejectCount',
                    $this->ReadAttributeInteger('RuleRejectCount') + 1);
                $this->recordEventAudit($event, 'RULE_OR_EVENTCODE_REJECTED');
                continue;
            }
            $this->WriteAttributeInteger('RuleMatchEventCount',
                $this->ReadAttributeInteger('RuleMatchEventCount') + 1);

            if (!$this->isUniquePersonDelivery($event)) {
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
                if ($this->publishHumanProof($event, 'IVS_CROSSREGION', $summary)) {
                    $this->recordEventAudit($event, 'HUMAN_START_COUNTED');
                    $this->refreshObserverStatus('IVS HUMAN START empfangen');
                } else {
                    $this->recordEventAudit($event, 'IVS_CROSS_SOURCE_DUPLICATE');
                }
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
     * Deduplicates the same HTTP event delivery, not all human activity:
     * independently detected events with different EventID/ObjectID are
     * retained even when the visible PersonDetected Boolean is still true.
     */
    private function isUniquePersonDelivery(array $event): bool
    {
        $seen=json_decode($this->ReadAttributeString('SeenEventKeys'),true);
        if (!is_array($seen)) {
            $seen=[];
        }
        $fresh=P03AuxObserverLogic::isFreshDelivery($event,$seen,time(),3);
        $this->WriteAttributeString('SeenEventKeys',json_encode($seen) ?: '{}');
        if (!$fresh) {
            $this->WriteAttributeInteger('DedupeRejectCount',
                $this->ReadAttributeInteger('DedupeRejectCount')+1);
        }
        return $fresh;
    }

    /**
     * One trusted positive PERSON Start increments exactly ONE monotonic
     * counter; STOP/Vehicle/generic Motion do not increment it.
     * Dedupe simultaneous SMD + IVS notifications for the same physical
     * person, without collapsing two independent same-source event IDs.
     *
     * @param array<string,mixed>|null $summary
     */
    private function publishHumanProof(array $event,string $source,?array $summary=null): bool
    {
        $now=time();
        $previous=$this->ReadAttributeString('LastHumanProofCode');
        $last=$this->ReadAttributeInteger('LastHumanProofAt');
        if ($previous!=='' && $previous!==$source && $last>0
            && $now-$last<=2) {
            return false;
        }
        $this->WriteAttributeString('LastHumanProofCode',$source);
        $this->WriteAttributeInteger('LastHumanProofAt',$now);
        if ($summary===null) {
            $summary=[
                'time'=>date('H:i:s'),'role'=>$this->ReadPropertyString('Role'),
                'code'=>$event['code']??'','action'=>$event['action']??'',
                'human'=>true,'humanFromRule'=>false,
                'source'=>$source,'classification'=>$event['classification']??null,
                'eventId'=>$event['eventId']??null,'objectId'=>$event['objectId']??null
            ];
        } else {
            $summary['source']=$source;
        }
        $this->SetValue('LastEvent',json_encode($summary,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $id=$this->GetIDForIdent('HumanEventCounter');
        if ($id>0 && IPS_VariableExists($id)) {
            $this->SetValue('HumanEventCounter',(int)GetValue($id)+1);
        }
        $this->WriteAttributeInteger('HumanPulseUntil',$now+5);
        $this->SetValue('PersonDetected',true);
        $this->SetTimerInterval('PersonPulseTimer',5000);
        return true;
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
                    $generic = in_array(strtolower($name),
                        ['videomotion', 'videomotioninfo', 'intervideoaccess'], true);
                    $now = time();
                    $lastGeneric = $this->ReadAttributeInteger('LastGenericWireProbeAt');
                    if (!$generic || $lastGeneric <= 0 || $now - $lastGeneric >= 15) {
                        // These 5442 cameras send up to 10 VideoMotionInfo
                        // messages PER SECOND, even without Human detection.
                        // No need to rewrite Symcon's WireProbe each time.
                        $this->SetValue('WireProbe', date('H:i:s') . ' ' . $safeHeader
                            . ' | Heartbeats=' . $this->ReadAttributeInteger('WireHeartbeatCount'));
                        if ($generic) {
                            $this->WriteAttributeInteger('LastGenericWireProbeAt', $now);
                        }
                    }
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
        $code = strtolower((string)($event['code'] ?? ''));
        if ($decision === 'RULE_OR_EVENTCODE_REJECTED'
            && in_array($code, ['videomotion', 'videomotioninfo', 'intervideoaccess'], true)) {
            $now = time();
            $last = $this->ReadAttributeInteger('LastGenericEventAuditAt');
            if ($last > 0 && $now - $last < 15) {
                return;
            }
            $this->WriteAttributeInteger('LastGenericEventAuditAt', $now);
        }
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
            'observedCodeCounts' => $this->ReadAttributeString('ObservedCodeCounts'),
            'genericHumanHintCount' => $this->ReadAttributeInteger('GenericHumanHintCount'),
            'eventsTotal' => $this->ReadAttributeInteger('ParsedEventCount'),
            'crossRegionTotal' => $this->ReadAttributeInteger('CrossRegionEventCount'),
            'ruleMatches' => $this->ReadAttributeInteger('RuleMatchEventCount'),
            'ruleRejected' => $this->ReadAttributeInteger('RuleRejectCount'),
            'dedupeRejected' => $this->ReadAttributeInteger('DedupeRejectCount'),
            'proofMode' => $this->ReadPropertyString('ProofMode'),
            'allowedStairRules' => json_decode($this->ReadPropertyString('AllowedIVSRules'), true),
            'smdHumanRawCount' => $this->ReadAttributeInteger('SmdHumanRawCount'),
            'smdHumanProofCount' => $this->ReadAttributeInteger('SmdHumanProofCount'),
            'lastHumanProofAt' => $this->ReadAttributeInteger('LastHumanProofAt'),
            'lastHumanProofCode' => $this->ReadAttributeString('LastHumanProofCode')
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
