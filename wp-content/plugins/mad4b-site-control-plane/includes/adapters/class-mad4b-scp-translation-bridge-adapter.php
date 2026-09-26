<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed translation relationship bridge for Polylang and WPML.
 * Text generation is intentionally out of scope: translated fields are created
 * through governed content abilities, then exact-bound translation relationships
 * are applied here.
 */
final class MAD4B_SCP_Translation_Bridge_Adapter extends MAD4B_SCP_Adapter_Base {
	const CONTRACT = 'mad4b.translation-bridge.v1';
	private static $hooked = false;

	public static function boot() {
		if ( self::$hooked ) return;
		self::$hooked = true;
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_with_registry' ), 6, 1 );
	}

	public static function register_with_registry( $registry ) {
		if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) $registry->register( new self() );
	}

	public function id() { return 'translation-bridge'; }
	public function label() { return 'Translation Bridge'; }
	public function is_available() { return $this->polylang_available() || $this->wpml_available(); }
	protected function certified_provider_key() { return 'translation'; }
	protected function provider_certification( $available ) { return null; }
	protected function mutation_requires_certification() { return false; }

	public function ability_names() {
		return array(
			'read' => array( 'mad4b/translation-status', 'mad4b/translation-list-languages', 'mad4b/translation-get-post' ),
			'content' => array( 'mad4b/translation-set-post-language', 'mad4b/translation-link-posts' ),
			'admin' => array(),
			'write' => array(),
		);
	}

	public function reversible_contracts() {
		return array(
			'mad4b/translation-set-post-language' => 'mad4b.rollback.translation-post-language.v1',
			'mad4b/translation-link-posts' => 'mad4b.rollback.translation-post-link.v1',
		);
	}

	public function register_abilities() {
		$this->add_ability( 'mad4b/translation-status', 'Translation Provider Status', 'translation_status', array( 'MAD4B_SCP_Policy', 'can_read' ) );
		$this->add_ability( 'mad4b/translation-list-languages', 'List Translation Languages', 'translation_list_languages', array( 'MAD4B_SCP_Policy', 'can_read' ), $this->schema( array( 'provider' => $this->provider_schema() ) ) );
		$this->add_ability( 'mad4b/translation-get-post', 'Get Post Translation Context', 'translation_get_post', array( $this, 'can_read_post' ), $this->schema( array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'provider' => $this->provider_schema(),
		), array( 'post_id' ) ) );

		$this->add_ability( 'mad4b/translation-set-post-language', 'Set Post Translation Language', 'translation_set_post_language', array( $this, 'can_edit_post' ), $this->schema( array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'provider' => $this->provider_schema(),
			'language' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 20 ),
			'expected_language' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ),
			'expected_group' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 128 ),
		), array( 'post_id', 'language', 'expected_language', 'expected_group' ) ), 'content', false, true, true );

		$this->add_ability( 'mad4b/translation-link-posts', 'Link Translated Posts', 'translation_link_posts', array( $this, 'can_link_posts' ), $this->schema( array(
			'provider' => $this->provider_schema(),
			'source_post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'target_post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'target_language' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 20 ),
			'expected_source_language' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ),
			'expected_target_language' => array( 'type' => 'string', 'maxLength' => 20 ),
			'expected_source_group' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 128 ),
			'expected_target_group' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 128 ),
			'allow_relink_existing_group' => array( 'type' => 'boolean', 'default' => false ),
		), array( 'source_post_id', 'target_post_id', 'target_language', 'expected_source_language', 'expected_target_language', 'expected_source_group', 'expected_target_group' ) ), 'content', false, true, true );
	}

	private function provider_schema() { return array( 'type' => 'string', 'enum' => array( 'auto', 'wpml', 'polylang' ), 'default' => 'auto' ); }
	private function polylang_available() { return function_exists( 'pll_get_post_language' ) && function_exists( 'pll_set_post_language' ); }
	private function wpml_available() { return has_filter( 'wpml_active_languages' ) || defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' ); }

	private function provider( $requested = 'auto' ) {
		$requested = sanitize_key( (string) $requested );
		if ( 'wpml' === $requested ) return $this->wpml_available() ? 'wpml' : new WP_Error( 'mad4b_wpml_unavailable', 'WPML translation provider is not available.' );
		if ( 'polylang' === $requested ) return $this->polylang_available() ? 'polylang' : new WP_Error( 'mad4b_polylang_unavailable', 'Polylang translation provider is not available.' );
		if ( $this->wpml_available() ) return 'wpml';
		if ( $this->polylang_available() ) return 'polylang';
		return new WP_Error( 'mad4b_translation_provider_unavailable', 'No supported translation provider is active.' );
	}

	public function can_read_post( $input ) { $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'read_post', $id ); }
	public function can_edit_post( $input ) { $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'edit_post', $id ); }
	public function can_link_posts( $input ) {
		$source = isset( $input['source_post_id'] ) ? absint( $input['source_post_id'] ) : 0;
		$target = isset( $input['target_post_id'] ) ? absint( $input['target_post_id'] ) : 0;
		return $source > 0 && $target > 0 && current_user_can( 'edit_post', $source ) && current_user_can( 'edit_post', $target );
	}

	public function translation_status() {
		return array(
			'contract' => self::CONTRACT,
			'available' => $this->is_available(),
			'wpml' => $this->wpml_available(),
			'polylang' => $this->polylang_available(),
			'preferred_provider' => $this->wpml_available() ? 'wpml' : ( $this->polylang_available() ? 'polylang' : '' ),
			'text_generation_in_scope' => false,
			'exact_group_guards' => true,
		);
	}

	public function translation_list_languages( $input = array() ) {
		$provider = $this->provider( isset( $input['provider'] ) ? $input['provider'] : 'auto' );
		if ( is_wp_error( $provider ) ) return $provider;
		if ( 'polylang' === $provider ) {
			$slugs = function_exists( 'pll_languages_list' ) ? pll_languages_list() : array();
			$names = function_exists( 'pll_languages_list' ) ? pll_languages_list( array( 'fields' => 'name' ) ) : array();
			$locales = function_exists( 'pll_languages_list' ) ? pll_languages_list( array( 'fields' => 'locale' ) ) : array();
			$items = array();
			foreach ( $slugs as $i => $slug ) $items[] = array( 'code' => (string) $slug, 'name' => isset( $names[ $i ] ) ? (string) $names[ $i ] : (string) $slug, 'locale' => isset( $locales[ $i ] ) ? (string) $locales[ $i ] : '' );
			return array( 'provider' => 'polylang', 'languages' => $items, 'count' => count( $items ) );
		}
		$languages = apply_filters( 'wpml_active_languages', null, 'skip_missing=0&orderby=code' );
		$items = array();
		foreach ( is_array( $languages ) ? $languages : array() as $code => $lang ) {
			$items[] = array(
				'code' => (string) $code,
				'name' => isset( $lang['native_name'] ) ? (string) $lang['native_name'] : ( isset( $lang['translated_name'] ) ? (string) $lang['translated_name'] : (string) $code ),
				'locale' => isset( $lang['default_locale'] ) ? (string) $lang['default_locale'] : '',
				'active' => ! empty( $lang['active'] ),
			);
		}
		return array( 'provider' => 'wpml', 'languages' => $items, 'count' => count( $items ) );
	}

	private function polylang_group_identity( $post_id, array $translations ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', array_values( $translations ) ) ) ) );
		if ( empty( $ids ) && $post_id > 0 ) $ids[] = (int) $post_id;
		sort( $ids, SORT_NUMERIC );
		return hash( 'sha256', wp_json_encode( $ids ) );
	}

	private function polylang_context( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) return new WP_Error( 'mad4b_post_missing', 'Post not found.' );
		$language = (string) pll_get_post_language( $post_id );
		$translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $post_id ) : array();
		if ( ! is_array( $translations ) ) $translations = array();
		ksort( $translations, SORT_STRING );
		return array(
			'provider' => 'polylang',
			'post_id' => $post_id,
			'post_type' => $post->post_type,
			'language' => $language,
			'group' => $this->polylang_group_identity( $post_id, $translations ),
			'translations' => $translations,
		);
	}

	private function wpml_context( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) return new WP_Error( 'mad4b_post_missing', 'Post not found.' );
		$element_type = 'post_' . $post->post_type;
		$details = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $post_id, 'element_type' => $element_type ) );
		if ( ! is_object( $details ) ) return new WP_Error( 'mad4b_wpml_language_details_unavailable', 'WPML did not return language details for this post.' );
		$trid = isset( $details->trid ) ? (int) $details->trid : 0;
		$translations_raw = $trid ? apply_filters( 'wpml_get_element_translations', null, $trid, $element_type ) : array();
		$translations = array();
		foreach ( is_array( $translations_raw ) ? $translations_raw : array() as $code => $entry ) {
			if ( is_object( $entry ) && ! empty( $entry->element_id ) ) $translations[ (string) $code ] = (int) $entry->element_id;
		}
		ksort( $translations, SORT_STRING );
		return array(
			'provider' => 'wpml',
			'post_id' => $post_id,
			'post_type' => $post->post_type,
			'element_type' => $element_type,
			'language' => isset( $details->language_code ) ? (string) $details->language_code : '',
			'source_language' => isset( $details->source_language_code ) ? (string) $details->source_language_code : '',
			'trid' => $trid,
			'group' => (string) $trid,
			'translations' => $translations,
		);
	}

	private function context( $post_id, $provider ) { return 'polylang' === $provider ? $this->polylang_context( $post_id ) : $this->wpml_context( $post_id ); }
	public function translation_get_post( $input ) {
		$provider = $this->provider( isset( $input['provider'] ) ? $input['provider'] : 'auto' );
		if ( is_wp_error( $provider ) ) return $provider;
		return $this->context( absint( $input['post_id'] ), $provider );
	}

	private function ensure_language_available( $provider, $language ) {
		$language = sanitize_key( (string) $language );
		$list = $this->translation_list_languages( array( 'provider' => $provider ) );
		if ( is_wp_error( $list ) ) return $list;
		foreach ( $list['languages'] as $entry ) if ( $language === sanitize_key( $entry['code'] ) ) return $language;
		return new WP_Error( 'mad4b_translation_language_invalid', 'Requested language is not configured in the selected translation provider.' );
	}

	private function assert_post_expectations( array $state, $expected_language, $expected_group, $prefix = 'translation' ) {
		if ( (string) $state['language'] !== (string) $expected_language ) return new WP_Error( 'mad4b_' . $prefix . '_language_drift', 'Post language changed since planning.', array( 'current_language' => $state['language'] ) );
		if ( (string) $state['group'] !== (string) $expected_group ) return new WP_Error( 'mad4b_' . $prefix . '_group_drift', 'Translation group changed since planning.', array( 'current_group' => $state['group'] ) );
		return true;
	}

	private function validate_set_language_plan( array $input, $provider, array $before ) {
		$guard = $this->assert_post_expectations( $before, sanitize_key( (string) $input['expected_language'] ), (string) $input['expected_group'] );
		if ( is_wp_error( $guard ) ) return $guard;
		if ( 'polylang' === $provider && '' === (string) $before['language'] ) return new WP_Error( 'mad4b_translation_unassigned_not_reversible', 'Changing an unassigned Polylang post is denied because exact automatic rollback cannot restore the unassigned state.' );
		$language = $this->ensure_language_available( $provider, $input['language'] );
		if ( is_wp_error( $language ) ) return $language;
		if ( isset( $before['translations'][ $language ] ) && (int) $before['translations'][ $language ] !== (int) $before['post_id'] ) return new WP_Error( 'mad4b_translation_language_collision', 'The requested language is already occupied by another post in the same translation group.', array( 'existing_post_id' => (int) $before['translations'][ $language ] ) );
		return $language;
	}

	public function translation_set_post_language( $input ) {
		$provider = $this->provider( isset( $input['provider'] ) ? $input['provider'] : 'auto' );
		if ( is_wp_error( $provider ) ) return $provider;
		$id = absint( $input['post_id'] );
		$before = $this->context( $id, $provider );
		if ( is_wp_error( $before ) ) return $before;
		$language = $this->validate_set_language_plan( $input, $provider, $before );
		if ( is_wp_error( $language ) ) return $language;

		if ( 'polylang' === $provider ) {
			pll_set_post_language( $id, $language );
		} else {
			do_action( 'wpml_set_element_language_details', array(
				'element_id' => $id,
				'element_type' => $before['element_type'],
				'trid' => (int) $before['trid'],
				'language_code' => $language,
				'source_language_code' => '' !== (string) $before['source_language'] ? (string) $before['source_language'] : null,
			) );
		}

		$after = $this->context( $id, $provider );
		if ( is_wp_error( $after ) || (string) $after['language'] !== $language ) return new WP_Error( 'mad4b_translation_language_update_failed', 'Translation provider did not persist the requested post language.' );
		if ( (string) $after['group'] !== (string) $before['group'] ) return new WP_Error( 'mad4b_translation_group_changed_unexpectedly', 'Changing post language unexpectedly changed translation-group membership.' );
		return $after;
	}

	private function validate_link_plan( array $input, $provider, array $source, array $target ) {
		if ( $source['post_type'] !== $target['post_type'] ) return new WP_Error( 'mad4b_translation_post_type_mismatch', 'Source and target posts must use the same post type.' );
		$source_guard = $this->assert_post_expectations( $source, sanitize_key( (string) $input['expected_source_language'] ), (string) $input['expected_source_group'], 'translation_source' );
		if ( is_wp_error( $source_guard ) ) return $source_guard;
		$target_guard = $this->assert_post_expectations( $target, sanitize_key( (string) $input['expected_target_language'] ), (string) $input['expected_target_group'], 'translation_target' );
		if ( is_wp_error( $target_guard ) ) return $target_guard;
		if ( '' === (string) $source['language'] ) return new WP_Error( 'mad4b_translation_source_language_missing', 'Source post must already have a translation language.' );
		if ( 'polylang' === $provider && '' === (string) $target['language'] ) return new WP_Error( 'mad4b_translation_target_unassigned_not_reversible', 'Linking an unassigned Polylang target is denied because exact rollback cannot restore the unassigned state.' );

		$language = $this->ensure_language_available( $provider, $input['target_language'] );
		if ( is_wp_error( $language ) ) return $language;
		if ( $language === (string) $source['language'] ) return new WP_Error( 'mad4b_translation_duplicate_language_denied', 'Source and target translations cannot use the same language.' );
		if ( isset( $source['translations'][ $language ] ) && (int) $source['translations'][ $language ] !== (int) $target['post_id'] ) return new WP_Error( 'mad4b_translation_language_collision', 'Source translation group already contains another post for the requested target language.', array( 'existing_post_id' => (int) $source['translations'][ $language ] ) );

		$target_members = array_values( array_unique( array_filter( array_map( 'absint', array_values( (array) $target['translations'] ) ) ) ) );
		if ( (string) $source['group'] !== (string) $target['group'] && count( $target_members ) > 1 && empty( $input['allow_relink_existing_group'] ) ) {
			return new WP_Error( 'mad4b_translation_existing_group_relink_denied', 'Target belongs to an existing multi-post translation group. Explicit allow_relink_existing_group=true is required to move it.' );
		}
		return $language;
	}

	public function translation_link_posts( $input ) {
		$provider = $this->provider( isset( $input['provider'] ) ? $input['provider'] : 'auto' );
		if ( is_wp_error( $provider ) ) return $provider;
		$source_id = absint( $input['source_post_id'] );
		$target_id = absint( $input['target_post_id'] );
		if ( $source_id === $target_id ) return new WP_Error( 'mad4b_translation_self_link_denied', 'Source and target posts must differ.' );
		$source = $this->context( $source_id, $provider );
		$target = $this->context( $target_id, $provider );
		if ( is_wp_error( $source ) ) return $source;
		if ( is_wp_error( $target ) ) return $target;
		$language = $this->validate_link_plan( $input, $provider, $source, $target );
		if ( is_wp_error( $language ) ) return $language;

		if ( 'polylang' === $provider ) {
			if ( ! function_exists( 'pll_save_post_translations' ) ) return new WP_Error( 'mad4b_polylang_link_unavailable', 'Polylang translation-link API is unavailable.' );
			pll_set_post_language( $target_id, $language );
			$map = $source['translations'];
			$map[ $source['language'] ] = $source_id;
			$map[ $language ] = $target_id;
			pll_save_post_translations( $map );
		} else {
			if ( empty( $source['trid'] ) ) return new WP_Error( 'mad4b_wpml_source_group_missing', 'Source post has no WPML translation group.' );
			do_action( 'wpml_set_element_language_details', array(
				'element_id' => $target_id,
				'element_type' => $target['element_type'],
				'trid' => (int) $source['trid'],
				'language_code' => $language,
				'source_language_code' => (string) $source['language'],
			) );
		}

		$source_after = $this->context( $source_id, $provider );
		$target_after = $this->context( $target_id, $provider );
		if ( is_wp_error( $source_after ) ) return $source_after;
		if ( is_wp_error( $target_after ) ) return $target_after;
		if ( (string) $target_after['language'] !== $language ) return new WP_Error( 'mad4b_translation_link_failed', 'Translation provider did not persist the requested target language.' );
		if ( (string) $source_after['group'] !== (string) $target_after['group'] ) return new WP_Error( 'mad4b_translation_link_failed', 'Source and target posts did not converge to the same translation group.' );
		if ( empty( $source_after['translations'][ $language ] ) || (int) $source_after['translations'][ $language ] !== $target_id ) return new WP_Error( 'mad4b_translation_link_failed', 'Source translation group does not point to the requested target post.' );
		return array( 'provider' => $provider, 'source' => $source_after, 'target' => $target_after, 'linked' => true );
	}

	public function capture_reversible_state( $ability_name, array $input ) {
		if ( ! in_array( $ability_name, array( 'mad4b/translation-set-post-language','mad4b/translation-link-posts' ), true ) ) return parent::capture_reversible_state( $ability_name, $input );
		$provider = $this->provider( isset( $input['provider'] ) ? $input['provider'] : 'auto' );
		if ( is_wp_error( $provider ) ) return $provider;

		if ( 'mad4b/translation-set-post-language' === $ability_name ) {
			$id = absint( $input['post_id'] );
			$state = $this->context( $id, $provider );
			if ( is_wp_error( $state ) ) return $state;
			$guard = $this->validate_set_language_plan( $input, $provider, $state );
			if ( is_wp_error( $guard ) ) return $guard;
			return array( 'target_type' => 'translation-post', 'target_id' => $provider . ':' . $id, 'target' => array( 'provider' => $provider, 'post_id' => $id ), 'state' => $state );
		}

		$source_id = absint( $input['source_post_id'] );
		$target_id = absint( $input['target_post_id'] );
		$source = $this->context( $source_id, $provider );
		$target = $this->context( $target_id, $provider );
		if ( is_wp_error( $source ) ) return $source;
		if ( is_wp_error( $target ) ) return $target;
		$guard = $this->validate_link_plan( $input, $provider, $source, $target );
		if ( is_wp_error( $guard ) ) return $guard;
		return array(
			'target_type' => 'translation-link',
			'target_id' => $provider . ':' . $source_id . ':' . $target_id,
			'target' => array( 'provider' => $provider, 'source_post_id' => $source_id, 'target_post_id' => $target_id ),
			'state' => array( 'source' => $source, 'target' => $target ),
		);
	}

	public function read_reversible_state( $ability_name, array $target ) {
		$provider = isset( $target['provider'] ) ? (string) $target['provider'] : '';
		if ( 'mad4b/translation-set-post-language' === $ability_name ) return $this->context( absint( $target['post_id'] ), $provider );
		if ( 'mad4b/translation-link-posts' === $ability_name ) {
			$source = $this->context( absint( $target['source_post_id'] ), $provider );
			$dest = $this->context( absint( $target['target_post_id'] ), $provider );
			if ( is_wp_error( $source ) ) return $source;
			if ( is_wp_error( $dest ) ) return $dest;
			return array( 'source' => $source, 'target' => $dest );
		}
		return parent::read_reversible_state( $ability_name, $target );
	}

	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		$provider = isset( $target['provider'] ) ? (string) $target['provider'] : '';
		if ( 'mad4b/translation-set-post-language' === $ability_name ) {
			$id = absint( $target['post_id'] );
			if ( ! current_user_can( 'edit_post', $id ) ) return new WP_Error( 'mad4b_translation_restore_denied', 'Current user cannot restore this translation state.' );
			if ( 'polylang' === $provider ) {
				if ( empty( $state['language'] ) ) return new WP_Error( 'mad4b_translation_restore_unassigned_unsupported', 'Automatic restore of an originally unassigned Polylang post is not certified.' );
				pll_set_post_language( $id, $state['language'] );
				return true;
			}
			do_action( 'wpml_set_element_language_details', array(
				'element_id' => $id,
				'element_type' => $state['element_type'],
				'trid' => (int) $state['trid'],
				'language_code' => (string) $state['language'],
				'source_language_code' => '' !== (string) $state['source_language'] ? (string) $state['source_language'] : null,
			) );
			return true;
		}

		if ( 'mad4b/translation-link-posts' === $ability_name ) {
			$source_id = absint( $target['source_post_id'] );
			$target_id = absint( $target['target_post_id'] );
			if ( ! current_user_can( 'edit_post', $source_id ) || ! current_user_can( 'edit_post', $target_id ) ) return new WP_Error( 'mad4b_translation_restore_denied', 'Current user cannot restore this translation relationship.' );
			if ( 'polylang' === $provider ) {
				if ( ! function_exists( 'pll_save_post_translations' ) ) return new WP_Error( 'mad4b_polylang_link_unavailable', 'Polylang translation-link API is unavailable.' );
				$source_map = isset( $state['source']['translations'] ) ? (array) $state['source']['translations'] : array();
				$target_map = isset( $state['target']['translations'] ) ? (array) $state['target']['translations'] : array();
				if ( empty( $state['source']['language'] ) || empty( $state['target']['language'] ) ) return new WP_Error( 'mad4b_translation_restore_unassigned_unsupported', 'Polylang rollback requires both original posts to have assigned languages.' );
				pll_set_post_language( $source_id, $state['source']['language'] );
				pll_set_post_language( $target_id, $state['target']['language'] );
				if ( $source_map ) pll_save_post_translations( $source_map );
				if ( $target_map && $target_map !== $source_map ) pll_save_post_translations( $target_map );
				return true;
			}
			$source = $state['source'];
			$dest = $state['target'];
			do_action( 'wpml_set_element_language_details', array( 'element_id' => $source_id, 'element_type' => $source['element_type'], 'trid' => (int) $source['trid'], 'language_code' => (string) $source['language'], 'source_language_code' => '' !== (string) $source['source_language'] ? (string) $source['source_language'] : null ) );
			do_action( 'wpml_set_element_language_details', array( 'element_id' => $target_id, 'element_type' => $dest['element_type'], 'trid' => (int) $dest['trid'], 'language_code' => (string) $dest['language'], 'source_language_code' => '' !== (string) $dest['source_language'] ? (string) $dest['source_language'] : null ) );
			return true;
		}
		return parent::restore_reversible_state( $ability_name, $target, $state, $record );
	}
}
