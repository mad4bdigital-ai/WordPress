<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_SEO_Adapter extends MAD4B_SCP_Adapter_Base {
	private $rank_math_fields = array( 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'focus_keyword' => 'rank_math_focus_keyword', 'robots' => 'rank_math_robots', 'canonical_url' => 'rank_math_canonical_url', 'facebook_title' => 'rank_math_facebook_title', 'facebook_description' => 'rank_math_facebook_description', 'twitter_title' => 'rank_math_twitter_title', 'twitter_description' => 'rank_math_twitter_description' );
	public function id() { return 'seo'; }
	public function label() { return 'SEO'; }
	public function is_available() { return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) || defined( 'WPSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ); }
	public function ability_names() { return array( 'read' => array( 'seo/status', 'seo/get-meta', 'seo/validate' ), 'content' => array( 'seo/update-meta' ), 'admin' => array() ); }
	public function reversible_contracts() { return 'rank-math' === $this->provider() ? array( 'seo/update-meta' => 'mad4b.rollback.rank-math-meta.v1' ) : array(); }
	protected function detect_plugin_version() { if ( defined( 'RANK_MATH_VERSION' ) ) return RANK_MATH_VERSION; if ( defined( 'WPSEO_VERSION' ) ) return WPSEO_VERSION; if ( defined( 'SEOPRESS_VERSION' ) ) return SEOPRESS_VERSION; return ''; }
	public function register_abilities() {
		$this->add_ability( 'seo/status', 'Get SEO Provider Status', 'status', array( 'MAD4B_SCP_Policy', 'can_read' ) );
		$post_schema = $this->schema( array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'post_id' ) );
		$this->add_ability( 'seo/get-meta', 'Get SEO Metadata', 'get_meta', array( $this, 'can_read_post' ), $post_schema );
		$this->add_ability( 'seo/validate', 'Validate SEO Metadata', 'validate_meta', array( $this, 'can_read_post' ), $post_schema );
		$this->add_ability( 'seo/update-meta', 'Update SEO Metadata', 'update_meta', array( $this, 'can_edit_post' ), $this->schema( array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'fields' => array( 'type' => 'object' ),
			'expected_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
		), array( 'post_id', 'fields', 'expected_sha256' ) ), 'content', false, true, true );
	}
	public function status() { $status = parent::status(); $status['provider'] = $this->provider(); $status['write_provider'] = 'rank-math' === $this->provider() ? 'rank-math' : ''; $status['write_scope'] = 'allowlisted_rank_math_post_meta_only'; return $status; }
	public function can_read_post( $input ) { $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'read_post', $id ); }
	public function can_edit_post( $input ) { $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'edit_post', $id ); }
	public function get_meta( $input ) {
		if ( 'rank-math' !== $this->provider() ) return new WP_Error( 'mad4b_seo_provider_readonly', 'This adapter currently exposes governed metadata for Rank Math only.' );
		$id = absint( $input['post_id'] );
		$fields = array();
		foreach ( $this->rank_math_fields as $name => $key ) $fields[ $name ] = get_post_meta( $id, $key, true );
		return array( 'post_id' => $id, 'provider' => 'rank-math', 'fields' => $fields, 'sha256' => $this->hash_value( $fields ) );
	}
	public function update_meta( $input ) {
		if ( 'rank-math' !== $this->provider() ) return new WP_Error( 'mad4b_seo_provider_readonly', 'Governed SEO writes are currently implemented for Rank Math only.' );
		$id = absint( $input['post_id'] );
		$current = $this->get_meta( array( 'post_id' => $id ) );
		if ( is_wp_error( $current ) ) return $current;
		if ( ! hash_equals( $current['sha256'], strtolower( trim( $input['expected_sha256'] ) ) ) ) return new WP_Error( 'mad4b_seo_stale_meta', 'SEO metadata changed since it was read.', array( 'current_sha256' => $current['sha256'] ) );
		if ( empty( $input['fields'] ) || ! is_array( $input['fields'] ) ) return new WP_Error( 'mad4b_seo_empty_fields', 'fields must be a non-empty object.' );

		$allowed_robots = array( 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );
		$sanitized = array();
		foreach ( $input['fields'] as $name => $value ) {
			if ( ! isset( $this->rank_math_fields[ $name ] ) ) return new WP_Error( 'mad4b_seo_field_denied', 'Unsupported SEO field: ' . sanitize_key( $name ) );
			if ( 'robots' === $name ) {
				if ( ! is_array( $value ) ) return new WP_Error( 'mad4b_seo_robots_invalid', 'robots must be an array.' );
				$value = array_values( array_unique( array_map( 'sanitize_key', $value ) ) );
				foreach ( $value as $directive ) if ( ! in_array( $directive, $allowed_robots, true ) ) return new WP_Error( 'mad4b_seo_robot_denied', 'Unsupported robots directive: ' . $directive );
			} elseif ( 'canonical_url' === $name ) {
				$value = esc_url_raw( (string) $value, array( 'http', 'https' ) );
				if ( '' !== trim( (string) $input['fields'][ $name ] ) && '' === $value ) return new WP_Error( 'mad4b_seo_canonical_invalid', 'canonical_url must be a valid HTTP(S) URL.' );
			} else {
				$value = sanitize_text_field( (string) $value );
			}
			$sanitized[ $name ] = $value;
		}

		foreach ( $sanitized as $name => $value ) update_post_meta( $id, $this->rank_math_fields[ $name ], $value );
		$after = $this->get_meta( array( 'post_id' => $id ) );
		MAD4B_SCP_Audit::record( 'seo/update-meta', array( 'post_id' => $id, 'provider' => 'rank-math', 'fields' => implode( ',', array_keys( $sanitized ) ), 'before_sha256' => $current['sha256'], 'after_sha256' => is_wp_error( $after ) ? '' : $after['sha256'] ) );
		return $after;
	}
	public function validate_meta( $input ) {
		$data = $this->get_meta( $input ); if ( is_wp_error( $data ) ) return $data;
		$title = wp_strip_all_tags( (string) $data['fields']['title'] );
		$description = wp_strip_all_tags( (string) $data['fields']['description'] );
		$robots = $data['fields']['robots'];
		return array( 'post_id' => absint( $input['post_id'] ), 'provider' => 'rank-math', 'checks' => array( 'title_length' => $this->text_length( $title ), 'description_length' => $this->text_length( $description ), 'has_focus_keyword' => '' !== trim( (string) $data['fields']['focus_keyword'] ), 'has_canonical' => '' !== trim( (string) $data['fields']['canonical_url'] ), 'noindex' => is_array( $robots ) && in_array( 'noindex', $robots, true ) ) );
	}
	public function capture_reversible_state( $ability_name, array $input ) {
		if ( 'seo/update-meta' !== $ability_name ) return parent::capture_reversible_state( $ability_name, $input );
		if ( 'rank-math' !== $this->provider() ) return new WP_Error( 'mad4b_seo_provider_readonly', 'Rank Math is required for the certified SEO rollback contract.' );
		$id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$current = $this->get_meta( array( 'post_id' => $id ) );
		if ( is_wp_error( $current ) ) return $current;
		$expected = isset( $input['expected_sha256'] ) ? strtolower( trim( (string) $input['expected_sha256'] ) ) : '';
		if ( '' === $expected || ! hash_equals( $current['sha256'], $expected ) ) return new WP_Error( 'mad4b_seo_stale_meta', 'SEO metadata changed since it was read.', array( 'current_sha256' => $current['sha256'] ) );
		return array( 'target_type' => 'rank-math-post-meta', 'target_id' => (string) $id, 'target' => array( 'post_id' => $id ), 'state' => $this->meta_restore_state( $id ) );
	}
	public function read_reversible_state( $ability_name, array $target ) {
		if ( 'seo/update-meta' !== $ability_name ) return parent::read_reversible_state( $ability_name, $target );
		if ( 'rank-math' !== $this->provider() ) return new WP_Error( 'mad4b_seo_provider_readonly', 'Rank Math is required for SEO rollback readback.' );
		$id = isset( $target['post_id'] ) ? absint( $target['post_id'] ) : 0;
		if ( ! $id || ! get_post( $id ) ) return new WP_Error( 'mad4b_post_missing', 'Post not found for Rank Math readback.' );
		return $this->meta_restore_state( $id );
	}
	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		if ( 'seo/update-meta' !== $ability_name ) return parent::restore_reversible_state( $ability_name, $target, $state, $record );
		if ( 'rank-math' !== $this->provider() ) return new WP_Error( 'mad4b_seo_provider_readonly', 'Rank Math is required for SEO rollback restore.' );
		$id = isset( $target['post_id'] ) ? absint( $target['post_id'] ) : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) return new WP_Error( 'mad4b_seo_restore_denied', 'Current user cannot restore SEO metadata for this post.' );
		foreach ( $this->rank_math_fields as $name => $key ) {
			if ( ! isset( $state[ $name ] ) || ! is_array( $state[ $name ] ) || ! array_key_exists( 'exists', $state[ $name ] ) || ! array_key_exists( 'value', $state[ $name ] ) ) return new WP_Error( 'mad4b_seo_restore_payload_invalid', 'Rank Math rollback state is incomplete.' );
			if ( $state[ $name ]['exists'] ) update_post_meta( $id, $key, $state[ $name ]['value'] ); else delete_post_meta( $id, $key );
		}
		return true;
	}
	private function meta_restore_state( $id ) {
		$state = array();
		foreach ( $this->rank_math_fields as $name => $key ) $state[ $name ] = array( 'exists' => metadata_exists( 'post', $id, $key ), 'value' => get_post_meta( $id, $key, true ) );
		return $state;
	}
	private function text_length( $value ) { return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value ); }
	private function provider() { if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) return 'rank-math'; if ( defined( 'WPSEO_VERSION' ) ) return 'yoast'; if ( defined( 'SEOPRESS_VERSION' ) ) return 'seopress'; return ''; }
}
