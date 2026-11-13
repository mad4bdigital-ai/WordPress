<?php
/**
 * Feature 009: bounded and provenance-bound schema definition export.
 * Read-only WP-CLI; data is untrusted on target and MUST NOT be executed.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Typed_Schema_V1 {
    public const CONTRACT='mad4b.tenant-typed-schema-bundle.v1';
    private const FIXED=[
       'jetengine'=>[15,26,223,273,310,362,363],
       'jet_taxonomies'=>[8,20,26],
       'jetsmartfilters'=>[15018],
       'fluent_forms'=>[1],
       'elementor'=>[15234],
       'wpml_post_ids'=>[54924,36260,37553],
    ];
    private static function ensure(bool $ok,string $message):void {
        if(!$ok)throw new RuntimeException('SCHEMA_EXPORT_'.$message);
    }
    private static function bytes(string $value):array {
        self::ensure(strlen($value)<=160000,'SOURCE_BYTES_OVER_LIMIT');
        self::ensure(!preg_match('/(?:client[_-]?secret|signing[_-]?secret|private[_-]?key|api[_-]?key|access[_-]?token|refresh[_-]?token|password\\s*[:=]|bearer\\s+[A-Za-z0-9]{12,})/i',$value),'SENSITIVE_VALUE_PATTERN');
        return [
            'encoding'=>'base64_original_source_bytes',
            'bytes'=>strlen($value),
            'sha256'=>hash('sha256',$value),
            'base64'=>base64_encode($value),
        ];
    }
    private static function rows(string $table,string $column,array $ids,array $fields):array {
        global $wpdb;
        $approved=['jet_post_types','jet_taxonomies','fluentform_forms'];
        self::ensure(in_array($table,$approved,true),'TABLE_FORBIDDEN');
        $name=$wpdb->prefix.$table;
        $ph=implode(',',array_fill(0,count($ids),'%d'));
        $output=$wpdb->get_results($wpdb->prepare(
           "SELECT ".implode(',',$fields)." FROM $name WHERE $column IN ($ph) ORDER BY $column",
           ...$ids),ARRAY_A);
        self::ensure(count($output)===count($ids),'SOURCE_ROWS_MISSING');
        $result=[];
        foreach($output as $row){
            $id=(int)$row[$column];$encoded=[];
            foreach($fields as $key){
                if($key===$column)continue;
                $encoded[$key]=self::bytes((string)($row[$key]??''));
            }
            $result[]=['source_id'=>$id,'source_table'=>$table,
                'source_fields'=>$encoded];
        }
        return $result;
    }
    private static function postMeta(int $id,string $kind):array {
        global $wpdb;
        $post=get_post($id);
        self::ensure($post instanceof WP_Post,'SOURCE_POST_MISSING');
        self::ensure($post->post_type===($kind==='elementor'?'elementor_library':'jet-smart-filters')
           &&$post->post_status==='publish','SOURCE_POST_NOT_PUBLISHED');
        $keys=$kind==='elementor'
          ?['_elementor_data','_elementor_page_settings','_elementor_conditions']
          :['_filter_type','_data_source','_source_taxonomy','_source_post_type',
             '_query_var','_filter_label','_filter_settings','_jet_smart_filters_settings'];
        $meta=[];
        foreach($keys as $key) {
            $rows=$wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE post_id=%d AND meta_key=%s ORDER BY meta_id",$id,$key));
            if(!$rows)continue;
            $meta[$key]=array_map([__CLASS__,'bytes'],$rows);
        }
        $raw=['title'=>self::bytes($post->post_title),
              'slug'=>self::bytes($post->post_name),
              'post_content'=>self::bytes($post->post_content)];
        return ['source_id'=>$id,'post_type'=>$post->post_type,
            'source_fields'=>$raw,'postmeta'=>$meta,
            'source_post_status'=>$post->post_status,
            'raw_execution_authorized'=>false];
    }
    private static function translations():array {
        global $wpdb;
        $table=$wpdb->prefix.'icl_translations';
        $cols=$wpdb->get_col('SHOW COLUMNS FROM '.$table,0);
        if(!in_array('trid',(array)$cols,true))return [];
        $results=[];
        foreach(self::FIXED['wpml_post_ids'] as $id){
            $post=get_post($id);
            self::ensure($post instanceof WP_Post,'SOURCE_TRANSLATION_POST_MISSING');
            $element='post_'.$post->post_type;
            $row=$wpdb->get_row($wpdb->prepare(
                "SELECT trid,language_code,source_language_code FROM $table
                 WHERE element_type=%s AND element_id=%d LIMIT 1",
                $element,$id),ARRAY_A);
            $results[]=['source_post_id'=>$id,'post_type'=>$post->post_type,
                'source_trid'=>isset($row['trid'])?(int)$row['trid']:null,
                'language_code'=>$row['language_code']??'und',
                'source_language_code'=>$row['source_language_code']??null,
                'local_wpml_trid_not_portable'=>true];
        }
        return $results;
    }
    public static function create():array {
        self::ensure(defined('WP_CLI') && WP_CLI,'CLI_REQUIRED');
        self::ensure(MAD4B_Tenant_Operation_Authority::scope(false)!=='',
            'SOURCE_SITE_AUTHORITY_REQUIRED');
        $s=MAD4B_SCP_Site_Profile::status();
        self::ensure(($s['authority_ready']??false)===true
            &&($s['binding_state']??'')==='exact'
            &&($s['environment_match']??false)===true
            &&($s['deployment_binding_match']??false)===true
            &&($s['origin_match']??false)===true
            &&wp_is_uuid((string)($s['site_uuid']??'')),
            'SOURCE_IDENTITY_REQUIRED');
        $payload=[
            'contract'=>self::CONTRACT,
            'source_site'=>[
                'site_uuid'=>$s['site_uuid'],
                'origin'=>untrailingslashit(home_url('/')),
                'profile_revision'=>(int)($s['revision']??0)],
            'selection'=>self::FIXED,
            'schemas'=>[
               'jetengine'=>self::rows('jet_post_types','id',self::FIXED['jetengine'],
                   ['id','slug','status','labels','args','meta_fields']),
               'jet_taxonomies'=>self::rows('jet_taxonomies','id',
                   self::FIXED['jet_taxonomies'],
                   ['id','slug','object_type','status','labels','args','meta_fields']),
               'jetsmartfilters'=>[
                   self::postMeta(self::FIXED['jetsmartfilters'][0],'filter')],
               'fluent_forms'=>self::rows('fluentform_forms','id',
                   self::FIXED['fluent_forms'],
                   ['id','status','type','form_fields','conditions']),
               'elementor'=>[self::postMeta(self::FIXED['elementor'][0],'elementor')],
               'wpml_translation_lineage'=>self::translations()
            ],
            'schema_activation_authorized'=>false,
            'credentials_exported'=>false,
            'form_submissions_exported'=>false,
            'raw_company_option_values_exported'=>false,
            'financial_data_exported'=>false,
            'target_provider_adapters_required'=>true,
            'company_detach_authorized'=>false
        ];
        $json=wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        self::ensure(strlen($json)<=650000,'BUNDLE_LIMIT');
        $payload['payload_sha256']=hash('sha256',$json);
        return $payload;
    }
    public static function cli($args,$assoc):void {
        if($args!==['export']||$assoc)
            WP_CLI::error('EXACT_SCHEMA_PILOT_EXPORT_ONLY');
        try {
            WP_CLI::line(wp_json_encode(self::create(),
                JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        } catch(Throwable $e){WP_CLI::error($e->getMessage());}
    }
    public static function boot():void {
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b typed-schema',[__CLASS__,'cli']);
    }
}
MAD4B_Tenant_Typed_Schema_V1::boot();
