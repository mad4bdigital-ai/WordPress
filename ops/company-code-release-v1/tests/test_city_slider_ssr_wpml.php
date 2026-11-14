<?php
/** Hermetic native City Slider + WPML guard tests; no WordPress bootstrap or DB writes. */
error_reporting(E_ALL);
if (!defined('ABSPATH')) define('ABSPATH', '/var/www/html/');
$dir = sys_get_temp_dir().'/mad4b-city-ssr-'.bin2hex(random_bytes(5));
mkdir($dir.'/uploads/elementor/css', 0700, true);
define('WP_CONTENT_DIR', $dir);
$GLOBALS['mock_lang']='en';
$GLOBALS['mock_host']='site.test';
$GLOBALS['mock_map']=['en'=>101,'es'=>202];
$GLOBALS['mock_options']=['mad4b_city_slider_firstpaint_geometry_v1'=>'on',
 'mad4b_perf_city_image_widget_id_v1'=>'abcde12','elementor_active_kit'=>17];
$GLOBALS['mock_raw']=['101'=>str_repeat('something ',26).'"abcde12"',
 '202'=>str_repeat('otro tipo ',26).'"abcde12"'];
$GLOBALS['mock_css']=['101'=>str_repeat('.city{color:#123;} ',9),
 '202'=>str_repeat('.ciudad{color:#abc;} ',9)];
foreach ($GLOBALS['mock_css'] as $id=>$css) file_put_contents($dir.'/uploads/elementor/css/post-'.$id.'.css',$css);
$entries=[];
foreach (['en'=>101,'es'=>202] as $lang=>$id) {
 $entries[$lang]=['status'=>'pass','locale'=>$lang,'listing_id'=>$id,
  'city_image_widget_id'=>'abcde12','measured_spacing_px'=>0,'tablet_columns'=>2,
  'listing_source_sha256'=>hash('sha256',$GLOBALS['mock_raw'][(string)$id]),
  'listing_css_sha256'=>hash('sha256',$GLOBALS['mock_css'][(string)$id]),
  'sampled_widths'=>[768,820,991]];
}
$GLOBALS['mock_options']['mad4b_city_slider_firstpaint_geometry_proofs_v1']=[
 'contract'=>'mad4b.native-city-slider.ssr-geometry-proof.v1',
 'site_host'=>'site.test','base_listing_id'=>101,'elementor_kit'=>17,
 'mobile_breakpoint'=>767,'tablet_breakpoint'=>991,'entries'=>$entries];
class MAD4B_Shared_First_Paint_Performance {public static function home_view(): bool {return true;}}
function is_admin(){return false;}
function wp_doing_ajax(){return false;}
function is_user_logged_in(){return false;}
function is_front_page(){return true;}
function is_tax($x=null){return false;}
function is_preview(){return false;}
function get_option($name,$default=null){return $GLOBALS['mock_options'][$name]??$default;}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function home_url($suffix='/'){return 'https://'.$GLOBALS['mock_host'].$suffix;}
function get_post_status($id){return in_array($id,[17,101,202],true)?'publish':false;}
function get_post_meta($id,$key,$single=true){
 if ($key==='_elementor_page_settings'&&$id===17) return ['viewport_mobile'=>767,'viewport_tablet'=>991];
 if ($key==='_elementor_data') return $GLOBALS['mock_raw'][(string)$id]??'';
 return null;
}
function absint($v){return abs((int)$v);}
function apply_filters($name,$value,...$args){
 if ($name==='wpml_current_language') return $GLOBALS['mock_lang'];
 if ($name==='wpml_object_id')return $GLOBALS['mock_map'][$args[2]??'']??0;
 return $value;
}
function add_action(...$args){return true;}
function add_filter(...$args){return true;}
require '/var/www/html/wp-content/mu-plugins/419-mad4b-city-card-shape-policy.php';
function output_css(){ob_start();MAD4B_City_Card_Shape_Policy_V1::early_slider_geometry();return ob_get_clean();}
$checks=0;
function yes($condition,$label){global $checks;$checks++;if (!$condition)throw new RuntimeException('FAILED '.$label);}
try {
 $english=output_css();yes(str_contains($english,'jet-listing-grid--101'),'English native listing');
 yes(str_contains($english,'min-width:768px')&&str_contains($english,'max-width:991px'),'Kit responsive boundaries');
 yes(str_contains($english,':not(.swiper-initialized)'),'Leaves initialized Swiper alone');
 $GLOBALS['mock_lang']='es';$espanol=output_css();yes(str_contains($espanol,'jet-listing-grid--202'),'Spanish WPML listing');
 yes(!str_contains($espanol,'jet-listing-grid--101'),'No EN template leakage');
 $GLOBALS['mock_map']['es']=101;yes(output_css()==='','Reject translation graph drift');$GLOBALS['mock_map']['es']=202;
 $GLOBALS['mock_options']['mad4b_city_slider_firstpaint_geometry_proofs_v1']['entries']['es']['listing_source_sha256']=str_repeat('0',64);
 yes(output_css()==='','Reject Elementor source drift');
 $GLOBALS['mock_options']['mad4b_city_slider_firstpaint_geometry_proofs_v1']['entries']=$entries;
 $GLOBALS['mock_options']['mad4b_city_slider_firstpaint_geometry_proofs_v1']['entries']['es']['listing_css_sha256']=str_repeat('0',64);
 yes(output_css()==='','Reject CSS drift');
 $GLOBALS['mock_options']['mad4b_city_slider_firstpaint_geometry_proofs_v1']['entries']=$entries;
 $GLOBALS['mock_options']['mad4b_city_slider_firstpaint_geometry_proofs_v1']['site_host']='other.site';yes(output_css()==='','Reject another tenant proof');
 $GLOBALS['mock_options']['mad4b_city_slider_firstpaint_geometry_proofs_v1']['site_host']='site.test';
 $GLOBALS['mock_options']['mad4b_city_slider_firstpaint_geometry_v1']='off';yes(output_css()==='','Off mode no-op');
 $GLOBALS['mock_options']['mad4b_city_slider_firstpaint_geometry_v1']='on';
 $GLOBALS['mock_options']['mad4b_city_slider_firstpaint_geometry_proofs_v1']['entries']['es']['sampled_widths']=[820];
 yes(output_css()==='','Incomplete width sampling fails closed');
 $GLOBALS['mock_options']['mad4b_city_slider_firstpaint_geometry_proofs_v1']['entries']=$entries;
 yes(str_contains(output_css(),'jet-listing-grid--202'),'Restore approved source');
 echo 'PASS '.$checks.'/'.$checks.' city-slider native WPML source, CSS and tenant guards'.PHP_EOL;
} finally {
 foreach (array_keys($GLOBALS['mock_css']) as $id) @unlink($dir.'/uploads/elementor/css/post-'.$id.'.css');
 @rmdir($dir.'/uploads/elementor/css');@rmdir($dir.'/uploads/elementor');@rmdir($dir.'/uploads');@rmdir($dir);
}
