<?php
/**
 * Company Management's bounded, signed-evidence, read-only reconciliation.
 * Uses the original Core Operation Registry and cryptographic receipt verifier.
 * Does NOT read arbitrary server paths, execute shell, modify WordPress or grant authority.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Company_Code_Reconciliation {
    const CONTRACT = 'mad4b.company-code-reconciliation.v1';
    const PLAN = 'mad4b/company-code-reconciliation-plan';
    const COMPARE = 'mad4b/company-code-reconciliation-compare';
    const MAX_FILES = 200;

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 34 );
    }
    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        foreach ( array( self::PLAN => 'plan', self::COMPARE => 'compare' ) as $ability => $handler ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability ) ) continue;
            wp_register_ability( $ability, array(
                'label' => 'Company Code Reconciliation ' . ucfirst( $handler ),
                'description' => 'Compare bounded, original-Core-signed source and target evidence; read-only and never authorizes file transfer.',
                'category' => 'mad4b-read',
                'execute_callback' => array( __CLASS__, $handler ),
                'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
                'input_schema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'company_id','source','target' ),
                    'properties' => array(
                        'company_id' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 96 ),
                        'source' => array( 'type' => 'object' ), 'target' => array( 'type' => 'object' ),
                    ) ),
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array(
                    'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
                ),
            ) );
        }
    }
    private static function fail( $code ) {
        return new WP_Error( 'mad4b_company_code_' . $code, 'Code reconciliation rejected unverified or unsafe evidence.' );
    }
    private static function digest( $value ) {
        $value = self::canon( $value );
        $json = function_exists( 'wp_json_encode' )
            ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
            : json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return is_string( $json ) ? hash( 'sha256', $json ) : '';
    }
    private static function canon( $value ) {
        if ( ! is_array( $value ) ) return $value;
        $keys = array_keys( $value );
        $list = empty( $value ) || $keys === range( 0, count( $value ) - 1 );
        if ( $list ) return array_map( array( __CLASS__, 'canon' ), $value );
        ksort( $value, SORT_STRING );
        foreach ( $value as $key => $item ) $value[ $key ] = self::canon( $item );
        return $value;
    }
    private static function evidence( $value, $company ) {
        if ( ! is_array( $value ) || array_keys( $value ) !== array( 'manifest','receipt' ) ||
             ! is_array( $value['manifest'] ) || ! is_array( $value['receipt'] ) ) return self::fail( 'shape_invalid' );
        $manifest = $value['manifest'];
        if ( ( $manifest['contract'] ?? '' ) !== 'mad4b.company-code-inventory-page.v1' ||
            ( $manifest['company_id'] ?? null ) !== $company ||
            ! is_string( $manifest['site_uuid'] ?? null ) ||
            ! preg_match( '/^[A-Za-z0-9-]{8,80}$/D', $manifest['site_uuid'] ) ||
            ! is_string( $manifest['origin'] ?? null ) ||
            ! preg_match( '~^https://[a-zA-Z0-9.-]{4,180}$~D', $manifest['origin'] ) ||
            ! in_array( $manifest['environment'] ?? '', array( 'staging','development','local' ), true ) ||
            ! is_int( $manifest['page_index'] ?? null ) || $manifest['page_index'] < 0 ||
            ! is_int( $manifest['page_count'] ?? null ) || $manifest['page_count'] < 1 ||
            $manifest['page_index'] >= $manifest['page_count'] ||
            ! is_string( $manifest['inventory_sha256'] ?? null ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $manifest['inventory_sha256'] ) ||
            ! is_array( $manifest['files'] ?? null ) ||
            count( $manifest['files'] ) > self::MAX_FILES ) return self::fail( 'manifest_invalid' );
        $old = '';
        foreach ( $manifest['files'] as $path => $row ) {
            if ( ! is_string( $path ) || strlen( $path ) > 300 ||
                ! preg_match( '~^(?:plugins|mu-plugins|themes|dropins)/[a-zA-Z0-9_./-]+$~D', $path ) ||
                strpos( $path, '..' ) !== false || ( '' !== $old && strcmp( $old, $path ) >= 0 ) ||
                ! is_array( $row ) || ! is_string( $row['sha256'] ?? null ) ||
                ! preg_match( '/^[a-f0-9]{64}$/D', $row['sha256'] ) ||
                ! in_array( $row['owner'] ?? '', array( 'protected', 'site_owned', 'vendor_owned', 'shared_candidate', 'staging_lab', 'not_runtime' ), true ) ) {
                return self::fail( 'file_manifest_invalid' );
            }
            $old = $path;
        }
        if ( ! class_exists( 'MAD4B_SCP_Execution_Receipt' ) ) return self::fail( 'original_core_receipt_verifier_missing' );
        $verified = MAD4B_SCP_Execution_Receipt::verify( $value['receipt'] );
        if ( is_wp_error( $verified ) || ! is_array( $verified ) ||
            ( $verified['valid'] ?? false ) !== true ||
            ( $verified['cryptographic_signature_verified'] ?? false ) !== true ) return self::fail( 'original_core_signature_invalid' );
        $receipt = $value['receipt'];
        // Only original-Core-signed site inventory receipts, not unrelated
        // privileged execution receipts, are eligible to inform this read plan.
        if ( ( $receipt['ability'] ?? null ) !== 'mad4b/company-code-inventory-page' ||
             ( $receipt['provider_id'] ?? null ) !== 'central_company_management' ||
             ( $receipt['approval_required'] ?? true ) !== false ) {
            return self::fail( 'receipt_source_not_inventory_provider' );
        }
        $bound_hash = self::digest( $manifest );
        $target_fingerprint = hash( 'sha256', implode( '|', array( $company, $manifest['site_uuid'], $manifest['origin'] ) ) );
        if ( ! is_string( $receipt['resource_set_sha256'] ?? null ) ||
            ! hash_equals( $bound_hash, $receipt['resource_set_sha256'] ) ||
            ! is_string( $receipt['target_fingerprint'] ?? null ) ||
            ! hash_equals( $target_fingerprint, $receipt['target_fingerprint'] ) ) {
            return self::fail( 'receipt_evidence_binding_mismatch' );
        }
        return $manifest;
    }
    private static function prepare( $input ) {
        if ( ! is_array( $input ) || count( $input ) !== 3 ||
            ! isset( $input['company_id'], $input['source'], $input['target'] ) ||
            ! is_string( $input['company_id'] ) ||
            ! preg_match( '/^[A-Za-z0-9._-]{3,96}$/D', $input['company_id'] ) ) return self::fail( 'company_scope_invalid' );
        $source = self::evidence( $input['source'], $input['company_id'] );
        if ( is_wp_error( $source ) ) return $source;
        $target = self::evidence( $input['target'], $input['company_id'] );
        if ( is_wp_error( $target ) ) return $target;
        if ( $source['site_uuid'] === $target['site_uuid'] || $source['origin'] === $target['origin'] ||
            $source['page_index'] !== $target['page_index'] ||
            $source['page_count'] !== $target['page_count'] ) return self::fail( 'cross_site_or_page_scope_mismatch' );
        // Separate original-Core-signed pages cannot attest a complete site-wide union;
        // never upgrade a page to a complete inventory or an Apply admission.
        return array( 'source' => $source, 'target' => $target );
    }
    public static function plan( $input = array() ) {
        $pair = self::prepare( $input );
        if ( is_wp_error( $pair ) ) return $pair;
        return array(
            'contract' => self::CONTRACT . '.plan',
            'company_id' => $input['company_id'],
            'source_site_uuid' => $pair['source']['site_uuid'],
            'target_site_uuid' => $pair['target']['site_uuid'],
            'source_inventory_sha256' => $pair['source']['inventory_sha256'],
            'target_inventory_sha256' => $pair['target']['inventory_sha256'],
            'page_index' => $pair['source']['page_index'],
            'page_count' => $pair['source']['page_count'],
            'source_page_sha256' => self::digest( $pair['source'] ),
            'target_page_sha256' => self::digest( $pair['target'] ),
            'state' => 'ORIGINAL_CORE_SIGNED_PAGES_VALIDATED',
            'complete_inventory_verified' => false,
            'authorizing' => false,
            'mutation_performed' => false
        );
    }
    public static function compare( $input = array() ) {
        $pair = self::prepare( $input );
        if ( is_wp_error( $pair ) ) return $pair;
        $s = $pair['source']['files']; $t = $pair['target']['files'];
        $keys = array_unique( array_merge( array_keys( $s ), array_keys( $t ) ) );
        sort( $keys, SORT_STRING );
        if ( count( $keys ) > self::MAX_FILES * 2 ) return self::fail( 'file_union_limit' );
        $rows = array(); $stats = array( 'exact'=>0,'different'=>0,'source_only'=>0,'target_only'=>0 );
        foreach ( $keys as $path ) {
            $a = $s[ $path ] ?? null; $b = $t[ $path ] ?? null;
            $presence = $a && $b ? ( hash_equals( $a['sha256'], $b['sha256'] ) ? 'exact' : 'different' ) : ( $a ? 'source_only' : 'target_only' );
            $stats[ $presence ]++;
            $owners = array_filter( array( $a['owner'] ?? '', $b['owner'] ?? '' ) );
            if ( in_array( 'protected', $owners, true ) ) $decision = 'PROTECT_READ_ONLY';
            elseif ( in_array( 'site_owned', $owners, true ) ) $decision = 'PRESERVE_SITE_AND_REVIEW_COMPATIBILITY';
            elseif ( in_array( 'vendor_owned', $owners, true ) ) $decision = 'VENDOR_LIFECYCLE_ONLY';
            elseif ( in_array( 'staging_lab', $owners, true ) ) $decision = 'ISOLATE_LAB_NO_TRANSFER';
            elseif ( in_array( 'not_runtime', $owners, true ) ) $decision = 'PRESERVE_ROLLBACK_NOT_RUNTIME';
            elseif ( ! $a ) $decision = 'PRESERVE_TARGET_UNTIL_OWNERSHIP_REVIEW';
            elseif ( ! $b ) $decision = 'REVIEW_PRODUCT_ACTIVATION_AND_DEPENDENCIES';
            elseif ( 'exact' === $presence ) $decision = 'NOOP_VERIFIED';
            else $decision = 'PLAN_PROVIDER_SEMANTIC_DIFF';
            $rows[] = array( 'path'=>$path,'presence'=>$presence,'decision'=>$decision,
                'source_sha256'=>$a['sha256'] ?? null,'target_sha256'=>$b['sha256'] ?? null );
        }
        $plan = self::plan( $input );
        if ( is_wp_error( $plan ) ) return $plan;
        return array(
            'contract' => self::CONTRACT . '.page-result',
            'company_id' => $input['company_id'], 'page' => $plan,
            'presence' => $stats, 'diff_rows' => $rows,
            'state' => 'PAGE_ONLY_REQUIRES_COMPLETE_PROVIDER_COVERAGE',
            'page_results_are_not_full_inventory' => true,
            'decision' => 'REVIEW_REQUIRED_NO_APPLY',
            'company_and_site_authority_verified' => false,
            'native_apply_authorized' => false,
            'production_mutation' => false,
            'writes_to_sites' => false
        );
    }
}
