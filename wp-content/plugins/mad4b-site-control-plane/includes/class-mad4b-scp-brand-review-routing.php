<?php
/**
 * MAD4B Brand Review Routing — dynamic policy inheritance, no synthetic approvals.
 * Source -> exact Site Profile Context Review Policy -> authenticated reviewer.
 * Inheritance transfers policy intent, never agent credentials or authority grants.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Brand_Review_Routing_V1 {
    const CONTRACT='mad4b.brand-review-routing.v1';

    public static function resolve(string $source_id): array {
        $base=[
            'contract'=>self::CONTRACT,
            'source_id'=>$source_id,
            'policy_source'=>'site_profile.context_authority.review_policy',
            'source_override'=>'inherit',
            'effective_mode'=>'human_only',
            'review_route'=>'human_review',
            'authority_ready'=>false,
            'ai_review_eligible'=>false,
            'human_review_eligible'=>true,
            'next_action'=>'review_queue_after_verified_indexing',
            'blockers'=>[],
            'publication_authorized'=>false,
            'agent_grants_inherited'=>false,
            'automatic_approval'=>false,
            'asset_indexed'=>false,
        ];
        if (!class_exists('MAD4B_SCP_Context_Authority',false) ||
            !class_exists('MAD4B_SCP_Site_Profile',false)) {
            return array_merge($base,['review_route'=>'blocked',
                'blockers'=>['context_authority_unavailable']]);
        }
        $site=MAD4B_SCP_Site_Profile::status();
        $source=MAD4B_SCP_Context_Authority::source($source_id);
        if (!is_array($source) || !$source
            || !is_array($site)
            || !hash_equals((string)($site['site_uuid']??''),
                (string)($source['site_uuid']??''))
            || (string)($source['mode']??'')!=='governed') {
            return array_merge($base,['review_route'=>'blocked',
                'blockers'=>['source_site_identity_unverified']]);
        }
        $policy=MAD4B_SCP_Context_Authority::ai_review_policy_status();
        $mode=(string)($policy['mode']??'human_only');
        if (!in_array($mode,['human_only','human_and_ai','ai_only'],true))
            $mode='human_only';

        $can_ai=in_array($mode,['human_and_ai','ai_only'],true)
            && !empty($policy['ready'])
            && !empty($policy['exact_grant_ready'])
            && !empty($policy['catalog_eligible']);
        $can_human=$mode!=='ai_only';
        $blockers=$mode==='human_only'?[]:(array)($policy['blockers']??[]);
        // Exact delegated Agent permission and this site's model worker
        // execution readiness are separate proofs; neither implies the other.
        $worker_ready=false; $worker_blockers=[];
        if (in_array($mode,['human_and_ai','ai_only'],true)
            && class_exists('MAD4B_SCP_AI_Review_Worker',false)) {
            $worker=MAD4B_SCP_AI_Review_Worker::preflight();
            if(is_array($worker)){
                $worker_ready=!empty($worker['ready']);
                $worker_blockers=array_values(array_map('sanitize_key',
                    (array)($worker['blockers']??[])));
            }
        }
        $indexed=0; $reviewable=0;
        foreach (MAD4B_SCP_Context_Authority::assets() as $asset) {
            if (!is_array($asset) || (string)($asset['source_id']??'')!==$source_id)
                continue;
            ++$indexed;
            if ( !empty($asset['content_complete'])
                && preg_match('/^[a-f0-9]{64}$/D',(string)($asset['content_hash']??''))
                && in_array((string)($asset['review_status']??'unreviewed'),
                    ['unreviewed','needs_review_content_changed','needs_changes'],true))
                ++$reviewable;
        }

        $route='human_review';
        if ($mode==='ai_only')
            $route=$can_ai?'ai_agent_review':'ai_agent_authority_pending';
        elseif($mode==='human_and_ai')
            $route=$can_ai?'human_or_ai_review':'human_review_ai_pending';

        $next=$indexed<1?'index_verified_inventory_first':
            ($reviewable===0?'no_new_reviewable_assets':
            ($can_ai?'delegate_exact_ai_agent_review':
             ($can_human?'human_review_queue':'await_ai_agent_authority')));
        return array_merge($base,[
            'site_uuid'=>(string)($site['site_uuid']??''),
            'effective_mode'=>$mode,
            'review_route'=>$route,
            'authority_ready'=>$mode==='human_only'||$can_ai,
            'ai_review_eligible'=>$can_ai,
            'local_ai_worker_ready'=>$worker_ready,
            'local_ai_worker_blockers'=>$worker_blockers,
            'human_review_eligible'=>$can_human,
            'blockers'=>array_values(array_unique(array_map('sanitize_key',$blockers))),
            'indexed_assets'=>$indexed,
            'reviewable_verified_assets'=>$reviewable,
            'asset_indexed'=>$indexed>0,
            'next_action'=>$next,
            'policy_contract'=>(string)($policy['contract']??''),
            'publication_authorized'=>false,
        ]);
    }

    public static function summarize(string $source_id): array {
        $r=self::resolve($source_id);
        return array_intersect_key($r,array_flip([
            'contract','source_id','policy_source','source_override','effective_mode',
            'review_route','authority_ready','ai_review_eligible',
            'local_ai_worker_ready','local_ai_worker_blockers',
            'human_review_eligible','blockers','indexed_assets','reviewable_verified_assets',
            'next_action','publication_authorized','agent_grants_inherited','automatic_approval'
        ]));
    }
}
