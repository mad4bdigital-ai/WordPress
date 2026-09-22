<?php
/**
 * Read-only runtime contract diagnostic for rc.41 functional gaps.
 *
 * Usage from the WordPress root:
 *   wp eval-file wp-content/plugins/mad4b-site-control-plane/tests/runtime-functional-gap-diagnostic.php
 *
 * This script performs no updates, deletes, remote requests, cron execution,
 * plugin activation/deactivation or mutation ability calls.
 */
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "WordPress runtime required.\n" ); exit( 2 ); }

if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';

$targets = array(
	'jetengine' => array( 'jet-engine/' ),
	'jetsmartfilters' => array( 'jet-smart-filters/' ),
	'rank-math' => array( 'seo-by-rank-math/', 'seo-by-rank-math-pro/' ),
	'wp-import-export' => array( 'wp-all-import-pro/', 'wp-all-export-pro/' ),
	'bulk-taxonomy-editor' => array( 'bulk-taxonomy-editor/' ),
	'custom-mega-menu' => array( 'custom-mega-menu/', 'custom-mega-menu-v' ),
	'duplicator' => array( 'duplicator/' ),
	'elementskit' => array( 'elementskit-lite/' ),
	'google-tag-manager' => array( 'duracelltomi-google-tag-manager/' ),
	'heic-support' => array( 'heic-support/' ),
	'hostinger-ai' => array( 'hostinger-ai-assistant/' ),
	'hostinger-onboarding' => array( 'hostinger-easy-onboarding/' ),
	'hostinger-reach' => array( 'hostinger-reach/' ),
	'meta-catalog-feed-mapper' => array( 'meta-catalog-feed-mapper-pro/' ),
	'wordpress-importer' => array( 'wordpress-importer/' ),
	'wpl-client' => array( 'wpl-client/' ),
);

$secret_pattern = '/(?:secret|token|password|passwd|api[_-]?key|license[_-]?key|access[_-]?key|client[_-]?secret)/i';

$normalize = static function ( $value ) {
	return strtolower( ltrim( str_replace( '\\', '/', (string) $value ), '/' ) );
};

$plugin_map = get_plugins();
$active = (array) get_option( 'active_plugins', array() );
$network_active = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
$active_set = array_fill_keys( array_map( $normalize, array_merge( $active, $network_active ) ), true );

$family_plugins = array();
foreach ( $targets as $family => $prefixes ) {
	$family_plugins[ $family ] = array();
	foreach ( $plugin_map as $plugin_file => $headers ) {
		$normalized = $normalize( $plugin_file );
		$matched = false;
		foreach ( $prefixes as $prefix ) {
			$prefix = $normalize( $prefix );
			if ( '' !== $prefix && 0 === strpos( $normalized, $prefix ) ) { $matched = true; break; }
		}
		if ( ! $matched ) continue;
		$family_plugins[ $family ][] = array(
			'plugin_file' => (string) $plugin_file,
			'name' => isset( $headers['Name'] ) ? (string) $headers['Name'] : '',
			'version' => isset( $headers['Version'] ) ? (string) $headers['Version'] : '',
			'active' => isset( $active_set[ $normalized ] ),
		);
	}
	usort( $family_plugins[ $family ], static function ( $a, $b ) { return strcmp( $a['plugin_file'], $b['plugin_file'] ); } );
}

$routes = array();
if ( function_exists( 'rest_get_server' ) ) {
	$server = rest_get_server();
	if ( is_object( $server ) && method_exists( $server, 'get_routes' ) ) {
		foreach ( $server->get_routes() as $route => $handlers ) {
			if ( ! preg_match( '#(?:bulk-taxonomy-editor|wpl-client|rank-math|rankmath|gtm|meta|duplicator|elementskit|hostinger)#i', (string) $route ) ) continue;
			$methods = array();
			foreach ( (array) $handlers as $handler ) {
				if ( ! is_array( $handler ) || empty( $handler['methods'] ) ) continue;
				foreach ( (array) $handler['methods'] as $method => $enabled ) {
					if ( is_int( $method ) ) $method = $enabled;
					if ( $enabled ) $methods[] = strtoupper( (string) $method );
				}
			}
			$methods = array_values( array_unique( $methods ) );
			sort( $methods, SORT_STRING );
			$routes[] = array( 'route' => (string) $route, 'methods' => $methods );
		}
	}
}
usort( $routes, static function ( $a, $b ) { return strcmp( $a['route'], $b['route'] ); } );

$ajax = array();
global $wp_filter;
if ( is_array( $wp_filter ) || $wp_filter instanceof ArrayAccess ) {
	foreach ( (array) $wp_filter as $hook => $hook_obj ) {
		if ( 0 !== strpos( (string) $hook, 'wp_ajax_' ) && 0 !== strpos( (string) $hook, 'wp_ajax_nopriv_' ) ) continue;
		if ( ! preg_match( '/(?:bte_|wpl_|gtm|rank_math|duplicator|elementskit|hostinger|meta)/i', (string) $hook ) ) continue;
		$ajax[] = (string) $hook;
	}
}
$ajax = array_values( array_unique( $ajax ) );
sort( $ajax, SORT_STRING );

$option_presence = array();
$option_candidates = array(
	'bte_api_enabled','bte_api_allowed_roles','bte_api_allowed_post_types',
	'wpl_access_token','wpl_api_key','wpl_serial_verified','wpl_verified_order_number','wpl_verified_order_numbers','wpl_verified_wpl_ids',
	'rank-math-options-general','rank-math-options-titles','rank-math-options-sitemap',
);
foreach ( $option_candidates as $key ) {
	$value = get_option( $key, null );
	$row = array( 'exists' => null !== $value );
	if ( preg_match( $secret_pattern, $key ) ) {
		$row['redacted'] = true;
		$row['configured'] = null !== $value && '' !== (string) $value;
	} else {
		$row['redacted'] = false;
		$row['type'] = gettype( $value );
		if ( is_scalar( $value ) || null === $value ) {
			$text = (string) $value;
			$row['value_sha256'] = hash( 'sha256', $text );
			$row['empty'] = '' === $text;
		} elseif ( is_array( $value ) ) {
			$row['item_count'] = count( $value );
			$row['value_sha256'] = hash( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
	}
	$option_presence[ $key ] = $row;
}

$cron = array();
if ( function_exists( '_get_cron_array' ) ) {
	foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
		foreach ( (array) $hooks as $hook => $events ) {
			if ( ! preg_match( '/(?:wpl|gtm|rank_math|duplicator|elementskit|hostinger|meta_catalog|feed)/i', (string) $hook ) ) continue;
			$cron[] = array( 'hook' => (string) $hook, 'timestamp' => (int) $timestamp, 'event_count' => is_array( $events ) ? count( $events ) : 0 );
		}
	}
}
usort( $cron, static function ( $a, $b ) { return strcmp( $a['hook'], $b['hook'] ) ?: ( $a['timestamp'] <=> $b['timestamp'] ); } );

$constants = array();
foreach ( array( 'JET_ENGINE_VERSION','JET_SMART_FILTERS_VERSION','RANK_MATH_VERSION','RANK_MATH_PRO_VERSION','PMXI_VERSION','PMXE_VERSION' ) as $name ) {
	$constants[ $name ] = defined( $name ) ? (string) constant( $name ) : null;
}

$out = array(
	'contract' => 'mad4b.runtime-functional-gap-diagnostic.v1',
	'generated_at' => gmdate( 'c' ),
	'site' => array(
		'home_host' => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
		'is_multisite' => is_multisite(),
	),
	'families' => $family_plugins,
	'constants' => $constants,
	'rest_routes' => $routes,
	'ajax_hooks' => $ajax,
	'option_presence' => $option_presence,
	'cron_hooks' => $cron,
	'safety' => array(
		'mutation_performed' => false,
		'remote_request_performed' => false,
		'secret_values_returned' => false,
		'raw_sql_performed' => false,
	),
);

echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL;
