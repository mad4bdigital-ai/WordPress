<?php
define('ABSPATH','/mock/wordpress/');define('WP_CLI',true);define('WP_CONTENT_DIR','/mock/wordpress/wp-content');
$GLOBALS['o']=['mad4b_scp_brand_context_profile_v1'=>['site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c','brand_id'=>'6568e330070a40e685ff36ccb7a726e1'],'mad4b_css_ai_epoch_v1'=>252,'mad4b_critical_css_home_dirty_v1'=>100];
$GLOBALS['impact']=['purge'=>true,'reason'=>'direct_home_card'];
function wp_get_environment_type(){return 'production';}
function home_url($x='/'){return 'https://wayfatours.com'.$x;}
function site_url($x='/'){return 'https://wayfatours.com'.$x;}
function wp_parse_url($x,$y=null){return parse_url($x,$y);}
function get_option($x,$d=false){return $GLOBALS['o'][$x]??$d;}
function update_option($x,$v,$a=null){$GLOBALS['o'][$x]=$v;return true;}
function sanitize_key($x){return strtolower((string)$x);}
function sanitize_text_field($x){return strip_tags((string)$x);}
function do_action($x,$v=null){}
function wp_is_post_revision($x){return false;}
function wp_is_post_autosave($x){return false;}
function get_post_type_object($x){return (object)['public'=>true];}
function get_permalink($x){return 'https://wayfatours.com/tours-and-activities/demo-'.$x.'/';}
function get_post($x){return $GLOBALS['p']??null;}
class WP_Post {public $ID=60627,$post_type='tours-and-activities',$post_status='publish';}
class MAD4B_Cache_Lifecycle_V1 {static function home_impact_preview($ids){return $GLOBALS['impact'];}}
require dirname(__DIR__).'/source-plugin/includes/class-mad4b-scp-adaptive-asset-intelligence.php';
$c='MAD4B_SCP_Adaptive_Asset_Intelligence';
function check($x,$k){if(!$x)throw new Exception($k);echo 'PASS '.$k."\n";}
$GLOBALS['p']=new WP_Post;
$c::post_saved(60627,$GLOBALS['p'],true);
check(get_option($c::EPOCH)===252,'direct home card preserves global CSS epoch');
check(get_option('mad4b_critical_css_home_dirty_v1')===101,'home first fold marked dirty');
check(count($c::queue())===1,'source queue still written');
$d=new class {function get_main_id(){return 60627;}};
$c::elementor_saved($d,[]);
check(get_option($c::EPOCH)===253,'Elementor document still invalidates global');
$prop=new ReflectionProperty($c,'epochBumpedThisRequest');$prop->setValue(false);
$GLOBALS['impact']=['purge'=>true,'reason'=>'dependency_graph_unavailable'];
$c::post_saved(60627,$GLOBALS['p'],true);
check(get_option($c::EPOCH)===254,'unknown graph keeps global fail closed');
$g=new ReflectionProperty($c,'homeDirtyBumpedThisRequest');
$g->setValue(false);$prop->setValue(false);
$GLOBALS['impact']=['purge'=>false,'reason'=>'audited_unrelated_content_only'];
$c::post_saved(60627,$GLOBALS['p'],true);
check(get_option($c::EPOCH)===254,'audited unrelated tour does not rotate CSS epoch');
check(get_option('mad4b_critical_css_home_dirty_v1')===101,'unrelated tour does not trigger home dirty');
$GLOBALS['impact']=['purge'=>true,'reason'=>'direct_home_card'];
$c::post_saved(60627,$GLOBALS['p'],true);
check(get_option('mad4b_critical_css_home_dirty_v1')===102,
      'new independent home update triggers exactly one home dirty event');
$c::post_saved(60627,$GLOBALS['p'],true);
check(get_option('mad4b_critical_css_home_dirty_v1')===102,
      'duplicate home saves in same request are coalesced');
$g->setValue(false);$prop->setValue(false);
$GLOBALS['p']->post_type='properties';
$c::post_saved(60627,$GLOBALS['p'],true);
check(get_option($c::EPOCH)===255,'non-tour cards do not gain scoped exemption');
check(get_option('mad4b_critical_css_home_dirty_v1')===102,'global fallback does not falsely emit scoped event');
echo "PASS 11/11 scoped dirty checks\n";
