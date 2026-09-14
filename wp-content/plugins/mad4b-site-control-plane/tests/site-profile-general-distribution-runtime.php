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
function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
function reset_state(){ $GLOBALS['mad4b_test_options']=array();$GLOBALS['mad4b_test_audit_events']=array();$GLOBALS['mad4b_test_audit_ready']=true;$GLOBALS['mad4b_test_audit_fail']=false;$GLOBALS['mad4b_test_is_admin']=true;MAD4B_SCP_Site_Profile::reset_cache(); }
function legacy_record($env,$origin,$revision=4){return array('contract'=>MAD4B_SCP_Site_Profile::LEGACY_CONTRACT,'version'=>MAD4B_SCP_Site_Profile::LEGACY_VERSION,'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000','revision'=>$revision,'environment'=>$env,'canonical_origin'=>$origin,'display_name'=>'Legacy Client','chatgpt_app_id'=>'plugin_asdk_app_legacy123','oauth_user_ids'=>array(7,8),'related_origins'=>array($env=>$origin),'features'=>array('oauth'=>true,'skills'=>true,'write'=>true,'production_write_confirmed'=>true,'provider_isolation'=>true,'managed_runtime'=>true,'acceptance'=>true),'legacy_agent_slug'=>'legacy-agent','legacy_zero_touch'=>true,'created_at'=>'2026-01-01T00:00:00Z','updated_at'=>'2026-01-01T00:00:00Z');}

// Fresh arbitrary install is unconfigured and zero-authority.
reset_state();
$GLOBALS['mad4b_test_environment']='staging';$GLOBALS['mad4b_test_home']='https://client.test/site/';
$s=MAD4B_SCP_Site_Profile::status();
ok(empty($s['configured']),'fresh arbitrary site is not auto-enrolled');
ok(!MAD4B_SCP_Site_Profile::write_enabled(),'fresh arbitrary site has zero write authority');
ok(!MAD4B_SCP_Site_Profile::oauth_enabled(),'fresh arbitrary site has zero OAuth authority');

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
