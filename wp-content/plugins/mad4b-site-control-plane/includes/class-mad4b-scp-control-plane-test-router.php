<?php
/**
 * Read-only, site-neutral route choice for testing a certified Control Plane
 * update on an enrolled Staging installation.
 *
 * This is not an installer, approval ticket, CI attestation, or updater.
 * Native and Selected HEAD plans remain the authoritative preflight sources.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Control_Plane_Test_Router {
    const ABILITY = 'mad4b/control-plane-test-route';
    const CONTRACT = 'mad4b.control-plane-test-route.v1';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' )
            || ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Choose Governed Control Plane Test Route',
            'description' => 'Read-only selection of eligible certified Staging package route and exact next steps; never installs, approves, bypasses lineage or grants authority.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'inspect' ),
            'permission_callback' => array( __CLASS__, 'can_read' ),
            'input_schema' => array(
                'type' => 'object', 'additionalProperties' => false, 'properties' => array(),
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }

    public static function can_read() {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' )
            && class_exists( 'MAD4B_SCP_Policy' )
            && true === MAD4B_SCP_Policy::can_read();
    }

    private static function error_code( $value ) {
        return function_exists( 'is_wp_error' ) && is_wp_error( $value )
            ? $value->get_error_code() : 'preflight_unavailable';
    }

    private static function blockers( $plan ) {
        if ( ! is_array( $plan ) ) return array( self::error_code( $plan ) );
        $result = array();
        foreach ( (array) ( $plan['blockers'] ?? array() ) as $code ) {
            if ( is_string( $code ) && preg_match( '/^[a-z0-9_.:-]{1,128}$/D', $code ) )
                $result[] = $code;
        }
        return array_slice( array_values( array_unique( $result ) ), 0, 12 );
    }

    private static function signed_target( $native ) {
        if ( ! is_array( $native ) ) return '';
        $target = $native['target'] ?? array();
        if ( ! is_array( $target )
            || true !== ( $target['published_from_master'] ?? false )
            || true !== ( $target['release_root_trust_verified'] ?? false )
            || true !== ( $target['release_verdict_success'] ?? false ) ) return '';
        $sha = $target['source_commit_sha'] ?? '';
        $digest = $target['archive_sha256'] ?? '';
        return is_string( $sha ) && preg_match( '/^[a-f0-9]{40}$/D', $sha )
            && is_string( $digest ) && preg_match( '/^[a-f0-9]{64}$/D', $digest )
            ? $sha : '';
    }

    /**
     * Pure reducer for isolated regression testing. Callers cannot supply
     * plans through MCP: inspect() always obtains them from local providers.
     */
    public static function reduce( $native, $selected, $environment ) {
        $staging = 'staging' === $environment;
        $source = self::signed_target( $native );
        $native_blockers = self::blockers( $native );
        $selected_blockers = self::blockers( $selected );
        $native_ready = $staging && '' !== $source && is_array( $native )
            && true === ( $native['eligible'] ?? false )
            && empty( $native_blockers );
        $selected_ready = $staging && '' !== $source && is_array( $selected )
            && true === ( $selected['eligible'] ?? false )
            && empty( $selected_blockers )
            && ( $selected['source']['resolved_sha'] ?? '' ) === $source
            && ( $selected['package_identity']['source_commit_sha'] ?? '' ) === $source;
        $routes = array(
            array(
                'id' => 'certified_selected_head', 'plan_ability' => 'mad4b/control-plane-selected-head-plan',
                'apply_ability' => 'mad4b/control-plane-selected-head-apply',
                'state' => $selected_ready ? 'eligible' : 'blocked',
                'blockers' => $selected_blockers,
                'requires_exact_owner_approval' => true,
            ),
            array(
                'id' => 'governed_native_release', 'plan_ability' => 'mad4b/control-plane-native-plan',
                'apply_ability' => 'mad4b/control-plane-native-apply',
                'state' => $native_ready ? 'eligible' : 'blocked',
                'blockers' => $native_blockers,
                'requires_exact_owner_approval' => true,
            ),
        );
        $recommended = $selected_ready ? $routes[0] : ( $native_ready ? $routes[1] : null );
        if ( ! $staging ) {
            $state = 'environment_blocked';
            $next = 'mad4b/site-profile-status';
            $action = 'Inspect the exact enrolled Staging profile. Never install on Production.';
        } elseif ( '' === $source ) {
            $state = 'certified_source_unavailable';
            $next = 'mad4b/control-plane-update-status';
            $action = 'Inspect the signed release manifest; do not substitute an unverified ZIP or branch.';
        } elseif ( $recommended ) {
            $state = 'eligible_for_separate_governed_review';
            $next = $recommended['plan_ability'];
            $action = 'Review a fresh exact plan and obtain independent owner OAuth/approval before any apply.';
        } else {
            $state = 'preflight_blocked';
            $next = 'mad4b/recovery-route-matrix';
            $action = 'Inspect the original route blockers and approved recovery plans; never bypass a failed gate.';
            if ( in_array( 'selected_head_opt_in_disabled', $selected_blockers, true ) ) {
                $next = 'mad4b/wordpress-native-candidate-opt-in-plan';
                $action = 'Review optional Staging-only enrollment. This does not authorize installation.';
            } elseif ( in_array( 'native_release_source_lineage_unverified', $native_blockers, true ) ) {
                $next = 'mad4b/control-plane-selected-head-plan';
                $action = 'Review the separately governed Selected HEAD route; unknown ancestry is not an approval.';
            }
        }
        return array(
            'contract' => self::CONTRACT,
            'state' => $state,
            'environment' => $environment,
            'certified_source_sha' => $source,
            'selected_route_id' => $recommended ? $recommended['id'] : null,
            'selected_plan_ability' => $recommended ? $recommended['plan_ability'] : '',
            'next_read_ability' => $next,
            'next_action' => $action,
            'routes' => $routes,
            'acceptance_readback_abilities' => array(
                'mad4b/control-plane-update-status', 'mad4b/build-provenance-status',
                'mad4b/session-safe-diagnostics', 'mad4b/skill-runtime-certification',
                'mad4b/staging-certification-status',
            ),
            'readback_complete' => false, 'runtime_certified' => false,
            'separate_exact_plan_required' => true, 'independent_owner_approval_required' => true,
            'automatic_mutation_allowed' => false, 'production_allowed' => false,
            'permission_reuse_allowed' => false, 'original_blockers_preserved' => true,
            'read_only' => true, 'mutation_performed' => false,
        );
    }

    public static function inspect( $input = array() ) {
        if ( ! self::can_read() )
            return new WP_Error( 'mad4b_test_route_read_denied', 'Admin governed read authority is required.' );
        if ( ! is_array( $input ) || ! empty( $input ) )
            return new WP_Error( 'mad4b_test_route_input_invalid', 'Only live local preflight is accepted.' );
        $env = function_exists( 'wp_get_environment_type' )
            ? (string) wp_get_environment_type() : 'unknown';
        if ( 'staging' !== $env ) return self::reduce( array(), array(), $env );
        if ( ! class_exists( 'MAD4B_SCP_Self_Update' )
            || ! class_exists( 'MAD4B_SCP_Selected_Head_Update' ) )
            return self::reduce( array(), array(), $env );
        $native = MAD4B_SCP_Self_Update::native_plan(
            array( 'reason' => 'Read-only selection of exact certified Staging test route' ) );
        $source = self::signed_target( $native );
        $selected = array( 'blockers' => array( 'certified_source_unavailable' ) );
        if ( '' !== $source ) {
            $selected = MAD4B_SCP_Selected_Head_Update::plan( array(
                'candidate_source' => array(
                    'repository' => 'mad4bdigital-ai/WordPress',
                    'type' => 'commit', 'reference' => $source,
                ),
                'reason' => 'Read-only certified Staging test route preflight',
            ) );
        }
        return self::reduce( $native, $selected, $env );
    }

    /** Explicit admin-only read, never a passive-page network operation. */
    public static function render_admin_panel() {
        if ( ! self::can_read() ) return;
        echo '<h2>Control Plane certified test route</h2>';
        echo '<p>Choose a verified Staging route from live WordPress plans. No installer or authority is invoked.</p>';
        $url = wp_nonce_url( add_query_arg( 'mad4b_test_route', '1' ), 'mad4b_test_route_read' );
        echo '<p><a class="button" href="' . esc_url( $url ) . '">Check certified test route</a></p>';
        if ( ! isset( $_GET['mad4b_test_route'] ) ) return;
        check_admin_referer( 'mad4b_test_route_read' );
        $report = self::inspect();
        if ( function_exists( 'is_wp_error' ) && is_wp_error( $report ) ) {
            echo '<p>' . esc_html( $report->get_error_code() ) . '</p>';
            return;
        }
        echo '<p><strong>State:</strong> <code>' . esc_html( $report['state'] ) . '</code></p>';
        echo '<p><strong>Certified SHA:</strong> <code>' . esc_html( $report['certified_source_sha'] ?: 'unavailable' ) . '</code></p>';
        echo '<p><strong>Selected route:</strong> <code>'
            . esc_html( $report['selected_route_id'] ?: 'none' ) . '</code></p>';
        foreach ( $report['routes'] as $route ) {
            echo '<p><code>' . esc_html( $route['id'] ) . '</code>: '
                . esc_html( $route['state'] ) . '; blockers: '
                . esc_html( $route['blockers'] ? implode( ', ', $route['blockers'] ) : 'none' ) . '</p>';
        }
        echo '<p><strong>Next read/plan:</strong> <code>'
            . esc_html( $report['next_read_ability'] ) . '</code></p>';
        echo '<p>' . esc_html( $report['next_action'] ) . '</p>';
        echo '<p>Separate exact approval and independent runtime acceptance remain required.</p>';
    }
}
