<?php
/**
 * Live source-of-truth Elementor MCP and editor capability preflight.
 *
 * Read-only. A registered WordPress Ability, MCP adapter, or server class does
 * NOT establish a negotiated authenticated MCP transport session.
 */
defined( 'ABSPATH' ) || exit;

final class MAD4B_SCP_Elementor_Native_MCP_Awareness {
    const CONTRACT = 'mad4b.elementor-native-mcp-authoring-awareness.v1';
    const NATIVE_SERVER = 'elementor-mcp-server';

    public static function inspect( $post_id = 0 ) {
        $post_id = absint( $post_id );
        $module = 'Elementor\\Modules\\Mcp\\Module';
        $adapter = 'WP\\MCP\\Core\\McpAdapter';
        $loaded = class_exists( $module );
        $transport_supported = $loaded && method_exists( $module, 'is_active' )
            && (bool) $module::is_active();
        $enabled = $loaded && method_exists( $module, 'is_site_mcp_exposure_enabled' )
            && (bool) $module::is_site_mcp_exposure_enabled();
        $registered = 0;
        $native_names = 0;
        if ( function_exists( 'wp_get_abilities' ) ) {
            foreach ( (array) wp_get_abilities() as $name => $ability ) {
                if ( 0 !== strpos( (string) $name, 'elementor/' ) ) continue;
                ++$registered;
                $category = is_object( $ability ) && method_exists( $ability, 'get_category' )
                    ? (string) $ability->get_category() : '';
                if ( $category === 'elementor' ) ++$native_names;
            }
        }
        $logged_in = get_current_user_id() > 0;
        $can_edit = $logged_in && ( $post_id > 0
            ? current_user_can( 'edit_post', $post_id )
            : current_user_can( 'edit_posts' ) );
        $unfiltered = $logged_in && current_user_can( 'unfiltered_html' );
        $handshake = ! $transport_supported ? 'NATIVE_MODULE_NOT_READY'
            : ( ! $enabled ? 'NOT_STARTED_SITE_DISABLED' : 'UNVERIFIED_NEEDS_AUTHENTICATED_MCP_INITIALIZE_TOOLS_LIST' );
        $route = $enabled && $transport_supported
            ? 'NATIVE_MCP_DISCOVER_AND_HANDSHAKE_REQUIRED'
            : 'NATIVE_MCP_DISABLED_OR_UNAVAILABLE';
        return array(
            'contract' => self::CONTRACT,
            'provider' => 'elementor',
            'site_uuid' => class_exists( 'MAD4B_SCP_Site_Profile' )
                ? (string) ( MAD4B_SCP_Site_Profile::status()['site_uuid'] ?? '' ) : '',
            'environment' => wp_get_environment_type(),
            'versions' => array(
                'core' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
                'pro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
                'mcp_adapter_present' => class_exists( $adapter ),
            ),
            'native_mcp' => array(
                'module_loaded' => $loaded,
                'adapter_compatible' => $transport_supported,
                'site_enabled' => $enabled,
                'server_slug' => self::NATIVE_SERVER,
                'handshake_state' => $handshake,
                'handshake_verified' => false,
                'initialize_verified' => false,
                'tools_list_verified' => false,
                'tools_schema_verified' => false,
                'external_client_binding_verified' => false,
                'endpoint_call_performed' => false,
                'native_execution_authorized' => false,
            ),
            'local_ability_registry' => array(
                'elementor_namespace_names' => $registered,
                'provider_category_names' => $native_names,
                'local_registration_is_handshake' => false,
            ),
            'actor' => array(
                'authenticated_wordpress_user' => $logged_in,
                'can_edit_target' => $can_edit,
                'can_use_unfiltered_html' => $unfiltered,
                'target_post_id' => $post_id,
            ),
            'authoring' => array(
                'custom_css_owner' => 'NATIVE_ELEMENTOR_VISUAL_EDITOR',
                'raw_elementor_data_css_write' => 'DENIED_BY_POLICY',
                'direct_sql_elementor_data_write' => 'DENIED_BY_POLICY',
                'unfiltered_html_absence_may_change_css' => true,
                'css_sensitive_tokens' => array( '>', '<', '&', 'quotes', 'backslashes' ),
                'native_edit_ready_for_actor' => $can_edit && $unfiltered,
                'required_save_receipt' => 'NATIVE_EDITOR_DOCUMENT_SAVE_WITH_CSS_EXACT_READBACK',
                'required_acceptance' => array( 'document_source_sha_before_after', 'css_exact_roundtrip', 'generated_css', 'preview_desktop_mobile', 'wpml_locale_and_rtl' ),
            ),
            'routing' => array(
                'preferred' => 'ELEMENTOR_EDITOR_NATIVE',
                'mcp' => $route,
                'fallback' => $can_edit && $unfiltered ? 'AUTHENTICATED_ELEMENTOR_EDITOR' : 'AWAIT_AUTHENTICATED_EDITOR',
                'non_elementor_content_jobs_blocked' => false,
            ),
            'operator_next' => ! $enabled
                ? 'Enable Elementor MCP in Elementor admin using native UI, with authorized client and WordPress user; then independently prove initialize/tools-list/schema.'
                : 'Use authenticated client to prove MCP initialize and tools/list before selecting native MCP execution.',
            'mutation_performed' => false,
            'write_authorized' => false,
            'production_mutation_allowed' => false,
        );
    }
}
