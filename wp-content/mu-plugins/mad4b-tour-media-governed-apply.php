<?php
/**
 * Plugin Name: MAD4B Governed Tour Media Apply
 * Description: Host-WPCLI-only, review-policy-bound media write and reversible WordPress/WPML CAS.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Tour_Media_Governed_Apply_V1 {
    const CONTRACT='mad4b.tour-media-governed-apply.v1';
    const BACKUP_PREFIX='mad4b_media_apply_backup_v1_';

    private static function error($code,$msg='The requested media operation cannot proceed.') {
        return new WP_Error($code,$msg);
    }
    private static function cli_ready():bool {
        return defined('WP_CLI') && WP_CLI && php_sapi_name()==='cli'
            && !get_current_user_id();
    }
    private static function inspect(array $m) {
        $need=['contract','site_uuid','canonical_post_id','expected_plan_fingerprint',
            'featured_id','gallery_ids','grid_ids','vertical_id','localized_alt',
            'review'];
        $given=array_keys($m);sort($given);sort($need);
        if($given!==$need ||($m['contract']??'')!==self::CONTRACT)
            return self::error('mad4b_media_apply_schema_invalid');
        $site=MAD4B_SCP_Site_Profile::status();
        if(empty($site['site_uuid'])||empty($site['origin_match'])
            ||empty($site['environment_match'])||empty($site['deployment_binding_match'])
            ||!is_string($m['site_uuid'])||$site['site_uuid']!==$m['site_uuid'])
            return self::error('mad4b_media_apply_site_binding_invalid');
        $id=absint($m['canonical_post_id']);
        $plan=MAD4B_Tour_Media_Reconcile_V1::plan($id,['limit'=>24]);
        if(is_wp_error($plan))return $plan;
        if(!is_string($m['expected_plan_fingerprint'])
           ||!hash_equals($plan['plan_fingerprint'],$m['expected_plan_fingerprint']))
            return self::error('mad4b_media_apply_plan_drift');
        $review=MAD4B_SCP_Context_Authority::review_policy();
        $mode=(string)($review['mode']??'human_only');
        $actor=(array)$m['review'];
        $expectedReview=['method','contact_sheet_sha256','representation','provenance_status','notes'];
        $rkeys=array_keys($actor);sort($rkeys);sort($expectedReview);
        if($rkeys!==$expectedReview || ($actor['method']??'')!=='ai_visual_review'
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($actor['contact_sheet_sha256']??''))
            || ($actor['representation']??'')!=='photographic_appearance'
            || ($actor['provenance_status']??'')!=='unverified'
            ||mb_strlen((string)($actor['notes']??''))<30)
            return self::error('mad4b_media_apply_review_evidence_invalid');
        $gallery=$m['gallery_ids'];
        $grid=$m['grid_ids'];
        $featured=(int)$m['featured_id'];
        $vertical=(int)$m['vertical_id'];
        if(!is_array($gallery)||!array_is_list($gallery)||count($gallery)<4||count($gallery)>12
            ||count(array_unique($gallery))!==count($gallery)
            ||!is_array($grid)||!array_is_list($grid)||count($grid)!==4
            ||count(array_unique($grid))!==count($grid)
            ||!in_array($featured,$gallery,true)
            ||array_diff($grid,$gallery)
            )
            return self::error('mad4b_media_apply_selection_invalid');
        foreach(array_merge($gallery,$grid,[$featured]) as $aid)
            if(!is_int($aid)||$aid<1)return self::error('mad4b_media_apply_attachment_id_invalid');
        $entries=$plan['candidate_catalog']['items'];
        $by_id=[];
        foreach($entries as $item)$by_id[(int)$item['canonical_attachment_id']]=$item;
        foreach($gallery as $aid)
            if(!isset($by_id[$aid]))
                return self::error('mad4b_media_apply_not_verified_tour_candidate');
        if($vertical!==0 && $by_id[$vertical]['aspect_ratio']>1.0)
            return self::error('mad4b_media_apply_vertical_requires_portrait');
        $family=$plan['wpml_post_family'];
        $fields=$plan['provider_fields'];
        if(!isset($fields['gallery'])||($fields['gallery']['value_format']??'')!=='url')
            return self::error('mad4b_media_apply_gallery_contract_missing');
        $gridFields=array_values(array_filter(array_keys($fields),
            static fn($k)=>str_starts_with($k,'grid_image_')));
        sort($gridFields,SORT_STRING);
        if(count($gridFields)!==4)return self::error('mad4b_media_apply_grid_contract_unexpected');
        foreach($gridFields as $field)
            if(($fields[$field]['value_format']??'')!=='url')
                return self::error('mad4b_media_apply_grid_format_invalid');
        $alts=(array)$m['localized_alt'];
        $expectedLang=array_keys($family);sort($expectedLang);
        $providedLang=array_keys($alts);sort($providedLang);
        if($expectedLang!==$providedLang)
            return self::error('mad4b_media_apply_wpml_locale_coverage_incomplete');
        $selected=array_values(array_unique($gallery));
        $updates=[];$ids=[];
        foreach($family as $lang=>$pid){
            $localeAlts=$alts[$lang];
            if(!is_array($localeAlts)||count($localeAlts)!==count($gallery))
                return self::error('mad4b_media_apply_alt_coverage_incomplete');
            $ref=array_map('strval',$gallery);
            $provided=array_map('strval',array_keys($localeAlts));sort($ref);sort($provided);
            if($ref!==$provided)
                return self::error('mad4b_media_apply_alt_selection_mismatch');
            $localized=[];
            foreach($gallery as $canonical){
                $attachment=(int)($by_id[$canonical]['locale_attachments'][$lang]['attachment_id']??0);
                if(!$attachment ||get_post_type($attachment)!=='attachment')
                    return self::error('mad4b_media_apply_wpml_attachment_missing');
                $f=get_attached_file($attachment);
                if(!is_file((string)$f) ||
                  !hash_equals((string)$by_id[$canonical]['image_sha256'],hash_file('sha256',$f)))
                    return self::error('mad4b_media_apply_attachment_hash_drift');
                $url=wp_get_attachment_url($attachment);
                if(!is_string($url)||!str_starts_with($url,'https://'))
                    return self::error('mad4b_media_apply_invalid_attachment_url');
                $localized[$canonical]=['id'=>$attachment,'url'=>$url];
                $alt=(string)$localeAlts[(string)$canonical];
                if(mb_strlen($alt)<30||mb_strlen($alt)>190
                    ||wp_strip_all_tags($alt)!==$alt||str_contains($alt,'http'))
                    return self::error('mad4b_media_apply_alt_text_invalid');
                $old=(string)get_post_meta($attachment,'_wp_attachment_image_alt',true);
                $post_title=(string)get_the_title($pid);
                $canonical_title=(string)get_the_title($id);
                if($old!==''&&$old!==$post_title &&$old!==$canonical_title
                   && $old!==$alt)
                    return self::error('mad4b_media_apply_existing_editorial_alt_preserved');
                $updates[$attachment.':_wp_attachment_image_alt']=[
                    'post_id'=>$attachment,'field'=>'_wp_attachment_image_alt',
                    'old'=>$old,'new'=>$alt,'target'=>'attachment_alt'];
                $ids[$attachment]=true;
            }
            // A portrait crop may be a tour-local verified derivative used
            // exclusively by Vertical, without duplicating it in Gallery.
            if($vertical!==0 &&!isset($localized[$vertical])){
                $v=(int)($by_id[$vertical]['locale_attachments'][$lang]['attachment_id']??0);
                $vf=$v?get_attached_file($v):false;
                $vu=$v?wp_get_attachment_url($v):false;
                if(!$v ||!is_file((string)$vf)
                  ||!hash_equals($by_id[$vertical]['image_sha256'],hash_file('sha256',$vf))
                  ||!is_string($vu)||!str_starts_with($vu,'https://')
                  ||mb_strlen(trim((string)get_post_meta($v,'_wp_attachment_image_alt',true)))<30)
                    return self::error('mad4b_media_apply_vertical_image_or_alt_invalid');
                $localized[$vertical]=['id'=>$v,'url'=>$vu];
            }
            // Gallery only contains the explicitly selected Gallery IDs.
            // A separately generated Vertical portrait must never leak into
            // the Gallery payload merely because it shares the URL lookup.
            $gallery_urls=[];
            foreach($gallery as $gallery_id)$gallery_urls[]=$localized[$gallery_id]['url'];
            $newMeta=['gallery'=>implode(',',$gallery_urls)];
            foreach($gridFields as $i=>$field)
                $newMeta[$field]=$localized[$grid[$i]]['url'];
            if($vertical!==0 &&isset($fields['image_vertical']))
                $newMeta['image_vertical']=$localized[$vertical]['url'];
            $newMeta['_thumbnail_id']=(string)$localized[$featured]['id'];
            foreach($newMeta as $key=>$value) {
                $old=(string)get_post_meta($pid,$key,true);
                if(isset($plan['source_field_snapshots'][$lang][$key]['sha256']) &&
                   !hash_equals($plan['source_field_snapshots'][$lang][$key]['sha256'],
                     hash('sha256',$old)))
                    return self::error('mad4b_media_apply_meta_snapshot_drift');
                $updates[$pid.':'.$key]=[
                    'post_id'=>$pid,'field'=>$key,'old'=>$old,'new'=>$value,'target'=>'tour_media'];
            }
            $ids[$pid]=true;
        }
        $changed=array_values(array_filter($updates,static fn($x)=>$x['new']!==$x['old']));
        return [
            'contract'=>self::CONTRACT,'plan'=>$plan,'review_mode'=>$mode,
            'company'=>$site['site_uuid'],'source_post_id'=>$id,
            'gallery_ids'=>$gallery,'featured_id'=>$featured,'grid_ids'=>$grid,
            'vertical_id'=>$vertical,
            'target_locales'=>array_keys($family),'changes'=>$changed,
            'affected_posts'=>array_keys($ids),
            'review_evidence'=>$actor,
            'vertical_pending'=>$vertical===0,
            'approval_permits_apply'=>$mode==='ai_only',
            'image_originality_verified'=>false,
            'rights_verified'=>false,
            'source_preserved_until_apply'=>true
        ];
    }
    private static function update_rows(array $changes,bool $reverse):void {
        global $wpdb;
        foreach($changes as $change) {
            $old=$reverse?$change['new']:$change['old'];
            $new=$reverse?$change['old']:$change['new'];
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT meta_id,meta_value FROM {$wpdb->postmeta}
                 WHERE post_id=%d AND meta_key=%s FOR UPDATE",
                $change['post_id'],$change['field']),ARRAY_A);
            if(!is_array($rows)||count($rows)!==1
                ||!hash_equals((string)$rows[0]['meta_value'],(string)$old))
                throw new RuntimeException('mad4b_media_apply_row_conflict:'.$change['post_id'].':'.$change['field']);
            $n=$wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value=%s
                 WHERE meta_id=%d AND BINARY meta_value = BINARY %s",
                $new,(int)$rows[0]['meta_id'],$old));
            if($n!==1)throw new RuntimeException('mad4b_media_apply_cas_update_failed');
        }
    }
    private static function refresh(array $ids):void {
        foreach($ids as $id){
            wp_cache_delete((int)$id,'post_meta');
            clean_post_cache((int)$id);
        }
        do_action('mad4b/tour_media_governed_apply/invalidated',$ids);
    }
    private static function apply(array $m) {
        global $wpdb;
        $prepared=self::inspect($m);
        if(is_wp_error($prepared))return $prepared;
        if(!$prepared['approval_permits_apply'])
            return self::error('mad4b_media_apply_review_policy_not_satisfied',
                'Inherited human-only review requires a separate exact human approval receipt.');
        $id=$prepared['source_post_id'];
        $site=$prepared['company'];
        $lock=MAD4B_SCP_Distributed_Lock::catalog_name('media-governed-apply:'.$site.':'.$id);
        $held=MAD4B_SCP_Distributed_Lock::acquire($lock);
        if(is_wp_error($held))return $held;
        try {
            $fresh=self::inspect($m);
            if(is_wp_error($fresh))return $fresh;
            if($fresh['changes']!==$prepared['changes'])
                return self::error('mad4b_media_apply_concurrent_change');
            $token=wp_generate_uuid4();
            $option=self::BACKUP_PREFIX.str_replace('-','',$token);
            $backup=[
                'contract'=>'mad4b.tour-media-rollback.v1','token'=>$token,
                'site_uuid'=>$site,'source_post_id'=>$id,
                'plan_fingerprint'=>$prepared['plan']['plan_fingerprint'],
                'changes'=>$prepared['changes'],'affected_posts'=>$prepared['affected_posts'],
                'written_at'=>gmdate('c'),'review_mode'=>$prepared['review_mode'],
                'review_evidence'=>$m['review'],'status'=>'applied',
            ];
            $started=$wpdb->query('START TRANSACTION');
            if($started===false)return self::error('mad4b_media_apply_transaction_failed');
            try{
                if(!add_option($option,$backup,'','no'))
                    throw new RuntimeException('mad4b_media_apply_backup_write_failed');
                self::update_rows($prepared['changes'],false);
                if($wpdb->query('COMMIT')===false)
                    throw new RuntimeException('mad4b_media_apply_commit_failed');
            } catch(Throwable $e){
                $wpdb->query('ROLLBACK');
                self::refresh($prepared['affected_posts']);
                return self::error('mad4b_media_apply_transaction_rolled_back',$e->getMessage());
            }
            self::refresh($prepared['affected_posts']);
            $audit=MAD4B_SCP_Audit::record('mad4b/tour-media-governed-applied',[
                'site_uuid'=>$site,'post_id'=>$id,'rollback_token'=>$token,
                'review_source'=>'ai_visual_review_under_site_policy',
                'review_evidence_sha256'=>$m['review']['contact_sheet_sha256'],
                'candidate_gallery'=>$prepared['gallery_ids'],
                'site_languages'=>$prepared['target_locales'],
                'changed_rows'=>count($prepared['changes']),
                'vertical_pending'=>$prepared['vertical_pending'],
                'real_photography_verified'=>false,'photo_rights_verified'=>false,
                'principal'=>'authorized_root_host_wpcli_no_user_impersonation'
            ]);
            return [
                'contract'=>self::CONTRACT,'status'=>'applied_pending_frontend_verification',
                'rollback_token'=>$token,'changed_rows'=>count($prepared['changes']),
                'site_uuid'=>$site,'source_post_id'=>$id,
                'wpml_locales'=>$prepared['target_locales'],
                'gallery_ids'=>$prepared['gallery_ids'],
                'vertical_pending'=>$prepared['vertical_pending'],
                'audit_receipt_ok'=>!is_wp_error($audit),
                'originality_verified'=>false
            ];
        } finally {
            MAD4B_SCP_Distributed_Lock::release($lock);
        }
    }
    private static function rollback($token) {
        global $wpdb;
        if(!preg_match('/^[a-f0-9-]{36}$/D',(string)$token))
            return self::error('mad4b_media_rollback_token_invalid');
        $option=self::BACKUP_PREFIX.str_replace('-','',$token);
        $backup=get_option($option,false);
        if(!is_array($backup)||($backup['contract']??'')!=='mad4b.tour-media-rollback.v1')
            return self::error('mad4b_media_rollback_not_found');
        if($backup['site_uuid']!==MAD4B_SCP_Site_Profile::site_uuid())
            return self::error('mad4b_media_rollback_wrong_company');
        if(($backup['status']??'')==='rolled_back')
            return ['status'=>'already_rolled_back','rollback_token'=>$token];
        $lock=MAD4B_SCP_Distributed_Lock::catalog_name(
            'media-governed-apply:'.$backup['site_uuid'].':'.$backup['source_post_id']);
        $held=MAD4B_SCP_Distributed_Lock::acquire($lock);
        if(is_wp_error($held))return $held;
        try{
            if($wpdb->query('START TRANSACTION')===false)
                return self::error('mad4b_media_rollback_transaction_failed');
            try {
                self::update_rows($backup['changes'],true);
                $backup['status']='rolled_back';
                $backup['rolled_back_at']=gmdate('c');
                if(!update_option($option,$backup,false))
                    throw new RuntimeException('mad4b_media_rollback_receipt_failed');
                if($wpdb->query('COMMIT')===false)
                    throw new RuntimeException('mad4b_media_rollback_commit_failed');
            }catch(Throwable $e){
                $wpdb->query('ROLLBACK');
                self::refresh($backup['affected_posts']);
                return self::error('mad4b_media_rollback_failed',$e->getMessage());
            }
            self::refresh($backup['affected_posts']);
            $audit=MAD4B_SCP_Audit::record('mad4b/tour-media-governed-rollback',[
                'site_uuid'=>$backup['site_uuid'],
                'source_post_id'=>$backup['source_post_id'],
                'rollback_token'=>$token,
                'reverted_rows'=>count($backup['changes']),
                'audit_actor'=>'root_host_wpcli'
            ]);
            return ['status'=>'rolled_back','rollback_token'=>$token,
                'reverted_rows'=>count($backup['changes']),
                'audit_receipt_ok'=>!is_wp_error($audit)];
        }finally{MAD4B_SCP_Distributed_Lock::release($lock);}
    }
    public static function cli($args,$assoc) {
        if(!self::cli_ready()||$args||array_diff(array_keys($assoc),['apply','rollback']))
            WP_CLI::error('Only host-controlled WP-CLI without web session or WordPress --user is supported.');
        if(isset($assoc['rollback'])){
            $r=self::rollback((string)$assoc['rollback']);
        }else{
            $s=stream_get_contents(STDIN,65537);
            if(!is_string($s)||strlen($s)>65536)
                WP_CLI::error('Missing/oversized bounded JSON media manifest');
            $m=json_decode($s,true);
            if(!is_array($m))WP_CLI::error('Invalid media manifest JSON');
            $r=isset($assoc['apply'])?self::apply($m):self::inspect($m);
        }
        if(is_wp_error($r))WP_CLI::error($r->get_error_code().':'.$r->get_error_message());
        if(!isset($assoc['apply'])&&!isset($assoc['rollback'])){
            unset($r['changes'],$r['plan']);
            $r['status']=$r['approval_permits_apply']?'dry_run_ready':'review_policy_blocks_apply';
        }
        WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
}
if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b media-governed-apply',[MAD4B_Tour_Media_Governed_Apply_V1::class,'cli']);

