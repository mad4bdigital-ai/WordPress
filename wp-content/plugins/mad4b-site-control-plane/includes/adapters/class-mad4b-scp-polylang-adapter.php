<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Polylang_Adapter extends MAD4B_SCP_Adapter_Base {
	public function id() { return 'polylang'; }
	public function label() { return 'Polylang'; }
	public function is_available() { return function_exists( 'pll_get_post_language' ) && function_exists( 'pll_set_post_language' ); }
	public function ability_names() { return array( 'read' => array( 'polylang/status', 'polylang/list-languages', 'polylang/get-post-translations' ), 'content' => array( 'polylang/set-post-language' ), 'admin' => array() ); }
	public function reversible_contracts() { return array( 'polylang/set-post-language' => 'mad4b.rollback.polylang-post-language.v1' ); }
	protected function detect_plugin_version() { return defined( 'POLYLANG_VERSION' ) ? POLYLANG_VERSION : ''; }
	public function status() { $status = parent::status(); $status['external_priority'] = true; $status['restore_requires_existing_language'] = true; return $status; }
	public function register_abilities() {
		$this->add_ability( 'polylang/status', 'Get Polylang Status', 'status', array( 'MAD4B_SCP_Policy', 'can_read' ) );
		$this->add_ability( 'polylang/list-languages', 'List Polylang Languages', 'list_languages', array( 'MAD4B_SCP_Policy', 'can_read' ) );
		$schema = $this->schema( array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'post_id' ) );
		$this->add_ability( 'polylang/get-post-translations', 'Get Polylang Post Translations', 'get_translations', array( $this, 'can_read_post' ), $schema );
		$this->add_ability( 'polylang/set-post-language', 'Set Polylang Post Language', 'set_language', array( $this, 'can_edit_post' ), $this->schema( array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'language' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 20 ), 'expected_language' => array( 'type' => 'string', 'maxLength' => 20 )
		), array( 'post_id', 'language', 'expected_language' ) ), 'content', false, false, true );
	}
	public function can_read_post( $input ) { $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'read_post', $id ); }
	public function can_edit_post( $input ) { $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'edit_post', $id ); }
	public function list_languages() {
		if ( ! function_exists( 'pll_languages_list' ) ) return $this->unavailable_error();
		return array( 'slugs' => pll_languages_list(), 'names' => pll_languages_list( array( 'fields' => 'name' ) ), 'locales' => pll_languages_list( array( 'fields' => 'locale' ) ) );
	}
	public function get_translations( $input ) {
		if ( ! $this->is_available() ) return $this->unavailable_error();
		$id = absint( $input['post_id'] );
		return array( 'post_id' => $id, 'language' => (string) pll_get_post_language( $id ), 'translations' => function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $id ) : array() );
	}
	public function set_language( $input ) {
		if ( ! $this->is_available() || ! function_exists( 'pll_languages_list' ) ) return $this->unavailable_error();
		$id = absint( $input['post_id'] );
		$current = (string) pll_get_post_language( $id );
		$expected = sanitize_key( (string) $input['expected_language'] );
		if ( $current !== $expected ) return new WP_Error( 'mad4b_polylang_stale_language', 'Post language changed since it was read.', array( 'current_language' => $current ) );
		$language = sanitize_key( $input['language'] );
		$available = pll_languages_list();
		if ( ! in_array( $language, $available, true ) ) return new WP_Error( 'mad4b_polylang_invalid_language', 'Language is not configured in Polylang.' );
		pll_set_post_language( $id, $language );
		$after = (string) pll_get_post_language( $id );
		if ( $after !== $language ) return new WP_Error( 'mad4b_polylang_update_failed', 'Polylang did not persist the requested language.' );
		MAD4B_SCP_Audit::record( 'polylang/set-post-language', array( 'post_id' => $id, 'before_language' => $current, 'language' => $after ) );
		return array( 'post_id' => $id, 'language' => $after, 'updated' => true );
	}
	public function capture_reversible_state( $ability_name, array $input ) {
		if ( 'polylang/set-post-language' !== $ability_name ) return parent::capture_reversible_state( $ability_name, $input );
		if ( ! $this->is_available() ) return $this->unavailable_error();
		$id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$current = (string) pll_get_post_language( $id );
		$expected = isset( $input['expected_language'] ) ? sanitize_key( (string) $input['expected_language'] ) : '';
		if ( $current !== $expected ) return new WP_Error( 'mad4b_polylang_stale_language', 'Post language changed since it was read.', array( 'current_language' => $current ) );
		if ( '' === $current ) return new WP_Error( 'mad4b_polylang_unassigned_not_reversible', 'Assigning a previously unassigned post is denied until a provider-native unassignment restore contract is certified.' );
		return array( 'target_type' => 'polylang-post-language', 'target_id' => (string) $id, 'target' => array( 'post_id' => $id ), 'state' => array( 'language' => $current ) );
	}
	public function read_reversible_state( $ability_name, array $target ) {
		if ( 'polylang/set-post-language' !== $ability_name ) return parent::read_reversible_state( $ability_name, $target );
		if ( ! $this->is_available() ) return $this->unavailable_error();
		$id = isset( $target['post_id'] ) ? absint( $target['post_id'] ) : 0;
		if ( ! $id || ! get_post( $id ) ) return new WP_Error( 'mad4b_post_missing', 'Post not found for Polylang readback.' );
		return array( 'language' => (string) pll_get_post_language( $id ) );
	}
	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		if ( 'polylang/set-post-language' !== $ability_name ) return parent::restore_reversible_state( $ability_name, $target, $state, $record );
		if ( ! $this->is_available() || ! function_exists( 'pll_languages_list' ) ) return $this->unavailable_error();
		$id = isset( $target['post_id'] ) ? absint( $target['post_id'] ) : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) return new WP_Error( 'mad4b_polylang_restore_denied', 'Current user cannot restore this post language.' );
		$language = isset( $state['language'] ) ? sanitize_key( (string) $state['language'] ) : '';
		if ( '' === $language || ! in_array( $language, pll_languages_list(), true ) ) return new WP_Error( 'mad4b_polylang_restore_language_missing', 'Recorded language is no longer configured; automatic restore is denied.' );
		pll_set_post_language( $id, $language );
		return $language === (string) pll_get_post_language( $id ) ? true : new WP_Error( 'mad4b_polylang_restore_failed', 'Polylang did not restore the recorded language.' );
	}
}
