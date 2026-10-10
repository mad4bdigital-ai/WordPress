<?php
/** Exact CSO site/source/actor identities and existing deployment-local integrity. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_CSO_Scope {
	const CONTRACT = 'mad4b.cso01.scope.v1';
	const MAX_BYTES = 262144;
	const MAX_DEPTH = 24;
	const MAX_NODES = 8192;
	private static $boot_blog = null;
	public static function boot() { if ( null === self::$boot_blog ) self::$boot_blog = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0; }
	public static function enabled( $flag ) {
		$flags = array( 'discovery' => 'MAD4B_CSO_READ_INVENTORY_ENABLED', 'read_inventory' => 'MAD4B_CSO_READ_INVENTORY_ENABLED', 'forms' => 'MAD4B_CSO_FORMS_ENABLED', 'secrets' => 'MAD4B_CSO_SECRETS_ENABLED', 'single_write' => 'MAD4B_CSO_SINGLE_WRITE_ENABLED', 'submit' => 'MAD4B_CSO_SINGLE_WRITE_ENABLED', 'bulk' => 'MAD4B_CSO_BULK_ENABLED', 'workflow' => 'MAD4B_CSO_WORKFLOW_ENABLED', 'workflows' => 'MAD4B_CSO_WORKFLOW_ENABLED', 'triggers' => 'MAD4B_CSO_WORKFLOW_ENABLED', 'multisite' => 'MAD4B_CSO_MULTISITE_ENABLED', 'operations' => 'MAD4B_CSO_OPERATIONS_ENABLED', 'production_proposal' => 'MAD4B_CSO_PRODUCTION_PROPOSAL_ENABLED' );
		return is_string( $flag ) && isset( $flags[ $flag ] ) && defined( $flags[ $flag ] ) && true === constant( $flags[ $flag ] );
	}
	public static function current() {
		self::boot();
		foreach ( array( 'MAD4B_SCP_Site_Profile', 'MAD4B_SCP_Adaptive_Operations_Context', 'MAD4B_SCP_Live_Acceptance_Observer', 'MAD4B_SCP_Identity_Context', 'MAD4B_SCP_Policy' ) as $class ) if ( ! class_exists( $class ) ) return self::error( 'identity_unavailable' );
		foreach ( array( 'get_current_blog_id', 'get_current_user_id', 'wp_get_current_user', 'determine_locale' ) as $function ) if ( ! function_exists( $function ) ) return self::error( 'identity_unavailable' );
		if ( self::$boot_blog !== get_current_blog_id() || ( class_exists( 'MAD4B_SCP_Unified_Capability_Gateway' ) && ! MAD4B_SCP_Unified_Capability_Gateway::runtime_blog_matches() ) ) return self::error( 'blog_changed' );
		if ( true !== MAD4B_SCP_Policy::can_read() || ! MAD4B_SCP_Site_Profile::configured() || ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return self::error( 'site_unavailable' );
		try {
			$runtime = MAD4B_SCP_Adaptive_Operations_Context::current(); $identity = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status(); $profile = MAD4B_SCP_Site_Profile::status();
			if ( is_wp_error( $runtime ) || ! is_array( $runtime ) || ! is_array( $identity ) || true !== ( $identity['identity_ready'] ?? null ) || true !== ( $identity['manifest_valid'] ?? null ) ) return self::error( 'runtime_unverified' );
			if ( ! is_array( $profile ) || true !== ( $profile['authority_ready'] ?? null ) || true !== ( $profile['origin_match'] ?? null ) || true !== ( $profile['environment_match'] ?? null ) || true !== ( $profile['deployment_binding_match'] ?? null ) || true === ( $profile['profile_authority_quarantined'] ?? false ) ) return self::error( 'site_unavailable' );
			$source = $identity['source_commit_sha'] ?? ''; $package = $identity['package_manifest_digest'] ?? '';
			if ( ! is_string( $source ) || ! preg_match( '/^[a-f0-9]{40}$/D', $source ) || ! self::sha( $package ) || $package !== ( $runtime['artifact_sha256'] ?? '' ) ) return self::error( 'runtime_unverified' );
			$site = array( 'contract' => self::CONTRACT, 'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(), 'origin' => MAD4B_SCP_Site_Profile::current_origin(), 'environment' => MAD4B_SCP_Site_Profile::current_environment(), 'source_sha' => $source, 'package_sha256' => $package, 'runtime_generation' => $runtime['runtime_generation'] ?? '', 'restore_epoch' => $runtime['restore_epoch'] ?? null, 'profile_sha256' => MAD4B_SCP_Site_Profile::profile_digest(), 'deployment_sha256' => MAD4B_SCP_Site_Profile::deployment_binding_digest(), 'external_record_sha256' => $runtime['external_record_sha256'] ?? '', 'blog_id' => get_current_blog_id(), 'locale' => determine_locale() );
			if ( ! self::valid_site( $site ) || ( $runtime['site_uuid'] ?? '' ) !== $site['site_uuid'] || ( $runtime['environment'] ?? '' ) !== $site['environment'] || ( $runtime['profile_digest'] ?? '' ) !== $site['profile_sha256'] || ( $runtime['origin_sha256'] ?? '' ) !== hash( 'sha256', $site['origin'] ) ) return self::error( 'runtime_unverified' );
			if ( class_exists( 'MAD4B_SCP_Environment' ) && MAD4B_SCP_Environment::effective() !== $site['environment'] ) return self::error( 'environment_changed' );
			$actor = self::actor(); if ( is_wp_error( $actor ) ) return $actor;
			$digest = self::digest( $actor ); if ( is_wp_error( $digest ) ) return $digest;
			$site['actor_sha256'] = MAD4B_SCP_Site_Profile::deployment_binding_proof( 'mad4b.cso01.actor.v1', $digest );
			if ( ! self::sha( $site['actor_sha256'] ) ) return self::error( 'actor_unavailable' );
			$digest = self::digest( $site ); if ( is_wp_error( $digest ) ) return $digest;
			$site['binding_sha256'] = $digest; $site['scope_sha256'] = $digest;
			return $site;
		} catch ( Throwable $error ) { return self::error( 'identity_unavailable' ); }
	}
	private static function actor() {
		$id = get_current_user_id(); $user = wp_get_current_user();
		if ( ! is_int( $id ) || $id < 1 || ! is_object( $user ) || ! isset( $user->allcaps, $user->roles ) || ! is_array( $user->allcaps ) || ! is_array( $user->roles ) || count( $user->allcaps ) > 512 || count( $user->roles ) > 64 || ( method_exists( 'MAD4B_SCP_Policy', 'can_connect_user' ) && true !== MAD4B_SCP_Policy::can_connect_user( $id ) ) ) return self::error( 'actor_unavailable' );
		$context = MAD4B_SCP_Identity_Context::current(); if ( is_wp_error( $context ) || ! is_array( $context ) ) return self::error( 'actor_unavailable' );
		$actor = array( 'wp_user_id' => $id, 'roles' => $user->roles, 'allcaps' => $user->allcaps ); sort( $actor['roles'], SORT_STRING ); ksort( $actor['allcaps'], SORT_STRING );
		if ( true === ( $context['authenticated'] ?? false ) ) {
			foreach ( array( 'subject_fingerprint', 'issuer_fingerprint', 'client_fingerprint', 'session_fingerprint' ) as $field ) if ( ! self::sha( $context[ $field ] ?? '' ) ) return self::error( 'actor_unavailable' );
			if ( ( $context['wp_user_id'] ?? null ) !== $id || ! is_string( $context['subject_type'] ?? null ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $context['subject_type'] ) || ! is_string( $context['auth_method'] ?? null ) || '' === $context['auth_method'] ) return self::error( 'actor_unavailable' );
			$scopes = $context['token_scopes'] ?? null; if ( ! is_array( $scopes ) || count( $scopes ) > 200 ) return self::error( 'actor_unavailable' );
			foreach ( $scopes as $scope ) if ( ! is_string( $scope ) || '' === $scope || strlen( $scope ) > 255 || false !== strpos( $scope, '*' ) ) return self::error( 'actor_unavailable' );
			$scopes = array_values( array_unique( $scopes ) ); sort( $scopes, SORT_STRING );
			$actor['identity'] = array_intersect_key( $context, array_fill_keys( array( 'subject_type', 'subject_fingerprint', 'issuer_fingerprint', 'client_fingerprint', 'session_fingerprint', 'auth_method', 'origin' ), true ) ); $actor['identity']['token_scopes'] = $scopes;
			if ( ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return self::error( 'nhi_unavailable' );
			$binding = MAD4B_SCP_Agent_Registry::subject_binding( $context['subject_type'], $context['subject_fingerprint'] ); $agent = MAD4B_SCP_Agent_Registry::resolve_agent( $context );
			if ( is_wp_error( $binding ) || is_wp_error( $agent ) || ! is_array( $binding ) || ! is_array( $agent ) || 'enabled' !== ( $binding['status'] ?? '' ) || 'enabled' !== ( $agent['status'] ?? '' ) || (int) ( $binding['agent_id'] ?? 0 ) !== (int) ( $agent['id'] ?? -1 ) ) return self::error( 'nhi_unavailable' );
			$grants = MAD4B_SCP_Agent_Registry::grants_for_agent( (int) $agent['id'] ); if ( ! is_array( $grants ) || count( $grants ) > 2048 ) return self::error( 'nhi_unavailable' );
			$actor['nhi'] = array( 'subject_binding' => $binding, 'agent' => $agent, 'grants' => $grants );
		} else {
			foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $header ) if ( isset( $_SERVER[ $header ] ) && '' !== trim( (string) $_SERVER[ $header ] ) ) return self::error( 'actor_unavailable' );
			if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() || ! function_exists( 'wp_get_session_token' ) ) return self::error( 'actor_unavailable' );
			$session = wp_get_session_token(); if ( ! is_string( $session ) || strlen( $session ) < 16 || strlen( $session ) > 512 ) return self::error( 'actor_unavailable' );
			$digest = self::digest( array( 'wp_user_id' => $id, 'session' => $session ) ); if ( is_wp_error( $digest ) ) return $digest;
			$actor['identity'] = array( 'auth_method' => 'wordpress_cookie', 'session_fingerprint' => MAD4B_SCP_Site_Profile::deployment_binding_proof( 'mad4b.cso01.cookie.v1', $digest ) );
			if ( ! self::sha( $actor['identity']['session_fingerprint'] ) ) return self::error( 'actor_unavailable' );
		}
		return $actor;
	}
	private static function valid_site( array $s ) {
		if ( ! is_string( $s['site_uuid'] ) || ! preg_match( '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $s['site_uuid'] ) || ! in_array( $s['environment'], array( 'local', 'development', 'staging', 'production' ), true ) || ! is_int( $s['blog_id'] ) || $s['blog_id'] < 1 || ! is_int( $s['restore_epoch'] ) || $s['restore_epoch'] < 1 || ! is_string( $s['locale'] ) || ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]{0,31}$/D', $s['locale'] ) ) return false;
		foreach ( array( 'package_sha256', 'runtime_generation', 'profile_sha256', 'deployment_sha256', 'external_record_sha256' ) as $key ) if ( ! self::sha( $s[ $key ] ) ) return false;
		if ( ! is_string( $s['origin'] ) || strlen( $s['origin'] ) > 512 ) return false; $parts = parse_url( $s['origin'] );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) return false;
		return 'https' === ( $parts['scheme'] ?? '' ) || ( in_array( $s['environment'], array( 'local', 'development' ), true ) && 'http' === ( $parts['scheme'] ?? '' ) && in_array( $parts['host'], array( 'localhost', '127.0.0.1', '[::1]' ), true ) );
	}
	public static function assert_current( array $scope ) {
		$current = self::current(); if ( is_wp_error( $current ) || true !== self::bounded( $scope ) ) return self::error( 'scope_changed' );
		$a = self::digest( $current ); $b = self::digest( $scope ); return ! is_wp_error( $a ) && ! is_wp_error( $b ) && hash_equals( $a, $b ) ? true : self::error( 'scope_changed' );
	}
	/** Boolean only: objects/resources, cycles, invalid UTF-8 and over-budget data fail closed. */
	public static function bounded( $value, $max_bytes = self::MAX_BYTES ) {
		if ( ! is_int( $max_bytes ) || $max_bytes < 1 || $max_bytes > self::MAX_BYTES ) return false; $nodes = 0;
		if ( ! self::walk( $value, 0, $nodes ) ) return false;
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ); return is_string( $json ) && strlen( $json ) <= $max_bytes;
	}
	private static function walk( $value, $depth, &$nodes ) {
		if ( ++$nodes > self::MAX_NODES || $depth > self::MAX_DEPTH ) return false;
		if ( null === $value || is_bool( $value ) || is_int( $value ) ) return true;
		if ( is_float( $value ) ) return is_finite( $value );
		if ( is_string( $value ) ) return strlen( $value ) <= self::MAX_BYTES && 1 === preg_match( '//u', $value );
		if ( ! is_array( $value ) ) return false;
		foreach ( $value as $key => $child ) if ( ( ! is_int( $key ) && ( ! is_string( $key ) || strlen( $key ) > 256 || 1 !== preg_match( '//u', $key ) ) ) || ! self::walk( $child, $depth + 1, $nodes ) ) return false;
		return true;
	}
	/** False second arg permits typed schema/integrity references, never raw secret values. */
	public static function safe_data( $value, $ordinary_values = true ) { return is_bool( $ordinary_values ) && true === self::bounded( $value ) && ! self::private_values( $value, $ordinary_values ); }
	private static function private_values( $value, $ordinary ) {
		if ( is_string( $value ) ) return 1 === preg_match( '/(?:Bearer\s+[A-Za-z0-9._~+\/-]{12,}|(?:sk-|ghp_|github_pat_|xox[baprs]-)[A-Za-z0-9_-]{12,}|-----BEGIN (?:RSA )?PRIVATE KEY-----|eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+|\bAKIA[0-9A-Z]{16}\b)/', $value ) || 1 === preg_match( '~[a-z][a-z0-9+.-]*://[^/@\s:]+:[^/@\s]+@~i', $value );
		if ( ! is_array( $value ) ) return false;
		foreach ( $value as $key => $child ) {
			if ( is_string( $key ) && preg_match( '/(?:authorization|password|passwd|credential|api[_-]?key|access[_-]?token|refresh[_-]?token|private[_-]?key|client[_-]?secret|signing[_-]?secret|cookie|^secret$|^token$)/i', $key ) ) {
				$reference = ! $ordinary && ( (bool) preg_match( '/(?:_sha256|_fingerprint|_id|_reference|_supported|_configured)$/D', $key ) || ( is_array( $child ) && isset( $child['type'] ) ) || is_bool( $child ) ); if ( ! $reference ) return true;
			}
			if ( self::private_values( $child, $ordinary || in_array( $key, array( 'values', 'input', 'params', 'desired', 'before', 'after' ), true ) ) ) return true;
		}
		return false;
	}
	public static function digest( $value ) {
		if ( true !== self::bounded( $value ) ) return self::error( 'data_invalid' );
		$json = wp_json_encode( self::canonical( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ); return is_string( $json ) ? hash( 'sha256', self::CONTRACT . "\n" . $json ) : self::error( 'data_invalid' );
	}
	private static function canonical( $value ) { if ( ! is_array( $value ) ) return $value; if ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ksort( $value, SORT_STRING ); foreach ( $value as $key => $child ) $value[ $key ] = self::canonical( $child ); return $value; }
	/** Pure exact-material MAC. Each service owns its scope, TTL and authority admission. */
	public static function seal( array $material, $purpose ) {
		if ( ! self::purpose( $purpose ) || true !== self::safe_data( $material, false ) || ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return self::error( 'sealed_material_invalid' );
		$digest = self::digest( array( 'purpose' => $purpose, 'material' => $material ) ); if ( is_wp_error( $digest ) ) return $digest;
		$proof = MAD4B_SCP_Site_Profile::deployment_binding_proof( $purpose, $digest ); if ( ! self::sha( $proof ) ) return self::error( 'deployment_proof_unavailable' );
		return array( 'contract' => $purpose, 'material' => $material, 'sha256' => $digest, 'proof' => $proof );
	}
	public static function unseal( array $sealed, $purpose ) {
		if ( ! self::purpose( $purpose ) || true !== self::bounded( $sealed ) || ( $sealed['contract'] ?? '' ) !== $purpose || count( $sealed ) !== 4 || ! isset( $sealed['material'] ) || ! is_array( $sealed['material'] ) || ! self::sha( $sealed['sha256'] ?? '' ) || ! self::sha( $sealed['proof'] ?? '' ) || true !== self::safe_data( $sealed['material'], false ) ) return self::error( 'sealed_material_invalid' );
		$digest = self::digest( array( 'purpose' => $purpose, 'material' => $sealed['material'] ) );
		if ( is_wp_error( $digest ) || ! hash_equals( $digest, $sealed['sha256'] ) || ! class_exists( 'MAD4B_SCP_Site_Profile' ) || true !== MAD4B_SCP_Site_Profile::verify_deployment_binding_proof( $purpose, $digest, $sealed['proof'] ) ) return self::error( 'sealed_material_modified' );
		return $sealed['material'];
	}
	private static function purpose( $p ) { return is_string( $p ) && strlen( $p ) <= 96 && 1 === preg_match( '/^mad4b\.cso01\.[a-z0-9][a-z0-9._-]{1,75}$/D', $p ); }
	private static function sha( $v ) { return is_string( $v ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $v ); }
	public static function error( $reason ) { $reason = is_string( $reason ) ? strtolower( $reason ) : ''; if ( ! preg_match( '/^[a-z0-9_]{1,64}$/D', $reason ) ) $reason = 'unavailable'; return new WP_Error( 'mad4b_cso_' . $reason, 'The exact site, source and actor must be prepared again.', array( 'reason' => $reason, 'authorizing' => false, 'mutation_performed' => false ) ); }
}
