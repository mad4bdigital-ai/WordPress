<?php
/** Original Hub REST consumer: HTTPS exact route fencing, zero HTTP calls. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($item){return $item instanceof WP_Error;}
function wp_parse_url($url,$part=-1){return $part===-1?parse_url($url):parse_url($url,$part);}
function get_option($name,$fallback=[]){
 return $name==='jet_engine_modules'?['rest-api-listings']:[];
}
function apply_filters($name,$default,...$args){
 return $GLOBALS['server_proofs'][$name]??$default;
}
class Jet_Engine_Module_Rest_Api_Listings {}
class MAD4B_JetEngine_Hub_Addon {
 static function dependencies_ready(){return true;}
}
class MAD4B_Hub_Discovery_Planner {
 static function validate($manifest){return [
  'market_scoped'=>true,'market_scope_kind'=>'market',
  'query_plan'=>['policy_blocks_read'=>false,'market_slices'=>[['market'=>'en']]]];}
}
function current_user_can($cap){return $cap==='manage_options';}
function register_rest_route($ns,$path,$args){$GLOBALS['routes'][$ns.$path]=$args;}
function add_action($name,$cb,$prio=10){$GLOBALS['hooks'][]=$name;}
function add_filter($name,$cb,$prio=10,$n=1){$GLOBALS['hooks'][]=$name;}
function consumer_check($condition,$msg){
 if(!$condition){fwrite(STDERR,'FAIL: '.$msg.PHP_EOL);exit(1);}
 $GLOBALS['cases']=($GLOBALS['cases']??0)+1;
}
class MAD4B_Hub_JetEngine_Consumer_Runtime {
 static $consumers=[];
 static $companies=[];
 static function registry(){return self::$consumers;}
 static function discovered_companies(){return self::$companies;}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-hub-jetengine-consumer-governance.php';
$c='MAD4B_SCP_Hub_JetEngine_Consumer_Governance';
$c::boot();
consumer_check(count($GLOBALS['hooks'])===2,'Original HTTP interception and REST registration stay native');
$c::register_routes();
consumer_check(count($GLOBALS['routes'])===2,
 'Original API exposes policy GET and a separate nonauthorizing validation POST');
$invalid=$c::validate_plan(['contract'=>$c::CONTRACT]);
consumer_check(is_wp_error($invalid),'Partial original company plan is not a valid signed REST feed');
$default=$c::status();
consumer_check($default['status']==='planning_only'
 &&$default['runtime_admission_default']==='deny'
 &&$default['private_signed_rest_required']===true
 &&$default['native_live_checkout_price_replaced']===false,
 'Original Hub consumer status is planning only and never replaces checkout');
MAD4B_Hub_JetEngine_Consumer_Runtime::$consumers=new WP_Error('registry_unavailable');
MAD4B_Hub_JetEngine_Consumer_Runtime::$companies=new WP_Error('discovery_unknown');
$broken=$c::status();
consumer_check($broken['status']==='runtime_registry_unverified'
 &&$broken['dynamic_registry_readback_verified']===false
 &&$broken['remote_company_discovery_readback_verified']===false,
 'Unknown original consumer registry cannot crash Hub or falsely claim no registered tenants');
MAD4B_Hub_JetEngine_Consumer_Runtime::$consumers=[];
MAD4B_Hub_JetEngine_Consumer_Runtime::$companies=[];
$plan=[
 'contract'=>$c::CONTRACT,
 'data_owner'=>'source_company','transport'=>'control_plane_signed_rest',
 'consumer_provider'=>'jet-engine',
 'listing_provider'=>'existing_jetengine_listing','query_provider'=>'jetengine_rest_query',
 'content_model'=>'remote_reference',
 'brand_authority'=>'local_company','media_authority'=>'source_company',
 'taxonomy_strategy'=>'reuse_verified_canonical_terms',
 'language_strategy'=>'source_target_wpml_verified',
 'pricing_strategy'=>'commercial_review_required',
 'source_company'=>'wayfa',
 'source_site_uuid'=>'11111111-1111-4111-8111-111111111111',
 'consumer_site_uuid'=>'22222222-2222-4222-8222-222222222222',
 'collection'=>'catalog',
 'endpoint_url'=>'https://tours.dev.mad4b.com/wp-json/mad4b-central/v1/hub-distribution/wayfa/catalog',
 'listing_id'=>4,'query_id'=>6,
 'fields'=>['source_company','source_id','title','language'],
 'taxonomy_bindings'=>[],
 'selection_manifest'=>[
  'contract'=>'mad4b.hub.capability-selection.v1',
  'source_company'=>'wayfa',
  'source_site_uuid'=>'11111111-1111-4111-8111-111111111111',
  'consumer_site_uuid'=>'22222222-2222-4222-8222-222222222222']
];
foreach([
 'create_shortcode','create_parallel_section','copy_source_database',
 'overwrite_native_posts','create_duplicate_taxonomy','overwrite_brand_kit',
 'import_private_media','enable_historical_checkout','expose_private_source_cpt'
] as $flag)$plan[$flag]=false;
$checked=$c::validate_plan($plan);
consumer_check(is_array($checked)&&$checked['plan_valid']===true
 &&$checked['runtime_approved']===false
 &&$checked['activation_performed']===false,
 'Exact original native JetEngine reference plan is only a nonauthorizing preview');
foreach([
 ['create_shortcode'=>true],
 ['endpoint_url'=>'http://tours.dev.mad4b.com/wp-json/mad4b-central/v1/hub-distribution/wayfa/catalog'],
 ['endpoint_url'=>$plan['endpoint_url'].'?outside=1'],
 ['source_site_uuid'=>$plan['consumer_site_uuid']],
 ['fields'=>['source_company','source_id','title','unknown_client_field']],
 ['query_id'=>0],
 ['collection'=>'arbitrary']
] as $change)
 consumer_check(is_wp_error($c::validate_plan(array_replace($plan,$change))),
  'Unknown data, cross-site identity or unapproved route cannot enter native Hub admission');
$GLOBALS['server_proofs']=[];
$blocked=$c::admit_runtime($plan);
consumer_check(is_wp_error($blocked),
 'Signed source READ alone cannot replace independent provider/market/content release proofs');
$all_tags=[
 'mad4b_scp_hub_consumer_signed_authority',
 'mad4b_scp_hub_consumer_native_binding_authority',
 'mad4b_scp_hub_consumer_market_authority',
 'mad4b_scp_hub_consumer_content_release_authority'
];
foreach($all_tags as $tag)$GLOBALS['server_proofs'][$tag]=true;
foreach($all_tags as $tag) {
 $GLOBALS['server_proofs'][$tag]=new WP_Error('original_authority_unknown');
 consumer_check(is_wp_error($c::admit_runtime($plan)),
  'WP_Error from any original server-side authority cannot be interpreted as admission');
 $GLOBALS['server_proofs'][$tag]=true;
}
$staging=$c::admit_runtime($plan);
consumer_check(is_array($staging)&&$staging['runtime_approved']===true
 &&$staging['activation_performed']===false
 &&$staging['server_side_proofs_verified']===true,
 'Four first-party test-only authoritative booleans admit only exact per-request read path');
$path='/wp-json/mad4b-central/v1/hub-distribution/wayfa/catalog';
$rp=new ReflectionProperty($c,'admitted_request_paths');
if(PHP_VERSION_ID<80100)$rp->setAccessible(true);
$rp->setValue(null,[$path=>true]);
$url='https://tours.dev.mad4b.com'.$path;
consumer_check($c::fence_hub_http(false,['method'=>'GET'],$url)===false,
 'Exact previously verified HTTPS GET route is not intercepted by source fence');
foreach([
 'http://tours.dev.mad4b.com'.$path,
 'https://tours.dev.mad4b.com:8443'.$path,
 'https://tours.dev.mad4b.com:443'.$path,
 'https://tours.dev.mad4b.com.'.$path,
 'https://user:password@tours.dev.mad4b.com'.$path,
 'https://tours.dev.mad4b.com'.$path.'?private=1',
 'https://tours.dev.mad4b.com'.$path.'#not-exact',
 'https://tours.dev.mad4b.com'.$path.'/extra',
 'https://tours.dev.mad4b.com/wp-json/mad4b-central/v1/hub-distribution/other/catalog'
] as $wrong){
 $result=$c::fence_hub_http(false,['method'=>'GET'],$wrong);
 consumer_check(is_wp_error($result),
  'An admitted feed PATH alone cannot permit wrong origin, scheme, query or subpath');
}
consumer_check(is_wp_error($c::fence_hub_http(false,['method'=>'POST'],$url)),
 'Hub GET admission never permits provider mutation or POST');
consumer_check($c::fence_hub_http(false,['method'=>'GET'],
 'https://wayfatours.com/rest/company')===false,
 'Native HTTP fence must not interfere with unrelated company services');
consumer_check($c::fence_hub_http(false,['method'=>'GET'],
 'https://tours.dev.mad4b.com/wp-json/wp/v2/posts')===false,
 'Original filter does not block unrelated WordPress REST endpoints');
$already=new WP_Error('original_http_preempt');
consumer_check($c::fence_hub_http($already,['method'=>'GET'],$url)===$already,
 'Another original HTTP preempt cannot be overwritten by this source filter');
echo 'PASS: HUB_JETENGINE_CONSUMER_FENCE '.($GLOBALS['cases']??0).
 ' native REST, HTTPS and no-publish admission cases'.PHP_EOL;
