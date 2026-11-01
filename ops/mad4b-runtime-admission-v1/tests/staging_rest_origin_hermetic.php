<?php
/* Hermetic regression for the original central Staging 015 admission guard. */
declare(strict_types=1);
define('ABSPATH','/tmp/mad4b-hermetic/');
define('WP_ENVIRONMENT_TYPE','staging');
$GLOBALS['runtime_env']='staging';
$GLOBALS['home_option']='https://tours.dev.mad4b.com';
$GLOBALS['presentation_path']='';
$GLOBALS['site_origin']='https://tours.dev.mad4b.com';
$GLOBALS['profile_authoritative']=true;
$_SERVER['HTTP_HOST']='tours.dev.mad4b.com';

function wp_get_environment_type() {return $GLOBALS['runtime_env'];}
function get_option($key,$default=null) {return $key==='home'?$GLOBALS['home_option']:$default;}
function wp_parse_url($url) {return parse_url($url);}
function home_url($path='/') {return $GLOBALS['home_option'].$GLOBALS['presentation_path'];}
function add_filter() {return true;}
class MAD4B_SCP_Environment {
    public static function effective() {return $GLOBALS['runtime_env'];}
}
class MAD4B_SCP_Site_Profile {
    public static function environment_resolution() {
        return ['profile_environment_authoritative'=>$GLOBALS['profile_authoritative'],
            'wordpress_profile_mismatch'=>false,
            'effective_environment'=>$GLOBALS['runtime_env']];
    }
    public static function site_uuid() {return 'e5e64bca-ed62-48d2-b2b3-dff693cd702a';}
    public static function site_origin() {return $GLOBALS['site_origin'];}
    public static function current_origin() {return rtrim(home_url('/'),'/');}
}
class WP_Error {
    private $name;
    private $data;
    public function __construct($name,$message,$data) {$this->name=$name;$this->data=$data;}
    public function get_error_code() {return $this->name;}
    public function get_error_data() {return $this->data;}
}
class TestRequest {
    private $method;
    private $route;
    public function __construct($method,$route) {$this->method=$method;$this->route=$route;}
    public function get_method() {return $this->method;}
    public function get_route() {return $this->route;}
}
function check($condition,$why) {if(!$condition) throw new RuntimeException($why);}
require __DIR__.'/../015-mad4b-runtime-admission.php';

$route='/mad4b-central/v1/company-environments/all-royal-egypt/staging/manager-assignment';
$post=new TestRequest('POST',$route);
$guard=MAD4B_Runtime_Admission::environment_status();
check($guard['verified']===true, 'baseline exact origin must pass');
check(MAD4B_Runtime_Admission::rest_write(null,null,$post)===null,'valid central REST host must reach original owner grant');

$GLOBALS['presentation_path']='/en/';
check(MAD4B_SCP_Site_Profile::current_origin()!==MAD4B_SCP_Site_Profile::site_origin(),
    'fixture must reproduce localized home_url() origin drift in original guard');
check(MAD4B_Runtime_Admission::environment_status()['verified']===true,
    'language-only home_url() path must not downgrade the persisted Staging identity');
check(MAD4B_Runtime_Admission::rest_write(null,null,$post)===null,
    'localized REST host should reach original 317 owner and nonce checks');

$GLOBALS['home_option']='https://foreign.example';
check(MAD4B_Runtime_Admission::environment_status()['verified']===false,
    'a different stored WordPress home may never be treated as the enrolled site');
$err=MAD4B_Runtime_Admission::rest_write(null,null,$post);
check($err instanceof WP_Error && $err->get_error_data()['status']===423,
    'foreign home must remain fail closed');

$GLOBALS['home_option']='https://tours.dev.mad4b.com';
$_SERVER['HTTP_HOST']='foreign.example';
$err=MAD4B_Runtime_Admission::rest_write(null,null,$post);
check($err instanceof WP_Error && ($err->get_error_data()['reason']??'')==='request_host_mismatch',
    'wrong HTTP Host must be rejected even with valid persisted profile');
check(MAD4B_Runtime_Admission::rest_write(null,null,new TestRequest('GET',$route))===null,
    'GET diagnostics and readbacks must remain readable');

$_SERVER['HTTP_HOST']='tours.dev.mad4b.com';
$GLOBALS['profile_authoritative']=false;
check(MAD4B_Runtime_Admission::environment_status()['verified']===false,
    'a non-authoritative Site Profile must not provide write authority');
$GLOBALS['profile_authoritative']=true;
$GLOBALS['runtime_env']='production';
check(MAD4B_Runtime_Admission::environment_status()['verified']===false,
    'production cannot become Staging by a localized URL');
$GLOBALS['runtime_env']='staging';

check(MAD4B_Runtime_Admission::rest_write(null,null,new TestRequest('POST','/wp/v2/posts'))===null,
    'existing non-MAD4B route semantics must remain unaffected');
echo "MAD4B_RUNTIME_HTTP_STAGING_GUARD_HERMETIC_PASS\n";
