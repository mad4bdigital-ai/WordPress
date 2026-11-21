<?php
/**
 * Native GSC OAuth scope + retained site-owned observation source, PHP 7.4+.
 * All provider and WP adapters are synthetic. No outgoing HTTP or credentials.
 */
define('ABSPATH',__DIR__.'/');
define('DAY_IN_SECONDS',86400);
$GLOBALS['gsc_admin']=false;
$GLOBALS['gsc_scope']='off';
$GLOBALS['gsc_connected']=false;
$GLOBALS['gsc_snapshot']=array();
$GLOBALS['gsc_abilities']=array();
$GLOBALS['gsc_http_calls']=0;
$GLOBALS['gsc_writes']=0;
function add_action($name,$callback,$priority=10){}
function add_filter($name,$callback,$priority=10,$count=1){}
function wp_register_ability($name,$cfg){$GLOBALS['gsc_abilities'][$name]=$cfg;}
function wp_has_ability($name){return isset($GLOBALS['gsc_abilities'][$name]);}
function current_user_can($name){return $name==='manage_options' && $GLOBALS['gsc_admin'];}
function get_option($name,$default=array()){
 return $name==='mad4b_scp_native_gsc_snapshot_v1'?$GLOBALS['gsc_snapshot']:$default;
}
function update_option($name,$value,$autoload=false){
 ++$GLOBALS['gsc_writes'];throw new RuntimeException('No original GSC writes in read-only test');
}
function wp_parse_url($url,$component=-1){return $component===-1?parse_url($url):parse_url($url,$component);}
function home_url($path='/'){return 'https://tours.example.invalid'.($path==='/'?'/':'');}
function untrailingslashit($value){return rtrim($value,'/');}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function is_wp_error($r){return $r instanceof WP_Error;}
function wp_remote_request($url,$arguments){
 ++$GLOBALS['gsc_http_calls'];throw new RuntimeException('No OAuth GSC HTTP calls permitted');
}
class WP_Error{
 private $code;public function __construct($code,$message=''){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
interface MAD4B_SCP_G5_Stored_Observation_Adapter {}
class MAD4B_SCP_Google_Drive_Context {
 public static function workspace_grants_status(){
  return array('selection'=>array('search_console'=>$GLOBALS['gsc_scope']),
    'grant_sha256'=>str_repeat('b',64));
 }
 public static function connection_status(){
  return array('connected'=>$GLOBALS['gsc_connected'],
   'token_unreadable'=>false,'revocation_pending'=>false);
 }
 public static function measurement_broker_grant_for_scope($scope){return array();}
}
class MAD4B_SCP_Site_Profile {
 public static function site_uuid(){return '12345678-1234-4123-8123-123456789abc';}
}
class MAD4B_SCP_Policy {
 public static function can_read(){return $GLOBALS['gsc_admin'];}
}
class MAD4B_SCP_G5_External_Providers {
 const CONTRACT='mad4b.g5.ext.fake';
}
function verify_gsc($yes,$why){
 if(!$yes)throw new RuntimeException('FAIL NATIVE GSC: '.$why);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-native-search-console.php';
$c='MAD4B_SCP_Native_Search_Console';
$c::register_abilities();
verify_gsc(count($GLOBALS['gsc_abilities'])===2
 && isset($GLOBALS['gsc_abilities']['mad4b/search-console-status'])
 && isset($GLOBALS['gsc_abilities']['mad4b/search-console-observations']),
 'Only two real read-only GSC Abilities, neither an OAuth mutation');
foreach($GLOBALS['gsc_abilities'] as $id=>$cfg){
 verify_gsc($cfg['meta']['annotations']['readonly']===true
  && $cfg['input_schema']['additionalProperties']===false
  && call_user_func($cfg['permission_callback'])===false,
  'Unprivileged client must not inspect Search Console: '.$id);
}
$denied=$c::status_read();
verify_gsc($denied instanceof WP_Error
 && $denied->get_error_code()==='mad4b_gsc_permission_denied',
 'Search Console read-only status still needs WordPress admin');
$GLOBALS['gsc_admin']=true;
verify_gsc($c::property_matches_site('https://tours.example.invalid/'),
 'Exact URL prefix property rejected');
verify_gsc($c::property_matches_site('sc-domain:example.invalid'),
 'Site host must match an exact domain-property suffix');
foreach(array(
 'sc-domain:evil-example.invalid','sc-domain:another.invalid',
 'sc-domain:example.invalid.evil.net','sc-domain:example..invalid',
 'sc-domain:.invalid','https://example.invalid/',
 'http://tours.example.invalid/','https://tours.example.invalid.evil.net/',
 'https://user:pw@tours.example.invalid/',
 'https://tours.example.invalid:444/','https://tours.example.invalid/foo',
) as $foreign)verify_gsc(!$c::property_matches_site($foreign),
 'Cross-company property accepted: '.$foreign);
$payload=array('rows'=>array(
 array('keys'=>array('https://tours.example.invalid/tour/one'),
   'clicks'=>5,'impressions'=>50,'ctr'=>0.1,'position'=>3.25),
 array('keys'=>array('https://company-elsewhere.invalid/other'),
   'clicks'=>11,'impressions'=>120,'ctr'=>0.09166,'position'=>2),
 array('keys'=>array('https://tours.example.invalid/tour/bad'),
   'clicks'=>3,'impressions'=>2,'ctr'=>1.5,'position'=>2),
 array('keys'=>array('https://user@tours.example.invalid/path'),
   'clicks'=>4,'impressions'=>12,'ctr'=>0.3,'position'=>1)
));
$rows=$c::decode_rows($payload,'https://tours.example.invalid/');
verify_gsc(is_array($rows) && count($rows)===1
 && isset($rows['https://tours.example.invalid/tour/one'])
 && $rows['https://tours.example.invalid/tour/one']['ctr_percent']===10.0,
 'Provider rows must be bounded to site and truthful numeric evidence');
$GLOBALS['gsc_scope']='read';$GLOBALS['gsc_connected']=true;
$now=time();
$GLOBALS['gsc_snapshot']=array(
 'contract'=>$c::CONTRACT,
 'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
 'site_origin'=>'https://tours.example.invalid/',
 'property'=>'sc-domain:example.invalid',
 'captured_unix'=>$now-100,'captured_at'=>gmdate('c',$now-100),
 'valid_until'=>$now+1000,
 'rows'=>$rows,'sha256'=>hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
);
$refs=$c::observation_refs(array('limit'=>1,'offset'=>0));
verify_gsc($refs['state']==='retained' && $refs['count']===1
 && count($refs['items'])===1 && $refs['authorizing']===false
 && $refs['items'][0]['page']==='https://tours.example.invalid/tour/one'
 && !isset($refs['items'][0]['access_token']),
 'Read-only reference must be site-local and never expose OAuth token');
$invalid=$c::observation_refs(array('unrecognized_grant'=>true));
verify_gsc($invalid instanceof WP_Error && $invalid->get_error_code()==='mad4b_gsc_input_invalid',
 'Caller cannot provide arbitrary OAuth or provider arguments');
$spoofed=$GLOBALS['gsc_snapshot'];$spoofed['rows']['foreign']=array('page'=>'https://elsewhere.invalid/');
$GLOBALS['gsc_snapshot']=$spoofed;
verify_gsc($c::observation_refs()['state']==='unavailable',
 'Modified retained observations must not pass SHA-bound readback');
$GLOBALS['gsc_snapshot']['rows']=$rows;
$GLOBALS['gsc_scope']='off';
verify_gsc($c::observation_refs()['state']==='unavailable',
 'Revoked Workspace grant invalidates retained observation');
$GLOBALS['gsc_scope']='read';$GLOBALS['gsc_connected']=false;
verify_gsc($c::observation_refs()['state']==='unavailable',
 'Missing Google OAuth connection invalidates retained observation');
$GLOBALS['gsc_connected']=true;
$blocked=$c::discover();
verify_gsc($blocked instanceof WP_Error
 && $blocked->get_error_code()==='mad4b_gsc_explicit_consent_required'
 && $GLOBALS['gsc_http_calls']===0 && $GLOBALS['gsc_writes']===0,
 'Missing scoped OAuth grant cannot trigger HTTP or content update');
echo "NATIVE_GSC_PHP74_SCOPE: PASS native property, OAuth/tenant boundary, retained-only observations\n";
// Only after all assertions: canonical terminal marker for audited CI.
echo "NATIVE_GSC_PHP74_SCOPE: PASS\n";
