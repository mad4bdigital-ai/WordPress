<?php
/**
 * Plugin Name: MAD4B Tour Media Reconcile Intelligence
 * Description: Source-bound multilingual media candidates and safe repair plans.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

/**
 * Read-only planner. Media evidence and provider contracts are NOT a grant
 * to rewrite a post, attest photography, or publish altered assets.
 */
final class MAD4B_Tour_Media_Reconcile_V1 {
    const CONTRACT='mad4b.tour-media-reconcile-plan.v1';
    const MAX_CANDIDATES=180;
    const MAX_OUTPUT=24;
    const MAX_BATCH=6;

    private static function wpml_family($id):array {
        $type=get_post_type($id);
        $trid=apply_filters('wpml_element_trid',null,$id,'post_'.$type);
        $translations=$trid?apply_filters('wpml_get_element_translations',[],$trid,'post_'.$type):[];
        $family=[];
        foreach(array_slice((array)$translations,0,12,true) as $lang=>$entry) {
            if(!is_object($entry))continue;
            $pid=(int)($entry->element_id??0);
            if($pid>0 && get_post_type($pid)===$type && get_post_status($pid)==='publish'
                && preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D',(string)$lang))
                $family[$lang]=$pid;
        }
        if(!$family)$family['und']=$id;
        ksort($family);
        return $family;
    }
    private static function site():array {
        if(!class_exists('MAD4B_SCP_Site_Profile',false))return [];
        $status=MAD4B_SCP_Site_Profile::status();
        if(empty($status['site_uuid'])||empty($status['origin_match'])
           ||empty($status['deployment_binding_match'])||empty($status['environment_match']))
            return [];
        return $status;
    }
    private static function media_fields($type):array {
        $fields=[];
        if(class_exists('MAD4B_SCP_Provider_Field_Intelligence',false)) {
            $res=MAD4B_SCP_Provider_Field_Intelligence::discover([
                'object'=>'post','subtype'=>$type,'only_media'=>true,'limit'=>500]);
            if(!is_wp_error($res))foreach((array)($res['fields']??[]) as $f){
                if(!is_array($f)||empty($f['declared'])||($f['provider']??'')!=='jetengine')continue;
                $key=(string)($f['key']??'');
                if(!preg_match('/^[a-z_][a-z0-9_]{1,99}$/D',$key)
                   ||!in_array($f['field_type']??'', ['gallery','media','image'],true))continue;
                $kind=($f['field_type']==='gallery')?'gallery':
                    (str_contains($key,'vertical')||str_contains($key,'portrait')?'vertical':
                    (str_contains($key,'grid')||str_contains($key,'card')?'grid':'media'));
                $fields[$key]=[
                    'role'=>$kind,'value_format'=>$f['value_format']??'unknown',
                    'wpml_mode'=>$f['wpml_translation']??'unconfigured',
                    'source'=>'live_jetengine_provider',
                ];
            }
        }
        return $fields;
    }
    private static function source_fields($ids,$fields):array {
        $result=[];
        foreach($ids as $lang=>$post_id) {
            $data=[];
            foreach($fields as $key=>$def){
                $v=get_post_meta($post_id,$key,true);
                $s=is_string($v)?$v:'';
                $data[$key]=[
                    'sha256'=>hash('sha256',$s),
                    'entries'=>$def['role']==='gallery'
                        ?count(array_filter(array_map('trim',explode(',',$s)))):($s!==''?1:0),
                    'value_format'=>$def['value_format'],
                    'role'=>$def['role'],
                    'present'=>$s!=='',
                ];
            }
            $result[$lang]=$data;
        }
        return $result;
    }
    private static function intersects_the_other_tour($title,$source_title):bool {
        if(!str_starts_with($title,$source_title))return false;
        $tail=trim(substr($title,strlen($source_title)));
        if(!str_starts_with($tail,'|'))return false;
        // Multiple imports contain "Tour A | Tour B filename".
        // Retain same-tour descriptors such as "Dolphin House | Dolphin
        // House Snorkeling Trip". Quarantine different-tour labels such
        // as "Dolphin House | Giftun Island ...".
        $source_words=preg_split('/[^\\p{L}\\p{N}]+/u',
            mb_strtolower($source_title,'UTF-8'));
        $other_words=preg_split('/[^\\p{L}\\p{N}]+/u',
            mb_strtolower(ltrim($tail,'| '),'UTF-8'));
        $head=array_slice(array_values(array_filter((array)$source_words,
            static fn($w)=>mb_strlen($w)>=4)),0,2);
        if(count($head)===2){
            $first=array_slice(array_values(array_filter((array)$other_words,
                static fn($w)=>mb_strlen($w)>=4)),0,6);
            if(in_array($head[0],$first,true)&&in_array($head[1],$first,true))
                return false;
        }
        return true;
    }
    private static function activity_tokens($title):array {
        $parts=preg_split('/[^\p{L}\p{N}]+/u',
            mb_strtolower(wp_strip_all_tags((string)$title),'UTF-8'));
        $skip=array_fill_keys([
            'tour','trip','days','day','from','with','and','the','one','two',
            'private','best','egypt','egyptian','excursion','experience','adventure',
            'package','packages','your','great','amazing','full','half',
            'city','hour','hours','luxury','travel','holiday','guide',
            'house','1','2','3','4','5','6','7','8','10','12',
            'de','en','la','las','los','el','con','por','desde','para',
        ],true);
        $tokens=[];
        foreach((array)$parts as $token){
            if(mb_strlen($token)<5||isset($skip[$token]))continue;
            // Prefix for simple plural and gerund variants; a contextual
            // *label hint*, not machine-verified subject recognition.
            $stem=mb_substr($token,0,7);
            $tokens[$stem]=true;
        }
        return array_slice(array_keys($tokens),0,12);
    }
    private static function candidates($id,$family,$limit):array {
        global $wpdb;
        $post=get_post($id);
        $canonical_title=(string)$post->post_title;
        $activity_tokens=self::activity_tokens($canonical_title);
        $ids=array_values(array_unique(array_map('intval',array_values($family))));
        $like=$wpdb->esc_like($canonical_title).'%';
        $parents=$ids?implode(',',array_fill(0,count($ids),'%d')):'0';
        $args=array_merge(['attachment','image/%',$like],$ids);
        // Query canonical-language Attachments once. The same file is
        // represented by 2-6 translated Attachment posts in WPML.
        // Enumerating all translations before bounding causes false
        // over-capacity errors on multilingual companies.
        $source_lang=(string)apply_filters('wpml_default_language','en');
        $sql=$wpdb->prepare(
            "SELECT p.ID,p.post_title,p.post_parent,p.post_mime_type
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->prefix}icl_translations tr
               ON tr.element_id=p.ID AND tr.element_type='post_attachment'
             WHERE p.post_type=%s AND p.post_mime_type LIKE %s
               AND (p.post_title LIKE %s OR p.post_parent IN ($parents))
               AND (tr.element_id IS NULL OR tr.language_code=%s)
             ORDER BY p.ID ASC LIMIT %d",
             ...array_merge($args,[$source_lang,self::MAX_CANDIDATES+1]));
        $rows=$wpdb->get_results($sql,ARRAY_A);
        if(!is_array($rows)||$wpdb->last_error)
            return ['complete'=>false,'error'=>'attachment_catalog_unavailable','items'=>[]];
        $complete=count($rows)<=self::MAX_CANDIDATES;
        if(!$complete)return ['complete'=>false,'error'=>'attachment_candidate_limit_exceeded',
            'candidate_rows'=>count($rows),'items'=>[]];
        $up=wp_upload_dir(null,false);
        $base=realpath((string)($up['basedir']??''));
        if(!$base)return ['complete'=>false,'error'=>'upload_root_unavailable','items'=>[]];
        $all=[];$reject=['different_tour_label'=>0,'missing_file'=>0,
            'not_exact_source'=>0,'too_small'=>0,'locale_family_incomplete'=>0];
        foreach($rows as $row){
            $aid=(int)$row['ID'];
            $title=(string)$row['post_title'];
            $by_parent=in_array((int)$row['post_parent'],$ids,true);
            $by_title=str_starts_with($title,$canonical_title);
            if(!$by_parent && !$by_title){$reject['not_exact_source']++;continue;}
            if(!$by_parent && self::intersects_the_other_tour($title,$canonical_title)){
                $reject['different_tour_label']++;continue;
            }
            $file=get_attached_file($aid);
            $real=$file?realpath($file):false;
            if(!$real || !str_starts_with($real,$base.DIRECTORY_SEPARATOR)
               ||!is_file($real)){ $reject['missing_file']++;continue;}
            $meta=wp_get_attachment_metadata($aid);
            $w=(int)($meta['width']??0);$h=(int)($meta['height']??0);
            if($w<480||$h<300){$reject['too_small']++;continue;}
            $trid=apply_filters('wpml_element_trid',null,$aid,'post_attachment');
            $locales=$trid?apply_filters('wpml_get_element_translations',[],$trid,'post_attachment'):[];
            $linked=[];
            foreach($family as $lang=>$pid){
                $t=isset($locales[$lang])&&is_object($locales[$lang])
                    ?(int)$locales[$lang]->element_id:0;
                if($lang==='und'&&!$t)$t=$aid;
                if(!$t || get_post_type($t)!=='attachment'){ $linked=[];break;}
                $translated=get_attached_file($t);
                $transreal=$translated?realpath($translated):false;
                if(!$transreal ||$transreal!==$real){$linked=[];break;}
                $linked[$lang]=[
                    'attachment_id'=>$t,
                    'post_id'=>$pid,
                    'current_alt_length'=>mb_strlen((string)get_post_meta($t,'_wp_attachment_image_alt',true)),
                    'alt_is_generic_tour_title'=>trim((string)get_post_meta($t,'_wp_attachment_image_alt',true))
                        ===trim((string)get_the_title($pid))
                ];
            }
            if(count($linked)!==count($family)){
                $reject['locale_family_incomplete']++;continue;
            }
            $tail=trim(substr($title,strlen($canonical_title)));
            $ratio=$h>0?round($w/$h,3):0;
            $matches=[];
            foreach($activity_tokens as $stem)
                if(mb_stripos(mb_strtolower($tail,'UTF-8'),$stem)!==false)
                    $matches[]=$stem;
            $score=50+($by_parent?35:0)+($by_title?20:0)
                 +min(18,(int)floor($w/170))
                 +($w>=1200?5:0)+($ratio>=1.3?4:0)
                 +min(35,count($matches)*12);
            $role_relevance=[
                'featured'=>$ratio>=1.3&&$w>=900?$score+20:($ratio>=1.1?$score:$score-20),
                'gallery'=>$score,
                'vertical'=>$ratio<=0.95&&$h>=800?$score+20:$score-20,
                'grid'=>$w>=800&&$h>=500?$score+12:$score-12,
            ];
            $all[]=[
                'canonical_attachment_id'=>$aid,
                'title'=>$title,
                'source_evidence'=>$by_parent?'wordpress_parent_post':'exact_tour_title_prefix',
                'subject_tags_from_filename_only'=>trim($tail,' -|_'),
                'activity_stem_matches_in_label'=>$matches,
                'metadata_relevance_is_not_visual_proof'=>true,
                'image_sha256'=>hash_file('sha256',$real),
                'image_bytes'=>(int)filesize($real),
                'width'=>$w,'height'=>$h,'aspect_ratio'=>$ratio,
                'mime_type'=>$row['post_mime_type'],
                'source_post_id'=>$id,'locale_attachments'=>$linked,
                'role_scores'=>$role_relevance,
                'editorial_visual_verified'=>false,
                'photographic_origin_verified'=>false,
                'media_rights_verified'=>false,
                'is_ai_generated'=>null,
                'auto_apply_authorized'=>false,
            ];
        }
        $unique=[];
        foreach($all as $entry){
            $fingerprint=$entry['image_sha256'];
            if(!isset($unique[$fingerprint])
               ||$entry['canonical_attachment_id']<$unique[$fingerprint]['canonical_attachment_id'])
                $unique[$fingerprint]=$entry;
        }
        $ranked=array_values($unique);
        usort($ranked,static fn($a,$b)=>
            ($b['role_scores']['featured']<=>$a['role_scores']['featured'])
            ?:($a['canonical_attachment_id']<=>$b['canonical_attachment_id']));
        return [
            'complete'=>$complete,
            'candidate_rows'=>count($rows),
            'verified_local_candidates'=>count($all),
            'unique_physical_images'=>count($ranked),
            'rejected'=>$reject,
            'items'=>array_slice($ranked,0,$limit),
        ];
    }
    private static function recommendations(array $items,array $fields):array {
        $selection=[];
        $used=[];
        $roles=['featured','gallery','vertical','grid'];
        foreach($roles as $role){
            $sorted=$items;
            usort($sorted,static fn($a,$b)=>
                ($b['role_scores'][$role]<=>$a['role_scores'][$role])
                 ?:($a['canonical_attachment_id']<=>$b['canonical_attachment_id']));
            $take=$role==='gallery'?min(8,count($sorted)):($role==='grid'?min(4,count($sorted)):1);
            $chosen=[];
            foreach($sorted as $item){
                if(count($chosen)>=$take)break;
                if($role==='vertical'&&$item['aspect_ratio']>1.0)continue;
                if($role==='featured'&&$item['width']<900)continue;
                $id=$item['canonical_attachment_id'];
                if($role!=='gallery'&&isset($used[$id]))continue;
                $chosen[]=$id;
                if($role!=='gallery')$used[$id]=true;
            }
            $selection[$role]=[
                'candidate_attachment_ids'=>$chosen,
                'selection_is_editorial_proposal'=>true,
                'needs_visual_verification'=>true,
                'valid_for_automatic_apply'=>false,
                'status'=>$chosen?'CANDIDATES_ONLY':'NO_SUITABLE_DIMENSION_FOUND',
            ];
        }
        $mapped=[];$grid_slot=0;
        foreach($fields as $key=>$def){
            $role=$def['role']==='media'?'grid':$def['role'];
            $pool=$selection[$role]['candidate_attachment_ids']??[];
            if($role==='grid'){
                // Each JetEngine grid cell gets a *distinct* proposed asset.
                $ids=isset($pool[$grid_slot])?[$pool[$grid_slot]]:[];
                $grid_slot++;
            } elseif($role==='vertical' || $role==='featured') {
                $ids=array_slice($pool,0,1);
            } else $ids=$pool;
            $mapped[$key]=[
                'role'=>$def['role'],
                'declared_storage_format'=>$def['value_format'],
                'wpml_mode'=>$def['wpml_mode'],
                'candidate_attachment_ids'=>$ids,
                'requires_explicit_policy_and_localized_write'=>true,
                'status'=>$ids?'CANDIDATES_ONLY':'NEEDS_ADDITIONAL_MEDIA_ASSET',
            ];
        }
        return ['roles'=>$selection,'fields'=>$mapped];
    }
    private static function localized_projections(array $recommend,array $candidates,
        array $family):array {
        $by_id=[];
        foreach($candidates as $candidate)
            $by_id[(int)$candidate['canonical_attachment_id']]=$candidate;
        $projected=[];
        foreach($family as $lang=>$pid){
            $rows=[];$urls_seen=[];
            foreach($recommend['fields'] as $field=>$info){
                $urls=[];$ids=[];
                foreach($info['candidate_attachment_ids'] as $canonical){
                    $candidate=$by_id[(int)$canonical]??null;
                    $t=(int)($candidate['locale_attachments'][$lang]['attachment_id']??0);
                    if(!$t)continue;
                    $url=wp_get_attachment_url($t);
                    if(!is_string($url)||!str_starts_with($url,'https://'))continue;
                    $ids[]=$t;$urls[]=$url;
                }
                $rows[$field]=[
                    'candidate_locale_attachment_ids'=>$ids,
                    'current_field_sha256'=>$info['current_field_sha256']??null,
                    'declared_format'=>$info['declared_storage_format'],
                    'suggested_value'=>($info['declared_storage_format']==='url')
                        ?implode(',',$urls):null,
                    'needs_visual_and_review_approval'=>true,
                    'can_apply_without_review'=>false,
                ];
            }
            $feature=$recommend['roles']['featured']['candidate_attachment_ids'][0]??0;
            $featured=(int)($by_id[$feature]['locale_attachments'][$lang]['attachment_id']??0);
            $projected[$lang]=[
                'post_id'=>$pid,
                'featured_attachment_candidate_id'=>$featured?:null,
                'media_field_values'=>$rows,
                'authoring_changes_made'=>false,
            ];
        }
        return $projected;
    }

    public static function plan($post_id,$opts=[]) {
        $id=absint($post_id);
        $limit=is_array($opts)?(int)($opts['limit']??12):12;
        if(!$id||$limit<1||$limit>self::MAX_OUTPUT)
            return new WP_Error('mad4b_media_plan_invalid_input','Bounded post ID and limit are required.');
        $site=self::site();
        if(!$site)return new WP_Error('mad4b_media_plan_site_binding_invalid','Exact enrolled Site Profile is required.');
        $p=get_post($id);
        if(!$p||$p->post_type!=='tours-and-activities'||$p->post_status!=='publish')
            return new WP_Error('mad4b_media_plan_invalid_tour','Published tour is required.');
        $family=self::wpml_family($id);
        $default=(string)apply_filters('wpml_default_language','en');
        $canonical=$family[$default]??$id;
        if($id!==$canonical)
            return new WP_Error('mad4b_media_plan_canonical_required',
                'Plan one original WPML tour, not each translated sibling.',
                ['canonical_post_id'=>$canonical]);
        $fields=self::media_fields($p->post_type);
        if(!$fields)return new WP_Error('mad4b_media_plan_provider_fields_missing',
            'Provider discovery must identify the current media field schema.');
        $sources=self::source_fields($family,$fields);
        $catalog=self::candidates($id,$family,$limit);
        $up=wp_upload_dir(null,false);
        $previous_gallery=(string)get_post_meta($id,'gallery',true);
        $foreign_gallery=str_contains($previous_gallery,'/uploads/')
            &&!str_contains($previous_gallery,wp_parse_url(home_url('/'),PHP_URL_HOST));
        $review=class_exists('MAD4B_SCP_Context_Authority',false)
            ?MAD4B_SCP_Context_Authority::review_policy():[];
        $brand=class_exists('MAD4B_SCP_Context_Authority',false)
            ?MAD4B_SCP_Context_Authority::profile():[];
        $recommend=self::recommendations($catalog['items']??[],$fields);
        $projection=self::localized_projections($recommend,$catalog['items']??[],$family);
        foreach($projection as $lang=>&$row)
            foreach($row['media_field_values'] as $key=>&$val)
                $val['current_field_sha256']=$sources[$lang][$key]['sha256']??null;
        unset($row,$val);
        $nonce=hash('sha256',wp_json_encode([
            'company'=>$site['site_uuid'],'source'=>$id,'locales'=>$family,
            'field_snapshots'=>$sources,
            'candidates'=>array_column($catalog['items']??[],'image_sha256'),
        ],JSON_UNESCAPED_SLASHES));
        return [
            'contract'=>self::CONTRACT,'version'=>'1.0.0',
            'site_uuid'=>$site['site_uuid'],
            'site_url'=>home_url('/'),
            'brand_id'=>$brand['brand_id']??null,
            'company_environment'=>$site['environment']??null,
            'canonical_post_id'=>$id,'canonical_title'=>$p->post_title,
            'wpml_post_family'=>$family,
            'provider_fields'=>$fields,'source_field_snapshots'=>$sources,
            'gallery_currently_references_other_host'=>$foreign_gallery,
            'candidate_catalog'=>$catalog,
            'recommended_roles'=>$recommend,
            'wpml_localized_proposed_values'=>$projection,
            'plan_fingerprint'=>$nonce,
            'review_mode_inherited'=>$review['mode']??'human_only',
            'decision'=>'WAITING_MEDIA_EVIDENCE_AND_REVIEW',
            'next_action'=>'obtain_evidence_of_visual_subject_and_media_rights_then_record_approved_candidate_set_via_content_job',
            'no_photo_origin_claim_without_evidence'=>true,
            'gallery_same_for_many_tours_must_not_be_a_source_of_candidates'=>true,
            'visual_classification_not_inferred_from_file_extension'=>true,
            'requires_content_job_review'=>true,
            'site_media_modified'=>false,
            'approval_fabricated'=>false,
            'applied'=>false,
            'rollback_required_for_future_apply'=>true
        ];
    }
    public static function cli($args,$opts):void {
        if($args||array_diff(array_keys($opts),['post-id','limit','batch','after']))
            WP_CLI::error('Usage: wp mad4b media-reconcile --post-id=ID [--limit=12] or --batch=1 [--after=ID].');
        $limit=(int)($opts['limit']??12);
        if(isset($opts['post-id'])){
            $r=self::plan((int)$opts['post-id'],['limit'=>$limit]);
            if(is_wp_error($r))WP_CLI::error($r->get_error_code());
            WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            return;
        }
        if(($opts['batch']??'')!=='1'||$limit<1||$limit>self::MAX_BATCH)
            WP_CLI::error('Use --batch=1 --limit=1..6 --after=<canonical-post-ID>.');
        $after=max(0,(int)($opts['after']??0));
        $ids=get_posts(['post_type'=>'tours-and-activities','post_status'=>'publish',
            'orderby'=>'ID','order'=>'ASC','posts_per_page'=>2500,'fields'=>'ids']);
        $results=[];$last=$after;
        foreach($ids as $id){
            if($id<=$after)continue;
            $family=self::wpml_family($id);
            $def=(string)apply_filters('wpml_default_language','en');
            $canonical=$family[$def]??$id;
            if($id!==$canonical)continue;
            $r=self::plan($id,['limit'=>12]);
            $results[]=[
                'post_id'=>$id,'plan_fingerprint'=>is_wp_error($r)?null:$r['plan_fingerprint'],
                'status'=>is_wp_error($r)?$r->get_error_code():$r['decision'],
                'candidates'=>is_wp_error($r)?0:count($r['candidate_catalog']['items']??[]),
                'source_gallery_foreign'=>is_wp_error($r)?null:$r['gallery_currently_references_other_host'],
                'no_publication'=>true
            ];
            $last=$id;
            if(count($results)>=$limit)break;
        }
        WP_CLI::line(wp_json_encode([
            'contract'=>'mad4b.media-reconcile-batch.v1',
            'items'=>$results,'last_post_id'=>$last,'limit'=>$limit,
            'read_only'=>true,'changes_made'=>false,
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
}
if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b media-reconcile',[MAD4B_Tour_Media_Reconcile_V1::class,'cli']);

