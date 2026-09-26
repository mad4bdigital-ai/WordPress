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
final class MAD4B_SCP_Schema {
    public static $fixture = array();
    public static function status($deep=false){return self::$fixture;}
}
final class MAD4B_SCP_Plugin {
    public static $code = '';
    public static $data = array();
    public static function governance_bootstrap_error_code(){return self::$code;}
    public static function governance_bootstrap_error_data(){return self::$data;}
}
function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function legacy($env='staging',$origin='https://staging.example.test'){return array('contract'=>MAD4B_SCP_Site_Profile::LEGACY_CONTRACT,'version'=>MAD4B_SCP_Site_Profile::LEGACY_VERSION,'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000','revision'=>4,'environment'=>$env,'canonical_origin'=>$origin,'display_name'=>'Legacy','chatgpt_app_id'=>'plugin_asdk_app_legacy','oauth_user_ids'=>array(7,8),'related_origins'=>array(),'features'=>array('oauth'=>true,'skills'=>true,'write'=>true,'production_write_confirmed'=>true,'provider_isolation'=>true,'managed_runtime'=>true,'acceptance'=>true),'legacy_agent_slug'=>'legacy','legacy_zero_touch'=>true);}
function snapshot($env='staging',$origin='https://staging.example.test'){return array('version'=>3,'environment'=>$env,'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000','profile_revision'=>4,'profile_digest'=>str_repeat('a',64),'canonical_origin'=>$origin,'wp_user_id'=>7,'primary_owner_user_id'=>7,'oauth_user_ids'=>array(7),'issuer'=>rtrim($origin,'/').'/oauth/mcp','updated_at'=>'2026-09-14T00:00:00Z');}
function legacy_snapshot($origin='https://staging.example.test'){return array('version'=>1,'wp_user_id'=>7,'issuer'=>rtrim($origin,'/').'/oauth/mcp','updated_at'=>'2026-09-10T00:00:00Z');}
function resetx(){ $GLOBALS['opts']=array();$GLOBALS['env']='staging';$GLOBALS['home']='https://staging.example.test';MAD4B_SCP_Site_Profile::reset_cache(); }

// Fresh installs remain zero-authority.
resetx();
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!$r['recovered']&&'not_applicable'===$r['state'],'fresh install must not recover authority');
ok(!MAD4B_SCP_Site_Profile::oauth_enabled()&&!MAD4B_SCP_Site_Profile::write_enabled(),'fresh install remains zero-authority');

// A surviving exact prior OAuth snapshot can rebuild only the minimal v2 read identity.
resetx();
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=snapshot();
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!empty($r['recovered'])&&'prior_oauth_snapshot'===$r['recovery_source'],'snapshot-only evidence recovers read continuity');
MAD4B_SCP_Site_Profile::reset_cache();
$s=MAD4B_SCP_Site_Profile::status();
ok(!empty($s['configured'])&&!empty($s['oauth_enabled']),'snapshot-only recovery configures OAuth Site Profile');
ok(empty($s['write_enabled']),'snapshot-only recovery must not restore write authority');
$v2=get_option(MAD4B_SCP_Site_Profile::OPTION,array());
ok('123e4567-e89b-42d3-a456-426614174000'===$v2['site_uuid'],'snapshot-only recovery preserves exact site UUID');
ok(5===$v2['revision'],'snapshot-only recovery advances prior profile revision');
ok(!empty($v2['features']['oauth'])&&!empty($v2['features']['managed_runtime'])&&!empty($v2['features']['provider_isolation']),'snapshot-only recovery restores bounded read runtime');
ok(empty($v2['features']['write'])&&empty($v2['features']['skills'])&&empty($v2['features']['acceptance'])&&empty($v2['features']['production_write_confirmed']),'snapshot-only recovery restores no unrelated authority');
ok('prior_oauth_snapshot'===$v2['migration_read_continuity_source']&&!empty($v2['migration_write_reenrollment_required']),'snapshot-only recovery records source and requires write re-enrollment');
ok(empty($r['write_restored'])&&empty($r['production_authority_restored'])&&empty($r['breakglass_restored']),'snapshot-only recovery never restores write, Production or Breakglass authority');
ok('consumed'===get_option(MAD4B_SCP_Upgrade_Continuity::RECOVERY_MARKER_OPTION,array())['state'],'snapshot-only recovery records one-time consumption marker');
delete_option(MAD4B_SCP_Site_Profile::OPTION);
MAD4B_SCP_Site_Profile::reset_cache();
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!$r['recovered']&&'snapshot_recovery_already_consumed'===$r['blocker'],'consumed snapshot must not resurrect a deliberately absent profile');
ok(false===get_option(MAD4B_SCP_Site_Profile::OPTION,false),'consumed recovery marker keeps a removed profile absent');

// The real pre-Site-Profile zero-touch snapshot shape can recover read continuity once.
resetx();
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=legacy_snapshot();
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!empty($r['recovered'])&&'legacy_zero_touch_oauth_snapshot_v1'===$r['recovery_source'],'legacy zero-touch snapshot recovers bounded read continuity');
MAD4B_SCP_Site_Profile::reset_cache();
$legacy_v2=get_option(MAD4B_SCP_Site_Profile::OPTION,array());
ok(!empty($legacy_v2['site_uuid'])&&preg_match('/^[a-f0-9-]{36}$/',$legacy_v2['site_uuid']),'legacy zero-touch recovery derives a stable site UUID');
ok(!empty($legacy_v2['features']['oauth'])&&empty($legacy_v2['features']['write']),'legacy zero-touch recovery restores read OAuth only');
$legacy_uuid=$legacy_v2['site_uuid'];
resetx();
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=legacy_snapshot();
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
$legacy_v2_repeat=get_option(MAD4B_SCP_Site_Profile::OPTION,array());
ok($legacy_uuid===$legacy_v2_repeat['site_uuid'],'legacy zero-touch site UUID derivation is deterministic');

// Legacy zero-touch evidence remains exact-issuer bound.
resetx();
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=legacy_snapshot('https://evil.example.test');
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!$r['recovered']&&'prior_oauth_issuer_mismatch'===$r['blocker'],'legacy zero-touch issuer drift fails closed');
ok(false===get_option(MAD4B_SCP_Site_Profile::OPTION,false),'legacy zero-touch issuer drift cannot synthesize a profile');

// A stored-but-invalid v2 record is never replaced by snapshot recovery.
resetx();
$GLOBALS['opts'][MAD4B_SCP_Site_Profile::OPTION]=array('contract'=>MAD4B_SCP_Site_Profile::CONTRACT,'version'=>2,'site_uuid'=>'broken');
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=snapshot();
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!$r['recovered']&&'current_site_profile_invalid'===$r['blocker'],'stored invalid v2 profile fails closed instead of being overwritten');
ok('broken'===$GLOBALS['opts'][MAD4B_SCP_Site_Profile::OPTION]['site_uuid'],'stored invalid v2 bytes remain untouched');

// Profile-bound snapshots reject malformed profile digests.
resetx();
$bad=snapshot();$bad['profile_digest']='not-a-digest';
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=$bad;
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!$r['recovered']&&'prior_oauth_profile_digest_invalid'===$r['blocker'],'malformed profile-bound digest fails closed');

// Snapshot-only origin drift fails closed and must not synthesize a Site Profile.
resetx();
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=snapshot('staging','https://evil.example.test');
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!$r['recovered']&&'prior_oauth_identity_mismatch'===$r['blocker'],'snapshot-only origin drift must fail closed');
ok(false===get_option(MAD4B_SCP_Site_Profile::OPTION,false),'snapshot-only origin drift must not persist a Site Profile');

// Snapshot-only continuity is never auto-restored on Production.
resetx();
$GLOBALS['env']='production';$GLOBALS['home']='https://prod.example.test';
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=snapshot('production','https://prod.example.test');
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!$r['recovered']&&'nonproduction_only'===$r['blocker'],'snapshot-only Production auto recovery is prohibited');
ok(false===get_option(MAD4B_SCP_Site_Profile::OPTION,false),'snapshot-only Production must not persist a Site Profile');

// Legacy identity alone remains fail-closed.
resetx();
$GLOBALS['opts'][MAD4B_SCP_Site_Profile::LEGACY_OPTION]=legacy();
$s=MAD4B_SCP_Site_Profile::status();
ok(!empty($s['reenrollment_required'])&&empty($s['oauth_enabled']),'legacy identity alone remains fail-closed');

// Exact prior OAuth evidence restores read/OAuth continuity only.
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=snapshot();
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
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=snapshot('staging','https://evil.example.test');
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!$r['recovered']&&'prior_oauth_identity_mismatch'===$r['blocker'],'origin drift must fail closed');

// Production never receives automatic continuity authority.
resetx();
$GLOBALS['env']='production';$GLOBALS['home']='https://prod.example.test';
$GLOBALS['opts'][MAD4B_SCP_Site_Profile::LEGACY_OPTION]=legacy('production','https://prod.example.test');
MAD4B_SCP_Site_Profile::status();
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=snapshot('production','https://prod.example.test');
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

// Schema migration failures surface durable blockers only to the administrator notice.
// The governance read ability must keep database-engine errors out of its public payload.
MAD4B_SCP_Schema::$fixture=array(
    'ready'=>false,
    'physical_integrity'=>array(
        'ready'=>false,
        'missing_tables'=>array(),
        'missing_approval_columns'=>array(),
        'missing_durable_columns'=>array('idempotency.claim_epoch'),
        'missing_durable_indexes'=>array('outbox.provider_idempotency'),
    ),
);
MAD4B_SCP_Plugin::$code='mad4b_governance_schema_unavailable';
MAD4B_SCP_Plugin::$data=array(
    'from_version'=>6,
    'target_version'=>9,
    'dbdelta_diagnostics'=>array(
        array(
            'table'=>'wp_mad4b_execution_outbox',
            'last_error'=>'Duplicate entry for key provider_idempotency',
        ),
    ),
);
$governance=MAD4B_SCP_Upgrade_Continuity::governance_status();
ok(!array_key_exists('bootstrap_error_data',$governance),'governance read status must not expose raw schema bootstrap error data');
ok(false===strpos(wp_json_encode($governance),'Duplicate entry for key'),'governance read status must not expose database-engine error details');
ob_start();
MAD4B_SCP_Upgrade_Continuity::replace_ambiguous_governance_notice();
$notice=ob_get_clean();
ok(false!==strpos($notice,'mad4b_governance_schema_unavailable'),'admin notice preserves exact schema blocker code');
ok(false!==strpos($notice,'schema=6→9'),'admin notice identifies migration origin and target');
ok(false!==strpos($notice,'missing_durable_columns=idempotency.claim_epoch'),'admin notice exposes missing durable columns');
ok(false!==strpos($notice,'missing_durable_indexes=outbox.provider_idempotency'),'admin notice exposes missing durable indexes');
ok(false!==strpos($notice,'dbdelta=wp_mad4b_execution_outbox:Duplicate entry for key provider_idempotency'),'admin notice exposes bounded dbDelta error');

fwrite(STDOUT,"mad4b.upgrade-continuity.runtime.v1: PASS\n");
