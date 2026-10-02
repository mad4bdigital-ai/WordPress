<?php
if ( ! defined( 'ABSPATH' ) ) { throw new RuntimeException( 'WordPress is not loaded.' ); }

if ( ! function_exists( 'mad4b_test_prepared_dispatch_identity' ) ) {
	function mad4b_test_prepared_dispatch_identity( $ability_name ) {
		$ability_name = (string) $ability_name;
		if ( ! class_exists( 'MAD4B_SCP_Capability_Descriptor_Registry' )
			|| ! class_exists( 'MAD4B_SCP_Preparation_Receipt' )
			|| ! class_exists( 'MAD4B_SCP_Ability_Catalog_Transport' ) ) {
			return new WP_Error( 'mad4b_test_preparation_unavailable', 'Prepared-dispatch runtime fixtures require the production preparation stack.' );
		}
		$row = MAD4B_SCP_Capability_Descriptor_Registry::describe( $ability_name );
		if ( is_wp_error( $row ) ) return $row;
		$receipt = MAD4B_SCP_Preparation_Receipt::issue( $row );
		if ( ! is_string( $receipt ) || '' === $receipt ) {
			return new WP_Error( 'mad4b_test_preparation_receipt_unavailable', 'Production preparation did not issue signed evidence for the fixture.' );
		}
		return array(
			'expected_input_schema_sha256' => (string) $row['input_schema_sha256'],
			'expected_execution_lane' => (string) $row['execution_lane'],
			'expected_classification_sha256' => (string) $row['classification_sha256'],
			'expected_authority_scope_sha256' => MAD4B_SCP_Ability_Catalog_Transport::current_authority_scope(),
			'preparation_receipt' => $receipt,
		);
	}
}
