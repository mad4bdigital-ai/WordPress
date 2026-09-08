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

	public static function runtime_plugins_for_family( $family_id ) {
		$families = self::families();
		$descriptor = isset( $families[ $family_id ] ) && is_array( $families[ $family_id ] ) ? $families[ $family_id ] : array();
		if ( ! $descriptor ) return array();
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		if ( ! is_array( $plugins ) ) $plugins = array();
		$matches = array();
		foreach ( (array) ( $descriptor['artifacts'] ?? array() ) as $artifact ) {
			foreach ( self::runtime_candidates( $artifact ) as $candidate ) {
				foreach ( $plugins as $plugin_file => $headers ) {
					$normalized = strtolower( str_replace( '\\', '/', (string) $plugin_file ) );
					$match = false;
					if ( substr( $candidate, -4 ) === '.php' ) $match = $normalized === $candidate;
					else $match = 0 === strpos( $normalized, rtrim( $candidate, '/' ) . '/' );
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
	public function id() { return $this->family_id; }
	public function label() {
		$labels = array(
			'admin-utilities'=>'Admin Utilities','analytics'=>'Analytics / Tag Manager','astra'=>'Astra Add-ons','content-utilities'=>'Content Utilities',
			'dangerous-code-execution'=>'High-Risk Code / Filesystem Tools','fluentforms'=>'Fluent Forms','hostinger'=>'Hostinger',
			'identity-admin'=>'Identity Administration','jet-ecosystem'=>'Jet Ecosystem','jetformbuilder'=>'JetFormBuilder','reviews'=>'Reviews / TripAdvisor',
			'wp-import-export'=>'WP Import / Export','wpl-client'=>'WPL Client','wpml'=>'WPML','mad4b-platform'=>'MAD4B Platform',
		);
		return isset( $labels[ $this->family_id ] ) ? $labels[ $this->family_id ] : ucwords( str_replace( '-', ' ', $this->family_id ) );
	}
	public function is_available() { return ! empty( MAD4B_SCP_Repository_Artifact_Catalog::runtime_plugins_for_family( $this->family_id ) ); }
	public function ability_names() { return array( 'read'=>array( $this->family_id . '/status' ), 'content'=>array(), 'admin'=>array() ); }
	protected function mutation_requires_certification() { return false; }
	protected function provider_certification( $available ) { return null; }
	protected function detect_plugin_version() {
		$plugins = MAD4B_SCP_Repository_Artifact_Catalog::runtime_plugins_for_family( $this->family_id );
		return ! empty( $plugins[0]['version'] ) ? (string) $plugins[0]['version'] : '';
	}
	public function register_abilities() {
		$this->add_ability( $this->family_id . '/status', 'Read ' . $this->label() . ' Repository Adapter Status', 'family_status', array( 'MAD4B_SCP_Policy', 'can_read' ) );
	}
	public function family_status() { return $this->status(); }
	public function status() {
		$runtime = MAD4B_SCP_Repository_Artifact_Catalog::runtime_plugins_for_family( $this->family_id );
		$active = 0; foreach ( $runtime as $plugin ) if ( ! empty( $plugin['active'] ) || ! empty( $plugin['network_active'] ) ) ++$active;
		return array(
			'id'=>$this->family_id,'label'=>$this->label(),'available'=>!empty($runtime),'version'=>$this->detect_plugin_version(),
			'contract'=>'mad4b.repository-family-read-adapter.v1','authority_mode'=>'read_only_non_authorizing','mutation_master_enabled'=>false,
			'mutation_requires_certification'=>false,'mutation_exposed'=>false,'reversible_contracts'=>array(),
			'support_mode'=>sanitize_key((string)($this->descriptor['support_mode']??'inventory_read')),
			'mutation_scope'=>sanitize_key((string)($this->descriptor['mutation_scope']??'none')),
			'repository_artifacts'=>array_values((array)($this->descriptor['artifacts']??array())),
			'repository_artifact_count'=>count((array)($this->descriptor['artifacts']??array())),
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
	foreach ( MAD4B_SCP_Repository_Artifact_Catalog::families() as $family_id => $descriptor ) {
		$family_id = sanitize_key( (string) $family_id );
		if ( '' === $family_id || $registry->get( $family_id ) ) continue;
		$registry->register( new MAD4B_SCP_Repository_Family_Adapter( $family_id, is_array( $descriptor ) ? $descriptor : array() ) );
	}
}, 30 );
