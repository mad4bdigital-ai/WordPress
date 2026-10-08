<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact, read-only post translation observation. Uses the existing certified
 * translation bridge, never WPML/Polylang writes or guessed ID translations.
 */
final class MAD4B_SCP_ACI01_Native_Relation_Audit {
    const CONTRACT = 'mad4b.aci01.native-relation-audit.v1';
    const MAX_TRANSLATIONS = 40;
    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 45 );
    }
    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/aci01-native-relation-audit' ) ) return;
        wp_register_ability( 'mad4b/aci01-native-relation-audit', array(
            'label' => 'ACI01 Native Translation Relation Audit',
            'description' => 'Read and compare exact post translation identities through the existing translation bridge; never assigns relations.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'preview' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array( 'type' => 'object', 'additionalProperties' => false,
                'properties' => array(
                    'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
                    'post_type' => array( 'type' => 'string', 'maxLength' => 64 ),
                    'locale' => array( 'type' => 'string', 'maxLength' => 20 ),
                    'provider' => array( 'type' => 'string', 'enum' => array( 'auto', 'wpml', 'polylang' ) ),
                ), 'required' => array( 'post_id', 'post_type', 'locale' ) ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }
    public static function preview( $input = array() ) {
        if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_read()
            || ! class_exists( 'MAD4B_SCP_ACI01_Runtime_Binding', false )
            || ! class_exists( 'MAD4B_SCP_Adapter_Registry', false )
            || ! is_array( $input ) ) return self::deny( 'read_provider_unavailable' );
        foreach ( $input as $key => $value )
            if ( ! in_array( $key, array( 'post_id', 'post_type', 'locale', 'provider' ), true ) )
                return self::deny( 'unexpected_input' );
        $id = $input['post_id'] ?? null;
        $type = $input['post_type'] ?? null;
        $locale = $input['locale'] ?? null;
        $provider = $input['provider'] ?? 'auto';
        if ( ! is_int( $id ) || $id < 1 || ! is_string( $type )
            || ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $type )
            || ! is_string( $locale ) || ! preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,2}$/iD', $locale )
            || ! is_string( $provider ) || ! in_array( $provider, array( 'auto', 'wpml', 'polylang' ), true ) )
            return self::deny( 'target_invalid' );
        $binding = MAD4B_SCP_ACI01_Runtime_Binding::current();
        if ( is_wp_error( $binding ) || ! is_array( $binding ) ) return self::deny( 'binding_unverified' );
        if ( ! current_user_can( 'read_post', $id ) ) return self::deny( 'post_read_denied' );
        $adapter = MAD4B_SCP_Adapter_Registry::instance()->get( 'translation-bridge' );
        if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'translation_get_post' )
            || ! $adapter->is_available() ) return self::deny( 'translation_provider_not_available' );
        $before = $adapter->translation_get_post( array( 'post_id' => $id, 'provider' => $provider ) );
        if ( is_wp_error( $before ) ) return self::deny( 'translation_observation_failed' );
        $after = $adapter->translation_get_post( array( 'post_id' => $id, 'provider' => $provider ) );
        $nextBinding = MAD4B_SCP_ACI01_Runtime_Binding::current();
        if ( is_wp_error( $after ) || is_wp_error( $nextBinding )
            || ! MAD4B_SCP_ACI01_Runtime_Binding::same( $binding, $nextBinding ) )
            return self::deny( 'snapshot_changed' );
        return self::assess( $before, $after, $id, $type, $locale, $binding );
    }

    /** Pure scope/identity checker. A matching post never certifies term/meta parity. */
    public static function assess( $before, $after, $id, $type, $locale, $binding ) {
        if ( ! class_exists( 'MAD4B_SCP_ACI01_Runtime_Binding', false )
            || ! MAD4B_SCP_ACI01_Runtime_Binding::is_valid( $binding ) )
            return self::deny( 'binding_invalid' );
        if ( ! is_int( $id ) || $id < 1 || ! is_string( $type ) || ! is_string( $locale )
            || ! is_array( $before ) || ! is_array( $after )
            || $before !== $after ) return self::deny( 'observation_inconsistent' );
        if ( ! in_array( $before['provider'] ?? '', array( 'wpml', 'polylang' ), true )
            || ! isset( $before['post_id'], $before['post_type'], $before['language'], $before['group'], $before['translations'] )
            || (int) $before['post_id'] !== $id || (string) $before['post_type'] !== $type
            || (string) $before['language'] !== $locale
            || ! is_string( $before['group'] ) || '' === $before['group']
            || ! is_array( $before['translations'] )
            || count( $before['translations'] ) > self::MAX_TRANSLATIONS )
            return self::deny( 'native_identity_mismatch' );
        $linked = array();
        foreach ( $before['translations'] as $lang => $translatedId ) {
            if ( ! is_string( $lang ) || ! preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,2}$/iD', $lang )
                || ! is_int( $translatedId ) || $translatedId < 1
                || ! function_exists( 'current_user_can' ) || ! current_user_can( 'read_post', $translatedId ) )
                return self::deny( 'translated_post_unreadable_or_invalid' );
            $linked[$lang] = $translatedId;
        }
        if ( count( array_unique( array_values( $linked ) ) ) !== count( $linked ) )
            return self::deny( 'duplicate_post_id_across_locales' );
        if ( ! isset( $linked[$locale] ) || $linked[$locale] !== $id )
            return self::deny( 'language_to_post_identity_mismatch' );
        ksort( $linked, SORT_STRING );
        return array(
            'contract' => self::CONTRACT, 'status' => 'NEEDS_EVIDENCE',
            'post_id' => $id, 'post_type' => $type, 'language' => $locale,
            'provider' => $before['provider'],
            'group_digest_sha256' => hash( 'sha256', $before['group'] ),
            'translation_count' => count( $linked ),
            'translation_identity_digest_sha256' => hash( 'sha256', json_encode( $linked ) ),
            'binding' => $binding,
            'reason_codes' => array( 'term_namespace_unverified', 'meta_field_translation_policy_unverified',
                'attachment_relationships_unverified', 'rendered_hreflang_unverified',
                'independent_native_readback_required' ),
            'translation_post_identity_observed' => true,
            'native_relation_certified' => false, 'authorizing' => false,
            'eligible_for_mutation' => false, 'mutation_performed' => false,
            'paid_calls' => 0,
        );
    }
    private static function deny( $why ) {
        return array( 'contract' => self::CONTRACT, 'status' => 'DENIED',
            'reason_codes' => array( $why ), 'native_relation_certified' => false,
            'authorizing' => false, 'eligible_for_mutation' => false,
            'mutation_performed' => false, 'paid_calls' => 0 );
    }
}
