<?php
/* Cloudflare company-domain adapter contract, run under PHP 7.4/8.3 without WP. */
define('ABSPATH', __DIR__);
$GLOBALS['hooks']=array();
$GLOBALS['options']=array();
$GLOBALS['requests']=array();
$GLOBALS['dns_existing']=array();
$GLOBALS['zone_active']=true;
$GLOBALS['zone_missing']=false;
$GLOBALS['records']=array();
$GLOBALS['http_posts']=0;
$GLOBALS['zone_enroll_posts']=0;
$GLOBALS['authenticated']=true;
$GLOBALS['settings']=array();
class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code,$message,$data=array()) {$this->code=$code;$this->message=$message;$this->data=$data;}
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->message;}
    public function get_error_data(){return $this->data;}
}
class WP_REST_Response {
    public $data; public $status;
    public function __construct($data,$status=200){$this->data=$data;$this->status=$status;}
    public function header($k,$v){}
}
class WP_REST_Server {}
class Mock_Request {
    private $params;
    public function __construct($params){$this->params=$params;}
    public function get_params(){return $this->params;}
    public function get_json_params(){return $this->params;}
}
class Fake_Wpdb {
    public function prepare($statement,$value){return $statement.' '.(string)$value;}
    public function get_var($statement){return 1;}
    public function query($statement){return true;}
}
$GLOBALS['wpdb']=new Fake_Wpdb();
function add_action($name,$fn,$priority=10){$GLOBALS['hooks'][$name][]=$fn;}
function register_rest_route($namespace,$path,$config){$GLOBALS['registered'][$namespace.$path]=$config;}
function current_user_can($cap){return $GLOBALS['authenticated'];}
function wp_validate_auth_cookie($cookie,$scheme){return 4;}
function wp_get_session_token(){return 'session-token';}
function get_current_user_id(){return 4;}
function wp_verify_nonce($nonce,$name){return $nonce==='valid-nonce';}
function wp_json_encode($value){return json_encode($value);}
function wp_salt($scheme){return 'test-secret-do-not-use-production';}
function is_wp_error($value){return $value instanceof WP_Error;}
function add_option($key,$value,$deprecated='',$autoload=false){
 if(array_key_exists($key,$GLOBALS['options']))return false;
 $GLOBALS['options'][$key]=$value;return true;
}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
function wp_remote_retrieve_response_code($response){return $response['code'];}
function wp_remote_retrieve_body($response){return $response['body'];}
function wp_remote_request($url,$args) {
    $path=substr($url,strlen('https://api.cloudflare.com/client/v4'));
    $GLOBALS['requests'][]=array('path'=>$path,'method'=>$args['method']);
    $result=null;
    if($path==='/zones/'.str_repeat('a',32)) {
        $result=array('id'=>str_repeat('a',32),'name'=>'mad4b.com','status'=>'active');
    } elseif(strpos($path,'/zones?name=')===0) {
        $result=$GLOBALS['zone_missing']?array():array(array('id'=>str_repeat('b',32),'name'=>'wayfatours.com','status'=>$GLOBALS['zone_active']?'active':'pending'));
    } elseif(strpos($path,'/dns_records?name=')!==false) {
        $result=$GLOBALS['records'];
    } elseif($path==='/zones/'.str_repeat('a',32).'/dns_records' && $args['method']==='POST') {
        $GLOBALS['http_posts']++;
        $value=json_decode($args['body'],true);
        $result=array_merge($value,array('id'=>'created-record-id'));
        $GLOBALS['records']=array($result);
    } elseif($path==='/zones' && $args['method']==='POST') {
        $GLOBALS['zone_enroll_posts']++;
        $result=array('id'=>str_repeat('c',32),'name_servers'=>array('ns1.cloudflare.test','ns2.cloudflare.test'),'status'=>'pending');
    } else {return new WP_Error('unexpected_http','Unexpected call');}
    return array('code'=>200,'body'=>json_encode(array('success'=>true,'result'=>$result)));
}
function wp_remote_get($url,$args){return new WP_Error('dns_not_propagated','not yet');}
function expect_true($condition,$name){if(!$condition){fwrite(STDERR,'FAIL '.$name.PHP_EOL);exit(1);}}
function response_data($r) {return $r instanceof WP_REST_Response?$r->data:$r;}
putenv('MAD4B_CLOUDFLARE_API_TOKEN=fake-token-contract');
putenv('MAD4B_CLOUDFLARE_ZONE_ID='.str_repeat('a',32));
putenv('MAD4B_CLOUDFLARE_ACCOUNT_ID='.str_repeat('d',32));
putenv('MAD4B_COMPANY_ORIGIN_IPV4=187.7.76.129');
putenv('MAD4B_COMPANY_LAUNCH_REGISTRY_JSON='.json_encode(array(
 'wayfatours'=>array('origin_host'=>'wayfatours.mad4b.com','production_admitted'=>true, 'approved_custom_domains'=>array('wayfatours.com'))
)));
require dirname(__DIR__,2).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-company-domain-cloudflare.php';
$c='MAD4B_SCP_Company_Domain_Cloudflare';
$c::boot(); $c::boot();
expect_true(count($GLOBALS['hooks']['rest_api_init'])===1,'boot idempotent');
foreach($GLOBALS['hooks']['rest_api_init'] as $fn)call_user_func($fn);
expect_true(count($GLOBALS['registered'])===5,'five routes registered');
expect_true(!$c::valid_host('http://127.0.0.1')&&!$c::valid_host('127.0.0.1'),'No IP, URL or private SSRF hostname');
expect_true($c::valid_host('wayfatours.mad4b.com'),'correct managed host');
expect_true($c::can_read()===true,'owner may read');
$GLOBALS['authenticated']=false;
expect_true(is_wp_error($c::can_write()),'anonymous denied');
$GLOBALS['authenticated']=true;
define('LOGGED_IN_COOKIE','wordpress_logged_in_test');
$_COOKIE[LOGGED_IN_COOKIE]='valid-cookie';
$_SERVER['HTTP_X_WP_NONCE']='bad';
expect_true(is_wp_error($c::can_write()),'nonce required');
$_SERVER['HTTP_X_WP_NONCE']='valid-nonce';
expect_true($c::can_write()===true,'valid browser owner');
$target=array('company_code'=>'wayfatours','mode'=>'subdomain','hostname'=>'wayfatours.mad4b.com');
$plan=response_data($c::plan(new Mock_Request($target)));
expect_true($plan['eligible_dns_commit']===true && $plan['requested_record']['content']==='187.7.76.129','controlled DNS plan');
expect_true($plan['public_live']===false,'plan never claims public');
$wrong=$target;$wrong['hostname']='other.mad4b.com';
expect_true(is_wp_error($c::plan(new Mock_Request($wrong))),'cross company hostname denied');
$commit=$target;
$commit['expected_plan_sha256']=$plan['plan_sha256'];
$commit['confirmation']='CREATE DNS FOR wayfatours.mad4b.com';
$commit['idempotency_key']='intent-001-wayfatours';
$first=$c::commit(new Mock_Request($commit));
expect_true($first instanceof WP_REST_Response && $first->status===201,'DNS record created after approval');
expect_true($GLOBALS['http_posts']===1,'single provider POST');
$second=$c::commit(new Mock_Request($commit));
expect_true(response_data($second)['state']==='DNS_ALREADY_MATCHES'&&$GLOBALS['http_posts']===1,'provider readback prevents double POST');
$GLOBALS['records']=array(array('name'=>'wayfatours.mad4b.com','type'=>'CNAME','content'=>'other.example','id'=>'conflict'));
expect_true($c::commit(new Mock_Request($commit))->get_error_code()==='mad4b_domain_dns_conflict','never overwrite conflicting hostname');
$other=array('company_code'=>'wayfatours','mode'=>'custom_domain','hostname'=>'other-brand.com');
expect_true(is_wp_error($c::plan(new Mock_Request($other))),'cannot bind unapproved foreign custom domain');
$custom=array('company_code'=>'wayfatours','mode'=>'custom_domain','hostname'=>'wayfatours.com');
$customPlan=response_data($c::plan(new Mock_Request($custom)));
expect_true(in_array('company_domain_ownership_txt_unverified',$customPlan['blockers'],true),'custom domain requires TXT ownership');
$GLOBALS['zone_missing']=true;
$missing=response_data($c::plan(new Mock_Request($custom)));
expect_true(in_array('zone_not_enrolled_in_cloudflare',$missing['blockers'],true),'unknown Cloudflare zone onboarding');
$enroll=$custom;
$enroll['expected_plan_sha256']=$missing['plan_sha256'];
$enroll['confirmation']='ENROLL CLOUDFLARE ZONE wayfatours.com';
$done=$c::enroll(new Mock_Request($enroll));
expect_true($done instanceof WP_REST_Response && $done->status===201,'new Cloudflare zone enrollment');
expect_true($GLOBALS['zone_enroll_posts']===1,'one zone creation');
expect_true(is_wp_error($c::enroll(new Mock_Request($enroll))),'zone creation replay blocked');
$status=response_data($c::status());
expect_true($status['provider_secret_exposed']===false && !isset($status['token']),'never leak token');
echo 'PASS: routes 5, permissions and nonce, subdomain commit + idempotence, conflict denial, custom TXT ownership, zone create + replay block, no tokens\n';
