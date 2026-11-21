<?php
/**
 * MAD4B Tour Rates — creation-first JetEngine native tab editor v2.
 *
 * Preview:
 * wp eval-file ops/pricing-unified-checkpoints/tools/tour-rate-native-creation-order-v2.php
 * Explicit apply (schema layout only):
 * MAD4B_RATE_CREATION_ORDER_APPLY=1 wp eval-file ops/pricing-unified-checkpoints/tools/tour-rate-native-creation-order-v2.php
 *
 * No postmeta, rates, relation, price, orders or payment changes.
 * Every original commercial field, condition, option and repeater stays
 * unchanged except selected display-only title/description improvements.
 */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI) return;

final class MAD4B_Tour_Rate_Creation_Order_V2 {
    private const POST_TYPE='tour-rates';
    // 52 native fields, each appears once and only once. Tab names are the
    // same established six native JetEngine tab identities as in v1.
    private const GROUPS=[
        'mad4b_rate_tab_tour_schedule'=>['related_tour_id','tour_rate_start_date','tour_rate_end_date','related_program_term_id','related_package_term_id','related_day_of_week_term_id','related_day_time_term_id','related_origin_destination_term_id','mad4b_cruise_route_variant_v1','mad4b_cruise_supplier_confirmed_v1','show_rate_departure_details'],
        'traveler_rates'=>['base_currency','unit_type','has_egyptians_price','egyptians_currency','is_on_sale','single_price','single_sale_price','egyptians_single_price','egyptians_single_sale_price','double_price','double_sale_price','egyptians_double_price','egyptians_double_sale_price','triple_price','triple_sale_price','egyptians_triple_price','egyptians_triple_sale_price'],
        'mad4b_rate_tab_accommodation'=>['has_properties','accommodation_assignment_mode','has_multiple_accommodation_stages','related_properties_id','_booking_experience_stay_stages_v1'],
        'mad4b_rate_tab_transport'=>['has_transportations','transport_assignment_mode','transport_selection_mode','transport_price_policy','related_transportations_id','_mad4b_transport_stages_v1','transport_markup_override_pct'],
        'mad4b_rate_tab_setup'=>['avilabile_status','has_allotment_inevntory','inevntory_quantity','sold_allotment'],
        'mad4b_rate_tab_financial'=>['shared_financial_tax_price_basis','shared_financial_discount_tax_order','shared_financial_payment_fee_taxable','shared_financial_payment_fee_bearer','shared_financial_discount_scope','shared_financial_refund_allocation','shared_financial_refund_payment_fee','note'],
    ];
    private const TAB_TITLES=[
        'mad4b_rate_tab_tour_schedule'=>'Tour & Dates',
        'traveler_rates'=>'Traveler Prices',
        'mad4b_rate_tab_accommodation'=>'Accommodation',
        'mad4b_rate_tab_transport'=>'Transportation',
        'mad4b_rate_tab_setup'=>'Availability & Stock',
        'mad4b_rate_tab_financial'=>'Policies & Notes',
    ];
    private const FRIENDLY_LABELS=[
        'related_tour_id'=>'Tour · select first',
        'tour_rate_start_date'=>'Rate valid from',
        'tour_rate_end_date'=>'Rate valid until',
        'related_program_term_id'=>'Program / itinerary (if applicable)',
        'related_package_term_id'=>'Package level (if applicable)',
        'related_day_of_week_term_id'=>'Departure weekday (if applicable)',
        'related_origin_destination_term_id'=>'Departure origin (if applicable)',
        'related_day_time_term_id'=>'Departure time (if applicable)',
        'mad4b_cruise_route_variant_v1'=>'Cruise route variant (7-night programs)',
        'mad4b_cruise_supplier_confirmed_v1'=>'Cruise route supplier confirmation',
        'base_currency'=>'Selling currency',
        'unit_type'=>'Price basis / unit',
        'has_egyptians_price'=>'Separate Egyptian traveler prices?',
        'egyptians_currency'=>'Egyptian selling currency',
        'is_on_sale'=>'Enable promotional sale prices?',
        'has_properties'=>'Include Property / accommodation?',
        'related_properties_id'=>'Property for a single stay',
        'accommodation_assignment_mode'=>'Accommodation price assignment',
        'has_multiple_accommodation_stages'=>'Use multiple accommodation stages?',
        '_booking_experience_stay_stages_v1'=>'Accommodation stages · hotels / cruise',
        'has_transportations'=>'Include transportation components?',
        'transport_assignment_mode'=>'Transportation assignment',
        'transport_selection_mode'=>'Transportation selection method',
        'related_transportations_id'=>'Transportation alternatives (OR)',
        'transport_price_policy'=>'Transportation price treatment',
        '_mad4b_transport_stages_v1'=>'Transportation stages · required legs',
        'avilabile_status'=>'Rate availability',
        'has_allotment_inevntory'=>'Limit available inventory?',
        'inevntory_quantity'=>'Available allotment',
        'sold_allotment'=>'Sold allotment',
        'show_rate_departure_details'=>'Show departure details on Rate card',
        'note'=>'Internal Rate notes',
    ];
    private const INPUT_HELP=[
        'related_tour_id'=>'Select the original Tour first. This is the only stored Rate-to-Tour link; Tour Queried Rates is an inventory view.',
        'tour_rate_start_date'=>'First valid date for this Rate; not an automatic service or contract approval.',
        'tour_rate_end_date'=>'Last valid date for this Rate.',
        'related_program_term_id'=>'Optional. Choose the itinerary or cruise program relevant to this Rate.',
        'base_currency'=>'Set this before entering Single, Double and Triple prices.',
        'unit_type'=>'Use the actual selling unit for the traveler price columns below.',
        'has_egyptians_price'=>'Choose Yes only when separate Egyptian-market amounts will be entered.',
        'is_on_sale'=>'Choose Yes only when promotional selling prices are entered.',
        'has_properties'=>'Choose Yes when this Rate directly includes a Property. For multiple stays, configure stages below.',
        'has_multiple_accommodation_stages'=>'Choose Yes only when accommodation uses more than one stage or property.',
        'has_transportations'=>'Choose Yes only when a native transportation component belongs to this Rate.',
        'transport_selection_mode'=>'Simple = alternatives. Stages = required route legs; save them in the native repeater.',
        'avilabile_status'=>'Set Rate availability separately from supplier cost and checkout authorization.',
        'has_allotment_inevntory'=>'Enable only when a finite Rate inventory/allotment is managed.',
    ];

    private static function orderedKeys(array $fields):array {
        return array_values(array_map(static function($f) {
            return (string)($f['name']??'');
        },$fields));
    }

    public static function transform(array $fields) {
        if(count($fields)!==58)return new WP_Error('rate_creation_v2_schema_count_unexpected');
        $tabs=[];$native=[];
        foreach($fields as $f){
            if(!is_array($f) || !is_string($f['name']??null))
                return new WP_Error('rate_creation_v2_invalid_field');
            $name=$f['name'];
            if(isset($tabs[$name])||isset($native[$name]))
                return new WP_Error('rate_creation_v2_duplicate_key');
            if(($f['object_type']??'field')==='tab')$tabs[$name]=$f;
            else $native[$name]=$f;
        }
        if(count($tabs)!==6||count($native)!==52||
            array_diff(array_keys(self::TAB_TITLES),array_keys($tabs))||
            array_diff(array_keys($tabs),array_keys(self::TAB_TITLES)))
            return new WP_Error('rate_creation_v2_foreign_tab_layout');
        $all=[];
        foreach(self::GROUPS as $group)foreach($group as $field){
            if(isset($all[$field])||!isset($native[$field]))
                return new WP_Error('rate_creation_v2_missing_or_duplicate_native_field');
            $all[$field]=true;
        }
        if(count($all)!==count($native))
            return new WP_Error('rate_creation_v2_unclassified_native_field');
        // Preserve the IDs and all attributes from the v1 JetEngine Tabs.
        $new=[];
        foreach(self::GROUPS as $tab=>$names){
            $header=$tabs[$tab];
            $header['title']=self::TAB_TITLES[$tab];
            $new[]=$header;
            foreach($names as $key){
                $field=$native[$key];
                if(isset(self::FRIENDLY_LABELS[$key]))
                    $field['title']=self::FRIENDLY_LABELS[$key];
                if(isset(self::INPUT_HELP[$key])){
                    $text=self::INPUT_HELP[$key];
                    $description=trim((string)($field['description']??''));
                    if(strpos($description,$text)===false)
                        $field['description']=$description===''?$text:
                            $description.' '.$text;
                }
                $new[]=$field;
            }
        }
        if(count($new)!==58 ||
            count(array_unique(self::orderedKeys($new)))!==58)
            return new WP_Error('rate_creation_v2_output_incomplete');
        // All data behavior must stay byte-for-byte equivalent other than
        // labels / short editor-facing usage hints. Crucially, no conditional
        // logic is rewritten or detached from its control field.
        foreach($new as $newField){
            $key=$newField['name'];
            if(!isset($native[$key]))continue;
            $oldField=$native[$key];
            foreach(['title','description'] as $display){
                unset($newField[$display],$oldField[$display]);
            }
            if($newField!==$oldField)
                return new WP_Error('rate_creation_v2_commercial_field_would_change');
        }
        return $new;
    }

    public static function run():void {
        global $wpdb;
        $table=$wpdb->prefix.'jet_post_types';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT id,slug,meta_fields FROM `".$table."` WHERE slug=%s LIMIT 1",
            self::POST_TYPE
        ));
        if(!$row||!is_string($row->meta_fields)){
            WP_CLI::error('Tour Rate JetEngine CPT schema not found');
            return;
        }
        $raw=$row->meta_fields;
        $fields=maybe_unserialize($raw);
        if(!is_array($fields)){
            WP_CLI::error('Tour Rate JetEngine schema corrupt');
            return;
        }
        $new=self::transform($fields);
        if(is_wp_error($new)){
            WP_CLI::error($new->get_error_code());
            return;
        }
        $beforeHash=hash('sha256',$raw);
        $newBlob=maybe_serialize($new);
        $afterHash=hash('sha256',$newBlob);
        $unchanged=hash_equals($beforeHash,$afterHash);
        $labels=[];
        foreach($new as $field)if(($field['object_type']??'')==='tab')
            $labels[]=$field['title'];
        $receipt=[
            'site'=>home_url(),'component'=>'JetEngine tour-rates CPT',
            'native_data_fields_preserved'=>52,
            'native_visual_tabs'=>count($labels),
            'creation_tab_order'=>$labels,
            'field_count_before'=>count($fields),
            'field_count_after'=>count($new),
            'related_tour_id'=>'unchanged_single_source_of_truth',
            'tour_queried_rates'=>'unchanged_existing_inventory_query',
            'jetengine_relation_created'=>false,
            'before_sha256'=>$beforeHash,'after_sha256'=>$afterHash,
            'already_applied'=>$unchanged,'write_performed'=>false,
        ];
        if(getenv('MAD4B_RATE_CREATION_ORDER_APPLY')==='1'&&!$unchanged){
            $backup='/tmp/mad4b-tour-rate-v2-before-'.
                (int)$row->id.'-'.substr($beforeHash,0,16).'.ser';
            if(file_put_contents($backup,$raw,LOCK_EX)===false){
                WP_CLI::error('Original JetEngine schema backup failed');
                return;
            }
            chmod($backup,0600);
            $applied=$wpdb->query($wpdb->prepare(
                "UPDATE `".$table."` SET meta_fields=%s WHERE id=%d AND meta_fields=%s",
                $newBlob,(int)$row->id,$raw
            ));
            if($applied!==1){
                WP_CLI::error('Concurrent JetEngine edit or schema update conflict');
                return;
            }
            $read=$wpdb->get_var($wpdb->prepare(
                "SELECT meta_fields FROM `".$table."` WHERE id=%d",
                (int)$row->id
            ));
            if(!is_string($read)||
                !hash_equals($afterHash,hash('sha256',$read))){
                WP_CLI::error('Tour Rate schema readback failed');
                return;
            }
            $receipt['write_performed']=true;
            $receipt['backup_path']=$backup;
        }
        WP_CLI::line(wp_json_encode($receipt,
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
}
MAD4B_Tour_Rate_Creation_Order_V2::run();
