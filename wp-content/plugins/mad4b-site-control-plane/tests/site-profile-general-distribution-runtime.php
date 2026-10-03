<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'MAD4B_SCP_DIR' ) ) define( 'MAD4B_SCP_DIR', rtrim( dirname( __DIR__ ), '/\\' ) . '/' );
$GLOBALS['mad4b_test_options'] = array();
$GLOBALS['mad4b_test_environment'] = 'staging';
$GLOBALS['mad4b_test_home'] = 'https://client.test/site/';
$GLOBALS['mad4b_test_user_id'] = 7;
$GLOBALS['mad4b_test_is_admin'] = true;
$GLOBALS['mad4b_test_audit_ready'] = true;
$GLOBALS['mad4b_test_audit_fail'] = false;
$GLOBALS['mad4b_test_audit_events'] = array();
$GLOBALS['mad4b_test_explicit_environment_filter'] = null;

class WP_Error { private $code; private $message; private $data; public function __construct($c,$m='',$d=null){$this->code=$c;$this->message=$m;$this->data=$d;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} public function get_error_data(){return $this->data;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function absint($v){return abs((int)$v);}
function wp_parse_url($u,$c=-1){return parse_url($u,$c);}
function wp_json_encode($v,$f=0){return json_encode($v,$f);}
function trailingslashit($v){return rtrim((string)$v,'/\\').'/';}
function home_url($p=''){return rtrim($GLOBALS['mad4b_test_home'],'/').(''===$p?'':'/'.ltrim($p,'/'));}
function wp_get_environment_type(){return $GLOBALS['mad4b_test_environment'];}
function apply_filters($tag,$value,...$args){
	if('mad4b_scp_wordpress_environment_explicit'===$tag && null!==$GLOBALS['mad4b_test_explicit_environment_filter']) return (bool)$GLOBALS['mad4b_test_explicit_environment_filter'];
	return $value;
}
function current_user_can($c){return 'manage_options'===$c ? !empty($GLOBALS['mad4b_test_is_admin']) : false;}
function get_current_user_id(){return (int)$GLOBALS['mad4b_test_user_id'];}
function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['mad4b_test_options'])?$GLOBALS['mad4b_test_options'][$k]:$d;}
function update_option($k,$v,$a=null){$GLOBALS['mad4b_test_options'][$k]=$v;return true;}
function delete_option($k){unset($GLOBALS['mad4b_test_options'][$k]);return true;}
function wp_generate_uuid4(){static $n=0;++$n;return sprintf('11111111-1111-4111-8111-%012d',$n);}
function get_userdata($id){return absint($id)>0?(object)array('ID'=>absint($id)):false;}
function user_can($u,$c){return is_object($u)&&'manage_options'===$c;}
function get_bloginfo($k){return 'Client Test';}
function wp_register_ability(){}
final class MAD4B_SCP_Audit { public static function storage_status(){return array('ready'=>!empty($GLOBALS['mad4b_test_audit_ready']));} public static function record($a,$s,$st){if(!empty($GLOBALS['mad4b_test_audit_fail']))return new WP_Error('audit_failed','forced');$GLOBALS['mad4b_test_audit_events'][]=array($a,$s,$st);return true;} }
require_once dirname(__DIR__).'/includes/class-mad4b-scp-site-profile.php';
require_once dirname(__DIR__).'/includes/class-mad4b-scp-environment.php';
function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
function reset_state(){ $GLOBALS['mad4b_test_options']=array();$GLOBALS['mad4b_test_audit_events']=array();$GLOBALS['mad4b_test_audit_ready']=true;$GLOBALS['mad4b_test_audit_fail']=false;$GLOBALS['mad4b_test_is_admin']=true;$GLOBALS['mad4b_test_explicit_environment_filter']=null;putenv('WP_ENVIRONMENT_TYPE');MAD4B_SCP_Site_Profile::reset_cache(); }
function legacy_record($env,$origin,$revision=4){return array('contract'=>MAD4B_SCP_Site_Profile::LEGACY_CONTRACT,'version'=>MAD4B_SCP_Site_Profile::LEGACY_VERSION,'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000','revision'=>$revision,'environment'=>$env,'canonical_origin'=>$origin,'display_name'=>'Legacy Client','chatgpt_app_id'=>'plugin_asdk_app_legacy123','oauth_user_ids'=>array(7,8),'related_origins'=>array($env=>$origin),'features'=>array('oauth'=>true,'skills'=>true,'write'=>true,'production_write_confirmed'=>true,'provider_isolation'=>true,'managed_runtime'=>true,'acceptance'=>true),'legacy_agent_slug'=>'legacy-agent','legacy_zero_touch'=>true,'created_at'=>'2026-01-01T00:00:00Z','updated_at'=>'2026-01-01T00:00:00Z');}


// Explicit WordPress environment is a monotonic safety fact. Filters may harden
// an implicit default, but may never downgrade an actual host declaration.
reset_state();
$GLOBALS['mad4b_test_environment']='production';
putenv('WP_ENVIRONMENT_TYPE=production');
$GLOBALS['mad4b_test_explicit_environment_filter']=false;
ok(true===MAD4B_SCP_Site_Profile::wordpress_environment_explicit(),'filter cannot downgrade explicit WP environment evidence');
putenv('WP_ENVIRONMENT_TYPE');
$GLOBALS['mad4b_test_explicit_environment_filter']=true;
ok(true===MAD4B_SCP_Site_Profile::wordpress_environment_explicit(),'filter may harden an implicit WordPress environment');
$GLOBALS['mad4b_test_explicit_environment_filter']=null;

// Fresh arbitrary install is unconfigured and zero-authority.
reset_state();
$GLOBALS['mad4b_test_environment']='staging';$GLOBALS['mad4b_test_home']='https://client.test/site/';
$s=MAD4B_SCP_Site_Profile::status();
ok(empty($s['configured']),'fresh arbitrary site is not auto-enrolled');
ok(!MAD4B_SCP_Site_Profile::write_enabled(),'fresh arbitrary site has zero write authority');
ok(!MAD4B_SCP_Site_Profile::oauth_enabled(),'fresh arbitrary site has zero OAuth authority');

// WordPress defaults to Production when WP_ENVIRONMENT_TYPE is absent. MAD4B
// may suggest Staging from an exact hostname label, but the hint is advisory
// until an administrator explicitly enrolls the exact current origin.
reset_state();
$GLOBALS['mad4b_test_environment']='production';$GLOBALS['mad4b_test_home']='https://staging.dynamic-client.test/';
ok('production'===MAD4B_SCP_Site_Profile::wordpress_environment(),'raw WordPress environment remains production');
ok('production'===MAD4B_SCP_Site_Profile::current_environment(),'hostname hint never changes authority before enrollment');
ok('production'===MAD4B_SCP_Environment::wordpress(),'central resolver preserves raw WordPress Production evidence before enrollment');
ok('production'===MAD4B_SCP_Environment::effective(),'central resolver grants no hostname authority before enrollment');
ok('staging'===MAD4B_SCP_Site_Profile::suggested_environment(),'staging hostname produces advisory staging enrollment default');
$dynamic=MAD4B_SCP_Site_Profile::save_current_site(array(
    'expected_revision'=>0,
    'display_name'=>'Dynamic Staging',
    'oauth_user_ids'=>array(7),
    'oauth_enabled'=>false,
    'skills_enabled'=>false,
    'write_enabled'=>false,
));
ok(!is_wp_error($dynamic),'explicit site enrollment can use advisory environment without wp-config edit');
ok('staging'===MAD4B_SCP_Site_Profile::current_environment(),'exact Site Profile becomes MAD4B environment authority');
ok('staging'===MAD4B_SCP_Environment::effective(),'central resolver propagates exact Site Profile Staging authority');
$environment_snapshot=MAD4B_SCP_Environment::snapshot();
ok('production'===($environment_snapshot['wordpress_environment']??'')&&'staging'===($environment_snapshot['effective_environment']??''),'central resolver preserves raw/effective split');
ok(empty($environment_snapshot['wordpress_environment_explicit'])&&!empty($environment_snapshot['profile_environment_authoritative']),'implicit WordPress Production yields to exact profile only');
ok('exact_site_profile_default_override'===($environment_snapshot['effective_source']??''),'central resolver exposes exact default-override provenance');
ok('staging'===($dynamic['environment']??'')&&'production'===($dynamic['wordpress_environment']??''),'status separates effective MAD4B environment from raw WordPress environment');
ok('exact_site_profile_default_override'===($dynamic['effective_environment_source']??''),'implicit WordPress Production default is replaced only by exact Site Profile enrollment');
ok(!empty($dynamic['wordpress_profile_mismatch']),'raw WordPress/default mismatch is explicit diagnostics');
ok(empty($dynamic['hostname_hint_used_for_authority']),'hostname hint never becomes authority by itself');
$GLOBALS['mad4b_test_home']='https://dynamic-client.test/';
MAD4B_SCP_Site_Profile::reset_cache();
ok('production'===MAD4B_SCP_Site_Profile::current_environment(),'foreign copied profile cannot override environment on another origin');
$foreign_env=MAD4B_SCP_Site_Profile::status();
ok('foreign_origin'===($foreign_env['binding_state']??'')&&!empty($foreign_env['profile_authority_quarantined']),'environment override is origin-bound and foreign profile is quarantined');

// Exact legacy Staging identity can migrate when WordPress is only reporting its
// implicit Production default. This never restores legacy feature authority.
reset_state();
$origin='https://staging.legacy-default.test';
$GLOBALS['mad4b_test_environment']='production';$GLOBALS['mad4b_test_home']=$origin.'/';
$GLOBALS['mad4b_test_options'][MAD4B_SCP_Site_Profile::LEGACY_OPTION]=legacy_record('staging',$origin,2);
$legacy_default=MAD4B_SCP_Site_Profile::status();
ok(!empty($legacy_default['configured'])&&'legacy_v1_migrated'===($legacy_default['source']??''),'exact legacy staging identity migrates across implicit WordPress Production default');
ok('staging'===($legacy_default['environment']??'')&&'production'===($legacy_default['wordpress_environment']??''),'legacy migration resolves effective Staging without wp-config edit');
ok(empty($legacy_default['write_enabled'])&&empty($legacy_default['oauth_enabled'])&&empty($legacy_default['skills_enabled']),'legacy environment continuity restores no authority');

// Exact v1 record migrates only as identity; every authority-bearing field fails closed.
reset_state();
$origin='https://legacy.client.test/subdir';$GLOBALS['mad4b_test_home']=$origin.'/';$GLOBALS['mad4b_test_environment']='staging';
$legacy=legacy_record('staging',$origin,4);$GLOBALS['mad4b_test_options'][MAD4B_SCP_Site_Profile::LEGACY_OPTION]=$legacy;
$s=MAD4B_SCP_Site_Profile::status();
ok(!empty($s['configured']),'exact legacy identity migrates');
ok('legacy_v1_migrated'===$s['source'],'legacy migration has explicit source');
ok($s['site_uuid']===$legacy['site_uuid'],'migration preserves site UUID');
ok(5===(int)$s['revision'],'migration increments revision');
ok(!empty($s['reenrollment_required']),'migration requires explicit reenrollment');
ok(in_array('site_profile_reenrollment_required',$s['blockers'],true),'migration blocker is explicit');
ok(empty($s['write_enabled'])&&empty($s['oauth_enabled'])&&empty($s['skills_enabled']),'migration carries no feature authority');
ok(''===MAD4B_SCP_Site_Profile::chatgpt_app_id(),'migration does not carry App ID');
ok(array()===MAD4B_SCP_Site_Profile::oauth_user_ids(),'migration does not carry OAuth users');
$v2=get_option(MAD4B_SCP_Site_Profile::OPTION,array());
ok(MAD4B_SCP_Site_Profile::CONTRACT===($v2['contract']??''),'migration persists v2 contract');
ok(isset($GLOBALS['mad4b_test_options'][MAD4B_SCP_Site_Profile::LEGACY_OPTION]),'legacy option remains available for rollback/audit reference');

// Explicit reenrollment preserves identity, clears migration blocker and enables only requested features.
$r=MAD4B_SCP_Site_Profile::save_current_site(array('expected_revision'=>5,'display_name'=>'Migrated Client','chatgpt_app_id'=>'plugin_asdk_app_new123','oauth_user_ids'=>array(7),'oauth_enabled'=>true,'skills_enabled'=>true,'write_enabled'=>true,'provider_isolation_enabled'=>true,'managed_runtime_enabled'=>true,'acceptance_enabled'=>true));
ok(!is_wp_error($r),'explicit reenrollment after migration succeeds');
ok(6===(int)$r['revision'],'reenrollment creates next revision');
ok($legacy['site_uuid']===$r['site_uuid'],'reenrollment preserves migrated UUID');
ok(empty($r['reenrollment_required']),'reenrollment clears migration marker');
ok(!in_array('site_profile_reenrollment_required',$r['blockers'],true),'reenrollment clears blocker');
ok(!empty($r['write_enabled'])&&!empty($r['oauth_enabled'])&&!empty($r['skills_enabled']),'only explicit reenrollment restores requested authority');

// Near-match clone cannot migrate v1 identity or authority.
reset_state();
$GLOBALS['mad4b_test_environment']='staging';$GLOBALS['mad4b_test_home']='https://clone.client.test/subdir/';
$GLOBALS['mad4b_test_options'][MAD4B_SCP_Site_Profile::LEGACY_OPTION]=legacy_record('staging','https://legacy.client.test/subdir',4);
$s=MAD4B_SCP_Site_Profile::status();
ok(empty($s['configured']),'different origin cannot inherit legacy identity');
ok(!isset($GLOBALS['mad4b_test_options'][MAD4B_SCP_Site_Profile::OPTION]),'failed legacy match does not create v2 option');
ok(!MAD4B_SCP_Site_Profile::write_enabled(),'different origin remains zero-authority');

// A valid v2 profile cloned onto another tenant is quarantined. Explicit enrollment
// creates a fresh site identity instead of reusing the source tenant UUID/revision.
reset_state();
$GLOBALS['mad4b_test_environment']='staging';$GLOBALS['mad4b_test_home']='https://site-a.test/';
$site_a=MAD4B_SCP_Site_Profile::save_current_site(array(
    'expected_revision'=>0,
    'display_name'=>'Site A',
    'oauth_user_ids'=>array(7),
    'oauth_enabled'=>true,
    'skills_enabled'=>false,
    'write_enabled'=>false,
));
ok(!is_wp_error($site_a)&&!empty($site_a['configured']),'source tenant profile created');
$site_a_uuid=$site_a['site_uuid'];$site_a_revision=(int)$site_a['revision'];
$GLOBALS['mad4b_test_home']='https://site-b.test/';
MAD4B_SCP_Site_Profile::reset_cache();
$foreign=MAD4B_SCP_Site_Profile::status();
ok(!empty($foreign['configured'])&&'foreign_origin'===($foreign['binding_state']??''),'cloned profile is classified as foreign origin');
ok(!empty($foreign['foreign_profile_detected'])&&!empty($foreign['profile_authority_quarantined']),'foreign profile authority is quarantined');
ok(empty($foreign['oauth_enabled'])&&empty($foreign['write_enabled'])&&empty($foreign['skills_enabled']),'foreign profile carries no authority to the new tenant');
$site_b=MAD4B_SCP_Site_Profile::save_current_site(array(
    'expected_revision'=>$site_a_revision,
    'display_name'=>'Site B',
    'oauth_user_ids'=>array(7),
    'oauth_enabled'=>true,
    'skills_enabled'=>false,
    'write_enabled'=>false,
));
ok(!is_wp_error($site_b),'explicit current-site enrollment replaces foreign binding');
ok('exact'===($site_b['binding_state']??'')&&!empty($site_b['origin_match'])&&!empty($site_b['environment_match']),'new tenant enrollment binds exactly');
ok($site_a_uuid!==$site_b['site_uuid'],'new tenant receives a fresh site UUID');
ok(1===(int)$site_b['revision'],'new tenant identity starts at revision one');
ok('https://site-b.test'===$site_b['canonical_origin'],'new tenant canonical origin is derived from current home URL');
ok(empty($site_b['write_enabled'])&&!empty($site_b['oauth_enabled']),'only explicitly requested authority is enabled on rebound identity');

// Invalid v2 record takes precedence and fails closed; it must not fall back to valid legacy authority.
reset_state();
$GLOBALS['mad4b_test_environment']='staging';$GLOBALS['mad4b_test_home']='https://legacy.client.test/subdir/';
$GLOBALS['mad4b_test_options'][MAD4B_SCP_Site_Profile::OPTION]=array('contract'=>'broken');
$GLOBALS['mad4b_test_options'][MAD4B_SCP_Site_Profile::LEGACY_OPTION]=legacy_record('staging','https://legacy.client.test/subdir',4);
$s=MAD4B_SCP_Site_Profile::status();
ok(empty($s['configured'])&&'stored_invalid'===$s['source'],'invalid v2 record blocks legacy fallback');
ok(!MAD4B_SCP_Site_Profile::write_enabled(),'invalid v2 state remains zero-authority');

// Legacy Production write confirmation never migrates; explicit v2 confirmation is required again.
reset_state();
$GLOBALS['mad4b_test_environment']='production';$GLOBALS['mad4b_test_home']='https://client.example/';
$GLOBALS['mad4b_test_options'][MAD4B_SCP_Site_Profile::LEGACY_OPTION]=legacy_record('production','https://client.example',2);
$s=MAD4B_SCP_Site_Profile::status();
ok(!empty($s['configured'])&&3===(int)$s['revision'],'production identity migrates');
ok(empty($s['write_enabled']),'legacy production write is disabled during migration');
$prod=array('expected_revision'=>3,'display_name'=>'Client Production','oauth_user_ids'=>array(7),'oauth_enabled'=>true,'write_enabled'=>true,'production_write_confirmed'=>true);
$denied=MAD4B_SCP_Site_Profile::save_current_site($prod);
ok(is_wp_error($denied)&&'mad4b_site_profile_production_write_confirmation_required'===$denied->get_error_code(),'production write requires typed v2 confirmation');
$prod['production_write_confirmation']=MAD4B_SCP_Site_Profile::PRODUCTION_WRITE_CONFIRMATION;
$allowed=MAD4B_SCP_Site_Profile::save_current_site($prod);
ok(!is_wp_error($allowed)&&!empty($allowed['write_enabled']),'exact explicit v2 production confirmation succeeds');

fwrite(STDOUT,"MAD4B Site Profile v2 general distribution runtime: PASS\n");
