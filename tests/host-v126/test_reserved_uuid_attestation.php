<?php
/** v126: signed Host reserved Site UUID denial paths; memory-only WP stubs. */
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
define('WP_ENVIRONMENT_TYPE','staging');
define('MAD4B_SCP_DIR',dirname(__DIR__,2).'/wp-content/plugins/mad4b-site-control-plane/');
$GLOBALS['opts']=[];$GLOBALS['admin']=true;$GLOBALS['attestation']='';$GLOBALS['write_calls']=0;
class WP_Error {private $c;function __construct($c,$m='',$d=null){$this->c=$c;}public function get_error_code(){return $this->c;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function current_user_can($c){return !empty($GLOBALS['admin']);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function absint($v){return abs((int)$v);}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function home_url($path=''){return 'https://tours.dev.mad4b.com/'.ltrim($path,'/');}
function site_url($path=''){return 'https://tours.dev.mad4b.com/'.ltrim($path,'/');}
function get_option($key,$def=false){return $GLOBALS['opts'][$key]??$def;}
function wp_get_environment_type(){return 'staging';}
function apply_filters($name,$v,...$args){
 return $name==='mad4b_scp_authorized_reserved_site_uuid'?$GLOBALS['attestation']:$v;
}
function wp_generate_uuid4(){return '23456789-1234-4234-8234-123456789abc';}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_bloginfo($key){return 'Staging fixture';}
function get_current_user_id(){return 3;}
function get_userdata($id){return (object)['ID'=>$id];}
function update_option($key,$val,$autoload=null){$GLOBALS['write_calls']++;$GLOBALS['opts'][$key]=$val;return true;}
function add_option($key,$val,$deprecated='',$autoload=null){$GLOBALS['write_calls']++;$GLOBALS['opts'][$key]=$val;return true;}
function delete_option($key){$GLOBALS['write_calls']++;unset($GLOBALS['opts'][$key]);return true;}
require_once MAD4B_SCP_DIR.'includes/class-mad4b-scp-site-profile.php';
$count=0;
function rejected($input,$code):void {
 global $count;
 $before=$GLOBALS['write_calls'];
 MAD4B_SCP_Site_Profile::reset_cache();
 $r=MAD4B_SCP_Site_Profile::save_current_site($input);
 if(!is_wp_error($r)||$r->get_error_code()!==$code)
   throw new RuntimeException('EXPECTED_DENIAL_'.$code.'_GOT_'.(is_wp_error($r)?$r->get_error_code():get_debug_type($r)));
 if($before!==$GLOBALS['write_calls'])throw new RuntimeException('UNAUTHORIZED_WRITE');
 ++$count;
}
$valid='23456789-1234-4234-8234-123456789abc';
rejected(['environment'=>'staging','reserved_site_uuid'=>'not-a-uuid'],'mad4b_reserved_site_uuid_invalid');
rejected(['environment'=>'staging','reserved_site_uuid'=>$valid],'mad4b_reserved_site_uuid_not_attested');
$GLOBALS['attestation']='12345678-1234-4234-8234-123456789abc';
rejected(['environment'=>'staging','reserved_site_uuid'=>$valid],'mad4b_reserved_site_uuid_not_attested');
$GLOBALS['attestation']='';$GLOBALS['admin']=false;
rejected(['environment'=>'staging','reserved_site_uuid'=>$valid],'mad4b_site_profile_admin_required');
echo json_encode(['status'=>'PASS','denied_cases'=>$count,
    'wordpress_persistent_writes'=>0,'host_signer_used'=>false],JSON_THROW_ON_ERROR),PHP_EOL;
