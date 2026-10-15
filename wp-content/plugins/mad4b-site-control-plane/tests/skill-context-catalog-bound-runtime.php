<?php
/** Native PHP 7.4+ regression: exact semantic catalog may exceed custom policy cap. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = null ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class MAD4B_SCP_Semantic_Content_Field_Contracts {
    public static $abilities = array();
    public static function declared_abilities() { return self::$abilities; }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-preflight.php';
$method = new ReflectionMethod( 'MAD4B_SCP_Context_Preflight', 'normalize_mutation_abilities' );
$method->setAccessible( true );
$evaluate = static function ( $input ) use ( $method ) { return $method->invoke( null, $input ); };
$check = static function ( $condition, $description ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $description . PHP_EOL ); exit( 1 ); }
};
$names = array();
for ( $i = 1; $i <= 23; $i++ ) $names[] = sprintf( 'mad4b/test-content-apply-%02d', $i );
MAD4B_SCP_Semantic_Content_Field_Contracts::$abilities = $names;
$canonical = $evaluate( array_reverse( $names ) );
$check( ! is_wp_error( $canonical ) && $canonical === $names, 'Canonical full 23-entry registry must normalize' );
$custom = $evaluate( array_slice( $names, 0, 20 ) );
$check( ! is_wp_error( $custom ) && count( $custom ) === 20, 'Bounded custom policy remains valid' );
$partial = $evaluate( array_slice( $names, 0, 21 ) );
$check( is_wp_error( $partial ) && 'mad4b_skill_context_mutation_abilities_limit' === $partial->get_error_code(), '21-entry partial policy must stay blocked' );
$duplicate = $names;
$duplicate[22] = $duplicate[21];
$denied = $evaluate( $duplicate );
$check( is_wp_error( $denied ) && 'mad4b_skill_context_mutation_abilities_limit' === $denied->get_error_code(), 'Duplicated full-size list cannot pass as catalog' );
$foreign = $names;
$foreign[22] = 'other/unregistered-mutation';
$denied = $evaluate( $foreign );
$check( is_wp_error( $denied ) && 'mad4b_skill_context_mutation_ability_invalid' === $denied->get_error_code(), 'Foreign ability is never admitted' );
$denied = $evaluate( array_merge( $names, array( $names[0] ) ) );
$check( is_wp_error( $denied ), 'Overlong duplicated list is rejected before normalization' );
$names[] = 'mad4b/test-content-apply-24';
MAD4B_SCP_Semantic_Content_Field_Contracts::$abilities = $names;
$stale = $evaluate( array_slice( $names, 0, 23 ) );
$check( is_wp_error( $stale ) && 'mad4b_skill_context_mutation_abilities_limit' === $stale->get_error_code(), 'Stale broad policy must not inherit a new ability automatically' );
$fresh = $evaluate( $names );
$check( ! is_wp_error( $fresh ) && $fresh === $names, 'New exact catalog becomes valid after governed reconciliation' );
echo "PASS: bounded custom policy, full dynamic catalog, stale/partial/duplicate/foreign denial\n";
