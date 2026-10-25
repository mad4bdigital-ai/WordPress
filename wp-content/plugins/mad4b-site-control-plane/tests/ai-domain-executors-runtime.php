<?php
define('ABSPATH',__DIR__.'/');
class WP_Error {
    private $code;public function __construct($code,$message=''){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_get_environment_type(){return $GLOBALS['env']??'local';}
function add_action($name,$callback,$priority=10){}
function do_action($name){}
$GLOBALS['env']='local';
$GLOBALS['receipt_valid']=true;
$GLOBALS['model_calls']=0;
$GLOBALS['submit_calls']=0;
$GLOBALS['candidate']=array('item_id'=>'draft:42','content_hash'=>str_repeat('a',64),'owner_revision'=>7);
class MAD4B_SCP_AI_Review_Worker {
    public static function preflight(){return array('ready'=>!empty($GLOBALS['inference_ready']));}
    public static function parse_verdict($text,$evidence){
        $row=json_decode($text,true);
        if(!is_array($row) || !in_array($row['decision']??'',array('approve','needs_changes'),true) ||
          ($row['evidence_lines']??[])!==array(1,2))return new WP_Error('verdict_invalid');
        return $row;
    }
}
class MAD4B_SCP_AI_Model_Router {
    public static function generate_text($prompt) {
        ++$GLOBALS['model_calls'];
        if(strpos($prompt,'UNTRUSTED')===false)return new WP_Error('prompt_wrong');
        return json_encode(array('decision'=>'approve','confidence'=>0.98,
            'review_note'=>'This draft was checked against its own exact source evidence.',
            'evidence_lines'=>array(1,2)));
    }
}
class OwnerDraft {
    public static function verified_review($scope){return true;}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-ai-review-lifecycle.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-ai-domain-executors.php';
class MockContentOwner implements MAD4B_SCP_AI_Domain_Executor {
    public function descriptor() {
        return array('contract'=>MAD4B_SCP_AI_Domain_Executors::ADAPTER_CONTRACT,
            'domain'=>'content_job','owner'=>'OwnerDraft','review_method'=>'verified_review',
            'source_sha256'=>hash_file('sha256',__FILE__));
    }
    public function next_review(){return $GLOBALS['candidate'];}
    public function exact_evidence($item){
        return array('normalized_text'=>"Evidence heading\nEvidence body includes sufficient actual text of the source.",
            'content_hash'=>$item['content_hash'],'owner_revision'=>$item['owner_revision'],'title'=>'Draft 42');
    }
    public function submit_original_review($item,$decision,$note,$source_binding){
        ++$GLOBALS['submit_calls'];
        return array('decision'=>$decision,'bound'=>$source_binding);
    }
    public function verify_original_receipt($item,$receipt,$source_binding){
        return !empty($GLOBALS['receipt_valid']) &&
            ($receipt['bound']??null)===$source_binding && $receipt['decision']==='approve';
    }
}
define('MAD4B_AI_DOMAIN_CERTIFIED_ADAPTERS',array('content_job'=>hash_file('sha256',__FILE__)));
$checks=0;
function check($name,$yes){global $checks;if(!$yes){fwrite(STDERR,"FAIL $name\n");exit(1);}echo "$name: PASS\n";++$checks;}
$inventory=MAD4B_SCP_AI_Domain_Executors::inventory();
check('all_eleven_domains_present',$inventory['domain_count']===11);
check('five_content_only_profiles',count(array_filter($inventory['domains'],fn($x)=>$x['mode']==='content_only_owner_executor'))===5);
check('five_protected_governance_profiles',count(array_filter($inventory['domains'],fn($x)=>$x['mode']==='protected_original_governance'))===5);
check('brand_stays_original_worker',$inventory['domains']['brand_context']['mode']==='original_brand_context_worker');
check('missing_content_executor_honestly_reported',$inventory['domains']['content_job']['state']==='original_owner_executor_missing');
check('inventory_never_approves',$inventory['auto_approvals_performed']===false && $inventory['mutation_performed']===false);
check('protected_domain_cannot_register',is_wp_error(MAD4B_SCP_AI_Domain_Executors::register('approval_ticket',new MockContentOwner())));
check('brand_cannot_replace_original',is_wp_error(MAD4B_SCP_AI_Domain_Executors::register('brand_context',new MockContentOwner())));
check('non_adapter_cannot_register',is_wp_error(MAD4B_SCP_AI_Domain_Executors::register('content_job',new stdClass())));
$GLOBALS['env']='production';
check('production_registration_denied',is_wp_error(MAD4B_SCP_AI_Domain_Executors::register('content_job',new MockContentOwner())));
$GLOBALS['env']='local';
check('exact_pinned_content_adapter_admitted',MAD4B_SCP_AI_Domain_Executors::register('content_job',new MockContentOwner())===true);
check('duplicate_adapter_denied',is_wp_error(MAD4B_SCP_AI_Domain_Executors::register('content_job',new MockContentOwner())));
$prop=new ReflectionProperty('MAD4B_SCP_AI_Domain_Executors','certifications');
$prop->setAccessible(true); // PHP 7.4 requires explicit reflective access to a private static property.
$proof=$prop->getValue();$valid_proof=$proof['content_job'];
$proof['content_job']['sha256']=str_repeat('0',64);
$prop->setValue(null,$proof);
$drift=MAD4B_SCP_AI_Domain_Executors::run_one('content_job');
check('runtime_revocation_and_source_pin_drift_denied',
 is_wp_error($drift) && $drift->get_error_code()==='mad4b_ai_domain_certification_stale');
$proof['content_job']=$valid_proof;
$prop->setValue(null,$proof);
$blocked=MAD4B_SCP_AI_Domain_Executors::run_one('content_job');
check('missing_real_model_preflight_denied',is_wp_error($blocked)&&$blocked->get_error_code()==='mad4b_ai_domain_preflight_blocked');
check('no_model_prompt_when_disabled',$GLOBALS['model_calls']===0);
$GLOBALS['inference_ready']=true;
$GLOBALS['candidate']['content_hash']='tampered';
check('unbound_hash_denied',is_wp_error(MAD4B_SCP_AI_Domain_Executors::run_one('content_job')));
$GLOBALS['candidate']['content_hash']=str_repeat('a',64);
$GLOBALS['candidate']['owner_revision']=0;
check('revision_required',is_wp_error(MAD4B_SCP_AI_Domain_Executors::run_one('content_job')));
$GLOBALS['candidate']['owner_revision']=7;
$GLOBALS['receipt_valid']=false;
$unverified=MAD4B_SCP_AI_Domain_Executors::run_one('content_job');
check('owner_receipt_readback_required',is_wp_error($unverified)&&$unverified->get_error_code()==='mad4b_ai_domain_owner_receipt_unverified');
$GLOBALS['receipt_valid']=true;
$done=MAD4B_SCP_AI_Domain_Executors::run_one('content_job');
check('certified_original_owner_receipt_completes',is_array($done)&&$done['state']==='original_owner_review_verified'&&$done['original_owner_receipt_verified']===true);
check('no_publication_grant',$done['publication_authorized']===false&&$done['deployment_authorized']===false);
check('no_protected_owner_called',is_wp_error(MAD4B_SCP_AI_Domain_Executors::run_one('plugin_lifecycle')));
$cycle=MAD4B_SCP_AI_Domain_Executors::cycle(0);
check('bounded_cycle_preserves_original_verified_result',($cycle['result']['original_owner_receipt_verified']??false)===true);
echo "SUMMARY pass=$checks fail=0\n";
echo "PASS: AI_DOMAIN_ORIGINAL_OWNER_EXECUTION\n";
