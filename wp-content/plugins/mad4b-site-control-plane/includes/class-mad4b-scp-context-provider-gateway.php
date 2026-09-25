<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Repository-owned Context provider gateway.
 *
 * This class is the only provider dispatch surface used by Brand Context
 * orchestration. Provider selection is derived from the governed Context
 * source registry; callers cannot supply an arbitrary runtime class.
 */
final class MAD4B_SCP_Context_Provider_Gateway {
	const CONTRACT = 'mad4b.context-provider-gateway.v1';

	private static function source( $source_id ) {
		$source_id = strtolower( trim( sanitize_text_field( (string) $source_id ) ) );
		if ( '' === $source_id || ! class_exists( 'MAD4B_SCP_Context_Authority' ) ) {
			return new WP_Error( 'mad4b_context_provider_source_unavailable', 'Context source authority is unavailable.' );
		}
		$source = MAD4B_SCP_Context_Authority::source( $source_id );
		if ( empty( $source ) ) return new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found.' );
		return $source;
	}

	private static function provider_from_source( array $source ) {
		$provider = sanitize_key( isset( $source['provider'] ) ? (string) $source['provider'] : '' );
		if ( 'google_drive' === $provider && class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ) return $provider;
		return new WP_Error(
			'mad4b_context_provider_unsupported',
			'Context source provider is not certified by the repository-owned provider gateway.',
			array( 'provider' => $provider )
		);
	}

	public static function capabilities() {
		return array(
			'contract' => self::CONTRACT,
			'providers' => array(
				'google_drive' => array(
					'read_asset' => class_exists( 'MAD4B_SCP_Google_Drive_Context' ) && method_exists( 'MAD4B_SCP_Google_Drive_Context', 'read_context_asset' ),
					'scan_source' => class_exists( 'MAD4B_SCP_Google_Drive_Context' ) && method_exists( 'MAD4B_SCP_Google_Drive_Context', 'scan_folder' ),
					'create_asset' => class_exists( 'MAD4B_SCP_Google_Drive_Context' ) && method_exists( 'MAD4B_SCP_Google_Drive_Context', 'create_asset' ),
					'rollback_created_brand_asset' => class_exists( 'MAD4B_SCP_Google_Drive_Context' ) && method_exists( 'MAD4B_SCP_Google_Drive_Context', 'rollback_created_brand_asset' ),
				),
			),
			'dynamic_provider_class_selection' => false,
			'authority_widening' => false,
		);
	}

	public static function read_context_asset( $asset_id ) {
		if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) ) return new WP_Error( 'mad4b_context_authority_unavailable', 'Context Authority is unavailable.' );
		$asset = MAD4B_SCP_Context_Authority::asset( $asset_id );
		if ( empty( $asset ) ) return new WP_Error( 'mad4b_context_asset_not_found', 'Context asset was not found.' );
		$source = self::source( isset( $asset['source_id'] ) ? $asset['source_id'] : '' );
		if ( is_wp_error( $source ) ) return $source;
		$provider = self::provider_from_source( $source );
		if ( is_wp_error( $provider ) ) return $provider;
		if ( 'google_drive' === $provider ) return MAD4B_SCP_Google_Drive_Context::read_context_asset( $asset_id );
		return new WP_Error( 'mad4b_context_provider_read_unsupported', 'Context provider does not expose certified asset readback.' );
	}

	public static function scan_source( $source_id ) {
		$source = self::source( $source_id );
		if ( is_wp_error( $source ) ) return $source;
		$provider = self::provider_from_source( $source );
		if ( is_wp_error( $provider ) ) return $provider;
		if ( 'google_drive' === $provider ) {
			return MAD4B_SCP_Google_Drive_Context::scan_folder(
				isset( $source['external_root_id'] ) ? $source['external_root_id'] : '',
				! empty( $source['recursive'] )
			);
		}
		return new WP_Error( 'mad4b_context_provider_scan_unsupported', 'Context provider does not expose certified source scanning.' );
	}

	public static function create_asset( $source_id, $name, $content, $format ) {
		$source = self::source( $source_id );
		if ( is_wp_error( $source ) ) return $source;
		$provider = self::provider_from_source( $source );
		if ( is_wp_error( $provider ) ) return $provider;
		if ( 'google_drive' === $provider ) return MAD4B_SCP_Google_Drive_Context::create_asset( $source_id, $name, $content, $format );
		return new WP_Error( 'mad4b_context_provider_create_unsupported', 'Context provider does not expose certified asset creation.' );
	}

	public static function rollback_created_brand_asset( array $receipt, $allow_unmarked = false ) {
		$source = self::source( isset( $receipt['source_id'] ) ? $receipt['source_id'] : '' );
		if ( is_wp_error( $source ) ) return $source;
		$provider = self::provider_from_source( $source );
		if ( is_wp_error( $provider ) ) return $provider;
		if ( 'google_drive' === $provider ) return MAD4B_SCP_Google_Drive_Context::rollback_created_brand_asset( $receipt, $allow_unmarked );
		return new WP_Error( 'mad4b_context_provider_rollback_unsupported', 'Context provider does not expose certified Brand Context rollback.' );
	}
}
