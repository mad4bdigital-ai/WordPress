<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Company-specific commercial-quote evidence audit, directly over native
 * Preflight, Quote Plan and domain pricing providers. Read-only; no fabricated
 * financial receipts, tax zeros, availability holds or user approvals.
 */
final class CommercialQuoteReadiness
{
    private const NS='commerce-composition/v1';

    private static function gate(string $owner,string $state,string $next,array $reasons=[]):array
    {
        return ['owner'=>$owner,'state'=>$state,'next_action'=>$next,
            'reasons'=>array_values(array_unique($reasons))];
    }

    /**
     * Server-side-only provider discovery. Registration is NOT evidence
     * verification. An installed adapter must supply a callable and an exact
     * tenant-bound contract, not merely a client-supplied provider name.
     */
    private static function providerRegistry(string $siteUuid):array
    {
        $declared=apply_filters('commerce_composition_final_quote_evidence_providers',[]);
        if(!is_array($declared))return [];
        $allowed=['final_quote','supplier_cost','tax','availability',
            'manager_approval','traveler_acceptance'];
        $registered=[];
        foreach($allowed as $kind){
            $v=$declared[$kind]??null;
            if(!is_array($v)||($v['contract']??null)!=='commerce.source-evidence-provider.v1'
                ||($v['site_uuid']??null)!==$siteUuid
                ||!is_string($v['provider_id']??null)
                ||!preg_match('/^[a-z][a-z0-9_.-]{2,79}$/D',$v['provider_id'])
                ||!is_callable($v['read']??null))continue;
            $registered[$kind]=[
                'provider_id'=>$v['provider_id'],
                'source_owner'=>(is_string($v['source_owner']??null) &&
                    preg_match('/^[a-z][a-z0-9_.-]{2,79}$/D',$v['source_owner']))?
                    $v['source_owner']:'unknown',
                'registered'=>true,'evidence_certified'=>false,
                'execution_authorized'=>false
            ];
        }
        return $registered;
    }

    public static function overview():array|\WP_Error
    {
        $ctx=CompanyCommercePolicy::context();
        if(is_wp_error($ctx))return $ctx;
        $policy=CompanyCommercePolicy::registry();
        if(is_wp_error($policy))return $policy;
        $providers=self::providerRegistry($ctx['site_uuid']);
        $taxOption=(string)get_option('woocommerce_calc_taxes','no');
        $rules=function_exists('shared_product_pricing_rules_catalog_v2')
            ?shared_product_pricing_rules_catalog_v2():[];
        if(!is_array($rules))$rules=[];
        $coverage=[];
        foreach(['supplier_cost_margin','tax_fee','availability','manual_approval',
                  'travelers_age','accommodation','transport','offers_coupons'] as $field){
            $entry=$rules[$field]??[];
            $coverage[$field]=[
                'owner'=>is_array($entry)?(string)($entry['authority']??'unknown'):'unknown',
                'provider_state'=>is_array($entry)?(string)($entry['state']??'missing'):'missing',
                'certified_for_final_quote'=>false
            ];
        }
        return [
            'contract'=>'commerce.commercial-quote-provider-overview.v1',
            'site_uuid'=>$ctx['site_uuid'],
            'site_profile_revision'=>$ctx['profile_revision'],
            'company_definition_revision'=>$ctx['company_revision'],
            'policy_revision'=>$policy['revision'],'policy_status'=>$policy['status'],
            'company_policy_source'=>'commerce_company_policy_registry_v1',
            'woocommerce_tax_calculation'=>$taxOption,
            'native_rule_coverage'=>$coverage,
            'registered_source_providers'=>$providers,
            'final_quote_provider_registered'=>isset($providers['final_quote']),
            'supplier_cost_receipt_registered'=>isset($providers['supplier_cost']),
            'tax_receipt_registered'=>isset($providers['tax']),
            'availability_hold_receipt_registered'=>isset($providers['availability']),
            'manager_approval_receipt_registered'=>isset($providers['manager_approval']),
            'traveler_acceptance_receipt_registered'=>isset($providers['traveler_acceptance']),
            'commercial_quote_certification_authorized'=>false,
            'woo_order_creation_authorized'=>false,
            'supplier_booking_authorized'=>false,
        ];
    }

    public static function inspect(int $sourceId,array $configuration,string $saleRole,
        string $market='',string $channel='web'):array|\WP_Error
    {
        $ctx=CompanyCommercePolicy::context();
        if(is_wp_error($ctx))return $ctx;
        $preflight=CommercialExecutionPreflight::inspect($sourceId,$configuration,$market,$channel);
        if(is_wp_error($preflight))return $preflight;
        $plan=CommercialQuotePlan::fromPreflight($preflight,$saleRole);
        if(is_wp_error($plan))return $plan;
        $policy=CompanyCommercePolicy::resolve($sourceId,
            isset($configuration['rate_id'])?(int)$configuration['rate_id']:null,
            $market,$channel);
        if(is_wp_error($policy))return $policy;
        // Immutable cross-domain consistency; a caller cannot rewrite the
        // selected Woo ID, Rate parent, site or policy digest.
        if(($policy['site_uuid']??null)!==($preflight['site_uuid']??null)||
            ($policy['policy_revision']??null)!==($preflight['policy_revision']??null)||
            ($policy['policy_digest']??null)!==($preflight['policy_digest']??null)||
            ($plan['chosen_sale_line']['service_id']??null)!==
                ($policy['source']['service_id']??null)||
            ($plan['chosen_sale_line']['rate_id']??null)!==
                ($policy['source']['rate_id']??null))
            return new \WP_Error('commercial_readiness_context_mismatch','Commercial readiness context invalid');

        $effective=$policy['effective'];
        $source=$policy['source'];
        $ratesId=$source['rate_id'];
        $financial=null;
        $financialBlockers=[];
        if($source['service_type']==='tours-and-activities'
            &&function_exists('shared_product_financial_readiness_v2')){
            $financial=shared_product_financial_readiness_v2(
                $source['service_id'],$ratesId);
            if(is_wp_error($financial))
                $financialBlockers[]='financial_readiness:'.$financial->get_error_code();
            elseif(is_array($financial)){
                foreach((array)($financial['blockers']??[]) as $reason)
                    if(is_string($reason))$financialBlockers[]=$reason;
            }
        }else $financialBlockers[]='domain_financial_readiness_provider_unavailable';

        $woo=wc_get_product($plan['chosen_sale_line']['woo_product_id']);
        $nativeIdentity=$woo instanceof \WC_Product &&
            (int)$woo->get_id()===$plan['chosen_sale_line']['woo_product_id'];
        $nativePurchasable=$nativeIdentity&&$woo->is_purchasable();

        $rules=shared_product_pricing_rules_catalog_v2();
        if(!is_array($rules))$rules=[];
        $isComplete=static function(string $key) use($rules):bool{
            $entry=$rules[$key]??null;
            return is_array($entry)
                &&($entry['state']??'')==='integrated'
                &&($entry['certified_final_quote']??false)===true;
        };

        $gates=[
            'site_company_policy'=>self::gate('MAD4B Site Profile / Company Policy',
                $policy['status']==='active'?'verified':'blocked',
                'approve_tenant_policy_draft',
                $policy['status']==='active'?[]:['company_policy_not_active']),
            'native_rate_and_product'=>self::gate('CPT Woo Integration / RateProvenance',
                $nativeIdentity?'verified':'blocked',
                'verify_sale_line_existing_wc_product_identity',
                $nativeIdentity?[]:['woo_identity_unavailable']),
            'financial_mode'=>self::gate('shared_product_financial_readiness_v2',
                !$financialBlockers?'needs_review':'blocked',
                'approve_native_mode_financial_policy',
                $financialBlockers?:['native_financial_policy_not_final_quote']),
            'final_pricing'=>self::gate('shared_product_pricing_quote_contract_v2',
                'blocked','register_certified_final_quote_provider',
                ['native_quote_is_indicative_preview_only']),
            'supplier_cost_margin'=>self::gate('company_supplier_cost_and_margin',
                'blocked',
                $effective['supplier_cost_source']==='manual_review'?
                    'obtain_approved_supplier_cost_and_margin_receipt':
                    'register_verified_supplier_cost_provider',
                $isComplete('supplier_cost_margin')?['final_receipt_not_provided']:
                    ['native_supplier_cost_margin_not_certified']),
            'tax_and_fees'=>self::gate('company_tax_and_woocommerce',
                'blocked','obtain_tax_fee_authority_receipt',
                array_values(array_unique(array_filter([
                    'tax_fee_final_receipt_missing',
                    get_option('woocommerce_calc_taxes','no')!=='yes'
                        ?'woocommerce_tax_calculation_disabled':null,
                    $effective['tax_mode']==='manual_review'
                        ?'company_tax_manual_review_required':null,
                ])))),
            'availability_inventory'=>self::gate('company_inventory_supplier',
                'blocked',
                $effective['availability_mode']==='manual_confirmation'?
                    'obtain_supplier_confirmation_and_expiring_hold':
                    'obtain_expiring_provider_allotment_or_live_hold',
                ['native_availability_hold_receipt_missing']),
            'manager_decision'=>self::gate('Commerce Manager / Company Policy',
                'blocked',$plan['requirements']['manager_approval'],
                ['no_signed_final_quote_digest_to_approve']),
            'traveler_acceptance'=>self::gate('Traveler / Verified Channel',
                'blocked',$plan['requirements']['traveler_acceptance'],
                ['no_final_quote_digest_accepted']),
            'woo_cart_order'=>self::gate('WooCommerce HPOS',
                'blocked','verify_accepted_final_quote_and_cart_line_idempotency',
                array_values(array_unique(array_filter([
                    'native_checkout_not_certified',
                    !$nativePurchasable?'woo_product_not_purchasable':null
                ])))),
            'supplier_fulfilment'=>self::gate('Supplier Provider',
                'blocked','bind_confirmed_supplier_reference_to_accepted_order',
                ['supplier_booking_receipt_missing'])
        ];
        $pending=array_keys(array_filter($gates,
            static fn(array $g)=>$g['state']!=='verified'));
        return [
            'contract'=>'commerce.commercial-quote-readiness.v1',
            'site_uuid'=>$ctx['site_uuid'],
            'policy_revision'=>$policy['policy_revision'],
            'policy_digest'=>$policy['policy_digest'],
            'preflight_digest'=>$preflight['preflight_digest'],
            'plan_digest'=>$plan['plan_digest'],
            'company_policy_status'=>$policy['status'],
            'selected_sale_line'=>$plan['chosen_sale_line'],
            'indicative_preview'=>$plan['indicative_preview'],
            'pricing_provider_status'=>$preflight['pricing_preview']['status'],
            'native_financial_blockers'=>$financialBlockers,
            'gates'=>$gates,
            'pending_gates'=>$pending,
            'pending_gate_count'=>count($pending),
            'readiness_digest'=>hash('sha256',wp_json_encode([
                $ctx['site_uuid'],$policy['policy_digest'],
                $preflight['preflight_digest'],$plan['plan_digest'],$gates
            ],JSON_UNESCAPED_SLASHES)),
            'final_quote_certified'=>false,
            'commercial_quote_issued'=>false,
            'manager_approval_recorded'=>false,
            'traveler_acceptance_recorded'=>false,
            'checkout_authorized'=>false,
            'order_authorized'=>false,
            'payment_authorized'=>false,
            'supplier_booking_authorized'=>false,
            'no_side_effects'=>true
        ];
    }

    public static function routes():void
    {
        $auth=static fn()=>current_user_can('manage_options')&&current_user_can('manage_woocommerce');
        register_rest_route(self::NS,'/management/commercial-quote/providers',[
            'methods'=>\WP_REST_Server::READABLE,
            'permission_callback'=>$auth,
            'callback'=>static function(){
                $out=self::overview();
                if(is_wp_error($out))return $out;
                $r=new \WP_REST_Response($out,200);
                $r->header('Cache-Control','private,no-store');
                return $r;
            }
        ]);
        register_rest_route(self::NS,'/management/commercial-quote/readiness',[
            'methods'=>\WP_REST_Server::CREATABLE,
            'permission_callback'=>$auth,
            'callback'=>static function(\WP_REST_Request $request){
                $body=$request->get_json_params();
                if(!is_array($body)||array_is_list($body)
                    ||!is_int($body['source_id']??null)
                    ||!is_array($body['configuration']??null)
                    ||!is_string($body['sale_role']??null)
                    ||array_diff(array_keys($body),
                        ['source_id','configuration','sale_role','market','channel']))
                    return new \WP_Error('commercial_readiness_invalid_input',
                        'Invalid commercial readiness request',['status'=>400]);
                $r=self::inspect($body['source_id'],$body['configuration'],
                    $body['sale_role'],(string)($body['market']??''),
                    (string)($body['channel']??'web'));
                if(is_wp_error($r))
                    return new \WP_Error($r->get_error_code(),
                        'Commercial readiness unavailable',['status'=>422]);
                $response=new \WP_REST_Response($r,200);
                $response->header('Cache-Control','private,no-store');
                return $response;
            }
        ]);
    }
    public static function bootstrap():void
    {
        add_action('rest_api_init',[self::class,'routes']);
    }
}
