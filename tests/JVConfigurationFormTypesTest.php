<?php
declare(strict_types=1);

/**
 * IP-Symcon 9 configuration forms reject unsupported element types.
 * Validate ALL module forms (including nested ExpansionPanel/List items)
 * before allowing any release. In particular "TextBox" is NOT supported:
 * a String property is configured via "ValidationTextBox".
 */
$root=dirname(__DIR__);
$valid=array_fill_keys([
    'Button','CheckBox','Configurator','ExpansionPanel','HorizontalSlider',
    'Image','IntervalBox','Label','List','NumberSpinner','PasswordTextBox',
    'PopupButton','RowLayout','ColumnLayout','Select','SelectCategory',
    'SelectColor','SelectDateTime','SelectEvent','SelectFile',
    'SelectInstance','SelectLink','SelectMedia','SelectObject',
    'SelectScript','SelectTime','SelectVariable','Tree','ValidationTextBox'
],true);
$checked=0;$files=0;
$inspect=function(mixed $node,string $where)use(&$inspect,&$checked,$valid):void{
    if(!is_array($node))return;
    if(array_is_list($node)){
        foreach($node as $i=>$child)$inspect($child,$where.'['.$i.']');
        return;
    }
    if(isset($node['type'])){
        if(!is_string($node['type'])||!isset($valid[$node['type']]))
            throw new RuntimeException('SYSCON_UNSUPPORTED_FORM_TYPE at '
                .$where.': '.json_encode($node['type']));
        $checked++;
    }
    foreach($node as $key=>$value){
        // "type" above is the declaration; all nested nodes are inspected.
        if($key==='type')continue;
        if(is_array($value))$inspect($value,$where.'.'.$key);
    }
};
foreach(glob($root.'/*/module.json')?:[] as $modulePath){
    $folder=dirname($modulePath);
    $file=$folder.'/form.json';
    if(!is_file($file))continue;
    $j=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($j)||!isset($j['elements'])||!is_array($j['elements']))
        throw new RuntimeException('SYSCON_INVALID_FORM_ELEMENTS: '.$file);
    $inspect($j,'form:'.basename($folder));
    $files++;
}
if($files<5||$checked<80)
    throw new RuntimeException('SYSCON_FORM_REGRESSION_INCOMPLETE: '
        .$files.' forms, '.$checked.' elements');
$shadow=json_decode((string)file_get_contents(
    $root.'/JVPresenceIdentityShadow/form.json'),true,512,JSON_THROW_ON_ERROR);
$zoneFields=[];
$collect=function(mixed $node)use(&$collect,&$zoneFields):void{
    if(!is_array($node))return;
    if(isset($node['name'])&&in_array($node['name'],[
        'JohannesEgiZoneName','CarinaGeofencyZoneName'
    ],true))$zoneFields[$node['name']]=$node['type']??null;
    foreach($node as $value)if(is_array($value))$collect($value);
};
$collect($shadow);
if($zoneFields!==[
    'JohannesEgiZoneName'=>'ValidationTextBox',
    'CarinaGeofencyZoneName'=>'ValidationTextBox'
])throw new RuntimeException('SHADOW_GEOFENCE_FORM_FIELDS_NOT_COMPATIBLE');
$php=(string)file_get_contents($root.'/JVPresenceIdentityShadow/module.php');
foreach(array_keys($zoneFields) as $name)
    if(!str_contains($php,"RegisterPropertyString('".$name."'"))
        throw new RuntimeException('MISSING_REGISTERED_STRING_PROPERTY: '.$name);
echo 'IP-Symcon configuration form element types PASS ('
    .$files.' forms, '.$checked.' types; two verified geofence string fields)'."\n";
