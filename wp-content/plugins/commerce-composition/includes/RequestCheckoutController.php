<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Optional nonpayment request Checkout form. No public REST mutation is
 * registered; this frontend POST is guarded by a time-limited session-specific
 * CSRF token. Owner opt-in required, OFF by default.
 */
final class RequestCheckoutController
{
    private const OUTCOME_KEY='commerce_request_checkout_outcome_v1';

    public static function bootstrap():void
    {
        add_action('template_redirect',[self::class,'handlePost'],5);
        add_shortcode('commerce_request_submit',[self::class,'shortcode']);
        // Backward-compatible alias for the previous QA-only name.
        add_shortcode('commerce_request_checkout',[self::class,'shortcode']);
    }

    private static function enabled():bool
    {
        return apply_filters('commerce_composition_enable_request_checkout',false)===true;
    }
    private static function customerId():string
    {
        if(!function_exists('WC')||!WC()->session||!WC()->session->has_session())
            return '';
        return (string)WC()->session->get_customer_id();
    }
    private static function mac(string $id,int $bucket):string
    {
        return hash_hmac('sha256',
            'nonfinancial-request-checkout|'.$id.'|'.$bucket,
            wp_salt('nonce'));
    }
    public static function token():string|\WP_Error
    {
        if(!self::enabled())return new \WP_Error('request_checkout_disabled');
        $id=self::customerId();
        if($id==='')return new \WP_Error('request_checkout_session_absent');
        $bucket=(int)floor(time()/600);
        return $bucket.'.'.self::mac($id,$bucket);
    }
    public static function verify(string $token):bool
    {
        if(!self::enabled()||self::customerId()===''||
            !preg_match('/^([0-9]{1,12})\.([a-f0-9]{64})$/D',$token,$m))
            return false;
        $sent=(int)$m[1];
        $current=(int)floor(time()/600);
        if($sent>$current||$sent<$current-1)return false;
        return hash_equals(self::mac(self::customerId(),$sent),$m[2]);
    }
    public static function submit(string $token):array|\WP_Error
    {
        if(!self::verify($token))
            return new \WP_Error('request_checkout_invalid_session_token');
        return RequestCheckout::commit();
    }
    public static function handlePost():void
    {
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||
            !isset($_POST['commerce_request_checkout_submit']))
            return;
        if(!self::enabled()) {
            wp_die(esc_html__('Request Checkout is currently unavailable.','woocommerce'),'',403);
        }
        $token=isset($_POST['request_token'])&&is_string($_POST['request_token'])
            ?sanitize_text_field(wp_unslash($_POST['request_token'])):'';
        $outcome=self::submit($token);
        if(function_exists('WC')&&WC()->session){
            WC()->session->set(self::OUTCOME_KEY,[
                'status'=>is_wp_error($outcome)?'failed':
                    (($outcome['status']??'')==='already_submitted'?'already_submitted':'submitted'),
                // No customer PII or source submission ID in the message.
                'error_code'=>is_wp_error($outcome)?$outcome->get_error_code():null
            ]);
            WC()->session->save_data();
        }
        $url=wp_get_referer();
        if(!is_string($url)||$url==='')$url=home_url('/');
        wp_safe_redirect(remove_query_arg(['request_checkout_submit'],$url),303);
        exit;
    }
    public static function shortcode($attributes=[]):string
    {
        if(!self::enabled())return '';
        $result='<section class="commerce-request-checkout">';
        $last=function_exists('WC')&&WC()->session
            ?WC()->session->get(self::OUTCOME_KEY,null):null;
        if(is_array($last)){
            if(($last['status']??'')==='submitted')
                $message=__('Booking request submitted for review. This is not a confirmed booking and no payment was taken.','woocommerce');
            elseif(($last['status']??'')==='already_submitted')
                $message=__('This booking request was already submitted. No payment was taken.','woocommerce');
            else $message=__('The request could not be submitted for review. It remains in your request basket. No payment was taken.','woocommerce');
            $result.='<p role="status">'.esc_html($message).'</p>';
        }
        $basket=RequestBasket::entries();
        $pending=0;
        if(is_array($basket)) {
            foreach($basket as $entry)
                if(!isset($entry['submitted_request_id']))$pending++;
        }
        $token=self::token();
        if($pending>0&&!is_wp_error($token)){
            nocache_headers();
            $result.='<p>'.esc_html__('Submit your booking requests for review without payment. Prices and availability are not confirmed.','woocommerce').'</p>';
            $result.='<form method="post">';
            $result.='<input type="hidden" name="request_token" value="'.esc_attr($token).'">';
            $result.='<button type="submit" name="commerce_request_checkout_submit" value="1">'.
                esc_html__('Submit booking request for review','woocommerce').'</button>';
            $result.='</form>';
        }
        return $result.'</section>';
    }
}
