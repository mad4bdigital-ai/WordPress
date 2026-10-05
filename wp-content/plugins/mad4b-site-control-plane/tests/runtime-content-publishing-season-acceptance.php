<?php
/**
 * Disposable acceptance proof for the exact content flow requested before release:
 * publish a Page, publish a Trip, create seasonal terms (صيف/شتاء), and bind one
 * season to the Trip. The persistent taxonomy provider route is asserted as a
 * registered governed Ability; provider execution is certified separately.
 */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
if ( ! isset( $check ) || ! is_callable( $check ) ) {
	$check = static function ( $condition, $message ) {
		if ( ! $condition ) throw new RuntimeException( $message );
	};
}

$trip_type = 'mad4b_ci_trip_accept';
$season_taxonomy = 'mad4b_ci_season';
$post_ids = array();
$term_ids = array();

register_post_type(
	$trip_type,
	array(
		'label' => 'MAD4B Acceptance Trips',
		'public' => true,
		'show_ui' => true,
		'show_in_rest' => true,
		'supports' => array( 'title', 'editor', 'excerpt' ),
		'capability_type' => 'post',
		'map_meta_cap' => true,
	)
);
register_taxonomy(
	$season_taxonomy,
	array( $trip_type ),
	array(
		'label' => 'المواسم',
		'public' => true,
		'show_ui' => true,
		'show_in_rest' => true,
		'hierarchical' => false,
	)
);

try {
	foreach ( array(
		'mad4b/content-create-post',
		'mad4b/taxonomy-create-term',
		'mad4b/taxonomy-set-object-terms',
	) as $ability_name ) {
		$check( wp_has_ability( $ability_name ), 'Required governed content Ability is not registered: ' . $ability_name );
	}

	// Persistent provider route must be discoverable in the runtime catalog.
	$check( wp_has_ability( 'jetengine/create-taxonomy' ), 'JetEngine persistent taxonomy creation Ability is not registered.' );

	$adapter = MAD4B_SCP_Adapter_Registry::instance()->get( 'core-content-modeling' );
	$check( $adapter instanceof MAD4B_SCP_Core_Content_Modeling_Adapter, 'Core Content Modeling adapter is not registered.' );
	$check( $adapter->is_available(), 'Core Content Modeling adapter is not runtime-available.' );

	$page = $adapter->create_post(
		array(
			'post_type' => 'page',
			'post_title' => 'MAD4B Acceptance Page',
			'post_content' => 'Disposable acceptance content.',
			'post_status' => 'publish',
		)
	);
	$check( ! is_wp_error( $page ) && ! empty( $page['post_id'] ), 'Acceptance Page creation failed.' );
	$page_id = (int) $page['post_id'];
	$post_ids[] = $page_id;
	$check( 'publish' === get_post_status( $page_id ), 'Acceptance Page was not published.' );
	$check( '' !== (string) get_permalink( $page_id ), 'Acceptance Page has no public permalink.' );

	$trip = $adapter->create_post(
		array(
			'post_type' => $trip_type,
			'post_title' => 'MAD4B Acceptance Trip',
			'post_content' => 'Disposable acceptance trip content.',
			'post_status' => 'publish',
		)
	);
	$check( ! is_wp_error( $trip ) && ! empty( $trip['post_id'] ), 'Acceptance Trip creation failed.' );
	$trip_id = (int) $trip['post_id'];
	$post_ids[] = $trip_id;
	$check( 'publish' === get_post_status( $trip_id ), 'Acceptance Trip was not published.' );
	$check( '' !== (string) get_permalink( $trip_id ), 'Acceptance Trip has no public permalink.' );

	$summer = $adapter->create_term(
		array(
			'taxonomy' => $season_taxonomy,
			'name' => 'صيف',
			'slug' => 'summer',
		)
	);
	$check( ! is_wp_error( $summer ) && ! empty( $summer['term_id'] ), 'Summer term creation failed.' );
	$summer_id = (int) $summer['term_id'];
	$term_ids[] = $summer_id;

	$winter = $adapter->create_term(
		array(
			'taxonomy' => $season_taxonomy,
			'name' => 'شتاء',
			'slug' => 'winter',
		)
	);
	$check( ! is_wp_error( $winter ) && ! empty( $winter['term_id'] ), 'Winter term creation failed.' );
	$winter_id = (int) $winter['term_id'];
	$term_ids[] = $winter_id;

	$assignment = $adapter->set_object_terms(
		array(
			'object_id' => $trip_id,
			'taxonomy' => $season_taxonomy,
			'term_ids' => array( $summer_id ),
			'expected_term_ids' => array(),
			'append' => false,
		)
	);
	$check( ! is_wp_error( $assignment ) && ! empty( $assignment['verified'] ), 'Trip season assignment failed.' );

	$assigned = wp_get_object_terms( $trip_id, $season_taxonomy, array( 'fields' => 'ids' ) );
	$assigned = is_wp_error( $assigned ) ? array() : array_values( array_map( 'intval', $assigned ) );
	sort( $assigned, SORT_NUMERIC );
	$check( array( $summer_id ) === $assigned, 'Trip season readback does not match Summer.' );

	$terms = get_terms(
		array(
			'taxonomy' => $season_taxonomy,
			'hide_empty' => false,
			'orderby' => 'term_id',
			'order' => 'ASC',
		)
	);
	$check( ! is_wp_error( $terms ), 'Season taxonomy readback failed.' );
	$names = array();
	foreach ( $terms as $term ) $names[] = (string) $term->name;
	$check( in_array( 'صيف', $names, true ) && in_array( 'شتاء', $names, true ), 'Season taxonomy does not contain both صيف and شتاء.' );

	echo "mad4b.content-publishing-season-acceptance.v1: PASS page={$page_id} trip={$trip_id} summer={$summer_id} winter={$winter_id}\n";
} finally {
	foreach ( array_reverse( array_unique( array_map( 'absint', $post_ids ) ) ) as $id ) {
		if ( $id > 0 ) wp_delete_post( $id, true );
	}
	foreach ( array_reverse( array_unique( array_map( 'absint', $term_ids ) ) ) as $id ) {
		if ( $id > 0 && taxonomy_exists( $season_taxonomy ) ) wp_delete_term( $id, $season_taxonomy );
	}
	if ( taxonomy_exists( $season_taxonomy ) ) unregister_taxonomy( $season_taxonomy );
	if ( post_type_exists( $trip_type ) ) unregister_post_type( $trip_type );
}
