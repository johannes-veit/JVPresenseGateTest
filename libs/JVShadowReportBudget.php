<?php
declare(strict_types=1);

/**
 * Whole-line bounded SHADOW report, with audit/absence/alarm priorities.
 * Operates on already-sanitized diagnostic lines; no raw device data.
 */
final class JVShadowReportBudget
{
    public static function fit(array $lines,int $maxBytes=13000):string
    {
        $lines=array_values(array_filter($lines,'is_string'));
        $all=implode("\n",$lines);
        if(strlen($all)<=$maxBytes)return $all;
        // Even deliberately tiny budgets must remain bounded and explicitly
        // fail closed. The normal module report budget is 13,000 bytes.
        if($maxBytes<700) {
            $short="SHADOW BERICHT GEKUERZT | ALARMFREIGABE: NEIN"
                ."\n=== ENDE TESTBERICHT ===";
            return substr($short,0,max(0,$maxBytes));
        }

        // Safety text cannot be dropped or made unbounded by repeated or
        // anomalously long upstream diagnostic lines. We take the LAST
        // ordinary copy-help message, bound its length without splitting
        // UTF-8, and always generate a fixed, fail-closed safety footer.
        $tail=[];
        $copyHelp=null;$privacyHelp=null;
        $safeLine=static function(string $line,int $max=200):string {
            if(strlen($line)<=$max)return $line;
            $cut=substr($line,0,$max-14);
            while($cut!=='' && preg_match('//u',$cut)!==1)
                $cut=substr($cut,0,-1);
            return $cut.' [GEKUERZT]';
        };
        foreach($lines as $i=>$line){
            if(str_starts_with($line,'ABWESENHEIT-AKTIONEN / ALARMFREIGABE:')){
                $tail[$i]=true;
            }elseif(str_starts_with($line,'An ChatGPT senden:')){
                $tail[$i]=true;$copyHelp=$safeLine($line);
            }elseif(str_starts_with($line,'Keine weiteren Passwoerter,')){
                $tail[$i]=true;$privacyHelp=$safeLine($line);
            }
        }
        $footer=['[DETAILS GEKUERZT – KERNBEFUNDE UND SICHERHEITSHINWEISE ERHALTEN]'];
        foreach([$copyHelp,$privacyHelp] as $line) {
            if($line!==null)$footer[]=$line;
        }
        $footer[]='ABWESENHEIT-AKTIONEN / ALARMFREIGABE: NEIN';
        $footer[]='=== ENDE TESTBERICHT ===';
        $suffix="\n".implode("\n",$footer);
        $capacity=$maxBytes-strlen($suffix)-1;
        $chosen=[];$used=0;
        // Index-based selection matters: dozens of identical RAUMIMPULS
        // rows must not be accidentally reinserted by shared text keys.
        $select=static function(int $i)use(&$chosen,&$used,$lines,$capacity):void{
            if(isset($chosen[$i]))return;
            $bytes=strlen($lines[$i])+1;
            if($used+$bytes>$capacity)return;
            $chosen[$i]=true;$used+=$bytes;
        };
        foreach(array_keys($lines) as $i){
            if($i<7&&!isset($tail[$i]))$select($i);
        }
        $priority=[
            '--- Johannes ---','--- Carina ---','--- KAMERAS / LCN / ROUTE ---',
            // Fresh commissioning defects must survive truncation ahead of
            // historical room patterns. Preserve the lines verbatim.
            'BETRIEB SSID Johannes:', 'BETRIEB SSID Carina:',
            'BETRIEB P03:', 'P03 FREIGABE:',
            'BETRIEB SSID-BRIDGE:', 'BETRIEB SSID QUELLWERTE:',
            'BETRIEB SSID UPDATE-VERLAUF:',
            'BETRIEB SSID NAECHSTER SCHRITT:',
            'BETRIEB P03 OFFEN ',
            'BEWOHNER-ABWESENHEITSINDIZ:',
            'BEWOHNER-ABWESENHEIT FEHLT:',
            '--- HEUTE ABEND:', 'P03-UEBERGANG/24h:',
            'P03-5MIN-STATUS:', 'P03-5MIN/24h:',
            'HANDY-EVENT/24h:', 'RAUMKONTEXT:',
            'RAUMSCHWERPUNKTE/15min:', 'RAUMZUSAMMENHANG:',
            'RAUMQUELLENFILTER:', 'RAUMWEGE-ATLAS:',
            'RAUMWEG-MUSTER:', 'RAUMWEGE-PRUEFUNG:',
            'DOPPELABREISE:', 'DOPPELABREISE RAUMKONFLIKT:',
            'DOPPELABREISE-QUELLENLUECKE ',
            'HANDY-DOPPELABREISE/24h:',
            'HANDY-ZYKLEN/24h:',
            'HANDY-ZONENUEBERSCHNEIDUNG:',
            'P03-EINMALBELEGUNG:',
            'HANDY-RAUM-GEGENCHECK:',
            'HANDY-RAUM-24h:',
            'QUELLEN-INTEGRITAET HANDY/P03/GUS:',
            'BEWEISKETTE DIREKTVERGLEICH:',
            'QUELLSPERRE BEI FEHLENDER QUELLE:',
            'QUELLEN-INTEGRITAET DETAIL:',
            'QUELLEN-INTEGRITAET NAECHSTER SCHRITT ',
            'HANDY-OUT-DOPPELMELDUNGEN VERWORFEN:',
            'HANDY-DOPPELABREISE-KONTEXT:',
            'HANDY-GEMEINSAMES-OUT:',
            'HANDY-P03-ZEITABGLEICH:',
            'HANDY-GEMEINSAMES-OUT-QUELLENLUECKE:',
            'RAUMHISTORIE/24h:',
            'RAUMHISTORIE/6h:', 'STROM-KUECHEN-KONTEXT:',
            'SELBSTPRUEFUNG:',
            'SELBSTPRUEFUNG HINWEISE:', 'UPGRADE-SPEICHERPRUEFUNG:',
            'UPGRADE PRUEFPUNKT:', 'ENDPRUEFUNG HAUS:',
            'ENDPRUEFUNG AUTO/FUSSWEG:', 'MEILENSTEIN QUELLENQUALIFIKATION:',
            'NAECHSTE QUELLENARBEIT (NUR LESEN):','WEITERE QUELLENARBEIT ',
            'OFFEN ', 'Weitere SHADOW-Warnungen:'
        ];
        foreach($lines as $i=>$line){
            if(isset($tail[$i])||$line==='=== ENDE TESTBERICHT ===')continue;
            foreach($priority as $tag){
                if(str_starts_with($line,$tag)){
                    $select($i);break;
                }
            }
        }
        foreach(array_keys($lines) as $i){
            if(isset($tail[$i])||$lines[$i]==='=== ENDE TESTBERICHT ===')
                continue;
            $select($i);
        }
        ksort($chosen,SORT_NUMERIC);
        $kept=[];
        foreach(array_keys($chosen) as $i)$kept[]=$lines[$i];
        $result=implode("\n",$kept).$suffix;
        // The fixed safety footer is bounded independently of the input;
        // every selected full line consumes its exact byte size.
        return $result;
    }
}
