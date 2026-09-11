<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Final authority split for external WPML acceptance.
 *
 * A request-local internal REST route probe is diagnostic-only. It must never
 * downgrade a trusted external WPML receipt that was already validated and
 * bound to the exact candidate build. Without trusted external evidence the
 * existing finalizer remains authoritative and fail-closed.
 */
final class MAD4B_SCP_External_WPML_Acceptance_Finalizer {
	const CONTRACT = 'mad4b.external-wpml-acceptance-finalizer.v1';

	private static $booted = false;

	public static function boot_early() {
		if ( self::$booted ) return;
		self::$booted = true;
		// Later than the strict snapshot finalizer (240). This wrapper changes no
		// write surface; it only resolves the authority of already-read evidence.
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'bind_callbacks' ), 260, 2 );
	}

	public static function bind_callbacks( $args, $name ) {
		if ( ! is_array( $args ) ) return $args;
		$name = (string) $name;
		if ( 'mad4b/external-wpml-receipt-status' === $name ) {
			$args['execute_callback'] = array( __CLASS__, 'external_wpml_receipt_status' );
		}
		if ( 'mad4b/live-acceptance-status' === $name ) {
			$args['execute_callback'] = array( __CLASS__, 'live_acceptance_status' );
		}
		return $args;
	}

	/**
	 * Pure authority merge used by runtime regression tests.
	 */
	public static function finalize_status( array $external, array $diagnostic ) {
		if ( empty( $external['verified'] ) ) return $diagnostic;

		$out = array_merge( $diagnostic, $external );
		$out['verified'] = true;
		$out['stale'] = isset( $external['stale'] ) ? (bool) $external['stale'] : false;
		$out['classification'] = 'success';
		$out['state'] = 'verified_external_wpml';
		$out['internal_probe_role'] = 'diagnostic_only';
		// Keep the diagnostic probe visible without allowing it to become the
		// acceptance authority.
		if ( array_key_exists( 'route_registered', $diagnostic ) ) {
			$out['route_registered'] = $diagnostic['route_registered'];
		}
		return $out;
	}

	/**
	 * Read the canonical normalized WPML response contract when available.
	 *
	 * The legacy observer predates WPML's standard success/data envelope and can
	 * therefore report a false negative for the same successful external HTTP
	 * response. It remains a compatibility fallback only when the normalized
	 * response-contract class is not present at all.
	 */
	private static function authoritative_external_receipt() {
		if ( class_exists( 'MAD4B_SCP_WPML_Response_Contract' ) ) {
			$external = MAD4B_SCP_WPML_Response_Contract::receipt_status();
			return is_array( $external ) ? $external : array();
		}
		if ( class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ) {
			$external = MAD4B_SCP_Live_Acceptance_Observer::external_wpml_receipt_status();
			return is_array( $external ) ? $external : array();
		}
		return array();
	}

	public static function external_wpml_receipt_status() {
		$external = self::authoritative_external_receipt();
		$diagnostic = class_exists( 'MAD4B_SCP_Live_Acceptance_Finalizer' )
			? MAD4B_SCP_Live_Acceptance_Finalizer::external_wpml_receipt_status()
			: array();
		if ( ! is_array( $diagnostic ) ) $diagnostic = array();
		$out = self::finalize_status( $external, $diagnostic );
		$out['authority_contract'] = self::CONTRACT;
		return $out;
	}

	public static function live_acceptance_status( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$base = class_exists( 'MAD4B_SCP_External_Snapshot_Finalizer' )
			? MAD4B_SCP_External_Snapshot_Finalizer::live_acceptance_status( $input )
			: ( class_exists( 'MAD4B_SCP_Live_Acceptance_Finalizer' )
				? MAD4B_SCP_Live_Acceptance_Finalizer::live_acceptance_status( $input )
				: array( 'contract' => 'mad4b.live-acceptance-status.v1', 'gates' => array() ) );
		if ( ! is_array( $base ) ) $base = array( 'contract' => 'mad4b.live-acceptance-status.v1', 'gates' => array() );
		if ( ! isset( $base['gates'] ) || ! is_array( $base['gates'] ) ) $base['gates'] = array();

		$wpml = self::external_wpml_receipt_status();
		if ( ! empty( $wpml['verified'] ) ) {
			$base['gates']['external_wpml'] = array(
				'state' => 'verified_external_wpml',
				'ready' => true,
				'fresh' => empty( $wpml['stale'] ),
				'source_contract' => isset( $wpml['contract'] ) && '' !== (string) $wpml['contract']
					? (string) $wpml['contract']
					: ( class_exists( 'MAD4B_SCP_Live_Acceptance_Finalizer' ) ? MAD4B_SCP_Live_Acceptance_Finalizer::WPML_DIAGNOSTIC_CONTRACT : 'mad4b.external-wpml-diagnostic.v2' ),
				'blockers' => array(),
				'observed_at' => isset( $wpml['observed_at'] ) ? (string) $wpml['observed_at'] : gmdate( 'c' ),
			);
			$base['ready'] = class_exists( 'MAD4B_SCP_Live_Acceptance_Finalizer' )
				? MAD4B_SCP_Live_Acceptance_Finalizer::aggregate_ready( $base['gates'] )
				: self::all_ready( $base['gates'] );
			$base['state'] = $base['ready'] ? 'ready' : 'pending_or_blocked';
		}
		$base['external_wpml_authority_contract'] = self::CONTRACT;
		$base['external_facts_self_certified'] = false;
		return $base;
	}

	private static function all_ready( array $gates ) {
		if ( empty( $gates ) ) return false;
		foreach ( $gates as $gate ) if ( ! is_array( $gate ) || empty( $gate['ready'] ) ) return false;
		return true;
	}
}

MAD4B_SCP_External_WPML_Acceptance_Finalizer::boot_early();
