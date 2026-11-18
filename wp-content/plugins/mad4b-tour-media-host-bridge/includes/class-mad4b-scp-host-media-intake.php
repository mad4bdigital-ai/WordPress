<?php
defined('ABSPATH') || exit;

/**
 * Host-attested, site-bound Content Job intake for real Media Review.
 * Source integrity + WordPress original ContentJobs + immutable artifacts.
 * No automatic image approval, WP post update, or publication.
 */
final class MAD4B_SCP_Host_Media_Intake {
    const CONTRACT='mad4b.host-media-intake.v1';
    const LEDGER='mad4b_content_readiness_issues_v1';

    private static function fail($code,$message,$context=[]){
        return new WP_Error($code,$message,$context);
    }
    private static function existing($site,$tag) {
        global $wpdb;
        $tables=MAD4B_SCP_Schema::tables();
        $pattern='%'.$wpdb->esc_like($tag).'%';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT job_id FROM {$tables['content_jobs']}
             WHERE site_uuid=%s AND subject LIKE %s
             ORDER BY id ASC LIMIT 1",$site,$pattern),ARRAY_A);
        return is_array($row)?(string)$row['job_id']:'';
    }
    private static function evidence_for($source_id,$gallery_sha,$issue_sha){
        $ledger=(array)get_option(self::LEDGER,[]);
        $found=[];
        foreach($ledger as $issue_key=>$entry){
            if(!is_array($entry) ||
                ($entry['source']??'')!=='mad4b-media-origin-integrity' ||
                (int)($entry['object_id']??0)!==$source_id ||
                ($entry['status']??'')!=='open')continue;
            $r=$entry['content_job_request']??null;
            if(!is_array($r)||($r['contract']??'')!=='mad4b.media-integrity-contentjob-intake.v1'
               ||!hash_equals($gallery_sha,(string)($r['gallery_sha256']??''))
               ||!hash_equals($issue_sha,(string)($r['source_sha256']??'')))continue;
            $found[(string)$issue_key]=$entry;
        }
        return $found;
    }
    private static function link_alerts($id,$gallery,$issue_sha,$job_id){
        $ledger=(array)get_option(self::LEDGER,[]);
        $count=0;
        foreach($ledger as $key=>$issue){
            if(!is_array($issue) ||($issue['source']??'')!=='mad4b-media-origin-integrity'
                ||(int)($issue['object_id']??0)!==$id)continue;
            $req=$issue['content_job_request']??null;
            if(!is_array($req) || !hash_equals($gallery,(string)($req['gallery_sha256']??''))
               || !hash_equals($issue_sha,(string)($req['source_sha256']??'')))continue;
            $ledger[$key]['content_job_id']=$job_id;
            $ledger[$key]['content_job_request']['status']='LINKED';
            $ledger[$key]['content_job_request']['linked_job_id']=$job_id;
            $ledger[$key]['content_job_request']['linked_at']=gmdate('c');
            $count++;
        }
        if(!$count)return self::fail('mad4b_media_intake_source_drift',
            'No exact current Media Readiness alerts remain.');
        if(!update_option(self::LEDGER,$ledger,false)){
            $read=(array)get_option(self::LEDGER,[]);
            $match=count(array_filter($read,static fn($x)=>
                ($x['content_job_id']??'')===$job_id));
            if($match<$count)return self::fail('mad4b_media_intake_ledger_write_failed',
                'ContentJob created but alert ledger not linked. Reconcile before retry.',
                ['job_id'=>$job_id]);
        }
        return $count;
    }
    /**
     * Persist localized, human-reviewable visual SEO suggestions as one
     * original immutable SEO QA artifact. Never write attachment meta.
     */
    public static function propose_localized_alt(array $body) {
        $expected=['job_id','source_post_id','site_uuid','gallery_sha256',
            'featured_sha256','alt_en','alt_es','representation'];
        $actual=array_keys($body);
        sort($expected);sort($actual);
        if($expected!==$actual)
            return self::fail('mad4b_alt_proposal_schema_invalid',
                'Exact bounded localized Alt proposal required.');
        if(!MAD4B_SCP_Host_Agent_Bridge::active_for('mad4b/artifact-append'))
            return self::fail('mad4b_alt_proposal_host_grant_missing',
                'Signed, exact, scoped artifact append required.');
        $site=MAD4B_SCP_Host_Agent_Bridge::site();
        if(empty($site['site_uuid'])||!is_string($body['site_uuid'])
            ||!hash_equals((string)$site['site_uuid'],$body['site_uuid']))
            return self::fail('mad4b_alt_proposal_company_mismatch',
                'The signed company Site Profile is required.');
        if((string)(MAD4B_SCP_Context_Authority::review_policy()['mode']??'')!=='human_only')
            return self::fail('mad4b_alt_proposal_policy_mismatch',
                'This proposal is bound to the current human-only review lane.');
        $id=absint($body['source_post_id']);
        if($id<1||get_post_type($id)!=='tours-and-activities'
           ||get_post_status($id)!=='publish')
            return self::fail('mad4b_alt_proposal_source_invalid',
                'Exact existing published tour required.');
        $gallery=(string)$body['gallery_sha256'];
        $hash=(string)$body['featured_sha256'];
        if(!preg_match('/^[a-f0-9]{64}$/D',$gallery)
           ||!preg_match('/^[a-f0-9]{64}$/D',$hash)
           ||!hash_equals($gallery,hash('sha256',
                (string)get_post_meta($id,'gallery',true))))
            return self::fail('mad4b_alt_proposal_drift',
                'Tour metadata changed since proposal.');
        $job_id=(string)$body['job_id'];
        $j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
        if(is_wp_error($j))return $j;
        if(($j['job']['state']??'')!=='WAITING_REVIEW'
           ||!str_contains((string)($j['job']['subject']??''), get_the_title($id)))
            return self::fail('mad4b_alt_proposal_job_mismatch',
                'Only the existing exact tour Media Review job can accept this draft.');
        $trid=apply_filters('wpml_element_trid',null,$id,
            'post_tours-and-activities');
        $translations=apply_filters('wpml_get_element_translations',[],
            $trid,'post_tours-and-activities');
        if(!is_array($translations)||!isset($translations['en'],$translations['es']))
            return self::fail('mad4b_alt_proposal_wpml_family_missing',
                'Exact EN and ES WPML tour family required.');
        $ids=[];$sha=[];
        foreach(['en','es'] as $lang) {
            $post=(int)($translations[$lang]->element_id??0);
            $attachment=$post>0?get_post_thumbnail_id($post):0;
            $file=$attachment?get_attached_file($attachment):'';
            if(!$post ||!$attachment ||!is_file((string)$file)
               ||!str_starts_with((string)get_post_mime_type($attachment),'image/')
               ||trim((string)get_post_meta($attachment,'_wp_attachment_image_alt',true))!=='')
                return self::fail('mad4b_alt_proposal_feature_changed',
                    'Source media missing, unverified, or existing Alt Text must be preserved.');
            $ids[$lang]=['post_id'=>$post,'attachment_id'=>$attachment];
            $sha[$lang]=hash_file('sha256',$file);
        }
        if(!hash_equals($hash,$sha['en'])||!hash_equals($hash,$sha['es']))
            return self::fail('mad4b_alt_proposal_binary_mismatch',
                'Both translated Attachments must point to identical verified source bytes.');
        if($body['representation']!=='illustrative_composite_requires_review')
            return self::fail('mad4b_alt_proposal_visual_provenance_required',
                'Image provenance cannot be called a verified photograph.');
        $alt=[];
        foreach(['en','es'] as $lang){
            $v=trim((string)$body['alt_'.$lang]);
            if(mb_strlen($v)<45||mb_strlen($v)>165||wp_strip_all_tags($v)!==$v)
                return self::fail('mad4b_alt_proposal_text_invalid',
                    'Localized image descriptions must be short plain text.');
            $alt[$lang]=$v;
        }
        if(!str_starts_with($alt['en'],'Illustration of ')
            ||!str_starts_with($alt['es'],'Ilustración de '))
            return self::fail('mad4b_alt_proposal_visual_type_not_disclosed',
                'This particular composite must be described as an illustration.');
        $lock=MAD4B_SCP_Distributed_Lock::catalog_name(
            'alt-seo-proposal:'.$site['site_uuid'].':'.$job_id);
        $held=MAD4B_SCP_Distributed_Lock::acquire($lock);
        if(is_wp_error($held))return $held;
        try {
            $prior=MAD4B_SCP_Artifacts::list_artifacts([
                'job_id'=>$job_id,'artifact_type'=>'seo_qa']);
            if(is_wp_error($prior))return $prior;
            if(!empty($prior['items'])){
                foreach($prior['items'] as $item){
                    if(($item['status']??'')==='active')
                        return ['state'=>'already_proposed',
                            'job_id'=>$job_id,'artifact_id'=>$item['artifact_id'],
                            'review_required'=>true,'postmeta_modified'=>false];
                }
            }
            $audit=MAD4B_SCP_Provider_Field_Intelligence::locale_media_audit($id);
            if(empty($audit['complete'])
                ||(int)($audit['featured_alt_missing']??0)!==2)
                return self::fail('mad4b_alt_proposal_localization_drift',
                    'Live provider/WPML Alt audit no longer matches the plan.');
            $payload=[
                'contract'=>'mad4b.localized-media-seo-proposal.v1',
                'source_post_id'=>$id,'job_id'=>$job_id,
                'site_uuid'=>$site['site_uuid'],'gallery_sha256'=>$gallery,
                'image_sha256'=>$hash,
                'media_representation'=>$body['representation'],
                'photo_subject_evidence'=>'direct_visual_inspection_of_exact_bytes',
                'actual_photography_verified'=>false,
                'visual_subject'=>'promotional composite showing a yacht, leaping dolphins, snorkelers and coral reef',
                'locale_suggestions'=>[
                    'en'=>['post_id'=>$ids['en']['post_id'],
                        'attachment_id'=>$ids['en']['attachment_id'],
                        'alt_text_draft'=>$alt['en']],
                    'es'=>['post_id'=>$ids['es']['post_id'],
                        'attachment_id'=>$ids['es']['attachment_id'],
                        'alt_text_draft'=>$alt['es']],
                ],
                'provider_field_discovery'=>[
                    'wpml_alt_mode'=>$audit['wpml_alt_translation_mode'],
                    'attachment_wpml_translation_groups'=>$audit['featured_attachment_translation_groups'],
                    'rank_math_localized_metadata'=>true],
                'status'=>'pending_human_editorial_and_brand_approval',
                'actual_alt_text_written'=>false,
                'publication_authorized'=>false,
                'source_image_bytes_modified'=>false,
                'future_review_requirements'=>[
                    'verify_honest_illustration_label',
                    'verify_cultural_and_brand_tone',
                    'confirm_alt_is_relevant_to_page_and_photo',
                    'confirm EN_ES_translation',
                ],
            ];
            $saved=MAD4B_SCP_Artifacts::append_artifact([
                'job_id'=>$job_id,'artifact_type'=>'seo_qa',
                'payload'=>$payload,'metadata'=>[
                    'site_uuid'=>$site['site_uuid'],'source_post_id'=>$id,
                    'gallery_sha256'=>$gallery,'image_sha256'=>$hash,
                    'status'=>'review_required','approved'=>false,
                    'review_policy'=>'human_only',
                    'wp_user_id'=>0],
                'producer_stage'=>'SEO','producer_ref'=>'signed-host-localized-media-qa',
                'reason'=>'Visual inspection + localized Alt Text SEO draft; human review required',
            ]);
            if(is_wp_error($saved))return $saved;
            $record=$saved['artifact']??[];
            $rec=MAD4B_SCP_Audit::record('mad4b/host-localized-media-seo-proposed',[
                'job_id'=>$job_id,'source_post_id'=>$id,
                'image_sha256'=>$hash,'site_uuid'=>$site['site_uuid'],
                'alt_text_written'=>false,'brand_review_state'=>'pending_human',
                'principal'=>'signed_host_wpcli',
                'artifact_id'=>$record['artifact_id']??'']);
            if(is_wp_error($rec))
                return self::fail('mad4b_alt_proposal_receipt_missing',
                    'SEO QA artifact saved but Audit receipt requires reconciliation.',
                    ['job_id'=>$job_id]);
            return ['contract'=>'mad4b.localized-media-seo-proposal.v1',
                'state'=>'waiting_human_review','job_id'=>$job_id,
                'artifact_id'=>$record['artifact_id']??null,
                'languages'=>['en','es'],'source_attachment_ids'=>[
                    $ids['en']['attachment_id'],$ids['es']['attachment_id']],
                'alt_text_written'=>false,'media_modified'=>false,
                'review_policy'=>'human_only','image_sha256'=>$hash];
        } finally {MAD4B_SCP_Distributed_Lock::release($lock);}
    }

    public static function execute(array $body) {
        $allowed=['source_post_id','expected_gallery_sha256',
            'expected_issue_sha256','expected_site_uuid'];
        $keys=array_keys($body);
        if(count($keys)!==4 || array_diff($keys,$allowed))
            return self::fail('mad4b_media_intake_schema_invalid',
                'Only exact bounded Media Intake fields are allowed.');
        $host=MAD4B_SCP_Host_Agent_Bridge::site();
        foreach(['mad4b/content-job-create','mad4b/artifact-append',
            'mad4b/content-job-transition'] as $cap)
            if(!MAD4B_SCP_Host_Agent_Bridge::active_for($cap))
                return self::fail('mad4b_media_intake_grant_missing',
                    'Exact signed-Host ContentJob grants required.', ['ability'=>$cap]);
        $site=(string)($host['site_uuid']??'');
        if(!$site || !hash_equals($site,(string)$body['expected_site_uuid']))
            return self::fail('mad4b_media_intake_wrong_site',
                'The signed request targets a different company.');
        $id=absint($body['source_post_id']);
        if($id<1 ||get_post_type($id)!=='tours-and-activities'
           ||get_post_status($id)!=='publish')
            return self::fail('mad4b_media_intake_tour_missing',
                'Published original tour is required.');
        // MySQL advisory mutex is bound to the actual WordPress writer
        // connection, protecting the readback/create/link CAS interval even
        // when distinct remote hosts submit the same source concurrently.
        $lock=MAD4B_SCP_Distributed_Lock::catalog_name(
            'host-media-intake:'.$site.':'.$id);
        $acquired=MAD4B_SCP_Distributed_Lock::acquire($lock);
        if(is_wp_error($acquired))return $acquired;
        try {
        $details=apply_filters('wpml_post_language_details',null,$id);
        $lang=sanitize_key((string)(is_array($details)?
            ($details['language_code']??'en'):'en'));
        $default=sanitize_key((string)apply_filters('wpml_default_language','en'));
        $canonical=(int)apply_filters('wpml_object_id',$id,'tours-and-activities',false,$default);
        if($canonical>0 &&$canonical!==$id)
            return self::fail('mad4b_media_intake_requires_canonical',
                'The existing canonical source post must own the shared ContentJob.',
                ['canonical_post_id'=>$canonical]);
        $gallery=(string)$body['expected_gallery_sha256'];
        $issue_sha=(string)$body['expected_issue_sha256'];
        if(!preg_match('/^[a-f0-9]{64}$/D',$gallery)
            ||!preg_match('/^[a-f0-9]{64}$/D',$issue_sha)
            ||!hash_equals($gallery,hash('sha256',(string)get_post_meta($id,'gallery',true))))
            return self::fail('mad4b_media_intake_gallery_drift',
                'Current Gallery SHA does not match the exact Intake request.');
        $evidence=self::evidence_for($id,$gallery,$issue_sha);
        if(!$evidence)
            return self::fail('mad4b_media_intake_no_matching_alert',
                'No current registered Readiness Intake with this SHA.');
        $brand=MAD4B_SCP_Context_Authority::profile();
        $scope=MAD4B_SCP_Operational_Integrity::capture();
        if(is_wp_error($scope))return $scope;
        if(!hash_equals($site,(string)$scope['scope']['site_uuid'])
           ||!hash_equals((string)$scope['scope']['brand_ref'],
               (string)($brand['brand_id']??'')))
            return self::fail('mad4b_media_intake_brand_scope_mismatch',
                'The source and Brand Profile must belong to the same company.');
        $tag='[MAD4B_MEDIA_'.substr(hash('sha256',$site.'|'.$id.'|'.$issue_sha),0,24).']';
        $original=self::existing($site,$tag);
        if($original){
            $linked=self::link_alerts($id,$gallery,$issue_sha,$original);
            if(is_wp_error($linked))return $linked;
            return ['contract'=>self::CONTRACT,'state'=>'already_registered',
                'job_id'=>$original,'source_post_id'=>$id,'linked_issues'=>$linked,
                'publication_authorized'=>false,'post_modified'=>false];
        }
        $review_plan=MAD4B_Media_Origin_Integrity_V1::review_plan($id);
        if(empty($review_plan['complete'])
           ||!hash_equals($gallery,(string)($review_plan['source_gallery_sha256']??'')))
            return self::fail('mad4b_media_intake_review_plan_unavailable',
                'Live site-specific Media Review plan not valid.');
        $brand_context=MAD4B_SCP_Brand_Experience_Quality::brand_context();
        $media_policy=class_exists('MAD4B_Media_Review_Hierarchy_V1',false)
            ?MAD4B_Media_Review_Hierarchy_V1::effective():[];
        $decision_mode=!empty($media_policy['policy_ready'])
            ?(string)$media_policy['mode']
            :(string)(MAD4B_SCP_Context_Authority::review_policy()['mode']??'human_only');
        $title=wp_strip_all_tags(get_the_title($id));
        $subject=$tag.' Review and rebuild tour Gallery/Grid/Vertical media: '
            .mb_substr($title,0,190);
        $job=MAD4B_SCP_Content_Jobs::create_job([
            'brand_id'=>(string)$brand['brand_id'],'subject'=>$subject,
            'primary_keyword'=>'tour visual relevance and local media',
            'language'=>$lang,'country'=>$lang==='es'?'es':'us',
            'content_type'=>'media_review','research_depth'=>'source_verified',
            'automation_level'=>'autonomous','target_post_type'=>'tours-and-activities',
            'reason'=>'Signed Host intake of verified Media Readiness alerts; exact original source and Brand Context.'
        ]);
        if(is_wp_error($job))return $job;
        $new=$job['job']??[];
        $job_id=(string)($new['job_id']??'');
        if(!preg_match('/^[a-f0-9-]{36}$/D',$job_id))
            return self::fail('mad4b_media_intake_job_readback_missing',
                'Native job creation outcome requires reconciliation.');
        $artifacts=[
          ['type'=>'fact_ledger','stage'=>'FACT_QA','payload'=>[
              'source_post_id'=>$id,'source_title'=>$title,
              'source_url'=>get_permalink($id),
              'site_uuid'=>$site,'brand_id'=>$brand['brand_id'],
              'gallery_sha256'=>$gallery,'media_issue_sha256'=>$issue_sha,
              'issue_codes'=>array_values(array_unique(array_column($evidence,'code'))),
              'source_quality'=>'readiness_ledger_source_verified',
              'image_subject_verified'=>false]],
          ['type'=>'context_pack','stage'=>'SITE_DISCOVERY','payload'=>[
              'brand_id'=>$brand['brand_id'],
              'brand_authority_level'=>$brand_context['authority_level']??'provisional',
              'brand_evidence_sha256'=>$brand_context['brand_evidence_sha256']??'',
              'brand_core_approved'=>($brand_context['authority_level']??'')==='approved_brand_context',
              'review_mode_inherited'=>$decision_mode,
              'publication_permission'=>false]],
          ['type'=>'media_plan','stage'=>'MEDIA','payload'=>$review_plan],
          ['type'=>'editorial_qa','stage'=>'EDITORIAL_QA','payload'=>[
              'status'=>'awaiting_visual_and_brand_review',
              'review_mode'=>$decision_mode,
              'source_post_id'=>$id,'expected_gallery_sha256'=>$gallery,
              'required_review'=>[
                  'real_image_subject_identification',
                  'tour_itinerary_relevance','hero_and_grid_selection',
                  'wpml_parity','mobile_image_aspect_ratio'],
              'automatic_image_replacement'=>false]],
        ];
        $artifact_ids=[];
        foreach($artifacts as $item){
            $saved=MAD4B_SCP_Artifacts::append_artifact([
                'job_id'=>$job_id,'artifact_type'=>$item['type'],
                'payload'=>$item['payload'],'metadata'=>[
                    'site_uuid'=>$site,'source_post_id'=>$id,
                    'source_gallery_sha256'=>$gallery,'review_mode'=>$decision_mode,
                    'no_publication'=>true],
                'producer_stage'=>$item['stage'],
                'producer_ref'=>'signed-host-media-intake',
                'reason'=>'Original immutable Media Review evidence for tour '.$id]);
            if(is_wp_error($saved))
                return self::fail('mad4b_media_intake_partial_artifacts',
                    'Native job created; artifact write needs exact reconciliation.',
                    ['job_id'=>$job_id,'failed_type'=>$item['type'],
                     'original_error'=>$saved->get_error_code()]);
            $rec=$saved['artifact']??[];
            $artifact_ids[$item['type']]=$rec['artifact_id']??'';
        }
        foreach([
          ['state'=>'QUEUED','stage'=>'SITE_DISCOVERY',
            'reason'=>'Verified company, original tour, gallery SHA and Brand Context.'],
          ['state'=>'RUNNING','stage'=>'MEDIA',
            'reason'=>'Media Review Plan and immutable evidence artifacts prepared.'],
          ['state'=>'WAITING_REVIEW','stage'=>'MEDIA',
            'reason'=>'Waiting for visual subject assessment, Brand policy review and WPML-safe replacement selection.']
        ] as $step){
            $curr=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
            $rec=$curr['job']??[];
            if(is_wp_error($curr)||!isset($rec['job_revision']))
                return self::fail('mad4b_media_intake_job_readback_failed',
                    'Native job created but transition readback failed.',['job_id'=>$job_id]);
            $next=MAD4B_SCP_Content_Jobs::transition_job([
                'job_id'=>$job_id,'expected_revision'=>(int)$rec['job_revision'],
                'state'=>$step['state'],'stage'=>$step['stage'],
                'reason'=>$step['reason'],'artifact_id'=>$artifact_ids['media_plan']??''
            ]);
            if(is_wp_error($next))
                return self::fail('mad4b_media_intake_job_transition_failed',
                    'Original Job remains; requires transition reconciliation.',
                    ['job_id'=>$job_id,'cause'=>$next->get_error_code()]);
        }
        $linked=self::link_alerts($id,$gallery,$issue_sha,$job_id);
        if(is_wp_error($linked))return $linked;
        $audit=MAD4B_SCP_Audit::record('mad4b/host-media-intake-created',[
            'site_uuid'=>$site,'source_post_id'=>$id,'job_id'=>$job_id,
            'source_gallery_sha256'=>$gallery,'source_media_sha256'=>$issue_sha,
            'artifact_ids'=>$artifact_ids,'intake_linked'=>$linked,
            'publication_authorized'=>false,'image_replacement_performed'=>false,
            'review_mode'=>$decision_mode,'principal'=>'signed_host_wpcli',
        ]);
        if(is_wp_error($audit))
            return self::fail('mad4b_media_intake_audit_receipt_missing',
                'Durable job exists, but Host receipt needs reconciliation.',
                ['job_id'=>$job_id,'cause'=>$audit->get_error_code()]);
        $read=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
        return ['contract'=>self::CONTRACT,'state'=>'waiting_review',
            'job_id'=>$job_id,'source_post_id'=>$id,'canonical_post_id'=>$id,
            'job_state'=>$read['job']['state']??null,
            'job_revision'=>$read['job']['job_revision']??null,
            'artifact_ids'=>$artifact_ids,'linked_issues'=>$linked,
            'review_mode_inherited'=>$decision_mode,
            'brand_authority_level'=>$brand_context['authority_level']??'provisional',
            'publication_authorized'=>false,'image_replacement_performed'=>false,
        ];
        } finally {
            MAD4B_SCP_Distributed_Lock::release($lock);
        }
    }
}
