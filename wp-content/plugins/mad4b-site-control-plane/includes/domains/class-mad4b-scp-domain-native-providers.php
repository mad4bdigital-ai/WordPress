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
		if ( ! class_exists( 'MAD4B_SCP_G4_Provider_Families' ) || ! method_exists( 'MAD4B_SCP_G4_Provider_Families', 'readiness' ) ) {
			return array( 'ready'=>false, 'complete'=>false, 'providers'=>array(), 'reason'=>'g4_provider_readiness_unavailable' );
		}
		$readiness = MAD4B_SCP_G4_Provider_Families::readiness( array() );
		if ( is_wp_error( $readiness ) || ! is_array( $readiness ) || ! is_array( $readiness['providers'] ?? null ) || count( $readiness['providers'] ) > 64 ) {
			return array( 'ready'=>false, 'complete'=>false, 'providers'=>array(), 'reason'=>'g4_provider_readiness_invalid' );
		}

		$path = MAD4B_SCP_DIR . 'config/wordpress-domain-catalog.json';
		if ( ! is_readable( $path ) || filesize( $path ) > 32768 ) return array( 'ready'=>false, 'complete'=>false, 'providers'=>array(), 'reason'=>'domain_overlay_unavailable' );
		$overlay = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( $overlay ) || 'mad4b.wordpress-domain-overlay.v1' !== ( $overlay['contract'] ?? '' ) || false !== ( $overlay['authorizing'] ?? null ) || ! is_array( $overlay['overlays'] ?? null ) || count( $overlay['overlays'] ) > 64 ) {
			return array( 'ready'=>false, 'complete'=>false, 'providers'=>array(), 'reason'=>'domain_overlay_invalid' );
		}

		$extras = array();
		foreach ( $overlay['overlays'] as $record ) {
			$id = is_array( $record ) ? ( $record['provider_id'] ?? '' ) : '';
			$prerequisites = is_array( $record ) ? ( $record['prerequisites'] ?? null ) : null;
			if ( ! MAD4B_SCP_Domain_Contracts::identifier( $id ) || isset( $extras[ $id ] ) || ! is_array( $prerequisites ) || count( $prerequisites ) > 16 ) {
				return array( 'ready'=>false, 'complete'=>false, 'providers'=>array(), 'reason'=>'domain_overlay_provider_invalid' );
			}
			foreach ( $prerequisites as $value ) {
				if ( ! is_string( $value ) || ! MAD4B_SCP_Domain_Contracts::identifier( $value ) ) return array( 'ready'=>false, 'complete'=>false, 'providers'=>array(), 'reason'=>'domain_overlay_prerequisite_invalid' );
			}
			$extras[ $id ] = array_values( $prerequisites );
		}

		$rows = array();
		$known = array();
		foreach ( $readiness['providers'] as $provider ) {
			if ( ! is_array( $provider ) || ! MAD4B_SCP_Domain_Contracts::identifier( $provider['provider_id'] ?? null ) || ! MAD4B_SCP_Domain_Contracts::identifier( $provider['family_id'] ?? null ) ) {
				return array( 'ready'=>false, 'complete'=>false, 'providers'=>array(), 'reason'=>'g4_provider_row_invalid' );
			}
			$id = $provider['provider_id'];
			if ( isset( $known[ $id ] ) ) return array( 'ready'=>false, 'complete'=>false, 'providers'=>array(), 'reason'=>'g4_provider_duplicate' );
			$known[ $id ] = true;
			$observed = array();
			foreach ( (array) ( $provider['observed_plugin_identities'] ?? array() ) as $identity ) {
				if ( ! is_array( $identity ) || count( $observed ) >= 20 ) continue;
				$observed[] = array(
					'plugin_file'=>sanitize_text_field( (string) ( $identity['plugin_file'] ?? '' ) ),
					'slug'=>sanitize_key( (string) ( $identity['slug'] ?? '' ) ),
					'version'=>sanitize_text_field( (string) ( $identity['version'] ?? '' ) ),
					'active'=>! empty( $identity['active'] ) || ! empty( $identity['network_active'] ),
				);
			}
			$rows[] = array(
				'provider_id'=>$id,
				'family'=>$provider['family_id'],
				'observed_plugins'=>$observed,
				'installed'=>! empty( $provider['installed'] ),
				'installed_version'=>sanitize_text_field( (string) ( $provider['installed_version'] ?? '' ) ),
				'certification_state'=>sanitize_key( (string) ( $provider['certification_state'] ?? '' ) ),
				'adapter_registered'=>! empty( $provider['adapter_registered'] ),
				'read_surface_ready'=>! empty( $provider['read_surface_ready'] ),
				'prerequisites'=>$extras[ $id ] ?? array(),
				'form_and_submission_readiness_independent'=>true,
				'plan_contract_inferred'=>false,
				'runtime_certification_inferred'=>false,
				'execution_supported'=>false,
			);
		}
		$orphans = array_values( array_diff( array_keys( $extras ), array_keys( $known ) ) );
		if ( $orphans ) return array( 'ready'=>false, 'complete'=>false, 'providers'=>array(), 'reason'=>'domain_overlay_provider_not_in_g4_catalog', 'orphan_provider_ids'=>$orphans );
		return array(
			'ready'=>true,
			'complete'=>true,
			'complete_scope'=>'g4_reviewed_provider_catalog',
			'provider_absence_certified'=>false,
			'providers'=>$rows,
			'catalog_is_authority'=>false,
			'identity_source'=>'mad4b.g4-provider-readiness.v1',
			'overlay_contract'=>'mad4b.wordpress-domain-overlay.v1',
			'duplicate_plugin_scanner'=>false,
		);
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
		if ( is_wp_error( $result ) || ! is_array( $result ) || is_wp_error( MAD4B_SCP_Domain_Contracts::bounded( $result, true ) ) ) return MAD4B_SCP_Domain_Contracts::error( 'native_read_denied_or_bound' );
		$facts = array();
		switch ( $this->row[3] ) {
			case 'commerce_catalog':
				$product = $result['product'] ?? array();
				if ( ! is_array( $product ) || ( $product['id'] ?? null ) !== $target[ $this->row[5] ] ) return MAD4B_SCP_Domain_Contracts::error( 'native_product_identity' );
				// The existing catalog read does not establish mutation-hook, stock or order-store safety.
				$facts = array( 'runtime_compatible'=>true, 'hpos_compatible'=>false, 'hooks_bounded'=>false, 'objects'=>array(), 'mutation_hook_acceptance_pending'=>true );
				break;
			case 'builder_tree':
				if ( ( $result['post_id'] ?? null ) !== $target[ $this->row[5] ] || ! MAD4B_SCP_Domain_Contracts::sha( $result['sha256'] ?? null ) || ! is_array( $result['elements'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'native_document_identity' );
				$nodes = array(); $types = array();
				$valid = self::outline( $result['elements'], '', $nodes, $types, 0 ); if ( is_wp_error( $valid ) ) return $valid;
				$valid = MAD4B_SCP_Domain_Contracts::hierarchy( $nodes ); if ( is_wp_error( $valid ) ) return $valid;
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
