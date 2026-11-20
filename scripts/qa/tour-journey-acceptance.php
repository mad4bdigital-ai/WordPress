<?php
/**
 * Journey Discovery acceptance (read-only).
 * Run from WP-CLI: TOUR_JOURNEY_SITE=https://... wp eval-file ...
 */
defined('ABSPATH') || exit;
$site=(string)getenv('TOUR_JOURNEY_SITE');
if($site==='' || get_option('home')!==$site) {
  fwrite(STDERR,"FAIL: site scope mismatch\n"); exit(2);
}
$plugin=WP_PLUGIN_DIR.'/mad4b-site-control-plane';
foreach([
 'includes/class-mad4b-scp-tour-highlights-v1.php',
 'includes/class-mad4b-scp-tour-highlights-presentation-v1.php',
 'assets/tour-highlights-v1.css',
 'assets/tour-journey-ui.js',
 'wpml-config.xml'
] as $path) {
  if(!is_readable($plugin.'/'.$path)) {
    fwrite(STDERR,"FAIL: unreadable dependency ".$path."\n"); exit(3);
  }
}
if(!class_exists('MAD4B_Tour_Highlights_V1',false)
  || !class_exists('MAD4B_Tour_Highlights_Presentation_V1',false)) {
  fwrite(STDERR,"FAIL: active runtime classes missing\n"); exit(4);
}
global $wpdb;
$rows=$wpdb->get_results(
 $wpdb->prepare(
  "SELECT p.ID,m.meta_value FROM {$wpdb->posts} p
    JOIN {$wpdb->postmeta} m ON m.post_id=p.ID
    WHERE p.post_type=%s AND m.meta_key=%s AND m.meta_value LIKE %s
    ORDER BY p.ID",
  'elementor_library','_elementor_data','%tour_highlights_content%'
 ),ARRAY_A
);
// Group counts are controlled by the site-specific QA contract, not a
// permanent hardcoded assumption that every Tour has five categories.
$expectedInput=trim((string)getenv('TOUR_JOURNEY_EXPECT_GROUPS'));
if($expectedInput!=='' && (!ctype_digit($expectedInput)
   || (int)$expectedInput<1 || (int)$expectedInput>100)) {
  fwrite(STDERR,"FAIL: invalid expected groups\n");exit(2);
}
$expectedGroups=$expectedInput!==''?(int)$expectedInput:null;
$report=['site'=>$site,'document_count'=>count($rows),'passed'=>0,'errors'=>[],
 'templates'=>[],'languages'=>[],'related_types'=>[],
 'expected_related_groups'=>$expectedGroups,
 'reviewed_at_utc'=>gmdate('c')];
foreach($rows as $row) {
  $id=(int)$row['ID'];
  $nodes=json_decode((string)$row['meta_value'],true);
  if(!is_array($nodes)) { $report['errors'][]=['doc'=>$id,'code'=>'json']; continue; }
  $target=[];
  $walker=function(array $items) use (&$walker,&$target) {
    foreach($items as $node) {
      if(($node['elType']??'')==='container') {
        $roles=preg_split('/\s+/u',trim((string)($node['settings']['css_classes']??'')));
        if(in_array('tour-journey-discovery-editor',$roles,true))$target[]=$node;
      }
      $children=$node['elements']??[];
      if(is_array($children)&&$children)$walker($children);
    }
  };
  $walker($nodes);
  if(count($target)!==1) {$report['errors'][]=['doc'=>$id,'code'=>'section_count','n'=>count($target)];continue;}
  $section=$target[0];
  $field=[];$heading=[];$templates=[];
  foreach($section['elements']??[] as $child) {
    $role=(string)($child['settings']['css_classes']??'');
    if(($child['widgetType']??'')==='jet-listing-dynamic-field'
        && ($child['settings']['dynamic_field_post_meta']??'')==='tour_highlights_content')$field[]=$child;
    if(($child['widgetType']??'')==='heading')$heading[]=$child;
    if(($child['widgetType']??'')==='template')$templates[]=$child;
  }
  if(count($field)!==1||count($heading)!==1||count($templates)!==1) {
    $report['errors'][]=['doc'=>$id,'code'=>'widget_counts','field'=>count($field),'heading'=>count($heading),'template'=>count($templates)];continue;
  }
  $roles=[
    'tour-journey-content-editor'=>$field[0],
    'tour-journey-heading-editor'=>$heading[0],
    'tour-journey-related-editor'=>$templates[0]
  ];
  $missing=[];
  foreach($roles as $role=>$child){
    $dataClasses=preg_split('/\s+/u',trim((string)($child['settings']['css_classes']??'')));
    $renderClasses=preg_split('/\s+/u',trim((string)($child['settings']['_css_classes']??'')));
    // Elementor widgets emit Advanced CSS Classes from _css_classes, not css_classes.
    if(!in_array($role,$dataClasses,true)||!in_array($role,$renderClasses,true))
      $missing[]=$role;
  }
  if($missing){$report['errors'][]=['doc'=>$id,'code'=>'roles_missing','roles'=>$missing];continue;}
  $template=(int)($templates[0]['settings']['template_id']??0);
  $lang=(string)$wpdb->get_var($wpdb->prepare(
   "SELECT language_code FROM {$wpdb->prefix}icl_translations
    WHERE element_id=%d AND element_type='post_elementor_library' LIMIT 1",$id));
  // WPML may preserve the English reference in Elementor JSON while resolving
  // the corresponding translated template at render time. Test that actual
  // resolved template, rather than forcing a redundant DB rewrite.
  $resolved=(int)apply_filters('wpml_object_id',$template,'elementor_library',false,$lang);
  $templateLang=(string)$wpdb->get_var($wpdb->prepare(
   "SELECT language_code FROM {$wpdb->prefix}icl_translations
    WHERE element_id=%d AND element_type='post_elementor_library' LIMIT 1",$resolved));
  if($template<=0||$lang===''||$resolved<=0||$templateLang!==$lang
    || get_post_type($resolved)!=='elementor_library'
    || get_post_status($resolved)!=='publish'){
    $report['errors'][]=['doc'=>$id,'code'=>'template_locale_unresolvable',
      'doc_locale'=>$lang,'reference_id'=>$template,
      'resolved_id'=>$resolved,'resolved_locale'=>$templateLang];continue;
  }
  $source=(string)get_post_meta($resolved,'_elementor_data',true);
  if(substr_count($source,'"widgetType":"jet-listing-grid"')<2) {
    $report['errors'][]=['doc'=>$id,'code'=>'missing_native_listing','template'=>$resolved];continue;
  }
  $report['passed']++;
  $report['templates'][(string)$id]=$resolved;
  $report['template_references'][(string)$id]=$template;
  $report['languages'][(string)$id]=$lang;
  $listing=json_decode($source,true);
  $groups=0;
  if(is_array($listing))foreach($listing as $group) {
    $classes=(string)($group['settings']['css_classes']??'');
    if(strpos(' '.$classes.' ',' tour-place-group ')!==false)$groups++;
  }
  $report['related_types'][(string)$id]=$groups;
  if($groups<1 || ($expectedGroups!==null && $groups!==$expectedGroups))
    $report['errors'][]=['doc'=>$id,'code'=>'listing_groups','groups'=>$groups,
      'expected'=>$expectedGroups];
}
$rules=(array)(get_option('icl_sitepress_settings',[])['custom_fields_translation']??[]);
$report['display_pattern_wpml_copy_once']=(int)($rules['tour_highlights_display_pattern']??0)===3;
if(!$report['display_pattern_wpml_copy_once'])$report['errors'][]=['code'=>'wpml_display_pattern_copy_once'];
$report['native_localization_hook']=(bool)has_action('elementor/frontend/widget/before_render',
  [MAD4B_Tour_Highlights_V1::class,'localize_native_grid']);
if(!$report['native_localization_hook'])$report['errors'][]=['code'=>'native_listing_localization_hook_missing'];
$report['passed_final']=count($report['errors'])===0 && $report['document_count']===$report['passed'];
echo wp_json_encode($report,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
if(!$report['passed_final'])exit(5);
