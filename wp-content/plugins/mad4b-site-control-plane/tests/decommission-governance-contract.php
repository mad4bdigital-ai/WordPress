<?php
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts']=array();
function add_action($h,$c,$p=10){}
function add_filter($h,$c,$p=10,$a=1){}
function apply_filters($h,$v){return $v;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',trim((string)$v))));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_option($n,$d=false){return array_key_exists($n,$GLOBALS['opts'])?$GLOBALS['opts'][$n]:$d;}
function update_option($n,$v,$a=false){$GLOBALS['opts'][$n]=$v;return true;}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{private $c;private $d;public function __construct($c,$m='',$d=null){$this->c=$c;$this->d=$d;}public function get_error_code(){return $this->c;}}

final class MAD4B_SCP_Content_Jobs {
 public static function list_jobs($input){return array('items'=>array(array('state'=>'COMPLETED')),'count'=>1);}
}
final class MAD4B_SCP_Scheduler_Backlog {
 public static function status($input=array()){return array('items'=>array(),'count'=>0);}
}
final class MAD4B_SCP_Remote_Work_Queue {
 public static function list_jobs($operation=''){return array('items'=>array(),'count'=>0);}
}
final class MAD4B_SCP_Artifacts {}
final class MAD4B_SCP_Schema { public static function is_ready(){return true;} }
final class MAD4B_SCP_Decommission_Portability {
 public static function preflight($input=array()){return array('decision'=>'READY_FOR_GOVERNED_QUIESCE','blockers'=>array());}
}

require dirname(__DIR__).'/includes/class-mad4b-scp-decommission-governance.php';
$fail=static function($m){fwrite(STDERR,"FAIL decommission-governance-contract: $m\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};

$inventory=MAD4B_SCP_Decommission_Governance::live_inventory();
$check(is_array($inventory),'live inventory failed');
$check(false===$inventory['caller_inventory_accepted'],'caller inventory became authoritative');
$check(false===$inventory['inventory_complete'],'unknown credential/webhook/DLQ dimensions were silently treated as zero');
$check(in_array('inventory_dimension_unknown:active_credentials',$inventory['blockers'],true),'unknown credential inventory not surfaced');

$plan=MAD4B_SCP_Decommission_Governance::quiesce_plan(array('scope'=>'site'));
$check(is_array($plan) && 'quiesce'===$plan['action'],'quiesce plan failed');
$check(1===preg_match('/^[a-f0-9]{64}$/',$plan['plan_sha256']),'quiesce plan digest invalid');
$check(false===$plan['production_authorized'],'quiesce plan widened Production authority');

$applied=MAD4B_SCP_Decommission_Governance::quiesce_apply(array('plan'=>$plan));
$check(is_array($applied) && 'QUIESCED'===$applied['state']['state'],'quiesce apply failed');

$ordinary=MAD4B_SCP_Decommission_Governance::kill_switch_state(
 array('active'=>false,'revision'=>'base','source'=>'base'),
 'mad4b/draft-apply','core',array(),array()
);
$check(true===$ordinary['active'] && 'decommission_governance'===$ordinary['source'],'ordinary late callback was not commit-time killed');

$safe=MAD4B_SCP_Decommission_Governance::kill_switch_state(
 array('active'=>false,'revision'=>'base','source'=>'base'),
 'mad4b/content-job-cancel','core',array(),array()
);
$check(false===$safe['active'],'safe drain ability was incorrectly killed');

$final=MAD4B_SCP_Decommission_Governance::finalize_plan(array(
 'external_revocation_receipt_sha256'=>str_repeat('a',64),
 'portable_export_bundle_sha256'=>str_repeat('b',64),
));
$check(is_array($final) && false===$final['ready'],'finalization ignored unknown live inventory dimensions');
$check(!empty($final['blockers']),'blocked finalization omitted blockers');

$resumePlan=MAD4B_SCP_Decommission_Governance::resume_plan();
$check(is_array($resumePlan) && 'resume'===$resumePlan['action'],'resume plan failed');
$resumed=MAD4B_SCP_Decommission_Governance::resume_apply(array('plan'=>$resumePlan));
$check(is_array($resumed) && 'ACTIVE'===$resumed['state']['state'],'resume failed');

$ordinaryAfter=MAD4B_SCP_Decommission_Governance::kill_switch_state(
 array('active'=>false,'revision'=>'base','source'=>'base'),
 'mad4b/draft-apply','core',array(),array()
);
$check(false===$ordinaryAfter['active'],'resume did not release decommission kill switch');

$source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-decommission-governance.php');
foreach(array('wp_delete_post(','wp_remote_post(','shell_exec(','proc_open(','unlink(') as $forbidden){
 $check(false===strpos($source,$forbidden),'decommission governance contains destructive/provider primitive: '.$forbidden);
}
echo "mad4b.decommission-governance.v1: PASS\n";
