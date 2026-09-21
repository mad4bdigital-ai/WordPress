<?php
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );

$check = static function ( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
};

$check( current_user_can( 'manage_options' ), 'Elementor structural runtime smoke requires administrator authority.' );
$check( defined( 'ELEMENTOR_VERSION' ), 'Exact Elementor runtime is unavailable.' );
$check( class_exists( '\\Elementor\\Plugin' ), 'Elementor Plugin class is unavailable.' );
$check( isset( \Elementor\Plugin::$instance->dynamic_tags ), 'Elementor Dynamic Tags manager is unavailable.' );
$manager = \Elementor\Plugin::$instance->dynamic_tags;
foreach ( array( 'tag_data_to_tag_text', 'tag_text_to_tag_data', 'create_tag' ) as $method ) {
	$check( is_callable( array( $manager, $method ) ), 'Elementor Dynamic Tags manager method missing: ' . $method );
}
$check( class_exists( 'MAD4B_SCP_Elementor_Adapter' ), 'MAD4B Elementor adapter is unavailable.' );

$adapter = new MAD4B_SCP_Elementor_Adapter();
$reflection = new ReflectionClass( $adapter );
$settings_method = $reflection->getMethod( 'canonical_etg_dynamic_settings' );
$settings_method->setAccessible( true );
$build_method = $reflection->getMethod( 'build_elementor_dynamic_tag' );
$build_method->setAccessible( true );
$parse_method = $reflection->getMethod( 'parse_elementor_dynamic_tag' );
$parse_method->setAccessible( true );

$cases = array(
	array( 'name' => 'etg-filter-title', 'input' => array() ),
	array( 'name' => 'etg-filter-intro', 'input' => array() ),
	array( 'name' => 'etg-filter-result-summary', 'input' => array() ),
	array( 'name' => 'etg-filter-image', 'input' => array( 'mode' => 'priority' ) ),
	array( 'name' => 'etg-filter-gallery', 'input' => array( 'mode' => 'combined', 'limit' => 9 ) ),
);
foreach ( $cases as $case ) {
	$settings = $settings_method->invoke( $adapter, $case['name'], $case['input'] );
	$check( ! is_wp_error( $settings ) && is_array( $settings ), 'Canonical ETG settings failed for ' . $case['name'] );
	$tag = $build_method->invoke( $adapter, 1001, 'deadbeef', 'title', $case['name'], $settings );
	$check( ! is_wp_error( $tag ) && is_string( $tag ) && '' !== $tag, 'Elementor manager failed to serialize ' . $case['name'] );
	$parsed = $parse_method->invoke( $adapter, $tag );
	$check( ! is_wp_error( $parsed ) && is_array( $parsed ), 'Elementor manager failed to parse ' . $case['name'] );
	$check( $case['name'] === (string) ( $parsed['name'] ?? '' ), 'ETG tag round-trip name mismatch for ' . $case['name'] );
}

$source_id = wp_insert_post( array( 'post_title' => 'MAD4B Elementor Source', 'post_status' => 'draft', 'post_type' => 'page' ), true );
$target_id = wp_insert_post( array( 'post_title' => 'MAD4B Elementor Target', 'post_status' => 'draft', 'post_type' => 'page' ), true );
$check( ! is_wp_error( $source_id ) && ! is_wp_error( $target_id ), 'Unable to create disposable Elementor comparison posts.' );

$source_elements = array(
	array( 'id' => 'aaa1111', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( '__dynamic__' => array( 'title' => 'source-tag' ) ), 'elements' => array() ),
	array( 'id' => 'bbb2222', 'elType' => 'container', 'settings' => array(), 'elements' => array() ),
);
$target_elements = array(
	array( 'id' => 'aaa1111', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( '__dynamic__' => array( 'title' => 'target-tag' ) ), 'elements' => array() ),
);
update_post_meta( $source_id, '_elementor_data', wp_slash( wp_json_encode( $source_elements ) ) );
update_post_meta( $target_id, '_elementor_data', wp_slash( wp_json_encode( $target_elements ) ) );

$comparison = $adapter->compare_documents( array( 'source_post_id' => $source_id, 'target_post_id' => $target_id ) );
$check( ! is_wp_error( $comparison ), 'Elementor compare-documents failed on disposable documents.' );
$check( 'mad4b.elementor-document-comparison.v1' === (string) ( $comparison['contract'] ?? '' ), 'Unexpected Elementor comparison contract.' );
$check( in_array( 'bbb2222', (array) ( $comparison['missing_in_target'] ?? array() ), true ), 'Elementor comparison did not report missing source element.' );
$check( ! empty( $comparison['dynamic_tag_drift'] ), 'Elementor comparison did not report dynamic-tag drift.' );
$check( empty( $comparison['parity'] ), 'Drifted Elementor documents were incorrectly reported as parity.' );
$check( ! empty( $comparison['read_only'] ), 'Elementor comparator must remain read-only.' );

wp_delete_post( $source_id, true );
wp_delete_post( $target_id, true );
echo "mad4b.elementor-etg-structural-primitives.runtime.v1: PASS\n";
