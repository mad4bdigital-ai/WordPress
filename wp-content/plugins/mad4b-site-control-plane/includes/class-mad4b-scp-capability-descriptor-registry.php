<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Canonical selected-capability view. No cached authority decisions. */
final class MAD4B_SCP_Capability_Descriptor_Registry {
	const CONTRACT = 'mad4b.capability-descriptor.v2';
	const GENERATION_CONTRACT = 'mad4b.capability-generation-roots.v1';

	public static function describe( $name ) {
		if ( ! class_exists( 'MAD4B_SCP_Ability_Contract_Inspector' ) ) {
			return new WP_Error( 'mad4b_capability_inspector_unavailable', 'Canonical Ability contract inspection is unavailable.' );
		}
		$row = MAD4B_SCP_Ability_Contract_Inspector::inspect( $name );
		if ( is_wp_error( $row ) ) return $row;

		$contract_root = MAD4B_SCP_Ability_Contract_Inspector::digest(
			self::CONTRACT . ':contract',
			array(
				'descriptor_contract' => self::CONTRACT,
				'inspector_contract' => isset( $row['inspector_contract'] ) ? (string) $row['inspector_contract'] : '',
				'classification_contract' => isset( $row['classification_contract'] ) ? (string) $row['classification_contract'] : '',
				'ability_name' => $row['ability_name'],
				'input_schema_sha256' => $row['input_schema_sha256'],
				'classification_sha256' => $row['classification_sha256'],
				'execution_lane' => $row['execution_lane'],
			)
		);
		if ( is_wp_error( $contract_root ) ) return $contract_root;

		$site_root = MAD4B_SCP_Ability_Contract_Inspector::digest(
			self::CONTRACT . ':site',
			array(
				'blog_id' => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1,
				'binding' => MAD4B_SCP_Ability_Contract_Inspector::site_binding(),
			)
		);
		if ( is_wp_error( $site_root ) ) return $site_root;

		$roots = array(
			'contract_root' => $contract_root,
			'site_root' => $site_root,
		);
		$descriptor_sha256 = MAD4B_SCP_Ability_Contract_Inspector::digest( self::GENERATION_CONTRACT, $roots );
		if ( is_wp_error( $descriptor_sha256 ) ) return $descriptor_sha256;

		$row['descriptor_contract'] = self::CONTRACT;
		$row['generation_contract'] = self::GENERATION_CONTRACT;
		$row['generation_roots'] = $roots;
		$row['descriptor_sha256'] = $descriptor_sha256;
		// Provider certification, grants, approvals, policy and impact remain
		// live execution checks. A descriptor is structural evidence, not authority.
		$row['authority_snapshot_is_grant'] = false;
		$row['authority_revalidation_required'] = true;
		return $row;
	}
}
