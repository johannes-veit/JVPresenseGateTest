<?php
declare(strict_types=1);
// CLI-only test shim. The real IP-Symcon engine provides these functions.
// Reentrant or concurrent access is simulated by returning false on second enter.
if(!function_exists('IPS_SemaphoreEnter')){
    $GLOBALS['shadowSemaphores']=[];
    function IPS_SemaphoreEnter(string $name,int $timeout):bool {
        if(isset($GLOBALS['shadowSemaphores'][$name]))return false;
        $GLOBALS['shadowSemaphores'][$name]=true;
        return true;
    }
    function IPS_SemaphoreLeave(string $name):void {
        unset($GLOBALS['shadowSemaphores'][$name]);
    }
}
