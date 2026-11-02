<?php
/**
 * Plugin Name: MAD4B WPML Content vs Technical Classifier
 * Description: Read-only dynamic-content evidence; avoids false translation tasks for text-free technical structures.
 * Version: 0.1.0-staging
 */
defined('ABSPATH')||exit;
final class MAD4B_WPML_Translation_Need_V1 {
 private static $memo=[];
 public static function ready(){
  return wp_get_environment_type()==='staging'
   && rtrim(home_url(),'/')==='https://tours.dev.mad4b.com'
   && defined('ICL_SITEPRESS_VERSION');
 }
 private static function visible_key($key){
  if(preg_match('/(^_|^flex_|^grid_|^preset|^container_|^background_|^overlay|css|style|typography|font|alignment|padding|margin|border|background|position|transition|animation|query|source|taxonomy|slug|redirect|url$|icon|color|width|height|size|template|filter_id|listing_id|selected|default_value|class|attribute|html_tag)/i',$key))return false;
  return (bool)preg_match('/(^title$|^editor$|^text$|^description$|^content$|^html$|^heading$|^caption$|^subtitle$|^placeholder$|^label$|^question$|^answer$|^negative_search$|^server_error$|message|button_text|button_label|_label$|_text$|_title$|_content$|_heading$|_placeholder$|_caption$)/i',$key);
 }
 private static function literal($value){
  if(!is_string($value))return '';
  $value=trim(wp_strip_all_tags(html_entity_decode($value,ENT_QUOTES,'UTF-8')));
  if(!$value)return '';
  if(preg_match('/^(?:#[0-9a-f]{3,8}|[0-9.,% ]+|https?:\/\/|\/[a-z0-9_\/.-]+$|\[(?:jet|elementor|wpml|shortcode)|\{\{|{{)/iu',$value))return '';
  if(preg_match('/^(?:yes|no|true|false|center|left|right|start|end|column|row|normal|italic|solid|classic|hidden|cover|auto|none|inherit)$/i',$value))return '';
  if(!preg_match('/[\p{L}]{2,}/u',$value))return '';
  return mb_substr($value,0,155);
 }
 private static function inspect_settings($value,$prefix,&$texts,&$technical,&$node_type){
  foreach((array)$value as $key=>$v){
   if(!is_string($key))continue;
   if($key==='__dynamic__'){ $technical['dynamic_tag_refs']++;continue; }
   if(in_array($key,['shortcode','raw_shortcode','custom_shortcode'],true)){if($v)$technical['shortcode_widgets']++;continue;}
   if(is_array($v)){self::inspect_settings($v,$prefix?($prefix.'/'.$key):$key,$texts,$technical,$node_type);continue;}
   if(!self::visible_key($key))continue;
   $candidate=self::literal($v);
   if(!$candidate)continue;
   $texts[]=['widget'=>$node_type,'field'=>($prefix?$prefix.'/':'').$key,'sample'=>$candidate];
  }
 }
 private static function nodes($tree,&$texts,&$technical){
  foreach((array)$tree as $node){
   if(!is_array($node)||!isset($node['elType']))continue;
   $name=(string)($node['widgetType']??$node['elType']);
   $technical['widget_count']++;
   if($name==='shortcode')$technical['shortcode_widgets']++;
   if(in_array($name,['jet-listing-grid','jet-listing-dynamic-field','jet-listing-dynamic-link','jet-smart-filters-select','jet-smart-filters-checkboxes','jet-smart-filters-range','jet-ajax-search'],true))$technical['dynamic_widgets']++;
   self::inspect_settings($node['settings']??[], '',$texts,$technical,$name);
   self::nodes($node['elements']??[],$texts,$technical);
  }
 }
 public static function source($id){
  $id=(int)$id;if(isset(self::$memo[$id]))return self::$memo[$id];
  $post=get_post($id);
  if(!$post)return new WP_Error('wpml_classify_post_not_found');
  $kind=$post->post_type;$texts=[];
  $technical=['widget_count'=>0,'dynamic_widgets'=>0,'dynamic_tag_refs'=>0,'shortcode_widgets'=>0];
  $data=get_post_meta($id,'_elementor_data',true);
  $tree=is_array($data)?$data:(json_decode((string)$data,true)?:[]);
  self::nodes($tree,$texts,$technical);
  if($kind!=='elementor_library'&&$kind!=='jet-engine'){
   foreach(['post_title','post_content','post_excerpt'] as $key){
    $v=self::literal((string)get_post_field($key,$id));
    if($v)$texts[]=['widget'=>'post','field'=>$key,'sample'=>$v];
   }
  }
  // Technical-only status is restricted to reusable Elementor/JetEngine
  // templates. For content posts, even empty titles do not auto-certify.
  // Shortcode providers can render text outside _elementor_data: keep review.
  $eligible_type=in_array($kind,['elementor_library','jet-engine'],true);
  $classification=$texts?'translation_required':($eligible_type
    && !$technical['shortcode_widgets'] && $technical['widget_count']>0
    ?'technical_only':'needs_render_review');
  return self::$memo[$id]=[
   'classification'=>$classification,'source_post_id'=>$id,'type'=>$kind,
   'visible_literals'=>count($texts),'examples'=>array_slice($texts,0,12),
   'technical_evidence'=>$technical,
   'requires_human_translation'=>$classification==='translation_required',
   'auto_publish'=>false,'audited_source_sha256'=>hash('sha256',wp_json_encode([
    'post_modified_gmt'=>$post->post_modified_gmt,'tree'=>$tree,
    'post_content'=>$post->post_content,'post_excerpt'=>$post->post_excerpt
   ]))
  ];
 }
 public static function preview(){
  if(!self::ready())return new WP_Error('wpml_classifier_wrong_environment');
  if(!class_exists('MAD4B_WPML_ContentJob_Adapter'))return new WP_Error('wpml_classifier_no_adapter');
  $list=MAD4B_WPML_ContentJob_Adapter::discover()['items'];$summary=[];$samples=[];$unique=[];
  foreach($list as $r){
   $v=self::source($r['src']);if(is_wp_error($v))continue;
   $label=$v['classification'];$summary[$label]=($summary[$label]??0)+1;
   if(!isset($unique[$r['src']])){$unique[$r['src']]=1;$samples[]=[
    'source'=>$r['src'],'title'=>mb_substr(get_the_title($r['src']),0,60),
    'type'=>$r['type'],'classification'=>$label,'examples'=>array_slice($v['examples'],0,5),
    'evidence'=>$v['technical_evidence']
   ];}
  }
  return ['contract'=>'mad4b.wpml.translation-need.v1','counts_by_pair'=>$summary,
   'sources_by_type'=>$samples,'preview_only'=>true];
 }
 public static function cli($args){
  $mode=$args[0]??'preview';
  if($mode==='preview')$r=self::preview();
  elseif($mode==='source')$r=self::source((int)($args[1]??0));
  else WP_CLI::error('Usage: wp mad4b-wpml-classify preview | source <post_id>');
  if(is_wp_error($r))WP_CLI::error($r->get_error_code());
  WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 }
 public static function boot(){
  if(self::ready()&&defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b-wpml-classify',[__CLASS__,'cli']);
 }
}
add_action('plugins_loaded',['MAD4B_WPML_Translation_Need_V1','boot'],118);
