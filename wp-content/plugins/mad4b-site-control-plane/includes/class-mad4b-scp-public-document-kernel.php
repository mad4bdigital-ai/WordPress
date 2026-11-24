<?php
/**
 * MAD4B Public Document Kernel
 *
 * Request-aware, company-neutral source module. No use of client headers to
 * confer authority or market eligibility. Every unknown/private/request-write
 * context retains the existing full Control Plane bootstrap.
 *
 * Scope is initially limited to independently configured public singular
 * routes. This is not an HTML/Redis/FastCGI cache admission contract.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Public_Document_Kernel {
    const CONTRACT = 'mad4b.scp-public-document-kernel.v1';
    const MODE_OPTION = 'mad4b_scp_public_document_kernel_mode_v1';
    const PREFIX_OPTION = 'mad4b_scp_public_document_kernel_prefixes_v1';
    const PATHS_OPTION = 'mad4b_scp_public_document_kernel_verified_paths_v1';

    /**
     * Pure request classification: never authenticates, approves a market,
     * bypasses a write policy, or executes a WordPress mutation.
     */
    public static function classify( array $server, array $get, array $post, array $cookies,
        $environment, $mode, array $prefixes, $canonical_host, $admin = false,
        $cli = false, $ajax = false, $cron = false ) {
        $deny = static function ( $reason ) {
            return array( 'eligible' => false, 'reason' => $reason );
        };
        if ( $admin || $cli || $ajax || $cron ) return $deny( 'private_runtime' );
        if ( ! in_array( $environment, array( 'staging', 'production' ), true ) ) return $deny( 'environment' );
        if ( ! in_array( $mode, array( 'on', 'canary' ), true ) ) return $deny( 'disabled' );
        if ( $mode === 'canary' && $environment !== 'staging' ) return $deny( 'canary_staging_only' );
        if ( ( $server['REQUEST_METHOD'] ?? '' ) !== 'GET' ) return $deny( 'method' );
        if ( $get || $post || $cookies ) return $deny( 'input_state' );
        if ( (string) ( $server['QUERY_STRING'] ?? '' ) !== '' ) return $deny( 'query_string' );
        if ( ! is_string( $canonical_host ) || $canonical_host === '' ||
            strtolower( (string) ( $server['HTTP_HOST'] ?? '' ) ) !== strtolower( $canonical_host ) )
            return $deny( 'host' );
        if ( (string) ( $server['HTTPS'] ?? '' ) !== 'on' &&
            (string) ( $server['SERVER_PORT'] ?? '' ) !== '443' ) return $deny( 'transport' );
        if ( ! empty( $server['HTTP_COOKIE'] ) || ! empty( $server['HTTP_AUTHORIZATION'] )
            || ! empty( $server['REDIRECT_HTTP_AUTHORIZATION'] ) || ! empty( $server['PHP_AUTH_USER'] )
            || ! empty( $server['HTTP_X_WP_NONCE'] ) || ! empty( $server['HTTP_X_REQUESTED_WITH'] )
            || ! empty( $server['HTTP_CF_IPCOUNTRY'] ) || ! empty( $server['HTTP_X_COUNTRY_CODE'] )
            || ! empty( $server['HTTP_X_GEO_COUNTRY'] ) ) return $deny( 'private_headers' );
        if ( ! empty( $server['HTTP_SEC_PURPOSE'] ) || ! empty( $server['HTTP_PURPOSE'] ) )
            return $deny( 'speculative_navigation' );
        foreach ( $server as $key => $value ) {
            if ( ! is_string( $key ) || ! is_scalar( $value ) ) continue;
            if ( strpos( $key, 'HTTP_' ) === 0 && (string) $value !== '' &&
                preg_match( '/(?:GEO|COUNTRY|CURRENCY|MARKET|PASSPORT)/i', $key ) )
                return $deny( 'untrusted_market_header' );
            if ( strpos( $key, 'HTTP_X_MAD4B_' ) === 0 && $value !== '' &&
                ! ( $mode === 'canary' && $environment === 'staging'
                    && $key === 'HTTP_X_MAD4B_SCP_PUBLIC_CANARY' && $value === 'v1' ) )
                return $deny( 'tenant_header' );
        }
        if ( $mode === 'canary' && ( $server['HTTP_X_MAD4B_SCP_PUBLIC_CANARY'] ?? '' ) !== 'v1' )
            return $deny( 'canary_header' );
        $uri = $server['REQUEST_URI'] ?? '';
        if ( ! is_string( $uri ) || strlen( $uri ) < 5 || strlen( $uri ) > 256
            || strpos( $uri, '?' ) !== false || strpos( $uri, '%' ) !== false
            || strpos( $uri, '\\' ) !== false || strpos( $uri, '//' ) !== false )
            return $deny( 'uri_shape' );
        if ( ! preg_match( '~^/(?:[a-z]{2}/)?([a-z0-9][a-z0-9-]{1,63})/([a-z0-9][a-z0-9-]{1,159})/$~D', $uri, $match ) )
            return $deny( 'not_singular_public_document' );
        $prefix = $match[1];
        if ( count( $prefixes ) < 1 || count( $prefixes ) > 16 ) return $deny( 'prefix_configuration' );
        foreach ( $prefixes as $allowed ) {
            if ( ! is_string( $allowed ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{1,63}$/D', $allowed ) )
                return $deny( 'prefix_configuration' );
        }
        if ( ! in_array( $prefix, $prefixes, true ) ) return $deny( 'unregistered_document_type' );
        return array( 'eligible' => true, 'reason' => 'strict_public_singular', 'route_type' => $prefix );
    }

    /** Site-bound admission occurs before any reduced loading. */
    public static function try_boot() {
        if ( defined( 'MAD4B_SCP_FORCE_FULL_BOOT' ) && MAD4B_SCP_FORCE_FULL_BOOT === true ) return false;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return false;
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return false;
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) return false;
        if ( defined( 'WP_CLI' ) && WP_CLI ) return false;
        if ( function_exists( 'is_admin' ) && is_admin() ) return false;

        // Site-neutral cheap structural rejection, before any option read.
        // Route prefixes are exclusively site-owned configuration, not code.
        if ( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET'
            || ! empty( $_GET ) || ! empty( $_POST ) || ! empty( $_COOKIE )
            || ! empty( $_FILES ) || ! empty( $_SERVER['QUERY_STRING'] )
            || ! is_string( $_SERVER['REQUEST_URI'] ?? null )
            || ! preg_match( '~^/(?:[a-z]{2}/)?[a-z0-9][a-z0-9-]{1,63}/[a-z0-9][a-z0-9-]{1,159}/$~D',
                $_SERVER['REQUEST_URI'] ) ) return false;

        $mode = get_option( self::MODE_OPTION, 'off' );
        if ( ! in_array( $mode, array( 'on', 'canary' ), true ) ) return false;
        $prefixes = get_option( self::PREFIX_OPTION, array() );
        if ( ! is_array( $prefixes ) ) return false;
        $decision = self::classify( $_SERVER, $_GET, $_POST, $_COOKIE,
            wp_get_environment_type(), $mode, $prefixes,
            (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        if ( empty( $decision['eligible'] ) ) return false;

        // Production rolls out only to independently verified exact canonical
        // paths, not implicitly to all tours under a newly configured prefix.
        if ( $mode === 'on' ) {
            $verified = get_option( self::PATHS_OPTION, array() );
            if ( ! is_array( $verified ) || count( $verified ) < 1 || count( $verified ) > 256 )
                return false;
            $exact = (string) $_SERVER['REQUEST_URI'];
            foreach ( $verified as $candidate ) {
                if ( ! is_string( $candidate ) || strlen( $candidate ) > 256
                    || strpos( $candidate, '?' ) !== false
                    || strpos( $candidate, '%' ) !== false ) return false;
            }
            if ( ! in_array( $exact, $verified, true ) ) return false;
        }

        require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-site-profile.php';
        $status = MAD4B_SCP_Site_Profile::status();
        if ( ! is_array( $status ) || empty( $status['configured'] )
            || empty( $status['origin_match'] ) || empty( $status['environment_match'] )
            || empty( $status['deployment_binding_bound'] ) ||
            empty( $status['deployment_binding_match'] ) ||
            ! empty( $status['foreign_profile_detected'] ) ||
            ! empty( $status['profile_authority_quarantined'] )
            || ! empty( $status['mutation_pending_audit'] )
            || ! empty( $status['host_bootstrap_required'] ) ) return false;

        // Early credential fence, deactivation hook, and Highlights consumer
        // were already registered by the main plugin before this entrypoint.
        // Avoid emitting any permission-bearing ability or management route.
        require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-request-scope.php';
        require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-market-profiles.php';
        require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-switcher-sources.php';
        require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-market-context.php';
        require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-market-preferences.php';
        // The Season Calendar's data methods are usable on public documents.
        // Its boot method is admin-owned and intentionally NOT called here.
        require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-season-calendar.php';

        // Keep original Company Operations Policy owner and write fences even
        // in a nominally public-only request; never synthesize a replacement.
        if ( ! class_exists( 'MAD4B_SCP_Company_Operations_Policy', false ) ) {
            $company_manager_active = in_array(
                'mad4b-company-portfolio-manager/mad4b-company-portfolio-manager.php',
                (array) get_option( 'active_plugins', array() ), true );
            $policy = $company_manager_active
                ? WP_PLUGIN_DIR . '/mad4b-company-portfolio-manager/includes/class-mad4b-scp-company-operations-policy.php'
                : MAD4B_SCP_DIR . 'includes/class-mad4b-scp-company-operations-policy.php';
            if ( ! is_readable( $policy ) ) return false;
            require_once $policy;
        }
        MAD4B_SCP_MCP_Request_Scope::bootstrap();
        MAD4B_SCP_Market_Profiles::boot();
        MAD4B_SCP_Market_Context::boot();
        MAD4B_SCP_Market_Preferences::boot();
        if ( ! defined( 'MAD4B_SCP_EARLY_ZERO_TOUCH_REASON' ) )
            define( 'MAD4B_SCP_EARLY_ZERO_TOUCH_REASON', 'public_document_kernel_v1' );
        return true;
    }
}
