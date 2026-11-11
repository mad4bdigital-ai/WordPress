<?php
/**
 * Plugin Name: Local Partner Rights Write Hold
 * Description: Local-only reversible admission freeze until first-party
 * signed organization/company and agreement authorities are certified.
 */
if (!defined('ABSPATH') || wp_get_environment_type() !== 'local') {
    return;
}
if (!function_exists('partner_rights_local_write_hold_active')) {
    function partner_rights_local_write_hold_active(): bool {
        // A draft, simulated user capability, option or filter cannot turn
        // this hold off. Removing it needs a separately governed decision.
        return wp_get_environment_type() === 'local';
    }
}
if (!function_exists('partner_rights_local_protected_type')) {
    function partner_rights_local_protected_type($post_type): bool {
        return in_array($post_type, ['organization', 'partner_assignment'], true);
    }
}
// The original source registers these eight subtype-specific meta keys
// with edit_posts. Override only their registered authorization callback,
// without changing their storage, REST schemas or sanitize callbacks.
// This intercepts the original registration on init and prevents the
// generic editor/contributor/partner role from acting as a rights signer.
add_filter('register_meta_args', static function ($args, $defaults, $object_type, $meta_key) {
    if (!partner_rights_local_write_hold_active()
        || $object_type !== 'post'
        || ($args['object_subtype'] ?? '') !== 'partner_assignment'
        || !in_array($meta_key, [
            '_organization_id', '_product_id', '_assignment_role',
            '_assignment_status', '_valid_from', '_valid_until',
            '_commercial_model', '_content_rights'
        ], true)) {
        return $args;
    }
    $args['auth_callback'] = '__return_false';
    return $args;
}, PHP_INT_MAX, 4);

// Independent WordPress edit/add/delete_post_meta primitive capability
// checks must also fail for all metadata of these protected post types.
// Normal product, tour and other metadata capabilities are untouched.
add_filter('map_meta_cap', static function ($caps, $cap, $user_id, $args) {
    if (partner_rights_local_write_hold_active()
        && in_array($cap, [
            'edit_post_meta', 'add_post_meta', 'delete_post_meta'
        ], true)
        && !empty($args[0])
        && partner_rights_local_protected_type(get_post_type((int) $args[0]))) {
        return ['do_not_allow'];
    }
    return $caps;
}, PHP_INT_MAX, 4);

// WordPress native REST controllers and wp-admin use post-type-specific
// primitive caps. Do not revoke generic edit_posts on other content.
add_filter('register_post_type_args', static function ($args, $post_type) {
    if (!partner_rights_local_write_hold_active()
        || !partner_rights_local_protected_type($post_type)) {
        return $args;
    }
    // Never map object-specific meta caps to do_not_allow: with
    // map_meta_cap=true WordPress registers those strings in the GLOBAL
    // post_type_meta_caps table, wrongly remapping do_not_allow to delete_post
    // without an object ID during Admin Bar "New" capability checks.
    // Retain native edit_post/delete_post meta caps and deny their primitive
    // edit_posts/delete_posts dependencies below, plus REST and direct-write
    // admission guards further down.
    $blocked = [
        'edit_posts',
        'edit_others_posts', 'edit_private_posts',
        'edit_published_posts', 'publish_posts',
        'delete_posts', 'delete_others_posts',
        'delete_private_posts', 'delete_published_posts',
        'create_posts'
    ];
    $args['capabilities'] = (array) ($args['capabilities'] ?? []);
    foreach ($blocked as $capability) {
        $args['capabilities'][$capability] = 'do_not_allow';
    }
    return $args;
}, PHP_INT_MAX, 2);

// REST-specific defense in depth, including update/delete routes.
// Read requests remain untouched. No REST handler is invoked on block.
add_filter('rest_pre_dispatch', static function ($result, $server, $request) {
    if (!partner_rights_local_write_hold_active()
        || !preg_match('#^/wp/v2/(?:organization|partner_assignment)(?:/|$)#D',
                       $request->get_route())
        || !in_array(strtoupper($request->get_method()),
                     ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return $result;
    }
    return new WP_Error('partner_rights_authority_required',
        'Partner rights writes require original approved authority.',
        ['status' => 403]);
}, PHP_INT_MAX, 3);

// The internal PHP APIs do NOT always call current_user_can(); force
// wp_insert_post / wp_update_post to fail *before* changing the database.
add_filter('wp_insert_post_empty_content', static function ($empty, $postarr) {
    $type = $postarr['post_type'] ?? null;
    if (!partner_rights_local_write_hold_active()) {
        return $empty;
    }
    if (partner_rights_local_protected_type($type)) {
        return true;
    }
    // Do not trust a caller-supplied post_type when updating an existing
    // protected ID: a forged ordinary type must not evade the hold.
    if (!empty($postarr['ID'])) {
        $original_type = get_post_type((int) $postarr['ID']);
        if (partner_rights_local_protected_type($original_type)) {
            return true;
        }
    }
    return $empty;
}, PHP_INT_MAX, 2);

foreach (['add_post_metadata', 'update_post_metadata', 'delete_post_metadata'] as $hook) {
    add_filter($hook, static function ($check, $post_id) {
        return partner_rights_local_write_hold_active()
            && partner_rights_local_protected_type(get_post_type((int) $post_id))
            ? false : $check;
    }, PHP_INT_MAX, 2);
}
add_filter('pre_delete_post', static function ($delete, $post) {
    return partner_rights_local_write_hold_active()
        && $post instanceof WP_Post
        && partner_rights_local_protected_type($post->post_type)
        ? false : $delete;
}, PHP_INT_MAX, 2);
add_filter('pre_trash_post', static function ($trash, $post) {
    return partner_rights_local_write_hold_active()
        && $post instanceof WP_Post
        && partner_rights_local_protected_type($post->post_type)
        ? false : $trash;
}, PHP_INT_MAX, 2);
