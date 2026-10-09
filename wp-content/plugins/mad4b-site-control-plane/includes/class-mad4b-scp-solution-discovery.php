<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Generic, inert solution discovery. Installed plugins and registered WordPress
 * Abilities are observations, NOT trusted executors. No vendor-specific mapping.
 */
final class MAD4B_SCP_Solution_Discovery {
    const ABILITY = 'mad4b/solution-discover';
    const CONTRACT = 'mad4b.solution-discovery.v1';
    private static $booted = false;

    public static function boot() {
        if ( self::$booted || ! function_exists( 'add_action' ) ) return;
        self::$booted = true;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 45 );
        add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_adapter' ), 45 );
    }

    public static function register_adapter( $registry ) {
        if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' )
            || ! class_exists( 'MAD4B_SCP_Adapter_Base', false ) ) return;
        $registry->register( new class extends MAD4B_SCP_Adapter_Base {
            public function id() { return 'solution-discovery'; }
            public function label() { return 'Generic Solution Discovery'; }
            public function is_available() { return true; }
            public function ability_names() { return array( 'read' => array( MAD4B_SCP_Solution_Discovery::ABILITY ), 'content' => array(), 'admin' => array() ); }
            public function register_abilities() { MAD4B_SCP_Solution_Discovery::register_ability(); }
            protected function mutation_requires_certification() { return false; }
            protected function provider_certification( $available ) { return null; }
        } );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Discover Potential Solutions',
            'description' => 'Inventory and keyword-match installed plugins, registered WordPress Abilities and untrusted external hints. Read-only discovery; never executes or certifies a candidate.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'read_discover' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array(
                'type' => 'object',
                'required' => array( 'expected_profile_digest', 'expected_runtime_generation', 'intent' ),
                'properties' => array(
                    'expected_profile_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'expected_runtime_generation' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'expected_snapshot_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'intent' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 180 ),
                    'related_terms' => array( 'type' => 'array', 'maxItems' => 12, 'items' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 80 ) ),
                    'mode' => array( 'type' => 'string', 'enum' => array( 'match', 'inventory' ) ),
                    'offset' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 1024 ),
                    'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 40 ),
                    'external_hints' => array( 'type' => 'array', 'maxItems' => 24, 'items' => array(
                        'type' => 'object', 'required' => array( 'id', 'label', 'source' ),
                        'properties' => array(
                            'id' => array( 'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9._-]{1,79}$' ),
                            'label' => array( 'type' => 'string', 'maxLength' => 120 ),
                            'source' => array( 'type' => 'string', 'enum' => array( 'external_service', 'connector', 'skill', 'operator' ) ),
                            'description' => array( 'type' => 'string', 'maxLength' => 180 ),
                        ), 'additionalProperties' => false,
                    ) ),
                ), 'additionalProperties' => false,
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }

    private static function fail( $reason ) {
        return new WP_Error( 'mad4b_solution_discovery_' . $reason,
            'Discovery refused invalid or stale evidence. No provider executed.',
            array( 'authorizing' => false, 'automatic_retry_allowed' => false ) );
    }

    private static function safe_text( $value, $max = 180 ) {
        return is_string( $value ) && strlen( $value ) <= $max * 4
            && 1 === preg_match( '/^[\p{L}\p{N}][\p{L}\p{M}\p{N} ._-]*$/uD', $value )
            && preg_match_all( '/./us', $value ) <= $max;
    }

    /** Sanitize ecosystem labels without allowing an unusual label to block inventory. */
    private static function safe_label( $label, $fallback ) {
        $candidate = is_string( $label ) ? preg_replace( '/[^\\p{L}\\p{N} ._-]+/u', ' ', $label ) : '';
        if ( self::safe_text( trim( (string) $candidate ), 120 ) ) return trim( $candidate );
        $candidate = preg_replace( '/[^A-Za-z0-9._-]+/', ' ', (string) $fallback );
        return self::safe_text( trim( (string) $candidate ), 120 ) ? trim( $candidate ) : 'Unverified metadata';
    }

    private static function tokens( $value ) {
        if ( ! is_string( $value ) || strlen( $value ) > 1024 ) return array();
        // No hard-coded provider synonyms. Unicode lexical query normalization
        // remains a hint, not a proof of semantics or authorization.
        $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
        $text = preg_replace( '/\\p{Mn}+/u', '', $text );
        if ( ! is_string( $text ) ) return array();
        $matches = array(); $result = array();
        if ( ! preg_match_all( '/[\\p{L}\\p{N}]{2,}/u', $text, $matches ) ) return $result;
        foreach ( $matches[0] as $token ) {
            // General English plural form, not a named integration lookup.
            if ( preg_match( '/^[a-z]{4,}s$/D', $token ) ) $token = substr( $token, 0, -1 );
            $result[ $token ] = true;
            if ( count( $result ) >= 80 ) break;
        }
        return $result;
    }

    /** Caller hints and plugin descriptions never serve as proof of abilities. */
    private static function validate( $input ) {
        if ( ! is_array( $input ) ) return false;
        $allowed = array( 'expected_profile_digest', 'expected_runtime_generation', 'intent',
            'related_terms', 'mode', 'offset', 'limit', 'external_hints', 'expected_snapshot_sha256' );
        foreach ( $input as $key => $val ) if ( ! in_array( $key, $allowed, true ) ) return false;
        foreach ( array( 'expected_profile_digest', 'expected_runtime_generation' ) as $key )
            if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] )
                || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input[ $key ] ) ) return false;
        if ( ! self::safe_text( $input['intent'] ?? null ) ) return false;
        if ( isset( $input['expected_snapshot_sha256'] ) && (
            ! is_string( $input['expected_snapshot_sha256'] ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $input['expected_snapshot_sha256'] ) ) ) return false;
        if ( ! in_array( $input['mode'] ?? 'match', array( 'match', 'inventory' ), true )
            || ! is_int( $input['offset'] ?? 0 ) || ( $input['offset'] ?? 0 ) < 0 || ( $input['offset'] ?? 0 ) > 1024
            || ! is_int( $input['limit'] ?? 20 ) || ( $input['limit'] ?? 20 ) < 1 || ( $input['limit'] ?? 20 ) > 40 ) return false;
        foreach ( array( 'related_terms' => 12, 'external_hints' => 24 ) as $key => $limit ) {
            $rows = $input[ $key ] ?? array();
            if ( ! is_array( $rows ) || count( $rows ) > $limit
                || ( $rows && array_keys( $rows ) !== range( 0, count( $rows ) - 1 ) ) ) return false;
            foreach ( $rows as $row ) {
                if ( 'related_terms' === $key ) {
                    if ( ! self::safe_text( $row, 80 ) ) return false;
                } else {
                    if ( ! is_array( $row ) ) return false;
                    foreach ( $row as $field => $unused )
                        if ( ! in_array( $field, array( 'id', 'label', 'description', 'source' ), true ) ) return false;
                    if ( ! is_string( $row['id'] ?? null ) || ! preg_match( '/^[a-z0-9][a-z0-9._-]{1,79}$/D', $row['id'] )
                        || ! self::safe_text( $row['label'] ?? null, 120 )
                        || ! in_array( $row['source'] ?? null, array( 'external_service', 'connector', 'skill', 'operator' ), true )
                        || ( isset( $row['description'] ) && ! self::safe_text( $row['description'], 180 ) ) ) return false;
                }
            }
        }
        return true;
    }

    /** The only environmental reads: plugin inventory and registered metadata. */
    public static function site_inventory() {
        $rows = array(); $plugin_complete = false; $ability_complete = false; $alternate_complete = true;
        // Load the trusted WordPress core metadata helper in REST/MCP contexts.
        // Do not include or execute unknown third-party plugin PHP.
        if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' )
            && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) )
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugins = array();
        if ( function_exists( 'get_plugins' ) ) {
            try {
                $plugins = get_plugins();
                $plugin_complete = is_array( $plugins );
            } catch ( Throwable $failure ) {
                $plugins = array(); $plugin_complete = false;
            }
        }
        // Restore partial observability after a failed full registry read.
        // Never convert active-only evidence into complete install coverage.
        if ( ! $plugin_complete && function_exists( 'get_option' ) ) {
            try {
                $active_subset = get_option( 'active_plugins', array() );
                if ( is_array( $active_subset ) ) foreach ( $active_subset as $path ) {
                    if ( is_string( $path ) && ! isset( $plugins[ $path ] ) )
                        $plugins[ $path ] = array( 'Name' => basename( dirname( $path ) ) );
                }
            } catch ( Throwable $failure ) { /* Coverage stays incomplete. */ }
        }
        if ( ! is_array( $plugins ) ) $plugins = array();
        // Do not discard malformed rows before validating completeness.
        ksort( $plugins, SORT_STRING );
        $active = array();
        if ( function_exists( 'get_option' ) ) {
            try { $active = get_option( 'active_plugins', array() ); }
            catch ( Throwable $failure ) { $plugin_complete = false; }
        }
        if ( ! is_array( $active ) ) { $active = array(); $plugin_complete = false; }
        $count = 0;
        foreach ( $plugins as $file => $data ) {
            if ( ++$count > 240 ) { $plugin_complete = false; break; }
            if ( ! is_string( $file ) ||
                ! preg_match( '~^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,89}/)?[A-Za-z0-9][A-Za-z0-9._-]{0,89}\\.php$~D', $file )
                || ! is_array( $data ) ) { $plugin_complete = false; continue; }
            $slug = dirname( $file );
            if ( '.' === $slug ) $slug = pathinfo( $file, PATHINFO_FILENAME );
            $label = isset( $data['Name'] ) && is_string( $data['Name'] ) ? trim( strip_tags( $data['Name'] ) ) : $slug;
            if ( ! self::safe_text( $label, 120 ) ) $label = self::safe_label( $label, $slug );
            $network_active = function_exists( 'is_multisite' ) && is_multisite()
                && function_exists( 'is_plugin_active_for_network' )
                && is_plugin_active_for_network( $file );
            $enabled = in_array( $file, $active, true ) || $network_active;
            $rows[] = array( 'id' => 'plugin:' . $file, 'label' => $label,
                'observed_version' => is_string( $data['Version'] ?? null ) ? substr( $data['Version'], 0, 64 ) : '',
                'source' => 'installed_plugin', 'observed_state' => $enabled ? 'active' : 'inactive',
                'match_text' => $label . ' ' . str_replace( array( '/', '-', '_' ), ' ', $file ),
                'metadata_digest' => hash( 'sha256', serialize( array( $file, 
                    is_string( $data['Version'] ?? null ) ? substr( $data['Version'], 0, 64 ) : '',
                    $enabled, $network_active ) ) ) );
        }
        // WordPress core also supports Must-Use plugins and drop-ins.
        // A missing helper or malformed metadata is an incomplete inventory,
        // never evidence that the extension surface is empty.
        foreach ( array( 'get_mu_plugins' => 'must_use_plugin',
                         'get_dropins' => 'dropin' ) as $function => $source ) {
            if ( ! function_exists( $function ) ) { $alternate_complete = false; continue; }
            try { $records = $function(); }
            catch ( Throwable $failure ) { $alternate_complete = false; continue; }
            if ( ! is_array( $records ) ) { $alternate_complete = false; continue; }
            ksort( $records, SORT_STRING ); $scanned = 0;
            foreach ( $records as $file => $record ) {
                if ( ++$scanned > 120 ) { $alternate_complete = false; break; }
                if ( ! is_string( $file ) ||
                    ! preg_match( '~^[A-Za-z0-9][A-Za-z0-9._-]{0,89}\.php$~D', $file ) ||
                    ! is_array( $record ) ) { $alternate_complete = false; continue; }
                $label = is_string( $record['Name'] ?? null ) ? trim( strip_tags( $record['Name'] ) ) : $file;
                if ( ! self::safe_text( $label, 120 ) ) $label = self::safe_label( $label, $file );
                $version = is_string( $record['Version'] ?? null ) ? substr( $record['Version'], 0, 64 ) : '';
                $status = 'must_use_plugin' === $source ? 'active' : 'registered';
                $rows[] = array( 'id' => $source . ':' . $file, 'label' => $label,
                    'source' => $source, 'observed_state' => $status,
                    'match_text' => $label . ' ' . str_replace( array( '-', '_' ), ' ', $file ),
                    'metadata_digest' => hash( 'sha256', serialize( array( $source, $file, $version, $status ) ) ) );
            }
        }
        if ( function_exists( 'wp_get_abilities' ) ) {
            $abilities = array();
            $ability_call_failed = false;
            try { $abilities = wp_get_abilities(); }
            catch ( Throwable $failure ) { $ability_call_failed = true; }
            if ( ! $ability_call_failed && is_array( $abilities ) ) {
                $ability_complete = true; ksort( $abilities, SORT_STRING );
                $count = 0;
                foreach ( $abilities as $name => $ability ) {
                    if ( ++$count > 240 ) { $ability_complete = false; break; }
                    if ( ! is_string( $name ) || ! preg_match( '~^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$~D', $name )
                        || ! is_object( $ability ) || ! method_exists( $ability, 'get_label' )
                        || ! method_exists( $ability, 'get_description' ) ) { $ability_complete = false; continue; }
                    // Private registration metadata is not a discovery result.
                    if ( ! method_exists( $ability, 'get_meta' ) ) { $ability_complete = false; continue; }
                    try { $meta = $ability->get_meta(); }
                    catch ( Throwable $failure ) { $ability_complete = false; continue; }
                    // WordPress' REST contract hides show_in_rest=false Abilities.
                    // Internal MAD4B tools belong to the separately governed MCP
                    // catalog, never this general discovery projection.
                    if ( ! is_array( $meta ) || true !== ( $meta['show_in_rest'] ?? false ) ) continue;
                    try { $label = $ability->get_label(); $description = $ability->get_description(); }
                    catch ( Throwable $failure ) { $ability_complete = false; continue; }
                    if ( ! self::safe_text( $label, 120 ) ) $label = self::safe_label( $label, $name );
                    $description = self::safe_text( $description, 180 ) ? $description : '';
                    $rows[] = array( 'id' => 'ability:' . $name, 'label' => $label,
                        'source' => 'registered_ability', 'observed_state' => 'registered',
                        'match_text' => $name . ' ' . $label . ' ' . $description );
                }
            }
        }
        // Cap-check the snapshot based on actual discovered sources, never on
        // the number of candidates surviving keyword matching.
        if ( count( $rows ) > 744 ) $alternate_complete = false;
        return array( 'rows' => $rows,
            'plugin_inventory_scope' => $plugin_complete ? 'installed_plugins' : 'partial_or_active_fallback',
            'ability_visibility_scope' => 'show_in_rest_only',
            'plugin_inventory_complete' => $plugin_complete && count( $plugins ) <= 240,
            'inventory_rows_observed' => count( $rows ),
            'extension_inventory_complete' => $alternate_complete,
            'ability_inventory_complete' => $ability_complete && ( ! isset( $abilities ) || count( $abilities ) <= 240 ) );
    }

    /**
     * Advisory join against the EXISTING governed Plugin Discovery projection.
     * No vendor slug map, executor lookup, plugin file include, or permission
     * escalation. Missing or stale rows remain unassessed rather than safe.
     */
    public static function attach_risk_evidence( $inventory, $coverage ) {
        if ( ! is_array( $inventory ) || ! is_array( $inventory['rows'] ?? null ) )
            return $inventory;
        $inventory['risk_coverage_complete'] = false;
        $inventory['risk_coverage_source'] = 'unavailable';
        if ( ! is_array( $coverage ) ||
            'mad4b.plugin-adapter-discovery.v1' !== ( $coverage['contract'] ?? null ) ||
            ! is_array( $coverage['plugins'] ?? null ) ||
            ! empty( $coverage['truncated'] ) ||
            count( $coverage['plugins'] ) > 500 ) return $inventory;
        $by_file = array(); $duplicate = false;
        foreach ( $coverage['plugins'] as $row ) {
            if ( ! is_array( $row ) || ! is_string( $row['plugin_file'] ?? null ) ) {
                $duplicate = true; continue;
            }
            $file = $row['plugin_file'];
            if ( isset( $by_file[ $file ] ) ) { $duplicate = true; continue; }
            $by_file[ $file ] = $row;
        }
        $complete = ! $duplicate;
        foreach ( $inventory['rows'] as &$candidate ) {
            if ( ! is_array( $candidate ) || ( $candidate['source'] ?? '' ) !== 'installed_plugin' ) continue;
            $file = substr( $candidate['id'], 7 );
            if ( ! isset( $by_file[ $file ] ) ) { $complete = false; continue; }
            $row = $by_file[ $file ];
            $risk = is_string( $row['risk'] ?? null ) ? $row['risk'] : 'unknown';
            $state = is_string( $row['coverage_state'] ?? null ) ? $row['coverage_state'] : 'unknown';
            $version = is_string( $row['version'] ?? null ) ? substr( $row['version'], 0, 64 ) : '';
            // Never attach a risk classification across an upgrade race.
            if ( $version !== ( $candidate['observed_version'] ?? '' ) ) {
                $complete = false; continue;
            }
            if ( ! in_array( $risk, array( 'low', 'medium', 'high', 'exceptional', 'unknown' ), true ) ||
                ! preg_match( '/^[a-z0-9_]{2,80}$/D', $state ) ) {
                $complete = false; continue;
            }
            $candidate['declared_risk'] = $risk;
            $candidate['coverage_state'] = $state;
            $functional = is_array( $row['functional_coverage'] ?? null )
                ? ( $row['functional_coverage']['state'] ?? '' ) : '';
            $states = array( 'functional_ready', 'read_ready_write_blocked',
                'status_only_candidate', 'contract_discovery_required',
                'safety_blocked', 'adapter_missing', 'intentionally_excluded', 'inactive' );
            $candidate['functional_state'] = in_array( $functional, $states, true ) ? $functional : 'unknown';
            $candidate['side_channel_blocked'] = ! empty( $row['side_channel_blocker'] );
            $candidate['provider_certification_ok'] = ( $row['provider_certification_ok'] ?? null ) === true;
            $candidate['adapter_runtime_available'] = ( $row['adapter_runtime_available'] ?? null ) === true;
            $candidate['read_ability_count'] = max( 0, min( 100, (int) ( $row['adapter_read_ability_count'] ?? 0 ) ) );
            $candidate['reversible_contract_count'] = is_array( $row['reversible_contracts'] ?? null )
                ? min( 100, count( $row['reversible_contracts'] ) ) : 0;
            $candidate['requires_exceptional_review'] = in_array( $risk, array( 'high', 'exceptional' ), true )
                || 'excluded_high_risk' === $state;
            $candidate['risk_metadata_source'] = 'governed_plugin_discovery';
            $candidate['risk_version_observed'] = $version;
            $candidate['qualification_status'] = ( 'excluded_high_risk' === $state ||
                in_array( $risk, array( 'high', 'exceptional' ), true ) ) ? 'EXCEPTIONAL_REVIEW'
                : ( $candidate['side_channel_blocked'] || 'safety_blocked' === $candidate['functional_state'] )
                    ? 'SAFETY_BLOCKED'
                    : ( 'unknown' === $risk || 'unknown' === $candidate['functional_state'] )
                        ? 'EVIDENCE_INCOMPLETE' : 'FUNCTIONAL_REVIEW_REQUIRED';
        }
        unset( $candidate );
        $inventory['risk_coverage_complete'] = $complete;
        $inventory['risk_coverage_source'] = 'governed_plugin_discovery';
        return $inventory;
    }

    public static function enriched_site_inventory() {
        $inventory = self::site_inventory();
        if ( ! class_exists( 'MAD4B_SCP_Plugin_Discovery', false ) ||
            ! method_exists( 'MAD4B_SCP_Plugin_Discovery', 'coverage' ) )
            return self::attach_risk_evidence( $inventory, null );
        try { $coverage = MAD4B_SCP_Plugin_Discovery::coverage(); }
        catch ( Throwable $error ) { $coverage = null; }
        return self::attach_risk_evidence( $inventory, $coverage );
    }

    public static function read_discover( $input = array() ) {
        if ( ! self::validate( $input ) ) return self::fail( 'input_invalid' );
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) )
            return self::fail( 'permission_denied' );
        if ( ! class_exists( 'MAD4B_SCP_Adaptive_Operations_Context', false ) ) return self::fail( 'context_missing' );
        $binding = MAD4B_SCP_Adaptive_Operations_Context::current();
        if ( ! is_array( $binding ) || ( $binding['profile_digest'] ?? '' ) !== $input['expected_profile_digest']
            || ( $binding['runtime_generation'] ?? '' ) !== $input['expected_runtime_generation']
            || empty( $binding['site_uuid'] ) || empty( $binding['artifact_sha256'] )
            || ! is_int( $binding['restore_epoch'] ?? null ) || $binding['restore_epoch'] < 1 ) return self::fail( 'stale_binding' );
        $inventory = self::enriched_site_inventory();
        $current = MAD4B_SCP_Adaptive_Operations_Context::current();
        if ( ! is_array( $current ) || serialize( $current ) !== serialize( $binding ) )
            return self::fail( 'concurrent_binding_change' );
        return self::discover( $input, $inventory, $binding );
    }

    /** Pure candidate reducer, no code/HTTP/provider dispatch; can be independently tested. */
    public static function discover( $input, $inventory, $binding ) {
        if ( ! self::validate( $input ) || ! is_array( $inventory ) || ! is_array( $inventory['rows'] ?? null )
            || count( $inventory['rows'] ) > 744 || ! is_array( $binding )
            || ( $binding['profile_digest'] ?? null ) !== $input['expected_profile_digest']
            || ( $binding['runtime_generation'] ?? null ) !== $input['expected_runtime_generation']
            || ! is_int( $binding['restore_epoch'] ?? null ) || $binding['restore_epoch'] < 1 ) return self::fail( 'snapshot_invalid' );
        $goal = self::tokens( $input['intent'] );
        foreach ( $input['related_terms'] ?? array() as $term ) $goal += self::tokens( $term );
        $candidates = array(); $seen = array();
        $all = $inventory['rows'];
        foreach ( $input['external_hints'] ?? array() as $hint ) {
            $all[] = array( 'id' => 'hint:' . $hint['source'] . ':' . $hint['id'],
                'label' => $hint['label'], 'source' => $hint['source'],
                'observed_state' => 'caller_claimed',
                'match_text' => $hint['label'] . ' ' . ( $hint['description'] ?? '' ) );
        }
        if ( count( $all ) > 768 ) return self::fail( 'inventory_over_budget' );
        foreach ( $all as $row ) {
            if ( ! is_array( $row ) || ! is_string( $row['id'] ?? null )
                || ! preg_match( '~^(?:(?:plugin:(?:[A-Za-z0-9][A-Za-z0-9._-]{0,89}/)?|(?:must_use_plugin|dropin):)[A-Za-z0-9][A-Za-z0-9._-]{0,89}\\.php|ability:[A-Za-z0-9._-]+/[A-Za-z0-9._-]+|hint:[a-z_]+:[a-z0-9][a-z0-9._-]{1,79})$~D', $row['id'] )
                || ! self::safe_text( $row['label'] ?? null, 120 )
                || ! in_array( $row['source'] ?? null, array( 'installed_plugin', 'must_use_plugin', 'dropin', 'registered_ability', 'external_service', 'connector', 'skill', 'operator' ), true )
                || ! in_array( $row['observed_state'] ?? null, array( 'active', 'inactive', 'registered', 'caller_claimed' ), true )
                || ! is_string( $row['match_text'] ?? null ) || strlen( $row['match_text'] ) > 900
                || ( isset( $row['metadata_digest'] ) && (
                    ! is_string( $row['metadata_digest'] ) ||
                    ! preg_match( '/^[a-f0-9]{64}$/D', $row['metadata_digest'] ) ) )
                || isset( $seen[ $row['id'] ] ) ) return self::fail( 'candidate_invalid' );
            $seen[ $row['id'] ] = true;
            $matches = array_keys( array_intersect_key( $goal, self::tokens( $row['match_text'] ) ) );
            sort( $matches, SORT_STRING );
            $score = count( $matches );
            if ( 'match' === ( $input['mode'] ?? 'match' ) && 0 === $score ) continue;
            $risk = in_array( $row['declared_risk'] ?? '', array( 'low', 'medium', 'high', 'exceptional', 'unknown' ), true )
                ? $row['declared_risk'] : 'unassessed';
            $risk_review = ! empty( $row['requires_exceptional_review'] );
            $qualification = $row['qualification_status'] ?? 'EVIDENCE_INCOMPLETE';
            $candidates[] = array( 'id' => $row['id'], 'label' => $row['label'],
                'source' => $row['source'], 'observed_state' => $row['observed_state'],
                'metadata_digest' => $row['metadata_digest'] ?? null,
                'declared_risk' => $risk,
                'coverage_state' => $row['coverage_state'] ?? 'unknown',
                'requires_exceptional_review' => $risk_review,
                'qualification_status' => $qualification,
                'functional_state' => $row['functional_state'] ?? 'unknown',
                'side_channel_blocked' => ! empty( $row['side_channel_blocked'] ),
                'provider_certification_ok' => ! empty( $row['provider_certification_ok'] ),
                'adapter_runtime_available' => ! empty( $row['adapter_runtime_available'] ),
                'read_ability_count' => $row['read_ability_count'] ?? 0,
                'reversible_contract_count' => $row['reversible_contract_count'] ?? 0,
                'qualification_verified' => false,
                'risk_metadata_source' => $row['risk_metadata_source'] ?? 'unassessed',
                'matched_terms' => $matches, 'lexical_score' => $score,
                'classification' => 'UNMAPPED_OR_UNVERIFIED',
                'metadata_is_untrusted' => true, 'instructions_in_metadata_ignored' => true,
                'behavior_verified' => false, 'authorization_verified' => false,
                'execution_allowed' => false, 'requires' => array( 'capability_attestation', 'scope_review', 'governed_execution_path' ) );
        }
        usort( $candidates, static function ( $a, $b ) {
            if ( $a['lexical_score'] !== $b['lexical_score'] ) return $b['lexical_score'] - $a['lexical_score'];
            return strcmp( $a['id'], $b['id'] );
        } );
        $limit = $input['limit'] ?? 20; $offset = $input['offset'] ?? 0;
        $snapshot = hash( 'sha256', serialize( array( $binding, $inventory, $input['external_hints'] ?? array(), $goal ) ) );
        if ( isset( $input['expected_snapshot_sha256'] ) &&
            ! hash_equals( $snapshot, $input['expected_snapshot_sha256'] ) )
            return self::fail( 'snapshot_changed' );
        return array( 'contract' => self::CONTRACT,
            'binding' => array( 'site_uuid' => $binding['site_uuid'] ?? '', 'profile_digest' => $binding['profile_digest'],
                'runtime_generation' => $binding['runtime_generation'], 'restore_epoch' => $binding['restore_epoch'],
                'artifact_sha256' => $binding['artifact_sha256'] ?? '' ),
            'snapshot_sha256' => $snapshot, 'mode' => $input['mode'] ?? 'match',
            'coverage' => array( 'plugin_inventory_complete' => ! empty( $inventory['plugin_inventory_complete'] ),
                'ability_inventory_complete' => ! empty( $inventory['ability_inventory_complete'] ),
                'extension_inventory_complete' => ! empty( $inventory['extension_inventory_complete'] ),
                'risk_coverage_complete' => ! empty( $inventory['risk_coverage_complete'] ),
                'qualification_coverage_complete' => ! empty( $inventory['risk_coverage_complete'] ),
                'ability_visibility_scope' => 'show_in_rest_only',
                'plugin_inventory_scope' => $inventory['plugin_inventory_scope'] ?? 'unknown',
                'external_inventory_complete' => false ),
            'total_matches' => count( $candidates ), 'offset' => $offset, 'limit' => $limit,
            'candidate_ranking' => 'LEXICAL_ONLY_UNVERIFIED',
            'unmatched_inventory_available' => count( $candidates ) === 0 && count( $all ) > 0,
            'inventory_incomplete' => empty( $inventory['plugin_inventory_complete'] )
                || empty( $inventory['ability_inventory_complete'] )
                || empty( $inventory['extension_inventory_complete'] ),
            'qualification_incomplete' => empty( $inventory['risk_coverage_complete'] ),
            'next_offset' => $offset + $limit < count( $candidates ) && $offset + $limit <= 1024 ? $offset + $limit : null,
            'candidates' => array_slice( $candidates, $offset, $limit ),
            'decision' => count( $candidates ) ? 'EVALUATE_CANDIDATES'
                : ( empty( $inventory['plugin_inventory_complete'] ) ||
                    empty( $inventory['ability_inventory_complete'] ) ||
                    empty( $inventory['extension_inventory_complete'] )
                    ? 'INVENTORY_INCOMPLETE_RETRY' : 'EXPAND_INVENTORY_OR_EXTERNAL_DISCOVERY' ),
            'mapping_required_to_discover' => false, 'authorizing' => false,
            'mutation_performed' => false, 'provider_executed' => false, 'auto_install_allowed' => false );
    }
}
