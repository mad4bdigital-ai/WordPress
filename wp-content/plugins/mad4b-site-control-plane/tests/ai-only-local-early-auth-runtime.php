<?php
/** Deterministic fail-closed test; no WordPress database, network, passwords or live mutation. */
define('ABSPATH',__DIR__.'/');
define('MAD4B_LOCAL_CLIENT_SITE_UUID','123e4567-e89b-12d3-a456-426614174000');
$GLOBALS['mock_environment']='local';
$GLOBALS['mock_user_site']=MAD4B_LOCAL_CLIENT_SITE_UUID;
$GLOBALS['mock_hooks']=array();
function wp_get_environment_type(){return $GLOBALS['mock_environment'];}
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
$run=function($label,$path,$method,$login,$site,$expected) {
    $GLOBALS['mock_user_site']=$site;
    $_GET=array();$_SERVER['REQUEST_URI']=$path;$_SERVER['REQUEST_METHOD']=$method;
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
$run('foreign_site_denied','/wp-json/mad4b-local-ai/v1/context-review','POST','mad4b_ai_local_fixture','00000000-0000-0000-0000-000000000000','mad4b_ai_only_service_site_denied');
$run('ordinary_user_not_impacted','/wp-json/wp/v2/users/me','GET','regular_subscriber',$site,'');
$GLOBALS['mock_environment']='production';
$run('production_not_managed','/wp-json/wp/v2/users/me','GET','mad4b_ai_local_fixture',$site,'');
echo "SUMMARY pass=11 fail=0\n";
echo "PASS: AI_ONLY_EARLY_AUTH_GUARD\n";
