<?php
/**
 * Source-owned Firecrawl routing: original WordPress HTTP API only, no
 * credential release, network, Private Fabric or external provider calls.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['provider_routes']=array();
$GLOBALS['provider_ability']=array();
$GLOBALS['provider_http']=array();
function add_action($name,$callback,$priority=10){}
function wp_register_ability($name,$cfg){$GLOBALS['provider_ability'][$name]=$cfg;}
function wp_has_ability($name){return isset($GLOBALS['provider_ability'][$name]);}
function wp_http_validate_url($url) {
 $parts=parse_url($url);
 return is_array($parts) && ($parts['scheme']??'')==='https' &&
 !empty($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass'])&&
 !isset($parts['fragment'])&&($parts['port']??443)===443;
}
function wp_safe_remote_post($url,$args) {
 $GLOBALS['provider_http'][]=['safe_post',$url,$args];
 return ['response'=>['code'=>200]];
}
function wp_safe_remote_request($url,$args) {
 $GLOBALS['provider_http'][]=['safe_request',$url,$args];
 return ['response'=>['code'=>200]];
}
function wp_remote_post($url,$args){
 $GLOBALS['provider_http'][]=['private_post',$url,$args];
 throw new RuntimeException('No private transport configured in native source test');
}
function wp_remote_request($url,$args){
 $GLOBALS['provider_http'][]=['private_request',$url,$args];
 throw new RuntimeException('No private transport configured in native source test');
}
class WP_Error {
 private $code;
 public function __construct($code,$message=''){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function provider_assert($ok,$reason){
 if(!$ok)throw new RuntimeException('FAIL UNIFIED PROVIDER ROUTING: '.$reason);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-unified-provider-routing.php';
$c='MAD4B_SCP_Unified_Provider_Routing';
$c::register_ability();
provider_assert(count($GLOBALS['provider_ability'])===1
 && isset($GLOBALS['provider_ability']['mad4b/provider-routing-status'])
 && $GLOBALS['provider_ability']['mad4b/provider-routing-status']['meta']['annotations']['readonly']===true
 && $GLOBALS['provider_ability']['mad4b/provider-routing-status']['meta']['mcp']['surface']==='read',
 'Original provider catalog route only exposes nonauthorizing read status');
$cloud=$c::firecrawl_plan('cloud','https://api.firecrawl.dev');
provider_assert($cloud['allowed']===true
 && $cloud['selected_transport']==='central_https'
 && $cloud['dispatch_allowed']===false
 && $cloud['authorization_checked']===false
 && $cloud['fallback_after_dispatch']===false,
 'Transport selection must not create grant or execute provider');
foreach(array(
 ['cloud','http://api.firecrawl.dev'],
 ['cloud','https://evil.example.invalid'],
 ['cloud','https://user:pass@api.firecrawl.dev'],
 ['cloud','https://api.firecrawl.dev:8443'],
 ['unknown','https://api.firecrawl.dev'],
 ['self_hosted','http://private-cluster.invalid:8080']
) as $case)provider_assert(!$c::firecrawl_plan($case[0],$case[1])['allowed'],
 'Unverified mode and unsafe endpoint must be denied');
provider_assert($c::transport_permitted('http://127.0.0.1/v2/scrape',[])===false,
 'No caller may create an in-flight private HTTP exception');
$missing=$c::post_scrape('cloud','https://api.firecrawl.dev',[
 'headers'=>['Authorization'=>'Bearer demo'], 'redirection'=>0]);
provider_assert(is_wp_error($missing)
 && $missing->get_error_code()==='mad4b_provider_request_not_bounded',
 'Scrape needs exact bounded body, token and redirects disabled');
$args=['headers'=>['Authorization'=>'Bearer demo'],
 'body'=>'{"url":"https://site.example.invalid"}',
 'redirection'=>0];
$scrape=$c::post_scrape('cloud','https://api.firecrawl.dev',$args);
provider_assert(is_array($scrape)&&count($GLOBALS['provider_http'])===1
 && $GLOBALS['provider_http'][0][0]==='safe_post'
 && $GLOBALS['provider_http'][0][1]==='https://api.firecrawl.dev/v2/scrape',
 'Cloud scrape must use WordPress safe HTTPS only, never raw private transport');
$crawl='some-valid-crawl-12345678';
$readArgs=['headers'=>['Authorization'=>'Bearer demo'],
 'timeout'=>80,'redirection'=>9];
$status=$c::request_crawl('cloud','https://api.firecrawl.dev','status',$crawl,$readArgs);
provider_assert(is_array($status)&&count($GLOBALS['provider_http'])===2
 && $GLOBALS['provider_http'][1][0]==='safe_request'
 && $GLOBALS['provider_http'][1][1]==='https://api.firecrawl.dev/v2/crawl/'.$crawl
 && $GLOBALS['provider_http'][1][2]['method']==='GET'
 && $GLOBALS['provider_http'][1][2]['redirection']===0
 && $GLOBALS['provider_http'][1][2]['sslverify']===true
 && $GLOBALS['provider_http'][1][2]['timeout']===30,
 'Crawl status must use safe fixed origin, method and bounded timeouts');
$next='https://api.firecrawl.dev/v2/crawl/'.$crawl.'?skip=100';
$continued=$c::request_crawl('cloud','https://api.firecrawl.dev',
 'status',$crawl,$readArgs,$next);
provider_assert(is_array($continued)
 && end($GLOBALS['provider_http'])[1]==='https://api.firecrawl.dev/v2/crawl/'.$crawl.'?skip=100',
 'Cursor query can be copied only onto the approved original provider route');
$before=count($GLOBALS['provider_http']);
foreach(array(
 'https://evil.example.invalid/v2/crawl/'.$crawl.'?skip=1',
 'https://api.firecrawl.dev.evil.invalid/v2/crawl/'.$crawl.'?skip=1',
 'https://api.firecrawl.dev/v2/crawl/another-valid-crawl-0001?skip=1',
 'https://user:pass@api.firecrawl.dev/v2/crawl/'.$crawl.'?skip=1',
 'https://api.firecrawl.dev/v2/crawl/'.$crawl.'?skip=1#frag'
)as $unsafe){
 $denied=$c::request_crawl('cloud','https://api.firecrawl.dev',
  'status',$crawl,$readArgs,$unsafe);
 provider_assert(is_wp_error($denied)
  && $denied->get_error_code()==='mad4b_firecrawl_crawl_cursor_origin_invalid',
  'Foreign, credential-bearing or altered crawl cursor must fail closed');
}
provider_assert(count($GLOBALS['provider_http'])===$before,
 'Rejected cursor must not send a network request');
$badId=$c::request_crawl('cloud','https://api.firecrawl.dev','status','../bad',$readArgs);
provider_assert(is_wp_error($badId)
 && $badId->get_error_code()==='mad4b_firecrawl_crawl_id_invalid',
 'Untrusted crawl path traversal must be denied');
echo "PROVIDER_ROUTING_NATIVE: PASS PHP7.4, no SSRF/redirect, exact safe Firecrawl scope\n";// Success-only audited completion marker after all denial assertions.
echo "PROVIDER_ROUTING_NATIVE: PASS\n";
