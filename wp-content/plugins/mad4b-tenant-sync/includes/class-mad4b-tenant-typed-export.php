<?php
/**
 * Feature 009: site-local bounded, typed commercial-content export.
 * Staging WP-CLI only. No full site snapshot, no finance, users or secrets.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Typed_Export_V1 {
    public const CONTRACT='mad4b.tenant-typed-content-bundle.v1';
    private const TYPES=[
        'tours-and-activities','properties','units','transportations',
        'tour-rates','property-rates','transport-rates'
    ];
    private static function global_id(string $site,string $type,int $id):string {
        return $type.'-'.substr(hash('sha256',$site.'|'.$type.'|'.$id),0,32);
    }
    private static function must(bool $yes,string $reason):void {
        if(!$yes)throw new RuntimeException('TYPED_EXPORT_'.$reason);
    }
    private static function site(): array {
        self::must(defined('WP_CLI') && WP_CLI,
            'AUTHORIZED_CLI_REQUIRED');
        self::must(MAD4B_Tenant_Operation_Authority::scope(false)!=='',
            'VERIFIED_SITE_BINDING_REQUIRED');
        $s=MAD4B_SCP_Site_Profile::status();
        self::must(($s['authority_ready']??false)===true
            &&($s['binding_state']??'')==='exact'
            &&($s['environment_match']??false)===true
            &&($s['deployment_binding_match']??false)===true
            &&wp_is_uuid((string)($s['site_uuid']??'')), 'SITE_IDENTITY_UNVERIFIED');
        return ['site_uuid'=>$s['site_uuid'],
            'origin'=>untrailingslashit(home_url('/')),
            'site_profile_revision'=>(int)($s['revision']??0),
            'site_profile_digest'=>(string)($s['profile_digest']??'')];
    }
    private static function language(string $type,int $id):array {
        global $wpdb;
        $table=$wpdb->prefix.'icl_translations';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)
            return ['code'=>'und','trid'=>null,'source_element_type'=>null];
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT language_code,trid,element_type FROM $table
             WHERE element_type=%s AND element_id=%d LIMIT 1",
            'post_'.$type,$id),ARRAY_A);
        return ['code'=>$row['language_code']??'und',
            'trid'=>isset($row['trid'])?(int)$row['trid']:null,
            'source_element_type'=>$row['element_type']??null];
    }
    private static function metadata(int $id):array {
        $keys=['_elementor_data','_elementor_page_settings',
            '_elementor_conditions','_elementor_css'];
        $output=[];
        foreach($keys as $key) {
            $value=get_post_meta($id,$key,true);
            if($value===null || $value==='' || $value===[])continue;
            $raw=maybe_serialize($value);
            $output[$key]=['sha256'=>hash('sha256',(string)$raw),
                'bytes'=>strlen((string)$raw),'raw_exported'=>false,
                'typed_provider_readback_required'=>true];
        }
        return $output;
    }
    public static function create(array $ids):array {
        $identity=self::site();
        self::must(count($ids)>=1 && count($ids)<=12,'ITEM_LIMIT');
        $ids=array_map('intval',$ids);
        self::must(count(array_unique($ids))===count($ids)
            &&min($ids)>0,'POST_ID_INVALID');
        $posts=[];$terms=[];$media=[];$idsByPost=[];
        foreach($ids as $id) {
            $p=get_post($id);
            self::must($p instanceof WP_Post
                &&in_array($p->post_type,self::TYPES,true)
                &&$p->post_status==='publish','POST_NOT_APPROVED_FOR_SAMPLE');
            $gid=self::global_id($identity['site_uuid'],'post', $id);
            $idsByPost[$id]=$gid;
            $lang=self::language($p->post_type,$id);
            $post=[
                'global_id'=>$gid,'source_post_id'=>$id,'source_type'=>$p->post_type,
                'status'=>'import_as_private_draft_only','language'=>$lang,
                'title'=>$p->post_title,'slug'=>$p->post_name,
                'excerpt'=>$p->post_excerpt,'content'=>$p->post_content,
                'source_post_modified_gmt'=>$p->post_modified_gmt,
                'taxonomy_term_ids'=>[],
                'featured_media_global_id'=>null,
                'elementor_source_evidence'=>self::metadata($id),
            ];
            foreach(get_object_taxonomies($p->post_type) as $tax) {
                $found=wp_get_object_terms($id,$tax,['fields'=>'all']);
                if(is_wp_error($found))continue;
                foreach($found as $term) {
                    $tid=(int)$term->term_id;
                    $parent=(int)$term->parent;
                    $key=self::global_id($identity['site_uuid'],'term',$tid);
                    $post['taxonomy_term_ids'][]=$key;
                    if(isset($terms[$key]))continue;
                    $terms[$key]=[
                        'global_id'=>$key,'source_term_id'=>$tid,
                        'source_taxonomy'=>$tax,'slug'=>$term->slug,'name'=>$term->name,
                        'parent_source_term_id'=>$parent,
                        'term_taxonomy_id'=>(int)$term->term_taxonomy_id,
                        'description_sha256'=>hash('sha256',(string)$term->description),
                    ];
                }
            }
            $thumb=(int)get_post_thumbnail_id($id);
            if($thumb>0){
                $file=get_attached_file($thumb);
                $upload=wp_get_upload_dir();
                $base=realpath($upload['basedir']);
                $real=is_string($file)?realpath($file):false;
                self::must($base!==false&&$real!==false
                    &&str_starts_with($real,$base.DIRECTORY_SEPARATOR),'MEDIA_PATH_UNTRUSTED');
                $bytes=filesize($real);
                self::must($bytes>0&&$bytes<=4000000,'MEDIA_EXCEEDS_SAMPLE_LIMIT');
                $mime=get_post_mime_type($thumb);
                self::must(in_array($mime,['image/jpeg','image/png','image/webp'],true),
                    'MEDIA_TYPE_NOT_ALLOWED_FOR_QA');
                $mid=self::global_id($identity['site_uuid'],'media',$thumb);
                $post['featured_media_global_id']=$mid;
                $media[$mid]=[
                    'global_id'=>$mid,'source_attachment_id'=>$thumb,
                    'relative_upload_path'=>ltrim(substr($real,strlen($base)),DIRECTORY_SEPARATOR),
                    'mime'=>$mime,'bytes'=>$bytes,'sha256'=>hash_file('sha256',$real),
                    'import_status'=>'requires_hash_verified_file_bytes',
                ];
            }
            $posts[$gid]=$post;
        }
        // Include every ancestor of the selected terms, not only terms directly
        // assigned to selected posts. This makes the QA remap graph complete.
        foreach(array_values($terms) as $entry) {
            $parent=(int)$entry['parent_source_term_id'];
            $depth=0;
            while($parent>0) {
                self::must(++$depth<=16,'TERM_PARENT_CYCLE');
                $key=self::global_id($identity['site_uuid'],'term',$parent);
                if(isset($terms[$key])) {
                    $parent=(int)$terms[$key]['parent_source_term_id'];
                    continue;
                }
                $ancestor=get_term($parent,$entry['source_taxonomy']);
                self::must($ancestor instanceof WP_Term,'PARENT_TERM_MISSING');
                $terms[$key]=[
                    'global_id'=>$key,'source_term_id'=>(int)$ancestor->term_id,
                    'source_taxonomy'=>$entry['source_taxonomy'],
                    'slug'=>$ancestor->slug,'name'=>$ancestor->name,
                    'parent_source_term_id'=>(int)$ancestor->parent,
                    'term_taxonomy_id'=>(int)$ancestor->term_taxonomy_id,
                    'description_sha256'=>hash('sha256',(string)$ancestor->description),
                ];
                self::must(count($terms)<=240,'TERM_LIMIT');
                $parent=(int)$ancestor->parent;
            }
        }
        global $wpdb;
        $edges=[];
        $relTable=$wpdb->prefix.'jet_rel_default';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$relTable))===$relTable) {
            $placeholders=implode(',',array_fill(0,count($ids),'%d'));
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT rel_id,parent_object_id,child_object_id FROM $relTable
                  WHERE parent_object_id IN ($placeholders) AND child_object_id IN ($placeholders)",
                ...array_merge($ids,$ids)),ARRAY_A);
            foreach((array)$rows as $row) {
                $parent=(int)$row['parent_object_id'];$child=(int)$row['child_object_id'];
                $edges[]=[
                    'relation_global_id'=>self::global_id($identity['site_uuid'],'relation',
                        (int)$row['rel_id']),
                    'source_relation_id'=>(int)$row['rel_id'],
                    'parent_post_global_id'=>$idsByPost[$parent],
                    'child_post_global_id'=>$idsByPost[$child],
                    'target_status'=>'requires_relation_provider',
                ];
            }
        }
        $result=[
            'contract'=>self::CONTRACT,'source_site'=>$identity,
            'export_scope'=>'explicit_public_content_qa_sample',
            'posts'=>array_values($posts),'terms'=>array_values($terms),
            'media'=>array_values($media),'relations'=>$edges,
            'schema_provider_states'=>[
                'jetengine'=>'inventory_only_not_activated',
                'jetengine_queries'=>'schema_hashes_only_no_raw_query_import',
                'jetengine_page_options'=>'schema_hashes_only_no_values_import',
                'jetsmartfilters'=>'schema_hashes_only_no_active_filters',
                'elementor'=>'raw_source_not_imported_or_executed',
                'fluent_forms'=>'schema_inventory_only_no_submissions',
                'wpml'=>'translation_group_lineage_only_not_applied',
                'pricing'=>'not_included','booking'=>'not_included',
            ],
            'financial_data_exported'=>false,
            'customer_data_exported'=>false,
            'media_bytes_included_in_json'=>false,
            'publication_authorized'=>false,
            'company_detach_authorized'=>false,
        ];
        $result['payload_sha256']=hash('sha256',wp_json_encode($result,
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $result;
    }
    public static function cli($args,$assoc):void {
        if($args!==['bundle']||array_diff(array_keys($assoc),['post-ids'])
           ||!isset($assoc['post-ids'])
           ||!preg_match('/^[1-9][0-9]*(,[1-9][0-9]*){0,11}$/D',$assoc['post-ids']))
            WP_CLI::error('EXACT_PUBLIC_SAMPLE_POST_IDS_REQUIRED');
        try {
            WP_CLI::line(wp_json_encode(self::create(explode(',',$assoc['post-ids'])),
                JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        } catch (Throwable $exception) {
            WP_CLI::error($exception->getMessage());
        }
    }
    public static function boot():void {
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b typed-export',[__CLASS__,'cli']);
    }
}
MAD4B_Tenant_Typed_Export_V1::boot();
