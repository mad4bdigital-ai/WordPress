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

$before_document = $adapter->get_document( array( 'post_id' => $target_id ) );
$check( ! is_wp_error( $before_document ) && preg_match( '/^[a-f0-9]{64}$/', (string) ( $before_document['sha256'] ?? '' ) ), 'Disposable target document must expose exact SHA-256 before mutation.' );
$before_sha = (string) $before_document['sha256'];
$mutation_input = array(
	'post_id' => $target_id,
	'element_id' => 'aaa1111',
	'target_setting' => 'gallery',
	'tag_name' => 'etg-filter-gallery',
	'mode' => 'combined',
	'limit' => 9,
	'expected_sha256' => $before_sha,
);
$rollback = $adapter->capture_reversible_state( 'elementor/set-etg-dynamic-tag', $mutation_input );
$check( ! is_wp_error( $rollback ) && 'elementor-structural-element' === (string) ( $rollback['target_type'] ?? '' ), 'Canonical ETG dynamic-tag mutation must capture bounded rollback state.' );

$mutation = $adapter->set_etg_dynamic_tag( $mutation_input );
$check( ! is_wp_error( $mutation ), 'Canonical ETG Gallery dynamic-tag mutation failed on exact certified Elementor runtime.' );
$check( ! empty( $mutation['updated'] ) && 'etg-filter-gallery' === (string) ( $mutation['dynamic_tag_name'] ?? '' ), 'Canonical ETG Gallery mutation did not report exact updated tag.' );
$check( preg_match( '/^[a-f0-9]{64}$/', (string) ( $mutation['sha256'] ?? '' ) ) && ! hash_equals( $before_sha, (string) $mutation['sha256'] ), 'Canonical ETG Gallery mutation must change the document SHA.' );

$dynamic_readback = $adapter->get_dynamic_tags( array( 'post_id' => $target_id ) );
$check( ! is_wp_error( $dynamic_readback ), 'Dynamic-tag readback failed after canonical ETG Gallery mutation.' );
$gallery_binding = '';
foreach ( (array) ( $dynamic_readback['dynamic_tags'] ?? array() ) as $entry ) {
	if ( 'aaa1111' === (string) ( $entry['element_id'] ?? '' ) && 'gallery' === (string) ( $entry['setting'] ?? '' ) ) {
		$gallery_binding = (string) ( $entry['tag'] ?? '' );
		break;
	}
}
$check( '' !== $gallery_binding, 'Canonical ETG Gallery binding was not persisted in Elementor __dynamic__ state.' );
$parsed_gallery = $parse_method->invoke( $adapter, $gallery_binding );
$check( ! is_wp_error( $parsed_gallery ) && 'etg-filter-gallery' === (string) ( $parsed_gallery['name'] ?? '' ), 'Persisted ETG Gallery binding did not round-trip through Elementor parser.' );
$check( 'combined' === (string) ( $parsed_gallery['settings']['mode'] ?? '' ) && 9 === (int) ( $parsed_gallery['settings']['limit'] ?? 0 ), 'Persisted ETG Gallery settings drifted during readback.' );

$stale = $adapter->set_etg_dynamic_tag( $mutation_input );
$check( is_wp_error( $stale ) && 'mad4b_elementor_stale_document' === $stale->get_error_code(), 'Replaying canonical ETG mutation with stale document SHA must fail closed.' );

$restored = $adapter->restore_reversible_state(
	'elementor/set-etg-dynamic-tag',
	(array) $rollback['target'],
	(array) $rollback['state'],
	array()
);
$check( true === $restored, 'Canonical ETG Gallery rollback failed.' );
$restored_document = $adapter->get_document( array( 'post_id' => $target_id ) );
$check( ! is_wp_error( $restored_document ) && hash_equals( $before_sha, (string) ( $restored_document['sha256'] ?? '' ) ), 'Canonical ETG Gallery rollback did not restore exact pre-mutation document SHA.' );

wp_delete_post( $source_id, true );
wp_delete_post( $target_id, true );
echo "mad4b.elementor-etg-structural-primitives.runtime.v1: PASS\n";
