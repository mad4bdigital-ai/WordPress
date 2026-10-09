<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site-local market intelligence and supplier/media governance.
 * Discovery, competitive prices and remote image URLs are NEVER licenses.
 * All evaluation methods are read-only; mutation is limited to admin policy
 * configuration, guarded by exact revision, confirmation and readback.
 */
final class MAD4B_SCP_Market_Growth_Policies {
    const CONTRACT = 'mad4b.market-growth-policies.v1';
    const OPTION = 'mad4b_scp_market_growth_policies_v1';
    const LOCK = 'mad4b_scp_market_growth_policy_writer_lock_v1';
    const MAX_BYTES = 65536;
    const MAX_RULES = 100;
    const MODES = array( 'match_market', 'markup_percent', 'discount_percent', 'markup_fixed', 'discount_fixed', 'fixed_price' );
    const RIGHTS = array( 'unknown', 'first_party', 'public_domain', 'licensed', 'permission_granted', 'restricted' );

    private static function error( $code, $message ) { return new WP_Error( $code, $message ); }

    public static function defaults() {
        return array(
            'contract' => self::CONTRACT,
            'revision' => 0,
            'suppliers' => array(),
            'competitors' => array(),
            'dmc_connections' => array(),
            'feed_mappings' => array(),
            'pricing_rules' => array(),
            'media_rules' => array(),
            'assistant_roles' => array(),
            'updated_at' => '',
        );
    }

    public static function current() {
        $data = get_option( self::OPTION, array() );
        if ( ! is_array( $data ) || ( isset( $data['contract'] ) && self::CONTRACT !== (string) $data['contract'] ) ) {
            return self::error( 'mad4b_growth_registry_corrupt', 'Market rules are unavailable; repair the registry without resetting authority.' );
        }
        $current = array_merge( self::defaults(), $data );
        foreach ( array( 'suppliers', 'competitors', 'dmc_connections', 'feed_mappings', 'pricing_rules', 'media_rules', 'assistant_roles' ) as $key ) {
            if ( ! is_array( $current[ $key ] ) ) return self::error( 'mad4b_growth_registry_corrupt', 'Invalid market rules structure.' );
        }
        return $current;
    }

    public static function checksum( $data ) {
        if ( ! is_array( $data ) ) return '';
        $encoded = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
    }

    public static function status() {
        $current = self::current();
        if ( is_wp_error( $current ) ) return $current;
        return array(
            'contract' => self::CONTRACT,
            'revision' => (int) $current['revision'],
            'registry_sha256' => self::checksum( $current ),
            // Display only public rule metadata. Agreement references, API
            // endpoints and confidential unit costs remain in the guarded
            // registry and must not be returned to generic read clients.
            'suppliers' => self::public_fields( $current['suppliers'], array( 'display_name', 'source_url', 'commercial_status', 'valid_until' ) ),
            'competitors' => self::public_fields( $current['competitors'], array( 'display_name', 'source_url', 'disabled' ) ),
            'dmc_connections' => self::public_fields( $current['dmc_connections'], array( 'supplier_id', 'direction' ) ),
            'feed_mappings' => self::public_fields( $current['feed_mappings'], array( 'post_type', 'direction', 'fields' ) ),
            'pricing_rules' => self::public_fields( $current['pricing_rules'], array( 'mode', 'currency', 'basis_points', 'amount_minor' ) ),
            'media_rules' => self::public_fields( $current['media_rules'], array( 'source_url', 'rights_status', 'valid_until' ) ),
            'assistant_roles' => self::public_fields( $current['assistant_roles'], array( 'role', 'skill_name' ) ),
            'no_implicit_copyright_waiver' => true,
            'no_automatic_media_license' => true,
            'no_automatic_supplier_resale_authority' => true,
            'read_only' => true,
            'mutation_performed' => false,
        );
    }

    private static function public_fields( $records, $allowed ) {
        $result = array();
        foreach ( $records as $id => $row ) {
            if ( ! is_array( $row ) ) continue;
            $result[ $id ] = array_intersect_key( $row, array_flip( $allowed ) );
        }
        return $result;
    }

    private static function bounded_id( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9_-]{1,63}$/', $value );
    }

    private static function valid_url( $url ) {
        if ( ! is_string( $url ) || strlen( $url ) > 2048 ) return false;
        $p = parse_url( $url );
        return is_array( $p ) && ! preg_match( '/[?&](?:access_token|token|api_?key|secret|signature|password)=/i', $url )
            && isset( $p['scheme'], $p['host'] )
            && in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true )
            && ! isset( $p['user'] ) && ! isset( $p['pass'] );
    }

    private static function valid_money( $value ) {
        return is_int( $value ) && $value >= 0 && $value <= 1000000000;
    }

    private static function validate_collection( $group, $rows ) {
        if ( ! is_array( $rows ) || count( $rows ) > self::MAX_RULES ) return self::error( 'mad4b_growth_rule_count_invalid', 'Rule collection exceeds the limit.' );
        foreach ( $rows as $key => $item ) {
            if ( ! self::bounded_id( $key ) || ! is_array( $item ) ) return self::error( 'mad4b_growth_rule_identity_invalid', 'Every rule requires a bounded identifier and object.' );
            if ( 'suppliers' === $group ) {
                if ( empty( $item['display_name'] ) || ! is_string( $item['display_name'] ) || strlen( $item['display_name'] ) > 160
                    || ! isset( $item['source_url'] ) || ! self::valid_url( $item['source_url'] ) ) return self::error( 'mad4b_growth_supplier_invalid', 'Supplier identity/name/source URL is invalid.' );
                if ( isset( $item['commercial_status'] ) && ! in_array( $item['commercial_status'], array( 'unknown', 'owner_confirmed', 'contract_reviewed', 'denied' ), true ) ) return self::error( 'mad4b_growth_supplier_status_invalid', 'Unsupported supplier status.' );
                if ( ! empty( $item['agreement_ref'] ) && ( ! is_string( $item['agreement_ref'] ) || strlen( $item['agreement_ref'] ) > 255 ) ) return self::error( 'mad4b_growth_agreement_invalid', 'Agreement reference must be bounded.' );
                if ( ! empty( $item['valid_until'] ) && ( ! is_string( $item['valid_until'] ) || ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $item['valid_until'] ) ) ) return self::error( 'mad4b_growth_agreement_date_invalid', 'Agreement expiry must be an ISO date.' );
            } elseif ( 'competitors' === $group ) {
                if ( empty( $item['display_name'] ) || ! is_string( $item['display_name'] ) || strlen( $item['display_name'] ) > 160
                    || ! isset( $item['source_url'] ) || ! self::valid_url( $item['source_url'] ) )
                    return self::error( 'mad4b_growth_competitor_invalid', 'A competitor research profile needs a name and source URL.' );
            } elseif ( 'dmc_connections' === $group ) {
                if ( empty( $item['supplier_id'] ) || ! self::bounded_id( $item['supplier_id'] )
                    || ! isset( $item['direction'] ) || ! in_array( $item['direction'], array( 'import', 'export', 'bidirectional' ), true ) )
                    return self::error( 'mad4b_growth_dmc_invalid', 'DMC connection requires supplier identity and exchange direction.' );
                if ( ! empty( $item['feed_url'] ) && ! self::valid_url( $item['feed_url'] ) )
                    return self::error( 'mad4b_growth_dmc_feed_invalid', 'DMC feed must use an absolute HTTP(S) URL.' );
            } elseif ( 'feed_mappings' === $group ) {
                if ( empty( $item['post_type'] ) || 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/', (string) $item['post_type'] )
                    || empty( $item['direction'] ) || ! in_array( $item['direction'], array( 'import', 'export', 'bidirectional' ), true ) )
                    return self::error( 'mad4b_growth_mapping_invalid', 'DMC mapping needs a native post type and exchange direction.' );
                if ( isset( $item['fields'] ) && ( ! is_array( $item['fields'] ) || count( $item['fields'] ) > 40 ) )
                    return self::error( 'mad4b_growth_mapping_fields_invalid', 'Mapping fields exceed the bounded limit.' );
            } elseif ( 'pricing_rules' === $group ) {
                if ( ! isset( $item['mode'] ) || ! in_array( $item['mode'], self::MODES, true ) ) return self::error( 'mad4b_growth_pricing_mode_invalid', 'Unsupported pricing strategy.' );
                if ( empty( $item['currency'] ) || ! preg_match( '/^[A-Z]{3}$/', (string) $item['currency'] ) ) return self::error( 'mad4b_growth_currency_invalid', 'Currency must be exactly ISO-style three uppercase letters.' );
                foreach ( array( 'amount_minor', 'floor_minor', 'ceiling_minor', 'cost_minor' ) as $name ) {
                    if ( array_key_exists( $name, $item ) && ! self::valid_money( $item[ $name ] ) ) return self::error( 'mad4b_growth_amount_invalid', 'Amounts must be bounded nonnegative minor-unit integers.' );
                }
                if ( isset( $item['basis_points'] ) && ( ! is_int( $item['basis_points'] ) || $item['basis_points'] < 0 || $item['basis_points'] > 10000 ) ) return self::error( 'mad4b_growth_basis_points_invalid', 'Percentage basis points must be between 0 and 10000.' );
                if ( isset( $item['floor_minor'], $item['ceiling_minor'] ) && $item['floor_minor'] > $item['ceiling_minor'] ) return self::error( 'mad4b_growth_price_range_invalid', 'Price floor exceeds ceiling.' );
            } elseif ( 'media_rules' === $group ) {
                if ( empty( $item['source_url'] ) || ! self::valid_url( $item['source_url'] )
                    || ! isset( $item['rights_status'] ) || ! in_array( $item['rights_status'], self::RIGHTS, true ) ) return self::error( 'mad4b_growth_media_invalid', 'Media source and rights status are required.' );
                if ( ! empty( $item['evidence_ref'] ) && ( ! is_string( $item['evidence_ref'] ) || strlen( $item['evidence_ref'] ) > 255 ) ) return self::error( 'mad4b_growth_media_evidence_invalid', 'Evidence reference must be bounded.' );
                if ( isset( $item['allowed_channels'] ) && ( ! is_array( $item['allowed_channels'] ) || count( $item['allowed_channels'] ) > 16 ) ) return self::error( 'mad4b_growth_channels_invalid', 'Media channels are invalid.' );
                if ( ! empty( $item['valid_until'] ) && ( ! is_string( $item['valid_until'] ) || ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $item['valid_until'] ) ) ) return self::error( 'mad4b_growth_media_expiry_invalid', 'Media expiry must be an ISO date.' );
            } elseif ( 'assistant_roles' === $group ) {
                if ( empty( $item['skill_name'] ) || ! is_string( $item['skill_name'] ) || strlen( $item['skill_name'] ) > 128
                    || ! in_array( isset( $item['role'] ) ? $item['role'] : '', array( 'researcher', 'writer', 'critic', 'reviewer', 'recovery' ), true ) ) return self::error( 'mad4b_growth_agent_role_invalid', 'Assistant role and skill name are required.' );
            }
            // Explicit fields are versioned; no magic flags can silently
            // grant rights or authorize external plugin execution.
            $allowed = array(
                'suppliers' => array( 'display_name', 'source_url', 'commercial_status', 'agreement_ref', 'valid_until' ),
                'competitors' => array( 'display_name', 'source_url', 'disabled' ),
                'dmc_connections' => array( 'supplier_id', 'direction', 'feed_url' ),
                'feed_mappings' => array( 'post_type', 'direction', 'fields' ),
                'pricing_rules' => array( 'mode', 'currency', 'amount_minor', 'basis_points', 'floor_minor', 'ceiling_minor', 'cost_minor' ),
                'media_rules' => array( 'source_url', 'rights_status', 'evidence_ref', 'valid_until', 'allowed_channels' ),
                'assistant_roles' => array( 'role', 'skill_name' ),
            );
            if ( array_diff( array_keys( $item ), $allowed[ $group ] ) )
                return self::error( 'mad4b_growth_rule_field_unknown', 'Rule includes an unsupported field.' );
            if ( strlen( (string) wp_json_encode( $item ) ) > 8192 ) return self::error( 'mad4b_growth_rule_size_invalid', 'Rule size limit exceeded.' );
        }
        return true;
    }

    /** Explicit admin operation; not an AI/self-certification path. */
    public static function replace( $input ) {
        if ( ! current_user_can( 'manage_options' ) ) return self::error( 'mad4b_growth_admin_required', 'Site administrator is required to change market policies.' );
        if ( ! is_array( $input ) || empty( $input['confirmed'] ) || ! isset( $input['expected_revision'], $input['expected_sha256'] ) )
            return self::error( 'mad4b_growth_confirmation_required', 'Confirmation, expected revision and exact registry checksum are required.' );
        $expected = (int) $input['expected_revision'];
        $digest = strtolower( (string) $input['expected_sha256'] );
        if ( $expected < 0 || 1 !== preg_match( '/^[a-f0-9]{64}$/', $digest ) ) return self::error( 'mad4b_growth_revision_invalid', 'Exact revision and checksum are required.' );
        $draft = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
        if ( array_diff( array_keys( $draft ), array( 'suppliers', 'competitors', 'dmc_connections', 'feed_mappings', 'pricing_rules', 'media_rules', 'assistant_roles' ) ) )
            return self::error( 'mad4b_growth_fields_invalid', 'Unknown configuration field is not allowed.' );
        $encoded = wp_json_encode( $draft );
        if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_BYTES ) return self::error( 'mad4b_growth_settings_too_large', 'Market policy settings exceed bounded size.' );
        foreach ( array( 'suppliers', 'competitors', 'dmc_connections', 'feed_mappings', 'pricing_rules', 'media_rules', 'assistant_roles' ) as $group ) {
            if ( array_key_exists( $group, $draft ) ) {
                $validation = self::validate_collection( $group, $draft[ $group ] );
                if ( is_wp_error( $validation ) ) return $validation;
            }
        }
        // add_option uses a unique option_name; concurrent policy writers
        // cannot both claim the same lock. Fail closed rather than stealing it.
        if ( ! add_option( self::LOCK, array( 'created_at' => time(), 'actor_id' => get_current_user_id() ), '', false ) )
            return self::error( 'mad4b_growth_writer_busy', 'Policy writer is locked. Reconcile the prior attempt before retry.' );
        try {
            $previous = self::current();
            if ( is_wp_error( $previous ) ) return $previous;
            if ( $expected !== (int) $previous['revision'] || ! hash_equals( self::checksum( $previous ), $digest ) )
                return self::error( 'mad4b_growth_policy_stale', 'Market policy changed: renew the exact revision before updating.' );
            $next = $previous;
            foreach ( $draft as $group => $rows ) $next[ $group ] = $rows;
            $next['revision'] = $expected + 1;
            $next['updated_at'] = gmdate( 'c' );
            if ( ! update_option( self::OPTION, $next, false ) ) return self::error( 'mad4b_growth_registry_write_failed', 'Could not persist market rules.' );
            $check = self::current();
            if ( is_wp_error( $check ) || ! hash_equals( self::checksum( $next ), self::checksum( $check ) ) )
                return self::error( 'mad4b_growth_registry_readback_failed', 'Market rules did not pass independent exact readback.' );
            return array( 'contract' => self::CONTRACT, 'revision' => (int) $next['revision'], 'registry_sha256' => self::checksum( $next ),
                'mutation_performed' => true, 'rights_auto_approved' => false, 'supplier_contracts_auto_approved' => false,
                'authorizing_publication' => false );
        } finally {
            delete_option( self::LOCK );
        }
    }

    public static function inspect( $input ) {
        $input = is_array( $input ) ? $input : array();
        $policies = self::current();
        if ( is_wp_error( $policies ) ) return $policies;
        $supplier_id = isset( $input['supplier_id'] ) ? (string) $input['supplier_id'] : '';
        $price_rule_id = isset( $input['pricing_rule_id'] ) ? (string) $input['pricing_rule_id'] : '';
        $media_id = isset( $input['media_id'] ) ? (string) $input['media_id'] : '';
        $supplier = isset( $policies['suppliers'][ $supplier_id ] ) ? $policies['suppliers'][ $supplier_id ] : array();
        $pricing = isset( $policies['pricing_rules'][ $price_rule_id ] ) ? $policies['pricing_rules'][ $price_rule_id ] : array();
        $media = isset( $policies['media_rules'][ $media_id ] ) ? $policies['media_rules'][ $media_id ] : array();
        $blockers = array(); $checks = array();
        if ( $supplier_id ) {
            if ( ! $supplier ) $blockers[] = 'supplier_not_registered';
            else {
                $agreement = isset( $supplier['agreement_ref'] ) ? trim( (string) $supplier['agreement_ref'] ) : '';
                $until = isset( $supplier['valid_until'] ) ? strtotime( $supplier['valid_until'] . ' 23:59:59 UTC' ) : false;
                $checks['supplier_evidence_present'] = '' !== $agreement;
                $checks['supplier_contract_current'] = false !== $until && $until >= time();
                if ( 'contract_reviewed' !== ( isset( $supplier['commercial_status'] ) ? $supplier['commercial_status'] : '' )
                    || '' === $agreement || ! $checks['supplier_contract_current'] ) $blockers[] = 'commercial_supplier_agreement_unverified_or_expired';
            }
        }
        $media_status = isset( $media['rights_status'] ) ? (string) $media['rights_status'] : 'unknown';
        $media_evidence = ! empty( $media['evidence_ref'] );
        $expires = isset( $media['valid_until'] ) ? strtotime( $media['valid_until'] . ' 23:59:59 UTC' ) : false;
        $media_current = false === $expires ? empty( $media['valid_until'] ) : $expires >= time();
        $allow_reuse = $media && $media_evidence && $media_current && in_array( $media_status, array( 'first_party', 'public_domain', 'licensed', 'permission_granted' ), true );
        if ( $media_id && ! $media ) $blockers[] = 'media_not_registered';
        elseif ( $media_id && ! $allow_reuse ) $blockers[] = 'media_provenance_license_or_expiry_unverified';
        $checks['media_candidate_discovery_allowed'] = true;
        $checks['media_registry_claim_and_evidence_present'] = (bool) $allow_reuse;
        $checks['media_ingest_preflight_eligible'] = false; // Independent licensing adjudication has not occurred.
        $checks['independent_license_verification_required'] = true;
        // Price inputs are positive minor units only; never mix currencies or
        // invent a live supplier quote, tax basis, FX or actual inventory.
        $market_minor = isset( $input['market_price_minor'] ) ? $input['market_price_minor'] : null;
        $market_currency = isset( $input['market_currency'] ) ? (string) $input['market_currency'] : '';
        $price = null;
        if ( $price_rule_id ) {
            if ( ! $pricing ) $blockers[] = 'pricing_rule_missing';
            else {
                $target_currency = (string) $pricing['currency'];
                if ( $market_currency !== $target_currency ) $blockers[] = 'market_price_currency_mismatch_fx_required';
                elseif ( ! self::valid_money( $market_minor ) ) $blockers[] = 'verified_market_price_required_in_minor_units';
                else {
                    $mode = $pricing['mode'];
                    $amount = isset( $pricing['amount_minor'] ) ? $pricing['amount_minor'] : 0;
                    $bp = isset( $pricing['basis_points'] ) ? $pricing['basis_points'] : 0;
                    $v = $market_minor;
                    if ( 'markup_percent' === $mode ) $v = $v + (int) round( $v * $bp / 10000, 0, PHP_ROUND_HALF_UP );
                    elseif ( 'discount_percent' === $mode ) $v = $v - (int) round( $v * $bp / 10000, 0, PHP_ROUND_HALF_UP );
                    elseif ( 'markup_fixed' === $mode ) $v += $amount;
                    elseif ( 'discount_fixed' === $mode ) $v -= $amount;
                    elseif ( 'fixed_price' === $mode ) $v = $amount;
                    if ( $v < 0 || $v > 1000000000 ) $blockers[] = 'calculated_price_out_of_bounds';
                    else {
                        if ( isset( $pricing['floor_minor'] ) ) $v = max( $v, $pricing['floor_minor'] );
                        if ( isset( $pricing['ceiling_minor'] ) ) $v = min( $v, $pricing['ceiling_minor'] );
                        if ( isset( $pricing['cost_minor'] ) && $v < $pricing['cost_minor'] ) $blockers[] = 'price_below_recorded_cost';
                        $price = array( 'amount_minor' => $v, 'currency' => $target_currency, 'mode' => $mode, 'is_quote' => false );
                    }
                }
            }
        }
        $publication_ready = false; // Separate current supplier, booking, tax, availability & media acceptance are mandatory.
        return array(
            'contract' => 'mad4b.market-growth-evaluation.v1',
            'registry_revision' => (int) $policies['revision'],
            'registry_sha256' => self::checksum( $policies ),
            'supplier_id' => $supplier_id,
            'pricing_rule_id' => $price_rule_id,
            'media_id' => $media_id,
            'suggested_price' => $price,
            'checks' => $checks,
            'blockers' => array_values( array_unique( $blockers ) ),
            'next_actions' => array_merge(
                array( 'collect_competitor_facts_and_media_candidate_metadata',
                    'verify_original_media_license_and_source_provenance',
                    'prepare_original_brand_aligned_copy_from_approved_context' ),
                $supplier_id ? array( 'verify_supplier_resale_contract_and_own_inventory' )
                    : array( 'verify_own_offer_eligibility_not_competitor_partnership' ),
                array( 'obtain_independent_price_tax_currency_and_availability_readback',
                    'perform_exact_governed_draft_and_publication_acceptance' ) ),
            'research_contract_required' => false,
            'publication_ready' => $publication_ready,
            'media_upload_performed' => false,
            'media_copied' => false,
            'publish_performed' => false,
            'commercial_use_authorized_by_plan' => false,
            'source_url_alone_is_license' => false,
            'read_only' => true,
            'authorizing' => false,
            'mutation_performed' => false,
        );
    }
}
