<?php
declare(strict_types=1);

/**
 * Read-only, model-focused Dahua 5442 config auditor.
 *
 * Never guesses a supported setup based on HTTP=200 alone. CGI keys on
 * 2.840 firmware differ across hardware revisions. Instead expose the
 * actual returned fields from THIS IPC, their read status, and whether
 * settings critical to SMD/IVS are populated.
 */
final class P03IPC5442Audit
{
    /** @return string[] */
    public static function summarizeConfig(string $table, string $body, int $limit = 42): array
    {
        $prefix = preg_quote($table, '/');
        $lines = preg_split('/\r\n|\n|\r/', $body) ?: [];
        $matched = [];
        $enabled = [];
        $detectorValues = ['region' => 0, 'schedule' => 0, 'class' => 0];

        foreach ($lines as $line) {
            if (!preg_match('/^\s*(' . $prefix . '\[\d+\](?:\[\d+\])?(?:[.\[].+?)?)\s*=\s*(.*?)\s*$/i', $line, $m)) {
                continue;
            }
            $key = trim((string) $m[1]);
            $value = trim((string) $m[2]);
            if (preg_match('/password|user(name)?|secret|token|key|nonce|auth|serial|macaddress|private/i', $key)) {
                continue;
            }
            if (!preg_match('/Enable|ObjectType|Human|Sensitivity|Threshold|Region|Points?|Polygon|Area|Coordinates?|Line|Direction|Scene|Class|Rule|Type|Name|Action|TimeSection|Schedule|Filter|MinSize|MaxSize|Object|Period|AntiDither|Target/i', $key)) {
                continue;
            }
            $value = substr(preg_replace('/[^\x20-\x7E\x{00A0}-\x{024F}]/u', '?', $value) ?? '', 0, 120);
            $matched[] = $key . '=' . $value;
            if (preg_match('/\.Enable$/i', $key)) {
                $enabled[] = $key . '=' . $value;
            }
            if (preg_match('/Region|Polygon|Area|Points?|Coordinates?|Line/i', $key)) {
                $detectorValues['region']++;
            }
            if (preg_match('/TimeSection|Period|Schedule/i', $key)) {
                $detectorValues['schedule']++;
            }
            if (preg_match('/ObjectType|Human|Class/i', $key)) {
                $detectorValues['class']++;
            }
        }

        $out = [
            'Echte CGI-Konfigurationsfelder: ' . count($matched)
                . ' | Bereich/Polygon-Felder=' . $detectorValues['region']
                . ' | Zeitplan-Felder=' . $detectorValues['schedule']
                . ' | Objektklassen-Felder=' . $detectorValues['class']
        ];
        if ($matched === []) {
            $out[] = 'KEINE erkennbaren ' . $table . '-Felder geliefert – keine Annahme über aktivierte KI treffen.';
            return $out;
        }
        foreach (array_slice($matched, 0, max(1, min(100, $limit))) as $entry) {
            $out[] = '  ' . $entry;
        }
        if (count($matched) > $limit) {
            $out[] = '  ... ' . (count($matched) - $limit) . ' weitere Felder nicht ausgegeben.';
        }

        if ($table === 'MotionDetect') {
            $out[] = 'MODELL-PRÜFPUNKT: MD.Enable UND MD-Erfassungsfläche UND Zeitplan überprüfen;';
            $out[] = 'SMD 3.0 setzt bei diesem Dahua-Webmodus konfigurierte Motion Detection voraus.';
        } elseif ($table === 'SmartMotionDetect') {
            $out[] = 'MODELL-PRÜFPUNKT: SmartMotionDetect[0].Enable, Human-Objektfilter, Sensitivity prüfen.';
        } elseif ($table === 'VideoAnalyseRule') {
            $out[] = 'MODELL-PRÜFPUNKT: Eine Regel-ID OHNE aktivierte/gezeichnete IVS-Fläche';
            $out[] = 'beweist keine funktionsfähige Erkennung. Enable, Region/Polygon, Human-Filter prüfen.';
        } elseif ($table === 'VideoAnalyseGlobal') {
            $out[] = 'MODELL-PRÜFPUNKT: aktiven IVS-Smart-Plan / Scene-Klasse und Regeln überprüfen.';
        }
        return $out;
    }

    /**
     * Reports only the event header; even unexpected binary/private JSON
     * payload is never copied into diagnostic state.
     */
    public static function wireHeader(string $code, string $action, string $index, int $count): string
    {
        foreach ([$code,$action,$index] as $v) {
            if (!preg_match('/^[A-Za-z0-9_+-]{1,80}$/D', $v)) {
                return '';
            }
        }
        return 'Dahua Code=' . $code . ';action=' . $action . ';index=' . $index . ';gesamt=' . $count;
    }
}
