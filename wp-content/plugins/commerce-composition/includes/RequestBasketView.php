<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Read-only customer request-basket presentation.
 * Never shares private Fluent Forms answers, supplier contracts or sale prices.
 */
final class RequestBasketView
{
    public static function bootstrap():void
    {
        add_action('rest_api_init',[self::class,'register']);
        add_shortcode('commerce_request_basket',[self::class,'shortcode']);
    }
    public static function snapshot():array|\WP_Error
    {
        if(!function_exists('WC')||!WC()->session)
            return new \WP_Error('request_session_unavailable');
        if(!WC()->session->has_session())
            return ['schema'=>1,'mode'=>'request_only','count'=>0,
                'lines'=>[],'can_checkout'=>false,'can_pay'=>false];
        $entries=RequestBasket::entries();
        if(is_wp_error($entries))return $entries;
        $lines=[];
        foreach($entries as $key=>$entry){
            $p=(int)($entry['parent_id']??0);
            $name=$p>0 && get_post_status($p)==='publish'?
                wp_strip_all_tags(get_the_title($p)):'Unavailable service';
            $components=[];
            foreach(($entry['composition']['components']??[]) as $child) {
                if(!is_array($child))continue;
                $id=(int)($child['source_id']??0);
                $components[]=[
                    'group'=>is_string($child['group']??null)?$child['group']:'',
                    'role'=>is_string($child['role']??null)?$child['role']:'',
                    'source_id'=>$id,
                    'name'=>$id>0 && get_post_status($id)==='publish'
                        ?wp_strip_all_tags(get_the_title($id)):'Unavailable component',
                ];
            }
            // Stable Fluent submission IDs and idempotency keys are internal.
            // Exposing them to a browser would leak original form-submission IDs.
            $lines[]=[
                'source_id'=>$p,'name'=>$name,
                'rate_id'=>(int)($entry['rate_id']??0),
                'components'=>$components,
                'price_state'=>'not_quoted',
                'request_state'=>isset($entry['submitted_request_id'])
                    ? 'submitted_for_review':'in_request_basket',
                'request_in_session'=>true,
            ];
        }
        return ['schema'=>1,'mode'=>'request_only','count'=>count($lines),
            'lines'=>$lines,'can_checkout'=>false,'can_pay'=>false];
    }
    public static function register():void
    {
        register_rest_route('commerce-composition/v1','/request-basket',[
            'methods'=>\WP_REST_Server::READABLE,
            'callback'=>[self::class,'read'],
            'permission_callback'=>'__return_true',
        ]);
    }
    public static function read(\WP_REST_Request $request):\WP_REST_Response|\WP_Error
    {
        $snapshot=self::snapshot();
        if(is_wp_error($snapshot))
            return new \WP_Error('request_basket_not_available',
                'Request basket is not available',['status'=>503]);
        $response=new \WP_REST_Response($snapshot,200);
        $response->header('Cache-Control','private, no-store, must-revalidate');
        return $response;
    }
    public static function shortcode($atts=[]):string
    {
        $data=self::snapshot();
        if(is_wp_error($data))
            return '<p>'.esc_html__('Request basket is unavailable.','woocommerce').'</p>';
        $out='<section class="commerce-request-basket" aria-label="'.
            esc_attr__('Booking requests','woocommerce').'">';
        $out.='<h3>'.esc_html__('Booking requests','woocommerce').'</h3>';
        $out.='<p>'.esc_html__('Requests only — prices and bookings are not yet confirmed. No payment will be collected here.','woocommerce').'</p>';
        if(!$data['lines'])$out.='<p>'.esc_html__('No booking requests have been added.','woocommerce').'</p>';
        else {
            $out.='<ul>';
            foreach($data['lines'] as $line){
                $out.='<li><strong>'.esc_html($line['name']).'</strong>';
                if($line['components']){
                    $out.='<ul>';
                    foreach($line['components'] as $component)
                        $out.='<li>'.esc_html($component['name']).'</li>';
                    $out.='</ul>';
                }
                $out.='</li>';
            }
            $out.='</ul>';
        }
        return $out.'</section>';
    }
}
