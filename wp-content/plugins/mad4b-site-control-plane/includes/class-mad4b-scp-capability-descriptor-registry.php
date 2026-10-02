<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Canonical selected-capability view. No cached authority decisions. */
final class MAD4B_SCP_Capability_Descriptor_Registry {
	const CONTRACT = 'mad4b.capability-descriptor.v1';
	public static function describe( $name ) {
		// Existing inspection remains the single classifier and provenance verifier.
		$row = MAD4B_SCP_ChatGPT_Tool_Projection::inspect_contract( $name );
		if ( is_wp_error( $row ) ) return $row;
		$roots = array(
			'contract_root' => hash( 'sha256', wp_json_encode( array( self::CONTRACT, $row['ability_name'], $row['input_schema_sha256'], $row['classification_sha256'], $row['execution_lane'] ) ) ),
			'site_root' => hash( 'sha256', wp_json_encode( array( get_current_blog_id(), MAD4B_SCP_ChatGPT_Tool_Projection::current_binding() ) ) ),
		);
		$row['descriptor_contract'] = self::CONTRACT;
		$row['generation_roots'] = $roots;
		$row['descriptor_sha256'] = hash( 'sha256', wp_json_encode( $roots ) );
		// Provider certification, grants and impact remain live execution checks.
		$row['authority_snapshot_is_grant'] = false;
		$row['authority_revalidation_required'] = true;
		return $row;
	}
}
