<?php
declare(strict_types=1);
namespace Commerce\Composition;
/** QA49. Identity-only, never a price/payment/booking authorization. */
final class NativeOrderItemIdentityResolver
{
    public const CONTRACT='commerce.native-order-item-identity.v1';
    private const M_ID='_commerce_native_source_id';
    private const M_TYPE='_commerce_native_source_type';
    private const M_PARENT='_commerce_native_parent_id';
    private const M_DIGEST='_commerce_native_relation_sha256';
    private static function err(string $code):\WC_Data_Exception {
        return new \WC_Data_Exception($code,'Unverified native CPT Order Item identity');
    }
    public static function contracts():array {
        $base=[
            'tours-and-activities'=>[null,null],
            'properties'=>[null,null],
            'transportations'=>[null,null],
            'cruises'=>[null,null],
            'tour-rates'=>['related_tour_id','tours-and-activities'],
            'property-rates'=>['prop_related_property_id','properties'],
            'transport-rates'=>['source_post_id','transportations'],
        ];
        $out=apply_filters('commerce_composition_native_order_item_contracts',$base);
        return is_array($out)?$out:$base;
    }
    private static function enabled(string $type):bool {
        $opts=get_option('cptwooint_settings',[]);
        return function_exists('cptwooint')
            &&class_exists(\TinySolutions\cptwooint\Helpers\Fns::class)
            &&is_array($opts)&&is_array($opts['selected_post_types']??null)
            &&array_key_exists($type,$opts['selected_post_types'])
            &&\TinySolutions\cptwooint\Helpers\Fns::is_supported($type);
    }
    public static function source(int $id):array {
        if($id<1)throw self::err('commerce_identity_source_invalid');
        $post=get_post($id);
        if(!$post || $post->post_status!=='publish')
            throw self::err('commerce_identity_source_not_published');
        $type=$post->post_type;
        if($type==='product')return ['id'=>$id,'type'=>$type,'parent'=>0,'native'=>true];
        if(!self::enabled($type))throw self::err('commerce_identity_type_not_enabled');
        $contract=self::contracts()[$type]??null;
        if(!is_array($contract)||count($contract)!==2||!array_is_list($contract))
            throw self::err('commerce_identity_type_contract_missing');
        [$field,$parentType]=$contract;
        $parent=0;
        if($field!==null){
            if(!is_string($field)||!preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$field)
                ||!is_string($parentType)||!preg_match('/^[a-z][a-z0-9_-]{2,63}$/D',$parentType))
                throw self::err('commerce_identity_contract_invalid');
            $raw=get_post_meta($id,$field,true);
            if(!is_scalar($raw)||!ctype_digit((string)$raw)||(int)$raw<1)
                throw self::err('commerce_identity_parent_missing');
            $parent=(int)$raw;
            $parentPost=get_post($parent);
            if(!$parentPost||$parentPost->post_type!==$parentType
                ||$parentPost->post_status!=='publish')
                throw self::err('commerce_identity_parent_mismatch');
        }elseif($parentType!==null)throw self::err('commerce_identity_contract_invalid');
        $product=wc_get_product($id);
        if(!($product instanceof \WC_Product)||(int)$product->get_id()!==$id)
            throw self::err('commerce_identity_woo_product_mismatch');
        return ['id'=>$id,'type'=>$type,'parent'=>$parent,'native'=>false];
    }
    private static function fingerprint(array $s):string {
        return hash('sha256',wp_json_encode([
            self::CONTRACT,home_url(),$s['id'],$s['type'],$s['parent']
        ],JSON_UNESCAPED_SLASHES));
    }
    private static function item(int $orderId,int $itemId):\WC_Order_Item_Product {
        if($orderId<1||$itemId<1)throw self::err('commerce_identity_item_id_invalid');
        $item=\WC_Order_Factory::get_order_item($itemId);
        if(!($item instanceof \WC_Order_Item_Product)
            ||(int)$item->get_order_id()!==$orderId)
            throw self::err('commerce_identity_item_order_mismatch');
        return $item;
    }
