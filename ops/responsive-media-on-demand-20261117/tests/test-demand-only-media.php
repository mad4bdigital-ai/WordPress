<?php
// Run offline in the same PHP/Imagick container as the WordPress site.
error_reporting(E_ALL);
define('ABSPATH','/fake/');define('HOUR_IN_SECONDS',3600);
$dir=sys_get_temp_dir().'/mad4b-demand-test-'.getmypid();
mkdir($dir.'/uploads',0700,true);define('WP_CONTENT_DIR',$dir);
$GLOBALS['options']=['mad4b_responsive_media_policy_v1'=>[
 'enabled'=>1,'taxonomy'=>'destinations','ids_option'=>'mad4b_perf_home_city_card_ids_v1',
 'source_meta_key'=>'featured_image','selector'=>'.elementor-element-95d1c9c'],
 'mad4b_perf_home_city_card_ids_v1'=>[123,124]];
$GLOBALS['schedule']=[];$GLOBALS['transients']=[];
function get_option($n,$default=null){return $GLOBALS['options'][$n]??$default;}
function get_transient($k){return $GLOBALS['transients'][$k]??false;}
function set_transient($k,$v,$age){$GLOBALS['transients'][$k]=$v;return true;}
function home_url($path='/'){return 'https://wayfatours.com'.$path;}
function wp_parse_url($url,$type=null){return $type===null?parse_url($url):parse_url($url,$type);}
function content_url($path){return 'https://wayfatours.com/wp-content'.$path;}
function set_url_scheme($u,$scheme){return $u;}
function get_term($id,$tax){return in_array($id,[123,124]) ? (object)['term_id'=>$id] : null;}
function is_wp_error($x){return false;}
function absint($x){return abs((int)$x);}
function get_term_meta($id,$key,$single){return $key==='featured_image'?'https://wayfatours.com/wp-content/uploads/test.jpg':'';}
function apply_filters($tag,$v,...$args){return $tag==='wpml_active_languages'?['en'=>[],'es'=>[]]:$v;}
function wp_next_scheduled($hook,$args=[]){return $GLOBALS['schedule'][join(':',$args)]??false;}
function wp_schedule_single_event($when,$hook,$args){$GLOBALS['schedule'][join(':',$args)]=$when;return true;}
function add_action($hook,$fn,$prio=10,$count=1){}
function is_admin(){return false;}function is_user_logged_in(){return false;}function is_front_page(){return true;}
function register_rest_route($ns,$name,$args){}
function wp_enqueue_script($handle,$src,$deps,$ver,$footer){$GLOBALS['enqueue']=$src;}
function wp_add_inline_script($handle,$data,$position){$GLOBALS['config']=$data;}
function rest_url($s){return 'https://wayfatours.com/wp-json/'.$s;}
function wp_json_encode($v,$opts=0){return json_encode($v,$opts);}
function check($condition,$label){if(!$condition)throw new RuntimeException('TEST_FAILED:'.$label);echo 'PASS ',$label,"\n";}
copy($argv[2],$dir.'/uploads/test.jpg');
require $argv[1];
$c='MAD4B_Responsive_Media_On_Demand_V1';
check($c::width_bucket(639)===640,'requested 639px => only 640px bucket');
check($c::width_bucket(640)===640,'same width reuses same bucket');
check($c::width_bucket(641)===704,'just above bucket gets next 64px');
check($c::width_bucket(191)===0 && $c::width_bucket(1537)===0,'out-of-range rejected');
check($c::width_bucket('abc')===0 && $c::width_bucket(INF)===0,'non-numeric/unbounded rejected');
check($c::query(999,640)['state']==='invalid_source','unlisted term denied');
check($c::query(123,640,false)['state']==='pending' && !$GLOBALS['schedule'],'no generation merely by reading');
check($c::query(123,640,true)['state']==='pending' && count($GLOBALS['schedule'])===1,'first requested derivative schedules once');
check($c::query(123,640,true)['state']==='pending' && count($GLOBALS['schedule'])===1,'duplicate request does not enqueue again');
check($c::query(123,704,true)['state']==='pending' && count($GLOBALS['schedule'])===2,'different measured layout may schedule its own width');
check($c::query(123,768,true)['state']==='pending' && $c::query(123,832,true)['state']==='pending','at most four quantized widths pending');
check($c::query(123,896,true)['state']==='fallback_request_budget','fifth width fails closed');
$c::process(123,640);
$candidates=glob($dir.'/uploads/test.jpg.mad4b-demand-640-*.webp');
$der=count($candidates)===1?$candidates[0]:'';
check(is_file($der) && filesize($der)>1500,'real local Imagick generated first requested derivative');
$sz=getimagesize($der);
check($sz[0]===640 && $sz[1]>200,'generated exact physical bucket size');
check($c::query(123,640)['state']==='ready','cached derivative reused without generation');
check(preg_match('~test\.jpg\.mad4b-demand-640-[a-f0-9]{12}\.webp$~',$c::query(123,640)['url'])===1,'returned same-origin static image only');
check(!glob($dir.'/uploads/test.jpg.mad4b-demand-896-*.webp'),'never generated unrequested fifth image');
check(is_file($dir.'/uploads/test.jpg'),'original source never overwritten');
$src=$dir.'/uploads/test.jpg';touch($src,filemtime($src)+120);clearstatcache(true,$src);
check($c::query(123,640,false)['state']==='pending','source updates invalidate cached derivative without overwriting old source');
$c::enqueue();
check(str_contains($GLOBALS['enqueue'],'/mu-plugins/assets/mad4b-responsive-media-demand-v1.js'),'company-owned frontend script wired');
check(str_contains($GLOBALS['config'],'/wp-json/mad4b/v1/responsive-media'),'public config has exact same-origin REST route');
foreach(glob($dir.'/uploads/*') as $f)unlink($f);
foreach(glob($dir.'/uploads/.*') as $f){if(is_file($f))unlink($f);}
rmdir($dir.'/uploads');rmdir($dir);
echo 'PASS 20/20 demand-only responsive media safety checks',"\n";
