<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Signed, exact-schema Developer info preparation.
 * Purely prepares a dispatch identity; never grants or executes authority.
 */
final class MAD4B_SCP_Developer_Preparation_Bridge {
    public static function approval_lane( $ability_name, $readonly ) {
        if ( $readonly ) return 'none';
        if ( class_exists( 'MAD4B_SCP_Impact_Policy' ) ) {
            $classification = MAD4B_SCP_Impact_Policy::classify( (string) $ability_name, 'core', array() );
            if ( is_array( $classification ) && isset( $classification['approval_lane'] )
                && in_array( $classification['approval_lane'], array( 'ai_autonomous', 'human_only' ), true ) )
                return (string) $classification['approval_lane'];
        }
        return 'human_only';
    }

    public static function enrich( array $result, $ability_name ) {
        if ( ! class_exists( 'MAD4B_SCP_Capability_Descriptor_Registry' ) ||
             ! class_exists( 'MAD4B_SCP_Ability_Catalog_Transport' ) ||
             ! class_exists( 'MAD4B_SCP_Preparation_Receipt' ) )
            return new WP_Error( 'mad4b_developer_preparation_runtime_unavailable', 'Signed Developer preparation unavailable.' );
        $row = MAD4B_SCP_Capability_Descriptor_Registry::describe( (string) $ability_name );
        if ( is_wp_error( $row ) ) return $row;
        if ( empty( $row['execution_eligible'] ) || 'developer' !== (string) ( $row['execution_lane'] ?? '' ) )
            return new WP_Error( 'mad4b_developer_preparation_not_eligible', 'Developer target not eligible.' );
        if ( ! hash_equals( (string) ( $row['input_schema_sha256'] ?? '' ),
                (string) $result['input_schema_sha256'] ) ||
             ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $row['classification_sha256'] ?? '' ) ) )
            return new WP_Error( 'mad4b_developer_preparation_schema_drift', 'Developer descriptor schema drift.' );
        $receipt = MAD4B_SCP_Preparation_Receipt::issue( $row );
        if ( is_wp_error( $receipt ) ) return $receipt;
        if ( ! is_string( $receipt ) || '' === $receipt )
            return new WP_Error( 'mad4b_developer_preparation_unavailable', 'Signed Developer preparation unavailable.' );
        $result['prepared_dispatch'] = array(
            'expected_input_schema_sha256' => (string) $result['input_schema_sha256'],
            'expected_execution_lane' => 'developer',
            'expected_classification_sha256' => (string) $row['classification_sha256'],
            'expected_authority_scope_sha256' => MAD4B_SCP_Ability_Catalog_Transport::current_authority_scope(),
            'preparation_receipt' => $receipt,
        );
        $result['dispatch_tool'] = 'mad4b/developer-execute';
        $result['preparation_contract'] = MAD4B_SCP_Preparation_Receipt::CONTRACT;
        return $result;
    }
}
