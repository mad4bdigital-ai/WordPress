<?php
if(!defined('WP_CLI') || !WP_CLI)exit(21);
$payload=json_decode((string)getenv('MAD4B_OPTIONS_REGISTRY_PAYLOAD'),true);
$target=(string)getenv('MAD4B_OPTIONS_REGISTRY_TARGET');
if(!is_array($payload) ||
   ($payload['contract']??'')!=='mad4b.company-options-field-registry.v1'||
   ($payload['page_count']??0)!==4||($payload['field_count']??0)!==112||
   !is_array($payload['pages']??null)||
   !preg_match('/^[a-f0-9]{64}$/D',$payload['fingerprint']??''))
    WP_CLI::error('REGISTRY_INPUT_REJECTED');
$host=wp_parse_url(home_url(),PHP_URL_HOST);
if($target==='central'){
  if(DB_NAME!=='etg_commerce_staging'||$host!=='tours.dev.mad4b.com'||
     wp_get_environment_type()!=='staging')WP_CLI::error('CONTROL_PLANE_IDENTITY_MISMATCH');
}elseif($target==='tenant'){
  $id=get_option('mad4b_company_site_identity_v1',[]);
  if(DB_NAME!=='wayfatours_production'||$host!=='wayfatours.mad4b.com'||
     ($id['company_code']??'')!=='wayfatours')WP_CLI::error('TENANT_IDENTITY_MISMATCH');
}else WP_CLI::error('TARGET_INVALID');
global $wpdb;
foreach($payload['pages'] as $slug=>$page){
 if(!preg_match('/^[a-z0-9-]+$/D',$slug) || !isset($page['fields']) ||
    !is_array($page['fields']))WP_CLI::error('PAGE_CONTRACT_REJECTED');
 $row=$wpdb->get_row($wpdb->prepare("SELECT id,meta_fields FROM {$wpdb->prefix}jet_post_types WHERE slug=%s AND status='page'",$slug),ARRAY_A);
 if(!$row)WP_CLI::error('PAGE_MISSING_'.$slug);
 $fields=maybe_unserialize($row['meta_fields']);
 $expected=array_map(static function($v){return $v['key'];},$page['fields']);
 $actual=array_map(static function($v){return $v['name']??'';},$fields);
 if($expected!==$actual)WP_CLI::error('PAGE_FIELDS_DO_NOT_MATCH_'.$slug);
}
$opt='mad4b_company_options_field_registry_v1';
$prior=get_option($opt,null);
$state='EXISTING_UNCHANGED';
if(!is_array($prior)||($prior['fingerprint']??'')!==$payload['fingerprint']){
 if(!update_option($opt,$payload,false)) {
   if(!add_option($opt,$payload,'',false))WP_CLI::error('REGISTRY_STORE_FAILED');
 }
 $state='SCHEMA_REGISTRY_STORED';
}
$read=get_option($opt,[]);
if(($read['fingerprint']??'')!==$payload['fingerprint'])WP_CLI::error('REGISTRY_READBACK_MISMATCH');
echo wp_json_encode(['state'=>$state,'environment'=>$target,
  'page_count'=>count($read['pages']),'field_count'=>$read['field_count'],
  'schema_fingerprint'=>$read['fingerprint'],'business_values_written'=>0],JSON_UNESCAPED_SLASHES).PHP_EOL;

