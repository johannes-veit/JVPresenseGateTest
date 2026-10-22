<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVP03ProofLogReplay.php';
require_once dirname(__DIR__).'/libs/JVLocalSensorEvidence.php';
require_once dirname(__DIR__).'/libs/JVLCNGUSInventory.php';
require_once dirname(__DIR__).'/libs/JVLocalActivityLedger.php';
require_once dirname(__DIR__).'/libs/JVResidentRoomPaths.php';
require_once dirname(__DIR__).'/libs/JVRoomMotionInsight.php';
require_once dirname(__DIR__).'/libs/JVAnonymousRoomRouteAtlas.php';
require_once dirname(__DIR__).'/libs/JVJointDepartureReadiness.php';
require_once dirname(__DIR__).'/libs/JVPhoneAreaCycleMatcher.php';
require_once dirname(__DIR__).'/libs/JVSharedDepartureTimeline.php';
require_once dirname(__DIR__).'/libs/JVP03PhonePairMatrix.php';
require_once dirname(__DIR__).'/libs/JVPhoneRoomOverlapAudit.php';
require_once dirname(__DIR__).'/libs/JVShadowCrossSourceIntegrityGate.php';
require_once dirname(__DIR__).'/libs/JVRoomDayDigest.php';
require_once dirname(__DIR__).'/libs/JVHousePowerContext.php';
require_once dirname(__DIR__).'/libs/JVP03IncrementalTimeline.php';
require_once dirname(__DIR__).'/libs/JVHomeOccupancyEvidence.php';
require_once dirname(__DIR__).'/libs/JVBoundaryEvidence.php';
require_once dirname(__DIR__).'/libs/JVExitCandidateInventory.php';
require_once dirname(__DIR__).'/libs/JVGateSignalAudit.php';
require_once dirname(__DIR__).'/libs/JVIndoorPresenceHints.php';
require_once dirname(__DIR__).'/libs/JVIndoorHintInventory.php';
require_once dirname(__DIR__).'/libs/JVIndoorAutoReadHints.php';
require_once dirname(__DIR__).'/libs/JVGateDisplayHint.php';
require_once dirname(__DIR__).'/libs/JVParallelPassageGuard.php';
require_once dirname(__DIR__).'/libs/JVCameraCapabilityPriority.php';
require_once dirname(__DIR__).'/libs/JVDahuaNumberStatEvidence.php';
require_once dirname(__DIR__).'/libs/JVAdditionalIPCInventory.php';
require_once dirname(__DIR__).'/libs/JVKnownIpcMetadata.php';
require_once dirname(__DIR__).'/libs/JVSupplementaryIpcEvidence.php';
require_once dirname(__DIR__).'/libs/JVP03SourceHealth.php';
require_once dirname(__DIR__).'/libs/JVPresenceOperationalReadiness.php';
require_once dirname(__DIR__).'/libs/JVResidentSourceCommissioning.php';
require_once dirname(__DIR__).'/libs/JVExistingWlanPhoneHints.php';
require_once dirname(__DIR__).'/libs/JVJohannesEgiGeoZoneHints.php';
require_once dirname(__DIR__).'/libs/JVCarinaGeofencyHints.php';
require_once dirname(__DIR__).'/libs/JVResidentPresenceDisplay.php';
require_once dirname(__DIR__).'/libs/JVResidentVisuState.php';
require_once dirname(__DIR__).'/libs/JVResidentTileDashboard.php';
require_once dirname(__DIR__).'/libs/JVResidentDepartureInference.php';
require_once dirname(__DIR__).'/libs/JVResidentArrivalInference.php';
require_once dirname(__DIR__).'/libs/JVPhoneContactContinuity.php';
require_once dirname(__DIR__).'/libs/JVResidentEventFusion.php';
require_once dirname(__DIR__).'/libs/JVRouteEpisodeLedger.php';
require_once dirname(__DIR__).'/libs/JVResidentJourneyEvidence.php';
require_once dirname(__DIR__).'/libs/JVShadowStateCheckpoint.php';
require_once dirname(__DIR__).'/libs/JVShadowStartupEpoch.php';
require_once dirname(__DIR__).'/libs/JVShadowIntegrityAudit.php';
require_once dirname(__DIR__).'/libs/JVShadowUpgradePreflight.php';
require_once dirname(__DIR__).'/libs/JVPresenceSafetyCase.php';
require_once dirname(__DIR__).'/libs/JVShadowReportBudget.php';
require_once dirname(__DIR__).'/libs/JVPresenceEvidenceReadiness.php';
require_once dirname(__DIR__).'/libs/JVPresenceCommissioningGate.php';
require_once dirname(__DIR__).'/libs/JVResidentEveningHandoff.php';
require_once dirname(__DIR__).'/libs/JVCombinedPresenceEvidence.php';
require_once dirname(__DIR__).'/libs/JVCombinedPresenceBoolean.php';
require_once dirname(__DIR__).'/libs/JVCompanyWiFiEvidence.php';
require_once dirname(__DIR__).'/libs/JVTemporalPresenceCrossCheck.php';

/**
 * Read-only SHADOW observer. Consumes only already-existing local Symcon
 * variables and never controls P03, P05, LCN, cameras, alarms or PresenceCore.
 * Neither a person's whereabouts nor an occupancy certificate is published.
 */
class JVPresenceIdentityShadow extends IPSModule
{
    private const P03_GUID='{0A7A91CE-3BC1-4B1E-9C11-7B7E973F5C03}';
    private const AUX_GUID='{8F4A3A57-3A5B-4F6E-9A64-9E1D326FF7B4}';
    private const PHONE_GUID='{B5BF0266-A4D1-40A0-AAC8-97E9C24C4173}';
    private const VM_UPDATE=10603; // Symcon documented variable-update message
    private const STORED_STATE_NAMES=[
        'LocalSensorState',
        'LocalActivityLedger',
        'ResidentVisuState',
        'ResidentPhoneContinuity',
        'ResidentEventFusion',
        'RouteEpisodeLedger',
        'TileResidentState',
        'P03IncrementalTimeline',
        'BoundaryLedger',
        'ExitCandidateInventory',
        'GateSignalLedger',
        'IndoorHintInventory',
        'CarinaGeofencyInventory',
        'AdditionalIPCInventory',
        'SupplementalIpcMetadata'
    ];

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Enabled',false);
        // Only the verified local SymBox installation (#32969/#36895).
        $this->RegisterPropertyBoolean('AutoSetupKnownRootVisu',true);
        // Known owner installation ID; verify GUID before EVERY read.
        $this->RegisterPropertyInteger('P03InstanceID',53879);
        // Explicit opt-in only AFTER a separate verified IPC People Counting
        // source is available. Never request/switch camera Smart Plans.
        $this->RegisterPropertyBoolean('EnableCountTelemetryRead',false);
        $this->RegisterPropertyInteger('CountTelemetryVariableID',0);
        // Explicit opt-in for an existing numerical W source, not a new meter.
        $this->RegisterPropertyBoolean('EnableHousePowerRead',false);
        $this->RegisterPropertyInteger('HousePowerVariableID',0);
        // WLAN/Smartphone is developed in a separate project. NEVER read it
        // until the user explicitly authorizes a future opt-in.
        $this->RegisterPropertyBoolean('EnablePhoneIntegration',false);
        $this->RegisterPropertyInteger('PhoneObserverInstanceID',0);
        // Exact pairs come from the user's independently working WLAN
        // Johannes v1.2 and WLAN Carina v1.5. Manual opt-in; no script writes.
        $this->RegisterPropertyBoolean('EnableExistingWLANHints',false);
        // Explicit opt-in: only read 3-level verified existing Boolean status
        // objects found through manual indoor metadata audit (button 5).
        $this->RegisterPropertyBoolean('EnableAutoIndoorReadOnlyHints',false);
        $this->RegisterPropertyInteger('JohannesWLANBoolID',19980);
        $this->RegisterPropertyInteger('JohannesWLANLastContactID',26497);
        $this->RegisterPropertyInteger('CarinaWLANBoolID',38430);
        $this->RegisterPropertyInteger('CarinaWLANLastContactID',10760);
        // WORK source is NOT inferred from home MAC, VLAN/IP or geofence.
        // Only actual phone-specific CURRENT SSID Strings are accepted.
        // No AP/controller credentials, no new actions or camera writes.
        $this->RegisterPropertyBoolean('EnableWorkWiFiHints',false);
        $this->RegisterPropertyInteger('JohannesSSIDVariableID',56338);
        $this->RegisterPropertyInteger('JohannesSSIDParentID',56881);
        $this->RegisterPropertyInteger('CarinaSSIDVariableID',24058);
        $this->RegisterPropertyInteger('CarinaSSIDParentID',32081);
        // Johannes' separately configured Android EgiGeoZone: SHADOW only
        // reads two existing child variables of the verified Symcon device.
        // The 150 m radius is an app setting, NOT verified via these values.
        $this->RegisterPropertyBoolean('EnableJohannesEgiGeoZone',false);
        $this->RegisterPropertyInteger('JohannesEgiInstanceID',35965);
        $this->RegisterPropertyInteger('JohannesEgiDeviceID',50000);
        $this->RegisterPropertyInteger('JohannesEgiZoneVariableID',41869);
        $this->RegisterPropertyInteger('JohannesEgiTimestampID',28055);
        $this->RegisterPropertyInteger('JohannesEgiRadiusMeters',150);
        // Verified 15.10.2026 live screenshot: the installed Boolean is
        // called "Zuhause", NOT the historical "TestGeoZone".
        $this->RegisterPropertyString('JohannesEgiZoneName','Zuhause');
        // Optional Carina iPhone Geofency: no unknown IDs guessed. A metadata
        // audit first identifies the real Geofency device/Boolean/Timestamp.
        $this->RegisterPropertyBoolean('EnableCarinaGeofencyHints',false);
        // Verified real iPhone Geofency source from 15.10. screenshot.
        // Direct exact-ID validation makes another scan unnecessary.
        $this->RegisterPropertyInteger('CarinaGeofencyInstanceID',48903);
        $this->RegisterPropertyInteger('CarinaGeofencyDeviceID',45439);
        $this->RegisterPropertyInteger('CarinaGeofencyZoneVarID',39169);
        $this->RegisterPropertyInteger('CarinaGeofencyTimestampID',45666);
        $this->RegisterPropertyString('CarinaGeofencyZoneName','Zuhause');
        // Historical LCN snapshot (06.10.2026). Candidates DISABLED until
        // current live source/path/type verification; never silently activate.
        $this->RegisterPropertyString('SensorSources', json_encode([
            ['Enabled'=>false,'SourceID'=>'eg_wohnen_kamin','Name'=>'EG Wohnen Kamin (Bestand 06.10.)','Kind'=>'MOTION_KAMIN','Zone'=>'HOME','VariableID'=>42452],
            ['Enabled'=>false,'SourceID'=>'eg_flur','Name'=>'EG Flur (Bestand 06.10.)','Kind'=>'MOTION','Zone'=>'HOME','VariableID'=>39155],
            ['Enabled'=>false,'SourceID'=>'og_flur','Name'=>'OG Flur (Bestand 06.10.)','Kind'=>'MOTION','Zone'=>'HOME','VariableID'=>22933],
            ['Enabled'=>false,'SourceID'=>'og_ankleide_decke','Name'=>'OG Ankleide Decke (Bestand 06.10.)','Kind'=>'MOTION','Zone'=>'HOME','VariableID'=>25969],
            ['Enabled'=>false,'SourceID'=>'og_bad_dusche','Name'=>'OG Bad Dusche (Bestand 06.10.)','Kind'=>'MOTION','Zone'=>'HOME','VariableID'=>30215],
            ['Enabled'=>false,'SourceID'=>'og_ankleide','Name'=>'OG Ankleide (Bestand 06.10.)','Kind'=>'MOTION','Zone'=>'HOME','VariableID'=>51583]
        ], JSON_UNESCAPED_UNICODE) ?: '[]');
        $this->RegisterAttributeString('SensorSubscriptions','[]');
        $this->RegisterAttributeString('GateSubscriptions','[]');
        $this->RegisterAttributeString('AutoPhoneSSIDInventory','{}');
        $this->RegisterAttributeString('ResidentRootVisuSetup','{}');
        // Attributes persist across Symcon / module restarts; buffers DO NOT.
        // Never add hidden Status variables for storage.
        foreach(self::STORED_STATE_NAMES as $name)
            $this->RegisterAttributeString('Persist'.$name,'{}');
        $this->RegisterVariableString('Status','Identität – SHADOW Status','',0);
        $this->RegisterVariableString('EvidenceJSON','Identität – Nachweise (JSON)','',1);
        // Two requested read-only string displays. FALSE Boolean would
        // misleadingly mean "person absent" even when phone is asleep.
        $this->RegisterVariableString('JohannesPresence','Johannes anwesend','',2);
        $this->RegisterVariableString('CarinaPresence','Carina anwesend','',3);
        // Exactly one additional user-requested Boolean inside SHADOW.
        // FALSE means NO CURRENT POSITIVE SIGNAL, never confirmed empty.
        $this->RegisterVariableBoolean('PresenceDetected','Anwesenheit erkannt','',4);
        // Exactly one requested additional VISU tile; the five historical
        // status IDs, types, names and idents remain unmodified.
        $this->RegisterVariableString('ResidentTile',
            'Anwesenheit – Johannes & Carina','~HTMLBox',5);
        // One new, automatically refreshed and copyable human-readable
        // handoff. This is not a log of multiple statuses and no passwords
        // or phone coordinates are included.
        $this->RegisterVariableString('TestReport',
            'Testergebnis an ChatGPT (Kopieren)','',6);
        $this->RegisterTimer('ReadLocal',0,'JVIDSH_Refresh($_IPS["TARGET"]);');
        // Symcon 9.0: the minimized instance itself becomes the compact
        // two-resident tile. The existing HTMLBox and expanded diagnostic
        // list remain intact. Legacy mocks/SDKs retain the HTMLBox fallback.
        if(method_exists($this,'SetVisualizationType')) {
            try{$this->SetVisualizationType(1);}
            catch(Throwable $ignored){}
        }
    }

    /**
     * Symcon starts a new instance in IS_CREATING (101). A failing initial
     * ApplyChanges can leave 101 behind even while subsequent timer/button
     * calls continue to update the existing output variables.
     *
     * Complete ALL local initialization stages before acknowledging ACTIVE.
     * An exception is reported as a genuine module error (201), not hidden
     * by setting 102. Never reset any attributes or recreate the instance.
     */
    public function ApplyChanges(): void
    {
        $step='PARENT_APPLY_CHANGES';
        try {
            parent::ApplyChanges();
            $step='RESTORE_PERSISTENT_SHADOW_STATE';
            // Restore prior observations without clearing the existing ledgers.
            $this->migrateLegacyShadowBuffers();
            $step='CONFIGURE_READ_ONLY_TIMER';
            $this->SetTimerInterval(
                'ReadLocal',$this->ReadPropertyBoolean('Enabled')?60000:0
            );
            $step='RECONNECT_EXISTING_GUS_SUBSCRIPTIONS';
            $this->syncSensorSubscriptions();
            $step='RECONNECT_EXISTING_GATE_SUBSCRIPTIONS';
            $this->syncGateSubscriptions();
            $step='INITIAL_READ_ONLY_REFRESH';
            $this->Refresh();
            // A blocked upgrade must not be advertised as an initialized
            // GREEN/active Symcon instance. Preserve the specific already
            // displayed report, and leave foreign modules untouched.
            $first=json_decode((string)$this->GetValue('EvidenceJSON'),true);
            if(is_array($first)
                &&($first['authority']??null)==='SHADOW_UPGRADE_READ_ONLY_BLOCK'){
                $this->SetStatus(201);
                return;
            }
            $step='SET_ACTIVE_STATUS';
            // Only after the successful complete bootstrap: clear a stuck
            // IS_CREATING=101 left by an earlier, incomplete initialization.
            if($this->SetStatus(102)===false)
                throw new RuntimeException('SYSCON_STATUS_TRANSITION_REJECTED');
            // Visualization setup is completely separate from the SHADOW
            // observation bootstrap: it may never make the module RED.
            if($this->ReadPropertyBoolean('AutoSetupKnownRootVisu')
                &&isset($this->InstanceID)&&$this->InstanceID===32969){
                try {$this->installKnownRootVisuSafely();}
                catch(Throwable $ignored){}
            }
        } catch(Throwable $e) {
            // A failed bootstrap is a missing observation epoch even if it
            // occurred only seconds after the last successful timer poll.
            try {$this->SetBuffer('ShadowObservationStarted','0');}
            catch(Throwable $ignored){}
            // A real bootstrap error must NOT be disguised as "active".
            // 201 is local to this module and does not touch other instances.
            try {$this->SetTimerInterval('ReadLocal',0);}
            catch(Throwable $ignored){}
            try {$this->SetStatus(201);}
            catch(Throwable $ignored){}
            // Do not expose credentials or foreign module data in messages.
            try {
                $this->SendDebug(
                    'ShadowInitialization',
                    'FAILED_STEP='.$step.'; EXCEPTION_CLASS='.get_class($e),
                    0
                );
            }catch(Throwable $ignored){}
            try {
                $this->SetValue(
                    'Status',
                    'SHADOW Initialisierung fehlgeschlagen: '.$step
                );
                $this->setResidentDisplayValues('NICHT BESTÄTIGT','NICHT BESTÄTIGT');
                $this->setPresenceDetected(false);
                $this->setTestReport('SHADOW-STARTFEHLER: '.$step
                    .' | Keine Abwesenheitsfreigabe. '
                    .'Kein fremdes Modul wurde veraendert.');
            }catch(Throwable $ignored){}
        }
    }

    /**
     * Persistent attributes are canonical. Never rely on GetBuffer surviving
     * module updates or a restart. A pre-v0.8.29 buffer is migrated only when
     * the persistent attribute has not yet been populated.
     */
    /** No state mutation: only the fifteen known SHADOW attributes. */
    private function auditStoredUpgradeState(): array
    {
        $stored=[];
        foreach(self::STORED_STATE_NAMES as $name){
            $stored[$name]=$this->ReadAttributeString('Persist'.$name);
        }
        return JVShadowUpgradePreflight::evaluate($stored,time());
    }

    private function readShadowState(string $name): string
    {
        // Read the latest validated in-memory state between 10-minute
        // durable checkpoints. P03 is historically schema-less: rejecting
        // its buffer would repeatedly replay old raw-log deltas. An
        // initialized=false P03 quarantine must also survive every poll.
        // After SymBox restart the missing buffer falls back to the
        // last durable, conservatively rebaselined checkpoint.
        $stored=$this->ReadAttributeString('Persist'.$name);
        if(JVShadowStateCheckpoint::useRuntimeCache($name)){
            $runtime=$this->GetBuffer($name);
            // A repaired/restored persistent attribute takes priority over
            // a stale in-memory sample. The checksum is an existing
            // volatile buffer only; no new durable attribute or variable.
            $origin=$this->GetBuffer('CheckpointOrigin_'.$name);
            if($runtime!=='' &&$runtime!=='{}'
                &&$origin!==''&&hash_equals($origin,hash('sha256',$stored))){
                $decoded=json_decode($runtime,true);
                if(is_array($decoded)
                    &&JVShadowStateCheckpoint::validRuntimeCache($name,$decoded))
                    return $runtime;
            }
        }
        // The declared persistent attribute is canonical even when it is
        // intentionally reset to '{}'. Reading a leftover runtime buffer
        // here would resurrect old critical proofs after a safe repair.
        // Historical pre-attribute migration is performed explicitly in
        // migrateLegacyShadowBuffers() during ApplyChanges(), not here.
        return $stored!==''?$stored:'{}';
    }

    private function writeShadowState(string $name,string $value): void
    {
        if($value==='')return;
        $durable=$this->ReadAttributeString('Persist'.$name);
        if(JVShadowStateCheckpoint::shouldPersist(
            $name,$durable,$value,time()
        )){
            $this->WriteAttributeString('Persist'.$name,$value);
            $durable=$value;
        }
        // Runtime cache keeps the complete latest state between 60-second
        // polls; all semantic changes are already durable immediately.
        if($this->GetBuffer($name)!==$value)
            $this->SetBuffer($name,$value);
        if(JVShadowStateCheckpoint::useRuntimeCache($name)){
            $anchor=hash('sha256',$durable);
            if($this->GetBuffer('CheckpointOrigin_'.$name)!==$anchor)
                $this->SetBuffer('CheckpointOrigin_'.$name,$anchor);
        }
    }

    private function migrateLegacyShadowBuffers(): void
    {
        foreach(self::STORED_STATE_NAMES as $name) {
            $persistent=$this->ReadAttributeString('Persist'.$name);
            if($persistent!==''&&$persistent!=='{}')continue;
            // An already-running new-version cache may survive a manual
            // repair within the same Symcon process. It is NOT a legacy
            // migration candidate and must never undo the repaired value.
            if($this->GetBuffer('CheckpointOrigin_'.$name)!=='')continue;
            $legacy=$this->GetBuffer($name);
            if($legacy===''||$legacy==='{}')continue;
            $decoded=json_decode($legacy,true);
            if(!is_array($decoded) || $decoded===[])continue;
            $this->WriteAttributeString('Persist'.$name,$legacy);
        }
    }

    public function GetConfigurationForm(): string
    {
        return (string)file_get_contents(__DIR__.'/form.json');
    }

    /**
     * Existing local IP-Symcon variables emit VM_UPDATE on updates. Watching
     * them avoids missing short GUS impulses between 60-second fallback polls.
     * Register only validated Boolean/Integer source variables; no new events,
     * commands or sockets. The subscribed IDs are maintained as an attribute
     * so stale registrations are removed when configuration changes.
     */
    private function syncSensorSubscriptions(): void
    {
        $old=json_decode($this->ReadAttributeString('SensorSubscriptions'),true);
        $old=is_array($old)?$old:[];
        $old=array_values(array_unique(array_filter($old,
            static fn($v)=>is_int($v)&&$v>0)));
        $next=[];
        if ($this->ReadPropertyBoolean('Enabled')) {
            $rows=json_decode($this->ReadPropertyString('SensorSources'),true);
            $cfg=JVLocalSensorEvidence::validate(is_array($rows)?$rows:[]);
            if ($cfg['errors']===[]) {
                foreach ($cfg['sources'] as $source) {
                    $id=$source['variableID'];
                    try {
                        if (!IPS_VariableExists($id)) continue;
                        $meta=IPS_GetVariable($id);
                        if (!in_array($meta['VariableType']??null,[0,1],true)) continue;
                        $next[]=$id;
                    } catch (Throwable $e) {
                        continue;
                    }
                }
            }
        }
        sort($old);sort($next);
        // RegisterMessage is idempotent; only change actual differences.
        foreach(array_diff($old,$next) as $id) {
            try { $this->UnregisterMessage((int)$id,self::VM_UPDATE); }
            catch (Throwable $e) {}
        }
        $registered=[];
        // Re-register desired IDs even if the attribute still lists them:
        // after a Symcon service restart the registration must exist again.
        foreach($next as $id) {
            try {
                $this->RegisterMessage((int)$id,self::VM_UPDATE);
                $registered[]=(int)$id;
            } catch(Throwable $e) {
                // No messages for this source. The local fallback poll remains.
            }
        }
        sort($registered);
        // ApplyChanges and module restarts must re-register VM_UPDATE,
        // but an unchanged ID list does not need another flash write.
        $subscriptionJSON=json_encode(
            array_values(array_unique($registered)))?:'[]';
        if($this->ReadAttributeString('SensorSubscriptions')!==$subscriptionJSON)
            $this->WriteAttributeString('SensorSubscriptions',$subscriptionJSON);
    }

    /**
     * Optional, passive VM_UPDATE watches for the seven inventoried Boolean
     * Schiebetor status variables. No Symcon event objects are created, no
     * commands, actions, sensor auto-linking or changes to foreign modules.
     * Re-register on ApplyChanges because VM_UPDATE hooks are runtime state.
     */
    private function syncGateSubscriptions(): void
    {
        $old=json_decode($this->ReadAttributeString('GateSubscriptions'),true);
        $old=is_array($old)?array_values(array_unique(array_filter(
            $old,static fn($id)=>is_int($id)&&$id>0))):[];
        $existingGus=json_decode($this->ReadAttributeString('SensorSubscriptions'),true);
        $gus=is_array($existingGus)?$existingGus:[];
        $next=[];
        $gate=json_decode($this->readShadowState('GateSignalLedger'),true);
        $inventory=json_decode($this->readShadowState('ExitCandidateInventory'),true);
        if($this->ReadPropertyBoolean('Enabled')
            && is_array($gate) && ($gate['active']??false)===true
            && is_array($inventory)
            && ($inventory['authority']??'')==='READ_ONLY_EXIT_SOURCE_CANDIDATES'
            && is_array($inventory['listedCandidates']??null)) {
            foreach(array_slice($inventory['listedCandidates'],0,16) as $row) {
                if(!is_array($row)||!is_int($row['variableId']??null)
                    || $row['variableId']<=0
                    || ($row['type']??null)!=='Boolean'
                    || ($row['grandparent']??null)!=='Schiebetor_Logik')continue;
                $id=$row['variableId'];
                try {
                    if(!IPS_VariableExists($id))continue;
                    $meta=IPS_GetVariable($id);
                    if(($meta['VariableType']??null)!==0)continue;
                    $o=IPS_GetObject($id);
                    $pid=(int)($o['ParentID']??0);
                    if($pid<=0 || IPS_GetName($id)!==($row['name']??null)
                        || IPS_GetName($pid)!==($row['parent']??null))continue;
                    $p=IPS_GetObject($pid);
                    $grandId=(int)($p['ParentID']??0);
                    if($grandId<=0
                        || IPS_GetName($grandId)!=='Schiebetor_Logik')continue;
                    $next[]=$id;
                } catch(Throwable $e) {
                    // A broken source stays unobserved. Never try to repair it.
                }
            }
        }
        $next=array_values(array_unique($next));
        sort($next);
        foreach(array_diff($old,$next) as $id) {
            // Do not remove GUS's independent subscription to the same ID.
            if(in_array($id,$gus,true))continue;
            try {$this->UnregisterMessage((int)$id,self::VM_UPDATE);}
            catch(Throwable $e){}
        }
        $subscribed=[];
        foreach($next as $id) {
            if(in_array($id,$gus,true)) {
                $subscribed[]=$id;continue;
            }
            try {
                $this->RegisterMessage((int)$id,self::VM_UPDATE);
                $subscribed[]=$id;
            } catch(Throwable $e) {
                // 60s periodic sampling remains available and unaltered.
            }
        }
        sort($subscribed);
        $value=json_encode($subscribed)?:'[]';
        if($this->ReadAttributeString('GateSubscriptions')!==$value)
            $this->WriteAttributeString('GateSubscriptions',$value);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if(!$this->ReadPropertyBoolean('Enabled')
            || (int)$Message!==self::VM_UPDATE) return;
        $registered=json_decode($this->ReadAttributeString('SensorSubscriptions'),true);
        $gateRegistered=json_decode($this->ReadAttributeString('GateSubscriptions'),true);
        $isGus=is_array($registered)
            && in_array((int)$SenderID,$registered,true);
        $isGate=is_array($gateRegistered)
            && in_array((int)$SenderID,$gateRegistered,true);
        if(!$isGus && !$isGate)return;
        // React immediately to an update to avoid losing a short human
        // pulse before the next poll. Read only current local Symcon data.
        $this->Refresh();
    }

    /**
     * One-click LOCAL READ-ONLY audit. Does not enable any sensor, start a
     * camera, alter P03, write LCN commands, or edit configured IDs.
     * Finds pre-existing LCN Unit motion sensors including the missing
     * kitchen GUS if it exists in the current SymBox object tree.
     */
    public function AuditLocalLCNInventory(): void
    {
        $guids=['{2D871359-14D8-493F-9B01-26432E3A710F}'];
        $rows=json_decode($this->ReadPropertyString('SensorSources'),true);
        $rows=is_array($rows)&&array_is_list($rows)?$rows:[];
        $configured=[];
        foreach(array_slice($rows,0,64) as $row) {
            if(!is_array($row)) continue;
            $id=$row['VariableID']??0;
            if(!is_int($id)||$id<=0) continue;
            $entry=['id'=>$id,'sourceID'=>(string)($row['SourceID']??''),
                'kind'=>(string)($row['Kind']??''),
                'configuredEnabled'=>($row['Enabled']??false)===true,
                'valid'=>false,'name'=>'','parentID'=>null,'error'=>''];
            try {
                if(!IPS_VariableExists($id)) {
                    $entry['error']='VARIABLE_MISSING';
                } else {
                    $meta=IPS_GetVariable($id);
                    $obj=IPS_GetObject($id);
                    $parent=(int)($obj['ParentID']??0);
                    $entry['name']=(string)IPS_GetName($id);
                    $entry['parentID']=$parent?:null;
                    $type=$meta['VariableType']??null;
                    if(!in_array($type,[0,1],true)) {
                        $entry['error']='WRONG_TYPE';
                    } elseif(str_starts_with($entry['kind'],'MOTION')) {
                        $inst=$parent>0?IPS_GetInstance($parent):[];
                        $gid=(string)($inst['ModuleInfo']['ModuleID']??'');
                        $parentName=$parent>0?(string)IPS_GetName($parent):'';
                        $looksMotion=stripos($parentName,'Bewegung')!==false;
                        $validName=strcasecmp($entry['name'],'Status')===0;
                        if(!in_array(strtoupper($gid),$guids,true)
                            || !$looksMotion || !$validName || $type!==0) {
                            $entry['error']='GUS_PARENT_TYPE_OR_NAME_MISMATCH';
                        } else {
                            $entry['valid']=true;
                        }
                    } else {
                        // For LIGHT/GATE/VEHICLE the source semantics still
                        // need separate verification by a user; only type
                        // and existence can be checked without live context.
                        $entry['error']='OUTPUT_MEANING_NOT_VERIFIED';
                    }
                }
            } catch(Throwable $e) {
                $entry['error']='SOURCE_READ_ERROR';
            }
            $configured[]=$entry;
        }
        $found=[];
        $limitsReached=false;
        foreach($guids as $guid) {
            try {
                $instances=IPS_GetInstanceListByModuleID($guid);
                if(!is_array($instances)) continue;
                if(count($instances)>400) {
                    $instances=array_slice($instances,0,400);
                    $limitsReached=true;
                }
                foreach($instances as $instId) {
                    if(count($found)>=80) {$limitsReached=true;break;}
                    $instId=(int)$instId;
                    if($instId<=0) continue;
                    $instanceName=(string)IPS_GetName($instId);
                    if(stripos($instanceName,'Bewegung')===false) continue;
                    $children=IPS_GetChildrenIDs($instId);
                    if(!is_array($children)) continue;
                    foreach(array_slice($children,0,24) as $child) {
                        $child=(int)$child;
                        if($child<=0 || strcasecmp((string)IPS_GetName($child),'Status')!==0
                            || !IPS_VariableExists($child)) continue;
                        $meta=IPS_GetVariable($child);
                        if(($meta['VariableType']??null)!==0) continue;
                        $found[]=['variableId'=>$child,'unitId'=>$instId,
                            'name'=>$instanceName];
                    }
                }
            } catch(Throwable $e) {
                $limitsReached=true;
            }
        }
        usort($found,static fn($a,$b)=>strcmp($a['name'],$b['name']));
        $validCount=count(array_filter($configured,
            static fn($entry)=>($entry['valid']??false)===true));
        $report=[
            'schema'=>1,'authority'=>'SHADOW_LOCAL_INVENTORY_ONLY',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'configurationChanged'=>false,
            'devicesChanged'=>false,
            'configuredCandidates'=>$configured,
            'detectedLCNMotionSensors'=>$found,
            'inventoryLimited'=>$limitsReached
        ];
        $this->publish($report,'LCN INVENTUR (NUR LESEN): '
            .$validCount.'/'.count($configured).' bestehende Quellen geprüft; '
            .count($found).' Bewegungsmelder gefunden. Details in Nachweise (JSON).');
    }

    /**
     * On explicit button click, populate *this* Shadow instance with all 15
     * physically existing GUS variables from the October 13 live inventory.
     * Never enable the global Shadow observer or alter third-party instances.
     * Full scan is repeated internally on the CURRENT SymBox object tree;
     * incomplete/renamed/reused IDs abort without a partial configuration.
     */
    public function ImportVerifiedLCNGUS(): void
    {
        $inventory=[];
        foreach(JVLCNGUSInventory::KNOWN as $known) {
            $id=$known['id'];
            try {
                if(!IPS_VariableExists($id)) continue;
                $meta=IPS_GetVariable($id);
                $obj=IPS_GetObject($id);
                $parent=$obj['ParentID']??0;
                if(!is_int($parent)||$parent<=0) continue;
                $inst=IPS_GetInstance($parent);
                $inventory[]=[
                    'variableId'=>$id,
                    'unitId'=>$parent,
                    'name'=>(string)IPS_GetName($parent),
                    'moduleGUID'=>(string)($inst['ModuleInfo']['ModuleID']??''),
                    'variableName'=>(string)IPS_GetName($id),
                    'variableType'=>$meta['VariableType']??null
                ];
            } catch (Throwable $e) {
                // A missing or inaccessible source blocks the complete
                // import. It must NOT trigger a partial install.
            }
        }
        $old=json_decode($this->ReadPropertyString('SensorSources'),true);
        $result=JVLCNGUSInventory::propose($inventory,is_array($old)?$old:[]);
        if($result['ok']) {
            $validated=JVLocalSensorEvidence::validate($result['rows']);
            if($validated['errors']) {
                $result['ok']=false;
                $result['errors']=$validated['errors'];
            }
        }
        if(!$result['ok']) {
            $this->publish([
                'schema'=>1,'authority'=>'SHADOW_LOCAL_GUS_IMPORT_REJECTED',
                'personPresenceMayBeChanged'=>false,
                'propertyEmptyMayBeCertified'=>false,
                'configurationChanged'=>false,
                'devicesChanged'=>false,
                'importedCount'=>0,'warnings'=>$result['errors']
            ],'GUS-Import gesperrt – keine Änderung: '.implode(', ',array_slice($result['errors'],0,2)));
            return;
        }

        if(!$result['unchanged']) {
            $json=json_encode($result['rows'],
                JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if(!is_string($json)) {
                $this->publish([
                    'schema'=>1,'authority'=>'SHADOW_LOCAL_GUS_IMPORT_REJECTED',
                    'personPresenceMayBeChanged'=>false,
                    'propertyEmptyMayBeCertified'=>false,
                    'configurationChanged'=>false,'devicesChanged'=>false,
                    'importedCount'=>0,'warnings'=>['JSON_ENCODING_FAILED']
                ],'GUS-Import gesperrt – JSON-Fehler.');
                return;
            }
            try {
                // Write EXCLUSIVELY this observer's own property.
                IPS_SetProperty($this->InstanceID,'SensorSources',$json);
                IPS_ApplyChanges($this->InstanceID);
            } catch(Throwable $e) {
                $this->publish([
                    'schema'=>1,'authority'=>'SHADOW_LOCAL_GUS_IMPORT_REJECTED',
                    'personPresenceMayBeChanged'=>false,
                    'propertyEmptyMayBeCertified'=>false,
                    'configurationChanged'=>false,'devicesChanged'=>false,
                    'importedCount'=>0,'warnings'=>['SHADOW_PROPERTY_APPLY_FAILED']
                ],'GUS-Import konnte nicht übernommen werden. Instanz prüfen.');
                return;
            }
        }
        $this->publish([
            'schema'=>1,'authority'=>'SHADOW_LOCAL_GUS_SOURCES_CONFIGURED',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'configurationChanged'=>!$result['unchanged'],
            'devicesChanged'=>false,
            'importedCount'=>$result['count'],
            'globalObservationEnabled'=>$this->ReadPropertyBoolean('Enabled'),
            'sources'=>$result['rows'],
            'warnings'=>[]
        ],'15/15 LCN-GUS in SHADOW übernommen (nur Lesen). '
            .'Globale Beobachtung: '
            .($this->ReadPropertyBoolean('Enabled')?'AN':'AUS – jetzt manuell einschalten'));
    }

    /**
     * One explicit commissioning click adds exactly ONE visual link into
     * the existing ancestor category "Anwesenheitserkennung". The link
     * targets THIS native HTML-SDK instance, whose tile already displays
     * Johannes and Carina together. Two separate variable links would
     * destroy the requested clean two-row tile.
     *
     * No category is created, no module or variable is moved, and no
     * existing object (including any foreign link) is reconfigured.
     */
    public function CreateAnwesenheitTileLink(): void
    {
        $result=$this->createAnwesenheitTileLinkSafely();
        try {
            $this->SendDebug('AnwesenheitVisuLink',$result,0);
            $status='VISU: '.$result;
            if($this->GetValue('Status')!==$status)
                $this->SetValue('Status',$status);
        }catch(Throwable $ignored){}
    }

    private function createAnwesenheitTileLinkSafely():string
    {
        $newLinkId=0;
        try {
            if(!is_int($this->InstanceID)||$this->InstanceID<=0)
                return 'Instanz-ID ungueltig; kein Link angelegt.';
            $current=$this->InstanceID;$visited=[];$root=0;
            // Find only an EXISTING category in this instance's real
            // ancestor chain. Never guess IDs or search other user folders.
            for($depth=0;$depth<12;$depth++) {
                if($current<=0||isset($visited[$current]))break;
                $visited[$current]=true;
                $object=IPS_GetObject($current);
                if(!is_array($object))break;
                $type=$object['ObjectType']??null;
                if($depth===0&&$type!==1)
                    return 'SHADOW-Objekttyp nicht verifiziert.';
                if($depth>0&&$type===0
                    &&in_array((string)IPS_GetName($current),
                        ['Anwesenheitserkennung','Anwesenheit'],true)){
                    $root=$current;break;
                }
                $parent=$object['ParentID']??null;
                if(!is_int($parent)||$parent<=0)break;
                $current=$parent;
            }
            if($root<=0)
                return 'Keine uebergeordnete Kategorie Anwesenheitserkennung gefunden; unveraendert.';
            $children=IPS_GetChildrenIDs($root);
            if(!is_array($children)||count($children)>2000)
                return 'Zielkategorie nicht sicher pruefbar; unveraendert.';
            foreach($children as $id) {
                if(!is_int($id)||$id<=0)continue;
                $object=IPS_GetObject($id);
                if(!is_array($object))return
                    'Unvollstaendige Zielkategorie; unveraendert.';
                if(($object['ObjectType']??null)===6) {
                    $link=IPS_GetLink($id);
                    if(!is_array($link))return
                        'Verlinkungen nicht pruefbar; unveraendert.';
                    if(($link['TargetID']??null)===$this->InstanceID)
                        return 'Bewohnerstatus unter Anwesenheitserkennung bereits verlinkt (#'.$id.').';
                }
                // Do not create a duplicate named object or overwrite any
                // user-managed link (including to a different target).
                if((string)IPS_GetName($id)==='Bewohnerstatus')
                    return 'Objekt Bewohnerstatus existiert bereits mit anderem Ziel; nichts geaendert.';
            }
            if(!function_exists('IPS_CreateLink')
                ||!function_exists('IPS_SetLinkTargetID'))
                return 'Symcon Link-API nicht verfuegbar; unveraendert.';
            $newLinkId=IPS_CreateLink();
            if(!is_int($newLinkId)||$newLinkId<=0)
                return 'Link konnte nicht angelegt werden.';
            if(IPS_SetParent($newLinkId,$root)!==true
                ||IPS_SetLinkTargetID($newLinkId,$this->InstanceID)!==true
                ||IPS_SetName($newLinkId,'Bewohnerstatus')!==true
                ||IPS_SetPosition($newLinkId,0)!==true)
                throw new RuntimeException('LINK_CONFIGURATION_REJECTED');
            $o=IPS_GetObject($newLinkId);
            $l=IPS_GetLink($newLinkId);
            if(!is_array($o)||!is_array($l)
                ||($o['ParentID']??null)!==$root
                ||($o['ObjectType']??null)!==6
                ||($l['TargetID']??null)!==$this->InstanceID)
                throw new RuntimeException('LINK_VERIFICATION_FAILED');
            return 'Bewohnerstatus-Link #'.$newLinkId
                .' direkt unter Anwesenheitserkennung angelegt. '
                .'WLAN-/SHADOW-Unterordner koennen jetzt in der Visu ausgeblendet werden.';
        }catch(Throwable $e){
            // Roll back only a link created by THIS click; never delete
            // anything that pre-existed and was manually configured.
            if($newLinkId>0) {
                try {
                    if(IPS_LinkExists($newLinkId))
                        IPS_DeleteLink($newLinkId);
                }catch(Throwable $ignored){}
            }
            return 'Link-Einrichtung fehlgeschlagen ('.get_class($e).'); '
                .'bestehende Symcon-Struktur unveraendert.';
        }
    }

    /**
     * SymBox-only layout correction for the user-confirmed hierarchy:
     * #36895 Anwesenheitserkennung -> #33054 Identity Shadow -> #32969.
     * One native instance link stays visible at the root; all other direct
     * root items are hidden FROM ALL VISUALIZATIONS, not disabled or deleted.
     * Previous visibility flags persist for exact restoration.
     *
     * This is deliberately not a general-purpose tree mover or controller.
     */
    public function InstallKnownRootVisu():void
    {
        $result=$this->installKnownRootVisuSafely(true);
        try{
            $this->SendDebug('ResidentRootVisuSetup',$result,0);
            $this->SetValue('Status','VISU: '.$result);
        }catch(Throwable $ignored){}
    }

    public function RestoreKnownRootVisu():void
    {
        $result=$this->restoreKnownRootVisuSafely();
        try{
            $this->SendDebug('ResidentRootVisuRestore',$result,0);
            $this->SetValue('Status','VISU: '.$result);
        }catch(Throwable $ignored){}
    }

    private function installKnownRootVisuSafely(bool $manual=false):string
    {
        $root=36895;$owner=32969;$parent=33054;$new=0;
        $changed=[];$old=[];$linkId=0;$created=false;
        try{
            if(!isset($this->InstanceID)||$this->InstanceID!==$owner)
                return 'NICHT installiert: SHADOW-Instanz ist nicht #32969.';
            foreach(['IPS_GetObject','IPS_GetChildrenIDs','IPS_GetLink',
                'IPS_CreateLink','IPS_SetLinkTargetID','IPS_SetParent',
                'IPS_SetName','IPS_SetPosition','IPS_SetHidden',
                'IPS_LinkExists','IPS_DeleteLink'] as $fn)
                if(!function_exists($fn))
                    return 'NICHT installiert: Symcon-API '.$fn.' fehlt.';
            $r=IPS_GetObject($root);
            $p=IPS_GetObject($parent);
            $o=IPS_GetObject($owner);
            if(($r['ObjectType']??null)!==0
                ||($r['ObjectName']??null)!=='Anwesenheitserkennung'
                ||($r['ObjectIsHidden']??false)===true
                ||($p['ObjectType']??null)!==0
                ||($p['ParentID']??null)!==$root
                ||($o['ObjectType']??null)!==1
                ||($o['ParentID']??null)!==$parent)
                return 'NICHT installiert: die bekannte Kategorie-/Instanzstruktur wurde veraendert.';
            $prior=json_decode($this->ReadAttributeString('ResidentRootVisuSetup'),true);
            if(is_array($prior)&&($prior['phase']??'')==='installed'){
                $oldLink=(int)($prior['linkId']??0);
                if($oldLink>0&&IPS_LinkExists($oldLink)
                    &&(IPS_GetLink($oldLink)['TargetID']??null)===$owner
                    &&(IPS_GetObject($oldLink)['ParentID']??null)===$root
                    &&(IPS_GetObject($oldLink)['ObjectIsHidden']??true)===false)
                    return 'BEREITS EINGERICHTET: Bewohnerstatus-Link #'.$oldLink
                        .' direkt unter Anwesenheitserkennung; alle anderen Kacheln ausgeblendet.';
                return 'PRUEFUNG ERFORDERLICH: fruehere VISU-Einrichtung veraendert; nicht erneut ueberschreiben.';
            }
            if(is_array($prior)&&($prior['phase']??'')==='prepared')
                return 'PRUEFUNG ERFORDERLICH: vorherige Einrichtung nicht abgeschlossen; nichts ueberschrieben.';
            if(is_array($prior)&&($prior['phase']??'')==='restored'&&!$manual)
                return 'Vorherige VISU-Anordnung wurde bewusst wiederhergestellt; automatische Neueinrichtung gesperrt.';
            $children=IPS_GetChildrenIDs($root);
            if(!is_array($children)||count($children)>100
                ||!in_array($parent,$children,true))
                return 'NICHT installiert: Zielkategorie nicht vollstaendig pruefbar.';
            foreach($children as $id){
                if(!is_int($id)||$id<=0)continue;
                $child=IPS_GetObject($id);
                if(!is_array($child)||($child['ParentID']??null)!==$root)
                    return 'NICHT installiert: Kindobjekt-Verifikation fehlgeschlagen.';
                if(($child['ObjectType']??null)===6){
                    $link=IPS_GetLink($id);
                    if(!is_array($link))
                        return 'NICHT installiert: bestehende Links unlesbar.';
                    if(($link['TargetID']??null)===$owner){
                        if($linkId>0)
                            return 'NICHT installiert: mehrere Bewohnerlinks gefunden; zuerst pruefen.';
                        $linkId=$id;
                    }
                }
                if(($child['ObjectName']??null)==='Bewohnerstatus'
                    &&(($child['ObjectType']??null)!==6
                        ||(IPS_GetLink($id)['TargetID']??null)!==$owner))
                    return 'NICHT installiert: fremdes Objekt Bewohnerstatus vorhanden.';
                $old[$id]=($child['ObjectIsHidden']??false)===true;
            }
            // Save a persistent BEFORE image prior to modifying other
            // visible objects. No other source properties are written.
            $prepared=[
                'schema'=>1,'phase'=>'prepared','root'=>$root,
                'owner'=>$owner,'parent'=>$parent,'linkId'=>$linkId,
                'createdLink'=>false,'visibilityBefore'=>$old
            ];
            $this->WriteAttributeString('ResidentRootVisuSetup',
                json_encode($prepared,JSON_UNESCAPED_SLASHES)?:'{}');
            if($linkId===0){
                $new=IPS_CreateLink();
                if(!is_int($new)||$new<=0)
                    throw new RuntimeException('LINK_CREATE_FAILED');
                $created=true;$linkId=$new;
                if(IPS_SetParent($linkId,$root)!==true
                    ||IPS_SetLinkTargetID($linkId,$owner)!==true
                    ||IPS_SetName($linkId,'Bewohnerstatus')!==true
                    ||IPS_SetPosition($linkId,0)!==true)
                    throw new RuntimeException('LINK_CONFIG_FAILED');
            }
            if((IPS_GetLink($linkId)['TargetID']??null)!==$owner
                ||(IPS_GetObject($linkId)['ParentID']??null)!==$root)
                throw new RuntimeException('LINK_VERIFY_FAILED');
            if((IPS_GetObject($linkId)['ObjectIsHidden']??false)===true){
                if(IPS_SetHidden($linkId,false)!==true)
                    throw new RuntimeException('LINK_VISIBILITY_FAILED');
                $changed[$linkId]=true;
            }
            foreach($old as $id=>$wasHidden){
                if($id===$linkId||$wasHidden)continue;
                if(IPS_SetHidden((int)$id,true)!==true)
                    throw new RuntimeException('HIDE_FAILED');
                $changed[(int)$id]=true;
            }
            $prepared['phase']='installed';
            $prepared['linkId']=$linkId;
            $prepared['createdLink']=$created;
            $this->WriteAttributeString('ResidentRootVisuSetup',
                json_encode($prepared,JSON_UNESCAPED_SLASHES)?:'{}');
            return 'ERFOLG: Link #'.$linkId.' direkt in #'.$root
                .'; '.count(array_filter($old,static fn(bool $hidden)=>!$hidden))
                .' bisher sichtbare Nachbarobjekte ausgeblendet. '
                .'P03, WLAN und SHADOW arbeiten unveraendert.';
        }catch(Throwable $e){
            // Only revert things this call changed. All old visibility
            // settings are restored, including originally hidden objects.
            foreach(array_keys($changed) as $id){
                try {
                    $original=$old[$id]??true;
                    IPS_SetHidden((int)$id,$original);
                }catch(Throwable $ignored){}
            }
            if($created&&$new>0){
                try{if(IPS_LinkExists($new))IPS_DeleteLink($new);}
                catch(Throwable $ignored){}
            }
            try{$this->WriteAttributeString('ResidentRootVisuSetup','{}');}
            catch(Throwable $ignored){}
            return 'FEHLER: '.get_class($e).' bei Einrichtung; '
                .'Aenderungen soweit moeglich zurueckgenommen.';
        }
    }

    private function restoreKnownRootVisuSafely():string
    {
        try{
            $v=json_decode($this->ReadAttributeString('ResidentRootVisuSetup'),true);
            if(!is_array($v)||($v['phase']??'')!=='installed'
                ||($v['root']??null)!==36895
                ||($v['owner']??null)!==32969
                ||!is_array($v['visibilityBefore']??null))
                return 'Keine gesicherte abgeschlossene Einrichtung vorhanden.';
            $root=IPS_GetObject(36895);
            if(($root['ObjectType']??null)!==0)
                return 'Kategorie fehlt, Wiederherstellung abgebrochen.';
            $before=$v['visibilityBefore'];
            foreach($before as $id=>$hidden){
                $id=(int)$id;
                if($id<=0||!is_bool($hidden))continue;
                if((IPS_GetObject($id)['ParentID']??null)!==36895)
                    return 'Objekt verschoben, Wiederherstellung nicht automatisch moeglich.';
            }
            foreach($before as $id=>$hidden){
                if(IPS_SetHidden((int)$id,$hidden)!==true)
                    return 'Wiederherstellung unvollstaendig; erneut pruefen.';
            }
            $linkId=(int)($v['linkId']??0);
            if(($v['createdLink']??false)===true&&$linkId>0
                &&IPS_LinkExists($linkId)
                &&(IPS_GetLink($linkId)['TargetID']??null)===32969
                &&(IPS_GetObject($linkId)['ParentID']??null)===36895
                &&(IPS_GetObject($linkId)['ObjectName']??null)==='Bewohnerstatus')
                IPS_DeleteLink($linkId);
            $this->WriteAttributeString('ResidentRootVisuSetup',
                '{"phase":"restored","schema":1}');
            return 'Vorherige Objekt-Sichtbarkeit unter Anwesenheitserkennung wiederhergestellt.';
        }catch(Throwable $e){
            return 'Wiederherstellung nicht erfolgreich ('.get_class($e).').';
        }
    }

    /**
     * Passive phone WiFi bridge delivery-path check. Reads ONLY the public
     * mapping metadata of WebHook Control. Never reads personal tokens,
     * changes any WebHook, invokes a remote endpoint or probes smartphones.
     */
    private function readPhoneBridgeHookMetadata():array
    {
        $out=['schema'=>1,
            'authority'=>'LOCAL_WIFI_BRIDGE_HOOK_METADATA_READ_ONLY',
            'status'=>'HOOK_UNVERIFIABLE','targetScriptID'=>null,
            'noSecretValuesRead'=>true,'noHookModified'=>true];
        try{
            if(!function_exists('IPS_GetInstanceListByModuleID')
                ||!function_exists('IPS_GetProperty')
                ||!function_exists('IPS_GetObject'))
                return $out;
            $ids=IPS_GetInstanceListByModuleID(
                '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}'
            );
            if(!is_array($ids)||count($ids)!==1)return $out;
            $hooks=json_decode((string)IPS_GetProperty((int)$ids[0],
                'Hooks'),true);
            if(!is_array($hooks)||!array_is_list($hooks)
                ||count($hooks)>500)return $out;
            $found=null;$count=0;
            foreach($hooks as $item){
                if(!is_array($item)
                    ||!is_string($item['Hook']??null)
                    ||!is_int($item['TargetID']??null))return $out;
                if($item['Hook']!=='/hook/jvphonewifi')continue;
                $count++;$found=$item['TargetID'];
            }
            if($count>1){
                $out['status']='HOOK_CONFLICT';return $out;
            }
            if($count===0){
                $out['status']='HOOK_NOT_REGISTERED';return $out;
            }
            $target=IPS_GetObject((int)$found);
            if(!is_array($target)
                ||($target['ObjectType']??null)!==3){
                $out['status']='HOOK_TARGET_INVALID';return $out;
            }
            // Do not publish the webhook's contents, token IDs or body.
            $out['status']='HOOK_ASSIGNED_VALID_SCRIPT';
            $out['targetScriptID']=(int)$found;
            return $out;
        }catch(Throwable $ignored){return $out;}
    }

    /**
     * Local, metadata-only candidate scan. Does not read VARIABLE VALUES,
     * switch devices, bind sources, or change the user's P03/LCN/WLAN.
     * Names are NOT verified door/gate inputs, merely suggestions.
     */
    public function AuditExistingExitCandidates(): void
    {
        $items=[];$limited=false;$scanCount=0;$warnings=[];
        try {
            $ids=IPS_GetVariableList();
            if(!is_array($ids)) throw new RuntimeException('INVALID_VARIABLE_LIST');
            $limited=count($ids)>8000;
            foreach(array_slice($ids,0,8000) as $rawId) {
                $id=(int)$rawId;
                if($id<=0)continue;
                $scanCount++;
                try {
                    $variable=IPS_GetVariable($id);
                    if(!in_array(($variable['VariableType']??null),[0,1],true))
                        continue;
                    $obj=IPS_GetObject($id);
                    $parentId=(int)($obj['ParentID']??0);
                    $parentName=$parentId>0?(string)IPS_GetName($parentId):'';
                    $grandName='';
                    if($parentId>0) {
                        $parentObj=IPS_GetObject($parentId);
                        $grandId=(int)($parentObj['ParentID']??0);
                        if($grandId>0)$grandName=(string)IPS_GetName($grandId);
                    }
                    $items[]=[
                        'variableId'=>$id,
                        'type'=>$variable['VariableType'],
                        'variableName'=>(string)IPS_GetName($id),
                        'parent'=>$parentName,
                        'grandparent'=>$grandName
                    ];
                } catch(Throwable $e) {
                    // Read failure does not make an unknown sensor a valid
                    // exit source. Keep diagnostic as incomplete scan.
                    $limited=true;
                }
            }
        } catch(Throwable $e) {
            $warnings[]='EXIT_INVENTORY_UNAVAILABLE';
        }
        $result=JVExitCandidateInventory::select($items,$limited);
        $result['scannedVariables']=$scanCount;
        $result['warnings']=$warnings;
        $result['scannedAt']=time();
        // Retain the metadata-only audit across the 60-second Refresh timer.
        // No Symcon objects or sensor bindings are created or changed.
        $snapshot=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(is_string($snapshot)) $this->writeShadowState('ExitCandidateInventory',$snapshot);
        $this->syncGateSubscriptions();
        $this->publish($result,'Ausgaenge/Kontakte NUR LESEN: '
            .$result['candidateCount'].' priorisierte Statuskandidaten aus '
            .$result['legacyBroadMatchCount'].' groben Namens-Treffern. '
            .'Keine Zuordnung/Aktivierung; Namen und IDs in Nachweise (JSON).');
    }

    /**
     * Begin PASSIVE observation of previously inventoried Schiebetor inputs.
     * No gate commands, no configuration edits, no new output variables.
     * The existing 60s SHADOW read now tracks only existing Boolean values.
     */
    public function AuditExistingGateSignals(): void
    {
        if(!$this->ReadPropertyBoolean('Enabled')) {
            $this->Refresh();
            return;
        }
        $inventory=json_decode($this->readShadowState('ExitCandidateInventory'),true);
        if(!is_array($inventory)
            || ($inventory['authority']??'')!=='READ_ONLY_EXIT_SOURCE_CANDIDATES'
            || !is_array($inventory['listedCandidates']??null)) {
            $this->publish([
                'schema'=>1,'authority'=>'READ_ONLY_GATE_SIGNAL_SNAPSHOTS',
                'error'=>'EXIT_INVENTORY_REQUIRED',
                'personPresenceMayBeChanged'=>false,
                'propertyEmptyMayBeCertified'=>false
            ],'Zuerst Button 3: vorhandene Tor-Variablen suchen (nur lesen)');
            return;
        }
        $prior=json_decode($this->readShadowState('GateSignalLedger'),true);
        if(!is_array($prior))$prior=[];
        $prior['active']=true;
        $next=json_encode($prior,JSON_UNESCAPED_SLASHES);
        if(is_string($next))$this->writeShadowState('GateSignalLedger',$next);
        $this->syncGateSubscriptions();
        $this->Refresh();
    }

    /**
     * Inspect ONLY variable metadata for indoor lighting and television
     * candidates. No values read, no SensorSources changes, no automation.
     */
    public function AuditIndoorHintCandidates(): void
    {
        $entries=[];$limited=false;$count=0;
        try {
            $ids=IPS_GetVariableList();
            if(!is_array($ids))throw new RuntimeException('INVALID_LIST');
            $limited=count($ids)>8000;
            foreach(array_slice($ids,0,8000) as $rawId){
                $id=(int)$rawId;
                if($id<=0)continue;
                $count++;
                try {
                    $meta=IPS_GetVariable($id);
                    if(!in_array($meta['VariableType']??null,[0,1],true))continue;
                    $obj=IPS_GetObject($id);
                    $pid=(int)($obj['ParentID']??0);
                    $parent=$pid>0?(string)IPS_GetName($pid):'';
                    $grand='';
                    if($pid>0){
                        $po=IPS_GetObject($pid);
                        $g=(int)($po['ParentID']??0);
                        if($g>0)$grand=(string)IPS_GetName($g);
                    }
                    $entries[]=[
                        'variableId'=>$id,
                        'type'=>$meta['VariableType'],
                        'variableName'=>(string)IPS_GetName($id),
                        'parent'=>$parent,'grandparent'=>$grand
                    ];
                }catch(Throwable $e){$limited=true;}
            }
        }catch(Throwable $e){$limited=true;}
        $result=JVIndoorHintInventory::select($entries,$limited);
        $result['scannedVariables']=$count;
        $result['scannedAt']=time();
        $json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(is_string($json))$this->writeShadowState('IndoorHintInventory',$json);
        $this->publish($result,'Innenlicht/TV NUR LESEN: '
            .$result['candidateCount'].' Statuskandidaten; IDs einzeln prüfen '
            .'und ausschließlich in SensorSources zuordnen. Außenlicht ausgeschlossen.');
    }

    /**
     * Metadata-only scan of already existing Symcon INSTANCES which might
     * represent additional IPC cameras. Never read secrets or connect to
     * IP addresses, reconfigure any camera or modify foreign modules.
     */
    public function AuditOtherIPCCandidates(): void
    {
        $entries=[];$matched=[];$limited=false;$scanned=0;
        try {
            $ids=IPS_GetInstanceList();
            if(!is_array($ids))throw new RuntimeException('NO_LIST');
            $limited=count($ids)>3000;
            foreach(array_slice($ids,0,3000) as $raw){
                $id=(int)$raw;if($id<=0)continue;$scanned++;
                try{
                    $o=IPS_GetObject($id);
                    $parentId=(int)($o['ParentID']??0);
                    $entries[]=[
                        'instanceId'=>$id,
                        'name'=>(string)IPS_GetName($id),
                        'parent'=>$parentId>0?(string)IPS_GetName($parentId):''
                    ];
                    // Qualify known VLAN107 camera hosts through narrowly
                    // allowlisted existing Symcon configuration properties.
                    // Never output or forward an entire configuration or
                    // credentials. No direct network connections are made.
                    try{
                        $conf=IPS_GetConfiguration($id);
                        if(!is_string($conf)||strlen($conf)>262144)continue;
                        $props=json_decode($conf,true);
                        if(!is_array($props))continue;
                        $cameraIP=null;
                        foreach($props as $key=>$value){
                            if(!is_string($key)||!is_string($value)
                                ||!in_array(strtolower($key),[
                                'host','hostname','ip','ipaddress',
                                'cameraip','camerahost','serverip',
                                'address','server','url'],true))continue;
                            if(preg_match('/(?<![0-9])192\\.168\\.107\\.([0-9]{1,3})(?![0-9])/',
                                $value,$match)){
                                $last=(int)$match[1];
                                if($last>=1&&$last<=254){
                                    $cameraIP='192.168.107.'.$last;
                                    break;
                                }
                            }
                        }
                        if($cameraIP===null)continue;
                        $eventVariableId=0;
                        try{
                            $inst=IPS_GetInstance($id);
                            if(strcasecmp((string)($inst['ModuleInfo']['ModuleID']??''),
                                self::AUX_GUID)===0){
                                $vid=@IPS_GetObjectIDByIdent('LastIVSEvent',$id);
                                if(is_int($vid)&&$vid>0&&IPS_VariableExists($vid)
                                    &&(IPS_GetVariable($vid)['VariableType']??null)===3
                                    &&(int)(IPS_GetObject($vid)['ParentID']??0)===$id)
                                    $eventVariableId=$vid;
                            }
                        }catch(Throwable $e){}
                        $matched[]=['instanceId'=>$id,'ip'=>$cameraIP,
                            'label'=>substr((string)IPS_GetName($id),0,100),
                            'eventVariableId'=>$eventVariableId];
                    }catch(Throwable $e){}
                }catch(Throwable $e){$limited=true;}
            }
        }catch(Throwable $e){$limited=true;}
        $result=JVAdditionalIPCInventory::evaluate($entries,$limited);
        $result['scannedInstances']=$scanned;
        $result['scannedAt']=time();
        $json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(is_string($json))$this->writeShadowState('AdditionalIPCInventory',$json);
        $qualified=JVKnownIpcMetadata::evaluate($matched,$limited);
        $qualified['symconInstancesScanned']=$scanned;
        $qualified['scannedAt']=time();
        $qualifiedJson=json_encode($qualified,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(is_string($qualifiedJson))
            $this->writeShadowState('SupplementalIpcMetadata',$qualifiedJson);
        $result['qualifiedOtherIpcCandidates']=$qualified['otherIpcCandidates'];
        $result['qualifiedOtherIpcFoundInSymcon']=$qualified['otherIpcFoundInSymcon'];
        $result['qualifiedAuditIsNetworkFree']=true;
        $this->publish($result,'Weitere IPCs: '.$result['candidateCount']
            .' Instanznamen-Kandidaten (nur lesen, nicht aktiv zugeordnet). '
            .'Kameramodell, Smart-Plan und tatsächliche Sicht auf Straßenausgang separat prüfen.');
    }

    /**
     * Consume only existing, explicitly qualified P03 Aux LastIVSEvent audit
     * strings from other IPCs. An event is NOT a human count or street exit.
     */
    private function readSupplementaryIpcEvidence(?array $inventory):array
    {
        $records=[];
        if(!is_array($inventory)||!is_array($inventory['otherIpcCandidates']??null))
            return JVSupplementaryIpcEvidence::evaluate([]);
        foreach(array_slice($inventory['otherIpcCandidates'],0,16) as $camera){
            if(!is_array($camera)||!is_string($camera['ip']??null))continue;
            $ip=$camera['ip'];
            foreach(array_slice($camera['existingP03AuxEventSources']??[],0,8) as $src){
                if(count($records)>=16)break 2;
                if(!is_array($src)||!is_int($src['instanceId']??null)
                    ||!is_int($src['eventVariableId']??null))continue;
                $id=$src['instanceId'];$vid=$src['eventVariableId'];
                if($id<=0||$vid<=0)continue;
                try{
                    $inst=IPS_GetInstance($id);
                    if(strcasecmp((string)($inst['ModuleInfo']['ModuleID']??''),
                        self::AUX_GUID)!==0)continue;
                    $conf=IPS_GetConfiguration($id);
                    if(!is_string($conf)||strlen($conf)>262144)continue;
                    $props=json_decode($conf,true);
                    if(!is_array($props)
                        ||(string)($props['CameraHost']??'')!==$ip)continue;
                    $actual=@IPS_GetObjectIDByIdent('LastIVSEvent',$id);
                    if($actual!==$vid||!IPS_VariableExists($vid))continue;
                    $meta=IPS_GetVariable($vid);
                    $obj=IPS_GetObject($vid);
                    if(($meta['VariableType']??null)!==3
                        ||(int)($obj['ParentID']??0)!==$id)continue;
                    $updated=$meta['VariableUpdated']??null;
                    if(!is_int($updated)||$updated<1||$updated>time()+5)
                        continue;
                    $value=GetValue($vid);
                    if(!is_string($value)||strlen($value)>8192)continue;
                    $records[]=[
                        'ip'=>$ip,'ageSeconds'=>max(0,time()-$updated),
                        'updatedAt'=>$updated,'raw'=>$value
                    ];
                }catch(Throwable $e){}
            }
        }
        return JVSupplementaryIpcEvidence::evaluate($records);
    }

    /**
     * Only two current P03 mast Aux observers are examined, each by exact
     * module GUID + configured role + current camera host. This is NOT a
     * Camera CGI call; even diagnostics use only already local IPS state.
     */
    private function readP03MastObserverHealth():array
    {
        $result=[];
        foreach([
            'JV_LEFT'=>['id'=>57215,'host'=>'192.168.107.95'],
            'WORK_LEFT'=>['id'=>27938,'host'=>'192.168.107.94']
        ] as $role=>$expected) {
            $id=$expected['id'];
            $row=['instanceId'=>$id,'guidVerified'=>false,
                'role'=>'UNKNOWN','host'=>'UNKNOWN',
                'streamOK'=>null,'diagnostic'=>[]];
            try{
                if(!IPS_InstanceExists($id)){$result[$role]=$row;continue;}
                $inst=IPS_GetInstance($id);
                if(strcasecmp(
                    (string)($inst['ModuleInfo']['ModuleID']??''),self::AUX_GUID
                )!==0){$result[$role]=$row;continue;}
                $conf=IPS_GetConfiguration($id);
                if(!is_string($conf)||strlen($conf)>65536){
                    $result[$role]=$row;continue;
                }
                $props=json_decode($conf,true);
                if(!is_array($props)){$result[$role]=$row;continue;}
                $host=$props['CameraHost']??null;
                $actualRole=$props['Role']??null;
                if($host!==$expected['host']||$actualRole!==$role){
                    $result[$role]=$row;continue;
                }
                $row['guidVerified']=true;
                $row['role']=$actualRole;
                $row['host']=$host;
                $var=@IPS_GetObjectIDByIdent('StreamOK',$id);
                if(is_int($var)&&$var>0&&IPS_VariableExists($var)){
                    $meta=IPS_GetVariable($var);
                    $obj=IPS_GetObject($var);
                    if(($meta['VariableType']??null)===0
                        &&(int)($obj['ParentID']??0)===$id) {
                        $val=GetValue($var);
                        if(is_bool($val))$row['streamOK']=$val;
                    }
                }
                // Public existing P03 Aux API explicitly provides a read-only
                // in-memory ObserverStatus. Do not call polling/handshake.
                if(function_exists('JVP03AUX_ReadP03Diagnostic')){
                    $raw=JVP03AUX_ReadP03Diagnostic($id,'ObserverStatus');
                    if(is_string($raw)&&strlen($raw)<=8192){
                        $diag=json_decode($raw,true);
                        if(is_array($diag)
                            &&($diag['role']??null)===$role
                            &&($diag['host']??null)===$host){
                            $safe=[];
                            foreach(['rxAge','streaming','authBlocked',
                                'authPending','parentOpen','configReady'] as $key){
                                if(array_key_exists($key,$diag))
                                    $safe[$key]=$diag[$key];
                            }
                            $row['diagnostic']=$safe;
                        }
                    }
                }
            }catch(Throwable $e){}
            $result[$role]=$row;
        }
        return $result;
    }

    /**
     * Four existing variables only. A phone Boolean and Unix contact integer
     * must be of the known type and EXACT known name, both under the same
     * preexisting parent. Do not query WLAN, ARP, IP, MAC or execute scripts.
     * A missing/reused ID is UNKNOWN; NEVER count it as a person leaving.
     */
    private function readExistingWLANPhones():array
    {
        $result=[];
        foreach([
            'johannes'=>[
                'bool'=>$this->ReadPropertyInteger('JohannesWLANBoolID'),
                'time'=>$this->ReadPropertyInteger('JohannesWLANLastContactID')
            ],
            'carina'=>[
                'bool'=>$this->ReadPropertyInteger('CarinaWLANBoolID'),
                'time'=>$this->ReadPropertyInteger('CarinaWLANLastContactID')
            ]
        ] as $person=>$ids){
            $entry=[
                'verified'=>false,'wlanValue'=>null,'lastContact'=>null,
                'boolId'=>$ids['bool'],'lastContactId'=>$ids['time']
            ];
            try{
                $bid=$ids['bool'];$tid=$ids['time'];
                if($bid<=0||$tid<=0||$bid===$tid
                    ||!IPS_VariableExists($bid)||!IPS_VariableExists($tid)){
                    $result[$person]=$entry;continue;
                }
                $bv=IPS_GetVariable($bid);$tv=IPS_GetVariable($tid);
                if(($bv['VariableType']??null)!==0
                    ||($tv['VariableType']??null)!==1
                    ||trim((string)IPS_GetName($bid))!=='WLAN anwesend'
                    ||trim((string)IPS_GetName($tid))!=='Letzter Kontakt'){
                    $result[$person]=$entry;continue;
                }
                $bp=(int)(IPS_GetObject($bid)['ParentID']??0);
                $tp=(int)(IPS_GetObject($tid)['ParentID']??0);
                if($bp<=0||$bp!==$tp){
                    $result[$person]=$entry;continue;
                }
                $bool=GetValue($bid);$last=GetValue($tid);
                if(!is_bool($bool)||!is_int($last)){
                    $result[$person]=$entry;continue;
                }
                $entry['verified']=true;
                $entry['wlanValue']=$bool;
                $entry['lastContact']=$last;
            }catch(Throwable $ignored){}
            $result[$person]=$entry;
        }
        return $result;
    }


    /**
     * EgiGeoZone: existing #41869 Zuhause Boolean, not TestGeoZone. Use
     * #28055 Timestamp Integer under known device #50000 / Egi #35965.
     * No latitude/longitude reads, no webhook setup or module actions.
     * An incorrect/recycled object ID fails closed BEFORE any value read.
     */
    private function readExistingJohannesEgiGeoZone():array
    {
        $snapshot=['verified'=>false,'inside'=>null,'timestamp'=>null];
        try{
            $root=$this->ReadPropertyInteger('JohannesEgiInstanceID');
            $device=$this->ReadPropertyInteger('JohannesEgiDeviceID');
            $zone=$this->ReadPropertyInteger('JohannesEgiZoneVariableID');
            $timestamp=$this->ReadPropertyInteger('JohannesEgiTimestampID');
            if($root<=0||$device<=0||$zone<=0||$timestamp<=0
                ||count(array_unique([$root,$device,$zone,$timestamp]))!==4
                ||!IPS_VariableExists($zone)
                ||!IPS_VariableExists($timestamp))return $snapshot;
            // EgiGeoZone holds a dummy device with its own child variables.
            $deviceObject=IPS_GetObject($device);
            if(($deviceObject['ParentID']??null)!==$root)return $snapshot;
            $zoneObject=IPS_GetObject($zone);
            $timestampObject=IPS_GetObject($timestamp);
            if(($zoneObject['ParentID']??null)!==$device
                ||($timestampObject['ParentID']??null)!==$device
                ||IPS_GetName($zone)!==$this->ReadPropertyString('JohannesEgiZoneName')
                ||IPS_GetName($timestamp)!=='Timestamp')return $snapshot;
            $zoneMeta=IPS_GetVariable($zone);
            $timeMeta=IPS_GetVariable($timestamp);
            if(($zoneMeta['VariableType']??null)!==0
                ||($timeMeta['VariableType']??null)!==1)return $snapshot;
            // EgiGeoZone has ONE device-wide Timestamp for ALL zones.
            // Its webhook writes Timestamp BEFORE the particular zone
            // Boolean. If the Timestamp is newer than the HOME Boolean,
            // that last event belongs to Schiebetor/another zone, not HOME.
            // Reject missing update metadata too: without a matching pair,
            // there is no safe per-zone event timestamp to correlate.
            // Keep a small positive allowance for two sequential Symcon
            // SetValue calls, never the prior 60-second cross-zone window.
            $zoneUpdated=$zoneMeta['VariableUpdated']??null;
            $timestampUpdated=$timeMeta['VariableUpdated']??null;
            if(!is_int($zoneUpdated)||$zoneUpdated<=0
                ||!is_int($timestampUpdated)||$timestampUpdated<=0
                ||$zoneUpdated<$timestampUpdated
                ||$zoneUpdated-$timestampUpdated>5)
                return $snapshot;
            $value=GetValue($zone);
            $at=GetValue($timestamp);
            if(!is_bool($value)||!is_int($at))return $snapshot;
            return ['verified'=>true,'inside'=>$value,'timestamp'=>$at,
                'sourceKey'=>'EGI:'.$root.':'.$device.':'.$zone.':'.$timestamp];
        }catch(Throwable $ignored){
            return $snapshot;
        }
    }


    /**
     * Button 7: discover the Carina device under the known Geofency module.
     * Metadata only! NO GetValue(), hooks, GPS values or iPhone commands.
     */
    public function AuditCarinaGeofencyCandidates():void
    {
        $rows=[];$limited=false;$scanned=0;
        try{
            $ids=IPS_GetVariableList();
            if(!is_array($ids))throw new RuntimeException('NO_VARIABLE_LIST');
            $limited=count($ids)>8000;
            foreach(array_slice($ids,0,8000) as $rawID){
                $id=(int)$rawID;
                if($id<=0)continue;
                $scanned++;
                try{
                    $meta=IPS_GetVariable($id);
                    $type=$meta['VariableType']??null;
                    if(!in_array($type,[0,1],true))continue;
                    $deviceID=(int)(IPS_GetObject($id)['ParentID']??0);
                    if($deviceID<=0)continue;
                    $root=(int)(IPS_GetObject($deviceID)['ParentID']??0);
                    if($root<=0)continue;
                    $info=IPS_GetInstance($root);
                    $guid=(string)($info['ModuleInfo']['ModuleID']??'');
                    if(strcasecmp($guid,JVCarinaGeofencyHints::GEOFENCY_GUID)!==0)continue;
                    $rows[]=[
                        'id'=>$id,'name'=>(string)IPS_GetName($id),
                        'type'=>$type,'deviceID'=>$deviceID,
                        'deviceName'=>(string)IPS_GetName($deviceID),
                        'rootID'=>$root,'rootGUID'=>JVCarinaGeofencyHints::GEOFENCY_GUID
                    ];
                }catch(Throwable $ignored){}
            }
        }catch(Throwable $ignored){$limited=true;}
        $result=JVCarinaGeofencyHints::inventory($rows,$limited,$scanned);
        $result['scannedAt']=time();
        $encoded=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(is_string($encoded))
            $this->writeShadowState('CarinaGeofencyInventory',$encoded);
        $this->publish($result,'Carina Geofency: '
            .$result['candidateCount'].' vorhandene Standort-/Timestamp-Kandidaten (Metadaten). '
            .'Keine Quellwerte gelesen oder Module verändert. '
            .'IDs in EvidenceJSON ansehen; danach SHADOW erneuern.');
    }

    /**
     * Carina's real Geofency IDs were supplied on 15.10.2026. Exact IDs
     * can be used without scanning the entire Symcon tree first. Device ID,
     * root module GUID, names, types, and timestamp source are revalidated
     * before GetValue on every 60 s SHADOW refresh.
     */
    private function readCarinaGeofencySnapshot():array
    {
        $none=['verified'=>false,'inside'=>null,'timestamp'=>null,
            'zoneName'=>null,'selectionStatus'=>'NOT_VERIFIED'];
        $root=$this->ReadPropertyInteger('CarinaGeofencyInstanceID');
        $device=$this->ReadPropertyInteger('CarinaGeofencyDeviceID');
        $zone=$this->ReadPropertyInteger('CarinaGeofencyZoneVarID');
        $timestamp=$this->ReadPropertyInteger('CarinaGeofencyTimestampID');
        $zoneName=$this->ReadPropertyString('CarinaGeofencyZoneName');
        // IP-Symcon preserves old registered property values on update:
        // v0.8.47's Device/Zone/Timestamp defaults were ALL zero.
        // Only for that precise legacy triple, use the three REAL
        // screenshot-confirmed IDs as a read-only effective mapping.
        // Never write IPS_SetProperty / IPS_ApplyChanges here. Mixed IDs
        // are never silently completed and fail closed below.
        if($device===0 &&$zone===0 &&$timestamp===0
            &&$root===48903 &&$zoneName==='Zuhause'){
            $device=45439;
            $zone=39169;
            $timestamp=45666;
            $none['selectionStatus']='LEGACY_ZERO_PROPERTIES_REAL_IDS_CHECKED';
        }
        // Previously unknown IDs can still be inventoried with Button 7,
        // but neither a stale inventory nor a device name alone is trusted.
        if($root<=0||$device<=0||$zone<=0||$timestamp<=0
            ||count(array_unique([$root,$device,$zone,$timestamp]))!==4
            ||$zoneName===''||strlen($zoneName)>120)return $none;
        try{
            if(!IPS_VariableExists($zone)||!IPS_VariableExists($timestamp))
                return $none;
            $info=IPS_GetInstance($root);
            if(strcasecmp((string)($info['ModuleInfo']['ModuleID']??''),
                JVCarinaGeofencyHints::GEOFENCY_GUID)!==0)return $none;
            if((int)(IPS_GetObject($device)['ParentID']??0)!==$root
                ||(int)(IPS_GetObject($zone)['ParentID']??0)!==$device
                ||(int)(IPS_GetObject($timestamp)['ParentID']??0)!==$device
                ||IPS_GetName($zone)!==$zoneName
                ||IPS_GetName($timestamp)!=='Timestamp')return $none;
            $v=IPS_GetVariable($zone);$t=IPS_GetVariable($timestamp);
            if(($v['VariableType']??null)!==0
                ||($t['VariableType']??null)!==1)return $none;
            // Geofency, like EgiGeoZone, stores ONE Timestamp per device,
            // not per zone. Its webhook first updates that Timestamp, then
            // only the Boolean of the zone which actually changed. A later
            // office zone may never lend its timestamp to Zuhause, even
            // when both HTTP calls arrived seconds apart.
            $zoneUpdated=$v['VariableUpdated']??null;
            $timestampUpdated=$t['VariableUpdated']??null;
            if(!is_int($zoneUpdated)||$zoneUpdated<=0
                ||!is_int($timestampUpdated)||$timestampUpdated<=0
                ||$zoneUpdated<$timestampUpdated
                ||$zoneUpdated-$timestampUpdated>5){
                $none['selectionStatus']='OTHER_GEOFENCE_TIMESTAMP';
                return $none;
            }
            $inside=GetValue($zone);$when=GetValue($timestamp);
            if(!is_bool($inside)||!is_int($when))return $none;
            return ['verified'=>true,'inside'=>$inside,
                'timestamp'=>$when,'zoneName'=>$zoneName,
                'sourceKey'=>'GEOFENCY:'.$root.':'.$device.':'.$zone.':'.$timestamp,
                'selectionStatus'=>'EXACT_SOURCE_IDS_VERIFIED'];
        }catch(Throwable $ignored){return $none;}
    }


    /**
     * ONLY existing phone-specific CURRENT SSID string variables.
     * Do not mistake an AP/SSID nearby for a phone connected to that SSID,
     * or a Shelly device WifiSSID as either resident's handset.
     * No ping, ARP, VLAN scan, UniFi API, hook, action, event or writes.
     */
    private function readWorkWiFiSSIDs():array
    {
        $pairs=[
            'johannes'=>[
                'variableID'=>$this->ReadPropertyInteger('JohannesSSIDVariableID'),
                'parentID'=>$this->ReadPropertyInteger('JohannesSSIDParentID')
            ],
            'carina'=>[
                'variableID'=>$this->ReadPropertyInteger('CarinaSSIDVariableID'),
                'parentID'=>$this->ReadPropertyInteger('CarinaSSIDParentID')
            ]
        ];
        $out=[];
        $j=$pairs['johannes']['variableID'];
        $c=$pairs['carina']['variableID'];
        // One generic SSID source must never falsely identify BOTH phones.
        if($j>0 && $c>0 && $j===$c)
            return ['johannes'=>['verified'=>false],
                'carina'=>['verified'=>false]];
        foreach($pairs as $person=>$pair){
            $out[$person]=['verified'=>false];
            $id=$pair['variableID'];$parent=$pair['parentID'];
            if($id<=0||$parent<=0||$id===$parent)continue;
            try{
                if(!IPS_VariableExists($id))continue;
                $name=(string)IPS_GetName($id);
                if(!in_array($name,['WifiSSID','WLAN SSID','WLAN-SSID',
                    'Aktuelle SSID'],true))continue;
                $meta=IPS_GetVariable($id);
                $updated=$meta['VariableUpdated']??null;
                if(($meta['VariableType']??null)!==3
                    ||!is_int($updated)||$updated<=0
                    ||$updated>time()+5)continue;
                if((int)(IPS_GetObject($id)['ParentID']??0)!==$parent)
                    continue;
                $obj=IPS_GetObject($parent);
                $root=(int)($obj['ParentID']??0);
                $parentName=(string)IPS_GetName($parent);
                // Known genuine device identities OR clearly person-named
                // custom reporter containers; never accept a generic
                // Shelly, AP or another resident's phone source.
                $knownDevice=$person==='johannes'
                    ?($parent===50000&&$root===35965)
                    :($parent===45439&&$root===48903);
                $namedPhone=preg_match(
                    '/(?:^|[^a-z])'.preg_quote($person,'/').'(?:$|[^a-z])/iu',
                    $parentName
                )===1;
                if(!$knownDevice&&!$namedPhone)continue;
                $value=GetValue($id);
                if(!is_string($value))continue;
                $out[$person]=[
                    'verified'=>true,'ssid'=>$value,'updatedAt'=>$updated,
                    'sourceVariableID'=>$id
                ];
            }catch(Throwable $ignored){}
        }
        return $out;
    }

    /**
     * Discover existing per-person WifiSSID values by object metadata only.
     *
     * This is a READ of existing Symcon values, never a WLAN scan, command,
     * AP/controller request, system script or foreign module mutation.
     * Cached for five minutes to avoid expensive full-tree inspection each
     * minute. Missing/ambiguous candidates are unverified, never WORK.
     */
    private function readAutoKnownPhoneSSIDs():array
    {
        $out=['johannes'=>['verified'=>false],
            'carina'=>['verified'=>false]];
        // The inventory is a non-evidence discovery cache. Keep frequent
        // scan timestamps only in RAM, and persist only when the ACTUAL
        // chosen source ID/path changes. This avoids an SD/flash write
        // every five minutes during an otherwise idle, stable household.
        $runtime=$this->GetBuffer('AutoPhoneSSIDRuntimeCache');
        $cache=json_decode($runtime!==''?$runtime:
            $this->ReadAttributeString('AutoPhoneSSIDInventory'),true);
        $now=time();
        if(!is_array($cache) || !is_int($cache['checkedAt']??null)
            ||($cache['checkedAt']??0)>$now+5
            ||($cache['checkedAt']??0)<$now-300
            ||!is_array($cache['people']??null)){
            $possible=['johannes'=>[],'carina'=>[]];
            try{
                $all=IPS_GetVariableList();
                // Complete scan required. A partial scan must NEVER select
                // a seemingly unique phone from a truncated inventory.
                if(!is_array($all)||count($all)>15000)
                    return $out;
                foreach($all as $id){
                    if(!is_int($id)||$id<=0)continue;
                    $name=(string)IPS_GetName($id);
                    if(!in_array($name,[
                        'WifiSSID','WLAN SSID','WLAN-SSID','Aktuelle SSID'
                    ],true))continue;
                    $meta=IPS_GetVariable($id);
                    if(($meta['VariableType']??null)!==3)continue;
                    $parent=(int)(IPS_GetObject($id)['ParentID']??0);
                    if($parent<=0)continue;
                    $parentName=(string)IPS_GetName($parent);
                    $grand=(int)(IPS_GetObject($parent)['ParentID']??0);
                    foreach(['johannes'=>'Johannes',
                        'carina'=>'Carina'] as $key=>$personName){
                        $bridge=$parentName===$personName
                            .' Smartphone SSID Quelle';
                        $known=$key==='johannes'
                            ?($parent===50000&&$grand===35965)
                            :($parent===45439&&$grand===48903);
                        if($bridge||$known)
                            $possible[$key][]=['id'=>$id,'parent'=>$parent,
                                'name'=>$name,'parentName'=>$parentName,
                                'grand'=>$grand];
                    }
                }
            }catch(Throwable $ignored){return $out;}
            $cache=['checkedAt'=>$now,'people'=>[]];
            foreach(['johannes','carina'] as $person){
                // An authenticated, dedicated JV phone reporter wins over
                // an occasional native Geofency WifiSSID value. Without
                // that priority, installing the optional bridge would make
                // two otherwise valid candidates appear ambiguous and
                // silently DISABLE WORK for exactly that phone.
                // Duplicate sources WITHIN the winning tier still fail
                // closed; a generic AP/Shelly SSID is never a candidate.
                $bridges=array_values(array_filter($possible[$person],
                    static fn(array $row):bool=>($row['parentName']??'')
                        ===ucfirst($person).' Smartphone SSID Quelle'));
                $chosen=count($bridges)>0?$bridges:$possible[$person];
                $cache['people'][$person]=count($chosen)===1
                    ?$chosen[0]:null;
            }
            $json=json_encode($cache,JSON_UNESCAPED_SLASHES);
            if(is_string($json)&&strlen($json)<2048){
                $this->SetBuffer('AutoPhoneSSIDRuntimeCache',$json);
                $saved=json_decode(
                    $this->ReadAttributeString('AutoPhoneSSIDInventory'),true
                );
                // Scan times belong to volatile RAM. Only a material
                // source identity change deserves a durable checkpoint.
                $priorSources=is_array($saved)?($saved['people']??[]):[];
                if($priorSources!==$cache['people'])
                    $this->WriteAttributeString('AutoPhoneSSIDInventory',$json);
            }
        }
        $j=$cache['people']['johannes']['id']??null;
        $c=$cache['people']['carina']['id']??null;
        if($j!==null&&$c!==null&&$j===$c)return $out;
        foreach(['johannes','carina'] as $person){
            $p=$cache['people'][$person]??null;
            if(!is_array($p)||!is_int($p['id']??null)
                ||!is_int($p['parent']??null)
                ||!is_string($p['name']??null))continue;
            try{
                $id=$p['id'];$parent=$p['parent'];
                if(!IPS_VariableExists($id)
                    ||IPS_GetName($id)!==$p['name']
                    ||(int)(IPS_GetObject($id)['ParentID']??0)!==$parent
                    ||IPS_GetName($parent)!==($p['parentName']??null)
                    ||(int)(IPS_GetObject($parent)['ParentID']??0)!==($p['grand']??-1))
                    continue;
                $meta=IPS_GetVariable($id);
                $when=$meta['VariableUpdated']??null;
                if(($meta['VariableType']??null)!==3
                    ||!is_int($when)||$when<=0||$when>$now+5)
                    continue;
                $value=GetValue($id);
                if(!is_string($value)||strlen($value)>64)continue;
                $out[$person]=['verified'=>true,'ssid'=>$value,
                    'updatedAt'=>$when,'sourceVariableID'=>$id];
            }catch(Throwable $ignored){}
        }
        return $out;
    }

    public function Refresh(): void
    {
        // Timer, sensor and gate notifications can fire concurrently.
        // Discard overlapping snapshots; the next 60 s poll recovers.
        // A partial concurrent state write could double-book a passage.
        $semaphore='JVPresenceShadow_'.(int)($this->InstanceID??0);
        if(!IPS_SemaphoreEnter($semaphore,1000)){
            // A short GUS impulse may be gone on the next 60-second poll.
            // Request one immediate follow-up after the current owner exits.
            $this->SetBuffer('RefreshPending','1');
            return;
        }
        try {
        if (!$this->ReadPropertyBoolean('Enabled')) {
            // Disabling and later re-enabling within a few minutes must
            // never join unobserved source events across the disabled time.
            if($this->GetBuffer('ShadowObservationStarted')!=='0')
                $this->SetBuffer('ShadowObservationStarted','0');
            $this->setResidentDisplayValues('NICHT BESTÄTIGT','NICHT BESTÄTIGT');
            $this->setPresenceDetected(false);
            $this->setResidentTile('<section>SHADOW deaktiviert – '
                .'Anwesenheit derzeit nicht bestätigt.</section>');
            $this->setTestReport(
                'SHADOW DEAKTIVIERT – keine aktive Beobachtung. '
                .'Im Modul die bestehende Option Beobachtung einschalten '
                .'und Uebernehmen waehlen. Keine Fremdmodule veraendert.'
            );
            $this->publish([
                'schema'=>1,'authority'=>'SHADOW_DISABLED',
                'personPresenceMayBeChanged'=>false,
                'propertyEmptyMayBeCertified'=>false,
                'p03'=>null,'phone'=>null,'warnings'=>[]
            ],'SHADOW AUS – alle anderen Module unverändert');
            return;
        }
        // Inspect durable state BEFORE any cursor, phone or route writes.
        // Invalid critical state is preserved for review, not overwritten
        // automatically by a seemingly clean first-run baseline.
        $upgradePreflight=$this->auditStoredUpgradeState();
        if(($upgradePreflight['safeToRefresh']??false)!==true){
            // Corrupt durable proof records stop observation until review.
            if($this->GetBuffer('ShadowObservationStarted')!=='0')
                $this->SetBuffer('ShadowObservationStarted','0');
            $this->setResidentDisplayValues('NICHT BESTÄTIGT','NICHT BESTÄTIGT');
            $this->setPresenceDetected(false);
            $this->setResidentTile('<section>SHADOW: gespeicherte Beweiskette '
                .'muss geprueft werden. Keine Fremdsteuerung.</section>');
            $issues=$upgradePreflight['criticalIssues']??[];
            $this->setTestReport('SHADOW UPGRADE-SCHUTZ: '
                .implode('; ',array_slice(is_array($issues)?$issues:[],0,3))
                .' | Persistente Beweise UNVERAENDERT. '
                .'Kein Nachweis fuer Abwesenheit, Fahrzeug oder Alarm.');
            $this->publish([
                'schema'=>1,'authority'=>'SHADOW_UPGRADE_READ_ONLY_BLOCK',
                'upgradePreflight'=>$upgradePreflight,
                'personPresenceMayBeChanged'=>false,
                'propertyEmptyMayBeCertified'=>false,
                'mayArmAlarm'=>false,'warnings'=>['CRITICAL_PERSISTENCE_REVIEW']
            ],'SHADOW Zustand pruefen – kein automatisches Ueberschreiben');
            return;
        }
        // RAM buffers vanish on a module/OS restart even if the prior
        // durable poll timestamp is seconds old. Until one complete and
        // successful read finishes, every source is in a fresh observation
        // epoch. Only one volatile buffer flag is needed, no IPS objects.
        $coldStart=$this->GetBuffer('ShadowObservationStarted')!=='1';
        $warnings=[];
        if($coldStart)$warnings[]='SHADOW_SHORT_RESTART_SOURCE_EPOCH_BASELINE';
        $p03Raw=$this->readLocalVariable(
            $this->ReadPropertyInteger('P03InstanceID'),
            self::P03_GUID,'Protocol');
        if ($p03Raw===null) {
            $warnings[]='P03_SOURCE_INVALID_OR_UNAVAILABLE';
            $p03=JVP03ProofLogReplay::parse('');
        } else {
            $p03=JVP03ProofLogReplay::parse($p03Raw);
        }
        if ($p03['warnings']) $warnings=array_merge($warnings,$p03['warnings']);
        // The prior completed P03 field test is a baseline, not a live walk.
        // This rolling proof ledger only consumes lines newly appended to
        // the existing P03 Protocol after this observer begins monitoring.
        $p03Previous=json_decode($this->readShadowState('P03IncrementalTimeline'),true);
        $p03Timeline=JVP03IncrementalTimeline::evaluate(
            $p03Raw??'',JVShadowStartupEpoch::fence(
                'P03IncrementalTimeline',
                is_array($p03Previous)?$p03Previous:[],$coldStart
            ),time()
        );
        if($p03Raw===null) {
            // Do not treat a failed local-variable read as a normal
            // empty P03 log, and do not leave the old P03 cursor alive
            // for the first seemingly healthy subsequent read.
            // The first restored read becomes an ordinary baseline;
            // only later APPENDED proofs qualify as fresh passages.
            $p03Timeline=JVP03IncrementalTimeline::evaluate('',[],time());
            $p03Timeline['report']['warnings'][]='P03_SOURCE_NOT_READABLE';
            $p03Timeline['next']=[
                'initialized'=>false,'lastRaw'=>null,'events'=>[]
            ];
        }
        // Important commit order: do not advance the P03 log cursor until
        // the fused delivery journal has been persisted below. A crash at
        // either boundary can then replay or deduplicate the same proof.
        if($p03Timeline['report']['warnings']) {
            $warnings=array_merge($warnings,
                $p03Timeline['report']['warnings']);
        }

        $phones=[];
        $phoneLink=$this->ReadPropertyBoolean('EnablePhoneIntegration')
            ?$this->resolvePhoneObserver()
            :['state'=>'DISABLED_SEPARATE_PROJECT','instanceId'=>0];
        $phoneInst=$phoneLink['instanceId'];
        if ($phoneInst>0) {
            $phoneRaw=$this->readLocalVariable($phoneInst,self::PHONE_GUID,'EvidenceJSON');
            $data=is_string($phoneRaw) && strlen($phoneRaw)<=65536
                ?json_decode($phoneRaw,true):null;
            if (!is_array($data) || ($data['schema']??0)!==1
                || !in_array(($data['authority']??''),['PHONE_OBSERVATIONS_ONLY','DISABLED'],true)
                || !is_array($data['people']??null)) {
                $warnings[]='PHONE_SOURCE_INVALID_OR_UNAVAILABLE';
            } else {
                foreach (array_slice($data['people'],0,64) as $person) {
                    if (!is_array($person)) continue;
                    $id=$person['id']??null;
                    $state=$person['phoneStatus']??null;
                    if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$id)
                        || !is_string($state)
                        || !in_array($state,[
                            'PHONE_PROPERTY_INDICATION','PHONE_OUTSIDE_INDICATION',
                            'PHONE_CONFLICT','PHONE_UNKNOWN','DISABLED'
                        ],true)) {
                        $warnings[]='PHONE_PROFILE_INVALID';
                        continue;
                    }
                    $phones[$id]=$state;
                }
            }
        }
        // Standalone two-phone WLAN scripts are never edited or executed.
        // Hard-fail closed on reused/wrong object IDs, types, names or parents.
        $existingWlanEnabled=$this->ReadPropertyBoolean('EnableExistingWLANHints');
        $existingWlanSnapshots=$existingWlanEnabled
            ?$this->readExistingWLANPhones():[];
        // Existing LCN, light, gate and vehicle Symcon variables are read
        // locally and ONLY as contextual hints. Never publish HOME/AWAY.
        $sourceRows=json_decode($this->ReadPropertyString('SensorSources'),true);
        if (!is_array($sourceRows)) $sourceRows=[['INVALID'=>'CONFIG_JSON']];
        $snapshots=[];
        $config=JVLocalSensorEvidence::validate($sourceRows);
        if ($config['errors']===[]) {
            foreach($config['sources'] as $source) {
                $varId=$source['variableID'];
                $isIndoor=in_array($source['kind'],['LIGHT_INTERIOR','TV_POWER'],true);
                $snapshots[$varId]=$this->readSensorSnapshot(
                    $varId,$isIndoor
                );
            }
        }
        $state=json_decode($this->readShadowState('LocalSensorState'),true);
        $state=JVShadowStartupEpoch::fence('LocalSensorState',
            is_array($state)?$state:[],$coldStart);
        $sensorData=JVLocalSensorEvidence::evaluate(
            $sourceRows,$snapshots,$state,time());
        $local=$sensorData['report'];
        $newState=json_encode($sensorData['next'],JSON_UNESCAPED_SLASHES);
        if(is_string($newState) && $this->readShadowState('LocalSensorState')!==$newState) {
            $this->writeShadowState('LocalSensorState',$newState);
        }
        if($local['warnings']) {
            $warnings=array_merge($warnings,$local['warnings']);
        }
        // Retain rolling room-level motion edges across 60s polls. A
        // single PIR can fire repeatedly and multiple PIRs can see one
        // person: these are NOT headcounts or identity evidence.
        $ledgerState=json_decode($this->readShadowState('LocalActivityLedger'),true);
        $ledger=JVLocalActivityLedger::evaluate(
            $local['motionSignals'],$local['readings'],
            JVShadowStartupEpoch::fence('LocalActivityLedger',
                is_array($ledgerState)?$ledgerState:[],$coldStart),time());
        $ledgerNext=json_encode($ledger['next'],JSON_UNESCAPED_SLASHES);
        if(is_string($ledgerNext)
            && $this->readShadowState('LocalActivityLedger')!==$ledgerNext) {
            $this->writeShadowState('LocalActivityLedger',$ledgerNext);
        }
        $activity=$ledger['report'];
        // Extract recent room paths from the EXISTING verified 15-GUS
        // ledger. No person labeling, new variable or source subscriptions.
        $roomPaths=JVResidentRoomPaths::evaluate(
            $ledger['next'],$activity,time(),true);
        // Internal full 90-minute history is NEVER serialized to EvidenceJSON.
        $fullRooms=$roomPaths['internalFullRoomEdges90m']??[];
        unset($roomPaths['internalFullRoomEdges90m']);
        $roomPaths['activityInsight']=JVRoomMotionInsight::evaluate(
            $roomPaths,time(),$fullRooms);
        $roomPaths['routeAtlas']=JVAnonymousRoomRouteAtlas::evaluate(
            $roomPaths,$fullRooms,time());
        $roomPaths['dayDigest']=JVRoomDayDigest::evaluate(
            $ledger['next'],$activity,time());
        $powerEnabled=$this->ReadPropertyBoolean('EnableHousePowerRead');
        $roomPaths['powerContext']=JVHousePowerContext::evaluate(
            $powerEnabled?$this->readExistingWattPower():[],
            $roomPaths,time(),$powerEnabled);
        unset($fullRooms);
        $wlanMotionHint=is_int($activity['lastMotionAt']??null)
            &&$activity['lastMotionAt']<=time()
            &&$activity['lastMotionAt']>=time()-600;
        // A motion in the past 24h only increases CONTEXT, never certifies
        // that the handset's owner is physically at home now.
        $wlan=JVExistingWlanPhoneHints::evaluate(
            $existingWlanSnapshots,time(),$existingWlanEnabled,
            $wlanMotionHint
        );
        if($wlan['warnings'])
            $warnings=array_merge($warnings,$wlan['warnings']);
        $workEnabled=$this->ReadPropertyBoolean('EnableWorkWiFiHints');
        $workWiFi=JVCompanyWiFiEvidence::evaluate(
            $workEnabled?$this->readWorkWiFiSSIDs():[],
            time(),$workEnabled
        );
        // Separate app-reported 150m handset location, opt-in and read-only.
        // Neither geo OUT nor no update can automatically set away/alarm.
        $egiEnabled=$this->ReadPropertyBoolean('EnableJohannesEgiGeoZone');
        $egi=JVJohannesEgiGeoZoneHints::evaluate(
            $egiEnabled?$this->readExistingJohannesEgiGeoZone():[],
            time(),$egiEnabled,$this->ReadPropertyInteger('JohannesEgiRadiusMeters')
        );
        if($egi['warnings']){
            foreach($egi['warnings'] as $notice)
                $warnings[]='JOHANNES_EGIGEOZONE:'.$notice;
        }
        $carinaGeoEnabled=$this->ReadPropertyBoolean('EnableCarinaGeofencyHints');
        $carinaGeoSnapshot=$carinaGeoEnabled
            ?$this->readCarinaGeofencySnapshot():[];
        $carinaGeo=JVCarinaGeofencyHints::evaluate(
            $carinaGeoSnapshot,time(),$carinaGeoEnabled,
            (string)($carinaGeoSnapshot['selectionStatus']??'DISABLED')
        );
        foreach($carinaGeo['warnings'] as $notice)
            $warnings[]='CARINA_GEOFENCY:'.$notice;
        foreach($wlan['people'] as $person=>$entry){
            if(($entry['recentMacEvidence']??false)===true
                && !array_key_exists($person,$phones)){
                // Existing PHONE_OBSERVER evidence, if enabled later, takes
                // precedence instead of overwriting it.
                $phones[$person]='PHONE_PROPERTY_INDICATION';
            }
        }
        if($activity['warnings']) {
            $warnings=array_merge($warnings,$activity['warnings']);
        }
        // Explicitly selected, existing indoor output statuses and TVs.
        // Multiple motion-triggered lamps are NOT independent GUS proofs.
        $autoIndoorEnabled=$this->ReadPropertyBoolean('EnableAutoIndoorReadOnlyHints');
        $autoIndoor=$autoIndoorEnabled
            ?$this->readAutoIndoorHintSnapshots($config['sources'])
            :JVIndoorAutoReadHints::evaluate([],[],time());
        $autoIndoor['enabled']=$autoIndoorEnabled;
        // Explicit manual SensorSources remain authoritative and are never
        // modified. The automatic inputs are separate weak read-only hints.
        $indoorHints=JVIndoorPresenceHints::evaluate(
            $local['readings']+$autoIndoor['readings'],time()
        );
        $autoIndoorSummary=$autoIndoor;
        unset($autoIndoorSummary['readings']);
        $houseObservation=JVHomeOccupancyEvidence::evaluate(
            $local,$activity,$p03Timeline['report'],time(),$indoorHints
        );
        // Two residents can cross simultaneously: a single P03 line event
        // must never become a single person or an empty-house assertion.
        $parallelPassage=JVParallelPassageGuard::evaluate(
            $p03Timeline['report'],$p03['street'],time()
        );
        $counterRaw=null;$counterAge=-1;
        if($this->ReadPropertyBoolean('EnableCountTelemetryRead')) {
            $data=$this->readExistingCountTelemetry(
                $this->ReadPropertyInteger('CountTelemetryVariableID')
            );
            $counterRaw=$data['raw'];$counterAge=$data['age'];
        }
        $numberStat=JVDahuaNumberStatEvidence::decode(
            $counterRaw,$counterAge
        );
        $houseObservation['parallelPersonPassage']=$parallelPassage['status'];
        $houseObservation['concurrentPeopleMayBeUndercounted']=true;
        if($houseObservation['warnings']) {
            $warnings=array_merge($warnings,$houseObservation['warnings']);
        }
        $previousBoundary=json_decode($this->readShadowState('BoundaryLedger'),true);
        $boundary=JVBoundaryEvidence::evaluate(
            $local['readings'],
            is_array($previousBoundary)?$previousBoundary:[],
            $p03Timeline['report'],time()
        );
        $nextBoundary=json_encode($boundary['next'],JSON_UNESCAPED_SLASHES);
        if(is_string($nextBoundary)
            && $this->readShadowState('BoundaryLedger')!==$nextBoundary) {
            $this->writeShadowState('BoundaryLedger',$nextBoundary);
        }
        if($boundary['report']['warnings']) {
            $warnings=array_merge($warnings,$boundary['report']['warnings']);
        }
        // Audit snapshots are explicit scans, not automatic sensor discovery.
        // Invalid/missing snapshots remain null, never an empty-exit assertion.
        $snapshot=json_decode($this->readShadowState('ExitCandidateInventory'),true);
        $exitInventory=is_array($snapshot)
            && ($snapshot['authority']??'')==='READ_ONLY_EXIT_SOURCE_CANDIDATES'
            && is_int($snapshot['scannedAt']??null)
            && is_int($snapshot['candidateCount']??null)
            && is_array($snapshot['listedCandidates']??null)
            ? $snapshot : null;
        // Activated only by the explicit manual read-only button. Never
        // attach a gate variable to SensorSources or any actor callback.
        $gateAudit=null;
        $gatePrior=json_decode($this->readShadowState('GateSignalLedger'),true);
        if(is_array($gatePrior)&&($gatePrior['active']??false)===true) {
            $gateCandidates=$exitInventory['listedCandidates']??[];
            $gateSnapshots=$this->readGateCandidateSnapshots($gateCandidates);
            $gateResult=JVGateSignalAudit::evaluate(
                $gateCandidates,$gateSnapshots,$gatePrior,time()
            );
            $gateAudit=$gateResult['report'];
            $subscriptions=json_decode($this->ReadAttributeString('GateSubscriptions'),true);
            $gateAudit['messageSubscriptions']=is_array($subscriptions)
                ?count($subscriptions):0;
            $gateAudit['observationMode']='VM_UPDATE_WITH_60S_FALLBACK';
            $gateNext=json_encode($gateResult['next'],JSON_UNESCAPED_SLASHES);
            if(is_string($gateNext)
                && $this->readShadowState('GateSignalLedger')!==$gateNext)
                $this->writeShadowState('GateSignalLedger',$gateNext);
        }
        $gateDisplay=JVGateDisplayHint::evaluate($gateAudit);
        $insideScan=json_decode($this->readShadowState('IndoorHintInventory'),true);
        $otherCamScan=json_decode($this->readShadowState('AdditionalIPCInventory'),true);
        $extraScan=json_decode($this->readShadowState('SupplementalIpcMetadata'),true);
        $qualifiedIpc=is_array($extraScan)
            &&($extraScan['authority']??'')==='ADDITIONAL_IPC_METADATA_ONLY'
            ?$extraScan:null;
        $extraIpcEvidence=$this->readSupplementaryIpcEvidence($qualifiedIpc);
        $mastHealth=JVP03SourceHealth::evaluate(
            $this->readP03MastObserverHealth(),
            (string)($this->readP03LiveCounters()['proofState']??''),
            time()
        );
        $otherCameraInventory=is_array($otherCamScan)
            && ($otherCamScan['authority']??'')==='ADDITIONAL_IPC_LOCAL_METADATA_ONLY'
            ?$otherCamScan:null;
        $indoorInventory=is_array($insideScan)
            && ($insideScan['authority']??'')==='INDOOR_LIGHT_TV_CANDIDATES_METADATA_ONLY'
            ?$insideScan:null;
        // Independent, read-only AUTO-source detection for the requested
        // single tile. It NEVER mutates other modules or overrides the
        // explicit manual opt-in flags of existing SHADOW diagnostics.
        $tileWlan=$existingWlanEnabled?$wlan:
            JVExistingWlanPhoneHints::evaluate(
                $this->readExistingWLANPhones(),time(),true
            );
        $tileGeoJ=$egiEnabled?$egi:
            JVJohannesEgiGeoZoneHints::evaluate(
                $this->readExistingJohannesEgiGeoZone(),time(),true,
                $this->ReadPropertyInteger('JohannesEgiRadiusMeters')
            );
        $tileGeoC=$carinaGeoEnabled?$carinaGeo:
            JVCarinaGeofencyHints::evaluate(
                $this->readCarinaGeofencySnapshot(),time(),true,'AUTO_READ_ONLY'
            );
        $autoSsid=$this->readAutoKnownPhoneSSIDs();
        $tileWork=JVCompanyWiFiEvidence::evaluate($autoSsid,time(),true);
        // Explicitly configured sources keep their precedence when
        // properly validated; AUTO may fill only missing personal sources.
        foreach(['johannes','carina'] as $person){
            if(($workWiFi['people'][$person]['sourceVerified']??false)===true
                &&in_array(($workWiFi['people'][$person]['status']??''),[
                    'WORK_FIRMEN_WLAN_PHONE','HOME_SSID_PHONE_HINT',
                    'WORK_OR_PRIVATE_PROPERTY_EXT_UNCERTAIN'
                ],true))
                $tileWork['people'][$person]=$workWiFi['people'][$person];
        }
        // A manual SSID binding can overlap another resident's automatic
        // source after both separately verified paths were merged. Recheck
        // the two final phone sources together before any tile/fusion use.
        $tileWork=JVCompanyWiFiEvidence::rejectSharedSource($tileWork);
        // The same final manual+auto, source-identity-checked SSID evidence
        // now feeds ALL person/context evaluators; never present different
        // HOME/WORK decisions in the old string status versus the tile.
        // Context-only fusion. Does NOT overwrite houseObservation.houseStatus
        // or any person presence/absence, and never writes to other modules.
        $combined=JVCombinedPresenceEvidence::evaluate(
            $wlan,$houseObservation,$p03Timeline['report'],
            $boundary['report'],$gateDisplay,$indoorHints,time(),$egi,$carinaGeo,$tileWork
        );
        $houseObservation['combinedContextStatus']=$combined['status'];
        $houseObservation['combinedContextAuthority']=$combined['authority'];
        $houseObservation['absenceEvidenceMissing']=$combined['absenceReview']['missingRequirements'];
        // WLAN evidence supplements physical context without creating an
        // identity, occupancy count or empty-property certificate.
        $houseObservation['wlanDeviceContext']=$wlan['combinedContext'];
        $houseObservation['wlanMacConfirmedDeviceCount']=$wlan['freshMacConfirmedDeviceCount'];
        $houseObservation['wlanCannotCertifyAbsence']=true;
        $houseObservation['johannesGeofenceContext']=$combined['johannesGeofenceContext'];
        $houseObservation['johannesWlanGeofenceCrossCheck']=$combined['johannesWlanGeofenceCrossCheck'];
        $houseObservation['geofenceOutsideCannotCertifyAway']=true;
        $houseObservation['carinaGeofencyContext']=$combined['carinaGeofencyContext'];
        $houseObservation['carinaWlanGeofencyCrossCheck']=$combined['carinaWlanGeofencyCrossCheck'];
        // Device-derived per-resident display is NEVER a physical
        // identification or permission for alarm/presence commands.
        $residentDisplay=JVResidentPresenceDisplay::evaluate(
            $wlan,$egi,$carinaGeo,$tileWork
        );
        $priorResident=json_decode($this->readShadowState('ResidentVisuState'),true);
        $residentVisu=JVResidentVisuState::evaluate(
            $wlan,$tileWork,$egi,$carinaGeo,
            $houseObservation,$p03Timeline['report'],
            is_array($priorResident)?$priorResident:[],time()
        );
        $residentNext=json_encode(
            $residentVisu['next'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
        );
        if(is_string($residentNext)
            &&$this->readShadowState('ResidentVisuState')!==$residentNext)
            $this->writeShadowState('ResidentVisuState',$residentNext);
        // Durable phone-contact history. A resumed WLAN confirmation is
        // not evidence of a human arriving, and silence is never departure.
        $contactPrior=json_decode(
            $this->readShadowState('ResidentPhoneContinuity'),true
        );
        $contactHistory=JVPhoneContactContinuity::evaluate(
            $tileWlan,$tileGeoJ,$tileGeoC,
            JVShadowStartupEpoch::fence('ResidentPhoneContinuity',
                is_array($contactPrior)?$contactPrior:[],$coldStart),time()
        );
        $contactNext=json_encode($contactHistory['next'],
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(is_string($contactNext)
            &&$this->readShadowState('ResidentPhoneContinuity')!==$contactNext)
            $this->writeShadowState('ResidentPhoneContinuity',$contactNext);
        // Durable, per-phone event sequence + anonymous P03 proof ledger.
        // Strictly local reads: no camera operation, alarms or person identity.
        $fusionPrior=json_decode(
            $this->readShadowState('ResidentEventFusion'),true
        );
        $residentFusion=JVResidentEventFusion::evaluate(
            $tileWlan,$tileGeoJ,$tileGeoC,$tileWork,
            $p03Timeline['report'],
            JVShadowStartupEpoch::fence('ResidentEventFusion',
                is_array($fusionPrior)?$fusionPrior:[],$coldStart),time()
        );
        $fusionNext=json_encode($residentFusion['next'],
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(is_string($fusionNext)
            &&$this->readShadowState('ResidentEventFusion')!==$fusionNext)
            $this->writeShadowState('ResidentEventFusion',$fusionNext);
        // Durable fused proof journal first; P03 raw cursor second.
        // On restart between those writes, source proofs replay safely.
        // A failed source read also persists the empty quarantine state.
        // Otherwise the next healthy poll could inherit the old cursor.
        $newState=json_encode($p03Timeline['next'],JSON_UNESCAPED_SLASHES);
        if(is_string($newState)
            &&$this->readShadowState('P03IncrementalTimeline')!==$newState)
            $this->writeShadowState('P03IncrementalTimeline',$newState);
        $p03ConnectionDiagnosis=$this->readLocalVariable(
            $this->ReadPropertyInteger('P03InstanceID'),
            self::P03_GUID,'P03ConnectionDiagnosis'
        );
        $temporalCrossCheck=JVTemporalPresenceCrossCheck::evaluate(
            $p03Raw??'',$p03ConnectionDiagnosis,
            $p03Timeline['report'],$wlan,$activity,$egi,$carinaGeo,
            $tileWork,
            ($p03['street']['directionMappingCertified']??false)===true,
            time()
        );
        // New anonymous two-mast OUT/IN corridor episodes, safely kept
        // separate from residents' own handset events and from car claims.
        $routePrior=json_decode(
            $this->readShadowState('RouteEpisodeLedger'),true
        );
        $routeEpisodes=JVRouteEpisodeLedger::evaluate(
            $residentFusion['report'],$boundary['report'],
            $roomPaths,$temporalCrossCheck,
            JVShadowStartupEpoch::fence('RouteEpisodeLedger',
                is_array($routePrior)?$routePrior:[],$coldStart),time()
        );
        $routeNext=json_encode($routeEpisodes['next'],
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $delivered=$residentFusion['report']['p03']['routeDeliveryProofs']??[];
        $hasDelivery=is_array($delivered)&&count($delivered)>0;
        if(is_string($routeNext)){
            if($hasDelivery){
                // Delivery acknowledgment requires a durable route checkpoint,
                // not just the runtime cache. Duplicated proofs are harmless.
                if($this->ReadAttributeString('PersistRouteEpisodeLedger')!==$routeNext)
                    $this->WriteAttributeString('PersistRouteEpisodeLedger',$routeNext);
                if($this->GetBuffer('RouteEpisodeLedger')!==$routeNext)
                    $this->SetBuffer('RouteEpisodeLedger',$routeNext);
                $acked=JVResidentEventFusion::acknowledgeRouteDelivery(
                    $residentFusion['next'],$delivered,time()
                );
                $ackedJSON=json_encode($acked,JSON_UNESCAPED_SLASHES|
                    JSON_UNESCAPED_UNICODE);
                if(is_string($ackedJSON)
                    &&$this->readShadowState('ResidentEventFusion')!==$ackedJSON)
                    $this->writeShadowState('ResidentEventFusion',$ackedJSON);
            }elseif($this->readShadowState('RouteEpisodeLedger')!==$routeNext){
                $this->writeShadowState('RouteEpisodeLedger',$routeNext);
            }
        }
        // Correlate individual phone sequences with the *anonymous*
        // route timeline. No camera identity, auto or occupancy certificate.
        $residentJourney=JVResidentJourneyEvidence::evaluate(
            $routeEpisodes['report'],$residentFusion['report'],
            $tileWork,$boundary['report'],time()
        );
        $evidenceReadiness=JVPresenceEvidenceReadiness::evaluate(
            $temporalCrossCheck,$mastHealth,$residentFusion['report'],
            $tileWork,$boundary['report'],$houseObservation,
            $residentJourney,time()
        );
        // Interpret the recurring question about two simultaneous residents
        // leaving together without attributing anonymous camera events to
        // handset owners. Diagnostic only, not a property-exit decision.
        $roomPaths['jointDepartureReadiness']=JVJointDepartureReadiness::evaluate(
            $roomPaths['routeAtlas'],$wlan,$temporalCrossCheck,
            $p03Timeline['report'],$evidenceReadiness,time()
        );
        $roomPaths['sharedDepartureTimeline']=JVSharedDepartureTimeline::evaluate(
            $residentFusion['report'],$p03Timeline['report'],
            $temporalCrossCheck,time()
        );
        // Cross-check only positive, anonymous GUS events against the
        // confirmed time intervals of two handset AREA-OUT cycles.
        // Neither a zero motion count nor a match certifies people or home.
        $areaCycles=JVPhoneAreaCycleMatcher::evaluate(
            $residentFusion['report'],time()
        );
        $roomPaths['phoneRoomOverlapAudit']=JVPhoneRoomOverlapAudit::evaluate(
            $areaCycles,$activity,$ledger['next'],time()
        );
        $roomPaths['crossSourceIntegrityGate']=
            JVShadowCrossSourceIntegrityGate::evaluate(
                $areaCycles,$roomPaths['sharedDepartureTimeline'],
                $p03Timeline['report'],$roomPaths['phoneRoomOverlapAudit'],
                time(),$activity,$ledger['next']
            );
        $integrityAudit=JVShadowIntegrityAudit::evaluate(
            $residentFusion['report'],$routeEpisodes['report'],
            $residentJourney,$evidenceReadiness,time(),
            $roomPaths['crossSourceIntegrityGate']
        );
        $commissioningGate=JVPresenceCommissioningGate::evaluate(
            $tileWlan,$residentFusion['report'],$tileWork,
            $evidenceReadiness,$boundary['report'],$extraIpcEvidence,
            $numberStat,$integrityAudit,time()
        );
        $presenceSafetyCase=JVPresenceSafetyCase::evaluate(
            $houseObservation,$tileWlan,$tileWork,$boundary['report'],
            $routeEpisodes['report'],$residentJourney,$commissioningGate,
            $integrityAudit,$upgradePreflight,time()
        );
        $tilePrior=json_decode($this->readShadowState('TileResidentState'),true);
        $tileResident=JVResidentVisuState::evaluate(
            $tileWlan,$tileWork,$tileGeoJ,$tileGeoC,
            $houseObservation,$p03Timeline['report'],
            is_array($tilePrior)?$tilePrior:[],time()
        );
        $tileNext=json_encode($tileResident['next'],
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(is_string($tileNext)
            &&$this->readShadowState('TileResidentState')!==$tileNext)
            $this->writeShadowState('TileResidentState',$tileNext);
        $departureHint=JVResidentDepartureInference::evaluate(
            $tileWlan,$tileWork,$tileGeoJ,$tileGeoC,
            $p03Timeline['report'],time(),$residentFusion['report']
        );
        $arrivalHint=JVResidentArrivalInference::evaluate(
            $tileWlan,$tileGeoJ,$tileGeoC,$p03Timeline['report'],time(),
            $residentFusion['report']
        );
        $tileData=JVResidentTileDashboard::evaluate(
            $tileResident['report'],$tileWlan,$tileWork,
            $p03Timeline['report'],$houseObservation,time(),$departureHint,$roomPaths,
            $residentFusion['report'],$routeEpisodes['report'],
            $residentJourney
        );
        // The existing ~HTMLBox variable now shows only the two compact
        // read-only resident rows; detailed evidence stays in TestReport.
        $compactResidentTile=JVResidentTileDashboard::compactState(
            $tileData,time()
        );
        $tileHTML=JVResidentTileDashboard::renderCompact($tileData,time());
        $presenceDetected=JVCombinedPresenceBoolean::evaluate(
            $combined,$houseObservation,$tileWlan,$tileGeoJ,$tileGeoC,
            $p03Timeline['report'],$gateDisplay,$indoorHints,time(),$tileWork
        );
        // Existing gate display is context only, NOT a verified exit route.
        $boundary['report']['existingGateDisplay']=$gateDisplay;
        $boundary['report']['parallelPersonPassage']=$parallelPassage['status'];
        $cameraCapability=JVCameraCapabilityPriority::evaluate(
            $p03Timeline['report'],$this->readP03LiveCounters(),
            $p03['street'],$numberStat,$qualifiedIpc
        );
        // A counting-capable IPC in active IVS mode has NO confirmed human
        // count; no camera model or counter snapshot changes this.
        $parallelPassage['individualPeopleCount']='UNKNOWN';
        $parallelPassage['cameraCountCapabilityPriority']='IPC_WIZMIND_FIRST_WHEN_PEOPLE_COUNTING_VERIFIED';
        $boundary['report']['individualsOrEntireGroupExitedCertified']=false;
        $operationalReadiness=JVPresenceOperationalReadiness::evaluate(
            $tileWork,$temporalCrossCheck,$mastHealth,time(),
            $p03ConnectionDiagnosis
        );
        // Hook mapping and phone timestamps ONLY. Never request an SSID
        // refresh from a smartphone, read tokens or modify the WebHook.
        $sourceCommissioning=JVResidentSourceCommissioning::evaluate(
            $tileWork,$operationalReadiness,$this->readPhoneBridgeHookMetadata()
        );
        $report=[
            'schema'=>1,'authority'=>'SHADOW_DIRECTION_PHONE_AND_LOCAL_HINTS',
            'snapshotAt'=>time(),
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'p03'=>$p03,
            'p03Live'=>$this->readP03LiveCounters(),
            'p03MastHealth'=>$mastHealth,
            'p03LiveTimeline'=>$p03Timeline['report'],
            'temporalPresenceCrossCheck'=>$temporalCrossCheck,
            'parallelPersonPassage'=>$parallelPassage,
            'cameraCapabilityPriority'=>$cameraCapability,
            'additionalIPCInventory'=>$otherCameraInventory,
            'qualifiedAdditionalIPCInventory'=>$qualifiedIpc,
            'supplementaryIpcEventEvidence'=>$extraIpcEvidence,
            'numberStatEvidence'=>$numberStat,
            'phone'=>$phones,
            'phoneLink'=>$phoneLink,
            'existingWlanSmartphones'=>$wlan,
            'johannesEgiGeoZone'=>$egi,
            'carinaGeofency'=>$carinaGeo,
            'residentDisplay'=>$residentDisplay,
            'residentVisuState'=>$tileResident['report'],
            'residentTileEvidence'=>$tileData,
            'compactResidentTile'=>$compactResidentTile,
            'residentDepartureInference'=>$departureHint,
            'residentArrivalInference'=>$arrivalHint,
            'phoneContactContinuity'=>$contactHistory['report'],
            'residentEventFusion'=>$residentFusion['report'],
            'anonymousRouteEpisodes'=>$routeEpisodes['report'],
            'residentJourneyCorrelation'=>$residentJourney,
            'presenceEvidenceReadiness'=>$evidenceReadiness,
            'operationalReadiness'=>$operationalReadiness,
            'sourceCommissioning'=>$sourceCommissioning,
            'shadowIntegrityAudit'=>$integrityAudit,
            'presenceCommissioningGate'=>$commissioningGate,
            'shadowUpgradePreflight'=>$upgradePreflight,
            'presenceSafetyCase'=>$presenceSafetyCase,
            'roomMovementPaths'=>$roomPaths,
            'workWiFi'=>$workWiFi,
            'effectiveWorkWiFi'=>$tileWork,
            'presenceDetected'=>$presenceDetected,
            'carinaGeofencyInventory'=>json_decode($this->readShadowState('CarinaGeofencyInventory'),true),
            'localSensors'=>$local,
            'localActivity'=>$activity,
            'houseObservation'=>$houseObservation,
            'combinedPresenceEvidence'=>$combined,
            'boundaryEvidence'=>$boundary['report'],
            'exitCandidateInventory'=>$exitInventory,
            'gateSignalAudit'=>$gateAudit,
            'gateDisplay'=>$gateDisplay,
            'indoorDeviceHints'=>$indoorHints,
            'indoorAutoRead'=>$autoIndoorSummary,
            'indoorHintInventory'=>$indoorInventory,
            'recentCombinedEvidence'=>$this->combinedShadowEvidence(
                $p03Timeline['report'],$activity,$boundary['report']
            ),
            // No baseline and no group count -> NO person occupancy guesses.
            // Distinguish missing MAC evidence from absent humans: even
            // when one handset goes offline, both known residents remain
            // explicitly UNKNOWN (never silently removed from the map).
            'people'=>array_fill_keys(
                $existingWlanEnabled
                    ?array_values(array_unique(array_merge(
                        array_keys($phones),['johannes','carina']
                    )))
                    :array_keys($phones),
                'UNKNOWN'
            ),
            'warnings'=>array_values(array_unique($warnings))
        ];
        $out=$p03['p03']['homeToWork'];
        $in=$p03['p03']['workToHome'];
        $street=$p03['street']['directionMappingCertified']?'Straße gelernt':'Straße ungeklärt';
        $homeHint=$local['zones']['HOME']['activity']??'UNKNOWN';
        $phoneDescription=$phoneLink['state']==='DISABLED_SEPARATE_PROJECT'
            ?'Smartphone getrennt'
            :($phoneLink['state']==='AUTO_LINKED'
                ?'Telefon-Observer AUTO #'.$phoneInst
                :($phoneLink['state']==='MANUAL_LINK'
                    ?'Telefon-Observer #'.$phoneInst
                    :'Telefon-Observer '.$phoneLink['state']));
        $houseLabel=[
            'HOUSE_MOVEMENT_CORROBORATED'=>'Bewegung bestaetigt',
            'HOUSE_MOVEMENT_HINT'=>'Bewegungshinweis',
            'HOME_ENTRY_DIRECTION_HINT'=>'Zugang aus Lager erkannt',
            'HOME_EXIT_DIRECTION_HINT'=>'Weg ins Lager erkannt',
            'HOME_DEVICE_ON_HINT'=>'Innenlicht/TV Hinweis',
            'HOUSE_QUIET_NO_ABSENCE_PROOF'=>'Ruhig (nicht leer bewiesen)',
            'UNKNOWN'=>'Ungeklaert'
        ][$houseObservation['houseStatus']]??'Ungeklaert';
        $state='P03-Archiv: OUT '.$out.' / IN '.$in.' (Richtungsbeweise); '
            .$street.' | LCN Haus: '.$homeHint
            .' | GUS-Impulse/24h: '.$activity['totalMotionEdges24h']
            .' | P03-neue Beweise/24h: '
            .array_sum($p03Timeline['report']['directionProofs24h'])
            .' | P03-Betrieb: '.($temporalCrossCheck['p03OperatingMode']==='TEST_AND_PRODUCTION_OFF'
                ?'TEST/PRODUKTION AUS – IVS nur roh'
                :($temporalCrossCheck['p03OperatingMode']==='PRODUCTION_ACTIVE'
                    ?'PRODUKTION AKTIV'
                    :($temporalCrossCheck['p03OperatingMode']==='TEST_ACTIVE'
                        ?'TEST AKTIV':'NICHT VERIFIZIERT')))
            .' | P03-Roh-STRASSE/24h: '
            .array_sum($temporalCrossCheck['rawStreetIVS24h'])
            .' | Haus: '.$houseLabel
            .' | Grundstuecksausgaenge: '.(
                $boundary['report']['configuredBoundarySignals']===0
                ?'noch keine Signale zugeordnet':'Signale beobachtet, Richtung ungeprueft'
            )
            .($exitInventory!==null
                ?' | Ausgangssuche: '.$exitInventory['candidateCount']
                    .' Statuskandidaten aus '
                    .($exitInventory['legacyBroadMatchCount']??'?')
                    .' Namens-Treffern (NICHT zugeordnet)'
                    .(!empty($exitInventory['symconScanLimited'])?' – Teilsuche':'')
                :'')
            .($gateAudit!==null
                ?' | Torwerte: '.$gateAudit['readableSignals'].'/'
                    .$gateAudit['signalCount'].' nur lesen':'')
            .' | Gruppenpassage: PERSONENZAHL UNBEKANNT'
            .' | Zaehlkameraprioritaet: IPC WizMind (kein Live-Count bestaetigt)'
            .' | Schiebetor: '.$gateDisplay['positionHint']
            .' | Innenlicht/TV AN: '.$indoorHints['onCount']
            .' | Auto-Innenlicht: '.($autoIndoorEnabled
                ?$autoIndoor['readableCount'].'/'.$autoIndoor['candidateCount'].' nur lesen'
                :'AUS')
            .' | EgiGeoZone Johannes: '.($egiEnabled
                ?$egi['state'].' (App '.$egi['radiusMetersConfiguredInPhoneApp'].'m)'
                :'AUS')
            .' | Carina Geofency: '.($carinaGeoEnabled
                ?$carinaGeo['state'].' ('.$carinaGeo['selectionStatus'].')'
                :'AUS')
            .' | Bewohner-Kacheln: Johannes='
            .$tileResident['report']['people']['johannes']['decision']
            .', Carina='.$tileResident['report']['people']['carina']['decision']
            .' | Firmen-WLAN Handys: '.$tileWork['workPhoneCount']
            .' | Anwesenheit erkannt: '.($presenceDetected['detected']?'JA':'NEIN')
            .' (NEIN = kein aktueller positiver Nachweis, NICHT leer)'
            .' | Abwesenheit: NICHT BESTAETIGT'
            .' | '.$phoneDescription
            .' | Kombiniert: '.$combined['status']
            .' | WLAN-Handys: '.(
                !$existingWlanEnabled?'nicht verbunden'
                :$wlan['freshMacConfirmedDeviceCount'].'/2 MAC-frisch (nur Geraetehinweis)'
            )
            .' | Identitaet UNBEKANNT';
        if ($warnings) $state.=' | QUELLHINWEIS '.implode(',',array_slice($warnings,0,2));
        // Use ONE verified tile-source decision across the HTML tile,
        // the two existing named status variables and the JSON report.
        // Explicit-only legacy diagnostics must not disagree visually with
        // the final auto+manual qualified resident phone source.
        $this->setResidentDisplayValues(
            $tileResident['report']['people']['johannes']['display'],
            $tileResident['report']['people']['carina']['display']
        );
        $this->setPresenceDetected($presenceDetected['detected']);
        $this->setResidentTile($tileHTML);
        $this->setTestReport(JVResidentEveningHandoff::build(
            $tileData,$tileResident['report'],$tileWlan,$tileWork,
            $tileGeoJ,$tileGeoC,$p03Timeline['report'],
            $temporalCrossCheck,$mastHealth,$houseObservation,
            $activity,$local,$boundary['report'],$gateDisplay,
            $warnings,time(),true,$departureHint,$roomPaths,$arrivalHint,
            $contactHistory['report'],$residentFusion['report'],
            $routeEpisodes['report'],$residentJourney,
            $evidenceReadiness,$integrityAudit,$commissioningGate,
            $upgradePreflight,$presenceSafetyCase,$operationalReadiness,
            $sourceCommissioning
        ));
        $this->publish($report,$state);
        // A prior unexpected runtime error must not leave the native
        // instance RED once a complete fresh read has succeeded again.
        if($this->GetBuffer('RefreshFailed')==='1'){
            $this->SetStatus(102);
            $this->SetBuffer('RefreshFailed','0');
        }
        // Mark continuity ONLY after the entire refresh and publication
        // succeed. A thrown exception must cause the next poll to baseline
        // again, never to assume the failed sample was continuously read.
        if($coldStart)$this->SetBuffer('ShadowObservationStarted','1');
        } catch(Throwable $e) {
            // Even a ONE-POLL runtime exception interrupts observation;
            // it cannot bridge an otherwise fresh old OUT/IN/P03 chain.
            try {$this->SetBuffer('ShadowObservationStarted','0');}
            catch(Throwable $ignored){}
            // An uncaught failure during a timer/VM_UPDATE refresh would
            // otherwise leave the PREVIOUS "ANWESEND" display and
            // PresenceDetected=true visible indefinitely. Fail CLOSED,
            // preserve durable ledgers, redact exception messages and
            // never execute a foreign module or physical action.
            try {$this->SetBuffer('RefreshFailed','1');}
            catch(Throwable $ignored){}
            try {$this->SetStatus(201);}
            catch(Throwable $ignored){}
            try {
                $this->setResidentDisplayValues('NICHT BESTÄTIGT','NICHT BESTÄTIGT');
                $this->setPresenceDetected(false);
                $this->setResidentTile('<section>SHADOW Auswertungsfehler – '
                    .'Anwesenheit derzeit nicht bestaetigt.</section>');
                $this->setTestReport('SHADOW LAUFZEITFEHLER: '
                    .'Auswertung gestoppt. Zuvor sichtbare Anwesenheits-'
                    .'hinweise ungueltig. Persistente SHADOW-Zustaende '
                    .'bleiben unveraendert; keine Abwesenheit, keine '
                    .'Alarmfreigabe, keine Fremdsteuerung. '
                    .'Modul-Fehlerstatus und Debug pruefen.');
                $this->publish([
                    'schema'=>1,'authority'=>'SHADOW_RUNTIME_READ_ONLY_BLOCK',
                    'personPresenceMayBeChanged'=>false,
                    'propertyEmptyMayBeCertified'=>false,
                    'mayArmAlarm'=>false,'maySwitchDevices'=>false,
                    'warnings'=>['SHADOW_UNEXPECTED_REFRESH_FAILURE']
                ],'SHADOW FEHLER – keine Anwesenheit bestaetigt');
            }catch(Throwable $ignored){}
            try {
                $this->SendDebug('ShadowRefresh',
                    'UNEXPECTED_EXCEPTION_CLASS='.get_class($e),0);
            }catch(Throwable $ignored){}
            throw $e;
        } finally {
            IPS_SemaphoreLeave($semaphore);
            // Coalesce multiple sensor notifications into at most one
            // immediate follow-up per owner pass. Never recurse indefinitely
            // when event activity stays high; the timer is fallback.
            if($this->GetBuffer('RefreshPending')==='1'
                &&$this->GetBuffer('RefreshDraining')!=='1'){
                $this->SetBuffer('RefreshPending','0');
                $this->SetBuffer('RefreshDraining','1');
                try {
                    $this->Refresh();
                } finally {
                    $this->SetBuffer('RefreshDraining','0');
                }
            }
        }
    }

    /**
     * Existing phone observer can be reused directly. No new phone observer
     * instance is created, no Wi-Fi network requests, and no property changes.
     * Automatic selection is allowed ONLY for exactly one matching module.
     */
    private function resolvePhoneObserver(): array
    {
        $manual=$this->ReadPropertyInteger('PhoneObserverInstanceID');
        if ($manual>0) {
            return ['state'=>'MANUAL_LINK','instanceId'=>$manual];
        }
        $candidates=[];
        try {
            $ids=IPS_GetInstanceListByModuleID(self::PHONE_GUID);
            if (!is_array($ids)) return [
                'state'=>'LOOKUP_FAILED','instanceId'=>0
            ];
            foreach($ids as $id) {
                if (!is_int($id) && !is_numeric($id)) continue;
                $id=(int)$id;
                if ($id<=0) continue;
                $instance=IPS_GetInstance($id);
                if(strcasecmp(
                    (string)($instance['ModuleInfo']['ModuleID']??''),
                    self::PHONE_GUID
                )===0) $candidates[$id]=true;
            }
        } catch(Throwable $e) {
            return ['state'=>'LOOKUP_FAILED','instanceId'=>0];
        }
        if(count($candidates)===1) return [
            'state'=>'AUTO_LINKED','instanceId'=>(int)array_key_first($candidates)
        ];
        return [
            'state'=>count($candidates)>1?'AMBIGUOUS_MULTIPLE':'NO_OBSERVER_FOUND',
            'instanceId'=>0
        ];
    }

    /**
     * Live diagnostics of EXISTING P03 variables. Do not increment any
     * person counter: the verified counter measures proof events, not humans.
     */
    private function readP03LiveCounters(): array
    {
        $inst=$this->ReadPropertyInteger('P03InstanceID');
        $count=null;
        $state=$this->readLocalVariable($inst,self::P03_GUID,'P03ProofState');
        try {
            if ($inst<=0) throw new RuntimeException('invalid instance');
            $i=IPS_GetInstance($inst);
            if(strcasecmp(
                (string)($i['ModuleInfo']['ModuleID']??''),
                self::P03_GUID
            )!==0) throw new RuntimeException('not P03');
            $id=@IPS_GetObjectIDByIdent('P03VerifiedTransfers',$inst);
            if(!is_int($id)||$id<=0||!IPS_VariableExists($id))
                throw new RuntimeException('missing counter');
            $meta=IPS_GetVariable($id);
            if(($meta['VariableType']??-1)!==1)
                throw new RuntimeException('wrong counter type');
            $value=GetValue($id);
            if(!is_int($value)||$value<0)
                throw new RuntimeException('invalid counter value');
            $count=$value;
        } catch(Throwable $e) {}
        return [
            'authority'=>'P03_COUNTER_DIAGNOSTIC_ONLY',
            'verifiedProofCounter'=>$count,
            'proofState'=>$state,
            'countIsNumberOfPeople'=>false,
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false
        ];
    }

    /**
     * Chronological context without person identity. A GUS edge near a
     * P03 proof may be an unrelated visitor, never an owner identification.
     * Keep the representation bounded inside the existing JSON variable.
     */
    private function combinedShadowEvidence(array $p03,array $local,array $boundary=[]):array
    {
        $out=[];
        foreach(($p03['recentDirectionProofs']??[]) as $row) {
            if(!is_array($row)||!is_int($row['at']??null))continue;
            $out[]=[
                'at'=>$row['at'],'kind'=>'P03_ZONE_DIRECTION',
                'direction'=>(string)($row['direction']??'UNKNOWN'),
                'personCount'=>'UNKNOWN','identity'=>'UNKNOWN'
            ];
        }
        foreach(($local['recentMotionEdges']??[]) as $row) {
            if(!is_array($row)||!is_int($row['at']??null))continue;
            $out[]=[
                'at'=>$row['at'],'kind'=>'LCN_GUS_ACTIVITY',
                'sourceID'=>(string)($row['sourceID']??''),
                'zone'=>(string)($row['zone']??''),
                'confidence'=>(string)($row['confidence']??''),
                'personCount'=>'UNKNOWN','identity'=>'UNKNOWN'
            ];
        }
        foreach(($boundary['recentChanges']??[]) as $row) {
            if(!is_array($row)||!is_int($row['at']??null))continue;
            $out[]=[
                'at'=>$row['at'],'kind'=>'BOUNDARY_STATUS_CHANGE',
                'sourceID'=>(string)($row['sourceID']??''),
                'sourceKind'=>(string)($row['kind']??''),
                'state'=>(string)($row['state']??'UNKNOWN'),
                'direction'=>'UNKNOWN',
                'personCount'=>'UNKNOWN','identity'=>'UNKNOWN'
            ];
        }
        usort($out,static fn($a,$b)=>$b['at']<=>$a['at']);
        return array_slice($out,0,20);
    }

    /**
     * Read-only optional STRING variable chosen by the user, containing an
     * already delivered Dahua NumberStat event. No camera connection or
     * password, no event object, no Subscribe/SmartPlan mutation.
     */
    private function readExistingCountTelemetry(int $id):array
    {
        $none=['raw'=>null,'age'=>-1];
        if($id<=0)return $none;
        try {
            if(!IPS_VariableExists($id))return $none;
            $meta=IPS_GetVariable($id);
            if(($meta['VariableType']??null)!==3)return $none;
            $updated=$meta['VariableUpdated']??null;
            if(!is_int($updated)||$updated<=0||$updated>time()+5)
                return $none;
            $val=GetValue($id);
            if(!is_string($val)||strlen($val)>8192)return $none;
            return ['raw'=>$val,'age'=>max(0,time()-$updated)];
        }catch(Throwable $e){return $none;}
    }

    private function readLocalVariable(int $instanceId,string $guid,string $ident): ?string
    {
        if ($instanceId<=0) return null;
        try {
            $i=IPS_GetInstance($instanceId);
            if (strcasecmp((string)($i['ModuleInfo']['ModuleID']??''),$guid)!==0) return null;
            $id=@IPS_GetObjectIDByIdent($ident,$instanceId);
            if (!is_int($id) || $id<=0 || !IPS_VariableExists($id)) return null;
            $meta=IPS_GetVariable($id);
            if (($meta['VariableType']??-1)!==3) return null;
            $value=GetValue($id);
            return is_string($value)?$value:null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Strictly read a local, already existing Boolean/Integer variable. Any
     * missing, wrong-type or future-dated metadata stays UNKNOWN. NEVER
     * trigger a module action or interpret integer counter deltas as humans.
     */
    /**
     * Only read already discovered Boolean Schiebetor status candidates.
     * Confirm the Symcon object tree still matches the inventory snapshot
     * so recycled/reparented IDs are never read as a trusted gate state.
     */
    private function readGateCandidateSnapshots(array $candidates): array
    {
        $snapshots=[];$used=[];
        foreach($candidates as $row) {
            if(count($used)>=16)break;
            if(!is_array($row)
                || ($row['grandparent']??null)!=='Schiebetor_Logik'
                || ($row['type']??null)!=='Boolean'
                || !is_int($row['variableId']??null)
                || $row['variableId']<=0)continue;
            $id=$row['variableId'];
            if(isset($used[$id]))continue;
            $used[$id]=true;

            // Always return a coded outcome for every candidate. Do not
            // expose PHP exception messages or perform any device action.
            $failure=static fn(string $code):array=>['errorCode'=>$code];
            try {
                if(!IPS_VariableExists($id)) {
                    $snapshots[$id]=$failure('VARIABLE_NOT_FOUND');continue;
                }
                $object=IPS_GetObject($id);
                $parentId=(int)($object['ParentID']??0);
                if(IPS_GetName($id)!==($row['name']??null)) {
                    $snapshots[$id]=$failure('VARIABLE_NAME_CHANGED');continue;
                }
                if($parentId<=0
                    || IPS_GetName($parentId)!==($row['parent']??null)) {
                    $snapshots[$id]=$failure('PARENT_PATH_CHANGED');continue;
                }
                $parent=IPS_GetObject($parentId);
                $grandId=(int)($parent['ParentID']??0);
                if($grandId<=0
                    || IPS_GetName($grandId)!=='Schiebetor_Logik') {
                    $snapshots[$id]=$failure('GRANDPARENT_PATH_CHANGED');continue;
                }
                $meta=IPS_GetVariable($id);
                if(($meta['VariableType']??null)!==0) {
                    $snapshots[$id]=$failure('NOT_BOOLEAN');continue;
                }
                $changed=$meta['VariableChanged']??null;
                if($changed!==null && !is_int($changed)) {
                    $snapshots[$id]=$failure('INVALID_CHANGED_TIMESTAMP');continue;
                }
                if(is_int($changed)
                    && ($changed<0 || $changed>time()+5)) {
                    $snapshots[$id]=$failure('INVALID_CHANGED_TIMESTAMP');continue;
                }
                $updated=$meta['VariableUpdated']??null;
                if($updated!==null && (!is_int($updated)
                    || $updated<0 || $updated>time()+5)) {
                    $snapshots[$id]=$failure('INVALID_UPDATED_TIMESTAMP');continue;
                }
                $value=GetValue($id);
                if(!is_bool($value)) {
                    $snapshots[$id]=$failure('NOT_BOOLEAN_VALUE');continue;
                }
                $snapshots[$id]=[
                    'type'=>0,
                    'value'=>$value,
                    'changedAt'=>$changed,
                    'updatedAt'=>$updated,
                    'errorCode'=>($changed===null||$changed===0)
                        ?'NO_CHANGE_TIMESTAMP':'NONE'
                ];
            } catch(Throwable $e) {
                $snapshots[$id]=$failure('METADATA_READ_EXCEPTION');
            }
        }
        return $snapshots;
    }


    /**
     * Optional low-cost 60s passive sampling of existing light status
     * variables, derived ONLY from button 5's persisted metadata inventory.
     * Fail closed when a variable ID is recycled, moved or type changed.
     * No action execution, no Symcon object creation, no device writes.
     */
    private function readAutoIndoorHintSnapshots(array $configuredSources):array
    {
        $configuredIds=[];
        foreach($configuredSources as $source){
            if(is_int($source['variableID']??null))
                $configuredIds[]=$source['variableID'];
        }
        $inventory=json_decode($this->readShadowState('IndoorHintInventory'),true);
        $selected=JVIndoorAutoReadHints::select(
            is_array($inventory)?$inventory:null,$configuredIds
        );
        $snapshots=[];
        foreach($selected as $row){
            $id=$row['variableId'];
            $snapshots[$id]=['valid'=>false];
            try{
                if(!IPS_VariableExists($id))continue;
                if(IPS_GetName($id)!==$row['name'])continue;
                $obj=IPS_GetObject($id);
                $parentId=(int)($obj['ParentID']??0);
                if($parentId<=0||IPS_GetName($parentId)!==$row['parent'])continue;
                $parentObj=IPS_GetObject($parentId);
                $grandId=(int)($parentObj['ParentID']??0);
                if($grandId<=0||IPS_GetName($grandId)!==$row['grandparent'])continue;
                $meta=IPS_GetVariable($id);
                $changed=$meta['VariableChanged']??null;
                if(($meta['VariableType']??null)!==0
                    ||!is_int($changed)||$changed<0||$changed>time()+5)continue;
                $value=GetValue($id);
                if(!is_bool($value))continue;
                $snapshots[$id]=[
                    'valid'=>true,'value'=>$value,'changedAt'=>$changed
                ];
            }catch(Throwable $ignored){}
        }
        return JVIndoorAutoReadHints::evaluate($selected,$snapshots,time());
    }

    /** Read an EXISTING numeric W source only after explicit manual opt-in.
     * An absent watt profile is a source defect, never a reason to guess units.
     */
    private function readExistingWattPower():array
    {
        $id=$this->ReadPropertyInteger('HousePowerVariableID');
        if($id<=0)return ['validatedWattMetadata'=>false];
        try {
            if(!IPS_VariableExists($id))return ['validatedWattMetadata'=>false];
            $meta=IPS_GetVariable($id);
            if(!in_array($meta['VariableType']??null,[1,2],true))
                return ['validatedWattMetadata'=>false];
            $profile=(string)($meta['VariableCustomProfile']??'');
            if($profile==='')$profile=(string)($meta['VariableProfile']??'');
            if($profile===''||!function_exists('IPS_GetVariableProfile'))
                return ['validatedWattMetadata'=>false];
            $units=IPS_GetVariableProfile($profile);
            if(!is_array($units)||trim((string)($units['Suffix']??''))!=='W')
                return ['validatedWattMetadata'=>false];
            $updated=$meta['VariableUpdated']??null;
            if(!is_int($updated))return ['validatedWattMetadata'=>false];
            $val=GetValue($id);
            if(!(is_int($val)||is_float($val)))
                return ['validatedWattMetadata'=>false];
            return ['validatedWattMetadata'=>true,'value'=>$val,
                'updatedAt'=>$updated];
        }catch(Throwable $ignored){
            return ['validatedWattMetadata'=>false];
        }
    }

    private function readSensorSnapshot(int $id,bool $indoorHint=false): ?array
    {
        if($id<=0) return null;
        try {
            if(!IPS_VariableExists($id)) return null;
            if($indoorHint){
                // Check original Symcon object tree, not just the editable
                // source display name. Prevent accidently mapping terrace,
                // driveway, garage or workshop lighting as HOME evidence.
                $walk=$id;$path='';
                for($depth=0;$depth<7&&$walk>0;$depth++){
                    $path.=' '.(string)IPS_GetName($walk);
                    $obj=IPS_GetObject($walk);
                    $walk=(int)($obj['ParentID']??0);
                }
                if(JVIndoorPresenceHints::isExterior($path))return null;
            }
            $meta=IPS_GetVariable($id);
            $type=$meta['VariableType']??null;
            $changed=$meta['VariableChanged']??null;
            if(!is_int($type) || !in_array($type,[0,1],true)
                || !is_int($changed)
                || ($indoorHint?$changed<0:$changed<=0)
                || $changed>time()+5)return null;
            $value=GetValue($id);
            if(($type===0 && !is_bool($value))
                || ($type===1 && (!is_int($value)||$value<0))) return null;
            return ['type'=>$type,'value'=>$value,'changedAt'=>$changed];
        } catch(Throwable $e) {
            return null;
        }
    }

    /**
     * Native IP-Symcon HTML-SDK instance tile. The module's usual expanded
     * list is still available in Symcon 9.0. Only our existing report is
     * read; no third-party object is accessed during tile rendering.
     */
    public function GetVisualizationTile():string
    {
        $file=__DIR__.'/module.html';
        $html=@file_get_contents($file);
        if(!is_string($html)||$html==='')return
            '<div>Bewohnerstatus derzeit nicht verfuegbar</div>';
        $message=$this->nativeResidentTileMessage();
        return $html.'<script>handleMessage('.json_encode($message,
            JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).');</script>';
    }

    /** Bounded enum-only payload. Stale or corrupt evidence is UNKNOWN. */
    private function nativeResidentTileMessage():string
    {
        $data=json_decode((string)$this->GetValue('EvidenceJSON'),true);
        $now=time();
        $fresh=is_array($data)
            &&($data['authority']??null)==='SHADOW_DIRECTION_PHONE_AND_LOCAL_HINTS'
            &&is_int($data['snapshotAt']??null)
            &&$data['snapshotAt']<=$now+5
            &&$data['snapshotAt']>=$now-120
            &&($data['compactResidentTile']['authority']??null)
                ==='COMPACT_PHONE_TILE_READ_ONLY';
        $allowed=['Zuhause'=>'home','In Firma'=>'work',
            'Abwesend'=>'away','Unklar'=>'unknown'];
        $out=['authority'=>'COMPACT_PHONE_TILE_READ_ONLY','people'=>[]];
        foreach(['johannes','carina'] as $person) {
            $row=$fresh&&is_array(
                $data['compactResidentTile']['people'][$person]??null)
                ?$data['compactResidentTile']['people'][$person]:[];
            $status=$row['status']??null;
            if(!is_string($status)||!isset($allowed[$status]))
                $status='Unklar';
            // Never restore a prior positive from an expired/faulty epoch.
            $reason=$status==='Unklar'
                ?'Kein aktueller eindeutiger Nachweis'
                :($status==='Zuhause'?'Heim-WLAN · Handy bestätigt'
                    :($status==='In Firma'?'Firmen-WLAN · Handy bestätigt'
                        :'Vermutet · Handy-Abreisehinweis'));
            $out['people'][$person]=['status'=>$status,'reason'=>$reason];
        }
        return json_encode($out,JSON_UNESCAPED_UNICODE|
            JSON_UNESCAPED_SLASHES)?:'{}';
    }

    /**
     * The native tile only needs updates when an existing SHADOW report
     * changes. Volatile updates do not allocate objects or flash attributes.
     * HTML-SDK failures never stop the normal independent SHADOW observer.
     */
    private function pushNativeResidentTile():void
    {
        if(!method_exists($this,'UpdateVisualizationValue'))return;
        try{
            $next=$this->nativeResidentTileMessage();
            if($this->GetBuffer('NativeResidentTileLast')===$next)return;
            $this->UpdateVisualizationValue($next);
            $this->SetBuffer('NativeResidentTileLast',$next);
        }catch(Throwable $ignored){}
    }

    /** One auto-refreshed copy/paste-only report, never raw secrets or GPS. */
    private function setTestReport(string $text):void
    {
        if($this->GetValue('TestReport')!==$text)
            $this->SetValue('TestReport',$text);
    }

    /** Update only the one new read-only HTMLBox display output on change. */
    private function setResidentTile(string $html):void
    {
        if($this->GetValue('ResidentTile')!==$html)
            $this->SetValue('ResidentTile',$html);
    }

    /** Write only our TWO requested SHADOW display variables. */
    private function setResidentDisplayValues(string $johannes,string $carina):void
    {
        if($this->GetValue('JohannesPresence')!==$johannes)
            $this->SetValue('JohannesPresence',$johannes);
        if($this->GetValue('CarinaPresence')!==$carina)
            $this->SetValue('CarinaPresence',$carina);
    }

    /** Write only our own Boolean, never HOME/AWAY/alarm of other modules. */
    private function setPresenceDetected(bool $detected):void
    {
        if($this->GetValue('PresenceDetected')!==$detected)
            $this->SetValue('PresenceDetected',$detected);
    }

    private function publish(array $report,string $status): void
    {
        $json=json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $newJson=is_string($json)?$json:'{}';
        if ($this->GetValue('EvidenceJSON')!==$newJson)
            $this->SetValue('EvidenceJSON',$newJson);
        $this->pushNativeResidentTile();
        if ($this->GetValue('Status')!==$status)
            $this->SetValue('Status',$status);
    }
}
