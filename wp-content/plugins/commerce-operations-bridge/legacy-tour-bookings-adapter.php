<?php
/**
 * ETG Legacy Tour Bookings — a read-only anti-duplication / migration planning adapter.
 * Does not alter JetEngine CPT, Woo orders, suppliers, or accounting.
 */
defined('ABSPATH') || exit;

final class Commerce_Operations_Tour_Bookings_Adapter {
    public static function legacy_groups(): array {
        return [
            'dmc'=>'tour_booking_dmc',
            'property'=>'tour_booking_property',
            'extra'=>'tour_bookinxg_extras_addons',
            'guide'=>'tour_booking_guide',
            'driver'=>'tour_booking_driver',
            'sales_commission'=>'tour_booking_sales_agent_affiliater',
        ];
    }

    public static function audit(): array|WP_Error {
        global $wpdb;
        $type=get_post_type_object('tour_bookings');
        if (!$type)return new WP_Error('commerce_ops_legacy_tour_bookings_missing');
        $table=$wpdb->prefix.'jet_post_types';
        // Legacy schema here; normal reads only; never write to JetEngine registry.
        $raw=$wpdb->get_var($wpdb->prepare("SELECT meta_fields FROM {$table} WHERE slug=%s AND status=%s LIMIT 1",'tour_bookings','publish'));
        $fields=is_string($raw)?maybe_unserialize($raw):[];
        if (!is_array($fields))return new WP_Error('commerce_ops_legacy_schema_invalid');
        $keys=array_filter(array_map(static fn($f)=>is_array($f)?(string)($f['name']??''):'',$fields));
        $groups=[];
        foreach(self::legacy_groups() as $domain=>$key){
            $groups[$domain]=in_array($key,$keys,true);
        }
        $rows=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s",'tour_bookings'));
        return [
            'source'=>'jetengine_tour_bookings','existing_cpt'=>true,
            'schema_defined_fields'=>count($fields),'records_in_this_environment'=>$rows,
            'groups_present'=>$groups,'all_required_groups_present'=>!in_array(false,$groups,true),
            'legacy_order_ref'=>'tour_order_id_of_this_booking',
            'woo_order_item_link_present'=>in_array('_mad4b_wc_order_item_id',$keys,true),
            'publicly_queryable'=>(bool)$type->publicly_queryable,
            'rest_publication_enabled'=>(bool)$type->show_in_rest,
            'anonymous_read_requires_access_review'=>($type->publicly_queryable || $type->show_in_rest),
            'migration_safe_to_apply'=>false,
        ];
    }

    /**
     * Read-only canonical source validation. A Rate CPT can price an order line,
     * but it is never the canonical supplier-bookable ProductRef. Until its
     * owning product is independently resolved, fail closed.
     */
    public static function canonical_product_ref($product_id): array|WP_Error {
        $id=filter_var($product_id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id)return new WP_Error('commerce_ops_booking_link_invalid_product_id');
        $type=get_post_type((int)$id);
        if (!is_string($type) || $type==='') {
            return new WP_Error('commerce_ops_booking_link_product_not_found');
        }
        if (preg_match('/(?:^|[-_])rates?$/i',$type)) {
            return new WP_Error('commerce_ops_booking_link_canonical_product_required');
        }
        // Being a content ProductRef is NOT a supplier booking entitlement.
        return ['post_type'=>$type,'post_id'=>(int)$id];
    }

    /**
     * Read-only tour identity from existing native Rate parent metadata.
     * Rate is a PRICE source; this never turns it into a bookable product.
     */
    public static function tour_ref_from_rate($rate_id,$item_quote_rate_id): array|WP_Error {
        $rate=filter_var($rate_id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $selected=filter_var($item_quote_rate_id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$rate || !$selected || (int)$rate!==(int)$selected)
            return new WP_Error('commerce_ops_rate_order_quote_rate_mismatch');
        if (get_post_type((int)$rate)!=='tour-rates' || get_post_status((int)$rate)!=='publish')
            return new WP_Error('commerce_ops_rate_order_source_unavailable');
        $parent=filter_var(get_post_meta((int)$rate,'related_tour_id',true),
            FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$parent || get_post_type((int)$parent)!=='tours-and-activities'
            || get_post_status((int)$parent)!=='publish')
            return new WP_Error('commerce_ops_rate_order_parent_unverified');
        return ['post_type'=>'tours-and-activities','post_id'=>(int)$parent];
    }

    /**
     * Resolve a real WC OrderItem Rate source to its existing Tour.
     * This proposal has no BookingProof, price receipt or supplier rights.
     */
    public static function rate_order_identity_proposal($order_id,$order_item_id): array|WP_Error {
        if (!function_exists('wc_get_order'))return new WP_Error('commerce_ops_woocommerce_missing');
        $o=filter_var($order_id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $i=filter_var($order_item_id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$o || !$i)return new WP_Error('commerce_ops_booking_link_invalid_ids');
        $order=wc_get_order((int)$o);
        if (!$order || $order->get_type()!=='shop_order')
            return new WP_Error('commerce_ops_rate_order_invalid_order');
        $item=$order->get_item((int)$i);
        if (!$item || !($item instanceof WC_Order_Item_Product))
            return new WP_Error('commerce_ops_booking_link_item_not_in_order');
        $rate=(int)$item->get_product_id();
        $parent=self::tour_ref_from_rate($rate,$item->get_meta('_shared_quote_rate_id',true));
        if (is_wp_error($parent))return $parent;
        $display=$item->get_meta('_shared_quote_display_currency',true);
        $source=$item->get_meta('_shared_quote_source_currency',true);
        $amount=$item->get_meta('_shared_quote_display_amount',true);
        $quoted=$item->get_meta('_shared_quote_quoted_at_utc',true);
        if (!is_string($display) || $display!==$order->get_currency()
            || !is_string($source) || !preg_match('/^[A-Z]{3}$/D',$source)
            || !is_numeric($amount) || !is_finite((float)$amount)
            || (float)$amount<0 || !is_string($quoted)
            || $quoted==='' || strtotime($quoted)===false
            || (int)$item->get_quantity()<1)
            return new WP_Error('commerce_ops_rate_order_quote_evidence_invalid');
        return [
            'schema'=>'commerce-ops-rate-order-identity-proposal.v1',
            'order_id'=>(int)$o,'order_item_id'=>(int)$i,
            'canonical_product_ref'=>$parent,
            'source_rate_ref'=>['post_type'=>'tour-rates','post_id'=>$rate],
            'order_currency_consistent'=>true,
            'quoted_rate_id_matches_item'=>true,
            'preview_quote_metadata_present'=>true,
            'travel_date_verified'=>false,
            'booking_configuration_verified'=>false,
            'signed_price_receipt_present'=>false,
            'supplier_rights_verified'=>false,
            'supplier_booking_authorized'=>false,
            'requires_quote_and_supplier_review'=>true,
            'writes_performed'=>false,
        ];
    }

    /**
     * Original Woo order item -> booking v2 readiness, read-only.
     * A legacy quote snapshot is NEVER a complete booking selection
     * nor signed financial authority or supplier permission.
     */
    public static function booking_configuration_readiness_proposal($order_id,$order_item_id): array|WP_Error {
        $identity=self::rate_order_identity_proposal($order_id,$order_item_id);
        if (is_wp_error($identity))return $identity;
        $order=wc_get_order((int)$identity['order_id']);
        $item=$order->get_item((int)$identity['order_item_id']);
        $rate_id=(int)$identity['source_rate_ref']['post_id'];
        $tour_id=(int)$identity['canonical_product_ref']['post_id'];

        $saved=$item->get_meta('_shared_booking_configuration',true);
        $booking_v2=is_array($saved) && ($saved['schema_version']??null)===2;
        $validated=false;$date=null;$date_covered=false;
        $reasons=[];
        if (!$booking_v2) {
            $reasons[]='booking_configuration_v2_missing';
        } elseif (!function_exists('shared_product_booking_v2_validate')) {
            $reasons[]='booking_v2_validator_unavailable';
        } else {
            $actual=shared_product_booking_v2_validate($saved);
            if (is_wp_error($actual)
                || (int)($actual['tour_id']??0)!==$tour_id
                || (int)($actual['rate_id']??0)!==$rate_id
                || ($actual['source']??null)!=='rate') {
                $reasons[]='booking_configuration_v2_invalid_or_unbound';
            } else {
                $validated=true;
                $date=$actual['travel_date']??null;
            }
        }
        if ($date===null) {
            $reasons[]='travel_date_not_verified';
        } elseif (!function_exists('shared_product_booking_v2_rate_covers_date')
            || !shared_product_booking_v2_rate_covers_date($rate_id,$date)) {
            $reasons[]='selected_date_outside_rate_window';
        } else {
            $date_covered=true;
        }
        // No native signed commercial price or supplier rights receipt
        // provider has been wired to this adapter yet.
        $reasons[]='approved_price_receipt_not_verified';
        $reasons[]='supplier_request_authority_not_verified';

        $window_start=(int)get_post_meta($rate_id,'tour_rate_start_date',true);
        $window_end=(int)get_post_meta($rate_id,'tour_rate_end_date',true);
        return [
            'schema'=>'commerce-ops-booking-configuration-readiness.v1',
            'status'=>'HOLD_NOT_READY_FOR_SUPPLIER_REQUEST',
            'order_id'=>(int)$identity['order_id'],
            'order_item_id'=>(int)$identity['order_item_id'],
            'canonical_product_ref'=>$identity['canonical_product_ref'],
            'source_rate_ref'=>$identity['source_rate_ref'],
            'order_status'=>$order->get_status(),
            'rate_window_present'=>$window_start>0 && $window_end>=$window_start,
            'rate_window_is_travel_date'=>false,
            'legacy_quote_preview_present'=>$identity['preview_quote_metadata_present'],
            'booking_configuration_v2_validated'=>$validated,
            'selected_travel_date_verified'=>$date_covered,
            'package_and_age_options_validated'=>$validated,
            'approved_price_receipt_verified'=>false,
            'supplier_request_authorized'=>false,
            'supplier_booking_confirmed'=>false,
            'blockers'=>$reasons,
            'writes_performed'=>false
        ];
    }

    /** Builds an order-to-booking link proposal; refuses invalid HPOS / item references. */
    public static function link_proposal($order_id,$order_item_id): array|WP_Error {
        if (!function_exists('wc_get_order'))return new WP_Error('commerce_ops_woocommerce_missing');
        $o=filter_var($order_id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $i=filter_var($order_item_id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$o||!$i)return new WP_Error('commerce_ops_booking_link_invalid_ids');
        $order=wc_get_order($o);
        if(!$order)return new WP_Error('commerce_ops_booking_link_order_not_found');
        $item=$order->get_item($i);
        if(!$item || !($item instanceof WC_Order_Item_Product))return new WP_Error('commerce_ops_booking_link_item_not_in_order');
        $canonical=self::canonical_product_ref($item->get_product_id());
        if (is_wp_error($canonical))return $canonical;
        return [
            'order_id'=>(int)$o, 'order_item_id'=>(int)$i,
            'booking_cpt'=>'tour_bookings',
            'relationship'=>'order_item_to_many_supplier_bookings',
            'product_id'=>(int)$item->get_product_id(),
            'canonical_product_ref'=>$canonical,
            'requires_supplier_validation'=>true,
            'requires_approved_quote'=>true,
            'creates_booking'=>false,
            'creates_financial_entry'=>false,
            'migration_authorized'=>false,
        ];
    }
}
