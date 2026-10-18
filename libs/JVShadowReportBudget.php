<?php
declare(strict_types=1);

/**
 * Keep the safety verdict and action items when a 24-hour diagnostic grows
 * beyond the one-field paste limit. Cuts WHOLE lines, not half a source ID,
 * reason code or UTF-8 character; does not add any secrets.
 */
final class JVShadowReportBudget
{
    public static function fit(array $lines,int $maxBytes=13000):string
    {
        $lines=array_values(array_filter($lines,'is_string'));
        $all=implode("\n",$lines);
        if(strlen($all)<=$maxBytes)return $all;
        if($maxBytes<700)return 'SHADOW BERICHT ZU LANG\n=== ENDE TESTBERICHT ===';
        $end='=== ENDE TESTBERICHT ===';
        $tail=array_values(array_filter($lines,static fn($s)=>
            str_starts_with($s,'ABWESENHEIT-AKTIONEN / ALARMFREIGABE:')
            ||str_starts_with($s,'An ChatGPT senden:')
            ||str_starts_with($s,'Keine weiteren Passwoerter,')
        ));
        $important=[];
        $tags=[
            '--- Johannes ---','--- Carina ---','--- KAMERAS / LCN / ROUTE ---',
            '--- HEUTE ABEND:', 'SELBSTPRUEFUNG:',
            'SELBSTPRUEFUNG HINWEISE:', 'UPGRADE-SPEICHERPRUEFUNG:',
            'UPGRADE PRUEFPUNKT:', 'ENDPRUEFUNG HAUS:',
            'ENDPRUEFUNG AUTO/FUSSWEG:', 'MEILENSTEIN QUELLENQUALIFIKATION:',
            'NAECHSTE QUELLENARBEIT (NUR LESEN):','WEITERE QUELLENARBEIT ',
            'OFFEN ', 'Weitere SHADOW-Warnungen:'
        ];
        foreach($lines as $s){
            foreach($tags as $tag){
                if(str_starts_with($s,$tag)){
                    $important[$s]=true;
                    break;
                }
            }
        }
        $picked=[];$used=0;
        $reserve=2500;
        foreach(array_slice($lines,0,7) as $line){
            if(strlen($line)>$maxBytes/5)continue;
            $picked[$line]=true;$used+=strlen($line)+1;
        }
        foreach(array_keys($important) as $line){
            if(isset($picked[$line]))continue;
            if($used+strlen($line)+1>$maxBytes-$reserve)continue;
            $picked[$line]=true;$used+=strlen($line)+1;
        }
        $footer="\n[DETAILS GEKUERZT – KERNBEFUNDE UND SICHERHEITSHINWEISE ERHALTEN]\n"
            .implode("\n",$tail)."\n".$end;
        $budget=$maxBytes-strlen($footer)-1;
        // Preserve useful regular context until the exact budget is reached.
        foreach($lines as $line){
            if(isset($picked[$line])||$line===$end
                ||in_array($line,$tail,true))continue;
            if($used+strlen($line)+1>$budget)continue;
            $picked[$line]=true;$used+=strlen($line)+1;
        }
        // Keep original chronological line order, not priority-first order.
        $kept=[];
        foreach($lines as $line)if(isset($picked[$line]))$kept[]=$line;
        $prefix=implode("\n",$kept);
        while(strlen($prefix)+strlen($footer)>$maxBytes
            &&count($kept)>7){
            array_pop($kept);
            $prefix=implode("\n",$kept);
        }
        return $prefix.$footer;
    }
}
