<?php
define('ABSPATH',__DIR__);define('MAD4B_SCP_TEST_MODE',true);
class WP_Error{private $c,$m,$d;function __construct($c='',$m='',$d=null){$this->c=(string)$c;$this->m=(string)$m;$this->d=$d;}function get_error_code(){return$this->c;}function get_error_message(){return$this->m;}function get_error_data(){return$this->d;}}
function is_wp_error($v){return$v instanceof WP_Error;}function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}function wp_parse_url($v){return parse_url($v);}function add_filter(){}
require dirname(__DIR__).'/includes/class-mad4b-scp-egress-policy.php';
$fail=static function($m,$v=null){fwrite(STDERR,'FAIL egress-policy-runtime: '.$m.(null===$v?'':' '.json_encode($v)).PHP_EOL);exit(1);};$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};
$args=MAD4B_SCP_Egress_Policy::mark_request('oauth_discovery','https://auth.example.test/.well-known/openid-configuration','https://auth.example.test',array('timeout'=>5,'redirection'=>0));
$check(is_array($args)&&true===$args['sslverify']&&0===$args['redirection']&&true===$args['reject_unsafe_urls'],'request hardening failed',$args);
$check('mad4b_egress_https_required'===$code(MAD4B_SCP_Egress_Policy::mark_request('oauth_discovery','http://auth.example.test/x','http://auth.example.test',array())),'HTTP accepted');
$check('mad4b_egress_tls_verification_required'===$code(MAD4B_SCP_Egress_Policy::mark_request('oauth_jwks','https://auth.example.test/jwks','https://auth.example.test',array('sslverify'=>false))),'sslverify=false accepted');
$check('mad4b_egress_redirect_budget_exceeded'===$code(MAD4B_SCP_Egress_Policy::mark_request('oauth_discovery','https://auth.example.test/x','https://auth.example.test',array('redirection'=>1))),'redirect widening accepted');
$check('mad4b_egress_authority_origin_drift'===$code(MAD4B_SCP_Egress_Policy::mark_request('oauth_jwks','https://keys.example.test/jwks','https://auth.example.test',array())),'origin drift accepted');
$media_args=MAD4B_SCP_Egress_Policy::mark_request('remote_media_import','https://images.example.test/photo.jpg','https://images.example.test',array('timeout'=>5,'redirection'=>0));
$inspect_args=MAD4B_SCP_Egress_Policy::mark_request('remote_media_inspect','https://images.example.test/photo.jpg','https://images.example.test',array('timeout'=>5,'redirection'=>0));
$check(is_array($media_args)&&true===$media_args['sslverify']&&0===$media_args['redirection'],'remote media request hardening failed',$media_args);
$check(is_array($inspect_args)&&true===$inspect_args['sslverify']&&0===$inspect_args['redirection'],'remote media inspection hardening failed',$inspect_args);
$check('mad4b_egress_https_required'===$code(MAD4B_SCP_Egress_Policy::mark_request('remote_media_discovery','http://example.test/page','http://example.test',array())),'remote media HTTP accepted');
$check(!MAD4B_SCP_Egress_Policy::proxy_host_allowed('proxy.local',array('other.local'))&&MAD4B_SCP_Egress_Policy::proxy_host_allowed('proxy.local',array('proxy.local')),'proxy trust failed');
MAD4B_SCP_Egress_Policy::set_test_resolver(static function(){return array('93.184.216.34');});$check(false===MAD4B_SCP_Egress_Policy::enforce(false,$args,'https://auth.example.test/x'),'public DNS rejected');
MAD4B_SCP_Egress_Policy::set_test_resolver(static function(){return array('10.0.0.7');});$check('mad4b_egress_dns_private_or_reserved'===$code(MAD4B_SCP_Egress_Policy::enforce(false,$args,'https://auth.example.test/x')),'private DNS accepted');
$n=0;MAD4B_SCP_Egress_Policy::set_test_resolver(static function()use(&$n){$n++;return 1===$n?array('93.184.216.34'):array('93.184.216.35');});$check(false===MAD4B_SCP_Egress_Policy::enforce(false,$args,'https://auth.example.test/x'),'initial DNS pin failed');$check('mad4b_egress_dns_answer_changed'===$code(MAD4B_SCP_Egress_Policy::enforce(false,$args,'https://auth.example.test/x')),'DNS drift accepted');
$mock=array('response'=>array('code'=>200));MAD4B_SCP_Egress_Policy::set_test_resolver(static function(){return array('10.0.0.8');});$check($mock===MAD4B_SCP_Egress_Policy::enforce($mock,$args,'https://auth.example.test/x'),'preempted request was revalidated');
$tls=new WP_Error('http_request_failed','SSL certificate hostname mismatch');$check('mad4b_egress_tls_verification_failed'===$code(MAD4B_SCP_Egress_Policy::classify_transport_error($tls,'oauth_jwks')),'TLS error not normalized');
$s=MAD4B_SCP_Egress_Policy::status();$check(!empty($s['ready'])&&empty($s['authorizing'])&&false===$s['private_or_reserved_dns_answers_allowed'],'status invalid',$s);
echo "mad4b.egress-policy.runtime.v1: PASS\n";
