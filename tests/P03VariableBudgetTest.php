<?php
declare(strict_types=1);
/**
 * Simulated IP-Symcon variable-license budget and migration contracts.
 * No live SymBox calls: tests exact registered Idents and safe retire list.
 */
function budgetCheck(bool $ok, string $what): void {
    if (!$ok) { throw new RuntimeException('P03 VARIABLE BUDGET FAIL: '.$what); }
    echo "OK: ".$what."\n";
}
$root=dirname(__DIR__);
$expected=[
    'JVPresenceP03TerraceTest/module.php'=>[
        'P03CameraStatus','P03ProofState','P03VerifiedTransfers',
        'Result','Protocol','P03ConnectionDiagnosis','ChatGPTTestResult'
    ],
    'JVPresenceP03AuxObserver/module.php'=>[
        'PersonDetected','StreamOK','HumanEventCounter','LastIVSEvent'
    ]
];
foreach ($expected as $path=>$allowed) {
    $src=file_get_contents($root.'/'.$path);
    budgetCheck(is_string($src)&&$src!=='',$path.' exists');
    $begin=strpos($src,'    public function Create(): void');
    $next=strpos($src,'    public function ApplyChanges(): void',$begin);
    budgetCheck($begin!==false&&$next!==false,$path.' Create and ApplyChanges sections');
    $create=substr($src,$begin,$next-$begin);
    $apply=substr($src,$next);
    preg_match_all('/\\$this->RegisterVariable(?:Boolean|Integer|String|Float)\\s*\\(\\s*[\\x27\\x22]([^\\x27\\x22]+)[\\x27\\x22]/',$src,$all);
    preg_match_all('/\\$this->RegisterVariable(?:Boolean|Integer|String|Float)\\s*\\(\\s*[\\x27\\x22]([^\\x27\\x22]+)[\\x27\\x22]/',$create,$createM);
    budgetCheck(array_values(array_unique($all[1]))===$allowed,
        $path.' strictly limits the total number of Symcon variable Idents to '.count($allowed));
    budgetCheck(array_values(array_unique($createM[1]))===$allowed,
        $path.' Create registers exactly the essential output variables');
    budgetCheck(str_contains($apply,'UnregisterVariable(')
        && str_contains($apply,'IPS_GetChildrenIDs(')
        && str_contains($apply,"ObjectType"),
        $path.' retires prior obsolete variable objects on ApplyChanges');
    budgetCheck(!str_contains($src,'IPS_DeleteVariable(') && !str_contains($src,'IPS_DeleteObject('),
        $path.' never performs unsafe direct global object deletion');
}
$main=file_get_contents($root.'/JVPresenceP03TerraceTest/module.php');
$obs=file_get_contents($root.'/JVPresenceP03AuxObserver/module.php');
foreach (['TestActive','CrossingCount','PresenceLastEvent','P03StreetDirectionStatus',
          'P03TerraceInBackupStatus','P03TerraceMonitor'] as $id) {
    budgetCheck(!preg_match('/\\$this->(?:SetValue|GetValue)\\s*\\(\\s*[\\x27\\x22]'.preg_quote($id,'/').'[\\x27\\x22]/',$main),
        'main no longer accesses removed Symcon variable '.$id);
}
budgetCheck(str_contains($main,"RegisterVariableString('ChatGPTTestResult'")
    &&substr_count($main,"RegisterVariableString('ChatGPTTestResult'")===2,
    'one user-requested extra test-report variable with same ident in Create/ApplyChanges');
budgetCheck(str_contains($main,"ReadAttributeInteger('P03CrossingCount')")
    && str_contains($main,"WriteAttributeInteger('P03CrossingCount'"),
    'single walk counter stored internally without sacrificing state machine');
budgetCheck(str_contains($main,'JVP03AUX_ReadP03Diagnostic')
    && str_contains($obs,'public function ReadP03Diagnostic(string $ident): string'),
    'read-only diagnostics served on demand, no observer diagnostic variables');
budgetCheck(str_contains($main,"findAuxVariable($id, 'LastIVSEvent')")
    || str_contains($main,"findAuxVariable($instanceID, 'LastIVSEvent')")
    || str_contains($main,"'LastIVSEvent'"),
    'IVS evidence remains available to directional proof');
echo "P03 VARIABLE BUDGET AND MIGRATION CONTRACT PASSED\n";
