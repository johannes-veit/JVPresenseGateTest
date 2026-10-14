<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVP03ProofLogReplay.php';
require_once dirname(__DIR__).'/libs/JVLocalSensorEvidence.php';
require_once dirname(__DIR__).'/libs/JVLCNGUSInventory.php';
require_once dirname(__DIR__).'/libs/JVLocalActivityLedger.php';
require_once dirname(__DIR__).'/libs/JVP03IncrementalTimeline.php';
require_once dirname(__DIR__).'/libs/JVHomeOccupancyEvidence.php';
require_once dirname(__DIR__).'/libs/JVBoundaryEvidence.php';
require_once dirname(__DIR__).'/libs/JVExitCandidateInventory.php';
require_once dirname(__DIR__).'/libs/JVGateSignalAudit.php';

/**
 * Read-only SHADOW observer. Consumes only already-existing local Symcon
 * variables and never controls P03, P05, LCN, cameras, alarms or PresenceCore.
 * Neither a person's whereabouts nor an occupancy certificate is published.
 */
class JVPresenceIdentityShadow extends IPSModule
{
    private const P03_GUID='{0A7A91CE-3BC1-4B1E-9C11-7B7E973F5C03}';
    private const PHONE_GUID='{B5BF0266-A4D1-40A0-AAC8-97E9C24C4173}';
    private const VM_UPDATE=10603; // Symcon documented variable-update message
    private const STORED_STATE_NAMES=[
        'LocalSensorState',
        'LocalActivityLedger',
        'P03IncrementalTimeline',
        'BoundaryLedger',
        'ExitCandidateInventory',
        'GateSignalLedger'
    ];

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Enabled',false);
        // Known owner installation ID; verify GUID before EVERY read.
        $this->RegisterPropertyInteger('P03InstanceID',53879);
        // WLAN/Smartphone is developed in a separate project. NEVER read it
        // until the user explicitly authorizes a future opt-in.
        $this->RegisterPropertyBoolean('EnablePhoneIntegration',false);
        $this->RegisterPropertyInteger('PhoneObserverInstanceID',0);
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
        // Attributes persist across Symcon / module restarts; buffers DO NOT.
        // Never add hidden Status variables for storage.
        foreach(self::STORED_STATE_NAMES as $name)
            $this->RegisterAttributeString('Persist'.$name,'{}');
        $this->RegisterVariableString('Status','Identität – SHADOW Status','',0);
        $this->RegisterVariableString('EvidenceJSON','Identität – Nachweise (JSON)','',1);
        $this->RegisterTimer('ReadLocal',0,'JVIDSH_Refresh($_IPS["TARGET"]);');
        // Re-running Create after a module update must NOT erase a running
        // 24h timeline or a manually approved passive gate inventory.
        // Only initialize buffers that have never been populated.
        foreach(self::STORED_STATE_NAMES as $bufferName) {
            if($this->GetBuffer($bufferName)==='')
                $this->SetBuffer($bufferName,'{}');
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // One-time migration of any legacy in-memory state still present
        // during a hot update. If already lost, do not invent historical data.
        $this->migrateLegacyShadowBuffers();
        $this->SetTimerInterval('ReadLocal',$this->ReadPropertyBoolean('Enabled')?60000:0);
        $this->syncSensorSubscriptions();
        $this->Refresh();
    }

    /**
     * Persistent attributes are canonical. Never rely on GetBuffer surviving
     * module updates or a restart. A pre-v0.8.29 buffer is migrated only when
     * the persistent attribute has not yet been populated.
     */
    private function readShadowState(string $name): string
    {
        $stored=$this->ReadAttributeString('Persist'.$name);
        if($stored!=='' && $stored!=='{}')return $stored;
        $legacy=$this->GetBuffer($name);
        if($legacy!=='' && $legacy!=='{}')return $legacy;
        return $stored!==''?$stored:'{}';
    }

    private function writeShadowState(string $name,string $value): void
    {
        if($value==='')return;
        if($this->ReadAttributeString('Persist'.$name)!==$value)
            $this->WriteAttributeString('Persist'.$name,$value);
        // Buffer is only a compatibility cache, NEVER source of persistence.
        if($this->GetBuffer($name)!==$value)
            $this->SetBuffer($name,$value);
    }

    private function migrateLegacyShadowBuffers(): void
    {
        foreach(self::STORED_STATE_NAMES as $name) {
            $persistent=$this->ReadAttributeString('Persist'.$name);
            if($persistent!==''&&$persistent!=='{}')continue;
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
        $this->WriteAttributeString('SensorSubscriptions',
            json_encode(array_values(array_unique($registered)))?:'[]');
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if(!$this->ReadPropertyBoolean('Enabled')
            || (int)$Message!==self::VM_UPDATE) return;
        $registered=json_decode($this->ReadAttributeString('SensorSubscriptions'),true);
        if(!is_array($registered)
            || !in_array((int)$SenderID,$registered,true)) return;
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
        $this->Refresh();
    }

    public function Refresh(): void
    {
        if (!$this->ReadPropertyBoolean('Enabled')) {
            $this->publish([
                'schema'=>1,'authority'=>'SHADOW_DISABLED',
                'personPresenceMayBeChanged'=>false,
                'propertyEmptyMayBeCertified'=>false,
                'p03'=>null,'phone'=>null,'warnings'=>[]
            ],'SHADOW AUS – alle anderen Module unverändert');
            return;
        }
        $warnings=[];
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
            $p03Raw??'',is_array($p03Previous)?$p03Previous:[],time()
        );
        if($p03Raw===null) {
            // Invalid source is NEVER interpreted as an empty reset log.
            $p03Timeline['report']['warnings'][]='P03_SOURCE_NOT_READABLE';
            $p03Timeline['next']=$p03Previous??[];
        } else {
            $newState=json_encode($p03Timeline['next'],
                JSON_UNESCAPED_SLASHES);
            if(is_string($newState)
                && $this->readShadowState('P03IncrementalTimeline')!==$newState) {
                $this->writeShadowState('P03IncrementalTimeline',$newState);
            }
        }
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
        // Existing LCN, light, gate and vehicle Symcon variables are read
        // locally and ONLY as contextual hints. Never publish HOME/AWAY.
        $sourceRows=json_decode($this->ReadPropertyString('SensorSources'),true);
        if (!is_array($sourceRows)) $sourceRows=[['INVALID'=>'CONFIG_JSON']];
        $snapshots=[];
        $config=JVLocalSensorEvidence::validate($sourceRows);
        if ($config['errors']===[]) {
            foreach($config['sources'] as $source) {
                $varId=$source['variableID'];
                $snapshots[$varId]=$this->readSensorSnapshot($varId);
            }
        }
        $state=json_decode($this->readShadowState('LocalSensorState'),true);
        $state=is_array($state)?$state:[];
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
            is_array($ledgerState)?$ledgerState:[],time());
        $ledgerNext=json_encode($ledger['next'],JSON_UNESCAPED_SLASHES);
        if(is_string($ledgerNext)
            && $this->readShadowState('LocalActivityLedger')!==$ledgerNext) {
            $this->writeShadowState('LocalActivityLedger',$ledgerNext);
        }
        $activity=$ledger['report'];
        if($activity['warnings']) {
            $warnings=array_merge($warnings,$activity['warnings']);
        }
        $houseObservation=JVHomeOccupancyEvidence::evaluate(
            $local,$activity,$p03Timeline['report'],time()
        );
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
            $gateNext=json_encode($gateResult['next'],JSON_UNESCAPED_SLASHES);
            if(is_string($gateNext)
                && $this->readShadowState('GateSignalLedger')!==$gateNext)
                $this->writeShadowState('GateSignalLedger',$gateNext);
        }
        $report=[
            'schema'=>1,'authority'=>'SHADOW_DIRECTION_PHONE_AND_LOCAL_HINTS',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'p03'=>$p03,
            'p03Live'=>$this->readP03LiveCounters(),
            'p03LiveTimeline'=>$p03Timeline['report'],
            'phone'=>$phones,
            'phoneLink'=>$phoneLink,
            'localSensors'=>$local,
            'localActivity'=>$activity,
            'houseObservation'=>$houseObservation,
            'boundaryEvidence'=>$boundary['report'],
            'exitCandidateInventory'=>$exitInventory,
            'gateSignalAudit'=>$gateAudit,
            'recentCombinedEvidence'=>$this->combinedShadowEvidence(
                $p03Timeline['report'],$activity,$boundary['report']
            ),
            // No baseline and no group count -> NO person occupancy guesses.
            'people'=>array_fill_keys(array_keys($phones),'UNKNOWN'),
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
            'HOUSE_QUIET_NO_ABSENCE_PROOF'=>'Ruhig (nicht leer bewiesen)',
            'UNKNOWN'=>'Ungeklaert'
        ][$houseObservation['houseStatus']]??'Ungeklaert';
        $state='P03-Archiv: OUT '.$out.' / IN '.$in.' (Richtungsbeweise); '
            .$street.' | LCN Haus: '.$homeHint
            .' | GUS-Impulse/24h: '.$activity['totalMotionEdges24h']
            .' | P03-neue Beweise/24h: '
            .array_sum($p03Timeline['report']['directionProofs24h'])
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
            .' | Abwesenheit: NICHT BESTAETIGT'
            .' | '.$phoneDescription
            .' | Identitaet UNBEKANNT';
        if ($warnings) $state.=' | QUELLHINWEIS '.implode(',',array_slice($warnings,0,2));
        $this->publish($report,$state);
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

    private function readSensorSnapshot(int $id): ?array
    {
        if($id<=0) return null;
        try {
            if(!IPS_VariableExists($id)) return null;
            $meta=IPS_GetVariable($id);
            $type=$meta['VariableType']??null;
            $changed=$meta['VariableChanged']??null;
            if(!is_int($type) || !in_array($type,[0,1],true)
                || !is_int($changed) || $changed<=0) return null;
            $value=GetValue($id);
            if(($type===0 && !is_bool($value))
                || ($type===1 && (!is_int($value)||$value<0))) return null;
            return ['type'=>$type,'value'=>$value,'changedAt'=>$changed];
        } catch(Throwable $e) {
            return null;
        }
    }

    private function publish(array $report,string $status): void
    {
        $json=json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $newJson=is_string($json)?$json:'{}';
        if ($this->GetValue('EvidenceJSON')!==$newJson)
            $this->SetValue('EvidenceJSON',$newJson);
        if ($this->GetValue('Status')!==$status)
            $this->SetValue('Status',$status);
    }
}
