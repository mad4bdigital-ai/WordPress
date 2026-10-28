<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Package options are eligible only if an actual trusted CPT field or a
 * trusted JetEngine relationship provider proves their parent/rate linkage.
 * A relationship proves choice eligibility, NOT inclusion or certified price.
 */
final class RelationProvenance
{
    public static function registry(): array|\WP_Error
    {
        $key=apply_filters('commerce_composition_relation_option_key','commerce_composition_relationships');
        if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_-]{0,119}$/D',$key))
            return new \WP_Error('relation_registry_key_invalid');
        $rules=apply_filters('commerce_composition_relation_registry',get_option($key,[]));
        if(!is_array($rules)||($rules['schema']??null)!==1 ||
            !is_array($rules['definitions']??null)||!array_is_list($rules['definitions']) ||
            count($rules['definitions'])>200)
            return new \WP_Error('relation_registry_unconfigured');
        return $rules;
    }
    public static function check(int $parentId,?int $rateId,string $childType,int $childId): bool|\WP_Error
    {
        if($parentId<1||$childId<1||get_post_status($parentId)!=='publish' ||
            get_post_status($childId)!=='publish'||get_post_type($childId)!==$childType)
            return new \WP_Error('relation_source_invalid');
        $raw=apply_filters('commerce_composition_relation_membership',null,
            $parentId,$rateId,$childType,$childId);
        if($raw!==null) {
            if(!is_bool($raw))return new \WP_Error('relation_provider_invalid');
            return $raw===true?true:new \WP_Error('relation_child_not_linked');
        }
        $registry=self::registry();
        if(is_wp_error($registry))return $registry;
        $seen=[];
        foreach($registry['definitions'] as $rule) {
            if(!is_array($rule))return new \WP_Error('relation_definition_invalid');
            $origin=$rule['origin']??null;
            $sourceType=$rule['source_post_type']??null;
            $field=$rule['field']??null;
            $target=$rule['child_post_type']??null;
            if(!in_array($origin,['parent','rate'],true) ||
               !is_string($sourceType)||!preg_match('/^[a-z][a-z0-9_-]{0,99}$/D',$sourceType)||
               !is_string($target)||!preg_match('/^[a-z][a-z0-9_-]{0,99}$/D',$target)||
               !is_string($field)||!preg_match('/^_?[a-zA-Z][a-zA-Z0-9_-]{0,119}$/D',$field))
                return new \WP_Error('relation_definition_invalid');
            $identity=$origin.'|'.$sourceType.'|'.$field.'|'.$target;
            if(isset($seen[$identity]))return new \WP_Error('relation_duplicate_definition');
            $seen[$identity]=true;
            $sourceId=$origin==='parent'?$parentId:$rateId;
            if($sourceId===null||get_post_type($sourceId)!==$sourceType||$target!==$childType)
                continue;
            $value=get_post_meta($sourceId,$field,true);
            if(is_string($value))$value=maybe_unserialize($value);
            if(is_scalar($value)&&$value!=='')$value=[$value];
            if(!is_array($value)||count($value)>1000)continue;
            foreach($value as $entry) {
                if(is_scalar($entry)&&ctype_digit((string)$entry)&&(int)$entry===$childId)
                    return true;
            }
        }
        return new \WP_Error('relation_child_not_linked');
    }
}
