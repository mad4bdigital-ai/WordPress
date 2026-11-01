<?php
/* Standalone regression: no WP_CLI class/constant exists on web requests. */
define('ABSPATH',__DIR__);
$GLOBALS['env']='staging';
$GLOBALS['called']=array();
function add_action($hook,$handler,$priority=10) {$GLOBALS['called'][]=$hook;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
class WP_Error {
 private $code;public function __construct($c,$message='',$data=array()){$this->code=$c;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($r){return $r instanceof WP_Error;}
class MAD4B_SCP_Execution_Authority {
 public static function require_access($op,$envs) {
  return array('site_uuid'=>'central-site','canonical_principal'=>'owner',
    'environment'=>$GLOBALS['env'],'transport'=>'oauth_mcp');
 }
}
class MAD4B_SCP_Environment_Convergence_Policy {
 public static function current() {
  return array('contract'=>'mad4b.environment-convergence-policy.v1',
    'environment'=>$GLOBALS['env'],'environment_authority_verified'=>true,
    'production_strict'=>$GLOBALS['env']==='production');
 }
}
class MAD4B_SCP_Live_Acceptance_Observer {
 public static function build_provenance_status() {
  return array('manifest_valid'=>true,'runtime_manifest_match'=>false,
    'stale'=>true,'provenance_mismatch'=>array('one-file-drift'),
    'source_commit_sha'=>str_repeat('a',40),'build_fingerprint'=>str_repeat('b',64));
 }
}
class MAD4B_SCP_Full_Staging_Authority {
 public static function plan(){return new WP_Error('source_not_ready');}
 public static function can_apply($in){return new WP_Error('denied');}
 public static function apply($in){throw new Exception('MUST NOT APPLY');}
}
class MAD4B_SCP_Staging_Write_Authority {
 public static function reconciliation_plan() {
  return array('exact_grants_missing_count'=>20,'stale_allow_grants_count'=>9,
   'unreviewed_stale_allow_grants_count'=>0,'effective_ready'=>false,
   'subject_preflight_blockers'=>array('oauth_subject_bound_to_other_agent'),
   'candidate_binding'=>array('match'=>false));
 }
}
require dirname(__DIR__).'/mad4b-unified-full-authority.php';
function verify($pass,$why){if(!$pass){fwrite(STDERR,"FAIL $why\n");exit(1);}}
verify(!class_exists('WP_CLI',false),'web must not fake WP-CLI');
MAD4B_Unified_Full_Authority::boot();
verify(in_array('admin_init',$GLOBALS['called'],true),'web bootstrap safe without WP-CLI');
$p=MAD4B_Unified_Full_Authority::lifecycle_status();
verify(!is_wp_error($p),'read-only lifecycle');
verify($p['rollout_topology']['per_company_staging_required']===false,
    'company staging is optional');
verify($p['rollout_topology']['central_staging_origin']==='https://tours.dev.mad4b.com',
    'one canonical central staging');
verify($p['rollout_topology']['company_production_authorized']===false,
    'readiness never authorizes production');
verify($p['missing_exact_grants']===20&&$p['stale_write_grants']===9,
    'honest live-style blockers');
$GLOBALS['env']='production';
$q=MAD4B_Unified_Full_Authority::lifecycle_status();
verify(!is_wp_error($q),'production is a supported planning mode');
verify($q['rollout_topology']['per_company_staging_required']===false,
    'production still does not require company staging');
verify($q['ready_to_apply']===false,'no native production execution without approval');
echo "PASS central staging and web bootstrap regression (10 checks)\n";
