<?php
/**
 * Company onboarding and legacy role reconciliation. The migration owns only
 * tenant-scoped WordPress roles and its own receipt. It does not import or
 * rewrite a company's content, JetEngine settings, WooCommerce data or users.
 */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Company_Tenant_Migration {
    const CONTRACT = 'mad4b.company-tenant-migration.v1';
    const CONFIRM = 'APPROVE COMPANY ROLE MIGRATION ON STAGING';
    const RECEIPT = 'mad4b_company_tenant_migration_receipt_v1';
    const MENU = 'mad4b-company-tenant-migration';
    private static function forbidden(): array {
        return ['manage_options','activate_plugins','install_plugins','update_plugins',
            'delete_plugins','edit_plugins','edit_theme_options','switch_themes',
            'update_themes','update_core','install_themes','delete_themes','edit_themes',
            'manage_network','manage_network_options','manage_network_plugins',
            'manage_network_themes','manage_network_users','manage_woocommerce',
            'create_users','delete_users','remove_users','edit_users','promote_users',
            'unfiltered_html','rank_math_role_manager','fluentform_full_access',
            'fluentform_settings_manager','wpml_manage_languages',
            'wpml_manage_troubleshooting'];
    }
    private static function required(): array {
        $caps=['read','edit_posts','edit_others_posts','edit_pages','edit_others_pages',
            'upload_files','mad4b_manage_company_operations','mad4b_work_company_tasks'];
        if(class_exists('MAD4B_Tenant_Operations_Workspace')) {
            $caps[]=MAD4B_Tenant_Operations_Workspace::CAP_CONTENT;
            $caps[]=MAD4B_Tenant_Operations_Workspace::CAP_PRICING;
        }
        // Provider integrations are opt-in based on each tenant's actual plugins.
        if(class_exists('WooCommerce')) {
            $caps=array_merge($caps,['edit_product','read_product','edit_products',
                'edit_others_products','publish_products','edit_published_products',
                'manage_product_terms','edit_product_terms','assign_product_terms',
                'edit_shop_order','read_shop_order','edit_shop_orders',
                'edit_others_shop_orders','view_woocommerce_reports']);
        }
        if(defined('ICL_SITEPRESS_VERSION'))
            $caps[]='wpml_manage_translation_management';
        if(defined('FLUENTFORM_VERSION'))
            $caps[]='fluentform_forms_manager';
        return array_values(array_unique($caps));
    }
    private static function preservationCandidates(): array {
        global $wpdb;
        // Preview only. Never perform an SQL content import or rewrite.
        $supported=['post','page','tours-and-activities','properties','transportations'];
        $types=implode(',',array_fill(0,count($supported),'%s'));
        $posts=$wpdb->posts;
        $meta=$wpdb->postmeta;
        $args=array_merge($supported,['_thumbnail_id','_mad4b_presentation_variant']);
        $where="SELECT DISTINCT p.ID FROM $posts p INNER JOIN $meta m ON m.post_id=p.ID
            WHERE p.post_type IN ($types)
            AND m.meta_key IN (%s,%s) AND p.post_status IN ('publish','draft','private')";
        $num=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM ($where) candidate",...$args));
        $ids=$wpdb->get_col($wpdb->prepare(
            "$where ORDER BY p.ID DESC LIMIT 30",...$args));
        $items=[];
        foreach($ids as $id) {
            $id=(int)$id;
            if(!MAD4B_Tenant_Override_Policy::context($id))continue;
            $thumbnail=(int)get_post_thumbnail_id($id);
            $hasImage=$thumbnail && get_post_type($thumbnail)==='attachment'
              && str_starts_with((string)get_post_mime_type($thumbnail),'image/');
            $variant=(string)get_post_meta($id,'_mad4b_presentation_variant',true);
            if(!in_array($variant,['card','accordion'],true))$variant='';
            if(!$hasImage && !$variant)continue;
            $items[]=[
                'post_id'=>$id,'post_type'=>get_post_type($id),
                'native_featured_attachment_id'=>$hasImage?$thumbnail:0,
                'known_presentation_variant'=>$variant,
                'selectable_fields'=>array_values(array_filter([
                    $hasImage?'featured_media_global_id':null,
                    'presentation_variant'
                ])),
                'company_workspace_url'=>admin_url('admin.php?page='.
                    MAD4B_Tenant_Operations_Workspace::SLUG.'&post_id='.$id)
            ];
        }
        return ['candidate_count'=>$num,'sample_size'=>count($items),
            'sample_max'=>30,'items'=>$items,
            'selection_required_per_post'=>true,
            'native_content_and_media_unchanged'=>true,
            'elementor_layout_json_autodetection'=>false,
            'customer_media_ids_cross_site_portable'=>false];
    }
    private static function existingSystemReadiness(): array {
        global $wpdb;
        $tables=[];
        foreach(['jet_cct_transport_rates','jet_post_types','fluentform_forms',
            'jet_rel_default','wpml_translations'] as $suffix) {
            // The WPML translation table uses a fixed standard table name.
            $table=$wpdb->prefix.($suffix==='wpml_translations'
                ?'icl_translations':$suffix);
            $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',
                $wpdb->esc_like($table)))===$table;
            $tables[$suffix]=[
                'exists'=>$exists,
                'rows'=>$exists?(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$table):0
            ];
        }
        $formsTable=$wpdb->prefix.'fluentform_forms';
        $bookingForm6=$tables['fluentform_forms']['exists']?
            (int)$wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM '.$formsTable.' WHERE id=%d',6)):0;
        $legacyCct=$tables['jet_cct_transport_rates']['rows'];
        $detected=[
            'legacy_transport_rate_cct_rows'=>$legacyCct,
            'transport_rate_cpt_registered'=>post_type_exists('transport-rate'),
            'tour_rates_cpt_registered'=>post_type_exists('tour-rates'),
            'form_6_present'=>$bookingForm6===1,
            'location_taxonomy_registered'=>taxonomy_exists('location_jet'),
            'tour_type_taxonomy_registered'=>taxonomy_exists('tour_type'),
            'wpml_translation_table_rows'=>$tables['wpml_translations']['rows'],
            'custom_configuration_tables'=>$tables
        ];
        $actions=[
            'existing_roles'=>'adopt_with_owner_approved_capability_delta',
            'jetengine_cpts_taxonomies'=>'preserve_existing_definitions_no_recreation',
            'transport_rate_cct'=>$legacyCct>0
                ?'REVIEW_REQUIRED:map_existing_rows_to_verified_rate_cpt_after_separate_certification'
                :'no_legacy_rate_records_to_migrate',
            'fluent_forms_6'=>$bookingForm6===1
                ?'reuse_existing_form_and_preserve_submissions'
                :'optional_form6_installation_or_mapping_review',
            'wpml'=>'preserve_translation_relationships_and_language_ids',
            'woocommerce'=>'preserve_products_rates_orders_and_inventory',
            'custom_fields'=>'preserve_existing_postmeta_and_relationships',
            'customer_data'=>'no_copy_no_delete_no_mutation',
            'source_provider_adapters'=>'certify_contracts_before_enabling_sync',
        ];
        return ['detected'=>$detected,'migration_actions'=>$actions,
            'provider_data_migration_status'=>$legacyCct>0
                ?'separate_review_required':'no_legacy_transport_rate_rows',
            'current_step'=>'role_policy_only',
            'data_phase_modifications_performed'=>false];
    }
    private static function inventory(): array {
        global $wpdb;
        $role=get_role('master_admin');
        $roleUsers=get_users(['role'=>'master_admin','fields'=>'ID','count_total'=>false]);
        $tables=[];
        foreach(['mad4b_tenant_content_maps','mad4b_tenant_field_overrides',
            'mad4b_tenant_hub_cache','mad4b_tenant_approvals'] as $name){
            $table=$wpdb->prefix.$name;
            $tables[$name]=($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',
                $wpdb->esc_like($table)))===$table);
        }
        $posts=[];
        foreach(['tours-and-activities','properties','transportations','tour-rates',
            'tour_orders','tour_bookings','product','shop_order'] as $type) {
            if(post_type_exists($type)){
                $count=wp_count_posts($type);
                $posts[$type]=array_sum(array_map('intval',(array)$count));
            }
        }
        return [
            'master_admin_exists'=>(bool)$role,
            'master_admin_users'=>count($roleUsers),
            'wordpress_users'=>count_users()['total_users'],
            'active_woocommerce'=>class_exists('WooCommerce'),
            'active_jetengine'=>function_exists('jet_engine'),
            'active_wpml'=>defined('ICL_SITEPRESS_VERSION'),
            'active_fluent_forms'=>defined('FLUENTFORM_VERSION'),
            'active_elementor'=>did_action('elementor/loaded')>0,
            'post_type_counts'=>$posts,
            'tenant_tables'=>$tables,
            'legacy_configuration'=>self::existingSystemReadiness(),
            'selective_preservation'=>class_exists('MAD4B_Tenant_Preservation')
                ?self::preservationCandidates()
                :['status'=>'tenant_preservation_adapter_not_installed',
                  'selection_required_per_post'=>true],
            'jetengine_options_preserved_unmodified'=>true,
            'legacy_role_migration'=>(string)get_option(
                MAD4B_SCP_Company_Master_Admin::MIGRATION,''),
            'preserve_existing_content'=>true,
            'preserve_legacy_settings'=>true,
            'no_database_import'=>true
        ];
    }
    public static function plan(): array {
        $s=MAD4B_SCP_Site_Profile::status();
        $role=get_role('master_admin');
        $caps=$role?(array)$role->capabilities:[];
        ksort($caps);
        $remove=array_values(array_filter(self::forbidden(),
            static fn($c)=>!empty($caps[$c])));
        $add=array_values(array_filter(self::required(),
            static fn($c)=>empty($caps[$c])));
        $existing=(string)get_option(MAD4B_SCP_Company_Master_Admin::MIGRATION,'');
        $blockers=[];$warnings=[];
        if(wp_get_environment_type()!=='staging'||!defined('WP_ENVIRONMENT_TYPE')
            ||WP_ENVIRONMENT_TYPE!=='staging')$blockers[]='explicit_staging_only';
        foreach(['authority_ready','origin_match','deployment_binding_match',
            'same_origin_clone_protection'] as $key)
            if(empty($s[$key]))$blockers[]='site_authority_'.$key;
        if(!empty($s['mutation_pending_audit']))$blockers[]='profile_reconciliation_pending';
        // Existing companies may already have approved external providers.
        // This operation edits ONLY the site-local master role, not their
        // provider grants. Report the drift; do not toggle company services.
        if(!empty($s['write_enabled'])||!empty($s['oauth_enabled'])
            ||!empty($s['skills_enabled']))
            $warnings[]='external_capabilities_enabled_left_unchanged';
        if(!class_exists('MAD4B_SCP_Company_Master_Admin') ||
            !class_exists('MAD4B_SCP_Company_Owner_Activation'))
            $blockers[]='site_control_plane_role_adapter_missing';
        if(!class_exists('MAD4B_SCP_Audit')
            ||empty(MAD4B_SCP_Audit::storage_status()['ready']))
            $blockers[]='audit_storage_not_ready';
        // Individually granted system powers are not inherited from WP roles
        // and must be reconciled separately: this migration does not touch users.
        $roleIds=get_users(['role'=>'master_admin','fields'=>'ID','count_total'=>false]);
        $privilegedUsers=[];
        global $wpdb;
        foreach($roleIds as $uid) {
            $raw=get_user_meta((int)$uid,$wpdb->prefix.'capabilities',true);
            if(!is_array($raw))continue;
            foreach(self::forbidden() as $cap) {
                if(!empty($raw[$cap])) {
                    $privilegedUsers[]=(int)$uid;break;
                }
            }
        }
        if($privilegedUsers)$blockers[]='direct_user_system_grants_require_manual_review';
        $inventory=self::inventory();
        $mode=!$role?'new_company':(
            $existing===MAD4B_SCP_Company_Master_Admin::VERSION
            && !$remove && !$add?'already_current':'existing_company_upgrade'
        );
        $scope=[
            'contract'=>self::CONTRACT,'site_uuid'=>(string)($s['site_uuid']??''),
            'profile_revision'=>(int)($s['revision']??0),
            'environment'=>wp_get_environment_type(),
            'plugin_version'=>defined('MAD4B_SCP_VERSION')?MAD4B_SCP_VERSION:'',
            'role_digest'=>hash('sha256',wp_json_encode($caps)),
            'legacy_migration_version'=>$existing,
            'mode'=>$mode,'remove_caps'=>$remove,'add_caps'=>$add,
            'direct_privileged_user_ids'=>$privilegedUsers,
            'has_tenant_workspace'=>class_exists('MAD4B_Tenant_Operations_Workspace')
        ];
        return ['contract'=>self::CONTRACT,'mode'=>$mode,
            'status'=>!$blockers?'ready_for_owner_approval':'blocked_preflight',
            'blockers'=>$blockers,'warnings'=>$warnings,'can_apply'=>!$blockers,
            'expected_digest'=>hash('sha256',wp_json_encode($scope)),
            'role_delta'=>$scope,'inventory'=>$inventory,
            'requires_browser_owner_cookie_nonce'=>true,
            'confirmation'=>self::CONFIRM,
            'modifies'=>['master_admin_role_caps',
                'company_migration_receipt','append_only_audit'],
            'preserves'=>['wordpress_users','other_wordpress_roles',
                'legacy_role_migration_marker','user_specific_capabilities',
                'posts','post_meta','terms','media','jetengine_options',
                'fluent_forms','wpml_languages','woocommerce_prices','orders',
                'bookings','payments','site_profile','wordpress_options_unrelated'],
            'migrates_customer_data'=>false,'external_sync_enabled'=>false,
            'mutation_performed'=>false];
    }
    public static function permission(){
        return current_user_can('manage_options')&&current_user_can('activate_plugins')
            ?true:new WP_Error('mad4b_company_migration_owner_required',
                'Authorized site owner required',['status'=>403]);
    }
    public static function rest_plan(){
        $r=rest_ensure_response(self::plan());
        $r->header('Cache-Control','private, no-store');
        return $r;
    }
    public static function commit(WP_REST_Request $request) {
        if(!MAD4B_SCP_Company_Owner_Activation::browserOwnerAuthorized())
            return new WP_Error('mad4b_company_migration_browser_owner_required',
                'Real authenticated owner browser session and REST nonce required',['status'=>403]);
        $plan=self::plan();
        if(!$plan['can_apply'])
            return new WP_Error('mad4b_company_migration_preflight_blocked',
                'Review site authority and per-user privileges before migration',
                ['status'=>409,'blockers'=>$plan['blockers']]);
        if(!hash_equals(self::CONFIRM,(string)$request->get_param('confirmation'))
           || !hash_equals($plan['expected_digest'],(string)$request->get_param('expected_digest')))
            return new WP_Error('mad4b_company_migration_stale_owner_consent',
                'Plan changed or explicit confirmation missing',['status'=>409]);
        global $wpdb;
        $lock='mad4b:company-tenant-migration:'.get_current_blog_id();
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)',$lock))!==1)
            return new WP_Error('mad4b_company_migration_busy','Concurrent migration',['status'=>423]);
        try{
            $latest=self::plan();
            if(!$latest['can_apply'] || !hash_equals(
                $plan['expected_digest'],$latest['expected_digest']))
                return new WP_Error('mad4b_company_migration_concurrent_change',
                    'Plan has changed since approval',['status'=>409]);
            if($plan['mode']==='already_current')
                return rest_ensure_response(['status'=>'already_current',
                    'mutation_performed'=>false,'site_uuid'=>$plan['role_delta']['site_uuid']]);
            $rolesBefore=get_option($wpdb->prefix.'user_roles');
            $receiptBefore=get_option(self::RECEIPT,null);
            $intent=MAD4B_SCP_Audit::record('mad4b/company-tenant-migration-intent',[
                'site_uuid'=>$plan['role_delta']['site_uuid'],
                'actor_id'=>get_current_user_id(),
                'plan_digest'=>$plan['expected_digest'],'mode'=>$plan['mode'],
                'existing_company_data_modified'=>false],'intent');
            if(is_wp_error($intent))return $intent;
            try{
                if(!get_role('master_admin') && !add_role('master_admin',
                        'Company Master Admin',['read'=>true]))
                    throw new RuntimeException('Failed to create role');
                // Non-destructive tenant-only adoption: do not invoke the older
                // global migration which also edits unrelated staff roles.
                $role=get_role('master_admin');
                if(!$role)throw new RuntimeException('Role missing after migration');
                foreach(self::forbidden() as $cap)$role->remove_cap($cap);
                foreach(self::required() as $cap)$role->add_cap($cap,true);
                $verified=get_role('master_admin');
                if(!$verified ||
                    !$verified->has_cap('mad4b_manage_company_operations') ||
                    !$verified->has_cap('read') ||
                    !$verified->has_cap('edit_posts'))
                    throw new RuntimeException('Role readback failed');
                foreach(self::forbidden() as $cap)
                    if($verified->has_cap($cap))
                        throw new RuntimeException('Forbidden privilege retained');
                $receipt=[
                    'contract'=>self::CONTRACT,
                    'site_uuid'=>$plan['role_delta']['site_uuid'],
                    'actor_id'=>get_current_user_id(),
                    'at'=>current_time('mysql',true),
                    'mode'=>$plan['mode'],
                    'plan_digest'=>$plan['expected_digest'],
                    'status'=>'committed','native_content_modified'=>false,
                    'existing_users_modified'=>false,
                    'jetengine_options_modified'=>false,
                    'woocommerce_records_modified'=>false
                ];
                update_option(self::RECEIPT,$receipt,false);
                if(get_option(self::RECEIPT)!==$receipt)
                    throw new RuntimeException('Receipt readback failed');
                $result=MAD4B_SCP_Audit::record('mad4b/company-tenant-migration-result',
                    $receipt,'ok');
                if(is_wp_error($result))throw new RuntimeException('Audit result failed');
                return rest_ensure_response(['status'=>'committed',
                    'site_uuid'=>$receipt['site_uuid'],'mode'=>$plan['mode'],
                    'company_role_ready'=>true,'native_content_modified'=>false,
                    'existing_users_modified'=>false,'commerce_activated'=>false,
                    'jetengine_options_modified'=>false]);
            }catch(Throwable $failure){
                // Restore only the role and marker owned by this migration.
                if($rolesBefore!==false){
                    update_option($wpdb->prefix.'user_roles',$rolesBefore,false);
                    wp_roles()->for_site(get_current_blog_id());
                }
                if($receiptBefore===null)delete_option(self::RECEIPT);
                else update_option(self::RECEIPT,$receiptBefore,false);
                error_log('[MAD4B tenant migration] rollback invoked; require audit reconciliation');
                return new WP_Error('mad4b_company_migration_rolled_back',
                    'Migration could not be confirmed; role snapshot restored',
                    ['status'=>500,'audit_review_required'=>true]);
            }
        }finally{
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    public static function page(): void {
        if(!current_user_can('manage_options')||!current_user_can('activate_plugins'))
            wp_die('Site owner permission required',403);
        $plan=self::plan();
        $json=wp_json_encode([
            'mode'=>$plan['mode'],
            'company_site_uuid'=>$plan['role_delta']['site_uuid'],
            'existing_company_inventory'=>$plan['inventory'],
            'role_permissions_to_add'=>$plan['role_delta']['add_caps'],
            'existing_system_permissions_to_revoke_from_master_admin'=>$plan['role_delta']['remove_caps'],
            'privileged_user_ids_needing_manual_review'=>$plan['role_delta']['direct_privileged_user_ids'],
            'blockers'=>$plan['blockers'],'warnings'=>$plan['warnings'],
            'preserved_data'=>$plan['preserves'],
            'changed_metadata'=>$plan['modifies']
        ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        echo '<div class="wrap"><h1>Company Tenant Migration</h1>';
        echo '<p>New-company setup and safe adoption of existing company WordPress sites.'
            .' Existing content, users, options, translations and commerce records are preserved.</p>';
        echo '<p><strong>Mode:</strong> '.esc_html($plan['mode']).' — '
            .($plan['can_apply']?'Ready for real owner approval':'Blocked until gaps are resolved').'</p>';
        echo '<pre style="max-height:340px;overflow:auto;background:#fff;padding:12px;border:1px solid #ddd">'
            .esc_html((string)$json).'</pre>';
        if(!$plan['can_apply']){
            echo '<p>No mutation is available. Resolve: '.esc_html(implode(', ',$plan['blockers'])).'</p></div>';
            return;
        }
        echo '<form id="mad4b-company-tenant-migrate">';
        echo '<p>Type <code>'.esc_html(self::CONFIRM).'</code> to authorize the role changes on this Staging site.</p>';
        echo '<input id="mad4b-company-migration-phrase" required autocomplete="off" '
            .'style="max-width:95%;width:540px" aria-label="Exact owner confirmation phrase">';
        echo '<p><button class="button button-primary" type="submit">Apply reviewed role migration</button></p>';
        echo '<p id="mad4b-company-migration-result" role="status" aria-live="polite"></p></form>';
        $endpoint=rest_url('mad4b/v1/company-tenant-migration/commit');
        $nonce=wp_create_nonce('wp_rest');
        echo '<script>(function(){'
          .'const form=document.getElementById("mad4b-company-tenant-migrate");'
          .'const result=document.getElementById("mad4b-company-migration-result");'
          .'form.addEventListener("submit",async function(event){event.preventDefault();'
          .'result.textContent="Submitting owner confirmation...";'
          .'try{const r=await fetch('.wp_json_encode($endpoint).',{method:"POST",'
          .'credentials:"same-origin",headers:{"Content-Type":"application/json",'
          .'"X-WP-Nonce":'.wp_json_encode($nonce).'},body:JSON.stringify({'
          .'confirmation:document.getElementById("mad4b-company-migration-phrase").value,'
          .'expected_digest:'.wp_json_encode($plan['expected_digest']).'})});'
          .'const body=await r.json();result.textContent=r.ok?'
          .'"Company role migration committed; reload to verify":'
          .'(body.message||"Migration rejected; reload and review current plan.");'
          .'}catch(e){result.textContent="No confirmed migration; check site status.";}'
          .'});})();</script></div>';
    }
    public static function register(): void {
        add_action('admin_menu',static function(){
            add_management_page('Company Tenant Migration','Company Tenant Migration',
                'manage_options',self::MENU,[__CLASS__,'page']);
        });
        add_action('rest_api_init',static function(){
            register_rest_route('mad4b/v1','/company-tenant-migration/plan',[
                'methods'=>'GET','permission_callback'=>[__CLASS__,'permission'],
                'callback'=>[__CLASS__,'rest_plan']]);
            register_rest_route('mad4b/v1','/company-tenant-migration/commit',[
                'methods'=>'POST','permission_callback'=>[__CLASS__,'permission'],
                'callback'=>[__CLASS__,'commit']]);
        });
    }
}
MAD4B_SCP_Company_Tenant_Migration::register();
