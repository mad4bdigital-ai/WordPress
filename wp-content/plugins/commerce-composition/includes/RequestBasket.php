<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Nonpayment booking REQUESTS attached to the current Woo customer session.
 *
 * NOT the native WC_Cart: inserting an unpriced travel item into WC_Cart would
 * bypass the existing commercial quote gate or create a fake free sale. This
 * separate session container can later be converted by an approved Checkout
 * handler, once its genuine request-only/financial contract is implemented.
 *
 * This writer guarantees same-session replay idempotency, not global
 * exactly-once claims across customers/parallel sessions.
 */
final class RequestBasket
{
    private const SESSION_KEY='commerce_composition_request_basket_v1';
    private const MAX_LINES=100;
    public static function entries():array|\WP_Error
    {
        if(!function_exists('WC')||!WC()->session)
            return new \WP_Error('request_session_unavailable');
        $records=WC()->session->get(self::SESSION_KEY,[]);
        if(!is_array($records)||count($records)>self::MAX_LINES)
            return new \WP_Error('request_session_corrupt');
        foreach($records as $key=>$entry) {
            if(!is_string($key)||!preg_match('/^[0-9]+:[0-9]+$/D',$key)
                ||!is_array($entry)||($entry['schema']??null)!==1
                ||!is_string($entry['selection_digest']??null))
                return new \WP_Error('request_session_entry_invalid');
        }
        return $records;
    }
    public static function stage(array $intent):array|\WP_Error
    {
        if(($intent['schema']??null)!==1 ||
            ($intent['status']??null)!=='validated_intent_not_cart_session_committed' ||
            ($intent['mode']??null)!=='request_only' ||
            ($intent['cart_authority']??null)!==false ||
            ($intent['checkout_authority']??null)!==false ||
            !is_int($intent['form_id']??null)||$intent['form_id']<1 ||
            !is_int($intent['source_submission_id']??null)||$intent['source_submission_id']<1 ||
            !is_string($intent['selection_digest']??null)||
            !preg_match('/^[a-f0-9]{64}$/D',$intent['selection_digest']))
            return new \WP_Error('request_intent_not_trusted');
        $plan=$intent['composition']??null;
        if(!is_array($plan)||($plan['status']??null)!==
            'structure_and_component_evidence_only_not_checkout_authority' ||
            ($plan['intent']??null)!=='request')
            return new \WP_Error('request_component_plan_unverified');
        if(!function_exists('WC')||!WC()->session||
            !method_exists(WC()->session,'save_data'))
            return new \WP_Error('request_session_unavailable');
        $key=$intent['form_id'].':'.$intent['source_submission_id'];
        $current=self::entries();
        if(is_wp_error($current))return $current;
        if(isset($current[$key])){
            if(!hash_equals($current[$key]['selection_digest'],$intent['selection_digest']))
                return new \WP_Error('request_submission_replayed_with_changed_configuration');
            return ['status'=>'already_present','key'=>$key,'basket_count'=>count($current),
                'checkout_authority'=>false];
        }
        if(count($current)>=self::MAX_LINES)
            return new \WP_Error('request_basket_full');
        $entry=[
            'schema'=>1,'form_id'=>$intent['form_id'],
            'submission_id'=>$intent['source_submission_id'],
            'parent_id'=>$intent['source_parent_id']??0,
            'rate_id'=>$intent['source_rate_id']??null,
            'selection_digest'=>$intent['selection_digest'],
            'policy_revision'=>$intent['policy_revision']??null,
            'composition'=>$plan,'created_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
            'mode'=>'request_only','price_authority'=>false,
            'native_woocommerce_cart_line'=>false,'checkout_authority'=>false,
        ];
        // WooCommerce does NOT persist anonymous session data until a customer
        // session cookie exists. Without this, CLI/guest additions appeared
        // successful in memory but vanished on the next HTTP request.
        if(!WC()->session->has_session()) {
            if(headers_sent() || !method_exists(WC()->session,'set_customer_session_cookie'))
                return new \WP_Error('request_cannot_initialize_customer_session');
            WC()->session->set_customer_session_cookie(true);
            if(!WC()->session->has_session())
                return new \WP_Error('request_session_cookie_unavailable');
        }
        $current[$key]=$entry;
        WC()->session->set(self::SESSION_KEY,$current);
        WC()->session->save_data();
        $read=self::entries();
        if(is_wp_error($read)||!isset($read[$key])||
            $read[$key]['selection_digest']!==$intent['selection_digest'])
            return new \WP_Error('request_session_write_unverified');
        return ['status'=>'staged_request','key'=>$key,'basket_count'=>count($read),
            'checkout_authority'=>false];
    }
}
