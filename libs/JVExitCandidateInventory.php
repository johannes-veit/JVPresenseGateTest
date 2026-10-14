<?php
declare(strict_types=1);

/**
 * Pure metadata-only candidate selector. Names are only hints: no automatic
 * source linking, no assumptions about "closed"/"open", no home/away action.
 */
final class JVExitCandidateInventory
{
    private const MAX_RESULTS=80;
    public static function select(array $items,bool $scanLimited=false):array
    {
        $candidates=[];$seen=[];
        foreach($items as $item) {
            if(!is_array($item)
                || !is_int($item['variableId']??null)
                || $item['variableId']<=0
                || !in_array(($item['type']??null),[0,1],true))continue;
            $id=$item['variableId'];
            if(isset($seen[$id]))continue;
            $seen[$id]=true;
            $location=implode(' / ',array_map('strval',[
                $item['grandparent']??'',
                $item['parent']??'',
                $item['variableName']??''
            ]));
            if(!preg_match(
                '/(?:garage|garagentor|rolltor|sektionaltor|haustür|haustuer|'
                .'eingangstür|eingangstuer|türkontakt|tuerkontakt|'
                .'torkontakt|türöffnung|türöffnung|einfahrt|zufahrt|'
                .'hoftor|gartentor|schiebetor|flügeltor|pforte|'
                .'torantrieb|fahrzeug|fahrzeugerkennung|'
                .'türschloss|türsensor|kontakt|tür|tuer|\btor\b)/iu',
                $location))continue;
            // Never suggest the independently maintained WLAN project.
            if(preg_match('/\b(?:WLAN\s*Johannes|Identity\s*Shadow)\b/iu',
                $location))continue;
            $candidates[]=[
                'variableId'=>$id,
                'type'=>$item['type']===0?'Boolean':'Integer',
                'name'=>(string)($item['variableName']??''),
                'parent'=>(string)($item['parent']??''),
                'grandparent'=>(string)($item['grandparent']??''),
                'hintOnly'=>true,
                'directionVerified'=>false,
                'polarityVerified'=>false
            ];
        }
        usort($candidates,static fn($a,$b)=>
            ($a['variableId']<=>$b['variableId']));
        $count=count($candidates);
        return [
            'schema'=>1,
            'authority'=>'READ_ONLY_EXIT_SOURCE_CANDIDATES',
            'configurationChanged'=>false,
            'devicesChanged'=>false,
            'personPresenceMayBeChanged'=>false,
            'propertyEmptyMayBeCertified'=>false,
            'candidateCount'=>$count,
            'listedCandidates'=>array_slice($candidates,0,self::MAX_RESULTS),
            'candidateListLimited'=>$count>self::MAX_RESULTS,
            'symconScanLimited'=>$scanLimited,
            'candidatesAreNotVerifiedExitRoutes'=>true
        ];
    }
}
