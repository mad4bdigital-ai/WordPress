<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Code-reviewed adapters read retained evidence; this interface has no outbound executor. */
interface MAD4B_SCP_G5_Stored_Observation_Adapter {
	public function descriptor();
	public function setup_fields();
	public function authorize_read( array $scope );
	public function consent_status( array $scope );
	public function observation_scope( $observation_id );
	public function read_observation( $observation_id, array $authorized_scope );
}

/**
 * G5 composes the existing Search account, secret, budget and breaker authorities.
 * Packs and GET projections cannot register adapters, resolve secrets or issue HTTP.
 */
final class MAD4B_SCP_G5_External_Providers {
	const CONTRACT = 'mad4b.feature007-g5-external-provider.v1';
	const PAGE_SLUG = 'mad4b-growth-providers';
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 32 );
		MAD4B_SCP_Admin_Route_Registry::schedule_submenu( array( __CLASS__, 'menu' ), 32 );
	}

	public static function register_abilities() {
		$definitions = array(
			'mad4b/external-provider-workspace' => array( 'Inspect Growth Provider Workspace', 'inventory', array() ),
			'mad4b/external-provider-setup-preview' => array( 'Preview Provider Setup', 'setup_preview', array(
				'provider_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
				'requested_scopes' => array( 'type' => 'array', 'maxItems' => 32, 'items' => array( 'type' => 'string', 'maxLength' => 191 ) ),
			) ),
		);
		foreach ( $definitions as $name => $row ) {
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
			if ( ! function_exists( 'wp_register_ability' ) ) return;
			wp_register_ability( $name, array(
				'label' => $row[0], 'description' => 'Inspect pinned providers and setup without outbound requests, paid execution or authority changes.',
				'category' => 'mad4b-admin', 'execute_callback' => array( __CLASS__, $row[1] ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'input_schema' => array( 'type' => 'object', 'properties' => $row[2], 'additionalProperties' => false ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array( 'public' => false, 'show_in_rest' => false, 'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'admin' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
			) );
		}
	}

	public static function can_manage( $input = null ) { return current_user_can( 'manage_options' ); }
	public static function error( $code ) { return new WP_Error( 'mad4b_g5_' . $code, 'The requested provider operation is unavailable or requires reviewed setup.' ); }
	public static function sha( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ); }
	public static function id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9._-]{0,63}$/D', $value ); }
	public static function digest( $value ) { return hash( 'sha256', wp_json_encode( self::canonical( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); }
	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) return $value;
		if ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonical( $item );
		return $value;
	}

	/** Registration is a server-code filter, never a descriptor supplied by a client or pack. */
	public static function adapters() {
		$out = array(); $duplicates = array();
		foreach ( (array) apply_filters( 'mad4b_scp_g5_stored_observation_adapters', array() ) as $adapter ) {
			if ( ! $adapter instanceof MAD4B_SCP_G5_Stored_Observation_Adapter ) continue;
			try { $d = $adapter->descriptor(); } catch ( Throwable $e ) { continue; }
			if ( ! is_array( $d ) || ! self::id( $d['provider_id'] ?? null ) ) continue;
			$id = $d['provider_id'];
			if ( isset( $out[ $id ] ) || isset( $duplicates[ $id ] ) ) { unset( $out[ $id ] ); $duplicates[ $id ] = true; continue; }
			$out[ $id ] = $adapter;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/** Exact adapter code pin and current server-owned economics/rights; no caller flags. */
	public static function descriptor_guard( $adapter, array $d ) {
		if ( ! $adapter instanceof MAD4B_SCP_G5_Stored_Observation_Adapter || self::CONTRACT !== ( $d['contract'] ?? '' ) || ! self::id( $d['provider_id'] ?? null ) || 'stored.growth-evidence.v1' !== ( $d['strategy_id'] ?? '' ) ) return self::error( 'protocol_unavailable' );
		foreach ( array( 'adapter_sha256', 'artifact_sha256', 'schema_sha256', 'generation_sha256', 'account_ref', 'tenant_ref' ) as $key ) if ( ! self::sha( $d[ $key ] ?? null ) ) return self::error( 'descriptor_unpinned' );
		try {
			$file = ( new ReflectionClass( $adapter ) )->getFileName();
			if ( ! is_string( $file ) || ! is_file( $file ) || ! hash_equals( $d['adapter_sha256'], hash_file( 'sha256', $file ) ) ) return self::error( 'adapter_code_drift' );
		} catch ( Throwable $e ) { return self::error( 'adapter_code_drift' ); }
		if ( true !== ( $d['certified'] ?? null ) || ! is_int( $d['expires_at'] ?? null ) || $d['expires_at'] <= time() || ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! is_string( $d['site_uuid'] ?? null ) || ! hash_equals( MAD4B_SCP_Site_Profile::site_uuid(), $d['site_uuid'] ) ) return self::error( 'descriptor_stale_or_foreign' );
		foreach ( array( 'required_scopes', 'property_refs', 'capability_ids' ) as $key ) {
			if ( ! is_array( $d[ $key ] ?? null ) || ! $d[ $key ] || count( $d[ $key ] ) > 32 ) return self::error( 'descriptor_scope_invalid' );
			foreach ( $d[ $key ] as $value ) if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 191 || preg_match( '/[\x00-\x1f\x7f*]/', $value ) ) return self::error( 'descriptor_scope_invalid' );
		}
		if ( array( 'retained_aggregate_read' ) !== ( $d['effects'] ?? null ) ) return self::error( 'descriptor_effects_unknown' );
		$e = $d['economics'] ?? null; $r = $d['rights'] ?? null;
		if ( ! is_array( $e ) || ! is_int( $e['quota_units'] ?? null ) || $e['quota_units'] < 0 || ! is_int( $e['max_cost_micro'] ?? null ) || $e['max_cost_micro'] < 0 || ! is_string( $e['currency'] ?? null ) || ! preg_match( '/^[A-Z]{3}$/D', $e['currency'] ) ) return self::error( 'economics_unknown' );
		if ( ! is_array( $r ) || true !== ( $r['aggregate_read_allowed'] ?? null ) || true !== ( $r['normalized_allowed'] ?? null ) || ! is_int( $r['max_retention_seconds'] ?? null ) || $r['max_retention_seconds'] <= 0 || ! is_array( $r['storage_regions'] ?? null ) || ! $r['storage_regions'] || ! is_bool( $r['redistribution_allowed'] ?? null ) ) return self::error( 'rights_unknown_or_denied' );
		// Retained reads must be zero-cost; charge-bearing reads use existing account budget execution.
		if ( 0 !== $e['quota_units'] || 0 !== $e['max_cost_micro'] ) return self::error( 'paid_read_requires_existing_execution' );
		if ( ! is_array( $d['metrics'] ?? null ) || ! $d['metrics'] || count( $d['metrics'] ) > 32 ) return self::error( 'metric_contract_unknown' );
		foreach ( $d['metrics'] as $name => $metric ) if ( ! self::id( $name ) || ! is_array( $metric ) || ! self::id( $metric['semantic_id'] ?? null ) || ! self::id( $metric['unit'] ?? null ) ) return self::error( 'metric_contract_unknown' );
		return true;
	}

	public static function inventory( $input = array() ) {
		if ( ! self::can_manage() ) return self::error( 'access_denied' );
		if ( ! is_array( $input ) || $input ) return self::error( 'input_invalid' );
		$items = array();
		foreach ( self::adapters() as $id => $adapter ) {
			try { $d = $adapter->descriptor(); $guard = self::descriptor_guard( $adapter, $d ); }
			catch ( Throwable $e ) { $items[] = array( 'provider_id' => $id, 'state' => 'adapter_unavailable' ); continue; }
			$items[] = array(
				'provider_id' => $id, 'strategy_id' => 'stored.growth-evidence.v1',
				'state' => is_wp_error( $guard ) ? 'blocked' : 'retained_read_admitted',
				'blockers' => is_wp_error( $guard ) ? array( $guard->get_error_code() ) : array(),
				'capability_ids' => is_wp_error( $guard ) ? array() : $d['capability_ids'],
				'contract_sha256' => is_wp_error( $guard ) ? '' : self::digest( $d ),
				'artifact_sha256' => is_wp_error( $guard ) ? '' : $d['artifact_sha256'],
				'schema_sha256' => is_wp_error( $guard ) ? '' : $d['schema_sha256'],
				'generation_sha256' => is_wp_error( $guard ) ? '' : $d['generation_sha256'],
				'effects' => array( 'retained_aggregate_read' ), 'authorizing' => false,
			);
		}
		$search_mesh = self::search_inventory();
		$reference_profiles = class_exists( 'MAD4B_SCP_G5_Provider_Profiles', false )
			? MAD4B_SCP_G5_Provider_Profiles::coverage( $items, $search_mesh )
			: array();
		return array( 'contract' => self::CONTRACT, 'providers' => $items, 'reference_profiles' => $reference_profiles, 'search_mesh' => $search_mesh, 'registered_adapter_count' => count( $items ), 'configuration_save_performs_network' => false, 'paid_execution_performed' => false, 'secret_values_included' => false, 'secret_handles_included' => false, 'authority_created' => false, 'authorizing' => false );
	}

	/** Reuse Search descriptors and secret status; never call prepare/execute/probe on GET. */
	private static function search_inventory() {
		if ( ! class_exists( 'MAD4B_SCP_Search_Providers' ) ) return array();
		$out = array();
		foreach ( MAD4B_SCP_Search_Providers::adapters() as $id => $adapter ) {
			try {
				$d = $adapter->descriptor(); $guard = MAD4B_SCP_Search_Providers::descriptor_guard( $d, time() );
				$s = class_exists( 'MAD4B_SCP_Search_Provider_Connections' ) ? MAD4B_SCP_Search_Provider_Connections::status( $id ) : null;
				$out[] = array( 'provider_id' => $id, 'state' => is_wp_error( $guard ) ? 'setup_or_certification_required' : 'existing_search_contract_admitted', 'blockers' => is_wp_error( $guard ) ? array( 'existing_search_contract_not_admitted' ) : array(), 'configured' => is_array( $s ) && true === ( $s['configured'] ?? false ), 'connection_state' => is_array( $s ) && in_array( $s['connection_state'] ?? '', array( 'NOT_CONFIGURED', 'NOT_CHECKED', 'CHECK_FAILED', 'VERIFIED', 'CHECK_EXPIRED' ), true ) ? $s['connection_state'] : 'UNAVAILABLE', 'setup_page' => 'mad4b-search-intelligence', 'paid_execution_requires' => array( 'exact_profile', 'existing_account_budget', 'current_provider_generation', 'circuit_breaker', 'egress_policy', 'reconcile_uncertain_charge_before_retry' ), 'authorizing' => false );
			} catch ( Throwable $e ) { $out[] = array( 'provider_id' => $id, 'state' => 'adapter_unavailable', 'authorizing' => false ); }
		}
		return $out;
	}

	public static function setup_preview( $input ) {
		if ( ! self::can_manage() ) return self::error( 'access_denied' );
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'provider_id', 'requested_scopes' ) ) || ! self::id( $input['provider_id'] ?? null ) ) return self::error( 'input_invalid' );
		$adapters = self::adapters(); $id = $input['provider_id'];
		if ( ! isset( $adapters[ $id ] ) ) return self::error( 'adapter_unavailable' );
		$adapter = $adapters[ $id ];
		try { $d = $adapter->descriptor(); $guard = self::descriptor_guard( $adapter, $d ); } catch ( Throwable $e ) { return self::error( 'adapter_unavailable' ); }
		if ( is_wp_error( $guard ) ) return $guard;
		$requested = $input['requested_scopes'] ?? $d['required_scopes'];
		if ( ! is_array( $requested ) || count( $requested ) > 32 ) return self::error( 'input_invalid' );
		foreach ( $requested as $scope ) if ( ! is_string( $scope ) || ! in_array( $scope, $d['required_scopes'], true ) ) return self::error( 'new_scope_requires_external_consent' );
		try { $fields = self::fields( $adapter->setup_fields() ); } catch ( Throwable $e ) { return self::error( 'setup_schema_invalid' ); }
		if ( is_wp_error( $fields ) ) return $fields;
		return array( 'contract' => self::CONTRACT, 'provider_id' => $id, 'fields' => $fields, 'requested_scopes' => array_values( array_unique( $requested ) ), 'available_properties' => $d['property_refs'], 'save_strategy' => 'existing_encrypted_connection_authority_required', 'network_test_strategy' => 'explicit_registered_account_test_required', 'new_scopes_require_external_consent' => true, 'credential_values_included' => false, 'credential_handles_included' => false, 'authorizing' => false );
	}

	private static function fields( $fields ) {
		if ( ! is_array( $fields ) || count( $fields ) > 32 ) return self::error( 'setup_schema_invalid' );
		$out = array();
		foreach ( $fields as $id => $field ) {
			if ( ! self::id( $id ) || ! is_array( $field ) || array_diff( array_keys( $field ), array( 'label', 'kind', 'required', 'max_length' ) ) || ! is_string( $field['label'] ?? null ) || strlen( $field['label'] ) > 191 || ! in_array( $field['kind'] ?? null, array( 'credential', 'account', 'property', 'profile' ), true ) || ! is_bool( $field['required'] ?? null ) || ! is_int( $field['max_length'] ?? null ) || $field['max_length'] < 1 || $field['max_length'] > 4096 ) return self::error( 'setup_schema_invalid' );
			$out[ $id ] = array( 'label' => sanitize_text_field( $field['label'] ), 'kind' => $field['kind'], 'required' => $field['required'], 'max_length' => $field['max_length'], 'value_included' => false );
		}
		return $out;
	}

	/** Immutable, non-executing pack check. Unknown keys/protocols never become endpoint arguments. */
	public static function validate_pack( $pack ) {
		if ( ! self::can_manage() ) return self::error( 'access_denied' );
		if ( ! is_array( $pack ) || array_diff( array_keys( $pack ), array( 'contract', 'provider_id', 'strategy_id', 'descriptor_sha256', 'requested_scopes' ) ) || 'mad4b.g5-provider-configuration-pack.v1' !== ( $pack['contract'] ?? '' ) || ! self::id( $pack['provider_id'] ?? null ) || ! self::sha( $pack['descriptor_sha256'] ?? null ) || 'stored.growth-evidence.v1' !== ( $pack['strategy_id'] ?? null ) ) return self::error( 'configuration_pack_protocol_unavailable' );
		$adapters = self::adapters(); $id = $pack['provider_id'];
		if ( ! isset( $adapters[ $id ] ) ) return self::error( 'adapter_unavailable' );
		try { $d = $adapters[ $id ]->descriptor(); $guard = self::descriptor_guard( $adapters[ $id ], $d ); } catch ( Throwable $e ) { return self::error( 'adapter_unavailable' ); }
		if ( is_wp_error( $guard ) ) return $guard;
		if ( ! hash_equals( self::digest( $d ), $pack['descriptor_sha256'] ) ) return self::error( 'configuration_pack_descriptor_drift' );
		$preview = self::setup_preview( array( 'provider_id' => $id, 'requested_scopes' => $pack['requested_scopes'] ?? array() ) );
		if ( is_wp_error( $preview ) ) return $preview;
		return array( 'contract' => 'mad4b.g5-provider-configuration-pack-preview.v1', 'pack_sha256' => self::digest( $pack ), 'provider_id' => $id, 'compatible' => true, 'activation_performed' => false, 'network_performed' => false, 'signed_registry_review_required' => true, 'external_consent_still_required' => true, 'authorizing' => false );
	}

	public static function menu() { add_submenu_page( 'mad4b-control-plane', 'Growth Providers', 'Growth Providers', 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render' ) ); }
	public static function render() {
		if ( ! self::can_manage() ) return;
		$model = self::inventory();
		if ( is_wp_error( $model ) ) return;
		echo '<div class="wrap"><h1>' . esc_html__( 'Growth Providers', 'mad4b-site-control-plane' ) . '</h1><p>' . esc_html__( 'Review retained analytics and research evidence. New scopes need external consent. Paid captures use a Search Profile and its account budget.', 'mad4b-site-control-plane' ) . '</p>';
		if ( ! $model['providers'] ) echo '<p>' . esc_html__( 'No reviewed analytics or research adapter is registered. Register and certify a provider adapter before requesting reports.', 'mad4b-site-control-plane' ) . '</p>';
		foreach ( $model['providers'] as $provider ) echo '<p><strong>' . esc_html( $provider['provider_id'] ) . '</strong>: ' . esc_html( $provider['state'] ) . '</p>';
		if ( $model['search_mesh'] ) {
			echo '<h2>' . esc_html__( 'Search accounts', 'mad4b-site-control-plane' ) . '</h2><ul>';
			foreach ( $model['search_mesh'] as $provider ) echo '<li>' . esc_html( $provider['provider_id'] . ': ' . $provider['state'] ) . '</li>';
			echo '</ul><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=mad4b-search-intelligence&section=providers' ) ) . '">' . esc_html__( 'Configure Search accounts', 'mad4b-site-control-plane' ) . '</a></p>';
		}
		echo '</div>';
	}
}

// Declare the admin route without bypassing shared parent ordering or permissions.
if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry', false ) ) MAD4B_SCP_Admin_Route_Registry::register( MAD4B_SCP_G5_External_Providers::PAGE_SLUG, 'manage_options' );
