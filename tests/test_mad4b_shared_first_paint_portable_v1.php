<?php
/** @contract MAD4B Shared First-Paint Performance v1.0.4 site-portability */
define('ABSPATH','/');
define('WP_CONTENT_DIR','/tmp/mad4b-performance-fixture/wp-content');
$GLOBALS['blog']=1;
$GLOBALS['lang']='en';
$GLOBALS['bound']=true;
$GLOBALS['env']=getenv('MAD4B_SITE_ENV')?:'staging';
$GLOBALS['args']=[
 1=>[
 'home'=>'https://tours.dev.mad4b.com','mad4b_scp_brand_context_profile_v1'=>['site_uuid'=>'123e4567-e89b-12d3-a456-426614174000'],
 'page_on_front'=>101,
 'mad4b_perf_city_image_widget_id_v1'=>'95d1c9c',
 'mad4b_perf_brand_image_widget_id_v1'=>'d570071'
 ],
 2=>[
 'home'=>'https://wayfatours.com','mad4b_scp_brand_context_profile_v1'=>['site_uuid'=>'123e4567-e89b-12d3-a456-426614174001'],
 'page_on_front'=>202,
 'mad4b_perf_city_image_widget_id_v1'=>'',
 'mad4b_perf_brand_image_widget_id_v1'=>''
 ]
];
$GLOBALS['currPage']=101;$GLOBALS['isFront']=true;$GLOBALS['isPage']=true;$GLOBALS['admin']=false;$GLOBALS['logged']=false;$GLOBALS['preview']=false;$GLOBALS['ajax']=false;
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['QUERY_STRING']='';$_SERVER['REQUEST_URI']='/';$_SERVER['HTTP_COOKIE']='';
class MAD4B_Experience_Site_Scope_V1 {public static function bound(){return $GLOBALS['bound'];}}
function add_action(...$args){}
function add_filter(...$args){}
function get_current_blog_id(){return $GLOBALS['blog'];}
function get_option($key,$default=null){return $GLOBALS['args'][$GLOBALS['blog']][$key]??$default;}
function apply_filters($tag,$value,...$others){
 if($tag==='wpml_current_language')return $GLOBALS['lang'];
 if($tag==='wpml_object_id')return $value;
 return $value;
}
function wp_get_environment_type(){return $GLOBALS['env'];}
function is_admin(){return $GLOBALS['admin'];}
function wp_doing_ajax(){return $GLOBALS['ajax'];}
function is_user_logged_in(){return $GLOBALS['logged'];}
function is_preview(){return $GLOBALS['preview'];}
function is_front_page(){return $GLOBALS['isFront'];}
function is_page(){return $GLOBALS['isPage'];}
function get_queried_object_id(){return $GLOBALS['currPage'];}
function get_permalink($id){return rtrim(get_option('home'),'/').'/';}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function wp_dequeue_style($x){}
function ok($name,$yes){if(!$yes)throw new RuntimeException('FAIL '.$name);echo "PASS $name\n";}
$path=getenv('MAD4B_PERF_SOURCE');
if(!$path||!is_file($path))throw new RuntimeException('missing source file');
require $path;
$cls=MAD4B_Shared_First_Paint_Performance::class;
$owner=(new ReflectionMethod($cls,'owner'));$owner->setAccessible(true);
$city=(new ReflectionMethod($cls,'city_widget_selector'));$city->setAccessible(true);
$brand=(new ReflectionMethod($cls,'brand_widget_id'));$brand->setAccessible(true);
$css=(new ReflectionMethod($cls,'safe_public_css'));$css->setAccessible(true);
ok('Staging own tenant bound',$owner->invoke(null)===true);
ok('Own configured Elementor selector',$city->invoke(null)==='.elementor-element-95d1c9c');
ok('Own configured logo widget',$brand->invoke(null)==='d570071');
ok('Anonymous canonical CSS permitted',$css->invoke(null)===true);
$GLOBALS['lang']='fr';ok('WPML locale not mistaken for another site',$owner->invoke(null)===true);
$GLOBALS['blog']=2;$GLOBALS['currPage']=202;$GLOBALS['lang']='es';
ok('Second company receives separate authority',$owner->invoke(null)===true);
ok('Second company no imported city selector',$city->invoke(null)==='');
ok('Second company no imported brand widget',$brand->invoke(null)==='');
$widget=new class{
 public function get_id(){return 'd570071';}
 public function get_name(){return 'image';}
};
$raw='<img src="https://wayfatours.com/wp-content/uploads/company-brands/wayfa/logo.png">';
ok('Target logo HTML unchanged without its own opt-in',$cls::responsive_brand_image($raw,$widget)===$raw);
ob_start();$cls::mobile_city_lcp();$css_out=ob_get_clean();
ok('Second company does not preload foreign city assets',$css_out==='');
ob_start();$cls::local_city_card_assets();$assets=ob_get_clean();
ok('Second company never injects unconfigured city CSS',$assets==='');
$GLOBALS['args'][2]['mad4b_perf_city_image_widget_id_v1']='</style>';
ok('Unsafe Elementor selector rejected',$city->invoke(null)==='');
$GLOBALS['args'][2]['mad4b_perf_city_image_widget_id_v1']='88ffaa1';
ok('Company-local selector accepted',$city->invoke(null)==='.elementor-element-88ffaa1');
$GLOBALS['args'][2]['mad4b_perf_brand_image_widget_id_v1']='not a selector';
ok('Invalid brand widget selector rejected',$brand->invoke(null)==='');
$_SERVER['QUERY_STRING']='mad4b_predictive_css_probe=1';
$GLOBALS['env']='production';
ok('No public QA CSS in Production',$css->invoke(null)===false);
$GLOBALS['env']='staging';
ok('QA CSS only in Staging',$css->invoke(null)===true);
$_SERVER['QUERY_STRING']='unexpected=1';
ok('Unknown public query denied',$css->invoke(null)===false);
$_SERVER['QUERY_STRING']='';$_SERVER['HTTP_COOKIE']='woocommerce_cart_hash=private';
ok('Cart HTML not promoted to public CSS',$css->invoke(null)===false);
$_SERVER['HTTP_COOKIE']='';
$GLOBALS['isFront']=false;$GLOBALS['isPage']=false;
ok('Non-homepage denied',$css->invoke(null)===false);
echo "PASS_SHARED_FIRST_PAINT_PORTABLE\n";
