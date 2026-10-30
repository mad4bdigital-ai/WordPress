<?php
if (!defined('ABSPATH')) exit;
/** Non-authorizing discovery connection preflight, no external requests. */
final class MAD4B_SCP_Firecrawl_Discovery_Preflight {
 public static function boot(){add_action('wp_abilities_api_init',array(__CLASS__,'register'),45);}
 public static function register(){
  if(!function_exists('wp_register_ability'))return;
  if(function_exists('wp_has_ability') && wp_has_ability('mad4b/firecrawl-discovery-preflight'))return;
  wp_register_ability('mad4b/firecrawl-discovery-preflight',array(
   'label'=>'Firecrawl Discovery Preflight',
   'description'=>'Read-only provider and task readiness, without scraping or importing.',
   'category'=>'mad4b-read',
   'execute_callback'=>array(__CLASS__,'execute'),
   'permission_callback'=>array(__CLASS__,'permitted'),
   'input_schema'=>array('type'=>'object','properties'=>array('post_id'=>array('type'=>'integer','minimum'=>1)),
      'required'=>array('post_id'),'additionalProperties'=>false),
   'output_schema'=>array('type'=>'object','additionalProperties'=>true),
   'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))
  ));
 }
 public static function permitted(){return wp_get_environment_type()==='staging'&&current_user_can('manage_options');}
 public static function execute($input){
  if(!self::permitted())return new WP_Error('mad4b_firecrawl_scope_denied','Staging administrator required.');
  return MAD4B_SCP_Tour_Workflow_Runtime::evaluate(absint($input['post_id']??0));
 }
}
MAD4B_SCP_Firecrawl_Discovery_Preflight::boot();
