<?php
define('ABSPATH','/');
$GLOBALS['calls']=[];$GLOBALS['opts']=['mad4b_render_query_data_generation'=>'old-version','mad4b_published_content_scope_cache_v1'=>['status'=>'active','strategy'=>'stable_scoped']];
function add_action($hook,$callback,$priority=10,$accepted=1){$GLOBALS['calls'][]=[$hook,$priority,$callback];return true;}
function add_filter($hook,$callback,$priority=10,$accepted=1){return true;}
function get_option($key,$default=null){return $GLOBALS['opts'][$key]??$default;}
function update_option($key,$value,$autoload=null){$GLOBALS['opts'][$key]=$value;return true;}
function wp_get_environment_type(){return 'staging';}
function get_post_type($id){return $id===60629?'tours-and-activities':'other';}
function get_post_status($id){return $id===60629?'publish':'draft';}
function wp_rand($min,$max){return 123456;}
require __DIR__.'/mad4b-published-content-scope-cache.php';
$klass='MAD4B_Published_Content_Scope_Cache';
$base=count($GLOBALS['calls']);
$klass::on_original_itinerary_meta_change(1,60629,'_elementor_css');
$klass::on_original_itinerary_meta_change(1,5,'itinerary_item');
if(count($GLOBALS['calls'])!==$base||get_option('mad4b_render_query_data_generation')!=='old-version')
 throw new RuntimeException('NON_ORIGINAL_REPEATER_MUST_NOT_INVALIDATE');
$klass::on_original_itinerary_meta_change(1,60629,'itinerary_item');
$klass::on_original_itinerary_meta_change(2,60629,'itinerary_item');
$registered=array_values(array_filter($GLOBALS['calls'],fn($h)=>$h[0]==='shutdown'&&$h[1]===94));
if(count($registered)!==1)throw new RuntimeException('REPEATER_MUST_DEBOUNCE_TO_ONE_SHUTDOWN');
if(get_option('mad4b_render_query_data_generation')!=='old-version')
 throw new RuntimeException('REPEATER_MUST_UPDATE_ONLY_AFTER_SOURCE_BATCH');
$klass::commit_original_itinerary_repeater_generation();
$next=get_option('mad4b_render_query_data_generation');
if($next==='old-version'||!str_ends_with($next,'-123456'))
 throw new RuntimeException('REPEATER_VERSION_NOT_ADVANCED');
$klass::commit_original_itinerary_repeater_generation();
if(get_option('mad4b_render_query_data_generation')!==$next)
 throw new RuntimeException('REPEATER_VERSION_BUMPED_TWICE_PER_BATCH');
echo 'REPEATER_SOURCE_HERMETIC_PASS callbacks=1 live_edits=0 published_itinerary_only=true one_epoch_per_batch=true no_object_flush=true'.PHP_EOL;
