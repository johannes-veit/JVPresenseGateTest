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

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Enabled',false);
        // Known owner installation ID; verify GUID before EVERY read.
        $this->RegisterPropertyInteger('P03InstanceID',53879);
        $this->RegisterPropertyInteger('PhoneObserverInstanceID',0);
        $this->RegisterPropertyString('SensorSources','[]');
        $this->RegisterVariableString('Status','Identität – SHADOW Status','',0);
        $this->RegisterVariableString('EvidenceJSON','Identität – Nachweise (JSON)','',1);
        $this->RegisterTimer('ReadLocal',0,'JVIDSH_Refresh($_IPS["TARGET"]);');
        $this->SetBuffer('LocalSensorState','{}');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('ReadLocal',$this->ReadPropertyBoolean('Enabled')?60000:0);
        $this->Refresh();
    }

    public function GetConfigurationForm(): string
    {
        return (string)file_get_contents(__DIR__.'/form.json');
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
