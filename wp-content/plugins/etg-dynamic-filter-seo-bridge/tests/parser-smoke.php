<?php
declare( strict_types=1 );

function wp_unslash( $value ) { return $value; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_title( $title ) { $title = preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( trim( (string) $title ) ) ); return trim( $title, '-' ); }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }

require_once __DIR__ . '/../includes/JetSmartFilters/FilterUrlParser.php';
$parser = new ETG\DynamicFilterSEOBridge\JetSmartFilters\FilterUrlParser();

$result = $parser->parse( '/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo;tour-types_jet:day-tours;tour-style_jet:luxury/' );
assert( true === $result['active'] );
assert( 'tours-and-activities' === $result['archive'] );
assert( 'jet-engine' === $result['provider'] );
assert( 'tours_query_archive' === $result['query_id'] );
assert( array( 'location_jet' => 'cairo', 'tour-types_jet' => 'day-tours', 'tour-style_jet' => 'luxury' ) === $result['filters'] );
assert( array() === $result['unknown_filters'] );
assert( array() === $result['malformed'] );

$unknown = $parser->parse( '/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo;unexpected_tax:unsafe/' );
assert( array( 'location_jet' => 'cairo' ) === $unknown['filters'] );
assert( array( 'unexpected_tax' => 'unsafe' ) === $unknown['unknown_filters'] );

$malformed = $parser->parse( '/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo;broken-pair/' );
assert( array( 'broken-pair' ) === $malformed['malformed'] );

$inactive = $parser->parse( '/tours-and-activities/' );
assert( false === $inactive['active'] );
fwrite( STDOUT, "FilterUrlParser smoke tests passed.\n" );
