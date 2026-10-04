<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'MAD4B_SCP_Canonicalization' ) ) require_once __DIR__ . '/class-mad4b-scp-canonicalization.php';

/** Canonical selected-capability view. No cached authority decisions. */
final class MAD4B_SCP_Capability_Descriptor_Registry {
	const CONTRACT = 'mad4b.capability-descriptor.v2';
	const GENERATION_CONTRACT = 'mad4b.capability-generation-roots.v1';
	const CONSUMER_BINDING_CONTRACT = 'mad4b.capability-descriptor-consumer-binding.v1';

	public static function binding( $name, $consumer ) {
		$consumer = sanitize_key( (string) $consumer );
		if ( '' === $consumer ) return new WP_Error( 'mad4b_capability_descriptor_consumer_invalid', 'Capability Descriptor consumer identity is required.' );
		$row = self::describe( $name );
		if ( is_wp_error( $row ) ) return $row;
		return array(
			'contract' => self::CONSUMER_BINDING_CONTRACT,
			'consumer' => $consumer,
			'descriptor_contract' => (string) $row['descriptor_contract'],
			'generation_contract' => (string) $row['generation_contract'],
			'ability_name' => (string) $row['ability_name'],
			'input_schema_sha256' => (string) $row['input_schema_sha256'],
			'classification_sha256' => (string) $row['classification_sha256'],
			'execution_lane' => (string) $row['execution_lane'],
			'descriptor_sha256' => (string) $row['descriptor_sha256'],
			'generation_roots' => $row['generation_roots'],
			'authority_snapshot_is_grant' => false,
			'authority_revalidation_required' => true,
			'authorizing' => false,
		);
	}

	public static function assert_binding( $name, array $expected, $consumer ) {
		$current = self::binding( $name, $consumer );
		if ( is_wp_error( $current ) ) return $current;
		foreach ( array( 'contract', 'consumer', 'descriptor_contract', 'generation_contract', 'ability_name', 'input_schema_sha256', 'classification_sha256', 'execution_lane', 'descriptor_sha256' ) as $field ) {
			$a = isset( $expected[ $field ] ) ? (string) $expected[ $field ] : '';
			$b = isset( $current[ $field ] ) ? (string) $current[ $field ] : '';
			if ( '' === $a || ! hash_equals( $a, $b ) ) return new WP_Error( 'mad4b_capability_descriptor_binding_drift', 'Canonical Capability Descriptor binding changed.', array( 'field' => $field, 'ability_name' => (string) $name ) );
		}
		$expected_roots = isset( $expected['generation_roots'] ) && is_array( $expected['generation_roots'] ) ? $expected['generation_roots'] : array();
		if ( $expected_roots !== $current['generation_roots'] ) return new WP_Error( 'mad4b_capability_descriptor_generation_drift', 'Capability Descriptor generation roots changed.', array( 'ability_name' => (string) $name ) );
		return $current;
	}

	public static function describe( $name ) {
		$canonical_name = MAD4B_SCP_Canonicalization::ability_name( $name );
		if ( is_wp_error( $canonical_name ) ) return $canonical_name;
		$name = $canonical_name;
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
		$extension_roots = apply_filters( 'mad4b_scp_capability_descriptor_generation_roots', array(), $name, $row );
		if ( ! is_array( $extension_roots ) ) return new WP_Error( 'mad4b_capability_descriptor_extension_roots_invalid', 'Capability Descriptor extension roots must be an object.' );
		$clean_extension_roots = array();
		foreach ( $extension_roots as $key => $digest ) {
			$key = sanitize_key( (string) $key );
			$digest = strtolower( trim( (string) $digest ) );
			if ( '' === $key || 1 !== preg_match( '/^[a-f0-9]{64}$/', $digest ) ) {
				return new WP_Error( 'mad4b_capability_descriptor_extension_root_invalid', 'Capability Descriptor extension root is malformed.' );
			}
			$clean_extension_roots[ $key ] = $digest;
		}
		if ( $clean_extension_roots ) {
			ksort( $clean_extension_roots, SORT_STRING );
			$roots['extension_roots'] = $clean_extension_roots;
		}
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
