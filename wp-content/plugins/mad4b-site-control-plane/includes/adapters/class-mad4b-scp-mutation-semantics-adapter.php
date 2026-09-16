<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only registry for mutation reversibility semantics that cannot be safely
 * inferred from the presence or absence of a rollback contract.
 */
final class MAD4B_SCP_Mutation_Semantics_Adapter extends MAD4B_SCP_Adapter_Base {
	const CONTRACT = 'mad4b.mutation-semantics.v1';
	private static $hooked = false;

	public static function boot() {
		if ( self::$hooked ) return;
		self::$hooked = true;
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_with_registry' ), 8, 1 );
	}

	public static function register_with_registry( $registry ) {
		if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) $registry->register( new self() );
	}

	public function id() { return 'mutation-semantics'; }
	public function label() { return 'Mutation Semantics'; }
	public function is_available() { return true; }
	protected function certified_provider_key() { return 'core'; }
	protected function mutation_requires_certification() { return false; }

	public function ability_names() {
		return array(
			'read' => array( 'mad4b/mutation-semantics' ),
			'content' => array(),
			'admin' => array(),
			'write' => array(),
		);
	}

	public function register_abilities() {
		$this->add_ability(
			'mad4b/mutation-semantics',
			'Mutation Reversibility Semantics',
			'mutation_semantics',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema( array( 'ability' => array( 'type' => 'string', 'maxLength' => 191, 'default' => '' ) ) )
		);
	}

	private function irreversible_map() {
		return array(
			'mad4b/taxonomy-delete-term' => array(
				'reversible' => false,
				'impact' => 'high',
				'reason' => 'wordpress_hard_delete_cannot_restore_same_term_identity',
				'compensation' => 'none',
			),
			'jetengine/create-cpt' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_create_identity_rollback_not_proven', 'compensation' => 'provider_specific_required' ),
			'jetengine/create-taxonomy' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_create_identity_rollback_not_proven', 'compensation' => 'provider_specific_required' ),
			'jetengine/create-meta-box' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_create_identity_rollback_not_proven', 'compensation' => 'provider_specific_required' ),
			'jetengine/create-cct' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_create_identity_rollback_not_proven', 'compensation' => 'provider_specific_required' ),
			'jetengine/create-query' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_create_identity_rollback_not_proven', 'compensation' => 'provider_specific_required' ),
			'jetengine/create-glossary' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_create_identity_rollback_not_proven', 'compensation' => 'provider_specific_required' ),
			'jetengine/create-listing' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_create_identity_rollback_not_proven', 'compensation' => 'provider_specific_required' ),
			'jetengine/manage-modules' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_module_compensation_not_proven', 'compensation' => 'provider_specific_required' ),
			'jetengine/import-configuration' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'provider_native_import_compensation_not_proven', 'compensation' => 'provider_specific_required' ),
			'mad4b/provider-import-content' => array( 'reversible' => false, 'impact' => 'high', 'reason' => 'generic_provider_import_compensation_not_proven', 'compensation' => 'runtime_blocked_until_proven' ),
		);
	}

	public function mutation_semantics( $input = array() ) {
		$map = $this->irreversible_map();
		$ability = isset( $input['ability'] ) ? (string) $input['ability'] : '';
		if ( '' !== $ability ) {
			return array(
				'contract' => self::CONTRACT,
				'ability' => $ability,
				'declared' => isset( $map[ $ability ] ),
				'semantics' => isset( $map[ $ability ] ) ? $map[ $ability ] : array(),
			);
		}
		return array( 'contract' => self::CONTRACT, 'count' => count( $map ), 'abilities' => $map );
	}
}
