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
        if($maxBytes<700)return 'SHADOW BERICHT ZU LANG\n=== ENDE TESTBERICHT ===';

        $tail=[];
        foreach($lines as $i=>$line){
            if(str_starts_with($line,'ABWESENHEIT-AKTIONEN / ALARMFREIGABE:')
                ||str_starts_with($line,'An ChatGPT senden:')
                ||str_starts_with($line,'Keine weiteren Passwoerter,')){
                $tail[$i]=$line;
            }
        }
        $suffix="\n[DETAILS GEKUERZT – KERNBEFUNDE UND SICHERHEITSHINWEISE ERHALTEN]\n"
            .implode("\n",$tail)
            ."\n=== ENDE TESTBERICHT ===";
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
            '--- HEUTE ABEND:', 'P03-UEBERGANG/24h:',
            'P03-5MIN-STATUS:', 'P03-5MIN/24h:',
            'HANDY-EVENT/24h:', 'RAUMKONTEXT:',
            'RAUMSCHWERPUNKTE/15min:', 'RAUMZUSAMMENHANG:',
            'RAUMQUELLENFILTER:', 'SELBSTPRUEFUNG:',
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
        // The index-based, byte-exact budget above guarantees this,
        // including the one newline per chosen line.
        return $result;
    }
}
