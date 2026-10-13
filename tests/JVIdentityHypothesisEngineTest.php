<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/JVIdentityHypothesisEngine.php';

$tests = 0;
function check($actual, $expected, string $name): void {
    global $tests;
    $tests++;
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL $name: ".var_export($actual,true)." !== ".var_export($expected,true)."\n");
        exit(1);
    }
}
function proof(): array {
    return ['human'=>true,'boundary'=>true,'direction'=>true,
        'count'=>true,'continuity'=>true,'unique'=>true];
}
function transfer(string $id,int $time,string $from,string $to,int $count=1,
                  ?array $verified=null): array {
    return ['id'=>$id,'time'=>$time,'type'=>'TRANSFER','from'=>$from,'to'=>$to,
        'count'=>$count,'proof'=>$verified??proof()];
}
function run(array $people,array $events, array $unknown=[],
             bool $baseline=true, array $coverage=[]): array {
    return JVIdentityHypothesisEngine::evaluate(
        $people,$unknown,$coverage ?: ['home'=>true,'house'=>true,'property'=>true],
        $baseline,$events
    );
}
$both=['johannes'=>'HOME','carina'=>'HOME'];

$r=run($both,[]);
check($r['counts']['HOME'],['min'=>2,'max'=>2],'two residents home');
check($r['certificates']['PROPERTY_EMPTY_CERTIFIED'],false,'property occupied');
check($r['authority'],'SHADOW_ONLY','shadow contract');
check($r['personPresenceMayBeChanged'],false,'no publish to person core');
check($r['propertyEmptyMayBePublished'],false,'no publish empty to core');

$r=run($both,[transfer('p03-1',100,'HOME','WORK')]);
check($r['worldCount'],2,'single exit two candidates');
check($r['counts']['HOME'],['min'=>1,'max'=>1],'anonymous exact home count');
check($r['counts']['WORK'],['min'=>1,'max'=>1],'anonymous exact work count');
check($r['people']['johannes']['zone'],'UNKNOWN','cannot guess Johannes');
check($r['people']['carina']['zone'],'UNKNOWN','cannot guess Carina');
check($r['certificates']['PROPERTY_EMPTY_CERTIFIED'],false,'HOME-WORK not away');

$r=run(['johannes'=>'HOME','carina'=>'WORK'],
       [transfer('p03-2',100,'HOME','WORK')]);
check($r['people']['johannes']['zone'],'WORK','only source occupant can be identified');
check($r['people']['carina']['zone'],'WORK','Carina remains at work');

$r=run($both,[transfer('yard',100,'HOME','PROPERTY_OTHER',2),
    transfer('gate',101,'PROPERTY_OTHER','AWAY',2),
    ['id'=>'phone', 'time'=>102,'type'=>'PHONE','personId'=>'johannes',
      'status'=>'PHONE_PROPERTY_INDICATION'],
    ['id'=>'lights','time'=>103,'type'=>'LIGHT']]);
check($r['people']['johannes']['zone'],'AWAY','Johannes away despite phone');
check($r['people']['carina']['zone'],'AWAY','Carina away despite phone');
check($r['certificates']['PROPERTY_EMPTY_CERTIFIED'],true,'both truly left and all routes covered');
check(in_array('PHONE_MAY_BE_LEFT_BEHIND:johannes',$r['warnings'],true),true,'forgotten phone flagged');
check($r['transfersApplied'],2,'only physical proof applied');

$r=run($both,[transfer('yard',100,'HOME','PROPERTY_OTHER',2),
    transfer('gate',101,'PROPERTY_OTHER','AWAY',2)],
    [],false);
check($r['certificates']['PROPERTY_EMPTY_CERTIFIED'],false,'no certified initial inventory');

$r=run($both,[transfer('yard',100,'HOME','PROPERTY_OTHER',2),
    transfer('gate',101,'PROPERTY_OTHER','AWAY',2)],
    [],true,['home'=>true,'house'=>true,'property'=>false]);
check($r['certificates']['PROPERTY_EMPTY_CERTIFIED'],false,'missing exit coverage denies property clear');

$r=run($both,[transfer('yard',100,'HOME','PROPERTY_OTHER',2),
    transfer('gate',101,'PROPERTY_OTHER','AWAY',2),
    ['id'=>'gus', 'time'=>102,'type'=>'ACTIVITY','zone'=>'HOME']]);
check($r['certificates']['HOME_EMPTY_CERTIFIED'],false,'GUS blocks home empty');
check($r['certificates']['PROPERTY_EMPTY_CERTIFIED'],false,'GUS blocks property empty');
check($r['people']['johannes']['zone'],'AWAY','GUS does not fabricate identity');
check($r['unresolvedActivityZones'],['HOME'],'activity zone retained');

$r=run($both,[['id'=>'wifi', 'time'=>10,'type'=>'WIFI'],
    ['id'=>'gate','time'=>11,'type'=>'GATE'],
    ['id'=>'car','time'=>12,'type'=>'VEHICLE'],
    ['id'=>'lights','time'=>13,'type'=>'LIGHT'],
    ['id'=>'quiet','time'=>14,'type'=>'SILENCE'],
    ['id'=>'phone','time'=>15,'type'=>'PHONE','personId'=>'carina',
        'status'=>'PHONE_OUTSIDE_INDICATION']]);
check($r['counts']['HOME'],['min'=>2,'max'=>2],'negative signals never move people');
check($r['transfersApplied'],0,'no negative-signal transitions');

$r=run(['johannes'=>'AWAY','carina'=>'AWAY'],
       [transfer('visitor',100,'AWAY','HOME')]);
check($r['counts']['HOME'],['min'=>1,'max'=>1],'one anonymous arrival');
check($r['worldCount'],3,'arrival could be Johannes Carina or visitor');
check($r['people']['johannes']['zone'],'UNKNOWN','cannot guess returning person');
check($r['people']['carina']['zone'],'UNKNOWN','cannot guess returning person two');

$r=run(['johannes'=>'HOME'],
       [transfer('verified',100,'HOME','AWAY')],
       ['HOME'=>1]);
check($r['worldCount'],2,'unknown resident or Johannes may have left');
check($r['certificates']['HOME_EMPTY_CERTIFIED'],false,'visitor may remain');

$p=proof();$p['human']=false;
$r=run(['johannes'=>'HOME'],[transfer('bad',100,'HOME','AWAY',1,$p)]);
check($r['stopped'],true,'unverified human blocks state');
check($r['people']['johannes']['zone'],'HOME','no transition for bad IVS');
check($r['certificates']['HOME_EMPTY_CERTIFIED'],false,'no certificate after bad IVS');

$r=run(['johannes'=>'HOME'],[
    transfer('dup',100,'HOME','AWAY'),
    transfer('dup',100,'HOME','AWAY')]);
check($r['people']['johannes']['zone'],'AWAY','event replay idempotent');
check($r['transfersApplied'],1,'duplicate not counted');

$r=run(['johannes'=>'HOME'],[
    transfer('dup',100,'HOME','AWAY'),
    transfer('dup',100,'HOME','WORK')]);
check($r['stopped'],true,'conflicting event IDs fail closed');
check($r['certificates']['PROPERTY_EMPTY_CERTIFIED'],false,'no certificate after duplicate conflict');

$r=run(['johannes'=>'HOME'],[
    transfer('later',200,'HOME','AWAY'),
    ['id'=>'earlier','time'=>150,'type'=>'LIGHT']]);
check($r['stopped'],true,'out-of-order source fails closed');
check($r['certificates']['PROPERTY_EMPTY_CERTIFIED'],false,'out-of-order denies empty');

$r=run(['johannes'=>'HOME'],[transfer('one',100,'HOME','WORK')]);
check($r['certificates']['HOME_EMPTY_CERTIFIED'],true,'home empty though work occupied');
check($r['certificates']['PROPERTY_EMPTY_CERTIFIED'],false,'work still property');

$r=run(['johannes'=>'WORK'],[transfer('one',100,'WORK','AWAY')],[],true,
       ['home'=>true,'house'=>true,'property'=>true]);
check($r['certificates']['PROPERTY_EMPTY_CERTIFIED'],true,'property clear with proven exit');

$large=[];
for($i=0;$i<12;$i++) $large['person_'.$i]='HOME';
$r=run($large,[transfer('group',100,'HOME','WORK',6)]);
check($r['stopped'],true,'combinatorial uncertainty fails closed');
check($r['certificates']['PROPERTY_EMPTY_CERTIFIED'],false,'overflow cannot certify');

$r=run(['johannes'=>'AWAY'],[transfer('arrive',100,'AWAY','HOME')],
    ['HOME'=>0]);
check($r['worldCount'],2,'one person outside does not exclude an unknown visitor');

$r=run($both,[transfer('yard',100,'HOME','PROPERTY_OTHER',2),
    transfer('gate',101,'PROPERTY_OTHER','AWAY',2),
    ['id'=>'gus-wanduhr','time'=>102,'type'=>'ACTIVITY','zone'=>'HOME']]);
check($r['certificates']['HOUSE_EMPTY_CERTIFIED'],false,'clock false alarm cannot silently maintain certificate');

echo "Identity hypotheses regression PASS ($tests assertions)\n";
