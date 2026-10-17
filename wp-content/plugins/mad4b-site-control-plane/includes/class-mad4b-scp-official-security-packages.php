<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Official WordPress.org packages only. Reuses the existing Plugin_Package
 * read/write abilities, central authorization, WordPress upgrader and Audit.
 * It does not implement scanning, firewalling or third-party API adapters.
 */
final class MAD4B_SCP_Official_Security_Packages {
    const CONTRACT = 'mad4b.security-operational-provider-install.v1';

    private static function registry() {
        $path = dirname( __DIR__ ) . '/config/security-operational-providers.json';
        if ( ! is_file( $path ) || ! is_readable( $path ) ) return array();
        $json = json_decode( (string) file_get_contents( $path ), true );
        if ( ! is_array( $json ) || ( $json['contract'] ?? '' ) !== 'mad4b.security-operational-providers.v1'
            || ! isset( $json['providers'] ) || ! is_array( $json['providers'] ) ) return array();
        return $json['providers'];
    }

    public static function provider( $id ) {
        if ( ! is_string( $id ) || ! preg_match( '/^security-[a-z0-9-]{3,64}$/D', $id ) ) return array();
        $registry = self::registry();
        $row = isset( $registry[ $id ] ) ? $registry[ $id ] : array();
        if ( ! is_array( $row ) ) return array();
        $slug = isset( $row['slug'] ) ? (string) $row['slug'] : '';
        $file = isset( $row['main_file'] ) ? (string) $row['main_file'] : '';
        if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{1,62}$/D', $slug )
            || ! preg_match( '#^' . preg_quote( $slug, '#' ) . '/[a-z0-9._-]+\.php$#D', $file ) ) return array();
        return array( 'provider_id' => $id, 'slug' => $slug, 'plugin_file' => $file,
            'role' => sanitize_key( (string) ( $row['role'] ?? '' ) ),
            'deployment' => sanitize_key( (string) ( $row['deployment'] ?? '' ) ) );
    }

    private static function official_package( $slug, $url ) {
        if ( ! is_string( $url ) || ! function_exists( 'wp_parse_url' ) ) return false;
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'], $parts['path'] )
            || 'https' !== strtolower( $parts['scheme'] )
            || 'downloads.wordpress.org' !== strtolower( $parts['host'] )
            || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] )
            || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) return false;
        return 1 === preg_match( '#^/plugin/' . preg_quote( $slug, '#' ) . '(?:\.[0-9A-Za-z._-]+)?\.zip$#D', $parts['path'] );
    }

    private static function directory_info( $slug ) {
        if ( ! function_exists( 'plugins_api' ) ) require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        $info = plugins_api( 'plugin_information', array( 'slug' => $slug,
            'fields' => array( 'sections' => false, 'banners' => false, 'icons' => false ) ) );
        if ( is_wp_error( $info ) ) return $info;
        if ( ! is_object( $info ) || ! isset( $info->slug, $info->version, $info->download_link )
            || (string) $info->slug !== $slug
            || ! preg_match( '/^[0-9][0-9A-Za-z._-]{0,64}$/D', (string) $info->version )
            || ! self::official_package( $slug, (string) $info->download_link ) )
            return new WP_Error( 'mad4b_security_official_source_invalid', 'The official WordPress.org package identity could not be established.' );
        return $info;
    }

    public static function plan( $input ) {
        $input = is_array( $input ) ? $input : array();
        $id = sanitize_key( (string) ( $input['provider_id'] ?? '' ) );
        $row = self::provider( $id );
        if ( ! $row ) return new WP_Error( 'mad4b_security_provider_unknown', 'Provider is not in the reviewed security catalog.' );
        if ( ( $input['source'] ?? 'wordpress_org' ) !== 'wordpress_org' || ! empty( $input['component'] ) )
            return new WP_Error( 'mad4b_security_source_denied', 'Only the reviewed official WordPress.org source is supported.' );

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugins = get_plugins();
        $installed = isset( $plugins[ $row['plugin_file'] ] );
        // Never install into an already occupied plugin directory, even if
        // the reviewed main file has changed between releases.
        foreach ( array_keys( $plugins ) as $file ) {
            if ( 0 === strpos( $file, $row['slug'] . '/' ) ) $installed = true;
        }
        $active = $installed && is_plugin_active( $row['plugin_file'] );
        $requested_active = ! empty( $input['activate_after_install'] );
        $blockers = array();
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ), 'write' ) )
            $blockers[] = 'staging_write_profile_required';
        if ( ! current_user_can( 'install_plugins' ) ) $blockers[] = 'install_plugins_capability_required';
        if ( $installed ) $blockers[] = 'plugin_already_installed_use_existing_lifecycle_or_update';
        if ( $requested_active && ( ! current_user_can( 'activate_plugins' )
            || ! MAD4B_SCP_Policy::plugin_lifecycle_allowed( $row['plugin_file'], 'activate' ) ) )
            $blockers[] = 'governed_plugin_activation_not_allowed';

        $info = self::directory_info( $row['slug'] );
        if ( is_wp_error( $info ) ) return $info;
        $php_min = isset( $info->requires_php ) ? trim( (string) $info->requires_php ) : '';
        $wp_min = isset( $info->requires ) ? trim( (string) $info->requires ) : '';
        if ( '' !== $php_min && version_compare( PHP_VERSION, $php_min, '<' ) )
            $blockers[] = 'php_version_incompatible';
        if ( '' !== $wp_min && version_compare( get_bloginfo( 'version' ), $wp_min, '<' ) )
            $blockers[] = 'wordpress_version_incompatible';

        $plan = array(
            'contract' => self::CONTRACT, 'provider_id' => $id,
            'slug' => $row['slug'], 'plugin_file' => $row['plugin_file'],
            'role' => $row['role'], 'deployment' => $row['deployment'],
            'source' => array( 'resolved_source' => 'wordpress_org', 'official_directory_only' => true,
                'package_url_sha256' => hash( 'sha256', (string) $info->download_link ) ),
            'operation' => 'install', 'target_version' => (string) $info->version,
            'installed' => $installed, 'active' => $active,
            'activate_after_install' => $requested_active, 'activation_scope' => 'site',
            'package_sha256_verified_before_download' => false,
            'post_download_sha256_receipt_required' => true,
            'production_allowed' => false, 'arbitrary_package_url_allowed' => false,
            'mutation_performed' => false, 'authorizing' => false,
            'eligible' => empty( $blockers ), 'blockers' => $blockers,
        );
        // Reproducible exact-plan digest; never include clocks, URLs or request-only credentials.
        $json = wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) ) return new WP_Error( 'mad4b_security_plan_encode_failed', 'Could not encode official source plan.' );
        $plan['plan_sha256'] = hash( 'sha256', $json );
        $plan['write_binding'] = array( 'expected_plan_sha256' => $plan['plan_sha256'] );
        return $plan;
    }

    public static function apply( $input ) {
        $input = is_array( $input ) ? $input : array();
        // Do not rely on a tool dispatcher as the only authorization boundary.
        $permission = MAD4B_SCP_Plugin_Package::can_apply( $input );
        if ( is_wp_error( $permission ) ) return $permission;
        if ( true !== $permission && ( ! is_array( $permission ) || empty( $permission['allowed'] ) ) )
            return new WP_Error( 'mad4b_security_write_denied', 'Governed Write approval is required.' );
        $expected = strtolower( trim( (string) ( $input['expected_plan_sha256'] ?? '' ) ) );
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) )
            return new WP_Error( 'mad4b_security_exact_plan_required', 'A reviewed exact-plan digest is required.' );
        $plan = self::plan( $input );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( ! hash_equals( $plan['plan_sha256'], $expected ) )
            return new WP_Error( 'mad4b_security_plan_changed', 'The official plugin version or local preflight changed. Review a new plan.' );
        if ( ! $plan['eligible'] )
            return new WP_Error( 'mad4b_security_preflight_blocked', 'Staging provider install is blocked.', array( 'blockers' => $plan['blockers'] ) );

        $row = self::provider( (string) $plan['provider_id'] );
        $info = self::directory_info( $row['slug'] );
        if ( is_wp_error( $info ) || (string) $info->version !== $plan['target_version']
            || ! hash_equals( $plan['source']['package_url_sha256'], hash( 'sha256', (string) $info->download_link ) ) )
            return new WP_Error( 'mad4b_security_package_offer_changed', 'Official package identity changed. No installation attempted.' );
        if ( ! class_exists( 'MAD4B_SCP_Audit' ) )
            return new WP_Error( 'mad4b_security_audit_required', 'Durable audit is required before installation.' );
        $audit = MAD4B_SCP_Audit::record( 'mad4b/security-provider-install-intent', array(
            'provider_id' => $row['provider_id'], 'plugin_file' => $row['plugin_file'],
            'plan_sha256' => $expected, 'target_version' => $plan['target_version'] ), 'ok' );
        if ( is_wp_error( $audit ) || false === $audit )
            return new WP_Error( 'mad4b_security_audit_unavailable', 'Could not commit pre-install audit.' );

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $package = download_url( (string) $info->download_link, 120 );
        if ( is_wp_error( $package ) ) return $package;
        $sha = is_file( $package ) ? hash_file( 'sha256', $package ) : '';
        if ( ! is_string( $sha ) || ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) ) {
            @unlink( $package );
            return new WP_Error( 'mad4b_security_download_unverified', 'Downloaded package could not be hashed.' );
        }
        // Native WordPress installer, never overwrite an existing plugin.
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        $result = $upgrader->install( $package, array( 'overwrite_package' => false ) );
        @unlink( $package );
        if ( is_wp_error( $result ) || true !== $result )
            return new WP_Error( 'mad4b_security_install_incomplete', 'Native plugin installation failed or is uncertain; inspect the installed state before retry.' );

        wp_clean_plugins_cache( true );
        $plugins = get_plugins();
        if ( ! isset( $plugins[ $row['plugin_file'] ] )
            || (string) $plugins[ $row['plugin_file'] ]['Version'] !== $plan['target_version'] )
            return new WP_Error( 'mad4b_security_post_install_unverified',
                'Plugin files or version do not match the reviewed plan. Do not retry blindly.' );

        if ( $plan['activate_after_install'] ) {
            $activated = activate_plugin( $row['plugin_file'], '', false, false );
            if ( is_wp_error( $activated ) || ! is_plugin_active( $row['plugin_file'] ) )
                return new WP_Error( 'mad4b_security_activation_unverified',
                    'Plugin was installed, but its activation was not confirmed. Inspect before retry.' );
        }
        $final = MAD4B_SCP_Audit::record( 'mad4b/security-provider-installed', array(
            'provider_id' => $row['provider_id'], 'plugin_file' => $row['plugin_file'],
            'plan_sha256' => $expected, 'target_version' => $plan['target_version'],
            'download_sha256' => $sha, 'activated' => (bool) $plan['activate_after_install'] ), 'ok' );
        if ( is_wp_error( $final ) || false === $final )
            return new WP_Error( 'mad4b_security_final_audit_unverified',
                'Plugin may be installed, but terminal audit failed; reconcile without replay.' );
        return array( 'contract' => self::CONTRACT,
            'provider_id' => $row['provider_id'], 'plugin_file' => $row['plugin_file'],
            'version' => $plan['target_version'], 'installed' => true,
            'active' => (bool) is_plugin_active( $row['plugin_file'] ),
            'operational_verified' => false, 'security_enforcement_certified' => false,
            'download_sha256' => $sha, 'plan_sha256' => $expected,
            'mutation_performed' => true, 'requires_independent_readback' => true );
    }
}
