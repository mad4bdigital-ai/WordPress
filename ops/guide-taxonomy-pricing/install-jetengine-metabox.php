<?php
/**
 * Install the true JetEngine Taxonomy Meta Box in its native registry, without
 * mutating existing taxonomy terms, rates, booking or pricing policies.
 * Dry run by default: MAD4B_GUIDE_TAX_SCHEMA_INSTALL=1 to apply.
 */
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$cls='MAD4B_Guide_Taxonomy_Pricing_V1';
if(!class_exists($cls,false))WP_CLI::error('Guide Taxonomy Pricing MU module not loaded');
if(!$cls::permitted())WP_CLI::error('Company pricing permission missing');
$all=get_option('jet_engine_meta_boxes',[]);
if(!is_array($all))WP_CLI::error('JetEngine metabox registry unavailable');
$existing=$all[$cls::META_BOX]??null;
$idRepair=false;
if($existing!==null && !$cls::registryIsPresent($all)){
    // Repair only the known early draft of this exact own schema that
    // lacked JetEngine's required outer ID; never overwrite other data.
    $probe=$all;
    if(is_array($probe[$cls::META_BOX]??null) &&
        !array_key_exists('id',$probe[$cls::META_BOX])) {
        $probe[$cls::META_BOX]['id']=$cls::META_BOX;
        $idRepair=$cls::registryIsPresent($probe);
    }
    if(!$idRepair)WP_CLI::error('Conflicting Guide Language box; no overwrite');
}
foreach($all as $id=>$box) {
 if($id===$cls::META_BOX)continue;
 foreach((array)($box['meta_fields']??[]) as $field) {
  if(is_array($field)&&in_array((string)($field['name']??''),$cls::FIELDS,true)
    && in_array('guide-languages',(array)($box['args']['allowed_tax']??[]),true))
     WP_CLI::error('Conflicting Guide term field '.($field['name']??''));
 }
}
$installed=$existing!==null;
$dry=getenv('MAD4B_GUIDE_TAX_SCHEMA_INSTALL')!=='1';
$backup='/tmp/mad4b-guide-jetengine-meta-boxes-before-'.gmdate('Ymd-His').'.json';
if(file_put_contents($backup,wp_json_encode([
 'site'=>home_url(),'time'=>gmdate('c'),'schema_id'=>$cls::META_BOX,
 'installed_before'=>$installed,'meta_box_keys'=>array_keys($all),'registry'=>$all,
],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))===false)WP_CLI::error('Backup unavailable');
WP_CLI::log(($dry?'DRY_RUN':'APPLY').' JetEngine guide cost taxonomy box='.
    $cls::META_BOX.' existing='.($installed?'yes':'no').
    ' id_repair='.($idRepair?'yes':'no').' backup='.$backup);
if(!$dry && (!$installed || $idRepair)){
 if($idRepair)$all[$cls::META_BOX]['id']=$cls::META_BOX;
 else $all[$cls::META_BOX]=$cls::nativeBox();
 if(!update_option('jet_engine_meta_boxes',$all,false))
     WP_CLI::error('JetEngine registry update failed');
 if(!$cls::registryIsPresent((array)get_option('jet_engine_meta_boxes',[])))
     WP_CLI::error('JetEngine schema readback failed');
}
if(!$dry && $installed && !$idRepair)
 WP_CLI::log('Existing identical schema retained; idempotent');
WP_CLI::success(($dry?'GUIDE_TAXONOMY_SCHEMA_DRYRUN':'GUIDE_TAXONOMY_SCHEMA_INSTALLED').' no_price_changes');
