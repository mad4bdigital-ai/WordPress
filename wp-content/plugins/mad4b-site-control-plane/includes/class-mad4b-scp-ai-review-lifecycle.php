<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * A shared, non-authorizing review -> repair -> rescan -> review reducer.
 *
 * An approved outcome MUST still come from the owning provider's separately
 * authenticated decision endpoint. A planner never fabricates a review, edits
 * a source, issues a ticket, or authorizes publication/deployment.
 */
final class MAD4B_SCP_AI_Review_Lifecycle {
    const CONTRACT = 'mad4b.ai-review-lifecycle.v1';
    const MAX_ROUNDS = 6;
    const MAX_STALLED_ROUNDS = 2;

    /** Profiles are intentionally semantic; they do not mint executable grants. */
    public static function profiles() {
        return array(
            'brand_context' => array('lane'=>'context_exact_review','repair'=>'source_owner_revise_then_complete_scan','risk'=>'content','automatic_review_eligible'=>true),
            'content_job' => array('lane'=>'content_job_review_gate','repair'=>'revise_draft_then_rerun_qa','risk'=>'content','automatic_review_eligible'=>true),
            'product_catalog' => array('lane'=>'product_content_review','repair'=>'correct_catalog_evidence_then_revalidate','risk'=>'content','automatic_review_eligible'=>true),
            'seo_metadata' => array('lane'=>'seo_content_review','repair'=>'revise_meta_then_validate','risk'=>'content','automatic_review_eligible'=>true),
            'localization' => array('lane'=>'translation_quality_review','repair'=>'correct_translation_then_check_parity','risk'=>'content','automatic_review_eligible'=>true),
            'design_template' => array('lane'=>'template_acceptance_review','repair'=>'adjust_template_then_browser_acceptance','risk'=>'content','automatic_review_eligible'=>true),
            'search_profile' => array('lane'=>'search_profile_review','repair'=>'repair_market_rules_then_test','risk'=>'configuration','automatic_review_eligible'=>false),
            'provider_configuration' => array('lane'=>'provider_change_review','repair'=>'repair_provider_binding_then_retest','risk'=>'configuration','automatic_review_eligible'=>false),
            'approval_ticket' => array('lane'=>'original_approval_ticket','repair'=>'replan_exact_operation_then_reassess_risk','risk'=>'protected_mutation','automatic_review_eligible'=>false),
            'plugin_lifecycle' => array('lane'=>'original_plugin_lifecycle','repair'=>'repair_dependency_then_exact_plan','risk'=>'protected_mutation','automatic_review_eligible'=>false),
            'cross_plugin' => array('lane'=>'certified_source_review','repair'=>'rebuild_certified_artifact_then_replan','risk'=>'protected_mutation','automatic_review_eligible'=>false),
        );
    }

    public static function decide( $domain, $item, $history = array() ) {
        $domain=(string)$domain;
        $profiles=self::profiles();
        if(!isset($profiles[$domain]) || !is_array($item) || !is_array($history))
            return new WP_Error('mad4b_ai_lifecycle_input_invalid','Supported review domain and structured facts required.');
        $profile=$profiles[$domain];
        $status=strtolower(trim((string)($item['review_status']??$item['status']??'unreviewed')));
        $content_hash=strtolower(trim((string)($item['content_hash']??$item['fingerprint']??'')));
        $reviewed_hash=strtolower(trim((string)($item['reviewed_content_hash']??'')));
        $hash_valid=1===preg_match('/^[a-f0-9]{64}$/D',$content_hash);
        $exact=$hash_valid && 1===preg_match('/^[a-f0-9]{64}$/D',$reviewed_hash)
            && hash_equals($content_hash,$reviewed_hash);
        $complete=!array_key_exists('content_complete',$item) || true===$item['content_complete'];
        $rounds=max(0,(int)($history['rounds']??0));
        $stalled=max(0,(int)($history['unchanged_repair_rounds']??0));
        $external=!empty($item['external_evidence_missing']);
        $identity_ready=!array_key_exists('identity_ready',$history) || true===$history['identity_ready'];
        $policy=$history['review_mode']??'ai_only';
        $delegated=$policy==='ai_only' && $identity_ready;
        $safe=($profile['automatic_review_eligible']===true)&&$delegated;
        $state='awaiting_review';
        $next='request_original_authenticated_review';
        $blockers=array();
        if($status==='approved' && $exact) {
            $state='approved';$next='continue_original_workflow';
        } elseif($external) {
            $state='blocked_external_evidence';$next='collect_verified_external_evidence';
            $blockers[]='external_evidence_missing';
        } elseif(!$complete || !$hash_valid) {
            $state='needs_normalization';$next='reinspect_source_and_normalize';
            $blockers[]='content_incomplete_or_unbound';
        } elseif($rounds>=self::MAX_ROUNDS) {
            $state='escalated';$next='inspect_repair_loop_and_evidence';
            $blockers[]='review_round_budget_exhausted';
        } elseif($stalled>=self::MAX_STALLED_ROUNDS) {
            $state='stalled';$next='change_actual_source_content_before_retry';
            $blockers[]='unchanged_content_repair_loop';
        } elseif(!$safe) {
            $state='protected_review_gate';$next='use_original_governed_approval_lane';
            $blockers[]=$profile['automatic_review_eligible']?'delegated_agent_not_ready':'risk_requires_independent_authority';
        } elseif(in_array($status,array('needs_changes','rejected','failed'),true) && $exact) {
            $state='needs_revision';$next=$profile['repair'];
        } elseif(in_array($status,array('waiting_review','unreviewed','needs_review_content_changed','needs_review','pending','needs_changes','rejected','failed','approved'),true)) {
            // A changed hash invalidates the prior approval but schedules the NEW
            // version immediately. Replaying a rejected unchanged version is denied.
            $state='review_queued';$next='request_original_authenticated_review';
        } else {
            $state='blocked_unknown_status';$next='inspect_original_lifecycle_status';
            $blockers[]='unsupported_review_state';
        }
        return array(
            'contract'=>self::CONTRACT,'domain'=>$domain,'lane'=>$profile['lane'],
            'state'=>$state,'next_action'=>$next,'target'=>'approved',
            'current_hash'=>$hash_valid?$content_hash:'',
            'review_binding_exact'=>$exact,'review_mode'=>$policy,
            'rounds'=>$rounds,'round_budget'=>self::MAX_ROUNDS,
            'automatic_repair_routing'=>$safe,'automatic_approval_performed'=>false,
            'original_governance_required'=>true,'publication_authorized'=>false,
            'deployment_authorized'=>false,'mutation_performed'=>false,
            'blockers'=>$blockers,
        );
    }

    public static function exact_media_evidence($asset_id) {
        $asset_id=strtolower(trim((string)$asset_id));
        if(!preg_match('/^[a-f0-9]{64}$/D',$asset_id))
            return new WP_Error('mad4b_ai_evidence_id_invalid','Exact asset id required.',array('status'=>400));
        $asset=MAD4B_SCP_Context_Authority::asset($asset_id);
        if(!is_array($asset) || empty($asset) || ($asset['source_mode']??'')!=='governed')
            return new WP_Error('mad4b_ai_evidence_asset_missing','Governed Context asset not found.',array('status'=>404));
        $source=MAD4B_SCP_Context_Authority::source((string)($asset['source_id']??''));
        if(!is_array($source) || ($source['provider']??'')!=='wordpress_media')
            return new WP_Error('mad4b_ai_evidence_provider_delegation_required','Use the original source provider to inspect this asset.',array('status'=>409));
        $id=(int)($asset['file_id']??0);
        $path=$id>0 && get_post_type($id)==='attachment'?get_attached_file($id):false;
        $real=is_string($path)?realpath($path):false;
        $uploads=wp_get_upload_dir();
        $base=is_array($uploads)?realpath((string)($uploads['basedir']??'')):false;
        if(!$real || !$base || strpos($real,$base.DIRECTORY_SEPARATOR)!==0 || is_link($path)
            || !is_file($real) || !is_readable($real) || filesize($real)>10485760)
            return new WP_Error('mad4b_ai_evidence_file_denied','Only bounded registered uploads may be inspected.',array('status'=>409));
        $bytes=file_get_contents($real);
        if(!is_string($bytes) || !hash_equals((string)($asset['content_hash']??''),hash('sha256',$bytes)))
            return new WP_Error('mad4b_ai_evidence_hash_drift','Source has changed. Rescan before review.',array('status'=>409));
        $mime=(string)get_post_mime_type($id);
        $normal=MAD4B_SCP_WordPress_Media_Context::normalize_bytes($mime,$bytes);
        if(is_wp_error($normal)) return $normal;
        $content=(string)($normal['normalized_text']??'');
        if(empty($normal['content_complete']) || strlen($content)>262144 || trim($content)==='')
            return new WP_Error('mad4b_ai_evidence_incomplete','Normalized content is incomplete.',array('status'=>409));
        $queue=MAD4B_SCP_Context_Authority::review_queue();
        return array('contract'=>'mad4b.ai-review-evidence.v1','asset_id'=>$asset_id,
            'title'=>(string)($asset['title']??''),'category'=>(string)($asset['category']??''),
            'mime_type'=>$mime,'normalized_text'=>$content,'content_hash'=>$asset['content_hash'],
            'expected_registry_revision'=>$queue['registry_revision'],
            'expected_authority_manifest_fingerprint'=>$queue['authority_manifest_fingerprint'],
            'read_only'=>true,'mutation_performed'=>false,'publication_authorized'=>false);
    }

    /** Derivative progress only; original Context registry remains the authority. */
    public static function read_history($asset_id,$current_hash='') {
        $items=get_option('mad4b_scp_ai_review_lifecycle_history_v1',array());
        $row=is_array($items)?($items[(string)$asset_id]??array()):array();
        if(!is_array($row))$row=array();
        return array('rounds'=>(int)($row['rounds']??0),
            'unchanged_repair_rounds'=>(string)($row['last_hash']??'')===(string)$current_hash
                ?(int)($row['unchanged_repair_rounds']??0):0);
    }
    public static function record_review_result($asset_id,$hash,$decision) {
        $asset_id=strtolower(trim((string)$asset_id));
        $hash=strtolower(trim((string)$hash));
        $decision=(string)$decision;
        if(!preg_match('/^[a-f0-9]{64}$/D',$asset_id) || !preg_match('/^[a-f0-9]{64}$/D',$hash)
           || !in_array($decision,array('approve','needs_changes','reject'),true))
            return new WP_Error('mad4b_ai_review_history_invalid','Only exact reviewed facts may be tracked.');
        $asset=MAD4B_SCP_Context_Authority::asset($asset_id);
        if(!is_array($asset) || ($asset['review_actor_type']??'')!=='ai_agent'
            || ($asset['review_decision']??'')!==$decision
            || !hash_equals((string)($asset['reviewed_content_hash']??''),$hash))
            return new WP_Error('mad4b_ai_review_history_unverified','Original AI review receipt must be verified first.');
        $revision=MAD4B_SCP_Context_Authority::registry_revision();
        $key='mad4b_scp_ai_review_lifecycle_history_v1';
        $items=get_option($key,array());if(!is_array($items))$items=array();
        $old=is_array($items[$asset_id]??null)?$items[$asset_id]:array();
        if((int)($old['registry_revision']??-1)===$revision && ($old['last_hash']??'')===$hash)
            return array('recorded'=>true,'already_recorded'=>true,'rounds'=>(int)($old['rounds']??0));
        $same_hash=($old['last_hash']??'')===$hash;
        $stalled=$decision==='needs_changes' ? ($same_hash && ($old['last_decision']??'')==='needs_changes'
            ?(int)($old['unchanged_repair_rounds']??0)+1:1):0;
        $items[$asset_id]=array('last_hash'=>$hash,'last_decision'=>$decision,
            'registry_revision'=>$revision,'rounds'=>(int)($old['rounds']??0)+1,
            'unchanged_repair_rounds'=>$stalled,'updated_at'=>gmdate('c'));
        if(count($items)>100)return new WP_Error('mad4b_ai_review_history_capacity','Lifecycle progress exceeds safe site capacity.');
        update_option($key,$items,false);
        $stored=get_option($key,array());
        if(!is_array($stored)||($stored[$asset_id]['registry_revision']??null)!==$revision)
            return new WP_Error('mad4b_ai_review_history_readback_failed','Review was committed but lifecycle progress cannot be verified.');
        return array('recorded'=>true,'already_recorded'=>false,'rounds'=>$items[$asset_id]['rounds'],
            'unchanged_repair_rounds'=>$stalled);
    }

    /** Current first-party Brand Core inbox. No DB writes or fake review actor. */
    public static function brand_context_inbox() {
        if(!class_exists('MAD4B_SCP_Context_Authority'))return new WP_Error('mad4b_ai_lifecycle_context_unavailable','Context Authority is unavailable.');
        $policy=MAD4B_SCP_Context_Authority::review_policy();
        $queue=MAD4B_SCP_Context_Authority::review_queue();
        if(!is_array($queue))return new WP_Error('mad4b_ai_lifecycle_queue_unavailable','Brand Context queue is unavailable.');
        $ai_status=MAD4B_SCP_Context_Authority::ai_review_policy_status();
        $identity_ready=is_array($ai_status) && !empty($ai_status['ready']) && !empty($ai_status['exact_grant_ready']);
        $items=array();
        foreach((array)($queue['items']??array()) as $asset) {
            if(!is_array($asset))continue;
            if(empty($asset['required']) && count($items)>=25)continue;
            $history=self::read_history((string)($asset['asset_id']??''),(string)($asset['content_hash']??''));
            $history['review_mode']=$policy['mode']??'human_only';
            $history['identity_ready']=$identity_ready;
            $plan=self::decide('brand_context',$asset,$history);
            if(is_wp_error($plan))continue;
            $items[]=array('asset_id'=>(string)($asset['asset_id']??''),
                'category'=>(string)($asset['category']??''),
                'required'=>!empty($asset['required']),
                'review_status'=>(string)($asset['review_status']??''),
                'lifecycle'=>$plan);
        }
        return array('contract'=>self::CONTRACT,'mode'=>$policy['mode']??'human_only',
            'registry_revision'=>(int)($queue['registry_revision']??0),
            'authority_manifest_fingerprint'=>(string)($queue['authority_manifest_fingerprint']??''),
            'items'=>$items,'count'=>count($items),'read_only'=>true,'mutation_performed'=>false);
    }
}
