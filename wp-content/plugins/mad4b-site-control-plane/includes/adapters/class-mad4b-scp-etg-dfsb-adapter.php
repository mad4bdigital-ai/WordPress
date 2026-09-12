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
		return array(
			'read' => array(
				'etg-dfsb/status',
				'etg-dfsb/build-identity',
				'etg-dfsb/configuration',
				'etg-dfsb/runtime-inventory',
				'etg-dfsb/profiles',
				'etg-dfsb/profile-blueprint',
				'etg-dfsb/profile-plan',
				'etg-dfsb/content-catalog',
			),
			'content' => array(),
			'admin' => array(),
		);
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
