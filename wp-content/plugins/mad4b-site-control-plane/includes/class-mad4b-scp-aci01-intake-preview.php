<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ACI01 first runtime slice: bounded, read-only site/content intent preflight.
 * Reuses Site Profile and Content Experience discovery; never issues authority.
 */
final class MAD4B_SCP_ACI01_Intake_Preview {
    const CONTRACT = 'mad4b.aci01.intake-preview.v1';
    const MAX_POST_TYPES = 80;
    const MAX_TAXONOMIES = 32;
    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 42 );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/aci01-intake-preview' ) ) return;
        wp_register_ability( 'mad4b/aci01-intake-preview', array(
            'label' => 'ACI01 Read-Only Content Intake Preview',
            'description' => 'Inspect exact native post type and suggest bounded content evidence steps; no operations or mutation.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'preview' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array(
                'type' => 'object', 'additionalProperties' => false,
                'properties' => array(
                    'post_type' => array( 'type' => 'string', 'maxLength' => 64 ),
                    'brand_id' => array( 'type' => 'string', 'maxLength' => 80 ),
                    'locale' => array( 'type' => 'string', 'maxLength' => 32 ),
                    'market' => array( 'type' => 'string', 'maxLength' => 2 ),
                ),
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }

    public static function preview( $input = array() ) {
        if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_read() ) {
            return self::denied( 'read_permission_missing' );
        }
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ) ) {
            return self::denied( 'required_existing_provider_missing' );
        }
        // The site profile is bootstrapped by the existing governed kernel.
        if ( ! MAD4B_SCP_Site_Profile::configured() ) return self::denied( 'site_not_enrolled' );
        $site = array(
            'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
            'origin' => MAD4B_SCP_Site_Profile::site_origin(),
            'environment' => MAD4B_SCP_Site_Profile::current_environment(),
        );
        $inventory = MAD4B_SCP_Content_Experience_Profiles::discover( array() );
        return self::plan_from_discovery( $input, $site, $inventory );
    }

    /** Pure adapter: useful for disposable PHP fixtures, no WordPress read/write. */
    public static function plan_from_discovery( $input, $site, $inventory ) {
        if ( ! is_array( $input ) || ! is_array( $site ) || ! is_array( $inventory ) ) {
            return self::denied( 'input_shape_invalid' );
        }
        $allowed = array( 'post_type', 'brand_id', 'locale', 'market' );
        foreach ( $input as $key => $value ) {
            if ( ! in_array( $key, $allowed, true ) || ! is_string( $value ) ) {
                return self::denied( 'unrecognized_or_untyped_input' );
            }
        }
        $uuid = isset( $site['site_uuid'] ) ? (string) $site['site_uuid'] : '';
        $origin = isset( $site['origin'] ) ? (string) $site['origin'] : '';
        $environment = isset( $site['environment'] ) ? (string) $site['environment'] : '';
        if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid ) ||
             ! self::https_origin( $origin ) || ! in_array( $environment, array( 'development', 'staging', 'production', 'disposable' ), true ) ) {
            return self::denied( 'site_identity_not_verified' );
        }
        $brand = isset( $input['brand_id'] ) ? $input['brand_id'] : '';
        $locale = isset( $input['locale'] ) ? $input['locale'] : '';
        $market = isset( $input['market'] ) ? strtoupper( $input['market'] ) : '';
        $post_type = isset( $input['post_type'] ) ? $input['post_type'] : '';
        if ( ( '' !== $brand && ! preg_match( '/^[A-Za-z0-9_.:-]{1,80}$/', $brand ) ) ||
             ( '' !== $locale && ! preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,3}$/', $locale ) ) ||
             ( '' !== $market && ! preg_match( '/^[A-Z]{2}$/', $market ) ) ||
             ( '' !== $post_type && ! preg_match( '/^[a-z0-9_-]{1,64}$/', $post_type ) ) ) {
            return self::denied( 'invalid_target_dimension' );
        }
        if ( ! isset( $inventory['post_types'] ) || ! is_array( $inventory['post_types'] ) ||
             count( $inventory['post_types'] ) > self::MAX_POST_TYPES ) {
            return self::denied( 'inventory_missing_or_unbounded' );
        }
        $catalog = array();
        foreach ( $inventory['post_types'] as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['post_type'] ) || ! is_string( $item['post_type'] ) ||
                 ! preg_match( '/^[a-z0-9_-]{1,64}$/', $item['post_type'] ) || isset( $catalog[ $item['post_type'] ] ) ) {
                return self::denied( 'inventory_identity_invalid' );
            }
            if ( empty( $item['show_ui'] ) || ( empty( $item['can_create'] ) && empty( $item['can_publish'] ) ) ) continue;
            $catalog[ $item['post_type'] ] = $item;
        }
        ksort( $catalog, SORT_STRING );
        if ( '' !== $post_type && ! isset( $catalog[ $post_type ] ) ) return self::denied( 'post_type_not_accessible' );
        $selected = '' !== $post_type ? $catalog[ $post_type ] : null;
        $reasons = array();
        if ( '' === $post_type ) $reasons[] = 'select_exact_post_type';
        if ( '' === $brand ) $reasons[] = 'brand_context_missing';
        if ( '' === $locale ) $reasons[] = 'locale_missing';
        if ( '' === $market ) $reasons[] = 'market_missing';
        $taxonomies = array();
        if ( $selected ) {
            $items = isset( $selected['taxonomies'] ) ? $selected['taxonomies'] : array();
            if ( ! is_array( $items ) || count( $items ) > self::MAX_TAXONOMIES ) return self::denied( 'taxonomy_inventory_unbounded' );
            foreach ( $items as $tax ) {
                if ( ! is_array( $tax ) || ! isset( $tax['name'] ) || ! is_string( $tax['name'] ) ||
                     ! preg_match( '/^[a-z0-9_-]{1,64}$/', $tax['name'] ) ) return self::denied( 'taxonomy_identity_invalid' );
                $taxonomies[] = $tax['name'];
            }
            $taxonomies = array_values( array_unique( $taxonomies ) );
            sort( $taxonomies, SORT_STRING );
        }
        // Site discovery reveals a *candidate*, never translates IDs or certifies WPML.
        $requires_relation_review = count( $taxonomies ) > 0;
        if ( $requires_relation_review ) $reasons[] = 'native_relation_policy_unverified';
        $reasons[] = 'governed_brand_and_source_receipts_not_supplied';
        $reasons = array_values( array_unique( $reasons ) );
        sort( $reasons, SORT_STRING );
        $status = '' === $post_type ? 'NEEDS_REVIEW' : 'NEEDS_EVIDENCE';
        $scope = array( 'site_uuid' => $uuid, 'origin' => $origin, 'environment' => $environment,
                        'brand_id' => $brand, 'locale' => $locale, 'market' => $market );
        $candidate = array( 'post_type' => $post_type, 'content_recipe_key' => $selected ? 'native:' . $post_type : null,
                            'taxonomies' => $taxonomies, 'requires_native_relation_review' => $requires_relation_review );
        return array(
            'contract' => self::CONTRACT, 'status' => $status,
            'scope' => $scope, 'candidate' => $candidate,
            'available_post_types' => array_keys( $catalog ),
            'stages' => array(
                array( 'id' => 'site_discovery', 'status' => 'OBSERVED' ),
                array( 'id' => 'evidence_pack', 'status' => 'NEEDS_EVIDENCE' ),
                array( 'id' => 'blueprint', 'status' => 'WAITING_DEPENDENCIES' ),
                array( 'id' => 'draft_qa', 'status' => 'WAITING_DEPENDENCIES' ),
                array( 'id' => 'operator_review', 'status' => 'NEEDS_REVIEW' ),
            ),
            'reason_codes' => $reasons,
            'review_ticket' => array( 'target' => $candidate['content_recipe_key'], 'required_decisions' => $reasons ),
            'source' => 'EXISTING_WORDPRESS_NATIVE_DISCOVERY', 'evidence_trust' => 'UNVERIFIED_READ_ONLY',
            'authority_checked' => false, 'authorizing' => false, 'eligible_for_mutation' => false,
            'mutation_performed' => false, 'external_provider_called' => false, 'paid_calls' => 0,
            'plan_fingerprint_sha256' => hash( 'sha256', json_encode( array( $scope, $candidate, $reasons ) ) ),
        );
    }

    private static function https_origin( $origin ) {
        if ( ! is_string( $origin ) || strlen( $origin ) > 255 ) return false;
        $url = parse_url( $origin );
        return is_array( $url ) && isset( $url['scheme'], $url['host'] ) &&
            strtolower( $url['scheme'] ) === 'https' && ! isset( $url['user'] ) && ! isset( $url['pass'] ) &&
            ! isset( $url['query'] ) && ! isset( $url['fragment'] ) &&
            ( ! isset( $url['path'] ) || $url['path'] === '' || $url['path'] === '/' ) &&
            ! preg_match( '/[\\s\\x00-\\x1f]/', $origin );
    }

    private static function denied( $reason ) {
        return array( 'contract' => self::CONTRACT, 'status' => 'DENIED',
                      'reason_codes' => array( $reason ), 'authorizing' => false,
                      'eligible_for_mutation' => false, 'mutation_performed' => false,
                      'external_provider_called' => false, 'paid_calls' => 0 );
    }
}
