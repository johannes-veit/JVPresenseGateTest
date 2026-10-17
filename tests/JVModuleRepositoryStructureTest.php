<?php
declare(strict_types=1);

/**
 * IP-Symcon module control does NOT ignore arbitrary repository-root
 * folders. They must either be documented system folders or modules with
 * their own module.json. This regression checks the actual repository.
 */
$repo=dirname(__DIR__);
$allowed=['libs','docs','imgs','tests','actions'];
$modules=[];
foreach(new DirectoryIterator($repo) as $entry){
    if($entry->isDot()||!$entry->isDir()||$entry->isLink())continue;
    $name=$entry->getFilename();
    if(str_starts_with($name,'.')||in_array($name,$allowed,true))continue;
    $modulePath=$entry->getPathname().'/module.json';
    if(!is_file($modulePath)){
        throw new RuntimeException('SYSCON_REPOSITORY_INVALID_ROOT_DIRECTORY: '.$name);
    }
    $json=json_decode((string)file_get_contents($modulePath),true,512,JSON_THROW_ON_ERROR);
    foreach(['id','name','type','prefix'] as $field){
        if(!array_key_exists($field,$json))
            throw new RuntimeException('MODULE_META_MISSING: '.$name.' '.$field);
    }
    if(!preg_match('/^\{[A-F0-9-]{36}\}$/i',(string)$json['id']))
        throw new RuntimeException('MODULE_GUID_INVALID: '.$name);
    if(!is_file($entry->getPathname().'/module.php'))
        throw new RuntimeException('MODULE_SCRIPT_MISSING: '.$name);
    if(isset($modules[$json['id']]))
        throw new RuntimeException('DUPLICATE_MODULE_GUID: '.$name);
    $modules[$json['id']]=$name;
}
if(count($modules)<1)throw new RuntimeException('NO_MODULES');
$library=json_decode((string)file_get_contents($repo.'/library.json'),true,512,JSON_THROW_ON_ERROR);
if(!is_array($library)||!isset($library['id'],$library['version'],$library['build']))
    throw new RuntimeException('INVALID_LIBRARY_JSON');
if(!is_file($repo.'/docs/phone-wifi-bridge/JVPhoneWiFiWebhook.php')
   ||!is_file($repo.'/docs/phone-wifi-bridge/README.md'))
    throw new RuntimeException('PHONE_WIFI_DOCS_MISSING');
if(is_dir($repo.'/tools'))
    throw new RuntimeException('UNSUPPORTED_TOOLS_ROOT');
echo 'IP-Symcon repository structure PASS ('.count($modules).' modules; only permitted non-module root folders)'."\n";
