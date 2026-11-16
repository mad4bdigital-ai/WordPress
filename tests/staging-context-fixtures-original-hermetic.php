<?php
/** Original Staging virtual fixtures and 3 authenticated REST routes. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($x){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$x));}
function sanitize_text_field($x){return trim((string)$x);}
function wp_get_environment_type(){return $GLOBALS['env'];}
function current_user_can($cap){return $GLOBALS['abilities'][$cap]??false;}
function get_option($name,$fallback=false){return $GLOBALS['opts'][$name]??$fallback;}
function add_action($hook,$callback,$priority=10){$GLOBALS['actions'][$hook][]=$callback;}
function register_rest_route($ns,$route,$data){$GLOBALS['routes'][$ns.$route]=$data;}
function rest_ensure_response($data){return new OriginalRest($data);}
function fixtures_check($yes,$reason){
 if(!$yes){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['cases']=($GLOBALS['cases']??0)+1;
}
class OriginalRest {
 public $headers=[],$data;
 function __construct($data){$this->data=$data;}
 function header($key,$val){$this->headers[$key]=$val;}
}
class OriginalDB {
 public $prefix='wp_';
 function get_var($query){return 6;}
}
$wpdb=new OriginalDB();
class MAD4B_SCP_Context_Authority {
 const STAGING_TRIAL_ARCHIVE='original_qa_trial_archive';
 static $brand=[];
 static $sources=[],$assets=[];
 static function profile(){return self::$brand;}
 static function status(){return ['state'=>'TEST_ONLY','ready'=>false];}
 static function sources(){return self::$sources;}
 static function assets(){return self::$assets;}
}
class MAD4B_SCP_Site_Profile {
 static $status=[];
 static function status(){return self::$status;}
}
class MAD4B_SCP_Company_Communication {
 static function brandIdentity(){return ['valid'=>true,'brand_name'=>'Original Staging Test Brand'];}
 static function canSend(){return false;}
 static function smtpConfigured(){return false;}
}
class MAD4B_SCP_Staging_Fixture_Drafts {
 static function report(){return ['test_data'=>true,'mutation_performed'=>false];}
}
$GLOBALS['env']='staging';
$GLOBALS['abilities']=['manage_options'=>true,'mad4b_manage_company_operations'=>true];
$GLOBALS['opts']=[MAD4B_SCP_Context_Authority::STAGING_TRIAL_ARCHIVE=>['trial_id'=>'test-brand-1']];
MAD4B_SCP_Context_Authority::$brand=[
 'status'=>'test_only','site_uuid'=>'11111111-1111-4111-8111-111111111111',
 'brand_id'=>'test-brand-1','brand_name'=>'Original Staging Test Brand','revision'=>2
];
MAD4B_SCP_Site_Profile::$status=[
 'site_uuid'=>'11111111-1111-4111-8111-111111111111',
 'environment'=>'staging','configured'=>true,'origin_match'=>true,'environment_match'=>true
];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-staging-context-fixtures.php';
$c='MAD4B_SCP_Staging_Context_Fixtures';
fixtures_check($c::canRead()===true && isset($GLOBALS['actions']['rest_api_init']),
 'Original Staging-only routes register through the WordPress authenticated runtime');
foreach($GLOBALS['actions']['rest_api_init'] as $cb)$cb();
fixtures_check(count($GLOBALS['routes'])===3,'Exactly three first-party virtual Context REST readers');
foreach($GLOBALS['routes'] as $route=>$data){
 fixtures_check($data['methods']==='GET'
  && $data['permission_callback']===[$c,'canRead'],
  'Only permission-bound GET routes expose original virtual test data');
}
$catalog=$c::catalog();
fixtures_check(is_array($catalog) && count($catalog['sources'])===2 &&count($catalog['assets'])===4
 &&$catalog['test_only']===true &&$catalog['persisted_to_governed_registry']===false,
 'Original fixture catalog is virtual, non-governed and not a real source');
foreach($catalog['sources'] as $source){
 fixtures_check($source['write_policy']==='none'
  &&$source['real_provider_access']===false
  &&$source['site_uuid']===MAD4B_SCP_Site_Profile::$status['site_uuid'],
  'Virtual source does not inherit external provider capabilities');
}
foreach($catalog['assets'] as $asset){
 fixtures_check($asset['publishable']===false
  &&$asset['approved']===false &&$asset['data_classification']==='test_only'
  &&$asset['post_status']==='draft' &&$asset['file_url']===null,
  'Test asset never becomes published media or a real WordPress attachment');
}
$response=$c::restCatalog(null);
fixtures_check($response instanceof OriginalRest
 &&$response->headers['Cache-Control']==='private, no-store',
 'Original virtual REST catalog cannot be shared across sites');
$checks=$c::restConsumers(null);
fixtures_check($checks instanceof OriginalRest
 &&$checks->data['commerce']['order_created']===false
 &&$checks->data['communication']['real_notifications_sent']===0
 &&$checks->data['status']==='PASS_READ_ONLY_TEST',
 'Virtual test consumers never send mail or create a booking');
$drafts=$c::restDrafts(null);
fixtures_check($drafts instanceof OriginalRest
 &&$drafts->data['mutation_performed']===false,
 'Draft metadata report remains read only');
MAD4B_SCP_Context_Authority::$sources=new WP_Error('source_inventory_failed');
fixtures_check(is_wp_error($c::consumers()),
 'Original governed source error cannot be miscounted as a working test consumer');
MAD4B_SCP_Context_Authority::$sources=[];
MAD4B_SCP_Site_Profile::$status['site_uuid']='22222222-2222-4222-8222-222222222222';
fixtures_check(is_wp_error($c::scope()) &&is_wp_error($c::catalog()),
 'Cloned or cross-company WordPress Site Profile cannot access source-bound test fixtures');
MAD4B_SCP_Site_Profile::$status['site_uuid']=MAD4B_SCP_Context_Authority::$brand['site_uuid'];
$GLOBALS['env']='production';
fixtures_check($c::canRead()===false &&is_wp_error($c::scope()),
 'Production never inherits original Staging virtual fixture endpoint');
echo 'PASS: STAGING_CONTEXT_FIXTURES '.($GLOBALS['cases']??0).
 ' native read-only REST and cross-site isolation tests'.PHP_EOL;
