<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Compiled bridges reuse exact canonical read abilities; catalog rows cannot choose PHP symbols. */
final class MAD4B_SCP_Domain_Native_Providers {
	public static function register() {
		foreach ( array( array('woocommerce','catalog','commerce','commerce_catalog','woocommerce/get-product','product_id'), array('elementor','document','builders','builder_tree','elementor/get-document','post_id'), array('jetformbuilder','definition-metadata','forms','form_config','jetformbuilder/get-form','id'), array('fluentforms','definition-metadata','forms','form_config','fluentforms/get-form','id') ) as $row ) {
			MAD4B_SCP_WordPress_Domain_Coverage::register( new MAD4B_SCP_Domain_Native_Read_Provider( $row ) );
		}
	}

	public static function discovery() {
		$path = MAD4B_SCP_DIR . 'config/wordpress-domain-catalog.json';
		if ( ! is_readable( $path ) || filesize( $path ) > 32768 || ! function_exists( 'get_plugins' ) ) return array( 'ready'=>false, 'complete'=>false, 'providers'=>array() );
		$catalog = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( $catalog ) || 'mad4b.wordpress-domain-discovery-catalog.v1' !== ( $catalog['contract'] ?? '' ) || false !== ( $catalog['authorizing'] ?? null ) || ! is_array( $catalog['providers'] ?? null ) || count( $catalog['providers'] ) > 64 ) return array( 'ready'=>false, 'complete'=>false, 'providers'=>array() );
		$plugins = get_plugins();
		if ( ! is_array( $plugins ) || count( $plugins ) > 512 ) return array( 'ready'=>false, 'complete'=>false, 'providers'=>array() );
		$rows = array();
		foreach ( $catalog['providers'] as $record ) {
			if ( ! is_array( $record ) || ! MAD4B_SCP_Domain_Contracts::identifier( $record['provider_id'] ?? null ) || ! is_array( $record['plugin_files'] ?? null ) || count( $record['plugin_files'] ) > 8 ) return array( 'ready'=>false, 'complete'=>false, 'providers'=>array() );
			$observed = array();
			foreach ( $record['plugin_files'] as $file ) {
				if ( ! is_string( $file ) || is_wp_error( MAD4B_SCP_Domain_Site_Operations::path( $file, false ) ) ) return array( 'ready'=>false, 'complete'=>false, 'providers'=>array() );
				if ( ! isset( $plugins[ $file ] ) ) continue;
				$observed[] = array( 'plugin_file'=>$file, 'version'=>sanitize_text_field( (string) ( $plugins[ $file ]['Version'] ?? '' ) ), 'active'=>( function_exists( 'is_plugin_active' ) && is_plugin_active( $file ) ) || ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $file ) ) );
			}
			$rows[] = array( 'provider_id'=>$record['provider_id'], 'family'=>$record['family'] ?? '', 'observed_plugins'=>$observed, 'installed'=>(bool) $observed, 'prerequisites'=>$record['prerequisites'] ?? array(), 'form_and_submission_readiness_independent'=>true, 'plan_contract_inferred'=>false, 'runtime_certification_inferred'=>false, 'execution_supported'=>false );
		}
		return array( 'ready'=>true, 'complete'=>true, 'complete_scope'=>'declared_plugin_files_only', 'provider_absence_certified'=>false, 'providers'=>$rows, 'catalog_is_authority'=>false );
	}

	public static function native_objects() {
		if ( ! class_exists( 'MAD4B_SCP_Runtime_Evidence_Collectors' ) ) return array( 'ready'=>false, 'complete'=>false );
		$types = MAD4B_SCP_Runtime_Evidence_Collectors::post_types( 64 );
		$taxonomies = MAD4B_SCP_Runtime_Evidence_Collectors::taxonomies( 64 );
		return array( 'ready'=>true, 'post_types'=>$types, 'taxonomies'=>$taxonomies, 'post_type_status'=>MAD4B_SCP_Runtime_Evidence_Collectors::collection_status( 'post_types', count( $types ), MAD4B_SCP_Runtime_Evidence_Collectors::observed_count( 'post_types', $types, array() ), 64 ), 'taxonomy_status'=>MAD4B_SCP_Runtime_Evidence_Collectors::collection_status( 'taxonomies', count( $taxonomies ), MAD4B_SCP_Runtime_Evidence_Collectors::observed_count( 'taxonomies', $taxonomies, array() ), 64 ), 'object_kinds'=>array( 'posts','pages','registered_cpt','terms','users','comments','media','menus','revisions','registered_meta' ), 'object_values_exposed'=>false, 'uncovered_objects_remain_unadmitted'=>true );
	}
}

final class MAD4B_SCP_Domain_Native_Read_Provider extends MAD4B_SCP_Domain_Provider {
	private $row;
	public function __construct( array $row ) { $this->row = $row; }
	public function id() { return $this->row[0]; }
	public function capabilities() {
		return array( $this->row[1]=>array( 'family'=>$this->row[2], 'profile'=>$this->row[3], 'read_ability'=>$this->row[4], 'target_schema'=>array( 'type'=>'object', 'additionalProperties'=>false, 'properties'=>array( $this->row[5]=>array( 'type'=>'integer', 'minimum'=>1 ) ), 'required'=>array( $this->row[5] ) ) ) );
	}
	private function adapter() { return class_exists( 'MAD4B_SCP_Adapter_Registry' ) ? MAD4B_SCP_Adapter_Registry::instance()->get( $this->id() ) : null; }
	public function runtime() {
		$adapter = $this->adapter(); $status = is_object( $adapter ) ? $adapter->status() : array();
		$certified = class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) ? MAD4B_SCP_Provider_Compatibility_Certification::ability_status( $this->id(), $this->row[4], $adapter ) : array();
		return array( 'active'=>is_object( $adapter ) && $adapter->is_available(), 'version'=>(string) ( $status['installed_version'] ?? $status['version'] ?? $status['plugin_version'] ?? '' ), 'artifact_sha256'=>(string) ( $certified['artifact']['runtime_artifact_fingerprint'] ?? '' ), 'read_eligible'=>true === ( $certified['read_eligible'] ?? null ), 'read_risk'=>(string) ( $certified['risk'] ?? '' ) );
	}
	public function authorize( $capability, array $target, array $desired ) {
		if ( $this->row[1] !== $capability || ! MAD4B_SCP_WordPress_Domain_Coverage::can_manage() ) return false;
		$runtime = $this->runtime();
		if ( true !== $runtime['active'] || true !== $runtime['read_eligible'] || 'read' !== $runtime['read_risk'] ) return false;
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $this->row[4] ) : null;
		return is_object( $ability ) && method_exists( $ability, 'check_permissions' ) && true === $ability->check_permissions( $target );
	}
	public function inspect( $capability, array $target ) {
		if ( ! class_exists( 'MAD4B_SCP_Execution_Fence' ) || ! method_exists( 'MAD4B_SCP_Execution_Fence', 'with_governed_child' ) ) return MAD4B_SCP_Domain_Contracts::error( 'native_fence_unavailable' );
		$ability = wp_get_ability( $this->row[4] );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'execute' ) || ! method_exists( $ability, 'get_meta' ) ) return MAD4B_SCP_Domain_Contracts::error( 'native_read_unavailable' );
		$meta = $ability->get_meta();
		if ( true !== ( $meta['annotations']['readonly'] ?? null ) || true === ( $meta['annotations']['destructive'] ?? false ) ) return MAD4B_SCP_Domain_Contracts::error( 'native_read_effect' );
		$result = MAD4B_SCP_Execution_Fence::with_governed_child( $this->row[4], $target, static function () use ( $ability, $target ) { return $ability->execute( $target ); }, 'wordpress_domain_read' );
		if ( is_wp_error( $result ) || ! is_array( $result ) || is_wp_error( MAD4B_SCP_Domain_Contracts::bounded( $result ) ) ) return MAD4B_SCP_Domain_Contracts::error( 'native_read_denied_or_bound' );
		$facts = array();
		switch ( $this->row[3] ) {
			case 'commerce_catalog':
				$product = $result['product'] ?? array();
				if ( ! is_array( $product ) || ( $product['id'] ?? null ) !== $target[ $this->row[5] ] ) return MAD4B_SCP_Domain_Contracts::error( 'native_product_identity' );
				// The existing catalog read does not establish mutation-hook, stock or order-store safety.
				$facts = array( 'runtime_compatible'=>true, 'hpos_compatible'=>false, 'hooks_bounded'=>false, 'objects'=>array(), 'mutation_hook_acceptance_pending'=>true );
				break;
			case 'builder_tree':
				if ( ( $result['post_id'] ?? null ) !== $target[ $this->row[5] ] || ! is_array( $result['elements'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'native_document_identity' );
				$nodes = array(); $types = array();
				$valid = self::outline( $result['elements'], '', $nodes, $types, 0 ); if ( is_wp_error( $valid ) ) return $valid;
				$locked = ! function_exists( 'wp_check_post_lock' ) || (bool) wp_check_post_lock( $target[ $this->row[5] ] );
				$facts = array( 'native_format'=>'elementor_tree', 'serialization_contract'=>true, 'revision_current'=>true, 'editor_locked'=>$locked, 'template_scope_authorized'=>current_user_can( 'edit_post', $target[ $this->row[5] ] ), 'allowed_node_types'=>array_keys( $types ), 'current_ids'=>array_column( $nodes, 'id' ), 'allocated_clone_ids'=>array(), 'control_contracts'=>array() );
				$result = array( 'post_id'=>$result['post_id'], 'native_sha256'=>$result['sha256'] ?? '', 'outline'=>$nodes );
				break;
			case 'form_config':
				$form = $result['form'] ?? array();
				if ( ! is_array( $form ) || ( $form['id'] ?? null ) !== $target[ $this->row[5] ] ) return MAD4B_SCP_Domain_Contracts::error( 'native_form_identity' );
				$facts = array( 'definition_available'=>false, 'serialization_contract'=>false, 'entries_available'=>false, 'definition_metadata_only'=>true );
				break;
		}
		return array( 'state_sha256'=>MAD4B_SCP_Domain_Contracts::digest( $result ), 'target_sha256'=>MAD4B_SCP_Domain_Contracts::digest( $target ), 'facts'=>$facts, 'projection'=>$result );
	}
	private static function outline( array $elements, $parent, array &$nodes, array &$types, $depth ) {
		if ( $depth > 8 || ! MAD4B_SCP_Domain_Contracts::is_list( $elements ) ) return MAD4B_SCP_Domain_Contracts::error( 'native_tree_bound' );
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || count( $nodes ) >= 256 || ! MAD4B_SCP_Domain_Contracts::identifier( $element['id'] ?? null ) || ! is_string( $element['elType'] ?? null ) || ! is_array( $element['elements'] ?? array() ) ) return MAD4B_SCP_Domain_Contracts::error( 'native_tree_node' );
			$type = is_string( $element['widgetType'] ?? null ) ? $element['widgetType'] : $element['elType'];
			$nodes[] = array( 'id'=>$element['id'], 'parent'=>$parent, 'type'=>$type, 'settings'=>array() ); $types[ $type ] = true;
			$check = self::outline( $element['elements'] ?? array(), $element['id'], $nodes, $types, $depth + 1 ); if ( is_wp_error( $check ) ) return $check;
		}
		return true;
	}
	public function matches( $capability, array $desired, array $context ) {
		return 'builder_tree' === $this->row[3] && 'outline' === ( $desired['mode'] ?? '' ) && MAD4B_SCP_Domain_Contracts::digest( $desired['nodes'] ?? array() ) === MAD4B_SCP_Domain_Contracts::digest( $context['projection']['outline'] ?? array() );
	}
}
