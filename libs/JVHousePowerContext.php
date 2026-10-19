<?php
declare(strict_types=1);

/**
 * Explicitly opted-in, strictly passive numeric W reading from an existing
 * Symcon variable. High power and kitchen PIR activity are only temporal
 * context. Neither identifies a device, the cooking activity nor a person.
 */
final class JVHousePowerContext
{
    public const AUTHORITY='EXISTING_WATT_SOURCE_AND_ANONYMOUS_KITCHEN_MOTION_ONLY';
    private const MAX_AGE=300;
    private const HIGH_WATTS=3000;
    public static function evaluate(array $snapshot,array $roomPaths,int $now,bool $enabled):array
    {
        $out=[
            'schema'=>1,'authority'=>self::AUTHORITY,
            'enabled'=>$enabled,'state'=>$enabled?'SOURCE_NOT_VERIFIED':'DISABLED',
            'wattSourceVerified'=>false,'watts'=>null,'ageSeconds'=>null,
            'highLoad3000W'=>false,'recentKitchenMovement5m'=>false,
            'highLoadAndKitchenMovementNearby'=>false,
            'highPowerIsNotProofOfCooking'=>true,
            'sourceMayMeasureOtherAppliances'=>true,
            'personIdentityCertified'=>false,'personCountCertified'=>false,
            'homeEmptyCertified'=>false,'alarmMayBeControlled'=>false,
            'noDeviceOrElectricMeterWrites'=>true
        ];
        if(!$enabled)return $out;
        if(($snapshot['validatedWattMetadata']??null)!==true
            ||!is_int($snapshot['updatedAt']??null)
            ||!is_int($now)||$now<=0)return $out;
        $raw=$snapshot['value']??null;
        if(!(is_int($raw)||is_float($raw))
            ||!is_finite((float)$raw)||$raw<0||$raw>100000){
            $out['state']='INVALID_NUMERIC_WATT_VALUE';return $out;
        }
        $at=$snapshot['updatedAt'];
        if($at<=0||$at>$now+5){
            $out['state']='INVALID_WATT_TIMESTAMP';return $out;
        }
        $out['wattSourceVerified']=true;
        $out['ageSeconds']=max(0,$now-$at);
        if($now-$at>self::MAX_AGE){
            $out['state']='STALE_WATT_SNAPSHOT';return $out;
        }
        $out['watts']=(int)round($raw);
        $out['highLoad3000W']=$raw>self::HIGH_WATTS;
        $validRooms=($roomPaths['schema']??null)===1
            &&($roomPaths['authority']??null)
                ==='LCN_ROOM_MOTION_PATHS_ANONYMOUS_ONLY'
            &&($roomPaths['coverageStatus']??null)==='AVAILABLE_ANONYMOUS_EDGES';
        $kitchenAt=$validRooms?($roomPaths['kitchenLastMotionAt']??null):null;
        $out['recentKitchenMovement5m']=is_int($kitchenAt)
            &&$kitchenAt<=$now+5
            &&$kitchenAt>=$now-300;
        $out['highLoadAndKitchenMovementNearby']=$out['highLoad3000W']
            &&$out['recentKitchenMovement5m'];
        $out['state']=$out['highLoadAndKitchenMovementNearby']
            ?'HIGH_LOAD_AND_KITCHEN_MOTION_NOT_COOKING_PROOF'
            :($out['highLoad3000W']
                ?'HIGH_LOAD_WITHOUT_CURRENT_KITCHEN_CONTEXT'
                :'NORMAL_OR_LOW_POWER_NO_ABSENCE_PROOF');
        return $out;
    }
}
