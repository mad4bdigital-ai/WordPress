<?php
/**
 * Plugin Name: MAD4B Package Supplier Admission Readback
 * Version: 1.0.0
 * Description: Native-provider-only readiness of Package cost components; no rates, quotes, or checkout changes.
 */
defined('ABSPATH') || exit;

final class MAD4B_Package_Supplier_Admission_V1 {
    const CONTRACT='mad4b.package-supplier-admission.read.v1';
    const LINK_META='_mad4b_package_stay_verified_room_program_links_v1';

    public const AJAX='mad4b_package_supplier_admission_review_v1';

    public static function ajax():void {
        if(!current_user_can('commerce_manage_pricing') ||
           !current_user_can('manage_woocommerce'))
            wp_send_json_error(['code'=>'package_admission_forbidden'],403);
        if(!check_ajax_referer(self::AJAX,'nonce',false))
            wp_send_json_error(['code'=>'package_admission_nonce_invalid'],403);
        $id=filter_var($_POST['tour_id']??null,FILTER_VALIDATE_INT);
        $adults=filter_var($_POST['adults']??null,FILTER_VALIDATE_INT);
        $children=filter_var($_POST['children']??null,FILTER_VALIDATE_INT);
        $infants=filter_var($_POST['infants']??null,FILTER_VALIDATE_INT);
        $rawDate=$_POST['date']??'';
        $rawCurrency=$_POST['currency']??'EGP';
        if(!is_string($rawDate)||!is_string($rawCurrency))
            wp_send_json_error(['code'=>'package_admission_dimensions_invalid'],422);
        $date=sanitize_text_field(wp_unslash($rawDate));
        $currency=strtoupper(sanitize_key(wp_unslash($rawCurrency)));
        if($id===false||$adults===false||$children===false||$infants===false)
            wp_send_json_error(['code'=>'package_admission_dimensions_invalid'],422);
        $result=self::inspect((int)$id,$date,$currency,(int)$adults,(int)$children,(int)$infants);
        if(is_wp_error($result))
            wp_send_json_error(['code'=>$result->get_error_code()],422);
        wp_send_json_success(['result'=>$result,'original_source'=>'read_only',
            'edit_tour_url'=>current_user_can('edit_post',(int)$id)
                ?get_edit_post_link((int)$id,'raw'):null,
            'saved'=>false]);
    }

    public static function enqueue():void {
        if(!is_admin()||
            !in_array((string)($_GET['page']??''),
                ['dynamic-pricing-controls','etg-dynamic-pricing-controls'],true)||
            !current_user_can('commerce_manage_pricing')||
            !current_user_can('manage_woocommerce'))return;
        $file=WPMU_PLUGIN_DIR.'/assets/mad4b-package-supplier-admission-review-v1.js';
        if(!is_readable($file))return;
        $tours=get_posts(['post_type'=>'tours-and-activities',
            'post_status'=>'publish','posts_per_page'=>500,
            'fields'=>'ids','orderby'=>'title','order'=>'ASC',
            'suppress_filters'=>true]);
        $rows=[];
        foreach($tours as $id){
            $id=(int)$id;
            $rows[]=['id'=>$id,'title'=>wp_strip_all_tags(
                html_entity_decode(get_the_title($id),ENT_QUOTES,'UTF-8'))];
        }
        $style=WPMU_PLUGIN_DIR.'/assets/mad4b-package-supplier-admission-review-v1.css';
        if(is_readable($style))
            wp_enqueue_style('mad4b-package-supplier-admission-review-v1',
                content_url('mu-plugins/assets/mad4b-package-supplier-admission-review-v1.css'),
                [],(string)filemtime($style));
        // The Price Sources workspace and Tour Preview provide the ONE
        // selected Tour context; run after both registered UI modules.
        $deps=[];
        foreach(['mad4b-jetengine-functional-modes',
                 'mad4b-jetengine-published-tours'] as $handle)
            if(wp_script_is($handle,'registered')) $deps[]=$handle;
        wp_enqueue_script('mad4b-package-supplier-admission-review-v1',
            content_url('mu-plugins/assets/mad4b-package-supplier-admission-review-v1.js'),
            $deps,(string)filemtime($file),true);
        wp_add_inline_script('mad4b-package-supplier-admission-review-v1',
            'window.MAD4BPackageSupplierAdmissionReview='.wp_json_encode([
                'url'=>admin_url('admin-ajax.php'),'action'=>self::AJAX,
                'nonce'=>wp_create_nonce(self::AJAX),'tours'=>$rows,
                'today'=>gmdate('Y-m-d'),
            ],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';','before');
    }

    public static function inspect(int $tour,string $date,string $currency='EGP',
        int $adults=2,int $children=0,int $infants=0):array|WP_Error {
        if(!current_user_can('commerce_manage_pricing')||
            !current_user_can('manage_woocommerce'))
            return new WP_Error('package_admission_forbidden');
        if(get_post_type($tour)!=='tours-and-activities' ||
            get_post_status($tour)!=='publish')
            return new WP_Error('package_admission_tour_invalid');
        if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$date,$m) ||
            !checkdate((int)$m[2],(int)$m[3],(int)$m[1]))
            return new WP_Error('package_admission_date_invalid');
        if(!in_array($currency,['EGP','USD','EUR','GBP'],true) ||
            $adults<1||$adults>30||$children<0||$children>30||$infants<0||$infants>20)
            return new WP_Error('package_admission_dimensions_invalid');
        $blockers=[];$report=[];
        $add=static function(string $code)use(&$blockers):void{$blockers[$code]=$code;};

        $links=get_post_meta($tour,self::LINK_META,true);
        $validLink=is_array($links) &&
            (int)($links['tour_id']??0)===$tour &&
            (string)($links['schema']??'')!=='';
        $stages=$validLink && is_array($links['bindings']??null)
            ?$links['bindings']:[];
        $roomStages=[];
        if(!$stages)$add('stay_program_binding_missing');
        if($validLink && ($links['checkout_ready']??false)!==false)
            $add('stay_link_checkout_state_unexpected');
        foreach(array_slice($stages,0,40) as $stage) {
            if(!is_array($stage))continue;
            $kind=(string)($stage['lodging_kind']??'');
            $price=(string)($stage['price_status']??'');
            $bookable=(array)($stage['bookable_unit_ids']??[]);
            $hasSelection=(int)($stage['selected_property_id']??0)>0 &&
                count($bookable)>0;
            $approved=!empty($stage['supplier_cost_receipt']) &&
                is_array($stage['supplier_cost_receipt']) &&
                ($stage['supplier_cost_receipt']['status']??'')==='approved';
            // A bookable selection and a value are never supplier proof by
            // themselves. Every stage also needs a live cost provider check.
            if(!$hasSelection)$add('stay_unit_not_selected');
            if(!$approved)$add('stay_supplier_cost_receipt_missing');
            $roomStages[]=[
                'stage_key'=>sanitize_key((string)($stage['stage_key']??'')),
                'kind'=>sanitize_key($kind),
                'nights'=>max(0,(int)($stage['nights']??0)),
                'price_status'=>$price,
                'bookable_unit_selection_present'=>$hasSelection,
                'supplier_cost_receipt_present'=>$approved,
                'commercial_stage_approved'=>false,
            ];
        }
        $report['accommodation']=[
            'source'=>self::LINK_META,'binding_count'=>count($roomStages),
            'stages'=>$roomStages,'source_is_editorial'=>true,
            'cost_qualified'=>false,
        ];

        $transport=class_exists('MAD4B_Transport_Self_Pricing_Controls_V1',false)
            ?MAD4B_Transport_Self_Pricing_Controls_V1::current():[];
        $role=$transport['price_role']??'';
        $source=class_exists('MAD4B_Transport_Self_Pricing_Controls_V1',false)
            ?MAD4B_Transport_Self_Pricing_Controls_V1::source():[];
        $component=null;
        if($role!=='self_cost')$add('transport_supplier_cost_role_missing');
        if(!$source)$add('transport_original_source_missing');
        // Original PDF vehicle_rates_unverified values and transportations
        // start_price are NOT certified tour route supplier cost evidence.
        $add('transport_route_cost_unapproved');
        $report['transportation']=[
            'source'=>'mad4b_transport_self_pricing_controls_v1',
            'source_role'=>$role,
            'currency'=>$transport['currency']??'',
            'original_pdf_source_present'=>(bool)$source,
            'approved_route_component_id'=>$component,
            'cost_qualified'=>false,
        ];

        $guide=class_exists('MAD4B_Guide_Service_Composition_V1',false)
            ?MAD4B_Guide_Service_Composition_V1::guide($tour,'english')
            :new WP_Error('guide_component_runtime_missing');
        $guideReady=!is_wp_error($guide)
            && is_array($guide)
            && ($guide['ready']??false)===true;
        if(!$guideReady)$add(is_wp_error($guide)?
            'guide:'.$guide->get_error_code():'guide_cost_source_not_certified');
        $report['guide']=[
            'source'=>'mad4b-guide-service-composition-v1',
            'status'=>is_wp_error($guide)?$guide->get_error_code():
                ($guideReady?'internal_cost_verified':'source_not_ready'),
            'cost_qualified'=>$guideReady,
            'included_in_customer_price'=>false,
        ];

        $extra=class_exists('MAD4B_Extra_Readonly_Quote_Runtime_V1',false)
            ?MAD4B_Extra_Readonly_Quote_Runtime_V1::status():[];
        if(($extra['rate_eligibility_verified']??false)!==true)
            $add('extra_supplier_rate_not_certified');
        // Quote handlers are selling-price preview; not an extras COST proof.
        $report['extras']=[
            'source'=>'mad4b.extra-readonly-quote-runtime.v1',
            'runtime_status'=>$extra['status']??'not_loaded',
            'supplier_cost_authority'=>false,'cost_qualified'=>false,
        ];
        if($children>0||$infants>0)
            $add('child_infant_component_costs_not_certified');
        if($currency!=='EGP')$add('certified_fx_receipt_missing');
        $add('company_margin_tax_source_unapproved');
        return [
            'contract'=>self::CONTRACT,
            'tour_id'=>$tour,'date'=>$date,'currency'=>$currency,
            'occupancy'=>['adults'=>$adults,'children'=>$children,'infants'=>$infants],
            'components'=>$report,'blockers'=>array_values($blockers),
            'source_state'=>'native_sources_reviewed_not_cost_qualified',
            'total_cost_minor'=>null,
            'rate_generation_ready'=>false,'checkout_ready'=>false,
            'customer_price_changed'=>false,
            'source_receipt_needed'=>true,
            'supplier_cost_provider_reverification_required'=>true,
        ];
    }
}
add_action('wp_ajax_'.MAD4B_Package_Supplier_Admission_V1::AJAX,
    [MAD4B_Package_Supplier_Admission_V1::class,'ajax']);
add_action('admin_enqueue_scripts',
    [MAD4B_Package_Supplier_Admission_V1::class,'enqueue'],80);
