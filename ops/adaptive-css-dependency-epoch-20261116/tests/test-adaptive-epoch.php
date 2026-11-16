<?php
declare(strict_types=1);
// Exact isolated runtime expects /mock/ (with slash), never a live WP install.
define('ABSPATH','/mock/');define('WP_CONTENT_DIR','/tmp');
$GLOBALS['options']=[
 'home'=>'https://tours.dev.mad4b.com',
 'mad4b_scp_brand_context_profile_v1'=>['site_uuid'=>'00000000-0000-4000-8000-000000000001','brand_id'=>str_repeat('a',32)],
 'mad4b_css_ai_epoch_v1'=>123,
 'page_on_front'=>35138,
];
$GLOBALS['impact']=false;
function wp_get_environment_type(){return 'staging';}
function home_url($path=''){return 'https://tours.dev.mad4b.com'.$path;}
function site_url($path=''){return 'https://tours.dev.mad4b.com'.$path;}
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
function update_option($k,$v,$autoload=null){$GLOBALS['options'][$k]=$v;return true;}
function wp_parse_url($u,$p=-1){return parse_url($u,$p);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return strip_tags((string)$v);}
function add_action(...$a){} function add_filter(...$a){}
function do_action(...$a){} function apply_filters($n,$v,...$a){return $v;}
function wp_is_post_revision($id){return false;} function wp_is_post_autosave($id){return false;}
function get_permalink($id){return "https://tours.dev.mad4b.com/tours-and-activities/$id/";}
function get_post_type_object($type){return (object)['public'=>true];}
class WP_Post {public $post_status='publish';public $post_type='tours-and-activities';}
class MAD4B_Cache_Lifecycle_V1{static function home_impact_preview(array $ids){return $GLOBALS['impact'];}}
require $argv[1];
function assert_eq($expected,$actual,$name){
 if($expected!==$actual){fwrite(STDERR,"FAIL $name expected=".json_encode($expected)." actual=".json_encode($actual)."\n");exit(1);}
 echo "PASS $name\n";
}
function p($type){$o=new WP_Post();$o->post_type=$type;return $o;}
function reset_epoch_mark(){$ref=new ReflectionProperty(MAD4B_SCP_Adaptive_Asset_Intelligence::class,'epochBumpedThisRequest');if(PHP_VERSION_ID<80100)$ref->setAccessible(true);$ref->setValue(null,false);}
function epoch(){return (int)get_option('mad4b_css_ai_epoch_v1');}
$GLOBALS['impact']=['purge'=>false,'reason'=>'audited_unrelated_content_only'];
MAD4B_SCP_Adaptive_Asset_Intelligence::post_saved(101,p('transportations'),true);
assert_eq(123,epoch(),'unrelated content does not invalidate global CSS');
assert_eq(1,count(MAD4B_SCP_Adaptive_Asset_Intelligence::queue()),'unrelated content still queued for route/source audit');
$GLOBALS['impact']=['purge'=>true,'reason'=>'homepage_post_type_dependency'];
MAD4B_SCP_Adaptive_Asset_Intelligence::post_saved(102,p('tours-and-activities'),true);
assert_eq(124,epoch(),'home-dependent content invalidates CSS');
MAD4B_SCP_Adaptive_Asset_Intelligence::post_saved(103,p('tours-and-activities'),true);
assert_eq(124,epoch(),'multiple changes in same request coalesce epoch');
reset_epoch_mark();
$GLOBALS['impact']=['purge'=>false];
MAD4B_SCP_Adaptive_Asset_Intelligence::post_saved(35138,p('page'),true);
assert_eq(125,epoch(),'front page saved always invalidates');
reset_epoch_mark();
$GLOBALS['impact']=[];
MAD4B_SCP_Adaptive_Asset_Intelligence::post_saved(104,p('tours-and-activities'),true);
assert_eq(126,epoch(),'missing home impact proof fails closed');
reset_epoch_mark();
MAD4B_SCP_Adaptive_Asset_Intelligence::post_saved(105,p('elementor_library'),true);
assert_eq(127,epoch(),'Elementor template always invalidates');
reset_epoch_mark();
MAD4B_SCP_Adaptive_Asset_Intelligence::mark_dirty('source_package_updated','/');
MAD4B_SCP_Adaptive_Asset_Intelligence::mark_dirty('source_package_updated','/');
assert_eq(128,epoch(),'package changes coalesce but do not lose invalidation');
assert_eq(true,count(MAD4B_SCP_Adaptive_Asset_Intelligence::queue())>=5,'queue retains unrelated and structural jobs');
echo "PASS 9/9 epoch/dependency assertions\n";
