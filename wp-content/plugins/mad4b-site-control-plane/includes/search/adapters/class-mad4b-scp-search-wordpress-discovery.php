<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Local inventory only. Rendered evidence is supplied by registered read providers. */
class MAD4B_SCP_Search_WordPress_Discovery implements MAD4B_SCP_Search_Discovery_Source {
	public function descriptor() { return array( 'id' => 'wordpress', 'active' => true, 'generation' => MAD4B_SCP_Search_Contracts::digest( array( get_bloginfo( 'version' ), get_option( 'blog_public' ), get_option( 'show_on_front' ) ) ) ); }
	public function languages() {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'pll_languages_list' ) ) return array();
		$locale = get_locale(); $language = strtolower( strtok( $locale, '_-' ) );
		return array( $language => array( 'active' => true, 'owned_count' => 0, 'locale' => $locale ) );
	}
	public function surfaces( $cursor, $limit ) {
		$cursor = is_array( $cursor ) ? array_merge( array( 'posts' => 0, 'terms' => 0, 'archives' => 0 ), $cursor ) : array( 'posts' => 0, 'terms' => 0, 'archives' => 0 ); $limit = min( 100, max( 10, (int) $limit ) );
		$post_limit = (int) floor( $limit / 2 ); $term_limit = (int) floor( $limit / 5 );
		$types = get_post_types( array( 'public' => true ), 'objects' ); $names = array();
		foreach ( $types as $name => $object ) if ( 'attachment' !== $name && ! empty( $object->publicly_queryable ) ) $names[] = $name;
		$posts = null === $cursor['posts'] ? array() : get_posts( array( 'post_type' => $names, 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => $post_limit, 'offset' => max( 0, (int) $cursor['posts'] ), 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true, 'lang' => 'all' ) );
		$items = array();
		foreach ( $posts as $post ) $items[] = $this->surface( 'CONTENT_OBJECT', array( 'kind' => 'post', 'id' => $post->ID, 'post_type' => $post->post_type ), get_permalink( $post ), $post->post_title, hash( 'sha256', $post->post_content . '|' . $post->post_modified_gmt ) );
		// Independent bounded cursors prevent a large post set from hiding term/archive inventory.
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		$terms = $taxonomies && null !== $cursor['terms'] ? get_terms( array( 'taxonomy' => array_values( $taxonomies ), 'hide_empty' => false, 'number' => $term_limit, 'offset' => max( 0, (int) $cursor['terms'] ), 'orderby' => 'term_id', 'order' => 'ASC', 'suppress_filter' => true, 'lang' => 'all' ) ) : array();
		if ( ! is_wp_error( $terms ) ) foreach ( $terms as $term ) {
			$url = get_term_link( $term ); if ( is_wp_error( $url ) ) continue;
			$ref = array( 'kind' => 'term', 'id' => $term->term_id, 'taxonomy' => $term->taxonomy );
			$items[] = $this->surface( 'TERM', $ref, $url, $term->name, hash( 'sha256', $term->description ) );
			$items[] = $this->surface( 'TERM_ARCHIVE', $ref, $url, $term->name, hash( 'sha256', $term->description . '|' . $term->count ) );
		}
		$archives = array();
		if ( null !== $cursor['archives'] ) {
			foreach ( $types as $name => $object ) if ( ! empty( $object->has_archive ) ) $archives[] = $this->surface( 'POST_TYPE_ARCHIVE', array( 'kind' => 'post_type', 'id' => $name ), get_post_type_archive_link( $name ), $object->label, hash( 'sha256', serialize( $object->rewrite ) ) );
			$archives[] = $this->surface( 'HOME', array( 'kind' => 'site', 'id' => 'home' ), home_url( '/' ), get_bloginfo( 'name' ), hash( 'sha256', (string) get_option( 'page_on_front' ) ) );
			$blog = (int) get_option( 'page_for_posts' );
			if ( $blog ) $archives[] = $this->surface( 'BLOG_INDEX', array( 'kind' => 'post', 'id' => $blog ), get_permalink( $blog ), get_the_title( $blog ), hash( 'sha256', (string) $blog ) );
		}
		$archive_limit = max( 1, $limit - $post_limit - 2 * $term_limit );
		$items = array_merge( $items, array_slice( $archives, (int) $cursor['archives'], $archive_limit ) );
		$next = array( 'posts' => count( $posts ) === $post_limit ? (int) $cursor['posts'] + $post_limit : null, 'terms' => ! is_wp_error( $terms ) && count( $terms ) === $term_limit ? (int) $cursor['terms'] + $term_limit : null, 'archives' => null !== $cursor['archives'] && count( $archives ) > (int) $cursor['archives'] + $archive_limit ? (int) $cursor['archives'] + $archive_limit : null );
		return array( 'items' => $items, 'cursor' => array_filter( $next, static function ( $value ) { return null !== $value; } ) ? $next : null );
	}
	private function surface( $type, array $ref, $url, $title, $fingerprint ) {
		$locale = get_locale();
		$surface = array( 'surface_type' => $type, 'object_ref' => $ref, 'public_url' => $url, 'canonical_url' => $url, 'title' => $title, 'language' => strtolower( strtok( $locale, '_-' ) ), 'locale' => $locale, 'content_fingerprint' => $fingerprint, 'captured_at' => time(), 'eligibility' => array( 'crawlable' => true, 'robots_txt_allowed' => null, 'indexable' => '0' !== (string) get_option( 'blog_public' ), 'canonical_state' => 'self', 'redirect_state' => 'none', 'hreflang_valid' => null, 'discoverable' => null ) );
		// Filters return typed evidence; they do not trigger arbitrary outbound requests.
		return apply_filters( 'mad4b_scp_search_live_surface_evidence', $surface );
	}
	public function seo( array $surface ) {
		$fields = array( 'title' => array( 'value' => $surface['title'], 'observation_class' => 'core_fallback' ) );
		$rendered = apply_filters( 'mad4b_scp_search_rendered_seo_evidence', array(), $surface );
		return is_array( $rendered ) && $rendered ? $rendered : $fields;
	}
}

/** Vendor hooks are isolated in the adapter, never used by the context kernel. */
final class MAD4B_SCP_Search_Multilingual_Discovery extends MAD4B_SCP_Search_WordPress_Discovery {
	public function descriptor() { return array( 'id' => 'multilingual', 'active' => defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'pll_languages_list' ), 'generation' => MAD4B_SCP_Search_Contracts::digest( array( defined( 'ICL_SITEPRESS_VERSION' ) ? ICL_SITEPRESS_VERSION : '', function_exists( 'pll_languages_list' ) ? pll_languages_list() : array() ) ) ); }
	public function languages() {
		$out = array(); $languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( is_array( $languages ) ) foreach ( $languages as $code => $row ) $out[ $code ] = array( 'active' => true, 'owned_count' => (int) apply_filters( 'mad4b_scp_search_owned_language_count', 0, $code ), 'partial_translation' => ! empty( $row['missing'] ) );
		if ( function_exists( 'pll_languages_list' ) ) foreach ( pll_languages_list( array( 'fields' => 'slug' ) ) as $code ) $out[ $code ] = array( 'active' => true, 'owned_count' => (int) apply_filters( 'mad4b_scp_search_owned_language_count', 0, $code ), 'partial_translation' => true );
		return $out;
	}
	public function surfaces( $cursor, $limit ) { return array( 'items' => array(), 'cursor' => null ); }
	public function seo( array $surface ) { return array(); }
	public static function localize( $surface ) {
		$ref = $surface['object_ref'];
		if ( 'post' === $ref['kind'] ) {
			$details = apply_filters( 'wpml_post_language_details', null, $ref['id'] );
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) { $surface['language'] = $details['language_code']; $surface['locale'] = isset( $details['locale'] ) ? $details['locale'] : $surface['locale']; }
			elseif ( function_exists( 'pll_get_post_language' ) ) { $language = pll_get_post_language( $ref['id'] ); if ( $language ) $surface['language'] = $language; }
		} elseif ( 'term' === $ref['kind'] ) {
			$language = apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $ref['id'], 'element_type' => 'tax_' . $ref['taxonomy'] ) );
			if ( $language ) $surface['language'] = $language;
			elseif ( function_exists( 'pll_get_term_language' ) ) { $language = pll_get_term_language( $ref['id'] ); if ( $language ) $surface['language'] = $language; }
		}
		return $surface;
	}
}

/** Field mappings are extensible data, including term metadata. */
final class MAD4B_SCP_Search_Metadata_Discovery extends MAD4B_SCP_Search_WordPress_Discovery {
	public function descriptor() { return array( 'id' => 'seo_metadata', 'active' => defined( 'RANK_MATH_VERSION' ), 'generation' => MAD4B_SCP_Search_Contracts::digest( $this->maps() ) ); }
	private function maps() { return apply_filters( 'mad4b_scp_search_seo_field_mappings', array( 'title' => 'rank_math_title', 'canonical' => 'rank_math_canonical_url', 'robots' => 'rank_math_robots', 'focus_keywords' => 'rank_math_focus_keyword' ) ); }
	public function languages() { return array(); }
	public function surfaces( $cursor, $limit ) { return array( 'items' => array(), 'cursor' => null ); }
	public function seo( array $surface ) {
		$ref = $surface['object_ref']; $out = array();
		if ( ! in_array( $ref['kind'], array( 'post', 'term' ), true ) ) return $out;
		foreach ( $this->maps() as $field => $key ) {
			$value = 'post' === $ref['kind'] ? get_post_meta( $ref['id'], $key, true ) : get_term_meta( $ref['id'], $key, true );
			if ( '' === $value || array() === $value ) continue;
			if ( 'focus_keywords' === $field && is_string( $value ) ) $value = array_map( 'trim', explode( ',', $value ) );
			$out[ $field ] = array( 'value' => $value, 'meta_key' => $key, 'observation_class' => 'configured_metadata' );
		}
		return $out;
	}
}
