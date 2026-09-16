<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bridges provider-native WordPress Abilities/MCP tools into MAD4B's governed
 * authority without exposing a generic executor.
 *
 * JetEngine development operations are fixed semantic wrappers. Every wrapper
 * is exact-bound to the discovered native ability name + input schema hash and
 * enforces the native ability's declared read/write mode before execution.
 */
final class MAD4B_SCP_Native_Provider_Bridge_Adapter extends MAD4B_SCP_Adapter_Base {
	const CONTRACT = 'mad4b.native-provider-bridge.v1';
	private static $hooked = false;

	public static function boot() {
		if ( self::$hooked ) return;
		self::$hooked = true;
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_with_registry' ), 7, 1 );
	}

	public static function register_with_registry( $registry ) {
		if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) $registry->register( new self() );
	}

	public function id() { return 'native-provider-bridge'; }
	public function label() { return 'Native Provider Bridge'; }
	public function is_available() { return function_exists( 'wp_get_abilities' ) && function_exists( 'wp_get_ability' ); }
	protected function certified_provider_key() { return 'native-provider'; }
	protected function provider_certification( $available ) { return null; }
	protected function mutation_requires_certification() { return false; }

	public function ability_names() {
		return array(
			'read' => array(
				'mad4b/provider-native-tools-inventory',
				'jetengine/native-tools-inventory',
				'jetengine/get-native-configuration',
				'jetengine/get-native-website-config',
				'jetengine/get-native-macros',
				'jetengine/export-configuration',
				'mad4b/provider-export-content',
			),
			'content' => array(),
			'admin' => array(),
			'write' => array(
				'jetengine/create-cpt',
				'jetengine/create-taxonomy',
				'jetengine/create-meta-box',
				'jetengine/create-cct',
				'jetengine/create-query',
				'jetengine/create-glossary',
				'jetengine/create-listing',
				'jetengine/manage-modules',
				'jetengine/import-configuration',
				'mad4b/provider-import-content',
			),
		);
	}

	public function register_abilities() {
		$read = array( 'MAD4B_SCP_Policy', 'can_read' );
		$admin = array( 'MAD4B_SCP_Policy', 'can_admin' );

		$this->add_ability( 'mad4b/provider-native-tools-inventory', 'Provider Native Tools Inventory', 'provider_inventory', $read, $this->schema( array(
			'provider_hint' => array( 'type' => 'string', 'maxLength' => 100, 'default' => '' ),
			'write_only' => array( 'type' => 'boolean', 'default' => false ),
		) ) );
		$this->add_ability( 'jetengine/native-tools-inventory', 'JetEngine Native MCP Tools Inventory', 'jetengine_inventory', $read );
		$this->add_ability( 'jetengine/get-native-configuration', 'Get Native JetEngine Configuration', 'jetengine_get_configuration', $read, $this->native_call_schema( false ) );
		$this->add_ability( 'jetengine/get-native-website-config', 'Get Native Website Configuration', 'jetengine_get_website_config', $read, $this->native_call_schema( false ) );
		$this->add_ability( 'jetengine/get-native-macros', 'Get Native JetEngine Macros', 'jetengine_get_macros', $read, $this->native_call_schema( false ) );
		$this->add_ability( 'jetengine/export-configuration', 'Export Native JetEngine Configuration', 'jetengine_export_configuration', $admin, $this->native_call_schema( false ), 'read', true, false, true );
		$this->add_ability( 'mad4b/provider-export-content', 'Provider Native Content Export', 'provider_export_content', $admin, $this->provider_call_schema(), 'read', true, false, true );

		$map = array(
			'jetengine/create-cpt' => array( 'jetengine_create_cpt', 'create_cpt' ),
			'jetengine/create-taxonomy' => array( 'jetengine_create_taxonomy', 'create_taxonomy' ),
			'jetengine/create-meta-box' => array( 'jetengine_create_meta_box', 'create_meta_box' ),
			'jetengine/create-cct' => array( 'jetengine_create_cct', 'create_cct' ),
			'jetengine/create-query' => array( 'jetengine_create_query', 'create_query' ),
			'jetengine/create-glossary' => array( 'jetengine_create_glossary', 'create_glossary' ),
			'jetengine/create-listing' => array( 'jetengine_create_listing', 'create_listing' ),
			'jetengine/manage-modules' => array( 'jetengine_manage_modules', 'manage_modules' ),
			'jetengine/import-configuration' => array( 'jetengine_import_configuration', 'import_configuration' ),
		);
		foreach ( $map as $ability => $pair ) {
			$this->add_ability(
				$ability,
				ucwords( str_replace( array( 'jetengine/','-' ), array( 'JetEngine ',' ' ), $ability ) ),
				$pair[0],
				$admin,
				$this->native_call_schema( true ),
				'write',
				false,
				true,
				false
			);
		}
		$this->add_ability( 'mad4b/provider-import-content', 'Provider Native Content Import', 'provider_import_content', $admin, $this->provider_call_schema(), 'write', false, true, false );
	}

	private function native_call_schema( $require_input ) {
		$props = array(
			'input' => array( 'type' => 'object', 'additionalProperties' => true, 'default' => array() ),
			'expected_native_ability' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191 ),
			'expected_schema_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[a-f0-9]{64}$' ),
		);
		$required = array( 'expected_native_ability', 'expected_schema_sha256' );
		if ( $require_input ) $required[] = 'input';
		return $this->schema( $props, $required );
	}

	private function provider_call_schema() {
		return $this->schema( array(
			'provider_hint' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 100 ),
			'expected_native_ability' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 191 ),
			'expected_schema_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[a-f0-9]{64}$' ),
			'input' => array( 'type' => 'object', 'additionalProperties' => true, 'default' => array() ),
		), array( 'provider_hint', 'expected_native_ability', 'expected_schema_sha256', 'input' ) );
	}

	private function ability_meta( $ability ) { return is_object( $ability ) && method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : array(); }
	private function ability_input_schema( $ability ) { return is_object( $ability ) && method_exists( $ability, 'get_input_schema' ) ? (array) $ability->get_input_schema() : array(); }
	private function ability_output_schema( $ability ) { return is_object( $ability ) && method_exists( $ability, 'get_output_schema' ) ? (array) $ability->get_output_schema() : array(); }
	private function schema_hash( $ability ) { return hash( 'sha256', wp_json_encode( $this->ability_input_schema( $ability ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); }

	private function ability_row( $name, $ability ) {
		$meta = $this->ability_meta( $ability );
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		return array(
			'name' => (string) $name,
			'label' => method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : '',
			'description' => method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '',
			'category' => method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '',
			'readonly' => array_key_exists( 'readonly', $annotations ) ? (bool) $annotations['readonly'] : null,
			'destructive' => array_key_exists( 'destructive', $annotations ) ? (bool) $annotations['destructive'] : null,
			'idempotent' => array_key_exists( 'idempotent', $annotations ) ? (bool) $annotations['idempotent'] : null,
			'input_schema' => $this->ability_input_schema( $ability ),
			'output_schema' => $this->ability_output_schema( $ability ),
			'schema_sha256' => $this->schema_hash( $ability ),
		);
	}

	private function mad4b_owned_row( array $row ) {
		$name = isset( $row['name'] ) ? (string) $row['name'] : '';
		$category = isset( $row['category'] ) ? sanitize_key( (string) $row['category'] ) : '';
		return 0 === strpos( $name, 'mad4b/' ) || 0 === strpos( $category, 'mad4b-' );
	}

	private function native_rows() {
		$rows = array();
		foreach ( wp_get_abilities() as $name => $ability ) {
			$name = (string) $name;
			$row = $this->ability_row( $name, $ability );
			if ( $this->mad4b_owned_row( $row ) ) continue;
			if ( in_array( $name, $this->ability_names()['write'], true ) || in_array( $name, $this->ability_names()['read'], true ) ) continue;
			$rows[ $name ] = $row;
		}
		ksort( $rows, SORT_STRING );
		return $rows;
	}

	private function normalize_text( $text ) {
		$text = strtolower( wp_strip_all_tags( (string) $text ) );
		$text = preg_replace( '/[^a-z0-9]+/', ' ', $text );
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}
	private function row_text( array $row ) { return $this->normalize_text( $row['name'] . ' ' . $row['label'] . ' ' . $row['description'] . ' ' . $row['category'] ); }
	private function contains_all( $text, array $tokens ) { foreach ( $tokens as $token ) if ( false === strpos( $text, $token ) ) return false; return true; }
	private function has_any( $text, array $tokens ) { foreach ( $tokens as $token ) if ( false !== strpos( $text, $token ) ) return true; return false; }
	private function jetengine_row( array $row ) {
		$text = $this->row_text( $row );
		return false !== strpos( $text, 'jetengine' ) || false !== strpos( $text, 'jet engine' ) || false !== strpos( $text, 'crocoblock' );
	}

	private function operation_matches( $operation, array $row ) {
		if ( ! $this->jetengine_row( $row ) ) return false;
		$text = $this->row_text( $row );
		$create = array( 'add','create','register' );
		switch ( $operation ) {
			case 'create_cpt': return $this->contains_all( $text, array( 'custom','post','type' ) ) && $this->has_any( $text, $create ) && false === strpos( $text, 'content type' );
			case 'create_taxonomy': return false !== strpos( $text, 'taxonomy' ) && $this->has_any( $text, $create );
			case 'create_meta_box': return $this->contains_all( $text, array( 'meta','box' ) ) && $this->has_any( $text, $create );
			case 'create_cct': return $this->contains_all( $text, array( 'custom','content','type' ) ) && $this->has_any( $text, $create );
			case 'create_query': return false !== strpos( $text, 'query' ) && $this->has_any( $text, $create );
			case 'create_glossary': return false !== strpos( $text, 'glossary' ) && $this->has_any( $text, $create );
			case 'create_listing': return false !== strpos( $text, 'listing' ) && $this->has_any( $text, $create );
			case 'manage_modules': return false !== strpos( $text, 'module' ) && $this->has_any( $text, array( 'manage','enable','disable' ) );
			case 'get_configuration': return false !== strpos( $text, 'config' ) && $this->has_any( $text, array( 'get','retrieve','configuration' ) );
			case 'get_website_config': return $this->contains_all( $text, array( 'website','config' ) ) && $this->has_any( $text, array( 'get','retrieve' ) );
			case 'get_macros': return false !== strpos( $text, 'macro' ) && $this->has_any( $text, array( 'get','list','retrieve' ) );
			case 'import_configuration': return false !== strpos( $text, 'import' );
			case 'export_configuration': return false !== strpos( $text, 'export' );
		}
		return false;
	}

	private function resolve_operation( $operation ) {
		$matches = array();
		foreach ( $this->native_rows() as $name => $row ) if ( $this->operation_matches( $operation, $row ) ) $matches[ $name ] = $row;
		if ( 1 !== count( $matches ) ) {
			return new WP_Error(
				0 === count( $matches ) ? 'mad4b_native_provider_operation_unavailable' : 'mad4b_native_provider_operation_ambiguous',
				0 === count( $matches ) ? 'No unique JetEngine provider-native ability matches the requested semantic operation.' : 'Multiple JetEngine provider-native abilities match the requested semantic operation.',
				array( 'operation' => $operation, 'matches' => array_values( $matches ) )
			);
		}
		$name = key( $matches );
		return array( 'name' => $name, 'row' => current( $matches ), 'ability' => wp_get_ability( $name ) );
	}

	public function provider_inventory( $input = array() ) {
		$hint = isset( $input['provider_hint'] ) ? $this->normalize_text( $input['provider_hint'] ) : '';
		$write_only = ! empty( $input['write_only'] );
		$items = array();
		foreach ( $this->native_rows() as $row ) {
			$text = $this->row_text( $row );
			if ( '' !== $hint && false === strpos( $text, $hint ) ) continue;
			if ( $write_only && false !== $row['readonly'] ) continue;
			$items[] = $row;
			if ( count( $items ) >= 300 ) break;
		}
		return array( 'contract' => self::CONTRACT, 'count' => count( $items ), 'abilities' => $items );
	}

	public function jetengine_inventory() {
		$ops = array( 'get_configuration','get_website_config','get_macros','create_cpt','create_taxonomy','create_meta_box','create_cct','create_query','create_glossary','create_listing','manage_modules','import_configuration','export_configuration' );
		$items = array();
		foreach ( $ops as $op ) {
			$resolved = $this->resolve_operation( $op );
			$items[] = is_wp_error( $resolved )
				? array( 'operation' => $op, 'available' => false, 'error' => $resolved->get_error_code(), 'matches' => (array) $resolved->get_error_data() )
				: array_merge( array( 'operation' => $op, 'available' => true ), $resolved['row'] );
		}
		return array(
			'contract' => self::CONTRACT,
			'jetengine_available' => function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' ),
			'operations' => $items,
			'count' => count( $items ),
		);
	}

	private function enforce_native_mode( array $row, $expect_write ) {
		if ( $expect_write ) {
			return false === $row['readonly']
				? true
				: new WP_Error( 'mad4b_native_provider_write_mode_unverified', 'Provider-native write execution requires an explicit readonly=false annotation.' );
		}
		return true === $row['readonly']
			? true
			: new WP_Error( 'mad4b_native_provider_read_mode_unverified', 'Provider-native read execution requires an explicit readonly=true annotation.' );
	}

	/**
	 * Optional per-ability runtime mount gate consumed by MAD4B_SCP_Servers.
	 * Stable external discovery remains unchanged, but execution mount/grants are
	 * withheld until the provider-native capability exists with an explicit write mode.
	 */
	public function mutation_ability_runtime_eligibility( $ability_name ) {
		$ability_name = (string) $ability_name;
		$jetengine = array(
			'jetengine/create-cpt' => 'create_cpt',
			'jetengine/create-taxonomy' => 'create_taxonomy',
			'jetengine/create-meta-box' => 'create_meta_box',
			'jetengine/create-cct' => 'create_cct',
			'jetengine/create-query' => 'create_query',
			'jetengine/create-glossary' => 'create_glossary',
			'jetengine/create-listing' => 'create_listing',
			'jetengine/manage-modules' => 'manage_modules',
			'jetengine/import-configuration' => 'import_configuration',
		);
		if ( isset( $jetengine[ $ability_name ] ) ) {
			$resolved = $this->resolve_operation( $jetengine[ $ability_name ] );
			if ( is_wp_error( $resolved ) ) return $resolved;
			return $this->enforce_native_mode( $resolved['row'], true );
		}
		if ( 'mad4b/provider-import-content' === $ability_name ) {
			foreach ( $this->native_rows() as $row ) {
				if ( false !== $row['readonly'] ) continue;
				if ( false !== strpos( $this->row_text( $row ), 'import' ) ) return true;
			}
			return new WP_Error( 'mad4b_provider_import_runtime_unavailable', 'No provider-native ability currently exposes an explicit write-mode import operation.' );
		}
		return true;
	}

	private function execute_resolved( $operation, array $input, $expect_write ) {
		$resolved = $this->resolve_operation( $operation );
		if ( is_wp_error( $resolved ) ) return $resolved;
		$row = $resolved['row'];
		$expected_name = isset( $input['expected_native_ability'] ) ? (string) $input['expected_native_ability'] : '';
		$expected_hash = isset( $input['expected_schema_sha256'] ) ? strtolower( (string) $input['expected_schema_sha256'] ) : '';
		if ( '' === $expected_name || ! hash_equals( $resolved['name'], $expected_name ) ) return new WP_Error( 'mad4b_native_provider_ability_drift', 'Resolved provider-native ability no longer matches the planned ability.', array( 'current_native_ability' => $resolved['name'] ) );
		if ( '' === $expected_hash || ! hash_equals( $row['schema_sha256'], $expected_hash ) ) return new WP_Error( 'mad4b_native_provider_schema_drift', 'Provider-native input schema changed since planning.', array( 'current_schema_sha256' => $row['schema_sha256'] ) );
		$mode = $this->enforce_native_mode( $row, (bool) $expect_write );
		if ( is_wp_error( $mode ) ) return $mode;
		$provider_input = isset( $input['input'] ) && is_array( $input['input'] ) ? $input['input'] : array();
		return $resolved['ability']->execute( $provider_input );
	}

	public function jetengine_get_configuration( $input ) { return $this->execute_resolved( 'get_configuration', $input, false ); }
	public function jetengine_get_website_config( $input ) { return $this->execute_resolved( 'get_website_config', $input, false ); }
	public function jetengine_get_macros( $input ) { return $this->execute_resolved( 'get_macros', $input, false ); }
	public function jetengine_create_cpt( $input ) { return $this->execute_resolved( 'create_cpt', $input, true ); }
	public function jetengine_create_taxonomy( $input ) { return $this->execute_resolved( 'create_taxonomy', $input, true ); }
	public function jetengine_create_meta_box( $input ) { return $this->execute_resolved( 'create_meta_box', $input, true ); }
	public function jetengine_create_cct( $input ) { return $this->execute_resolved( 'create_cct', $input, true ); }
	public function jetengine_create_query( $input ) { return $this->execute_resolved( 'create_query', $input, true ); }
	public function jetengine_create_glossary( $input ) { return $this->execute_resolved( 'create_glossary', $input, true ); }
	public function jetengine_create_listing( $input ) { return $this->execute_resolved( 'create_listing', $input, true ); }
	public function jetengine_manage_modules( $input ) { return $this->execute_resolved( 'manage_modules', $input, true ); }
	public function jetengine_import_configuration( $input ) { return $this->execute_resolved( 'import_configuration', $input, true ); }
	public function jetengine_export_configuration( $input ) { return $this->execute_resolved( 'export_configuration', $input, false ); }

	private function resolve_provider_semantic( $semantic, $provider_hint, $expected_name, $expect_write ) {
		$hint = $this->normalize_text( $provider_hint );
		$matches = array();
		foreach ( $this->native_rows() as $name => $row ) {
			$text = $this->row_text( $row );
			if ( '' !== $hint && false === strpos( $text, $hint ) ) continue;
			if ( false === strpos( $text, $semantic ) ) continue;
			if ( '' !== $expected_name && $name !== $expected_name ) continue;
			$mode = $this->enforce_native_mode( $row, (bool) $expect_write );
			if ( is_wp_error( $mode ) ) continue;
			$matches[ $name ] = $row;
		}
		if ( 1 !== count( $matches ) ) return new WP_Error(
			0 === count( $matches ) ? 'mad4b_provider_semantic_operation_unavailable' : 'mad4b_provider_semantic_operation_ambiguous',
			'Provider semantic operation did not resolve to exactly one native ability with the required read/write mode.',
			array( 'semantic' => $semantic, 'provider_hint' => $provider_hint, 'matches' => array_values( $matches ) )
		);
		$name = key( $matches );
		return array( 'name' => $name, 'row' => current( $matches ), 'ability' => wp_get_ability( $name ) );
	}

	private function execute_provider_semantic( $semantic, array $input, $expect_write ) {
		$expected_name = (string) $input['expected_native_ability'];
		$resolved = $this->resolve_provider_semantic( $semantic, (string) $input['provider_hint'], $expected_name, (bool) $expect_write );
		if ( is_wp_error( $resolved ) ) return $resolved;
		if ( ! hash_equals( $resolved['row']['schema_sha256'], strtolower( (string) $input['expected_schema_sha256'] ) ) ) return new WP_Error( 'mad4b_native_provider_schema_drift', 'Provider-native input schema changed since planning.', array( 'current_schema_sha256' => $resolved['row']['schema_sha256'] ) );
		return $resolved['ability']->execute( isset( $input['input'] ) && is_array( $input['input'] ) ? $input['input'] : array() );
	}

	public function provider_import_content( $input ) { return $this->execute_provider_semantic( 'import', $input, true ); }
	public function provider_export_content( $input ) { return $this->execute_provider_semantic( 'export', $input, false ); }
}
