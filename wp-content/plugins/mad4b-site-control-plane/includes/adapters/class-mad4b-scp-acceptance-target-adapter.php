<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Registers an exact-candidate, Staging-only acceptance fixture on the normal
 * governed MAD4B adapter/write authority. The adapter base loads this file only
 * after MAD4B_SCP_Adapter_Base exists, so the concrete class is top-level and
 * remains compatible with PHP 7.4.
 */
final class MAD4B_SCP_Acceptance_Target_Adapter_Bootstrap {
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register' ), 5, 1 );
	}

	public static function register( $registry ) {
		if ( ! class_exists( 'MAD4B_SCP_Adapter_Base' ) || ! $registry instanceof MAD4B_SCP_Adapter_Registry ) return;
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return;
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return;
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return;
		$registry->register( new MAD4B_SCP_Acceptance_Target_Adapter() );
	}
}

final class MAD4B_SCP_Acceptance_Target_Adapter extends MAD4B_SCP_Adapter_Base {
	const CONTRACT = 'mad4b.acceptance-target.v1';
	const RESTORE_CONTRACT = 'mad4b.rollback.acceptance-target.v1';
	const STATUS_ABILITY = 'mad4b/acceptance-target-status';
	const PROVISION_ABILITY = 'mad4b/acceptance-target-provision';
	const META_BINDING = '_mad4b_acceptance_target_binding';
	const META_RECORD = '_mad4b_acceptance_target_v1';

	public function id() { return 'acceptance-target'; }
	public function label() { return 'Acceptance Target'; }
	public function is_available() { return ! is_wp_error( $this->current_binding() ); }
	protected function certified_provider_key() { return 'core'; }
	protected function mutation_requires_certification() { return false; }

	public function ability_names() {
		return array(
			'read' => array( self::STATUS_ABILITY ),
			'content' => array(),
			'admin' => array(),
			'write' => array( self::PROVISION_ABILITY ),
		);
	}

	public function reversible_contracts() {
		return array( self::PROVISION_ABILITY => self::RESTORE_CONTRACT );
	}

	public function register_abilities() {
		if ( ! wp_has_ability( self::STATUS_ABILITY ) ) {
			$this->add_ability(
				self::STATUS_ABILITY,
				'Acceptance Target Status',
				'target_status',
				array( 'MAD4B_SCP_Policy', 'can_read' ),
				null,
				'read',
				true,
				false,
				true
			);
		}

		if ( ! wp_has_ability( self::PROVISION_ABILITY ) ) {
			$this->add_ability(
				self::PROVISION_ABILITY,
				'Provision Acceptance Target',
				'provision',
				array( 'MAD4B_SCP_Policy', 'can_admin' ),
				$this->schema(
					array(
						'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
						'expected_profile_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
						'expected_build_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
					),
					array( 'expected_revision', 'expected_profile_digest', 'expected_source_commit_sha', 'expected_build_fingerprint' )
				),
				'write',
				false,
				true,
				true
			);
		}
	}

	public function target_status() {
		$binding = $this->current_binding();
		if ( is_wp_error( $binding ) ) return $binding;
		$state = $this->state_for_binding( $binding );
		if ( is_wp_error( $state ) ) return $state;
		$result = array(
			'contract' => self::CONTRACT,
			'environment' => 'staging',
			'candidate_sha' => $binding['candidate_sha'],
			'build_fingerprint' => $binding['build_fingerprint'],
			'site_profile_revision' => $binding['site_profile_revision'],
			'site_profile_digest' => $binding['site_profile_digest'],
			'binding_sha256' => $this->binding_hash( $binding ),
			'exists' => ! empty( $state['exists'] ),
			'safe_for_mutation_acceptance' => ! empty( $state['safe'] ),
			'isolated' => ! empty( $state['safe'] ),
		);
		if ( ! empty( $state['exists'] ) ) {
			$post = get_post( (int) $state['post_id'] );
			$result['post_id'] = (int) $state['post_id'];
			$result['post_type'] = (string) $state['post_type'];
			$result['post_status'] = (string) $state['post_status'];
			$result['post_title'] = (string) $state['post_title'];
			$result['post_excerpt'] = (string) $state['post_excerpt'];
			$result['modified_gmt'] = $post ? (string) $post->post_modified_gmt : '';
		}
		return $result;
	}

	public function provision( $input ) {
		$binding = $this->current_binding( is_array( $input ) ? $input : array(), true );
		if ( is_wp_error( $binding ) ) return $binding;
		$state = $this->state_for_binding( $binding );
		if ( is_wp_error( $state ) ) return $state;
		if ( ! empty( $state['exists'] ) ) {
			if ( empty( $state['safe'] ) ) return new WP_Error( 'mad4b_acceptance_target_state_unsafe', 'An exact-bound acceptance target exists but no longer satisfies the isolated target contract.' );
			return array( 'created' => false, 'idempotent' => true, 'post_id' => (int) $state['post_id'], 'binding_sha256' => $this->binding_hash( $binding ), 'safe_for_mutation_acceptance' => true );
		}
		if ( ! current_user_can( 'edit_posts' ) ) return new WP_Error( 'mad4b_acceptance_target_create_denied', 'Current user cannot create the isolated acceptance post.' );

		$binding_hash = $this->binding_hash( $binding );
		$marker = array(
			'contract' => self::CONTRACT,
			'binding_sha256' => $binding_hash,
			'candidate_sha' => $binding['candidate_sha'],
			'build_fingerprint' => $binding['build_fingerprint'],
			'site_profile_revision' => $binding['site_profile_revision'],
			'site_profile_digest' => $binding['site_profile_digest'],
			'site_uuid' => $binding['site_uuid'],
			'environment' => 'staging',
		);
		$marker_json = wp_json_encode( $marker, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $marker_json ) return new WP_Error( 'mad4b_acceptance_target_marker_encode_failed', 'Acceptance target marker could not be encoded.' );
		$post_id = wp_insert_post( wp_slash( array(
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => $this->fixed_title( $binding ),
			'post_content' => '',
			'post_excerpt' => '',
			'meta_input' => array(
				self::META_BINDING => $binding_hash,
				self::META_RECORD => $marker_json,
			),
		) ), true );
		if ( is_wp_error( $post_id ) ) return $post_id;

		$after = $this->state_for_binding( $binding );
		if ( is_wp_error( $after ) || empty( $after['exists'] ) || empty( $after['safe'] ) || (int) $after['post_id'] !== (int) $post_id ) {
			wp_delete_post( (int) $post_id, true );
			return new WP_Error( 'mad4b_acceptance_target_verification_failed', 'Acceptance target creation failed exact read-after-write verification.' );
		}
		MAD4B_SCP_Audit::record( self::PROVISION_ABILITY, array( 'post_id' => (int) $post_id, 'binding_sha256' => $binding_hash, 'candidate_sha' => $binding['candidate_sha'], 'verified' => true ) );
		return array( 'created' => true, 'idempotent' => false, 'post_id' => (int) $post_id, 'binding_sha256' => $binding_hash, 'safe_for_mutation_acceptance' => true );
	}

	public function capture_reversible_state( $ability_name, array $input ) {
		if ( self::PROVISION_ABILITY !== (string) $ability_name ) return parent::capture_reversible_state( $ability_name, $input );
		$binding = $this->current_binding( $input, true );
		if ( is_wp_error( $binding ) ) return $binding;
		$state = $this->state_for_binding( $binding );
		if ( is_wp_error( $state ) ) return $state;
		if ( ! empty( $state['exists'] ) && empty( $state['safe'] ) ) return new WP_Error( 'mad4b_acceptance_target_state_unsafe', 'Existing exact-bound target is not safe for governed acceptance.' );
		return array(
			'target_type' => 'acceptance_target',
			'target_id' => $this->binding_hash( $binding ),
			'target' => $binding,
			'state' => $state,
		);
	}

	public function read_reversible_state( $ability_name, array $target ) {
		if ( self::PROVISION_ABILITY !== (string) $ability_name ) return parent::read_reversible_state( $ability_name, $target );
		$binding = $this->validate_recorded_binding( $target );
		if ( is_wp_error( $binding ) ) return $binding;
		return $this->state_for_binding( $binding );
	}

	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		if ( self::PROVISION_ABILITY !== (string) $ability_name ) return parent::restore_reversible_state( $ability_name, $target, $state, $record );
		$binding = $this->validate_recorded_binding( $target );
		if ( is_wp_error( $binding ) ) return $binding;
		$current = $this->state_for_binding( $binding );
		if ( is_wp_error( $current ) ) return $current;

		if ( empty( $state['exists'] ) ) {
			if ( empty( $current['exists'] ) ) return array( 'restored' => true, 'deleted' => false, 'already_absent' => true );
			$post_id = isset( $current['post_id'] ) ? absint( $current['post_id'] ) : 0;
			if ( $post_id < 1 || ! current_user_can( 'delete_post', $post_id ) ) return new WP_Error( 'mad4b_acceptance_target_delete_denied', 'Current user cannot remove the isolated acceptance target.' );
			$deleted = wp_delete_post( $post_id, true );
			if ( ! $deleted ) return new WP_Error( 'mad4b_acceptance_target_delete_failed', 'Unable to remove the isolated acceptance target.' );
			$readback = $this->state_for_binding( $binding );
			if ( is_wp_error( $readback ) || ! empty( $readback['exists'] ) ) return new WP_Error( 'mad4b_acceptance_target_delete_verification_failed', 'Acceptance target cleanup did not verify as absent.' );
			return array( 'restored' => true, 'deleted' => true, 'post_id' => $post_id );
		}

		if ( $this->state_hash( $current ) !== $this->state_hash( $state ) ) return new WP_Error( 'mad4b_acceptance_target_restore_drift', 'Existing acceptance target differs from its recorded pre-state.' );
		return array( 'restored' => true, 'deleted' => false, 'no_change_required' => true );
	}

	private function current_binding( array $expected = array(), $assert_expected = false ) {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return new WP_Error( 'mad4b_acceptance_target_profile_missing', 'An enrolled Site Profile is required.' );
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return new WP_Error( 'mad4b_acceptance_target_staging_only', 'Acceptance target provisioning is Staging-only.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_acceptance_target_profile_not_exact', 'Current origin and URLs must exactly match the enrolled Site Profile.' );
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_acceptance_target_provenance_unavailable', 'Build provenance authority is unavailable.' );
		$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( ! is_array( $provenance ) || empty( $provenance['manifest_present'] ) || empty( $provenance['manifest_valid'] ) || empty( $provenance['runtime_manifest_match'] ) || ! empty( $provenance['stale'] ) || ! empty( $provenance['provenance_mismatch'] ) ) return new WP_Error( 'mad4b_acceptance_target_provenance_not_ready', 'Exact current build provenance is not ready.' );
		$binding = array(
			'candidate_sha' => strtolower( isset( $provenance['source_commit_sha'] ) ? (string) $provenance['source_commit_sha'] : '' ),
			'build_fingerprint' => strtolower( isset( $provenance['build_fingerprint'] ) ? (string) $provenance['build_fingerprint'] : '' ),
			'site_profile_revision' => (int) MAD4B_SCP_Site_Profile::revision(),
			'site_profile_digest' => strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() ),
			'site_uuid' => (string) MAD4B_SCP_Site_Profile::site_uuid(),
			'environment' => 'staging',
			'origin' => (string) MAD4B_SCP_Site_Profile::current_origin(),
		);
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $binding['candidate_sha'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $binding['build_fingerprint'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $binding['site_profile_digest'] ) || $binding['site_profile_revision'] < 1 ) return new WP_Error( 'mad4b_acceptance_target_binding_invalid', 'Current candidate/profile binding is invalid.' );
		if ( ! $assert_expected ) return $binding;

		foreach ( array( 'expected_revision', 'expected_profile_digest', 'expected_source_commit_sha', 'expected_build_fingerprint' ) as $key ) {
			if ( ! array_key_exists( $key, $expected ) ) return new WP_Error( 'mad4b_acceptance_target_binding_required', 'Exact revision, profile digest and build binding are required.' );
		}
		if ( (int) $expected['expected_revision'] !== $binding['site_profile_revision'] ) return new WP_Error( 'mad4b_acceptance_target_revision_stale', 'Site Profile revision changed before target provisioning.' );
		if ( ! hash_equals( $binding['site_profile_digest'], strtolower( trim( (string) $expected['expected_profile_digest'] ) ) ) ) return new WP_Error( 'mad4b_acceptance_target_digest_stale', 'Site Profile digest changed before target provisioning.' );
		if ( ! hash_equals( $binding['candidate_sha'], strtolower( trim( (string) $expected['expected_source_commit_sha'] ) ) ) ) return new WP_Error( 'mad4b_acceptance_target_candidate_mismatch', 'Source commit does not match the exact requested candidate.' );
		if ( ! hash_equals( $binding['build_fingerprint'], strtolower( trim( (string) $expected['expected_build_fingerprint'] ) ) ) ) return new WP_Error( 'mad4b_acceptance_target_fingerprint_mismatch', 'Build fingerprint does not match the exact requested candidate.' );
		return $binding;
	}

	private function validate_recorded_binding( array $binding ) {
		$current = $this->current_binding();
		if ( is_wp_error( $current ) ) return $current;
		if ( ! hash_equals( $this->binding_hash( $current ), $this->binding_hash( $binding ) ) ) return new WP_Error( 'mad4b_acceptance_target_recorded_binding_stale', 'Recorded acceptance target binding no longer matches the live candidate/profile.' );
		return $current;
	}

	private function state_for_binding( array $binding ) {
		$binding_hash = $this->binding_hash( $binding );
		$ids = get_posts( array(
			'post_type' => 'post',
			'post_status' => array( 'draft', 'pending', 'private', 'publish', 'trash' ),
			'numberposts' => 3,
			'fields' => 'ids',
			'orderby' => 'ID',
			'order' => 'ASC',
			'suppress_filters' => true,
			'meta_key' => self::META_BINDING,
			'meta_value' => $binding_hash,
		) );
		$ids = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
		if ( count( $ids ) > 1 ) return new WP_Error( 'mad4b_acceptance_target_duplicate', 'More than one acceptance target exists for the exact candidate binding.' );
		if ( empty( $ids ) ) return array( 'exists' => false, 'binding_sha256' => $binding_hash, 'safe' => true );

		$post = get_post( $ids[0] );
		if ( ! $post ) return array( 'exists' => false, 'binding_sha256' => $binding_hash, 'safe' => true );
		$marker_raw = (string) get_post_meta( $post->ID, self::META_RECORD, true );
		$marker = json_decode( $marker_raw, true );
		$marker_ok = is_array( $marker )
			&& isset( $marker['contract'], $marker['binding_sha256'] )
			&& self::CONTRACT === (string) $marker['contract']
			&& hash_equals( $binding_hash, (string) $marker['binding_sha256'] );
		$safe = $marker_ok
			&& 'post' === (string) $post->post_type
			&& 'draft' === (string) $post->post_status
			&& $this->fixed_title( $binding ) === (string) $post->post_title
			&& '' === (string) $post->post_content
			&& 0 === (int) $post->post_parent;
		return array(
			'exists' => true,
			'binding_sha256' => $binding_hash,
			'post_id' => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'post_status' => (string) $post->post_status,
			'post_title' => (string) $post->post_title,
			'post_content' => (string) $post->post_content,
			'post_excerpt' => (string) $post->post_excerpt,
			'post_parent' => (int) $post->post_parent,
			'marker_sha256' => hash( 'sha256', $marker_raw ),
			'safe' => $safe,
		);
	}

	private function fixed_title( array $binding ) { return 'MAD4B Mutation Acceptance Scratch — ' . substr( (string) $binding['candidate_sha'], 0, 8 ); }
	private function binding_hash( array $binding ) { return hash( 'sha256', wp_json_encode( $binding, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); }
	private function state_hash( array $state ) { return hash( 'sha256', wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); }
}

MAD4B_SCP_Acceptance_Target_Adapter_Bootstrap::boot();