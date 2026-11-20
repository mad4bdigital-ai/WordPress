<?php
defined('ABSPATH') || exit;

/**
 * Resolves human-readable help from the JetEngine field definition.
 * Never reads or writes field values, and never writes translated strings on page load.
 */
final class MAD4B_JetEngine_Guidance_V1 {
    public const CONTEXT = 'MAD4B JetEngine Guidance';
    // General annotation form: tenant/project name + environment marker.
    public const ENV_PREFIX_PATTERN =
        '/\A(?:[\p{Lu}][\p{L}\p{N}._-]{1,24}(?:\h+[\p{Lu}][\p{L}\p{N}._-]{1,24}){0,2})\h+(?i:lab|demo|staging|production|test|testing|dev|sandbox|preview)\h*:\h*/u';

    public static function strip_brand_from_admin_translation($value) {
        if (!is_string($value) || $value === '') return $value;
        $stripped = preg_replace(self::ENV_PREFIX_PATTERN, '', $value, 1);
        return is_string($stripped) ? $stripped : $value;
    }


    public static function normalize($value): string {
        if (!is_string($value) || $value === '') return '';
        // Help is plain text. Never send an executable HTML description to JS.
        $value = wp_strip_all_tags($value, true);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
        $value = str_replace("\r", '', $value);
        $value = trim($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, 3000) : substr($value, 0, 3000);
    }

    /**
     * Remove environment/tenant labels before guidance is displayed.
     * Matches generic prefixes such as "ABC lab:" or "Project staging:".
     * Never strips brand mentions from normal sentences or internal field keys.
     */
    public static function neutral_source_label($value): string {
        $text = self::normalize($value);
        if ($text === '') return '';
        return self::normalize(self::strip_brand_from_admin_translation($text));
    }

    private static function translate(string $text, string $slug, string $field, string $key): string {
        if ($text === '') return '';
        if ($key === 'jetengine') {
            // Use the identical translation filter JetEngine itself uses for admin labels.
            $translated = apply_filters('jet-engine/compatibility/translate-string', $text);
        } else {
            // Site overrides/context are explicit WPML strings, registered only when edited.
            $language = apply_filters('wpml_current_language', null);
            $translated = apply_filters('wpml_translate_single_string', $text, self::CONTEXT,
                $slug . '/' . $field . '/' . $key, $language);
        }
        return $key === 'jetengine'
            ? self::neutral_source_label(is_string($translated) ? $translated : $text)
            : self::normalize(is_string($translated) ? $translated : $text);
    }

    public static function register_configured_strings(string $slug, string $field, array $values): void {
        foreach (['override', 'context'] as $kind) {
            $key = 'guidance_' . $kind;
            $text = self::normalize($values[$key] ?? '');
            if ($text !== '') do_action('wpml_register_single_string', self::CONTEXT,
                $slug . '/' . $field . '/' . $kind, $text);
        }
    }

    public static function resolve(string $slug, array $field, array $local): array {
        $name = (string)($field['name'] ?? '');
        $source = self::neutral_source_label($field['description'] ?? '');
        $override = self::normalize($local['guidance_override'] ?? '');
        $extra = self::normalize($local['guidance_context'] ?? '');
        $primary = $override !== ''
            ? self::translate($override, $slug, $name, 'override')
            : self::translate($source, $slug, $name, 'jetengine');
        $context = self::translate($extra, $slug, $name, 'context');
        if ($context !== '' && $context === $primary) $context = '';
        $result = [
            'text' => $primary,
            'context' => $context,
            'source' => $override !== '' ? 'company' : ($source !== '' ? 'jetengine' : 'none'),
            'has_native_description' => ($source !== ''),
        ];
        $custom = apply_filters('mad4b/jetengine/guidance_resolved', $result, $slug, $field, $local);
        if (is_array($custom)) {
            $result['text'] = self::normalize($custom['text'] ?? $result['text']);
            $result['context'] = self::normalize($custom['context'] ?? $result['context']);
            $candidate = (string)($custom['source'] ?? $result['source']);
            if (in_array($candidate, ['company', 'jetengine', 'provider', 'none'], true)) {
                $result['source'] = $candidate;
            }
        }
        $result['available'] = $result['text'] !== '' || $result['context'] !== '';
        return $result;
    }
}
