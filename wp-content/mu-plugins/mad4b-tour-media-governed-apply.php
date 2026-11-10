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
    /** Recognize repeated short tour labels, not unique descriptive Alt. */
    private static function legacy_import_alt_key(string $raw):string {
        $value=trim(mb_strtolower(html_entity_decode(
            wp_strip_all_tags($raw),ENT_QUOTES|ENT_HTML5,'UTF-8')));
        // Generic tour titles may be longer in Spanish, Italian or Arabic
        // and can be copied into 3+ different physical image records.
        // Preserve single-image editorial Alt by requiring a repeated key.
        if(mb_strlen($value)<12 || mb_strlen($value)>230)return '';
        $words=preg_split('/[^\p{L}\p{N}]+/u',$value,-1,PREG_SPLIT_NO_EMPTY);
        if(!is_array($words)||count($words)<2||count($words)>36)return '';
        $generic=['tour','tours','trip','trips','cruise','cruises',
            'excursion','excursión','excursions','visita','reise',
            'ausflug','gita','رحلة','جولة','viajes','viaje',
            'escursione','escursioni','crucero','cruceros',
            'sound','espectáculo','spettacolo'];
        // A tour-name token is required even when the title starts with
        // Coptic, Full-Day, Egyptian, Nile or another destination name.
        // Repetition across separate image hashes is checked by the caller.
        return array_intersect($words,$generic)?implode(' ',$words):'';
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
        // Media photo review is separately inheritable from Company
        // Management. Do not change Brand Context general approval grants.
        // No synced exact policy means HOLD, not implicit AI permission.
        $media_policy=class_exists('MAD4B_Media_Review_Hierarchy_V1',false)
            ?MAD4B_Media_Review_Hierarchy_V1::effective():[];
        if(empty($media_policy['policy_ready']))
            return self::error('mad4b_media_policy_inheritance_unready');
        $mode=(string)($media_policy['mode']??'human_only');
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
        // A cropped WPML attachment is not a new photograph. Prevent NEW
        // Featured/card selections from reusing an original already featured
        // by another published tour family. Existing selections stay
        // editable for repair, but the read-only audit still reports them.
        if(!class_exists('MAD4B_Media_Visual_Lineage_V1',false))
            return self::error('mad4b_media_visual_lineage_guard_missing');
        if((int)get_post_thumbnail_id($id)!==$featured){
            $visual=MAD4B_Media_Visual_Lineage_V1::guard_featured($id,$featured);
            if(empty($visual['allowed']))
                return self::error('mad4b_media_featured_visual_origin_reused',
                    'The proposed Featured photo is not independently unique: '.
                    (string)($visual['reason']??'verification_failed'));
        }
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
        // An old import may apply the SAME short tour-title Alt to 3+
        // distinct photographs. That is a repeated category label, not
        // a hand-written per-image description. Require separate image hashes
        // and an explicit generic travel word; never overwrite a unique
        // image-specific editorial description.
        $legacy_generic_alt_images=[];
        foreach($gallery as $candidate){
            $imgsha=(string)($by_id[$candidate]['image_sha256']??'');
            if(!preg_match('/^[a-f0-9]{64}$/D',$imgsha))continue;
            foreach($family as $locale=>$post_id){
                $local_id=(int)($by_id[$candidate]['locale_attachments'][$locale]['attachment_id']??0);
                if(!$local_id)continue;
                $key=self::legacy_import_alt_key(
                    (string)get_post_meta($local_id,'_wp_attachment_image_alt',true));
                if($key!=='')$legacy_generic_alt_images[$key][$imgsha]=true;
            }
        }
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
                // Imported media often inherit an abbreviated tour title
                // rather than an image description (sometimes the EN title
                // in every WPML locale). Only replace these demonstrable
                // generic title prefixes; preserve meaningful editorial Alt.
                $decode=static fn($v)=>trim(html_entity_decode(
                    (string)$v,ENT_QUOTES|ENT_HTML5,'UTF-8'));
                $old_normalized=$decode($old);
                $local_title_normalized=$decode($post_title);
                $canonical_title_normalized=$decode($canonical_title);
                // Near-identical translated tour-title variants must not
                // be treated as original image descriptions. Example:
                // "Tour Piramidi di Giza di Mezza Giornata" vs
                // "Tour Piramidi di Giza e Sfinge di Mezza Giornata".
                $similarity=0.0;
                similar_text($old_normalized,$local_title_normalized,$similarity);
                $generic_title_alt=mb_strlen($old_normalized)>=18 && (
                    mb_stripos($local_title_normalized,$old_normalized)===0 ||
                    mb_stripos($canonical_title_normalized,$old_normalized)===0 ||
                    (mb_strlen($old_normalized)>=22 && $similarity>=80.0)
                );
                $legacy_key=self::legacy_import_alt_key($old_normalized);
                $legacy_first=$legacy_key!=='' ? strtok($legacy_key,' ') : '';
                $legacy_clear_prefix=in_array($legacy_first,[
                    'tour','tours','trip','trips','excursion','excursión',
                    'viaje','viajes','sound','espectáculo','spettacolo'],true);
                // Longer imported itinerary labels need five physically
                // distinct images with the exact same Alt, not a guess based
                // on a single title match. Unique editorial Alt stays intact.
                $legacy_repeated_generic=$legacy_key!=='' &&
                    count($legacy_generic_alt_images[$legacy_key]??[])>=
                        ($legacy_clear_prefix?3:5);
                // Imported image slug plus appended tour title is not
                // photo-specific editorial Alt; it is an unedited machine
                // filename and can be replaced after bounded visual review.
                $machine_slug_alt=(mb_strlen($old_normalized)>120
                    &&preg_match('/^(?:[a-z0-9]+-){8,}/i',$old_normalized))
                    // Imported image filename labels like 5033-luxor-tombs-egypt
                    // or Mortuary_Temple_of_Hatshepsut are NOT authored Alt.
                    // Require token separators and no natural-language spaces.
                    || (!str_contains($old_normalized,' ')
                        && preg_match('/^(?:[\p{L}\p{N}]+[-_]){2,}[\p{L}\p{N}_,.-]+$/u',$old_normalized))
                    // The site-wide template label is generic, not photography.
                    || in_array(mb_strtolower(trim($old_normalized)),
                        ['egypt tours, nile cruises trips'],true)
                    // Legacy shared uploads can inherit these importer labels,
                    // falsely describing a different tour altogether. This
                    // exact set is visually reviewed and bounded to this migration;
                    // no broad rule may replace human-authored descriptions.
                    || in_array(mb_strtolower(trim($old_normalized)),[
                        'sphinx | pyramids | egypt',
                        'from step pyramid to pharaohs – saqqara & memphis day tour',
                        'valley of the kings, hatshepsut & colossi of memnon day tour',
                    ],true);
                if($old!=='' &&$old!==$post_title
                   &&$old!==$canonical_title &&$old!==$alt
                   &&!$generic_title_alt &&!$legacy_repeated_generic
                   &&!$machine_slug_alt)
                    return self::error('mad4b_media_apply_existing_editorial_alt_preserved');
                $updates[$attachment.':_wp_attachment_image_alt']=[
                    'post_id'=>$attachment,'field'=>'_wp_attachment_image_alt',
                    'old'=>$old,'new'=>$alt,'target'=>'attachment_alt',
                    'old_present'=>metadata_exists('post',$attachment,
                        '_wp_attachment_image_alt')];
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
            'review_policy_fingerprint'=>$media_policy['policy_fingerprint'],
            'review_policy_source'=>$media_policy['source'],
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
            // WPML imports can leave 2+ identical Post Meta rows for a
            // translated JetEngine field. Their multiplicity is preserved:
            // update EVERY identical row under one transaction, never choose
            // the first row silently or collapse rows irreversibly.
            // Divergent duplicate values remain a hard conflict.
            $alt_missing_from_start=$change['target']==='attachment_alt'
                &&array_key_exists('old_present',$change)
                &&$change['old_present']===false;
            // New imported media often have no Alt row at all.
            // Create exactly one record. Reverse rollback deletes that
            // new record, restoring *absence*, not a new empty row.
            if($alt_missing_from_start &&!$reverse &&!$rows &&$old===''){
                $n=$wpdb->query($wpdb->prepare(
                    "INSERT INTO {$wpdb->postmeta} (post_id,meta_key,meta_value)
                     VALUES (%d,%s,%s)",
                    $change['post_id'],$change['field'],$new));
                if($n!==1)
                    throw new RuntimeException('mad4b_media_apply_missing_alt_insert_failed');
                continue;
            }
            if($alt_missing_from_start &&$reverse){
                if(!is_array($rows)||count($rows)!==1
                   ||!hash_equals((string)$rows[0]['meta_value'],(string)$old))
                    throw new RuntimeException('mad4b_media_apply_rollback_missing_alt_conflict');
                $n=$wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->postmeta}
                     WHERE meta_id=%d AND BINARY meta_value = BINARY %s",
                    (int)$rows[0]['meta_id'],$old));
                if($n!==1)
                    throw new RuntimeException('mad4b_media_apply_rollback_missing_alt_delete_failed');
                continue;
            }
            if(!is_array($rows)||!$rows||count($rows)>8)
                throw new RuntimeException('mad4b_media_apply_row_count_invalid:'.
                    $change['post_id'].':'.$change['field']);
            foreach($rows as $row){
                if(!hash_equals((string)$row['meta_value'],(string)$old))
                    throw new RuntimeException('mad4b_media_apply_row_conflict:'.
                        $change['post_id'].':'.$change['field']);
            }
            foreach($rows as $row){
                $n=$wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} SET meta_value=%s
                     WHERE meta_id=%d AND BINARY meta_value = BINARY %s",
                    $new,(int)$row['meta_id'],$old));
                if($n!==1)
                    throw new RuntimeException('mad4b_media_apply_cas_update_failed');
            }
        }
    }
    private static function purge_exact_public_tours(array $ids):array {
        $host=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
        $paths=[];
        foreach(array_unique(array_map('intval',$ids)) as $pid){
            if($pid<1||get_post_type($pid)!=='tours-and-activities'
               ||get_post_status($pid)!=='publish')continue;
            $url=get_permalink($pid);
            $details=apply_filters('wpml_post_language_details',null,$pid);
            $lang=is_array($details)?(string)($details['language_code']??''):'';
            if(preg_match('/^[a-z]{2}$/D',$lang))
                $url=apply_filters('wpml_permalink',$url,$lang,true);
            if(!is_string($url)||wp_parse_url($url,PHP_URL_HOST)!==$host)continue;
            $path=(string)wp_parse_url($url,PHP_URL_PATH);
            if(strlen($path)>350||
               !preg_match('#^/(?:[a-z]{2}/)?tours-and-activities/[a-z0-9/_%-]+/$#iD',$path))
                continue;
            $paths[$path]=true;
        }
        $paths=array_keys($paths);
        if(!$paths||count($paths)>24)
            return ['status'=>'no_exact_tour_paths','paths_count'=>count($paths)];
        $socket_path=WP_CONTENT_DIR.'/.mad4b-purge/origin.sock';
        if(!file_exists($socket_path)||is_link($socket_path))
            return ['status'=>'host_path_coordinator_unavailable','paths'=>$paths];
        $fd=@stream_socket_client('unix://'.$socket_path,$errno,$err,2);
        if(!$fd)return ['status'=>'host_path_coordinator_unreachable','paths'=>$paths];
        stream_set_timeout($fd,5);
        $request=wp_json_encode($paths,JSON_UNESCAPED_SLASHES)."\n";
        $written=fwrite($fd,$request);
        $reply=$written===strlen($request)
            ?json_decode((string)fgets($fd,4096),true):null;
        fclose($fd);
        if(!is_array($reply)||($reply['status']??'')!=='ok')
            return ['status'=>'host_path_coordinator_receipt_invalid','paths'=>$paths];
        return ['status'=>'ok','paths'=>$paths,'paths_count'=>count($paths),
            'versioned'=>(int)($reply['versioned']??0),
            'removed'=>(int)($reply['removed']??0),
            'duration_ms'=>(float)($reply['duration_ms']??0),
            'global_epoch_rotated'=>false,'redis_flushed'=>false];
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
            if($fresh['changes']!==$prepared['changes']
                ||$fresh['review_policy_fingerprint']!==$prepared['review_policy_fingerprint']
                ||$fresh['review_mode']!==$prepared['review_mode'])
                return self::error('mad4b_media_apply_concurrent_change');
            $token=wp_generate_uuid4();
            $option=self::BACKUP_PREFIX.str_replace('-','',$token);
            $backup=[
                'contract'=>'mad4b.tour-media-rollback.v1','token'=>$token,
                'site_uuid'=>$site,'source_post_id'=>$id,
                'plan_fingerprint'=>$prepared['plan']['plan_fingerprint'],
                'changes'=>$prepared['changes'],'affected_posts'=>$prepared['affected_posts'],
                'written_at'=>gmdate('c'),'review_mode'=>$prepared['review_mode'],
                'review_evidence'=>$m['review'],
                'review_policy_fingerprint'=>$prepared['review_policy_fingerprint'],
                'review_policy_source'=>$prepared['review_policy_source'],
                'status'=>'applied',
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
            $cache=self::purge_exact_public_tours($prepared['affected_posts']);
            $audit=MAD4B_SCP_Audit::record('mad4b/tour-media-governed-applied',[
                'site_uuid'=>$site,'post_id'=>$id,'rollback_token'=>$token,
                'review_source'=>'ai_visual_review_under_inherited_media_policy',
                'review_policy_fingerprint'=>$prepared['review_policy_fingerprint'],
                'review_policy_source'=>$prepared['review_policy_source'],
                'exact_public_html_purge'=>$cache,
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
                'exact_public_html_purge'=>$cache,
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
            $cache=self::purge_exact_public_tours($backup['affected_posts']);
            $audit=MAD4B_SCP_Audit::record('mad4b/tour-media-governed-rollback',[
                'site_uuid'=>$backup['site_uuid'],
                'source_post_id'=>$backup['source_post_id'],
                'rollback_token'=>$token,
                'reverted_rows'=>count($backup['changes']),
                'exact_public_html_purge'=>$cache,
                'audit_actor'=>'root_host_wpcli'
            ]);
            return ['status'=>'rolled_back','rollback_token'=>$token,
                'reverted_rows'=>count($backup['changes']),
                'exact_public_html_purge'=>$cache,
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
