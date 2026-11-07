<?php
/**
 * MAD4B Experience — canonical company runtime scope.
 *
 * Source of truth: this installation's own WordPress home option.
 * A WPML-translated home_url() is a display route, not an authorization scope.
 * Shared across Presentation and Visitor. No persistent caching or grants.
 */
defined('ABSPATH') || exit;

final class MAD4B_Experience_Site_Scope_V1 {
    public static function bound(): bool {
        $home = get_option('home', '');
        if (!is_string($home) || $home === '') return false;
        $home = rtrim(strtolower($home), '/');

        $parts = wp_parse_url($home);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])
            || !in_array($parts['scheme'], ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) return false;

        $environment = (string)wp_get_environment_type();
        // WordPress environments: local/development/staging/production.
        // Site identity must not be inferred from a hardcoded host or "staging".
        if ($environment === 'production' && $parts['scheme'] !== 'https')
            return false;
        if (defined('MAD4B_TENANT_ORIGIN')
            && $home !== rtrim(strtolower((string)MAD4B_TENANT_ORIGIN), '/'))
            return false;
        if (defined('MAD4B_TENANT_ENVIRONMENT')
            && $environment !== (string)MAD4B_TENANT_ENVIRONMENT)
            return false;

        if (defined('MAD4B_TENANT_SITE_UUID')) {
            $uuid = (string)MAD4B_TENANT_SITE_UUID;
            if (!wp_is_uuid($uuid)) return false;
            $profile = get_option('mad4b_scp_brand_context_profile_v1', []);
            if (is_array($profile) && isset($profile['site_uuid'])
                && $profile['site_uuid'] !== $uuid) return false;
            if (class_exists('MAD4B_SCP_Site_Profile', false)
                && method_exists('MAD4B_SCP_Site_Profile','site_uuid')
                && MAD4B_SCP_Site_Profile::site_uuid() !== $uuid)
                return false;
        }
        return true;
    }
}
