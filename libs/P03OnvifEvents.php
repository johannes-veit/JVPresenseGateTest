<?php
declare(strict_types=1);

/**
 * Independent ONVIF PullPoint event source. No camera rule reads/writes and
 * never uses the Dahua eventManager.cgi stream as evidence.
 *
 * ONVIF "RuleEngine/LineDetector/Crossed" is a crossing observation; a
 * human transfer additionally REQUIRES a positive Human classification and
 * a named staircase rule. An unlabeled crossing is only a diagnostic hint.
 */
final class P03OnvifEvents
{
    /** @return array<int,array<string,mixed>> */
    public static function parseNotifications(string $xml): array
    {
        if (!class_exists('DOMDocument') || strlen($xml) > 1024 * 1024) {
            return [];
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!@$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                return [];
            }
            $xp = new DOMXPath($doc);
            $nodes = $xp->query('//*[local-name()="NotificationMessage"]');
            if ($nodes === false) {
                return [];
            }
            $events = [];
            foreach ($nodes as $node) {
                if (!($node instanceof DOMElement)) {
                    continue;
                }
                $topic = self::firstNodeText($xp, './*[local-name()="Topic"]', $node);
                $message = $xp->query('.//*[local-name()="Message"]', $node)->item(0);
                if (!($message instanceof DOMElement)) {
                    continue;
                }
                $source = [];
                $data = [];
                foreach (['Source'=>'source','Data'=>'data'] as $segment=>$target) {
                    $items = $xp->query('.//*[local-name()="' . $segment . '"]/*[local-name()="SimpleItem"]', $message);
                    if ($items === false) {
                        continue;
                    }
                    foreach ($items as $item) {
                        if (!($item instanceof DOMElement)) {
                            continue;
                        }
                        $key = trim($item->getAttribute('Name'));
                        if ($key === '' || strlen($key) > 100) {
                            continue;
                        }
                        if ($target === 'source') {
                            $source[$key] = substr($item->getAttribute('Value'), 0, 160);
                        } else {
                            $data[$key] = substr($item->getAttribute('Value'), 0, 160);
                        }
                    }
                }
                $msg = $xp->query('.//*[local-name()="Message"]', $node)->item(0);
                $events[] = [
                    'topic' => $topic,
                    'utc' => $msg instanceof DOMElement ? $msg->getAttribute('UtcTime') : '',
                    'operation' => $msg instanceof DOMElement ? $msg->getAttribute('PropertyOperation') : '',
                    'source' => $source,
                    'data' => $data
                ];
                if (count($events) >= 100) {
                    break;
                }
            }
            return $events;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function firstNodeText(DOMXPath $xp, string $query, DOMNode $base): string
    {
        $node = $xp->query($query, $base)->item(0);
        return $node ? substr(trim((string)$node->textContent), 0, 220) : '';
    }

    /** @param array<string,mixed> $event */
    public static function isHumanStairCrossing(array $event): bool
    {
        $topic = strtolower((string)($event['topic'] ?? ''));
        if (!str_contains($topic, 'ruleengine/linedetector/crossed')) {
            return false;
        }
        if (in_array(strtolower((string)($event['operation'] ?? '')),
            ['initialized', 'deleted'], true)) {
            return false;
        }
        $src = is_array($event['source'] ?? null) ? $event['source'] : [];
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $rule = trim((string)($src['Rule'] ?? $src['RuleName'] ?? ''));
        if ($rule === '' || !preg_match('/trepp/i', $rule)
            || preg_match('/strasse|straße|street/i', $rule)) {
            return false;
        }
        // ONVIF's standard Crossed notification generally contains only an
        // ObjectId (NO human class). That is INSUFFICIENT because both humans
        // and vehicles are permitted by the user-defined camera rule.
        $classification = self::extractObjectClassification($data);
        if (!in_array($classification, ['human','person','pedestrian'], true)) {
            return false;
        }
        $state = strtolower(trim((string)($data['State'] ?? 'true')));
        return !in_array($state, ['false','0','off','stop'],true);
    }

    /** @param array<string,mixed> $event */
    public static function summarize(array $event): array
    {
        $source = is_array($event['source'] ?? null) ? $event['source'] : [];
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        return [
            'topic'=>substr((string)($event['topic']??''),0,120),
            'utc'=>substr((string)($event['utc']??''),0,40),
            'operation'=>substr((string)($event['operation']??''),0,40),
            'rule'=>substr((string)($source['Rule']??$source['RuleName']??''),0,100),
            'objectType'=>substr(self::extractObjectClassification($data),0,50),
            'classRaw'=>substr((string)($data['ClassTypes']??$data['ClassType']??$data['ObjectType']??''),0,80),
            'objectId'=>substr((string)($data['ObjectId']??$data['ObjectID']??''),0,40),
            'humanStairCrossing'=>self::isHumanStairCrossing($event)
        ];
    }

    /**
     * Real ONVIF Analytics can use ClassTypes (plural), while vendors
     * sometimes use ObjectType, TargetType, ClassType or ObjectClass.
     * Reject contradictory/multi-object classifications (Human,Vehicle)
     * rather than guessing one of several object classes.
     *
     * @param array<string,mixed> $data
     */
    public static function extractObjectClassification(array $data): string
    {
        $normalized=[];
        foreach (['ClassTypes','ObjectType','ObjectClass','TargetType','ClassType'] as $key) {
            foreach ($data as $actualKey=>$value) {
                if (strcasecmp((string)$actualKey,$key)!==0 || !is_scalar($value)) {
                    continue;
                }
                $raw=strtolower(trim((string)$value));
                if ($raw==='') {
                    continue;
                }
                // Human, Vehicle; Human|Vehicle; whitespace separated,
                // or XML list typed as "Human". Never accept mixed classes.
                $types=preg_split('/[\s,;|]+/u',$raw,-1,PREG_SPLIT_NO_EMPTY);
                if (!is_array($types)) {
                    return '';
                }
                foreach ($types as $type) {
                    $normalized[$type]=true;
                }
            }
        }
        return count($normalized)===1 ? (string)array_key_first($normalized) : '';
    }

    /**
     * Dahua may recycle ObjectId and timestamps, so a previously seen event
     * must never remain permanently blocked. Remove expired keys BEFORE
     * checking the new notification.
     *
     * @param array<string,mixed> $summary
     * @param array<string,int> $seen
     */
    public static function isFresh(array $summary,array &$seen,int $now,int $seconds=3): bool
    {
        $seconds=max(1,min(10,$seconds));
        foreach ($seen as $id=>$stamp) {
            if (!is_numeric($stamp) || (int)$stamp>$now || $now-(int)$stamp >= $seconds) {
                unset($seen[$id]);
            }
        }
        $key=sha1(json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'');
        if (isset($seen[$key])) {
            return false;
        }
        $seen[$key]=$now;
        if (count($seen)>200) {
            asort($seen,SORT_NUMERIC);
            $seen=array_slice($seen,-200,null,true);
        }
        return true;
    }

    /** Only allow same camera host to prevent ONVIF XAddr SSRF or LAN pivot. */
    public static function verifiedSameHostUrl(string $url, string $host, int $port=80): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme']??''))!=='http'
            || (string)($parts['host']??'')!==$host
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (int)($parts['port']??80)!==$port) {
            return null;
        }
        $path = (string)($parts['path']??'');
        if (!str_starts_with($path, '/onvif/')) {
            return null;
        }
        return $url;
    }
}
