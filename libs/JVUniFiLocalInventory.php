<?php
declare(strict_types=1);

/**
 * LOCAL diagnostic of already existing IP-Symcon objects.
 * No ping, ARP, SSH, HTTP, DNS, port scan, SNMP or device writes.
 * No raw IPS instance configuration is returned or persisted.
 */
final class JVUniFiLocalInventory
{
    public const MAX_CANDIDATES = 80;

    /** Network drawing 29 Aug 2026: AP addresses are *not* fully specified. */
    public static function diagram(): array
    {
        return [
            ['name'=>'Büro','connection'=>'Coreswitch Port 13 / PoE','ip'=>'192.168.107.x (Platzhalter)'],
            ['name'=>'A-Raum','connection'=>'Coreswitch Port 10 / PoE','ip'=>'192.168.107.x (Platzhalter)'],
            ['name'=>'Lagerplatz (UniFi Flex Port 5)','connection'=>'UniFi Flex / Port 5','ip'=>'192.168.107.x (Platzhalter)'],
            ['name'=>'Werkstatt AP 1','connection'=>'UniFi Switch Werkstatt / Port 2','ip'=>'192.168.107.x (Platzhalter)'],
            ['name'=>'Werkstatt AP 2','connection'=>'UniFi Switch Werkstatt / Port 8','ip'=>'192.168.107.x (Platzhalter)'],
            ['name'=>'Garage JV','connection'=>'UniFi Ultra 8 / Port 1','ip'=>'192.168.103.x (Platzhalter)'],
            ['name'=>'JV Keller','connection'=>'Netgear GS305PP Keller 2 / Port 1','ip'=>'192.168.107.x (Platzhalter)'],
            ['name'=>'JV EG','connection'=>'Netgear GS305PP EG / Port 1','ip'=>'192.168.107.x (Platzhalter)'],
            ['name'=>'JV OG','connection'=>'Netgear GS305PP OG / Port 1','ip'=>'192.168.107.x (Platzhalter)']
        ];
    }

    /** Only names that may refer to an AP, controller or WLAN object. */
    public static function plausibleName(string $name): bool
    {
        return preg_match('/(?:unifi|uap\b|access[ -]?point|wlan|wifi|wi-fi)/iu',$name)===1;
    }

    /**
     * @param array<int,array{name:string,config:mixed,id?:int}> $objects
     * @return array{knownDiagramAPs:int,found:array,warnings:array}
     */
    public static function inspect(array $objects): array
    {
        $found=[];
        $warnings=[];
        $seen=[];
        foreach ($objects as $object) {
            if (!is_array($object) || !self::plausibleName((string)($object['name']??''))) {
                continue;
            }
            $name=trim((string)$object['name']);
            if ($name==='') continue;
            $config=$object['config']??null;
            if (is_string($config)) {
                $config=json_decode($config,true);
            }
            // Filter by known, explicitly named IP/host keys only;
            // password/token/credentials/keys/HTTP URLs are NEVER output.
            $ips=[];
            if (is_array($config)) {
                foreach ($config as $key=>$value) {
                    if (!is_string($key) || !is_string($value)
                        || !preg_match('/^(?:Host|Hostname|IPAddress|IP|Address|DeviceIP)$/iD',$key)) {
                        continue;
                    }
                    $value=trim($value);
                    if (filter_var($value,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false
                        && $value!=='0.0.0.0' && $value!=='255.255.255.255') {
                        $ips[]=$value;
                    }
                }
            }
            $ips=array_values(array_unique($ips));
            sort($ips,SORT_STRING);
            $key=$name.'|'.implode(',',$ips);
            if (isset($seen[$key])) continue;
            $seen[$key]=true;
            $found[]=[
                'name'=>mb_substr($name,0,100),
                'ipCandidates'=>$ips,
                // A local IPS instance can be UniFi Network, not a physical
                // AP; label every address as candidate, never verified.
                'verifiedAP'=>false
            ];
            if (count($found)>=self::MAX_CANDIDATES) {
                $warnings[]='Treffergrenze erreicht (80), keine weiteren Objekte geprüft';
                break;
            }
        }
        if (!$found) {
            $warnings[]='Keine zugehörigen UniFi-/WLAN-Objekte in IP-Symcon gefunden';
        }
        $warnings[]='IP-Kandidaten sind nur bestehende Symcon-Einträge; keine AP-Verbindung geprüft';
        return ['knownDiagramAPs'=>count(self::diagram()),'found'=>$found,'warnings'=>$warnings];
    }

    public static function readable(array $audit): string
    {
        $lines=[
            '=== UNIFI INVENTAR / NUR SYMCON-LOKAL GELESEN ===',
            'Diagramm vom 29.08.2026: '.(int)($audit['knownDiagramAPs']??0).' AP-Positionen',
            'Diagramm zeigt IP-Endziffern als x; keine IP wird erfunden.',
            'SymBox: 192.168.103.59 (bekannt), keine AP-Netzwerkabfrage.'
        ];
        foreach ($audit['found']??[] as $row) {
            $lines[]='Symcon: '.$row['name'].' | AP-IP-Kandidaten: '
                .($row['ipCandidates']?implode(', ',$row['ipCandidates']):'keine');
        }
        foreach ($audit['warnings']??[] as $warning) $lines[]='Hinweis: '.$warning;
        $lines[]='KEINE Änderungen; kein Ping, SSH, HTTP, Netzwerkscan.';
        return implode("\n",$lines);
    }
}
