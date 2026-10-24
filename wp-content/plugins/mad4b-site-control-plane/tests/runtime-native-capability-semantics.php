<?php
/**
 * CI-only native WordPress meta-capability integration test.
 *
 * Must run with \`wp eval-file\` as a WordPress administrator in a disposable DB.
 * No mocks: this catches false negatives caused by treating 'edit_post' as
 * a primitive role capability instead of going through map_meta_cap().
 */
if ( ! defined( 'ABSPATH' ) ) {
    throw new RuntimeException( 'Native WordPress bootstrap is required.' );
}
$admin = get_current_user_id();
if ( $admin < 1 || ! current_user_can( 'manage_options' ) ) {
    throw new RuntimeException( 'CI fixture requires an administrator.' );
}
$post_id = 0;
$subscriber_id = 0;
try {
    $post_id = wp_insert_post( array(
        'post_type' => 'post',
        'post_status' => 'draft',
        'post_title' => 'MAD4B CI native capability test',
        'post_author' => $admin,
    ), true );
    if ( is_wp_error( $post_id ) || ! $post_id ) {
        throw new RuntimeException( 'Could not create isolated draft fixture.' );
    }
    $mapped = map_meta_cap( 'edit_post', $admin, $post_id );
    if ( ! is_array( $mapped ) || ! in_array( 'edit_posts', $mapped, true ) ) {
        throw new RuntimeException( 'Core did not map edit_post to edit_posts for draft owner.' );
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        throw new RuntimeException( 'Draft owner unexpectedly denied edit_post.' );
    }
    $subscriber_id = wp_insert_user( array(
        'user_login' => 'mad4b_ci_caps_' . strtolower( wp_generate_password( 8, false, false ) ),
        'user_pass' => wp_generate_password( 22, true, true ),
        'user_email' => 'caps-' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.invalid',
        'role' => 'subscriber',
    ) );
    if ( is_wp_error( $subscriber_id ) || ! $subscriber_id ) {
        throw new RuntimeException( 'Could not create isolated subscriber fixture.' );
    }
    wp_set_current_user( $subscriber_id );
    if ( current_user_can( 'edit_post', $post_id ) ) {
        throw new RuntimeException( 'Subscriber edit_post incorrectly allowed.' );
    }
    if ( current_user_can( 'edit_posts' ) ) {
        throw new RuntimeException( 'Subscriber incorrectly possesses edit_posts.' );
    }
    echo "L2_NATIVE_WORDPRESS_CAPABILITIES: PASS\n";
} finally {
    wp_set_current_user( $admin );
    if ( is_int( $post_id ) && $post_id > 0 ) {
        wp_delete_post( $post_id, true );
    }
    if ( is_int( $subscriber_id ) && $subscriber_id > 0 ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $subscriber_id );
    }
}