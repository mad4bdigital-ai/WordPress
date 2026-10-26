<?php
/** Original OAuth Resource Bridge: in-memory RS256/JWKS, no network or WordPress secrets. */
define('ABSPATH',__DIR__.'/');
define('MAD4B_MCP_OAUTH_MODE','external');
define('MAD4B_MCP_OAUTH_ISSUER','https://authority.test');
define('MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS',['user:permitted-subject']);
define('MAD4B_MCP_OAUTH_RESOURCE_POLICY_BY_ISSUER',['https://authority.test'=>['mad4b-chatgpt']]);
$GLOBALS['transients']=[];$GLOBALS['wp_user']=61;$GLOBALS['http_calls']=0;
class WP_Error{private $code;private $message;function __construct($code,$message=''){$this->code=$code;$this->message=$message;}function get_error_code(){return $this->code;}function get_error_message(){return $this->message;}}
function is_wp_error($r){return $r instanceof WP_Error;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function untrailingslashit($s){return rtrim((string)$s,'/');}
function wp_parse_url($u,$part=-1){return $part===-1?parse_url($u):parse_url($u,$part);}
function home_url($p=''){return 'https://site.test'.($p?('/'.ltrim($p,'/')):'');}
function rest_url($p=''){return home_url('/wp-json/'.ltrim($p,'/'));}
function add_query_arg($key,$val,$url){return $url.(strpos($url,'?')!==false?'&':'?').rawurlencode($key).'='.rawurlencode($val);}
function esc_url_raw($url){return $url;}
function get_transient($k){return $GLOBALS['transients'][$k]??false;}
function set_transient($k,$v,$ttl=0){$GLOBALS['transients'][$k]=$v;return true;}
function delete_transient($k){unset($GLOBALS['transients'][$k]);return true;}
function wp_set_current_user($id){$GLOBALS['wp_user']=$id;}
function wp_safe_remote_get($url,$options=[]){$GLOBALS['http_calls']++;return new WP_Error('test_no_network');}
require dirname(__DIR__).'/includes/class-mad4b-scp-oauth-resource-bridge.php';
$c='MAD4B_SCP_OAuth_Resource_Bridge';
function b64url($s){return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function ck($label,$pass){global $n;if(!$pass){fwrite(STDERR,'FAIL '.$label.PHP_EOL);exit(1);}$n++;}
$n=0;
$issuer=MAD4B_MCP_OAUTH_ISSUER;$resource=$c::resource_identifier('mad4b-chatgpt');
$jwksUrl=$issuer.'/keys';
$discoveryKey='mad4b_oauth_discovery_'.substr(hash('sha256',$issuer),0,32);
$jwksKey='mad4b_oauth_jwks_'.substr(hash('sha256',$issuer."\0".$jwksUrl),0,32);
$private=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
if(!$private){fwrite(STDERR,'RSA2048 fixture unavailable'.PHP_EOL);exit(1);}
$details=openssl_pkey_get_details($private);
$key=['kty'=>'RSA','kid'=>'test-2048','alg'=>'RS256','use'=>'sig','key_ops'=>['verify'],
 'n'=>b64url($details['rsa']['n']),'e'=>b64url($details['rsa']['e'])];
$GLOBALS['transients'][$discoveryKey]=['issuer'=>$issuer,'jwks_uri'=>$jwksUrl];
$GLOBALS['transients'][$jwksKey]=['keys'=>[$key]];
$now=time();
$claims=['iss'=>$issuer,'sub'=>'user:permitted-subject','aud'=>$resource,'resource'=>$resource,
 'exp'=>$now+3600,'iat'=>$now-10,'nbf'=>$now-10,'scope'=>'mad4b:read','client_id'=>'test-client',
 'azp'=>'test-client','jti'=>'original-fixture-jti'];
function sign_token($h,$c,$priv){
 $input=b64url(json_encode($h)).'.'.b64url(json_encode($c));
 openssl_sign($input,$signature,$priv,OPENSSL_ALGO_SHA256);
 return $input.'.'.b64url($signature);
}
$header=['alg'=>'RS256','typ'=>'at+jwt','kid'=>'test-2048'];
$verify=new ReflectionMethod($c,'verify_access_token');$verify->setAccessible(true);
$token=sign_token($header,$claims,$private);
$result=$verify->invoke(null,$token,$resource);
ck('signed RSA2048 access token verified by original implementation',is_array($result)&&$result['subject']==='user:permitted-subject');
ck('scope and client fingerprint are exact',$result['scopes']===['mad4b:read']
 &&$result['client_fingerprint']===hash('sha256','oauth-client'."\0".$issuer."\0".'test-client'));
ck('session fingerprint is token-bound',$result['session_fingerprint']===hash('sha256','oauth-token-instance'."\0".$issuer."\0".'original-fixture-jti'));
ck('trusted issuer explicit',$c::is_trusted_issuer($issuer));
ck('unknown issuer not trusted',!$c::is_trusted_issuer('https://other.test'));
ck('protected resource mapping exact',$c::resource_for_route('/mcp/mad4b-chatgpt')===$resource);
ck('unrelated route has no OAuth resource',$c::resource_for_route('/random/route')==='');
ck('developer resource not silently granted to read issuer',
 !$c::issuer_allowed_for_resource($issuer,$c::resource_identifier('mad4b-developer')));
ck('unapproved subject excluded',!$c::subject_allowed($issuer,'user:not-approved'));
$wrong=$claims;$wrong['sub']='user:not-approved';
$r=$verify->invoke(null,sign_token($header,$wrong,$private),$resource);
ck('validly signed unapproved subject denied',is_wp_error($r)&&$r->get_error_code()==='mad4b_oauth_subject_not_approved');
$wrong=$claims;$wrong['aud']='https://other.test';
$r=$verify->invoke(null,sign_token($header,$wrong,$private),$resource);
ck('signed mismatching audience denied',is_wp_error($r)&&$r->get_error_code()==='mad4b_oauth_audience_mismatch');
$wrong=$claims;$wrong['resource']='https://other.test';
$r=$verify->invoke(null,sign_token($header,$wrong,$private),$resource);
ck('signed wrong resource claim denied',is_wp_error($r)&&$r->get_error_code()==='mad4b_oauth_resource_mismatch');
$wrong=$claims;$wrong['exp']=$now-3600;
$r=$verify->invoke(null,sign_token($header,$wrong,$private),$resource);
ck('signed expired token denied',is_wp_error($r)&&$r->get_error_code()==='mad4b_oauth_token_expired');
$wrong=$claims;$wrong['scope']='mad4b:read *';
$r=$verify->invoke(null,sign_token($header,$wrong,$private),$resource);
ck('wildcard OAuth grant denied',is_wp_error($r)&&$r->get_error_code()==='mad4b_oauth_scope_invalid');
$wrong=$claims;$wrong['client_id']='A';$wrong['azp']='B';
$r=$verify->invoke(null,sign_token($header,$wrong,$private),$resource);
ck('different authorized party denied',is_wp_error($r)&&$r->get_error_code()==='mad4b_oauth_client_claim_mismatch');
$wrongHeader=['alg'=>'none','kid'=>'test-2048'];
$r=$verify->invoke(null,sign_token($wrongHeader,$claims,$private),$resource);
ck('alg none denied',is_wp_error($r)&&$r->get_error_code()==='mad4b_oauth_jwt_alg_denied');
$tampered=explode('.',$token);$tampered[2]=b64url('not-the-signature');
$r=$verify->invoke(null,implode('.',$tampered),$resource);
ck('tampered signature denied by actual OpenSSL verifier',is_wp_error($r)&&$r->get_error_code()==='mad4b_oauth_jwt_signature_invalid');
$keyWrong=$key;$keyWrong['key_ops']=['sign'];
$GLOBALS['transients'][$jwksKey]=['keys'=>[$keyWrong]];
$r=$verify->invoke(null,$token,$resource);
ck('sign-only JWK cannot verify',is_wp_error($r)&&$r->get_error_code()==='mad4b_oauth_jwk_key_ops_invalid');
$GLOBALS['transients'][$jwksKey]=['keys'=>[$key,$key]];
$r=$verify->invoke(null,$token,$resource);
ck('duplicate KID denied',is_wp_error($r)&&$r->get_error_code()==='mad4b_oauth_jwk_kid_ambiguous');
$GLOBALS['transients'][$jwksKey]=['keys'=>[$key]];
$weak=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>1024]);
$detailsWeak=openssl_pkey_get_details($weak);
$weakKey=$key;$weakKey['n']=b64url($detailsWeak['rsa']['n']);$weakKey['e']=b64url($detailsWeak['rsa']['e']);
$GLOBALS['transients'][$jwksKey]=['keys'=>[$weakKey]];
$r=$verify->invoke(null,$token,$resource);
ck('weak RSA modulus denied',is_wp_error($r)&&$r->get_error_code()==='mad4b_oauth_jwk_rsa_too_small');
$GLOBALS['transients'][$jwksKey]=['keys'=>[$key]];
ck('verified bearer is inactive until authenticated original REST request',$c::verified_bearer_active()===false);
$c::reset_verified_bearer_context(true);
ck('denial clears WordPress user',$GLOBALS['wp_user']===0&&!$c::verified_bearer_active());
ck('all signed requests simulated without HTTP',$GLOBALS['http_calls']===0);
echo 'OAUTH_RS256_ORIGINAL: PASS '.$n.PHP_EOL;
