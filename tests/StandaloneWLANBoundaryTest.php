<?php
declare(strict_types=1);

/**
 * Prevent inadvertent coupling of the separately maintained WLAN Johannes
 * script (#16001, category #10939) into the JV Presence Gate module library.
 * The independently installed WLAN script is NOT controlled by this test.
 *
 * Continuous integration guard only. Does NOT run on the SymBox and
 * does NOT inspect/change/delete WLAN variables or existing objects.
 */
$root=dirname(__DIR__);
$modules=glob($root.'/*/module.php');
if (!is_array($modules) || count($modules)<6) {
    throw new RuntimeException('EXPECTED_MODULES_MISSING');
}
$forbiddenFunctions=[
    'IPS_CreateVariable',
    'IPS_CreateCategory',
];
$forbiddenIdentifiers=[
    'JVWLAN_Johannes',
    'JVWLAN_Standalone',
    'JVWLAN_PollEvent',
];
$inspected=0;
foreach($modules as $path) {
    $source=file_get_contents($path);
    if(!is_string($source)) {
        throw new RuntimeException('UNREADABLE_MODULE:'.basename(dirname($path)));
    }
    $inspected++;
    foreach($forbiddenFunctions as $fn) {
        if(preg_match('/\\b'.preg_quote($fn,'/').'\\s*\\(/i',$source)===1) {
            throw new RuntimeException(
                'STANDALONE_WLAN_CREATION_FORBIDDEN:'.$path.':'.$fn
            );
        }
    }
    foreach($forbiddenIdentifiers as $marker) {
        if(strpos($source,$marker)!==false) {
            throw new RuntimeException(
                'STANDALONE_WLAN_REFERENCE_FORBIDDEN:'.$path.':'.$marker
            );
        }
    }
}
// Identity Shadow is the only new module used in this conversation.
// It must not silently enable WLAN even if future updates modify
// other local-presence logic.
$shadow=file_get_contents(
    $root.'/JVPresenceIdentityShadow/module.php');
if(!is_string($shadow)
    || !str_contains(
        $shadow,
        "RegisterPropertyBoolean('EnablePhoneIntegration',false)"
    )) {
    throw new RuntimeException('SHADOW_WLAN_MUST_DEFAULT_TO_OFF');
}
preg_match_all(
    "/RegisterVariable(?:Boolean|Integer|Float|String)\\s*\\(\\s*'([^']+)'/",
    $shadow,$matches
);
$registered=$matches[1]??[];
sort($registered);
if($registered!==['CarinaPresence','EvidenceJSON','JohannesPresence','PresenceDetected','ResidentTile','Status','TestReport']) {
    throw new RuntimeException(
        'SHADOW_CREATED_ADDITIONAL_VARIABLES:'.implode(',',$registered)
    );
}
echo "Standalone WLAN object boundary PASS: $inspected modules; " .
    "no category/variable creation calls; Shadow 7 variables (five historical plus one tile and one requested copy-paste test report), WLAN disabled.\\n";
