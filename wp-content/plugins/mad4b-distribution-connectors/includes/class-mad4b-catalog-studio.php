<?php
/** Catalog Studio v1: internal lifecycle on top of Meta Catalog Feed Mapper. */
defined('ABSPATH') || exit;
final class MAD4B_Catalog_Studio_V1 {
    public const STORE='mad4b_catalog_studio_receipts_v1';
    private const PAGE='mcfm_feeds_v2';
    private const MAX_ROWS=48;
    private const MAX_SAMPLE=5;
    private static function mapper(): bool {
        return function_exists('mcfm_get_feeds') && function_exists('mcfm_update_feeds')
            && function_exists('mcfm_iterate_feed_rows');
    }
    private static function admin(): bool {return current_user_can('manage_options');}
    public static function page_url(string $slug='',array $more=[]): string {
        $args=['page'=>self::PAGE];if($slug!=='')$args['feed']=$slug;
        return add_query_arg(array_merge($args,$more),admin_url('options-general.php'));
    }
    public static function is_private(array $feed): bool {
        return str_starts_with((string)($feed['slug']??''),'mad4b-managed-');
    }
    public static function get(string $slug): ?array {
        if(!self::mapper())return null;
        foreach(mcfm_get_feeds() as $i=>$row){
            if(($row['slug']??null)===$slug)return ['index'=>$i,'feed'=>mcfm_normalize_feed($row)];
        }
        return null;
    }
    public static function receipts(): array {
        $rows=get_option(self::STORE,[]);return is_array($rows)?$rows:[];
    }
    public static function fingerprint(array $feed): string {
        // Complete source/profile contract. Format delimiter, provider policy,
        // all mapping options and source-data epoch must invalidate stale Ready.
        $picked=function_exists('mcfm_normalize_feed')?mcfm_normalize_feed($feed):$feed;
        $picked['source_epoch']=class_exists('MAD4B_Catalog_Data_Epoch_V1')
            ? MAD4B_Catalog_Data_Epoch_V1::epoch((string)($picked['object_type']??''),
                (string)($picked['object_key']??'')):'0';
        $picked['review_contract']='mad4b.catalog-studio-fingerprint.v2';
        return hash('sha256',wp_json_encode($picked,JSON_UNESCAPED_SLASHES));
    }
    /** Provider-readiness evidence from the same bounded mapped sample.
     * This is advisory only; the company Google TTD readiness scanner owns tasks.
     */
    public static function commercial_sample(array $feed,array $sample): array {
        if(($feed['object_type']??'')!=='post_type' ||
           ($feed['object_key']??'')!=='tours-and-activities')
            return ['scope'=>'not_applicable'];
        $total=count($sample);$priced=0;$ready=0;$request=0;$missing=[];
        $priceReadyMapped=false;
        foreach((array)($feed['mapping']??[]) as $map)
            if(($map['column']??'')==='google_price_ready')$priceReadyMapped=true;
        foreach($sample as $row){
            if(is_numeric($row['weighted_price']??null) && (float)$row['weighted_price']>0)$priced++;
            if(in_array(strtolower((string)($row['google_price_ready']??'')),['1','true','yes'],true))$ready++;
            if(($row['price_state']??'')==='price_on_request')$request++;
            foreach(array_filter(explode(',',(string)($row['google_checks_missing']??''))) as $part){
                $key=sanitize_key(trim($part));
                if($key!=='')$missing[$key]=($missing[$key]??0)+1;
            }
        }
        $blocked=$total-$ready;
        return ['scope'=>'google_things_to_do','sampled'=>$total,'priced'=>$priced,
            'price_on_request'=>$request,'price_ready'=>$ready,
            'price_unverified'=>$blocked,'missing_checks'=>$missing,
            'price_ready_mapped'=>$priceReadyMapped,
            'provider_submission_ready'=>false,
            'status'=>!$priceReadyMapped?'price_ready_column_missing':
                ($blocked>0?'action_required':'price_sample_passed_provider_unverified')];
    }
    public static function get_state(array $feed): array {
        $item=self::receipts()[$feed['slug']]??[];
        $checked=is_array($item)?strtotime((string)($item['checked_at']??'')):false;
        $fresh=is_array($item)&&$checked!==false && $checked<=time()+60
            && time()-$checked<=3600
            && ($item['fingerprint']??'')===self::fingerprint($feed);
        $status=$fresh?($item['status']??'DRAFT'):'DRAFT';
        return ['status'=>$status,'fresh'=>$fresh,'checked_at'=>$fresh?($item['checked_at']??''):null,
            'issues'=>$fresh?(array)($item['issues']??[]):[],
            'warnings'=>$fresh?(array)($item['warnings']??[]):[],
            'sample_count'=>$fresh?(int)($item['sample_count']??0):0,
            'validation_hash'=>$fresh?($item['fingerprint']??null):null,
            'commercial_sample'=>$fresh?(array)($item['commercial_sample']??[]):[]];
    }
    private static function update_receipt(string $slug,array $value): bool {
        $rows=self::receipts();$rows[$slug]=$value;
        return update_option(self::STORE,$rows,false) || get_option(self::STORE,[])===$rows;
    }
    public static function validate_structure(array $feed): array {
        $issues=[];$warnings=[];$names=[];
        if(!self::is_private($feed))$issues[]='This feed is not private. Copy it to a managed profile before activating internally.';
        if(!in_array($feed['object_type']??'',['taxonomy','post_type'],true))$issues[]='Invalid object type.';
        if(($feed['object_type']??'')==='post_type' && !post_type_exists((string)($feed['object_key']??'')))$issues[]='The source post type is not registered.';
        if(($feed['object_type']??'')==='taxonomy' && !taxonomy_exists((string)($feed['object_key']??'')))$issues[]='The source taxonomy is not registered.';
        $mapping=(array)($feed['mapping']??[]);
        if(!$mapping)$issues[]='At least one mapping row is required.';
        if(count($mapping)>self::MAX_ROWS)$issues[]='Maximum 48 output columns.';
        foreach($mapping as $i=>$row){
            $col=trim((string)($row['column']??''));$source=trim((string)($row['source']??''));
            $type=(string)($row['source_type']??'');
            if($col==='' || !preg_match('/^[A-Za-z_][A-Za-z0-9_.\[\]-]{0,95}$/D',$col))$issues[]='Mapping '.($i+1).': output column is invalid.';
            if(preg_match('/(?:secret|password|credential|private.?key|access.?token|api.?token)/i',$col))
                $issues[]='Sensitive output column is forbidden: '.$col;
            if(isset($names[$col]))$issues[]='Duplicate output column: '.$col;
            $names[$col]=true;
            if(!in_array($type,['object_field','meta','taxonomy_terms','featured_image','computed','static','language_code'],true))$issues[]='Mapping '.($i+1).': source type unsupported.';
            if(($feed['object_type']??'')==='taxonomy' && in_array($type,['featured_image','taxonomy_terms'],true))$issues[]='Mapping '.($i+1).': this source type requires a post type.';
            if($source==='' && !in_array($type,['featured_image','language_code'],true))$issues[]='Mapping '.($i+1).': source field is empty.';
        }
        if(!isset($names['id']) && !isset($names['destination_id']))$warnings[]='Recommended identity column missing (id / destination_id).';
        if(!isset($names['url']) && !isset($names['link']))$warnings[]='Recommended landing URL column missing (url / link).';
        if(!isset($names['weighted_price']) && !isset($names['price']))$warnings[]='No weighted-price output. External Google/Meta product eligibility remains unverified.';
        if(($feed['object_key']??'')==='tours-and-activities')$warnings[]='Google Things to do eligibility (booking, fees, occupancy and availability) requires separate provider checks.';
        return ['issues'=>array_values(array_unique($issues)),'warnings'=>array_values(array_unique($warnings))];
    }
    public static function sample(array $feed,int $limit=self::MAX_SAMPLE,string $language=''): array {
        if(!self::mapper())return [];
        $limit=max(1,min(self::MAX_SAMPLE,$limit));
        $feed=mcfm_normalize_feed($feed);
        $language=sanitize_key($language);
        if($language!=='' && function_exists('mcfm_get_active_languages')
            && !array_key_exists($language,(array)mcfm_get_active_languages()))
            throw new RuntimeException('preview_language_not_enabled');
        $lang=mcfm_resolve_requested_language($feed,$language?:null);
        $originalLang=mcfm_is_wpml_active()?apply_filters('wpml_current_language',null):null;
        $rows=[];
        if($lang)mcfm_switch_language($lang);
        try {
            $perPage=24;
            for($page=0;$page<4 && count($rows)<$limit;$page++){
                if($feed['object_type']==='taxonomy'){
                    if(function_exists('mcfm_prefetch_wpml_taxonomy_ids'))
                        mcfm_prefetch_wpml_taxonomy_ids($feed['object_key']);
                    $objects=get_terms(['taxonomy'=>$feed['object_key'],
                        'hide_empty'=>!empty($feed['hide_empty_terms']),
                        'number'=>$perPage,'offset'=>$page*$perPage,
                        'orderby'=>'term_id','order'=>'ASC','update_term_meta_cache'=>true]);
                    if(is_wp_error($objects))throw new RuntimeException('term_preview_query_failed');
                    if($objects)update_meta_cache('term',array_map(static fn($x)=>(int)$x->term_id,$objects));
                }else{
                    $query=new WP_Query(['post_type'=>$feed['object_key'],
                        'post_status'=>array_values(array_filter(array_map('trim',explode(',',(string)$feed['status'])))),
                        'posts_per_page'=>$perPage,'paged'=>$page+1,'no_found_rows'=>true,
                        'orderby'=>'ID','order'=>'ASC','ignore_sticky_posts'=>true,
                        'update_post_meta_cache'=>true,'update_post_term_cache'=>true,'suppress_filters'=>false]);
                    $objects=$query->posts;
                }
                try {
                    foreach((array)$objects as $object){
                        $row=[];
                        foreach($feed['mapping'] as $map){
                            if(empty($map['column']))continue;
                            $row[$map['column']]=mcfm_resolve_value($feed,$object,$map,$lang);
                        }
                        if(!mcfm_row_passes_required_mapping($feed,$row))continue;
                        if(!apply_filters('mad4b/mcfm/include_row',true,$feed,$object,$row,$lang))continue;
                        $safe=[];
                        foreach($row as $column=>$value){
                            $safe[$column]=preg_match('/secret|password|credential|api.?token|private.?key/i',(string)$column)
                                ?'[redacted]':mb_strimwidth((string)$value,0,300,'…');
                        }
                        $rows[]=$safe;
                        if(count($rows)>=$limit)break;
                    }
                }finally {if(isset($query)){wp_reset_postdata();unset($query);}}
                if(count((array)$objects)<$perPage)break;
            }
        }finally {if($originalLang!==null)mcfm_switch_language($originalLang);}
        return $rows;
    }
    public static function validate_feed(array $feed,bool $collect=true): array {
        $static=self::validate_structure($feed);
        $sample=[];$issues=$static['issues'];$warnings=$static['warnings'];
        if(!$issues && $collect) {
            try {$sample=self::sample($feed);}catch(Throwable $e){$issues[]='Source sample failed. Check the source post type and resolver.';}
            if(!$sample)$issues[]='No eligible rows among the first 96 candidates; review filters or another language.';
            foreach($sample as $i=>$row){
                foreach((array)$feed['mapping'] as $m){
                    if(!empty($m['required_non_empty'])&&mcfm_is_empty_feed_value($row[$m['column']]??null))
                        $issues[]='Sample '.($i+1).': required field '.$m['column'].' is empty.';
                }
            }
        }
        $issues=array_values(array_unique($issues));
        return ['status'=>$issues?'VALIDATION_BLOCKED':'VALIDATED_INTERNAL',
            'issues'=>$issues,'warnings'=>$warnings,'sample_count'=>count($sample),
            'commercial_sample'=>self::commercial_sample($feed,$sample),
            'fingerprint'=>self::fingerprint($feed),'checked_at'=>gmdate('c'),
            'external_delivery_enabled'=>false];
    }
    public static function preview_and_record(string $slug,string $language=''): array|WP_Error {
        if(!current_user_can('manage_options'))return new WP_Error('catalog_admin_required');
        $found=self::get($slug);if(!$found)return new WP_Error('feed_not_found');
        if(!self::is_private($found['feed']))return new WP_Error('copy_unmanaged_feed_first');
        $structure=self::validate_structure($found['feed']);
        if($structure['issues'])return new WP_Error('fix_mapping_before_preview');
        $before=self::fingerprint($found['feed']);
        try{$sample=self::sample($found['feed'],self::MAX_SAMPLE,$language);}
        catch(Throwable $e){return new WP_Error('preview_source_unavailable');}
        if($before!==self::fingerprint($found['feed']))return new WP_Error('source_changed_during_preview');
        $record=['slug'=>$slug,'hash'=>$before,
            'sample'=>$sample,'created_at'=>gmdate('c'),'language'=>$language];
        set_transient('mad4b_studio_preview_'.get_current_user_id().'_'.$slug,$record,600);
        return ['state'=>'PREVIEW_READY','slug'=>$slug,'sample_count'=>count($sample),
            'external_delivery_enabled'=>false];
    }
    public static function get_preview(string $slug): array {
        $found=self::get($slug);if(!$found)return [];
        $current=get_transient('mad4b_studio_preview_'.get_current_user_id().'_'.$slug);
        if(!is_array($current)||($current['hash']??'')!==self::fingerprint($found['feed']))return [];
        return (array)($current['sample']??[]);
    }
    public static function get_import_review(string $slug): array {
        $found=self::get($slug);if(!$found)return [];
        $item=get_transient('mad4b_studio_import_'.get_current_user_id().'_'.$slug);
        if(!is_array($item)||($item['feed_hash']??'')!==self::fingerprint($found['feed']))return [];
        return ['token'=>(string)($item['review_token']??''),'mapping'=>(array)($item['mapping']??[])];
    }
    public static function validate_and_record(string $slug): array|WP_Error {
        if(!current_user_can('manage_options'))return new WP_Error('catalog_admin_required');
        $found=self::get($slug);if(!$found)return new WP_Error('feed_not_found');
        $expected=self::fingerprint($found['feed']);
        $result=self::validate_feed($found['feed']);
        if($expected!==self::fingerprint($found['feed']))
            return new WP_Error('source_changed_during_validation');
        if(!self::update_receipt($slug,$result))return new WP_Error('receipt_write_failed');
        return $result;
    }
    public static function save_mapping(string $slug,array $request): array|WP_Error {
        if(!current_user_can('manage_options'))return new WP_Error('catalog_admin_required');
        $found=self::get($slug);if(!$found)return new WP_Error('feed_not_found');
        $feed=$found['feed'];if(!self::is_private($feed))return new WP_Error('copy_unmanaged_feed_first');
        $columns=(array)($request['column']??[]);
        $types=(array)($request['source_type']??[]);
        $sources=(array)($request['source']??[]);
        $extras=(array)($request['extra']??[]);
        $required=(array)($request['required']??[]);
        if(count($columns)>self::MAX_ROWS)return new WP_Error('mapping_column_limit');
        $mapping=[];
        foreach($columns as $i=>$col){
            $col=sanitize_text_field((string)$col);
            if($col==='')continue;
            $mapping[]=mcfm_sanitize_mapping_row([
                'column'=>$col,'source_type'=>(string)($types[$i]??''),
                'source'=>(string)($sources[$i]??''),
                'extra'=>(string)($extras[$i]??''),
                'required_non_empty'=>!empty($required[$i])?1:0]);
        }
        $next=$feed;$next['mapping']=$mapping;
        $check=self::validate_structure($next);
        if($check['issues'])return new WP_Error('mapping_invalid',implode(' | ',$check['issues']));
        if($next['mapping']===$feed['mapping'])return ['state'=>'UNCHANGED','slug'=>$slug];
        $all=mcfm_get_feeds();$all[$found['index']]=$next;
        mcfm_update_feeds($all);
        $back=self::get($slug);
        if(!$back || $back['feed']['mapping']!==$mapping)return new WP_Error('mapping_readback_failed');
        return ['state'=>'MAPPING_SAVED_REVALIDATION_REQUIRED','slug'=>$slug,'columns'=>count($mapping)];
    }
    public static function save_profile(string $slug,array $request): array|WP_Error {
        if(!current_user_can('manage_options'))return new WP_Error('catalog_admin_required');
        $found=self::get($slug);if(!$found)return new WP_Error('feed_not_found');
        $feed=$found['feed'];if(!self::is_private($feed))return new WP_Error('copy_unmanaged_feed_first');
        $format=sanitize_key((string)($request['format']??''));
        $language=sanitize_key((string)($request['language_mode']??''));
        if(!in_array($format,['csv','tsv','xml','rss','atom','xlsx'],true))return new WP_Error('format_not_supported');
        if(!in_array($language,['all','default','specific'],true))return new WP_Error('language_mode_invalid');
        $feed['default_format']=$format;
        $feed['delimiter']=$format==='tsv'?"\t":',';
        $feed['language_mode']=$language;
        $feed['language_code']=$language==='specific'?sanitize_key((string)($request['language_code']??'')):'';
        if($language==='specific' && !preg_match('/^[a-z]{2}(-[a-z]{2})?$/',$feed['language_code']))return new WP_Error('language_code_invalid');
        $feed['hide_empty_terms']=!empty($request['hide_empty_terms'])?1:0;
        $all=mcfm_get_feeds();$all[$found['index']]=$feed;mcfm_update_feeds($all);
        $back=self::get($slug);
        if(!$back || self::fingerprint($back['feed'])!==self::fingerprint($feed))return new WP_Error('profile_readback_failed');
        return ['state'=>'PROFILE_SAVED_REVALIDATION_REQUIRED','slug'=>$slug];
    }
    public static function create(array $input): array|WP_Error {
        if(!current_user_can('manage_options'))return new WP_Error('catalog_admin_required');
        if(!self::mapper())return new WP_Error('mapper_unavailable');
        $label=sanitize_text_field((string)($input['label']??''));
        $rawSlug=sanitize_title((string)($input['slug']??''));
        $rawSlug=preg_replace('/^mad4b-managed-/','',$rawSlug);
        $slug='mad4b-managed-'.$rawSlug;
        $type=sanitize_key((string)($input['object_type']??''));
        $key=sanitize_key((string)($input['object_key']??''));
        if(strlen($label)<3||strlen($label)>100||strlen($slug)>90||$slug==='mad4b-managed-' ||
            !in_array($type,['taxonomy','post_type'],true))return new WP_Error('invalid_new_feed');
        if($type==='taxonomy'?!taxonomy_exists($key):!post_type_exists($key))return new WP_Error('unknown_feed_source');
        if(self::get($slug))return new WP_Error('feed_slug_exists');
        $new=mcfm_default_feed();
        $new['slug']=$slug;$new['label']=$label;$new['object_type']=$type;$new['object_key']=$key;
        $new['default_format']='csv';$new['status']='publish';$new['mapping']=[];
        $all=mcfm_get_feeds();$all[]=$new;mcfm_update_feeds($all);
        if(!self::get($slug))return new WP_Error('create_readback_failed');
        return ['state'=>'PRIVATE_DRAFT_CREATED','slug'=>$slug,'external_delivery_enabled'=>false];
    }
    public static function duplicate(string $slug): array|WP_Error {
        if(!current_user_can('manage_options'))return new WP_Error('catalog_admin_required');
        $found=self::get($slug);if(!$found)return new WP_Error('feed_not_found');
        $feed=$found['feed'];$base='mad4b-managed-'.substr(sanitize_title(preg_replace('/^mad4b-managed-/','',$slug)),0,55).'-copy';
        $newSlug=$base;
        for($i=2;self::get($newSlug)&&$i<100;$i++)$newSlug=$base.'-'.$i;
        if(self::get($newSlug))return new WP_Error('feed_duplicate_limit');
        $new=$feed;$new['slug']=$newSlug;$new['label']=substr($feed['label'].' (copy)',0,100);
        $all=mcfm_get_feeds();$all[]=$new;mcfm_update_feeds($all);
        if(!self::get($newSlug))return new WP_Error('duplicate_readback_failed');
        return ['state'=>'PRIVATE_DRAFT_DUPLICATED','slug'=>$newSlug,'external_delivery_enabled'=>false];
    }
    public static function publish_internal(string $slug): array|WP_Error {
        if(!current_user_can('manage_options'))return new WP_Error('catalog_admin_required');
        $found=self::get($slug);if(!$found)return new WP_Error('feed_not_found');
        $feed=$found['feed'];if(!self::is_private($feed))return new WP_Error('cannot_publish_unmanaged_feed');
        $reviews=self::receipts();$item=$reviews[$slug]??[];
        $state=self::get_state($feed);
        if(empty($state['fresh']) || $state['status']!=='VALIDATED_INTERNAL'
            || (int)$state['sample_count']<1)
            return new WP_Error('validate_current_mapping_first');
        // Publishing means ONLY internal preview ready; no public rewrite, cron or partner upload.
        $item['status']='READY_INTERNAL';$item['published_internal_at']=gmdate('c');
        if(!self::update_receipt($slug,$item))return new WP_Error('internal_publishing_readback_failed');
        return ['state'=>'READY_INTERNAL','slug'=>$slug,'external_delivery_enabled'=>false];
    }
    public static function import_review(string $slug,array $upload): array|WP_Error {
        if(!current_user_can('manage_options'))return new WP_Error('catalog_admin_required');
        $found=self::get($slug);if(!$found)return new WP_Error('feed_not_found');
        if(!self::is_private($found['feed']))return new WP_Error('copy_unmanaged_feed_first');
        $file=$upload['template_file']??null;
        if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK ||
            (int)($file['size']??0)>512000)return new WP_Error('import_upload_missing_or_too_large');
        $parsed=mcfm_extract_template_payload_from_upload($file);
        if(is_wp_error($parsed))return $parsed;
        $mapping=(array)($parsed['payload']['mapping']??[]);
        if(!$mapping||count($mapping)>self::MAX_ROWS)return new WP_Error('import_mapping_invalid_size');
        $feed=$found['feed'];$feed['mapping']=array_map('mcfm_sanitize_mapping_row',$mapping);
        $check=self::validate_structure($feed);
        if($check['issues'])return new WP_Error('import_mapping_invalid',implode(' | ',$check['issues']));
        $token=wp_generate_password(24,false,false);
        set_transient('mad4b_studio_import_'.get_current_user_id().'_'.$slug,
            ['token_hash'=>hash('sha256',$token),'review_token'=>$token,'mapping'=>$feed['mapping'],'feed_hash'=>self::fingerprint($found['feed'])],600);
        return ['state'=>'IMPORT_STAGED_CONFIRM_REQUIRED','slug'=>$slug,'token'=>$token,'columns'=>count($feed['mapping'])];
    }
    public static function apply_import(string $slug,string $token): array|WP_Error {
        if(!current_user_can('manage_options'))return new WP_Error('catalog_admin_required');
        $name='mad4b_studio_import_'.get_current_user_id().'_'.$slug;
        $draft=get_transient($name);
        if(!is_array($draft)||!hash_equals((string)($draft['token_hash']??''),hash('sha256',$token)))return new WP_Error('import_review_expired');
        $found=self::get($slug);if(!$found || self::fingerprint($found['feed'])!==($draft['feed_hash']??null))
            return new WP_Error('feed_changed_since_import_review');
        $mapped=(array)($draft['mapping']??[]);
        $next=$found['feed'];$next['mapping']=$mapped;
        if(self::validate_structure($next)['issues'])return new WP_Error('import_mapping_requires_revision');
        $all=mcfm_get_feeds();$all[$found['index']]=$next;mcfm_update_feeds($all);
        delete_transient($name);
        $back=self::get($slug);
        if(!$back || $back['feed']['mapping']!==$mapped)return new WP_Error('import_readback_failed');
        return ['state'=>'MAPPING_IMPORTED_REVALIDATION_REQUIRED','slug'=>$slug,'columns'=>count($mapped)];
    }
    /** Exclusive, DB-backed Studio edit gate, scoped to one WordPress installation. */
    public static function guarded_action(string $action,string $slug,string $expected,callable $job) {
        if(!self::admin())return new WP_Error('catalog_admin_required');
        if(!function_exists('mcfm_feed_editor_lock'))return new WP_Error('shared_feed_lock_unavailable');
        $dbLock=mcfm_feed_editor_lock();
        if(is_wp_error($dbLock))return $dbLock;
        try {return self::guarded_action_under_db_lock($action,$slug,$expected,$job);}
        finally {mcfm_feed_editor_unlock();}
    }
    private static function guarded_action_under_db_lock(string $action,string $slug,string $expected,callable $job) {
        $lock='mad4b_catalog_studio_write_lock_v1';
        $token=wp_generate_password(32,false,false);
        $value=['token'=>$token,'created'=>time()];
        if(!add_option($lock,$value,'',false)) {
            $existing=get_option($lock,[]);
            if(!is_array($existing) || time()-(int)($existing['created']??0)<90)
                return new WP_Error('catalog_edit_in_progress');
            if(get_option($lock,[])===$existing)delete_option($lock);
            if(!add_option($lock,$value,'',false))return new WP_Error('catalog_edit_in_progress');
        }
        try {
            if($action!=='create') {
                $found=self::get($slug);
                if(!$found)return new WP_Error('feed_not_found');
                if($expected==='' || !hash_equals(self::fingerprint($found['feed']),$expected))
                    return new WP_Error('feed_revision_conflict');
            }
            return $job();
        }finally {
            $current=get_option($lock,[]);
            if(is_array($current) && hash_equals($token,(string)($current['token']??'')))
                delete_option($lock);
        }
    }
    public static function admin_post(): void {
        if(!self::admin())wp_die('Access denied',403);
        check_admin_referer('mad4b_catalog_studio_action');
        $action=sanitize_key((string)($_POST['studio_action']??''));
        $slug=sanitize_title((string)($_POST['slug']??''));
        $input=wp_unslash($_POST);
        $expected=is_string($input['feed_revision']??null)?$input['feed_revision']:'';
        $result=self::guarded_action($action,$slug,$expected,static fn()=>match($action){
            'create'=>self::create($input),
            'duplicate'=>self::duplicate($slug),
            'save_mapping'=>self::save_mapping($slug,$input),
            'save_profile'=>self::save_profile($slug,$input),
            'validate'=>self::validate_and_record($slug),
            'preview'=>self::preview_and_record($slug,(string)($input['preview_language']??'')),
            'publish_internal'=>self::publish_internal($slug),
            'import_review'=>self::import_review($slug,$_FILES),
            'apply_import'=>self::apply_import($slug,(string)($input['review_token']??'')),
            default=>new WP_Error('catalog_operation_not_supported'),
        });
        $isErr=is_wp_error($result);$next=$isErr?$slug:($result['slug']??$slug);
        $code=$isErr?$result->get_error_code():($result['state']??'completed');
        if(!$isErr && $action==='import_review'){
            $token=$result['token'];
            // Save token in short-lived per-user transient and render in the authenticated
            // review form. Never expose the token in a redirect URL or audit record.
        }
        if(!$isErr && $action==='validate')$code=$result['status'];
        $target=self::page_url($next,['studio_notice'=>sanitize_key(strtolower($code))]);
        wp_safe_redirect($target.'#mad4b-studio-workspace');exit;
    }
    public static function enqueue(string $hook): void {
        if($hook!=='settings_page_'.self::PAGE || !self::admin())return;
        $dir=dirname(__DIR__);
        foreach(['css'=>'catalog-workbench.css','js'=>'catalog-workbench.js'] as $type=>$file){
            $path=$dir.'/assets/'.$file;
            if(!is_readable($path))continue;
            $url=plugins_url('assets/'.$file,$dir.'/mad4b-distribution-connectors.php');
            if($type==='css')wp_enqueue_style('mad4b-catalog-workbench',$url,[],(string)filemtime($path));
            else wp_enqueue_script('mad4b-catalog-workbench',$url,[],(string)filemtime($path),true);
        }
    }
    public static function boot(): void {
        add_action('admin_post_mad4b_catalog_studio_action',[__CLASS__,'admin_post']);
        add_action('admin_enqueue_scripts',[__CLASS__,'enqueue']);
    }
}
MAD4B_Catalog_Studio_V1::boot();
