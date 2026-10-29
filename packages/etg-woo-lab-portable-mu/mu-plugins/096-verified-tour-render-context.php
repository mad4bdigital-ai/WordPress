<?php
/** Verified parent Tour authority for the native Rate AJAX rendering lifecycle. */
defined('ABSPATH') || exit;

final class Shared_Product_Verified_Tour_Render_Context_v1 {
    private static array $stack=[];
    public static function resolve(array $request, string $referer, string $host): ?array {
        if(($request['action']??'')!=='jet_smart_filters' || !function_exists('jet_smart_filters'))return null;
        $provider=$request['provider']??null;
        if(!is_string($provider)||!in_array($provider,['jet-engine/tour_rates','jet-engine/tour_rates_accordion'],true))return null;
        $settings=$request['settings']??null;
        if(!is_array($settings)||!is_string($settings['jsf_signature']??null)||$settings['jsf_signature']==='')return null;
        $signature=$settings['jsf_signature'];unset($settings['jsf_signature']);
        $render=jet_smart_filters()->render;
        $expected=$render->create_signature($render->prepare_provider_settings_for_signature($settings,'jet-engine'));
        if(!hash_equals($expected,$signature))return null;
        $raw=$settings['queried_id']??null;
        if(!is_scalar($raw)||!ctype_digit((string)$raw)||!class_exists('Shared_Product_Presentation_Context_v1'))return null;
        $tour=(int)$raw;
        if(get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')return null;
        $context=Shared_Product_Presentation_Context_v1::resolve_referer($tour,$referer,$host);
        if(empty($context['ok'])||$provider!=='jet-engine/'.($context['provider_id']??'')
            ||(int)($settings['lisitng_id']??0)!==(int)($context['listing_id']??0)
            ||($settings['_element_id']??'')!==$context['provider_id'])return null;
        return $context+['provider'=>$provider];
    }
    public static function begin():void {
        $context=null;
        if(wp_doing_ajax() && ($_REQUEST['action']??'')==='jet_smart_filters') {
            $request=jet_smart_filters()->data->get_request();
            if(is_array($request))$context=self::resolve($request,(string)wp_get_referer(),(string)($_SERVER['HTTP_HOST']??''));
        }
        self::$stack[]=$context;
    }
    public static function end():void {array_pop(self::$stack);}
    public static function current():?array {
        if(!self::$stack)return null;
        $context=self::$stack[count(self::$stack)-1];
        return is_array($context)?$context:null;
    }
}
function shared_product_verified_presentation_tour_id():int {
    if(wp_doing_ajax())return (int)(Shared_Product_Verified_Tour_Render_Context_v1::current()['tour_id']??0);
    global $wp_the_query;
    if(!$wp_the_query instanceof WP_Query || !$wp_the_query->is_singular('tours-and-activities'))return 0;
    $tour=$wp_the_query->get_queried_object();
    return $tour instanceof WP_Post && $tour->post_type==='tours-and-activities' && $tour->post_status==='publish' ? (int)$tour->ID : 0;
}
add_action('jet-smart-filters/render/ajax/before',[Shared_Product_Verified_Tour_Render_Context_v1::class,'begin'],1,0);
add_filter('jet-smart-filters/render/ajax/data',static function($data){Shared_Product_Verified_Tour_Render_Context_v1::end();return $data;},PHP_INT_MAX);
