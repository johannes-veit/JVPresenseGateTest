<?php
declare(strict_types=1);
/**
 * JV Phone Wi-Fi bridge for IP-Symcon 9.x.
 *
 * Run once MANUALLY in the Symcon script editor. Creates ONLY one
 * bridge category, two smartphone categories, two String variables and two
 * hidden independent token variables. Registers /hook/jvphonewifi
 * automatically in WebHook Control ONLY if available and not owned by
 * another script; existing hooks are never changed or overwritten.
 *
 * Client POST: {"person":"johannes","ssid":"Intern-Veit","token":"<secret>"}
 * Other valid person: carina. Empty SSID = disconnected; then no location.
 *
 * Never send password, latitude, longitude, Wi-Fi MAC, AP credentials.
 */
$scriptID = (int) ($_IPS['SELF'] ?? 0);
if ($scriptID <= 0) {
    throw new RuntimeException('Kein IP-Symcon-Skriptkontext.');
}

// This script deliberately NEVER registers or overwrites another WebHook.
$parentID = (int) IPS_GetObject($scriptID)['ParentID'];
$getChild = static function (int $parent, string $ident): int {
    // IPS_GetObjectIDByIdent produces E_WARNING for expected first-run
    // misses, even inside try/catch. Inspect direct children instead.
    // This works with Symcon 9.0 without @ error suppression.
    foreach (IPS_GetChildrenIDs($parent) as $candidate) {
        if (!is_int($candidate) || $candidate <= 0) continue;
        $meta = IPS_GetObject($candidate);
        if (($meta['ObjectIdent'] ?? null) === $ident)
            return $candidate;
    }
    return 0;
};
$rootID = $getChild($parentID, 'JVPhoneWifiBridgeRoot');
// IP-Symcon: SENDER=WebHook, plus $_SERVER['HOOK'] on real HTTP requests.
// Only explicitly manual "Execute" calls may initialize the bridge.
$sender = (string) ($_IPS['SENDER'] ?? '');
$isWebhook = $sender === 'WebHook';
// A once-only upgrade of legacy credentials previously printed in setup
// logs. Only explicit editor Execute may rotate. The marker is stored in
// each OWN hidden token object's info, not a new Symcon variable.
$securityMarker = 'JVPHONEWIFI_SECRET_V2_ROTATED_LOCALLY';
if (!$isWebhook && $sender !== 'Execute') {
    echo "Setup verweigert: nur manuell im Editor ausfuehren.\n";
    return;
}
$hookPath = '/hook/jvphonewifi';
$hookControlID = 0;
$hookAlreadyAssigned = false;
$hookCanInstall = false;
$hookProblem = '';
if (!$isWebhook) {
    // Never overwrite an existing foreign hook and never guess the control.
    try {
        $list = IPS_GetInstanceListByModuleID(
            '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}'
        );
        if (!is_array($list) || count($list) !== 1) {
            $hookProblem = 'WebHook-Control nicht eindeutig gefunden';
        } else {
            $hookControlID = (int) $list[0];
            $configured = json_decode(
                (string) IPS_GetProperty($hookControlID, 'Hooks'), true
            );
            if (!is_array($configured) || !array_is_list($configured)) {
                $hookProblem = 'WebHook-Liste unlesbar oder ungueltig';
            } else {
                $hookCanInstall = true;
                foreach ($configured as $row) {
                    if (!is_array($row) || !is_string($row['Hook'] ?? null)
                        || !is_int($row['TargetID'] ?? null)) {
                        $hookCanInstall = false;
                        $hookProblem = 'WebHook-Liste enthaelt ungueltigen Eintrag';
                        break;
                    }
                    if ($row['Hook'] !== $hookPath) continue;
                    if ($row['TargetID'] !== $scriptID) {
                        throw new RuntimeException(
                            'WebHook-Konflikt: /hook/jvphonewifi zeigt bereits auf ein anderes Ziel. Unveraendert!'
                        );
                    }
                    $hookAlreadyAssigned = true;
                }
            }
        }
    } catch (RuntimeException $e) {
        if (str_starts_with($e->getMessage(), 'WebHook-Konflikt:'))
            throw $e;
        $hookCanInstall = false;
        $hookProblem = 'WebHook-Pruefung nicht moeglich';
    } catch (Throwable $ignored) {
        $hookCanInstall = false;
        $hookProblem = 'WebHook-Pruefung nicht moeglich';
    }
}
if (!$isWebhook) {
    // Installer is idempotent. Re-running neither rotates the credentials
    // nor overwrites the current SSID readings.
    if ($rootID === 0) {
        $rootID = IPS_CreateCategory();
        IPS_SetParent($rootID, $parentID);
        IPS_SetName($rootID, 'JV Phone WiFi Bridge');
        IPS_SetIdent($rootID, 'JVPhoneWifiBridgeRoot');
    }
    $ids = [];
    foreach (['johannes' => 'Johannes', 'carina' => 'Carina'] as $key => $name) {
        $ident = 'JVWifiPhone_' . $name;
        $phoneID = $getChild($rootID, $ident);
        if ($phoneID === 0) {
            $phoneID = IPS_CreateCategory();
            IPS_SetParent($phoneID, $rootID);
            IPS_SetName($phoneID, $name . ' Smartphone SSID Quelle');
            IPS_SetIdent($phoneID, $ident);
        }
        $ssidID = $getChild($phoneID, 'JVPhoneWifiCurrentSSID');
        if ($ssidID === 0) {
            $ssidID = IPS_CreateVariable(3); // String
            IPS_SetParent($ssidID, $phoneID);
            IPS_SetName($ssidID, 'WifiSSID');
            IPS_SetIdent($ssidID, 'JVPhoneWifiCurrentSSID');
            SetValue($ssidID, '');
        }
        if ((IPS_GetVariable($ssidID)['VariableType'] ?? null) !== 3) {
            throw new RuntimeException('WifiSSID hat falschen Datentyp: ' . $ssidID);
        }
        $secretID = $getChild($phoneID, 'JVPhoneWifiSharedToken');
        if ($secretID === 0) {
            $secretID = IPS_CreateVariable(3);
            IPS_SetParent($secretID, $phoneID);
            IPS_SetName($secretID, 'Webhook-Schlüssel (privat)');
            IPS_SetIdent($secretID, 'JVPhoneWifiSharedToken');
            SetValue($secretID, bin2hex(random_bytes(24)));
            IPS_SetInfo($secretID, $securityMarker);
        } else {
            // Older installer printed tokens in plain text, which could be
            // copied into tickets/chats/logs. Regenerate those once on the
            // first manual editor invocation after script upgrade. Never
            // silently repeat rotation on every manual re-run or WebHook.
            $tokenObject = IPS_GetObject($secretID);
            if ((int)($tokenObject['ParentID']??0) !== $phoneID
                || (IPS_GetVariable($secretID)['VariableType']??null) !== 3)
                throw new RuntimeException('Privater WebHook-Token ist falsch gebunden');
            if (($tokenObject['ObjectInfo']??null) !== $securityMarker) {
                SetValue($secretID, bin2hex(random_bytes(24)));
                IPS_SetInfo($secretID, $securityMarker);
            }
        }
        IPS_SetHidden($secretID, true);
        $ids[$key] = ['parent' => $phoneID, 'ssid' => $ssidID,
            'secret' => $secretID];
    }
    // Install ONLY the new private hook. Existing list stays unchanged,
    // and same-target re-runs skip ApplyChanges entirely.
    $hookState = 'MANUELL_NOETIG';
    if ($hookCanInstall && $hookAlreadyAssigned) {
        $hookState = 'BEREITS_KORREKT';
    } elseif ($hookCanInstall) {
        $newHooks = $configured;
        $newHooks[] = ['Hook' => $hookPath, 'TargetID' => $scriptID];
        $json = json_encode($newHooks, JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            try {
                IPS_SetProperty($hookControlID, 'Hooks', $json);
                IPS_ApplyChanges($hookControlID);
                $verify = json_decode(
                    (string) IPS_GetProperty($hookControlID, 'Hooks'), true
                );
                foreach (is_array($verify) ? $verify : [] as $row) {
                    if (is_array($row) && ($row['Hook'] ?? null) === $hookPath
                        && ($row['TargetID'] ?? null) === $scriptID)
                        $hookState = 'AUTOMATISCH_EINGERICHTET';
                }
            } catch (Throwable $ignored) {
                $hookProblem = 'Automatische WebHook-Zuordnung fehlgeschlagen';
            }
        }
    }
    echo "Einrichtung erfolgreich. Vorhandene Geofency-/Egi-Hooks bleiben unveraendert.\n\n";
    echo "WEBHOOK: " . $hookState . "\n";
    if ($hookState === 'MANUELL_NOETIG')
        echo "Bitte /hook/jvphonewifi manuell auf Skript #{$scriptID} zuordnen (" . $hookProblem . ").\n";
    echo "Hook-Ziel: /hook/jvphonewifi -> Skript #{$scriptID}\n";
    foreach ($ids as $key => $row) {
        echo strtoupper($key) . "\n";
        echo " Eltern-ID: " . $row['parent'] . "\n";
        echo " WifiSSID-String-ID: " . $row['ssid'] . "\n";
        echo " Geheimtoken-ID (ausgeblendet): " . $row['secret'] . " | Token im lokalen Objekt ablesen, NIEMALS in Chats posten.\n";
    }
    echo "\nSHADOW #32969 erkennt gueltige personenbezogene WifiSSID-Quellen automatisch (bis 5 Min.).\n";
    echo "Keine vier Quell-IDs oder zusaetzlichen SHADOW-Schalter erforderlich.\n";
    echo "Voraussetzung: beide Telefone senden ihre aktuelle SSID regelmaessig per POST.\n";
    echo "Bei Update von altem Skript wurden vorhandene Geheimtokens genau einmal neu erzeugt.\n";
    echo "Auch ein erfolgreiches Hook-Setup allein ist KEIN aktueller WORK-Nachweis.\n";
    return;
}

// Remote updates require HTTPS on the PHONE side (Symcon Connect).
// Never accept GET requests for source mutation.
header('Content-Type: application/json; charset=utf-8');
$reply = static function (int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['ok' => $status === 200, 'result' => $message],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    $reply(405, 'POST_REQUIRED');
    exit;
}
if ($rootID <= 0) {
    $reply(503, 'RUN_LOCAL_SETUP_FIRST');
    exit;
}
$raw = file_get_contents('php://input', false, null, 0, 513);
if (!is_string($raw) || strlen($raw) > 512) {
    $reply(413, 'PAYLOAD_TOO_LARGE');
    exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    $reply(400, 'JSON_REQUIRED');
    exit;
}
$person = $data['person'] ?? null;
$ssid = $data['ssid'] ?? null;
$token = $data['token'] ?? null;
if (!is_string($person) || !in_array($person, ['johannes', 'carina'], true)
    || !is_string($ssid) || strlen($ssid) > 64
    || preg_match('/[\x00-\x1F\x7F]/', $ssid) === 1
    || !is_string($token) || strlen($token) !== 48
    || !ctype_xdigit($token)) {
    $reply(400, 'INVALID_FIELDS');
    exit;
}
$name = $person === 'johannes' ? 'Johannes' : 'Carina';
$phoneID = $getChild($rootID, 'JVWifiPhone_' . $name);
if ($phoneID <= 0 || IPS_GetName($phoneID) !== $name . ' Smartphone SSID Quelle') {
    $reply(503, 'DEVICE_CONFIGURATION_MISSING');
    exit;
}
$secretID = $getChild($phoneID, 'JVPhoneWifiSharedToken');
$ssidID = $getChild($phoneID, 'JVPhoneWifiCurrentSSID');
if (!$secretID || !$ssidID) {
    $reply(503, 'SOURCE_CONFIGURATION_MISSING');
    exit;
}
$expected = GetValue($secretID);
if (!is_string($expected) || !hash_equals($expected, $token)) {
    $reply(403, 'AUTH_FAILED');
    exit;
}
if ((IPS_GetVariable($ssidID)['VariableType'] ?? null) !== 3
    || IPS_GetName($ssidID) !== 'WifiSSID'
    || (int) IPS_GetObject($ssidID)['ParentID'] !== $phoneID) {
    $reply(503, 'SOURCE_CHANGED');
    exit;
}
// SetValue updates VariableUpdated even if SSID did not change, which
// provides the timestamp required by SHADOW's 15-minute freshness rule.
SetValue($ssidID, $ssid);
$reply(200, 'ACCEPTED');
