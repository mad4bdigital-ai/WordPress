<?php
/**
 * Tenant-owned read-only provider census for independent company handover.
 * Counts and source coverage only, no content bodies, credentials or financial
 * records. This is not an export, a detachment grant or an applied migration.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Handover_Inventory_V1 {
    public const CONTRACT='mad4b.tenant-handover-inventory.v1';
    private const TABLES=[
        'jet_post_types','jet_taxonomies','jet_rel_default','jet_rel_default_meta',
        'fluentform_forms','fluentform_submissions','icl_translations',
        'mad4b_tenant_content_maps','mad4b_hub_publication_targets'
    ];
    private const POSTS=[
        'post','page','tours-and-activities','properties','units',
        'transportations','tour-rates','property-rates','transport-rates',
        'jet-smart-filters','jet-form-builder','elementor_library',
        'product','shop_order','shop_order_refund'
    ];
    private const TAXONOMIES=[
        'destinations','tour-types','tour_categories','tour-styles',
        'guide-languages','durations','season','package-level','property-types',
        'amenities','transportation-types'
    ];
    private const OPTIONS=[
        'mad4b_scp_site_profile_v2','mad4b_scp_brand_context_profile_v1',
        'mad4b_scp_market_profiles_v1','mad4b_content_experience_profiles_v1',
        'service-activation','destination-activation','jet_engine_meta_boxes',
        'elementor_active_kit','_wcml_settings'
    ];
    private static function fail(string $reason,int $status=409): WP_Error {
        return new WP_Error('mad4b_handover_'.$reason,'Handover evidence: '.$reason,['status'=>$status]);
    }
    private static function site() {
        if(!class_exists('MAD4B_SCP_Site_Profile',false)
            ||!MAD4B_Tenant_Operation_Authority::scope(false))
            return self::fail('verified_site_profile_required',403);
        $status=MAD4B_SCP_Site_Profile::status();
        if(!is_array($status)||!wp_is_uuid((string)($status['site_uuid']??''))
            ||($status['authority_ready']??false)!==true
            ||($status['origin_match']??false)!==true
            ||($status['deployment_binding_match']??false)!==true
            ||($status['environment_match']??false)!==true
            ||($status['binding_state']??'')!=='exact')
            return self::fail('site_binding_not_verified');
        return [
            'site_uuid'=>$status['site_uuid'],
            'origin'=>untrailingslashit(home_url('/')),
            'environment'=>(string)($status['configured_environment']??wp_get_environment_type()),
            'profile_revision'=>(int)($status['revision']??0),
            'profile_digest'=>(string)($status['profile_digest']??'')
        ];
    }
    private static function table(string $suffix): array {
        global $wpdb;
        $name=$wpdb->prefix.$suffix;
        $present=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',
            $wpdb->esc_like($name)))===$name;
        // Table names are exclusively built from static internal allowlists.
        return ['exists'=>$present,'rows'=>$present?(int)$wpdb->get_var("SELECT COUNT(*) FROM $name"):0];
    }
    private static function posts(): array {
        global $wpdb;
        $filter="'".implode("','",self::POSTS)."'";
        $rows=$wpdb->get_results("SELECT post_type,post_status,COUNT(*) n
            FROM {$wpdb->posts} WHERE post_type IN ($filter)
            GROUP BY post_type,post_status",ARRAY_A);
        $result=[];
        foreach(self::POSTS as $type)$result[$type]=['count'=>0,'statuses'=>[]];
        foreach((array)$rows as $row) {
            $type=$row['post_type'];$n=(int)$row['n'];
            $result[$type]['count']+=$n;
            $result[$type]['statuses'][$row['post_status']]=$n;
        }
        return $result;
    }
    private static function taxonomies(): array {
        global $wpdb;
        $result=[];
        foreach(self::TAXONOMIES as $type)
            $result[$type]=['registered'=>taxonomy_exists($type),
                'terms'=>(int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy=%s",$type))];
        return $result;
    }
    private static function jet(array $table): array {
        if(!$table['exists'])return ['state'=>'NOT_INSTALLED','definition_types'=>[]];
        global $wpdb;
        $rows=$wpdb->get_results("SELECT status,COUNT(*) n FROM {$wpdb->prefix}jet_post_types
             GROUP BY status",ARRAY_A);
        $types=[];foreach((array)$rows as $r)$types[$r['status']]=(int)$r['n'];
        return ['state'=>'OBSERVED','definition_types'=>$types,'local_ids_portable'=>false];
    }
    private static function maps(array $table,string $siteUuid): array {
        if(!$table['exists'])return ['state'=>'TABLE_ABSENT','count'=>null];
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT mode,COUNT(*) n FROM {$wpdb->prefix}mad4b_tenant_content_maps
             WHERE site_uuid=%s GROUP BY mode",$siteUuid),ARRAY_A);
        $types=[];$total=0;
        foreach((array)$rows as $row){$n=(int)$row['n'];$types[$row['mode']]=$n;$total+=$n;}
        return ['state'=>'OBSERVED','count'=>$total,'modes'=>$types,
            'last_common_baseline_verified'=>false];
    }
    private static function wpml(array $table): array {
        if(!$table['exists'])return ['state'=>'NOT_INSTALLED','translations'=>0];
        global $wpdb;
        $rows=$wpdb->get_results("SELECT language_code,COUNT(*) n
             FROM {$wpdb->prefix}icl_translations GROUP BY language_code",ARRAY_A);
        $langs=[];$total=0;
        foreach((array)$rows as $r){$langs[$r['language_code']]=(int)$r['n'];$total+=(int)$r['n'];}
        return ['state'=>'OBSERVED','translations'=>$total,'language_counts'=>$langs,
            'translation_group_id_remap_required'=>true];
    }
    private static function elementor(): array {
        global $wpdb;
        $rows=$wpdb->get_results("SELECT meta_key,COUNT(*) n FROM {$wpdb->postmeta}
            WHERE meta_key IN ('_elementor_data','_elementor_page_settings',
            '_elementor_conditions','_elementor_css') GROUP BY meta_key",ARRAY_A);
        $data=[];foreach((array)$rows as $r)$data[$r['meta_key']]=(int)$r['n'];
        return ['source_document_counts'=>$data,
            'compiled_css_portable'=>false,'css_regeneration_required'=>true];
    }
    public static function inventory() {
        $site=self::site();if(is_wp_error($site))return $site;
        $tables=[];foreach(self::TABLES as $name)$tables[$name]=self::table($name);
        $options=[];foreach(self::OPTIONS as $name)
            $options[$name]=get_option($name,null)!==null;
        $result=[
            'contract'=>self::CONTRACT,'site'=>$site,
            'inventory_kind'=>'local_schema_and_content_counts_only',
            'providers'=>[
                'posts'=>self::posts(),
                'taxonomies'=>self::taxonomies(),
                'tables'=>$tables,
                'jetengine'=>self::jet($tables['jet_post_types']),
                'tenant_maps'=>self::maps($tables['mad4b_tenant_content_maps'],$site['site_uuid']),
                'wpml'=>self::wpml($tables['icl_translations']),
                'elementor'=>self::elementor(),
                'profile_option_presence'=>$options,
                'woo_financial_data'=>'EXCLUDED_FROM_PORTABLE_EXPORT',
                'fluent_submissions'=>'EXCLUDED_FROM_PORTABLE_EXPORT',
                'media_files'=>'CHECKSUM_READBACK_PENDING'
            ],
            'blocking_gates'=>[
                'provider_raw_export_not_performed',
                'last_common_baseline_missing',
                'cross_site_ids_not_remapped',
                'target_import_readback_missing',
                'media_integrity_readback_missing',
                'financial_provider_continuity_receipt_missing',
                'central_grant_revocation_receipt_missing'
            ],
            'portable_export_created'=>false,
            'detach_authorized'=>false,
            'runtime_mutated'=>false
        ];
        $result['inventory_sha256']=hash('sha256',
            wp_json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $result;
    }
    public static function permission() {
        return MAD4B_Tenant_Operation_Authority::admin_read()
            ?true:self::fail('admin_required',403);
    }
    public static function routes(): void {
        register_rest_route('mad4b-tenant/v1','/handover-inventory',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'permission'],
            'callback'=>static function(){
                $r=rest_ensure_response(self::inventory());
                if($r instanceof WP_REST_Response)$r->header('Cache-Control','private,no-store');
                return $r;
            }
        ]);
    }
    public static function cli($args,$assoc):void {
        if($args!==['inventory'] || $assoc)WP_CLI::error('EXACT_READ_ONLY_INVENTORY_REQUIRED');
        $data=self::inventory();
        if(is_wp_error($data))WP_CLI::error($data->get_error_code());
        WP_CLI::line(wp_json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    public static function boot():void {
        add_action('rest_api_init',[__CLASS__,'routes'],85);
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b tenant-handover',[__CLASS__,'cli']);
    }
}
MAD4B_Tenant_Handover_Inventory_V1::boot();
