<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * External WPML REST truth for Live Acceptance.
 *
 * WPML currently returns the tested values in the standard WordPress success
 * envelope (`success=true`, `data={...}`). Older acceptance code expected the
 * fields at the top level and therefore produced a false negative. This class
 * accepts only the two documented compatible shapes, stores normalized/safe
 * diagnostics, and keeps the external WPML gate independent from the internal
 * MCP-request route-registration probe.
 */
final class MAD4B_SCP_WPML_Response_Contract {
	const CONTRACT = 'mad4b.external-wpml-response-contract.v1';
	const OPTION = 'mad4b_scp_external_wpml_response_contract_v1';
	const ROUTE = '/wpml/v1/rest/status';
	const STAGING_ORIGIN = 'https://staging.egypttourgates.com';
	const TTL = 21600;

	private static $booted = false;

	public static function boot_early() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'observe_response' ), PHP_INT_MAX - 5, 3 );
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'bind_callbacks' ), 180, 2 );
	}

	public static function bind_callbacks( $args, $name ) {
		if ( ! is_array( $args ) ) return $args;
		if ( 'mad4b/external-wpml-receipt-status' === (string) $name ) {
			$args['execute_callback'] = array( __CLASS__, 'receipt_status' );
		}
		if ( 'mad4b/live-acceptance-status' === (string) $name ) {
			$args['execute_callback'] = array( __CLASS__, 'live_acceptance_status' );
		}
		return $args;
	}

	public static function observe_response( $response, $server, $request ) {
		if ( ! self::staging_allowed() || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return $response;
		$route = '/' . ltrim( rtrim( (string) $request->get_route(), '/' ), '/' );
		if ( self::ROUTE !== $route ) return $response;

		$parameter_present = method_exists( $request, 'get_param' ) && '1' === (string) $request->get_param( 'test_get_parameter' );
		$status_code = 0;
		$data = null;
		$error_code = '';
		$safe_message = '';
		$content_type = '';

		if ( is_wp_error( $response ) ) {
			$error_code = sanitize_key( (string) $response->get_error_code() );
			$safe_message = self::safe_message( $response->get_error_message() );
			$error_data = $response->get_error_data();
			$status_code = is_array( $error_data ) && isset( $error_data['status'] ) ? absint( $error_data['status'] ) : 500;
		} else {
			$rest = rest_ensure_response( $response );
			if ( $rest instanceof WP_REST_Response ) {
				$status_code = (int) $rest->get_status();
				$data = $rest->get_data();
				$headers = $rest->get_headers();
				foreach ( $headers as $header_name => $value ) {
					if ( 'content-type' !== strtolower( (string) $header_name ) ) continue;
					$content_type = is_array( $value ) ? (string) reset( $value ) : (string) $value;
					break;
				}
				if ( is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ) $error_code = sanitize_key( $data['code'] );
				if ( is_array( $data ) && isset( $data['message'] ) ) $safe_message = self::safe_message( $data['message'] );
			}
		}

		$evaluated = self::evaluate_response( $parameter_present, $status_code, $data, $error_code );
		$candidate = self::candidate_identity();
		$receipt = array(
			'contract' => self::CONTRACT,
			'observed' => true,
			'observed_at' => gmdate( 'c' ),
			'build_fingerprint' => isset( $candidate['build_fingerprint'] ) ? (string) $candidate['build_fingerprint'] : '',
			'request_route' => self::ROUTE,
			'test_get_parameter_present' => (bool) $parameter_present,
			'response_status' => $status_code,
			'response_content_type' => substr( sanitize_text_field( $content_type ), 0, 120 ),
			'error_code' => $error_code,
			'body_classification' => $evaluated['body_classification'],
			'classification' => $evaluated['classification'],
			'status' => $evaluated['status'],
			'get_parameters' => $evaluated['get_parameters'],
			'safe_message' => $safe_message,
		);
		update_option( self::OPTION, $receipt, false );
		return $response;
	}

	/** @internal Pure evaluator used by regression tests. */
	public static function evaluate_response( $parameter_present, $status_code, $data, $error_code = '', $route_registered = null ) {
		$status_code = (int) $status_code;
		$error_code = sanitize_key( (string) $error_code );
		$body_classification = is_array( $data ) ? 'json_object' : ( null === $data ? 'empty_or_error' : 'non_json' );
		$status = '';
		$get_parameters = '';

		if ( false === $route_registered ) {
			return array( 'classification' => 'route_not_registered', 'body_classification' => $body_classification, 'status' => '', 'get_parameters' => '' );
		}
		if ( 'rest_no_route' === $error_code ) {
			return array( 'classification' => 'rest_no_route', 'body_classification' => $body_classification, 'status' => '', 'get_parameters' => '' );
		}
		if ( '' !== $error_code ) {
			return array( 'classification' => 'wp_error', 'body_classification' => $body_classification, 'status' => '', 'get_parameters' => '' );
		}
		if ( $status_code >= 300 && $status_code < 400 ) {
			return array( 'classification' => 'redirect_response', 'body_classification' => $body_classification, 'status' => '', 'get_parameters' => '' );
		}
		if ( ! is_array( $data ) ) {
			return array( 'classification' => 'non_json_response', 'body_classification' => $body_classification, 'status' => '', 'get_parameters' => '' );
		}
		if ( $status_code < 200 || $status_code >= 300 ) {
			return array( 'classification' => 'unexpected_status', 'body_classification' => $body_classification, 'status' => '', 'get_parameters' => '' );
		}

		$payload = $data;
		if ( isset( $data['success'] ) && true === $data['success'] && isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$payload = $data['data'];
			$body_classification = 'wp_rest_success_envelope';
		}
		$status = isset( $payload['status'] ) ? sanitize_key( (string) $payload['status'] ) : '';
		$get_parameters = isset( $payload['get_parameters'] ) ? sanitize_key( (string) $payload['get_parameters'] ) : '';
		if ( $parameter_present && 'valid' === $status && 'valid' === $get_parameters ) {
			return array( 'classification' => 'success', 'body_classification' => $body_classification, 'status' => $status, 'get_parameters' => $get_parameters );
		}
		return array( 'classification' => 'contract_mismatch', 'body_classification' => $body_classification, 'status' => $status, 'get_parameters' => $get_parameters );
	}

	public static function receipt_status() {
		$candidate = self::candidate_identity();
		$stored = self::staging_allowed() ? get_option( self::OPTION, array() ) : array();
		$base = array(
			'contract' => self::CONTRACT,
			'observed' => false,
			'verified' => false,
			'stale' => true,
			'state' => 'pending_external_evidence',
			'classification' => 'pending_external_evidence',
			'observed_at' => '',
			'build_fingerprint' => '',
			'current_build_fingerprint' => isset( $candidate['build_fingerprint'] ) ? $candidate['build_fingerprint'] : '',
			'request_route' => self::ROUTE,
			'test_get_parameter_present' => false,
			'response_status' => 0,
			'response_content_type' => '',
			'error_code' => '',
			'body_classification' => '',
			'status' => '',
			'get_parameters' => '',
			'safe_message' => '',
		);
		if ( ! is_array( $stored ) || empty( $stored['observed'] ) ) {
			$rest = class_exists( 'MAD4B_SCP_REST_Compatibility' ) ? MAD4B_SCP_REST_Compatibility::status() : array();
			if ( isset( $rest['wpml']['route_registered'] ) && false === (bool) $rest['wpml']['route_registered'] ) {
				$base['classification'] = 'route_not_registered';
				$base['state'] = 'route_not_registered_internal_probe_only';
			}
			return $base;
		}
		foreach ( array( 'observed','observed_at','build_fingerprint','request_route','test_get_parameter_present','response_status','response_content_type','error_code','body_classification','classification','status','get_parameters','safe_message' ) as $key ) {
			if ( array_key_exists( $key, $stored ) ) $base[ $key ] = $stored[ $key ];
		}
		$build_match = ! empty( $candidate['build_fingerprint'] ) && ! empty( $stored['build_fingerprint'] ) && hash_equals( (string) $candidate['build_fingerprint'], (string) $stored['build_fingerprint'] );
		$observed_ts = self::parse_time( isset( $stored['observed_at'] ) ? $stored['observed_at'] : '' );
		$fresh = false !== $observed_ts && $observed_ts <= time() + 60 && ( time() - $observed_ts ) <= self::TTL;
		$verified = $build_match && $fresh && ! empty( $stored['test_get_parameter_present'] ) && 'success' === (string) $stored['classification'] && 'valid' === (string) $stored['status'] && 'valid' === (string) $stored['get_parameters'];
		$base['verified'] = $verified;
		$base['stale'] = ! ( $build_match && $fresh );
		$base['state'] = $verified ? 'verified_external_wpml' : ( ! $build_match ? 'stale_build_evidence' : ( ! $fresh ? 'stale_evidence' : (string) $stored['classification'] ) );
		return $base;
	}

	public static function live_acceptance_status( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$base = class_exists( 'MAD4B_SCP_Live_Acceptance_Finalizer' )
			? MAD4B_SCP_Live_Acceptance_Finalizer::live_acceptance_status( $input )
			: array( 'contract' => 'mad4b.live-acceptance-status.v1', 'gates' => array() );
		if ( ! is_array( $base ) ) $base = array( 'contract' => 'mad4b.live-acceptance-status.v1', 'gates' => array() );
		if ( ! isset( $base['gates'] ) || ! is_array( $base['gates'] ) ) $base['gates'] = array();
		$wpml = self::receipt_status();
		$base['gates']['external_wpml'] = array(
			'state' => isset( $wpml['state'] ) ? (string) $wpml['state'] : 'pending_external_evidence',
			'ready' => ! empty( $wpml['verified'] ),
			'fresh' => empty( $wpml['stale'] ),
			'source_contract' => self::CONTRACT,
			'blockers' => ! empty( $wpml['verified'] ) ? array() : array( isset( $wpml['classification'] ) ? (string) $wpml['classification'] : 'external_wpml_evidence_required' ),
			'observed_at' => isset( $wpml['observed_at'] ) ? (string) $wpml['observed_at'] : gmdate( 'c' ),
		);
		$base['ready'] = class_exists( 'MAD4B_SCP_Live_Acceptance_Finalizer' ) ? MAD4B_SCP_Live_Acceptance_Finalizer::aggregate_ready( $base['gates'] ) : false;
		$base['state'] = $base['ready'] ? 'ready' : 'pending_or_blocked';
		$base['external_wpml_contract'] = self::CONTRACT;
		return $base;
	}

	private static function candidate_identity() {
		$provenance = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
		$sha = isset( $provenance['source_commit_sha'] ) ? strtolower( (string) $provenance['source_commit_sha'] ) : '';
		$fingerprint = isset( $provenance['build_fingerprint'] ) ? strtolower( (string) $provenance['build_fingerprint'] ) : '';
		return array(
			'source_commit_sha' => preg_match( '/^[a-f0-9]{40}$/', $sha ) ? $sha : '',
			'build_fingerprint' => preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ? $fingerprint : '',
		);
	}

	private static function staging_allowed() {
		return class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) && MAD4B_SCP_Live_Acceptance_Observer::staging_capture_allowed();
	}

	private static function parse_time( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) return false;
		$ts = strtotime( $value );
		return false === $ts ? false : $ts;
	}

	private static function safe_message( $message ) {
		$message = preg_replace( '/[\r\n\t]+/', ' ', (string) $message );
		$message = preg_replace( '/\bBearer\s+[^\s]+/i', 'Bearer [redacted]', $message );
		$message = preg_replace( '/\b(authorization|cookie|password|token|secret|api[_-]?key)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $message );
		$message = trim( (string) $message );
		return strlen( $message ) > 240 ? substr( $message, 0, 240 ) : $message;
	}
}
