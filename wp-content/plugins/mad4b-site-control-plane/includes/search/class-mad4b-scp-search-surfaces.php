<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface MAD4B_SCP_Search_Discovery_Source {
	public function descriptor();
	public function languages();
	public function surfaces( $cursor, $limit );
	public function seo( array $surface );
}

/** Discovery and SEO composition retain disagreements instead of choosing a plugin. */
final class MAD4B_SCP_Search_Surfaces {
	public static function sources() {
		$rows = apply_filters( 'mad4b_scp_search_discovery_sources', array( new MAD4B_SCP_Search_WordPress_Discovery(), new MAD4B_SCP_Search_Multilingual_Discovery(), new MAD4B_SCP_Search_Metadata_Discovery() ) );
		$out = array();
		foreach ( (array) $rows as $source ) {
			if ( ! $source instanceof MAD4B_SCP_Search_Discovery_Source ) continue;
			$d = $source->descriptor();
			if ( ! is_array( $d ) || empty( $d['active'] ) || ! MAD4B_SCP_Search_Contracts::id( isset( $d['id'] ) ? $d['id'] : '' ) || ! MAD4B_SCP_Search_Contracts::sha( isset( $d['generation'] ) ? $d['generation'] : '' ) || isset( $out[ $d['id'] ] ) ) continue;
			$out[ $d['id'] ] = $source;
		}
		ksort( $out, SORT_STRING ); return $out;
	}

	public static function languages() {
		$rows = array();
		foreach ( self::sources() as $source ) {
			$d = $source->descriptor();
			foreach ( (array) $source->languages() as $language => $fact ) {
				if ( ! is_string( $language ) || ! is_array( $fact ) || ! preg_match( '/^[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{2,8})*$/D', $language ) ) continue;
				$fact['source'] = $d['id']; $fact['source_generation'] = $d['generation'];
				$rows[ $language ]['observations'][] = $fact;
			}
		}
		foreach ( $rows as $language => &$row ) {
			$states = array(); $count = 0;
			foreach ( $row['observations'] as $observation ) { $states[] = ! empty( $observation['active'] ); $count = max( $count, (int) ( isset( $observation['owned_count'] ) ? $observation['owned_count'] : 0 ) ); }
			$row['conflicting'] = count( array_unique( $states, SORT_REGULAR ) ) > 1;
			$row['active'] = ! $row['conflicting'] && ! in_array( false, $states, true );
			$row['owned_count'] = $count; $row['source'] = 'language_registry';
			$row['partial_translation'] = false;
			foreach ( $row['observations'] as $o ) if ( ! empty( $o['partial_translation'] ) ) $row['partial_translation'] = true;
		}
		unset( $row ); ksort( $rows, SORT_STRING ); return $rows;
	}

	public static function seo( array $surface ) {
		$fields = array();
		foreach ( self::sources() as $source ) {
			$d = $source->descriptor();
			foreach ( (array) $source->seo( $surface ) as $field => $observation ) {
				if ( ! is_array( $observation ) || ! array_key_exists( 'value', $observation ) || ! MAD4B_SCP_Search_Contracts::bounded( $observation ) ) continue;
				$observation['source'] = $d['id']; $observation['source_generation'] = $d['generation'];
				$observation['observed_at'] = isset( $observation['observed_at'] ) ? $observation['observed_at'] : time();
				$fields[ $field ]['observations'][] = $observation;
			}
		}
		foreach ( $fields as &$field ) {
			$authoritative = array_filter( $field['observations'], static function ( $o ) { return ! isset( $o['observation_class'] ) || 'core_fallback' !== $o['observation_class']; } );
			$values = array(); foreach ( $authoritative ? $authoritative : $field['observations'] as $o ) $values[ MAD4B_SCP_Search_Contracts::digest( $o['value'] ) ] = $o['value'];
			$field['conflicting'] = count( $values ) > 1;
			$field['value'] = $field['conflicting'] ? null : reset( $values );
			$field['state'] = $field['conflicting'] ? 'requires_reconciliation' : 'observed';
		}
		unset( $field ); return $fields;
	}

	public static function eligibility( array $surface, array $seo ) {
		$e = isset( $surface['eligibility'] ) && is_array( $surface['eligibility'] ) ? $surface['eligibility'] : array();
		$required = array( 'crawlable', 'robots_txt_allowed', 'indexable', 'canonical_state', 'redirect_state', 'hreflang_valid' );
		$reasons = array(); $unknown = array();
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $e ) || null === $e[ $key ] ) $unknown[] = $key;
			elseif ( false === $e[ $key ] || in_array( $e[ $key ], array( 'elsewhere', 'blocked', 'conflicting', 'invalid', 'redirected' ), true ) ) $reasons[] = $key;
		}
		foreach ( array( 'meta_robots', 'x_robots_header' ) as $key ) {
			if ( isset( $e[ $key ] ) && preg_match( '/(?:noindex|none)/i', implode( ',', (array) $e[ $key ] ) ) ) $reasons[] = $key;
		}
		foreach ( $seo as $key => $field ) {
			if ( ! empty( $field['conflicting'] ) ) $reasons[] = 'seo_conflict:' . $key;
			if ( 'robots' === $key && ! empty( $field['value'] ) && preg_match( '/(?:noindex|none)/i', implode( ',', (array) $field['value'] ) ) ) $reasons[] = 'seo_noindex';
			if ( 'canonical' === $key && ! empty( $field['value'] ) && ! MAD4B_SCP_Search_Contracts::owned_match( $field['value'], $surface ) ) $reasons[] = 'seo_canonical_elsewhere';
		}
		if ( isset( $surface['http_state'] ) && 200 !== (int) $surface['http_state'] ) $reasons[] = 'http_state';
		$measurement = MAD4B_SCP_Search_Eligibility::resolve( array_merge( $e, array( 'http_status' => isset( $surface['http_state'] ) ? $surface['http_state'] : 0, 'x_robots_tag' => isset( $e['x_robots_header'] ) ? $e['x_robots_header'] : array(), 'hreflang_state' => ! empty( $e['hreflang_valid'] ) ? 'valid' : 'unknown', 'sitemap_state' => ! empty( $e['discoverable'] ) ? 'observed' : 'unknown' ) ) );
		if ( ! $measurement['owned_tracking_eligible'] ) $reasons[] = 'measurement_eligibility_denied';
		$conflict = false; foreach ( $reasons as $r ) if ( 0 === strpos( $r, 'seo_conflict:' ) ) $conflict = true;
		$state = $conflict || $unknown ? 'requires_reconciliation' : ( $reasons ? 'ineligible' : 'eligible' );
		return array_merge( $e, array( 'contract' => 'mad4b.search-eligibility-envelope.v1', 'effective' => $state, 'confidence' => 'eligible' === $state ? 1 : ( $unknown ? 0.5 : 1 ), 'reasons' => $reasons, 'unknown' => $unknown ) );
	}

	public static function admit( array $surface, array $policy, $count ) {
		if ( $count >= $policy['max_surfaces'] || ! MAD4B_SCP_Search_Contracts::bounded( $surface ) ) return MAD4B_SCP_Search_Contracts::error( 'surface_cardinality' );
		$url = MAD4B_SCP_Search_Contracts::url( isset( $surface['public_url'] ) ? $surface['public_url'] : '' );
		$home = MAD4B_SCP_Search_Contracts::url( home_url( '/' ) );
		if ( is_wp_error( $url ) || is_wp_error( $home ) || $url['host'] !== $home['host'] ) return MAD4B_SCP_Search_Contracts::error( 'surface_not_owned' );
		$type = isset( $surface['surface_type'] ) ? $surface['surface_type'] : '';
		if ( ! in_array( $type, array( 'CONTENT_OBJECT', 'TERM', 'TERM_ARCHIVE', 'POST_TYPE_ARCHIVE', 'HOME', 'BLOG_INDEX', 'PAGINATED_ARCHIVE', 'VIRTUAL_LANDING_SURFACE' ), true ) ) return MAD4B_SCP_Search_Contracts::error( 'surface_type_invalid' );
		if ( in_array( $type, array( 'PAGINATED_ARCHIVE', 'VIRTUAL_LANDING_SURFACE' ), true ) ) {
			if ( empty( $surface['provider_approved'] ) || empty( $surface['route_id'] ) || ! in_array( $surface['route_id'], $policy['virtual_routes'], true ) || empty( $surface['cardinality_estimate'] ) || $surface['cardinality_estimate'] > $policy['max_surfaces'] || ( isset( $surface['page'] ) && $surface['page'] > $policy['max_pagination'] ) ) return MAD4B_SCP_Search_Contracts::error( 'virtual_surface_denied' );
			$p = wp_parse_url( $surface['public_url'] );
			foreach ( isset( $p['query'] ) ? explode( '&', $p['query'] ) : array() as $pair ) if ( ! in_array( rawurldecode( explode( '=', $pair, 2 )[0] ), $policy['query_parameters'], true ) ) return MAD4B_SCP_Search_Contracts::error( 'surface_parameter_denied' );
			if ( empty( $surface['combination_approved'] ) ) return MAD4B_SCP_Search_Contracts::error( 'facet_combination_unknown' );
		}
		$admission = MAD4B_SCP_Search_Eligibility::admit_surface( array( 'surface_type' => strtolower( $type ), 'url' => $surface['public_url'], 'estimated_cardinality' => isset( $surface['cardinality_estimate'] ) ? $surface['cardinality_estimate'] : 1, 'page_number' => isset( $surface['page'] ) ? $surface['page'] : 1 ), array( 'allowed_surface_types' => array( strtolower( $type ) ), 'allowed_query_params' => $policy['query_parameters'], 'max_cardinality' => $policy['max_surfaces'], 'max_page_number' => $policy['max_pagination'] ) );
		if ( is_wp_error( $admission ) ) return $admission;
		$object_ref = isset( $surface['object_ref'] ) ? $surface['object_ref'] : array();
		$surface['contract'] = 'mad4b.indexable-search-surface.v1';
		$surface['object_id'] = MAD4B_SCP_Search_Contracts::digest( array( 'site' => MAD4B_SCP_Site_Profile::site_uuid(), 'object' => $object_ref ) );
		$surface['surface_key'] = MAD4B_SCP_Search_Contracts::digest( array( 'object_id' => $surface['object_id'], 'type' => $type, 'url_id' => $url['url_id'], 'language' => isset( $surface['language'] ) ? $surface['language'] : '' ) );
		$surface['seo'] = self::seo( $surface );
		$surface['eligibility'] = self::eligibility( $surface, $surface['seo'] );
		$material = $surface['seo'];
		foreach ( $material as &$field ) foreach ( $field['observations'] as &$observation ) unset( $observation['observed_at'] );
		unset( $field, $observation );
		$surface['seo_fingerprint'] = MAD4B_SCP_Search_Contracts::digest( $material );
		$stable = $surface; unset( $stable['captured_at'], $stable['seo'] );
		$surface['surface_fingerprint'] = MAD4B_SCP_Search_Contracts::digest( $stable );
		$surface['authorizing'] = false; return $surface;
	}

	public static function refresh( array $surface, array $policy ) {
		$ref = $surface['object_ref'];
		if ( 'post' === $ref['kind'] && function_exists( 'get_post' ) ) {
			$post = get_post( $ref['id'] );
			if ( ! $post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) return MAD4B_SCP_Search_Contracts::error( 'surface_no_longer_public' );
			$surface['public_url'] = get_permalink( $post ); $surface['canonical_url'] = $surface['public_url']; $surface['title'] = $post->post_title;
			$surface['content_fingerprint'] = hash( 'sha256', $post->post_content . '|' . $post->post_modified_gmt );
		} elseif ( 'term' === $ref['kind'] && function_exists( 'get_term' ) ) {
			$term = get_term( $ref['id'], $ref['taxonomy'] );
			if ( ! $term || is_wp_error( $term ) ) return MAD4B_SCP_Search_Contracts::error( 'surface_object_removed' );
			$surface['public_url'] = get_term_link( $term ); $surface['canonical_url'] = $surface['public_url']; $surface['title'] = $term->name;
			$surface['content_fingerprint'] = hash( 'sha256', $term->description . '|' . $term->count );
		}
		unset( $surface['seo'], $surface['seo_fingerprint'], $surface['surface_fingerprint'], $surface['authorizing'] );
		$surface = apply_filters( 'mad4b_scp_search_live_surface_evidence', $surface );
		return self::admit( $surface, $policy, 0 );
	}

	public static function discover( array $profile, array $cursors = array() ) {
		$out = array(); $next = array(); $diagnostics = array();
		foreach ( self::sources() as $id => $source ) {
			$result = $source->surfaces( isset( $cursors[ $id ] ) ? $cursors[ $id ] : 0, 100 );
			if ( is_wp_error( $result ) ) { $diagnostics[] = $result->get_error_code(); continue; }
			if ( ! is_array( $result ) || empty( $result['items'] ) ) continue;
			$next[ $id ] = isset( $result['cursor'] ) ? $result['cursor'] : null;
			foreach ( array_slice( $result['items'], 0, 100 ) as $raw ) {
				$surface = self::admit( $raw, $profile['surface_policy'], count( $out ) );
				if ( is_wp_error( $surface ) ) { $diagnostics[] = $surface->get_error_code(); continue; }
				$out[ $surface['surface_key'] ] = $surface;
			}
		}
		return array( 'contract' => 'mad4b.search-discovery.v1', 'languages' => self::languages(), 'surfaces' => array_values( $out ), 'cursors' => $next, 'diagnostics' => $diagnostics, 'authorizing' => false );
	}
}
