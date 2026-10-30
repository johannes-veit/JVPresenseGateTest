<?php
/** JV Schiebetor: bewusst kleiner, idempotenter Installer fuer Symcon 9.0.
 * Nur GPS-Vorschau -> VISU; niemals Torbefehle oder bestehende GPS-Hooks.
 */
declare(strict_types=1);

final class JVSchiebetorAutoInstaller
{
    private const ROOT = 29252;
    private const DECIDER = 30894;
    private const OLD_BRIDGE = 43393;
    private const VISU = 56236;
    private const GATE = 21149;
    private const TOKEN_IDENT = 'JVSchiebetorVisuToken';
    private const SCRIPT_IDENT = 'JVSchiebetorVisuBridge';
    private const HOOK = '/hook/schiebetor-kachel';
    private const CONTROL_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    private static function assertType(int $id, int $type): void
    {
        if (!IPS_ObjectExists($id)
            || (int)(IPS_GetObject($id)['ObjectType'] ?? -1) !== $type) {
            throw new RuntimeException('VORAUSSETZUNG_FEHLT_#'.$id);
        }
    }
    private static function childWithIdent(int $parent, string $ident): int
    {
        foreach (IPS_GetChildrenIDs($parent) as $id) {
            if (($obj=IPS_GetObject($id)) && ($obj['ObjectIdent'] ?? '')===$ident)
                return (int)$id;
        }
        return 0;
    }
    /** @return array{0:int,1:list<array<string,mixed>>,2:bool,3:int|null} */
    private static function findHook(int $script): array
    {
        $controls=IPS_GetInstanceListByModuleID(self::CONTROL_GUID);
        if (!is_array($controls) || count($controls)!==1)
            throw new RuntimeException('WEBHOOK_CONTROL_NICHT_EINDEUTIG');
        $control=(int)$controls[0];
        $hooks=json_decode((string)IPS_GetProperty($control,'Hooks'),true);
        if (!is_array($hooks) || !array_is_list($hooks))
            throw new RuntimeException('WEBHOOK_KONFIGURATION_UNLESBAR');
        $exists=false;$orphan=null;
        foreach ($hooks as $index=>$row) {
            if (!is_array($row) || !is_string($row['Hook'] ?? null)
                || !is_int($row['TargetID'] ?? null))
                throw new RuntimeException('WEBHOOK_KONFIGURATION_UNGUELTIG');
            if ($row['Hook']!==self::HOOK) continue;
            if ($exists || $orphan!==null)
                throw new RuntimeException('WEBHOOK_DOPPELT');
            if ($row['TargetID']===$script) $exists=true;
            elseif ($row['TargetID']===self::OLD_BRIDGE
                    && !IPS_ObjectExists(self::OLD_BRIDGE)) $orphan=$index;
            else throw new RuntimeException('WEBHOOK_FREMD_BEREITS_BELEGT');
        }
        return [$control,$hooks,$exists,$orphan];
    }
    private static function recognized(string $old): bool
    {
        // Keine fremden oder manuell erweiterten Skripte ungeprueft ersetzen.
        if (str_contains($old,'JV_GATE_BRIDGE_MANAGED_V18')) return true;
        if (!str_contains($old,'Schiebetor') || !str_contains($old,'Visu-Bruecke'))
            return false;
        return str_contains($old,"const STV_VERSION = '1.3'")
            || str_contains($old,"const STV_VERSION = '1.7'");
    }
    private static function backup(int $script, string $old): void
    {
        if (trim($old)==='') return;
        $folder=rtrim(IPS_GetKernelDir(),'/\\').'/user/schiebetor';
        if (!is_dir($folder) && !@mkdir($folder,0750,true) && !is_dir($folder))
            throw new RuntimeException('BACKUP_ORDNER_FEHLT');
        $path=$folder.'/bridge_backup_'.$script.'_'.substr(hash('sha256',$old),0,12).'.php.txt';
        if (is_file($path)) return;
        if (@file_put_contents($path,$old,LOCK_EX)!==strlen($old))
            throw new RuntimeException('BRIDGE_BACKUP_FEHLGESCHLAGEN');
    }
    public static function install(string $sourcePath): string
    {
        try {
            self::assertType(self::ROOT,0);
            self::assertType(self::DECIDER,3);
            self::assertType(self::VISU,1);
            self::assertType(self::GATE,0);
            $decider=IPS_GetScriptContent(self::DECIDER);
            if (!str_contains($decider,"const V11_VERSION = '1.6.2-SHADOW'"))
                return 'NICHT_BEREIT: Entscheider #30894 ist nicht v1.6.2. Keine Aenderung.';
            $desired=@file_get_contents($sourcePath);
            if (!is_string($desired)
                || !str_contains($desired,'JV_GATE_BRIDGE_MANAGED_V18'))
                throw new RuntimeException('QUELLCODE_FEHLT');
            $script=0;$new=false;
            if (IPS_ObjectExists(self::OLD_BRIDGE)) {
                self::assertType(self::OLD_BRIDGE,3);
                if ((int)IPS_GetParent(self::OLD_BRIDGE)!==self::ROOT)
                    throw new RuntimeException('BRIDGE_PARENT_UNERWARTET');
                $script=self::OLD_BRIDGE;
            } else {
                $script=self::childWithIdent(self::ROOT,self::SCRIPT_IDENT);
                if ($script) self::assertType($script,3);
                else $new=true;
            }
            $old=$script?IPS_GetScriptContent($script):'';
            if (!$new && $old!==$desired && !self::recognized($old))
                return 'KONFLIKT: Bridge-Skript wurde fremd geaendert; nicht ueberschrieben.';
            // Vor dem ersten Schreibzugriff gesamten Hook Control validieren.
            // Neues Skript hat noch keine ID; bestehende Zielzuordnung muss frei sein.
            $knownScript=$new ? -1 : $script;
            [$control,$hooks,$already,$orphan]=self::findHook($knownScript);
            if ($new) {
                $script=IPS_CreateScript(0);
                IPS_SetParent($script,self::ROOT);
                IPS_SetName($script,'Schiebetor Visu-Bruecke');
                IPS_SetIdent($script,self::SCRIPT_IDENT);
            }
            if ($old!==$desired) {
                if (!$new) self::backup($script,$old);
                if (!IPS_SetScriptContent($script,$desired))
                    throw new RuntimeException('BRIDGE_UPDATE_FEHLER');
            }
            $token=self::childWithIdent(self::ROOT,self::TOKEN_IDENT);
            if ($token) {
                self::assertType($token,2);
                if ((int)(IPS_GetVariable($token)['VariableType']??-1)!==3)
                    throw new RuntimeException('TOKEN_FALSCHER_DATENTYP');
                if (strlen((string)GetValue($token))<40)
                    throw new RuntimeException('TOKEN_LEER_ODER_UNGUELTIG');
            } else {
                $token=IPS_CreateVariable(3);
                IPS_SetParent($token,self::ROOT);
                IPS_SetName($token,'Schiebetor Automate Schluessel (privat)');
                IPS_SetIdent($token,self::TOKEN_IDENT);
                SetValue($token,bin2hex(random_bytes(32)));
            }
            IPS_SetHidden($token,true);
            if (!$already) {
                if ($orphan!==null) $hooks[$orphan]['TargetID']=$script;
                else $hooks[]=['Hook'=>self::HOOK,'TargetID'=>$script];
                IPS_SetProperty($control,'Hooks',json_encode($hooks,JSON_UNESCAPED_SLASHES));
                IPS_ApplyChanges($control);
            }
            [$againControl,$verified,$present]=self::findHook($script);
            if (!$present || $againControl!==$control)
                throw new RuntimeException('WEBHOOK_NICHT_BESTAETIGT');
            return 'BEREIT: Schiebetor-Bridge #'.$script.', Hook '.self::HOOK.
                ', privater Automate-Schluessel in versteckter Variable #'.$token.
                '. Noch Automate auf Xiaomi ergaenzen.';
        } catch (Throwable $e) {
            // Kein vertraulicher Inhalt ausgeben.
            return 'INSTALLATION_GESTOPPT: '.$e->getMessage();
        }
    }
}
