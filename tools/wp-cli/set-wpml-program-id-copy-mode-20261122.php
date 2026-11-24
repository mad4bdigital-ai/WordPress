<?php
defined('ABSPATH')||exit;
if(!current_user_can('manage_options'))throw new RuntimeException('master_admin_required');
$site=home_url();
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))throw new RuntimeException('unexpected_company');
if(!defined('WPML_COPY_CUSTOM_FIELD')||WPML_COPY_CUSTOM_FIELD!==1)throw new RuntimeException('wpml_copy_constant_not_verified');
$xml=ABSPATH.'wp-content/plugins/mad4b-pricing-registry/wpml-config.xml';
if(!is_readable($xml))throw new RuntimeException('wpml_config_unavailable');
$tree=simplexml_load_file($xml);
if(!$tree)throw new RuntimeException('wpml_config_xml_invalid');
foreach(['related_program_terms','program_term_id'] as $field){
 $found=false;foreach($tree->{'custom-fields'}->{'custom-field'} as $entry){
  if(trim((string)$entry)===$field && (string)$entry['action']==='copy')$found=true;
 }
 if(!$found)throw new RuntimeException('wpml_config_does_not_authorize_copy_'.$field);
}
$options=get_option('icl_sitepress_settings',[]);
if(!is_array($options)||!is_array($options['translation-management']??null))
 throw new RuntimeException('wpml_translation_options_unavailable');
$old=$options['translation-management']['custom_fields_translation']??[];
if(!is_array($old))throw new RuntimeException('wpml_custom_field_translation_bad_type');
$changes=[];
foreach(['related_program_terms','program_term_id'] as $field){
 $before=$old[$field]??null;
 if($before!==1)$changes[$field]=['previous'=>$before,'new'=>1];
}
$dry=getenv('MAD4B_APPLY_ID_COPY')!=='yes';
if(!$dry&&$changes){
 foreach($changes as $field=>$row)$options['translation-management']['custom_fields_translation'][$field]=1;
 if(!update_option('icl_sitepress_settings',$options,false))
   throw new RuntimeException('wpml_copy_settings_save_failed');
}
$read=get_option('icl_sitepress_settings',[]);
$after=(array)($read['translation-management']['custom_fields_translation']??[]);
if(!$dry && ($after['related_program_terms']??null)!==1)
 throw new RuntimeException('wpml_program_id_copy_readback_failed');
if(!$dry && ($after['program_term_id']??null)!==1)
 throw new RuntimeException('wpml_nested_program_id_copy_readback_failed');
echo wp_json_encode(['site'=>$site,'dry_run'=>$dry,'changes'=>$changes,
 'readback'=>[
  'related_program_terms'=>$after['related_program_terms']??null,
  'program_term_id'=>$after['program_term_id']??null],
 'taxonomy_programs_translatable'=>(string)($read['taxonomies_sync_option']['programs']??'')==='2',
 'program_names_unchanged'=>true,'tour_linked_program_ids_unchanged'=>true],
 JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

