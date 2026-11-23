<?php
/** Hermetic first-party Elementor/post-save HOME vs GLOBAL CSS epoch source proof. */
define('ABSPATH','/mock/wordpress/');
define('WP_CLI',true);
define('WP_CONTENT_DIR','/mock/wordpress/wp-content');
$GLOBALS['o']=[
 'mad4b_scp_brand_context_profile_v1'=>[
  'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
  'brand_id'=>'6568e330070a40e685ff36ccb7a726e1'],
 'mad4b_css_ai_epoch_v1'=>252,
 'mad4b_critical_css_home_dirty_v1'=>100
];
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
class WP_Post {
 public $ID=60627,$post_type='tours-and-activities',$post_status='publish';
}
class MAD4B_Cache_Lifecycle_V1 {
 static function home_impact_preview($ids){return $GLOBALS['impact'];}
}
require dirname(__DIR__).'/source-plugin/includes/class-mad4b-scp-adaptive-asset-intelligence.php';
$c='MAD4B_SCP_Adaptive_Asset_Intelligence';
$GLOBALS['p']=new WP_Post;
$doc=new class {function get_main_id(){return 60627;}};
$checks=0;
function ok($label,$passed){
 global $checks;
 if(!$passed)throw new RuntimeException($label);
 $checks++;echo "PASS $label
";
}
function fresh($c) {
 (new ReflectionProperty($c,'epochBumpedThisRequest'))->setValue(false);
 (new ReflectionProperty($c,'homeDirtyBumpedThisRequest'))->setValue(false);
}
$c::post_saved(60627,$GLOBALS['p'],true);
ok('direct tour home card does not change global CSS Epoch',get_option($c::EPOCH)===252);
ok('direct tour home card invalidates only first-fold home',get_option('mad4b_critical_css_home_dirty_v1')===101);
ok('an original first-party content review job is created',count($c::queue())===1);
$c::elementor_saved($doc,[]);
ok('same-request Elementor save cannot rotate global CSS Epoch',get_option($c::EPOCH)===252);
ok('same-request double Elementor/post-save coalesces first-fold dirty',get_option('mad4b_critical_css_home_dirty_v1')===101);
fresh($c);
$GLOBALS['impact']=['purge'=>true,'reason'=>'dependency_graph_unavailable'];
$c::post_saved(60627,$GLOBALS['p'],true);
ok('unverified tour graph still changes global CSS Epoch',get_option($c::EPOCH)===253);
fresh($c);
$GLOBALS['impact']=['purge'=>false,'reason'=>'audited_unrelated_content_only'];
$c::post_saved(60627,$GLOBALS['p'],true);
ok('independently unrelated tour save does not rotate global CSS Epoch',get_option($c::EPOCH)===253);
ok('unrelated tour does not issue new home first-fold dirty event',get_option('mad4b_critical_css_home_dirty_v1')===101);
fresh($c);
$GLOBALS['impact']=['purge'=>true,'reason'=>'direct_home_card'];
$c::post_saved(60627,$GLOBALS['p'],true);
ok('fresh home-card transaction marks home once',get_option('mad4b_critical_css_home_dirty_v1')===102);
$c::post_saved(60627,$GLOBALS['p'],true);
ok('duplicate home-card post save remains coalesced',get_option('mad4b_critical_css_home_dirty_v1')===102);
fresh($c);
$GLOBALS['p']->post_type='properties';
$c::post_saved(60627,$GLOBALS['p'],true);
ok('Property post-save with uncertain home impact still increments global',get_option($c::EPOCH)===254);
fresh($c);
$GLOBALS['impact']=['purge'=>false,'reason'=>'audited_unrelated_content_only'];
$GLOBALS['p']->post_type='tours-and-activities';
$start=get_option($c::EPOCH);
$c::elementor_saved($doc,[]);
ok('proved unrelated Elementor tour change is not global CSS',get_option($c::EPOCH)===$start);
fresh($c);
$GLOBALS['impact']=['purge'=>true,'reason'=>'direct_home_card'];
$home=get_option('mad4b_critical_css_home_dirty_v1');
$c::elementor_saved($doc,[]);
ok('direct home-tour Elementor event does not change global CSS',get_option($c::EPOCH)===$start);
ok('direct Elementor home-tour event marks only first-fold',get_option('mad4b_critical_css_home_dirty_v1')===$home+1);
fresh($c);
$GLOBALS['impact']=['purge'=>true,'reason'=>'dependency_graph_unavailable'];
$c::elementor_saved($doc,[]);
ok('unknown Elementor tour dependency retains conservative global epoch',get_option($c::EPOCH)===$start+1);
fresh($c);
$GLOBALS['p']->post_type='properties';
$GLOBALS['impact']=['purge'=>false,'reason'=>'audited_unrelated_content_only'];
$start=get_option($c::EPOCH);
$c::elementor_saved($doc,[]);
ok('proved unrelated Elementor Property edit remains scoped',get_option($c::EPOCH)===$start);
fresh($c);
$GLOBALS['p']->post_type='post';
$c::elementor_saved($doc,[]);
ok('proved unrelated Elementor public article edit remains scoped',get_option($c::EPOCH)===$start);
fresh($c);
$GLOBALS['p']->post_type='page';
$c::elementor_saved($doc,[]);
ok('WordPress page Elementor edit retains global invalidation',get_option($c::EPOCH)===$start+1);
fresh($c);
$GLOBALS['p']->post_type='elementor_library';
$start=get_option($c::EPOCH);
$c::elementor_saved($doc,[]);
ok('Elementor global Theme Builder edit remains global',get_option($c::EPOCH)===$start+1);
fresh($c);
$GLOBALS['p']->post_type='unknown-public-cpt';
$start=get_option($c::EPOCH);
$c::elementor_saved($doc,[]);
ok('unknown CPT cannot claim a scoped CSS exception',get_option($c::EPOCH)===$start+1);
fresh($c);
$GLOBALS['p']->post_type='tours-and-activities';
$GLOBALS['impact']=['purge'=>false,'reason'=>'audited_unrelated_content_only'];
$GLOBALS['o']['page_on_front']=60627;
$start=get_option($c::EPOCH);
$c::elementor_saved($doc,[]);
ok('front-page Elementor ownership forbids the scoped exception',get_option($c::EPOCH)===$start+1);
fresh($c);
$start=get_option($c::EPOCH);
$c::elementor_saved(null,[]);
ok('invalid Elementor input cannot cause an epoch change',get_option($c::EPOCH)===$start);
fresh($c);
unset($GLOBALS['o']['page_on_front']);
$GLOBALS['p']=null;
$c::elementor_saved($doc,[]);
ok('missing Elementor post identity fails conservatively global',get_option($c::EPOCH)===$start+1);
fresh($c);
$start=get_option($c::EPOCH);
$c::option_changed('litespeed.conf.optm-css_comb','0','0');
ok('unchanged CSS optimization option cannot rotate global epoch',get_option($c::EPOCH)===$start);
$c::option_changed('litespeed.conf.optm-css_comb','0','1');
ok('genuine CSS optimization setting change rotates epoch',get_option($c::EPOCH)===$start+1);
echo "PASS $checks/$checks exact Elementor/home/global CSS epoch fixtures
";
