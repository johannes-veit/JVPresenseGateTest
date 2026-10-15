<?php
declare(strict_types=1);
/**
 * Metadata-only discovery of potentially separate Dahua IPC instances.
 * A model match or an old IP is NEVER sufficient for live counting.
 */
final class JVAdditionalIPCInventory
{
    public static function evaluate(array $instances,bool $limited):array
    {
        $list=[];
        foreach($instances as $item){
            if(!is_array($item)||!is_int($item['instanceId']??null)||$item['instanceId']<=0)continue;
            $name=trim((string)($item['name']??''));
            $parent=trim((string)($item['parent']??''));
            $combined=$name.' '.$parent;
            // Only a camera-like display name; never inspect credentials
            // or try connecting to the camera or changing its smart plan.
            if(!preg_match('/(?:kamera|camera|ipc|dahua|tioc|onvif)/iu',$combined))continue;
            $model=preg_match('/(?:HFW5442E.ZE)/i',$combined)
                ?'IPC-HFW5442E-ZE':(preg_match('/PDW3849.A180.AS.PV/i',$combined)
                    ?'IPC-PDW3849-A180-AS-PV':'UNKNOWN');
            $list[]=[
                'instanceId'=>$item['instanceId'],
                'name'=>$name,'parent'=>$parent,'modelFromName'=>$model,
                'actualModelVerified'=>false,
                'isAdditionalPhysicalCameraVerified'=>false,
                'viewCoversStreetExit'=>'UNKNOWN',
                'currentSmartPlan'=>'UNKNOWN',
                'personCountingCurrentlyAvailable'=>'UNKNOWN',
                'mayBePrioritizedForCounting'=>false,
                'requiresCameraModelSmartPlanAndViewVerification'=>true
            ];
        }
        usort($list,static fn($a,$b)=>$a['instanceId']<=>$b['instanceId']);
        return [
            'schema'=>1,'authority'=>'ADDITIONAL_IPC_LOCAL_METADATA_ONLY',
            'candidateCount'=>count($list),
            'listedCandidates'=>array_slice($list,0,80),
            'candidateListLimited'=>count($list)>80,
            'scanLimited'=>$limited,
            'noCameraNetworkAccess'=>true,
            'noDevicesOrSourcesChanged'=>true,
            'automaticCountingPriorityChanged'=>false,
            'personCountCertified'=>false,
            'propertyEmptyMayBeCertified'=>false
        ];
    }
}
