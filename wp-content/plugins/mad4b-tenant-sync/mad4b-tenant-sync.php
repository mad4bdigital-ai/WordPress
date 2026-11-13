<?php
/**
 * Plugin Name: MAD4B Tenant Sync & Pricing
 * Description: Tenant-owned content mapping and explicitly scoped pricing preview; no live WooCommerce repricing.
 * Version: 0.1.0-dev
 * Author: MAD4B
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/includes/class-mad4b-tenant-schema-provider-catalog.php';
require_once __DIR__.'/includes/class-mad4b-tenant-operation-authority.php';
require_once __DIR__.'/includes/class-mad4b-tenant-price-engine.php';
require_once __DIR__.'/includes/class-mad4b-tenant-field-registry.php';
require_once __DIR__.'/includes/class-mad4b-tenant-sync-planner.php';
require_once __DIR__.'/includes/class-mad4b-tenant-override-policy.php';
require_once __DIR__.'/includes/class-mad4b-tenant-sparse-store.php';
require_once __DIR__.'/includes/class-mad4b-tenant-sparse-admin.php';
require_once __DIR__.'/includes/class-mad4b-tenant-effective-read.php';
require_once __DIR__.'/includes/class-mad4b-tenant-signed-publication.php';
require_once __DIR__.'/includes/class-mad4b-tenant-shadow.php';
require_once __DIR__.'/includes/class-mad4b-tenant-consumers.php';
require_once __DIR__.'/includes/class-mad4b-tenant-commerce-preview.php';
require_once __DIR__.'/includes/class-mad4b-tenant-workflow.php';
require_once __DIR__.'/includes/class-mad4b-tenant-operations-workspace.php';
require_once __DIR__.'/includes/class-mad4b-tenant-preservation.php';
require_once __DIR__.'/includes/class-mad4b-tenant-company-media.php';
require_once __DIR__.'/includes/class-mad4b-tenant-handover-inventory.php';
require_once __DIR__.'/includes/class-mad4b-tenant-rejoin-preview.php';
require_once __DIR__.'/includes/class-mad4b-tenant-typed-export.php';
require_once __DIR__.'/includes/class-mad4b-tenant-typed-schema.php';
final class MAD4B_Tenant_Sync {
    const CONTRACT='mad4b.tenant-sync-scaffold.v1';
    const POLICY='mad4b_tenant_pricing_policy_v1';
    public static function boot(){
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('rest_api_init',[__CLASS__,'routes']);
        add_action('admin_post_mad4b_tenant_pricing_save',[__CLASS__,'save']);
    }
    public static function all_policies(){
        $store=get_option(self::POLICY,[]);
        return is_array($store)?$store:[];
    }
    public static function policy($domain){
        $all=self::all_policies();
        $record=$all[$domain]??null;
        return is_array($record)&&is_array($record['policy']??null)?$record['policy']:null;
    }
    public static function save(){
        if(!MAD4B_Tenant_Operations_Workspace::pricing())wp_die('Permission required',403);
        check_admin_referer('mad4b_tenant_price_policy');
        $profile=self::profile();
        if(empty($profile['ready']))wp_die('Site identity not ready',403);
        if(!class_exists('MAD4B_SCP_Audit') || empty(MAD4B_SCP_Audit::storage_status()['ready']))
            wp_die('Append-only audit unavailable',503);
        $domain=sanitize_key((string)wp_unslash($_POST['domain']??''));
        $model=sanitize_key((string)wp_unslash($_POST['model']??''));
        $currency=strtoupper(sanitize_text_field((string)wp_unslash($_POST['currency']??'')));
        $bps=filter_var(wp_unslash($_POST['basis_points']??null),FILTER_VALIDATE_INT);
        $fee=filter_var(wp_unslash($_POST['fixed_fee_minor']??null),FILTER_VALIDATE_INT);
        $floor=filter_var(wp_unslash($_POST['minimum_profit_minor']??null),FILTER_VALIDATE_INT);
        $round=filter_var(wp_unslash($_POST['rounding_increment_minor']??null),FILTER_VALIDATE_INT);
        $expected=filter_var(wp_unslash($_POST['expected_revision']??null),FILTER_VALIDATE_INT);
        if(!in_array($domain,['tour','accommodation','transportation'],true)
            || !in_array($model,['markup','target_margin'],true)
            || !preg_match('/^[A-Z]{3}$/D',$currency)
            || $bps===false||$bps<0||$bps>($model==='markup'?30000:8500)
            || $fee===false||$fee<0||$fee>1000000000
            || $floor===false||$floor<0||$floor>1000000000
            || $round===false||$round<1||$round>1000000
            || $expected===false||$expected<0)
                wp_die('Invalid explicit pricing policy',400);
        $all=self::all_policies();$current=(int)($all[$domain]['revision']??0);
        if($current!==$expected)wp_die('Pricing policy changed; refresh before saving',409);
        $policy=[
            'enabled'=>isset($_POST['enabled']),
            'model'=>$model,'basis_points'=>$bps,
            'fixed_fee_minor'=>$fee,'minimum_profit_minor'=>$floor,
            'rounding_increment_minor'=>$round,'display_currency'=>$currency,
        ];
        $hash=hash('sha256',wp_json_encode($policy,JSON_UNESCAPED_SLASHES));
        $audit=MAD4B_SCP_Audit::record('mad4b/tenant-pricing-policy-intent',[
            'site_uuid'=>$profile['site_uuid'],'domain'=>$domain,
            'expected_revision'=>$expected,'policy_sha256'=>$hash,
            'live_commerce_repricing_enabled'=>false,'production_mutation'=>false
        ],'intent');
        if(is_wp_error($audit))wp_die('Audit intent could not be recorded',503);
        $all[$domain]=[
            'revision'=>$current+1,'policy'=>$policy,'policy_sha256'=>$hash,
            'site_uuid'=>$profile['site_uuid'],
        ];
        update_option(self::POLICY,$all,false);
        $verify=self::all_policies();
        if(($verify[$domain]['policy_sha256']??'')!==$hash
            || (int)($verify[$domain]['revision']??0)!==$current+1)
            wp_die('Pricing policy readback failed, review required',503);
        $audit=MAD4B_SCP_Audit::record('mad4b/tenant-pricing-policy-result',[
            'site_uuid'=>$profile['site_uuid'],'domain'=>$domain,
            'revision'=>$current+1,'policy_sha256'=>$hash,
            'commercial_checkout_affected'=>false
        ],'ok');
        if(is_wp_error($audit))wp_die('Policy saved but audit result incomplete: reconcile',503);
        wp_safe_redirect(add_query_arg(['page'=>'mad4b-tenant-pricing','saved'=>'1',
            'domain'=>$domain],admin_url('admin.php')));exit;
    }
    public static function profile(){
        if(!class_exists('MAD4B_SCP_Site_Profile')) return ['ready'=>false];
        $s=MAD4B_SCP_Site_Profile::status();
        return ['ready'=>MAD4B_Tenant_Operation_Authority::scope(false)!=='',
            'site_uuid'=>(string)($s['site_uuid']??''),
            'environment'=>(string)($s['configured_environment']??wp_get_environment_type())];
    }
    public static function routes(){
        register_rest_route('mad4b-tenant/v1','/status',[
            'methods'=>'GET','callback'=>[__CLASS__,'status'],
            'permission_callback'=>static fn()=>MAD4B_Tenant_Operations_Workspace::pricing()]);
    }
    public static function status(){
        return rest_ensure_response([
            'contract'=>self::CONTRACT,'profile'=>self::profile(),
            'sync_mode'=>'not_connected','shared_offers_inherited'=>0,
            'pricing_preview_available'=>true,
            'configured_company_margin_rule'=>array_keys(self::all_policies()),
            'live_woocommerce_price_override'=>false,
            'sparse_storage_ready'=>MAD4B_Tenant_Sparse_Store::exists(),
            'content_review_scope'=>MAD4B_Tenant_Workflow::scope_status(),
            'sparse_override_capable'=>true,
            'remote_hub_publications_ingested'=>0,
            'signed_publication_verifier_ready'=>class_exists('MAD4B_Tenant_Signed_Publication'),
            'publisher_identity_enrolled'=>is_readable(MAD4B_Tenant_Signed_Publication::RUNTIME_KEY)
                && is_array(get_option('mad4b_hub_issuer_enrollment_v1',null)),
            'signed_external_ingest_enabled'=>false,
            'shadow_plan_available'=>class_exists('MAD4B_Tenant_Shadow'),
            'automatic_shadow_creation_enabled'=>false,
            'elementor_dynamic_tag_registered'=>has_action('elementor/dynamic_tags/register',
                ['MAD4B_Tenant_Consumers','register_elementor'])!==false,
            'jetengine_explicit_field_bridge'=>true,
            'rankmath_explicit_override_bridge'=>true,
            'woo_quote_preview_only'=>true,
            'woo_checkout_binding_enabled'=>false,
            'local_shadow_policy'=>'opt_in_existing_wp_or_certified_draft_only',
            'mutations_performed'=>false]);
    }
    public static function menu(){
        add_submenu_page('woocommerce','MAD4B Tenant Pricing','Tenant Pricing',
            'manage_woocommerce','mad4b-tenant-pricing',[__CLASS__,'page']);
    }
    public static function page(){
        if(!MAD4B_Tenant_Operations_Workspace::pricing())wp_die('Permission required');
        $s=self::profile();
        $domain=sanitize_key((string)($_GET['domain']??'tour'));
        if(!in_array($domain,['tour','accommodation','transportation'],true))$domain='tour';
        $all=self::all_policies();
        $record=$all[$domain]??[];
        $cfg=$record['policy']??[
            'enabled'=>false,'model'=>'markup','basis_points'=>0,
            'fixed_fee_minor'=>0,'minimum_profit_minor'=>0,
            'rounding_increment_minor'=>100,'display_currency'=>'USD',
        ];
        echo '<div class="wrap"><h1>MAD4B Tenant Pricing</h1>';
        echo '<p><strong>Tenant-owned policy:</strong> choose your markup, gross-margin target, fees and rounding. The current adapter remains preview-only; no WooCommerce quote, cart, order or payment override.</p>';
        if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>Pricing preview policy saved and audited.</p></div>';
        echo '<p>Company Site UUID: <code>'.esc_html($s['site_uuid']??'').'</code></p>';
        echo '<p>Source price type must be verified. A reference retail rate cannot be treated as supplier cost or used for target gross margin.</p>';
        echo '<form method="get"><input type="hidden" name="page" value="mad4b-tenant-pricing"><label>Business domain <select name="domain">';
        foreach(['tour','accommodation','transportation'] as $type)
            echo '<option value="'.esc_attr($type).'" '.selected($type,$domain,false).'>'.esc_html(ucfirst($type)).'</option>';
        echo '</select></label> <button class="button">Select</button></form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_tenant_price_policy');
        echo '<input type="hidden" name="action" value="mad4b_tenant_pricing_save">';
        echo '<input type="hidden" name="domain" value="'.esc_attr($domain).'">';
        echo '<input type="hidden" name="expected_revision" value="'.(int)($record['revision']??0).'">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Enable company pricing preview rule</th><td><input type="checkbox" name="enabled" '.checked(!empty($cfg['enabled']),true,false).'></td></tr>';
        echo '<tr><th>Pricing model</th><td><select name="model">';
        foreach(['markup'=>'Markup on source','target_margin'=>'Target gross margin on verified cost'] as $mode=>$title)
            echo '<option value="'.esc_attr($mode).'" '.selected($cfg['model'],$mode,false).'>'.esc_html($title).'</option>';
        echo '</select></td></tr>';
        foreach(['basis_points'=>'Basis points (2000 = 20%)','fixed_fee_minor'=>'Fixed fee in currency minor units','minimum_profit_minor'=>'Minimum difference in minor units','rounding_increment_minor'=>'Round UP to minor-unit increment'] as $field=>$label)
            echo '<tr><th>'.esc_html($label).'</th><td><input type="number" name="'.esc_attr($field).'" min="0" value="'.(int)($cfg[$field]??0).'"></td></tr>';
        echo '<tr><th>Display currency</th><td><input name="currency" pattern="[A-Z]{3}" maxlength="3" value="'.esc_attr($cfg['display_currency']).'"></td></tr>';
        echo '</tbody></table>';
        submit_button('Save preview policy — no live price changes');
        echo '</form></div>';
    }
}
MAD4B_Tenant_Sync::boot();
