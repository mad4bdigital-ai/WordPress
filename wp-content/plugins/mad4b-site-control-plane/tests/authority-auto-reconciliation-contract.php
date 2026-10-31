<?php
define('ABSPATH',__DIR__);
class WP_Error {
 private $c;
 public function __construct($c,$m='',$d=array()){$this->c=$c;}
 public function get_error_code(){return $this->c;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v,$opts=0){return json_encode($v,$opts);}
class MAD4B_SCP_Site_Profile {
 public static function configured(){return true;}
 public static function current_environment(){return $GLOBALS['env'];}
 public static function origin_enrolled(){return true;}
 public static function site_urls_match_enrollment(){return true;}
 public static function site_uuid(){return '12345678-1234-1234-1234-123456789012';}
}
class MAD4B_SCP_Live_Acceptance_Observer {
 public static function build_provenance_status(){
  return array('manifest_valid'=>true,'runtime_manifest_match'=>!$GLOBALS['stale'],
    'stale'=>$GLOBALS['stale'],'provenance_mismatch'=>array(),
    'source_commit_sha'=>str_repeat('a',40),
    'build_fingerprint'=>str_repeat('b',64));
 }
}
class MAD4B_SCP_Staging_Write_Authority {
 public static function reconciliation_plan(){return $GLOBALS['plan'];}
}
$GLOBALS['env']='staging';$GLOBALS['stale']=false;
$GLOBALS['plan']=array(
 'agent_public_id'=>'12345678-1234-1234-1234-123456789012',
 'persisted_grant_records_fingerprint'=>str_repeat('c',64),
 'unreviewed_stale_allow_grants'=>array(
   array('id'=>340,'ability'=>'mad4b/ai-localization-review','provider'=>'core','environment'=>'local','reason'=>'stale_allow_unreviewed'),
   array('id'=>339,'ability'=>'mad4b/context-ai-review','provider'=>'core','environment'=>'all','reason'=>'stale_allow_unreviewed'),
   array('id'=>350,'ability'=>'seo/update-meta','provider'=>'rank_math','environment'=>'staging','reason'=>'stale_allow_unreviewed')),
 'exact_grants_missing'=>array(array('ability'=>'foo/bar','provider'=>'core','reason'=>'missing_exact_grant')),
 'subject_preflight_blockers'=>array('oauth_subject_bound_to_other_agent'),
 'candidate_binding'=>array('match'=>false),
 'effective_ready'=>false,
);
require dirname(__DIR__).'/includes/class-mad4b-scp-authority-auto-reconciliation.php';
function check($bool,$msg){if(!$bool){fwrite(STDERR,"FAIL: $msg\n");exit(1);}}
$p=MAD4B_SCP_Authority_Auto_Reconciliation::plan();
check(!is_wp_error($p),'valid plan');
check(count($p['safe_stale_retirement'])===2,'retire only non-current/broad obsolete grants');
check(count($p['needs_review'])===1,'current-environment grant never auto retired');
check(count($p['missing_exact_grants'])===1,'preserve missing exact grants');
check($p['subject_conflicts'][0]==='oauth_subject_bound_to_other_agent','preserve cross-agent conflict');
check(!$p['mutation_performed'],'planning performs no mutation');
$a=MAD4B_SCP_Authority_Auto_Reconciliation::reconcile_safe();
check(is_wp_error($a),'unverified CLI cannot mutate');
$GLOBALS['stale']=true;
check(is_wp_error(MAD4B_SCP_Authority_Auto_Reconciliation::plan()),'stale provenance denied');
$GLOBALS['stale']=false;$GLOBALS['env']='production';
check(is_wp_error(MAD4B_SCP_Authority_Auto_Reconciliation::plan()),'production denied');
echo "PASS authority auto-reconciliation boundaries (9 checks)\n";
