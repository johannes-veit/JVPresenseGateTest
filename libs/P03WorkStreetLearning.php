<?php
declare(strict_types=1);

require_once __DIR__ . '/P03ExistingIvsLines.php';

/**
 * Distinct STREET boundary on the existing WORK_LEFT camera.
 * Known independently audited Dahua rule: Überquerung zur Straße, ID 5.
 * Stair rule ID 4 remains the ONLY source of the mast HumanEventCounter.
 * Street signals are never property-empty certificates or person identities.
 */
final class P03WorkStreetLearning
{
    private const STREET_NAME = 'Überquerung zur Straße';
    private const STREET_ID = 5;

    /** @param array<string,mixed> $e */
    public static function validStreetStart(array $e): bool
    {
        // Even when the exact street name is present, a Dahua event must
        // carry RuleID=5; stream index or UI labels alone are insufficient.
        $hasVerifiedID=false;
        foreach (['cfgRuleId','ruleIdUpper','ruleIdLower','ruleId'] as $field) {
            $value=$e[$field]??null;
            if ($value===null || $value==='') continue;
            if (!is_numeric($value) || (int)$value!==self::STREET_ID) return false;
            $hasVerifiedID=true;
        }
        if (!$hasVerifiedID) return false;
        if (!P03ExistingIvsLines::matchingHumanStart([
            'code' => $e['code'] ?? null,
            'action' => $e['action'] ?? null,
            'ruleName' => $e['ruleName'] ?? null,
            'cfgRuleId' => $e['cfgRuleId'] ?? null,
            'ruleIdUpper' => $e['ruleIdUpper'] ?? null,
            'ruleIdLower' => $e['ruleIdLower'] ?? null,
            'ruleId' => $e['ruleId'] ?? null,
            'classification' => $e['classification'] ?? null
        ], [[
            'name' => self::STREET_NAME, 'id' => self::STREET_ID,
            'humanOnly' => false, 'objects' => ['human','vehicle']
        ]])) {
            return false;
        }
        return in_array((string)($e['direction'] ?? ''), ['LeftToRight','RightToLeft'],true);
    }

    /**
     * Only dedupe already stored forensic CrossLine events. The Observer
     * publishes the last eight whenever a new CrossLine is received;
     * unchanged old history must not be counted a second time.
     *
     * @param array<int,array<string,mixed>> $history
     * @param array<int,string> $seen
     * @return array{seen:array<int,string>,new:array<int,array<string,mixed>>,rejected:int}
     */
    public static function ingest(array $history, array $seen): array
    {
        $keys = array_fill_keys(array_map('strval',$seen),true);
        $fresh=[];
        $rejected=0;
        foreach ($history as $item) {
            if (!is_array($item)) continue;
            // Always mark STOP and non-street events as seen; they may be
            // part of the rolling buffer but never represent a passage.
            $digest=hash('sha256',json_encode([
                $item['time']??null,$item['code']??null,
                $item['action']??null,$item['ruleName']??null,
                $item['cfgRuleId']??null,$item['ruleIdUpper']??null,
                $item['ruleIdLower']??null,$item['ruleId']??null,
                $item['eventId']??null,$item['classification']??null,
                $item['direction']??null
            ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '');
            if (isset($keys[$digest])) continue;
            $keys[$digest]=true;
            if (!self::validStreetStart($item)) {
                $rejected++;
                continue;
            }
            $fresh[]=[
                'id'=>$digest,
                'direction'=>(string)$item['direction'],
                'time'=>(string)($item['time']??''),
                'ruleId'=>self::STREET_ID,
                'source'=>'WORK_LEFT_STREET'
            ];
        }
        return [
            'seen'=>array_slice(array_keys($keys),-96),
            'new'=>$fresh,
            'rejected'=>$rejected
        ];
    }

    /** @param array<int,array<string,mixed>> $events
     *  @return array{status:string,progress:int,out:?string,in:?string}
     */
    public static function evaluateTraining(array $events): array
    {
        $directions=array_values(array_map(
            static fn(array $e):string=>(string)($e['direction']??''),$events
        ));
        $count=count($directions);
        if ($count<4) {
            return ['status'=>'LEARNING','progress'=>$count,'out'=>null,'in'=>null];
        }
        $d=array_slice($directions,0,4);
        if (!in_array($d[0],['LeftToRight','RightToLeft'],true)
            || $d[0]!==$d[2] || $d[1]!==$d[3] || $d[0]===$d[1]) {
            return ['status'=>'CONTRADICTION','progress'=>4,'out'=>null,'in'=>null];
        }
        return ['status'=>'VERIFIED','progress'=>4,'out'=>$d[0],'in'=>$d[1]];
    }

    /** @param array<string,mixed> $mapping */
    public static function boundaryMessage(array $event,array $mapping): string
    {
        $direction=(string)($event['direction']??'');
        if (($mapping['status']??'')!=='VERIFIED') {
            return 'UNBEKANNT – Straßenrichtung noch nicht eingelernt';
        }
        if ($direction===($mapping['out']??null)) {
            return 'LETZTER STRASSENUEBERTRITT: ARBEIT → AUSSERHALB (kein Grundstueck-leer-Nachweis)';
        }
        if ($direction===($mapping['in']??null)) {
            return 'LETZTER STRASSENUEBERTRITT: AUSSERHALB → ARBEIT (kein Personenzaehlungsnachweis)';
        }
        return 'UNBEKANNT – Richtung nicht zugeordnet';
    }
}
