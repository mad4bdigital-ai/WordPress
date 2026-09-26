<?php

define( 'ABSPATH', __DIR__ . '/' );

function add_action( $hook, $callback, $priority = 10 ) {}
function sanitize_key( $value ) {
	$value = strtolower( (string) $value );
	return preg_replace( '/[^a-z0-9_\-]/', '', $value );
}
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function esc_url_raw( $value, $protocols = null ) { return (string) $value; }
function wp_parse_url( $value, $component = -1 ) { return parse_url( $value, $component ); }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-site-bootstrap.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL site-bootstrap-contract: ' . $message . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message ) use ( $fail ) { if ( ! $condition ) $fail( $message ); };

$base = array(
	'contract' => 'mad4b.site-content-bootstrap.v1',
	'site_uuid' => '11111111-2222-4333-8444-555555555555',
	'identity' => array( 'source_commit_sha' => str_repeat( 'a', 40 ) ),
	'wordpress_version' => '7.1.2',
	'active_languages' => array( 'en', 'fr' ),
	'content_types' => array( array( 'post_type' => 'post', 'public' => true, 'hierarchical' => false ) ),
	'taxonomies' => array(),
	'redirect_observations' => array(),
	'inventory_scope' => array( 'post_types' => array( 'post' ), 'post_statuses' => array( 'publish' ), 'max_items' => 10 ),
	'observed_at' => '2026-09-25T00:00:00Z',
	'historical_source_attribution' => 'observed_existing_content',
	'backfill_performed' => false,
	'intent_claims_created' => false,
	'artifacts_created' => false,
	'mutation_performed' => false,
	'authorizing' => false,
);

$items = array(
	array(
		'content_id' => 'site:post:2',
		'locale' => 'en',
		'object_type' => 'post',
		'object_id' => 2,
		'public_url' => 'https://example.test/a-2',
		'canonical_url' => 'https://example.test/a',
	),
	array(
		'content_id' => 'site:post:1',
		'locale' => 'en',
		'object_type' => 'post',
		'object_id' => 1,
		'public_url' => 'https://example.test/a-1',
		'canonical_url' => 'https://example.test/a',
	),
	array(
		'content_id' => 'site:post:3',
		'locale' => 'fr',
		'object_type' => 'post',
		'object_id' => 3,
		'public_url' => 'https://example.test/fr/a',
		'canonical_url' => 'https://example.test/a',
	),
	array(
		'content_id' => 'site:post:4',
		'locale' => 'en',
		'object_type' => 'post',
		'object_id' => 4,
		'public_url' => 'https://example.test/a-1',
		'canonical_url' => 'https://example.test/b',
	),
);

$snapshot = MAD4B_SCP_Site_Bootstrap::finalize_snapshot( $base, $items, 4, 10 );
$check( true === $snapshot['complete'], 'bounded complete inventory was not complete' );
$check( true === $snapshot['bounded'], 'snapshot lost bounded marker' );
$check( 4 === $snapshot['total_item_count'] && 4 === $snapshot['returned_item_count'], 'item counts mismatch' );
$check( false === $snapshot['mutation_performed'] && false === $snapshot['authorizing'], 'bootstrap became mutating/authorizing' );
$check( false === $snapshot['backfill_performed'], 'bootstrap silently backfilled artifacts' );
$check( false === $snapshot['intent_claims_created'], 'bootstrap silently created intents' );
$check( 1 === preg_match( '/^[a-f0-9]{64}$/', $snapshot['snapshot_sha256'] ), 'snapshot digest invalid' );

$collisions = $snapshot['collision_analysis'];
$check( 1 === count( $collisions['duplicate_canonicals_same_locale'] ), 'same-locale canonical duplicate not detected exactly once' );
$duplicate = $collisions['duplicate_canonicals_same_locale'][0];
$check( 'en' === $duplicate['locale'], 'same-locale canonical collision collapsed locale identity' );
$check( array( 'site:post:1', 'site:post:2' ) === $duplicate['content_ids'], 'canonical owner ordering drifted' );
$check( 1 === count( $collisions['cross_locale_shared_canonicals'] ), 'cross-locale shared canonical not observed' );
$check( array( 'en', 'fr' ) === $collisions['cross_locale_shared_canonicals'][0]['locales'], 'cross-locale languages collapsed' );
$check( false === $collisions['cross_locale_shared_canonical_is_automatic_conflict'], 'multilingual shared canonical was auto-labeled conflict' );
$check( false === $collisions['cannibalization_automatic_from_topic_overlap'], 'topic overlap became automatic cannibalization' );
$check( 1 === count( $collisions['duplicate_public_urls'] ), 'duplicate public URL not detected' );

# Item order and observation timestamp must not change state identity.
$base2 = $base;
$base2['observed_at'] = '2026-09-25T01:00:00Z';
$items2 = array_reverse( $items );
$snapshot2 = MAD4B_SCP_Site_Bootstrap::finalize_snapshot( $base2, $items2, 4, 10 );
$check( hash_equals( $snapshot['snapshot_sha256'], $snapshot2['snapshot_sha256'] ), 'snapshot identity depends on row order or observation clock' );

# A bounded partial inventory is truthfully blocked, never silently accepted as complete.
$partial = MAD4B_SCP_Site_Bootstrap::finalize_snapshot( $base, array_slice( $items, 0, 2 ), 4, 2 );
$check( false === $partial['complete'], 'partial bounded inventory became complete' );
$check( in_array( 'inventory_bound_exceeded_or_incomplete', $partial['blocking_reasons'], true ), 'partial inventory omitted blocker reason' );

$check( '' === MAD4B_SCP_Site_Bootstrap::normalize_url( 'javascript:alert(1)' ), 'unsafe non-http URL was retained' );
$check( 'https://example.test/path' === MAD4B_SCP_Site_Bootstrap::normalize_url( 'HTTPS://Example.Test/path' ), 'URL normalization drifted' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-site-bootstrap.php' );
$main = file_get_contents( dirname( __DIR__ ) . '/mad4b-site-control-plane.php' );
$servers = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php' );
foreach ( array(
	"'mad4b/site-bootstrap-snapshot'",
	"'readonly' => true",
	"'destructive' => false",
	"'idempotent' => true",
	"'backfill_performed' => false",
	"'intent_claims_created' => false",
	"'artifacts_created' => false",
	"'cross_locale_shared_canonical_is_automatic_conflict' => false",
) as $marker ) {
	$check( false !== strpos( $source, $marker ), 'bootstrap contract marker missing: ' . $marker );
}
$check( false !== strpos( $main, 'class-mad4b-scp-site-bootstrap.php' ), 'bootstrap runtime is not loaded' );
$check( false !== strpos( $servers, "'mad4b/site-bootstrap-snapshot'" ), 'bootstrap snapshot is not mounted on read plane' );
foreach ( array(
	'$wpdb->insert',
	'$wpdb->update',
	'$wpdb->delete',
	'wp_insert_post(',
	'wp_update_post(',
	'update_post_meta(',
	'delete_post_meta(',
	'wp_set_object_terms(',
	'update_option(',
	'delete_option(',
	'shell_exec(',
	'proc_open(',
) as $forbidden ) {
	$check( false === strpos( $source, $forbidden ), 'bootstrap contains forbidden mutation primitive: ' . $forbidden );
}

echo "mad4b.site-content-bootstrap.v1: PASS\n";
