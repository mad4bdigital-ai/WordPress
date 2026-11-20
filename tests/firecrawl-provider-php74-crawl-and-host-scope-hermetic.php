<?php
/**
 * Executes the original native Firecrawl Provider source with synthetic
 * WordPress APIs, no network, credentials, external provider or database writes.
 * In particular: PHP 7.4 URL/scoped crawl/secretless host admission parity.
 */
define('ABSPATH',__DIR__.'/');
define('WP_CLI',true);
$GLOBALS['firecrawl_option']=[];
$GLOBALS['firecrawl_actions']=[];
$GLOBALS['firecrawl_filters']=[];
$GLOBALS['firecrawl_write_calls']=0;
$GLOBALS['firecrawl_network_calls']=0;
$GLOBALS['firecrawl_admin']=true;
class WP_Error {
 private $code;private $message;
 public function __construct($code,$message='',$data=[]){$this->code=$code;$this->message=$message;}
 public function get_error_code(){return $this->code;}
 public function get_error_message(){return $this->message;}
}
function is_wp_error($r){return $r instanceof WP_Error;}
function add_action($hook,$fn,$priority=10){$GLOBALS['firecrawl_actions'][$hook][]=$fn;}
function add_filter($hook,$fn,$priority=10){$GLOBALS['firecrawl_filters'][$hook][]=$fn;}
function current_user_can($role){return $GLOBALS['firecrawl_admin'] && $role==='manage_options';}
function wp_get_environment_type(){return 'staging';}
function home_url($path=''){return 'https://tours.example.invalid'.$path;}
function sanitize_key($key){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$key));}
function wp_parse_url($url,$component=-1){
 return $component===-1?parse_url($url):parse_url($url,$component);
}
function wp_http_validate_url($url){
 $parts=parse_url((string)$url);
 return is_array($parts) && ($parts['scheme']??'')==='https' &&
 !empty($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass']) &&
 !isset($parts['fragment'])&&($parts['port']??443)===443;
}
function esc_url_raw($url){return (string)$url;}
function get_option($name,$default=[]){return $GLOBALS['firecrawl_option'][$name]??$default;}
function update_option($name,$val,$autoload=false){
 ++$GLOBALS['firecrawl_write_calls'];
 throw new RuntimeException('Original Firecrawl Provider must not write in the hermetic read test');
}
function wp_json_encode($value){return json_encode($value);}
function wp_safe_remote_post($url,$args){
 ++$GLOBALS['firecrawl_network_calls'];
 return new WP_Error('http_request_failed','Connection timed out');
}
function wp_safe_remote_request($url,$args){
 ++$GLOBALS['firecrawl_network_calls'];
 return new WP_Error('http_request_failed','Connection timed out');
}
class MAD4B_SCP_Policy {
 public static function can_read(){return true;}
 public static function can_mutate(){return true;}
}
function check_firecrawl($success,$why){
 if(!$success)throw new RuntimeException('FAIL FIRECRAWL PHP74 PROVIDER: '.$why);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-firecrawl-provider.php';
$c='MAD4B_SCP_Firecrawl_Provider';
$good='https://tours.example.invalid/tours/luxor';
$single=$c::crawl_plan($good,'single_page');
check_firecrawl(is_array($single)&&$single['endpoint']==='/v2/scrape'
 && $single['mode']==='single_page'
 && $single['execution_performed']===false
 && $single['payload']['url']===$good,
 'Exact provider crawl plan must stay evidence-only, bounded to source URL');
$section=$c::crawl_plan($good,'section_crawl');
check_firecrawl(is_array($section)&&$section['endpoint']==='/v2/crawl'
 && $section['payload']['crawlEntireDomain']===false
 && $section['payload']['allowExternalLinks']===false
 && $section['payload']['allowSubdomains']===false,
 'Section crawl must stay on original host and subtree');
$full=$c::crawl_plan($good,'full_site_crawl');
check_firecrawl(is_array($full)&&$full['payload']['crawlEntireDomain']===true
 && $full['payload']['allowExternalLinks']===false,
 'Full site crawl still forbids external links');
foreach([
 ['http://tours.example.invalid/page','single_page'],
 ['https://user:pass@tours.example.invalid/page','single_page'],
 ['https://tours.example.invalid/page','unknown_mode'],
 ['https://tours.example.invalid/','section_crawl'],
] as $test){
 $out=$c::crawl_plan($test[0],$test[1]);
 check_firecrawl(is_wp_error($out),'Provider planning must fail closed for invalid HTTPS or crawl mode');
}
foreach([
 'https://127.0.0.1/path'=>'mad4b_source_loop_private_host_rejected',
 'https://intranet.local/page'=>'mad4b_source_loop_private_host_rejected',
 'https://svc.internal/page'=>'mad4b_source_loop_private_host_rejected',
 'https://unregistered.example.invalid/page'=>'mad4b_source_loop_host_not_enrolled',
] as $url=>$error){
 $out=$c::scrape_intake_url($url);
 check_firecrawl(is_wp_error($out)&&$out->get_error_code()===$error,
  'Source Loop must reject private and non-enrolled hosts without network: '.$url);
}
check_firecrawl($GLOBALS['firecrawl_network_calls']===0 && $GLOBALS['firecrawl_write_calls']===0,
 'No provider HTTP call or WordPress mutation in unowned/invalid URL test');
$timeout=$c::timeout_plan($good,'research');
check_firecrawl(is_array($timeout) && $timeout['timeout']>=45 && $timeout['timeout']<=115,
 'Existing timeouts remain bounded without global HTTP override');
// No implicit execution on an unenrolled site, even when running WP-CLI
// as an administrator with a globally permissive mutation-policy stub.
$owned=$c::start_owned_crawl($good,'section_crawl');
check_firecrawl(is_wp_error($owned)
 && $owned->get_error_code()==='mad4b_firecrawl_crawl_admin_required',
 'Owned crawl is not permissioned without canonical enrolled Site Profile');
$content=$c::start_content_crawl($good,'section_crawl',
 '11111111-1111-4111-8111-111111111111');
check_firecrawl(is_wp_error($content)
 && $content->get_error_code()==='mad4b_firecrawl_content_crawl_not_authorized',
 'No ContentJob crawl can start without site, owner and exact job admission');
$owned_status=$c::owned_crawl_status('not-registered');
check_firecrawl(is_wp_error($owned_status)
 && $owned_status->get_error_code()==='mad4b_firecrawl_crawl_admin_required',
 'Unregistered host never polls or persists an owned crawl');
$smoke=$c::scrape_owned_source('https://tours.example.invalid/');
check_firecrawl(is_wp_error($smoke)
 && $smoke->get_error_code()==='mad4b_firecrawl_stage_admin_required',
 'Public homepage scrape cannot execute without enrolled site');
$native=$c::scrape_native_origin_tour(77);
check_firecrawl(is_wp_error($native)
 && $native->get_error_code()==='mad4b_firecrawl_native_origin_grant_required',
 'Native published tour source requires separate original signed intake');
$research=$c::scrape_research_url('fixture',77,$good);
check_firecrawl(is_wp_error($research)
 && $research->get_error_code()==='mad4b_firecrawl_research_adapter_unavailable',
 'ContentJob research cannot start without loaded original Bridge');
$robots=$c::owned_robots_readiness('https://other.example.invalid/');
check_firecrawl($robots['state']==='NOT_OWNED'
 && $robots['robots_verified']===false,
 'Foreign robots are not probed or assumed to permit Firecrawl');
$probe=$c::probe();
check_firecrawl($probe['ready']===false
 && $probe['reason']==='configuration_missing',
 'An absent credential profile is not silently presented as a healthy provider');
$last=$c::test_status();
check_firecrawl($last['tested']===false,
 'No saved credential/network proof can be invented');
$GLOBALS['firecrawl_admin']=false;
ob_start();$c::render();$html=ob_get_clean();
check_firecrawl($html==='',
 'Provider credential UI must remain hidden from non-admin users');
check_firecrawl($GLOBALS['firecrawl_network_calls']===0 && $GLOBALS['firecrawl_write_calls']===0,
 'All absent-site/provider calls remain network-free and mutation-free');
echo "PASS: FIRECRAWL_NATIVE_PHP74 crawl host, source/job/owner admission, credentials and no writes\n";
