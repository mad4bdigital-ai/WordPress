<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_SEO_Adapter extends MAD4B_SCP_Adapter_Base {
	private $rank_math_fields = array( 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'focus_keyword' => 'rank_math_focus_keyword', 'robots' => 'rank_math_robots', 'canonical_url' => 'rank_math_canonical_url', 'facebook_title' => 'rank_math_facebook_title', 'facebook_description' => 'rank_math_facebook_description', 'twitter_title' => 'rank_math_twitter_title', 'twitter_description' => 'rank_math_twitter_description' );
	public function id() { return 'seo'; }
	public function label() { return 'SEO'; }
	public function is_available() { return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) || defined( 'WPSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ); }
	public function ability_names() { return array( 'read' => array( 'seo/status', 'seo/get-meta', 'seo/validate' ), 'content' => array( 'seo/update-meta' ), 'admin' => array() ); }
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
		), array( 'post_id', 'fields', 'expected_sha256' ) ), 'content', false, false, true );
	}
	public function status() { $status = parent::status(); $status['provider'] = $this->provider(); $status['write_provider'] = 'rank-math' === $this->provider() ? 'rank-math' : ''; return $status; }
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
		$title = (string) $data['fields']['title']; $description = (string) $data['fields']['description']; $robots = $data['fields']['robots'];
		return array( 'post_id' => absint( $input['post_id'] ), 'provider' => 'rank-math', 'checks' => array( 'title_length' => strlen( wp_strip_all_tags( $title ) ), 'description_length' => strlen( wp_strip_all_tags( $description ) ), 'has_focus_keyword' => '' !== trim( (string) $data['fields']['focus_keyword'] ), 'has_canonical' => '' !== trim( (string) $data['fields']['canonical_url'] ), 'noindex' => is_array( $robots ) && in_array( 'noindex', $robots, true ) ) );
	}
	private function provider() { if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) return 'rank-math'; if ( defined( 'WPSEO_VERSION' ) ) return 'yoast'; if ( defined( 'SEOPRESS_VERSION' ) ) return 'seopress'; return ''; }
}
