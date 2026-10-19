<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only MCP adapter for ETG Dynamic Filter SEO Bridge Alpha 13.
 *
 * This adapter intentionally consumes ETG's non-authorizing service layer rather
 * than proxying browser AJAX endpoints or creating a second write plane. It does
 * not enable the ETG bridge, mutate profiles, publish SEO state, alter Elementor
 * documents, or grant any authority.
 */
final class MAD4B_SCP_ETG_DFSB_Adapter extends MAD4B_SCP_Adapter_Base {
	const CONTRACT = 'mad4b.etg-dfsb-read-adapter.v1';
	const SUPPORTED_VERSION = '0.4.0-alpha.13';

	public function id() { return 'etg-dfsb'; }
	public function label() { return 'ETG Dynamic Filter SEO Bridge'; }

	public function is_available() {
		return defined( 'ETG_DFSB_VERSION' )
			&& self::SUPPORTED_VERSION === (string) ETG_DFSB_VERSION
			&& class_exists( '\\ETG\\DynamicFilterSEOBridge\\Bootstrap' )
			&& class_exists( '\\ETG\\DynamicFilterSEOBridge\\Config\\Configuration' )
			&& class_exists( '\\ETG\\DynamicFilterSEOBridge\\Config\\ProfileRegistry' )
			&& class_exists( '\\ETG\\DynamicFilterSEOBridge\\Diagnostics\\RuntimeInventory' )
			&& class_exists( '\\ETG\\DynamicFilterSEOBridge\\Diagnostics\\BuildIdentity' )
			&& class_exists( '\\ETG\\DynamicFilterSEOBridge\\Diagnostics\\InventoryProfilePlanner' )
			&& class_exists( '\\ETG\\DynamicFilterSEOBridge\\Presentation\\InventoryContentCatalog' );
	}

	public function ability_names() {
		$read = array(
			'etg-dfsb/status',
			'etg-dfsb/build-identity',
			'etg-dfsb/configuration',
			'etg-dfsb/runtime-inventory',
			'etg-dfsb/profiles',
			'etg-dfsb/profile-blueprint',
			'etg-dfsb/profile-plan',
			'etg-dfsb/profile-diff',
			'etg-dfsb/slot-plan',
			'etg-dfsb/content-catalog',
		);
		$read = array_merge( $read, $this->native_evidence_read_abilities() );
		return array(
			'read' => array_values( array_unique( $read ) ),
			'content' => array(),
			'admin' => array(),
		);
	}

	/**
	 * ETG owns the canonical EvidenceProvider abilities. MAD4B only declares
	 * projection ownership after proving the native abilities are present and
	 * remain bounded read-only callables. Older/drifted ETG packages therefore do
	 * not create dead MCP tools, and no duplicate ability implementation is added.
	 */
	private function native_evidence_read_abilities() {
		$candidates = array( 'etg-dfsb/evidence-provider', 'etg-dfsb/evidence-query' );
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) ) return array();
		$available = array();
		foreach ( $candidates as $ability_name ) {
			if ( ! wp_has_ability( $ability_name ) ) continue;
			$ability = wp_get_ability( $ability_name );
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) || ! method_exists( $ability, 'execute' ) ) continue;
			$meta = $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			if ( ! array_key_exists( 'readonly', $annotations ) || true !== $annotations['readonly'] ) continue;
			if ( ! array_key_exists( 'destructive', $annotations ) || false !== $annotations['destructive'] ) continue;
			$available[] = $ability_name;
		}
		return $available;
	}

	protected function detect_plugin_version() {
		return defined( 'ETG_DFSB_VERSION' ) ? (string) ETG_DFSB_VERSION : '';
	}

	protected function mutation_requires_certification() { return false; }

	protected function provider_certification( $available ) {
		return array(
			'provider' => 'etg-dfsb',
			'label' => 'ETG Dynamic Filter SEO Bridge',
			'status' => $available ? 'read_only_compatible' : 'unavailable_or_version_drift',
			'certified_version' => self::SUPPORTED_VERSION,
			'installed_version' => $this->detect_plugin_version(),
			'contract_mode' => 'read_only_non_authorizing_service_adapter',
			'runtime_contract_ok' => (bool) $available,
			'mutation_certified' => false,
		);
	}

	public function status() {
		$status = parent::status();
		$status['contract'] = self::CONTRACT;
		$status['supported_etg_version'] = self::SUPPORTED_VERSION;
		$status['version_compatible'] = $this->is_available();
		$status['authority_mode'] = 'read_only_non_authorizing';
		$status['mounted_surface'] = 'mad4b-read';
		$status['mutation_exposed'] = false;
		$status['profile_mutation_exposed'] = false;
		$status['seo_publication_mutation_exposed'] = false;
		$status['ajax_proxy_exposed'] = false;
		$status['elementor_document_mutation_delegated_to'] = 'elementor-adapter';
		$status['native_evidence_projection'] = $this->native_evidence_read_abilities();
		if ( $this->is_available() ) {
			try {
				$status['etg_readiness'] = \ETG\DynamicFilterSEOBridge\Bootstrap::instance()->readiness();
			} catch ( Throwable $error ) {
				$status['etg_readiness'] = array( 'available' => false, 'error' => 'readiness_unavailable' );
			}
		}
		return $status;
	}

	public function register_abilities() {
		$read = array( 'MAD4B_SCP_Policy', 'can_read' );
		$this->add_ability( 'etg-dfsb/status', 'Get ETG DFSB MCP Integration Status', 'status', $read );
		$this->add_ability( 'etg-dfsb/build-identity', 'Read ETG DFSB Exact Build Identity', 'build_identity', $read );
		$this->add_ability( 'etg-dfsb/configuration', 'Read ETG DFSB Configuration', 'configuration', $read );
		$this->add_ability( 'etg-dfsb/runtime-inventory', 'Read ETG DFSB Runtime Inventory', 'runtime_inventory', $read );
		$this->add_ability( 'etg-dfsb/profiles', 'Read ETG DFSB Surface Profiles', 'profiles', $read );
		$this->add_ability(
			'etg-dfsb/profile-blueprint',
			'Build ETG DFSB Non-Authorizing Profile Blueprint',
			'profile_blueprint',
			$read,
			$this->schema(
				array(
					'post_type' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
					'taxonomies' => array( 'type' => 'array', 'maxItems' => 20, 'items' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ) ),
					'profile_id' => array( 'type' => 'string', 'maxLength' => 64 ),
				),
				array( 'post_type', 'taxonomies' )
			)
		);
		$this->add_ability( 'etg-dfsb/profile-plan', 'Plan ETG DFSB Profiles from Runtime Inventory', 'profile_plan', $read );
		$this->add_ability(
			'etg-dfsb/profile-diff', 'Compare ETG Profile Against Verified Runtime Plan',
			'profile_diff', $read,
			$this->schema( array( 'profile_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ) ), array( 'profile_id' ) )
		);
		$this->add_ability(
			'etg-dfsb/slot-plan', 'Preview ETG Dynamic Content Slot Without Saving',
			'slot_plan', $read,
			$this->schema( array( 'slot' => array( 'type' => 'object', 'maxProperties' => 24, 'additionalProperties' => true ) ), array( 'slot' ) )
		);
		$this->add_ability( 'etg-dfsb/content-catalog', 'Read ETG DFSB Dynamic Content Catalog', 'content_catalog', $read );
	}

    public function build_identity() {
        $guard = $this->guard_runtime();
        if ( is_wp_error( $guard ) ) return $guard;
        try {
  $identity = \ETG\DynamicFilterSEOBridge\Diagnostics\BuildIdentity::collect();
  if ( ! is_array( $identity ) ) return $this->runtime_error( 'build_identity_unavailable' );
  $identity['adapter_contract'] = self::CONTRACT;
  $identity['authorizing'] = false;
  $identity['read_only'] = true;
  $identity['mutation_exposed'] = false;
  return $identity;
        } catch ( Throwable $error ) {
  return $this->runtime_error( 'build_identity_unavailable' );
        }
    }

	public function configuration() {
		$guard = $this->guard_runtime();
		if ( is_wp_error( $guard ) ) return $guard;
		try {
			$config = new \ETG\DynamicFilterSEOBridge\Config\Configuration();
			return array(
				'contract' => self::CONTRACT,
				'authorizing' => false,
				'read_only' => true,
				'mutation_exposed' => false,
				'enabled' => $config->enabled(),
				'revision' => $config->revision(),
				'validation_errors' => $config->validationErrors(),
				'configuration' => $config->all(),
			);
		} catch ( Throwable $error ) {
			return $this->runtime_error( 'configuration_unavailable' );
		}
	}

	public function runtime_inventory() {
		$guard = $this->guard_runtime();
		if ( is_wp_error( $guard ) ) return $guard;
		try {
			$inventory = new \ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory();
			return $inventory->collect();
		} catch ( Throwable $error ) {
			return $this->runtime_error( 'runtime_inventory_unavailable' );
		}
	}

	public function profiles() {
		$guard = $this->guard_runtime();
		if ( is_wp_error( $guard ) ) return $guard;
		try {
			$config = new \ETG\DynamicFilterSEOBridge\Config\Configuration();
			$registry = new \ETG\DynamicFilterSEOBridge\Config\ProfileRegistry( $config );
			$profiles = $registry->all();
			return array(
				'contract' => self::CONTRACT,
				'authorizing' => false,
				'read_only' => true,
				'profile_mutation' => false,
				'profile_count' => count( $profiles ),
				'validation_errors' => $registry->validationErrors(),
				'profiles' => $profiles,
				'discovery' => $registry->discovery(),
			);
		} catch ( Throwable $error ) {
			return $this->runtime_error( 'profiles_unavailable' );
		}
	}

	public function profile_blueprint( $input ) {
		$guard = $this->guard_runtime();
		if ( is_wp_error( $guard ) ) return $guard;
		$input = is_array( $input ) ? $input : array();
		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
		$profile_id = isset( $input['profile_id'] ) ? sanitize_key( (string) $input['profile_id'] ) : '';
		$taxonomies = array();
		foreach ( array_slice( isset( $input['taxonomies'] ) && is_array( $input['taxonomies'] ) ? $input['taxonomies'] : array(), 0, 20 ) as $taxonomy ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			if ( '' !== $taxonomy ) $taxonomies[] = $taxonomy;
		}
		$taxonomies = array_values( array_unique( $taxonomies ) );
		if ( '' === $post_type ) return new WP_Error( 'mad4b_etg_dfsb_post_type_required', 'A bounded post_type is required.' );
		try {
			$config = new \ETG\DynamicFilterSEOBridge\Config\Configuration();
			$registry = new \ETG\DynamicFilterSEOBridge\Config\ProfileRegistry( $config );
			return $registry->blueprint( $post_type, $taxonomies, $profile_id );
		} catch ( Throwable $error ) {
			return $this->runtime_error( 'profile_blueprint_unavailable' );
		}
	}

	public function profile_plan() {
		$guard = $this->guard_runtime();
		if ( is_wp_error( $guard ) ) return $guard;
		try {
			$config = new \ETG\DynamicFilterSEOBridge\Config\Configuration();
			$registry = new \ETG\DynamicFilterSEOBridge\Config\ProfileRegistry( $config );
			$inventory = new \ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory();
			$planner = new \ETG\DynamicFilterSEOBridge\Diagnostics\InventoryProfilePlanner();
			return $planner->plan( $inventory->collect(), $registry->all() );
		} catch ( Throwable $error ) {
			return $this->runtime_error( 'profile_plan_unavailable' );
		}
	}

	/**
	 * Governed read planning only. An inventory proposal is not a write grant
	 * or a signal that publication or indexing is safe.
	 */
	public function profile_diff( $input = array() ) {
		$guard = $this->guard_runtime();
		if ( is_wp_error( $guard ) ) return $guard;
		$input = is_array( $input ) ? $input : array();
		$id = isset( $input['profile_id'] ) ? sanitize_key( (string) $input['profile_id'] ) : '';
		if ( '' === $id ) return new WP_Error( 'mad4b_etg_profile_id_required', 'A Profile ID is required.' );
		try {
			$config = new \ETG\DynamicFilterSEOBridge\Config\Configuration();
			$profiles = new \ETG\DynamicFilterSEOBridge\Config\ProfileRegistry( $config );
			$inventory = new \ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory();
			$snapshot = $inventory->collect();
			$planner = new \ETG\DynamicFilterSEOBridge\Diagnostics\InventoryProfilePlanner();
			$plan = $planner->plan( $snapshot, $profiles->all() );
			$proposal = isset( $plan['proposals'][ $id ] ) && is_array( $plan['proposals'][ $id ] ) ? $plan['proposals'][ $id ] : array();
			if ( ! $proposal ) return new WP_Error( 'mad4b_etg_profile_not_found', 'No verified proposal exists for this Profile.' );
			$changes = is_array( $proposal['changes'] ?? null ) ? array_values( $proposal['changes'] ) : array();
			if ( count( $changes ) > 50 ) return new WP_Error( 'mad4b_etg_diff_too_large', 'Profile diff exceeds the bounded read contract.' );
			return array(
				'contract' => 'mad4b.etg-dfsb.profile-diff.v1',
				'profile_id' => $id, 'revision' => $config->revision(),
				'snapshot_fingerprint' => (string) ( $snapshot['snapshot_fingerprint'] ?? '' ),
				'state' => $changes ? 'review_required' : 'aligned',
				'structural_plan_eligible' => ! empty( $proposal['safe_to_apply'] ),
				'blocking_reasons' => array_values( (array) ( $proposal['blocking_reasons'] ?? array() ) ),
				'changes' => $changes, 'authorizing' => false,
				'read_only' => true, 'apply_authorized' => false,
				'mutation_performed' => false,
			);
		} catch ( Throwable $error ) {
			return $this->runtime_error( 'profile_diff_unavailable' );
		}
	}

	/** Dry-run Content Slot normalization and compare hashes, never write options. */
	public function slot_plan( $input = array() ) {
		$guard = $this->guard_runtime();
		if ( is_wp_error( $guard ) ) return $guard;
		$input = is_array( $input ) ? $input : array();
		$raw = isset( $input['slot'] ) && is_array( $input['slot'] ) ? $input['slot'] : array();
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $raw ) : json_encode( $raw );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 32768 ) {
			return new WP_Error( 'mad4b_etg_slot_plan_too_large', 'Slot planning payload exceeds bounded limits.' );
		}
		try {
			if ( ! class_exists( '\ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry' ) ) return $this->runtime_error( 'slot_registry_unavailable' );
			$slots = new \ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry();
			$proposed = $slots->normalize( $raw );
			$id = (string) ( $proposed['id'] ?? '' );
			if ( '' === $id || 0 === strpos( $id, 'etg_background_' ) ) {
				return new WP_Error( 'mad4b_etg_slot_id_invalid', 'A non-reserved Slot ID is required.' );
			}
			$current = $slots->get( $id );
			unset( $current['origin'], $current['internal'] );
			$keys = array_values( array_unique( array_merge( array_keys( $current ), array_keys( $proposed ) ) ) );
			$changed = array();
			foreach ( $keys as $key ) {
				if ( ( $current[ $key ] ?? null ) !== ( $proposed[ $key ] ?? null ) ) $changed[] = $key;
			}
			return array(
				'contract' => 'mad4b.etg-dfsb.slot-plan.v1',
				'slot_id' => $id, 'revision' => $slots->revision(),
				'state' => ! $current ? 'create_candidate' : ( $changed ? 'update_candidate' : 'aligned' ),
				'changed_fields' => array_slice( $changed, 0, 24 ),
				'current_sha256' => hash( 'sha256', function_exists( 'wp_json_encode' ) ? wp_json_encode( $current ) : json_encode( $current ) ),
				'proposed_sha256' => hash( 'sha256', function_exists( 'wp_json_encode' ) ? wp_json_encode( $proposed ) : json_encode( $proposed ) ),
				'authorizing' => false, 'read_only' => true,
				'apply_authorized' => false, 'seo_publication_authorized' => false,
				'mutation_performed' => false,
			);
		} catch ( Throwable $error ) {
			return $this->runtime_error( 'slot_plan_unavailable' );
		}
	}

	public function content_catalog() {
		$guard = $this->guard_runtime();
		if ( is_wp_error( $guard ) ) return $guard;
		try {
			$config = new \ETG\DynamicFilterSEOBridge\Config\Configuration();
			$registry = new \ETG\DynamicFilterSEOBridge\Config\ProfileRegistry( $config );
			$inventory = new \ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory();
			$catalog = new \ETG\DynamicFilterSEOBridge\Presentation\InventoryContentCatalog();
			return $catalog->build( $inventory->collect(), $registry->all() );
		} catch ( Throwable $error ) {
			return $this->runtime_error( 'content_catalog_unavailable' );
		}
	}

	private function guard_runtime() {
		if ( ! defined( 'ETG_DFSB_VERSION' ) || ! class_exists( '\\ETG\\DynamicFilterSEOBridge\\Bootstrap' ) ) {
			return new WP_Error( 'mad4b_etg_dfsb_unavailable', 'ETG Dynamic Filter SEO Bridge is not active.' );
		}
		if ( self::SUPPORTED_VERSION !== (string) ETG_DFSB_VERSION ) {
			return new WP_Error(
				'mad4b_etg_dfsb_version_drift',
				'ETG DFSB read adapter is pinned to the certified Alpha 13 service contract.',
				array( 'supported_version' => self::SUPPORTED_VERSION, 'installed_version' => (string) ETG_DFSB_VERSION )
			);
		}
		if ( ! $this->is_available() ) return $this->unavailable_error();
		return true;
	}

	private function runtime_error( $code ) {
		return new WP_Error( 'mad4b_etg_dfsb_' . sanitize_key( (string) $code ), 'ETG DFSB read service is unavailable for this request.' );
	}
}

add_action( 'mad4b_scp_register_adapters', static function ( $registry ) {
	if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) {
		$registry->register( new MAD4B_SCP_ETG_DFSB_Adapter() );
	}
} );
