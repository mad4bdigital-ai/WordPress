<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * G5 SEO provider-family preflight.
 *
 * This surface reports exact provider applicability and the gates required by a
 * future native apply. It never writes SEO fields, renders provider callbacks,
 * purges caches or treats an SEO signal as content authority.
 */
final class MAD4B_SCP_G5_SEO_Provider_Families {
	const CONTRACT = 'mad4b.feature007-g5-seo-provider-family.v1';
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 33 );
	}

	public static function register_abilities() {
		$definitions = array(
			'mad4b/seo-provider-family-readiness' => array( 'Inspect SEO Provider Family', 'readiness', array(
				'provider_id' => array( 'type' => 'string', 'maxLength' => 64 ),
			) ),
			'mad4b/seo-provider-plan' => array( 'Preview SEO Provider Plan', 'plan', array(
				'provider_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
				'surface_kind' => array( 'type' => 'string', 'enum' => array( 'post', 'term', 'archive' ) ),
				'field_ids' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 8, 'items' => array( 'type' => 'string', 'maxLength' => 64 ) ),
				'language' => array( 'type' => 'string', 'maxLength' => 32 ),
				'rendered_surface_ref' => array( 'type' => 'string', 'maxLength' => 512 ),
			) ),
		);
		foreach ( $definitions as $name => $row ) {
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
			if ( ! function_exists( 'wp_register_ability' ) ) return;
			wp_register_ability( $name, array(
				'label' => $row[0],
				'description' => 'Inspect reviewed SEO provider applicability and readback gates without executing provider mutation.',
				'category' => 'mad4b-admin',
				'execute_callback' => array( __CLASS__, $row[1] ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'input_schema' => array( 'type' => 'object', 'properties' => $row[2], 'additionalProperties' => false ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'admin' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			) );
		}
	}

	public static function can_manage( $input = null ) { return current_user_can( 'manage_options' ); }

	public static function catalog() {
		return array(
			'contract' => self::CONTRACT,
			'providers' => array(
				'rank-math' => array( 'label' => 'Rank Math', 'existing_adapter' => true, 'native_write_foundation' => true ),
				'yoast' => array( 'label' => 'Yoast SEO', 'existing_adapter' => true, 'native_write_foundation' => false ),
				'aioseo' => array( 'label' => 'All in One SEO', 'existing_adapter' => false, 'native_write_foundation' => false ),
				'seopress' => array( 'label' => 'SEOPress', 'existing_adapter' => true, 'native_write_foundation' => false ),
				'slim-seo' => array( 'label' => 'Slim SEO', 'existing_adapter' => false, 'native_write_foundation' => false ),
				'the-seo-framework' => array( 'label' => 'The SEO Framework', 'existing_adapter' => false, 'native_write_foundation' => false ),
			),
			'field_ids' => array( 'title', 'description', 'focus_keyword', 'schema', 'social', 'canonical', 'robots' ),
			'surface_kinds' => array( 'post', 'term', 'archive' ),
			'acceptance_dimensions' => array( 'provider', 'object_or_archive', 'language', 'stored_generation', 'rendered_generation', 'cache_generation' ),
			'authorizing' => false,
			'production_authorized' => false,
			'signal_direct_mutation_allowed' => false,
			'generic_post_meta_parity_claimed' => false,
		);
	}

	public static function readiness( $input = array() ) {
		if ( ! self::can_manage() ) return self::error( 'access_denied' );
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'provider_id' ) ) ) return self::error( 'input_invalid' );
		$catalog = self::catalog();
		$requested = isset( $input['provider_id'] ) ? sanitize_key( $input['provider_id'] ) : '';
		if ( '' !== $requested && ! isset( $catalog['providers'][ $requested ] ) ) return self::error( 'provider_unknown' );
		$rows = array();
		foreach ( $catalog['providers'] as $id => $definition ) {
			if ( '' !== $requested && $requested !== $id ) continue;
			$identity = self::runtime_identity( $id );
			$rows[] = array(
				'provider_id' => $id,
				'label' => $definition['label'],
				'installed' => $identity['installed'],
				'version' => $identity['version'],
				'existing_adapter' => $definition['existing_adapter'],
				'native_write_foundation' => $definition['native_write_foundation'],
				'readiness_state' => $identity['installed'] ? 'installed_preflight_only' : 'provider_absent',
				'provider_native_apply_certified' => false,
				'rendered_acceptance_certified' => false,
				'coexistence_certified' => false,
				'execution_admitted' => false,
				'authorizing' => false,
			);
		}
		return array(
			'contract' => self::CONTRACT,
			'providers' => $rows,
			'provider_count' => count( $rows ),
			'authorizing' => false,
			'production_authorized' => false,
			'live_provider_acceptance' => false,
			'live_browser_acceptance' => false,
		);
	}

	public static function plan( $input ) {
		if ( ! self::can_manage() ) return self::error( 'access_denied' );
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'provider_id', 'surface_kind', 'field_ids', 'language', 'rendered_surface_ref' ) ) ) return self::error( 'input_invalid' );
		$provider = sanitize_key( isset( $input['provider_id'] ) ? $input['provider_id'] : '' );
		$surface = sanitize_key( isset( $input['surface_kind'] ) ? $input['surface_kind'] : '' );
		$catalog = self::catalog();
		if ( ! isset( $catalog['providers'][ $provider ] ) ) return self::error( 'provider_unknown' );
		if ( ! in_array( $surface, $catalog['surface_kinds'], true ) ) return self::error( 'surface_invalid' );
		$fields = isset( $input['field_ids'] ) && is_array( $input['field_ids'] ) ? array_values( array_unique( array_map( 'sanitize_key', $input['field_ids'] ) ) ) : array();
		if ( ! $fields || count( $fields ) > 8 || array_diff( $fields, $catalog['field_ids'] ) ) return self::error( 'field_scope_invalid' );
		$language = isset( $input['language'] ) ? sanitize_key( $input['language'] ) : '';
		$rendered = isset( $input['rendered_surface_ref'] ) ? trim( (string) $input['rendered_surface_ref'] ) : '';
		if ( strlen( $language ) > 32 || strlen( $rendered ) > 512 ) return self::error( 'input_invalid' );
		$identity = self::runtime_identity( $provider );
		$gates = array(
			'provider_runtime_presence',
			'provider_native_schema_and_serialization',
			'exact_field_ownership',
			'exact_stored_readback',
			'exact_rendered_readback',
			'language_and_archive_scope',
			'cache_generation_freshness',
			'coexistence_conflict_preservation',
			'current_authority_and_impact_approval',
			'no_signal_direct_mutation',
		);
		return array(
			'contract' => 'mad4b.feature007-g5-seo-plan.v1',
			'provider_id' => $provider,
			'surface_kind' => $surface,
			'field_ids' => $fields,
			'language' => $language,
			'rendered_surface_ref' => $rendered,
			'installed' => $identity['installed'],
			'version' => $identity['version'],
			'required_gates' => $gates,
			'execution_supported' => false,
			'provider_execution_performed' => false,
			'content_mutation_performed' => false,
			'rendered_acceptance_performed' => false,
			'authorizing' => false,
		);
	}

	private static function runtime_identity( $provider ) {
		$version = '';
		switch ( $provider ) {
			case 'rank-math':
				if ( defined( 'RANK_MATH_VERSION' ) ) $version = (string) RANK_MATH_VERSION;
				elseif ( class_exists( 'RankMath' ) ) $version = 'present-unversioned';
				break;
			case 'yoast':
				if ( defined( 'WPSEO_VERSION' ) ) $version = (string) WPSEO_VERSION;
				elseif ( class_exists( 'WPSEO_Options' ) ) $version = 'present-unversioned';
				break;
			case 'aioseo':
				if ( defined( 'AIOSEO_VERSION' ) ) $version = (string) AIOSEO_VERSION;
				elseif ( function_exists( 'aioseo' ) ) $version = 'present-unversioned';
				break;
			case 'seopress':
				if ( defined( 'SEOPRESS_VERSION' ) ) $version = (string) SEOPRESS_VERSION;
				break;
			case 'slim-seo':
				if ( defined( 'SLIM_SEO_VER' ) ) $version = (string) SLIM_SEO_VER;
				elseif ( class_exists( 'SlimSEO\\Plugin' ) ) $version = 'present-unversioned';
				break;
			case 'the-seo-framework':
				if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) $version = (string) THE_SEO_FRAMEWORK_VERSION;
				elseif ( function_exists( 'the_seo_framework' ) ) $version = 'present-unversioned';
				break;
		}
		return array( 'installed' => '' !== $version, 'version' => $version );
	}

	private static function error( $code ) {
		return new WP_Error( 'mad4b_g5_seo_' . $code, 'The requested SEO provider operation is unavailable or requires reviewed provider-native acceptance.' );
	}
}
