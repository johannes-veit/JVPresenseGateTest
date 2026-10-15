<?php
declare(strict_types=1);

/**
 * Compact, shareable, read-only P03 test report. Does NOT call cameras,
 * infer distinct people from events, change test/production state or read
 * usernames/passwords. Format can evolve while P03's public Symcon variable
 * ident remains ChatGPTTestResult across future module updates.
 */
final class P03ChatGPTTestReport
{
    public const SCENARIOS=[
        'ONE_HOME_TO_WORK'=>'Eine Person HOME -> LAGER',
        'TWO_SIDE_BY_SIDE'=>'Zwei Personen nebeneinander HOME -> LAGER',
        'TWO_SEPARATED'=>'Zwei Personen mit Abstand HOME -> LAGER',
        'RETURN_WORK_TO_HOME'=>'Rueckweg LAGER -> HOME',
        'STREET_CROSSING'=>'Strassengrenze WORK_LEFT RuleID5',
        'GENERAL_DIAGNOSIS'=>'Allgemeine Kamera-/P03-Diagnose'
    ];

    public static function render(array $baseline,array $nowState,int $now):string
    {
        $hasStart=($baseline['schema']??null)===1
            &&is_int($baseline['startedAt']??null)
            &&$baseline['startedAt']>0
            &&$baseline['startedAt']<=$now
            &&is_array($baseline['values']??null);
        $scenario=$hasStart?(string)($baseline['scenario']??'GENERAL_DIAGNOSIS')
            :'GENERAL_DIAGNOSIS';
        $scenarioName=self::SCENARIOS[$scenario]??self::SCENARIOS['GENERAL_DIAGNOSIS'];
        $b=$hasStart?$baseline['values']:[];
        $lines=[
            '=== P03 TESTERGEBNIS AN CHATGPT ===',
            'Version: 1 | Quelle: bestehende P03-Hauptinstanz #53879 | NUR LESEN',
            'Aktueller Test: '.$scenarioName,
            'Testbeginn: '.($hasStart?date('Y-m-d H:i:s',$baseline['startedAt'])
                :'NICHT MARKIERT – zuerst Testbeginn-Button druecken'),
            'Bericht erzeugt: '.date('Y-m-d H:i:s',$now),
            'TESTARM: '.(($nowState['testActive']??false)?'AN':'AUS')
                .' | PRODUKTION: '.(($nowState['productionEnabled']??false)?'AN':'AUS'),
            'P03 Beweisstatus: '.self::safeLine((string)($nowState['proofState']??'UNBEKANNT')),
            'Verifizierte Uebergaenge: '.self::delta(
                $nowState['verifiedTransfers']??null,
                $b['verifiedTransfers']??null,$hasStart),
            'HINWEIS: IVS-Ereignisse sind KEINE eindeutig gezaehlten Personen.',
            'TEST/PRODUKTION AUS: Mast-Human-Zaehler duerfen trotzdem steigen; '
                .'es wird kein neuer Zonenuebergang gebucht.',
            '--- MASTKAMERAS / ERKENNUNG ---'
        ];
        foreach(['JV_LEFT','WORK_LEFT'] as $role){
            $data=is_array($nowState['masts'][$role]??null)?$nowState['masts'][$role]:[];
            $start=is_array($b['masts'][$role]??null)?$b['masts'][$role]:[];
            $lines[]=$role.' | Observer #'.(int)($data['observerId']??0)
                .' | StreamOK='.self::tri($data['streamOK']??null)
                .' | Human-Zaehler='.self::delta(
                    $data['counter']??null,$start['counter']??null,$hasStart);
            $lines[]='  Empfangsalter(s): '.self::num($data['rxAge']??null)
                .' | Wire-Codes seit Observer-Start: '.self::num($data['wireCodeCount']??null)
                .' | Heartbeats: '.self::num($data['wireHeartbeatCount']??null);
            $after=self::newHistory($data['crosslines']??[],
                $start['crosslines']??[],$hasStart);
            $lines[]='  CrossLine seit markiertem Testbeginn: '.($hasStart
                ?count($after).' neu in den verfuegbaren letzten 8 Ereignissen'
                :'NICHT VERGLEICHBAR (kein Baseline)');
            $items=$hasStart?$after:array_slice($data['crosslines']??[],-3);
            foreach(array_slice($items,-8) as $event){
                if(!is_array($event))continue;
                $lines[]='  IVS '.self::safeLine((string)($event['time']??'?'),16)
                    .' | '.self::safeLine((string)($event['action']??'?'),16)
                    .' | RuleID='.self::safeLine((string)($event['ruleId']??'?'),16)
                    .' | Klasse='.self::safeLine((string)($event['classification']??'?'),24)
                    .' | Richtung='.self::safeLine((string)($event['direction']??'?'),40)
                    .' | Entscheidung='.self::safeLine((string)($event['decision']??'?'),56)
                    .' | Grund='.self::safeLine((string)($event['rejectionReason']??'?'),90)
                    .' | ObjectID='.self::safeLine((string)($event['objectId']??'?'),22);
            }
            if($hasStart&&$after===[])$lines[]='  Keine neue CrossLine-Historie im verfuegbaren Verlauf.';
            $lines[]='  Verlauf ist auf je 8 Kameraereignisse begrenzt; verlorene '
                .'oder beim Neustart geloeschte Daten nicht rekonstruierbar.';
        }
        $lines[]='--- ZUSATZQUELLEN ---';
        $lines[]='TiOC Terrasse | Stream='.self::tri($nowState['terrace']['streamOK']??null)
            .' | Human-CrossLine-Zaehler='.self::delta(
                $nowState['terrace']['counter']??null,
                $b['terrace']['counter']??null,$hasStart);
        $lines[]='WORK_LEFT Strassen-Human-Rohzaehler: '.self::delta(
            $nowState['streetHumanRawCount']??null,
            $b['streetHumanRawCount']??null,$hasStart);
        $lines[]='Letztes Strassensignal: '.self::safeLine(
            (string)($nowState['streetLastRaw']??'KEINER'),260);
        $lines[]='Personenzahl: UNBEKANNT; zwei gleichzeitige Menschen koennen '
            .'nur ein IVS-Ereignis ausloesen.';
        $lines[]='Keine Identitaet, keine zertifizierte Abwesenheit; keine '
            .'Aenderung an LCN, Kamera, Alarm, WLAN oder P03-Testmodus.';
        $lines[]='=== ENDE: KOMPLETT KOPIEREN UND IN DENSELBEN CHAT EINFUEGEN ===';
        return implode("\n",$lines);
    }

    public static function newHistory(mixed $current,mixed $before,bool $hasStart):array
    {
        if(!$hasStart||!is_array($current)||!is_array($before))return [];
        $counts=[];
        foreach($before as $event){
            if(!is_array($event))continue;
            $key=json_encode($event,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if(is_string($key))$counts[$key]=($counts[$key]??0)+1;
        }
        $new=[];
        foreach($current as $event){
            if(!is_array($event))continue;
            $key=json_encode($event,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if(!is_string($key))continue;
            if(($counts[$key]??0)>0){$counts[$key]--;continue;}
            $new[]=$event;
        }
        return $new;
    }

    private static function delta(mixed $value,mixed $start,bool $hasStart):string
    {
        if(!is_int($value))return 'NICHT LESBAR';
        if(!$hasStart||!is_int($start))return (string)$value.' | Delta: KEINE BASELINE';
        if($value<$start)return $value.' | Delta: UNGUELTIG (Zaehlerruecksetzung)';
        return $value.' | Delta: +'.($value-$start);
    }
    private static function tri(mixed $value):string
    {
        return is_bool($value)?($value?'JA':'NEIN'):'UNBEKANNT';
    }
    private static function num(mixed $value):string
    {
        return is_int($value)?(string)$value:'UNBEKANNT';
    }
    private static function safeLine(string $s,int $max=240):string
    {
        return substr(preg_replace('/[\r\n\t]+/u',' ',trim($s))??'',0,$max);
    }
}
