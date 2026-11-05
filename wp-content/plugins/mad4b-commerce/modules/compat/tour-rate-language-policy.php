<?php
/** Central Rate language strategy: localized records now; shared canonical records when WPML opts out. */
defined('ABSPATH') || exit;
final class MAD4B_Tour_Rate_Language_Policy_V1 {
 const GLOBAL='mad4b_tour_rate_language_policy_global_v1';
 const META='mad4b_tour_rates_locale_policy_v1';
 const FALLBACK='mad4b_tour_rate_fallback_policy_v1';
 static function wpml_translatable():bool {
  $settings=get_option('icl_sitepress_settings',[]);
  if(!is_array($settings))return false;
  $value=$settings['custom_posts_sync_option']['tour-rates']??0;
  return in_array((int)$value,[1,2],true);
 }
 static function canonical_tour(int $tour):int {
  if($tour<1 || get_post_type($tour)!=='tours-and-activities' || get_post_status($tour)!=='publish')return 0;
  $default=(string)apply_filters('wpml_default_language','');
  if($default==='')return $tour;
  $original=(int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,$default);
  return $original>0 && get_post_type($original)==='tours-and-activities'
   && get_post_status($original)==='publish'?$original:0;
 }
 static function mode(int $tour=0):array {
  $global=(string)get_option(self::GLOBAL,'translated');
  if(!in_array($global,['translated','shared'],true))$global='translated';
  $requested=$global;
  if($tour>0 && get_post_type($tour)==='tours-and-activities'){
   $local=(string)get_post_meta($tour,self::META,true);
   if(!in_array($local,['translated','shared'],true)){
    $source=self::canonical_tour($tour);
    $local=$source>0 && $source!==$tour?(string)get_post_meta($source,self::META,true):'';
   }
   if(in_array($local,['translated','shared'],true))$requested=$local;
  }
  $translatable=self::wpml_translatable();
  return ['requested'=>$requested,'effective'=>$translatable?$requested:'shared',
   'wpml_translatable'=>$translatable,
   'reason'=>$translatable?'configured_mode':'wpml_rate_type_not_translatable'];
 }
 /** Fail-closed fallback, using WPML's *current* default language as authority. */
 static function fallback_setting(int $tour):string {
  $value=(string)get_option(self::FALLBACK,'disabled');
  if(!in_array($value,['disabled','missing_locale_catalog'],true))$value='disabled';
  if($tour<1 || get_post_type($tour)!=='tours-and-activities')return $value;
  $local=(string)get_post_meta($tour,self::FALLBACK,true);
  if(in_array($local,['disabled','missing_locale_catalog'],true))return $local;
  $source=self::canonical_tour($tour);
  $inherited=$source>0&&$source!==$tour?(string)get_post_meta($source,self::FALLBACK,true):'';
  return in_array($inherited,['disabled','missing_locale_catalog'],true)?$inherited:$value;
 }
 static function catalog_exists(int $tour):bool {
  if($tour<1 || get_post_type($tour)!=='tours-and-activities')return false;
  global $wpdb;
  $id=$wpdb->get_var($wpdb->prepare(
   "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID
    WHERE p.post_type=%s AND p.post_status=%s AND m.meta_key=%s AND m.meta_value=%s LIMIT 1",
   'tour-rates','publish','related_tour_id',(string)$tour));
  return (int)$id>0;
 }
 static function fallback_decision(int $tour):array {
  $source=self::canonical_tour($tour);
  $default=(string)apply_filters('wpml_default_language','');
  $policy=self::fallback_setting($tour);
  $result=['policy'=>$policy,'fallback'=>false,'reason'=>'disabled',
   'default_language'=>$default,'default_tour_id'=>$source];
  if(self::mode($tour)['effective']!=='translated'){$result['reason']='non_translated_mode';return $result;}
  if($policy!=='missing_locale_catalog')return $result;
  if(!$source || $source===$tour){$result['reason']='no_distinct_default_tour';return $result;}
  if(self::catalog_exists($tour)){$result['reason']='localized_catalog_exists';return $result;}
  if(!self::catalog_exists($source)){$result['reason']='default_catalog_missing';return $result;}
  $result['fallback']=true;$result['reason']='localized_catalog_missing';
  return $result;
 }
 static function eligible_rate(int $tour,int $rate):bool {
  if($tour<1 || $rate<1 || get_post_type($rate)!=='tour-rates' || get_post_status($rate)!=='publish')return false;
  $policy=self::mode($tour);
  $parent=(int)get_post_meta($rate,'related_tour_id',true);
  $expected=(int)self::resolve_context($tour)['query_tour_id'];
  // Basic ownership check only. Final eligibility, dates, market, currency and inventory
  // remain exclusively enforced by the existing financial engine.
  return $expected>0 && $parent===$expected;
 }
 static function resolve_context(int $tour):array {
  $mode=self::mode($tour);
  $source=self::canonical_tour($tour);
  $fallback=self::fallback_decision($tour);
  $use_default=$mode['effective']==='shared'||$fallback['fallback'];
  return $mode+['tour_id'=>$tour,'canonical_tour_id'=>$source,
   'default_language'=>(string)apply_filters('wpml_default_language',''),
   'fallback'=>$fallback,'resolved_source'=>$use_default?'default_language':'localized',
   'query_tour_id'=>$use_default?$source:$tour];
 }
 static function restrict_query($args,$query) {
  if(!is_array($args)||!is_object($query)||!in_array((int)($query->id??0),[310,313],true))return $args;
  if(!in_array('tour-rates',(array)($args['post_type']??[]),true))return $args;
  $tour=function_exists('shared_product_verified_presentation_tour_id')
   ?(int)shared_product_verified_presentation_tour_id():0;
  if($tour<=0 && !wp_doing_ajax() && is_singular('tours-and-activities'))
   $tour=absint(get_queried_object_id());
  if($tour<=0 || get_post_type($tour)!=='tours-and-activities'
   || get_post_status($tour)!=='publish')return $args;
  $context=self::resolve_context($tour);
  if($context['resolved_source']!=='default_language'||$context['query_tour_id']<=0)return $args;
  $clauses=(array)($args['meta_query']??[]);
  $replaced=false;
  foreach($clauses as $key=>$row) {
   if(!is_array($row) || ($row['key']??'')!=='related_tour_id')continue;
   $clauses[$key]['value']=(string)$context['query_tour_id'];
   $clauses[$key]['compare']='=';
   $replaced=true;
  }
  if(!$replaced)$clauses[]=['key'=>'related_tour_id','value'=>(string)$context['query_tour_id'],'compare'=>'='];
  $args['meta_query']=$clauses;
  // JetEngine stores [0] as an empty explicit inclusion. In verified fallback only,
  // the exact source relationship and the native date/status predicates govern inclusion.
  if(($args['post__in']??null)===[0])$args['post__in']=[];
  $default=(string)apply_filters('wpml_default_language','');
  if($default!=='')$args['lang']=$default;
  // No broad WPML unfiltering. Query 310 and visibility Query 313 preserve their native predicates.
  return $args;
 }
 /** Resolve source-owned Rate Archive mode in translated Elementor shells.
  * Operates only when the current tour actually uses the WPML default fallback. */
 static function rate_archive_visibility($nodes,$template_id) {
  if(!is_array($nodes)||is_admin()||!is_singular('tours-and-activities'))return $nodes;
  $tour=absint(get_queried_object_id());
  if(!$tour || get_post_type($tour)!=='tours-and-activities')return $nodes;
  $ctx=self::resolve_context($tour);
  if($ctx['query_tour_id']<1)return $nodes;
  // A published localized catalog is authoritative. Never replace local
  // financial records with default-language prices for visibility parity.
  $is_fallback=$ctx['resolved_source']==='default_language';
  if(!$is_fallback && !self::catalog_exists($tour))return $nodes;
  $lang=(string)apply_filters('wpml_current_language','');
  $translated=absint(apply_filters('wpml_object_id',48317,'elementor_library',false,$lang));
  if(!in_array((int)$template_id,array_filter([48317,$translated]),true))return $nodes;
  $mode=(string)get_post_meta($tour,'tour_rates_display_style',true);
  if(!in_array($mode,['Accordion','Grid Listing'],true))return $nodes;
  $changed=false;
  $walk=function($items)use(&$walk,$mode,&$changed,$is_fallback){
   foreach($items as &$item){
    if(!is_array($item))continue;
    $id=(string)($item['id']??'');
    if($id==='854289f'||$id==='eb639ca'){
     $wanted=($mode==='Accordion'?'854289f':'eb639ca');
     if($id===$wanted && (int)($item['settings']['custom_query_id']??0)===310){
      // WPML Structural Sync prunes pending locale widgets at priority 9000.
      // For an explicitly resolved Rates fallback, this source-backed Rate
      // Listing has no local commercial copy to translate: preserve its wrapper
      // in memory, never modify saved Elementor templates or other nodes.
      $classes=(string)($item['settings']['_css_classes']??'');
      $item['settings']['_css_classes']=trim(preg_replace('/(?:^|\s)mad4b-wpml-needs-translation(?=\s|$)/',' ',$classes));
      if($is_fallback){
       $item['settings']['jedv_enabled']='';
       $item['settings']['jedv_conditions']=[];
      }
      $changed=true;
     }
    }
    if(!empty($item['elements'])&&is_array($item['elements']))$item['elements']=$walk($item['elements']);
   }
   unset($item);
   return $items;
  };
  return $walk($nodes);
 }
 static function option_fields($fields) {
  if(!is_array($fields))return $fields;
  foreach($fields as &$field){
   if(($field['name']??'')===self::META){
   $field['options']=[
    ['key'=>'','value'=>'Use company / canonical tour setting'],
    ['key'=>'translated','value'=>'Translated Rates — WPML localized records'],
    ['key'=>'shared','value'=>'Shared Rates — WPML default-language records'],
   ];
   }
   if(($field['name']??'')===self::FALLBACK)$field['options']=[
    ['key'=>'','value'=>'Use global / default-tour setting'],
    ['key'=>'disabled','value'=>'Disabled'],
    ['key'=>'missing_locale_catalog','value'=>'Missing locale catalog → WPML default language'],
   ];
  }
  unset($field);
  return $fields;
 }
 static function admin_menu():void {
  add_submenu_page('edit.php?post_type=tours-and-activities',
   'Rates language strategy','Rates Language Strategy','manage_options',
   'mad4b-rate-language-policy',[__CLASS__,'settings_page']);
 }
 static function settings_page():void {
  if(!current_user_can('manage_options'))return;
  $state=self::mode(0);
  echo '<div class="wrap"><h1>Tour Rates · Language Strategy</h1>';
  echo '<p>Default: translated Rate records. Shared mode and fallback resolve the current WPML default language dynamically, without copying commercial prices. Existing rates and currencies are not modified.</p>';
  echo '<p>WPML Rate type: <strong>'.($state['wpml_translatable']?'Translatable':'Not translatable').'</strong>; effective mode: <strong>'.esc_html($state['effective']).'</strong>.</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  wp_nonce_field('mad4b_rate_language_policy');
  echo '<input type="hidden" name="action" value="mad4b_rate_language_policy">';
  echo '<label for="mad4b-rates-mode">Default language strategy</label> ';
  echo '<select id="mad4b-rates-mode" name="mode">';
  foreach(['translated'=>'Translated — use localized Rate records','shared'=>'Shared — use WPML default-language Rate records'] as $value=>$label)
   echo '<option value="'.esc_attr($value).'" '.selected($state['requested'],$value,false).'>'.esc_html($label).'</option>';
  echo '</select>';
  $fallback=self::fallback_setting(0);
  echo '<p><label for="mad4b-rate-fallback">Fallback Resolve Policy</label> ';
  echo '<select id="mad4b-rate-fallback" name="fallback">';
  foreach(['disabled'=>'Disabled (default)','missing_locale_catalog'=>'Missing localized Rate catalog → WPML default-language Rates'] as $v=>$label)
   echo '<option value="'.esc_attr($v).'" '.selected($fallback,$v,false).'>'.esc_html($label).'</option>';
  echo '</select></p>';
  echo '<p>The fallback does not override empty results caused by dates, market eligibility, inventory or stop-sale. Those remain financial-engine decisions.</p>';
  submit_button('Save language strategy');
  echo '</form><p>Per-tour override: edit the Tour Page Options · Presentation &amp; Section Order JetEngine meta box.</p></div>';
 }
 static function save_settings():void {
  if(!current_user_can('manage_options')||!check_admin_referer('mad4b_rate_language_policy'))wp_die('Unauthorized');
  $value=sanitize_key((string)wp_unslash($_POST['mode']??''));
  if(!in_array($value,['translated','shared'],true))wp_die('Invalid Rate language strategy');
  $fallback=sanitize_key((string)wp_unslash($_POST['fallback']??'disabled'));
  if(!in_array($fallback,['disabled','missing_locale_catalog'],true))wp_die('Invalid fallback resolve policy');
  update_option(self::GLOBAL,$value,false);
  update_option(self::FALLBACK,$fallback,false);
  do_action('litespeed_purge_all');
  wp_safe_redirect(admin_url('edit.php?post_type=tours-and-activities&page=mad4b-rate-language-policy&updated=1'));
  exit;
 }
 static function init():void {
  add_filter('jet-engine/query-builder/types/posts-query/args',[__CLASS__,'restrict_query'],90,2);
  add_filter('elementor/frontend/builder_content_data',[__CLASS__,'rate_archive_visibility'],8900,2);
  add_filter('jet-engine/meta-boxes/raw-fields',[__CLASS__,'option_fields'],31,2);
  add_action('admin_menu',[__CLASS__,'admin_menu'],31);
  add_action('admin_post_mad4b_rate_language_policy',[__CLASS__,'save_settings']);
 }
}
MAD4B_Tour_Rate_Language_Policy_V1::init();
