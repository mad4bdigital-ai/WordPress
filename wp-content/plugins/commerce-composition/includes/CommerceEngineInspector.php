<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Admin-only, read-only observation of source-owned commerce and rendering
 * engines. NEVER treats plugin registration or a preview as write authority.
 */
final class CommerceEngineInspector
{
    private const ROOT='commerce-composition/v1';

    private static function context():array|\WP_Error {
        return CompanyManagementRest::inspectorContext();
    }
    private static function response(array $value):\WP_REST_Response {
        $r=new \WP_REST_Response(['schema'=>'commerce.engine-inspection.v1']+$value,200);
        $r->header('Cache-Control','private,no-store');
        return $r;
    }
    private static function unavailable(string $code,int $status=422):\WP_Error {
        return new \WP_Error($code,'Engine inspection unavailable',['status'=>$status]);
    }
    private static function optionalPlugin(string $slug):bool {
        foreach((array)get_option('active_plugins',[]) as $file)
            if(is_string($file)&&str_starts_with($file,$slug.'/'))return true;
        return false;
    }
    private static function rules():array {
        if(!function_exists('shared_product_pricing_rules_catalog_v2'))
            return ['status'=>'provider_unmounted','registered'=>0,'incomplete'=>[]];
        $catalog=shared_product_pricing_rules_catalog_v2();
        if(!is_array($catalog))return ['status'=>'provider_invalid','registered'=>0,'incomplete'=>[]];
        $incomplete=[];
        foreach($catalog as $key=>$value){
            $state=is_array($value)?(string)($value['state']??'unknown'):'unknown';
            if(str_starts_with($state,'not_integrated')||$state==='partial')
                $incomplete[]=['rule'=>(string)$key,'state'=>$state];
        }
        return ['status'=>'read_only_preview','registered'=>count($catalog),
            'incomplete'=>$incomplete,'final_quote_certified'=>false];
    }
    public static function overview():array|\WP_Error {
        $ctx=self::context();
        if(is_wp_error($ctx))return $ctx;
        $domains=$ctx['definition']['domains'];
        $owners=[
            'tour_price'=>['domain'=>'quotes','provider'=>'shared_product_pricing_quote_contract_v2',
                'mounted'=>function_exists('shared_product_pricing_quote_contract_v2')],
            'accommodation'=>['domain'=>'rates','provider'=>'abk_accommodation_shadow_quote',
                'mounted'=>function_exists('abk_accommodation_shadow_quote')],
            'transport'=>['domain'=>'rates','provider'=>'BookingExperience.transport_rate_source',
                'mounted'=>function_exists('BookingExperience\\transport_rate_source')],
            'booking_forms'=>['domain'=>'forms','provider'=>'Commerce.Composition.FormRouter',
                'mounted'=>class_exists(FormRouter::class)],
            'cpt_woocommerce'=>['domain'=>'services','provider'=>'cpt_woo_integration',
                'mounted'=>function_exists('cptwooint')&&function_exists('wc_get_product')],
            'inventory'=>['domain'=>'inventory','provider'=>'abk_order_inventory_readiness',
                'mounted'=>function_exists('abk_order_inventory_readiness')],
            'reviews'=>['domain'=>'reviews','provider'=>'Commerce.Composition.RequestReview',
                'mounted'=>class_exists(RequestReview::class)],
            'supplier'=>['domain'=>'suppliers','provider'=>'Commerce.Composition.RequestSupplierReadiness',
                'mounted'=>class_exists(RequestSupplierReadiness::class)],
            'workflow_jobs'=>['domain'=>'workflows','provider'=>'Commerce.Composition.WorkflowJobs',
                'mounted'=>class_exists(WorkflowJobs::class)],
            'woocommerce_hpos'=>['domain'=>'orders','provider'=>'WooCommerce.HPOS',
                'mounted'=>class_exists('WooCommerce')&&get_option('woocommerce_custom_orders_table_enabled')==='yes'],
            'elementor'=>['domain'=>'services','provider'=>'Elementor.DynamicTags',
                'mounted'=>self::optionalPlugin('elementor')],
            'jetengine'=>['domain'=>'services','provider'=>'JetEngine.CPT.Metadata',
                'mounted'=>self::optionalPlugin('jet-engine')],
            'jetsmartfilters'=>['domain'=>'services','provider'=>'JetSmartFilters.Archive',
                'mounted'=>self::optionalPlugin('jet-smart-filters')],
            'fluentboards'=>['domain'=>'tasks','provider'=>'FluentBoards.Tasks',
                'mounted'=>self::optionalPlugin('fluent-boards')]
        ];
        foreach($owners as $name=>&$engine) {
            $engine['company_enabled']=$domains[$engine['domain']]??false;
            $engine['state']=!$engine['company_enabled']?'company_disabled':
                ($engine['mounted']?'registered_not_commercially_certified':'provider_not_mounted');
            $engine['mutation_authorized']=false;
        }
        unset($engine);
        return [
            'site_uuid'=>$ctx['site_uuid'],
            'company_definition_revision'=>(int)$ctx['definition']['revision'],
            'environment'=>$ctx['environment'],
            'source_types'=>$ctx['definition']['source_types'],
            'engines'=>$owners,
            'pricing_rules'=>self::rules(),
            'write_ready'=>$ctx['write_ready'],
            'write_blockers'=>$ctx['write_blockers'],
            'pricing_approved'=>false,'checkout_authorized'=>false,
            'supplier_booking_authorized'=>false
        ];
    }
    public static function product(int $sourceId,?int $rateId=null,?string $date=null):array|\WP_Error {
        $ctx=self::context();
        if(is_wp_error($ctx))return $ctx;
        if($sourceId<1||($rateId!==null&&$rateId<1))
            return self::unavailable('engine_source_invalid',400);
        if($date!==null){
            $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!$parsed||$parsed->format('Y-m-d')!==$date)
                return self::unavailable('engine_date_invalid',400);
        }
        if(($ctx['definition']['domains']['services']??false)!==true)
            return self::unavailable('engine_service_domain_disabled',403);
        $allowed=(array)($ctx['definition']['source_types']['services']??[]);
        $post=get_post($sourceId);
        if(!($post instanceof \WP_Post)||$post->post_status!=='publish'||
           !in_array($post->post_type,$allowed,true))
            return self::unavailable('engine_service_not_authorized',404);

        $rateStatus=['id'=>$rateId,'state'=>$rateId===null?'not_selected':'not_verified'];
        if($rateId!==null){
            $rate=get_post($rateId);
            $types=(array)($ctx['definition']['source_types']['rates']??[]);
            if(!($rate instanceof \WP_Post)||$rate->post_status!=='publish'||
               !in_array($rate->post_type,$types,true))
                return self::unavailable('engine_rate_not_authorized',404);
            $proof=RateProvenance::verify($sourceId,$rateId);
            if(is_wp_error($proof))
                return self::unavailable('engine_rate_not_owned',422);
            $rateStatus['state']='source_relation_verified_only';
            if($post->post_type==='tours-and-activities'&&$date!==null){
                $inspected=TourRateEligibility::inspect($sourceId,$rateId,$date);
                $rateStatus['state']=is_wp_error($inspected)?'needs_review':
                    (($inspected['rate_selection_eligible']??false)?'eligible_preview_only':'needs_review');
                $rateStatus['blockers']=is_array($inspected)?$inspected['blockers']:
                    [$inspected->get_error_code()];
            }
        }
        $identity=ServiceProductIdentity::inspect($sourceId);
        $identityState=is_wp_error($identity)?$identity->get_error_code():
            'native_cpt_wc_identity_readable';
        // The original domain FormRouter owns display-mode decisions; no
        // alternative form ID or display policy is invented by this inspector.
        $form=FormRouter::describe($sourceId,$rateId);
        $display=is_wp_error($form)?[
            'status'=>'requires_review','reason'=>$form->get_error_code(),
            'booking_available'=>false,'checkout_authority'=>false]:[
            'status'=>'native_policy_read','mode'=>$form['mode']??null,
            'inquiry_form_id'=>$form['inquiry_form_id']??null,
            'booking_form_id'=>$form['booking_form_id']??null,
            'booking_available'=>$form['booking_available']??false,
            'checkout_authority'=>false
        ];
        $types=($ctx['definition']['source_types']??[]);
        return [
            'site_uuid'=>$ctx['site_uuid'],'source_id'=>$sourceId,
            'source_type'=>$post->post_type,'published'=>true,
            'source_owner'=>'wordpress_service_cpt','wc_identity'=>$identityState,
            'rate'=>$rateStatus,'booking_interface'=>$display,
            'presentation'=>[
                'elementor_mounted'=>self::optionalPlugin('elementor'),
                'jetengine_mounted'=>self::optionalPlugin('jet-engine'),
                'jetsmartfilters_mounted'=>self::optionalPlugin('jet-smart-filters'),
                'tour_price_tag_provider'=>function_exists('tour_component_structured_price_value'),
                'transport_price_tag_provider'=>function_exists('transport_component_price_part'),
                'company_text_tag_provider'=>function_exists('company_presentation_text'),
                'dynamic_render_certified'=>false,
                'source_aware_template_validation_required'=>true
            ],
            'pricing_quote_status'=>'preview_contract_not_final_approval',
            'quote_approved'=>false,
            'checkout_authorized'=>false,
            'supplier_booking_authorized'=>false
        ];
    }
    public static function routes():void {
        $auth=static fn()=>current_user_can('manage_options');
        register_rest_route(self::ROOT,'/management/engines',[
            'methods'=>\WP_REST_Server::READABLE,'permission_callback'=>$auth,
            'callback'=>static function(){
                $data=self::overview();
                return is_wp_error($data)?$data:self::response($data);
            }]);
        register_rest_route(self::ROOT,'/management/product-context/(?P<source_id>[1-9][0-9]*)',[
            'methods'=>\WP_REST_Server::READABLE,'permission_callback'=>$auth,
            'args'=>[
                'rate_id'=>['type'=>'integer','minimum'=>1],
                'travel_date'=>['type'=>'string']
            ],
            'callback'=>static function(\WP_REST_Request $req){
                $rate=$req->get_param('rate_id');
                $date=$req->get_param('travel_date');
                $data=self::product((int)$req['source_id'],
                    $rate===null?null:(int)$rate,$date===null?null:(string)$date);
                return is_wp_error($data)?$data:self::response($data);
            }]);
    }
    public static function bootstrap():void {
        add_action('rest_api_init',[self::class,'routes']);
    }
}
