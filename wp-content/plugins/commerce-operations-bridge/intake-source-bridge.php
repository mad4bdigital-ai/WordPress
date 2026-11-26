<?php
/** Local-only source-of-truth discovery; no submissions, writes, or public routes. */
defined('ABSPATH') || exit;

final class Commerce_Operations_Intake_Source_Bridge {
    // Only site-owned explicit mappings; field existence and type are verified below.
    public const MAPPING_OPTION='commerce_ops_intake_field_bindings_v1';
    public static function mapping(int $id):array|WP_Error {
        $all=get_option(self::MAPPING_OPTION,[]);
        $m=is_array($all)?($all[$id]??null):null;
        if(!is_array($m)||array_diff(array_keys($m),['date','date_meaning','adults','children','infants','flex_to'])||!in_array($m['date_meaning']??null,['requested_departure','requested_arrival'],true))return new WP_Error('intake_form_not_mapped');
        $fields=[];
        foreach(['date','adults','children','infants','flex_to'] as $key){
            if(!array_key_exists($key,$m)){if(in_array($key,['date','adults'],true))return new WP_Error('intake_form_mapping_invalid');continue;}
            if(!is_string($m[$key])||!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,119}$/D',$m[$key])||in_array($m[$key],$fields,true))return new WP_Error('intake_form_mapping_invalid');
            $fields[]=$m[$key];
        }
        return $m;
    }

    private static function field_types($form_id): array|WP_Error {
        global $wpdb;
        $table=$wpdb->prefix.'fluentform_forms';
        $json=$wpdb->get_var($wpdb->prepare(
            "SELECT form_fields FROM {$table} WHERE id=%d AND status='published' LIMIT 1", $form_id));
        if (!is_string($json) || $json==='')return new WP_Error('intake_form_not_available');
        $schema=json_decode($json,true);
        if (!is_array($schema))return new WP_Error('intake_form_schema_invalid');
        $types=[];
        $walk=static function($fields)use(&$walk,&$types){
            foreach((array)$fields as $node){
                if (!is_array($node))continue;
                $name=$node['attributes']['name']??null;
                $element=$node['element']??null;
                if(is_string($name)&&is_string($element)){
                    if(isset($types[$name]) && $types[$name]!==$element){
                        $types[$name]='ambiguous';
                    }else{$types[$name]=$element;}
                }
                foreach(['fields','columns'] as $child)
                    if(isset($node[$child]))$walk($node[$child]);
            }
        };
        $walk($schema['fields']??[]);
        return $types;
    }

    /** Only validate shape of synthetic input against live form schema, never submit. */
    public static function preview_fluent_request($form_id,$values): array|WP_Error {
        $id=filter_var($form_id,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        if(!$id || is_bool($form_id))return new WP_Error('intake_form_not_mapped');
        if(!is_array($values))return new WP_Error('intake_values_not_array');
        $mapping=self::mapping((int)$id);
        if(is_wp_error($mapping))return $mapping;
        $types=self::field_types((int)$id);
        if(is_wp_error($types))return $types;
        $expected_date=$mapping['date'];
        if(($types[$expected_date]??null)!=='input_date')
            return new WP_Error('intake_form_date_binding_changed');
        if(isset($mapping['flex_to'])&&($types[$mapping['flex_to']]??null)!=='input_date')return new WP_Error('intake_form_date_binding_changed');
        foreach(['adults','children','infants'] as $k)
            if(isset($mapping[$k]) &&
              ($types[$mapping[$k]]??null)!=='input_number')
                return new WP_Error('intake_form_occupancy_binding_changed');
        $date=$values[$expected_date]??null;
        $valid_date=is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)
            && DateTimeImmutable::createFromFormat('!Y-m-d',$date)
            && DateTimeImmutable::createFromFormat('!Y-m-d',$date)->format('Y-m-d')===$date;
        if(!$valid_date)return new WP_Error('intake_request_date_missing_or_invalid');
        $quantities=[];
        foreach(['adults','children','infants'] as $k){
            if(!isset($mapping[$k]))continue;
            // Missing child/infant counts must remain unknown, NOT zero.
            if(!array_key_exists($mapping[$k],$values)
                || $values[$mapping[$k]]==='' || $values[$mapping[$k]]===null){
                if($k==='adults')return new WP_Error('intake_guest_count_missing');
                $quantities[$k]=null;
                continue;
            }
            $raw=$values[$mapping[$k]];
            $n=filter_var($raw,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>$k==='adults'?1:0,'max_range'=>999]]);
            if($n===false || $n===null || is_bool($raw))
                return new WP_Error('intake_guest_count_invalid');
            $quantities[$k]=(int)$n;
        }
        $flex=null;
        if(isset($mapping['flex_to']) && !empty($values[$mapping['flex_to']])){
            $flex=$values[$mapping['flex_to']];
            if(!is_string($flex) ||
              !DateTimeImmutable::createFromFormat('!Y-m-d',$flex) ||
              DateTimeImmutable::createFromFormat('!Y-m-d',$flex)->format('Y-m-d')!==$flex ||
              $flex<$date)
              return new WP_Error('intake_flex_date_invalid');
        }
        // Arrival or departure is not implicitly the Tour start date.
        return [
            'schema'=>'commerce-ops-intake-source-preview.v1',
            'origin'=>'fluent_forms_request',
            'form_id'=>(int)$id,
            'date_field'=>$expected_date,
            'date_meaning'=>$mapping['date_meaning'],
            'requested_date'=>$date,
            'requested_flex_to'=>$flex,
            'guest_count'=>$quantities,
            'guest_counts_complete'=>isset($quantities['adults'],$quantities['children'],$quantities['infants']),
            'child_ages_verified'=>false,
            'tour_date_verified'=>false,
            'booking_configuration_v2_verified'=>false,
            'contact_source'=>'fluent_forms_submission_not_read',
            'marketing_consent_verified'=>false,
            'form_submission_authenticated'=>false,
            'order_created'=>false,
            'supplier_request_authorized'=>false,
            'status'=>'INQUIRY_PREVIEW_ONLY',
        ];
    }

    /** Real HPOS order contact availability, no PII returned or mutated. */
    public static function inspect_checkout_order($order_id): array|WP_Error {
        if(!function_exists('wc_get_order'))return new WP_Error('intake_woocommerce_missing');
        $id=filter_var($order_id,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        if(!$id)return new WP_Error('intake_order_id_invalid');
        $order=wc_get_order((int)$id);
        if(!$order || $order->get_type()!=='shop_order')
            return new WP_Error('intake_order_missing');
        $lines=$order->get_items('line_item');
        $v2=0;
        foreach($lines as $line){
            $config=$line->get_meta('_shared_booking_configuration',true);
            if(is_array($config) && ($config['schema_version']??null)===2)$v2++;
        }
        return [
            'schema'=>'commerce-ops-checkout-source-inspection.v1',
            'origin'=>'woocommerce_hpos_checkout',
            'order_id'=>(int)$order->get_id(),
            'billing_contact_present'=>[
                'name'=>trim($order->get_billing_first_name().' '.$order->get_billing_last_name())!=='',
                'email'=>$order->get_billing_email()!=='',
                'phone'=>$order->get_billing_phone()!=='',
            ],
            'billing_country_present'=>$order->get_billing_country()!=='',
            'customer_nationality_verified'=>false,
            'payment_status'=>$order->is_paid()?'paid':'not_paid',
            'line_count'=>count($lines),
            'booking_v2_line_count'=>$v2,
            'marketing_consent_verified'=>false,
            'supplemental_booking_fields_inferred'=>false,
            'supplier_request_authorized'=>false,
            'writes_performed'=>false,
        ];
    }
}
