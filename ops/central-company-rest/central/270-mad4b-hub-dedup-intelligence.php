<?php
/**
 * Plugin Name: MAD4B Content Hub Intelligence Dedup Discovery
 * Description: Generic, tenant-scoped, read-only duplicate discovery; specialized providers can extend the report.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Dedup_Intelligence_V1 {
    const CONTRACT='mad4b.hub.dedup-discovery.v1';
    const CPT='mad4b_hub_content';
    const MAX=3000;
    const SAMPLES=30;
    private static $adapters=[];

    public static function enabled(): bool {
        return post_type_exists(self::CPT) &&
            wp_get_environment_type()==='staging' &&
            strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))==='tours.dev.mad4b.com';
    }
    public static function register_adapter($id,$callback): bool {
        if(!is_string($id) || !preg_match('/^[a-z0-9_]{3,45}$/D',$id) ||
            !is_callable($callback) || isset(self::$adapters[$id]))return false;
        self::$adapters[$id]=$callback;
        ksort(self::$adapters,SORT_STRING);
        return true;
    }
    public static function adapters(): array {return array_keys(self::$adapters);}
    private static function error($code,$status=400) {
        return new WP_Error('mad4b_hub_dedup_'.$code,'Dedup discovery unavailable',['status'=>$status]);
    }
    private static function allowed(): bool {
        return current_user_can('manage_options') ||
            (defined('WP_CLI') && WP_CLI && PHP_SAPI==='cli');
    }
    private static function norm($value): string {
        $txt=mb_strtolower(trim(wp_strip_all_tags((string)$value)),'UTF-8');
        $txt=preg_replace('/[\p{Z}\p{P}\s]+/u',' ',$txt);
        return is_string($txt)?trim($txt):'';
    }
    private static function groups($groups,$decision): array {
        $samples=[];$count=0;$records=0;
        foreach($groups as $values) {
            if(count($values)<2)continue;
            $count++;$records+=count($values);
            if(count($samples)<self::SAMPLES)$samples[]=[
                'decision'=>$decision,'hub_post_ids'=>array_values($values),
                'auto_merge'=>false
            ];
        }
        return ['groups'=>$count,'records'=>$records,
            'extra_records'=>$records-$count,'samples'=>$samples];
    }
    /**
     * Read-only generic core. Never assume same title/body means same article.
     * Blank bodies are *unknown*, not identical fingerprints.
     * Adapter callbacks receive scoped records and may only return review evidence.
     */
    public static function discover(array $scope=[]) {
        if(!self::enabled() || !self::allowed())return self::error('forbidden',403);
        if(array_diff(array_keys($scope),['company_id','source_type']))
            return self::error('invalid_scope');
        $company=$scope['company_id']??'';
        $type=$scope['source_type']??'';
        if(!is_string($company)||!is_string($type)||
           ($company!==''&&!preg_match('/^[a-z0-9-]{3,70}$/D',$company)) ||
           ($type!==''&&!preg_match('/^[a-z0-9_-]{1,55}$/D',$type)))
            return self::error('invalid_scope');
        if($company!=='' && class_exists('MAD4B_Central_Company_REST_V1') &&
           !array_key_exists($company,MAD4B_Central_Company_REST_V1::COMPANIES))
            return self::error('unknown_company',404);
        $ids=get_posts(['post_type'=>self::CPT,
            'post_status'=>['private','draft','pending'],'fields'=>'ids',
            'numberposts'=>-1,'suppress_filters'=>true]);
        if(!is_array($ids)||count($ids)>self::MAX)
            return self::error('scan_limit',409);
        $index=[];$titleGroups=[];$fingerprints=[];$records=[];$sourceCounts=[];
        $crossTitleGroups=[];$translationGroups=[];
        $emptyBodies=0;$nonemptyBodies=0;$unusable=0;$privateCount=0;
        foreach($ids as $id) {
            $id=(int)$id;$p=get_post($id);
            if(!$p||$p->post_type!==self::CPT)continue;
            $owner=(string)get_post_meta($id,'_mad4b_hub_import_owner',true);
            $srcType=(string)get_post_meta($id,'_mad4b_hub_import_type',true);
            $language=(string)get_post_meta($id,'_mad4b_hub_import_language',true);
            $sourceId=(int)get_post_meta($id,'_mad4b_hub_import_source_id',true);
            if(($company!==''&&$owner!==$company)||($type!==''&&$srcType!==$type))continue;
            if($owner===''||$sourceId<1||$srcType===''||$language===''){$unusable++;continue;}
            $key=$owner.':'.$srcType.':'.$language.':'.$sourceId;
            $index[$key][]=$id;
            $sourceCounts[$owner]=($sourceCounts[$owner]??0)+1;
            if($p->post_status==='private')$privateCount++;
            $title=self::norm($p->post_title);
            if($title!=='') {
                $titleGroups[$owner.':'.$srcType.':'.$language.':'.$title][]=$id;
                $crossTitleGroups[$srcType.':'.$language.':'.$title][$owner][]=$id;
            }
            $trid=(int)get_post_meta($id,'_mad4b_hub_import_translation_trid',true);
            if($trid>0)$translationGroups[$owner.':'.$srcType.':'.$trid][$language][]=$id;
            $body=trim(wp_strip_all_tags((string)$p->post_content));
            if($body==='')$emptyBodies++;
            else {
                $nonemptyBodies++;
                // Only nonempty content participates in exact body matching.
                $fingerprints[$owner.':'.$srcType.':'.$language.':'.hash('sha256',self::norm($body))][]=$id;
            }
            $records[$id]=[
                'hub_post_id'=>$id,'company_id'=>$owner,
                'source_type'=>$srcType,'source_language'=>$language,
                'source_post_id'=>$sourceId,'source_key'=>$key,
                'title'=>$p->post_title,'title_normalized'=>$title,
                'body_present'=>$body!=='',
            ];
        }
        $crossCompany=[];
        foreach($crossTitleGroups as $owners)if(count($owners)>1 && count($crossCompany)<self::SAMPLES) {
            $crossCompany[]=['owner_post_ids'=>$owners,
                'decision'=>'review_cross_company_title_only',
                'source_authority_preserved'=>true,'auto_merge'=>false];
        }
        $translationFamilies=0;
        foreach($translationGroups as $languages)if(count($languages)>1)$translationFamilies++;
        $specialists=[];
        foreach(self::$adapters as $id=>$callback) {
            try {
                $result=call_user_func($callback,[
                    'scope'=>['company_id'=>$company,'source_type'=>$type],
                    'records'=>$records,'source_key_index'=>$index,
                ]);
                if(!is_array($result))return self::error('adapter_contract',409);
                $specialists[$id]=$result;
            } catch(Throwable $e) {
                // Do not report a deceptively complete scan when one adapter fails.
                return self::error('adapter_failed',409);
            }
        }
        return [
            'contract'=>self::CONTRACT,'read_only'=>true,
            'auto_merge'=>false,'can_publish'=>false,
            'scope'=>['company_id'=>$company,'source_type'=>$type],
            'imported_records_scanned'=>count($records),
            'private_records'=>$privateCount,'unusable_source_identity'=>$unusable,
            'source_counts'=>$sourceCounts,
            'identity_duplicates'=>self::groups($index,'review_same_source_identity'),
            'title_candidates'=>self::groups($titleGroups,'review_same_title_distinct_identity'),
            'body_candidates'=>self::groups($fingerprints,'review_identical_nonempty_body'),
            'cross_company_title_candidates'=>$crossCompany,
            'translation_families_identified'=>$translationFamilies,
            'body_evidence'=>['nonempty'=>$nonemptyBodies,'empty'=>$emptyBodies,
                'semantic_matching_ready'=>$nonemptyBodies>0],
            'specialists'=>$specialists,
            'adapters'=>array_keys(self::$adapters),
        ];
    }
    public static function rest_discover($request) {
        $scope=[];
        $company=$request->get_param('company_id');
        $type=$request->get_param('source_type');
        if($company!==null)$scope['company_id']=$company;
        if($type!==null)$scope['source_type']=$type;
        $result=self::discover($scope);
        return is_wp_error($result)?$result:new WP_REST_Response($result,200);
    }
    public static function register_rest(): void {
        register_rest_route('mad4b-central/v1','/content-hub/dedup-discovery',[
            'methods'=>WP_REST_Server::READABLE,
            'permission_callback'=>static function(){return current_user_can('manage_options');},
            'callback'=>[__CLASS__,'rest_discover'],
        ]);
    }
    public static function menu(): void {
        if(!self::enabled())return;
        add_submenu_page('edit.php?post_type=mad4b_hub_nile',
            'Content Hub Dedup Discovery','Dedup Discovery',
            'manage_options','mad4b-hub-dedup-discovery',[__CLASS__,'screen']);
    }
    public static function screen(): void {
        if(!self::allowed())return;
        $result=self::discover();
        echo '<div class="wrap"><h1>Content Hub — Intelligence Dedup Discovery</h1>';
        echo '<p>Read-only source resolution. Exact identity, title, language and specialist evidence. No merges, deletions or publication.</p>';
        if(is_wp_error($result)){echo '<p>'.esc_html($result->get_error_code()).'</p></div>';return;}
        echo '<p><strong>Imported records:</strong> '.(int)$result['imported_records_scanned'].
            ' | <strong>Empty bodies:</strong> '.(int)$result['body_evidence']['empty'].
            ' | <strong>Exact source-key duplicate groups:</strong> '.(int)$result['identity_duplicates']['groups'].
            ' | <strong>Title candidate groups:</strong> '.(int)$result['title_candidates']['groups'].'</p>';
        echo '<h2>Discovery evidence (no changes)</h2><pre style="white-space:pre-wrap;max-width:1100px;">'.
            esc_html(wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></div>';
    }
    public static function boot(): void {
        add_action('rest_api_init',[__CLASS__,'register_rest']);
        add_action('admin_menu',[__CLASS__,'menu']);
    }
}
MAD4B_Hub_Dedup_Intelligence_V1::boot();
