<?php
defined('ABSPATH') || exit;

/**
 * Original signed-Host candidate reconciliation → immutable Media Plan.
 * Approval and application are intentionally separate operations.
 */
final class MAD4B_SCP_Host_Media_Reconcile_Proposal {
    const CONTRACT='mad4b.host-media-reconcile-proposal.v1';

    public static function execute(array $body) {
        $expected=['job_id','source_post_id','site_uuid','expected_gallery_sha256',
            'expected_plan_fingerprint','expected_previous_media_plan_id'];
        $actual=array_keys($body);sort($actual);sort($expected);
        if($expected!==$actual)
            return new WP_Error('mad4b_media_reconcile_request_schema_invalid',
                'Exact company-bound reconciliation input required.');
        if(!MAD4B_SCP_Host_Agent_Bridge::active_for('mad4b/artifact-append'))
            return new WP_Error('mad4b_media_reconcile_signed_host_required',
                'Signed Host with exact Artifact Append grant is required.');
        $site=MAD4B_SCP_Host_Agent_Bridge::site();
        if(empty($site['site_uuid'])||!is_string($body['site_uuid'])
            ||!hash_equals($site['site_uuid'],$body['site_uuid']))
            return new WP_Error('mad4b_media_reconcile_site_drift',
                'Company Site Profile does not match the signed envelope.');
        $job_id=(string)$body['job_id'];
        $post_id=(int)$body['source_post_id'];
        if(!preg_match('/^[a-f0-9-]{36}$/D',$job_id)
            ||$post_id<1 ||get_post_type($post_id)!=='tours-and-activities')
            return new WP_Error('mad4b_media_reconcile_post_invalid',
                'Exact original Media Review Job and tour are required.');
        $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
        if(is_wp_error($job))return $job;
        if(($job['job']['state']??'')!=='WAITING_REVIEW'
           ||($job['job']['site_uuid']??'')!==$site['site_uuid'])
            return new WP_Error('mad4b_media_reconcile_job_not_reviewable',
                'Signed Host reconciliation only for a current company Media Review Job.');
        $facts=MAD4B_SCP_Artifacts::list_artifacts([
            'job_id'=>$job_id,'artifact_type'=>'fact_ledger']);
        if(is_wp_error($facts))return $facts;
        $bound=false;
        foreach((array)($facts['items']??[]) as $fact) {
            $p=$fact['payload']??[];
            if((int)($p['source_post_id']??0)===$post_id
                &&($p['site_uuid']??'')===$site['site_uuid']){$bound=true;break;}
        }
        if(!$bound)
            return new WP_Error('mad4b_media_reconcile_job_source_unbound',
                'A versioned Fact Ledger must bind the original tour.');
        $lock=MAD4B_SCP_Distributed_Lock::catalog_name(
            'media-reconcile-artifact:'.$site['site_uuid'].':'.$job_id);
        $held=MAD4B_SCP_Distributed_Lock::acquire($lock);
        if(is_wp_error($held))return $held;
        try {
            $plan=MAD4B_Tour_Media_Reconcile_V1::plan($post_id,['limit'=>18]);
            if(is_wp_error($plan))return $plan;
            if(!hash_equals((string)$plan['plan_fingerprint'],
                (string)$body['expected_plan_fingerprint'])
                ||!hash_equals(hash('sha256',(string)get_post_meta($post_id,'gallery',true)),
                    (string)$body['expected_gallery_sha256']))
                return new WP_Error('mad4b_media_reconcile_stale_snapshot',
                    'Source gallery or attached candidate fingerprint changed.');
            if($plan['site_uuid']!==$site['site_uuid'])
                return new WP_Error('mad4b_media_reconcile_wrong_site',
                    'Candidate selection is not scoped to this site.');
            $prior=MAD4B_SCP_Artifacts::list_artifacts([
                'job_id'=>$job_id,'artifact_type'=>'media_plan']);
            if(is_wp_error($prior))return $prior;
            $active=null;
            foreach((array)($prior['items']??[]) as $row){
                if(($row['status']??'')!=='active')continue;
                if(!$active ||(int)$row['version']>(int)$active['version'])
                    $active=$row;
            }
            if(!$active)
                return new WP_Error('mad4b_media_reconcile_prior_plan_missing',
                    'Existing original Media Plan must be preserved.');
            if(($active['payload']['reconcile_plan']['plan_fingerprint']??'')
                ===$plan['plan_fingerprint'])
                return [
                    'contract'=>self::CONTRACT,'state'=>'already_proposed',
                    'job_id'=>$job_id,'artifact_id'=>$active['artifact_id'],
                    'version'=>$active['version'],'plan_fingerprint'=>$plan['plan_fingerprint'],
                    'website_updated'=>false,'review_required'=>true,
                ];
            if(!hash_equals((string)$active['artifact_id'],
                (string)$body['expected_previous_media_plan_id']))
                return new WP_Error('mad4b_media_reconcile_version_conflict',
                    'Previous active Media Plan differs from the signed request.');
            $review=MAD4B_SCP_Context_Authority::review_policy();
            $payload=[
                'contract'=>'mad4b.media-plan-with-reconciliation.v2',
                'source_post_id'=>$post_id,'site_uuid'=>$site['site_uuid'],
                'source_gallery_sha256'=>$body['expected_gallery_sha256'],
                'reconcile_plan'=>$plan,
                'supersedes_artifact_id'=>$active['artifact_id'],
                'status'=>'waiting_visual_subject_and_provenance_review',
                'review_mode'=>$review['mode']??'human_only',
                'original_media_plan_preserved'=>true,
                'image_selection_approved'=>false,
                'publication_authorized'=>false,
                'gallery_or_featured_written'=>false,
            ];
            $saved=MAD4B_SCP_Artifacts::append_artifact([
                'job_id'=>$job_id,'artifact_type'=>'media_plan',
                'payload'=>$payload,
                'metadata'=>[
                    'site_uuid'=>$site['site_uuid'],'source_post_id'=>$post_id,
                    'source_gallery_sha256'=>$body['expected_gallery_sha256'],
                    'plan_fingerprint'=>$plan['plan_fingerprint'],
                    'previous_artifact_id'=>$active['artifact_id'],
                    'review_mode'=>$review['mode']??'human_only',
                    'human_or_ai_review_needed'=>true,'approved'=>false,
                ],
                'producer_stage'=>'MEDIA','producer_ref'=>'signed-host-media-reconcile',
                'reason'=>'Same-tour, WPML-aware local media candidates and reversible field projections; review before apply'
            ]);
            if(is_wp_error($saved))return $saved;
            $record=$saved['artifact']??[];
            $audit=MAD4B_SCP_Audit::record('mad4b/host-media-reconciliation-proposed',[
                'site_uuid'=>$site['site_uuid'],'job_id'=>$job_id,
                'source_post_id'=>$post_id,'plan_fingerprint'=>$plan['plan_fingerprint'],
                'candidate_count'=>count($plan['candidate_catalog']['items']??[]),
                'previous_artifact_id'=>$active['artifact_id'],
                'new_artifact_id'=>$record['artifact_id']??'',
                'review_policy'=>$review['mode']??'human_only',
                'host_wp_user_id'=>0,'media_changed'=>false,
            ]);
            if(is_wp_error($audit))
                return new WP_Error('mad4b_media_reconcile_audit_pending',
                    'Media Plan Artifact saved but audit receipt requires reconciliation.',
                    ['artifact_id'=>$record['artifact_id']??'']);
            return [
                'contract'=>self::CONTRACT,'state'=>'waiting_review',
                'job_id'=>$job_id,'source_post_id'=>$post_id,
                'artifact_id'=>$record['artifact_id']??'',
                'version'=>$record['version']??0,
                'supersedes'=>$active['artifact_id'],
                'plan_fingerprint'=>$plan['plan_fingerprint'],
                'candidate_count'=>count($plan['candidate_catalog']['items']??[]),
                'wpml_locales'=>array_keys($plan['wpml_post_family']),
                'review_mode'=>$review['mode']??'human_only',
                'website_updated'=>false,'media_approved'=>false,
            ];
        } finally {
            MAD4B_SCP_Distributed_Lock::release($lock);
        }
    }
}

