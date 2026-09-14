<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'MAD4B_SCP_DIR' ) ) define( 'MAD4B_SCP_DIR', rtrim( dirname( __DIR__ ), '/\\' ) . '/' );
$GLOBALS['opts'] = array();
$GLOBALS['env'] = 'staging';
$GLOBALS['home'] = 'https://staging.example.test';
class WP_Error { private $c; public function __construct($c,$m='',$d=null){$this->c=$c;} public function get_error_code(){return $this->c;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function esc_url_raw($v){return (string)$v;}
function absint($v){return abs((int)$v);}
function wp_parse_url($u,$c=-1){return parse_url($u,$c);}
function wp_json_encode($v,$f=0){return json_encode($v,$f);}
function trailingslashit($v){return rtrim((string)$v,'/\\').'/';}
function untrailingslashit($v){return rtrim((string)$v,'/\\');}
function home_url($p=''){return rtrim($GLOBALS['home'],'/').(''===$p?'':'/'.ltrim($p,'/'));}
function rest_url($p=''){return rtrim($GLOBALS['home'],'/').'/wp-json/'.ltrim($p,'/');}
function wp_get_environment_type(){return $GLOBALS['env'];}
function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['opts'])?$GLOBALS['opts'][$k]:$d;}
function update_option($k,$v,$a=null){$GLOBALS['opts'][$k]=$v;return true;}
function delete_option($k){unset($GLOBALS['opts'][$k]);return true;}
function get_userdata($id){return (int)$id===7?(object)array('ID'=>7):false;}
function user_can($u,$c){return is_object($u)&&$u->ID===7&&$c==='manage_options';}
function current_user_can($c){return true;}
function get_current_user_id(){return 7;}
function wp_generate_uuid4(){return 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';}
function get_bloginfo($k){return 'Test';}
function add_action(){}
function add_filter(){}
function remove_action(){}
function is_admin(){return false;}
function esc_html($v){return $v;}
function esc_html__($v){return $v;}
function nocache_headers(){}
function status_header($v){}
function wp_register_ability(){}
function wp_has_ability(){return false;}
final class MAD4B_SCP_Audit { public static function storage_status(){return array('ready'=>true);} public static function record(){return true;} }
require dirname(__DIR__).'/includes/class-mad4b-scp-site-profile.php';
final class MAD4B_SCP_Local_OAuth_Server {
    private static function base(){return rtrim($GLOBALS['home'],'/');}
    public static function authorize_url(){return self::base().'/oauth/mcp/authorize';}
    public static function token_url(){return self::base().'/oauth/mcp/token';}
    public static function jwks_url(){return self::base().'/oauth/mcp/jwks';}
    public static function revocation_url(){return self::base().'/oauth/mcp/revoke';}
    public static function metadata_url(){
        $issuer=self::base().'/oauth/mcp';$parts=parse_url($issuer);
        $origin=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
        return $origin.'/.well-known/oauth-authorization-server/'.ltrim(rtrim($parts['path'],'/'),'/');
    }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-upgrade-continuity.php';
function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function legacy($env='staging',$origin='https://staging.example.test'){return array('contract'=>MAD4B_SCP_Site_Profile::LEGACY_CONTRACT,'version'=>MAD4B_SCP_Site_Profile::LEGACY_VERSION,'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000','revision'=>4,'environment'=>$env,'canonical_origin'=>$origin,'display_name'=>'Legacy','chatgpt_app_id'=>'plugin_asdk_app_legacy','oauth_user_ids'=>array(7,8),'related_origins'=>array(),'features'=>array('oauth'=>true,'skills'=>true,'write'=>true,'production_write_confirmed'=>true,'provider_isolation'=>true,'managed_runtime'=>true,'acceptance'=>true),'legacy_agent_slug'=>'legacy','legacy_zero_touch'=>true);}
function resetx(){ $GLOBALS['opts']=array();$GLOBALS['env']='staging';$GLOBALS['home']='https://staging.example.test';MAD4B_SCP_Site_Profile::reset_cache(); }

// Fresh installs remain zero-authority.
resetx();
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!$r['recovered']&&'not_applicable'===$r['state'],'fresh install must not recover authority');
ok(!MAD4B_SCP_Site_Profile::oauth_enabled()&&!MAD4B_SCP_Site_Profile::write_enabled(),'fresh install remains zero-authority');

// Legacy identity alone remains fail-closed.
resetx();
$GLOBALS['opts'][MAD4B_SCP_Site_Profile::LEGACY_OPTION]=legacy();
$s=MAD4B_SCP_Site_Profile::status();
ok(!empty($s['reenrollment_required'])&&empty($s['oauth_enabled']),'legacy identity alone remains fail-closed');

// Exact prior OAuth evidence restores read/OAuth continuity only.
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=array('environment'=>'staging','site_uuid'=>'123e4567-e89b-42d3-a456-426614174000','profile_revision'=>4,'canonical_origin'=>'https://staging.example.test','wp_user_id'=>7,'primary_owner_user_id'=>7,'oauth_user_ids'=>array(7),'issuer'=>'https://staging.example.test/oauth/mcp');
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!empty($r['recovered']),'exact prior OAuth evidence recovers read continuity');
MAD4B_SCP_Site_Profile::reset_cache();
$s=MAD4B_SCP_Site_Profile::status();
ok(!empty($s['oauth_enabled']),'OAuth continuity restored');
ok(empty($s['write_enabled']),'write authority remains disabled');
$v2=get_option(MAD4B_SCP_Site_Profile::OPTION,array());
ok(!empty($v2['features']['managed_runtime'])&&!empty($v2['features']['provider_isolation']),'managed read runtime restored');
ok(empty($v2['features']['skills'])&&empty($v2['features']['acceptance'])&&empty($v2['features']['production_write_confirmed']),'unrelated authority remains disabled');
ok(!empty($v2['migration_write_reenrollment_required']),'write requires explicit re-enrollment');
ok(empty($r['write_restored'])&&empty($r['production_authority_restored']),'recovery never restores write or Production authority');

// Origin drift invalidates old OAuth evidence.
resetx();
$GLOBALS['opts'][MAD4B_SCP_Site_Profile::LEGACY_OPTION]=legacy();
MAD4B_SCP_Site_Profile::status();
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=array('environment'=>'staging','site_uuid'=>'123e4567-e89b-42d3-a456-426614174000','profile_revision'=>4,'canonical_origin'=>'https://evil.example.test','wp_user_id'=>7,'issuer'=>'https://evil.example.test/oauth/mcp');
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!$r['recovered']&&'prior_oauth_identity_mismatch'===$r['blocker'],'origin drift must fail closed');

// Production never receives automatic continuity authority.
resetx();
$GLOBALS['env']='production';$GLOBALS['home']='https://prod.example.test';
$GLOBALS['opts'][MAD4B_SCP_Site_Profile::LEGACY_OPTION]=legacy('production','https://prod.example.test');
MAD4B_SCP_Site_Profile::status();
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=array('environment'=>'production','site_uuid'=>'123e4567-e89b-42d3-a456-426614174000','profile_revision'=>4,'canonical_origin'=>'https://prod.example.test','wp_user_id'=>7,'issuer'=>'https://prod.example.test/oauth/mcp');
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!$r['recovered']&&'nonproduction_only'===$r['blocker'],'Production auto recovery is prohibited');

// Known OAuth paths are derived from the live local authority instead of assuming domain-root WordPress.
ok(MAD4B_SCP_Upgrade_Continuity::is_known_oauth_protocol_path('/oauth/mcp/authorize'),'authorize path recognized');
ok(MAD4B_SCP_Upgrade_Continuity::is_known_oauth_protocol_path('/.well-known/oauth-authorization-server/oauth/mcp/'),'metadata path recognized');
ok(!MAD4B_SCP_Upgrade_Continuity::is_known_oauth_protocol_path('/unrelated'),'unrelated path ignored');
$GLOBALS['home']='https://staging.example.test/wordpress';
ok(MAD4B_SCP_Upgrade_Continuity::is_known_oauth_protocol_path('/wordpress/oauth/mcp/authorize'),'subdirectory authorize path recognized');
ok(MAD4B_SCP_Upgrade_Continuity::is_known_oauth_protocol_path('/wordpress/oauth/mcp/token'),'subdirectory token path recognized');
ok(MAD4B_SCP_Upgrade_Continuity::is_known_oauth_protocol_path('/.well-known/oauth-authorization-server/wordpress/oauth/mcp'),'subdirectory metadata path recognized');
ok(!MAD4B_SCP_Upgrade_Continuity::is_known_oauth_protocol_path('/oauth/mcp/authorize'),'domain-root OAuth path rejected for subdirectory site');
$GLOBALS['home']='https://staging.example.test';

fwrite(STDOUT,"mad4b.upgrade-continuity.runtime.v1: PASS\n");
