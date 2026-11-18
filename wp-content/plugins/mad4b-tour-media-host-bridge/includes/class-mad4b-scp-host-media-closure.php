<?php
defined('ABSPATH') || exit;
/**
 * Signed Host only: close an original Media Review Content Job after verified
 * tour-local media application. Does not grant rights, publish or modify images.
 */
final class MAD4B_SCP_Host_Media_Closure {
    const CONTRACT='mad4b.host-media-closure.v1';
    private static function fail($code) {
        return new WP_Error($code,'Media Review job closure could not be verified.');
    }
    public static function preflight(array $body) {
        $expected=['site_uuid','source_post_id','job_id','expected_job_revision',
            'expected_media_plan_artifact_id','rollback_token',
            'frontend_report_sha256','dry_run'];
        // Optional bounded chain is exclusively for successive *signed*
        // governed applies to the same tour. The last token must remain the
        // existing rollback_token, so old clients and receipts stay valid.
        $has_chain=array_key_exists('rollback_chain_tokens',$body);
        if($has_chain)$expected[]='rollback_chain_tokens';
        $keys=array_keys($body); sort($keys); sort($expected);
        if($keys!==$expected
            || !is_int($body['source_post_id']) || $body['source_post_id']<=0
            || !is_int($body['expected_job_revision']) || $body['expected_job_revision']<1
            || !is_bool($body['dry_run'])
            || !preg_match('/^[a-f0-9-]{36}$/D',(string)$body['job_id'])
            || !preg_match('/^[a-f0-9-]{36}$/D',(string)$body['expected_media_plan_artifact_id'])
            || !preg_match('/^[a-f0-9-]{36}$/D',(string)$body['rollback_token'])
            || !preg_match('/^[a-f0-9]{64}$/D',(string)$body['frontend_report_sha256']))
            return self::fail('mad4b_media_closure_body_invalid');
        if($has_chain){
            $tokens=$body['rollback_chain_tokens'];
            if(!is_array($tokens)||!array_is_list($tokens)
                ||count($tokens)<2||count($tokens)>4
                ||count(array_unique($tokens))!==count($tokens)
                ||end($tokens)!==$body['rollback_token'])
                return self::fail('mad4b_media_closure_chain_invalid');
            foreach($tokens as $token)if(!is_string($token)
                ||!preg_match('/^[a-f0-9-]{36}$/D',$token))
                return self::fail('mad4b_media_closure_chain_token_invalid');
        }
        $site=MAD4B_SCP_Host_Agent_Bridge::site();
        if(empty($site['site_uuid'])
            || !hash_equals($site['site_uuid'],(string)$body['site_uuid']))
            return self::fail('mad4b_media_closure_site_scope_mismatch');
        $post_id=$body['source_post_id'];
        if(get_post_type($post_id)!=='tours-and-activities'
            || get_post_status($post_id)!=='publish')
            return self::fail('mad4b_media_closure_tour_not_published');
        $review=MAD4B_Media_Review_Hierarchy_V1::effective();
        if(empty($review['policy_ready']) || ($review['mode']??'')!=='ai_only')
            return self::fail('mad4b_media_closure_review_policy_not_ready');
        $j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$body['job_id']]);
        if(is_wp_error($j))return $j;
        $job=$j['job'];
        if(($job['site_uuid']??'')!==$site['site_uuid']
            ||($job['state']??'')!=='WAITING_REVIEW'
            ||($job['stage']??'')!=='MEDIA'
            ||($job['content_type']??'')!=='media_review'
            ||(int)$job['job_revision']!==$body['expected_job_revision']
            ||!hash_equals((string)$job['current_artifact_id'],
                (string)$body['expected_media_plan_artifact_id']))
            return self::fail('mad4b_media_closure_job_state_drift');
        $artifact=MAD4B_SCP_Artifacts::get_artifact([
            'artifact_id'=>$body['expected_media_plan_artifact_id']]);
        if(is_wp_error($artifact))return $artifact;
        $art=$artifact['artifact'];
        $original=$art['payload']??[];
        if(($art['job_id']??'')!==$job['job_id']
            ||($art['artifact_type']??'')!=='media_plan'
            ||!in_array((string)($art['status']??''),['active','superseded'],true)
            ||(int)($original['post_id']??$original['source_post_id']??0)!==$post_id)
            return self::fail('mad4b_media_closure_original_plan_not_bound');
        $b=get_option('mad4b_media_apply_backup_v1_'.str_replace('-','',$body['rollback_token']),[]);
        if($has_chain){
            $receipts=[];$merged=[];$original=[];$last_written='';
            foreach($body['rollback_chain_tokens'] as $token){
                $receipt=get_option('mad4b_media_apply_backup_v1_'.str_replace('-','',$token),[]);
                if(!is_array($receipt)
                    ||($receipt['contract']??'')!=='mad4b.tour-media-rollback.v1'
                    ||($receipt['status']??'')!=='applied'
                    ||($receipt['site_uuid']??'')!==$site['site_uuid']
                    ||(int)($receipt['source_post_id']??0)!==$post_id
                    ||($receipt['review_mode']??'')!=='ai_only'
                    ||!hash_equals((string)($receipt['review_policy_fingerprint']??''),
                        (string)$review['policy_fingerprint'])
                    ||!is_array($receipt['changes']??null)
                    ||!is_string($receipt['written_at']??null)
                    ||$receipt['written_at']===''
                    ||($last_written!=='' && strcmp($receipt['written_at'],$last_written)<0))
                    return self::fail('mad4b_media_closure_chain_receipt_invalid');
                $last_written=$receipt['written_at'];
                $receipts[]=$receipt;
                foreach($receipt['changes'] as $change){
                    if(!is_array($change)||!isset($change['post_id'],$change['field'],$change['old'],$change['new'])
                        ||!is_int($change['post_id'])||!is_string($change['field']))
                        return self::fail('mad4b_media_closure_chain_change_invalid');
                    $key=$change['post_id'].':'.$change['field'];
                    if(isset($merged[$key])){
                        if((string)$merged[$key]['new']!==(string)$change['old'])
                            return self::fail('mad4b_media_closure_chain_disconnected');
                        $merged[$key]['new']=$change['new'];
                    }else $merged[$key]=$change;
                }
            }
            if(count($receipts)<2||count($receipts[0]['changes'])<8
                ||count($merged)<8)
                return self::fail('mad4b_media_closure_chain_insufficient_changes');
            // Compose a single virtual factual receipt: oldest preimages,
            // newest postimages. Never modify historical rollback records.
            $b=$receipts[0];
            $b['changes']=array_values($merged);
        }
        if(!is_array($b)||($b['contract']??'')!=='mad4b.tour-media-rollback.v1'
            ||($b['status']??'')!=='applied'
            ||($b['site_uuid']??'')!==$site['site_uuid']
            ||(int)($b['source_post_id']??0)!==$post_id
            ||($b['review_mode']??'')!=='ai_only'
            ||!hash_equals((string)($b['review_policy_fingerprint']??''),
                (string)$review['policy_fingerprint'])
            ||count($b['changes']??[])<8)
            return self::fail('mad4b_media_closure_apply_receipt_missing');
        foreach($b['changes'] as $change){
            if(!is_array($change)||!isset($change['post_id'],$change['field'],$change['new'])
                ||(string)get_post_meta((int)$change['post_id'],
                    (string)$change['field'],true)!==(string)$change['new'])
                return self::fail('mad4b_media_closure_post_apply_meta_drift');
        }
        // Some imported Jobs still point to the original Media Plan even
        // though a newer reconciliation proposal superseded it. Never
        // silently close against a superseded plan. Prove the active v2
        // proposal references exactly the pre-apply gallery in the current
        // rollback receipt; then append a verified applied v3 plan.
        $active_plan_id=(string)$art['artifact_id'];
        $reconcile_pending=false;
        if(($art['status']??'')==='superseded'){
            $plans=MAD4B_SCP_Artifacts::list_artifacts([
                'job_id'=>$body['job_id'],'artifact_type'=>'media_plan']);
            if(is_wp_error($plans))return $plans;
            $active=array_values(array_filter((array)($plans['items']??[]),
                static fn($x)=>($x['status']??'')==='active'));
            if(count($active)!==1)
                return self::fail('mad4b_media_closure_active_plan_ambiguous');
            $live=$active[0]; $payload=$live['payload']??[];
            if((int)($live['version']??0)<=(int)($art['version']??0)
                ||($live['job_id']??'')!==$job['job_id']
                ||(int)($payload['source_post_id']??0)!==$post_id
                ||($payload['site_uuid']??'')!==$site['site_uuid'])
                return self::fail('mad4b_media_closure_superseding_plan_mismatch');
            $active_plan_id=(string)$live['artifact_id'];
            if(($payload['contract']??'')==='mad4b.media-plan-with-reconciliation.v2'){
                if(($payload['status']??'')!=='waiting_visual_subject_and_provenance_review')
                    return self::fail('mad4b_media_closure_unexpected_pending_review');
                $oldgallery='';
                foreach($b['changes'] as $change){
                    if((int)($change['post_id']??0)===$post_id
                        &&($change['field']??'')==='gallery'){
                        $oldgallery=(string)($change['old']??'');
                        break;
                    }
                }
                if($oldgallery==='' || !hash_equals(
                    hash('sha256',$oldgallery),
                    (string)($payload['source_gallery_sha256']??'')))
                    return self::fail('mad4b_media_closure_old_gallery_plan_drift');
                $reconcile_pending=true;
            }elseif(($payload['contract']??'')==='mad4b.media-plan-applied-reconciliation.v1'){
                if(($payload['status']??'')!=='applied_verified'
                    ||($payload['rollback_token']??'')!==$body['rollback_token']
                    ||!hash_equals(
                        hash('sha256',(string)get_post_meta($post_id,'gallery',true)),
                        (string)($payload['postapply_gallery_sha256']??'')))
                    return self::fail('mad4b_media_closure_postapply_plan_drift');
            }else return self::fail('mad4b_media_closure_unknown_active_plan');
        }
        $plan=MAD4B_Tour_Media_Reconcile_V1::plan($post_id,['limit'=>24]);
        if(is_wp_error($plan))return $plan;
        if(($plan['site_uuid']??'')!==$site['site_uuid']
            ||!is_array($plan['wpml_post_family']??null)
            ||count($plan['wpml_post_family'])<2)
            return self::fail('mad4b_media_closure_wpml_plan_unavailable');
        $host=(string)wp_parse_url(home_url(),PHP_URL_HOST);
        $uploads=wp_upload_dir();
        if(!empty($uploads['error']))return self::fail('mad4b_media_closure_uploads_unavailable');
        $checked=[];
        foreach($plan['wpml_post_family'] as $lang=>$pid){
            if(get_post_status($pid)!=='publish')
                return self::fail('mad4b_media_closure_locale_unpublished');
            $fields=['gallery','grid_image_1_middle_upper','grid_image_2',
                'grid_image_3_middle_bottom','grid_image_4_','image_vertical'];
            foreach($fields as $field){
                $value=(string)get_post_meta($pid,$field,true);
                $urls= $field==='gallery'?explode(',',$value):[$value];
                if(count($urls)<1||count($urls)>12)
                    return self::fail('mad4b_media_closure_gallery_count_invalid');
                foreach($urls as $url){
                    $url=trim($url);
                    if(!str_starts_with($url,trailingslashit((string)$uploads['baseurl']))
                        ||(string)wp_parse_url($url,PHP_URL_HOST)!==$host
                        ||!preg_match('#^/wp-content/uploads/#',
                            (string)wp_parse_url($url,PHP_URL_PATH)))
                        return self::fail('mad4b_media_closure_foreign_media_reference');
                    $rel=substr($url,strlen(trailingslashit((string)$uploads['baseurl'])));
                    $file=trailingslashit((string)$uploads['basedir']).rawurldecode($rel);
                    if(!is_file($file))
                        return self::fail('mad4b_media_closure_local_file_missing');
                }
                $checked[$lang][$field]=count($urls);
            }
            $thumbnail=get_post_thumbnail_id($pid);
            if(!$thumbnail||!is_file((string)get_attached_file($thumbnail)))
                return self::fail('mad4b_media_closure_thumbnail_unavailable');
        }
        $scan=MAD4B_Media_Origin_Integrity_V1::scan();
        if(empty($scan['complete'])||!is_array($scan['issues']??null))
            return self::fail('mad4b_media_closure_integrity_incomplete');
        $family_ids=array_map('intval',array_values($plan['wpml_post_family']));
        foreach($scan['issues'] as $issue)
            if(in_array((int)($issue['object_id']??0),$family_ids,true))
                return self::fail('mad4b_media_closure_media_issues_remain');
        $ledger=(array)get_option('mad4b_content_readiness_issues_v1',[]);
        $resolved=0;
        foreach($ledger as $issue){
            if(!is_array($issue)||($issue['source']??'')!=='mad4b-media-origin-integrity'
                ||(int)($issue['object_id']??0)!==$post_id)continue;
            if(($issue['status']??'')!=='resolved'
                ||($issue['content_job_id']??'')!==$body['job_id'])
                return self::fail('mad4b_media_closure_alert_unresolved');
            $resolved++;
        }
        if($resolved<2)return self::fail('mad4b_media_closure_resolved_alert_receipts_missing');
        return ['contract'=>self::CONTRACT,'status'=>'verified_ready',
            'site_uuid'=>$site['site_uuid'],'source_post_id'=>$post_id,
            'job_id'=>$job['job_id'],'job_revision'=>(int)$job['job_revision'],
            'media_plan_artifact_id'=>$art['artifact_id'],
            'active_media_plan_artifact_id'=>$active_plan_id,
            'reconciliation_needed'=>$reconcile_pending,
            'current_gallery_sha256'=>hash('sha256',(string)get_post_meta($post_id,'gallery',true)),
            'rollback_token'=>$body['rollback_token'],
            'applied_changed_rows'=>count($b['changes']),
            'verified_locales'=>$checked,
            'resolved_media_alerts'=>$resolved,
            'media_integrity_complete'=>true,
            'tour_open_media_issues'=>0,
            'frontend_report_sha256'=>$body['frontend_report_sha256'],
            'original_photo_rights_verified'=>false,
            'originality_certified'=>false,
            'media_post_modified'=>false];
    }
    public static function execute(array $body) {
        $verified=self::preflight($body);
        if(is_wp_error($verified))return $verified;
        if($body['dry_run'])return $verified;
        if(!MAD4B_SCP_Host_Agent_Bridge::active_for('mad4b/artifact-append')
            ||!MAD4B_SCP_Host_Agent_Bridge::active_for('mad4b/content-job-transition'))
            return self::fail('mad4b_media_closure_signed_host_grants_required');
        if($verified['reconciliation_needed']){
            // Original review proposal is historical after the governed
            // media Apply. Supersede it only with a fact-bound post-Apply
            // artifact, never with another unverified candidate selection.
            $applied=MAD4B_SCP_Artifacts::append_artifact([
                'job_id'=>$body['job_id'],'artifact_type'=>'media_plan',
                'payload'=>[
                    'contract'=>'mad4b.media-plan-applied-reconciliation.v1',
                    'status'=>'applied_verified',
                    'site_uuid'=>$body['site_uuid'],
                    'source_post_id'=>$body['source_post_id'],
                    'prior_media_plan_artifact_id'=>$verified['active_media_plan_artifact_id'],
                    'postapply_gallery_sha256'=>$verified['current_gallery_sha256'],
                    'rollback_token'=>$body['rollback_token'],
                    'applied_changed_rows'=>$verified['applied_changed_rows'],
                    'verified_locales'=>array_keys($verified['verified_locales']),
                    'resolved_media_alerts'=>$verified['resolved_media_alerts'],
                    'review_mode'=>'ai_only',
                    'photo_provenance_certified'=>false,
                    'rights_verified'=>false,
                    'website_media_updated_by_reconciliation'=>false],
                'metadata'=>[
                    'site_uuid'=>$body['site_uuid'],
                    'source_post_id'=>$body['source_post_id'],
                    'frontend_report_sha256'=>$body['frontend_report_sha256'],
                    'source_active_media_plan_id'=>$verified['active_media_plan_artifact_id'],
                    'review_scope'=>'applied_media_reference_reconciliation'],
                'producer_stage'=>'MEDIA',
                'producer_ref'=>'signed-host-media-postapply-reconcile',
                'reason'=>'Supersede stale, pre-apply media proposal after current gallery, locale files and resolved alerts verification',
            ]);
            if(is_wp_error($applied))return $applied;
            $new_plan=$applied['artifact']??[];
            if(($new_plan['status']??'')!=='active'
                ||($new_plan['artifact_type']??'')!=='media_plan')
                return self::fail('mad4b_media_closure_applied_plan_readback_failed');
            $verified['active_media_plan_artifact_id']=(string)$new_plan['artifact_id'];
            $verified['reconciliation_needed']=false;
        }
        $proof=MAD4B_SCP_Artifacts::append_artifact([
            'job_id'=>$body['job_id'],'artifact_type'=>'final_qa',
            'payload'=>array_merge($verified,[
                'contract'=>'mad4b.media-reference-remediation-final-qa.v1',
                'review_provenance'=>'inherited_ai_only',
                'evidence_scope'=>'tour_gallery_grid_vertical_and_wpml_local_media',
                'publishing_rights_certified'=>false,
                'media_metadata_modified_by_closure'=>false,
                'reviewed_at_utc'=>gmdate('c')]),
            'metadata'=>['site_uuid'=>$body['site_uuid'],
                'source_post_id'=>$body['source_post_id'],
                'media_plan_artifact_id'=>$body['expected_media_plan_artifact_id'],
                'frontend_report_sha256'=>$body['frontend_report_sha256'],
                'rights_status'=>'unverified',
                'scope'=>'media_references_only'],
            'producer_stage'=>'FINAL_QA',
            'producer_ref'=>'signed-host-media-close',
            'reason'=>'Verified media remediation with current local files, resolved alerts, and rollback record',
        ]);
        if(is_wp_error($proof))return $proof;
        $final_id=(string)($proof['artifact']['artifact_id']??'');
        if(!$final_id)return self::fail('mad4b_media_closure_final_artifact_missing');
        $changed=MAD4B_SCP_Content_Jobs::transition_job([
            'job_id'=>$body['job_id'],
            'expected_revision'=>$body['expected_job_revision'],
            'state'=>'COMPLETED','stage'=>'POST_PUBLISH',
            'reason'=>'Media references repaired across WPML and verified against current integrity scan and rollback proof',
            'plan_sha256'=>$body['frontend_report_sha256'],
            'artifact_id'=>$final_id
        ]);
        if(is_wp_error($changed))return $changed;
        if(($changed['job']['state']??'')!=='COMPLETED'
            ||($changed['job']['current_artifact_id']??'')!==$final_id)
            return self::fail('mad4b_media_closure_transition_readback_mismatch');
        return ['contract'=>self::CONTRACT,'status'=>'completed_verified',
            'job_id'=>$body['job_id'],'source_post_id'=>$body['source_post_id'],
            'job_revision'=>$changed['job']['job_revision'],
            'final_qa_artifact_id'=>$final_id,
            'resolved_media_alerts'=>$verified['resolved_media_alerts'],
            'verified_locales'=>array_keys($verified['verified_locales']),
            'rollback_token'=>$body['rollback_token'],
            'rights_verified'=>false,'site_uuid'=>$body['site_uuid']];
    }
}
