<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Read-only, source-owned provider configuration coverage. A key, a selected
 * executor, an OAuth connection, and a certified execution are distinct facts.
 *
 * This class NEVER resolves or returns a secret, tests paid API accounts, or
 * manufactures browser-signed evidence. It links to the existing owners.
 */
final class MAD4B_SCP_Provider_Setup_Coverage {
    const CONTRACT = 'mad4b.provider-setup-coverage.v1';
    const PAGE_SLUG = 'mad4b-provider-connections';
    private static function row( $family, $id, $label, $custody, $fields, $credential, $connection, $execution, $next, $url = '' ) {
        $clean = array();
        foreach ( $fields as $field ) {
            if ( ! is_string( $field ) || ! preg_match( '/^[A-Z_a-z][A-Z_a-z0-9]{0,95}$/D', $field ) ) continue;
            $clean[] = $field;
        }
        return array(
            'family' => $family, 'provider_id' => $id, 'label' => $label,
            'credential_custody' => $custody, 'field_names' => array_values( array_unique( $clean ) ),
            'credential_state' => $credential, 'connection_state' => $connection,
            'execution_state' => $execution, 'next_step' => $next, 'setup_url' => $url,
            'secret_included' => false, 'secret_handle_included' => false,
            'authorizing' => false, 'mutation_performed' => false,
        );
    }
    private static function core_source( $connector ) {
        if ( ! is_array( $connector ) ) return 'connector_missing';
        $auth = $connector['authentication'] ?? array();
        if ( ( $auth['method'] ?? '' ) !== 'api_key' ) return 'external_consent_required';
        if ( ! function_exists( '_wp_connectors_get_api_key_source' ) ) return 'core_source_unavailable';
        $source = _wp_connectors_get_api_key_source(
            (string) ( $auth['setting_name'] ?? '' ),
            (string) ( $auth['env_var_name'] ?? '' ),
            (string) ( $auth['constant_name'] ?? '' )
        );
        // A source label, never the key. Unknown source must stay unverified.
        return is_string( $source ) && in_array( $source, array( 'none', 'database', 'environment', 'env', 'constant' ), true )
            ? $source : 'source_unverified';
    }
    public static function browser_rows() {
        $rows = array();
        if ( ! class_exists( 'MAD4B_SCP_Browser_Acceptance_Admin_UI' ) ) return $rows;
        $setup = admin_url( 'admin.php?page=mad4b-browser-acceptance' );
        $selection = MAD4B_SCP_Browser_Acceptance_Admin_UI::public_selection();
        $selected = (string) ( $selection['executor'] ?? '' );
        foreach ( MAD4B_SCP_Browser_Acceptance_Admin_UI::managed_executors() as $id => $meta ) {
            $fields = isset( $meta[1] ) && is_array( $meta[1] ) ? $meta[1] : array();
            $rows[] = self::row( 'browser_runner', $id, (string) $meta[0],
                'external_runner_secret_store', $fields,
                'unverifiable_from_wordpress', 'external_runner_not_probed',
                'not_certified_by_preference',
                $selected === $id ? 'configure_external_runner_then_verify_signed_evidence'
                    : 'configure_external_runner_before_selecting', $setup );
        }
        $valid = false;
        if ( class_exists( 'MAD4B_SCP_Browser_Acceptance_Core' ) ) {
            $inventory = MAD4B_SCP_Browser_Acceptance_Core::capabilities();
            $valid = is_array( $inventory ) && (int) ( $inventory['provider_count'] ?? 0 ) > 0;
        }
        $rows[] = self::row( 'browser_native', 'native_hybrid', 'Native Browser Acceptance',
            'no_external_credential', array(), 'not_required',
            ! empty( $selection['preference_valid'] ) ? 'preference_valid' : 'preference_invalid',
            $valid ? 'browser_adapter_registered_not_signed_certification' : 'browser_adapter_missing',
            'run_separate_interactive_and_signed_browser_acceptance_if_required', $setup );
        return $rows;
    }
    public static function core_ai_rows() {
        $rows = array();
        if ( ! function_exists( 'wp_get_connector' ) ) return $rows;
        $setup = admin_url( 'options-connectors.php' );
        foreach ( array(
            'google' => 'Google AI Provider',
            'openai' => 'OpenAI Provider',
            'anthropic' => 'Anthropic Provider',
            'mad4b_gemini' => 'MAD4B Gemini',
            'mad4b_openrouter' => 'MAD4B OpenRouter',
            'mad4b_custom' => 'MAD4B Custom AI',
        ) as $id => $label ) {
            $connector = wp_get_connector( $id );
            if ( ! is_array( $connector ) ) {
                $rows[] = self::row( 'ai', $id, $label, 'wordpress_core_connectors',
                    array(), 'connector_missing', 'not_configured', 'not_verified',
                    'install_or_register_provider_connector', $setup );
                continue;
            }
            $auth = $connector['authentication'] ?? array();
            $setting = (string) ( $auth['setting_name'] ?? '' );
            $source = self::core_source( $connector );
            $configured = ! in_array( $source, array( 'none', 'source_unverified', 'core_source_unavailable', 'connector_missing' ), true );
            $is_client = ( $connector['type'] ?? '' ) === 'ai_provider';
            $active = false;
            $callable = $connector['plugin']['is_active'] ?? null;
            if ( is_callable( $callable ) ) $active = (bool) call_user_func( $callable );
            $rows[] = self::row( 'ai', $id, $label, 'wordpress_core_connectors',
                $setting !== '' ? array( $setting ) : array(), $configured ? 'configured_'.$source : $source,
                $configured ? 'credential_present_not_live_tested' : 'credential_missing',
                $is_client && ! $active ? 'provider_plugin_inactive'
                    : 'model_inference_not_verified_by_key_presence',
                $is_client && ! $active ? 'activate_compatible_wp_ai_provider'
                    : 'configure_credentials_then_test_explicit_model_inference',
                $setup );
        }
        return $rows;
    }
    public static function search_rows() {
        $rows = array();
        if ( ! class_exists( 'MAD4B_SCP_Search_Providers' ) ) return $rows;
        $url = admin_url( 'admin.php?page=mad4b-search-intelligence&section=providers' );
        foreach ( MAD4B_SCP_Search_Providers::adapters() as $id => $adapter ) {
            if ( ! $adapter instanceof MAD4B_SCP_Search_SERP_Enrollment ) continue;
            try {
                $setup = $adapter->enrollment();
                $status = MAD4B_SCP_Search_Provider_Connections::status( $id );
                $field_names = is_array( $setup['fields'] ?? null ) ? array_keys( $setup['fields'] ) : array();
                $configured = is_array( $status ) && ! empty( $status['configured'] );
                $connection = is_array( $status ) ? (string) ( $status['connection_state'] ?? 'unknown' ) : 'state_unavailable';
                $rows[] = self::row( 'search', $id, (string) ( $setup['label'] ?? $id ),
                    'search_encrypted_site_scoped_cas_store', $field_names,
                    $configured ? 'encrypted_saved' : 'not_configured', $connection,
                    $connection === 'VERIFIED' ? 'account_tested_not_capture_certified'
                        : 'not_tested_or_expired',
                    $configured ? 'test_or_recertify_saved_account' : 'save_provider_credentials', $url );
            } catch ( Throwable $e ) {
                $rows[] = self::row( 'search', $id, $id, 'search_encrypted_site_scoped_cas_store',
                    array(), 'unknown', 'adapter_unavailable', 'blocked', 'repair_existing_adapter', $url );
            }
        }
        return $rows;
    }
    public static function oauth_rows() {
        $out = array();
        if ( ! class_exists( 'MAD4B_SCP_Provider_Connection_Center' ) ) return $out;
        $rows = MAD4B_SCP_Provider_Connection_Center::discover();
        if ( is_wp_error( $rows ) || ! is_array( $rows['providers'] ?? null ) ) return $out;
        foreach ( $rows['providers'] as $item ) {
            if ( ! is_array( $item ) ) continue;
            $connected = ! empty( $item['connected'] );
            $fields = array();
            $custody = 'original_provider_connection_authority';
            $credential = 'adapter_credential_status_not_exposed';
            if ( (string)( $item['provider'] ?? '' ) === 'google_drive'
                 && class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ) {
                // Exact OAuth mode is the source of truth, not the Gemini
                // Connector or an imagined shared client-secret form.
                $oauth = MAD4B_SCP_Google_Drive_Context::credentials_status();
                $mode = is_array( $oauth ) ? (string)( $oauth['auth_mode'] ?? '' ) : '';
                $custody = is_array( $oauth )
                    ? (string)( $oauth['credential_custody'] ?? 'unknown' ) : 'unknown';
                if ( $mode === MAD4B_SCP_Google_Drive_Context::AUTH_MODE_MANAGED ) {
                    $fields = array( 'MAD4B_GOOGLE_MANAGED_OAUTH_BROKER_URL',
                        'MAD4B_GOOGLE_MANAGED_OAUTH_SITE_KEY_ID',
                        'MAD4B_GOOGLE_MANAGED_OAUTH_SITE_SECRET', 'owner_consent' );
                } elseif ( $mode === MAD4B_SCP_Google_Drive_Context::AUTH_MODE_DEDICATED ) {
                    $fields = array( 'MAD4B_GOOGLE_DEDICATED_CLIENT_ID',
                        'MAD4B_GOOGLE_DEDICATED_CLIENT_SECRET', 'owner_consent' );
                } elseif ( $mode === MAD4B_SCP_Google_Drive_Context::AUTH_MODE_CUSTOM ) {
                    $fields = array( 'MAD4B_GOOGLE_DRIVE_CLIENT_ID',
                        'MAD4B_GOOGLE_DRIVE_CLIENT_SECRET', 'owner_consent' );
                }
                $credential = $mode === '' ? 'oauth_mode_unknown'
                    : ( ! empty( $oauth['configured'] ) ? $mode.'_credential_configured'
                        : $mode.'_credential_not_configured' );
            }
            $out[] = self::row( 'oauth', (string)$item['provider'], (string)$item['label'],
                $custody, $fields, $credential,
                (string)( $item['health_state'] ?? 'unknown' ),
                $connected ? 'connected_not_scope_or_write_certified' : 'not_connected',
                $connected ? 'check_current_scopes_and_exact_refresh_plan' : 'complete_original_owner_oauth_handoff',
                (string)( $item['owner_handoff_url'] ?? '' ) );
        }
        return $out;
    }
    public static function auxiliary_rows() {
        $out = array();
        // Generic Context extractor is intentionally an operator-owned HTTPS
        // service. A URL in the admin UI must not silently grant egress.
        $url = defined( 'MAD4B_CONTEXT_EXTRACTOR_URL' )
            && trim( (string)constant( 'MAD4B_CONTEXT_EXTRACTOR_URL' ) ) !== '';
        $token = defined( 'MAD4B_CONTEXT_EXTRACTOR_TOKEN' )
            && trim( (string)constant( 'MAD4B_CONTEXT_EXTRACTOR_TOKEN' ) ) !== '';
        $out[] = self::row( 'context', 'generic_extractor', 'Generic document extractor',
            'external_host_environment_or_php_config',
            array( 'MAD4B_CONTEXT_EXTRACTOR_URL', 'MAD4B_CONTEXT_EXTRACTOR_TOKEN' ),
            $token ? 'host_secret_declared' : 'host_secret_missing',
            $url && $token ? 'configured_not_probed' : 'incomplete_configuration',
            'not_certified_by_const_presence',
            'set_both_in_host_config_and_review_egress_policy' );
        $vault = getenv( 'MAD4B_G6_VAULT_KEY_BASE64' );
        $vault_id = getenv( 'MAD4B_G6_VAULT_KEY_ID' );
        $ready = is_string( $vault ) && $vault !== '' && is_string( $vault_id ) && $vault_id !== '';
        $out[] = self::row( 'growth', 'g6_secret_vault', 'G6 Provider Vault',
            'host_environment', array( 'MAD4B_G6_VAULT_KEY_BASE64', 'MAD4B_G6_VAULT_KEY_ID' ),
            $ready ? 'host_fields_present' : 'host_fields_incomplete',
            'provider_adapter_not_checked', 'not_authorized_by_vault_configuration',
            'configure_host_vault_then_certify_specific_provider' );
        if ( class_exists( 'MAD4B_SCP_G5_External_Providers' ) ) {
            $adapters = MAD4B_SCP_G5_External_Providers::adapters();
            $out[] = self::row( 'growth', 'g5_adapter_registry', 'G5 Growth Analytics Adapters',
                'existing_certified_provider_authority', array(),
                count( $adapters ) > 0 ? 'registered_adapters_present' : 'no_registered_adapters',
                'no_account_test_performed', 'requires_individual_certification',
                'enroll_and_certify_reviewed_observation_adapter',
                admin_url( 'admin.php?page=mad4b-growth-providers' ) );
            // A newly registered G5 adapter may provide exact credential,
            // property and account field descriptors through the original
            // descriptor-verified setup preview. Never infer fields from names.
            foreach ( array_slice( $adapters, 0, 32, true ) as $provider_id => $adapter ) {
                $preview = MAD4B_SCP_G5_External_Providers::setup_preview(
                    array( 'provider_id' => $provider_id ) );
                $safe = is_array( $preview ) && is_array( $preview['fields'] ?? null );
                $out[] = self::row( 'growth', (string)$provider_id,
                    'G5: '.(string)$provider_id,'existing_certified_provider_authority',
                    $safe ? array_keys( $preview['fields'] ) : array(),
                    'credential_status_not_exposed',
                    $safe ? 'certified_setup_descriptor_available' : 'setup_descriptor_blocked',
                    'retained_reads_require_separate_execution_grant',
                    $safe ? 'use_existing_certified_provider_connection' : 'repair_g5_adapter_certification',
                    admin_url( 'admin.php?page=mad4b-growth-providers' ) );
            }
        }
        return $out;
    }
    public static function inventory() {
        if ( ! current_user_can( 'manage_options' ) )
            return new WP_Error( 'mad4b_provider_setup_admin_required', 'Administrator required.' );
        $rows = array_merge( self::browser_rows(), self::core_ai_rows(),
            self::search_rows(), self::oauth_rows(), self::auxiliary_rows() );
        return array( 'contract' => self::CONTRACT, 'read_only' => true,
            'authorizing' => false, 'mutation_performed' => false,
            'provider_count' => count( $rows ), 'providers' => $rows,
            'secret_values_included' => false, 'secret_handles_included' => false,
            'checks_performed' => 'local_registered_sources_only',
            'live_connection_tests_performed' => false,
            'unverified_not_equivalent_to_ready' => true );
    }
    public static function render() {
        $data = self::inventory();
        if ( is_wp_error( $data ) ) return;
        echo '<hr/><h2>Provider setup coverage</h2><p>Fields below are read-only names from their owning adapters. "Credential present" does not establish a live connection, signed browser evidence, paid API authorization or production readiness.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Provider</th><th>Required fields / credential owner</th><th>Credential state</th><th>Connection / execution evidence</th><th>Setup</th></tr></thead><tbody>';
        foreach ( $data['providers'] as $row ) {
            echo '<tr><td><strong>'.esc_html( $row['label'] ).'</strong><br/><code>'.esc_html( $row['family'].'/'.$row['provider_id'] ).'</code></td>';
            echo '<td>'.esc_html( $row['credential_custody'] ).'<ul>';
            foreach ( $row['field_names'] as $name ) echo '<li><code>'.esc_html( $name ).'</code></li>';
            echo '</ul></td><td><code>'.esc_html( $row['credential_state'] ).'</code></td>';
            echo '<td><code>'.esc_html( $row['connection_state'] ).'</code><p><code>'.esc_html( $row['execution_state'] ).'</code></p></td>';
            echo '<td><code>'.esc_html( $row['next_step'] ).'</code>';
            if ( $row['setup_url'] !== '' && wp_validate_redirect( $row['setup_url'], '' ) !== '' )
                echo '<p><a href="'.esc_url( $row['setup_url'] ).'">Open original setup</a></p>';
            echo '</td></tr>';
        }
        echo '</tbody></table><p>Browserbase, Browserless, Steel and Cloudflare browser credentials live in the independently operated external runner. WordPress has no authority to test or save those runner keys. The Browser Acceptance page describes their exact secret names and signed-evidence requirements; do not paste those secrets into an unrelated WordPress setting.</p>';
    }
}
