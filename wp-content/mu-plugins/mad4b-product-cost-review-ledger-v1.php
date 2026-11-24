<?php
/**
 * Plugin Name: MAD4B Unified Product Cost Review Ledger
 * Version: 1.0.0
 * Description: Cost/receipt/source review and explicit reviewer history for native products. Read-only business state; never a signing, pricing or publication authority.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Product_Cost_Review_Ledger_V1',false)) {
final class MAD4B_Product_Cost_Review_Ledger_V1 {
    public const PAGE='mad4b-product-cost-review';
    public const HISTORY='_mad4b_cost_review_history_v1';
    public const CONTRACT='mad4b.product-cost-review-ledger.v1';
    public const TYPES=[
        'tours-and-activities'=>'Tour',
        'tour-rates'=>'Tour Rate',
        'properties'=>'Property',
        'units'=>'Unit',
        'property-rates'=>'Property Rate',
        'transportations'=>'Transportation',
        'transport-rates'=>'Transport Rate',
        'operational-services'=>'Operational Service',
        'transport-com'=>'Transport Component',
        'product'=>'WooCommerce Product',
        'attractions'=>'Attraction',
    ];
    public static function types():array {
        return class_exists('MAD4B_Cost_Source_Registry_V1',false)
            ?MAD4B_Cost_Source_Registry_V1::types(self::TYPES):self::TYPES;
    }
    public static function supported($type):bool {
        return is_string($type)&&isset(self::types()[$type]);
    }
    public static function allowed(int $id=0):bool {
        if(!current_user_can('commerce_manage_pricing'))return false;
        if($id<1)return true;
        return self::supported(get_post_type($id))&&current_user_can('edit_post',$id);
    }
    public static function link(int $id):string {
        return admin_url('admin.php?page='.self::PAGE.'&product_id='.$id);
    }
    private static function ref(int $id):?array {
        if($id<1||!self::supported(get_post_type($id))||!current_user_can('edit_post',$id))return null;
        $url=get_edit_post_link($id,'raw');
        return ['id'=>$id,'title'=>wp_strip_all_tags((string)get_the_title($id)),
            'type'=>get_post_type($id),'edit_url'=>$url?:null,
            'review_url'=>self::link($id)];
    }
    private static function sourceHash($value):string {
        return hash('sha256',wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    /** A recorded receipt is NOT an approved receipt; only its native provider can decide. */
    public static function receiptSummary($receipt,bool $signedProviderAvailable=false):array {
        $present=is_array($receipt)&&$receipt!==[];
        $status=$present?(string)($receipt['status']??'unverified'):'missing';
        return ['recorded'=>$present,'declared_status'=>$status,
            'native_signed_verification_available'=>$signedProviderAvailable,
            'qualified'=>false,
            'commercially_authorized'=>false,
            'approval_ref'=>$present&&is_scalar($receipt['approval_ref']??null)
                ?(string)$receipt['approval_ref']:null,
            'source_ref'=>$present&&is_scalar($receipt['source_document_ref']??null)
                ?(string)$receipt['source_document_ref']:null,
            'evidence_hash'=>$present?self::sourceHash($receipt):null,
            'blocker'=>$present?'native_receipt_reverification_required':'supplier_cost_receipt_missing'];
    }
    private static function sourceRows(int $id,string $type):array {
        $rows=[];
        $add=static function(string $label,$value,string $source,string $status='recorded')use(&$rows):void{
            $rows[]=['component'=>$label,'value'=>$value,'source'=>$source,'status'=>$status];
        };
        if($type==='tours-and-activities'){
            $plan=get_post_meta($id,'_mad4b_package_accommodation_source_plan_v1',true);
            $binding=get_post_meta($id,'_mad4b_package_stay_verified_room_program_links_v1',true);
            $byKey=[];
            foreach((array)($binding['bindings']??[]) as $b)
                if(is_array($b)&&isset($b['stage_key']))$byKey[(string)$b['stage_key']]=$b;
            if(is_array($plan)&&is_array($plan['stages']??null)){
                foreach(array_slice($plan['stages'],0,40) as $stage){
                    if(!is_array($stage))continue;
                    $key=(string)($stage['stage_key']??'');$b=$byKey[$key]??[];
                    $receipt=self::receiptSummary($b['supplier_cost_receipt']??null,
                        has_filter('mad4b/package-cost-receipt/verify-authority')!==false);
                    $selected=(int)($b['selected_property_id']??0);
                    $add($key,['kind'=>$stage['lodging_kind']??'unknown',
                        'city'=>$stage['destination_key']??null,
                        'nights'=>(int)($stage['nights']??0),
                        'board'=>$stage['board']??null,
                        'selected_operational_property_id'=>$selected?:null,
                        'unit_optional'=>true,
                        'source_rate_id'=>(int)($b['supplier_cost_receipt']['rate_id']??0)?:null,
                        'receipt'=>$receipt],
                        '_mad4b_package_stay_verified_room_program_links_v1',
                        $receipt['qualified']?'qualified':($receipt['recorded']?'reverify':'missing_cost_receipt'));
                }
            }elseif(class_exists('MAD4B_Package_Components_Editor_Context_V1',false)&&
                MAD4B_Package_Components_Editor_Context_V1::isPackage($id)){
                $add('Package overnight stages','No original source plan',
                    '_mad4b_package_accommodation_source_plan_v1','missing');
            }
            $add('Guide cost source',class_exists('MAD4B_Guide_Service_Composition_V1',false)
                ?'Native Guide Service Composition available':'Provider not found',
                'mad4b-guide-service-composition-v1','source_only');
            $add('Transport cost source',class_exists('MAD4B_Package_Transport_Needs_V2',false)
                ?'Native Transport Needs available':'Provider not found',
                'mad4b-package-transport-needs-v2','source_only');
        }elseif($type==='property-rates'){
            $meta=['prop_related_property_id','prop_related_unit_id','mad4b_rate_scope_v1',
                'mad4b_rate_price_kind_v1','base_amount','currency','prop_price_unit',
                'valid_from','valid_to','prop_board_type','stop_sale','avilabile_status'];
            foreach($meta as $key)$add($key,get_post_meta($id,$key,true),$key);
            $self=get_post_meta($id,'_mad4b_self_provider_owner_cost_approval_v1',true);
            $source=get_post_meta($id,'_mad4b_supplier_sheet_proposal_v1',true);
            $normalized=class_exists('MAD4B_Hotel_Nightly_Cost_Basis_V1',false)
                ?MAD4B_Hotel_Nightly_Cost_Basis_V1::effective($id):false;
            $add('Original supplier sheet',is_array($source)?[
                'source_cell'=>$source['source_cell']??null,'meal_plan'=>$source['meal_plan']??null,
                'occupancy'=>$source['occupancy']??null,'source_basis'=>$source['price_unit']??null,
                'source_hash'=>self::sourceHash($source)]:null,
                '_mad4b_supplier_sheet_proposal_v1',is_array($source)?'reference':'missing');
            $add('Owner source cost approval',is_array($self)?[
                'scope'=>$self['approval_scope']??null,
                'source_value_approved'=>$self['cost_value_source_approved']??false,
                'signed_cost_receipt'=>$self['native_cost_receipt_signed']??false]:null,
                '_mad4b_self_provider_owner_cost_approval_v1',
                is_array($self)?'source_reference_only':'missing');
            $companyReceipt=get_post_meta($id,'_mad4b_company_cost_value_receipt_v1',true);
            $add('Company sheet cost-value receipt',is_array($companyReceipt)?[
                'status'=>$companyReceipt['status']??null,
                'source_price_confirmed'=>$companyReceipt['source_price_confirmed']??false,
                'native_package_cost_receipt_qualified'=>$companyReceipt['native_package_cost_receipt_qualified']??false,
                'inventory_confirmed'=>$companyReceipt['inventory_confirmed']??false,
                'source_fingerprint'=>$companyReceipt['receipt_fingerprint_sha256']??null,
                'evidence_hash'=>self::sourceHash($companyReceipt)]:null,
                '_mad4b_company_cost_value_receipt_v1',
                is_array($companyReceipt)?'reference_only_not_signed_stage_receipt':'missing');
            $add('Nightly normalized basis',$normalized,
                '_mad4b_hotel_cost_basis_normalized_v1',$normalized?'source_normalized':'not_normalized');
        }elseif($type==='tour-rates'){
            foreach(['related_tour_id','related_package_term_id','tour_rate_start_date',
                'tour_rate_end_date','single_price','double_price','triple_price','base_currency',
                '_mad4b_rate_plan_price_state','_mad4b_rate_plan_key',
                'stop_sale','avilabile_status'] as $key)
                $add($key,get_post_meta($id,$key,true),$key);
            if(class_exists('Shared_Product_Rate_Generation',false)){
                $ready=Shared_Product_Rate_Generation::readiness($id);
                $add('Original generator readiness',is_wp_error($ready)?
                    ['error'=>$ready->get_error_code()]:[
                    'commercial_ready'=>$ready['commercial_ready']??false,
                    'checkout_ready'=>$ready['checkout_ready']??false,
                    'blocking_conditions'=>$ready['blocking_conditions']??[]],
                    'shared-product-core/pricing/rate-generation.php','native_gate');
            }
        }elseif($type==='properties'||$type==='units'){
            $cost=get_post_meta($id,'_mad4b_direct_cost_v1',true);
            $add('Direct acquisition cost',is_array($cost)?[
                'amount'=>$cost['amount']??null,'currency'=>$cost['currency']??null,
                'basis'=>$cost['price_unit']??null,
                'source_reference'=>$cost['source_reference']??null]:null,
                '_mad4b_direct_cost_v1',is_array($cost)?'reference_only':'missing');
            if($type==='units'){
                foreach(['unit_max_occupancy','unit_available','unit_inventory_quantity'] as $key)
                    $add($key,get_post_meta($id,$key,true),$key);
                $proof=get_post_meta($id,'_mad4b_room_source_receipt_v1',true);
                $add('Unit/property source relation',is_array($proof)?[
                    'property_id'=>$proof['property_id']??null,
                    'evidence_hash'=>self::sourceHash($proof)]:null,
                    '_mad4b_room_source_receipt_v1',is_array($proof)?'source_reference_only':'missing');
            }else{
                $rates=get_posts(['post_type'=>'property-rates','post_status'=>['publish','draft','pending'],
                    'fields'=>'ids','posts_per_page'=>80,'suppress_filters'=>true,
                    'meta_key'=>'prop_related_property_id','meta_value'=>(string)$id]);
                foreach($rates as $rid){
                    $costRole=(string)get_post_meta((int)$rid,'mad4b_rate_price_kind_v1',true)==='cost';
                    $add('Linked Property Rate #'.(int)$rid,[
                        'rate_id'=>(int)$rid,'cost_reference'=>$costRole,
                        'status'=>get_post_status((int)$rid),
                        'stop_sale'=>get_post_meta((int)$rid,'stop_sale',true)],
                        'prop_related_property_id','linked_rate');
                }
            }
        }elseif($type==='transportations'||$type==='transport-com'||$type==='transport-rates'){
            $keys=$type==='transport-rates'?
                ['source_post_id','base_amount','currency','valid_from','valid_to','stop_sale']:
                ['trans_available_status','trans_use_in_tour_rate_generation'];
            foreach($keys as $key)$add($key,get_post_meta($id,$key,true),$key);
            if($type==='transport-rates'){
                $verifiable=function_exists('BookingExperience\\transport_rate_approval_verified')
                    &&BookingExperience\transport_rate_approval_verified($id);
                $add('Native transport Rate approval',$verifiable,
                    'BookingExperience\\transport_rate_approval_verified',
                    $verifiable?'native_verified':'not_verified');
            }else{
                $rates=get_posts(['post_type'=>'transport-rates','post_status'=>['publish','draft'],
                    'fields'=>'ids','posts_per_page'=>50,'meta_key'=>'source_post_id',
                    'meta_value'=>(string)$id,'suppress_filters'=>true]);
                foreach($rates as $rid)$add('Transport Rate #'.(int)$rid,[
                    'rate_id'=>(int)$rid,'status'=>get_post_status((int)$rid)],
                    'source_post_id','linked_rate');
            }
        }elseif($type==='operational-services'){
            $add('Operational Service Cost State',get_post_meta($id,'_mad4b_os_cost_state',true),
                '_mad4b_os_cost_state','native_service_state');
        }elseif($type==='product'){
            if(function_exists('wc_get_product')){
                $product=wc_get_product($id);
                $add('WooCommerce retail price', $product?[
                    'price'=>$product->get_price('edit'),
                    'currency'=>get_woocommerce_currency(),
                    'tax_status'=>$product->get_tax_status()]:null,
                    'WooCommerce Product','retail_not_supplier_cost');
            }
        }
        $native=array_slice($rows,0,100);
        return class_exists('MAD4B_Cost_Source_Registry_V1',false)
            ?array_merge($native,MAD4B_Cost_Source_Registry_V1::rows($id,$type)):$native;
    }
    public static function inspect(int $id,array $options=[]):array|WP_Error {
        if(!self::allowed($id))return new WP_Error('cost_review_permission_denied');
        $post=get_post($id);
        if(!$post||!self::supported($post->post_type))return new WP_Error('cost_review_product_not_supported');
        $rows=self::sourceRows($id,$post->post_type);
        $summary=['component_records'=>count($rows),'recorded_supplier_receipts'=>0,
            'native_signed_receipts_qualified'=>0,'requires_review'=>0,
            'commercial_price_approved'=>false,'checkout_ready'=>false,
            'commercial_review_separate'=>true];
        foreach($rows as $row){
            $receipt=$row['value']['receipt']??null;
            if(is_array($receipt)&&!empty($receipt['recorded']))$summary['recorded_supplier_receipts']++;
            if(in_array($row['status'],['missing','missing_cost_receipt','not_verified','reverify'],true))
                $summary['requires_review']++;
        }
        $details=['native_pricing_workbench'=>null,'native_cost_admission'=>null,
            'dated_indicative_stage_composition'=>null];
        if(class_exists('MAD4B_Pricing_Workbench',false)&&
           in_array($post->post_type,['tours-and-activities','properties','transportations'],true)){
            $work=MAD4B_Pricing_Workbench::inspect($id);
            if(!is_wp_error($work))$details['native_pricing_workbench']=[
                'domain'=>$work['domain']??null,
                'source'=>$work['source']??[],
                'alerts'=>$work['alerts']??[],
                'commercially_verified'=>$work['commercially_verified']??false];
        }
        if(class_exists('MAD4B_Cost_Component_Rules_V1',false))
            $details['dynamic_component_policy_preview']=MAD4B_Cost_Component_Rules_V1::preview($rows);
        $date=(string)($options['date']??'');
        $tier=(int)($options['tier_id']??0);
        $currency=(string)($options['currency']??'USD');
        $adults=(int)($options['adults']??2);
        $children=(int)($options['children']??0);
        $infants=(int)($options['infants']??0);
        $validDate=preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)&&
            ($d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC')))
            &&$d->format('Y-m-d')===$date;
        if($post->post_type==='tours-and-activities'&&$validDate&&
           class_exists('MAD4B_Package_Components_Editor_Context_V1',false)&&
           MAD4B_Package_Components_Editor_Context_V1::isPackage($id)&&
           in_array($currency,['USD','EGP','EUR','GBP'],true)&&
           $adults>=1&&$adults<=30&&$children>=0&&$children<=30&&
           $infants>=0&&$infants<=20){
            if(class_exists('MAD4B_Package_Supplier_Admission_V1',false)&&
               current_user_can('manage_woocommerce')){
                $review=MAD4B_Package_Supplier_Admission_V1::inspect(
                    $id,$date,$currency,$adults,$children,$infants);
                if(!is_wp_error($review)){
                    $nativeRows=[];
                    foreach((array)($review['accommodation']['stages']??[]) as $r){
                        $nativeRows[]=['stage_key'=>$r['stage_key']??null,
                            'supplier_cost_receipt_qualified'=>$r['supplier_cost_receipt_qualified']??false,
                            'receipt_blockers'=>array_slice((array)($r['receipt_blockers']??[]),0,12),
                            'service_date'=>$r['service_date']??null,
                            'last_service_date'=>$r['last_service_date']??null];
                        if(($r['supplier_cost_receipt_qualified']??false)===true)
                            $summary['native_signed_receipts_qualified']++;
                    }
                    $details['native_cost_admission']=[
                        'status'=>$review['status']??null,'checkout_ready'=>false,
                        'blocking_conditions'=>array_slice((array)($review['blockers']??[]),0,30),
                        'stages'=>$nativeRows];
                }else $details['native_cost_admission']=['error'=>$review->get_error_code()];
            }
            if($tier>0&&class_exists('MAD4B_Package_Pricing_Lab_Readiness_V1',false)){
                $before=$_POST;
                try {
                    $_POST=array_replace($_POST,[
                        'travel_date'=>$date,'rooms'=>(string)max(1,(int)($options['rooms']??1)),
                        'adults'=>(string)$adults,'children'=>(string)$children,
                        'infants'=>(string)$infants,'currency'=>$currency]);
                    $lab=MAD4B_Package_Pricing_Lab_Readiness_V1::inspect($id,$tier);
                } finally {$_POST=$before;}
                if(!is_wp_error($lab)){
                    $cost=$lab['commercial_cost_preflight']??[];
                    $details['dated_indicative_stage_composition']=[
                        'contract'=>$cost['contract']??null,
                        'date'=>$date,'tier_id'=>$tier,
                        'stage_count'=>$cost['stage_count']??null,
                        'hotel_costed_stages'=>$cost['hotel_costed_stage_count']??null,
                        'preliminary_hotel_reference_subtotal'=>$cost['preliminary_hotel_reference_subtotal']??null,
                        'currency'=>$cost['currency']??null,
                        'missing_cost_groups'=>$cost['missing_groups']??[],
                        'missing_source_errors'=>$cost['source_errors']??[],
                        'native_guide_cost_references'=>$cost['native_guide_cost_references']??null,
                        'native_transport_cost_readiness'=>$cost['native_transport_cost_readiness']??null,
                        'stages'=>array_map(static fn($r)=>[
                            'key'=>$r['stage_key']??null,'kind'=>$r['kind']??null,
                            'property_id'=>$r['pricing_property_id']??null,
                            'cost_rate_id'=>$r['cost_rate_id']??null,
                            'reference_cost'=>$r['reference_cost']??null,
                            'missing'=>$r['missing']??[]],
                            array_slice((array)($cost['stages']??[]),0,40)),
                        'approved_price'=>null,'checkout_ready'=>false];
                }else $details['dated_indicative_stage_composition']=[
                    'error'=>$lab->get_error_code()];
            }
        }
        if(class_exists('MAD4B_Generic_Cost_Composition_V1',false))
            $details['generic_cost_composition']=MAD4B_Generic_Cost_Composition_V1::inspect(
                $rows,$options,(array)($details['dated_indicative_stage_composition']??[]));
        if($post->post_type==='tours-and-activities'&&class_exists('MAD4B_Daily_Cost_Components_V1',false)){
            $daily=MAD4B_Daily_Cost_Components_V1::inspect($id,$options);
            $details['daily_cost_components']=is_wp_error($daily)?['error'=>$daily->get_error_code()]:$daily;
        }
        if(class_exists('MAD4B_Entrance_Source_Rates_V1',false)&&MAD4B_Entrance_Source_Rates_V1::allowed($id))
            $details['entrance_or_activity_internal_rates']=MAD4B_Entrance_Source_Rates_V1::current($id);
        $profit=class_exists('MAD4B_Company_Global_Profit_V1',false)
            ?MAD4B_Company_Global_Profit_V1::current():null;
        $policy=is_wp_error($profit)?['error'=>$profit->get_error_code()]:[
            'kind'=>$profit['kind']??null,'percent'=>$profit['percent']??null,
            'policy_revision'=>$profit['policy_revision']??null];
        $authorities=[
            'cost_receipt_validator_loaded'=>class_exists('MAD4B_Package_Cost_Receipt_Gate_V1',false),
            'signed_cost_receipt_authority_registered'=>
                has_filter('mad4b/package-cost-receipt/verify-authority')!==false,
            'commercial_package_price_provider_registered'=>
                has_filter('mad4b/package_rate_generator/approved_price')!==false,
            'company_profit_policy'=>$policy,
            'snapshot_is_not_supplier_authorization'=>true,
            'no_new_rate_or_order_writes'=>true,
        ];
        $state=$summary['requires_review']>0?'review_required':'no_explicit_source_blocker_found';
        $fingerprint=self::sourceHash([
            'product_id'=>$id,'type'=>$post->post_type,
            'post_status'=>$post->post_status,'source_rows'=>$rows,
            'summary'=>$summary,'details'=>$details,'authorities'=>$authorities]);
        return ['contract'=>self::CONTRACT,'product_id'=>$id,
            'title'=>wp_strip_all_tags((string)get_the_title($id)),
            'type'=>$post->post_type,'post_status'=>$post->post_status,
            'source_fingerprint'=>$fingerprint,'review_state'=>$state,
            'summary'=>$summary,'cost_sources'=>$rows,'original_readbacks'=>$details,
            'authorities'=>$authorities,
            'links'=>['original_editor'=>get_edit_post_link($id,'raw')?:null,
                'pricing_workbench'=>admin_url('admin.php?page=mad4b-pricing-workbench&product_id='.$id),
                'pricing_lab'=>admin_url('admin.php?page=mad4b-pricing-lab&mad4b_package_tour='.$id),
                'accommodation_pricing'=>admin_url('admin.php?page=mad4b-stay-price-layers')],
            'commercially_approved'=>false,'review_record_is_approval'=>false,
            'recorded_at_utc'=>null];
    }
    public static function history(int $id,int $page=1):array {
        if(!self::allowed($id))return [];
        global $wpdb;
        $offset=(max(1,min(100000,$page))-1)*25;
        $raw=$wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id DESC LIMIT 25 OFFSET %d",$id,self::HISTORY,$offset));
        return array_reverse(array_values(array_filter(array_map('maybe_unserialize',(array)$raw),'is_array')));
    }
    public static function record(int $id,array $payload):array|WP_Error {
        if(!self::allowed($id))return new WP_Error('cost_review_write_permission_denied');
        $note=sanitize_textarea_field((string)($payload['note']??''));
        if(mb_strlen($note)>500)return new WP_Error('cost_review_note_too_long');
        $options=(array)($payload['options']??[]);
        $current=self::inspect($id,$options);
        if(is_wp_error($current))return $current;
        $expected=(string)($payload['expected_fingerprint']??'');
        if($expected!==''&&(!preg_match('/^[a-f0-9]{64}$/D',$expected)||
           !hash_equals($expected,$current['source_fingerprint'])))
            return new WP_Error('cost_review_source_changed_recheck');
        global $wpdb;
        $lock='mad4b_cost_review_'.get_current_blog_id().'_'.$id;
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)
            return new WP_Error('cost_review_conflicting_writer');
        try {
            // Refresh after native lock so a stale browser digest cannot be signed as reviewed.
            $latest=self::inspect($id,$options);
            if(is_wp_error($latest))return $latest;
            if(!hash_equals($current['source_fingerprint'],$latest['source_fingerprint']))
                return new WP_Error('cost_review_source_changed_recheck');
            $old=self::history($id);
            $entry=[
                'contract'=>'mad4b.product-cost-review-snapshot.v1',
                'product_id'=>$id,
                'revision'=>$old?(int)(end($old)['revision']??0)+1:1,
                'reviewer_id'=>get_current_user_id(),
                'reviewed_at_utc'=>gmdate('c'),
                'source_fingerprint'=>$latest['source_fingerprint'],
                'review_state'=>$latest['review_state'],
                'summary'=>$latest['summary'],
                'note'=>$note,
                'review_is_supplier_approval'=>false,
                'review_is_rate_publication'=>false,
                'checkout_authorized'=>false,
            ];
            $entry['review_context']=$options;
            // Append one independent record; older revisions are never truncated.
            if(!add_post_meta($id,self::HISTORY,$entry,false))
                return new WP_Error('cost_review_history_save_denied');
            $readback=self::history($id);
            $last=end($readback);
            if(!is_array($last)||($last['source_fingerprint']??'')!==$entry['source_fingerprint']||
                ($last['reviewer_id']??0)!==$entry['reviewer_id'])
                return new WP_Error('cost_review_history_readback_mismatch');
            return $entry;
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    private static function renderValue($value):string {
        if(is_array($value)||is_object($value)){
            $text=wp_json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
            return '<pre style="white-space:pre-wrap;overflow:auto;max-width:850px;max-height:340px">'.
                esc_html((string)$text).'</pre>';
        }
        if(is_bool($value))return $value?'Yes':'No';
        if($value===null||$value==='')return '<em>Not supplied</em>';
        return esc_html((string)$value);
    }
    private static function options():array {
        $date=sanitize_text_field((string)wp_unslash($_GET['date']??''));
        $tier=absint($_GET['tier_id']??0);
        $currency=strtoupper(sanitize_text_field((string)wp_unslash($_GET['currency']??'USD')));
        if(!in_array($currency,['USD','EGP','EUR','GBP'],true))$currency='USD';
        return [
            'date'=>$date,'tier_id'=>$tier,'currency'=>$currency,
            'rooms'=>max(1,min(30,absint($_GET['rooms']??1))),
            'adults'=>max(1,min(30,absint($_GET['adults']??2))),
            'children'=>min(30,absint($_GET['children']??0)),
            'infants'=>min(20,absint($_GET['infants']??0)),
            'nights'=>min(365,absint($_GET['nights']??0)),
            'days'=>min(365,absint($_GET['days']??0)),
            'vehicles'=>min(60,absint($_GET['vehicles']??0)),
            'guides'=>min(30,absint($_GET['guides']??0)),
            'cabins'=>min(60,absint($_GET['cabins']??0)),
            'guide_language_id'=>absint($_GET['guide_language_id']??0),
            'nationality'=>is_string($_GET['nationality']??null)?strtoupper(sanitize_text_field(wp_unslash($_GET['nationality']))):'',
            'entrance_cohorts'=>is_string($_GET['entrance_cohorts']??null)?sanitize_textarea_field(wp_unslash($_GET['entrance_cohorts'])):'',
        ];
    }
    private static function dateForm(int $id,array $options):void {
        $tour=get_post_type($id)==='tours-and-activities';
        echo '<h2>Cost Composition Context</h2>';
        echo '<p>Set the calculation context. Nights/days/vehicles/cabins/guides must be explicitly entered for the applicable mapped billing basis. Native package stages use their original quantities. This does not make a Rate sellable.</p>';
        echo '<form method="get"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'">';
        echo '<input type="hidden" name="product_id" value="'.esc_attr((string)$id).'">';
        echo '<label>Travel date <input type="date" name="date" value="'.esc_attr($options['date']).'"></label> ';
        echo '<label>Passport nationality (same for party) <input name="nationality" maxlength="2" pattern="[A-Za-z]{2}" placeholder="US" value="'.esc_attr($options['nationality']??'').'"></label> ';
        echo '<p><label>Optional entrance cohorts (one line each: country,category,age,quantity,student-ID checked yes/no)<br><textarea name="entrance_cohorts" rows="3" maxlength="3000" placeholder="EG,adult,35,1,no&#10;US,student,20,1,yes">'.esc_textarea($options['entrance_cohorts']??'').'</textarea></label></p>';
        echo '<label>Package Tier <select name="tier_id"><option value="0">Choose</option>';
        if(class_exists('MAD4B_Package_Pricing_Lab_Readiness_V1',false)){
            foreach(MAD4B_Package_Pricing_Lab_Readiness_V1::tierIds() as $tier){
                $tid=(int)$tier['id'];
                echo '<option value="'.esc_attr((string)$tid).'"'.
                    selected($tid,$options['tier_id'],false).'>' .
                    esc_html((string)$tier['label']).'</option>';
            }
        }
        echo '</select></label> ';
        echo '<label>Currency <select name="currency">';
        foreach(['USD','EUR','EGP','GBP'] as $cur)
            echo '<option value="'.esc_attr($cur).'"'.selected($cur,$options['currency'],false).'>'.esc_html($cur).'</option>';
        echo '</select></label> ';
        foreach(['adults'=>'Adults','children'=>'Children','infants'=>'Infants','rooms'=>'Rooms'] as $key=>$name){
            echo '<label>'.esc_html($name).' <input type="number" min="'.
                ($key==='rooms'||$key==='adults'?'1':'0').'" max="30" name="'.esc_attr($key).
                '" style="width:65px" value="'.esc_attr((string)$options[$key]).'"></label> ';
        }
        echo '<p>';
        foreach(['nights'=>'Nights','days'=>'Service days','vehicles'=>'Vehicles','guides'=>'Guides','cabins'=>'Cabins'] as $key=>$name)
            echo '<label>'.esc_html($name).' <input type="number" min="0" max="'.(in_array($key,['nights','days'],true)?365:60).'" name="'.esc_attr($key).'" style="width:65px" value="'.esc_attr((string)($options[$key]?:'')).'"></label> ';
        echo '</p>';
        if($tour){
            $langs=wp_get_object_terms($id,'guide-languages');
            echo '<p><label>Guide language <select name="guide_language_id"><option value="0">Choose explicitly</option>';
            foreach(is_wp_error($langs)?[]:(array)$langs as $lang)
                if(current_user_can('edit_term',(int)$lang->term_id))
                    echo '<option value="'.esc_attr((string)$lang->term_id).'"'.selected((int)$lang->term_id,(int)$options['guide_language_id'],false).'>'.esc_html($lang->name).'</option>';
            echo '</select></label></p>';
        }
        echo '<button class="button button-secondary">Calculate cost references</button></form>';
    }
    private static function renderIndex():void {
        $type=sanitize_key((string)($_GET['product_type']??''));
        if($type!==''&&!self::supported($type))$type='';
        $search=sanitize_text_field((string)wp_unslash($_GET['s']??''));
        $args=['post_type'=>$type?:array_keys(self::types()),
            'post_status'=>['publish','draft','pending','private'],
            'posts_per_page'=>50,'orderby'=>'modified','order'=>'DESC',
            'suppress_filters'=>true,'fields'=>'ids'];
        if($search!=='')$args['s']=$search;
        $list=get_posts($args);
        echo '<form method="get"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'">';
        echo '<label>Product type <select name="product_type"><option value="">All supported</option>';
        foreach(self::types() as $slug=>$label)
            echo '<option value="'.esc_attr($slug).'"'.selected($slug,$type,false).'>'.esc_html($label).'</option>';
        echo '</select></label> <label>Search name <input type="search" name="s" value="'.esc_attr($search).'"></label> ';
        echo '<button class="button">Filter</button></form>';
        echo '<p>Showing up to 50 recently modified matching products. Open a Product review directly from any original editor, or use its ID:</p>';
        echo '<form method="get"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'">';
        echo '<label>Product ID <input type="number" min="1" name="product_id"></label> ';
        echo '<button class="button button-primary">Open review</button></form>';
        echo '<table class="widefat striped"><thead><tr><th>Product</th><th>Type</th><th>Post status</th><th>Last recorded review</th><th>Open</th></tr></thead><tbody>';
        $displayed=0;
        foreach($list as $raw){
            $id=(int)$raw;
            if(!self::allowed($id))continue;
            $history=self::history($id);$latest=$history?end($history):[];
            echo '<tr><td><strong>'.esc_html(get_the_title($id)).'</strong> #'.esc_html((string)$id).'</td>';
            echo '<td>'.esc_html(self::types()[get_post_type($id)]??get_post_type($id)).'</td>';
            echo '<td>'.esc_html((string)get_post_status($id)).'</td>';
            echo '<td>'.esc_html((string)($latest['reviewed_at_utc']??'Not yet reviewed')).'</td>';
            echo '<td><a href="'.esc_url(self::link($id)).'">Review costs &amp; receipts</a></td></tr>';
            $displayed++;
        }
        if(!$displayed)echo '<tr><td colspan="5">No products accessible under current pricing permissions.</td></tr>';
        echo '</tbody></table>';
    }
    private static function renderReview(int $id,array $options):void {
        if(!self::allowed($id)){wp_die('Cost review: this product is not in your pricing scope.');}
        $data=self::inspect($id,$options);
        if(is_wp_error($data)){echo '<div class="notice notice-error"><p>'.
            esc_html($data->get_error_code()).'</p></div>';return;}
        $history=self::history($id);$last=$history?end($history):null;
        $changed=!is_array($last)||
            !hash_equals((string)($last['source_fingerprint']??''),$data['source_fingerprint']);
        echo '<h2>'.esc_html($data['title']).' <small>#'.esc_html((string)$id).'</small></h2>';
        echo '<p><strong>'.esc_html(self::types()[$data['type']]).'</strong> · '.
            esc_html($data['post_status']).' · '.esc_html($data['review_state']).'</p>';
        echo '<p><strong>Since last recorded review:</strong> '.
            ($last?($changed?'SOURCE OR POLICY CHANGED — re-review required':'No relevant source change detected'):'No review has been recorded').'</p>';
        if(($_GET['review_saved']??'')==='1')
            echo '<div class="notice notice-success"><p>Review snapshot saved. This is NOT financial approval.</p></div>';
        echo '<p>';
        if($data['links']['original_editor'])
            echo '<a class="button" href="'.esc_url($data['links']['original_editor']).'">Original product editor</a> ';
        if(in_array($data['type'],['tours-and-activities','properties','transportations'],true))
            echo '<a class="button" href="'.esc_url($data['links']['pricing_workbench']).'">Original Pricing Workbench</a> ';
        if($data['type']==='tours-and-activities')
            echo '<a class="button" href="'.esc_url($data['links']['pricing_lab']).'">Pricing Lab</a> ';
        if(in_array($data['type'],['properties','units','property-rates'],true))
            echo '<a class="button" href="'.esc_url($data['links']['accommodation_pricing']).'">Property &amp; Unit Pricing</a> ';
        echo '</p>';
        self::dateForm($id,$options);
        $composition=$data['original_readbacks']['generic_cost_composition']??null;
        if(is_array($composition)){
            echo '<h2>Cost Composition — reference only</h2><p>Native and dynamic registry scopes remain separate until source coverage and deduplication are approved. No commercial Rate or payment is authorized.</p>';
            foreach(['native_composition'=>'Native package components','registry_composition'=>'Dynamic mapped components'] as $key=>$label){
                $scope=$composition[$key]??null;if(!is_array($scope))continue;
                echo '<h3>'.esc_html($label).'</h3><table class="widefat striped"><thead><tr><th>Line</th><th>Unit cost</th><th>Quantity</th><th>Converted cost</th></tr></thead><tbody>';
                foreach((array)($scope['lines']??[]) as $line)
                    echo '<tr><td>'.esc_html($line['line_key']).'</td><td>'.esc_html((string)$line['unit_amount'].' '.$line['source_currency']).'</td><td>'.esc_html((string)$line['quantity']).'</td><td>'.esc_html(is_int($line['target_total_minor'])?number_format($line['target_total_minor']/100,2).' '.$line['target_currency']:'Missing FX / cost').'</td></tr>';
                echo '</tbody></table>';
                $subtotal=$scope['converted_reference_subtotal_minor']??null;
                $margin=$scope['profit_preview']['indicative_selling_price_minor']??null;
                echo '<p><strong>Calculated partial cost:</strong> '.esc_html(is_int($subtotal)?number_format($subtotal/100,2).' '.$composition['context']['currency']:'Not calculated').'</p>';
                if(is_int($margin))echo '<p><strong>Illustrative margin applied once (partial scope only):</strong> '.esc_html(number_format($margin/100,2).' '.$composition['context']['currency']).'</p>';
                echo '<p><strong>Outstanding:</strong> '.esc_html(implode(' · ',(array)($scope['blockers']??[]))).'</p>';
            }
            echo '<p><strong>Commercial total / approved Rate:</strong> Not available until independently verified complete costs.</p>';
        }
        echo '<h2>Cost Source &amp; Receipt Register</h2>';
        if(class_exists('MAD4B_Cost_Source_Registry_V1',false)&&MAD4B_Cost_Source_Registry_V1::manageable())
            echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=mad4b-cost-source-registry')).'">Configure dynamic cost sources</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>Component / Field</th><th>Evidence / Source</th><th>Review state</th><th>Details</th></tr></thead><tbody>';
        foreach($data['cost_sources'] as $row){
            echo '<tr><td>'.esc_html($row['component']).'</td><td><code>'.esc_html($row['source']).'</code></td>';
            echo '<td>'.esc_html($row['status']).'</td><td>'.self::renderValue($row['value']);
            if(is_array($row['value'])){
                if(!empty($row['value']['native_edit_url']))
                    echo '<p><a href="'.esc_url($row['value']['native_edit_url']).'">Edit original mapped source</a></p>';
                $links=[];
                foreach(['rate_id','source_rate_id','property_id','selected_operational_property_id'] as $field){
                    $linked=(int)($row['value'][$field]??0);
                    if($linked>0&&self::allowed($linked))$links[$linked]=true;
                }
                foreach(array_keys($links) as $linked)
                    echo '<p><a href="'.esc_url(self::link((int)$linked)).'">Open linked source #'.
                        esc_html((string)$linked).' review</a></p>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Original Readbacks</h2>';
        foreach($data['original_readbacks'] as $title=>$read){
            if($read===null)continue;
            echo '<details><summary>'.esc_html(str_replace('_',' ',$title)).'</summary>'.
                self::renderValue($read).'</details>';
        }
        echo '<h2>Independent Authority and Company Profit</h2>';
        echo self::renderValue($data['authorities']);
        echo '<p><strong>Commercial price approved:</strong> No, not by this review. <strong>Checkout authorized:</strong> No, not by this review.</p>';
        echo '<h2>Record an operator review</h2>';
        echo '<p>The note and current evidence fingerprint are appended to this product\'s review history. This does NOT sign a Cost Receipt, publish a Rate, change a price or authorize payment.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="mad4b_product_cost_review_record_v1">';
        echo '<input type="hidden" name="product_id" value="'.esc_attr((string)$id).'">';
        echo '<input type="hidden" name="expected_fingerprint" value="'.esc_attr($data['source_fingerprint']).'">';
        foreach(['date','tier_id','currency','rooms','adults','children','infants','nights','days','vehicles','guides','cabins','guide_language_id','nationality','entrance_cohorts'] as $key)
            echo '<input type="hidden" name="context_'.$key.'" value="'.esc_attr((string)$options[$key]).'">';
        wp_nonce_field('mad4b_product_cost_review_'.$id,'review_nonce');
        echo '<label for="mad4b-cost-review-note">Reviewer note (max 500 characters)</label><p>';
        echo '<textarea id="mad4b-cost-review-note" name="review_note" rows="3" maxlength="500" style="width:100%;max-width:800px" placeholder="Evidence checked; missing source or next action"></textarea></p>';
        echo '<button type="submit" class="button button-primary">Save review snapshot — NOT approval</button></form>';
        echo '<h2>Recorded review history</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Review revision</th><th>Recorded UTC</th><th>Reviewer</th><th>Source fingerprint</th><th>Findings</th><th>Note</th></tr></thead><tbody>';
        $historyPage=max(1,absint($_GET['history_page']??1));
        $history=self::history($id,$historyPage);
        foreach(array_reverse($history) as $entry){
            $actor=get_userdata((int)($entry['reviewer_id']??0));
            echo '<tr><td>'.esc_html((string)($entry['revision']??'')).'</td><td>'.
                esc_html((string)($entry['reviewed_at_utc']??'')).'</td><td>'.
                esc_html($actor?(string)$actor->display_name:'Unknown reviewer').'</td><td><code>'.
                esc_html(substr((string)($entry['source_fingerprint']??''),0,16)).'…</code></td><td>'.
                esc_html((string)($entry['review_state']??'')).'</td><td>'.
                esc_html((string)($entry['note']??'')).'</td></tr>';
        }
        if(!$history)echo '<tr><td colspan="6">No review snapshots on this page.</td></tr>';
        echo '</tbody></table><p>';
        if($historyPage>1)echo '<a href="'.esc_url(add_query_arg('history_page',$historyPage-1)).'">Newer reviews</a> ';
        if(count($history)===25)echo '<a href="'.esc_url(add_query_arg('history_page',$historyPage+1)).'">Older reviews</a>';
        echo '</p>';
    }
    public static function page():void {
        if(!self::allowed())wp_die('Pricing cost review permission required.');
        $id=absint($_GET['product_id']??0);
        echo '<div class="wrap"><h1>Cost Review &amp; Supplier Receipt Ledger</h1>';
        if(class_exists('MAD4B_Cost_Source_Registry_V1',false)&&MAD4B_Cost_Source_Registry_V1::manageable())
            echo '<p><a href="'.esc_url(admin_url('admin.php?page=mad4b-cost-source-registry')).'">Cost Source Registry</a></p>';
        echo '<p>One review entry point for native company products and Rates. All original pricing, supplier-cost, booking, publishing and signing authorities remain independent. A review snapshot never approves an amount or changes an order.</p>';
        if($id)self::renderReview($id,self::options());
        else self::renderIndex();
        echo '</div>';
    }
    public static function saveAction():void {
        $id=absint($_POST['product_id']??0);
        if(!$id||!self::allowed($id))wp_die('Cost review write is not authorized.','Access denied',['response'=>403]);
        check_admin_referer('mad4b_product_cost_review_'.$id,'review_nonce');
        $o=[];
        foreach(['date','tier_id','currency','rooms','adults','children','infants','nights','days','vehicles','guides','cabins','guide_language_id','nationality','entrance_cohorts'] as $key)
            $o[$key]=$key==='entrance_cohorts'?sanitize_textarea_field((string)wp_unslash($_POST['context_'.$key]??'')):sanitize_text_field((string)wp_unslash($_POST['context_'.$key]??''));
        $expected=sanitize_text_field((string)wp_unslash($_POST['expected_fingerprint']??''));
        if(!preg_match('/^[a-f0-9]{64}$/D',$expected))wp_die('Invalid review source digest.','Invalid request',['response'=>400]);
        $latest=self::inspect($id,$o);
        if(is_wp_error($latest)||!hash_equals($expected,$latest['source_fingerprint']))
            wp_die('Underlying cost sources or rules changed. Reopen the review before saving.','Review conflict',['response'=>409]);
        $saved=self::record($id,[
            'note'=>wp_unslash($_POST['review_note']??''),
            'expected_fingerprint'=>$expected,
            'options'=>$o]);
        if(is_wp_error($saved))wp_die('Review was not recorded: '.$saved->get_error_code(),'Review error',['response'=>409]);
        $link=self::link($id).'&review_saved=1';
        foreach($o as $key=>$value)if($value!=='')
            $link.='&'.rawurlencode($key).'='.rawurlencode((string)$value);
        wp_safe_redirect($link);exit;
    }
    public static function menu():void {
        if(!self::allowed())return;
        add_submenu_page('mad4b-pricing-workspace',
            'Product Cost Review Ledger','Cost Review Ledger',
            'commerce_manage_pricing',self::PAGE,[self::class,'page']);
    }
    public static function editorBox(WP_Post $post):void {
        $id=(int)$post->ID;
        if(!self::allowed($id))return;
        $history=self::history($id);$latest=$history?end($history):[];
        echo '<p>Original source cost, Cost Receipts, outstanding checks and review history in one location. Does not issue payment authorization.</p>';
        echo '<p><a class="button button-primary" href="'.esc_url(self::link($id)).'">Open Cost Review &amp; Receipt History</a></p>';
        echo '<p><small>Last saved review: '.esc_html((string)($latest['reviewed_at_utc']??'Not yet reviewed')).'</small></p>';
    }
    public static function attachBoxes():void {
        foreach(array_keys(self::types()) as $type){
            add_action('add_meta_boxes_'.$type,static function($post){
                if(!($post instanceof WP_Post)||!self::allowed((int)$post->ID))return;
                add_meta_box('mad4b-cost-review-ledger-link','Cost Review & Supplier Receipts',
                    [self::class,'editorBox'],$post->post_type,'side','high');
            },90);
        }
    }
    public static function rowActions(array $actions,$post):array {
        if(!($post instanceof WP_Post)||!self::allowed((int)$post->ID))return $actions;
        $actions['mad4b_cost_review_ledger']='<a href="'.esc_url(self::link((int)$post->ID)).'">Cost Review</a>';
        return $actions;
    }
    public static function originalWorkbenchLink():void {
        if(!self::allowed()||sanitize_key((string)($_GET['page']??''))!=='mad4b-pricing-workbench')return;
        $id=absint($_GET['product_id']??0);
        if($id&&!self::allowed($id))return;
        echo '<div class="notice notice-info"><p><strong>Unified product Cost Reviews:</strong> ';
        echo '<a href="'.esc_url($id?self::link($id):admin_url('admin.php?page='.self::PAGE)).'">'.
            'Open supplier cost evidence, receipt status and review history</a>. Original pricing and approval workflows stay in place.</p></div>';
    }
    public static function boot():void {
        add_action('admin_menu',[self::class,'menu'],870);
        add_action('admin_init',[self::class,'attachBoxes'],20);
        add_filter('post_row_actions',[self::class,'rowActions'],25,2);
        add_filter('page_row_actions',[self::class,'rowActions'],25,2);
        add_action('admin_post_mad4b_product_cost_review_record_v1',[self::class,'saveAction']);
        add_action('admin_notices',[self::class,'originalWorkbenchLink'],95);
    }
}
MAD4B_Product_Cost_Review_Ledger_V1::boot();
}
