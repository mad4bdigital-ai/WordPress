<?php
/** First-party human browser/HTTPS transport request classification, no credential handling. */
define('ABSPATH',__DIR__.'/');
class WP_Error{function __construct($code,$msg='',$data=[]){}}
function is_user_logged_in(){return $GLOBALS['logged'];}
function wp_get_session_token(){return str_repeat('x',40);}
function is_ssl(){return $GLOBALS['ssl'];}
function home_url(){return $GLOBALS['home'];}
function wp_parse_url($url,$field=null){return $field===PHP_URL_SCHEME?parse_url($url,PHP_URL_SCHEME):parse_url($url);}
class MAD4B_SCP_Adaptive_Interaction_Broker {static $allowed=true;static function permitted(){return self::$allowed;}}
class MAD4B_SCP_Local_Transport_Policy {static $allowed=false;static function request_allowed($url,$server,$post){return self::$allowed;}}
function ui_check($yes,$why){if(!$yes){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}$GLOBALS['n']=($GLOBALS['n']??0)+1;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-adaptive-interaction-ui.php';
$c='MAD4B_SCP_Adaptive_Interaction_UI';
$b=new ReflectionMethod($c,'valid_browser');
$channel=new ReflectionMethod($c,'allowed_channel');
$values=new ReflectionMethod($c,'typed_values');
if(PHP_VERSION_ID<80100){$b->setAccessible(true);$channel->setAccessible(true);$values->setAccessible(true);}
unset($_SERVER['HTTP_AUTHORIZATION'],$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
$GLOBALS['home']='https://site.example';
$GLOBALS['logged']=true;$GLOBALS['ssl']=true;
ui_check($b->invoke(null)===true&&$channel->invoke(null)===true,
 'Verified native user session and HTTPS browser remain eligible');
foreach([new WP_Error('login_unknown'),1,'true',null,false] as $bad){
 $GLOBALS['logged']=$bad;
 ui_check($b->invoke(null)===false,'Error-shaped browser login is not authenticated');
}
$GLOBALS['logged']=true;
foreach([new WP_Error('broker_unknown'),1,'true',null,false] as $bad){
 MAD4B_SCP_Adaptive_Interaction_Broker::$allowed=$bad;
 ui_check($b->invoke(null)===false,'Error-shaped first-party broker permission is not authenticated');
}
MAD4B_SCP_Adaptive_Interaction_Broker::$allowed=true;
foreach([new WP_Error('tls_unknown'),1,'true',null,false] as $bad){
 $GLOBALS['ssl']=$bad;
 ui_check($channel->invoke(null)===false,'Unverified HTTPS connection cannot accept browser secrets');
}
$GLOBALS['home']='http://site.local';$GLOBALS['ssl']=false;
foreach([new WP_Error('local_policy_unknown'),1,'true',null,false] as $bad){
 MAD4B_SCP_Local_Transport_Policy::$allowed=$bad;
 ui_check($channel->invoke(null)===false,'Only exact Local Transport Policy approval admits HTTP native handoff');
}
$form=['fields'=>[['key'=>'enabled','type'=>'boolean']]];
ui_check($values->invoke(null,$form,['enabled'=>'1'])===['enabled'=>true],
 'Original typed boolean schema remains validated');
ui_check($values->invoke(null,$form,['enabled'=>'invalid'])===null,
 'Malformed controlled form boolean is rejected');
ui_check($values->invoke(null,['fields'=>[['key'=>new WP_Error('schema_bad'),'type'=>'string']]],[])===null,
 'Malformed source schema key cannot be used as native form map index');
echo 'PASS: ADAPTIVE_INTERACTION_UI_ORIGINAL '.($GLOBALS['n']??0).
 ' native user session, local/HTTPS and typed input denials'.PHP_EOL;
