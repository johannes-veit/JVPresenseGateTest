<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/libs/JVPhoneEvidenceEngine.php';

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
        return (string)file_get_contents($path);
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
        $inputs=[];
        foreach ($validated['devices'] as $device) {
            // Inactive/retired phones are never read. This also allows safe
            // replacement of their old variable IDs without extra work.
            if (!$device['enabled']) {
                continue;
            }
            // Read ONLY existing local Symcon source values; zero network I/O,
            // no AP polling, no camera API calls, no per-phone objects.
            $inputs[$device['id']]=[
                'wifi'=>$this->readSource($device['wifiVarID'],0),
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
