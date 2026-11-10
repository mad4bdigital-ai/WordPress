<?php
/**
 * Plugin Name: MAD4B Media Origin Integrity
 * Description: Read-only, company-bound provenance analysis for CMS media references; registers actionable Content Readiness alerts.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Media_Origin_Integrity_V1 {
    const CONTRACT='mad4b.media-origin-integrity.v1';
    const SOURCE='mad4b-media-origin-integrity';
    const MAX_ROWS=15000;
    const MAX_LINKS=65000;
    const MAX_PER_FIELD=300;
    const CODES=['media_foreign_upload_origin','media_local_attachment_missing',
      'media_local_attachment_ambiguous','media_duplicate_attachment_records',
      'media_gallery_shared_across_tours','media_presentation_images_shared',
      'media_gallery_destination_review','media_reference_malformed',
      'media_featured_visual_origin_reused','media_visual_lineage_scan_incomplete'];

    /** Low-cost destination signal from editorial text or source filename.
     * Location clues are not image recognition and never auto-approve media.
     */
    private static function location_signals($text):array {
        $text=mb_strtolower(remove_accents((string)$text));
        $words=preg_replace('/[^a-z0-9]+/',' ',$text);
        $dictionary=[
          'cairo'=>['cairo','el cairo','giza','pyramid','pyramids','piramides','pirámides'],
          'luxor'=>['luxor','valley of kings','karnak'],
          'aswan'=>['aswan','asuan','abu simbel'],
          'hurghada'=>['hurghada','dolphin house','snorkeling','snorkel'],
          'alexandria'=>['alexandria','alejandria'],
          'sharm_el_sheikh'=>['sharm el sheikh','sharm el shaikh','ras mohammed'],
          'marsa_alam'=>['marsa alam'],
          'siwa'=>['siwa'],
          'fayoum'=>['fayoum','faiyum'],
        ];
        $found=[];
        foreach($dictionary as $name=>$aliases)foreach($aliases as $alias){
            $alias=trim(preg_replace('/[^a-z0-9]+/',' ',mb_strtolower(remove_accents($alias))));
            if($alias!=='' && str_contains(' '.$words.' ',' '.$alias.' ')){
                $found[$name]=true;break;
            }
        }
        return array_keys($found);
    }
    private static function fields():array {
        // Live provider descriptors from JetEngine/registered WordPress meta.
        // A fixed legacy fallback is retained only if discovery is unavailable,
        // not treated as a complete schema or an authoritative provider policy.
        $fields=class_exists('MAD4B_SCP_Provider_Field_Intelligence',false)
            ?MAD4B_SCP_Provider_Field_Intelligence::media_field_names(
                'tours-and-activities'):array();
        if(!$fields)$fields=[
            'gallery','image_vertical','grid_image_1_middle_upper',
            'grid_image_2','grid_image_3_middle_bottom','grid_image_4_'
        ];
        $fields=apply_filters('mad4b/media_integrity/fields',$fields);
        $out=[];
        if(is_array($fields))foreach($fields as $name){
            if(is_string($name)&&preg_match('/^[a-zA-Z0-9_]{2,80}$/D',$name))$out[$name]=true;
        }
        return array_slice(array_keys($out),0,40);
    }

    private static function post_types($policy):array {
        $requested=(array)($policy['post_types']??['tours-and-activities']);
        $valid=[];
        foreach($requested as $type){
            if(!is_string($type) || !preg_match('/^[a-z0-9_-]{2,64}$/D',$type))continue;
            $object=get_post_type_object($type);
            if($object && !empty($object->public) && !in_array($type,['attachment','revision'],true))
                $valid[$type]=true;
        }
        return array_slice(array_keys($valid),0,24);
    }
    private static function issue($type,$id,$code,$severity,$label,$evidence):array {
        return [
            'id'=>hash('sha256',get_current_blog_id().'|'.$type.'|'.$id.'|'.$code),
            'post_type'=>$type,'object_id'=>$id,
            'source'=>self::SOURCE,'code'=>$code,'severity'=>$severity,
            'label'=>mb_substr($label,0,228),'source_evidence'=>$evidence
        ];
    }
    private static function parse($url):array {
        $url=trim((string)$url);
        $parts=wp_parse_url($url);
        if(!is_array($parts) || !in_array(strtolower((string)($parts['scheme']??'')),['https','http'],true)
           || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass']))
            return ['valid'=>false];
        $host=strtolower(rtrim((string)$parts['host'],'.'));
        $path=rawurldecode((string)($parts['path']??''));
        $needle='/wp-content/uploads/';
        $pos=strpos($path,$needle);
        if($pos===false)return ['valid'=>false,'host'=>$host];
        $rel=substr($path,$pos+strlen($needle));
        // Never authorize traversal, query strings as paths, or external fetches.
        if(!$rel || strlen($rel)>700 || str_contains($rel,'..')
           || str_contains($rel,'\\') || str_starts_with($rel,'/')
           || preg_match('/[\x00-\x1f]/',$rel))
            return ['valid'=>false,'host'=>$host];
        return ['valid'=>true,'host'=>$host,'relative_path'=>$rel];
    }

    public static function scan($policy=null):array {
        global $wpdb;
        $start=microtime(true);
        $policy=is_array($policy)?$policy:
            (class_exists('MAD4B_SCP_Content_Readiness_Alerts',false)
                ? MAD4B_SCP_Content_Readiness_Alerts::rules():['post_types'=>['tours-and-activities']]);
        $types=self::post_types($policy);
        $fields=self::fields();
        $status=[
            'contract'=>self::CONTRACT,'site'=>home_url('/'),
            'site_uuid'=>class_exists('MAD4B_SCP_Site_Profile',false)
                ?(string)MAD4B_SCP_Site_Profile::site_uuid():'',
            'complete'=>false,'issue_count'=>0,'scanned_records'=>0,
            'scanned_posts'=>0,'references'=>0,'foreign_references'=>0,
            'missing_local_references'=>0,'ambiguous_local_references'=>0,
            'equivalent_duplicate_attachment_records'=>0,
            'destination_review_candidates'=>0,'shared_gallery_tour_candidates'=>0,
            'shared_presentation_tour_candidates'=>0,'shared_media_field_names'=>[],
            'duplicate_postmeta_rows'=>0,
            'invalid_references'=>0,'origin_counts'=>[],
            'fields'=>$fields,'issues'=>[],'read_only'=>true,
            'mutations_performed'=>false,'automatic_origin_rewrite'=>false,
        ];
        if(!$types || !$fields || empty($status['site_uuid'])){
            $status['error']='scanner_site_or_catalog_unavailable';return $status;
        }
        $marks_types=implode(',',array_fill(0,count($types),'%s'));
        $marks_fields=implode(',',array_fill(0,count($fields),'%s'));
        $sql=$wpdb->prepare(
            "SELECT pm.post_id,p.post_type,pm.meta_key,pm.meta_value
             FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id
             WHERE p.post_type IN ($marks_types) AND p.post_status='publish'
               AND pm.meta_key IN ($marks_fields)
               AND LENGTH(pm.meta_value)>9
             ORDER BY pm.post_id,pm.meta_id LIMIT %d",
            ...array_merge($types,$fields,[self::MAX_ROWS+1])
        );
        $rows=$wpdb->get_results($sql,ARRAY_A);
        if(!is_array($rows)||$wpdb->last_error ||count($rows)>self::MAX_ROWS){
            $status['error']='record_inventory_incomplete';return $status;
        }
        $site_host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        $upload_base=wp_upload_dir(null,false);
        $upload_host=strtolower((string)wp_parse_url((string)$upload_base['baseurl'],PHP_URL_HOST));
        $allowed_hosts=array_fill_keys(array_filter([$site_host,$upload_host]),true);
        $extra=apply_filters('mad4b/media_integrity/approved_hosts',[], $status['site_uuid']);
        // Other company/CDN origins may be registered explicitly through a
        // vetted hook; do not accept an attacker-controlled URL as allowlist.
        if(is_array($extra))foreach(array_slice($extra,0,10) as $h){
            if(is_string($h)&&preg_match('/^[a-z0-9.-]{3,190}$/D',$h))
                $allowed_hosts[strtolower($h)]=true;
        }
        $posts=[];$relative_paths=[];$seen_field_meta=[];$gallery_groups=[];
        $media_field_groups=[];
        $default_language=(string)apply_filters('wpml_default_language','en');
        foreach($rows as $row){
            $id=(int)$row['post_id'];$raw=(string)$row['meta_value'];
            $field=(string)$row['meta_key'];
            // WordPress can retain identical duplicate postmeta rows after a
            // migration. Count one logical field, not every raw database row.
            $fingerprint=hash('sha256',$raw);
            if(isset($seen_field_meta[$id][$field])){
                if(!hash_equals($fingerprint,$seen_field_meta[$id][$field])){
                    $status['error']='conflicting_duplicate_media_postmeta';
                    return $status;
                }
                $status['duplicate_postmeta_rows']++;
                continue;
            }
            $seen_field_meta[$id][$field]=$fingerprint;
            $family=(int)apply_filters('wpml_object_id',$id,
                'tours-and-activities',false,$default_language);
            if(!$family)$family=$id;
            $media_field_groups[$field][$fingerprint][$family]=true;
            if($field==='gallery')
                $gallery_groups[$fingerprint][$family]=true;
            if(strlen($raw)>180000){
                $status['error']='oversized_media_field';return $status;
            }
            $links=array_values(array_filter(array_map('trim',explode(',',$raw)),'strlen'));
            if(count($links)>self::MAX_PER_FIELD){
                $status['error']='oversized_gallery';return $status;
            }
            if(!$links)continue;
            if(!isset($posts[$id]))$posts[$id]=[
                'type'=>(string)$row['post_type'],'fields'=>[],
                'fields_sha256'=>[],'ref_count'=>0,'foreign'=>[],
                'unknown'=>0,'paths'=>[],'local_paths'=>[],
            ];
            $posts[$id]['fields'][$field]=true;
            $posts[$id]['fields_sha256'][$field]=hash('sha256',$raw);
            foreach($links as $url){
                if(++$status['references']>self::MAX_LINKS){
                    $status['error']='reference_inventory_incomplete';return $status;
                }
                $posts[$id]['ref_count']++;
                $parsed=self::parse($url);
                if(empty($parsed['valid'])){
                    $posts[$id]['unknown']++;
                    $status['invalid_references']++;
                    continue;
                }
                $host=$parsed['host'];$rel=$parsed['relative_path'];
                $status['origin_counts'][$host]=($status['origin_counts'][$host]??0)+1;
                $relative_paths[$rel]=true;
                $posts[$id]['paths'][$rel]=($posts[$id]['paths'][$rel]??0)+1;
                if(!isset($allowed_hosts[$host])){
                    $status['foreign_references']++;
                    $posts[$id]['foreign'][$host]=($posts[$id]['foreign'][$host]??0)+1;
                    $posts[$id]['local_paths'][$rel]=true;
                }
            }
        }
        $status['scanned_records']=count($rows);
        $status['scanned_posts']=count($posts);
        $status['unique_media_paths']=count($relative_paths);
        ksort($status['origin_counts']);
        // One set-based DB lookup per bounded chunk. No remote HTTP requests.
        $attachment_map=[];
        foreach(array_chunk(array_keys($relative_paths),180) as $chunk){
            $slots=implode(',',array_fill(0,count($chunk),'%s'));
            $found=$wpdb->get_results($wpdb->prepare(
                "SELECT pm.meta_value,pm.post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
                 WHERE pm.meta_key='_wp_attached_file'
                   AND pm.meta_value IN ($slots)
                   AND p.post_type='attachment' AND p.post_mime_type LIKE 'image/%%'",
                 ...$chunk),ARRAY_A);
            if(!is_array($found)||$wpdb->last_error){
                $status['error']='attachment_lookup_incomplete';return $status;
            }
            foreach($found as $item)
                $attachment_map[(string)$item['meta_value']][]=(int)$item['post_id'];
        }
        $upload_path=realpath((string)$upload_base['basedir']);
        if(!$upload_path){
            $status['error']='local_upload_directory_unavailable';return $status;
        }
        $verified_paths=[];
        foreach(array_keys($relative_paths) as $rel){
            $candidate=realpath($upload_path.'/'.$rel);
            $on_disk=$candidate && str_starts_with($candidate,$upload_path.DIRECTORY_SEPARATOR)
                && is_file($candidate);
            $ids=array_unique($attachment_map[$rel]??[]);
            $verified=[];
            if($on_disk)foreach($ids as $id){
                $file=get_attached_file($id);
                if($file && realpath($file)===$candidate
                   && str_starts_with((string)get_post_mime_type($id),'image/'))$verified[]=$id;
            }
            // Multiple DB attachment records may point to the exact same
            // physical image. That's one equivalently recoverable file, not
            // several conflicting image candidates.
            $verified_paths[$rel]=array(
                'physical_files'=>$verified?1:0,
                'attachment_records'=>count($verified)
            );
        }
        $status['gallery_fingerprints']=count($gallery_groups);
        $status['largest_shared_gallery_family']=$gallery_groups
            ?max(array_map('count',$gallery_groups)):0;
        $status['complete']=true;
        $brand=class_exists('MAD4B_SCP_Context_Authority',false)
            ?MAD4B_SCP_Context_Authority::profile():[];
        $brand_id=(string)($brand['brand_id']??'');
        foreach($posts as $id=>$post){
            $foreign_count=array_sum($post['foreign']);
            $missing=0;$ambiguous=0;$local_exact=0;$equiv_records=0;
            foreach($post['local_paths'] as $relative=>$_){
                $entry=$verified_paths[$relative]??array();
                $physical=(int)($entry['physical_files']??0);
                $records=(int)($entry['attachment_records']??0);
                if($physical===0)$missing++;
                elseif($physical>1)$ambiguous++;
                else {
                    $local_exact++;
                    $equiv_records+=max(0,$records-1);
                }
            }
            $status['missing_local_references']+=$missing;
            $status['ambiguous_local_references']+=$ambiguous;
            $status['equivalent_duplicate_attachment_records']+=$equiv_records;
            $gallery_value=(string)get_post_meta($id,'gallery',true);
            $gallery_fingerprint=hash('sha256',$gallery_value);
            $gallery_family_count=count($gallery_groups[$gallery_fingerprint]??[]);
            $shared_media_fields=[];
            foreach($post['fields_sha256'] as $field=>$fingerprint){
                $count=count($media_field_groups[$field][$fingerprint]??[]);
                if($count>1)$shared_media_fields[$field]=$count;
            }
            $other_shared=$shared_media_fields;
            unset($other_shared['gallery']);
            if($other_shared){
                $status['shared_presentation_tour_candidates']++;
                foreach($other_shared as $field=>$unused)
                    $status['shared_media_field_names'][$field]=true;
            }
            $tour_signals=self::location_signals(get_the_title($id));
            $labeled=0;$unmatched=0;$filename_signals=[];
            foreach(array_keys($post['local_paths']) as $relative){
                $signals=self::location_signals(pathinfo($relative,PATHINFO_FILENAME));
                if(!$signals)continue;
                $labeled++;$filename_signals=array_unique(array_merge($filename_signals,$signals));
                if($tour_signals&&!array_intersect($signals,$tour_signals))$unmatched++;
            }
            // This is a content-review *signal*, not a claim that the photo
            // actually depicts the place written in its filename.
            $destination_review=$tour_signals && $labeled>=5
                &&$unmatched/$labeled>=0.75;
            if($destination_review)$status['destination_review_candidates']++;
            $data=[
                'contract'=>self::CONTRACT,'site_uuid'=>$status['site_uuid'],
                'brand_id'=>$brand_id,'post_type'=>$post['type'],'post_id'=>$id,
                'source_sha256'=>hash('sha256',wp_json_encode($post['fields_sha256'])),
                'fields'=>array_keys($post['fields']),
                'source_hosts'=>array_keys($post['foreign']),
                'foreign_reference_count'=>$foreign_count,
                'unique_foreign_paths'=>count($post['local_paths']),
                'local_unique_attachment_paths'=>$local_exact,
                'missing_local_paths'=>$missing,
                'ambiguous_local_paths'=>$ambiguous,
                'equivalent_attachment_record_surplus'=>$equiv_records,
                'gallery_fingerprint_sha256'=>$gallery_fingerprint,
                'shared_by_tour_families'=>$gallery_family_count,
                'shared_visual_fields'=>$shared_media_fields,
                'shared_presentation_field_count'=>count($other_shared),
                'title_destination_signals'=>$tour_signals,
                'filename_destination_signals'=>$filename_signals,
                'labeled_gallery_paths'=>$labeled,
                'filename_signal_disjoint_paths'=>$unmatched,
                'destination_review_candidate'=>(bool)$destination_review,
                'location_evidence_is_filename_only'=>true,
                'invalid_url_count'=>$post['unknown'],
                'total_references'=>$post['ref_count'],
                'local_match_is_not_editorial_approval'=>true,
                'provenance_unverified'=>true,
                'remote_content_not_fetched'=>true,
                'recommendation'=>'Review destination relevance and gallery provenance before selecting verified local attachments; never mass-replace by filename alone.',
                'publication_authorized'=>false,'media_replacement_authorized'=>false,
            ];
            if($foreign_count){
                $status['issues'][]=self::issue($post['type'],$id,
                    'media_foreign_upload_origin','high',
                    'Foreign gallery media origin: '.$foreign_count.
                      ' references; '.$local_exact.' verified local files; '.$missing.' missing; '.$equiv_records.' equivalent duplicate attachment rows',
                    $data);
            }
            if($missing){
                $status['issues'][]=self::issue($post['type'],$id,
                    'media_local_attachment_missing','high',
                    'Gallery local recovery incomplete: '.$missing.
                    ' source paths have no matching local attachment', $data);
            }
            if($ambiguous){
                $status['issues'][]=self::issue($post['type'],$id,
                    'media_local_attachment_ambiguous','medium',
                    'Gallery attachment mapping ambiguous: '.$ambiguous.
                    ' local paths have multiple matching media records', $data);
            }
            if($equiv_records){
                $status['issues'][]=self::issue($post['type'],$id,
                    'media_duplicate_attachment_records','medium',
                    'Verified images have '.$equiv_records.
                    ' redundant Attachment records. Canonical local presentation is safe; review media ownership and metadata separately.',
                    $data);
            }
            if($gallery_family_count>1){
                $status['shared_gallery_tour_candidates']++;
                $status['issues'][]=self::issue($post['type'],$id,
                    'media_gallery_shared_across_tours','high',
                    'The same Gallery is assigned to '.$gallery_family_count.
                    ' distinct tour families; '.count($other_shared).
                    ' additional visual fields also reused. Review actual trip relevance.',
                    $data);
            } elseif($other_shared) {
                $status['issues'][]=self::issue($post['type'],$id,
                    'media_presentation_images_shared','high',
                    count($other_shared).
                    ' visual fields reused across distinct tour families even though Gallery differs. Review featured, grid and vertical images.',
                    $data);
            }
            if($destination_review){
                $status['issues'][]=self::issue($post['type'],$id,
                    'media_gallery_destination_review','high',
                    'Tour destination and legacy gallery filenames may not align. Review actual images against the itinerary before selecting replacements.',
                    $data);
            }
            if($post['unknown']){
                $status['issues'][]=self::issue($post['type'],$id,
                    'media_reference_malformed','medium',
                    'Gallery or image metadata contains '.$post['unknown'].' malformed references', $data);
            }
        }
        // Include actual photographic lineage in the SAME authoritative
        // integrity/readiness scan. Different cropped file hashes alone are
        // not proof of visual originality across tour card Featured images.
        if(class_exists('MAD4B_Media_Visual_Lineage_V1',false)){
            $lineage=MAD4B_Media_Visual_Lineage_V1::audit(false);
            if(empty($lineage['complete'])){
                $status['complete']=false;
                $status['error']='media_visual_lineage_scan_incomplete';
                $status['visual_lineage_error']=$lineage['reason']??'unknown';
                return $status;
            }
            $status['visual_lineage_confirmed_groups']=$lineage['confirmed_group_count'];
            // Count distinct affected tour families, not language duplicates,
            // so editorial remediation reports track true card coverage.
            $status['visual_lineage_affected_tour_families']=count(array_unique(
                array_merge(...array_map(static fn($group)=>array_column(
                    $group['tours'],'family_key'),
                    $lineage['confirmed_reused_featured_origins']))));
            $status['visual_lineage_scanned_published_posts']=$lineage['scanned_published_posts'];
            $status['visual_lineage_unresolved_origins']=count($lineage['unresolved_origins']);
            if($lineage['unresolved_origins']){
                $status['complete']=false;
                $status['error']='media_visual_lineage_origins_unverified';
                return $status;
            }
            foreach($lineage['confirmed_reused_featured_origins'] as $group){
                foreach($group['tours'] as $item){
                    $status['issues'][]=self::issue('tours-and-activities',
                        (int)$item['post_id'],'media_featured_visual_origin_reused','high',
                        'Featured/card image comes from the same original photo as '.
                          (int)($group['families']-1).' other tour families despite distinct crops.',[
                            'origin_sha256'=>$group['origin_sha256'],
                            'origin_attachment_id'=>$item['origin_attachment_id'],
                            'lineage_depth'=>$item['lineage_depth'],
                            'other_tour_ids'=>array_values(array_diff(
                                array_column($group['tours'],'post_id'),[$item['post_id']])),
                            'evidence'=>'verified_original_file_sha256_and_derivative_ancestry',
                            'remediation'=>'select a truly different original image; recropping the same photo is not sufficient',
                            'rights_verified'=>false,
                        ]);
                }
            }
        }else{
            // Installation is atomic with this scanner. No silent pass if
            // the required detector file is missing or fails to load.
            $status['complete']=false;
            $status['error']='media_visual_lineage_guard_missing';
            return $status;
        }
        $status['shared_media_field_names']=array_keys($status['shared_media_field_names']);
        $status['issue_count']=count($status['issues']);
        $status['duration_ms']=(int)round((microtime(true)-$start)*1000);
        return $status;
    }
    public static function add_issues($issues,$policy){
        if(!is_array($issues))return $issues;
        $result=self::scan($policy);
        if(empty($result['complete'])){
            // Existing readiness reconciler fails closed rather than resolving
            // previously observed issues on a partial or failed scan.
            $issues[]=['source'=>self::SOURCE,'code'=>'media_integrity_scan_incomplete'];
            return $issues;
        }
        return array_merge($issues,$result['issues']);
    }
    /**
     * A bounded, site-specific media review plan. Presentation may resolve
     * local file identity, but a visual/Brand review is still required before
     * replacing author-edited Gallery metadata or asserting destination fit.
     * No image network fetches, side effects, or implied publication grants.
     */
    private static function candidate_reconciliation($post_id):array {
        $r=MAD4B_Tour_Media_Reconcile_V1::plan((int)$post_id,['limit'=>12]);
        if(is_wp_error($r))
            return ['available'=>false,'error'=>$r->get_error_code(),
                'canonical_post_id'=>$r->get_error_data()['canonical_post_id']??null,
                'changes_made'=>false];
        return [
            'available'=>true,'contract'=>$r['contract'],
            'plan_fingerprint'=>$r['plan_fingerprint'],
            'wpml_family'=>$r['wpml_post_family'],
            'candidate_summary'=>array_diff_key($r['candidate_catalog'],['items'=>true]),
            'candidates'=>$r['candidate_catalog']['items'],
            'role_recommendations'=>$r['recommended_roles'],
            'provider_fields'=>$r['provider_fields'],
            'source_field_snapshots'=>$r['source_field_snapshots'],
            'review_required'=>true,'media_applied'=>false,
        ];
    }

    public static function review_plan($post_id):array {
        $post_id=absint($post_id);
        $post=$post_id?get_post($post_id):null;
        $site=class_exists('MAD4B_SCP_Site_Profile',false)
            ?MAD4B_SCP_Site_Profile::status():array();
        if(!$post ||$post->post_status!=='publish'
           ||$post->post_type!=='tours-and-activities'
           ||empty($site['site_uuid'])||empty($site['authority_ready']))
            return ['contract'=>'mad4b.media-review-plan.v1','complete'=>false,
                'error'=>'tour_or_site_unavailable'];
        if(!class_exists('MAD4B_Gallery_Resolution_Engine_V1',false))
            return ['contract'=>'mad4b.media-review-plan.v1','complete'=>false,
                'error'=>'gallery_resolution_engine_unavailable'];
        $raw=(string)get_post_meta($post_id,'gallery',true);
        $resolution=MAD4B_Gallery_Resolution_Engine_V1::inspect($raw,$post_id);
        if(empty($resolution['valid_source'])||!empty($resolution['resolver_error']))
            return ['contract'=>'mad4b.media-review-plan.v1','complete'=>false,
                'error'=>'gallery_source_unresolved'];
        $terms=get_the_terms($post_id,'destinations');
        $destinations=[];
        if(is_array($terms))foreach($terms as $term)
            if($term instanceof WP_Term)$destinations[]=$term->name;
        $destinations=array_values(array_unique(array_slice($destinations,0,30)));
        $title=get_the_title($post_id);
        $signals=self::location_signals($title.' '.implode(' ',$destinations));
        $chosen=(string)($resolution['render_value']??'');
        $ids=preg_match('/^[0-9]+(?:,[0-9]+)*$/D',$chosen)
            ?array_map('intval',explode(',',$chosen)):array();
        $samples=[];
        foreach(array_slice(array_values(array_unique($ids)),0,10) as $id){
            $file=get_attached_file($id);
            $real=$file?realpath($file):false;
            $mime=(string)get_post_mime_type($id);
            if(!$real ||!is_file($real)||!str_starts_with($mime,'image/'))continue;
            $meta=wp_get_attachment_metadata($id);
            $file_alt=(string)get_post_meta($id,'_wp_attachment_image_alt',true);
            $label=pathinfo($real,PATHINFO_FILENAME).' '.$file_alt;
            $file_signals=self::location_signals($label);
            $score='needs_visual_review';
            if($signals && $file_signals)
                $score=array_intersect($signals,$file_signals)
                    ?'filename_or_alt_signal_overlaps':'filename_or_alt_signal_disjoint';
            $samples[]=[
                'attachment_id'=>$id,'mime'=>$mime,
                'width'=>is_array($meta)?(int)($meta['width']??0):0,
                'height'=>is_array($meta)?(int)($meta['height']??0):0,
                'alt'=>mb_substr($file_alt,0,220),
                'attachment_parent_match'=>(int)get_post_field('post_parent',$id)===$post_id,
                'asset_content_sha256'=>filesize($real)<=12*1024*1024
                    ?hash_file('sha256',$real):null,
                'source_signals'=>$file_signals,
                'destination_signal'=>$score,
                'image_subject_verified_by_ai'=>false,
            ];
        }
        $issues=[];
        if($resolution['foreign_origin_count'])$issues[]='foreign_origin';
        if($resolution['unresolved'])$issues[]='missing_or_nonmatching_file';
        if($resolution['equivalent_attachment_records'])$issues[]='duplicate_local_attachment_records';
        $scan=self::scan();
        $record=null;
        if(!empty($scan['complete']))
          foreach((array)$scan['issues'] as $issue){
            if((int)($issue['object_id']??0)===$post_id
                &&in_array((string)($issue['code']??''),[
                    'media_gallery_shared_across_tours','media_presentation_images_shared'],true)){
                $record=$issue['source_evidence'];
                break;
            }
          }
        if(!empty($record['shared_by_tour_families'])
            && (int)$record['shared_by_tour_families']>1)
            $issues[]='identical_gallery_across_distinct_tours';
        if(!empty($record['shared_presentation_field_count']))
            $issues[]='reused_vertical_or_grid_media_fields';
        $ledger=(array)get_option('mad4b_content_readiness_issues_v1',[]);
        $requests=[];
        foreach($ledger as $alert){
            if(($alert['source']??'')!==self::SOURCE
                ||(int)($alert['object_id']??0)!==$post_id)continue;
            $q=(array)($alert['content_job_request']??[]);
            if($q && $q['site_uuid']===$site['site_uuid'])
                $requests[]=[
                    'source_issue_id'=>(string)($alert['id']??''),
                    'gap_code'=>(string)($alert['code']??''),
                    'status'=>(string)($q['status']??''),
                    'deduplication_key'=>(string)($q['deduplication_key']??''),
                ];
        }
        // Media photo review has its own inherited Central/Company policy.
        // Brand Context's independent approval mode is not an image policy.
        $media_policy=class_exists('MAD4B_Media_Review_Hierarchy_V1',false)
            ?MAD4B_Media_Review_Hierarchy_V1::effective():[];
        $route=!empty($media_policy['policy_ready'])
            ?(string)$media_policy['mode']
            :(class_exists('MAD4B_SCP_Context_Authority',false)
                ?(string)(MAD4B_SCP_Context_Authority::review_policy()['mode']??'human_only')
                :'human_only');
        return [
            'contract'=>'mad4b.media-review-plan.v1','complete'=>true,
            'site_uuid'=>$site['site_uuid'],'environment'=>$site['environment']??'',
            'post_id'=>$post_id,'tour_title'=>$title,'tour_url'=>get_permalink($post_id),
            'destination_terms'=>$destinations,'destination_signals'=>$signals,
            'source_gallery_sha256'=>hash('sha256',$raw),
            'source_modified_gmt'=>(string)$post->post_modified_gmt,
            'gallery_entries'=>$resolution['total_entries'],
            'verified_local'=>$resolution['verified_local'],
            'unresolved'=>$resolution['unresolved'],
            'duplicate_attachment_records'=>$resolution['equivalent_attachment_records'],
            'foreign_origin_count'=>$resolution['foreign_origin_count'],
            'resolution_strategies'=>$resolution['strategies'],
            'media_samples'=>$samples,
            'active_alert_intake'=>$requests,
            'issues_requiring_review'=>array_values(array_unique($issues)),
            'shared_visual_fields'=>(array)($record['shared_visual_fields']??[]),
            'review_mode_inherited'=>$route,
            'locale_media_seo_audit'=>class_exists(
                'MAD4B_SCP_Provider_Field_Intelligence',false)
                ?MAD4B_SCP_Provider_Field_Intelligence::locale_media_audit($post_id)
                :['complete'=>false,'error'=>'field_provider_intelligence_unavailable'],
            'visual_semantic_strategy_context'=>class_exists(
                'MAD4B_SCP_Visual_SEO_Strategy',false)
                ?MAD4B_SCP_Visual_SEO_Strategy::inspect($post_id,'featured')
                :['ready'=>false,'reason'=>'visual_strategy_engine_unavailable'],
            'tour_specific_media_reconciliation'=>class_exists(
                'MAD4B_Tour_Media_Reconcile_V1',false)
                ?self::candidate_reconciliation($post_id)
                :['available'=>false,'error'=>'media_reconcile_engine_unavailable'],
            'next_action'=>'verify_photographic_subject_and_brand_fit_then_submit_media_review_content_job',
            'editing_restrictions'=>[
                'original_gallery_meta_write'=>false,
                'automatic_media_replacement'=>false,
                'website_publication'=>false,
                'destination_approval_from_filename'=>false,
            ],
        ];
    }

    public static function cli($args,$assoc){
        if(!$assoc && count($args)===2 && $args[0]==='plan'
           && ctype_digit((string)$args[1])){
            $plan=self::review_plan((int)$args[1]);
            WP_CLI::line(wp_json_encode($plan,
                JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            if(empty($plan['complete']))WP_CLI::halt(3);
            return;
        }
        if($args || $assoc)WP_CLI::error('Use no arguments, or plan POST_ID.');
        $report=self::scan();
        // Exclude the detailed per-post evidence from the top-level summary.
        $issues=$report['issues']??[];unset($report['issues']);
        $report['issue_codes']=array_count_values(array_column($issues,'code'));
        $report['affected_posts']=count(array_unique(array_column($issues,'object_id')));
        WP_CLI::line(wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        if(empty($report['complete']))WP_CLI::halt(3);
    }
}
add_filter('mad4b/content_readiness/external_issues',
    ['MAD4B_Media_Origin_Integrity_V1','add_issues'],34,2);
if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b media-integrity',
        [MAD4B_Media_Origin_Integrity_V1::class,'cli']);
