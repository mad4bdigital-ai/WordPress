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
    const PAGE_SLUG = 'mad4b-mcp-principal-mode';
    const DELEGATED = 'delegated_user';
    const INDEPENDENT = 'independent_nhi';

    public static function boot() {
        if ( ! function_exists( 'add_action' ) ) return;
        // Queue through the native parent-order and permission registry.
        if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry' ) ) {
            MAD4B_SCP_Admin_Route_Registry::register( self::PAGE_SLUG, 'manage_options' );
            MAD4B_SCP_Admin_Route_Registry::schedule_submenu( array( __CLASS__, 'menu' ) );
        }
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


    /**
     * A verified user's governed execution principal.
     *
     * This is NOT an autonomous NHI authorization mode: the actor is owned by
     * the exact enrolled WP user; its grants are restricted to draft-content
     * creation / wrapper dispatch and remain subordinate to WordPress caps,
     * transport scope, exact tickets, rights, context and budget checks.
     *
     * Provision only on a verified authenticated *write attempt*, never on
     * status/read/CLI/anonymous requests. Never re-enable a revoked agent or
     * subject, or rebind a subject owned by another agent.
     */
    public static function ensure_content_actor( $identity, $validated_prepared_dispatch = false ) {
        if ( self::DELEGATED !== self::mode_for_identity( $identity ) )
            return new WP_Error( 'mad4b_delegation_mode_invalid', 'Delegation actor only applies to verified user mode.' );
        $user = self::authenticated_user( $identity );
        if ( ! $user || ! user_can( $user, 'edit_posts' ) )
            return new WP_Error( 'mad4b_delegation_content_cap_denied', 'Delegating user cannot create content.' );
        if ( ! class_exists( 'MAD4B_SCP_Agent_Registry' )
            || ! class_exists( 'MAD4B_SCP_Schema' )
            || ! MAD4B_SCP_Schema::is_ready() )
            return new WP_Error( 'mad4b_delegation_registry_unavailable', 'Delegation registry is unavailable.' );
        $gate = class_exists( 'MAD4B_SCP_Policy' )
            ? MAD4B_SCP_Policy::mutation_gate_status() : array();
        if ( ! is_array( $gate ) || empty( $gate['effective'] ) )
            return new WP_Error( 'mad4b_delegation_site_write_disabled', 'Site governance has not enabled write authority.' );

        $type = (string) ( $identity['subject_type'] ?? '' );
        $fingerprint = (string) ( $identity['subject_fingerprint'] ?? '' );
        if ( ! in_array( $type, array( 'oauth', 'wp_app_password' ), true )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) )
            return new WP_Error( 'mad4b_delegation_subject_invalid', 'Verified subject fingerprint is required.' );

        // Explicit bearer write authorization; read-only OAuth sessions must
        // never be promoted into managed principals simply by calling a tool.
        if ( 'oauth' === $type && ! $validated_prepared_dispatch ) {
            if ( ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( 'mad4b:write' )
                && ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( 'server:mad4b-write' )
                && ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( 'ability:mad4b/content-apply-bundle' ) )
                return new WP_Error( 'mad4b_delegation_write_scope_required', 'Direct delegation requires an explicitly verified write scope.' );
        }
        // ChatGPT's existing mad4b:read bearer can access ONLY the
        // independently verified prepared-write dispatcher. The dispatcher
        // must validate the signed preparation, exact schema, requested
        // draft target and one-time approval ticket before setting this flag.
        // Its target is still governed by the existing scope and approval
        // checks in central authorization; this flag grants no write itself.
        if ( $validated_prepared_dispatch && 'oauth' === $type
            && ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( 'mad4b:read' ) )
            return new WP_Error( 'mad4b_delegation_dispatch_scope_denied', 'Signed prepared dispatch requires an authenticated read-scoped bearer.' );

        $existing_subject = MAD4B_SCP_Agent_Registry::subject_binding( $type, $fingerprint );
        if ( is_wp_error( $existing_subject ) ) return $existing_subject;
        if ( is_array( $existing_subject ) ) {
            if ( 'enabled' !== (string) ( $existing_subject['status'] ?? '' ) )
                return new WP_Error( 'mad4b_delegation_subject_revoked', 'A revoked subject cannot be automatically re-enabled.' );
            $prior = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
            if ( is_wp_error( $prior ) ) return $prior;
            if ( ! self::agent_matches_user( $identity, $prior ) )
                return new WP_Error( 'mad4b_delegation_subject_owner_mismatch', 'An existing subject belongs to a different WordPress principal.' );
            return $prior;
        }

        $environment = class_exists( 'MAD4B_SCP_Environment' )
            ? sanitize_key( (string) MAD4B_SCP_Environment::effective() )
            : ( function_exists( 'wp_get_environment_type' )
                ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' );
        // Provisioning is deliberately staged until independently approved
        // and exercised against a real authenticated MCP request.
        if ( 'staging' !== $environment )
            return new WP_Error( 'mad4b_delegation_provision_environment_denied', 'Automatic delegated content enrollment is enabled only for staging.' );

        $slug = 'mad4b-delegated-user-' . (int) $user->ID;
        $actor = MAD4B_SCP_Agent_Registry::get_agent_by_slug( $slug );
        $new_actor = false;
        if ( ! $actor ) {
            $actor = MAD4B_SCP_Agent_Registry::create_agent( array(
                'slug' => $slug, 'label' => 'Verified delegated WordPress content principal',
                'status' => 'disabled', 'environment' => $environment, 'wp_user_id' => (int) $user->ID,
            ) );
            if ( is_wp_error( $actor ) ) return $actor;
            $new_actor = true;
        }
        if ( ! is_array( $actor ) || (int) ( $actor['wp_user_id'] ?? 0 ) !== (int) $user->ID
            || (string) ( $actor['environment'] ?? '' ) !== $environment )
            return new WP_Error( 'mad4b_delegation_actor_owner_mismatch', 'Managed delegated principal belongs to another user or environment.' );
        if ( ! $new_actor && 'enabled' !== (string) ( $actor['status'] ?? '' ) )
            return new WP_Error( 'mad4b_delegation_actor_revoked', 'Disabled delegated principal requires explicit operator reactivation.' );

        // Only a reviewed, site-mounted exact content ability. Do not grant
        // arbitrary admin, filesystem, plugin, Developer or publication tools.
        $needed = array( 'mad4b-content', 'mad4b-write' );
        foreach ( $needed as $server ) {
            if ( ! class_exists( 'MAD4B_SCP_Servers' )
                || 'core' !== MAD4B_SCP_Servers::provider_for_ability( $server, 'mad4b/content-apply-bundle' ) )
                return new WP_Error( 'mad4b_delegation_mount_unavailable', 'Delegated content ability is not mounted on the requested server.' );
            $present = MAD4B_SCP_Agent_Registry::grants_for_agent( $actor['id'], $server );
            $found = false;
            foreach ( $present as $g ) {
                if ( 'mad4b/content-apply-bundle' === (string) $g['ability_name']
                    && 'core' === (string) $g['provider']
                    && in_array( (string) $g['environment'], array( $environment, 'all' ), true ) ) {
                    if ( 'deny' === (string) $g['effect'] )
                        return new WP_Error( 'mad4b_delegation_explicit_deny', 'An explicit policy deny prevents delegated content operation.' );
                    $found = true;
                }
            }
            if ( ! $found && ! $new_actor )
                return new WP_Error( 'mad4b_delegation_grant_revoked', 'An existing principal requires explicit operator re-grant; automatic repair is denied.' );
            if ( ! $found ) {
                $grant = MAD4B_SCP_Agent_Registry::grant_ability(
                    (string) $actor['public_id'], $server, 'mad4b/content-apply-bundle',
                    'core', array(), 'allow', $environment );
                if ( is_wp_error( $grant ) ) return $grant;
            }
        }

        if ( $new_actor ) {
            $activated = MAD4B_SCP_Agent_Registry::update_agent(
                (string) $actor['public_id'], array( 'status' => 'enabled' ), (int) $actor['revision'] );
            if ( is_wp_error( $activated ) ) return $activated;
            $actor = $activated;
        }
        $bound = MAD4B_SCP_Agent_Registry::bind_subject(
            (string) $actor['public_id'], $type, $fingerprint, 'verified delegated WP user' );
        if ( is_wp_error( $bound ) ) return $bound;
        $resolved = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
        if ( is_wp_error( $resolved ) ) return $resolved;
        return self::agent_matches_user( $identity, $resolved )
            ? $resolved : new WP_Error( 'mad4b_delegation_final_identity_mismatch', 'Delegated user binding did not converge.' );
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

        if ( 'mad4b/content-apply-bundle' === $ability_name ) {
            $type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : '';
            $post_type = $type ? get_post_type_object( $type ) : null;
            $post = isset( $input['post'] ) && is_array( $input['post'] )
                ? $input['post'] : array();
            $status = sanitize_key( (string) ( $post['post_status'] ?? 'draft' ) );
            if ( ! $post_type || ! in_array( $status, array( 'draft', 'pending', 'auto-draft' ), true ) )
                return new WP_Error( 'mad4b_delegation_draft_only',
                    'Delegated content bundles are draft/pending-only and require an exact registered post type.' );
            $mode = sanitize_key( (string) ( $input['mode'] ?? 'create' ) );
            if ( 'create' === $mode ) {
                $cap = $post_type->cap->create_posts ?? $post_type->cap->edit_posts;
                if ( ! user_can( $user, $cap ) || ! empty( $input['post_id'] ) )
                    return new WP_Error( 'mad4b_delegation_draft_create_denied',
                        'Delegating user cannot create a draft for this post type.' );
            } elseif ( 'update' === $mode ) {
                $id = absint( $input['post_id'] ?? 0 );
                $target = $id ? get_post( $id ) : null;
                if ( ! $target || $target->post_type !== $type
                    || in_array( $target->post_status, array( 'publish', 'private' ), true )
                    || ! user_can( $user, 'edit_post', $id ) )
                    return new WP_Error( 'mad4b_delegation_draft_edit_denied',
                        'Delegating user cannot update the exact draft target.' );
            } else {
                return new WP_Error( 'mad4b_delegation_mode_denied', 'Unsupported draft operation.' );
            }
            if ( ! empty( $post['post_author'] ) && (int) $post['post_author'] !== (int) $user->ID
                && ! user_can( $user, $post_type->cap->edit_others_posts ) )
                return new WP_Error( 'mad4b_delegation_author_denied', 'Delegating user cannot assign another author.' );
            return true;
        }

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
        add_submenu_page( MAD4B_SCP_Admin_Navigation_Manifest::PARENT,
            'MCP Principal Mode', 'MCP Principal Mode',
            'manage_options', self::PAGE_SLUG, array( __CLASS__, 'page' ) );
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
