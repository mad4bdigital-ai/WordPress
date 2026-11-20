<?php
/**
 * v125: Validate the **inert source-only** original 379-entry MU contract.
 * Do not load WordPress, run MU router, or execute any Original305/318 module.
 */
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
$root=dirname(__DIR__,2);
$base=$root.'/deploy/host-authority/reconciliation/v125/live-dependency-sources/'
    .'wp-content/plugins/mad4b-platform/';
$map=$base.'config/ordered-mu-map.php';
$contract=$base.'config/ordered-mu-map-contract.json';
$verifier=$base.'includes/class-mad4b-mu-map-contract.php';
if(!is_file($map)||!is_file($contract)||!is_file($verifier))
 throw new RuntimeException('V125_MU_CONTRACT_INPUT_MISSING');
$entries=require $map;
$manifest=json_decode((string)file_get_contents($contract),true);
require_once $verifier;
$assert=static function(bool $v,string $code):void{
 if(!$v) throw new RuntimeException($code);
};
$assert(count($entries)===379,'V125_ORIGINAL_ENTRY_COUNT_CHANGED');
$assert(MAD4B_MU_Map_Contract_V1::verify($entries,$manifest),
    'V125_ORIGINAL_SOURCE_MAP_SIGNATURE_OR_HASH_NOT_VERIFIED');
$assert($manifest['by_family']===['mad4b-commerce'=>59,'mad4b-platform'=>320],
    'V125_ORIGINAL_FAMILIES_DRIFT');
$assert(hash('sha256',json_encode($entries,JSON_UNESCAPED_SLASHES))===
    $manifest['ordered_entries_sha256'],'V125_ORDERED_ENTRY_SHA_CHANGED');
// Tamper tests are in-memory only: original protected sources remain intact.
$alter=$entries;
$tmp=$alter[135];$alter[135]=$alter[136];$alter[136]=$tmp;
$assert(MAD4B_MU_Map_Contract_V1::verify($alter,$manifest)===false,
    'V125_SWAPPED_MAP_ENTRIES_NOT_REJECTED');
$alter=$entries;$alter[0][1]='mad4b-platform/modules/compat/fake.php';
$assert(MAD4B_MU_Map_Contract_V1::verify($alter,$manifest)===false,
    'V125_TAMPERED_MODULE_PATH_NOT_REJECTED');
$alter=$entries;array_pop($alter);
$assert(MAD4B_MU_Map_Contract_V1::verify($alter,$manifest)===false,
    'V125_SHORT_MAP_NOT_REJECTED');
$alter=$entries;$alter[1][0]=$alter[0][0];
$assert(MAD4B_MU_Map_Contract_V1::verify($alter,$manifest)===false,
    'V125_DUPLICATE_MODULE_NAME_NOT_REJECTED');
$alter=$manifest;$alter['ordered_entries_sha256']=str_repeat('0',64);
$assert(MAD4B_MU_Map_Contract_V1::verify($entries,$alter)===false,
    'V125_TAMPERED_MANIFEST_NOT_REJECTED');
echo json_encode(['state'=>'PASS','checks'=>9,
    'ordered_entries'=>count($entries),
    'families'=>$manifest['by_family'],
    'runtime_module_loaded'=>false,'wordpress_write'=>false],
    JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
