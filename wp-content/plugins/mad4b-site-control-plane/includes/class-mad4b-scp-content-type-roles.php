<?php
/**
 * Shared, fail-closed Content Type Roles for site-neutral optional providers.
 *
 * Company-specific post types must be explicitly bound after Site Profile
 * enrollment. No business slug is inferred from plugin source or site URL.
 * This file has no hooks, bootstrap, database writes or network effects.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Content_Type_Roles {
    private static function allowed_roles() {
        return array('tour', 'property', 'transport');
    }

    public static function get($role) {
        if (!is_string($role) || !in_array($role, self::allowed_roles(), true) ||
            !function_exists('apply_filters') || !function_exists('get_post_type_object') ||
            !class_exists('MAD4B_SCP_Site_Profile', false) ||
            !MAD4B_SCP_Site_Profile::origin_enrolled() ||
            !MAD4B_SCP_Site_Profile::site_urls_match_enrollment()) {
            return '';
        }
        $roles = apply_filters('mad4b_scp_content_type_roles', array());
        if (!is_array($roles) || !isset($roles[$role]) || !is_string($roles[$role])) return '';
        $type = $roles[$role];
        if (!preg_match('/^[a-z][a-z0-9_-]{0,19}$/D', $type)) return '';
        $object = get_post_type_object($type);
        if (!is_object($object) || empty($object->public) ||
            !isset($object->_builtin) || false !== $object->_builtin) return '';
        return $type;
    }
}
