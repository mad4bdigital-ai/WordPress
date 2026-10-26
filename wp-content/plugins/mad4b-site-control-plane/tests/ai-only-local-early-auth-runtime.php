<?php
/** Deterministic fail-closed test; no WordPress database, network, passwords or live mutation. */
define('ABSPATH',__DIR__.'/');
define('MAD4B_LOCAL_CLIENT_SITE_UUID','123e4567-e89b-12d3-a456-426614174000');
$GLOBALS['mock_environment']='local';
$GLOBALS['mock_user_site']=MAD4B_LOCAL_CLIENT_SITE_UUID;
$GLOBALS['mock_hooks']=array();
function wp_get_environment_type(){return $GLOBALS['mock_environment'];}
function is_ssl(){return $GLOBALS['mock_ssl']??false;}
function home_url($path='/'){return ($GLOBALS['mock_home']??'https://lab.example/').ltrim((string)$path,'/');}
function add_action($name,$callback,$priority=10,$args=1){$GLOBALS['mock_hooks'][$name]=$callback;}
function get_user_meta($id,$key,$single){return $GLOBALS['mock_user_site'];}
function wp_parse_url($url,$component){return parse_url($url,$component);}
class WP_User{
    public $ID;
    public $user_login;
    public function __construct($id,$login){$this->ID=$id;$this->user_login=$login;}
}
class WP_Error{
    public $codes=array();
    public function add($code,$message){$this->codes[]=$code;}
    public function has_errors(){return !empty($this->codes);}
}
require_once __DIR__.'/../includes/class-mad4b-scp-ai-only-early-auth-guard.php';
if(!isset($GLOBALS['mock_hooks']['wp_authenticate_application_password_errors']))exit(1);
$run=function($label,$path,$method,$login,$site,$expected,$rest_route='') {
    $GLOBALS['mock_user_site']=$site;
    $_GET=$rest_route!==''?array('rest_route'=>$rest_route):array();$_SERVER['REQUEST_URI']=$path;$_SERVER['REQUEST_METHOD']=$method;
    $error=new WP_Error();$user=new WP_User(44,$login);
    MAD4B_SCP_AI_Only_Early_Auth_Guard::authenticate($error,$user,array('uuid'=>'fixture'));
    $actual=implode(',',$error->codes);
    if($actual!==$expected){fwrite(STDERR,"FAIL $label got $actual expected $expected\n");exit(1);}
    echo $label.": PASS\n";
};
$site=MAD4B_LOCAL_CLIENT_SITE_UUID;
$run('valid_local_review_post','/wp-json/mad4b-local-ai/v1/context-review','POST','mad4b_ai_local_fixture',$site,'');
$run('valid_native_owner_review_post','/wp-json/mad4b-local-ai/v1/domain-review','POST','mad4b_ai_local_fixture',$site,'');
$run('native_owner_review_get_denied','/wp-json/mad4b-local-ai/v1/domain-review','GET','mad4b_ai_local_fixture',$site,'mad4b_ai_only_service_route_denied');
$run('native_owner_review_put_denied','/wp-json/mad4b-local-ai/v1/domain-review','PUT','mad4b_ai_local_fixture',$site,'mad4b_ai_only_service_route_denied');
$run('non_review_route_denied','/wp-json/wp/v2/users/me','GET','mad4b_ai_local_fixture',$site,'mad4b_ai_only_service_route_denied');
$run('other_mcp_route_denied','/wp-json/mcp/mad4b-chatgpt','POST','mad4b_ai_local_fixture',$site,'mad4b_ai_only_service_route_denied');
$run('review_queue_get_allowed','/wp-json/mad4b-local-ai/v1/context-review','GET','mad4b_ai_local_fixture',$site,'');
$run('method_put_denied','/wp-json/mad4b-local-ai/v1/context-review','PUT','mad4b_ai_local_fixture',$site,'mad4b_ai_only_service_route_denied');
$run('valid_query_route_review','/?rest_route=/mad4b-local-ai/v1/context-review','POST','mad4b_ai_local_fixture',$site,'','/mad4b-local-ai/v1/context-review');
$run('valid_query_route_domain','/index.php?rest_route=/mad4b-local-ai/v1/domain-review','POST','mad4b_ai_local_fixture',$site,'','/mad4b-local-ai/v1/domain-review');
$run('foreign_pretty_route_with_spoofed_query_denied','/wp-json/wp/v2/users/me?rest_route=/mad4b-local-ai/v1/context-review','POST','mad4b_ai_local_fixture',$site,'mad4b_ai_only_service_route_denied','/mad4b-local-ai/v1/context-review');
$run('arbitrary_admin_with_spoofed_query_denied','/wp-admin/admin-ajax.php?rest_route=/mad4b-local-ai/v1/context-review','POST','mad4b_ai_local_fixture',$site,'mad4b_ai_only_service_route_denied','/mad4b-local-ai/v1/context-review');
$run('pretty_route_with_conflicting_query_denied','/wp-json/mad4b-local-ai/v1/context-review?rest_route=/wp/v2/users/me','POST','mad4b_ai_local_fixture',$site,'mad4b_ai_only_service_route_denied','/wp/v2/users/me');
$run('query_route_domain_get_denied','/?rest_route=/mad4b-local-ai/v1/domain-review','GET','mad4b_ai_local_fixture',$site,'mad4b_ai_only_service_route_denied','/mad4b-local-ai/v1/domain-review');
$run('query_route_foreign_denied','/?rest_route=/wp/v2/users/me','POST','mad4b_ai_local_fixture',$site,'mad4b_ai_only_service_route_denied','/wp/v2/users/me');
$GLOBALS['mock_home']='https://lab.example/lab/';
$run('subdir_wordpress_query_review_allowed','/lab/?rest_route=/mad4b-local-ai/v1/context-review','POST','mad4b_ai_local_fixture',$site,'','/mad4b-local-ai/v1/context-review');
$run('wrong_subdir_query_denied','/wrong/?rest_route=/mad4b-local-ai/v1/context-review','POST','mad4b_ai_local_fixture',$site,'mad4b_ai_only_service_route_denied','/mad4b-local-ai/v1/context-review');
unset($GLOBALS['mock_home']);
$run('foreign_site_denied','/wp-json/mad4b-local-ai/v1/context-review','POST','mad4b_ai_local_fixture','00000000-0000-0000-0000-000000000000','mad4b_ai_only_service_site_denied');
$run('ordinary_user_not_impacted','/wp-json/wp/v2/users/me','GET','regular_subscriber',$site,'');
$GLOBALS['mock_environment']='production';
$run('production_default_denied','/wp-json/wp/v2/users/me','GET','mad4b_ai_local_fixture',$site,'mad4b_ai_review_tls_required');
define('MAD4B_AI_REVIEW_CROSS_ENV_ENABLED',true);
define('MAD4B_AI_REVIEW_SITE_UUID',MAD4B_LOCAL_CLIENT_SITE_UUID);
$GLOBALS['mock_ssl']=true;
$run('production_scoped_review_allowed','/wp-json/mad4b-local-ai/v1/context-review','POST','mad4b_ai_local_fixture',$site,'');
$run('production_foreign_admin_denied','/wp-json/wp/v2/users/me','GET','mad4b_ai_local_fixture',$site,'mad4b_ai_only_service_route_denied');
$run('production_foreign_site_denied','/wp-json/mad4b-local-ai/v1/context-review','POST','mad4b_ai_local_fixture','00000000-0000-0000-0000-000000000000','mad4b_ai_only_service_site_denied');
$GLOBALS['mock_environment']='staging';
$run('staging_scoped_review_allowed','/wp-json/mad4b-local-ai/v1/context-review','POST','mad4b_ai_local_fixture',$site,'');
$run('staging_query_spoof_denied','/wp-admin/admin-ajax.php?rest_route=/mad4b-local-ai/v1/context-review','POST','mad4b_ai_local_fixture',$site,'mad4b_ai_only_service_route_denied','/mad4b-local-ai/v1/context-review');
$GLOBALS['mock_environment']='production';
$GLOBALS['mock_ssl']=false;
$run('production_http_denied','/wp-json/mad4b-local-ai/v1/context-review','POST','mad4b_ai_local_fixture',$site,'mad4b_ai_review_tls_required');
echo "SUMMARY pass=26 fail=0\n";
echo "PASS: AI_ONLY_EARLY_AUTH_GUARD\n";
