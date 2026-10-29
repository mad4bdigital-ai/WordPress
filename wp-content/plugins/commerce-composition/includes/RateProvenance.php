<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Rate-to-parent ownership is proven from trusted typed CPT fields.
 * The per-site mapping is data, never a fixed CPT name or company prefix.
 */
final class RateProvenance
{
    public static function registry(): array|\WP_Error
    {
        $key=apply_filters('commerce_composition_rate_registry_option_key','commerce_composition_rate_relations');
        if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_-]{0,119}$/D',$key))
            return new \WP_Error('rate_registry_key_invalid');
        $data=apply_filters('commerce_composition_rate_registry',get_option($key,[]));
        if(!is_array($data)||($data['schema']??null)!==1 ||
            !is_array($data['definitions']??null)||!array_is_list($data['definitions']) ||
            count($data['definitions'])>200)
            return new \WP_Error('rate_registry_unconfigured');
        return $data;
    }
    public static function verify(int $parentId,int $rateId): bool|\WP_Error
    {
        if($parentId<1||$rateId<1 || get_post_status($parentId)!=='publish' ||
            get_post_status($rateId)!=='publish')
            return new \WP_Error('rate_source_not_published');
        $parentType=get_post_type($parentId);
        $rateType=get_post_type($rateId);
        if(!is_string($parentType)||!is_string($rateType))
            return new \WP_Error('rate_source_type_invalid');
        $registry=self::registry();
        if(is_wp_error($registry))return $registry;
        $matched=[];
        foreach($registry['definitions'] as $rule){
            if(!is_array($rule))return new \WP_Error('rate_registry_definition_invalid');
            $keys=['rate_post_type','parent_post_type','parent_meta'];
            foreach($keys as $key){
                if(!isset($rule[$key])||!is_string($rule[$key])||
                    !preg_match('/^_?[a-zA-Z][a-zA-Z0-9_-]{0,119}$/D',$rule[$key]))
                    return new \WP_Error('rate_registry_definition_invalid');
            }
            $identity=$rule['rate_post_type'].'|'.$rule['parent_post_type'];
            if(isset($matched[$identity]))return new \WP_Error('rate_registry_duplicate');
            $matched[$identity]=true;
            if($rule['rate_post_type']!==$rateType||$rule['parent_post_type']!==$parentType)
                continue;
            $linked=get_post_meta($rateId,$rule['parent_meta'],true);
            if(!is_int($linked) && !(is_string($linked)&&preg_match('/^[1-9][0-9]*$/D',$linked)))
                return new \WP_Error('rate_parent_field_invalid');
            if((int)$linked!==$parentId)return new \WP_Error('rate_parent_mismatch');
            return true;
        }
        return new \WP_Error('rate_mapping_unavailable');
    }
}
