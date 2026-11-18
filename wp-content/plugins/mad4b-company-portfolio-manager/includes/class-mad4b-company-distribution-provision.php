<?php
/**
 * Company Management — inherited distribution-channel plans.
 * A plan only; remote changes must be executed by an authorized company runner.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Distribution_Provision_V1 {
    public const CONTRACT='mad4b.company-distribution-plan.v1';
    public const STORE='mad4b_company_distribution_provision_plans_v1';

    public static function bound_company(string $code) {
        if (!class_exists('MAD4B_Company_Strategy_V1',false)
            || !MAD4B_Company_Strategy_V1::central()) return new WP_Error('company_distribution_central_only');
        return MAD4B_Company_Strategy_V1::binding($code);
    }

    public static function channel_blueprints(): array {
        if (class_exists('MAD4B_Distribution_Channel_Registry_V1'))
            return array_keys(MAD4B_Distribution_Channel_Registry_V1::catalog());
        // Provisioning plan is still deterministic if the portable addon isn't active yet.
        return ['meta_catalog','facebook_shops','instagram_shopping','whatsapp_business_catalog',
            'google_things_to_do','google_ads','google_business_profile',
            'viator','getyourguide','klook','partner_b2b'];
    }

    /** Company-owned credentials: no central/external vault service. */
    public static function local_credential_policy(): array {
        return [
            'credential_backend'=>'company_local_wordpress_encrypted',
            'credential_encryption'=>'xchacha20poly1305_site_bound_wp_salts',
            'external_vault_required'=>false,
            'credential_inheritance'=>'never_copy_between_companies',
            'provider_accounts_inherited'=>false,
        ];
    }

    public static function plan(string $code) {
        $code=sanitize_key($code);
        $binding=self::bound_company($code);
        if (is_wp_error($binding)) return $binding;
        $all=get_option(self::STORE,[]);
        if (!is_array($all)) return new WP_Error('company_distribution_plan_store_corrupt');
        $old=$all[$code]??null;
        if (is_array($old)) {
            if (($old['company_site_uuid']??'')!==$binding['company_site_uuid']
                || ($old['native_site_profile_uuid']??'')!==$binding['native_site_profile_uuid'])
                return new WP_Error('company_distribution_site_identity_changed');
            $newChannels=array_diff(self::channel_blueprints(),array_keys((array)($old['channels']??[])));
            $oldPolicies=(array)($old['capability_policies']??[]);
            $desiredPolicies=array_merge($oldPolicies,self::local_credential_policy());
            $policyUpdated=$oldPolicies!==$desiredPolicies;
            if (!$newChannels && !$policyUpdated)
                return ['state'=>'EXISTS','plan'=>$old,'changed'=>false];
            $old['capability_policies']=$desiredPolicies;
            foreach($newChannels as $id) {
                $old['channels'][$id]=[
                    'inheritance'=>'inherit_with_company_override',
                    'connection'=>'not_connected','approval'=>'pending',
                    'credentials_inherited'=>false,'provider_accounts_inherited'=>false,
                    'external_sending'=>false,
                ];
            }
            $old['revision']=(int)($old['revision']??0)+1;
            $all[$code]=$old;
            if (!update_option(self::STORE,$all,false))return new WP_Error('company_distribution_reconcile_failed');
            if ((get_option(self::STORE,[])[$code]??null)!==$old)
                return new WP_Error('company_distribution_reconcile_readback_failed');
            return ['state'=>$newChannels?'NEW_CHANNELS_INHERITED':'CREDENTIAL_POLICY_RECONCILED',
                'plan'=>$old,'changed'=>true];
        }
        if ($old!==null) return new WP_Error('company_distribution_plan_invalid');
        $channels=array_fill_keys(self::channel_blueprints(),[
            'inheritance'=>'inherit_with_company_override',
            'connection'=>'not_connected','approval'=>'pending',
            'credentials_inherited'=>false,'provider_accounts_inherited'=>false,
            'external_sending'=>false,
        ]);
        $plan=[
            'contract'=>self::CONTRACT,'company_code'=>$code,
            'company_site_uuid'=>$binding['company_site_uuid'],
            'native_site_profile_uuid'=>$binding['native_site_profile_uuid'],
            'revision'=>1,'state'=>'PENDING_COMPANY_LOCAL_PROVISION',
            'distribution_package'=>'mad4b-distribution-connectors',
            'mapper_package'=>'meta-catalog-feed-mapper-pro',
            'capability_policies'=>[
                'local_feeds'=>'auto_create_if_missing',
                'pricing'=>'source_weighted_rate_resolver',
                'destinations'=>'native_taxonomy_and_schema',
                'marketplace_accounts'=>'company_local_only',
                'external_connections'=>'company_local_only',
                'external_delivery'=>'disabled_until_verified',
                ...self::local_credential_policy(),
            ],
            'channels'=>$channels,
            'company_local_executor_required'=>true,
            'remote_target_mutated'=>false,'receipts_claimed'=>false,
            'created_at'=>gmdate('c'),
        ];
        $all[$code]=$plan;
        if (!update_option(self::STORE,$all,false))
            return new WP_Error('company_distribution_plan_write_failed');
        if ((get_option(self::STORE,[])[$code]??null)!==$plan)
            return new WP_Error('company_distribution_plan_readback_failed');
        do_action('mad4b_company_distribution_plan_prepared',$code,$plan);
        return ['state'=>'PLANNED','plan'=>$plan,'changed'=>true];
    }

    public static function on_enrolled($code): void {
        if (!is_string($code)) return;
        self::plan($code);
    }
    public static function on_portfolio_transition($event): void {
        if (!is_array($event) || !is_string($event['company_code']??null)) return;
        self::plan((string)$event['company_code']);
    }
    public static function rest(): void {
        register_rest_route('mad4b/v1','/company-distribution/plans',[
            'methods'=>'GET',
            'permission_callback'=>static fn()=>current_user_can('manage_options')
                && class_exists('MAD4B_Company_Strategy_V1',false)
                && MAD4B_Company_Strategy_V1::central(),
            'callback'=>static fn()=>rest_ensure_response([
                'contract'=>self::CONTRACT,
                'plans'=>(array)get_option(self::STORE,[]),
                'company_remote_execution_performed'=>false,
            ]),
        ]);
        register_rest_route('mad4b/v1','/company-distribution/plans/(?P<company>[a-z][a-z0-9-]+)',[
            'methods'=>'POST',
            'permission_callback'=>static fn()=>current_user_can('manage_options')
                && class_exists('MAD4B_Company_Strategy_V1',false)
                && MAD4B_Company_Strategy_V1::central(),
            'callback'=>static function(WP_REST_Request $r){
                $result=self::plan((string)$r['company']);
                return is_wp_error($result)?$result:rest_ensure_response($result);
            },
        ]);
    }
    public static function menu(): void {
        if (!class_exists('MAD4B_Company_Strategy_V1',false)
            || !MAD4B_Company_Strategy_V1::central()) return;
        add_submenu_page('mad4b-company-brand-readiness',
            'Distribution & Marketplaces','Distribution & Marketplaces',
            'manage_options','mad4b-company-distribution',[__CLASS__,'screen']);
    }
    public static function screen(): void {
        if (!current_user_can('manage_options'))wp_die('Not authorized',403);
        echo '<div class="wrap"><h1>MAD4B · Company Distribution & Marketplaces</h1>';
        echo '<p>Inherited channel definitions and company-specific onboarding. API credentials are encrypted locally using company WordPress site keys; no external Vault is needed. Central plans never copy provider secrets or accounts.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Site UUID</th><th>Provision</th><th>Channels</th><th>External delivery</th></tr></thead><tbody>';
        foreach((array)get_option(self::STORE,[]) as $company=>$row){
            if (!is_array($row))continue;
            echo '<tr><td>'.esc_html($company).'</td><td><code>'.esc_html($row['company_site_uuid']??'').'</code></td><td>'.
                esc_html($row['state']??'').'</td><td>'.count((array)($row['channels']??[])).'</td><td>OFF</td></tr>';
        }
        echo '</tbody></table><p>New companies receive their own plan on enrollment. Actual package deployment belongs to the existing authorized Company Plugin Reconciliation lifecycle.</p></div>';
    }
    public static function boot():void {
        add_action('mad4b_company_strategy_enrolled',[__CLASS__,'on_enrolled'],30,1);
        add_action('mad4b_company_portfolio_transitioned',[__CLASS__,'on_portfolio_transition'],30,1);
        add_action('rest_api_init',[__CLASS__,'rest']);
        add_action('admin_menu',[__CLASS__,'menu'],95);
    }
}
MAD4B_Company_Distribution_Provision_V1::boot();
