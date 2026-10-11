<?php
/**
 * Non-authorizing, bounded WordPress capability signals.
 *
 * An installed plugin is an observation, never permission to load its code,
 * select an unsigned driver, construct an arbitrary URL or issue PASS.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Site_Capability_Discovery {
	const CONTRACT = 'mad4b.site-capability-discovery.v1';
	const MAX_PLUGINS = 128;
	const MAX_TYPES = 96;
	const MAX_TAXONOMIES = 96;
	const MAX_MATCHES = 32;

	private static function names( $items, $max, $pattern ) {
		if ( ! is_array( $items ) || count( $items ) > $max ) return null;
		$names = array();
		foreach ( $items as $key => $value ) {
			$name = is_int( $key ) ? $value : $key;
			if ( ! is_string( $name ) || ! preg_match( $pattern, $name ) ) return null;
			$names[ $name ] = true;
		}
		$names = array_keys( $names );
		sort( $names, SORT_STRING );
		return $names;
	}

	private static function plugin_slug( $basename ) {
		if ( ! is_string( $basename ) || ! preg_match( '/^[a-zA-Z0-9._-]+(?:\/[a-zA-Z0-9._-]+)?\.php$/D', $basename ) ) return '';
		$parts = explode( '/', $basename );
		foreach ( $parts as $part ) if ( '.' === $part || '..' === $part || 0 === strpos( $part, '.' ) ) return '';
		$slug = 1 === count( $parts ) ? substr( $parts[0], 0, -4 ) : $parts[0];
		if ( ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/D', $slug ) ) return '';
		return strtolower( $slug );
	}

	public static function observe( $site_origin, array $providers = array() ) {
		$blockers = array();
		$origin = is_string( $site_origin ) ? $site_origin : '';
		if ( ! preg_match( '~^https://[a-zA-Z0-9.-]+(?::[0-9]{2,5})?(?:/[a-zA-Z0-9][a-zA-Z0-9._-]{0,63})*$~D', $origin ) ) $blockers[] = 'site_origin_invalid';
		$plugins = array();
		$plugin_versions = array();
		$version_evidence_complete = true;
		if ( ! function_exists( 'get_option' ) ) {
			$blockers[] = 'plugin_inventory_unavailable';
		} else {
			$active = get_option( 'active_plugins', array() );
			if ( ! is_array( $active ) || count( $active ) > self::MAX_PLUGINS ) {
				$blockers[] = 'plugin_inventory_invalid_or_overflow';
			} else {
				if ( function_exists( 'get_site_option' ) ) {
					$network = get_site_option( 'active_sitewide_plugins', array() );
					if ( ! is_array( $network ) || count( $network ) > self::MAX_PLUGINS ) $blockers[] = 'network_plugin_inventory_invalid_or_overflow';
					else $active = array_merge( $active, array_keys( $network ) );
				}
				if ( count( $active ) > self::MAX_PLUGINS ) $blockers[] = 'combined_plugin_inventory_overflow';
				if ( ! $blockers ) {
					$seen_basename = array();
					foreach ( $active as $item ) {
						$slug = self::plugin_slug( $item );
						if ( '' === $slug ) { $blockers[] = 'plugin_basename_invalid'; break; }
						if ( isset( $seen_basename[ $item ] ) ) continue; // Same plugin active per-site and network-wide.
						$seen_basename[ $item ] = true;
						if ( isset( $plugins[ $slug ] ) ) { $blockers[] = 'duplicate_plugin_slug'; break; }
						$plugins[ $slug ] = true;
						$version = '';
						if ( function_exists( 'get_file_data' ) && defined( 'WP_PLUGIN_DIR' ) && is_string( WP_PLUGIN_DIR ) ) {
							$meta = get_file_data( WP_PLUGIN_DIR . '/' . $item, array( 'Version' => 'Version' ), 'plugin' );
							if ( is_array( $meta ) && isset( $meta['Version'] ) && is_string( $meta['Version'] ) ) $version = $meta['Version'];
						}
						if ( ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._+-]{0,99}$/D', $version ) ) {
							$version_evidence_complete = false;
							$version = '';
						}
						$plugin_versions[] = array( 'slug' => $slug, 'basename' => $item, 'version' => $version );
					}
				}
			}
		}
		$plugin_ids = array_keys( $plugins );
		sort( $plugin_ids, SORT_STRING );
		usort( $plugin_versions, static function ( $a, $b ) { return strcmp( $a['slug'], $b['slug'] ); } );
		if ( ! $version_evidence_complete ) $blockers[] = 'plugin_version_evidence_unavailable';
		$post_types = function_exists( 'get_post_types' ) ? self::names( get_post_types( array(), 'names' ), self::MAX_TYPES, '/^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/D' ) : null;
		$taxonomies = function_exists( 'get_taxonomies' ) ? self::names( get_taxonomies( array(), 'names' ), self::MAX_TAXONOMIES, '/^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/D' ) : null;
		if ( null === $post_types ) $blockers[] = 'post_type_inventory_unavailable_or_invalid';
		if ( null === $taxonomies ) $blockers[] = 'taxonomy_inventory_unavailable_or_invalid';
		$theme = array( 'stylesheet' => '', 'version' => '', 'parent_stylesheet' => '', 'parent_version' => '' );
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme_object = wp_get_theme();
			if ( is_object( $theme_object ) && method_exists( $theme_object, 'get_stylesheet' ) && method_exists( $theme_object, 'get' ) ) {
				$theme['stylesheet'] = $theme_object->get_stylesheet();
				$theme['version'] = $theme_object->get( 'Version' );
				$parent = method_exists( $theme_object, 'parent' ) ? $theme_object->parent() : false;
				if ( is_object( $parent ) && method_exists( $parent, 'get_stylesheet' ) && method_exists( $parent, 'get' ) ) {
					$theme['parent_stylesheet'] = $parent->get_stylesheet();
					$theme['parent_version'] = $parent->get( 'Version' );
				}
			}
		}
		$theme_valid = is_string( $theme['stylesheet'] ) && preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/D', $theme['stylesheet'] )
			&& is_string( $theme['version'] ) && preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._+-]{0,99}$/D', $theme['version'] )
			&& ( '' === $theme['parent_stylesheet'] || ( is_string( $theme['parent_stylesheet'] )
				&& preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/D', $theme['parent_stylesheet'] )
				&& is_string( $theme['parent_version'] )
				&& preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._+-]{0,99}$/D', $theme['parent_version'] ) ) );
		if ( ! $theme_valid ) $blockers[] = 'theme_version_evidence_unavailable';
		$matches = array();
		$mapped = array();
		if ( count( $providers ) > self::MAX_MATCHES ) $blockers[] = 'provider_inventory_overflow';
		else foreach ( $providers as $provider_id => $provider ) {
			if ( ! is_string( $provider_id ) || ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}$/D', $provider_id ) || ! is_array( $provider ) ) {
				$blockers[] = 'provider_identity_invalid';
				break;
			}
			$descriptor = isset( $provider['descriptor'] ) && is_array( $provider['descriptor'] ) ? $provider['descriptor'] : array();
			$recognition = isset( $descriptor['recognition'] ) && is_array( $descriptor['recognition'] ) ? $descriptor['recognition'] : array();
			$evidence = array(
				'source_plugins' => array( isset( $recognition['source_plugins'] ) ? $recognition['source_plugins'] : array(), $plugin_ids ),
				'source_post_types' => array( isset( $recognition['source_post_types'] ) ? $recognition['source_post_types'] : array(), $post_types ),
				'source_taxonomies' => array( isset( $recognition['source_taxonomies'] ) ? $recognition['source_taxonomies'] : array(), $taxonomies ),
			);
			$expected = array();
			$observed_matches = array();
			foreach ( $evidence as $kind => $pair ) {
				$pattern = 'source_plugins' === $kind ? '/^[a-z0-9][a-z0-9._-]{0,63}$/D' : '/^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/D';
				$expected[ $kind ] = self::names( $pair[0], self::MAX_MATCHES, $pattern );
				if ( null === $expected[ $kind ] ) { $blockers[] = 'provider_recognition_invalid'; break; }
				$observed_matches[ $kind ] = count( array_intersect( $expected[ $kind ], (array) $pair[1] ) );
			}
			if ( null === $expected['source_plugins'] || count( $expected ) < 3 ) break;
			$total = count( $expected['source_plugins'] ) + count( $expected['source_post_types'] ) + count( $expected['source_taxonomies'] );
			if ( ! $total ) continue; // Missing recognition metadata cannot become an inferred driver.
			$matched = array_sum( $observed_matches );
			$complete = $matched === $total;
			$matches[] = array(
				'provider_id' => $provider_id,
				'source_plugins' => $expected['source_plugins'],
				'source_post_types' => $expected['source_post_types'],
				'source_taxonomies' => $expected['source_taxonomies'],
				'matched_plugins' => $observed_matches['source_plugins'],
				'matched_post_types' => $observed_matches['source_post_types'],
				'matched_taxonomies' => $observed_matches['source_taxonomies'],
				'recognized' => $complete, 'certified' => false, 'authorizing' => false,
			);
			if ( $complete ) foreach ( $expected['source_plugins'] as $plugin_slug ) $mapped[ $plugin_slug ] = true;
		}
		$unmapped = array_values( array_diff( $plugin_ids, array_keys( $mapped ) ) );
		$complete = ! $blockers;
		$source = array(
			'origin' => $origin,
			'plugins' => $plugin_ids,
			'plugin_versions' => $plugin_versions,
			'theme' => $theme,
			'post_types' => null === $post_types ? array() : $post_types,
			'taxonomies' => null === $taxonomies ? array() : $taxonomies,
			'provider_matches' => $matches,
		);
		return array(
			'contract' => self::CONTRACT, 'read_only' => true, 'authorizing' => false,
			'discovery_complete' => $complete, 'certification_issued' => false,
			'origin' => $origin, 'snapshot_sha256' => hash( 'sha256', wp_json_encode( $source ) ),
			'plugins' => $plugin_ids, 'plugin_versions' => $plugin_versions,
			'plugin_versions_complete' => $version_evidence_complete && ! in_array( 'plugin_basename_invalid', $blockers, true ),
			'theme' => $theme, 'theme_version_complete' => (bool) $theme_valid,
			'post_types' => $source['post_types'],
			'taxonomies' => $source['taxonomies'], 'provider_matches' => $matches,
			'unmapped_plugins' => $unmapped,
			'blocking_reasons' => array_values( array_unique( $blockers ) ),
			'unsupported_action' => 'adapter_required_not_auto_installed',
		);
	}
}
