<?php
namespace DynamicFilterSEOBridge\Context;

/** Provider capability projection. Discovery never grants commercial authority. */
final class ProviderContextResolver {
    public const CONTRACT='dfsb.provider-projection.v1';
    private static function item(string $id,string $status,string $source): array {
        return array('provider_id'=>$id,'status'=>$status,'source'=>$source,
            'read_only'=>true,'authorizing'=>false,'can_quote'=>false,
            'can_book'=>false,'can_mutate'=>false);
    }
    public static function forContext(array $context): array {
        $type=(string)($context['post_type']??'');
        $kind=(string)($context['page_kind']??'unresolved');
        $result=array('contract'=>self::CONTRACT,'read_only'=>true,'authorizing'=>false,'related_components'=>array(),
            'content'=>self::item('unresolved','unresolved','none'),
            'filter'=>self::item('unresolved','unbound','none'),
            'presentation'=>self::item('elementor','available_unbound','native_elementor'),
            'pricing'=>self::item('unresolved','not_evaluated','no_commercial_projection'),
            'availability'=>self::item('unresolved','not_evaluated','no_commercial_projection'),
            'booking'=>self::item('unresolved','not_evaluated','no_commercial_projection'),
            'activation'=>self::item('unresolved','not_evaluated','service_activation_owns_policy'));
        if(in_array($kind,array('unresolved','commerce_transaction'),true)
            || ($type!=='' && !PageContextResolver::publicType($type)))return $result;
        $result['content']=self::item(
            ($type==='product' && WooCommerceContextResolver::available()?'woocommerce_products':($type==='post'?'wordpress_posts':(function_exists('jet_engine')?'jetengine_cpt':'wordpress_cpt'))),
            'resolved','wordpress_registered_post_type');
        if($kind==='taxonomy'){
            $result['content']=self::item('wordpress_taxonomy','resolved','get_term / get_taxonomy');
            $result['content']['taxonomy']=(string)($context['taxonomy']??'');
        }
        if(in_array($kind,array('post_type_archive','posts_home','taxonomy','shop_archive'),true)){
            $result['filter']=self::item(function_exists('jet_smart_filters')?'jet_smart_filters':'wordpress_query',
                'available_unbound','native_query_provider_requires_verified_elementor_binding');
        }
        if($kind==='singular' && $type==='product' && WooCommerceContextResolver::available()){
            $verified=WooCommerceContextResolver::forProduct((int)($context['post_id']??0));
            if(($verified['status']??'')==='native_product_verified_read_only'){
                $price=self::item((string)$verified['price_provider'],
                    'verified_owner_only','WooCommerce native product');
                $price['product_type']=(string)$verified['product_type'];
                $result['pricing']=$price;
                $stock=self::item((string)$verified['availability_provider'],
                    'owner_identified_not_checked','WooCommerce stock/purchasability rules');
                $result['availability']=$stock;
            }
        }
        if($kind==='singular' && $type==='tours-and-activities'){
            // Reuse the existing company/provider verified read-only binding.
            // Do not call a Rate calculator or checkout scenario resolver here.
            $id=(int)($context['post_id']??0);
            if($id>0 && class_exists('Shared_Product_Presentation_Context_v1')
                && is_callable(array('Shared_Product_Presentation_Context_v1','inspect'))){
                $binding=\Shared_Product_Presentation_Context_v1::inspect($id);
                $b=is_array($binding)?($binding['binding']??array()):array();
                if(is_array($binding) && !empty($binding['ok']) && is_array($b) && !empty($b['ok'])
                    && ($b['slot']??'')==='tour.rate_list'
                    && in_array((string)($b['provider_id']??''),array('tour_rates','tour_rates_accordion'),true)
                    && (int)($binding['tour_id']??0)===$id
                    && empty($binding['can_mutate']) && empty($binding['authorized_for_booking'])){
                    $provider=self::item((string)$b['provider_id'],'verified_read_only','Shared_Product_Presentation_Context_v1');
                    $provider['slot']='tour.rate_list';
                    $provider['query_id']=absint($b['query_id']??0);
                    $provider['listing_id']=absint($b['listing_id']??0);
                    $provider['template_id']=absint($b['template_id']??0);
                    $provider['display_mode']=(string)($binding['mode']??'unknown');
                    $result['pricing']=$provider;
                    $result['presentation']=$provider;
                }
            }
        }
        if($kind==='singular' && $type==='tours-and-activities'
            && class_exists('Shared_Product_Presentation_Transport_Context_v1')){
            // Read and validate native optional transport UI bindings. An
            // available presentation slot is NOT a purchasable service.
            foreach(\Shared_Product_Presentation_Transport_Context_v1::categories() as $key=>$component){
                $binding=\Shared_Product_Presentation_Transport_Context_v1::binding((string)$key);
                if(!is_array($binding)||empty($binding['ok'])||empty($binding['read_only'])
                    || !str_starts_with((string)($binding['slot']??''),'tour.transport.'))continue;
                $item=self::item((string)$binding['provider_id'],
                    'verified_presentation_only','Shared_Product_Presentation_Transport_Context_v1');
                $item['slot']=(string)$binding['slot'];
                $item['query_id']=absint($binding['query_id']??0);
                $item['listing_id']=absint($binding['listing_id']??0);
                $result['related_components'][]=$item;
            }
        }
        return $result;
    }
}
