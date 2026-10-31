<?php
/**
 * Plugin Name: MAD4B Tour Rate Departure Details — Governed Opt-in (Staging)
 * Description: Opt-in schedule display, validated Tour-origin inheritance and neutral JetEngine choices.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Rate_Departure_Details_V1 {
 const UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 const FIELD='show_rate_departure_details';
 const RATE_CPT_ID=328;
 const ORIGIN_FIELDS=['tour_origin_location_term','tour_origin_address'];
 const WIDGETS=['2c7a06c'=>'day','08da455'=>'origin','0d736f7'=>'time',
                '7ddc41b'=>'day','213c9bf'=>'origin','fb0a8c1'=>'time'];
 static function bound(){
  if(wp_get_environment_type()!=='staging'||wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')return false;
  $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
  return is_array($p)&&($p['site_uuid']??'')===self::UUID;
 }
 static function current_rate(){
  if(!function_exists('jet_engine')||!isset(jet_engine()->listings->data))return 0;
  $o=jet_engine()->listings->data->get_current_object();
  if(is_object($o)&&isset($o->ID)&&get_post_type((int)$o->ID)==='tour-rates')return (int)$o->ID;
  // Reuse the existing JetEngine-safe context resolver for alternate listing skins.
  if(function_exists('tk_trdf_get_current_tour_rate_id')){
   $id=(int)tk_trdf_get_current_tour_rate_id();
   if($id && get_post_type($id)==='tour-rates')return $id;
  }
  return 0;
 }
 static function is_enabled($rate){return $rate>0 && (string)get_post_meta($rate,self::FIELD,true)==='1';}
 static function valid_origin_term($id){
  $t=$id>0?get_term($id):null;
  if(!$t||is_wp_error($t)||!in_array($t->taxonomy,['destinations','location_jet'],true))return null;
  $type=strtolower(trim((string)get_term_meta($t->term_id,'destination_type',true)));
  if($t->parent===0||in_array($type,['continent','country'],true))return null;
  return $t;
 }
 static function origin($rate){
  $tour=(int)get_post_meta($rate,'related_tour_id',true);
  if(!$tour||get_post_type($tour)!=='tours-and-activities'||!in_array(get_post_status($tour),['publish','private'],true))return '';
  $term=self::valid_origin_term((int)get_post_meta($tour,'tour_origin_location_term',true));
  if(!$term)$term=self::valid_origin_term((int)get_post_meta($tour,'tour_origin_address',true));
  if(!$term)return '';
  $lang=function_exists('apply_filters')?apply_filters('wpml_current_language',null):null;
  if($lang && $lang!=='all'){
   $translated=(int)apply_filters('wpml_object_id',$term->term_id,$term->taxonomy,true,$lang);
   $t=self::valid_origin_term($translated);
   if($t)$term=$t;
  }
  return (string)$term->name;
 }
 static function actual_day_time($rate,$field){
  if(!self::is_enabled($rate))return '';
  $meta=$field==='day'?'related_day_of_week_term_id':'related_day_time_term_id';
  $taxonomy=$field==='day'?'schudle-days-of-weak':'day-times';
  $id=(int)get_post_meta($rate,$meta,true);
  $term=$id?get_term($id,$taxonomy):null;
  return $term && !is_wp_error($term) && $term->taxonomy===$taxonomy?(string)$term->name:'';
 }
 /** Resolve rate details by the JetEngine meta key, not a template widget ID.
  * This also covers WPML Elementor clones and future Listing templates. */
 static function widget_type($widget){
  if(!is_object($widget)||!method_exists($widget,'get_id'))return null;
  $name=method_exists($widget,'get_name')?(string)$widget->get_name():'';
  if($name==='jet-listing-dynamic-field' || $name==='jet-listing-dynamic-field.default'){
   $field='';
   if(method_exists($widget,'get_settings_for_display'))$field=(string)$widget->get_settings_for_display('dynamic_field_post_meta_custom');
   if($field==='' && method_exists($widget,'get_settings'))$field=(string)$widget->get_settings('dynamic_field_post_meta_custom');
   $map=['related_day_of_week_term_id'=>'day','related_origin_destination_term_id'=>'origin','related_day_time_term_id'=>'time'];
   if(isset($map[$field]))return $map[$field];
  }
  // Backwards compatibility for older JetEngine widgets that do not expose
  // their custom field in the get_settings() API.
  $id=(string)$widget->get_id();return self::WIDGETS[$id]??null;
 }
 static function widget_format($widget,$type){
  if(method_exists($widget,'get_settings_for_display')){
   $format=(string)$widget->get_settings_for_display('dynamic_field_format');
   if($format!=='' && substr_count($format,'%s')===1 && strlen($format)<=100 && strpos($format,'%n')===false)
    return wp_strip_all_tags($format);
  }
  $id=(string)$widget->get_id();
  return $type==='origin'&&$id==='213c9bf'?'from %s':($type==='time'&&$id==='fb0a8c1'?'at %s':'%s');
 }
 static function mark_widget($widget){
  if(!self::bound()||is_admin()||!is_object($widget)||!method_exists($widget,'get_id')||!method_exists($widget,'add_render_attribute'))return;
  if(self::widget_type($widget)===null)return;
  $rate=self::current_rate();
  $widget->add_render_attribute('_wrapper','data-mad4b-departure-optin',self::is_enabled($rate)?'1':'0');
  $widget->add_render_attribute('_wrapper','data-mad4b-departure-source','linked-tour');
 }
 static function widget_content($content,$widget){
  if(!self::bound()||is_admin()||!is_object($widget)||!method_exists($widget,'get_id'))return $content;
  $type=self::widget_type($widget);
  if($type===null)return $content;
  $rate=self::current_rate();
  // No verified rate context means no potentially false schedule or continent text.
  if(!$rate || !self::is_enabled($rate))return '';
  $value=$type==='origin'?self::origin($rate):self::actual_day_time($rate,$type);
  if($value==='')return '';
  // Preserve the JetEngine/Elementor wrapper and CSS while replacing stale rendered data.
  // Replace the text node of the dynamic-field output, not a full HTML fragment.
  if(preg_match('~(<div\b[^>]*class=["\'][^"\']*jet-listing-dynamic-field__content[^"\']*["\'][^>]*>)(.*?)(</div>)~is',$content)){
   $format=self::widget_format($widget,$type);
   return preg_replace_callback('~(<div\b[^>]*class=["\'][^"\']*jet-listing-dynamic-field__content[^"\']*["\'][^>]*>)(.*?)(</div>)~is',
    static function($m)use($format,$value){return $m[1].esc_html(sprintf($format,$value)).$m[3];},$content,1);
  }
  return '';
 }
 static function migrate_schema(){
  if(!self::bound())return new WP_Error('wrong_site');
  global $wpdb;
  $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}jet_post_types WHERE id=%d AND slug=%s",self::RATE_CPT_ID,'tour-rates'),ARRAY_A);
  if(!$row)return new WP_Error('rate_cpt_schema_missing');
  $backup='mad4b_rate_departure_schema_backup_20261031_v1';
  if(!get_option($backup))add_option($backup,['id'=>self::RATE_CPT_ID,'raw'=>$row['meta_fields'],'sha256'=>hash('sha256',$row['meta_fields'])],'','no');
  $old=get_option($backup);if(!hash_equals($old['sha256'],hash('sha256',$old['raw'])))return new WP_Error('backup_mismatch');
  $fields=maybe_unserialize($row['meta_fields']);if(!is_array($fields))return new WP_Error('schema_invalid');
  $found=[];
  foreach($fields as &$f){
   $key=$f['name']??'';
   if(in_array($key,['related_day_of_week_term_id','related_day_time_term_id','related_origin_destination_term_id'],true)){
    $f['default_val']='';$f['is_required']=false;$f['placeholder']='— Not specified —';
    $f['description']=$key==='related_origin_destination_term_id'?'Legacy reference only. Published Rate origin uses linked Tour origin metadata; never a continent.':
      'Optional; leave Not specified until the departure schedule is explicitly verified.';
    $found[]=$key;
   }
   if($key===self::FIELD)$found[]=self::FIELD;
  }
  unset($f);
  if(count(array_intersect(['related_day_of_week_term_id','related_day_time_term_id','related_origin_destination_term_id'],$found))!==3)return new WP_Error('required_rate_fields_missing');
  if(!in_array(self::FIELD,$found,true))$fields[]=[
    'name'=>self::FIELD,'title'=>'Show departure details on Rate card',
    'description'=>'Display confirmed departure weekday/time and Tour-origin only when True. Default False hides these details.','object_type'=>'field',
    'type'=>'radio','options_source'=>'glossary','glossary_id'=>'321',
    'default_val'=>'0','width'=>'50%','show_in_rest'=>true,'revision_support'=>true,
    'quick_editable'=>true,'id'=>827231,'isNested'=>false
  ];
  $new=maybe_serialize($fields);
  if($new!==$row['meta_fields']){
   $ok=$wpdb->update($wpdb->prefix.'jet_post_types',['meta_fields'=>$new],['id'=>self::RATE_CPT_ID],['%s'],['%d']);
   if($ok!==1)return new WP_Error('schema_update_failed');
  }
  $read=$wpdb->get_var($wpdb->prepare("SELECT meta_fields FROM {$wpdb->prefix}jet_post_types WHERE id=%d",self::RATE_CPT_ID));
  $decoded=maybe_unserialize($read);
  if(!is_array($decoded)||!in_array(self::FIELD,array_column($decoded,'name'),true))return new WP_Error('schema_readback_failed');
  return ['ok'=>true,'schema_sha256'=>hash('sha256',$read),'source_backup_option'=>$backup,'toggle_default'=>'0','glossary_id'=>321];
 }
 /** Read-only whole-site integrity: counts include every published/draft Rate.
  * Preserve old optional values; only opted-in invalid data is actionable. */
 static function audit(){
  if(!self::bound())return new WP_Error('wrong_site');
  global $wpdb;
  $rows=$wpdb->get_results($wpdb->prepare("SELECT ID,post_status FROM {$wpdb->posts} WHERE post_type=%s AND post_status IN ('publish','draft') ORDER BY ID ASC",'tour-rates'),ARRAY_A);
  $result=['site_uuid'=>self::UUID,'scanned'=>0,'publish'=>0,'draft'=>0,'opted_in'=>0,
   'hidden_legacy_rates'=>0,'opted_in_invalid_origin'=>[],'orphan_published_rate'=>[],
   'schema_valid'=>false,'glossary_id'=>321,'defaults_hidden'=>true];
  foreach((array)$rows as $row){
   $id=(int)$row['ID'];++$result['scanned'];++$result[$row['post_status']];
   $tour=(int)get_post_meta($id,'related_tour_id',true);
   if($row['post_status']==='publish' && (!$tour||get_post_type($tour)!=='tours-and-activities')){
    if(count($result['orphan_published_rate'])<20)$result['orphan_published_rate'][]=$id;
   }
   $enabled=self::is_enabled($id);
   if($enabled){
    ++$result['opted_in'];
    if(self::origin($id)==='' && count($result['opted_in_invalid_origin'])<20)$result['opted_in_invalid_origin'][]=$id;
   }elseif((int)get_post_meta($id,'related_day_of_week_term_id',true)>0
    ||(int)get_post_meta($id,'related_day_time_term_id',true)>0
    ||(int)get_post_meta($id,'related_origin_destination_term_id',true)>0){
    ++$result['hidden_legacy_rates'];
   }
  }
  // JetEngine schema may be independently edited; detect changed defaults.
  $schema=$wpdb->get_var($wpdb->prepare("SELECT meta_fields FROM {$wpdb->prefix}jet_post_types WHERE id=%d AND slug=%s",self::RATE_CPT_ID,'tour-rates'));
  $fields=maybe_unserialize($schema);$byKey=[];
  foreach((array)$fields as $f){if(is_array($f)&&isset($f['name']))$byKey[$f['name']]=$f;}
  $toggle=$byKey[self::FIELD]??[];
  $result['schema_valid']=($toggle['type']??'')==='radio' && ($toggle['options_source']??'')==='glossary'
   && (int)($toggle['glossary_id']??0)===321 && (string)($toggle['default_val']??'')==='0';
  foreach(['related_day_of_week_term_id','related_day_time_term_id','related_origin_destination_term_id'] as $key){
   if(!isset($byKey[$key]) || (string)($byKey[$key]['default_val']??'')!=='' || !empty($byKey[$key]['is_required']))$result['defaults_hidden']=false;
  }
  return $result;
 }
 static function cli($args,$opts){
  $command=$args[0]??'status';
  if($command==='audit'){
   $result=self::audit();if(is_wp_error($result))WP_CLI::error($result->get_error_code());
   WP_CLI::line(wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));return;
  }
  if($command==='status'){
   WP_CLI::line(wp_json_encode(['site_bound'=>self::bound(),'example_rate_60840_enabled'=>self::is_enabled(60840),'example_origin'=>self::origin(60840),'schema_backup'=>!!get_option('mad4b_rate_departure_schema_backup_20261031_v1')],JSON_PRETTY_PRINT));return;
  }
  if($command!=='migrate'||($opts['confirm']??'')!=='STAGING RATE DEPARTURE SCHEMA')WP_CLI::error('Invalid command or missing exact confirm');
  $result=self::migrate_schema();if(is_wp_error($result))WP_CLI::error($result->get_error_code());
  WP_CLI::line(wp_json_encode($result,JSON_PRETTY_PRINT));
 }
 static function admin_neutral_options(){
  if(!self::bound()||!is_admin())return;
  $screen=function_exists('get_current_screen')?get_current_screen():null;
  if(!$screen||($screen->post_type??'')!=='tour-rates'||$screen->base!=='post')return;
  $keys=['related_day_of_week_term_id','related_day_time_term_id','related_origin_destination_term_id'];
  $rate=(int)($_GET['post']??0);
  $saved=[];
  foreach($keys as $key)$saved[$key]=$rate && get_post_type($rate)==='tour-rates'
    ? (string)get_post_meta($rate,$key,true):'';
  echo '<script id="mad4b-rate-neutral-selects">const mad4bSavedRateSelections='.wp_json_encode($saved).';</script>';
  ?>
  <script id="mad4b-rate-neutral-select-init">
  (function(){'use strict';
   const saved=window.mad4bSavedRateSelections || mad4bSavedRateSelections;
   const keys=Object.keys(saved);
   function fix(){
    document.querySelectorAll('select').forEach(function(select){
     const key=keys.find(function(k){return select.name===k||select.name==='jet_engine_meta['+k+']'||select.id===k;});
     if(!key)return;
     let neutral=Array.from(select.options).find(o=>o.value==='');
     if(!neutral){neutral=new Option('— Not specified —','',false,false);select.insertBefore(neutral,select.firstChild);}
     if(select.dataset.mad4bNeutralReady!=='1'){
      select.dataset.mad4bNeutralReady='1';
      if(saved[key]===''){select.value='';}
      if(window.jQuery && window.jQuery.fn.select2)window.jQuery(select).trigger('change.select2');
     }
    });
   }
   document.addEventListener('DOMContentLoaded',fix);
   if(document.readyState!=='loading')fix();
   new MutationObserver(fix).observe(document.documentElement,{childList:true,subtree:true});
  })();
  </script>
  <?php
 }
 static function boot(){
  if(!self::bound())return;
  add_action('elementor/frontend/widget/before_render',[__CLASS__,'mark_widget'],20,1);
  add_filter('elementor/widget/render_content',[__CLASS__,'widget_content'],60,2);
  add_action('admin_footer-post.php',[__CLASS__,'admin_neutral_options'],90);
  add_action('admin_footer-post-new.php',[__CLASS__,'admin_neutral_options'],90);
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))WP_CLI::add_command('mad4b rate-departure',[__CLASS__,'cli']);
 }
}
add_action('plugins_loaded',['MAD4B_Rate_Departure_Details_V1','boot'],80);
