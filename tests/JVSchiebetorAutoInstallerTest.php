<?php
declare(strict_types=1);
// Standalone Symcon-Mock: keine echte Installation oder Toransteuerung.
require_once dirname(__DIR__).'/libs/JVSchiebetorAutoInstaller.php';
$GLOBALS['g']=[];
function fixture(string $version="const V11_VERSION = '1.6.2-SHADOW';", string $bridge="<?php /* Schiebetor Visu-Bruecke */ const STV_VERSION = '1.3';"): void {
    $GLOBALS['g']=[
        'objects'=>[
            29252=>['ObjectType'=>0,'ParentID'=>0,'ObjectIdent'=>''],
            30894=>['ObjectType'=>3,'ParentID'=>29252,'ObjectIdent'=>''],
            43393=>['ObjectType'=>3,'ParentID'=>29252,'ObjectIdent'=>''],
            56236=>['ObjectType'=>1,'ParentID'=>0,'ObjectIdent'=>''],
            21149=>['ObjectType'=>0,'ParentID'=>0,'ObjectIdent'=>''],
            25009=>['ObjectType'=>3,'ParentID'=>29252,'ObjectIdent'=>'']
        ],
        'scripts'=>[30894=>$version,43393=>$bridge,25009=>'GPS_ORIGINAL_NO_CHANGE'],
        'values'=>[], 'hidden'=>[], 'next'=>60000,
        'properties'=>[1000=>['Hooks'=>'[]']], 'applies'=>0, 'writes'=>[]
    ];
}
function IPS_ObjectExists(int $i): bool {return isset($GLOBALS['g']['objects'][$i]);}
function IPS_GetObject(int $i): array {return $GLOBALS['g']['objects'][$i];}
function IPS_GetParent(int $i): int {return $GLOBALS['g']['objects'][$i]['ParentID'];}
function IPS_GetChildrenIDs(int $i): array {return array_keys(array_filter($GLOBALS['g']['objects'],static fn($x)=>$x['ParentID']===$i));}
function IPS_GetScriptContent(int $i): string {return $GLOBALS['g']['scripts'][$i]??'';}
function IPS_SetScriptContent(int $i,string $s): bool {$GLOBALS['g']['scripts'][$i]=$s;$GLOBALS['g']['writes'][]=$i;return true;}
function IPS_GetInstanceListByModuleID(string $g): array {return [1000];}
function IPS_GetProperty(int $i,string $p): string {return $GLOBALS['g']['properties'][$i][$p];}
function IPS_SetProperty(int $i,string $p,string $v): void {$GLOBALS['g']['properties'][$i][$p]=$v;}
function IPS_ApplyChanges(int $i): void {$GLOBALS['g']['applies']++;}
function IPS_CreateScript(int $t): int {$id=++$GLOBALS['g']['next'];$GLOBALS['g']['objects'][$id]=['ObjectType'=>3,'ParentID'=>0,'ObjectIdent'=>''];$GLOBALS['g']['scripts'][$id]='';return $id;}
function IPS_CreateVariable(int $t): int {$id=++$GLOBALS['g']['next'];$GLOBALS['g']['objects'][$id]=['ObjectType'=>2,'ParentID'=>0,'ObjectIdent'=>''];$GLOBALS['g']['variables'][$id]=['VariableType'=>$t];return $id;}
function IPS_SetParent(int $i,int $p): void {$GLOBALS['g']['objects'][$i]['ParentID']=$p;}
function IPS_SetName(int $i,string $v): void {}
function IPS_SetIdent(int $i,string $v): void {$GLOBALS['g']['objects'][$i]['ObjectIdent']=$v;}
function IPS_GetVariable(int $i): array {return $GLOBALS['g']['variables'][$i]??[];}
function SetValue(int $i,$v): void {$GLOBALS['g']['values'][$i]=$v;}
function GetValue(int $i) {return $GLOBALS['g']['values'][$i]??null;}
function IPS_SetHidden(int $i,bool $v): void {$GLOBALS['g']['hidden'][$i]=$v;}
function IPS_GetKernelDir(): string {return sys_get_temp_dir().'/schiebetor_ci_sandbox/';}
function check(bool $ok,string $name): void {if (!$ok) throw new RuntimeException('FAIL '.$name);echo "PASS {$name}\n";}
$source=dirname(__DIR__).'/docs/schiebetor/SchiebetorVisuBridge.php';
fixture();
$first=JVSchiebetorAutoInstaller::install($source);
check(str_starts_with($first,'BEREIT:'),'Erstinstallation');
check($GLOBALS['g']['scripts'][43393]===file_get_contents($source),'Bridge-Code exakt installiert');
check($GLOBALS['g']['scripts'][25009]==='GPS_ORIGINAL_NO_CHANGE','GPS-Empfang unberuehrt');
$h=json_decode($GLOBALS['g']['properties'][1000]['Hooks'],true);
check(count($h)===1 && $h[0]['TargetID']===43393,'Hook genau einmal');
$tokens=array_keys(array_filter($GLOBALS['g']['objects'],static fn($o)=>$o['ObjectIdent']==='JVSchiebetorVisuToken'));
check(count($tokens)===1 && strlen(GetValue($tokens[0]))===64,'Lokales privates Token');
check($GLOBALS['g']['hidden'][$tokens[0]]===true,'Token verborgen');
$token=GetValue($tokens[0]);
$again=JVSchiebetorAutoInstaller::install($source);
check(str_starts_with($again,'BEREIT:') && $GLOBALS['g']['applies']===1,'Wiederholbar ohne Hook-Reapply');
check(GetValue($tokens[0])===$token,'Token bleibt bei Update stabil');

fixture("const V11_VERSION = '1.6.1-SHADOW';");
$out=JVSchiebetorAutoInstaller::install($source);
check(str_starts_with($out,'NICHT_BEREIT:') && $GLOBALS['g']['writes']===[],'Alte Entscheider-Version verhindert Installation');
fixture("const V11_VERSION = '1.6.2-SHADOW';","<?php echo 'fremdes Skript';");
$out=JVSchiebetorAutoInstaller::install($source);
check(str_starts_with($out,'KONFLIKT:') && $GLOBALS['g']['writes']===[],'Fremdskript nicht ueberschreiben');
fixture();
$GLOBALS['g']['properties'][1000]['Hooks']=json_encode([['Hook'=>'/hook/schiebetor-kachel','TargetID'=>12345]]);
$out=JVSchiebetorAutoInstaller::install($source);
check(str_starts_with($out,'INSTALLATION_GESTOPPT: WEBHOOK_FREMD') && $GLOBALS['g']['writes']===[],'Fremden Hook nicht ueberschreiben');
echo "ALLE SCHIEBETOR-INSTALLER-TESTS BESTANDEN\n";
