<?php
/**
 * Feature 009 P1-D: company-scoped content portability proof, read-only.
 * This is a documentary boundary between Content Hub and a company's local
 * runtime, not an exporter, grant issuer or operational detach/rejoin engine.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Portability_Readiness_V1 {
    public const CONTRACT='mad4b.company-portability-readiness.v1';
    private const TABLES=['hub'=>'mad4b_hub_content_records',
        'versions'=>'mad4b_hub_content_versions',
        'publication'=>'mad4b_hub_publication_targets',
        'tenant'=>'mad4b_tenant_content_maps'];
    private static function fail(string $code,int $http=409): WP_Error {
        return new WP_Error('mad4b_portability_'.$code,
            'Company portability evidence unavailable: '.$code,['status'=>$http]);
    }
    public static function permission() {
        return MAD4B_Company_Portfolio_Manager_V1::staging()
            && current_user_can('manage_options')
            && current_user_can('activate_plugins')
            ? true:self::fail('central_admin_required',403);
    }
    private static function tables() {
        global $wpdb;
        $found=[];
        foreach(self::TABLES as $key=>$suffix){
            $name=$wpdb->prefix.$suffix;
            if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',
               $wpdb->esc_like($name)))!==$name)
                return self::fail('provider_table_missing',503);
            $found[$key]=$name;
        }
        return $found;
    }
    /** Pure evidence evaluation; no authority grant even when all inputs true. */
    public static function gates(array $evidence):array {
        $required=[
            'verified_company_binding'=>'company_binding_unverified',
            'separate_company_runtime_identity'=>'independent_company_site_identity_missing',
            'company_profile_portable'=>'company_profile_export_receipt_missing',
            'hub_company_content_versions'=>'hub_company_approved_version_proof_missing',
            'hub_publication_lineage'=>'hub_company_publication_lineage_missing',
            'content_rights_disposition'=>'content_rights_disposition_missing',
            'signed_last_common_baseline'=>'signed_last_common_baseline_missing',
            'typed_provider_import_readback'=>'native_typed_provider_readback_missing',
            'financial_continuity_readback'=>'company_financial_continuity_receipt_missing',
            'remote_grant_revocation_readback'=>'central_grant_revocation_receipt_missing',
        ];
        $blockers=[];
        foreach($required as $field=>$code)
            if(($evidence[$field]??false)!==true)$blockers[]=$code;
        return [
            'evidence_complete'=>count($blockers)===0,
            'blocking_gates'=>$blockers,
            'can_detach_now'=>false,
            'can_rejoin_now'=>false,
            'automated_publication_authorized'=>false
        ];
    }
    public static function current(string $company) {
        if(!MAD4B_Company_Portfolio_Manager_V1::staging())
            return self::fail('central_staging_required',403);
        $binding=MAD4B_Company_Portfolio_Manager_V1::company_binding($company);
        if(is_wp_error($binding))return $binding;
        if(($binding['company_code']??null)!==$company
            ||!wp_is_uuid((string)($binding['company_site_uuid']??''))
            ||!preg_match('/^[a-f0-9]{64}$/D',(string)($binding['binding_sha256']??'')))
            return self::fail('company_binding_invalid',403);
        $membership=MAD4B_Company_Network_Membership_V1::current($company);
        $catalog=MAD4B_Company_Service_Catalog_V1::current($company);
        if(is_wp_error($membership))return $membership;
        if(is_wp_error($catalog))return $catalog;
        if(($membership['company_site_uuid']??null)!==$binding['company_site_uuid']
            ||($catalog['company_site_uuid']??null)!==$binding['company_site_uuid'])
            return self::fail('company_binding_drift',409);
        $tables=self::tables();if(is_wp_error($tables))return $tables;
        global $wpdb;
        $uuid=$binding['company_site_uuid'];
        $central=MAD4B_SCP_Site_Profile::status();
        $centralUuid=(string)($central['site_uuid']??'');
        $same=$uuid===$centralUuid;
        $candidates=$wpdb->get_results($wpdb->prepare(
            "SELECT status,COUNT(*) AS n FROM {$tables['hub']}
             WHERE site_uuid=%s AND brand_id=%s GROUP BY status",
            $uuid,$company),ARRAY_A);
        $companyStates=[];$records=0;
        foreach((array)$candidates as $x){
            $n=(int)$x['n'];$companyStates[$x['status']]=$n;$records+=$n;
        }
        $candidateGlobal=(int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$tables['hub']}");
        $linked=$wpdb->get_results($wpdb->prepare(
            "SELECT source_verified,target_status,COUNT(*) n
             FROM {$tables['publication']}
             WHERE company_id=%s AND destination_site_uuid=%s
             GROUP BY source_verified,target_status",
            $company,$uuid),ARRAY_A);
        $linkedCount=0;$verified=0;$linkedStates=[];
        foreach((array)$linked as $x) {
            $n=(int)$x['n'];$linkedCount+=$n;
            if(in_array($x['source_verified'],
                ['local_wordpress','verified_remote_connector'],true))$verified+=$n;
            $linkedStates[]=['source_verified'=>$x['source_verified'],
                'target_status'=>$x['target_status'],'count'=>$n];
        }
        $maps=$wpdb->get_results($wpdb->prepare(
            "SELECT mode,state,COUNT(*) n FROM {$tables['tenant']}
             WHERE site_uuid=%s GROUP BY mode,state",
            $uuid),ARRAY_A);
        $mapped=0;$mapStates=[];
        foreach((array)$maps as $m){
            $mapped+=(int)$m['n'];
            $mapStates[]=['mode'=>$m['mode'],'state'=>$m['state'],'count'=>(int)$m['n']];
        }
        $options=get_option('mad4b_central_company_market_portfolio_plans_v1',[]);
        $companyMarket=is_array($options)?($options[$company]??null):null;
        $marketBound=is_array($companyMarket)
            &&($companyMarket['company_site_uuid']??null)===$uuid;
        // Central plan is not the target runtime's Market Profile receipt.
        $evidence=[
            'verified_company_binding'=>true,
            'separate_company_runtime_identity'=>false,
            'company_profile_portable'=>false,
            'hub_company_content_versions'=>false,
            'hub_publication_lineage'=>false,
            'content_rights_disposition'=>false,
            'signed_last_common_baseline'=>false,
            'typed_provider_import_readback'=>false,
            'financial_continuity_readback'=>false,
            'remote_grant_revocation_readback'=>false,
        ];
        $gates=self::gates($evidence);
        // Source data has no certified company-owned commercial release today.
        $report=[
            'contract'=>self::CONTRACT,
            'company_code'=>$company,'company_site_uuid'=>$uuid,
            'company_binding_sha256'=>$binding['binding_sha256'],
            'membership_state'=>$membership['state'],
            'membership_revision'=>$membership['revision'],
            'content_authority_plan'=>$membership['content_authority'],
            'catalog_plan'=>$catalog['mode'],
            'catalog_revision'=>$catalog['revision'],
            'company_market_portfolio_plan_present'=>is_array($companyMarket),
            'company_market_plan_exact_site_bound'=>$marketBound,
            'company_market_runtime_approved'=>false,
            'same_site_uuid_as_central_source'=>$same,
            'hub'=>[
                'canonical_records_global'=>$candidateGlobal,
                'exact_company_site_brand_records'=>$records,
                'exact_company_statuses'=>$companyStates,
                'company_publication_target_count'=>$linkedCount,
                'verified_target_link_count'=>$verified,
                'target_states'=>$linkedStates,
                'company_tenant_content_map_count'=>$mapped,
                'company_tenant_mapping_states'=>$mapStates,
                'commercial_ownership_certified'=>false,
                'rights_to_distribute_certified'=>false
            ],
            'provider_source_inventory'=>'P1C_SAMPLE_AND_FULL_DIGEST_ONLY',
            'actual_company_export_created'=>false,
            'source_site_write_authorized'=>false,
            'company_site_write_authorized'=>false,
            'membership_changed'=>false,
            'privacy_or_financial_data_transferred'=>false,
        ];
        return array_merge($report,$gates);
    }
    public static function routes():void {
        register_rest_route('mad4b/v1',
            '/company-management/companies/(?P<company_code>[a-z][a-z0-9-]{2,47})/portability-readiness',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'permission'],
            'callback'=>static function($request) {
                $response=rest_ensure_response(self::current((string)$request['company_code']));
                if($response instanceof WP_REST_Response)
                    $response->header('Cache-Control','private, no-store');
                return $response;
            }
        ]);
    }
    public static function admin_menu():void {
        if(!MAD4B_Company_Portfolio_Manager_V1::staging())return;
        add_submenu_page('mad4b-company-brand-readiness',
            'Company Portability Readiness','Portability Readiness',
            'manage_options','mad4b-company-portability-readiness',
            [__CLASS__,'screen']);
    }
    public static function screen():void {
        $permission=self::permission();
        if(is_wp_error($permission))wp_die(esc_html($permission->get_error_message()),403);
        echo '<div class="wrap"><h1>Company Portability Readiness</h1>';
        echo '<p><strong>Read-only evidence.</strong> Hub commercial content ownership, typed export coverage, local runtime proof and grant revocation must all be verified before Detach or Rejoin.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Membership</th><th>Hub targets</th><th>Company content records</th><th>Market plan</th><th>Blocking gates</th></tr></thead><tbody>';
        foreach(MAD4B_Company_Service_Catalog_V1::companies() as $code){
            $r=self::current($code);
            echo '<tr><td><strong>'.esc_html($code).'</strong></td>';
            if(is_wp_error($r)){
                echo '<td colspan="5">'.esc_html($r->get_error_message()).'</td></tr>';
                continue;
            }
            echo '<td>'.esc_html($r['membership_state']).'</td>';
            echo '<td>'.esc_html((string)$r['hub']['verified_target_link_count']).'</td>';
            echo '<td>'.esc_html((string)$r['hub']['exact_company_site_brand_records']).'</td>';
            echo '<td>'.($r['company_market_plan_exact_site_bound']?'Site bound':'Not verified').'</td>';
            echo '<td>'.esc_html(implode(', ',$r['blocking_gates'])).'</td></tr>';
        }
        echo '</tbody></table><p>Zero site writes. This view is not proof that current Hub candidates have commercial redistribution rights.</p></div>';
    }
    public static function boot():void {
        add_action('rest_api_init',[__CLASS__,'routes'],84);
        add_action('admin_menu',[__CLASS__,'admin_menu'],94);
    }
}
MAD4B_Company_Portability_Readiness_V1::boot();
