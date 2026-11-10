<?php
if (!defined('ABSPATH')) exit;
/** Local-only WordPress Ability for bounded, governed model inference. */
final class MAD4B_SCP_Local_Ollama_Ability {
 const ABILITY='mad4b/ollama-inference';
 const CONTRACT='mad4b.local-ollama-ability.v1';
 public static function permission($input=array()) {
  if(!function_exists('wp_get_environment_type')||wp_get_environment_type()!=='local'
    ||!class_exists('MAD4B_SCP_Local_AI_Governance')||!is_array($input))return false;
  $task=(string)($input['task']??'');
  if(!in_array($task,MAD4B_SCP_Local_AI_Governance::allowed_tasks(),true))return false;
  return current_user_can('manage_options') ||
   MAD4B_SCP_Local_AI_Governance::native_agent_authorized($task);
 }
 public static function execute($input=array()) {
  if(!self::permission($input))return new WP_Error('mad4b_local_ollama_ability_denied');
  $task=(string)$input['task'];
  $prompt=$input['prompt']??null;
  if(!is_string($prompt))return new WP_Error('mad4b_local_ollama_prompt_invalid');
  $config=MAD4B_SCP_AI_Model_Router::settings();
  if(empty($config['enabled'])||($config['mode']??'')!=='local_ollama'
     ||($config['model']??'')!==MAD4B_SCP_Local_AI_Governance::MODEL)
    return new WP_Error('mad4b_local_ollama_pinned_router_required');
  $override=array('task'=>$task,'model'=>MAD4B_SCP_Local_AI_Governance::MODEL);
  if($task==='structured_review')$override['response_format']='review_verdict_v1';
  $text=MAD4B_SCP_AI_Model_Router::generate_text($prompt,$override);
  if(is_wp_error($text))return $text;
  return array('contract'=>self::CONTRACT,'task'=>$task,
   'model'=>MAD4B_SCP_Local_AI_Governance::MODEL,'text'=>$text,
   'domain_mutation_authorized'=>false,'publication_authorized'=>false);
 }
 public static function register() {
  if(!function_exists('wp_get_environment_type')||wp_get_environment_type()!=='local'
    ||!function_exists('wp_register_ability')||wp_has_ability(self::ABILITY))return;
  wp_register_ability(self::ABILITY,array(
   'label'=>'Governed local Ollama inference',
   'description'=>'Bounded Local model inference; exact MAD4B agent authority required. No publishing or model-based approval.',
   'category'=>'mad4b-read',
   'execute_callback'=>array(__CLASS__,'execute'),
   'permission_callback'=>array(__CLASS__,'permission'),
   'input_schema'=>array('type'=>'object','additionalProperties'=>false,
    'properties'=>array(
      'task'=>array('type'=>'string','enum'=>MAD4B_SCP_Local_AI_Governance::allowed_tasks()),
      'prompt'=>array('type'=>'string','minLength'=>1,'maxLength'=>4500)),
    'required'=>array('task','prompt')),
   'output_schema'=>array('type'=>'object','additionalProperties'=>false,
    'properties'=>array(
      'contract'=>array('type'=>'string'),'task'=>array('type'=>'string'),
      'model'=>array('type'=>'string'),'text'=>array('type'=>'string'),
      'domain_mutation_authorized'=>array('type'=>'boolean'),
      'publication_authorized'=>array('type'=>'boolean')),
    'required'=>array('contract','task','model','text',
      'domain_mutation_authorized','publication_authorized')),
   'meta'=>array('public'=>false,'show_in_rest'=>false,
     'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
     'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>false))
  ));
 }
 public static function boot(){add_action('wp_abilities_api_init',array(__CLASS__,'register'),75);}
}
MAD4B_SCP_Local_Ollama_Ability::boot();
