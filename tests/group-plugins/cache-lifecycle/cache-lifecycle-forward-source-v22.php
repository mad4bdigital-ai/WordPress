<?php
/** Disposable runtime behavior checks; does not load a WordPress database. */
define('ABSPATH', '/');
$GLOBALS['action_hooks']=[];
$GLOBALS['filter_hooks']=[];
function add_action($name,$callable,$priority=10,$args=1){$GLOBALS['action_hooks'][]=$name;}
function add_filter($name,$callable,$priority=10,$args=1){$GLOBALS['filter_hooks'][]=$name;}
function get_post_type($id){return false;}
require dirname(__DIR__,3) . '/wp-content/plugins/mad4b-cache-lifecycle/includes/runtime.php';
$class='MAD4B_Cache_Lifecycle_V1';
function verify(bool $condition,string $reason):void {
  if(!$condition)throw new RuntimeException('CACHE_LIFECYCLE_V22_FAIL_'.$reason);
}
$cases=[];
$_COOKIE=[];$_GET=[];
verify($class::context()===null,'ANONYMOUS_PAGE_WRONGLY_PRIVATE');
$cases['public_anonymous']='PASS';
foreach(['wcmlc','mad4b_date_from','mad4b_date_to','mad4b_date_mode','travel_date','wc-ajax'] as $name){
 $_GET=[$name=>'sample'];$_COOKIE=[];
 verify($class::context()==='personalized_query','PERSONAL_QUERY_NOT_PRIVATE_'.$name);
}
$cases['query_context']='PASS';
$_GET=['utm_source'=>'campaign'];$_COOKIE=[];
verify($class::context()===null,'ANALYTICS_PARAMETER_WRONGLY_PRIVATE');
$cases['analytics_query_public']='PASS';
$_GET=[];$_COOKIE=['currency'=>'USD'];
verify($class::context()==='personalized_cookie','PERSONAL_COOKIE_NOT_PRIVATE');
$cases['personal_cookie']='PASS';
$_COOKIE=[];$_GET=[];
foreach(['_edit_lock','_edit_last','_wp_old_slug','_elementor_controls_usage','_elementor_element_cache'] as $name){
 verify($class::metadata_changes_public_html($name)===false,'DERIVED_META_FLUSHES_'.$name);
}
foreach(['tour_price','_elementor_data','_unknown_custom_field',''] as $name){
 verify($class::metadata_changes_public_html($name)===true,'REAL_META_NOT_FLUSHED_'.$name);
}
$cases['metadata_invalidation']='PASS';
verify($class::home_impact_preview([])['purge']===true,'UNKNOWN_HOME_DEPENDENCIES_SKIPPED');
verify($class::home_impact_preview([10],true)['purge']===true,'TAXONOMY_HOME_CHANGE_SKIPPED');
verify($class::home_impact_preview([10],false)['purge']===true,'UNKNOWN_TYPE_HOME_CHANGE_SKIPPED');
$cases['homepage_fail_closed']='PASS';
verify(in_array('set_object_terms',$GLOBALS['action_hooks'],true),'TERM_HOOK_MISSING');
verify(in_array('updated_post_meta',$GLOBALS['action_hooks'],true),'META_HOOK_MISSING');
verify(in_array('shutdown',$GLOBALS['action_hooks'],true),'PUBLISH_FLUSH_MISSING');
$cases['hooks']='PASS';
verify($class::early_headers([])===[],'PUBLIC_HEADERS_PRIVATE');
$_GET=['wcmlc'=>'EUR'];
$headers=$class::early_headers([]);
verify(isset($headers['Cache-Control'])&&false!==strpos($headers['Cache-Control'],'no-store'),'PRIVATE_HEADERS_MISSING');
$cases['cache_control']='PASS';
echo json_encode(['state'=>'PASS','cases'=>$cases,'count'=>count($cases),
 'staging_modified'=>false,'wordpress_database_modified'=>false],JSON_THROW_ON_ERROR);
