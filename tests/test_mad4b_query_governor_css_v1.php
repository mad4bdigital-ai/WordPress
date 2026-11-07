<?php
defined('ABSPATH')||define('ABSPATH','/');
define('DAY_IN_SECONDS',86400);
$GLOBALS['callbacks']=[];
$GLOBALS['opts']=['home'=>'https://tours.dev.mad4b.com','mad4b_render_query_data_generation'=>'r0'];
$GLOBALS['lang']='en';$GLOBALS['blog']=1;$GLOBALS['bump']=0;$GLOBALS['home_url_calls']=0;
function add_action($hook,$fn,$priority=10,$accepted=1){$GLOBALS['callbacks'][$hook][]=$fn;}
function add_filter($hook,$fn,$priority=10,$accepted=1){$GLOBALS['callbacks'][$hook][]=$fn;}
function is_admin(){return false;}
function wp_doing_ajax(){return false;}
function is_user_logged_in(){return false;}
function wp_using_ext_object_cache(){return true;}
function get_option($key,$default=null){return $GLOBALS['opts'][$key]??$default;}
function update_option($key,$val,$autoload=false){$GLOBALS['opts'][$key]=$val;$GLOBALS['bump']++;return true;}
function home_url($path='/'){$GLOBALS['home_url_calls']++;throw new RuntimeException('home_url should not be called by public Query setup');}
function untrailingslashit($v){return rtrim($v,'/');}
function get_current_blog_id(){return $GLOBALS['blog'];}
function get_post_type($id){return 'elementor_library';}
function get_post_type_object($v){return (object)['public'=>true];}
function wp_is_post_revision($id){return false;}
function wp_rand($a,$b){return $a;}
function current_filter(){return '';}
function do_action($hook,...$args){return null;}
function apply_filters($hook,$val,...$args){return $hook==='wpml_current_language'?$GLOBALS['lang']:$val;}
function determine_locale(){return 'en_US';}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$v));}
function maybe_serialize($v){return serialize($v);}
function ok($label,$test){if(!$test)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
require getenv('MAD4B_QUERY_GOVERNOR_SOURCE');
$meta1=['time'=>100,'status'=>'file','fonts'=>[],'icons'=>[],
    'dynamic_elements_ids'=>['abc123']];
$meta2=$meta1;$meta2['time']=900;
$metaChanged=$meta2;$metaChanged['dynamic_elements_ids'][]='def456';
ok('CSS regeneration time is non-keyed',
    MAD4B_Render_Query_Public_Fragments::stable_css_manifest_digest($meta1)
      ===MAD4B_Render_Query_Public_Fragments::stable_css_manifest_digest($meta2));
ok('CSS dynamic selectors remain keyed',
    MAD4B_Render_Query_Public_Fragments::stable_css_manifest_digest($meta1)
      !==MAD4B_Render_Query_Public_Fragments::stable_css_manifest_digest($metaChanged));
$changedStatus=$meta2;$changedStatus['status']='inline';
ok('CSS status remains keyed',
    MAD4B_Render_Query_Public_Fragments::stable_css_manifest_digest($meta1)
      !==MAD4B_Render_Query_Public_Fragments::stable_css_manifest_digest($changedStatus));
function fire_meta($name,$key){foreach($GLOBALS['callbacks'][$name]??[] as $cb)$cb(1,44,$key);}
fire_meta('updated_post_meta','_elementor_css');
fire_meta('added_post_meta','_elementor_css');
fire_meta('deleted_post_meta','_elementor_css');
ok('generated CSS never invalidates query data',$GLOBALS['bump']===0);
$realChange=getenv('MAD4B_REAL_META') ?: '_elementor_data';
fire_meta('updated_post_meta',$realChange);
ok('real Elementor document change invalidates query data',$GLOBALS['bump']===1);
fire_meta('updated_post_meta',$realChange);
ok('multiple changes in one request are coalesced into one epoch',$GLOBALS['bump']===1);
function make_query() {
 return (object)['id'=>41,'query_type'=>'posts',
   'final_query'=>['post_type'=>['tours-and-activities'],'post_status'=>'publish'],
   'cache_query'=>false,'cache_expires'=>0,'cache_group'=>''];
}
$q=make_query();
foreach($GLOBALS['callbacks']['jet-engine/query-builder/query/after-query-setup'] as $cb)$cb($q);
ok('public query is cached',$q->cache_query===true);
ok('cache group is language-isolated',str_contains($q->cache_group,'-en-'));
ok('WPML home_url filter skipped',$GLOBALS['home_url_calls']===0);
$english=$q->cache_group;
$GLOBALS['lang']='fr';
$q2=make_query();
foreach($GLOBALS['callbacks']['jet-engine/query-builder/query/after-query-setup'] as $cb)$cb($q2);
ok('French cache group is different',$q2->cache_group!==$english && str_contains($q2->cache_group,'-fr-'));
$GLOBALS['blog']=2;
$q3=make_query();
foreach($GLOBALS['callbacks']['jet-engine/query-builder/query/after-query-setup'] as $cb)$cb($q3);
ok('company identity isolates multisite blogs',$q3->cache_group!==$q2->cache_group);
echo "PASS QUERY GOVERNOR CSS/LOCALE/COMPANY TEST\n";
