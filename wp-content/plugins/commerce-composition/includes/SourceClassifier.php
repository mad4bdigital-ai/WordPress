<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * WordPress source classifier. Registry comes from a server-owned options page or
 * a trusted provider callback. Post/Taxonomy registration and assigned term slugs
 * determine the result, never display text or client-submitted "product_kind".
 */
final class SourceClassifier
{
    public static function registry(): array|\WP_Error
    {
        $key=apply_filters('commerce_composition_classifier_option_key','commerce_composition_classification_rules');
        if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_-]{0,119}$/D',$key))
            return new \WP_Error('classification_option_key_invalid');
        $rules=get_option($key,[]);
        $rules=apply_filters('commerce_composition_classification_rules',$rules);
        if(!is_array($rules)||($rules['schema']??null)!==1)
            return new \WP_Error('classification_registry_unconfigured');
        return $rules;
    }
    public static function kindsFor(int $id): array|\WP_Error
    {
        $type=get_post_type($id);
        if($id<1||!is_string($type)||get_post_status($id)!=='publish')
            return new \WP_Error('classification_post_unavailable');
        $registry=self::registry();
        if(is_wp_error($registry))return $registry;
        try {
            return Classification::kinds(
                $registry,$type,
                static function(string $taxonomy) use($id,$type):array {
                    if(!taxonomy_exists($taxonomy)||!is_object_in_taxonomy($type,$taxonomy))
                        return [];
                    $terms=wp_get_object_terms($id,$taxonomy,['fields'=>'all']);
                    if(is_wp_error($terms))return [];
                    $slugs=[];
                    foreach($terms as $term)if($term instanceof \WP_Term)
                        $slugs[]=sanitize_title($term->slug);
                    return $slugs;
                },
                static fn(string $key)=>get_post_meta($id,$key,true)
            );
        } catch(\DomainException $exception){
            return new \WP_Error('classification_registry_invalid',$exception->getMessage());
        } catch(\Throwable $exception){
            return new \WP_Error('classification_provider_failed');
        }
    }
    public static function hasKind(int $id, string $kind): bool|\WP_Error
    {
        $kinds=self::kindsFor($id);
        if(is_wp_error($kinds))return $kinds;
        return in_array($kind,$kinds,true);
    }
}
