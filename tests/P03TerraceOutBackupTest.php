<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/P03TerraceOutBackup.php';
function outCheck(bool $cond,string $message):void{
    if (!$cond) throw new RuntimeException('OUT FUSION FAILURE: '.$message);
    echo "OK: ".$message."\n";
}
$terrace=['id'=>'t-out','kind'=>'IVS','direction'=>'LeftToRight',
    'ts'=>1000.0,'used'=>false];
$jv=['id'=>'j-stair','source'=>'JV_LEFT','ts'=>1001.0,'used'=>false];
$work=['id'=>'w-stair','source'=>'WORK_LEFT','ts'=>1014.0,'used'=>false];
$r=P03TerraceOutBackup::evaluate([$jv],[$terrace],1006.0);
outCheck($r['status']==='PENDING' && $r['gap']===1.0,
    'Terrasse OUT LeftToRight 19:35:50 -> JV 19:35:51 pending until normal 2mast window ends');
$r=P03TerraceOutBackup::evaluate([$jv],[$terrace],1030.999);
outCheck($r['status']==='PENDING','one-mast JV OUT backup cannot preempt late WORK stair at <30s');
$r=P03TerraceOutBackup::evaluate([$jv],[$terrace],1031.0);
outCheck($r['status']==='VERIFIED'
    && $r['path']==='TERRACE_OUT_IVS_THEN_JV_LEFT_STAIR',
    'Terrasse+JV prove TEST-only OUT after entire 2-camera observation window');
$r=P03TerraceOutBackup::evaluate([$work],[$terrace],1045.0);
outCheck($r['status']==='VERIFIED' && $r['gap']===14.0
    && $r['path']==='TERRACE_OUT_IVS_THEN_WORK_LEFT_STAIR',
    'Terrasse+WORK prove OUT when JV_LEFT misses its stair IVS');
outCheck(P03TerraceOutBackup::evaluate([$jv,$work],[$terrace],1045.0)['status']==='UNKNOWN',
    'all 3 cams: true JV then WORK primary pair blocks optional OUT fallback');
outCheck(P03TerraceOutBackup::evaluate([array_replace($work,['ts'=>1050.0])],
    [$terrace],1080.0)['status']==='VERIFIED',
    'exact 50s terrace->WORK OUT remains available after normal 30s mast waiting period');
outCheck(P03TerraceOutBackup::evaluate([array_replace($work,['ts'=>1050.001])],
    [$terrace],1080.001)['status']==='UNKNOWN',
    '50.001s OUT boundary refused');
outCheck(P03TerraceOutBackup::evaluate([array_replace($jv,['ts'=>1015.0])],
    [$terrace],1045.0)['status']==='VERIFIED',
    'exact 15s terrace->JV OUT accepted after waiting for normal mast');
outCheck(P03TerraceOutBackup::evaluate([array_replace($jv,['ts'=>1015.001])],
    [$terrace],1045.001)['status']==='UNKNOWN',
    '15.001s terrace->JV OUT refused');
foreach([
    ['kind'=>'PERSON','direction'=>null],
    ['kind'=>'IVS','direction'=>'RightToLeft'],
    ['kind'=>'IVS','direction'=>null],
    ['used'=>true]
] as $invalid) {
    outCheck(P03TerraceOutBackup::evaluate([$jv],
        [array_replace($terrace,$invalid)],1031.0)['status']==='UNKNOWN',
        'generic SMD, opposite, unknown and consumed terrace signal never prove OUT: '.json_encode($invalid));
}
outCheck(P03TerraceOutBackup::evaluate([array_replace($jv,['used'=>true])],
    [$terrace],1031.0)['status']==='UNKNOWN',
    'consumed mast Human counter cannot be used in another OUT');
outCheck(P03TerraceOutBackup::evaluate([$jv],[$terrace,
    ['id'=>'t-in','kind'=>'IVS','direction'=>'RightToLeft',
        'ts'=>1001.0,'used'=>false]],1031.0)['status']==='UNKNOWN',
    'opposite terrace IN IVS at same passage vetoes OUT fallback');
outCheck(P03TerraceOutBackup::evaluate([],[$terrace],1031.0)['status']==='UNKNOWN',
    'terrace IVS alone never proves OUT');
outCheck(P03TerraceOutBackup::evaluate([$jv],[],1031.0)['status']==='UNKNOWN',
    'single mast alone never proves OUT');
outCheck(P03TerraceOutBackup::evaluate([$jv],[$terrace],1055.0)['status']==='UNKNOWN',
    'stale single-mast candidate cannot trigger delayed phantom OUT');
echo "P03 3-CAMERA OUT TESTS PASSED\n";
