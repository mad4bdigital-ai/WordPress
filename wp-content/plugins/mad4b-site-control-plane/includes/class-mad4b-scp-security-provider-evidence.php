<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded read-only federation of existing security provider APIs.
 *
 * Never reads vendor tables/options, issues generic HTTP/shell commands,
 * starts a scan, changes a plugin, or creates an authentication grant.
 * A missing or denied source is NOT a clean security finding.
 */
final class MAD4B_SCP_Security_Provider_Evidence {
    const CONTRACT = 'mad4b.security-provider-evidence.v1';
    const MAX_FINDINGS = 10;
    const MAX_COMPONENTS = 15;
    const MAX_SCANNED_COMPONENTS = 250;

    private static function unavailable( $provider, $reason, $source = '' ) {
        return array(
            'contract' => self::CONTRACT, 'provider_id' => $provider,
            'state' => 'unavailable', 'reason' => $reason,
            'source' => $source, 'read_only' => true,
            'complete' => false, 'site_clean_certified' => false,
            'enforcement_verified' => false, 'findings' => array(),
        );
    }

    private static function positive_int( $value ) {
        return is_numeric( $value ) && (int) $value >= 0 ? min( 1000000, (int) $value ) : null;
    }

    private static function registered_read_ability( $name ) {
        if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' )
            || ! wp_has_ability( $name ) ) return null;
        $ability = wp_get_ability( $name );
        return is_object( $ability ) && method_exists( $ability, 'execute' ) ? $ability : null;
    }

    private static function activity_log( $period ) {
        $provider = 'security-activity-log';
        if ( ! class_exists( 'AAL_Abilities', false )
            || ! method_exists( 'AAL_Abilities', 'is_agent_exposure_enabled' )
            || ! AAL_Abilities::is_agent_exposure_enabled() )
            return self::unavailable( $provider, 'activity_log_agent_exposure_disabled', 'activity-log/Abilities' );

        $summary_ability = self::registered_read_ability( 'activity-log/summarize-activity' );
        $suspicious_ability = self::registered_read_ability( 'activity-log/detect-suspicious-events' );
        if ( ! $summary_ability || ! $suspicious_ability )
            return self::unavailable( $provider, 'native_read_abilities_unavailable', 'activity-log/Abilities' );

        $input = array( 'period' => $period );
        $summary = $summary_ability->execute( $input );
        if ( is_wp_error( $summary ) || ! is_array( $summary ) || ! isset( $summary['total'] )
            || null === self::positive_int( $summary['total'] ) )
            return self::unavailable( $provider, 'native_summary_read_denied_or_invalid', 'activity-log/summarize-activity' );
        $suspicious = $suspicious_ability->execute( $input );
        if ( is_wp_error( $suspicious ) || ! is_array( $suspicious )
            || ! isset( $suspicious['findings'] ) || ! is_array( $suspicious['findings'] ) )
            return self::unavailable( $provider, 'native_suspicious_read_denied_or_invalid', 'activity-log/detect-suspicious-events' );

        $findings = array();
        foreach ( array_slice( $suspicious['findings'], 0, self::MAX_FINDINGS ) as $row ) {
            if ( ! is_array( $row ) ) continue;
            $rule = isset( $row['rule'] ) ? (string) $row['rule'] : '';
            $severity = isset( $row['severity'] ) ? (string) $row['severity'] : '';
            if ( ! preg_match( '/^[a-z][a-z0-9_]{1,63}$/D', $rule )
                || ! in_array( $severity, array( 'high', 'medium', 'low', 'critical' ), true ) ) continue;
            $findings[] = array(
                'rule' => $rule, 'severity' => $severity,
                'count' => self::positive_int( $row['count'] ?? 0 ),
            );
        }
        $channels = array();
        if ( isset( $summary['by_channel'] ) && is_array( $summary['by_channel'] ) ) {
            foreach ( array( 'admin', 'rest', 'cli', 'cron', 'xmlrpc', 'abilities' ) as $channel ) {
                if ( isset( $summary['by_channel'][ $channel ] ) ) {
                    $number = self::positive_int( $summary['by_channel'][ $channel ] );
                    if ( null !== $number ) $channels[ $channel ] = $number;
                }
            }
        }
        return array(
            'contract' => self::CONTRACT, 'provider_id' => $provider,
            'state' => 'observed', 'source' => 'native_wordpress_abilities',
            'period' => $period, 'events_total' => self::positive_int( $summary['total'] ),
            'channels' => $channels, 'findings' => $findings,
            'findings_reported' => count( $suspicious['findings'] ),
            'truncated' => ! empty( $suspicious['truncated'] )
                || count( $suspicious['findings'] ) > self::MAX_FINDINGS,
            'read_only' => true, 'complete' => false,
            'site_clean_certified' => false, 'enforcement_verified' => false,
            'personal_data_returned' => false,
        );
    }

    private static function wpvulnerability( $surface ) {
        $provider = 'security-wpvulnerability';
        // Route is owned by WPVulnerability. Local REST dispatch executes its
        // permission_callback as the current user and may trigger provider network I/O.
        if ( ! class_exists( 'WP_REST_Request' ) || ! function_exists( 'rest_get_server' )
            || ! function_exists( 'rest_do_request' ) )
            return self::unavailable( $provider, 'wordpress_rest_unavailable', 'wpvulnerability/v1' );
        $route = '/wpvulnerability/v1/' . $surface;
        $server = rest_get_server();
        if ( ! is_object( $server ) || ! method_exists( $server, 'get_routes' ) )
            return self::unavailable( $provider, 'wordpress_rest_unavailable', 'wpvulnerability/v1' );
        $routes = $server->get_routes();
        if ( ! is_array( $routes ) || ! isset( $routes[ $route ] ) )
            return self::unavailable( $provider, 'provider_route_not_registered', $route );
        $response = rest_do_request( new WP_REST_Request( 'GET', $route ) );
        if ( is_wp_error( $response ) || ! is_object( $response )
            || ! method_exists( $response, 'get_status' ) || ! method_exists( $response, 'get_data' )
            || 200 !== (int) $response->get_status() )
            return self::unavailable( $provider, 'provider_rest_denied_or_failed', $route );
        $data = $response->get_data();
        if ( ! is_array( $data ) ) return self::unavailable( $provider, 'provider_payload_unrecognized', $route );
        // Official plugin response is a list of affected components, each with
        // vulnerabilities[]. Never expose vendor descriptions, CVE links or slugs.
        if ( array_keys( $data ) !== range( 0, count( $data ) - 1 ) && ! empty( $data ) )
            return self::unavailable( $provider, 'provider_payload_unrecognized', $route );
        $affected = 0;
        $vulnerabilities = 0;
        $samples = array();
        $processed = 0;
        foreach ( array_slice( $data, 0, self::MAX_SCANNED_COMPONENTS ) as $component ) {
            if ( ! is_array( $component ) || ! isset( $component['vulnerabilities'] )
                || ! is_array( $component['vulnerabilities'] ) )
                return self::unavailable( $provider, 'provider_payload_unrecognized', $route );
            ++$affected;
            $count = count( $component['vulnerabilities'] );
            $vulnerabilities += $count;
            if ( $processed++ < self::MAX_COMPONENTS ) {
                $samples[] = array( 'vulnerability_count' => min( 1000000, $count ) );
            }
        }
        return array(
            'contract' => self::CONTRACT, 'provider_id' => $provider,
            'state' => 'observed', 'source' => 'provider_local_rest',
            'surface' => $surface, 'affected_components' => $affected,
            'components_reported' => count( $data ),
            'vulnerabilities_reported' => $vulnerabilities,
            'component_samples' => $samples,
            'truncated' => count( $data ) > self::MAX_COMPONENTS,
            'aggregation_complete' => count( $data ) <= self::MAX_SCANNED_COMPONENTS,
            'underlying_provider_network_possible' => true,
            'read_only' => true, 'complete' => false,
            'site_clean_certified' => false, 'enforcement_verified' => false,
            'personal_data_returned' => false,
        );
    }

    private static function wordfence() {
        $provider = 'security-wordfence';
        // Wordfence itself has no verified stable read Abilities contract.
        // Optional third-party companion: never auto-install or trust it
        // merely because Wordfence is active; use read-only names only.
        $names = array(
            'issues' => 'wpcli-for-wordfence/issues-count',
            'scan' => 'wpcli-for-wordfence/scan-status',
            'firewall' => 'wpcli-for-wordfence/firewall-status',
        );
        $abilities = array();
        foreach ( $names as $kind => $name ) {
            $abilities[ $kind ] = self::registered_read_ability( $name );
            if ( ! $abilities[ $kind ] )
                return self::unavailable( $provider, 'unreviewed_or_missing_optional_companion', 'wpcli-for-wordfence' );
        }
        $issues = $abilities['issues']->execute( array() );
        $scan = $abilities['scan']->execute( array() );
        $firewall = $abilities['firewall']->execute( array() );
        if ( is_wp_error( $issues ) || is_wp_error( $scan ) || is_wp_error( $firewall )
            || ! is_array( $issues ) || ! is_array( $scan ) || ! is_array( $firewall )
            || ! isset( $issues['new'], $scan['running'], $firewall['enabled'] )
            || null === self::positive_int( $issues['new'] )
            || ! is_bool( $scan['running'] ) || ! is_bool( $firewall['enabled'] ) )
            return self::unavailable( $provider, 'optional_companion_read_failed', 'wpcli-for-wordfence' );
        return array(
            'contract' => self::CONTRACT, 'provider_id' => $provider,
            'state' => 'observed_unattested', 'source' => 'third_party_companion_abilities',
            'issues_new' => self::positive_int( $issues['new'] ),
            'scan_running' => $scan['running'],
            'last_scan_timestamp' => self::positive_int( $scan['last_scan_timestamp'] ?? 0 ),
            'firewall_reports_enabled' => $firewall['enabled'],
            'read_only' => true, 'complete' => false,
            'companion_behavior_certified' => false,
            'site_clean_certified' => false, 'enforcement_verified' => false,
            'personal_data_returned' => false,
        );
    }

    public static function collect( $provider, $period = '24h', $surface = 'plugins' ) {
        if ( ! in_array( $provider, array( 'security-wordfence', 'security-wpvulnerability', 'security-activity-log' ), true ) )
            return self::unavailable( $provider, 'provider_not_allowlisted' );
        if ( ! in_array( $period, array( '24h', '7d' ), true )
            || ! in_array( $surface, array( 'core', 'plugins', 'themes' ), true ) )
            return self::unavailable( $provider, 'input_invalid' );
        if ( ! class_exists( 'MAD4B_SCP_Official_Security_Packages', false ) )
            require_once __DIR__ . '/class-mad4b-scp-official-security-packages.php';
        $inventory = MAD4B_SCP_Official_Security_Packages::inventory();
        if ( empty( $inventory['inventory_available'] ) )
            return self::unavailable( $provider, 'plugin_inventory_unavailable' );
        $matching = null;
        foreach ( $inventory['providers'] as $item ) {
            if ( $provider === $item['provider_id'] ) $matching = $item;
        }
        if ( ! is_array( $matching ) || ! empty( $matching['identity_ambiguous'] ) )
            return self::unavailable( $provider, 'plugin_identity_ambiguous' );
        if ( empty( $matching['installed'] ) )
            return self::unavailable( $provider, 'plugin_not_installed' );
        if ( empty( $matching['site_active'] ) && empty( $matching['network_active'] ) )
            return self::unavailable( $provider, 'plugin_not_active' );
        if ( 'security-activity-log' === $provider ) return self::activity_log( $period );
        if ( 'security-wpvulnerability' === $provider ) return self::wpvulnerability( $surface );
        return self::wordfence();
    }
}
