<?php
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );

$ci_phase = getenv( 'MAD4B_CI_CONTENT_EXPERIENCE_PHASE' );
if ( is_string( $ci_phase ) && '' !== $ci_phase ) {
	$check = static function ( $condition, $message ) {
		if ( ! $condition ) throw new RuntimeException( $message );
	};
	register_post_type( 'mad4b_ci_trip', array(
		'label' => 'MAD4B CI Trips', 'public' => true, 'show_ui' => true, 'show_in_rest' => true,
		'supports' => array( 'title', 'editor', 'excerpt', 'thumbnail' ), 'capability_type' => 'post', 'map_meta_cap' => true,
	) );
	register_taxonomy( 'mad4b_ci_region', array( 'mad4b_ci_trip' ), array( 'label' => 'MAD4B CI Regions', 'public' => true, 'show_ui' => true ) );

	$profile = MAD4B_SCP_Content_Experience_Profiles::profile( 'ci-trip' );
	$check( ! is_wp_error( $profile ), 'Next-request Content Experience profile is unavailable.' );
	$expected_revision = 'r2' === $ci_phase ? 2 : 1;
	$check( $expected_revision === (int) $profile['revision'], 'Next-request Content Experience profile revision mismatch.' );
	$routes = $profile['routes'];
	foreach ( array( 'create_apply', 'update_apply', 'publish_apply' ) as $route_key ) {
		$check( ! empty( $routes[ $route_key ] ) && wp_has_ability( $routes[ $route_key ] ), 'Generated next-request executor Ability is not registered: ' . $route_key );
	}

	$create_ability = wp_get_ability( $routes['create_apply'] );
	$update_ability = wp_get_ability( $routes['update_apply'] );
	$publish_ability = wp_get_ability( $routes['publish_apply'] );
	$profile_apply_ability = wp_get_ability( MAD4B_SCP_Content_Experience_Profiles::PROFILE_APPLY_ABILITY );
	$check( true === $create_ability->get_meta()['annotations']['idempotent'], 'Create replay must remain explicitly idempotent.' );
	$check( false === $update_ability->get_meta()['annotations']['idempotent'], 'Update must not claim retry idempotency after modified-state drift.' );
	$check( false === $publish_ability->get_meta()['annotations']['idempotent'], 'Publish must not claim retry idempotency after status drift.' );
	$check( false === $profile_apply_ability->get_meta()['annotations']['idempotent'], 'Profile apply must be exact one-shot revision mutation.' );

	$descriptor_route = 'r2' === $ci_phase ? $routes['update_apply'] : $routes['create_apply'];
	$descriptor = MAD4B_SCP_Capability_Descriptor_Registry::binding( $descriptor_route, 'content_experience_runtime_smoke_' . $ci_phase );
	$check( ! is_wp_error( $descriptor ), 'Generated executor Capability Descriptor binding failed in next request.' );
	$check(
		isset( $descriptor['generation_roots']['extension_roots']['content_experience_profile'] )
		&& hash_equals( (string) $profile['authority_sha256'], (string) $descriptor['generation_roots']['extension_roots']['content_experience_profile'] ),
		'Generated next-request executor descriptor is not bound to exact profile authority.'
	);
	echo 'mad4b.site-control-plane.runtime-content-experience.' . $ci_phase . ": PASS\n";
	return;
}

if ( ! isset( $check ) || ! is_callable( $check ) ) throw new RuntimeException( 'Parent runtime check helper is unavailable.' );

$option = MAD4B_SCP_Content_Experience_Profiles::OPTION;
$original_profiles = get_option( $option, array() );
$post_ids = array();
$term_ids = array();
$gallery_image_filter = null;

$launch_next_request = static function ( $phase ) use ( $check ) {
	$reflection = new ReflectionClass( 'WP_CLI' );
	$bootstrap_file = (string) $reflection->getFileName();
	$wp_cli_phar = '';
	if ( preg_match( '#^phar://(.+?\\.phar)/#', $bootstrap_file, $matches ) ) $wp_cli_phar = $matches[1];
	if ( '' === $wp_cli_phar && isset( $_SERVER['argv'][0] ) ) $wp_cli_phar = realpath( (string) $_SERVER['argv'][0] );
	$check( is_string( $wp_cli_phar ) && '' !== $wp_cli_phar && is_file( $wp_cli_phar ), 'Unable to resolve the active WP-CLI PHAR for next-request Content Experience proof.' );
	$check( function_exists( 'exec' ), 'PHP exec() is required by the disposable next-request Content Experience runtime proof.' );

	$command = 'MAD4B_CI_CONTENT_EXPERIENCE_PHASE=' . escapeshellarg( (string) $phase )
		. ' ' . escapeshellarg( PHP_BINARY )
		. ' ' . escapeshellarg( $wp_cli_phar )
		. ' eval-file ' . escapeshellarg( __FILE__ )
		. ' --path=' . escapeshellarg( ABSPATH )
		. ' --user=' . escapeshellarg( (string) get_current_user_id() );
	$output = array();
	$exit_code = 0;
	exec( $command . ' 2>&1', $output, $exit_code );
	if ( 0 !== $exit_code ) throw new RuntimeException( "Next-request Content Experience proof failed for {$phase}:\n" . implode( "\n", $output ) );
	$marker = 'mad4b.site-control-plane.runtime-content-experience.' . $phase . ': PASS';
	$check( in_array( $marker, $output, true ), 'Next-request Content Experience proof did not emit its PASS marker.' );
};

register_post_type( 'mad4b_ci_trip', array(
	'label' => 'MAD4B CI Trips', 'public' => true, 'show_ui' => true, 'show_in_rest' => true,
	'supports' => array( 'title', 'editor', 'excerpt', 'thumbnail' ), 'capability_type' => 'post', 'map_meta_cap' => true,
) );
register_taxonomy( 'mad4b_ci_region', array( 'mad4b_ci_trip' ), array( 'label' => 'MAD4B CI Regions', 'public' => true, 'show_ui' => true ) );

try {
	$term = wp_insert_term( 'Cairo', 'mad4b_ci_region', array( 'slug' => 'cairo' ) );
	$check( ! is_wp_error( $term ), 'Unable to create Content Experience taxonomy fixture.' );
	$term_ids[] = (int) $term['term_id'];

	$image_one = wp_insert_post( array(
		'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'CI Trip Main Image',
		'post_mime_type' => 'image/jpeg', 'post_excerpt' => 'Initial caption', 'post_content' => 'Initial description',
	), true );
	$image_two = wp_insert_post( array(
		'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'CI Trip Gallery Image',
		'post_mime_type' => 'image/jpeg',
	), true );
	$brochure = wp_insert_post( array(
		'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'CI Trip Brochure',
		'post_mime_type' => 'application/pdf',
	), true );
	$check( ! is_wp_error( $image_one ) && ! is_wp_error( $image_two ) && ! is_wp_error( $brochure ), 'Unable to create media fixtures.' );
	$image_one = (int) $image_one; $image_two = (int) $image_two; $brochure = (int) $brochure;
	$post_ids[] = $image_one; $post_ids[] = $image_two; $post_ids[] = $brochure;
	// WordPress image-type checks require a real attachment-file binding, not
	// only an image/* MIME value. Keep the disposable fixture file-less on disk,
	// but model Media Library identity through the canonical attachment API.
	$check( update_attached_file( $image_one, 'ci-trip-main.jpg' ), 'Unable to bind main image attachment file identity.' );
	$check( update_attached_file( $image_two, 'ci-trip-gallery.jpg' ), 'Unable to bind gallery image attachment file identity.' );
	update_post_meta( $image_one, '_wp_attachment_image_alt', 'Initial alt' );
	wp_update_attachment_metadata( $image_one, array(
		'width' => 1600, 'height' => 900, 'file' => 'ci-trip-main.jpg', 'filesize' => 12345,
		'sizes' => array( 'thumbnail' => array( 'file' => 'ci-trip-main-150x150.jpg', 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg', 'filesize' => 1234 ) ),
		'image_meta' => array( 'credit' => 'MAD4B CI', 'camera' => 'Synthetic Fixture', 'copyright' => 'CI only' ),
	) );
	wp_update_attachment_metadata( $image_two, array( 'width' => 1200, 'height' => 800, 'file' => 'ci-trip-gallery.jpg', 'filesize' => 10000 ) );

	$gallery_image_ids = array( $image_one, $image_two );
	$gallery_image_filter = static function ( $html, $attachment ) use ( $gallery_image_ids ) {
		return in_array( (int) $attachment, $gallery_image_ids, true ) ? '<img src="ci-gallery.jpg" alt="" />' : $html;
	};
	add_filter( 'wp_get_attachment_image', $gallery_image_filter, 10, 2 );

	$media_adapter = MAD4B_SCP_Adapter_Registry::instance()->get( 'media' );
	$check( $media_adapter instanceof MAD4B_SCP_Media_Adapter, 'Media adapter is unavailable.' );
	$media_before = $media_adapter->get_media( array( 'attachment_id' => $image_one ) );
	$check( ! is_wp_error( $media_before ) && 1600 === (int) $media_before['media']['width'] && 900 === (int) $media_before['media']['height'], 'Image technical metadata readback is incomplete.' );
	$technical_sha = (string) $media_before['media']['metadata_sha256'];
	$media_after = $media_adapter->update_metadata( array(
		'attachment_id' => $image_one,
		'expected_sha256' => $media_before['sha256'],
		'title' => 'Cairo Nile Experience',
		'caption' => 'Cairo and Nile journey gallery image',
		'description' => '<p>Editorial description for the trip image.</p>',
		'alt' => 'Cairo Nile journey in Egypt',
	) );
	$check(
		! is_wp_error( $media_after ),
		'Governed media metadata update failed'
			. ( is_wp_error( $media_after ) ? ': ' . $media_after->get_error_code() . ' — ' . $media_after->get_error_message() : '' )
			. '.'
	);
	$check( 'Cairo Nile Experience' === $media_after['media']['title'], 'Media title readback mismatch.' );
	$check( 'Cairo and Nile journey gallery image' === $media_after['media']['caption'], 'Media caption readback mismatch.' );
	$check( 'Cairo Nile journey in Egypt' === $media_after['media']['alt'], 'Media alt readback mismatch.' );
	$check( hash_equals( $technical_sha, (string) $media_after['media']['metadata_sha256'] ), 'Editorial metadata update unexpectedly changed generated attachment metadata.' );

	// Least-privilege defaults are explicit and empty means empty, never "everything".
	$default_plan = MAD4B_SCP_Content_Experience_Profiles::profile_plan( array(
		'profile' => array( 'slug' => 'ci-default', 'post_type' => 'mad4b_ci_trip', 'label' => 'CI Default' ),
		'expected_revision' => 0,
	) );
	$check( ! is_wp_error( $default_plan ), 'Safe-default profile planning failed.' );
	$check( 'allowlist' === $default_plan['profile']['meta_mode'], 'Profile meta default is not fail-closed allowlist.' );
	$check( 'allowlist' === $default_plan['profile']['taxonomy_mode'], 'Profile taxonomy default is not fail-closed allowlist.' );
	$check( empty( $default_plan['profile']['taxonomies'] ), 'Empty taxonomy allowlist unexpectedly widened to all attached taxonomies.' );

	$bootstrap = MAD4B_SCP_Content_Experience_Profiles::bootstrap_plan( array(
		'post_type' => 'mad4b_ci_trip',
		'profile_slug' => 'ci-bootstrap-trip',
		'taxonomy_strategy' => 'public_assignable',
	) );
	$check( ! is_wp_error( $bootstrap ), 'Content Experience bootstrap planning failed.' );
	$check( 'PROFILE_PROPOSED' === $bootstrap['state'] && empty( $bootstrap['mutation_performed'] ), 'Bootstrap plan must be read-only and proposed-only.' );
	$check( in_array( 'mad4b_ci_region', $bootstrap['included_taxonomies'], true ), 'Bootstrap plan did not include the assignable public taxonomy.' );
	$check( ! empty( $bootstrap['supports_featured_media'] ) && ! empty( $bootstrap['profile_plan']['profile']['featured_media'] ), 'Bootstrap plan did not infer thumbnail support.' );
	$check( array() === $bootstrap['profile_plan']['profile']['meta_keys'] && array() === $bootstrap['profile_plan']['profile']['enabled_helpers'], 'Bootstrap plan widened meta/helper authority.' );
	$check( MAD4B_SCP_Content_Experience_Profiles::PROFILE_APPLY_ABILITY === $bootstrap['profile_apply_ability'], 'Bootstrap plan did not hand off to the governed profile apply Ability.' );
	foreach ( array( 'remote_media_library_first', 'create_nonpublic', 'create_structured', 'update_existing', 'publish_or_private', 'verify', 'rollback' ) as $scenario ) {
		$check( ! empty( $bootstrap['scenarios'][ $scenario ]['supported'] ), 'Bootstrap scenario missing: ' . $scenario );
	}
	$check( in_array( MAD4B_SCP_Content_Experience_Profiles::BOOTSTRAP_PLAN_ABILITY, MAD4B_SCP_Content_Experience_Profiles::ability_names( 'read' ), true ), 'Bootstrap planner is not exposed on the read surface.' );

	$bootstrap_media = MAD4B_SCP_Content_Experience_Profiles::bootstrap_plan( array(
		'post_type' => 'mad4b_ci_trip',
		'profile_slug' => 'ci-bootstrap-media',
		'media_meta_fields' => array(
			'ci_gallery' => array( 'kind' => 'image_gallery', 'storage' => 'ids', 'max_items' => 12 ),
		),
	) );
	$check( ! is_wp_error( $bootstrap_media ), 'Bootstrap explicit media mapping failed.' );
	$check( in_array( 'ci_gallery', $bootstrap_media['profile_plan']['profile']['meta_keys'], true ), 'Bootstrap media mapping was not promoted into the profile meta allowlist.' );
	$check( 'image_gallery' === $bootstrap_media['profile_plan']['profile']['media_meta_fields']['ci_gallery']['kind'], 'Bootstrap media mapping lost its typed image-gallery contract.' );

	$remote_plan_unknown = $media_adapter->remote_import_plan( array( 'source_url' => 'https://images.example.invalid/tour.jpg' ) );
	$check( ! is_wp_error( $remote_plan_unknown ) && empty( $remote_plan_unknown['ready'] ) && in_array( 'rights_confirmation_required', $remote_plan_unknown['blockers'], true ), 'Remote media plan did not fail closed on unknown rights.' );
	$remote_plan_allowed = $media_adapter->remote_import_plan( array(
		'source_url' => 'https://images.example.invalid/tour.jpg',
		'source_page_url' => 'https://example.invalid/tour',
		'rights_basis' => 'permission',
		'rights_note' => 'CI fixture',
		'alt' => 'Nile cruise exterior',
	) );
	$check( ! is_wp_error( $remote_plan_allowed ) && ! empty( $remote_plan_allowed['ready'] ) && empty( $remote_plan_allowed['mutation_performed'] ), 'Remote media import planning is not safe/read-only.' );
	foreach ( array( MAD4B_SCP_Media_Adapter::REMOTE_DISCOVER_ABILITY, MAD4B_SCP_Media_Adapter::REMOTE_IMPORT_PLAN_ABILITY, MAD4B_SCP_Media_Adapter::REMOTE_IMPORT_APPLY_ABILITY ) as $ability_name ) {
		$check( wp_has_ability( $ability_name ), 'Remote media Ability is not registered: ' . $ability_name );
	}

	$profile_input = array(
		'slug' => 'ci-trip',
		'label' => 'CI Trip',
		'post_type' => 'mad4b_ci_trip',
		'meta_mode' => 'allowlist',
		'meta_keys' => array( 'ci_price', 'ci_gallery', 'ci_gallery_usage', 'ci_gallery_csv', 'ci_attachments' ),
		'media_meta_fields' => array(
			'ci_gallery' => array( 'kind' => 'image_gallery', 'storage' => 'ids', 'max_items' => 12 ),
			'ci_gallery_usage' => array(
				'kind' => 'image_gallery_usage',
				'storage' => 'items',
				'references_field' => 'ci_gallery',
				'max_items' => 12,
				'usage_fields' => array( 'role', 'alt_override', 'caption_override', 'title_override', 'description_override', 'credit', 'copyright', 'license', 'license_expires_on', 'source_url', 'focal_point', 'aria_label', 'decorative', 'link_url', 'link_target' ),
				'roles' => array( 'hero', 'gallery', 'card' ),
				'licenses' => array( 'owned', 'licensed', 'editorial', 'public-domain' ),
				'publish_rights_policy' => 'require_valid',
				'expiry_required_licenses' => array( 'licensed', 'editorial' ),
			),
			'ci_gallery_csv' => array( 'kind' => 'image_gallery', 'storage' => 'csv_ids', 'max_items' => 12 ),
			'ci_attachments' => array( 'kind' => 'attachment_gallery', 'storage' => 'ids', 'max_items' => 8 ),
		),
		'taxonomy_mode' => 'allowlist',
		'taxonomies' => array( 'mad4b_ci_region' ),
		'featured_media' => true,
		'hierarchy' => false,
		'enabled_helpers' => array(),
		'creation_status' => 'draft',
		'live_update_mode' => 'draft_first',
	);
	$profile_plan = MAD4B_SCP_Content_Experience_Profiles::profile_plan( array( 'profile' => $profile_input, 'expected_revision' => 0 ) );
	$check( ! is_wp_error( $profile_plan ), 'Content Experience profile plan failed.' );
	$applied_profile = MAD4B_SCP_Content_Experience_Profiles::profile_apply( array(
		'profile' => $profile_input, 'expected_revision' => 0, 'plan_sha256' => $profile_plan['plan_sha256'],
	) );
	$check( ! is_wp_error( $applied_profile ) && ! empty( $applied_profile['profile']['authority_sha256'] ), 'Content Experience profile apply did not persist authority identity.' );
	$profile_v1 = $applied_profile['profile'];
	$routes_v1 = $profile_v1['routes'];
	$check( false !== strpos( $routes_v1['create_apply'], '-r1-create-apply' ), 'Mutation executor route is not generation-bound.' );
	$check( 'mad4b/ci-trip-create-plan' === $routes_v1['create_plan'], 'Planner route should remain stable across profile generations.' );

	$adapter = MAD4B_SCP_Adapter_Registry::instance()->get( 'full-content-operations' );
	$check( $adapter instanceof MAD4B_SCP_Full_Content_Operations_Adapter, 'Full Content Operations adapter is unavailable.' );
	$launch_next_request( 'r1' );

	$create_input = array(
		'post_title' => 'CI governed trip',
		'post_content' => 'Initial content',
		'meta' => array(
			'ci_price' => '100',
			'ci_gallery' => array( $image_two, $image_one ),
			'ci_gallery_usage' => array(
				array(
					'attachment_id' => $image_two,
					'role' => 'gallery',
					'alt_override' => 'Contextual gallery alt for the second trip image',
					'caption_override' => 'Contextual caption used only inside this trip gallery.',
					'title_override' => 'Cairo gallery context title',
					'description_override' => '<p>Trip-specific contextual image description.</p>',
					'credit' => 'All Royal Egypt',
					'copyright' => 'All Royal Egypt',
					'license' => 'licensed',
					'license_expires_on' => '2099-12-31',
					'source_url' => 'https://example.invalid/media-source-two',
					'focal_point' => array( 'x' => 0.35, 'y' => 0.45 ),
					'aria_label' => 'Open Cairo gallery image details',
					'decorative' => false,
					'link_url' => 'https://example.invalid/trips/cairo',
					'link_target' => '_blank',
				),
				array(
					'attachment_id' => $image_one,
					'role' => 'hero',
					'alt_override' => 'Trip-specific hero alt distinct from the attachment global alt',
					'caption_override' => 'Trip-specific hero caption.',
					'credit' => 'All Royal Egypt',
					'license' => 'owned',
					'license_expires_on' => '',
					'source_url' => 'https://example.invalid/media-source-one',
					'focal_point' => array( 'x' => 0.5, 'y' => 0.4 ),
					'decorative' => false,
				),
			),
			'ci_gallery_csv' => array( $image_two, $image_one ),
			'ci_attachments' => array( $brochure ),
		),
		'taxonomies' => array( 'mad4b_ci_region' => array( 'cairo' ) ),
		'featured_media_id' => $image_one,
	);
	$create_plan = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'create', $create_input );
	$check( ! is_wp_error( $create_plan ) && ! empty( $create_plan['profile_snapshot']['authority_sha256'] ), 'Create plan lacks historical profile snapshot.' );
	$create = MAD4B_SCP_Content_Experience_Runtime::operation_apply( 'ci-trip', 'create', array_merge( $create_input, array( 'plan_sha256' => $create_plan['plan_sha256'] ) ) );
	$check( ! is_wp_error( $create ) && ! empty( $create['verified'] ) && ! empty( $create['post_id'] ), 'Dynamic create apply failed.' );
	$post_id = (int) $create['post_id'];
	$post_ids[] = $post_id;
	$check( 'draft' === get_post_status( $post_id ), 'Create did not preserve draft-first status.' );
	$check( '100' === get_post_meta( $post_id, 'ci_price', true ), 'Create meta readback mismatch.' );

	$check( array( $image_two, $image_one ) === get_post_meta( $post_id, 'ci_gallery', true ), 'Image gallery order/readback mismatch.' );
	$usage_readback = get_post_meta( $post_id, 'ci_gallery_usage', true );
	$check( is_array( $usage_readback ) && 2 === count( $usage_readback ), 'Contextual gallery usage metadata readback is incomplete.' );
	$check( $image_two === (int) $usage_readback[0]['attachment_id'] && $image_one === (int) $usage_readback[1]['attachment_id'], 'Contextual gallery usage order drifted from gallery IDs.' );
	$check( 'gallery' === $usage_readback[0]['role'] && 'hero' === $usage_readback[1]['role'], 'Contextual gallery roles readback mismatch.' );
	$check( 'Trip-specific hero alt distinct from the attachment global alt' === $usage_readback[1]['alt_override'], 'Contextual hero alt override readback mismatch.' );
	$check( array( 'x' => 0.5, 'y' => 0.4 ) === $usage_readback[1]['focal_point'], 'Contextual focal point readback mismatch.' );
	$check( 'licensed' === $usage_readback[0]['license'] && '2099-12-31' === $usage_readback[0]['license_expires_on'], 'Contextual media rights metadata readback mismatch.' );
	$check( false === $usage_readback[0]['decorative'] && '_blank' === $usage_readback[0]['link_target'], 'Contextual accessibility/link semantics readback mismatch.' );
	$check( 'Open Cairo gallery image details' === $usage_readback[0]['aria_label'], 'Contextual ARIA label readback mismatch.' );
	$check( '<p>Trip-specific contextual image description.</p>' === $usage_readback[0]['description_override'], 'Contextual description override readback mismatch.' );
	$check( 'Cairo Nile journey in Egypt' === get_post_meta( $image_one, '_wp_attachment_image_alt', true ), 'Contextual ALT override polluted the global attachment ALT.' );
	$check( 'Cairo and Nile journey gallery image' === get_post( $image_one )->post_excerpt, 'Contextual caption override polluted the global attachment caption.' );
	$check( $image_two . ',' . $image_one === get_post_meta( $post_id, 'ci_gallery_csv', true ), 'CSV image gallery storage/readback mismatch.' );
	$check( array( $brochure ) === get_post_meta( $post_id, 'ci_attachments', true ), 'Attachment gallery readback mismatch.' );
	$check( $image_one === (int) get_post_thumbnail_id( $post_id ), 'Featured image readback mismatch.' );

	$parent_result = $media_adapter->set_parent( array( 'attachment_id' => $image_one, 'parent_post_id' => $post_id, 'expected_parent_id' => 0 ) );
	$check( ! is_wp_error( $parent_result ) && $post_id === (int) get_post( $image_one )->post_parent, 'Attachment-to-trip parent binding failed.' );

	$trip_media_search = $media_adapter->search( array( 'parent_post_id' => $post_id, 'image_only' => true, 'limit' => 10 ) );
	$check( ! is_wp_error( $trip_media_search ) && 1 === (int) $trip_media_search['count'], 'Trip-scoped media search did not isolate the attached image.' );
	$trip_media_item = $trip_media_search['items'][0];
	$check( $image_one === (int) $trip_media_item['media']['id'], 'Trip-scoped media search returned the wrong attachment.' );
	$check( 'summary' === $trip_media_item['media']['detail_level'] && ! isset( $trip_media_item['media']['metadata'], $trip_media_item['media']['sizes'], $trip_media_item['media']['description'] ), 'Media search leaked deep attachment metadata instead of compact summary state.' );
	$deep_trip_media = $media_adapter->get_media( array( 'attachment_id' => $image_one ) );
	$check( ! is_wp_error( $deep_trip_media ) && 'full' === $deep_trip_media['detail_level'] && isset( $deep_trip_media['media']['metadata'], $deep_trip_media['media']['sizes'], $deep_trip_media['media']['description'] ), 'Deep media read did not return full attachment metadata.' );
	$check( hash_equals( (string) $trip_media_item['sha256'], (string) $deep_trip_media['sha256'] ), 'Compact media search and deep media get disagree on optimistic-concurrency identity.' );
	$check( 1600 === (int) $trip_media_item['media']['width'] && 900 === (int) $trip_media_item['media']['height'] && 1.777778 === (float) $trip_media_item['media']['aspect_ratio'], 'Compact media summary lost image dimensions/aspect ratio.' );

	$unattached_images = $media_adapter->search( array( 'unattached_only' => true, 'image_only' => true, 'limit' => 10 ) );
	$check( ! is_wp_error( $unattached_images ), 'Unattached image search failed.' );
	$unattached_ids = array_map( static function ( $row ) { return (int) $row['media']['id']; }, (array) $unattached_images['items'] );
	$check( in_array( $image_two, $unattached_ids, true ) && ! in_array( $image_one, $unattached_ids, true ), 'Unattached media search did not respect attachment parent identity.' );
	$parent_conflict = $media_adapter->search( array( 'parent_post_id' => $post_id, 'unattached_only' => true ) );
	$check( is_wp_error( $parent_conflict ) && 'mad4b_media_search_parent_conflict' === $parent_conflict->get_error_code(), 'Media search accepted contradictory parent filters.' );
	$mime_conflict = $media_adapter->search( array( 'image_only' => true, 'mime_type' => 'application/pdf' ) );
	$check( is_wp_error( $mime_conflict ) && 'mad4b_media_search_mime_conflict' === $mime_conflict->get_error_code(), 'Media search accepted an image-only/non-image MIME contradiction.' );

	$media_verify = MAD4B_SCP_Content_Experience_Runtime::verify( 'ci-trip', array( 'post_id' => $post_id ) );
	$check( ! is_wp_error( $media_verify ) && ! empty( $media_verify['media_state_valid'] ), 'Content Experience media verification failed.' );
	$check( array( $image_two, $image_one ) === $media_verify['media_fields']['ci_gallery']['attachment_ids'], 'Media verify lost canonical gallery attachment order.' );
	$usage_verify = $media_verify['media_fields']['ci_gallery_usage'];
	$check( array( $image_two, $image_one ) === $usage_verify['attachment_ids'], 'Media verify usage IDs drifted from canonical gallery order.' );
	$check( array( 'gallery', 'hero' ) === $usage_verify['roles'], 'Media verify lost contextual media roles.' );
	$check( array( 'licensed', 'owned' ) === $usage_verify['licenses'], 'Media verify lost contextual media licenses.' );
	$check( array( '2099-12-31' ) === $usage_verify['license_expiries'], 'Media verify lost bounded rights-expiry evidence.' );
	$check( 1 === (int) $usage_verify['aria_label_count'] && 1 === (int) $usage_verify['linked_count'] && 2 === (int) $usage_verify['focal_point_count'], 'Media verify accessibility/link/focal summaries are incomplete.' );

	$current_for_media_guard = get_post( $post_id );
	$bad_gallery_plan = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'update', array(
		'post_id' => $post_id,
		'expected_modified_gmt' => $current_for_media_guard->post_modified_gmt,
		'meta' => array( 'ci_gallery' => array( $brochure ) ),
	) );
	$check( is_wp_error( $bad_gallery_plan ) && 'mad4b_content_experience_media_image_required' === $bad_gallery_plan->get_error_code(), 'Image gallery accepted a non-image attachment.' );
	$duplicate_gallery_plan = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'update', array(
		'post_id' => $post_id,
		'expected_modified_gmt' => $current_for_media_guard->post_modified_gmt,
		'meta' => array( 'ci_gallery' => array( $image_one, $image_one ) ),
	) );
	$check( is_wp_error( $duplicate_gallery_plan ) && 'mad4b_content_experience_media_gallery_duplicate' === $duplicate_gallery_plan->get_error_code(), 'Image gallery accepted duplicate attachment IDs.' );

	$partial_gallery_drift = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'update', array(
		'post_id' => $post_id,
		'expected_modified_gmt' => $current_for_media_guard->post_modified_gmt,
		'meta' => array( 'ci_gallery' => array( $image_one, $image_two ) ),
	) );
	$check( is_wp_error( $partial_gallery_drift ) && 'mad4b_content_experience_media_usage_reference_drift' === $partial_gallery_drift->get_error_code(), 'Partial gallery mutation escaped effective gallery/usage binding validation.' );

	$media_drift_input = array(
		'post_id' => $post_id,
		'expected_modified_gmt' => $current_for_media_guard->post_modified_gmt,
		'post_excerpt' => 'media-plan-drift-check',
	);
	$media_drift_plan = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'update', $media_drift_input );
	$check( ! is_wp_error( $media_drift_plan ) && ! empty( $media_drift_plan['effective_media_state_sha256'] ), 'Update plan did not bind effective media state identity.' );
	$original_gallery = get_post_meta( $post_id, 'ci_gallery', true );
	$original_usage = get_post_meta( $post_id, 'ci_gallery_usage', true );
	$external_gallery = array( $image_one, $image_two );
	$external_usage = array( $original_usage[1], $original_usage[0] );
	update_post_meta( $post_id, 'ci_gallery', $external_gallery );
	update_post_meta( $post_id, 'ci_gallery_usage', $external_usage );
	$media_drift_capture = MAD4B_SCP_Content_Experience_Runtime::capture_reversible_state(
		'ci-trip',
		'update',
		array_merge( $media_drift_input, array( 'plan_sha256' => $media_drift_plan['plan_sha256'] ) )
	);
	$check( is_wp_error( $media_drift_capture ) && 'mad4b_content_experience_operation_plan_drift' === $media_drift_capture->get_error_code(), 'External media-meta drift did not invalidate the reviewed operation plan.' );
	update_post_meta( $post_id, 'ci_gallery', $original_gallery );
	update_post_meta( $post_id, 'ci_gallery_usage', $original_usage );

	$usage_without_reference = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'update', array(
		'post_id' => $post_id,
		'expected_modified_gmt' => $current_for_media_guard->post_modified_gmt,
		'meta' => array(
			'ci_gallery_usage' => array(
				array( 'attachment_id' => $image_two, 'role' => 'gallery', 'alt_override' => 'Updated contextual alt' ),
				array( 'attachment_id' => $image_one, 'role' => 'hero', 'alt_override' => 'Updated contextual hero alt' ),
			),
		),
	) );
	$check( is_wp_error( $usage_without_reference ) && 'mad4b_content_experience_media_usage_reference_required' === $usage_without_reference->get_error_code(), 'Contextual usage mutation escaped atomic gallery binding.' );

	$usage_order_drift = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'update', array(
		'post_id' => $post_id,
		'expected_modified_gmt' => $current_for_media_guard->post_modified_gmt,
		'meta' => array(
			'ci_gallery' => array( $image_two, $image_one ),
			'ci_gallery_usage' => array(
				array( 'attachment_id' => $image_one, 'role' => 'hero' ),
				array( 'attachment_id' => $image_two, 'role' => 'gallery' ),
			),
		),
	) );
	$check( is_wp_error( $usage_order_drift ) && 'mad4b_content_experience_media_usage_reference_drift' === $usage_order_drift->get_error_code(), 'Contextual usage order drifted independently from gallery IDs.' );

	$usage_property_denied = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'update', array(
		'post_id' => $post_id,
		'expected_modified_gmt' => $current_for_media_guard->post_modified_gmt,
		'meta' => array(
			'ci_gallery' => array( $image_two, $image_one ),
			'ci_gallery_usage' => array(
				array( 'attachment_id' => $image_two, 'role' => 'gallery', 'arbitrary_json' => 'denied' ),
				array( 'attachment_id' => $image_one, 'role' => 'hero' ),
			),
		),
	) );
	$check( is_wp_error( $usage_property_denied ) && 'mad4b_content_experience_media_usage_property_denied' === $usage_property_denied->get_error_code(), 'Contextual media usage accepted an unapproved arbitrary property.' );

	$usage_role_denied = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'update', array(
		'post_id' => $post_id,
		'expected_modified_gmt' => $current_for_media_guard->post_modified_gmt,
		'meta' => array(
			'ci_gallery' => array( $image_two, $image_one ),
			'ci_gallery_usage' => array(
				array( 'attachment_id' => $image_two, 'role' => 'unreviewed-role' ),
				array( 'attachment_id' => $image_one, 'role' => 'hero' ),
			),
		),
	) );
	$check( is_wp_error( $usage_role_denied ) && 'mad4b_content_experience_media_usage_role_denied' === $usage_role_denied->get_error_code(), 'Contextual media usage accepted a role outside the profile allowlist.' );

	$usage_license_denied = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'update', array(
		'post_id' => $post_id,
		'expected_modified_gmt' => $current_for_media_guard->post_modified_gmt,
		'meta' => array(
			'ci_gallery' => array( $image_two, $image_one ),
			'ci_gallery_usage' => array(
				array( 'attachment_id' => $image_two, 'role' => 'gallery', 'license' => 'unknown-license' ),
				array( 'attachment_id' => $image_one, 'role' => 'hero', 'license' => 'owned' ),
			),
		),
	) );
	$check( is_wp_error( $usage_license_denied ) && 'mad4b_content_experience_media_usage_license_denied' === $usage_license_denied->get_error_code(), 'Contextual media usage accepted a license outside the profile allowlist.' );

	$decorative_alt_conflict = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'update', array(
		'post_id' => $post_id,
		'expected_modified_gmt' => $current_for_media_guard->post_modified_gmt,
		'meta' => array(
			'ci_gallery' => array( $image_two, $image_one ),
			'ci_gallery_usage' => array(
				array( 'attachment_id' => $image_two, 'role' => 'gallery', 'decorative' => true, 'alt_override' => 'must be empty' ),
				array( 'attachment_id' => $image_one, 'role' => 'hero' ),
			),
		),
	) );
	$check( is_wp_error( $decorative_alt_conflict ) && 'mad4b_content_experience_media_usage_decorative_alt_conflict' === $decorative_alt_conflict->get_error_code(), 'Decorative media accepted a non-empty contextual ALT.' );

	$link_target_without_url = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'update', array(
		'post_id' => $post_id,
		'expected_modified_gmt' => $current_for_media_guard->post_modified_gmt,
		'meta' => array(
			'ci_gallery' => array( $image_two, $image_one ),
			'ci_gallery_usage' => array(
				array( 'attachment_id' => $image_two, 'role' => 'gallery', 'link_target' => '_blank' ),
				array( 'attachment_id' => $image_one, 'role' => 'hero' ),
			),
		),
	) );
	$check( is_wp_error( $link_target_without_url ) && 'mad4b_content_experience_media_usage_link_target_without_url' === $link_target_without_url->get_error_code(), 'Contextual media usage accepted link_target without link_url.' );
	foreach ( array(
		array( 'source_url' => '/relative-source' ),
		array( 'link_url' => '//example.invalid/gallery' ),
		array( 'source_url' => 'https://user:password@example.invalid/gallery' ),
		array( 'link_url' => '', 'link_target' => '_blank' ),
	) as $invalid_contextual_url ) {
		$invalid_usage_plan = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'update', array(
			'post_id' => $post_id,
			'expected_modified_gmt' => $current_for_media_guard->post_modified_gmt,
			'meta' => array(
				'ci_gallery' => array( $image_two, $image_one ),
				'ci_gallery_usage' => array(
					array( 'attachment_id' => $image_two, 'role' => 'gallery' ) + $invalid_contextual_url,
					array( 'attachment_id' => $image_one, 'role' => 'hero' ),
				),
			),
		) );
		$check( is_wp_error( $invalid_usage_plan ), 'Contextual URL validation did not fence the generated update planner.' );
	}
	$check( $usage_readback === get_post_meta( $post_id, 'ci_gallery_usage', true ), 'Rejected contextual URL plans changed stored gallery usage.' );

	$invalid_rights_date = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'update', array(
		'post_id' => $post_id,
		'expected_modified_gmt' => $current_for_media_guard->post_modified_gmt,
		'meta' => array(
			'ci_gallery' => array( $image_two, $image_one ),
			'ci_gallery_usage' => array(
				array( 'attachment_id' => $image_two, 'role' => 'gallery', 'license' => 'licensed', 'license_expires_on' => '2028-02-31' ),
				array( 'attachment_id' => $image_one, 'role' => 'hero', 'license' => 'owned' ),
			),
		),
	) );
	$check( is_wp_error( $invalid_rights_date ) && 'mad4b_content_experience_media_usage_date_invalid' === $invalid_rights_date->get_error_code(), 'Contextual media usage accepted an invalid rights-expiry date.' );

	$replay = MAD4B_SCP_Content_Experience_Runtime::operation_apply( 'ci-trip', 'create', array_merge( $create_input, array( 'plan_sha256' => $create_plan['plan_sha256'] ) ) );
	$check( ! is_wp_error( $replay ) && ! empty( $replay['idempotent_replay'] ) && $post_id === (int) $replay['post_id'], 'Create idempotent replay did not resolve exact prior result.' );

	// Target-level lock denies concurrent mutation before the second writer can revalidate.
	$current = get_post( $post_id );
	$locked_input = array( 'post_id' => $post_id, 'expected_modified_gmt' => $current->post_modified_gmt, 'post_excerpt' => 'locked-change' );
	$locked_plan = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'update', $locked_input );
	$check( ! is_wp_error( $locked_plan ), 'Lock fixture update plan failed.' );
	$lock_name = MAD4B_SCP_Content_Experience_Governance::acquire_lock( 'ci-trip', $post_id, '' );
	$check( ! is_wp_error( $lock_name ), 'Unable to acquire explicit Content Experience fixture lock.' );
	$blocked = MAD4B_SCP_Content_Experience_Runtime::operation_apply( 'ci-trip', 'update', array_merge( $locked_input, array( 'plan_sha256' => $locked_plan['plan_sha256'] ) ) );
	MAD4B_SCP_Content_Experience_Governance::release_lock( $lock_name );
	$check( is_wp_error( $blocked ) && 'mad4b_content_experience_target_busy' === $blocked->get_error_code(), 'Concurrent Content Experience target was not fenced.' );

	// Force a post-core failure and prove automatic compensation restores the title.
	$attachment_id = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'CI image', 'post_mime_type' => 'image/png' ), true );
	$check( ! is_wp_error( $attachment_id ), 'Unable to create attachment fixture.' );
	$attachment_id = (int) $attachment_id;
	$post_ids[] = $attachment_id;
	$check( update_attached_file( $attachment_id, 'ci-compensation.png' ), 'Unable to bind compensation image attachment file identity.' );
	$image_filter = static function ( $html, $attachment, $size, $icon, $attr ) use ( $attachment_id ) {
		return (int) $attachment === (int) $attachment_id ? '<img src="ci.png" alt="" />' : $html;
	};
	add_filter( 'wp_get_attachment_image', $image_filter, 10, 5 );
	$meta_fail = static function ( $check_value, $object_id, $meta_key ) use ( $post_id ) {
		return ( (int) $object_id === (int) $post_id && '_thumbnail_id' === (string) $meta_key ) ? false : $check_value;
	};
	add_filter( 'update_post_metadata', $meta_fail, 10, 3 );
	$current = get_post( $post_id );
	$before_title = $current->post_title;
	$failure_input = array( 'post_id' => $post_id, 'expected_modified_gmt' => $current->post_modified_gmt, 'post_title' => 'Must roll back', 'featured_media_id' => (int) $attachment_id );
	$failure_plan = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'update', $failure_input );
	$check( ! is_wp_error( $failure_plan ), 'Compensation fixture plan failed.' );
	$failure = MAD4B_SCP_Content_Experience_Runtime::operation_apply( 'ci-trip', 'update', array_merge( $failure_input, array( 'plan_sha256' => $failure_plan['plan_sha256'] ) ) );
	remove_filter( 'update_post_metadata', $meta_fail, 10 );
	remove_filter( 'wp_get_attachment_image', $image_filter, 10 );
	$check( is_wp_error( $failure ), 'Injected featured-media failure did not fail the mutation.' );
	$failure_data = $failure->get_error_data( $failure->get_error_code() );
	$check( is_array( $failure_data ) && ! empty( $failure_data['mad4b_compensation']['verified'] ), 'Partial failure did not report verified automatic compensation.' );
	$check( $before_title === get_post( $post_id )->post_title, 'Automatic compensation did not restore the pre-mutation title.' );

	// Capture revision-1 rollback state, mutate, then advance the profile to r2.
	$current = get_post( $post_id );
	$update_input = array( 'post_id' => $post_id, 'expected_modified_gmt' => $current->post_modified_gmt, 'post_excerpt' => 'revision-one-change' );
	$update_plan = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'update', $update_input );
	$before_update = MAD4B_SCP_Content_Experience_Runtime::capture_reversible_state( 'ci-trip', 'update', array_merge( $update_input, array( 'plan_sha256' => $update_plan['plan_sha256'] ) ) );
	$check( ! is_wp_error( $before_update ), 'Historical rollback capture failed.' );
	$update = MAD4B_SCP_Content_Experience_Runtime::operation_apply( 'ci-trip', 'update', array_merge( $update_input, array( 'plan_sha256' => $update_plan['plan_sha256'] ) ) );
	$check( ! is_wp_error( $update ), 'Revision-one update failed.' );

	$profile_input['label'] = 'CI Trip r2';
	$profile_plan2 = MAD4B_SCP_Content_Experience_Profiles::profile_plan( array( 'profile' => $profile_input, 'expected_revision' => 1 ) );
	$check( ! is_wp_error( $profile_plan2 ), 'Profile revision-two plan failed.' );
	$applied_profile2 = MAD4B_SCP_Content_Experience_Profiles::profile_apply( array( 'profile' => $profile_input, 'expected_revision' => 1, 'plan_sha256' => $profile_plan2['plan_sha256'] ) );
	$check( ! is_wp_error( $applied_profile2 ), 'Profile revision-two apply failed.' );
	$profile_v2 = $applied_profile2['profile'];
	$routes_v2 = $profile_v2['routes'];
	$check( $routes_v1['update_apply'] !== $routes_v2['update_apply'] && false !== strpos( $routes_v2['update_apply'], '-r2-update-apply' ), 'Profile authority change did not rotate mutation executor generation.' );
	$check( ! hash_equals( $profile_v1['authority_sha256'], $profile_v2['authority_sha256'] ), 'Profile revision did not rotate authority fingerprint.' );
	$launch_next_request( 'r2' );
	$restored_old = MAD4B_SCP_Content_Experience_Runtime::restore_reversible_state( $before_update['target'], $before_update['state'] );
	$check( true === $restored_old, 'Historical rollback could not restore after current profile advanced.' );
	$check( '' === get_post( $post_id )->post_excerpt, 'Historical rollback used mutable current profile state.' );

	$invalid_type = $profile_input;
	$invalid_type['post_type'] = 'page';
	$type_plan = MAD4B_SCP_Content_Experience_Profiles::profile_plan( array( 'profile' => $invalid_type, 'expected_revision' => 2 ) );
	$check( is_wp_error( $type_plan ) && 'mad4b_content_experience_post_type_immutable' === $type_plan->get_error_code(), 'Existing profile allowed post_type semantic widening.' );

	// Publish is the last visible transition after all preconditions/helper stages.
	$current = get_post( $post_id );
	$publish_input = array( 'post_id' => $post_id, 'expected_modified_gmt' => $current->post_modified_gmt, 'post_status' => 'publish' );
	$rights_usage = get_post_meta( $post_id, 'ci_gallery_usage', true );

	$expired_rights = $rights_usage;
	$expired_rights[0]['license_expires_on'] = '2000-01-01';
	update_post_meta( $post_id, 'ci_gallery_usage', $expired_rights );
	$expired_publish = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'publish', $publish_input );
	$check( is_wp_error( $expired_publish ) && 'mad4b_content_experience_media_rights_expired' === $expired_publish->get_error_code(), 'Publish accepted an expired contextual media license.' );
	update_post_meta( $post_id, 'ci_gallery_usage', $rights_usage );

	$missing_expiry = $rights_usage;
	$missing_expiry[0]['license_expires_on'] = '';
	update_post_meta( $post_id, 'ci_gallery_usage', $missing_expiry );
	$missing_expiry_publish = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'publish', $publish_input );
	$check( is_wp_error( $missing_expiry_publish ) && 'mad4b_content_experience_media_rights_expiry_required' === $missing_expiry_publish->get_error_code(), 'Publish accepted a licensed image without its required expiry date.' );
	update_post_meta( $post_id, 'ci_gallery_usage', $rights_usage );

	$missing_license = $rights_usage;
	unset( $missing_license[0]['license'] );
	update_post_meta( $post_id, 'ci_gallery_usage', $missing_license );
	$missing_license_publish = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'publish', $publish_input );
	$check( is_wp_error( $missing_license_publish ) && 'mad4b_content_experience_media_rights_license_required' === $missing_license_publish->get_error_code(), 'Publish accepted contextual media without a governed license.' );
	update_post_meta( $post_id, 'ci_gallery_usage', $rights_usage );

	$publish_plan = MAD4B_SCP_Content_Experience_Runtime::operation_plan( 'ci-trip', 'publish', $publish_input );
	$check( ! is_wp_error( $publish_plan ) && ! empty( $publish_plan['media_publish_rights']['valid'] ), 'Publish plan did not certify contextual media rights.' );
	$check( 2 === (int) $publish_plan['media_publish_rights']['checked_item_count'] && '2099-12-31' === $publish_plan['media_publish_rights']['nearest_expiry'], 'Publish rights evidence did not preserve the reviewed gallery license state.' );
	$publish = MAD4B_SCP_Content_Experience_Runtime::operation_apply( 'ci-trip', 'publish', array_merge( $publish_input, array( 'plan_sha256' => $publish_plan['plan_sha256'] ) ) );
	$check( ! is_wp_error( $publish ) && 'publish' === get_post_status( $post_id ), 'Governed publish did not complete as final visible transition.' );
	$verify = MAD4B_SCP_Content_Experience_Runtime::verify( 'ci-trip', array( 'post_id' => $post_id ) );
	$check( ! is_wp_error( $verify ) && ! empty( $verify['authority_match'] ) && ! empty( $verify['marker_match'] ), 'Content Experience verification did not prove current profile authority.' );

	// Clone provides the safe migration path when post_type/route identity must change.
	$clone_plan = MAD4B_SCP_Content_Experience_Profiles::profile_clone_plan( array( 'source_slug' => 'ci-trip', 'new_slug' => 'ci-trip-clone', 'label' => 'CI Clone' ) );
	$check( ! is_wp_error( $clone_plan ), 'Profile clone plan failed.' );
	$clone = MAD4B_SCP_Content_Experience_Profiles::profile_clone_apply( array( 'source_slug' => 'ci-trip', 'new_slug' => 'ci-trip-clone', 'label' => 'CI Clone', 'plan_sha256' => $clone_plan['plan_sha256'] ) );
	$check( ! is_wp_error( $clone ), 'Profile clone apply failed.' );
	$delete_plan = MAD4B_SCP_Content_Experience_Profiles::profile_delete_plan( array( 'slug' => 'ci-trip-clone', 'expected_revision' => 1 ) );
	$delete = MAD4B_SCP_Content_Experience_Profiles::profile_delete_apply( array( 'slug' => 'ci-trip-clone', 'expected_revision' => 1, 'plan_sha256' => $delete_plan['plan_sha256'] ) );
	$check( ! is_wp_error( $delete ) && ! empty( $delete['content_preserved'] ), 'Profile decommission failed or claimed content deletion.' );

	echo "mad4b.site-control-plane.runtime-content-experience.v1: PASS\n";
} finally {
	if ( $gallery_image_filter ) remove_filter( 'wp_get_attachment_image', $gallery_image_filter, 10 );
	foreach ( array_reverse( array_unique( array_map( 'absint', $post_ids ) ) ) as $id ) if ( $id > 0 ) wp_delete_post( $id, true );
	foreach ( array_reverse( array_unique( array_map( 'absint', $term_ids ) ) ) as $id ) if ( $id > 0 ) wp_delete_term( $id, 'mad4b_ci_region' );
	update_option( $option, is_array( $original_profiles ) ? $original_profiles : array(), false );
	MAD4B_SCP_Content_Experience_Profiles::reset_request_cache();
	unregister_taxonomy( 'mad4b_ci_region' );
	unregister_post_type( 'mad4b_ci_trip' );
}
