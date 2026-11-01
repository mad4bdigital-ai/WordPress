<?php
/** Pure state machine contract; no provider, WordPress DB, user or file changes. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
    private $code;
    public function __construct($code,$message=''){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
require dirname(__DIR__).'/includes/class-mad4b-scp-ai-review-lifecycle.php';
$h=str_repeat('a',64);
$old=str_repeat('b',64);
$checks=array(
 'new_brand_requeues'=>array('brand_context',array('content_hash'=>$h,'review_status'=>'unreviewed'),array(),'review_queued','request_original_authenticated_review'),
 'changed_approval_requeues'=>array('brand_context',array('content_hash'=>$h,'reviewed_content_hash'=>$old,'review_status'=>'approved'),array(),'review_queued','request_original_authenticated_review'),
 'approved_exact_is_terminal'=>array('brand_context',array('content_hash'=>$h,'reviewed_content_hash'=>$h,'review_status'=>'approved'),array(),'approved','continue_original_workflow'),
 'needs_changes_same_hash_repairs'=>array('brand_context',array('content_hash'=>$h,'reviewed_content_hash'=>$h,'review_status'=>'needs_changes'),array(),'needs_revision','source_owner_revise_then_complete_scan'),
 'changed_rejected_version_requeues'=>array('brand_context',array('content_hash'=>$h,'reviewed_content_hash'=>$old,'review_status'=>'rejected'),array(),'review_queued','request_original_authenticated_review'),
 'needs_external_fact'=>array('brand_context',array('content_hash'=>$h,'external_evidence_missing'=>true),array(),'blocked_external_evidence','collect_verified_external_evidence'),
 'no_text_must_normalize'=>array('brand_context',array('content_hash'=>$h,'content_complete'=>false),array(),'needs_normalization','reinspect_source_and_normalize'),
 'stalled_content_awaits_new_revision'=>array('brand_context',array('content_hash'=>$h,'review_status'=>'needs_changes'),array('unchanged_repair_rounds'=>2),'stalled','change_actual_source_content_before_retry'),
 'max_rounds_exhausted'=>array('brand_context',array('content_hash'=>$h),array('rounds'=>6),'escalated','inspect_repair_loop_and_evidence'),
 'content_draft_queued'=>array('content_job',array('content_hash'=>$h,'review_status'=>'waiting_review'),array(),'review_queued','request_original_authenticated_review'),
 'catalog_queued'=>array('product_catalog',array('content_hash'=>$h,'review_status'=>'unreviewed'),array(),'review_queued','request_original_authenticated_review'),
 'seo_queued'=>array('seo_metadata',array('content_hash'=>$h),array(),'review_queued','request_original_authenticated_review'),
 'translations_repair'=>array('localization',array('content_hash'=>$h,'review_status'=>'needs_changes','reviewed_content_hash'=>$h),array(),'needs_revision','correct_translation_then_check_parity'),
 'templates_queued'=>array('design_template',array('content_hash'=>$h),array(),'review_queued','request_original_authenticated_review'),
 'search_profile_protected'=>array('search_profile',array('content_hash'=>$h),array(),'protected_review_gate','use_original_governed_approval_lane'),
 'provider_change_protected'=>array('provider_configuration',array('content_hash'=>$h),array(),'protected_review_gate','use_original_governed_approval_lane'),
 'approval_ticket_protected'=>array('approval_ticket',array('content_hash'=>$h),array(),'protected_review_gate','use_original_governed_approval_lane'),
 'plugin_lifecycle_protected'=>array('plugin_lifecycle',array('content_hash'=>$h),array(),'protected_review_gate','use_original_governed_approval_lane'),
 'cross_plugin_protected'=>array('cross_plugin',array('content_hash'=>$h),array(),'protected_review_gate','use_original_governed_approval_lane'),
 'no_delegation_no_auto_review'=>array('brand_context',array('content_hash'=>$h),array('identity_ready'=>false),'protected_review_gate','use_original_governed_approval_lane'),
);
$n=0;
foreach($checks as $name=>$args){
    $plan=MAD4B_SCP_AI_Review_Lifecycle::decide($args[0],$args[1],$args[2]);
    if(is_wp_error($plan) || ($plan['state']??'')!==$args[3] || ($plan['next_action']??'')!==$args[4]
        || !empty($plan['automatic_approval_performed']) || !empty($plan['mutation_performed'])
        || !empty($plan['publication_authorized']) || !empty($plan['deployment_authorized'])) {
        fwrite(STDERR,"FAIL $name\n");exit(1);
    }
    $n++;
    echo $name.": PASS\n";
}
if(!is_wp_error(MAD4B_SCP_AI_Review_Lifecycle::decide('unregistered',array())))exit(2);
echo "SUMMARY pass=$n fail=0\n";
