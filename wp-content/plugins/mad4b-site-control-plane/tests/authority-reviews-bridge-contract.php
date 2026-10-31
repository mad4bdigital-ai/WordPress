<?php
define('ABSPATH', __DIR__);
class WP_Error {private $code;public function __construct($code,$message=''){$this->code=$code;}}
function is_wp_error($r){return $r instanceof WP_Error;}
$GLOBALS['options']=array();
$GLOBALS['events']=array();
function add_action($n,$f,$p=10,$args=1){$GLOBALS['events'][$n][]=$f;}
function get_option($k,$d=null){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,$autoload=false){$GLOBALS['options'][$k]=$v;return true;}
function do_action($name,$payload){$GLOBALS['dispatched'][]=$name;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
class MAD4B_SCP_Site_Profile {
  public static function current_environment(){return 'staging';}
  public static function site_uuid(){return '12345678-1234-1234-1234-123456789abc';}
}
class MAD4B_SCP_Post_Update_Continuation {
  public static function approved_grant_baseline(){return array();}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-authority-reviews-bridge.php';
function check($x,$m){if(!$x){fwrite(STDERR,"FAIL: ".$m."\n");exit(1);}}
$p=array('contract'=>'mad4b.authority-auto-reconciliation.v1',
 'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
 'source_commit_sha'=>str_repeat('a',40),
 'plan_sha256'=>str_repeat('b',64),
 'agent_public_id'=>'12345678-1234-1234-1234-123456789abc',
 'missing_exact_grants'=>array(
  array('ability'=>'mad4b/post-r1-create-apply','provider'=>'core','reason'=>'missing_exact_grant')),
 'subject_conflicts'=>array('oauth_subject_bound_to_other_agent'),
 'needs_review'=>array(),
 'candidate_binding_match'=>false);
MAD4B_SCP_Authority_Reviews_Bridge::capture($p);
$s=MAD4B_SCP_Authority_Reviews_Bridge::status();
check($s['unresolved']===3,'three issues in reviews');
check($s['approval_tickets_issued']===0&&!$s['mutation_performed'],'no grants issued');
check(in_array('mad4b_authority_review_intake_updated',$GLOBALS['dispatched']??array(),true),'native intake event emitted');
MAD4B_SCP_Authority_Reviews_Bridge::capture($p);
check(MAD4B_SCP_Authority_Reviews_Bridge::status()['total']===3,'repeat idempotent');
$p['missing_exact_grants']=array();
$p['subject_conflicts']=array();
$p['candidate_binding_match']=true;
$p['plan_sha256']=str_repeat('c',64);
MAD4B_SCP_Authority_Reviews_Bridge::capture($p);
check(MAD4B_SCP_Authority_Reviews_Bridge::status()['unresolved']===0,'resolved automatically by readback');
check(MAD4B_SCP_Authority_Reviews_Bridge::status()['total']===3,'history kept');
echo "PASS: authority Reviews deterministic intake and resolved-readback lifecycle\n";
