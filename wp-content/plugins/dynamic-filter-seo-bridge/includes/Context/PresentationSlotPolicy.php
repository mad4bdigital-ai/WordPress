<?php
namespace DynamicFilterSEOBridge\Context;

/** Portable display intent; Elementor keeps layout and design authority. */
final class PresentationSlotPolicy {
    public static function forContext(array $context): array {
        $kind=(string)($context['page_kind']??'unresolved');
        $slots=array('contract'=>'dfsb.presentation-slots.v1','read_only'=>true,
            'authorizing'=>false,'placement'=>'none','roles'=>array(),
            'pricing_surface'=>'native_provider_only','style_authority'=>'elementor_global_kit',
            'injects_content'=>false);
        if(in_array($kind,array('posts_home','post_type_archive','taxonomy','shop_archive'),true)){
            $slots['placement']='after_listing';
            $slots['roles']=array('native_hero_heading','optional_dynamic_intro',
                'archive_results','optional_editorial_below_results');
        }elseif($kind==='singular'){
            $slots['placement']='native_template';
            $slots['roles']=array('native_single_heading','native_primary_content',
                'optional_seo_gap_only');
            if(($context['post_type']??'')==='product')$slots['roles'][]='native_woocommerce_product_info_and_price';
            if(($context['post_type']??'')==='tours-and-activities'){
                $slots['roles'][]='native_rate_list_owned_by_pricing_provider';
                $slots['roles'][]='native_transport_components_owned_by_provider';
            }
        }
        return $slots;
    }
}
