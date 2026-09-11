<?php
declare( strict_types=1 );
function wp_unslash( $value ) { return $value; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_title( $title ) { $title = preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( trim( (string) $title ) ) ); return trim( $title, '-' ); }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function expect_same( $expected, $actual, string $message ): void { if ( $expected !== $actual ) { fwrite( STDERR, "FAILED: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" ); exit( 1 ); } }
require_once __DIR__ . '/../includes/JetSmartFilters/FilterUrlParser.php';
$parser = new ETG\DynamicFilterSEOBridge\JetSmartFilters\FilterUrlParser( array(), array(), array( 'gclid', 'fbclid' ) );
$result = $parser->parse( '/it/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo;tour-types_jet:day-tours;tour-styles_jet:luxury/?utm_source=x&gclid=123' );
expect_same( true, $result['active'], 'acceptance URL active' );
expect_same( '/it/tours-and-activities/', $result['archive_path'], 'language-aware archive path' );
expect_same( 'tours-and-activities', $result['archive'], 'archive slug' );
expect_same( 'jet-engine', $result['provider'], 'provider' );
expect_same( 'tours_query_archive', $result['query_id'], 'query id' );
expect_same( array( 'location_jet' => 'cairo', 'tour-types_jet' => 'day-tours', 'tour-styles_jet' => 'luxury' ), $result['filters'], 'filter map' );
expect_same( array(), $result['unsupported_query_params'], 'tracking parameters are not functional state' );
expect_same( array( 'gclid' => '123', 'utm_source' => 'x' ), $result['tracking_query_params'], 'tracking state captured' );
$unknown = $parser->parse( '/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo;unexpected_tax:unsafe/' );
expect_same( array( 'unexpected_tax' => 'unsafe' ), $unknown['unknown_filters'], 'unknown taxonomy captured' );
$duplicate = $parser->parse( '/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo;location_jet:giza/' );
expect_same( array( 'duplicate:location_jet' ), $duplicate['malformed'], 'duplicate taxonomy hard-fails grammar' );
$multi = $parser->parse( '/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo,giza/' );
expect_same( array( 'multi_value:location_jet' ), $multi['malformed'], 'unsupported multi-value state hard-fails' );
$query = $parser->parse( '/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo/?sort=price' );
expect_same( array( 'sort' => 'price' ), $query['unsupported_query_params'], 'unknown functional query state captured' );
$paged = $parser->parse( '/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo/?paged=2' );
expect_same( 2, $paged['pagination_page'], 'pagination page classified' );
expect_same( array( 'paged' => '2' ), $paged['unsupported_query_params'], 'page > 1 fails closed' );
$inactive = $parser->parse( '/tours-and-activities/' );
expect_same( false, $inactive['active'], 'plain archive inactive' );
$queryStringFilter = $parser->parse( '/?tour-types_jet=day-tours' );
expect_same( false, $queryStringFilter['active'], 'query-string taxonomy state remains outside JSF-path authority' );
expect_same( array( 'tour-types_jet' => 'day-tours' ), $queryStringFilter['unsupported_query_params'], 'query-string taxonomy state is not silently promoted' );
$staleSingularStyle = $parser->parse( '/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/tour-style_jet:archaeology-tours/' );
expect_same( array( 'tour-style_jet' => 'archaeology-tours' ), $staleSingularStyle['unknown_filters'], 'stale singular Production taxonomy slug remains unprofiled' );
fwrite( STDOUT, "FilterUrlParser smoke tests passed.\n" );
