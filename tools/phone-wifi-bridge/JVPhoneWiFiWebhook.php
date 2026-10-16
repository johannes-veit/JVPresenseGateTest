<?php
declare(strict_types=1);
/**
 * JV Phone Wi-Fi bridge for IP-Symcon 9.x.
 *
 * Run once MANUALLY in script editor to create ONLY a dedicated category,
 * two named smartphone categories, two String variables and two hidden
 * token variables. Assign THIS SAME script as WebHook Control target for
 * /hook/jvphonewifi (manual single hook setup; existing hooks untouched).
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
    try {
        $candidate = IPS_GetObjectIDByIdent($ident, $parent);
        return is_int($candidate) && $candidate > 0 ? $candidate : 0;
    } catch (Throwable $ignored) {
        return 0;
    }
};
$rootID = $getChild($parentID, 'JVPhoneWifiBridgeRoot');
$isWebhook = isset($_SERVER['HOOK']) && is_string($_SERVER['HOOK']);

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
        }
        IPS_SetHidden($secretID, true);
        $ids[$key] = ['parent' => $phoneID, 'ssid' => $ssidID,
            'secret' => $secretID];
    }
    echo "Einrichtung erfolgreich. Vorhandene Geofency-/Egi-Hooks bleiben unverändert.\n\n";
    echo "WebHook Control: /hook/jvphonewifi -> dieses Skript #{$scriptID}\n";
    foreach ($ids as $key => $row) {
        echo strtoupper($key) . "\n";
        echo " Eltern-ID: " . $row['parent'] . "\n";
        echo " WifiSSID-String-ID: " . $row['ssid'] . "\n";
        echo " Token (geheim, NICHT in Chat/Screenshots teilen): " . GetValue($row['secret']) . "\n";
    }
    echo "\nIm SHADOW #32969: vier Quell-IDs zuordnen, dann EnableWorkWiFiHints einschalten.\n";
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
