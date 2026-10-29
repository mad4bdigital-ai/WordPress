<?php
namespace DynamicFilterSEOBridge\Context;

/** Native WooCommerce page/provider identity. No cart or product mutation. */
final class WooCommerceContextResolver {
    const CONTRACT='dfsb.woocommerce-context.v1';
    public static function available(): bool {
        return class_exists('WooCommerce') && function_exists('wc_get_page_id')
            && post_type_exists('product');
    }
    public static function pageId(string $role): int {
        if(!self::available() || !in_array($role,array('shop','cart','checkout','myaccount'),true))return 0;
        return max(0,(int)wc_get_page_id($role));
    }
    public static function transactionRole(int $postId): string {
        if(!self::available()||$postId<=0)return '';
        foreach(array('cart','checkout','myaccount') as $role){
            if(self::pageId($role)===$postId)return $role;
        }
        return '';
    }
    public static function restrictedPage(int $postId): bool {
        return self::transactionRole($postId)!=='';
    }
    public static function shop(): array {
        if(!self::available()||!PageContextResolver::publicType('product'))
            return array('page_kind'=>'unresolved','read_only'=>true,'authorizing'=>false);
        $id=self::pageId('shop');
        $page=$id?get_post($id):null;
        $out=PageContextResolver::forArchive('product');
        if(($out['page_kind']??'')==='unresolved')return $out;
        $out['page_kind']='shop_archive';
        $out['commerce_surface']='shop';
        $out['object_id']=$id;
        if($page && $page->post_status==='publish'){
            $out['title']=\DynamicFilterSEOBridge\SEO\SiteSeoSources::plain($page->post_title,150);
            $out['description']=\DynamicFilterSEOBridge\SEO\SiteSeoSources::plain($page->post_excerpt,260);
        }
        return $out;
    }
    /** Registered Woo product types are not equivalent to CPTs. */
    public static function catalog(): array {
        $out=array('contract'=>self::CONTRACT,'read_only'=>true,
            'available'=>self::available(),'shop_id'=>0,
            'published_products'=>0,'draft_products'=>0,
            'registered_product_types'=>array(),'public_product_taxonomies'=>array());
        if(!$out['available'])return $out;
        $out['shop_id']=self::pageId('shop');
        $counts=wp_count_posts('product');
        $out['published_products']=(int)($counts->publish??0);
        $out['draft_products']=(int)($counts->draft??0);
        $out['registered_product_types']=array_keys((array)wc_get_product_types());
        foreach(get_object_taxonomies('product') as $taxonomy){
            $tax=get_taxonomy($taxonomy);
            if($tax && !empty($tax->public))$out['public_product_taxonomies'][]=$taxonomy;
        }
        return $out;
    }
    public static function forProduct(int $productId): array {
        $result=array('contract'=>self::CONTRACT,'status'=>'unresolved',
            'read_only'=>true,'authorizing'=>false,'post_id'=>$productId,
            'product_type'=>'','type_registered'=>false,
            'price_provider'=>'unresolved','availability_provider'=>'unresolved',
            'can_quote'=>false,'can_book'=>false,'can_mutate'=>false);
        if(!self::available()||$productId<=0||get_post_type($productId)!=='product'
            ||get_post_status($productId)!=='publish'||!function_exists('wc_get_product'))return $result;
        $product=wc_get_product($productId);
        if(!$product instanceof \WC_Product || (int)$product->get_id()!==$productId)return $result;
        $type=(string)$product->get_type();
        $registered=(array)wc_get_product_types();
        $result['product_type']=$type;
        $result['type_registered']=isset($registered[$type]);
        if(!$result['type_registered']){
            $result['status']='unrecognized_product_type';
            return $result;
        }
        // WooCommerce get_type() is identity only. We DO NOT read price,
        // stock, purchasability, or call quote/checkout operations here.
        $result['status']='native_product_verified_read_only';
        $result['price_provider']='woocommerce_product_data_store';
        $result['availability_provider']='woocommerce_stock_rules';
        return $result;
    }
}
