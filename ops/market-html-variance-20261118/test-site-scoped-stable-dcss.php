<?php
declare(strict_types=1);
define('ABSPATH','/');
$home=false;$archive=false;$login=false;$admin=false;$ajax=false;
$scope=true;$wooSession=false;$env='production';$siteType='tours-and-activities';
$siteHost='wayfatours.com';
$options=['mad4b_perf_stable_dcss_v1'=>'on','mad4b_perf_stable_tour_dcss_v1'=>'on',
 'mad4b_perf_archive_stable_dcss_v1'=>'off','mad4b_perf_private_home_stable_dcss_v1'=>'off',
 'mad4b_stable_dcss_post_types_v1'=>['singular'=>['tours-and-activities'],'archive'=>['tours-and-activities']]];
function add_action(...$args) {}
function add_filter(...$args) {}
function is_admin(){global $admin;return $admin;}
function wp_doing_ajax(){global $ajax;return $ajax;}
function is_user_logged_in(){global $login;return $login;}
function is_post_type_archive($type){global $archive,$siteType;return $type===$siteType && $archive;}
function get_post_type_archive_link($type){global $siteHost;return 'https://'.$siteHost.'/'.$type.'/';}
function post_type_exists($type){return in_array($type,['tours-and-activities','excursions','private-note'],true);}
function get_post_type_object($type){return (object)['public'=>$type!=='private-note','publicly_queryable'=>$type!=='private-note','has_archive'=>$type!=='private-note'];}
function is_singular($type){return false;}
function wp_parse_url($u,$part){return parse_url($u,$part);}
function get_option($k,$default=null){global $options;return $options[$k]??$default;}
function wp_get_environment_type(){global $env;return $env;}
function jet_engine(){global $mock;return $mock;}
class MAD4B_Shared_First_Paint_Performance{
 public static function home_view(){global $home;return $home;}
}
class MAD4B_Experience_Site_Scope_V1{
 public static function bound(){global $scope;return $scope;}
}
class WCTestSession{public function has_session(){global $wooSession;return $wooSession;}}
class WCTestWC{public $session;public function __construct(){$this->session=new WCTestSession;}}
function WC(){static $obj;return $obj??=new WCTestWC;}
class ListingObject{public $term_id=23;}
class ListingData{
 public function get_current_object(){return new ListingObject;}
 public function get_current_object_id($object){return 23;}
 public function get_current_object_unique_id($object){return '12345678902345';}
}
$mock=(object)['listings'=>(object)['data'=>new ListingData]];
$file=getenv('MAD4B_STABLE_DCSS_TEST_PLUGIN');
if(!$file || !is_file($file))throw new RuntimeException('MISSING_EXACT_PLUGIN');
require_once $file;
$cls='MAD4B_Stable_Listing_CSS_Identity';
$method=new ReflectionMethod($cls,'enabled');
$counter=0;
function check($name,$good){global $counter;$counter++;if(!$good){fwrite(STDERR,"FAIL $name\n");exit(7);}echo "PASS $name\n";}
$_SERVER=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/tours-and-activities/','QUERY_STRING'=>''];$_GET=[];
check('archive feature off is inert',!$method->invoke(null));
$archive=true;$cls::capture_archive_route();
check('archive exact path cannot override off setting',!$method->invoke(null));
$options['mad4b_perf_archive_stable_dcss_v1']='on';
check('archive admitted only with exact activated policy',$method->invoke(null));
$login=true;$wooSession=true;
check('authenticated archive with Woo session excluded',!$method->invoke(null));
$login=false;$wooSession=false;
$object=new ListingObject;
$old='elementor-dcss-12345678902345';
$expected='elementor-dcss-mad4b-'.substr(hash('sha256',ListingObject::class.':23'),0,16);
$classes=['jet-listing-grid__item',$old,'other-class'];
$converted=$cls::item_classes($classes,$object,0,null);
check('archive listing identity stable and class-preserving',$converted===['jet-listing-grid__item',$expected,'other-class']);
$css='.'.$old.'{color:#123;}';
check('archive inline CSS selector matches normalized item selector',
 $cls::css_content($css,40,[])==='.'. $expected.'{color:#123;}');
check('arbitrary CSS without known selector unchanged',
 $cls::css_content('body{color:#111}',40,[])==='body{color:#111}');
$nested='<li class="jet-listing-grid__item jet-listing-dynamic-post-60471 jet-listing-grid__list_item elementor-dcss-1234567890123456" data-post-id="60471" >'.
 '<div data-elementor-id="25102"></div><style>.elementor-dcss-1234567890123456{display:block}</style></li>';
$nestedExpected='elementor-dcss-mad4b-'.substr(hash('sha256','60471:25102'),0,16);
$normalized=$cls::normalize_buffer($nested);
check('JetEngine list_item secondary class stabilizes outer li and CSS',
 str_contains($normalized,$nestedExpected) && !str_contains($normalized,'elementor-dcss-1234567890123456'));
$wrong=str_replace('data-post-id="60471"','data-post-id="12"',$nested);
check('unverified data-post-id never normalized',
 $cls::normalize_buffer($wrong)===$wrong);
check('same object identity deterministic across new objects',
 $cls::item_classes($classes,new ListingObject,1,null)===$converted);
$_SERVER['QUERY_STRING']='date=tomorrow';
check('archive filters fail closed',!$method->invoke(null));
$_SERVER['QUERY_STRING']='';
$_SERVER['REQUEST_METHOD']='POST';
check('archive POST excluded',!$method->invoke(null));
$_SERVER['REQUEST_METHOD']='GET';$ajax=true;
check('AJAX excluded',!$method->invoke(null));
$ajax=false;$admin=true;
check('admin excluded',!$method->invoke(null));
$admin=false;$scope=false;
check('missing site authority excluded',!$method->invoke(null));
$scope=true;
$archive=false;$home=true;$login=true;$_SERVER['REQUEST_URI']='/';
check('authenticated home off by default',!$method->invoke(null));
$options['mad4b_perf_private_home_stable_dcss_v1']='on';
check('authenticated home can have CSS-only identity',$method->invoke(null));
check('logged-in CSS and classes share same stable identity',
 $cls::item_classes($classes,$object,0,null)===$converted &&
 $cls::css_content($css,40,[])==='.'. $expected.'{color:#123;}');
$wooSession=true;
check('authenticated Woo session excluded',!$method->invoke(null));
$wooSession=false;$login=false;
check('original anonymous home policy preserved',$method->invoke(null));
$home=false;$_SERVER['REQUEST_URI']='/checkout/';
check('other private routes excluded',!$method->invoke(null));
// A separate WordPress site uses a different canonical route and post type.
$siteHost='other.example';$siteType='excursions';
$options['mad4b_stable_dcss_post_types_v1']=['singular'=>['excursions'],'archive'=>['excursions']];
$archive=true;$_SERVER['REQUEST_URI']='/excursions/';
$cls::capture_archive_route();
check('second site custom archive and post type works',$method->invoke(null));
check('second site deterministic object CSS',
 $cls::item_classes($classes,$object,0,null)===$converted);
$_SERVER['REQUEST_URI']='/tours-and-activities/';
check('wrong current path rejected',!$method->invoke(null));
$_SERVER['REQUEST_URI']='/excursions/';
$options['mad4b_stable_dcss_post_types_v1']=['singular'=>['private-note'],'archive'=>['private-note']];
check('non-public post type disallowed',!$method->invoke(null));
$options['mad4b_stable_dcss_post_types_v1']=['singular'=>['excursions'],'archive'=>['excursions']];
$options['mad4b_perf_archive_stable_dcss_v1']='off';
check('second site default-off honored',!$method->invoke(null));
$options['mad4b_perf_archive_stable_dcss_v1']='on';
$archive=false;
check('wrong main query rejected',!$method->invoke(null));
echo "SITE_SCOPED_STABLE_DCSS_QA ".$counter." PASS\n";
