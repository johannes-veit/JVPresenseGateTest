<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/libs/JVPhoneEvidenceEngine.php';
require_once dirname(__DIR__) . '/libs/JVUniFiAPSnapshot.php';
require_once dirname(__DIR__) . '/libs/JVUniFiLocalInventory.php';

/**
 * PHONE-ONLY observer for the PresenceCore.
 * Neither P03/P05 nor cameras, router, AP or actual occupancy are modified.
 * Two visible String variables; source credentials are never requested.
 */
class JVPresencePhoneObserver extends IPSModule
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Enabled', true);
        $this->RegisterPropertyInteger('WiFiFreshSeconds', 180);
        $this->RegisterPropertyInteger('GeoFreshSeconds', 900);
        $this->RegisterPropertyInteger('APSnapshotFreshSeconds', 180);
        $this->RegisterPropertyString('APSources','[]');
        $this->RegisterPropertyString('People', json_encode([
            ['PersonID'=>'johannes','Name'=>'Johannes','Enabled'=>true],
            ['PersonID'=>'carina','Name'=>'Carina','Enabled'=>true]
        ], JSON_UNESCAPED_UNICODE));
        $this->RegisterPropertyString('Devices', json_encode([
            ['DeviceID'=>'johannes_phone','PersonID'=>'johannes','Name'=>'Xiaomi 13T',
             'Enabled'=>true,'MAC'=>'','WiFiVarID'=>0,'GeoVarID'=>0],
            ['DeviceID'=>'carina_phone','PersonID'=>'carina','Name'=>'iPhone',
             'Enabled'=>true,'MAC'=>'','WiFiVarID'=>0,'GeoVarID'=>0]
        ], JSON_UNESCAPED_UNICODE));
        $this->RegisterVariableString('Status','Smartphone-Status','',0);
        $this->RegisterVariableString('EvidenceJSON','Smartphone-Nachweise (JSON)','',1);
        $this->RegisterTimer('Poll',0,'JVPHONE_RefreshEvidence($_IPS["TARGET"]);');
        // Internal per-AP evidence in an IPSModule buffer, NOT variables.
        // New instance and restart always begin with unknown AP observations.
        $this->SetBuffer('APSnapshotStatus','{}');
        $this->SetBuffer('UniFiLocalAudit','Noch keine lokale AP-Inventur durchgeführt.');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $enabled=$this->ReadPropertyBoolean('Enabled');
        // Polling only the selected existing Symcon variables, never the AP.
        $this->SetTimerInterval('Poll',$enabled?60000:0);
        $this->RefreshEvidence();
    }

    public function GetConfigurationForm(): string
    {
        $path=__DIR__.'/form.json';
        $form=json_decode((string)file_get_contents($path),true);
        if (!is_array($form) || !isset($form['elements'])
            || !is_array($form['elements'])) return '{}';
        $audit=$this->GetBuffer('UniFiLocalAudit');
        if (trim($audit)==='') $audit='Noch keine lokale AP-Inventur durchgeführt.';
        $form['elements'][]=[
            'type'=>'Label',
            'caption'=>mb_substr($audit,0,6000)
        ];
        return json_encode($form,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}';
    }

    /**
     * One-click audit only: reads existing IPS names and selected host/IP
     * properties of relevant locally installed IPS instances. No network I/O,
     * no credentials emitted, no AP or Symcon instance configuration written,
     * no new Symcon variables. The report persists only in an instance buffer.
     */
    public function DiagnoseUniFiLocal(): string
    {
        $objects=[];
        $matched=0;
        foreach (IPS_GetInstanceList() as $id) {
            if (!is_int($id) && !is_numeric($id)) continue;
            $id=(int)$id;
            if ($id<=0) continue;
            try {
                $name=(string)IPS_GetName($id);
                if (!JVUniFiLocalInventory::plausibleName($name)) continue;
                // Limit configuration reads to avoid excessive local work.
                if ($matched++>=JVUniFiLocalInventory::MAX_CANDIDATES) break;
                $objects[]=[
                    'name'=>$name,
                    'config'=>IPS_GetConfiguration($id)
                ];
            } catch (Throwable $e) {
                // Failure is strictly informational. Never attempt to
                // recover by changing this or any remote device.
            }
        }
        $audit=JVUniFiLocalInventory::inspect($objects);
        $message=JVUniFiLocalInventory::readable($audit);
        $this->SetBuffer('UniFiLocalAudit',$message);
        return $message;
    }

    /**
     * Refresh can be called by a button, Symcon timer or another module.
     * It is deliberately read-only except for its own two output variables.
     */
    public function RefreshEvidence(): void
    {
        if (!$this->ReadPropertyBoolean('Enabled')) {
            $report=[
                'schema'=>JVPhoneEvidenceEngine::SCHEMA,'time'=>time(),
                'authority'=>'DISABLED','personPresenceMayBeChanged'=>false,
                'propertyEmptyMayBeCertified'=>false,
                'people'=>[],'devices'=>[],'errors'=>[]
            ];
            $this->publish($report,'Smartphone-Beobachtung deaktiviert');
            return;
        }
        $people=json_decode($this->ReadPropertyString('People'),true);
        $devices=json_decode($this->ReadPropertyString('Devices'),true);
        if (!is_array($people) || !array_is_list($people)
            || !is_array($devices) || !array_is_list($devices)) {
            $this->publish([
                'schema'=>JVPhoneEvidenceEngine::SCHEMA,'time'=>time(),
                'authority'=>'PHONE_OBSERVATIONS_ONLY',
                'personPresenceMayBeChanged'=>false,
                'propertyEmptyMayBeCertified'=>false,
                'people'=>[],'devices'=>[],'errors'=>['Ungültige Konfiguration (JSON)']
            ],'Konfigurationsfehler – keine Smartphone-Nachweise');
            return;
        }
        $validated=JVPhoneEvidenceEngine::validateConfiguration($people,$devices);
        // No variable reads at all if any configuration is inconsistent.
        if ($validated['errors']) {
            $report=JVPhoneEvidenceEngine::evaluate($people,$devices,[],time());
            $this->publish($report,'Konfigurationsfehler – keine Smartphone-Nachweise');
            return;
        }
        $apConfig=$this->validatedAPSources();
        if ($apConfig['errors']!==[]) {
            $validated['errors']=array_merge($validated['errors'],$apConfig['errors']);
        }
        if ($validated['errors']!==[]) {
            $report=JVPhoneEvidenceEngine::evaluate($people,$devices,[],time());
            $report['errors']=array_merge($report['errors'],$apConfig['errors']);
            $report['people']=[];
            $report['devices']=[];
            $this->publish($report,'Konfigurationsfehler – AP-Quellen unklar');
            return;
        }
        $apStatus=json_decode($this->GetBuffer('APSnapshotStatus'),true);
        $apStatus=is_array($apStatus)?$apStatus:[];
        // Old, removed or disabled APs MUST NOT contribute to matching.
        $activeAPs=array_fill_keys($apConfig['active'],true);
        $apStatus=array_intersect_key($apStatus,$activeAPs);
        $matched=JVUniFiAPSnapshot::currentMatches(
            $apStatus,time(),$this->ReadPropertyInteger('APSnapshotFreshSeconds')
        );
        $inputs=[];
        foreach ($validated['devices'] as $device) {
            // Inactive/retired phones are never read. This also allows safe
            // replacement of their old variable IDs without extra work.
            if (!$device['enabled']) {
                continue;
            }
            // Read ONLY existing local Symcon source values; zero network I/O,
            // no AP polling, no camera API calls, no per-phone objects.
            $wifi=$this->readSource($device['wifiVarID'],0);
            if (isset($matched[$device['id']])) {
                // Positive direct-AP station match outranks missing/negative
                // generic WLAN sources. Neither can certify human presence.
                $wifi=['value'=>true,'updatedAt'=>$matched[$device['id']]];
            }
            $inputs[$device['id']]=[
                'wifi'=>$wifi,
                'geo'=>$this->readSource($device['geoVarID'],3)
            ];
        }
        $report=JVPhoneEvidenceEngine::evaluate(
            $people,$devices,$inputs,time(),
            max(10,$this->ReadPropertyInteger('WiFiFreshSeconds')),
            max(10,$this->ReadPropertyInteger('GeoFreshSeconds'))
        );
        $pieces=[];
        foreach ($report['people'] as $person) {
            // Keep the ONE short human-readable status bounded even with
            // 30+ residents. All residents remain in the single JSON report.
            if (count($pieces) >= 5) {
                break;
            }
            $parts=[
                'PHONE_PROPERTY_INDICATION'=>'Telefon am Grundstück wahrscheinlich',
                'PHONE_OUTSIDE_INDICATION'=>'Telefon außerhalb gemeldet',
                'PHONE_CONFLICT'=>'Widerspruch der Telefonsignale',
                'DISABLED'=>'ausgeschaltet',
                'PHONE_UNKNOWN'=>'Telefonstatus unbekannt'
            ];
            $pieces[]=$person['name'].': '.($parts[$person['phoneStatus']]??'unbekannt');
        }
        $remainder=count($report['people'])-count($pieces);
        if ($remainder>0) {
            $pieces[]='+'.$remainder.' weitere Hausbewohner (Details im JSON)';
        }
        $this->publish($report,implode(' | ',$pieces)
            .' | Personen-Anwesenheit: NICHT abgeleitet');
    }

    /**
     * Authorized local adapter hands us ONE complete, fresh AP mca-dump JSON.
     * No network sockets, AP login, images or passwords are handled here.
     * Returns false on unknown AP, invalid or overlarge data. An invalid
     * snapshot INVALIDATES older same-AP evidence (fail closed).
     * No IPS variables are created, even with dozens of devices or APs.
     */
    public function ImportUniFiAPSnapshot(string $apId,string $json): bool
    {
        if (!$this->ReadPropertyBoolean('Enabled')) return false;
        $apConfig=$this->validatedAPSources();
        if ($apConfig['errors']!==[] || !in_array($apId,$apConfig['active'],true)) {
            return false;
        }
        $people=json_decode($this->ReadPropertyString('People'),true);
        $devices=json_decode($this->ReadPropertyString('Devices'),true);
        if (!is_array($people)||!is_array($devices)
            || !array_is_list($people)||!array_is_list($devices)) return false;
        $validated=JVPhoneEvidenceEngine::validateConfiguration($people,$devices);
        if ($validated['errors']!==[]) return false;
        $matched=JVUniFiAPSnapshot::parse($json,$validated['devices']);
        $snapshots=json_decode($this->GetBuffer('APSnapshotStatus'),true);
        $snapshots=is_array($snapshots)?$snapshots:[];
        $snapshots=array_intersect_key(
            $snapshots,array_fill_keys($apConfig['active'],true));
        $snapshots[$apId]=[
            'at'=>time(),
            'ok'=>$matched['ok'],
            // Store ONLY already enrolled phone IDs, NOT station MACs.
            'matchedDeviceIDs'=>$matched['matchedDeviceIDs']
        ];
        // Even a previously cached association must be removed if a fresh
        // complete AP read fails. Never reuse possibly stale snapshots.
        $this->SetBuffer('APSnapshotStatus',
            json_encode($snapshots,JSON_UNESCAPED_SLASHES)?:'{}');
        $this->RefreshEvidence();
        return $matched['ok'];
    }

    /** @return array{active:array,errors:array} */
    private function validatedAPSources(): array
    {
        $rows=json_decode($this->ReadPropertyString('APSources'),true);
        if (!is_array($rows) || !array_is_list($rows)) {
            return ['active'=>[], 'errors'=>['APSources: ungültige Liste']];
        }
        $active=[];
        $seen=[];
        $errors=[];
        if (count($rows)>64) $errors[]='APSources: mehr als 64 APs';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $errors[]='APSources: ungültige Zeile';
                continue;
            }
            $id=trim((string)($row['APID']??''));
            if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$id)
                || isset($seen[$id])) {
                $errors[]='APSources: ungültige/doppelte AP-ID';
                continue;
            }
            $seen[$id]=true;
            if (!empty($row['Enabled'])) $active[]=$id;
        }
        return ['active'=>$active,'errors'=>$errors];
    }

    /** Only fresh, already existing variables can feed an observation. */
    private function readSource(int $id, int $type): ?array
    {
        if ($id<=0) return null;
        try {
            if (!IPS_VariableExists($id)) return ['value'=>null,'updatedAt'=>0];
            $meta=IPS_GetVariable($id);
            if ((int)($meta['VariableType']??-1)!==$type) {
                return ['value'=>null,'updatedAt'=>0];
            }
            $updated=(int)($meta['VariableUpdated']??0);
            return ['value'=>GetValue($id),'updatedAt'=>$updated];
        } catch (Throwable $e) {
            return ['value'=>null,'updatedAt'=>0];
        }
    }

    private function publish(array $report,string $status): void
    {
        $json=json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $newJson=$json===false?'{}':$json;
        // Compare semantic evidence, NOT the current polling timestamp:
        // otherwise SetValue writes two variables to the Symcon archive and
        // update bus every 60s although no phone state actually changed.
        $previousJson=(string)$this->GetValue('EvidenceJSON');
        $previous=json_decode($previousJson,true);
        if (is_array($previous)) {
            $newComparable=$report;
            $oldComparable=$previous;
            unset($newComparable['time'],$oldComparable['time']);
            if ($newComparable===$oldComparable) {
                // 'time' in EvidenceJSON means LAST CHANGED, not last polled.
                $newJson=$previousJson;
            }
        }
        if ($previousJson!==$newJson) {
            $this->SetValue('EvidenceJSON',$newJson);
        }
        if ((string)$this->GetValue('Status')!==$status) {
            $this->SetValue('Status',$status);
        }
    }

    public function RunSimulation(): void
    {
        $now=100000;
        $p=[['PersonID'=>'johannes','Name'=>'Johannes','Enabled'=>true],
            ['PersonID'=>'carina','Name'=>'Carina','Enabled'=>true]];
        $d=[['DeviceID'=>'xiaomi','PersonID'=>'johannes','Name'=>'Xiaomi 13T',
             'Enabled'=>true,'MAC'=>'','WiFiVarID'=>0,'GeoVarID'=>0],
            ['DeviceID'=>'iphone','PersonID'=>'carina','Name'=>'iPhone',
             'Enabled'=>true,'MAC'=>'','WiFiVarID'=>0,'GeoVarID'=>0]];
        $cases=[
            'keine Signale'=>[],
            'Telefon im WLAN'=>['xiaomi'=>['wifi'=>['value'=>true,'updatedAt'=>$now]]],
            'Telefon ohne WLAN'=>['xiaomi'=>['wifi'=>['value'=>false,'updatedAt'=>$now]]],
            'Telefon außerhalb'=>['iphone'=>['geo'=>['value'=>'OUTSIDE','updatedAt'=>$now]]],
            'Widerspruch'=>['xiaomi'=>['wifi'=>['value'=>true,'updatedAt'=>$now],
                                    'geo'=>['value'=>'OUTSIDE','updatedAt'=>$now]]],
            'veraltetes WLAN'=>['xiaomi'=>['wifi'=>['value'=>true,'updatedAt'=>$now-1000]]]
        ];
        $lines=['=== PHONE OBSERVER SIMULATION – OHNE KAMERA-/STATUSBUCHUNG ==='];
        foreach ($cases as $name=>$input) {
            $r=JVPhoneEvidenceEngine::evaluate($p,$d,$input,$now);
            $ok=($r['authority']==='PHONE_OBSERVATIONS_ONLY'
                && $r['personPresenceMayBeChanged']===false
                && $r['propertyEmptyMayBeCertified']===false
                && ($r['people'][0]['personLocation']??'')==='UNKNOWN');
            $lines[]=$name.' – '.($ok?'PASS':'FEHLER')
                .' | '.($r['people'][0]['phoneStatus']??'?');
        }
        $this->SetValue('Status',implode(" | ",$lines));
    }
}
