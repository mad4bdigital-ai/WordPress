<?php
/** Read-only original CSS-owner acceptance for single-Tour rate templates. */
defined('ABSPATH')||exit;
if(!class_exists('MAD4B_Elementor_Widget_CSS_Integrity_V1',false))
 WP_CLI::error('Native Elementor CSS ownership unavailable');
$fields=maybe_unserialize(
  (string) $GLOBALS['wpdb']->get_var(
    $GLOBALS['wpdb']->prepare(
      "SELECT meta_fields FROM ".$GLOBALS['wpdb']->prefix."jet_post_types WHERE id=%d",192)));
$found=array_values(array_filter((array)$fields,static fn($f)=>
 is_array($f)&&($f['name']??null)==='tour_rate_facets_display_mode'));
if(count($found)!==1 || ($found[0]['default_val']??null)!=='adaptive_labels')
 WP_CLI::error('JetEngine Look & Feel schema missing expected mode');
$choices=array_column($found[0]['options']??[],'key');
if($choices!==['adaptive_labels','hide_redundant','always_select'])
 WP_CLI::error('JetEngine facet modes changed unexpectedly');
if((get_option('look-and-feel',[])['tour_rate_facets_display_mode']??null)!=='adaptive_labels')
 WP_CLI::error('Company option not enabled');
$id=48317;
if(!in_array($id,MAD4B_Elementor_Widget_CSS_Integrity_V1::template_ids(),true))
 WP_CLI::error('Rate template not enrolled in native CSS checker');
$a=MAD4B_Elementor_Widget_CSS_Integrity_V1::audit($id);
if(($a['status']??'')!=='complete' || ($a['expected']??0)<3)
 WP_CLI::error('Original rate template CSS incomplete');
$expected=['container-border:elementor-element-060f497:groove'];
$css=file_get_contents(WP_CONTENT_DIR.'/uploads/elementor/css/post-'.$id.'.css');
if(MAD4B_Elementor_Widget_CSS_Integrity_V1::missing_selectors($css,$expected)!==[])
 WP_CLI::error('Rate border selector not compiled');
if(count(MAD4B_Elementor_Widget_CSS_Integrity_V1::missing_selectors(
    'body{color:black}', $expected))!==1)
 WP_CLI::error('CSS integrity checker failed to detect missing border');
WP_CLI::success(wp_json_encode([
 'site'=>home_url(),'source'=>'original-elementor','document_id'=>$id,
 'native_css_status'=>$a['status'],'company_facet_policy'=>'adaptive_labels',
 'program_visibility_untouched'=>true,'read_only'=>true
],JSON_UNESCAPED_SLASHES));
