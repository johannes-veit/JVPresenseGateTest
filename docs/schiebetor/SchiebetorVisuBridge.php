<?php
/** JV_GATE_BRIDGE_MANAGED_V18
 * Nur Visualisierung; niemals Tor-Aktionsskripte ausfuehren.
 * Installiert durch JVPresenceIdentityShadow #32969.
 */
declare(strict_types=1);

const JVGB_VERSION = '1.8.0';
const JVGB_ROOT = 29252;
const JVGB_DECIDER = 30894;
const JVGB_VISU = 56236;
const JVGB_GATE = 21149;
const JVGB_TOKEN_IDENT = 'JVSchiebetorVisuToken';
const JVGB_STATE = 'user/schiebetor/monitor_v11_state.json';
const JVGB_ACK = 'user/schiebetor/bridge_v18_ack.json';

function jvgbPath(string $name): string {
    return rtrim(IPS_GetKernelDir(), '/\\') . '/' . $name;
}
function jvgbRead(string $name, int $limit): ?array {
    $path = jvgbPath($name);
    if (!is_file($path) || !is_readable($path) || @filesize($path) > $limit) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || strlen($raw) > $limit) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}
/**
 * Bereits im urspruenglichen, lokal betriebenen GPS-Empfaenger konfigurierter
 * Schluessel. Wird NICHT in GitHub oder neuen Symcon-Einstellungen gespeichert.
 * Nur autorisiert, die Tor-Bedienseite anzuzeigen; nie einen Torbefehl.
 */
function jvgbExistingGpsToken(): string {
    if (!IPS_ObjectExists(25009)
        || (int)(IPS_GetObject(25009)['ObjectType'] ?? -1)!==3)
        return '';
    $php=IPS_GetScriptContent(25009);
    if (!is_string($php) || strlen($php)>100000)
        return '';
    if (!preg_match("/\\bconst\\s+SGT_TOKEN\\s*=\\s*'([a-fA-F0-9]{40,128})'\\s*;/", $php, $match))
        return '';
    return (string)$match[1];
}
function jvgbReply(int $code, string $value): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo $value;
}
function jvgbToken(): string {
    if (!IPS_ObjectExists(JVGB_ROOT)) return '';
    foreach (IPS_GetChildrenIDs(JVGB_ROOT) as $id) {
        $obj = IPS_GetObject($id);
        if (($obj['ObjectIdent'] ?? '') === JVGB_TOKEN_IDENT
            && (int)($obj['ObjectType'] ?? -1) === 2
            && (int)(IPS_GetVariable($id)['VariableType'] ?? -1) === 3)
            return (string)GetValue($id);
    }
    return '';
}
function jvgbReady(int $now, int $age): array {
    $no = static fn(): array => ['ready'=>false,'id'=>''];
    if (!IPS_ObjectExists(JVGB_DECIDER) || !IPS_ObjectExists(JVGB_VISU)
        || !IPS_ObjectExists(JVGB_GATE)) return $no();
    if ((int)(IPS_GetObject(JVGB_DECIDER)['ObjectType'] ?? -1)!==3
        || (int)(IPS_GetObject(JVGB_VISU)['ObjectType'] ?? -1)!==1
        || (int)(IPS_GetObject(JVGB_GATE)['ObjectType'] ?? -1)!==0) return $no();
    $s = jvgbRead(JVGB_STATE, 12000);
    if (($s['schema'] ?? 0)!==1) return $no();
    $at = (int)($s['v16_preview_at'] ?? 0);
    $ep = (int)($s['v16_preview_last_episode'] ?? 0);
    $seen = (int)($s['last_seen_at'] ?? 0);
    $fix = (int)($s['last_ts'] ?? 0);
    if ($at<1 || $ep<1 || $at>$now || $now-$at>$age
        || $seen<1 || $seen>$now+5 || $now-$seen>28
        || $fix<1 || $fix>$now+5 || $now-$fix>28
        || (int)($s['v16_preview_count'] ?? 0)<1
        || !in_array((string)($s['v16_preview_last_side'] ?? ''),
                     ['OSTSEITE','WESTSEITE'],true)) return $no();
    return ['ready'=>true,'id'=>$at.':'.$ep];
}
function jvgbAck(string $id, int $now): bool {
    $path = jvgbPath(JVGB_ACK);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir,0750,true) && !is_dir($dir)) return false;
    $raw = json_encode(['id'=>$id,'at'=>$now],JSON_UNESCAPED_SLASHES);
    if (!is_string($raw)) return false;
    $tmp = $path.'.'.getmypid().'.tmp';
    if (@file_put_contents($tmp,$raw,LOCK_EX)!==strlen($raw)
        || !@rename($tmp,$path)) { @unlink($tmp); return false; }
    return true;
}
function jvgbMain(): void {
    global $_IPS;
    $sender = (string)($_IPS['SENDER'] ?? '');
    if ($sender === 'Execute') {
        echo "Schiebetor-Visu-Bruecke ".JVGB_VERSION." | kein Torbefehl\n";
        echo "Hook: /hook/schiebetor-kachel | Token lokal in Kategorie #".JVGB_ROOT."\n";
        echo "Entscheider frisches Ereignis: ".(jvgbReady(time(),18)['ready']?'JA':'NEIN')."\n";
        return;
    }
    if ($sender !== 'WebHook') return;
    $private = jvgbToken();
    $legacy = jvgbExistingGpsToken();
    $given = (string)($_SERVER['HTTP_X_SCHIEBETOR_KEY'] ?? '');
    $verified = (strlen($private)>=40 && strlen($given)===strlen($private)
        && hash_equals($private,$given));
    if (!$verified && strlen($legacy)>=40 && strlen($given)===strlen($legacy))
        $verified=hash_equals($legacy,$given);
    // Leerkoerper bei ALLEM ausser OPEN: Automate-Boolean prueft nur
    // einen positiven OPEN-Text; Fehlermeldungen duerfen nie App-Start
    // ausloesen.
    if (!$verified) { jvgbReply(403,'');return; }
    $action=(string)($_GET['action'] ?? 'poll');
    $method=(string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!(($action==='poll' && $method==='GET')
        || ($action==='show' && $method==='POST'))) {
        jvgbReply(405,''); return;
    }
    $fresh=jvgbReady(time(),$action==='poll'?18:30);
    if (!$fresh['ready']) {jvgbReply(204,'');return;}
    if (!IPS_SemaphoreEnter('JVSchiebetorBridge_v18',1000)) {
        jvgbReply(503,'');return;
    }
    try {
        $ack=jvgbRead(JVGB_ACK,512);
        if (($ack['id'] ?? '') === $fresh['id']) {jvgbReply(204,'');return;}
        if ($action==='poll') {jvgbReply(200,'OPEN');return;}
        $again=jvgbReady(time(),30);
        if (!$again['ready'] || $again['id']!==$fresh['id']) {
            jvgbReply(200,'WAIT');return;
        }
        // Oeffnet ausschliesslich die vorhandene Tor-Kategorie in der
        // bestehenden Visualisierung; keine Toraktion oder Motoransteuerung.
        if (VISU_OpenObject(JVGB_VISU,JVGB_GATE,'')!==true) {
            jvgbReply(503,'');return;
        }
        if (!jvgbAck($fresh['id'],time())) {jvgbReply(503,'');return;}
        jvgbReply(200,'SHOWN');
    } catch (Throwable $ignored) {
        jvgbReply(503,'');
    } finally {
        IPS_SemaphoreLeave('JVSchiebetorBridge_v18');
    }
}
jvgbMain();
