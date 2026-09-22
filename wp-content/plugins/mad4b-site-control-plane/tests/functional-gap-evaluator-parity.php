<?php
if ( PHP_SAPI !== 'cli' ) { fwrite( STDERR, "CLI required.\n" ); exit( 2 ); }

$root = dirname( __DIR__ );
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', $root . '/' );
if ( ! defined( 'MAD4B_SCP_DIR' ) ) define( 'MAD4B_SCP_DIR', $root . '/' );

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}

if ( $argc !== 4 ) {
	fwrite( STDERR, "Usage: php functional-gap-evaluator-parity.php <repository-evidence.json> <runtime.json> <python-output.json>\n" );
	exit( 2 );
}

$load = static function ( $path ) {
	$raw = file_get_contents( $path );
	$data = false === $raw ? null : json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		fwrite( STDERR, "Invalid JSON: " . $path . "\n" );
		exit( 3 );
	}
	return array( $raw, $data );
};

list( $repo_raw, $repository ) = $load( $argv[1] );
list( $_runtime_raw, $runtime ) = $load( $argv[2] );
list( $_python_raw, $python ) = $load( $argv[3] );

$repository['valid'] = true;
$repository['evidence_sha256'] = hash( 'sha256', $repo_raw );
$repository['blockers'] = array();

require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-functional-gap-evidence.php';

$reflection = new ReflectionClass( 'MAD4B_SCP_Functional_Gap_Evidence' );
$digest_method = $reflection->getMethod( 'canonical_digest' );
$digest_method->setAccessible( true );
$canonical_vectors = array(
	array( array(), '75ba621010ccaf63e7ef664ef0ecbbf26ca60506903cc05e68cba6011d8d692b' ),
	array( array( 'a'=>true, 'b'=>array(), 'c'=>'✓' ), '859b873c5b7837892deb70de68ea0fb70bc74d09c294f08d689265645b339d9b' ),
	array( array( 1, false, null, 'x' ), '8ffca4dff324e9febf88bc81127a38695e5746f1d8abffd8487d911bdeb26ca3' ),
	array( array( 'empty'=>array(), 'list'=>array() ), '2b6c715a39a7f4840cda82a57b503fc3284d34b65ae100e3ae103a1f74de084b' ),
);
foreach ( $canonical_vectors as $vector ) {
	$actual = $digest_method->invoke( null, $vector[0] );
	if ( ! hash_equals( $vector[1], (string) $actual ) ) {
		fwrite( STDERR, "Canonical digest known-answer mismatch: expected {$vector[1]} got {$actual}\n" );
		exit( 4 );
	}
}

$method = $reflection->getMethod( 'evaluate' );
$method->setAccessible( true );
$php = $method->invoke( null, $repository, $runtime );

$fail = static function ( $message, $php = null, $python = null ) {
	fwrite( STDERR, $message . "\n" );
	if ( null !== $php ) fwrite( STDERR, "PHP: " . json_encode( $php, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	if ( null !== $python ) fwrite( STDERR, "PY : " . json_encode( $python, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	exit( 4 );
};

foreach ( array( 'contract','policy_contract','policy_sha256','runtime_evidence_fingerprint','decision_fingerprint','ready','promotion_authorized','production_mutation' ) as $key ) {
	$left = array_key_exists( $key, $php ) ? $php[ $key ] : null;
	$right = array_key_exists( $key, $python ) ? $python[ $key ] : null;
	if ( $left !== $right ) $fail( 'Cross-language evaluator mismatch: ' . $key, $left, $right );
}

$normalize_decisions = static function ( $rows ) {
	$out = array();
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		if ( ! is_array( $row ) || empty( $row['family'] ) ) continue;
		$family = (string) $row['family'];
		$out[ $family ] = array(
			'state' => isset( $row['state'] ) ? (string) $row['state'] : '',
			'reason' => isset( $row['reason'] ) ? (string) $row['reason'] : '',
			'evaluation_mode' => isset( $row['evaluation_mode'] ) ? (string) $row['evaluation_mode'] : '',
			'runtime_versions' => isset( $row['runtime_versions'] ) && is_array( $row['runtime_versions'] ) ? array_values( $row['runtime_versions'] ) : array(),
			'runtime_tree_evidence' => isset( $row['runtime_tree_evidence'] ) && is_array( $row['runtime_tree_evidence'] ) ? $row['runtime_tree_evidence'] : array(),
			'exact_tree_matches' => isset( $row['exact_tree_matches'] ) && is_array( $row['exact_tree_matches'] ) ? $row['exact_tree_matches'] : array(),
		);
	}
	ksort( $out, SORT_STRING );
	return $out;
};

$php_decisions = $normalize_decisions( isset( $php['decisions'] ) ? $php['decisions'] : array() );
$python_decisions = $normalize_decisions( isset( $python['decisions'] ) ? $python['decisions'] : array() );
if ( $php_decisions !== $python_decisions ) $fail( 'Cross-language evaluator decision projection mismatch.', $php_decisions, $python_decisions );

$php_counts = isset( $php['counts'] ) && is_array( $php['counts'] ) ? $php['counts'] : array();
$python_counts = isset( $python['counts'] ) && is_array( $python['counts'] ) ? $python['counts'] : array();
ksort( $php_counts, SORT_STRING );
ksort( $python_counts, SORT_STRING );
if ( $php_counts !== $python_counts ) $fail( 'Cross-language evaluator count mismatch.', $php_counts, $python_counts );

$php_blockers = isset( $php['blockers'] ) && is_array( $php['blockers'] ) ? array_values( $php['blockers'] ) : array();
$python_blockers = isset( $python['blockers'] ) && is_array( $python['blockers'] ) ? array_values( $python['blockers'] ) : array();
sort( $php_blockers, SORT_STRING );
sort( $python_blockers, SORT_STRING );
if ( $php_blockers !== $python_blockers ) $fail( 'Cross-language evaluator blocker mismatch.', $php_blockers, $python_blockers );

echo "mad4b.functional-gap-evaluator-parity.v1: PASS families=" . count( $php_decisions ) . PHP_EOL;
