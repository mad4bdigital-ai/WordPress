<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Selects the MCP principal model without elevating WordPress or agent rights.
 *
 * Default: verified WordPress user delegation. The independent NHI path stays
 * available for autonomous agents, Developer tools and explicit site selection.
 * This class is a restrictive authorization layer, not an approval/grant issuer.
 */
final class MAD4B_SCP_Delegation_Mode {
    const CONTRACT = 'mad4b.mcp-principal-mode.v1';
    const OPTION = 'mad4b_mcp_principal_mode';
    const DELEGATED = 'delegated_user';
    const INDEPENDENT = 'independent_nhi';

    public static function boot() {
        if ( ! function_exists( 'add_action' ) ) return;
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
        add_action( 'admin_post_mad4b_mcp_principal_mode_save', array( __CLASS__, 'save' ) );
    }

    public static function selected_mode() {
        $value = function_exists( 'get_option' ) ? get_option( self::OPTION, self::DELEGATED ) : self::DELEGATED;
        // Never treat corrupt or unknown configuration as a delegation grant.
        return in_array( $value, array( self::DELEGATED, self::INDEPENDENT ), true )
            ? $value : self::INDEPENDENT;
    }

    public static function mode_for_identity( $identity ) {
        if ( self::INDEPENDENT === self::selected_mode() || ! is_array( $identity ) ) return self::INDEPENDENT;
        // Autonomous agents and Developer identities retain independent NHI.
        return in_array( $identity['subject_type'] ?? '', array( 'oauth', 'wp_app_password' ), true )
            ? self::DELEGATED : self::INDEPENDENT;
    }

    /**
     * Accept ONLY an already verified OAuth-to-user mapping or a WordPress
     * authenticated application-password event. Never infer authority from a
     * user ID, OAuth JWT text, CLI identity, arbitrary REST headers or a role.
     */
    public static function authenticated_user( $identity ) {
        if ( self::DELEGATED !== self::mode_for_identity( $identity )
            || empty( $identity['authenticated'] ) ) return null;
        $id = isset( $identity['wp_user_id'] ) ? absint( $identity['wp_user_id'] ) : 0;
        if ( $id < 1 || $id !== get_current_user_id()
            || ! class_exists( 'MAD4B_SCP_Policy' )
            || ! MAD4B_SCP_Policy::can_connect_user( $id ) ) return null;

        $is_oauth = 'oauth' === ( $identity['subject_type'] ?? '' )
            && 'oauth2_bearer' === ( $identity['auth_method'] ?? '' )
            && 'mcp' === ( $identity['origin'] ?? '' );
        if ( $is_oauth ) {
            if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' )
                || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()
                || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( 'mad4b:read' )
                || ! class_exists( 'MAD4B_SCP_OAuth_Subject_User_Bridge' ) ) return null;
            $mapped = MAD4B_SCP_OAuth_Subject_User_Bridge::mapped_context();
            if ( ! is_array( $mapped )
                || empty( $mapped['authenticated'] )
                || $id !== (int) ( $mapped['wp_user_id'] ?? 0 )
                || ! hash_equals( (string) ( $mapped['subject_fingerprint'] ?? '' ),
                    (string) ( $identity['subject_fingerprint'] ?? '' ) ) ) return null;
            if ( ! in_array( 'mad4b:read', $identity['token_scopes'] ?? array(), true ) ) return null;
        } elseif ( 'wp_app_password' === ( $identity['subject_type'] ?? '' )
            && 'wp_application_password' === ( $identity['auth_method'] ?? '' )
            && 'wordpress_application_password' === ( $identity['origin'] ?? '' ) ) {
            if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST
                || ( ! is_ssl() && ( ! function_exists( 'wp_get_environment_type' )
                    || 'local' !== wp_get_environment_type() ) )
                || ! class_exists( 'MAD4B_SCP_Application_Password_Agent_Bridge' )
                || ! method_exists( 'MAD4B_SCP_Application_Password_Agent_Bridge', 'verified_context' ) ) return null;
            $verified = MAD4B_SCP_Application_Password_Agent_Bridge::verified_context();
            if ( ! is_array( $verified )
                || $id !== (int) ( $verified['wp_user_id'] ?? 0 )
                || ! hash_equals( (string) ( $verified['subject_fingerprint'] ?? '' ),
                    (string) ( $identity['subject_fingerprint'] ?? '' ) ) ) return null;
        } else {
            return null;
        }
        return get_userdata( $id ) ?: null;
    }

    /**
     * An NHI agent referenced by the same OAuth subject must not silently act
     * on behalf of a DIFFERENT WordPress user in default delegation mode.
     * Unbound/foreign agents can still use the explicit independent NHI mode.
     */
    public static function agent_matches_user( $identity, $agent ) {
        if ( self::INDEPENDENT === self::mode_for_identity( $identity ) ) return true;
        $user = self::authenticated_user( $identity );
        return $user && is_array( $agent )
            && (int) ( $agent['wp_user_id'] ?? 0 ) === (int) $user->ID;
    }

    /** Mandatory *upper bound* for delegated work, even when NHI grants exist. */
    public static function principal_allowed( $identity ) {
        if ( self::INDEPENDENT === self::mode_for_identity( $identity ) ) return true;
        $user = self::authenticated_user( $identity );
        return $user && ( user_can( $user, 'edit_posts' )
            || user_can( $user, 'manage_options' ) || user_can( $user, 'upload_files' ) );
    }

    /**
     * Enforce the user's live WP capabilities in addition to all existing
     * exact agent grants, OAuth scopes, ticket, tenant, budget and write gates.
     * Missing/unsupported content input does not turn into broad administrator
     * authority. WordPress post meta capabilities remain authoritative.
     */
    public static function authorize_ability( $identity, $ability_name, $input ) {
        if ( self::INDEPENDENT === self::mode_for_identity( $identity ) ) return true;
        $user = self::authenticated_user( $identity );
        if ( ! $user ) return new WP_Error( 'mad4b_delegation_identity_unverified',
            'An authenticated, enrolled WordPress user must be verified for the delegated MCP request.' );
        $ability_name = (string) $ability_name;
        $input = is_array( $input ) ? $input : array();

        if ( 'mad4b/content-update-post' === $ability_name ) {
            $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
            if ( $id < 1 || ! get_post( $id ) || ! user_can( $user, 'edit_post', $id ) )
                return new WP_Error( 'mad4b_delegation_post_edit_denied', 'Delegating user cannot edit the exact post.' );
            if ( isset( $input['post_status'] ) && 'publish' === $input['post_status'] ) {
                $post = get_post( $id );
                $type = get_post_type_object( $post->post_type );
                if ( ! $type || ! user_can( $user, $type->cap->publish_posts ) )
                    return new WP_Error( 'mad4b_delegation_publish_denied', 'Delegating user cannot publish this post type.' );
            }
            return true;
        }

        if ( 'mad4b/content-create-post' === $ability_name ) {
            $type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'post';
            $post_type = get_post_type_object( $type );
            if ( ! $post_type || ! user_can( $user, $post_type->cap->edit_posts ) )
                return new WP_Error( 'mad4b_delegation_create_denied', 'Delegating user cannot create the requested post type.' );
            if ( 'publish' === ( $input['post_status'] ?? '' ) && ! user_can( $user, $post_type->cap->publish_posts ) )
                return new WP_Error( 'mad4b_delegation_publish_denied', 'Delegating user cannot publish this post type.' );
            return true;
        }

        if ( 'mad4b/write-execute' === $ability_name || 'mad4b/approval-plan' === $ability_name ) {
            if ( empty( $input['ability'] ) || ! is_string( $input['ability'] )
                || ! isset( $input['input'] ) || ! is_array( $input['input'] )
                || in_array( $input['ability'], array( 'mad4b/write-execute', 'mad4b/approval-plan' ), true ) )
                return new WP_Error( 'mad4b_delegation_nested_target_invalid',
                    'A bounded, non-recursive target is required for delegated write planning.' );
            return self::authorize_ability( $identity, $input['ability'], $input['input'] );
        }

        if ( in_array( $ability_name, array( 'mad4b/plugin-activate', 'mad4b/plugin-deactivate' ), true ) ) {
            $cap = 'activate_plugins';
        } elseif ( false !== strpos( $ability_name, 'media-upload' ) ) {
            $cap = 'upload_files';
        } else {
            // Unmapped sensitive/custom operations remain available under the
            // explicitly selected independent-NHI mode, not inherited by guess.
            return new WP_Error( 'mad4b_delegation_ability_unmapped',
                'This ability has no reviewed WordPress capability mapping. Select independent NHI or certify a mapping.' );
        }
        return user_can( $user, $cap ) ? true
            : new WP_Error( 'mad4b_delegation_capability_denied', 'Delegating user lacks the necessary WordPress capability.' );
    }

    public static function menu() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        add_submenu_page( 'mad4b-control-plane', 'MCP Principal Mode', 'MCP Principal Mode',
            'manage_options', 'mad4b-mcp-principal-mode', array( __CLASS__, 'page' ) );
    }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not authorized.' );
        $current = self::selected_mode();
        echo '<div class="wrap"><h1>MCP Principal Mode</h1>';
        echo '<p>Default: verified WordPress-user delegation. Independent NHI remains available for autonomous agents. Both modes preserve the existing exact-grant, approval, scope, tenant and write policy gates.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'mad4b_mcp_principal_mode_save' );
        echo '<input type="hidden" name="action" value="mad4b_mcp_principal_mode_save"/>';
        foreach ( array( self::DELEGATED => 'Delegated WordPress User (default)',
            self::INDEPENDENT => 'Independent NHI (legacy)' ) as $value => $label ) {
            echo '<p><label><input type="radio" name="principal_mode" value="' . esc_attr( $value ) . '" '
                . checked( $current, $value, false ) . '/> ' . esc_html( $label ) . '</label></p>';
        }
        submit_button( 'Save Principal Mode' );
        echo '</form></div>';
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not authorized.' );
        check_admin_referer( 'mad4b_mcp_principal_mode_save' );
        $value = isset( $_POST['principal_mode'] ) && is_string( $_POST['principal_mode'] )
            ? sanitize_key( wp_unslash( $_POST['principal_mode'] ) ) : '';
        if ( ! in_array( $value, array( self::DELEGATED, self::INDEPENDENT ), true ) )
            wp_die( 'Unsupported principal mode.' );
        update_option( self::OPTION, $value, false );
        wp_safe_redirect( admin_url( 'admin.php?page=mad4b-mcp-principal-mode&updated=1' ) );
        exit;
    }
}
MAD4B_SCP_Delegation_Mode::boot();
