<?php
defined('ABSPATH') || exit;

/**
 * Signed Host → strategy evaluation → native versioned SEO QA Artifact.
 * No WordPress-user impersonation, attachment-meta update, or auto-approval.
 */
final class MAD4B_SCP_Host_Visual_Strategy_Proposal {
    const CONTRACT='mad4b.host-visual-strategy-proposal.v1';

    public static function execute(array $body) {
        $expected=['job_id','site_uuid','expected_previous_artifact_id',
            'post_id','media_role','destination_slug','gallery_sha256',
            'image_sha256','visual_representation','observed_objects',
            'alt_drafts','market'];
        $received=array_keys($body);sort($received);sort($expected);
        if($received!==$expected)
            return new WP_Error('mad4b_visual_signed_input_invalid','Exact bounded strategy input required.');
        if(!MAD4B_SCP_Host_Agent_Bridge::active_for('mad4b/artifact-append'))
            return new WP_Error('mad4b_visual_host_grant_required','Exact signed Host Artifact grant required.');
        $site=MAD4B_SCP_Host_Agent_Bridge::site();
        if(empty($site['site_uuid'])||!is_string($body['site_uuid'])
            ||!hash_equals($site['site_uuid'],$body['site_uuid']))
            return new WP_Error('mad4b_visual_site_drift','Site fingerprint drift.');
        $job_id=(string)$body['job_id'];
        if(!preg_match('/^[a-f0-9-]{36}$/D',$job_id))
            return new WP_Error('mad4b_visual_job_invalid','Job reference invalid.');
        $source_id=absint($body['post_id']);
        if(get_post_type($source_id)!=='tours-and-activities'
            ||get_post_status($source_id)!=='publish')
            return new WP_Error('mad4b_visual_post_invalid','Published source tour required.');
        $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
        if(is_wp_error($job))return $job;
        if(($job['job']['state']??'')!=='WAITING_REVIEW'
            ||!str_contains((string)($job['job']['subject']??''),get_the_title($source_id)))
            return new WP_Error('mad4b_visual_job_not_bound','Source tour Media Review Job is not awaiting review.');
        $args=array_intersect_key($body,array_flip([
            'post_id','media_role','destination_slug','gallery_sha256',
            'image_sha256','visual_representation','observed_objects','alt_drafts','market'
        ]));
        $assessment=MAD4B_SCP_Visual_SEO_Strategy::evaluate($args);
        if(is_wp_error($assessment))return $assessment;
        if(($assessment['context']['site_uuid']??'')!==$site['site_uuid'])
            return new WP_Error('mad4b_visual_context_wrong_company','Strategy resolved for another company.');
        $lock=MAD4B_SCP_Distributed_Lock::catalog_name(
            'host-visual-seo:'.$site['site_uuid'].':'.$job_id);
        $held=MAD4B_SCP_Distributed_Lock::acquire($lock);
        if(is_wp_error($held))return $held;
        try {
            // Revalidate the live image and Brand Policy under the lock.
            $now=MAD4B_SCP_Visual_SEO_Strategy::evaluate($args);
            if(is_wp_error($now))return $now;
            if(!hash_equals($assessment['strategy_fingerprint'],$now['strategy_fingerprint']))
                return new WP_Error('mad4b_visual_strategy_snapshot_drift','Strategy evaluation changed.');
            $artifacts=MAD4B_SCP_Artifacts::list_artifacts([
                'job_id'=>$job_id,'artifact_type'=>'seo_qa']);
            if(is_wp_error($artifacts))return $artifacts;
            $active=null;
            foreach(($artifacts['items']??[]) as $artifact){
                if(($artifact['status']??'')==='active'){
                    if(!$active ||(int)$artifact['version']>(int)$active['version'])$active=$artifact;
                }
            }
            if(!$active)return new WP_Error('mad4b_visual_prior_qa_missing','Existing SEO QA proposal required for versioned migration.');
            $fp=(string)$now['strategy_fingerprint'];
            $old_fp=(string)($active['payload']['strategy_evaluation']['strategy_fingerprint']??'');
            if($old_fp!==''&&hash_equals($old_fp,$fp))
                return [
                    'contract'=>self::CONTRACT,'state'=>'already_proposed',
                    'job_id'=>$job_id,'artifact_id'=>$active['artifact_id'],
                    'version'=>$active['version'],'strategy_fingerprint'=>$fp,
                    'review_required'=>true,'alt_text_written'=>false
                ];
            if(!hash_equals((string)$active['artifact_id'],(string)$body['expected_previous_artifact_id']))
                return new WP_Error('mad4b_visual_artifact_revision_conflict','Previous Artifact does not match active generation.');
            $old_image=(string)($active['payload']['image_sha256']??$active['payload']['source_image_sha256']??'');
            if($old_image!=='' && !hash_equals($old_image,$now['source_image_sha256']))
                return new WP_Error('mad4b_visual_image_changed','Source image changed since prior QA Artifact.');
            foreach($now['attachments'] as $lang=>$entry){
                if(trim((string)$entry['alt_text_current'])!=='')
                    return new WP_Error('mad4b_visual_alt_changed','Existing approved or manually entered Alt must be preserved.');
            }
            $localized=[];
            foreach($now['localized_alt_suggestions'] as $lang=>$draft){
                $loc=$now['attachments'][$lang];
                $localized[$lang]=[
                    'post_id'=>$loc['post_id'],'attachment_id'=>$loc['attachment_id'],
                    'alt_text_draft'=>$draft['alt_text_draft'],
                    'destination_from_taxonomy'=>$draft['destination'],
                    'rank_math_title_present'=>$draft['rank_math_title_present'],
                    'review_required'=>true
                ];
            }
            $payload=[
                'contract'=>'mad4b.localized-semantic-seo-qa.v2',
                'source_post_id'=>$source_id,'job_id'=>$job_id,
                'site_uuid'=>$site['site_uuid'],
                'image_sha256'=>$now['source_image_sha256'],
                'gallery_sha256'=>$now['source_gallery_sha256'],
                'locale_suggestions'=>$localized,
                'strategy_evaluation'=>$now,
                'supersedes_artifact_id'=>$active['artifact_id'],
                'status'=>'pending_inherited_brand_and_editorial_review',
                'review_policy'=>$now['review_mode_inherited'],
                'old_proposal_preserved'=>true,
                'alt_text_written'=>false,'image_replaced'=>false,
                'publication_authorized'=>false
            ];
            $saved=MAD4B_SCP_Artifacts::append_artifact([
                'job_id'=>$job_id,'artifact_type'=>'seo_qa',
                'payload'=>$payload,
                'metadata'=>[
                    'site_uuid'=>$site['site_uuid'],'source_post_id'=>$source_id,
                    'strategy_version'=>MAD4B_SCP_Visual_SEO_Strategy::VERSION,
                    'strategy_fingerprint'=>$fp,
                    'source_image_sha256'=>$now['source_image_sha256'],
                    'review_policy'=>$now['review_mode_inherited'],
                    'prior_artifact_id'=>$active['artifact_id'],
                    'approved'=>false,'alt_written'=>false
                ],
                'producer_stage'=>'SEO','producer_ref'=>'signed-host-visual-seo-strategy',
                'reason'=>'Destination-contextualized semantic Alt draft under inherited MAD4B strategy, pending Brand Review'
            ]);
            if(is_wp_error($saved))return $saved;
            $record=$saved['artifact']??[];
            $audit=MAD4B_SCP_Audit::record('mad4b/visual-strategy-seo-qa-versioned',[
                'site_uuid'=>$site['site_uuid'],'source_post_id'=>$source_id,
                'job_id'=>$job_id,'strategy_fingerprint'=>$fp,
                'source_image_sha256'=>$now['source_image_sha256'],
                'previous_artifact_id'=>$active['artifact_id'],
                'new_artifact_id'=>$record['artifact_id']??'',
                'old_version'=>(int)$active['version'],
                'new_version'=>(int)($record['version']??0),
                'review_mode'=>$now['review_mode_inherited'],
                'identity_method'=>'signed_host_wpcli',
                'wp_user_id'=>0,'alt_written'=>false,
                'publication_authorized'=>false
            ]);
            if(is_wp_error($audit))
                return new WP_Error('mad4b_visual_audit_partial','Versioned Artifact written; audit receipt requires reconciliation.',
                    ['artifact_id'=>$record['artifact_id']??'','job_id'=>$job_id]);
            return [
                'contract'=>self::CONTRACT,'state'=>'waiting_review',
                'job_id'=>$job_id,'artifact_id'=>$record['artifact_id']??'',
                'version'=>$record['version']??0,
                'supersedes_artifact_id'=>$active['artifact_id'],
                'strategy_fingerprint'=>$fp,
                'destination'=>$now['source_destination']['name'],
                'languages'=>array_keys($localized),
                'review_mode'=>$now['review_mode_inherited'],
                'alt_text_written'=>false,'media_modified'=>false,
                'publication_authorized'=>false
            ];
        } finally {
            MAD4B_SCP_Distributed_Lock::release($lock);
        }
    }
}
