<?php
define('ABSPATH','/');
$GLOBALS['hooks']=[];$GLOBALS['samples']=[];$GLOBALS['wpdb']=(object)['num_queries'=>0];
function wp_get_environment_type(){return $GLOBALS['env']??'staging';}
function add_action($h,$cb,$p=10,$a=1){$GLOBALS['hooks'][$h][]=$cb;}
function do_action($h,...$a){}
function is_admin(){return false;}function is_user_logged_in(){return false;}function is_preview(){return false;}function wp_doing_ajax(){return false;}
function is_front_page(){return false;}function is_singular($x){return true;}function is_post_type_archive($x){return false;}function is_tax(){return false;}
function wp_json_encode($x){return json_encode($x);}function wp_is_mobile(){return true;}function apply_filters($h,$x){return 'en';}
function wp_cache_set($k,$v,$g,$t){$GLOBALS['samples'][$k]=$v;return true;}
function wp_cache_get($k,$g){return $GLOBALS['samples'][$k]??false;}
function ok($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
class MockEl{public function __construct(private string $n){}public function get_name(){return $this->n;}}
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_TIME_FLOAT']=microtime(true);
require $argv[1];
$c='MAD4B_Adaptive_Render_Profile_V1';
ok(!$GLOBALS['hooks'],'ordinary traffic attaches no profiling hooks');
$_SERVER['HTTP_X_MAD4B_RENDER_PROFILE']='v1';$_SERVER['HTTP_COOKIE']='private=1';$c::boot();
ok(!$GLOBALS['hooks'],'cookie-bearing requests rejected');
unset($_SERVER['HTTP_COOKIE']);$GLOBALS['env']='production';$c::boot();
ok(!$GLOBALS['hooks'],'production profiling disabled');
$GLOBALS['env']='staging';$c::boot();$c::activate();
ok(isset($GLOBALS['hooks']['elementor/frontend/before_render']),'explicit staging public sample attaches native hooks');
$p=new MockEl('container');$child=new MockEl('fluent-form-widget');
$c::before($p);$GLOBALS['wpdb']->num_queries+=2;
$c::before($child);usleep(4000);$GLOBALS['wpdb']->num_queries+=5;$c::after($child);
$GLOBALS['wpdb']->num_queries+=3;$c::after($p);
$c::query((object)['id'=>291,'query_type'=>'terms','cache_query'=>true]);
$c::query((object)['id'=>291,'query_type'=>'terms','cache_query'=>false]);
$q=(object)['id'=>273,'query_type'=>'posts','final_query'=>['term'=>10],'cache_group'=>'native-en-usd','cache_expires'=>900];
$c::execution($q,false);$c::execution($q,true);$q->final_query=['term'=>11];$c::execution($q,false);
$c::finish();$r=$c::samples()[0];$types=array_column($r['ranked_widgets'],null,'widget');
ok($types['container']['exclusive_queries']===5&&$types['fluent-form-widget']['exclusive_queries']===5,'exclusive SQL counts avoid double counting children');
ok($types['fluent-form-widget']['candidate_action']==='live_only'&&!$types['fluent-form-widget']['cache_authorized'],'transactional widgets never authorized');
ok($types['container']['candidate_action']==='inspect_public_structure'&&!$types['container']['cache_authorized'],'public candidate requires separate native qualification');
ok($r['query_setups'][0]['setup_count']===2&&$r['query_setups'][0]['cache_admitted']===1,'query setup counts preserve original decision');
ok(!$r['cache_authorized']&&!$r['native_certificate_written'],'observation cannot grant admission');
$e=$r['query_executions'][0];ok($e['calls']===3&&$e['distinct_resolved_scopes']===2&&$e['repeat_observed']===1,'same Query ID with distinct resolved terms is not a duplicate');
ok($e['native_hits']===1&&$e['native_misses']===2&&!$e['dedup_authorized'],'native hit outcome observed without overriding native cache');

$c::activate();$c::before($p);$c::after($child);$count=count($c::samples());$c::finish();
ok(count($c::samples())===$count,'mismatched stack discards incomplete observation');
echo "PASS 12/12 adaptive profile invariants\n";
