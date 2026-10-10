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
            // CGI getConfig commonly returns "table.MotionDetect[0].Enable"
            // rather than "MotionDetect[0].Enable". Normalize both forms,
            // including module-wide keys with no channel index.
            if (!preg_match('/^\s*(?:table\.)?(' . $prefix
                . '(?:\[\d+\])*(?:\.[A-Za-z0-9_\[\].-]+)?)\s*=\s*(.*?)\s*$/i',
                $line, $m)) {
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
     * Focused evaluation of every installed VideoAnalyseRule entry.
     * Important: the Dahua table usually contains hundreds of scheduling
     * fields for OFF rules [0][0] before reaching the P03 IVS rule [0][3].
     * Showing first 48 keys loses the only useful real-world diagnosis.
     *
     * @return string[] Safety-filtered information; never exposes login
     * credentials or full raw camera responses.
     */
    public static function summarizeRulePriorities(
        string $body, int $expectedIndex, string $expectedName, int $expectedRuleID
    ): array {
        $rules = [];
        foreach (preg_split('/\r\n|\n|\r/', $body) ?: [] as $line) {
            if (!preg_match('/^\s*(?:table\.)?VideoAnalyseRule\[(\d+)\]\[(\d+)\]\.([A-Za-z0-9_\[\].-]+)\s*=\s*(.*?)\s*$/i',
                    $line, $m)) {
                continue;
            }
            $channel = (int) $m[1];
            $idx = (int) $m[2];
            $key = (string) $m[3];
            if ($channel !== 0 || $idx > 100
                || preg_match('/password|user(name)?|secret|token|nonce|auth|private|serial|macaddress/i', $key)) {
                continue;
            }
            $value = substr(preg_replace('/[^\x20-\x7E\x{00A0}-\x{024F}]/u', '?',
                trim((string) $m[4])) ?? '', 0, 140);
            $rules[$idx][$key] = $value;
        }

        $out = [
            'IVS REGEL-PRIORITÄT (vollständige Kamera-Antwort, keine abgeschnittenen 48 Felder)',
            'Erwartung aus P03-Audit: RuleIndex=' . $expectedIndex
                . ', RuleID=' . $expectedRuleID . ', RuleName=' . $expectedName,
            'Tatsächlich gefundene IVS-Regelindizes: ' . implode(', ', array_keys($rules))
        ];
        if ($rules === []) {
            $out[] = 'NICHT AUSWERTBAR: Kamera lieferte keine zuordenbaren VideoAnalyseRule[0][n]-Einträge.';
            return $out;
        }
        ksort($rules, SORT_NUMERIC);
        foreach ($rules as $idx => $fields) {
            $name = (string) ($fields['Name'] ?? 'NICHT_GELIEFERT');
            $enabled = (string) ($fields['Enable'] ?? 'NICHT_GELIEFERT');
            $type = (string) ($fields['Type'] ?? 'NICHT_GELIEFERT');
            $class = (string) ($fields['Class'] ?? 'NICHT_GELIEFERT');
            $id = (string) ($fields['Id'] ?? $fields['ID'] ?? 'NICHT_GELIEFERT');
            $isTarget = $idx === $expectedIndex || $name === $expectedName;
            $regions = count(array_filter(array_keys($fields), static fn($key) =>
                (bool) preg_match('/Config\.(DetectRegion|Region|DetectLine|Polygon)/i', $key)));
            $humanFields = count(array_filter(array_keys($fields), static fn($key) =>
                (bool) preg_match('/ObjectTypes|Human|\.Class/i', $key)));
            $out[] = 'Regel [0][' . $idx . ']: Enable=' . $enabled
                . ' | Class=' . $class . ' | Type=' . $type
                . ' | Id=' . $id . ' | Name=' . $name
                . ' | DetectRegion/Line-Felder=' . $regions
                . ' | Objektklassen-Felder=' . $humanFields
                . ($isTarget ? ' | << P03-ZIELREGEL >>' : '');
        }

        $idx = $expectedIndex;
        $nameMatch = false;
        if (!isset($rules[$idx])) {
            foreach ($rules as $candidate => $fields) {
                if (($fields['Name'] ?? '') === $expectedName) {
                    $idx = $candidate;
                    $nameMatch = true;
                    break;
                }
            }
        }
        if (!isset($rules[$idx])) {
            $out[] = 'KRITISCH: Erwartete P03-IVS-Regel im vollständigen Kamera-Reply NICHT GEFUNDEN.';
            $out[] = 'Nicht automatisch aktivieren/überschreiben: zuerst Modus und Regelbestand prüfen.';
            return $out;
        }
        $fields = $rules[$idx];
        $out[] = '';
        $out[] = '=== DETAIL P03-REGEL [0][' . $idx . ']'
            . ($nameMatch ? ' (anhand Namen gefunden, Index abweichend)' : '') . ' ===';
        $keys = ['Enable','Class','Type','Name','Id','ID','TrackEnable','Config.Direction',
            'Config.Action','Config.Action[0]','Config.Action[1]'];
        foreach ($keys as $key) {
            $out[] = $key . '=' . (string) ($fields[$key] ?? 'NICHT_GELIEFERT');
        }
        $actualName = (string) ($fields['Name'] ?? '');
        $actualEnable = strtolower((string) ($fields['Enable'] ?? ''));
        $actualType = (string) ($fields['Type'] ?? '');
        $out[] = 'ABGLEICH: RuleName='
            . ($actualName === $expectedName ? 'PASST' : 'WEICHT_AB')
            . ' | Enable=' . ($actualEnable === 'true' ? 'JA' : ($actualEnable === 'false' ? 'NEIN' : 'UNBEKANNT'))
            . ' | Type=' . ($actualType === 'CrossRegionDetection' ? 'PASST' : 'WEICHT_AB_ODER_UNBEKANNT');
        if ($expectedRuleID >= 0) {
            $actualID = $fields['Id'] ?? $fields['ID'] ?? null;
            $out[] = 'ABGLEICH: RuleID=' . ($actualID === null
                ? 'NICHT_GELIEFERT'
                : ((string) $actualID === (string) $expectedRuleID ? 'PASST' : 'WEICHT_AB'));
        }
        $priority = [];
        $other = [];
        $schedule = [];
        foreach ($fields as $key => $value) {
            if (in_array($key, $keys, true)) {
                continue;
            }
            if (preg_match('/TimeSection|Schedule|Period/i', $key)) {
                $schedule[$key] = $value;
                continue;
            }
            if (preg_match('/ObjectTypes|Human|Vehicle|DetectRegion|Region|Polygon|DetectLine|Config\.Action|Config\.Direction|SizeFilter|Filter/i', $key)) {
                $priority[$key] = $value;
                continue;
            }
            if (preg_match('/^Config\.|^EventHandler\.(Message|Log|Record|Snapshot).*Enable$/i', $key)) {
                $other[$key] = $value;
            }
        }
        $out[] = 'Objekt-/Flächen-/Aktionsfilter: ' . count($priority)
            . ' Felder; Zeitplan: ' . count($schedule) . ' Felder';
        if ($priority === []) {
            $out[] = 'ACHTUNG: Keine Objekt-/Erfassungsflächenfelder im CGI-Reply nachweisbar.';
        }
        foreach (array_slice($priority, 0, 65, true) as $key => $value) {
            $out[] = '  ' . $key . '=' . $value;
        }
        if (count($priority) > 65) {
            $out[] = '  ... ' . (count($priority) - 65) . ' weitere Detailfelder (Anzahl erhalten).';
        }
        foreach (array_slice($other, 0, 12, true) as $key => $value) {
            $out[] = '  ' . $key . '=' . $value;
        }
        $enabledSlots = 0;
        foreach ($schedule as $key => $value) {
            if (preg_match('/^1\s+\d\d:\d\d:\d\d-\d\d:\d\d:\d\d$/', $value)) {
                $enabledSlots++;
            }
        }
        $out[] = 'Zeitplan: ' . count($schedule)
            . ' Felder, aktiv markierte Zeitfenster=' . $enabledSlots
            . ' (nur Rohfeldzählung; keine Interpretation der Kamera-Uhrzeit)';
        foreach (array_slice(array_filter($schedule, static fn($value) =>
                    str_starts_with($value, '1 ')), 0, 10, true) as $key => $value) {
            $out[] = '  ' . $key . '=' . $value;
        }
        $out[] = 'Hinweis: IVS-Konfiguration wird hier NUR GELESEN; ein aktiviertes Feld'
            . ' ist kein Beweis eines während Testgangs ausgelösten Human-Ereignisses.';
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
