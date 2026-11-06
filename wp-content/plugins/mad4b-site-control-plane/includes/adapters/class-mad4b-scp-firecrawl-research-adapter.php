<?php
defined('ABSPATH')||exit;
/**
 * Reviewed, original MAD4B SCP adapter for source-bound research.
 * External provider claims do not authorize SCP writes.
 */
final class MAD4B_SCP_Firecrawl_Research_Adapter extends MAD4B_SCP_Adapter_Base {
 public function id(){return 'firecrawl-research';}
 public function label(){return 'Firecrawl Source-bound Research';}
 public function is_available(){return class_exists('MAD4B_Firecrawl_Research_Bridge_V1') && class_exists('MAD4B_SCP_Firecrawl_Provider');}
 protected function certified_provider_key(){return 'core';}
 // Internal source-bound Artifact intake uses the MAD4B native Ability authorization,
 // not external vendor plugin certification. The exact job/source/operator permissions
 // and independent VPS receipt gates remain mandatory.
 protected function mutation_requires_certification(){return false;}
 public function ability_names(){return ['read'=>[],'content'=>[],'admin'=>[],'write'=>['mad4b/firecrawl-research-capture']];}
 public function register_abilities(){
  $name='mad4b/firecrawl-research-capture';
  if(!wp_has_ability($name)){
   $this->add_ability($name,'Firecrawl Source-bound ContentJob Capture','capture',
    static function($input=[]){return current_user_can('manage_options') && MAD4B_SCP_Policy::can_mutate();},
    $this->schema([
      'job_id'=>['type'=>'string'],
      'draft_post_id'=>['type'=>'integer'],
      'source_url'=>['type'=>'string']
    ],['job_id','draft_post_id','source_url']), 'write',false,false,false);
  }
 }
 public function capture($input=[]){
  if(!class_exists('MAD4B_Firecrawl_Research_Bridge_V1',false))
   return new WP_Error('mad4b_firecrawl_bridge_unavailable');
  return MAD4B_Firecrawl_Research_Bridge_V1::execute($input);
 }
}
