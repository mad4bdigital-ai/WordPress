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

    /**
     * Accept only the reviewed, fingerprinted native guide/day cost contract.
     * The native provider intentionally has no "ready" field: a successful
     * verified cost receipt is not permission to sell or charge the traveler.
     */
    public static function guideEvidenceReady($receipt):bool {
        if(!is_array($receipt) ||
           ($receipt['contract']??'')!=='mad4b.guide-cost-component.v1' ||
           ($receipt['supplier']??'')!=='self' ||
           ($receipt['currency']??'')!=='EGP' ||
           ($receipt['amount_type']??'')!=='internal_cost_not_sell' ||
           ($receipt['not_price_authority']??false)!==true ||
           ($receipt['apply_to_retail_total']??true)!==false ||
           ($receipt['checkout_ready']??true)!==false ||
           (int)($receipt['source_record_revision']??0)<1 ||
           !preg_match('/^[a-f0-9]{64}$/D',(string)($receipt['source_hash']??'')) ||
           !preg_match('/^[a-f0-9]{64}$/D',(string)($receipt['cost_fingerprint']??'')))
            return false;
        foreach(['unit_cost','effective_guide_days','cost'] as $key)
            if(!isset($receipt[$key])||!is_numeric($receipt[$key])||
               !is_finite((float)$receipt[$key])||(float)$receipt[$key]<0)
                return false;
        $count=$receipt['guide_count']??null;
        if(!is_int($count)||$count<0||$count>30)return false;
        $expected=round((float)$receipt['unit_cost']*
            (float)$receipt['effective_guide_days']*$count,2);
        if(abs($expected-(float)$receipt['cost'])>0.01)return false;
        $unsigned=$receipt;
        unset($unsigned['cost_fingerprint']);
        return hash_equals((string)$receipt['cost_fingerprint'],
            hash('sha256',wp_json_encode($unsigned)));
    }

    /**
     * Live native Tour Rate CPT is the sole transport-stage owner.
     * related_tour_id is already the authoritative scalar postmeta link.
     * The Tour's tour_queried_rates query field is inventory UI only; never
     * read or write it as a competing relation or a selected Rate list.
     */
    public static function linkedRateRoutes(int $tour):array {
        $ids=get_posts([
            'post_type'=>'tour-rates','post_status'=>['publish','draft','pending'],
            'posts_per_page'=>300,'fields'=>'ids','suppress_filters'=>true,
            'meta_key'=>'related_tour_id','meta_value'=>(string)$tour,
            'orderby'=>'ID','order'=>'ASC',
        ]);
        $rows=[];$total=0;
        foreach((array)$ids as $rawId){
            $rate=(int)$rawId;
            if($rate<=0||get_post_type($rate)!=='tour-rates'||
                (int)get_post_meta($rate,'related_tour_id',true)!==$tour)
                continue;
            $stored=get_post_meta($rate,'_mad4b_transport_stages_v1',true);
            // JetEngine saves the Repeater as an array of native stage rows,
            // not as a virtual "stages" wrapper. Accept an older wrapper only
            // for source-compatible previews; never normalize by writing meta.
            if(is_array($stored) && is_array($stored['stages']??null))
                $stored=$stored['stages'];
            $count=0;
            foreach(is_array($stored)?$stored:[] as $stage)
                if(is_array($stage) &&
                   trim((string)($stage['key']??''))!=='')$count++;
            $total+=$count;
            $rows[]=[
                'rate_id'=>$rate,'status'=>(string)get_post_status($rate),
                'native_route_stage_count'=>$count,
            ];
        }
        return [
            'linked_rate_count'=>count($rows),
            'native_route_stage_count'=>$total,
            'rate_stage_inventory'=>$rows,
            'source_relation'=>'related_tour_id',
            'tour_query_is_inventory_only'=>true,
        ];
    }

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
            // Native identities remain distinct for hotels, sailings and sleeper services.
            $hasSelection=match($kind) {
                'hotel','resort'=>(int)($stage['selected_property_id']??0)>0,
                'cruise'=>(int)($stage['selected_vessel_id']??0)>0 &&
                    !empty($stage['cruise_program']) && !empty($stage['sailing_date']),
                'sleeper_train'=>(int)($stage['selected_train_id']??0)>0,
                default=>false,
            };
            $startDay=(int)($stage['source_day_start']??0);
            $nights=(int)($stage['nights']??0);
            $dateObj=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
            $stageDate=$dateObj&&$startDay>0?$dateObj->modify('+'.($startDay-1).' days'):null;
            $lastDate=$stageDate&&$nights>0?$stageDate->modify('+'.($nights-1).' days'):null;
            $receipt=$stage['supplier_cost_receipt']??null;
            // Preserve the live per-company Self supplier fallback and exact receipt identity.
            $supplier=class_exists('MAD4B_Package_Company_Supplier_V1',false)
                ?MAD4B_Package_Company_Supplier_V1::resolve($tour,$stage,$stage)
                :['identity_resolved'=>false,'supplier_mode'=>'unresolved',
                   'identity_issue'=>'supplier_identity_provider_unavailable'];
            if(empty($supplier['identity_resolved']))$add('stage_supplier_identity_unresolved');
            $context=[
                'tour_id'=>$tour,'stage_key'=>(string)($stage['stage_key']??''),
                'kind'=>$kind,'currency'=>$currency,
                'service_date'=>$stageDate?$stageDate->format('Y-m-d'):'',
                'last_service_date'=>$lastDate?$lastDate->format('Y-m-d'):'',
                'adults'=>$adults,'children'=>$children,'infants'=>$infants,
                'property_id'=>(int)($stage['selected_property_id']??0),
                'unit_ids'=>$bookable,
                'vessel_id'=>(int)($stage['selected_vessel_id']??0),
                'cabin_unit_id'=>(int)($stage['selected_cabin_unit_id']??0),
                'cruise_program'=>$stage['cruise_program']??null,
                'train_id'=>(int)($stage['selected_train_id']??0),
                'train_number'=>(string)($stage['selected_train_number']??''),
            ];
            if($kind==='sleeper_train'){
                $segment=class_exists('MAD4B_Package_Stage_Sourcing_V1',false)
                    ?MAD4B_Package_Stage_Sourcing_V1::stageTrainSegment($tour,$stage)
                    :null;
                if(is_array($segment)){
                    $context['boarding_station_id']=(int)$segment['boarding_station_id'];
                    $context['arrival_station_id']=(int)$segment['arrival_station_id'];
                }
                $context['cabin_class']=(string)($stage['selected_train_cabin_class']??'')?:'Standard';
            }
            $verification=class_exists('MAD4B_Package_Cost_Receipt_Gate_V1',false)
                ?MAD4B_Package_Cost_Receipt_Gate_V1::validate($receipt,$context)
                :['qualified'=>false,'blockers'=>['cost_receipt_validator_missing']];
            $supplierMatches=class_exists('MAD4B_Package_Company_Supplier_V1',false)
                && MAD4B_Package_Company_Supplier_V1::receiptIdentityMatches($receipt,$supplier);
            $approved=$hasSelection && ($verification['qualified']??false)===true
                && $supplierMatches;
            if(is_array($receipt)&&!$supplierMatches)
                $add('stage_supplier_receipt_identity_mismatch');
            // A bookable selection and a value are never supplier proof by
            // themselves. Every stage also needs a live cost provider check.
            if(!$hasSelection)$add('stay_pricing_component_not_selected');
            if(!$approved)$add('stay_supplier_cost_receipt_missing');
            if(is_array($receipt))foreach((array)($verification['blockers']??[]) as $reason)
                $add('cost_receipt:'.$reason);
            $roomStages[]=[
                'stage_key'=>sanitize_key((string)($stage['stage_key']??'')),
                'kind'=>sanitize_key($kind),
                'nights'=>max(0,(int)($stage['nights']??0)),
                'price_status'=>$price,
                'bookable_unit_selection_present'=>count($bookable)>0 ||
                    (int)($stage['selected_cabin_unit_id']??0)>0,
                'pricing_component_identity_present'=>$hasSelection,
                'unit_selection_required'=>false,
                'supplier_cost_receipt_present'=>$approved,
                'supplier_cost_receipt_recorded'=>is_array($receipt),
                'supplier_resolution'=>$supplier,
                'supplier_receipt_identity_matches'=>$supplierMatches,
                'supplier_cost_receipt_qualified'=>$approved,
                'receipt_blockers'=>$verification['blockers']??['validator_missing'],
                'service_date'=>$context['service_date'],
                'last_service_date'=>$context['last_service_date'],
                'commercial_stage_approved'=>false,
            ];
        }
        $report['accommodation']=[
            'source'=>self::LINK_META,'binding_count'=>count($roomStages),
            'supplier_fallback_policy'=>'self_if_no_explicit_external_assignment',
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
        // The native Tour Rate routing editor owns stage identity. Resolve
        // its scalar related_tour_id postmeta; never invent a second Relation.
        $linked=self::linkedRateRoutes($tour);
        if(!$linked['linked_rate_count'])$add('transport_linked_tour_rates_missing');
        if(!$linked['native_route_stage_count'])
            $add('transport_route_stage_binding_missing');
        $model=class_exists('MAD4B_Transport_Distance_Time_V1',false)
            ?MAD4B_Transport_Distance_Time_V1::current():[];
        $profiles=is_array($model['profiles']??null)?$model['profiles']:[];
        $proposals=[];$unconfigured=[];
        foreach($profiles as $vehicle=>$profile){
            if(!is_array($profile))continue;
            $known=($profile['source']??'')==='pdf_proposal' &&
                ((float)($profile['per_km']??0)>0 ||
                 (float)($profile['per_drive_min']??0)>0);
            if($known)$proposals[]=(string)$vehicle;
            else $unconfigured[]=(string)$vehicle;
        }
        // The PDF rates and distance/time model are useful input, but are
        // expressly draft estimates. Neither a matching city nor a vehicle
        // proposal is a signed, dated, tour-specific supplier COST receipt.
        $add('transport_route_cost_unapproved');
        $report['transportation']=[
            'source'=>'mad4b_transport_self_pricing_controls_v1',
            'source_role'=>$role,
            'currency'=>$transport['currency']??'',
            'original_pdf_source_present'=>(bool)$source,
            'original_source_row_count'=>count($source['rows']??[]),
            'native_distance_time_policy_present'=>(bool)$model,
            'native_distance_time_revision'=>(int)($model['revision']??0),
            'vehicle_profile_owner'=>(string)($model['vehicle_profile_owner']??''),
            'model_candidate_vehicle_keys'=>$proposals,
            'vehicle_profiles_needing_review'=>$unconfigured,
            'explicit_route_stage_count'=>$linked['native_route_stage_count'],
            'linked_tour_rate_count'=>$linked['linked_rate_count'],
            'linked_rate_stage_inventory'=>$linked['rate_stage_inventory'],
            'stage_meta_owner'=>'tour-rates',
            'relation_source'=>'related_tour_id',
            'tour_queried_rates_inventory_only'=>true,
            'route_component_status'=>$linked['native_route_stage_count']
                ?'rate_stage_selected_cost_and_provider_review_required'
                :'route_stages_not_assigned_in_tour_rates',
            'approved_route_component_id'=>$component,
            'source_cost_estimate_egp'=>null,
            'cost_qualified'=>false,
        ];

        $guideRuntime=class_exists('MAD4B_Guide_Service_Composition_V1',false);
        $guideSource=$guideRuntime
            ?MAD4B_Guide_Service_Composition_V1::source($tour)
            :new WP_Error('guide_component_runtime_missing');
        $guideReview=is_array($guideSource)
            ?MAD4B_Guide_Service_Composition_V1::saved($guideSource)
            :$guideSource;
        // English is a read-only default preview, not a traveler or product
        // language assignment. Native provider enforces the offered taxonomy.
        $guide=$guideRuntime
            ?MAD4B_Guide_Service_Composition_V1::guide($tour,'english')
            :new WP_Error('guide_component_runtime_missing');
        $guideReady=self::guideEvidenceReady($guide);
        if(!$guideReady)$add(is_wp_error($guide)?
            'guide:'.$guide->get_error_code():'guide_cost_source_not_certified');
        $base=class_exists('MAD4B_Guide_Daily_Base_Cost_V1',false)
            ?MAD4B_Guide_Daily_Base_Cost_V1::status():[];
        $reviewCounts=is_array($guideReview) &&
            is_array($guideReview['statuses']??null)
            ?array_count_values($guideReview['statuses']):[];
        $report['guide']=[
            'source'=>'mad4b-guide-service-composition-v1',
            'status'=>is_wp_error($guide)?$guide->get_error_code():
                ($guideReady?'internal_cost_verified':'source_not_ready'),
            'company_self_cost_configured'=>($base['configured']??false)===true,
            'source_day_count'=>is_array($guideSource)
                ?count($guideSource['days']??[]):0,
            'coverage_review_status'=>is_wp_error($guideReview)
                ?$guideReview->get_error_code():'reviewed',
            'coverage_counts'=>$reviewCounts,
            'language_preview'=>'english',
            'source_cost_egp'=>$guideReady?(float)$guide['cost']:null,
            'source_cost_fingerprint'=>$guideReady?$guide['cost_fingerprint']:null,
            'cost_qualified'=>$guideReady,
            // Existing Tour Rate may already include the guide; unknown is
            // NOT equivalent to excluded. Never charge it again by default.
            'included_in_customer_price'=>null,
            'rate_inclusion_status'=>'unverified_tour_rate_evidence',
            'not_added_to_retail_price'=>true,
            'not_price_authority'=>true,
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
