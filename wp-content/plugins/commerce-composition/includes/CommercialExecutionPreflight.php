<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Policy + first-party pricing + native CPT/Woo identity readback.
 * This is a PRE-FLIGHT receipt, NEVER a final customer quote or sale grant.
 * Providers/approval writers must remain separately certified.
 */
final class CommercialExecutionPreflight
{
    private const NS='commerce-composition/v1';
    private static function error(string $code):\WP_Error {
        return new \WP_Error($code,'Commercial preflight unavailable');
    }
    public static function allowed():bool {
        return current_user_can('manage_options') && current_user_can('manage_woocommerce');
    }
    public static function inspect(int $sourceId, array $configuration,
        string $market='',string $channel='web'):array|\WP_Error
    {
        if(!self::allowed())return self::error('commercial_preflight_forbidden');
        if($sourceId<1||!is_array($configuration)||array_is_list($configuration)
            ||!function_exists('wc_get_product'))
            return self::error('commercial_preflight_invalid_input');
        $requestedRate=$configuration['rate_id']??null;
        if($requestedRate!==null&&(!is_int($requestedRate)||$requestedRate<1))
            return self::error('commercial_preflight_invalid_rate');
        $policy=CompanyCommercePolicy::resolve($sourceId,$requestedRate,$market,$channel);
        if(is_wp_error($policy))return $policy;
        $identity=$policy['source'];
        // Configuration must refer to the source's own canonical identity.
        // No caller-provided product or Rate ID may override native ancestry.
        if(($configuration['tour_id']??null)!==$identity['service_id']||
           ($configuration['rate_id']??null)!==$identity['rate_id']||
           !in_array($configuration['source']??null,
                [$identity['rate_id']!==null?'rate':'direct'],true)||
           ($configuration['schema_version']??null)!==2)
            return self::error('commercial_preflight_product_rate_context_mismatch');
        $choices=$identity['sale_item_choices'];
        $selected=[];
        foreach($choices as $name=>$choice){
            if(($choice['policy_selected']??false)!==true)continue;
            $id=$choice['product_id']??null;
            if(!is_int($id)||$id<1)continue;
            $product=wc_get_product($id);
            if(!($product instanceof \WC_Product)||(int)$product->get_id()!==$id)
                continue;
            $selected[]=[
                'role'=>$name,'product_id'=>$id,
                'source_post_type'=>get_post_type($id),
                'current_woo_purchasable'=>$product->is_purchasable(),
                'quantity_source'=>'validated_booking_configuration',
                'would_be_cart_line_only'=>true,
                'cart_write_authorized'=>false,
                'order_item_write_authorized'=>false
            ];
        }
        if(!$selected)return self::error('commercial_preflight_no_mapped_product');
        // A separate line for each choice is a CHOICE, not a multi-line
        // Cart instruction. Prevent charging the parent and Rate twice.
        $requestedMode=(string)($configuration['booking_intent']??'quote');
        $supported=($identity['service_type']==='tours-and-activities')
            &&function_exists('shared_product_pricing_quote_contract_v2');
        $quote=null;$pricingError=null;
        if($supported){
            $original=shared_product_pricing_quote_contract_v2($configuration);
            if(is_wp_error($original))$pricingError=$original->get_error_code();
            else $quote=$original;
        }else $pricingError='domain_quote_provider_not_certified';
        $pricingValid=is_array($quote)
            &&is_numeric($quote['total']??null)
            &&is_string($quote['currency']??null)
            &&preg_match('/^[A-Z]{3}$/D',$quote['currency'])
            &&($quote['checkout_ready']??true)===false
            &&($quote['availability_status']??'')==='not_checked_price_only';
        if($quote!==null&&!$pricingValid)
            return self::error('commercial_preflight_unexpected_pricing_contract');
        $ruleCatalog=function_exists('shared_product_pricing_rules_catalog_v2')?
            shared_product_pricing_rules_catalog_v2():[];
        if(!is_array($ruleCatalog))$ruleCatalog=[];
        $rules=[];
        foreach([
            'supplier_cost_margin','travelers_age','accommodation','transport',
            'availability','offers_coupons','tax_fee','manual_approval'
        ] as $key){
            $native=$ruleCatalog[$key]??[];
            $state=is_array($native)&&is_string($native['state']??null)?
                $native['state']:'missing';
            $rules[$key]=[
                'state'=>$state,
                'certified'=>false,
                'owner'=>is_array($native)?($native['authority']??'unknown'):'unknown'
            ];
        }
        $policyEffective=$policy['effective'];
        $approval=[
            'manager'=>[
                'company_mode'=>$policyEffective['manager_approval'],
                'exact_quote_digest_verified'=>false,
                'approval_verified'=>false
            ],
            'traveler'=>[
                'company_mode'=>$policyEffective['traveler_acceptance'],
                'accepted_same_quote_digest'=>false,
                'acceptance_verified'=>false
            ]
        ];
        // The native Payment/Inventory/Supplier writers are not wired to a
        // certified, accepted final Quote in this release.
        $execution=[
            'policy_active'=>$policy['status']==='active',
            'company_site_authority_ready'=>!in_array('site_authority_not_ready',
                $policy['execution_blockers'],true),
            'domain_quote_preview_available'=>$pricingValid,
            'final_price_certified'=>false,
            'supplier_cost_certified'=>false,
            'tax_certified'=>false,
            'availability_confirmed'=>false,
            'inventory_hold_confirmed'=>false,
            'manager_decision_verified'=>false,
            'traveler_acceptance_verified'=>false,
            'native_woocommerce_purchasability_certified'=>false,
            'order_item_mapping_certified'=>false,
            'woocommerce_order_authorized'=>false,
            'payment_authorized'=>false,
            'supplier_booking_authorized'=>false,
            'accounting_posting_authorized'=>false
        ];
        $blocks=[];
        foreach($execution as $step=>$yes)if($yes!==true)$blocks[]=$step;
        $components=is_array($quote)?($quote['components']??[]):[];
        $preview=[
            'status'=>$pricingValid?'indicative_only':'provider_requires_review',
            'amount'=>$pricingValid?(float)$quote['total']:null,
            'currency'=>$pricingValid?$quote['currency']:null,
            'availability_status'=>$pricingValid?$quote['availability_status']:'unknown',
            'components_count'=>is_array($components)?count($components):0,
            'provider_error'=>$pricingError,
            'price_is_final'=>false
        ];
        $digest=hash('sha256',wp_json_encode([
            $policy['site_uuid'],$policy['policy_digest'],
            $identity['service_id'],$identity['rate_id'],
            $configuration,$preview,$selected
        ],JSON_UNESCAPED_SLASHES));
        return [
            'contract'=>'commerce.commercial-preflight.v1',
            'site_uuid'=>$policy['site_uuid'],
            'policy_revision'=>$policy['policy_revision'],
            'policy_digest'=>$policy['policy_digest'],
            'preflight_digest'=>$digest,
            'source'=>[
                'service_id'=>$identity['service_id'],
                'service_type'=>$identity['service_type'],
                'rate_id'=>$identity['rate_id'],
                'relationship_verified'=>$identity['relationship_verified']
            ],
            'sale_line_choices'=>$selected,
            'sale_mode'=>$policyEffective['rate_sale_mode'],
            'single_sale_line_required'=>true,
            'double_charge_parent_and_rate_forbidden'=>true,
            'pricing_preview'=>$preview,
            'financial_rule_coverage'=>$rules,
            'company_policy'=>[
                'status'=>$policy['status'],
                'cost_source'=>$policyEffective['supplier_cost_source'],
                'margin_mode'=>$policyEffective['margin_mode'],
                'tax_mode'=>$policyEffective['tax_mode'],
                'availability_mode'=>$policyEffective['availability_mode'],
                'order_timing'=>$policyEffective['order_timing'],
                'supplier_booking_timing'=>$policyEffective['supplier_booking_timing']
            ],
            'approvals'=>$approval,
            'execution'=>$execution,
            'blockers'=>$blocks,
            'commercial_quote_issued'=>false,
            'manager_approval_recorded'=>false,
            'traveler_acceptance_recorded'=>false,
            'cart_mutated'=>false,
            'order_created'=>false,
            'supplier_booking_created'=>false,
            'no_side_effects'=>true
        ];
    }
    public static function routes():void {
        register_rest_route(self::NS,'/management/commercial-quote/preflight',[
            'methods'=>\WP_REST_Server::CREATABLE,
            'permission_callback'=>[self::class,'allowed'],
            'callback'=>static function(\WP_REST_Request $request){
                $body=$request->get_json_params();
                if(!is_array($body)||array_is_list($body))
                    return new \WP_Error('commercial_preflight_json_invalid',
                        'Invalid input',['status'=>400]);
                $keys=array_keys($body);sort($keys);
                $allowed=['source_id','configuration','market','channel'];
                if(array_diff($keys,$allowed)||
                   !is_int($body['source_id']??null)||
                   !is_array($body['configuration']??null))
                    return new \WP_Error('commercial_preflight_schema_invalid',
                        'Invalid input',['status'=>400]);
                $result=self::inspect($body['source_id'],$body['configuration'],
                    (string)($body['market']??''),(string)($body['channel']??'web'));
                if(is_wp_error($result))
                    return new \WP_Error($result->get_error_code(),
                        'Preflight unavailable',['status'=>422]);
                $response=new \WP_REST_Response($result,200);
                $response->header('Cache-Control','private,no-store');
                return $response;
            }
        ]);
    }
    public static function bootstrap():void{
        add_action('rest_api_init',[self::class,'routes']);
    }
}
