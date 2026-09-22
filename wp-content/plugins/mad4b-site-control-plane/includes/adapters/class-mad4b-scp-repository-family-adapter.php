<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Repository_Artifact_Catalog {
	const CONTRACT = 'mad4b.repository-plugin-artifacts.v1';
	private static $data = null;

	public static function all() {
		if ( null !== self::$data ) return self::$data;
		$path = MAD4B_SCP_DIR . 'config/repository-plugin-artifacts.json';
		if ( ! is_readable( $path ) ) return self::$data = array();
		$raw = file_get_contents( $path );
		$data = false === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $data ) || self::CONTRACT !== (string) ( $data['contract'] ?? '' ) || empty( $data['families'] ) || ! is_array( $data['families'] ) ) return self::$data = array();
		return self::$data = $data;
	}

	public static function families() {
		$data = self::all();
		return isset( $data['families'] ) && is_array( $data['families'] ) ? $data['families'] : array();
	}

	public static function artifact_count() {
		$count = 0;
		foreach ( self::families() as $descriptor ) $count += count( isset( $descriptor['artifacts'] ) && is_array( $descriptor['artifacts'] ) ? $descriptor['artifacts'] : array() );
		return $count;
	}

	public static function find_artifact( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) return array();
		foreach ( self::families() as $family_id => $descriptor ) {
			foreach ( (array) ( $descriptor['artifacts'] ?? array() ) as $artifact ) {
				$artifact = (string) $artifact;
				$id = preg_replace( '/\.(zip|php)$/i', '', $artifact );
				if ( $value === strtolower( $artifact ) || $value === strtolower( $id ) ) return self::artifact_record( $family_id, $descriptor, $artifact );
			}
		}
		return array();
	}

	public static function artifact_record( $family_id, array $descriptor, $artifact ) {
		return array(
			'artifact' => (string) $artifact,
			'artifact_id' => preg_replace( '/\.(zip|php)$/i', '', (string) $artifact ),
			'adapter_id' => sanitize_key( (string) $family_id ),
			'support_mode' => sanitize_key( (string) ( $descriptor['support_mode'] ?? 'inventory_read' ) ),
			'mutation_scope' => sanitize_key( (string) ( $descriptor['mutation_scope'] ?? 'none' ) ),
			'authorizing' => false,
		);
	}

	public static function runtime_plugins_for_family( $family_id, array $runtime_match = array() ) {
		$families = self::families();
		$descriptor = isset( $families[ $family_id ] ) && is_array( $families[ $family_id ] ) ? $families[ $family_id ] : array();
		if ( ! $descriptor && empty( $runtime_match ) ) return array();
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		if ( ! is_array( $plugins ) ) $plugins = array();
		$matches = array();
		foreach ( (array) ( $descriptor['artifacts'] ?? array() ) as $artifact ) {
			foreach ( self::runtime_candidates( $artifact ) as $candidate ) {
				foreach ( $plugins as $plugin_file => $headers ) {
					$normalized = strtolower( str_replace( '\\', '/', (string) $plugin_file ) );
					$match = false;
					if ( substr( $candidate, -4 ) === '.php' ) {
						$match = $normalized === $candidate;
					} else {
						$base = rtrim( $candidate, '/' );
						$match = 0 === strpos( $normalized, $base . '/' );
						if ( ! $match && ! preg_match( '/-v\\d+(?:\\.\\d+)*$/', $base ) ) {
							$match = 1 === preg_match( '/^' . preg_quote( $base, '/' ) . '-v\\d+(?:\\.\\d+)*\\//', $normalized );
						}
					}
					if ( ! $match ) continue;
					$matches[ $plugin_file ] = array(
						'plugin_file' => (string) $plugin_file,
						'name' => sanitize_text_field( (string) ( $headers['Name'] ?? '' ) ),
						'version' => sanitize_text_field( (string) ( $headers['Version'] ?? '' ) ),
						'active' => function_exists( 'is_plugin_active' ) ? is_plugin_active( $plugin_file ) : false,
						'network_active' => function_exists( 'is_plugin_active_for_network' ) ? is_plugin_active_for_network( $plugin_file ) : false,
						'repository_artifact' => (string) $artifact,
					);
				}
			}
		}
		foreach ( self::runtime_plugins_for_matches( $runtime_match ) as $plugin ) {
			if ( empty( $plugin['plugin_file'] ) ) continue;
			$file = (string) $plugin['plugin_file'];
			if ( isset( $matches[ $file ] ) ) {
				$matches[ $file ]['runtime_match'] = isset( $plugin['runtime_match'] ) ? $plugin['runtime_match'] : '';
				continue;
			}
			$matches[ $file ] = $plugin;
		}
		ksort( $matches, SORT_STRING );
		return array_values( $matches );
	}

	public static function runtime_plugins_for_matches( array $patterns ) {
		if ( empty( $patterns ) ) return array();
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		if ( ! is_array( $plugins ) ) $plugins = array();
		$matches = array();
		foreach ( $plugins as $plugin_file => $headers ) {
			$normalized = strtolower( ltrim( str_replace( '\\', '/', (string) $plugin_file ), '/' ) );
			foreach ( $patterns as $pattern ) {
				$pattern = strtolower( ltrim( str_replace( '\\', '/', sanitize_text_field( (string) $pattern ) ), '/' ) );
				if ( '' === $pattern || 0 !== strpos( $normalized, $pattern ) ) continue;
				$matches[ $plugin_file ] = array(
					'plugin_file' => (string) $plugin_file,
					'name' => sanitize_text_field( (string) ( $headers['Name'] ?? '' ) ),
					'version' => sanitize_text_field( (string) ( $headers['Version'] ?? '' ) ),
					'active' => function_exists( 'is_plugin_active' ) ? is_plugin_active( $plugin_file ) : false,
					'network_active' => function_exists( 'is_plugin_active_for_network' ) ? is_plugin_active_for_network( $plugin_file ) : false,
					'repository_artifact' => '',
					'runtime_match' => $pattern,
				);
				break;
			}
		}
		ksort( $matches, SORT_STRING );
		return array_values( $matches );
	}

	private static function runtime_candidates( $artifact ) {
		$artifact = strtolower( basename( (string) $artifact ) );
		if ( 'hello.php' === $artifact ) return array( 'hello.php' );
		$stem = preg_replace( '/\.zip$/', '', $artifact );
		$candidates = array( $stem );
		$candidates[] = preg_replace( '/-(?:master|main)$/', '', $stem );
		$candidates[] = preg_replace( '/-v\d+(?:\.\d+)*$/', '', $stem );
		$candidates[] = preg_replace( '/-\d{3,}$/', '', $stem );
		$aliases = array(
			'otgs-installer-plugin' => array( 'installer' ),
			'wp-all-export-csv-excel-xml-for-acf' => array( 'wp-all-export-acf-add-on' ),
			'wpae-acf-add-on' => array( 'wpae-acf-add-on' ),
			'wpai-woocommerce-add-on' => array( 'wpai-woocommerce-add-on' ),
		);
		if ( isset( $aliases[ $stem ] ) ) $candidates = array_merge( $candidates, $aliases[ $stem ] );
		return array_values( array_unique( array_filter( $candidates ) ) );
	}
}

final class MAD4B_SCP_Repository_Family_Adapter extends MAD4B_SCP_Adapter_Base {
	private $family_id;
	private $descriptor;

	public function __construct( $family_id, array $descriptor ) {
		$this->family_id = sanitize_key( (string) $family_id );
		$this->descriptor = $descriptor;
	}
	private function runtime_plugins() {
		$runtime_match = isset( $this->descriptor['runtime_match'] ) && is_array( $this->descriptor['runtime_match'] ) ? $this->descriptor['runtime_match'] : array();
		return MAD4B_SCP_Repository_Artifact_Catalog::runtime_plugins_for_family( $this->family_id, $runtime_match );
	}
	public function id() { return $this->family_id; }
	public function label() {
		$labels = array(
			'admin-utilities'=>'Admin Utilities','google-tag-manager'=>'Google Tag Manager','bulk-taxonomy-editor'=>'Bulk Taxonomy Editor','custom-mega-menu'=>'Custom Mega Menu','meta-catalog-feed-mapper'=>'Meta Catalog Feed Mapper','astra'=>'Astra Add-ons',
			'dangerous-code-execution'=>'High-Risk Code / Filesystem Tools','fluentforms'=>'Fluent Forms','hostinger'=>'Hostinger',
			'identity-admin'=>'Identity Administration','jet-ecosystem'=>'Jet Ecosystem','jetformbuilder'=>'JetFormBuilder','reviews'=>'Reviews / TripAdvisor',
			'wp-import-export'=>'WP Import / Export','wpl-client'=>'WPL Client','wpml'=>'WPML','mad4b-platform'=>'MAD4B Platform',
		);
		return isset( $labels[ $this->family_id ] ) ? $labels[ $this->family_id ] : ucwords( str_replace( '-', ' ', $this->family_id ) );
	}
	public function is_available() { return ! empty( $this->runtime_plugins() ); }
	public function ability_names() { return array( 'read'=>array( $this->family_id . '/status' ), 'content'=>array(), 'admin'=>array() ); }
	protected function mutation_requires_certification() { return false; }
	protected function provider_certification( $available ) { return null; }
	protected function detect_plugin_version() {
		$plugins = $this->runtime_plugins();
		return ! empty( $plugins[0]['version'] ) ? (string) $plugins[0]['version'] : '';
	}
	public function register_abilities() {
		$this->add_ability( $this->family_id . '/status', 'Read ' . $this->label() . ' Repository Adapter Status', 'family_status', array( 'MAD4B_SCP_Policy', 'can_read' ) );
	}
	public function family_status() { return $this->status(); }
	public function status() {
		$runtime = $this->runtime_plugins();
		$active = 0; foreach ( $runtime as $plugin ) if ( ! empty( $plugin['active'] ) || ! empty( $plugin['network_active'] ) ) ++$active;
		return array(
			'id'=>$this->family_id,'label'=>$this->label(),'available'=>!empty($runtime),'version'=>$this->detect_plugin_version(),'abilities'=>$this->ability_names(),
			'contract'=>!empty($this->descriptor['artifacts'])?'mad4b.repository-family-read-adapter.v1':'mad4b.runtime-family-read-adapter.v1','authority_mode'=>'read_only_non_authorizing','mutation_master_enabled'=>false,
			'mutation_requires_certification'=>false,'mutation_exposed'=>false,'reversible_contracts'=>array(),
			'support_mode'=>sanitize_key((string)($this->descriptor['support_mode']??'inventory_read')),
			'mutation_scope'=>sanitize_key((string)($this->descriptor['mutation_scope']??'none')),
			'repository_artifacts'=>array_values((array)($this->descriptor['artifacts']??array())),
			'repository_artifact_count'=>count((array)($this->descriptor['artifacts']??array())),
			'runtime_match'=>array_values((array)($this->descriptor['runtime_match']??array())),
			'runtime_source'=>!empty($this->descriptor['artifacts'])?'repository_artifact_plus_runtime_match':'runtime_match_only',
			'runtime_plugins'=>$runtime,'runtime_plugin_count'=>count($runtime),'active_runtime_plugin_count'=>$active,
		);
	}
}

final class MAD4B_SCP_Repository_Plugins_Adapter extends MAD4B_SCP_Adapter_Base {
	public function id() { return 'repository-plugins'; }
	public function label() { return 'Repository Plugin Coverage'; }
	public function is_available() { return ! empty( MAD4B_SCP_Repository_Artifact_Catalog::all() ); }
	public function ability_names() { return array( 'read'=>array( 'repository-plugins/inventory', 'repository-plugins/get-artifact' ), 'content'=>array(), 'admin'=>array() ); }
	protected function mutation_requires_certification() { return false; }
	protected function provider_certification( $available ) { return null; }
	public function status() {
		return array(
			'id'=>$this->id(),'label'=>$this->label(),'available'=>$this->is_available(),'version'=>'','abilities'=>$this->ability_names(),
			'contract'=>'mad4b.repository-plugin-coverage-adapter.v1','authority_mode'=>'read_only_non_authorizing','mutation_master_enabled'=>false,
			'mutation_requires_certification'=>false,'mutation_exposed'=>false,'reversible_contracts'=>array(),
			'artifact_count'=>MAD4B_SCP_Repository_Artifact_Catalog::artifact_count(),'family_count'=>count(MAD4B_SCP_Repository_Artifact_Catalog::families()),
		);
	}
	public function register_abilities() {
		$read = array( 'MAD4B_SCP_Policy', 'can_read' );
		$this->add_ability( 'repository-plugins/inventory', 'Read Repository Plugin Adapter Inventory', 'inventory', $read );
		$this->add_ability( 'repository-plugins/get-artifact', 'Read Repository Plugin Artifact Adapter Mapping', 'get_artifact', $read, $this->schema( array( 'artifact_id'=>array( 'type'=>'string','minLength'=>1,'maxLength'=>160 ) ), array( 'artifact_id' ) ) );
	}
	public function inventory() {
		$families = array(); $covered = 0;
		$registry = class_exists( 'MAD4B_SCP_Adapter_Registry' ) ? MAD4B_SCP_Adapter_Registry::instance() : null;
		foreach ( MAD4B_SCP_Repository_Artifact_Catalog::families() as $id => $descriptor ) {
			$count = count( (array) ( $descriptor['artifacts'] ?? array() ) ); $covered += $count;
			$families[] = array(
				'adapter_id'=>sanitize_key((string)$id),'adapter_registered'=>$registry ? (bool)$registry->get(sanitize_key((string)$id)) : false,
				'support_mode'=>sanitize_key((string)($descriptor['support_mode']??'')),'mutation_scope'=>sanitize_key((string)($descriptor['mutation_scope']??'none')),
				'artifact_count'=>$count,'artifacts'=>array_values((array)($descriptor['artifacts']??array())),'authorizing'=>false,
			);
		}
		return array('contract'=>MAD4B_SCP_Repository_Artifact_Catalog::CONTRACT,'authorizing'=>false,'read_only'=>true,'runtime_write_default'=>'deny','artifact_count'=>$covered,'family_count'=>count($families),'families'=>$families);
	}
	public function get_artifact( $input ) {
		$input = is_array( $input ) ? $input : array(); $id = isset($input['artifact_id']) ? (string)$input['artifact_id'] : '';
		$record = MAD4B_SCP_Repository_Artifact_Catalog::find_artifact( $id );
		if ( ! $record ) return new WP_Error( 'mad4b_repository_artifact_unknown', 'Repository plugin artifact is not registered in the governed manifest.' );
		$registry = class_exists( 'MAD4B_SCP_Adapter_Registry' ) ? MAD4B_SCP_Adapter_Registry::instance() : null;
		$adapter = $registry ? $registry->get( $record['adapter_id'] ) : null;
		$record['adapter_registered'] = is_object( $adapter );
		$record['adapter_status'] = is_object( $adapter ) && method_exists( $adapter, 'status' ) ? $adapter->status() : array();
		return $record;
	}
}

add_action( 'mad4b_scp_register_adapters', static function ( $registry ) {
	if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) || ! method_exists( $registry, 'get' ) ) return;
	if ( ! $registry->get( 'repository-plugins' ) ) $registry->register( new MAD4B_SCP_Repository_Plugins_Adapter() );

	$support_catalog = class_exists( 'MAD4B_SCP_Plugin_Discovery' ) ? MAD4B_SCP_Plugin_Discovery::catalog() : array();
	$support_rows = isset( $support_catalog['families'] ) && is_array( $support_catalog['families'] ) ? $support_catalog['families'] : array();
	$support_by_id = array();
	foreach ( $support_rows as $row ) {
		if ( ! is_array( $row ) || empty( $row['id'] ) ) continue;
		$support_by_id[ sanitize_key( (string) $row['id'] ) ] = $row;
	}

	foreach ( MAD4B_SCP_Repository_Artifact_Catalog::families() as $family_id => $descriptor ) {
		$family_id = sanitize_key( (string) $family_id );
		if ( '' === $family_id || $registry->get( $family_id ) ) continue;
		$descriptor = is_array( $descriptor ) ? $descriptor : array();
		if ( isset( $support_by_id[ $family_id ]['match'] ) && is_array( $support_by_id[ $family_id ]['match'] ) ) {
			$descriptor['runtime_match'] = array_values( $support_by_id[ $family_id ]['match'] );
		}
		$registry->register( new MAD4B_SCP_Repository_Family_Adapter( $family_id, $descriptor ) );
	}

	// Runtime-only providers must not require a fake repository artifact merely to
	// receive a read-only contract-discovery adapter. Only explicitly cataloged,
	// self-named, non-mutating families are eligible for this generic surface.
	foreach ( $support_rows as $row ) {
		if ( ! is_array( $row ) ) continue;
		$family_id = sanitize_key( (string) ( $row['id'] ?? '' ) );
		$adapter_id = sanitize_key( (string) ( $row['adapter_id'] ?? '' ) );
		$mode = sanitize_key( (string) ( $row['functional_mode'] ?? '' ) );
		$strategy = sanitize_key( (string) ( $row['strategy'] ?? '' ) );
		$mutation_scope = sanitize_key( (string) ( $row['mutation_scope'] ?? '' ) );
		if ( '' === $family_id || $family_id !== $adapter_id || $registry->get( $adapter_id ) ) continue;
		if ( 'registered_adapter' !== $strategy || 'none_read_only' !== $mutation_scope ) continue;
		if ( ! in_array( $mode, array( 'contract_discovery', 'intentionally_restricted' ), true ) ) continue;
		$runtime_match = isset( $row['match'] ) && is_array( $row['match'] ) ? array_values( array_filter( array_map( 'strval', $row['match'] ) ) ) : array();
		if ( empty( $runtime_match ) ) continue;
		$registry->register( new MAD4B_SCP_Repository_Family_Adapter( $family_id, array(
			'support_mode' => 'runtime_family_read',
			'mutation_scope' => 'none',
			'artifacts' => array(),
			'runtime_match' => $runtime_match,
		) ) );
	}
}, 30 );
