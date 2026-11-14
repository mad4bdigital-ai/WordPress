<?php
/**
 * Plugin Name: MAD4B Rate Facet Visibility V1
 * Description: Hide redundant Package/Season selectors for an authenticated published Tour context; only live eligible Rates determine options.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Rate_Facet_Visibility_V1',false)) {
final class MAD4B_Rate_Facet_Visibility_V1 {
 private static array $cache=[];
 static function current_tour():int {
  if(is_admin() && !wp_doing_ajax())return 0;
  global $wp_the_query;
  if(!$wp_the_query instanceof WP_Query||!$wp_the_query->is_singular('tours-and-activities'))return 0;
  $id=(int)$wp_the_query->get_queried_object_id();
  return $id&&get_post_type($id)==='tours-and-activities'&&get_post_status($id)==='publish'?$id:0;
 }
 static function options(int $tour):array {
  if(isset(self::$cache[$tour]))return self::$cache[$tour];
  $out=['package'=>[],'season'=>[],'rate_ids'=>[]];
  if(!$tour||!function_exists('shared_product_eligible_tour_rate_ids'))return self::$cache[$tour]=$out;
  $ids=shared_product_eligible_tour_rate_ids($tour);
  if(!is_array($ids))return self::$cache[$tour]=$out;
  foreach($ids as $rate){$rate=absint($rate);
   if(get_post_type($rate)!=='tour-rates'||get_post_status($rate)!=='publish'||(int)get_post_meta($rate,'related_tour_id',true)!==$tour)continue;
   $out['rate_ids'][]=$rate;
   $level=absint(get_post_meta($rate,'related_package_term_id',true));
   if($level&&term_exists($level,'package_levels'))$out['package'][$level]=get_term($level,'package_levels')->name;
   $terms=wp_get_object_terms($rate,'season',['fields'=>'all']);
   if(!is_wp_error($terms))foreach($terms as $term)if($term instanceof WP_Term){
    $out['season'][(int)$term->term_id]=['slug'=>$term->slug,'name'=>$term->name];
   }
  }
  return self::$cache[$tour]=$out;
 }
 private static function company_mode():string {
  $options=get_option('look-and-feel',[]);
  $mode=is_array($options)?(string)($options['tour_rate_facets_display_mode']??''):'';
  return in_array($mode,['adaptive_labels','hide_redundant','always_select'],true)
   ?$mode:'adaptive_labels';
 }
 /**
  * Bind to the native JetSmartFilters query variable, not Elementor element
  * IDs. The related_program_term_id selector remains strictly outside this
  * policy and retains its JetEngine cruise-program visibility condition.
  */
 private static function facet_of($widget):string {
  if(!is_object($widget)||!method_exists($widget,'get_name')||
     $widget->get_name()!=='jet-smart-filters-select'||
     !method_exists($widget,'get_settings_for_display'))return '';
  $settings=$widget->get_settings_for_display();
  if(!is_array($settings)||($settings['query_id']??'')!=='tour_rates')return '';
  $raw=$settings['filter_id']??null;
  $id=is_array($raw)?absint(reset($raw)):absint($raw);
  if(!$id||get_post_status($id)!=='publish'||get_post_type($id)!=='jet-smart-filters')return '';
  $query=(string)get_post_meta($id,'_query_var',true);
  if($query==='related_package_term_id')return 'package';
  if($query==='season' && get_post_meta($id,'_source_taxonomy',true)==='season')return 'season';
  return '';
 }
 private static function active_group(string $group):bool {
  return self::active_filter($group==='package'?'related_package_term_id':'season');
 }
 private static function display_name(string $group,int $id,string $name):string {
  $taxonomy=$group==='package'?'package_levels':'season';
  $lang=(string)apply_filters('wpml_current_language','en');
  if($id>0&&$lang){
   $translated=absint(apply_filters('wpml_object_id',$id,$taxonomy,true,$lang));
   if($translated){
    $term=get_term($translated,$taxonomy);
    if($term instanceof WP_Term && $term->name!=='')return $term->name;
   }
  }
  return $name;
 }
 private static function facet_label(string $group):string {
  $lang=(string)apply_filters('wpml_current_language','en');
  $labels=[
   'en'=>['package'=>'Package','season'=>'Season'],
   'es'=>['package'=>'Paquete','season'=>'Temporada'],
   'ar'=>['package'=>'الباقة','season'=>'الموسم'],
   'fr'=>['package'=>'Forfait','season'=>'Saison'],
   'de'=>['package'=>'Paket','season'=>'Saison'],
   'it'=>['package'=>'Pacchetto','season'=>'Stagione']
  ];
  return $labels[$lang][$group]??$labels['en'][$group];
 }
 static function render_content($content,$widget,$render_args=[]) {
  if(!is_string($content)||$content===''||self::company_mode()!=='adaptive_labels'
    ||current_user_can('edit_posts')||is_preview())return $content;
  $group=self::facet_of($widget);
  if(!$group||self::active_group($group))return $content;
  $tour=self::current_tour();
  if(!$tour)return $content;
  $options=self::options($tour);
  if(!$options['rate_ids']||count($options[$group])!==1)return $content;
  $termId=(int)array_key_first($options[$group]);
  $record=reset($options[$group]);
  $name=$group==='season'&&is_array($record)
   ?(string)($record['name']??''):(string)$record;
  if($name==='')return $content; // fail open rather than fake a label
  $title=self::facet_label($group);
  $name=self::display_name($group,$termId,$name);
  return '<div class="mad4b-rate-facet-summary" data-mad4b-facet="'.esc_attr($group).
    '" role="group" aria-label="'.esc_attr($title.': '.$name).'">'.
    '<span class="mad4b-rate-facet-summary__label">'.esc_html($title).'</span>'.
    '<span class="mad4b-rate-facet-summary__value">'.esc_html($name).'</span></div>';
 }
 static function inline_css():void {
  if(!is_singular('tours-and-activities')||self::company_mode()!=='adaptive_labels')return;
  echo '<style id="mad4b-tour-rate-facet-summary-css">'.
   '.mad4b-rate-facet-summary{display:flex;align-items:center;gap:.55rem;flex-wrap:wrap;'.
   'min-height:44px;padding:8px 12px;border:1px solid #d8e2e5;border-radius:8px;'.
   'background:var(--e-global-color-secondary,#fff);color:var(--e-global-color-primary,#173e4a)}'.
   '.mad4b-rate-facet-summary__label{font-size:.78rem;opacity:.8}'.
   '.mad4b-rate-facet-summary__value{font-size:.9rem;font-weight:600}'.
   '</style>'."\n";
 }
 static function active_filter(string $field):bool {
  $path=(string)($_SERVER['REQUEST_URI']??'');
  if(str_contains($path,'/jsf/'))return true;
  return isset($_GET[$field]) && is_scalar($_GET[$field]) && trim((string)$_GET[$field])!=='';
 }
 static function should_render($allowed,$widget) {
  if(!$allowed||!is_object($widget)||!method_exists($widget,'get_name')||$widget->get_name()!=='jet-smart-filters-select')return $allowed;
  $group=self::facet_of($widget);
  if(!$group)return $allowed;
  $tour=self::current_tour();if(!$tour||current_user_can('edit_posts')||is_preview())return $allowed;
  if(self::active_group($group))return $allowed;
  $data=self::options($tour);
  if(!$data['rate_ids']||self::company_mode()==='always_select')return $allowed;
  $count=count($data[$group]);
  if($count>1)return $allowed;
  if($count===1&&self::company_mode()==='adaptive_labels')return $allowed;
  return false;
 }
 static function filter_season($options,$filterId) {
  if((int)$filterId!==66030||!is_array($options))return $options;
  $tour=self::current_tour();if(!$tour||self::active_filter('season'))return $options;
  $ctx=self::options($tour);if(!$ctx['rate_ids']||!$ctx['season'])return $options;
  $allowed=[''=>true];
  foreach($ctx['season'] as $id=>$t){$allowed[(string)$id]=true;$allowed[$t['slug']]=true;
   $language=(string)apply_filters('wpml_current_language','');
   if($language){$translated=(int)apply_filters('wpml_object_id',$id,'season',true,$language);
    $term=$translated?get_term($translated,'season'):null;
    if($term instanceof WP_Term){$allowed[(string)$term->term_id]=true;$allowed[$term->slug]=true;}}
  }
  // JetSmartFilters can pass a plain associative array or structured entries.
  // Unknown schemas are returned unchanged rather than guessing.
  if(!array_is_list($options)){
   foreach($options as $key=>$value){if(!is_scalar($value))return $options;}
   return array_filter($options,static fn($v,$k)=>isset($allowed[(string)$k]),ARRAY_FILTER_USE_BOTH);
  }
  $result=[];foreach($options as $row){
   if(!is_array($row)||!isset($row['value'])||!is_scalar($row['value']))return $options;
   if(isset($allowed[(string)$row['value']]))$result[]=$row;
  }
  return $result;
 }
 /**
  * Localized JetSmartFilters taxonomy values can be empty while the English
  * filter post is used by a translated Tour. Recover only this Rate Season
  * selector from eligible Rates of the exact published parent Tour. Do not
  * fabricate availability or change Query 310/prices.
  */
 static function restore_localized_seasons($options,$filterId) {
  if((int)$filterId!==66030 || !is_array($options)
    ||(is_admin()&&!wp_doing_ajax()))return $options;
  // Keep vendor/native values and supported schemas when already present.
  foreach($options as $key=>$value)
   if($key!=='' && is_scalar($key) && ctype_digit((string)$key))
    return $options;
  if($options && array_is_list($options))return $options;
  $tour=self::current_tour();
  if(!$tour)return $options;
  $ctx=self::options($tour);
  $seasons=$ctx['season'];
  if(class_exists('MAD4B_Rate_Season_Calendar_Integrity_V1',false)){
   $selected=MAD4B_Rate_Season_Calendar_Integrity_V1::selected_season();
   if(!empty($selected['active']) && empty($selected['invalid'])
      &&($selected['term']??null) instanceof WP_Term){
    $term=$selected['term'];
    $policy=MAD4B_Rate_Season_Calendar_Integrity_V1::policy_term_id($term);
    if($policy>0 && get_term_meta($policy,'season_enabled',true)==='1'
      &&has_term((int)$term->term_id,'season',$tour))
     $seasons[(int)$term->term_id]=['slug'=>$term->slug,'name'=>$term->name];
   }
  }
  if(!$seasons)return $options;
  $lang=(string)apply_filters('wpml_current_language','en');
  $labels=['en'=>'All Seasons','es'=>'Todas las temporadas',
   'ar'=>'كل المواسم','fr'=>'Toutes les saisons',
   'de'=>'Alle Saisons','it'=>'Tutte le stagioni'];
  $blank=isset($options['']) && is_scalar($options['']) && trim((string)$options[''])!==''
    ? (string)$options[''] : ($labels[$lang]??$labels['en']);
  $result=[''=>$blank];
  uasort($seasons,static fn($a,$b)=>strnatcasecmp((string)$a['name'],(string)$b['name']));
  foreach($seasons as $id=>$term)
   if(is_array($term)&&isset($term['name']))$result[(string)$id]=(string)$term['name'];
  return $result;
 }
 static function cli(array $args):void {
  if(($args[0]??'audit')!=='audit')WP_CLI::error('Read-only audit only');
  $out=[];foreach([60629,60623] as $tour){$opt=self::options($tour);
   $out[]=['tour'=>$tour,'rate_ids'=>$opt['rate_ids'],'package_levels'=>array_keys($opt['package']),'seasons'=>array_values($opt['season'])];}
  WP_CLI::line(wp_json_encode(['site'=>home_url(),'results'=>$out],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
 }
 static function boot():void {
  add_filter('elementor/frontend/widget/should_render',[__CLASS__,'should_render'],35,2);
  add_filter('elementor/widget/render_content',[__CLASS__,'render_content'],35,3);
  add_action('wp_head',[__CLASS__,'inline_css'],35);
  add_filter('jet-smart-filters/filters/filter-options',[__CLASS__,'filter_season'],40,2);
  add_filter('jet-smart-filters/filters/filter-options',[__CLASS__,'restore_localized_seasons'],250,2);
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))WP_CLI::add_command('mad4b rate-facets',[__CLASS__,'cli']);
 }
}
MAD4B_Rate_Facet_Visibility_V1::boot();
}
