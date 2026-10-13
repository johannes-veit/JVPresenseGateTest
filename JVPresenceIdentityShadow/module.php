<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/JVP03ProofLogReplay.php';
require_once dirname(__DIR__).'/libs/JVLocalSensorEvidence.php';

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

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Enabled',false);
        // Known owner installation ID; verify GUID before EVERY read.
        $this->RegisterPropertyInteger('P03InstanceID',53879);
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
        $this->RegisterVariableString('Status','Identität – SHADOW Status','',0);
        $this->RegisterVariableString('EvidenceJSON','Identität – Nachweise (JSON)','',1);
        $this->RegisterTimer('ReadLocal',0,'JVIDSH_Refresh($_IPS["TARGET"]);');
        $this->SetBuffer('LocalSensorState','{}');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('ReadLocal',$this->ReadPropertyBoolean('Enabled')?60000:0);
        $this->syncSensorSubscriptions();
        $this->Refresh();
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

        $phones=[];
        $phoneInst=$this->ReadPropertyInteger('PhoneObserverInstanceID');
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
        $state=json_decode($this->GetBuffer('LocalSensorState'),true);
        $state=is_array($state)?$state:[];
        $sensorData=JVLocalSensorEvidence::evaluate(
            $sourceRows,$snapshots,$state,time());
        $local=$sensorData['report'];
        $newState=json_encode($sensorData['next'],JSON_UNESCAPED_SLASHES);
        if(is_string($newState) && $this->GetBuffer('LocalSensorState')!==$newState) {
            $this->SetBuffer('LocalSensorState',$newState);
        }
        if($local['warnings']) {
            $warnings=array_merge($warnings,$local['warnings']);
        }
        $report=[
            'schema'=>1,'authority'=>'SHADOW_DIRECTION_PHONE_AND_LOCAL_HINTS',
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'p03'=>$p03,
            'phone'=>$phones,
            'localSensors'=>$local,
            // No baseline and no group count -> NO person occupancy guesses.
            'people'=>array_fill_keys(array_keys($phones),'UNKNOWN'),
            'warnings'=>array_values(array_unique($warnings))
        ];
        $out=$p03['p03']['homeToWork'];
        $in=$p03['p03']['workToHome'];
        $street=$p03['street']['directionMappingCertified']?'Straße gelernt':'Straße ungeklärt';
        $homeHint=$local['zones']['HOME']['activity']??'UNKNOWN';
        $state='P03-Archiv: OUT '.$out.' / IN '.$in.' (nur Richtungsbeweise); '
            .$street.' | LCN Haus: '.$homeHint
            .' | Personenzahl/Identitaet UNBEKANNT';
        if ($warnings) $state.=' | QUELLHINWEIS '.implode(',',array_slice($warnings,0,2));
        $this->publish($report,$state);
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
