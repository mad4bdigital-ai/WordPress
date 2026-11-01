<?php
/** JWT and subject preflight gates: DENY ONLY, no signature or authority asserted. */
define('ABSPATH',__DIR__.'/');
$GLOBALS['trusted_issuer']='https://issuer.example';
$GLOBALS['resource']='https://service.example/mcp/mad4b-chatgpt';
$GLOBALS['subject_allowed']=true;
class WP_Error{private $code;private $message;
 function __construct($c,$m=''){$this->code=$c;$this->message=$m;}
 function get_error_code(){return $this->code;}
 function get_error_message(){return $this->message;}
}
class WP_REST_Response{
 private $status;private $data;public $headers=[];
 function __construct($data=[],$status=200){$this->data=$data;$this->status=$status;}
 function get_status(){return $this->status;}
 function get_data(){return $this->data;}
 function header($name,$v){$this->headers[$name]=$v;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim((string)$v);}
function untrailingslashit($s){return rtrim((string)$s,'/');}
function add_filter($hook,$callback,$priority=10,$args=1){}
class MAD4B_SCP_OAuth_Resource_Bridge{
 static function resource_for_route($route){
  return in_array($route,['/mcp/mad4b-chatgpt','/mcp/mad4b-developer'],true)?$GLOBALS['resource']:'';
 }
 static function challenge_header($resource){return 'Bearer resource_metadata="https://service.example/.well-known/oauth-protected-resource"';}
 static function is_trusted_issuer($i){return $i===$GLOBALS['trusted_issuer'];}
 static function subject_allowed($iss,$sub){return $GLOBALS['subject_allowed']&&$iss===$GLOBALS['trusted_issuer']&&$sub==='approved-subject';}
 static function status(){return ['effective'=>false,'authority_mode'=>'test'];}
 static function trusted_issuers(){return [$GLOBALS['trusted_issuer']];}
 static function allowed_subjects_for_issuer($issuer){return ['approved-subject'];}
}
class TestRequest{
 private $route,$method,$header;
 function __construct($route,$method,$auth=''){$this->route=$route;$this->method=$method;$this->header=$auth;}
 function get_route(){return $this->route;}
 function get_method(){return $this->method;}
 function get_header($key){return strtolower($key)==='authorization'?$this->header:'';}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-oauth-jwt-header-guard.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-oauth-subject-gate.php';
$n=0;
function ck($name,$ok){global $n;if(!$ok){fwrite(STDERR,'FAIL '.$name.PHP_EOL);exit(1);}$n++;}
function b64($s){return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function token($h,$c){return b64(json_encode($h)).'.'.b64(json_encode($c)).'.'.b64('not-a-valid-signature');}
function auth($h,$c){return 'Bearer '.token($h,$c);}
function status($v,$code,$http){
 return $v instanceof WP_REST_Response&&$v->get_status()===$http
   &&($v->get_data()['error']??'')===$code
   &&($v->headers['Cache-Control']??'')==='no-store';
}
$header='MAD4B_SCP_OAuth_JWT_Header_Guard';$subject='MAD4B_SCP_OAuth_Subject_Gate';
$goodH=['alg'=>'RS256','typ'=>'at+jwt'];$goodC=['iss'=>$GLOBALS['trusted_issuer'],
 'sub'=>'approved-subject','aud'=>$GLOBALS['resource'],'resource'=>$GLOBALS['resource']];
$request=new TestRequest('/mcp/mad4b-chatgpt','POST',auth($goodH,$goodC));
ck('JWT preflight correctly passes to later verifier',$header::enforce(null,null,$request)===null);
ck('Subject preflight correctly passes to later verifier',$subject::enforce(null,null,$request)===null);
ck('both preflight gates are deny-only, not crypto verified',$header::status()['creates_authority']===false
 &&$subject::status()['claims_used_before_signature']==='deny-only');
ck('no bearer is passed to original auth verifier',$header::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST'))===null);
ck('subject gate without bearer is advisory only',$subject::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST'))===null);
ck('OPTIONS skips gate for CORS',$header::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','OPTIONS','not-bearer'))===null);
ck('subject OPTIONS skips gate',$subject::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','OPTIONS','not-bearer'))===null);
ck('unknown resource outside gate',$header::enforce(null,null,new TestRequest('/wp-json/unrelated','POST','not-bearer'))===null);
ck('unknown resource outside subject gate',$subject::enforce(null,null,new TestRequest('/wp-json/unrelated','POST','not-bearer'))===null);
ck('other existing predispatch denial preserved',$header::enforce('blocked',null,$request)==='blocked'&&$subject::enforce('blocked',null,$request)==='blocked');
$bad=$goodH;unset($bad['typ']);
ck('header missing typ denied',status($header::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST',auth($bad,$goodC))),'mad4b_oauth_jwt_typ_denied',401));
$bad=$goodH;$bad['typ']='JWT';
ck('header wrong typ denied',status($header::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST',auth($bad,$goodC))),'mad4b_oauth_jwt_typ_denied',401));
$bad=$goodH;$bad['alg']='none';
ck('none algorithm denied',status($header::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST',auth($bad,$goodC))),'mad4b_oauth_jwt_alg_denied',401));
$bad=$goodH;$bad['alg']='HS256';
ck('HS256 denied',status($header::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST',auth($bad,$goodC))),'mad4b_oauth_jwt_alg_denied',401));
ck('malformed compact token denied',status($header::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST','Bearer bogus')),'mad4b_oauth_jwt_header_invalid',401));
ck('invalid bearer scheme denied',status($header::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST','Basic abc')),'mad4b_oauth_jwt_header_invalid',401));
ck('oversized header denied',status($header::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST','Bearer '.str_repeat('z',17000))),'mad4b_oauth_jwt_header_invalid',401));
$bad=$goodC;$bad['iss']='https://evil.example';
ck('untrusted issuer denied',status($subject::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST',auth($goodH,$bad))),'mad4b_oauth_subject_issuer_mismatch',403));
$bad=$goodC;$bad['sub']='unlisted';
ck('unapproved subject denied',status($subject::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST',auth($goodH,$bad))),'mad4b_oauth_subject_not_approved',403));
$GLOBALS['subject_allowed']=false;
ck('independent subject allowlist deny',status($subject::enforce(null,null,$request),'mad4b_oauth_subject_not_approved',403));
$GLOBALS['subject_allowed']=true;
$bad=$goodC;$bad['sub']='';
ck('empty subject denied',status($subject::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST',auth($goodH,$bad))),'mad4b_oauth_subject_invalid',403));
$bad=$goodC;$bad['aud']='https://wrong.example';
ck('wrong audience denied',status($subject::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST',auth($goodH,$bad))),'mad4b_oauth_subject_audience_mismatch',403));
$bad=$goodC;$bad['aud']=array_fill(0,21,$GLOBALS['resource']);
ck('oversized audience set denied',status($subject::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST',auth($goodH,$bad))),'mad4b_oauth_subject_audience_mismatch',403));
$bad=$goodC;$bad['resource']='https://other.example';
ck('mismatched resource denied',status($subject::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST',auth($goodH,$bad))),'mad4b_oauth_subject_resource_mismatch',403));
$bad=$goodC;unset($bad['resource']);
ck('missing resource denied',status($subject::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST',auth($goodH,$bad))),'mad4b_oauth_subject_resource_mismatch',403));
ck('malformed subject JWT denied',status($subject::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST','Bearer bogus')),'mad4b_oauth_subject_jwt_invalid',401));
ck('WWW-Authenticate challenge is emitted by every denial',
 isset($subject::enforce(null,null,new TestRequest('/mcp/mad4b-chatgpt','POST','Bearer bogus'))->headers['WWW-Authenticate']));
echo 'PASS: '.$n.' OAuth deny-only preflight cases'.PHP_EOL;
