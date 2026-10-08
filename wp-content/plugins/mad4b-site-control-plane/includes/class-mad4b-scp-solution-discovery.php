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
                    'intent' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 180 ),
                    'related_terms' => array( 'type' => 'array', 'maxItems' => 12, 'items' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 80 ) ),
                    'mode' => array( 'type' => 'string', 'enum' => array( 'match', 'inventory' ) ),
                    'offset' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 400 ),
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
            && 1 === preg_match( '/^[\p{L}\p{N}][\p{L}\p{N} ._-]*$/uD', $value )
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
        $result = array();
        $matches = array();
        if ( ! preg_match_all( '/[\p{L}\p{N}]{3,}/u', strtolower( $value ), $matches ) ) return $result;
        foreach ( $matches[0] as $token ) $result[ $token ] = true;
        return $result;
    }

    /** Caller hints and plugin descriptions never serve as proof of abilities. */
    private static function validate( $input ) {
        if ( ! is_array( $input ) ) return false;
        $allowed = array( 'expected_profile_digest', 'expected_runtime_generation', 'intent',
            'related_terms', 'mode', 'offset', 'limit', 'external_hints' );
        foreach ( $input as $key => $val ) if ( ! in_array( $key, $allowed, true ) ) return false;
        foreach ( array( 'expected_profile_digest', 'expected_runtime_generation' ) as $key )
            if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] )
                || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input[ $key ] ) ) return false;
        if ( ! self::safe_text( $input['intent'] ?? null ) ) return false;
        if ( ! in_array( $input['mode'] ?? 'match', array( 'match', 'inventory' ), true )
            || ! is_int( $input['offset'] ?? 0 ) || ( $input['offset'] ?? 0 ) < 0 || ( $input['offset'] ?? 0 ) > 400
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
        $rows = array(); $plugin_complete = false; $ability_complete = false;
        // Load the trusted WordPress core metadata helper in REST/MCP contexts.
        // Do not include or execute unknown third-party plugin PHP.
        if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' )
            && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) )
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugins = array();
        if ( function_exists( 'get_plugins' ) ) {
            $plugins = get_plugins();
            $plugin_complete = is_array( $plugins );
        } elseif ( function_exists( 'get_option' ) ) {
            $active = get_option( 'active_plugins', array() );
            if ( is_array( $active ) ) foreach ( $active as $path )
                if ( is_string( $path ) ) $plugins[ $path ] = array( 'Name' => basename( dirname( $path ) ) );
        }
        if ( ! is_array( $plugins ) ) $plugins = array();
        ksort( $plugins, SORT_STRING );
        $active = function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array();
        if ( ! is_array( $active ) ) $active = array();
        $count = 0;
        foreach ( $plugins as $file => $data ) {
            if ( ++$count > 240 ) break;
            if ( ! is_string( $file ) ||
                ! preg_match( '~^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,89}/)?[A-Za-z0-9][A-Za-z0-9._-]{0,89}\\.php$~D', $file )
                || ! is_array( $data ) ) continue;
            $slug = dirname( $file );
            if ( '.' === $slug ) $slug = pathinfo( $file, PATHINFO_FILENAME );
            $label = isset( $data['Name'] ) && is_string( $data['Name'] ) ? trim( strip_tags( $data['Name'] ) ) : $slug;
            if ( ! self::safe_text( $label, 120 ) ) $label = self::safe_label( $label, $slug );
            $rows[] = array( 'id' => 'plugin:' . $file, 'label' => $label,
                'source' => 'installed_plugin', 'observed_state' => in_array( $file, $active, true ) ? 'active' : 'inactive',
                'match_text' => $label . ' ' . str_replace( array( '/', '-', '_' ), ' ', $file ),
                'metadata_digest' => hash( 'sha256', serialize( array( $file, 
                    is_string( $data['Version'] ?? null ) ? substr( $data['Version'], 0, 64 ) : '',
                    in_array( $file, $active, true ) ) ) ) );
        }
        if ( function_exists( 'wp_get_abilities' ) ) {
            $abilities = wp_get_abilities();
            if ( is_array( $abilities ) ) {
                $ability_complete = true; ksort( $abilities, SORT_STRING );
                $count = 0;
                foreach ( $abilities as $name => $ability ) {
                    if ( ++$count > 240 ) break;
                    if ( ! is_string( $name ) || ! preg_match( '~^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$~D', $name )
                        || ! is_object( $ability ) || ! method_exists( $ability, 'get_label' )
                        || ! method_exists( $ability, 'get_description' ) ) continue;
                    // Private registration metadata is not a discovery result.
                    if ( ! method_exists( $ability, 'get_meta' ) ) continue;
                    $meta = $ability->get_meta();
                    if ( ! is_array( $meta ) ) continue;
                    // Include opaque names for admins; never index or disclose
                    // private labels or descriptions. Registration != authority.
                    $public = true === ( $meta['show_in_rest'] ?? false );
                    $label = $public ? $ability->get_label() : $name;
                    $description = $public ? $ability->get_description() : '';
                    if ( ! self::safe_text( $label, 120 ) ) $label = self::safe_label( $label, $name );
                    $description = self::safe_text( $description, 180 ) ? $description : '';
                    $rows[] = array( 'id' => 'ability:' . $name, 'label' => $label,
                        'source' => 'registered_ability', 'observed_state' => 'registered',
                        'match_text' => $name . ' ' . $label . ' ' . $description );
                }
            }
        }
        return array( 'rows' => $rows,
            'plugin_inventory_scope' => $plugin_complete ? 'installed_plugins' : 'active_only_fallback',
            'ability_visibility_scope' => 'private_metadata_redacted_admin_only',
            'plugin_inventory_complete' => $plugin_complete && count( $plugins ) <= 240,
            'ability_inventory_complete' => $ability_complete && ( ! isset( $abilities ) || count( $abilities ) <= 240 ) );
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
        return self::discover( $input, self::site_inventory(), $binding );
    }

    /** Pure candidate reducer, no code/HTTP/provider dispatch; can be independently tested. */
    public static function discover( $input, $inventory, $binding ) {
        if ( ! self::validate( $input ) || ! is_array( $inventory ) || ! is_array( $inventory['rows'] ?? null )
            || count( $inventory['rows'] ) > 480 || ! is_array( $binding )
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
        if ( count( $all ) > 504 ) return self::fail( 'inventory_over_budget' );
        foreach ( $all as $row ) {
            if ( ! is_array( $row ) || ! is_string( $row['id'] ?? null )
                || ! preg_match( '~^(?:plugin:(?:[A-Za-z0-9][A-Za-z0-9._-]{0,89}/)?[A-Za-z0-9][A-Za-z0-9._-]{0,89}\\.php|ability:[A-Za-z0-9._-]+/[A-Za-z0-9._-]+|hint:[a-z_]+:[a-z0-9][a-z0-9._-]{1,79})$~D', $row['id'] )
                || ! self::safe_text( $row['label'] ?? null, 120 )
                || ! in_array( $row['source'] ?? null, array( 'installed_plugin', 'registered_ability', 'external_service', 'connector', 'skill', 'operator' ), true )
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
            $candidates[] = array( 'id' => $row['id'], 'label' => $row['label'],
                'source' => $row['source'], 'observed_state' => $row['observed_state'],
                'metadata_digest' => $row['metadata_digest'] ?? null,
                'matched_terms' => $matches, 'lexical_score' => $score,
                'classification' => 'UNMAPPED_OR_UNVERIFIED',
                'behavior_verified' => false, 'authorization_verified' => false,
                'execution_allowed' => false, 'requires' => array( 'capability_attestation', 'scope_review', 'governed_execution_path' ) );
        }
        usort( $candidates, static function ( $a, $b ) {
            if ( $a['lexical_score'] !== $b['lexical_score'] ) return $b['lexical_score'] - $a['lexical_score'];
            return strcmp( $a['id'], $b['id'] );
        } );
        $limit = $input['limit'] ?? 20; $offset = $input['offset'] ?? 0;
        $snapshot = hash( 'sha256', serialize( array( $binding, $inventory, $input['external_hints'] ?? array(), $goal ) ) );
        return array( 'contract' => self::CONTRACT,
            'binding' => array( 'site_uuid' => $binding['site_uuid'] ?? '', 'profile_digest' => $binding['profile_digest'],
                'runtime_generation' => $binding['runtime_generation'], 'restore_epoch' => $binding['restore_epoch'],
                'artifact_sha256' => $binding['artifact_sha256'] ?? '' ),
            'snapshot_sha256' => $snapshot, 'mode' => $input['mode'] ?? 'match',
            'coverage' => array( 'plugin_inventory_complete' => ! empty( $inventory['plugin_inventory_complete'] ),
                'ability_inventory_complete' => ! empty( $inventory['ability_inventory_complete'] ),
                'ability_visibility_scope' => 'private_metadata_redacted_admin_only',
                'plugin_inventory_scope' => $inventory['plugin_inventory_scope'] ?? 'unknown',
                'external_inventory_complete' => false ),
            'total_matches' => count( $candidates ), 'offset' => $offset, 'limit' => $limit,
            'inventory_incomplete' => empty( $inventory['plugin_inventory_complete'] )
                || empty( $inventory['ability_inventory_complete'] ),
            'next_offset' => $offset + $limit < count( $candidates ) && $offset + $limit <= 400 ? $offset + $limit : null,
            'candidates' => array_slice( $candidates, $offset, $limit ),
            'decision' => count( $candidates ) ? 'EVALUATE_CANDIDATES' : 'EXPAND_INVENTORY_OR_EXTERNAL_DISCOVERY',
            'mapping_required_to_discover' => false, 'authorizing' => false,
            'mutation_performed' => false, 'provider_executed' => false, 'auto_install_allowed' => false );
    }
}
