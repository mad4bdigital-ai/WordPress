<?php
namespace DynamicFilterSEOBridge\IndexFlow;

/**
 * WPML-owned localized URL segments for BOTH CPT and taxonomy rewrite bases.
 * Read-only; never writes WPML strings, adds rewrites, changes permalinks, or
 * creates SEO publication authority.
 */
final class LocalizedRewriteBase {
    public const CONTRACT = 'indexflow.wpml-rewrite-base.v1';

    public static function inspect(string $kind, string $name, string $locale): array {
        $kind = strtolower(trim($kind));
        $name = sanitize_key($name);
        $locale = sanitize_key($locale);
        $type = $kind === 'taxonomy' ? 'taxonomy' : 'post';
        if (!in_array($kind, array('taxonomy','post_type'), true) || !$name || !$locale) {
            return self::denied($kind,$name,$locale,'invalid_rewrite_subject');
        }
        $object = $kind === 'taxonomy' ? get_taxonomy($name) : get_post_type_object($name);
        if (!$object || empty($object->public) || !is_array($object->rewrite)
            || empty($object->rewrite['slug'])) {
            return self::denied($kind,$name,$locale,'native_rewrite_unavailable');
        }
        $source = trim((string)$object->rewrite['slug'],'/');
        if (!self::validPath($source)) return self::denied($kind,$name,$locale,'invalid_native_rewrite_base');
        $translationEnabled = false;
        $translated = $source;
        $verified = false;
        $provider = 'wordpress_permalink_settings';
        if (has_filter('wpml_object_id')) {
            $resolver = new \DynamicFilterSEOBridge\WPML\LanguageResolver();
            $default = $resolver->defaultLanguage();
            $active = $resolver->activeLanguages();
            if (!$default || !isset($active[$locale])) return self::denied($kind,$name,$locale,'wpml_locale_not_active');
            if (!has_filter('wpml_type_slug_is_translated') || !has_filter('wpml_get_translated_slug'))
                return self::denied($kind,$name,$locale,'wpml_slug_provider_unavailable');
            $translationEnabled = (bool)apply_filters('wpml_type_slug_is_translated', false, $name, $type);
            if ($locale === $default) {
                $verified = true;
            } elseif ($translationEnabled) {
                // WPML returns the original slug on a missing translation;
                // check explicit COMPLETE status rather than string inequality.
                $available = apply_filters('wpml_get_slug_translation_languages', array(), $name, $type);
                if (!is_array($available) || !in_array($locale,$available,true)) {
                    return self::denied($kind,$name,$locale,'wpml_base_translation_missing',
                        array('source_base'=>$source,'translation_enabled'=>true));
                }
                $translated = apply_filters('wpml_get_translated_slug', $source, $name, $locale, $type);
                if (!is_string($translated) || !self::validPath(trim($translated,'/'))) {
                    return self::denied($kind,$name,$locale,'wpml_base_translation_invalid');
                }
                $translated = trim($translated,'/');
                $verified = true;
                $provider = 'wpml_string_translation';
            } else {
                // Explicitly disabled in WPML: keep its native URL unchanged,
                // but never call that a verified localized translation.
                $provider = 'wpml_native_base_reuse';
            }
        } else {
            $registered = strtolower(str_replace('_','-',(string)get_locale()));
            if ($locale !== $registered && $locale !== substr($registered,0,2))
                return self::denied($kind,$name,$locale,'locale_not_available');
            $verified = true;
        }
        return array(
            'ready'=>true,'reason'=>$verified?'ok':'wpml_base_translation_disabled',
            'kind'=>$kind,'name'=>$name,'locale'=>$locale,
            'source_base'=>$source,'localized_base'=>$translated,
            'translation_enabled'=>$translationEnabled,
            'translation_verified'=>$verified,'provider'=>$provider,
            'authorizing'=>false,
        );
    }

    /** Refuse unverified translations on SEO published routes. */
    public static function requireLocalized(string $kind, string $name, string $locale): string {
        $record=self::inspect($kind,$name,$locale);
        if (empty($record['ready']) || empty($record['translation_verified'])) {
            throw new \InvalidArgumentException((string)($record['reason']??'unverified_permalink_translation'));
        }
        return (string)$record['localized_base'];
    }

    public static function archive(string $postType, string $locale): array {
        $base=self::inspect('post_type',$postType,$locale);
        if (empty($base['ready'])) return $base;
        $resolver=new \DynamicFilterSEOBridge\WPML\LanguageResolver();
        $read=$resolver->executeInLanguage($locale,static function () use ($postType) {
            return get_post_type_archive_link($postType);
        });
        $url=$read['result']??null;
        if (empty($read['available']) || !is_string($url) || !filter_var($url,FILTER_VALIDATE_URL)) {
            return self::denied('post_type',$postType,$locale,'native_archive_url_unavailable');
        }
        $base['archive_url']=trailingslashit($url);
        return $base;
    }

    private static function denied(string $kind,string $name,string $locale,string $reason,array $extra=array()): array {
        return array_merge(array('ready'=>false,'reason'=>$reason,'kind'=>$kind,
            'name'=>$name,'locale'=>$locale,'authorizing'=>false),$extra);
    }

    private static function validPath(string $value): bool {
        if (strlen($value)>200 || !$value || strpos($value,'//')!==false
            || strpos($value,'..')!==false || preg_match('~[\\\\?#\s]~u',rawurldecode($value))) return false;
        foreach(explode('/',$value) as $segment) {
            if (!$segment || sanitize_title($segment)!==$segment) return false;
        }
        return true;
    }
}
