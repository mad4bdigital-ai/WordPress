<?php
/** Execute: php -d extension=pdo_sqlite tests/adaptive-search-runtime.php */
require __DIR__ . '/fixtures/search-runtime-fixtures.php';
set_error_handler( static function ( $severity, $message, $file, $line ) { if ( error_reporting() & $severity ) throw new ErrorException( $message, 0, $severity, $file, $line ); } );
$cases = array(); $assertions = 0; $results = array();
function check( $condition, $message ) { ++$GLOBALS['assertions']; if ( ! $condition ) throw new RuntimeException( $message ); }
function ok( $value, $message ) { check( ! is_wp_error( $value ), $message . ( is_wp_error( $value ) ? ': ' . $value->get_error_code() : '' ) ); return $value; }
function denied( $value, $suffix, $message ) { check( is_wp_error( $value ) && false !== strpos( $value->get_error_code(), $suffix ), $message ); }
function scenario( $id, $callback ) { $GLOBALS['cases'][ $id ] = $callback; }
function receipt( $units, $cycle = 'cycle.1', $clock = null ) { $clock = null === $clock ? time() : (int) $clock; return array( 'remaining' => $units, 'observed_at' => $clock, 'reset_at' => $clock + 86400, 'cycle_id' => $cycle, 'generation' => hash( 'sha256', 'generation' ) ); }
function capture( $input ) { $plan = ok( MAD4B_SCP_Search_Runtime::capture_plan( $input ), 'capture plan' ); return array( ok( MAD4B_SCP_Search_Runtime::capture_apply( array_merge( $input, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'capture apply' ), $plan ); }
function sample_snapshot( $request = null, $data = null, $at = null, $descriptor = null ) {
	$request = $request ?: array_merge( asi_candidate(), array( 'query_id' => hash( 'sha256', 'query' ), 'depth' => 3 ) );
	return ok( MAD4B_SCP_Search_Evidence::snapshot( $request, $descriptor ?: asi_descriptor( 'alpha' ), array( 'location_id' => 'fixture-city', 'country' => 'US', 'language_code' => 'en', 'precision' => 'city' ), $data ?: asi_normalized(), hash( 'sha256', 'raw' ), hash( 'sha256', 'build' ), $at ?: time() ), 'snapshot' );
}

scenario( 'direct_profile_creation_must_be_paused_and_frozen_before_typed_activation', static function () {
	foreach ( array( 'active' => array( true, true ), 'unfrozen' => array( false, false ), 'active_unfrozen' => array( true, false ) ) as $label => $state ) {
		$raw = asi_profile( 'creation-denial-' . $label );
		$raw['markets'][0]['id'] = 'creation-' . $label . '-us';
		$raw['markets'][1]['id'] = 'creation-' . $label . '-fr';
		$raw['enabled'] = $state[0]; $raw['provider_policy']['freeze_spend'] = $state[1];
		$args = array( 'profile' => $raw, 'expected_revision' => 0 );
		denied( MAD4B_SCP_Search_Context::plan( $args ), 'creation_requires_pause_and_spend_freeze', 'domain plan refuses active/unfrozen creation: ' . $label );
		denied( MAD4B_SCP_Search_Runtime::profile_apply( array_merge( $args, array( 'plan_sha256' => str_repeat( '0', 64 ) ) ) ), 'creation_requires_pause_and_spend_freeze', 'public runtime apply refuses unsafe create: ' . $label );
		check( null === MAD4B_SCP_Search_Store::read( 'profile', $raw['profile_id'] ), 'no unsafe profile persisted: ' . $label );
		foreach ( $raw['markets'] as $market ) check( null === MAD4B_SCP_Search_Store::read( 'market-identity', $market['id'] ), 'no market reservation: ' . $label );
	}
	$omitted = asi_profile( 'creation-omitted-frozen-default' );
	$omitted['markets'][0]['id'] = 'creation-default-us'; $omitted['markets'][1]['id'] = 'creation-default-fr';
	unset( $omitted['provider_policy']['freeze_spend'] );
	$omitted_args = array( 'profile' => $omitted, 'expected_revision' => 0 );
	$omitted_plan = ok( MAD4B_SCP_Search_Context::plan( $omitted_args ), 'legacy missing freeze_spend defaults to a frozen draft' );
	check( ! $omitted_plan['profile']['enabled'] && $omitted_plan['profile']['provider_policy']['freeze_spend'], 'implicit freeze never enables spend on creation' );
	$omitted_apply = ok( MAD4B_SCP_Search_Context::apply( array_merge( $omitted_args, array( 'plan_sha256' => $omitted_plan['plan_sha256'] ) ) ), 'legacy omission applies only a frozen draft' );
	check( ! $omitted_apply['profile']['enabled'] && $omitted_apply['profile']['provider_policy']['freeze_spend'], 'persisted implicit freeze matches exact plan' );
	$invalid = asi_profile( 'creation-explicit-null-policy' );
	$invalid['provider_policy'] = null;
	denied( MAD4B_SCP_Search_Context::plan( array( 'profile' => $invalid, 'expected_revision' => 0 ) ), 'profile_invalid', 'explicit null provider policy cannot be treated as omitted safe defaults' );
	$safe = asi_profile( 'creation-safe-typed-controls' );
	$safe['markets'][0]['id'] = 'creation-safe-us'; $safe['markets'][1]['id'] = 'creation-safe-fr';
	$input = array( 'profile' => $safe, 'expected_revision' => 0 );
	$plan = ok( MAD4B_SCP_Search_Context::plan( $input ), 'safe creation plan' );
	$created = ok( MAD4B_SCP_Search_Runtime::profile_apply( array_merge( $input, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'safe creation apply' );
	check( ! $created['profile']['enabled'] && $created['profile']['provider_policy']['freeze_spend'], 'new profile cannot run observations or incur provider spend' );
	denied( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $safe['profile_id'], 'control' => 'resume' ) ), 'revision_required', 'resume requires exact revision' );
	denied( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $safe['profile_id'], 'control' => 'unfreeze_spend' ) ), 'revision_required', 'unfreeze requires exact revision' );
	$GLOBALS['fixture_write'] = false;
	denied( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $safe['profile_id'], 'control' => 'unfreeze_spend', 'expected_revision' => 1 ) ), 'unauthorized', 'profile existence cannot grant configuration authority' );
	$GLOBALS['fixture_write'] = true;
	$active = asi_activate_profile( $safe['profile_id'], $created['profile']['revision'] );
	check( $active['profile']['enabled'] && ! $active['profile']['provider_policy']['freeze_spend'], 'typed unfreeze + resume explicitly activate with fresh revisions' );
	$persisted = ok( MAD4B_SCP_Search_Context::profile( $safe['profile_id'] ), 'persist activated profile' );
	check( $persisted['profile_sha256'] === $active['profile']['profile_sha256'], 'readback binds activation to persisted revision' );
	$noop = array( 'profile' => array_intersect_key( $persisted, array_flip( MAD4B_SCP_Search_Context::policy()['profile_fields'] ) ), 'expected_revision' => $persisted['revision'] );
	ok( MAD4B_SCP_Search_Context::plan( $noop ), 'existing active historical profile is not forcibly paused by new creation policy' );
} );
scenario( 'profile_exact_plan_revision_and_boundary', static function () {
	$input = array( 'profile' => asi_profile(), 'expected_revision' => 0 ); $plan = ok( MAD4B_SCP_Search_Context::plan( $input ), 'profile plan' );
	denied( MAD4B_SCP_Search_Runtime::profile_apply( $input ), 'plan_drift', 'SHA required' );
	$input['plan_sha256'] = $plan['plan_sha256']; ok( MAD4B_SCP_Search_Runtime::profile_apply( $input ), 'exact plan accepted' );
	check( MAD4B_SCP_Search_Context::verify( array( 'profile_id' => 'fixture.search' ) )['valid'], 'profile readback hash' );
	denied( MAD4B_SCP_Search_Context::apply( $input ), 'revision_drift', 'stale profile denied' );
	foreach ( array( 'authority', 'authorization', 'production', 'breakglass', 'egress', 'sslverify', 'endpoint', 'secret', 'api_key' ) as $key ) {
		$raw = asi_profile(); $raw['overlays']['market']['metro']['provider_policy'][ $key ] = true; denied( MAD4B_SCP_Search_Context::validate( $raw ), 'boundary', 'overlay cannot widen ' . $key );
	}
	foreach ( array( 'language_policy', 'priority_policy', 'surface_policy' ) as $key ) { $raw = asi_profile(); $raw[ $key ] = 1; denied( MAD4B_SCP_Search_Context::validate( $raw ), 'invalid', 'malformed profile denied' ); }
} );
scenario( 'overlay_precedence_minimal_drift_and_reason_chain', static function () {
	$raw = asi_profile(); foreach ( array( 'brand', 'market', 'language', 'surface', 'experiment' ) as $i => $key ) $raw['overlays'][ $key ]['selected'] = array( 'objective' => $key );
	$p = ok( MAD4B_SCP_Search_Context::plan( array( 'profile' => $raw, 'expected_revision' => 0 ) ), 'plan' )['profile'];
	$facts = array( 'languages' => array( 'en' => array( 'active' => true, 'owned_count' => 2 ), 'fr' => array( 'active' => true, 'owned_count' => 0, 'partial_translation' => true ) ) );
	$selection = array_fill_keys( array( 'brand', 'market', 'language', 'surface', 'experiment' ), 'selected' );
	$ctx = ok( MAD4B_SCP_Search_Context::compile( $p, $facts, $selection, array( 'objective' => 'request' ) ), 'context' );
	check( 'request' === $ctx['effective']['objective'] && 8 === count( $ctx['reason_chain'] ), 'precedence is explicit' );
	check( ! $ctx['resolved_languages']['fr']['owned_allowed'] && $ctx['resolved_languages']['fr']['discovery_allowed'], 'partial translation has discovery only' );
	$after = $ctx['dependencies']; $after['LANGUAGE'] = hash( 'sha256', 'disabled' );
	check( array( 'LANGUAGE_DRIFT' ) === MAD4B_SCP_Search_Context::drift( $ctx['dependencies'], $after, array( 'PROFILE', 'LANGUAGE', 'SURFACE' ) ), 'minimal invalidation' );
	check( array() === MAD4B_SCP_Search_Context::drift( $ctx['dependencies'], $after, array( 'PROFILE' ) ), 'unrelated dependencies remain usable' );
} );
scenario( 'object_surface_virtual_admission_and_indexability_conflicts', static function () {
	$p = MAD4B_SCP_Search_Context::validate( asi_profile() ); $term = asi_surface( 'TERM', 8 ); $archive = asi_surface( 'TERM_ARCHIVE', 8 );
	$archive['public_url'] = $term['public_url']; $archive['canonical_url'] = $term['canonical_url'];
	$a = ok( MAD4B_SCP_Search_Surfaces::admit( $term, $p['surface_policy'], 0 ), 'term' ); $b = ok( MAD4B_SCP_Search_Surfaces::admit( $archive, $p['surface_policy'], 1 ), 'archive' );
	check( $a['object_id'] === $b['object_id'] && $a['surface_key'] !== $b['surface_key'], 'object/surface separate identity' );
	$v = asi_surface( 'VIRTUAL_LANDING_SURFACE' ); $v['public_url'] .= '?facet=blue'; $v['provider_approved'] = true; $v['route_id'] = 'registered'; $v['cardinality_estimate'] = 20; $v['combination_approved'] = true;
	denied( MAD4B_SCP_Search_Surfaces::admit( $v, $p['surface_policy'], 0 ), 'virtual_surface_denied', 'unregistered route denied' );
	$p['surface_policy']['virtual_routes'] = array( 'registered' ); denied( MAD4B_SCP_Search_Surfaces::admit( $v, $p['surface_policy'], 0 ), 'parameter_denied', 'unknown facet denied' );
	$p['surface_policy']['query_parameters'] = array( 'facet' ); ok( MAD4B_SCP_Search_Surfaces::admit( $v, $p['surface_policy'], 0 ), 'approved virtual surface' );
	$v['combination_approved'] = false; denied( MAD4B_SCP_Search_Surfaces::admit( $v, $p['surface_policy'], 0 ), 'combination_unknown', 'unknown combination denied' );
	$s = asi_surface(); $s['eligibility']['robots_txt_allowed'] = null; check( 'requires_reconciliation' === MAD4B_SCP_Search_Surfaces::eligibility( $s, array() )['effective'], 'unknown robots is not eligible' );
	foreach ( array( 'yoast', 'aioseo', 'seopress', 'unseen-seo' ) as $id ) {
		$GLOBALS['fixture_sources'] = array( new ASI_Discovery( $id, array( 'title' => array( 'value' => 'Configured', 'observation_class' => 'configured_metadata' ) ) ), new ASI_Discovery( 'rendered', array( 'title' => array( 'value' => 'Different', 'observation_class' => 'rendered' ) ) ) );
		$seo = MAD4B_SCP_Search_Surfaces::seo( asi_surface() ); check( $seo['title']['conflicting'] && null === $seo['title']['value'] && 2 === count( $seo['title']['observations'] ), 'adapter-neutral field conflict ' . $id );
		check( 'requires_reconciliation' === MAD4B_SCP_Search_Surfaces::eligibility( asi_surface(), $seo )['effective'], 'no silent SEO winner' );
	}
} );
scenario( 'wordpress_inventory_pagination_and_public_cpt_taxonomy', static function () {
	$GLOBALS['fixture_post_types']['new_cpt'] = (object) array( 'publicly_queryable' => true, 'has_archive' => true, 'label' => 'New CPT', 'rewrite' => array( 'slug' => 'new' ) );
	for ( $i = 1; $i <= 151; ++$i ) $GLOBALS['fixture_posts'][] = (object) array( 'ID' => $i, 'post_type' => 'new_cpt', 'post_title' => 'Public ' . $i, 'post_content' => 'Body', 'post_modified_gmt' => '2026-10-04', 'post_status' => 'publish', 'post_password' => '' );
	for ( $i = 1; $i <= 43; ++$i ) $GLOBALS['fixture_terms'][] = (object) array( 'term_id' => $i, 'taxonomy' => 'category', 'name' => 'Term', 'description' => 'Archive', 'count' => 3 );
	$GLOBALS['fixture_options']['page_for_posts'] = 5; $source = new MAD4B_SCP_Search_WordPress_Discovery(); $cursor = 0; $seen = array(); $types = array(); $pages = 0;
	do { $page = $source->surfaces( $cursor, 100 ); check( count( $page['items'] ) <= 100, 'inventory page bounded' ); foreach ( $page['items'] as $s ) { $key = $s['surface_type'] . ':' . $s['public_url']; check( ! isset( $seen[ $key ] ), 'no duplicated inventory row' ); $seen[ $key ] = true; $types[ $s['surface_type'] ] = true; } $cursor = $page['cursor']; ++$pages; } while ( null !== $cursor && $pages < 20 );
	check( 240 === count( $seen ), '151 content +86 term/archive +3 site/archive surfaces' );
	foreach ( array( 'CONTENT_OBJECT', 'TERM', 'TERM_ARCHIVE', 'POST_TYPE_ARCHIVE', 'HOME', 'BLOG_INDEX' ) as $type ) check( isset( $types[ $type ] ), 'inventory type ' . $type );
} );
scenario( 'language_registry_conflict_partial_and_new_translation', static function () {
	$GLOBALS['fixture_sources'][] = new ASI_Discovery( 'independent-language', array(), array( 'en' => array( 'active' => false ), 'ar' => array( 'active' => true, 'owned_count' => 0 ) ) );
	$l = MAD4B_SCP_Search_Surfaces::languages(); check( $l['en']['conflicting'] && ! $l['en']['active'], 'language conflicts preserved' );
	$GLOBALS['fixture_sources'][0]->language_rows['fr']['owned_count'] = 1;
	check( 1 === MAD4B_SCP_Search_Surfaces::languages()['fr']['owned_count'], 'new live translation discovered without kernel branch' );

	// Query-language provenance is independent from page translation state. It
	// must bind the exact target language/market and retain an explicit semantic
	// relationship before a translated/transcreated query can enter targeting.
	$target = ok( MAD4B_SCP_Search_Contracts::target( asi_candidate() ), 'query provenance target' );
	$candidate = asi_candidate();
	$candidate['query_language_provenance'] = array(
		'source' => 'keyword-registry',
		'source_language' => 'fr',
		'target_language' => $target['language'],
		'market' => $target['market'],
		'method' => 'translation',
		'market_evidence_refs' => array( 'market-evidence-b', 'market-evidence-a', 'market-evidence-b' ),
		'semantic_cluster_relation' => 'localized_variant',
		'semantic_cluster_id' => 'cluster.localized',
		'confidence' => 0.91,
		'approval' => 'policy_approved',
		'page_translation_implies_query_translation' => false,
		'source_query_id' => hash( 'sha256', 'fr-source-query' ),
	);
	$provenance = ok( MAD4B_SCP_Search_Targets::query_language_provenance( $candidate, $target ), 'translated query provenance' );
	check( 'mad4b.query-language-provenance.v1' === $provenance['contract'] && 64 === strlen( $provenance['provenance_sha256'] ), 'query provenance is content-addressed' );
	check( array( 'market-evidence-a', 'market-evidence-b' ) === $provenance['market_evidence_refs'], 'market evidence refs are canonicalized' );
	check( $candidate['query_language_provenance']['source_query_id'] === $provenance['source_query_id'], 'source query binding retained' );

	$bad = $candidate; $bad['query_language_provenance']['target_language'] = 'zz';
	denied( MAD4B_SCP_Search_Targets::query_language_provenance( $bad, $target ), 'provenance_invalid', 'target language mismatch denied' );
	$bad = $candidate; $bad['query_language_provenance']['page_translation_implies_query_translation'] = true;
	denied( MAD4B_SCP_Search_Targets::query_language_provenance( $bad, $target ), 'provenance_invalid', 'page translation cannot imply query translation' );
	$bad = $candidate; $bad['query_language_provenance']['semantic_cluster_relation'] = 'related_intent';
	denied( MAD4B_SCP_Search_Targets::query_language_provenance( $bad, $target ), 'relation_invalid', 'translation cannot claim an unrelated semantic relation' );
	$bad = $candidate; $bad['query_language_provenance']['source_query_id'] = 'not-a-sha';
	denied( MAD4B_SCP_Search_Targets::query_language_provenance( $bad, $target ), 'source_query_invalid', 'source query requires exact SHA binding' );
	$bad = $candidate; $bad['query_language_provenance']['method'] = 'transcreation'; $bad['query_language_provenance']['semantic_cluster_relation'] = 'transcreated_variant'; $bad['query_language_provenance']['source_language'] = '';
	denied( MAD4B_SCP_Search_Targets::query_language_provenance( $bad, $target ), 'source_required', 'transcreation requires source language provenance' );
} );
scenario( 'live_archive_home_blog_and_public_registration_drift', static function () {
	$policy = MAD4B_SCP_Search_Context::validate( asi_profile() )['surface_policy'];
	$GLOBALS['fixture_post_types']['new_cpt'] = (object) array( 'publicly_queryable' => true, 'has_archive' => true, 'label' => 'New CPT', 'rewrite' => array( 'slug' => 'new' ) );
	$s = asi_surface( 'POST_TYPE_ARCHIVE' ); $s['object_ref'] = array( 'kind' => 'post_type', 'id' => 'new_cpt' );
	$a = ok( MAD4B_SCP_Search_Surfaces::refresh( $s, $policy ), 'live archive' );
	$b = ok( MAD4B_SCP_Search_Surfaces::refresh( $a, $policy ), 'unchanged archive' ); check( $a['surface_fingerprint'] === $b['surface_fingerprint'], 'archive refresh is stable' );
	$GLOBALS['fixture_post_types']['new_cpt']->rewrite['slug'] = 'replacement';
	$b = ok( MAD4B_SCP_Search_Surfaces::refresh( $a, $policy ), 'archive rewrite change' ); check( $a['surface_fingerprint'] !== $b['surface_fingerprint'], 'live archive rewrite invalidates context' );
	$GLOBALS['fixture_post_types']['new_cpt']->has_archive = false;
	denied( MAD4B_SCP_Search_Surfaces::refresh( $a, $policy ), 'no_longer_public', 'removed archive cannot reuse old eligibility' );
	$GLOBALS['fixture_posts'][] = (object) array( 'ID' => 5, 'post_type' => 'new_cpt', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Blog', 'post_content' => '', 'post_modified_gmt' => '2026-10-04' );
	$s = asi_surface( 'BLOG_INDEX', 5 ); $s['object_ref'] = array( 'kind' => 'post', 'id' => 5 ); $GLOBALS['fixture_options']['page_for_posts'] = 5;
	$a = ok( MAD4B_SCP_Search_Surfaces::refresh( $s, $policy ), 'live blog index' );
	$b = ok( MAD4B_SCP_Search_Surfaces::refresh( $a, $policy ), 'stable blog index' ); check( $a['surface_fingerprint'] === $b['surface_fingerprint'], 'blog refresh agrees with inventory fingerprint' );
	$GLOBALS['fixture_options']['page_for_posts'] = 6; denied( MAD4B_SCP_Search_Surfaces::refresh( $a, $policy ), 'route_drift', 'reassigned blog index suspends the previous surface' );
	$GLOBALS['fixture_post_types']['new_cpt']->publicly_queryable = false; denied( MAD4B_SCP_Search_Surfaces::refresh( $a, $policy ), 'no_longer_public', 'private CPT cannot retain public post eligibility' );
	$GLOBALS['fixture_terms'][] = (object) array( 'term_id' => 9, 'taxonomy' => 'private_tax', 'name' => 'Private group', 'description' => 'Body', 'count' => 2 );
	$s = asi_surface( 'TERM_ARCHIVE', 9 ); $s['object_ref'] = array( 'kind' => 'term', 'id' => 9, 'taxonomy' => 'private_tax' );
	denied( MAD4B_SCP_Search_Surfaces::refresh( $s, $policy ), 'no_longer_public', 'private taxonomy cannot retain public archive eligibility' );
	$s = asi_surface( 'HOME' ); $s['object_ref'] = array( 'kind' => 'site', 'id' => 'home' );
	$a = ok( MAD4B_SCP_Search_Surfaces::refresh( $s, $policy ), 'live home' ); $GLOBALS['fixture_options']['page_on_front'] = 7;
	$b = ok( MAD4B_SCP_Search_Surfaces::refresh( $a, $policy ), 'new front page' ); check( $a['surface_fingerprint'] !== $b['surface_fingerprint'], 'front page binding drift is visible' );
	$GLOBALS['fixture_options']['blog_public'] = 0;
	$b = ok( MAD4B_SCP_Search_Surfaces::refresh( $a, $policy ), 'site visibility re-read' ); check( 'eligible' !== $b['eligibility']['effective'], 'global noindex prevents stored eligibility from authorizing owned tracking' );
} );
scenario( 'target_identity_unicode_url_and_purpose_separation', static function () {
	foreach ( array( 'api_key', 'secret_handle', 'authorization', 'credentials', 'endpoint' ) as $key ) { $bad = asi_candidate(); $bad['query_language_provenance'] = array( $key => 'fixture-private-value' ); denied( MAD4B_SCP_Search_Contracts::target( $bad ), 'metadata_denied', 'candidate cannot persist private/egress metadata: ' . $key ); }
	$q = ok( MAD4B_SCP_Search_Contracts::query( "  Café + site:Example.com  " ), 'Unicode query' ); check( 'Café + site:Example.com' === $q['normalized_query'], 'operators/case/accents preserved' );
	denied( MAD4B_SCP_Search_Contracts::query( "line\nquery" ), 'query_invalid', 'control character rejected' );
	$a = ok( MAD4B_SCP_Search_Contracts::target( asi_candidate() ), 'identity' );
	foreach ( array( 'purpose' => 'OWNED_RANK_TRACKING', 'language' => 'fr', 'device' => 'mobile', 'market' => 'second', 'engine' => 'bing', 'query' => 'first Query' ) as $key => $value ) { $b = asi_candidate(); $b[ $key ] = $value; check( $a['target_id'] !== MAD4B_SCP_Search_Contracts::target( $b )['target_id'], 'identity dimension ' . $key ); }
	$translated = asi_candidate( 'Séjour Égypte' ); $translated['language'] = 'fr';
	$translated_target = ok( MAD4B_SCP_Search_Contracts::target( $translated ), 'translated target' );
	$translated['query_language_provenance'] = array(
		'source' => 'market_research',
		'source_language' => 'en',
		'target_language' => 'fr',
		'market' => 'metro',
		'method' => 'transcreation',
		'market_evidence_refs' => array( 'evidence:us-fr-demand' ),
		'semantic_cluster_relation' => 'transcreated_variant',
		'semantic_cluster_id' => 'cluster-egypt-packages',
		'confidence' => 0.82,
		'approval' => 'operator_approved',
		'page_translation_implies_query_translation' => false,
		'source_query_id' => hash( 'sha256', 'Egypt package' ),
	);
	$prov = ok( MAD4B_SCP_Search_Targets::query_language_provenance( $translated, $translated_target ), 'query language provenance' );
	check( 'mad4b.query-language-provenance.v1' === $prov['contract'] && 'transcreation' === $prov['method'] && 64 === strlen( $prov['provenance_sha256'] ) && ! $prov['authorizing'], 'transcreation provenance is versioned and non-authorizing' );
	$bad = $translated; $bad['query_language_provenance']['page_translation_implies_query_translation'] = true;
	denied( MAD4B_SCP_Search_Targets::query_language_provenance( $bad, $translated_target ), 'provenance_invalid', 'page translation cannot imply query translation' );
	$bad = $translated; unset( $bad['query_language_provenance']['source_language'] );
	denied( MAD4B_SCP_Search_Targets::query_language_provenance( $bad, $translated_target ), 'source_required', 'transcreation requires source language' );
	$bad = $translated; $bad['query_language_provenance']['market'] = 'second';
	denied( MAD4B_SCP_Search_Targets::query_language_provenance( $bad, $translated_target ), 'provenance_invalid', 'query provenance market must match target market' );
	$s = asi_surface(); check( MAD4B_SCP_Search_Contracts::owned_match( $s['public_url'] . '#fragment', $s ), 'fragment ignored' );
	foreach ( array( str_replace( 'https:', 'http:', $s['public_url'] ), rtrim( $s['public_url'], '/' ), $s['public_url'] . '?filter=1', 'https://fixture.example.evil.example/content_object/1/' ) as $url ) check( ! MAD4B_SCP_Search_Contracts::owned_match( $url, $s ), 'unverified alias never matches' );
	$s['verified_aliases'] = array( rtrim( $s['public_url'], '/' ) ); check( MAD4B_SCP_Search_Contracts::owned_match( $s['verified_aliases'][0], $s ), 'explicit verified canonical alias' );
} );
scenario( 'target_4k_compilation_owned_and_discovery_independence', static function () {
	$p = ok( MAD4B_SCP_Search_Context::plan( array( 'profile' => asi_profile(), 'expected_revision' => 0 ) ), 'profile' )['profile']; $facts = MAD4B_SCP_Search_Runtime::facts( $p ); $ctx = MAD4B_SCP_Search_Context::compile( $p, $facts );
	$list = array(); for ( $i = 0; $i < 4200; ++$i ) $list[] = asi_candidate( 'Unseen term ' . $i );
	$list[] = asi_candidate( 'Missing owned', 'OWNED_RANK_TRACKING' ); $list[] = asi_candidate( 'Independent discovery' );
	$compiled = ok( MAD4B_SCP_Search_Targets::compile( $ctx, $list, $facts['surfaces'] ), '4K+ compilation' ); check( 4201 === count( $compiled['targets'] ) && in_array( 'owned_surface_required', $compiled['excluded'], true ), '4K+ targets plus independent discovery' );
	check( ! $compiled['provider_execution_performed'], 'compilation does not purchase evidence' );
	$list = array_fill( 0, 5001, asi_candidate() ); denied( MAD4B_SCP_Search_Targets::compile( $ctx, $list, array() ), 'compile_bound', 'bounded compilation' );
} );
scenario( 'decision_policy_provenance_monotonicity_and_refresh', static function () {
	$t = MAD4B_SCP_Search_Contracts::target( asi_candidate() ); $p = MAD4B_SCP_Search_Context::policy()['defaults']['priority_policy']; $now = time();
	$f = array(); foreach ( $p['weights'] as $key => $weight ) $f[ $key ] = array( 'value' => 0.5, 'source' => 'first_party_search_performance', 'observed_at' => $now - 5, 'expires_at' => $now + 50, 'confidence' => 0.8, 'normalization_version' => 'bounded-test.v1', 'market' => 'metro', 'language' => 'en' );
	$low = MAD4B_SCP_Search_Decisions::score( $t, $p, $f, array( 'last_observed' => $now - 10 ), $now ); $f['business_value']['value'] = 0.9; $high = MAD4B_SCP_Search_Decisions::score( $t, $p, $f, array( 'last_observed' => $now - 10 ), $now ); check( $high['effective_priority'] > $low['effective_priority'], 'monotonic commercial-value factor' );
	$f['business_value']['expires_at'] = $now - 1; $missing = MAD4B_SCP_Search_Decisions::score( $t, $p, $f, array(), $now ); check( 'policy_missing_value' === $missing['factor_provenance']['business_value']['source'], 'expired value replaced with explicit default' );
	check( $missing === MAD4B_SCP_Search_Decisions::score( $t, $p, $f, array(), $now ), 'deterministic same inputs' );
	$refresh = MAD4B_SCP_Search_Context::policy()['defaults']['refresh_policy']; $stable = MAD4B_SCP_Search_Decisions::refresh( $refresh, array( 'confidence' => 1 ), array() ); $urgent = MAD4B_SCP_Search_Decisions::refresh( $refresh, array( 'confidence' => 0.2, 'volatility' => 40, 'ranking_loss' => true ), array() ); check( $urgent < $stable && $urgent >= $refresh['min_seconds'], 'target-specific refresh responds to loss/volatility' );
	denied( MAD4B_SCP_Search_Decisions::pacing( -1, $now + 10, $now ), 'unknown', 'negative pacing denied' );
} );
scenario( 'fairness_aging_and_minimum_coverage_no_starvation', static function () {
	$p = MAD4B_SCP_Search_Context::plan( array( 'profile' => asi_profile(), 'expected_revision' => 0 ) )['profile']; $ctx = MAD4B_SCP_Search_Context::compile( $p, array() ); $rows = array(); $coverage = array(); $seen = array();
	for ( $i = 0; $i < 48; ++$i ) { $t = MAD4B_SCP_Search_Contracts::target( asi_candidate( 'Query ' . $i ) ); $t['language'] = array( 'en', 'fr', 'ar' )[ $i % 3 ]; $t['market'] = $i % 2 ? 'metro' : 'second'; $rows[] = array( 'target' => $t, 'decision' => array( 'effective_priority' => $i === 0 ? 100000000 : 1 ), 'last_observed' => 0, 'provider_eligible' => true ); }
	for ( $round = 0; $round < 48; ++$round ) { $batch = MAD4B_SCP_Search_Decisions::batch( $ctx, $rows, 1, $coverage, 1000 + $round ); $chosen = $batch['selected'][0]['target']['target_id']; $seen[ $chosen ] = true; $coverage = $batch['coverage_after']; foreach ( $rows as &$row ) if ( $row['target']['target_id'] === $chosen ) $row['last_observed'] = 1000 + $round; unset( $row ); }
	check( 48 === count( $seen ), 'all 48 targets observed within 48 constrained slots despite 1e8 priority noisy neighbor' );
	foreach ( array( 'en', 'fr', 'ar' ) as $lang ) check( 16 === $coverage['language'][ $lang ], 'equal minimum language coverage' );
} );
scenario( 'budget_hierarchy_protected_reserve_depth_rate_and_expiry', static function () {
	$p = asi_policy_nodes( 20, 2 ); $p['reserve_fraction'] = 0.25; $child = $p['nodes'][0]; $child['id'] = 'brand'; $child['selectors'] = array( 'brand_id' => 'limited' ); $child['monthly_units'] = 1; $child['max_depth'] = 3; $p['nodes'][] = $child;
	$now = time(); ok( MAD4B_SCP_Search_Budgets::configure( 'quota.account', $p, receipt( 20, 'cycle.1', $now ), $now ), 'quota configure' ); $id = hash( 'sha256', 'one' );
	$r = ok( MAD4B_SCP_Search_Budgets::reserve( 'quota.account', $id, array( 'brand_id' => 'limited', 'depth' => 3 ), 1, 5, $now ), 'child reserve' );
	denied( MAD4B_SCP_Search_Budgets::reserve( 'quota.account', hash( 'sha256', 'two' ), array( 'brand_id' => 'limited', 'depth' => 3 ), 1, 5, $now ), 'node_exhausted', 'child cap cannot be bypassed' );
	denied( MAD4B_SCP_Search_Budgets::reserve( 'quota.account', hash( 'sha256', 'deep' ), array( 'brand_id' => 'limited', 'depth' => 10 ), 1, 5, $now ), 'depth_exceeded', 'depth budget constrained' );
	denied( MAD4B_SCP_Search_Budgets::reserve( 'quota.account', hash( 'sha256', 'reserve' ), array(), 15, 5, $now ), 'reserve_protected', 'protected fraction held' );
	ok( MAD4B_SCP_Search_Budgets::transition( 'quota.account', $id, $r['epoch'], 'entered', $now ), 'entry' );
	ok( MAD4B_SCP_Search_Budgets::transition( 'quota.account', $id, $r['epoch'], 'unknown', $now ), 'unknown effect' );
	denied( MAD4B_SCP_Search_Budgets::configure( 'quota.account', $p, receipt( 20, 'cycle.2', $now ), $now ), 'reconciliation_required', 'reset cannot discard unknown effect' );
	check( 'unknown' === MAD4B_SCP_Search_Budgets::status( 'quota.account' )['reservations'][ $id ]['state'], 'unknown holds survive lease expiry' );
	denied( MAD4B_SCP_Search_Budgets::reserve( 'quota.account', hash( 'sha256', 'late' ), array(), 1, 5, $now + 86401 ), 'reset', 'billing reset needs observed receipt' );
	denied( MAD4B_SCP_Search_Budgets::configure( 'negative', $p, receipt( -1, 'cycle.1', $now ), $now ), 'unknown', 'negative allowance fails closed' );
	$local = new class implements MAD4B_SCP_Search_Store_Backend { private $rows = array(); public function read( $key ) { return isset( $this->rows[ $key ] ) ? $this->rows[ $key ] : null; } public function compare_exchange( $key, $old, array $next ) { $this->rows[ $key ] = $next; return true; } public function scan( $a, $b, $c ) { return array(); } };
	add_filter( 'mad4b_scp_search_account_budget_backend', static function () use ( $local ) { return $local; } );
	$state = ok( MAD4B_SCP_Search_Budgets::configure( 'local.account', asi_policy_nodes(), receipt( 100, 'cycle.1', $now ), $now ), 'local budget' ); check( ! $state['hard_global_enforcement'] && 'local_allocation' === $state['authority_scope'], 'honest downgrade without shared authority' );
} );
scenario( 'shared_account_race_across_processes_and_sites', static function () {
	check( function_exists( 'pcntl_fork' ), 'process race support required' );
	for ( $round = 0; $round < 8; ++$round ) {
		// Bind the usage receipt and admission clock to the same round. A fixed
		// scenario-start clock makes later fresh receipts appear to come from the
		// future and correctly trips the runtime's fail-closed freshness check.
		$now = time();
		$account = 'race.' . $round; ok( MAD4B_SCP_Search_Budgets::configure( $account, asi_policy_nodes( 1 ), receipt( 1, 'cycle.1', $now ), $now ), 'last-unit configure' ); $children = array();
		for ( $worker = 0; $worker < 12; ++$worker ) { $pid = pcntl_fork(); if ( 0 === $pid ) { $GLOBALS['fixture_store']->reconnect(); $GLOBALS['fixture_site'] = sprintf( '%08d-1111-4111-8111-111111111111', $worker + 1 ); $value = MAD4B_SCP_Search_Budgets::reserve( $account, hash( 'sha256', $round . ':' . $worker ), array(), 1, 5, $now ); exit( is_wp_error( $value ) ? 0 : 3 ); } check( $pid > 0, 'fork launched' ); $children[] = $pid; }
		$admitted = 0; foreach ( $children as $pid ) { pcntl_waitpid( $pid, $status ); check( pcntl_wifexited( $status ), 'worker exited normally' ); if ( 3 === pcntl_wexitstatus( $status ) ) ++$admitted; else check( 0 === pcntl_wexitstatus( $status ), 'worker returned known denial' ); }
		check( 1 === $admitted, 'exactly one winner from 12 workers on shared last unit' );
		check( 1 === count( MAD4B_SCP_Search_Budgets::status( $account )['reservations'] ), 'one atomic reservation across sites' );
	}
} );
scenario( 'provider_auction_quota_open_probe_failover_and_generation', static function () {
	$input = asi_seed(); $alpha = $GLOBALS['fixture_providers'][0]; $beta = $GLOBALS['fixture_providers'][1];
	$plan = ok( MAD4B_SCP_Search_Worker::plan( $input ), 'auction' ); check( 'alpha' === $plan['routing']['selected']['provider_id'] && 2 === count( $plan['routing']['alternatives'] ), 'economic candidate selection' );
	$GLOBALS['fixture_breakers']['alpha'] = 'open'; $plan = ok( MAD4B_SCP_Search_Worker::plan( $input ), 'failover' ); check( 'beta' === $plan['routing']['selected']['provider_id'], 'OPEN provider never executes paid search' );
	ok( MAD4B_SCP_Search_Providers::probe( array( 'provider_id' => 'alpha' ) ), 'bounded read probe' ); check( 0 === $alpha->calls && 'closed' === $GLOBALS['fixture_breakers']['alpha'], 'probe recovers without buying SERP' );
	$alpha->data['usage']['remaining'] = 0; check( 'beta' === MAD4B_SCP_Search_Worker::plan( $input )['routing']['selected']['provider_id'], 'quota exhaustion selects independent provider' );
	$beta->data['certification_expires_at'] = time() - 1; denied( MAD4B_SCP_Search_Worker::plan( $input ), 'no_eligible_provider', 'stale certification denies all providers' );
} );
scenario( 'durable_capture_replay_cache_and_no_authority', static function () {
	$input = asi_seed(); list( $result, $plan ) = capture( $input ); check( 'COMPLETE' === $result['state'], 'job complete' );
	$snapshot = ok( MAD4B_SCP_Search_Store::evidence( 'snapshot', $result['snapshot_id'] ), 'capture evidence' );
	check( 'live_serp' === $snapshot['provenance']['source_class'] && ! $snapshot['raw_retained'] && ! $snapshot['authorizing'], 'receipt provenance, no raw retention/authority' );
	$job = MAD4B_SCP_Search_Store::read( 'job', $result['job_id'] ); foreach ( array( 'CANDIDATE', 'ADMITTED', 'SCHEDULED', 'LEASED', 'PROVIDER_PREPARED', 'PROVIDER_ENTERED', 'PROVIDER_RETURNED', 'VALIDATED', 'NORMALIZED', 'EVIDENCE_COMMITTED', 'SIGNALS_DERIVED', 'COMPLETE' ) as $state ) check( in_array( $state, array_column( $job['checkpoints'], 'state' ), true ), 'durable checkpoint ' . $state );
	$replay = ok( MAD4B_SCP_Search_Worker::apply( array_merge( $input, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'replay' ); check( $replay['replayed'] && 1 === $GLOBALS['fixture_providers'][0]->calls, 'semantic replay cannot repurchase' );
	denied( MAD4B_SCP_Search_Worker::apply( array_merge( $input, array( 'plan_sha256' => str_repeat( '0', 64 ) ) ) ), 'replay_plan_mismatch', 'wrong reviewed plan cannot replay' );
	$input['observation_epoch']--; list( $reuse, $cache_plan ) = capture( $input ); check( 'CACHE_REUSE' === $cache_plan['mode'] && $reuse['cache_reused'] && 1 === $GLOBALS['fixture_providers'][0]->calls, 'fresh exact evidence reused before reservation' );
	$signal = $job['inference']['signal_ids'][0]; $proposal = ok( MAD4B_SCP_Search_Runtime::proposal( array( 'signal_id' => $signal, 'experience_slug' => 'editorial' ) ), 'content proposal' ); check( ! $proposal['authorizing'] && ! $proposal['content_mutation_performed'] && 'mad4b/editorial-update-plan' === $proposal['next_plan_ability'], 'handoff only to governed plan' );
	$GLOBALS['fixture_environment'] = 'production'; denied( MAD4B_SCP_Search_Worker::apply( $input ), 'unauthorized', 'Production execution forbidden' );
} );
scenario( 'ambiguous_effect_reconciliation_before_any_retry', static function () {
	$input = asi_seed(); $a = $GLOBALS['fixture_providers'][0]; $a->effect = 'unknown'; $plan = ok( MAD4B_SCP_Search_Worker::plan( $input ), 'plan' ); $input['plan_sha256'] = $plan['plan_sha256'];
	denied( MAD4B_SCP_Search_Worker::apply( $input ), 'reconciliation_required', 'timeout held uncertain' );
	for ( $i = 0; $i < 3; ++$i ) denied( MAD4B_SCP_Search_Worker::apply( $input ), 'reconciliation_required', 'blind retry denied' ); check( 1 === $a->calls, 'exactly one potentially charged call' );
	$new = $input; $new['observation_epoch']--; $new_plan = MAD4B_SCP_Search_Worker::plan( $new );
	denied( MAD4B_SCP_Search_Worker::apply( array_merge( $new, array( 'plan_sha256' => is_wp_error( $new_plan ) ? $plan['plan_sha256'] : $new_plan['plan_sha256'] ) ) ), 'reconciliation', 'changing observation epoch cannot bypass uncertain target lock' );
	$job = MAD4B_SCP_Search_Store::read( 'job', $plan['job_id'] ); check( 'unknown' === MAD4B_SCP_Search_Budgets::status( asi_account( 'alpha' ) )['reservations'][ $plan['job_id'] ]['state'], 'uncertain quota remains reserved' );
	$GLOBALS['fixture_receipt'] = array( 'verified' => true, 'effect' => false, 'request_fingerprint' => str_repeat( '0', 64 ), 'provider_generation' => $plan['provider_generation'] ); denied( MAD4B_SCP_Search_Worker::reconcile( array( 'job_id' => $plan['job_id'] ) ), 'receipt_invalid', 'unbound receipt rejected' );
	$GLOBALS['fixture_receipt']['request_fingerprint'] = MAD4B_SCP_Search_Contracts::digest( $plan['request'] ); $GLOBALS['fixture_receipt']['effect'] = true; $GLOBALS['fixture_receipt']['response'] = array( 'payload' => array( 'receipt' => 1 ), 'raw_sha256' => hash( 'sha256', 'received' ) );
	$result = ok( MAD4B_SCP_Search_Worker::reconcile( array( 'job_id' => $plan['job_id'] ) ), 'verified prior effect' ); check( 'COMPLETE' === $result['state'] && 1 === $a->calls, 'reconciled evidence without a second provider call' );
} );
scenario( 'last_unit_and_single_concurrency_capture_revalidates_own_hold', static function () {
	$input = asi_seed(); $a = $GLOBALS['fixture_providers'][0];
	// Use a new account and one total unit so the pre-entry preview cannot admit another request.
	$a->data['account_id'] = 'last.unit'; $a->data['shared_account'] = false; $a->data['usage']['remaining'] = 1;
	ok( MAD4B_SCP_Search_Budgets::configure( 'last.unit', asi_policy_nodes( 1, 1 ), receipt( 1 ), time() ), 'last-unit account' );
	$GLOBALS['fixture_providers'][1]->data['active'] = false;
	list( $result ) = capture( $input ); check( 'COMPLETE' === $result['state'] && 1 === $a->calls, 'own held unit is revalidated without a second admission' );
	$state = MAD4B_SCP_Search_Budgets::status( 'last.unit' ); check( 0 === $state['remaining'] && 1 === count( $state['reservations'] ), 'one exact debit and one hold' );
	denied( MAD4B_SCP_Search_Budgets::reserve( 'last.unit', hash( 'sha256', 'second' ), array(), 1, 5, time() ), 'reserve_protected', 'another request cannot spend last unit' );
} );
scenario( 'verified_no_effect_release_without_blind_retry', static function () {
	$input = asi_seed(); $GLOBALS['fixture_providers'][0]->effect = 'unknown'; $plan = MAD4B_SCP_Search_Worker::plan( $input ); $input['plan_sha256'] = $plan['plan_sha256']; MAD4B_SCP_Search_Worker::apply( $input );
	$GLOBALS['fixture_receipt'] = array( 'verified' => true, 'effect' => false, 'request_fingerprint' => MAD4B_SCP_Search_Contracts::digest( $plan['request'] ), 'provider_generation' => $plan['provider_generation'] );
	$r = ok( MAD4B_SCP_Search_Worker::reconcile( array( 'job_id' => $plan['job_id'] ) ), 'verified no-effect' ); check( 'RECONCILED_NO_EFFECT' === $r['state'] && ! $r['blind_retry_allowed'], 'no-effect terminal state' );
	check( 'released' === MAD4B_SCP_Search_Budgets::status( asi_account( 'alpha' ) )['reservations'][ $plan['job_id'] ]['state'], 'quota release requires verified receipt' );
} );
scenario( 'late_lease_restore_and_provider_generation_fences', static function () {
	foreach ( array( 'lease', 'restore', 'provider' ) as $fault ) {
		asi_reset(); $input = asi_seed(); $plan = MAD4B_SCP_Search_Worker::plan( $input ); $a = $GLOBALS['fixture_providers'][0];
		$a->callback = static function () use ( $fault, $plan, $a ) { if ( 'restore' === $fault ) ++$GLOBALS['fixture_epoch']; elseif ( 'provider' === $fault ) $a->data['certification_generation'] = hash( 'sha256', 'replacement' ); else { $row = MAD4B_SCP_Search_Store::read( 'job', $plan['job_id'] ); $next = $row; $next['lease_until'] = time() - 1; MAD4B_SCP_Search_Store::cas( 'job', $plan['job_id'], $row, $next, 'LEASE_LOST' ); } };
		denied( MAD4B_SCP_Search_Worker::apply( array_merge( $input, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'fenced', 'late response rejected ' . $fault );
		check( 1 === $a->calls && 'entered' === MAD4B_SCP_Search_Budgets::status( asi_account( 'alpha' ) )['reservations'][ $plan['job_id'] ]['state'], 'quota is held after ' . $fault );
	}
} );
scenario( 'local_checkpoint_resume_does_not_repurchase', static function () {
	$input = asi_seed(); $plan = MAD4B_SCP_Search_Worker::plan( $input ); $failed = false;
	$GLOBALS['fixture_cas_failure'] = static function ( $key, $old, $next ) use ( &$failed ) { if ( ! $failed && isset( $next['state'] ) && 'SIGNALS_DERIVED' === $next['state'] ) { $failed = true; return true; } return false; };
	denied( MAD4B_SCP_Search_Worker::apply( array_merge( $input, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'compare_exchange', 'crash after evidence and cost settlement' ); $GLOBALS['fixture_cas_failure'] = null;
	$row = MAD4B_SCP_Search_Store::read( 'job', $plan['job_id'] ); check( 'EVIDENCE_COMMITTED' === $row['state'], 'durable local checkpoint' ); $next = $row; $next['lease_until'] = time() - 1; ok( MAD4B_SCP_Search_Store::cas( 'job', $plan['job_id'], $row, $next, 'WORKER_STOPPED' ), 'expire owner' );
	$r = ok( MAD4B_SCP_Search_Worker::reconcile( array( 'job_id' => $plan['job_id'] ) ), 'local resume' ); check( 'COMPLETE' === $r['state'] && 1 === $GLOBALS['fixture_providers'][0]->calls, 'resume reuses immutable pending evidence and settled charge' );
	check( 99 === MAD4B_SCP_Search_Budgets::status( asi_account( 'alpha' ) )['remaining'], 'settlement idempotent' );
} );
scenario( 'domain_profile_market_identity_and_safe_retarget_invariants', static function () {
	$input = array( 'profile' => asi_profile( 'domain-safety' ), 'expected_revision' => 0 );
	$initial = ok( MAD4B_SCP_Search_Context::plan( $input ), 'direct profile plan' );
	$created = ok( MAD4B_SCP_Search_Context::apply( array_merge( $input, array( 'plan_sha256' => $initial['plan_sha256'] ) ) ), 'direct profile commit' );
	$id = $created['profile']['profile_id'];
	$current = asi_activate_profile( $id, $created['profile']['revision'] )['profile'];
	$active_revision = $current['revision'];
	$collision = asi_profile( 'same-market-different-profile' );
	denied( MAD4B_SCP_Search_Context::plan( array( 'profile' => $collision, 'expected_revision' => 0 ) ), 'market_identity_conflict', 'existing market ID cannot be shared across profiles' );
	$fields = array_flip( MAD4B_SCP_Search_Context::policy()['profile_fields'] );
	$raw = array_intersect_key( $current, $fields );
	// A direct generic profile API cannot resume, freeze, unfreeze, disable or
	// enable providers; these are typed operational controls only.
	$pausing = $raw; $pausing['enabled'] = false;
	denied( MAD4B_SCP_Search_Context::plan( array( 'profile' => $pausing, 'expected_revision' => $active_revision ) ), 'state_requires_explicit_control', 'generic API cannot pause observations' );
	$freezing = $raw; $freezing['provider_policy']['freeze_spend'] = true;
	denied( MAD4B_SCP_Search_Context::plan( array( 'profile' => $freezing, 'expected_revision' => $active_revision ) ), 'state_requires_explicit_control', 'generic API cannot freeze or unfreeze spend' );
	$disabled = $raw; $disabled['provider_policy']['disabled'] = array( 'alpha' );
	denied( MAD4B_SCP_Search_Context::plan( array( 'profile' => $disabled, 'expected_revision' => $active_revision ) ), 'state_requires_explicit_control', 'generic API cannot change provider enabled state' );
	$control = ok( MAD4B_SCP_Search_Context::control_transition( array( 'profile_id' => $id, 'control' => 'pause', 'expected_revision' => $active_revision, 'profile' => array( 'enabled' => true, 'budget_policy' => array( 'nodes' => array( 'forged' ) ) ) ) ), 'typed pause ignores supplied profile JSON' );
	check( ! $control['profile']['enabled'] && $active_revision + 1 === $control['profile']['revision'], 'typed pause applies only requested state and current policy' );
	$control = ok( MAD4B_SCP_Search_Context::control_transition( array( 'profile_id' => $id, 'control' => 'resume', 'expected_revision' => $control['profile']['revision'] ) ), 'typed resume still works' );
	check( $control['profile']['enabled'] && $active_revision + 2 === $control['profile']['revision'], 'typed resume readback' );
	$raw = array_intersect_key( $control['profile'], $fields );
	$country = $raw; $country['markets'][0]['country'] = 'AU';
	denied( MAD4B_SCP_Search_Context::plan( array( 'profile' => $country, 'expected_revision' => $control['profile']['revision'] ) ), 'market_identity_locked', 'direct API cannot reassign market country' );
	$removed = $raw; array_shift( $removed['markets'] );
	denied( MAD4B_SCP_Search_Context::plan( array( 'profile' => $removed, 'expected_revision' => $control['profile']['revision'] ) ), 'market_identity_locked', 'direct API cannot erase historical market IDs' );
	$lang = $raw; $lang['language_policy']['desired'][] = 'es';
	denied( MAD4B_SCP_Search_Context::plan( array( 'profile' => $lang, 'expected_revision' => $control['profile']['revision'] ) ), 'targeting_requires_pause_and_spend_freeze', 'active and spend-unfrozen retarget denied' );
	// An attempt to pause/freeze and retarget in the *same* transaction is rejected.
	$mixed = $lang; $mixed['enabled'] = false; $mixed['provider_policy']['freeze_spend'] = true;
	denied( MAD4B_SCP_Search_Context::plan( array( 'profile' => $mixed, 'expected_revision' => $control['profile']['revision'] ) ), 'targeting_requires_pause_and_spend_freeze', 'combined pause freeze retarget denied' );
	$paused = ok( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $id, 'control' => 'pause', 'expected_revision' => $control['profile']['revision'] ) ), 'explicit pause works' );
	$frozen = ok( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $id, 'control' => 'freeze_spend', 'expected_revision' => $paused['profile']['revision'] ) ), 'explicit freeze works' );
	$mixed['markets'][] = array( 'id' => 'new-market', 'country' => 'JP' );
	$safe = array( 'profile' => $mixed, 'expected_revision' => $frozen['profile']['revision'] );
	$plan = ok( MAD4B_SCP_Search_Context::plan( $safe ), 'safe new market and language plan' );
	$result = ok( MAD4B_SCP_Search_Context::apply( array_merge( $safe, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'safe new market and language apply' );
	check( 'US' === $result['profile']['markets'][0]['country'] && 'JP' === $result['profile']['markets'][2]['country'], 'existing geo history unchanged and new market added' );
	check( ! $result['profile']['enabled'] && $result['profile']['provider_policy']['freeze_spend'], 'safe update never activates observations or spend' );
	denied( MAD4B_SCP_Search_Context::apply( array_merge( $safe, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'revision_drift', 'stale direct API apply denied' );
} );
scenario( 'typed_control_scope_blocks_nested_profile_api_escalation', static function () {
	$owner = asi_profile( 'control-scope-owner' );
	$owner['markets'][0]['id'] = 'scope-owner-us'; $owner['markets'][1]['id'] = 'scope-owner-fr';
	$peer = asi_profile( 'control-scope-peer' );
	$peer['markets'][0]['id'] = 'scope-peer-us'; $peer['markets'][1]['id'] = 'scope-peer-fr';
	foreach ( array( $owner, $peer ) as $raw ) {
		$input = array( 'profile' => $raw, 'expected_revision' => 0 );
		$plan = ok( MAD4B_SCP_Search_Context::plan( $input ), 'scope fixture create plan' );
		ok( MAD4B_SCP_Search_Context::apply( array_merge( $input, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'scope fixture create' );
	}
	$owner_key = MAD4B_SCP_Search_Store::key( 'profile', 'control-scope-owner' );
	$GLOBALS['scope_nested_checked'] = false; $GLOBALS['scope_nested_denial'] = null;
	$GLOBALS['fixture_cas_failure'] = static function ( $key, $old, $next ) use ( $owner_key, $peer ) {
		if ( $key !== $owner_key || $GLOBALS['scope_nested_checked'] ) return false;
		$GLOBALS['scope_nested_checked'] = true;
		$raw = $peer; $raw['enabled'] = true;
		$GLOBALS['scope_nested_denial'] = MAD4B_SCP_Search_Context::plan( array( 'profile' => $raw, 'expected_revision' => 1 ) );
		return false;
	};
	$paused = ok( MAD4B_SCP_Search_Context::control_transition( array( 'profile_id' => 'control-scope-owner', 'control' => 'pause', 'expected_revision' => 1 ) ), 'exact control during simulated reentrancy' );
	$GLOBALS['fixture_cas_failure'] = null;
	check( $GLOBALS['scope_nested_checked'] && is_wp_error( $GLOBALS['scope_nested_denial'] ) && false !== strpos( $GLOBALS['scope_nested_denial']->get_error_code(), 'state_requires_explicit_control' ), 'peer cannot borrow scoped control state during nested store callback' );
	check( 2 === $paused['profile']['revision'] && ! $paused['profile']['enabled'], 'outer typed transition remains correct' );
	denied( MAD4B_SCP_Search_Context::control_transition( array( 'profile_id' => 'control-scope-owner', 'control' => 'resume' ) ), 'revision_required', 'high-impact resume cannot omit exact revision' );
	$restarted = ok( MAD4B_SCP_Search_Context::control_transition( array( 'profile_id' => 'control-scope-owner', 'control' => 'resume', 'expected_revision' => 2 ) ), 'typed resume after reentrant callback' );
	check( 3 === $restarted['profile']['revision'] && $restarted['profile']['enabled'], 'typed control scope is released after completion' );
	$next = asi_profile( 'unauthorized-domain-apply' ); $next['markets'][0]['id'] = 'unauthorized-us'; $next['markets'][1]['id'] = 'unauthorized-fr';
	$input = array( 'profile' => $next, 'expected_revision' => 0 );
	$plan = ok( MAD4B_SCP_Search_Context::plan( $input ), 'read-only plan available to unauthorized executor' );
	$GLOBALS['fixture_write'] = false;
	denied( MAD4B_SCP_Search_Context::apply( array_merge( $input, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'configuration_unauthorized', 'domain apply itself enforces config authority' );
	$GLOBALS['fixture_write'] = true;
} );
scenario( 'profile_registry_malformed_denial_before_claims_or_profile_write', static function () {
	$invalid = array(
		'missing_ids' => array( 'unknown' => true ),
		'string_ids' => array( 'ids' => 'not-an-array' ),
		'associative_ids' => array( 'ids' => array( 'named' => 'valid-profile' ) ),
		'duplicate_ids' => array( 'ids' => array( 'duplicated', 'duplicated' ) ),
		'invalid_profile_id' => array( 'ids' => array( 'invalid profile' ) ),
		'foreign_fields' => array( 'ids' => array(), 'unexpected' => true ),
	);
	foreach ( $invalid as $label => $bad ) {
		asi_reset();
		$raw = asi_profile( 'corrupt-' . str_replace( '_', '-', $label ) );
		$args = array( 'profile' => $raw, 'expected_revision' => 0 );
		$preplan = ok( MAD4B_SCP_Search_Context::plan( $args ), 'clean pre-corruption plan: ' . $label );
		ok( MAD4B_SCP_Search_Store::cas( 'registry', 'profiles', null, $bad, 'CORRUPT_REGISTRY_FIXTURE' ), 'fixture corrupt registry: ' . $label );
		denied( MAD4B_SCP_Search_Context::plan( $args ), 'profile_registry_invalid', 'reject malformed registry on creation plan: ' . $label );
		denied( MAD4B_SCP_Search_Context::apply( array_merge( $args, array( 'plan_sha256' => $preplan['plan_sha256'] ) ) ), 'profile_registry_invalid', 'reject registry changed between plan and apply: ' . $label );
		check( null === MAD4B_SCP_Search_Store::read( 'profile', $raw['profile_id'] ), 'corrupt registry cannot create a profile: ' . $label );
		foreach ( $raw['markets'] as $market ) check( null === MAD4B_SCP_Search_Store::read( 'market-identity', $market['id'] ), 'no orphan market claim: ' . $label );
		check( 0 === $GLOBALS['fixture_providers'][0]->calls && 0 === $GLOBALS['fixture_providers'][1]->calls, 'no paid provider traffic: ' . $label );
	}
} );
scenario( 'profile_registry_corruption_rejected_for_existing_no_new_market_edit', static function () {
	foreach ( array( 'missing' => null, 'scalar' => 'bad', 'duplicated' => array( 'a', 'a' ), 'unversioned' => null, 'event_tamper' => null, 'payload_tamper' => array( 'foreign-profile' ) ) as $label => $value ) {
		asi_reset();
		$raw = asi_profile( 'existing-registry-' . $label );
		$args = array( 'profile' => $raw, 'expected_revision' => 0 );
		$plan = ok( MAD4B_SCP_Search_Context::plan( $args ), 'valid initial plan: ' . $label );
		$created = ok( MAD4B_SCP_Search_Context::apply( array_merge( $args, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'valid initial create: ' . $label );
		$profile_before = MAD4B_SCP_Search_Store::read( 'profile', $raw['profile_id'] );
		$edit = array( 'profile' => $raw, 'expected_revision' => $created['profile']['revision'] );
		$clean = ok( MAD4B_SCP_Search_Context::plan( $edit ), 'clean no-new-market edit plan: ' . $label );
		$key = MAD4B_SCP_Search_Store::key( 'registry', 'profiles' );
		$registry = MAD4B_SCP_Search_Store::read( 'registry', 'profiles' );
		$corrupt = $registry;
		if ( 'missing' === $label ) unset( $corrupt['ids'] );
		elseif ( 'unversioned' === $label ) unset( $corrupt['_revision'] );
		elseif ( 'event_tamper' === $label ) $corrupt['_event_sha256'] = str_repeat( '0', 64 );
		else $corrupt['ids'] = $value;
		check( $GLOBALS['fixture_store']->compare_exchange( $key, $registry, $corrupt ), 'fixture registry corruption persisted: ' . $label );
		denied( MAD4B_SCP_Search_Context::plan( $edit ), 'profile_registry_invalid', 'no-new-market plan must still validate registry: ' . $label );
		denied( MAD4B_SCP_Search_Context::apply( array_merge( $edit, array( 'plan_sha256' => $clean['plan_sha256'] ) ) ), 'profile_registry_invalid', 'no-new-market apply must fail closed: ' . $label );
		check( MAD4B_SCP_Search_Store::read( 'profile', $raw['profile_id'] ) === $profile_before, 'corruption cannot mutate existing profile: ' . $label );
	}
} );
scenario( 'corrupt_registry_does_not_disable_scoped_emergency_stop', static function () {
	$raw = asi_profile( 'emergency-when-registry-corrupt' );
	$raw['markets'][0]['id'] = 'emergency-metro';
	$raw['markets'][1]['id'] = 'emergency-second';
	$args = array( 'profile' => $raw, 'expected_revision' => 0 );
	$plan = ok( MAD4B_SCP_Search_Context::plan( $args ), 'register emergency-test profile' );
	$created = ok( MAD4B_SCP_Search_Context::apply( array_merge( $args, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'persist emergency-test profile' );
	$key = MAD4B_SCP_Search_Store::key( 'registry', 'profiles' );
	$before = MAD4B_SCP_Search_Store::read( 'registry', 'profiles' );
	$corrupt = $before; $corrupt['ids'] = 'invalid';
	check( $GLOBALS['fixture_store']->compare_exchange( $key, $before, $corrupt ), 'inject malformed registry' );
	$pause = ok( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $raw['profile_id'], 'control' => 'pause', 'expected_revision' => $created['profile']['revision'] ) ), 'scoped emergency pause still allowed' );
	check( empty( $pause['profile']['enabled'] ) && ! empty( $pause['control_readback_verified'] ), 'pause readback proves stopped observations' );
	$freeze = ok( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $raw['profile_id'], 'control' => 'freeze_spend', 'expected_revision' => $pause['profile']['revision'] ) ), 'scoped emergency spend freeze still allowed' );
	check( ! empty( $freeze['profile']['provider_policy']['freeze_spend'] ) && ! empty( $freeze['control_readback_verified'] ), 'freeze readback proves spend stopped' );
	check( MAD4B_SCP_Search_Store::read( 'registry', 'profiles' ) === $corrupt, 'emergency control must not silently rewrite corrupt registry' );
	check( 0 === $GLOBALS['fixture_providers'][0]->calls && 0 === $GLOBALS['fixture_providers'][1]->calls, 'no paid provider calls' );
} );
scenario( 'domain_market_claim_readback_proves_ownership_and_flags_legacy', static function () {
	$raw = asi_profile( 'claim-readback' ); $raw['markets'][0]['id'] = 'claim-metro'; $raw['markets'][1]['id'] = 'claim-second';
	$input = array( 'profile' => $raw, 'expected_revision' => 0 );
	$plan = ok( MAD4B_SCP_Search_Context::plan( $input ), 'owned claim plan' );
	ok( MAD4B_SCP_Search_Context::apply( array_merge( $input, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'owned claim commit' );
	$verified = ok( MAD4B_SCP_Search_Context::verify( array( 'profile_id' => 'claim-readback' ) ), 'owned claim readback' );
	check( $verified['valid'] && $verified['market_identity_integrity'] && $verified['market_claims_certified'] && 0 === $verified['legacy_unclaimed_market_count'], 'new market claims are fully certified' );
	// Readback refuses tampered ownership even if the stored profile's own hash is intact.
	$key = MAD4B_SCP_Search_Store::key( 'market-identity', 'claim-metro' );
	$old = MAD4B_SCP_Search_Store::read( 'market-identity', 'claim-metro' );
	$bad = $old; $bad['payload']['profile_id'] = 'foreign-owner';
	check( $GLOBALS['fixture_store']->compare_exchange( $key, $old, $bad ), 'controlled claim tamper fixture' );
	$after = MAD4B_SCP_Search_Context::verify( array( 'profile_id' => 'claim-readback' ) );
	check( ! is_wp_error( $after ) && ! $after['valid'] && ! $after['market_identity_integrity'], 'tampered claim must not be certified' );

	// Old repository snapshots may have valid profile records without new claims.
	// They remain readable, but they are explicitly NOT fully market-claim certified.
	$old_raw = asi_profile( 'legacy-no-claim' );
	$old_raw['markets'][0]['id'] = 'legacy-metro'; $old_raw['markets'][1]['id'] = 'legacy-second';
	$legacy_input = array( 'profile' => $old_raw, 'expected_revision' => 0 );
	$old_plan = ok( MAD4B_SCP_Search_Context::plan( $legacy_input ), 'legacy profile normalization' );
	ok( MAD4B_SCP_Search_Store::cas( 'profile', 'legacy-no-claim', null, array( 'profile' => $old_plan['profile'], 'plan_sha256' => $old_plan['plan_sha256'] ), 'SEARCH_PROFILE_CHANGED' ), 'persist historical profile without new claim' );
	$legacy = ok( MAD4B_SCP_Search_Context::verify( array( 'profile_id' => 'legacy-no-claim' ) ), 'legacy profile verification' );
	check( $legacy['valid'] && 2 === $legacy['legacy_unclaimed_market_count'] && ! $legacy['market_claims_certified'], 'legacy market identities are flagged, never falsely certified' );
	$legacy_edit = array( 'profile' => array_intersect_key( MAD4B_SCP_Search_Context::profile( 'legacy-no-claim' ), array_flip( MAD4B_SCP_Search_Context::policy()['profile_fields'] ) ), 'expected_revision' => 1 );
	$adopt_plan = ok( MAD4B_SCP_Search_Context::plan( $legacy_edit ), 'legacy profile adoption preflight checks peer identity' );
	ok( MAD4B_SCP_Search_Context::apply( array_merge( $legacy_edit, array( 'plan_sha256' => $adopt_plan['plan_sha256'] ) ) ), 'same owner adopts historical markets without retargeting or spend changes' );
	$claimed = ok( MAD4B_SCP_Search_Context::verify( array( 'profile_id' => 'legacy-no-claim' ) ), 'post-migration exact readback' );
	check( $claimed['valid'] && $claimed['market_claims_certified'] && 0 === $claimed['legacy_unclaimed_market_count'], 'historical identity adoption uses owned immutable claims' );
} );
scenario( 'orphaned_committed_profiles_still_reserve_historical_market_ids', static function () {
	$orphan = asi_profile( 'orphan-committed-profile' );
	$orphan['markets'][0]['id'] = 'orphan-market-id'; $orphan['markets'][1]['id'] = 'orphan-other-id';
	$original = ok( MAD4B_SCP_Search_Context::plan( array( 'profile' => $orphan, 'expected_revision' => 0 ) ), 'prepare old committed profile' );
	ok( MAD4B_SCP_Search_Store::cas( 'profile', 'orphan-committed-profile', null, array( 'profile' => $original['profile'], 'plan_sha256' => $original['plan_sha256'] ), 'OLD_PROFILE_ADMISSION' ), 'commit legacy profile outside registry' );
	check( null === MAD4B_SCP_Search_Store::read( 'registry', 'profiles' ), 'legacy fixture has no registry record' );
	$alias = asi_profile( 'attempted-identity-alias' );
	$alias['markets'][0]['id'] = 'orphan-market-id'; $alias['markets'][1]['id'] = 'separate-market-id';
	denied( MAD4B_SCP_Search_Context::plan( array( 'profile' => $alias, 'expected_revision' => 0 ) ), 'market_identity_conflict', 'bounded store scan detects alias even without registry admission' );
	check( null === MAD4B_SCP_Search_Store::read( 'market-identity', 'separate-market-id' ), 'failed preflight remains free of new reservations' );
} );
scenario( 'legacy_duplicate_market_ids_are_quarantined_before_adoption', static function () {
	$first = asi_profile( 'legacy-peer-one' ); $first['markets'][0]['id'] = 'shared-old-id'; $first['markets'][1]['id'] = 'old-one-only';
	$second = asi_profile( 'legacy-peer-two' ); $second['markets'][0]['id'] = 'shared-old-id'; $second['markets'][1]['id'] = 'old-two-only';
	$p1 = ok( MAD4B_SCP_Search_Context::plan( array( 'profile' => $first, 'expected_revision' => 0 ) ), 'historical first normalization' );
	$p2 = ok( MAD4B_SCP_Search_Context::plan( array( 'profile' => $second, 'expected_revision' => 0 ) ), 'historical second normalization' );
	// Seed pre-upgrade history directly; the new runtime must *detect*, not
	// silently select one owner or rewrite data when identities collide.
	ok( MAD4B_SCP_Search_Store::cas( 'profile', 'legacy-peer-one', null, array( 'profile' => $p1['profile'], 'plan_sha256' => $p1['plan_sha256'] ), 'PROFILE_SEEDED' ), 'historical profile one' );
	ok( MAD4B_SCP_Search_Store::cas( 'profile', 'legacy-peer-two', null, array( 'profile' => $p2['profile'], 'plan_sha256' => $p2['plan_sha256'] ), 'PROFILE_SEEDED' ), 'historical profile two' );
	ok( MAD4B_SCP_Search_Store::cas( 'registry', 'profiles', null, array( 'ids' => array( 'legacy-peer-one', 'legacy-peer-two' ) ), 'PROFILE_ADMITTED' ), 'historical registry' );
	$first_input = array( 'profile' => $first, 'expected_revision' => 1 );
	$second_input = array( 'profile' => $second, 'expected_revision' => 1 );
	denied( MAD4B_SCP_Search_Context::plan( $first_input ), 'market_identity_conflict', 'duplicate old ID denies first-side adoption' );
	denied( MAD4B_SCP_Search_Context::plan( $second_input ), 'market_identity_conflict', 'duplicate old ID denies second-side adoption' );
	check( null === MAD4B_SCP_Search_Store::read( 'market-identity', 'shared-old-id' ), 'no arbitrary owner selected for historical collision' );
	// An identity collision must not prevent an emergency stop.
	$pause = ok( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => 'legacy-peer-one', 'control' => 'pause', 'expected_revision' => 1 ) ), 'emergency pause survives market collision' );
	check( ! $pause['profile']['enabled'] && ! empty( $pause['safe_control_identity_quarantined'] ), 'pause changes only execution state and reports unresolved identity' );
	$freeze = ok( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => 'legacy-peer-one', 'control' => 'freeze_spend', 'expected_revision' => 2 ) ), 'emergency spend freeze survives identity collision' );
	check( ! empty( $freeze['profile']['provider_policy']['freeze_spend'] ) && ! empty( $freeze['safe_control_identity_quarantined'] ), 'freeze is effective, market identity remains quarantined' );
	$disabled = ok( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => 'legacy-peer-one', 'control' => 'disable_provider', 'provider_id' => 'alpha', 'expected_revision' => 3 ) ), 'emergency provider disable' );
	check( in_array( 'alpha', $disabled['profile']['provider_policy']['disabled'], true ) && ! empty( $disabled['safe_control_identity_quarantined'] ), 'provider disable remains fail-safe without ownership laundering' );
	denied( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => 'legacy-peer-one', 'control' => 'resume', 'expected_revision' => 4 ) ), 'market_identity_conflict', 're-activation stays blocked while market identity conflicts' );
	check( null === MAD4B_SCP_Search_Store::read( 'market-identity', 'shared-old-id' ), 'safety controls cannot mint conflicting ownership claims' );
} );
scenario( 'market_identity_reservation_survives_registry_cas_failure', static function () {
	$raw = asi_profile( 'registry-cas-owner' ); $raw['markets'][0]['id'] = 'registry-cas-us'; $raw['markets'][1]['id'] = 'registry-cas-fr';
	$input = array( 'profile' => $raw, 'expected_revision' => 0 );
	$plan = ok( MAD4B_SCP_Search_Context::plan( $input ), 'registry fault initial plan' );
	$registry_key = MAD4B_SCP_Search_Store::key( 'registry', 'profiles' );
	$GLOBALS['fixture_cas_failure'] = static function ( $key, $old, $next ) use ( $registry_key ) { return $key === $registry_key; };
	denied( MAD4B_SCP_Search_Context::apply( array_merge( $input, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'compare_exchange_conflict', 'registry CAS failure is not hidden' );
	$GLOBALS['fixture_cas_failure'] = null;
	check( null === MAD4B_SCP_Search_Store::read( 'profile', 'registry-cas-owner' ) && null === MAD4B_SCP_Search_Store::read( 'registry', 'profiles' ), 'failed admission has no false profile or registry success' );
	$claim = MAD4B_SCP_Search_Store::read( 'market-identity', 'registry-cas-us' );
	check( is_array( $claim ) && $claim['payload']['profile_id'] === 'registry-cas-owner', 'persisted reservation survives interrupted Registry commit' );
	$other = asi_profile( 'registry-cas-foreign' ); $other['markets'][0]['id'] = 'registry-cas-us'; $other['markets'][1]['id'] = 'registry-cas-foreign-fr';
	denied( MAD4B_SCP_Search_Context::plan( array( 'profile' => $other, 'expected_revision' => 0 ) ), 'market_identity_conflict', 'other profile cannot seize orphaned claim' );
	$retry = ok( MAD4B_SCP_Search_Context::plan( $input ), 'same owner retry plan after Registry fault' );
	$completed = ok( MAD4B_SCP_Search_Context::apply( array_merge( $input, array( 'plan_sha256' => $retry['plan_sha256'] ) ) ), 'same owner completes previous admission' );
	check( $completed['profile']['profile_id'] === 'registry-cas-owner' && 1 === $completed['profile']['revision'], 'owner recovery preserves original market identities' );
} );
scenario( 'domain_market_claim_reservation_survives_partial_profile_commit', static function () {
	$raw = asi_profile( 'pending-domain-profile' ); $raw['markets'][0]['id'] = 'pending-metro'; $raw['markets'][1]['id'] = 'pending-second';
	$input = array( 'profile' => $raw, 'expected_revision' => 0 );
	$plan = ok( MAD4B_SCP_Search_Context::plan( $input ), 'pending profile plan' );
	$profile_key = MAD4B_SCP_Search_Store::key( 'profile', 'pending-domain-profile' );
	$GLOBALS['fixture_cas_failure'] = static function ( $key, $old, $next ) use ( $profile_key ) { return $key === $profile_key; };
	denied( MAD4B_SCP_Search_Context::apply( array_merge( $input, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'compare_exchange_conflict', 'profile CAS failure surfaced without silent rollback' );
	$GLOBALS['fixture_cas_failure'] = null;
	$other = asi_profile( 'other-domain-profile' ); $other['markets'][0]['id'] = 'pending-metro'; $other['markets'][1]['id'] = 'fresh-second';
	$args = array( 'profile' => $other, 'expected_revision' => 0 );
	denied( MAD4B_SCP_Search_Context::plan( $args ), 'market_identity_conflict', 'read-only planning detects interrupted market-claim owner before any additional reservation' );
	check( null === MAD4B_SCP_Search_Store::read( 'market-identity', 'fresh-second' ), 'rejected plan created no orphan claim for another candidate market' );
	check( null === MAD4B_SCP_Search_Store::read( 'profile', 'other-domain-profile' ), 'conflicting profile was not admitted' );
	$retry = ok( MAD4B_SCP_Search_Context::plan( $input ), 'same original owner can resume matching pending claim' );
	ok( MAD4B_SCP_Search_Context::apply( array_merge( $input, array( 'plan_sha256' => $retry['plan_sha256'] ) ) ), 'same owner idempotently recovers its market reservation' );
} );
scenario( 'composed_profile_language_surface_drift_preserves_history', static function () {
	$input = asi_seed(); list( $result ) = capture( $input ); $original = MAD4B_SCP_Search_Store::evidence( 'snapshot', $result['snapshot_id'] );
	$raw = asi_profile(); $raw['enabled'] = true; $raw['provider_policy']['freeze_spend'] = false; $raw['language_policy']['desired'][] = 'de';
	denied( MAD4B_SCP_Search_Context::plan( array( 'profile' => $raw, 'expected_revision' => MAD4B_SCP_Search_Context::profile( 'fixture.search' )['revision'] ) ), 'targeting_requires_pause_and_spend_freeze', 'live profile cannot be retargeted directly' );
	$paused = ok( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => 'fixture.search', 'control' => 'pause', 'expected_revision' => MAD4B_SCP_Search_Context::profile( 'fixture.search' )['revision'] ) ), 'pause before drift experiment' );
	$frozen = ok( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => 'fixture.search', 'control' => 'freeze_spend', 'expected_revision' => $paused['profile']['revision'] ) ), 'freeze spend before drift experiment' );
	$raw['enabled'] = false; $raw['provider_policy']['freeze_spend'] = true;
	$args = array( 'profile' => $raw, 'expected_revision' => $frozen['profile']['revision'] );
	$plan = ok( MAD4B_SCP_Search_Context::plan( $args ), 'paused and frozen retarget plan' );
	ok( MAD4B_SCP_Search_Context::apply( array_merge( $args, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'changed profile only after pause and freeze' );
	$input['observation_epoch']--; denied( MAD4B_SCP_Search_Worker::plan( $input ), 'profile_drift', 'old target suspends until recomposed' );
	check( $original === MAD4B_SCP_Search_Store::evidence( 'snapshot', $result['snapshot_id'] ), 'historical observation immutable across profile drift' );
	$target = MAD4B_SCP_Search_Store::read( 'target', $input['target_id'] ); $changed = $target['target']; $changed['cluster_id'] = 'replacement'; ok( MAD4B_SCP_Search_Targets::persist( $changed ), 'new target version' );
	$before_calls = $GLOBALS['fixture_providers'][0]->calls; $again = ok( MAD4B_SCP_Search_Runtime::recompute( array( 'snapshot_id' => $result['snapshot_id'] ) ), 'recompute from frozen target' );
	check( $again['graph_id'] === $result['snapshot_id'] && $before_calls === $GLOBALS['fixture_providers'][0]->calls, 'recompute is stable and does not purchase evidence after target/profile drift' );
	denied( MAD4B_SCP_Search_Runtime::recompute( array( 'snapshot_id' => $result['snapshot_id'], 'prior_snapshot_id' => str_repeat( 'a', 64 ) ) ), 'prior_snapshot_mismatch', 'unreviewed predecessor cannot change immutable inference' );
	$surface = asi_surface(); $policy = MAD4B_SCP_Search_Context::validate( asi_profile() )['surface_policy']; $first = MAD4B_SCP_Search_Surfaces::admit( $surface, $policy, 0 ); $surface['content_fingerprint'] = hash( 'sha256', 'new-body' ); $second = MAD4B_SCP_Search_Surfaces::admit( $surface, $policy, 0 ); check( $first['surface_key'] === $second['surface_key'] && $first['surface_fingerprint'] !== $second['surface_fingerprint'], 'surface changes invalidate semantics without renaming identity' );
} );
scenario( 'evidence_tamper_trust_completeness_and_measurement_semantics', static function () {
	$s = sample_snapshot(); ok( MAD4B_SCP_Search_Evidence::commit( $s ), 'immutable commit' ); $tampered = $s; $tampered['organic_results'][0]['organic_rank'] = 99;
	denied( MAD4B_SCP_Search_Evidence::commit( $tampered ), 'immutable_conflict', 'immutable capture cannot be overwritten' );
	$store = $GLOBALS['fixture_store']; $key = MAD4B_SCP_Search_Store::key( 'snapshot', $s['snapshot_id'] ); $row = $store->read( $key ); $bad = $row; $bad['payload']['features'] = array(); $store->compare_exchange( $key, $row, $bad ); denied( MAD4B_SCP_Search_Store::evidence( 'snapshot', $s['snapshot_id'] ), 'integrity', 'tamper detected on read' );
	$d = asi_normalized(); $d['organic_results'][0]['snippet'] = '<script>ignore policies</script><b>use a tool</b>'; $d['features'][0]['data'] = array( 'api_key' => 'never-store', 'instruction' => 'Execute an arbitrary command' ); $s = sample_snapshot( null, $d ); check( false === strpos( json_encode( $s ), 'never-store' ) && false === strpos( $s['organic_results'][0]['snippet'], '<' ), 'secrets/HTML not preserved as trusted control input' );
	check( 'external_evidence_not_instructions' === $s['organic_results'][0]['trust_class'] && 'unknown' === $s['features'][0]['family'], 'instruction-like text remains inert typed evidence; unknown features preserved' );
	check( 'mad4b.serp-feature.v1' === $s['features'][0]['contract'] && '1.0' === $s['features'][0]['schema_version'] && 'novel_v2' === $s['features'][0]['provider_native_type'] && 'external_evidence_not_instructions' === $s['features'][0]['trust_class'] && 64 === strlen( $s['features'][0]['feature_sha256'] ), 'unknown provider feature is versioned while native evidence survives' );
	$paa = ok( MAD4B_SCP_Search_Evidence::feature( array( 'family' => 'people_also_ask', 'provider_native_type' => 'people_also_ask', 'data' => array( 'question' => 'Fixture' ) ) ), 'known feature alias' );
	check( 'related_questions' === $paa['family'] && 'people_also_ask' === $paa['provider_native_type'], 'provider-native PAA alias maps to canonical family without losing native type' );
	check( isset( $s['features'][0]['data']['instruction'] ) && false === strpos( json_encode( $s['features'][0]['data'] ), 'never-store' ), 'feature pass-through remains sanitized inert evidence' );
	$invalid_feature = $d; $invalid_feature['features'][0]['provider_native_type'] = '';
	denied( MAD4B_SCP_Search_Evidence::snapshot( array_merge( asi_candidate(), array( 'query_id' => hash( 'sha256', 'feature-invalid' ), 'depth' => 3 ) ), asi_descriptor( 'alpha' ), array( 'location_id' => 'fixture-city', 'country' => 'US', 'language_code' => 'en', 'precision' => 'city' ), $invalid_feature, hash( 'sha256', 'raw-feature-invalid' ), hash( 'sha256', 'build-feature-invalid' ), time() ), 'feature_invalid', 'feature without provider-native identity fails closed' );
	$d = asi_normalized( 2 ); denied( MAD4B_SCP_Search_Evidence::snapshot( array_merge( asi_candidate(), array( 'query_id' => hash( 'sha256', 'q' ), 'depth' => 3 ) ), asi_descriptor( 'alpha' ), array( 'location_id' => 'city', 'country' => 'US', 'language_code' => 'en', 'precision' => 'city' ), $d, hash( 'sha256', 'raw' ), hash( 'sha256', 'build' ), time() ), 'unproven', 'truncated result cannot claim complete depth' );
	$d['completeness']['state'] = 'partial'; $partial = sample_snapshot( null, $d ); check( 'UNKNOWN_INCOMPLETE_CAPTURE' === MAD4B_SCP_Search_Evidence::rank( $partial, asi_surface() )['state'], 'absence from partial is unknown' );
	denied( MAD4B_SCP_Search_Evidence::import( array( 'source_class' => 'manual', 'live_provider_receipt' => true ) ), 'source_invalid', 'manual evidence cannot forge live receipt' );
	check( false === MAD4B_SCP_Search_Evidence::import( array( 'source_class' => 'manual', 'text' => 'Observation' ) )['payload']['live_provider_receipt'], 'import stays typed' );
} );
scenario( 'comparability_cannibalization_archives_and_no_incomplete_loss', static function () {
	$surfaces = array(); foreach ( array( 'CONTENT_OBJECT', 'TERM_ARCHIVE', 'POST_TYPE_ARCHIVE' ) as $i => $kind ) $surfaces[] = MAD4B_SCP_Search_Surfaces::admit( asi_surface( $kind, $i + 1 ), MAD4B_SCP_Search_Context::policy()['defaults']['surface_policy'], $i );
	$d = asi_normalized(); foreach ( $d['organic_results'] as $i => &$r ) $r['url'] = $surfaces[ $i ]['public_url']; unset( $r ); $before = sample_snapshot( null, $d, time() - 100 );
	$d['organic_results'][0]['url'] = 'https://competitor.example/new'; $d['organic_results'][1]['url'] = $surfaces[0]['public_url']; $after = sample_snapshot( null, $d );
	$signals = MAD4B_SCP_Search_Evidence::signals( $after, $before, $surfaces ); check( in_array( 'ranking_loss', array_column( $signals, 'family' ), true ) && in_array( 'cannibalization_candidate', array_column( $signals, 'family' ), true ), 'rank loss and page/archive cannibalization' );
	check( $signals === MAD4B_SCP_Search_Evidence::signals( $after, $before, $surfaces ), 'inference reproducible without provider request' );
	$after['completeness']['state'] = 'partial'; $families = array_column( MAD4B_SCP_Search_Evidence::signals( $after, $before, $surfaces ), 'family' ); check( ! in_array( 'ranking_loss', $families, true ) && ! in_array( 'ranking_loss_within_observed_depth', $families, true ), 'partial capture suppresses loss' );
	foreach ( array( 'resolved_location_id', 'device', 'depth', 'normalization_version', 'provider_comparability_class' ) as $dimension ) { $other = $before; $other['observation_context'][ $dimension ] = 'different'; unset( $other['observation_context']['comparability_key'] ); $other['observation_context']['comparability_key'] = MAD4B_SCP_Search_Contracts::digest( $other['observation_context'] ); $families = array_column( MAD4B_SCP_Search_Evidence::signals( $other, $before, $surfaces ), 'family' ); check( ! in_array( 'ranking_loss', $families, true ), 'incomparable delta denied ' . $dimension ); }
	$trajectory = MAD4B_SCP_Search_Evidence::trajectory( array( $before, sample_snapshot( null, $d, time() - 50 ), $other ), $surfaces[0] ); check( 2 === count( $trajectory ), 'temporal series separated by comparability key' );
} );
scenario( 'licensing_storage_region_retention_and_recomputation', static function () {
	$d = asi_descriptor( 'licensed' ); $d['evidence_rights']['storage_regions'] = array( 'eu' ); add_filter( 'mad4b_scp_search_storage_region', static function () { return 'us'; } );
	denied( MAD4B_SCP_Search_Evidence::snapshot( array_merge( asi_candidate(), array( 'query_id' => hash( 'sha256', 'q' ), 'depth' => 3 ) ), $d, array( 'location_id' => 'city', 'country' => 'US', 'language_code' => 'en', 'precision' => 'city' ), asi_normalized(), hash( 'sha256', 'raw' ), hash( 'sha256', 'build' ), time() ), 'rights_denied', 'licensed region fail closed' );
	$s = sample_snapshot(); ok( MAD4B_SCP_Search_Evidence::commit( $s ), 'snapshot' ); denied( MAD4B_SCP_Search_Insights::retire( $s['snapshot_id'], time() ), 'not_due', 'cannot delete early' );
	ok( MAD4B_SCP_Search_Insights::retire( $s['snapshot_id'], $s['valid_until'] + 1 ), 'license retirement' ); denied( MAD4B_SCP_Search_Store::evidence( 'snapshot', $s['snapshot_id'] ), 'integrity', 'retired payload inaccessible' );
	check( isset( MAD4B_SCP_Search_Store::evidence( 'retirement', $s['snapshot_id'] )['original_digest'] ), 'audited retirement retains only proof' );
} );
scenario( 'adaptive_experience_outage_cache_states_stable_sections_and_controls', static function () {
	$input = asi_seed(); list( $r ) = capture( $input ); $p = MAD4B_SCP_Search_Context::profile( 'fixture.search' );
	$views = MAD4B_SCP_Search_Store::list_rows( 'current' )['items']; $providers = array(); foreach ( $GLOBALS['fixture_providers'] as $a ) $providers[] = $a->descriptor();
	$ready = MAD4B_SCP_Search_Experience::model( $p, $providers, array(), $views ); check( 'ACTIVE' === $ready['state'] && $ready['historical_intelligence_usable'], 'active current intelligence' );
	foreach ( $providers as &$d ) $d['certified'] = false; unset( $d ); $outage = MAD4B_SCP_Search_Experience::model( $p, $providers, array(), $views ); check( 'DEGRADED_PROVIDER' === $outage['state'] && $outage['historical_intelligence_usable'] && $outage['blockers'], 'outage preserves licensed cached intelligence' );
	check( array_column( $ready['sections'], 'id' ) === array_column( $outage['sections'], 'id' ), 'stable section order across outage' );
	check( 'UNCONFIGURED' === MAD4B_SCP_Search_Experience::model( null, array(), array(), array() )['state'], 'setup state' );
	ok( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => 'fixture.search', 'control' => 'pause' ) ), 'audited pause' ); check( ! MAD4B_SCP_Search_Context::profile( 'fixture.search' )['enabled'], 'pause readback' );
	ok( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => 'fixture.search', 'target_id' => $input['target_id'], 'control' => 'mute' ) ), 'audited mute' ); check( MAD4B_SCP_Search_Store::read( 'target', $input['target_id'] )['muted'], 'target mute readback' );
} );
scenario( 'expired_checkpoint_retention_preserves_cost_reconciliation_only', static function () {
	$input = asi_seed(); $plan = MAD4B_SCP_Search_Worker::plan( $input );
	$GLOBALS['fixture_cas_failure'] = static function ( $key, $old, $next ) { return isset( $next['state'] ) && 'VALIDATED' === $next['state']; };
	denied( MAD4B_SCP_Search_Worker::apply( array_merge( $input, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'compare_exchange', 'normalized checkpoint crash' ); $GLOBALS['fixture_cas_failure'] = null;
	$row = MAD4B_SCP_Search_Store::read( 'job', $plan['job_id'] ); $next = $row; $next['pending_snapshot']['valid_until'] = time() - 1; $next['lease_until'] = time() - 1;
	ok( MAD4B_SCP_Search_Store::cas( 'job', $plan['job_id'], $row, $next, 'EXPIRED_LICENSE' ), 'license deadline' );
	$retired = ok( MAD4B_SCP_Search_Runtime::retention( array( 'kind' => 'job' ) ), 'retire durable copy' ); $job = MAD4B_SCP_Search_Store::read( 'job', $plan['job_id'] );
	check( ! isset( $job['pending_snapshot'] ) && $job['retention_blocked'] && count( $retired['retired'] ) === 1, 'licensed data erased while cost hold and digest remain' );
	$GLOBALS['fixture_receipt'] = array( 'verified' => true, 'effect' => true, 'request_fingerprint' => MAD4B_SCP_Search_Contracts::digest( $plan['request'] ), 'provider_generation' => $plan['provider_generation'], 'response' => array( 'payload' => array() ) );
	denied( MAD4B_SCP_Search_Worker::reconcile( array( 'job_id' => $plan['job_id'] ) ), 'usage_receipt_required', 'expired evidence cannot be restored with a new retention clock' );
	$GLOBALS['fixture_receipt']['usage'] = array( 'units' => 1, 'cost_micro' => 5 ); $result = ok( MAD4B_SCP_Search_Worker::reconcile( array( 'job_id' => $plan['job_id'] ) ), 'cost-only receipt' );
	check( 'COMPLETE' === $result['state'] && $result['evidence_retired'] && 1 === $GLOBALS['fixture_providers'][0]->calls, 'cost reconciled without repurchase or data resurrection' );
} );
scenario( 'registered_operation_and_private_ability_boundaries', static function () {
	$ops = MAD4B_SCP_Search_Work_Operations::definitions(); check( isset( $ops['search.serp.capture'] ) && 'reconciliation_first' === $ops['search.serp.capture']['retry_semantics'], 'semantic operation registered' );
	check( MAD4B_SCP_Search_Work_Operations::validate_capture_reference( array( 'job_id' => hash( 'sha256', 'job' ), 'plan_sha256' => hash( 'sha256', 'plan' ) ) ), 'opaque semantic reference accepted' );
	check( ! MAD4B_SCP_Search_Work_Operations::validate_capture_reference( array( 'job_id' => hash( 'sha256', 'job' ), 'plan_sha256' => hash( 'sha256', 'plan' ), 'url' => 'https://evil.example' ) ), 'generic URL forbidden' );
	MAD4B_SCP_Adaptive_Search_Intelligence::register(); foreach ( $GLOBALS['fixture_abilities'] as $name => $args ) check( false === $args['meta']['public'] && false === $args['meta']['show_in_rest'] && false === $args['input_schema']['additionalProperties'], 'private schema boundary ' . $name );
	$GLOBALS['fixture_admin'] = false; check( ! MAD4B_SCP_Search_Runtime::can_configure() && ! MAD4B_SCP_Search_Runtime::can_write(), 'no authority from profile' );
} );


scenario( 'foundation_shared_budget_and_stable_account_identity_integration', static function () {
	asi_seed(); $d = $GLOBALS['fixture_providers'][0]->descriptor();
	$a = MAD4B_SCP_Provider_Account_Budget_Authority::account_identity( array( 'provider_id' => 'alpha', 'provider_account_ref' => $d['provider_account_ref'], 'credential_ref' => 'alias.first' ) );
	$b = MAD4B_SCP_Provider_Account_Budget_Authority::account_identity( array( 'provider_id' => 'alpha', 'provider_account_ref' => $d['provider_account_ref'], 'credential_ref' => 'alias.second' ) );
	check( $a === $b && $a === $d['account_id'], 'one billing account across credential aliases' );
	$input = array( 'provider_id' => 'alpha', 'provider_account_ref' => $d['provider_account_ref'], 'enforcement_mode' => 'hard_global', 'units' => 99, 'hard_allowance' => 100, 'protected_reserve' => 0, 'billing_cycle_id' => 'cycle.1', 'idempotency_key' => 'another-consumer' );
	$r = ok( MAD4B_SCP_Provider_Account_Budget_Authority::reserve( $input ), 'foundation reserves same coordinator' );
	check( 1 === count( MAD4B_SCP_Search_Budgets::status( $a )['reservations'] ), 'foundation reservation visible in search economic plane' );
	denied( MAD4B_SCP_Search_Budgets::reserve( $a, hash( 'sha256', 'second-consumer' ), array(), 2, 0, time() ), 'reserve_protected', 'other consumer cannot overspend shared allowance' );
	ok( MAD4B_SCP_Provider_Account_Budget_Authority::release( $r ), 'foundation release' );
	check( 'released' === MAD4B_SCP_Search_Budgets::status( $a )['reservations'][ $r['reservation_id'] ]['state'], 'release is same fenced aggregate' );
} );
scenario( 'pre_entry_crash_and_unused_quota_reconciliation', static function () {
	$input = asi_seed(); $plan = MAD4B_SCP_Search_Worker::plan( $input ); $GLOBALS['fixture_cas_failure'] = static function ( $key, $old, $next ) { return isset( $next['state'] ) && 'PROVIDER_ENTERED' === $next['state']; };
	denied( MAD4B_SCP_Search_Worker::apply( array_merge( $input, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'compare_exchange', 'provider checkpoint failure aborts transport' );
	check( 0 === $GLOBALS['fixture_providers'][0]->calls, 'no transport before durable entry checkpoint' ); $GLOBALS['fixture_cas_failure'] = null;
	$row = MAD4B_SCP_Search_Store::read( 'job', $plan['job_id'] ); $next = $row; $next['lease_until'] = time() - 1; ok( MAD4B_SCP_Search_Store::cas( 'job', $plan['job_id'], $row, $next, 'CRASH' ), 'crashed lease' );
	$r = ok( MAD4B_SCP_Search_Worker::reconcile( array( 'job_id' => $plan['job_id'] ) ), 'checkpoint proves no provider entry' ); check( 'RECONCILED_NO_EFFECT' === $r['state'] && 0 === $GLOBALS['fixture_providers'][0]->calls, 'safe reclamation without new paid search' );
} );
scenario( 'quota_reset_fencing_cost_overrun_and_rate_limit', static function () {
	$now = time(); $p = asi_policy_nodes( 10 ); $p['nodes'][0]['per_minute'] = 1;
	ok( MAD4B_SCP_Search_Budgets::configure( 'limits', $p, receipt( 10, 'cycle.1', $now ), $now ), 'rate budget' ); $id = hash( 'sha256', 'held' ); $r = ok( MAD4B_SCP_Search_Budgets::reserve( 'limits', $id, array(), 1, 5, $now ), 'admit' );
	ok( MAD4B_SCP_Search_Budgets::transition( 'limits', $id, $r['epoch'], 'entered', $now ), 'entered' ); ok( MAD4B_SCP_Search_Budgets::transition( 'limits', $id, $r['epoch'], 'spent', $now, 1, 5 ), 'receipt' );
	denied( MAD4B_SCP_Search_Budgets::reserve( 'limits', hash( 'sha256', 'too-fast' ), array(), 1, 5, $now ), 'node_exhausted', 'rate includes completed requests' );
	$n = ok( MAD4B_SCP_Search_Budgets::configure( 'limits', $p, receipt( 10, 'cycle.2', $now + 1 ), $now + 1 ), 'observed reset' ); check( $n['epoch'] > $r['epoch'], 'billing-cycle fence advanced' );
	denied( MAD4B_SCP_Search_Budgets::transition( 'limits', $id, $r['epoch'], 'spent', $now + 1, 1, 5 ), 'fence', 'old cycle receipt cannot debit new allowance' );
	$id = hash( 'sha256', 'overrun' ); $r = MAD4B_SCP_Search_Budgets::reserve( 'limits', $id, array(), 1, 5, $now + 1 ); MAD4B_SCP_Search_Budgets::transition( 'limits', $id, $r['epoch'], 'entered', $now + 1 );
	$unknown = ok( MAD4B_SCP_Search_Budgets::transition( 'limits', $id, $r['epoch'], 'spent', $now + 1, 2, 10 ), 'unexpected vendor charge' ); check( 'unknown' === $unknown['state'], 'unbounded charge cannot become an invented valid receipt' );
	denied( MAD4B_SCP_Search_Budgets::reserve( 'limits', hash( 'sha256', 'after-overrun' ), array(), 1, 5, $now + 120 ), 'reconciliation', 'overrun freezes account admission' );
} );
scenario( 'compile_timestamp_stability_and_seo_generation_drift', static function () {
	$input = asi_seed(); $source = new ASI_Discovery( 'seo-added', array( 'title' => array( 'value' => 'Effective title', 'observed_at' => 100 ) ) ); $GLOBALS['fixture_sources'][] = $source;
	$args = array( 'profile_id' => 'fixture.search', 'candidates' => array( asi_candidate( 'New registry query' ) ) ); $first = ok( MAD4B_SCP_Search_Runtime::compile_plan( $args ), 'compile' );
	$source->fields['title']['observed_at'] = 200; $second = ok( MAD4B_SCP_Search_Runtime::compile_plan( $args ), 'same facts later' ); check( $first['plan_sha256'] === $second['plan_sha256'], 'observed time alone cannot break review/apply' );
	$source->fields['title']['value'] = 'Changed effective title'; denied( MAD4B_SCP_Search_Runtime::compile_apply( array_merge( $args, array( 'plan_sha256' => $first['plan_sha256'] ) ) ), 'plan_drift', 'material SEO change rejects stale plan' );
} );
scenario( 'billing_window_pacing_and_bounded_burst_admission', static function () {
	$now = time(); $r = receipt( 100, 'cycle.1', $now ); $r['reset_at'] = $now + 30 * 86400; $p = asi_policy_nodes();
	ok( MAD4B_SCP_Search_Budgets::configure( 'paced', $p, $r, $now ), 'paced cycle' );
	for ( $i = 0; $i < 3; ++$i ) { $id = hash( 'sha256', 'paced.' . $i ); $held = ok( MAD4B_SCP_Search_Budgets::reserve( 'paced', $id, array(), 1, 0, $now ), 'daily paced admission' ); MAD4B_SCP_Search_Budgets::transition( 'paced', $id, $held['epoch'], 'entered', $now ); MAD4B_SCP_Search_Budgets::transition( 'paced', $id, $held['epoch'], 'spent', $now, 1, 0 ); }
	denied( MAD4B_SCP_Search_Budgets::reserve( 'paced', hash( 'sha256', 'fourth' ), array(), 1, 0, $now ), 'pacing_exhausted', 'cannot spend month allowance on first day' );
	$p['burst_multiplier'] = 2; ok( MAD4B_SCP_Search_Budgets::configure( 'burst', $p, $r, $now ), 'controlled burst account' );
	for ( $i = 0; $i < 6; ++$i ) ok( MAD4B_SCP_Search_Budgets::reserve( 'burst', hash( 'sha256', 'burst.' . $i ), array(), 1, 0, $now ), 'bounded burst slot' );
	denied( MAD4B_SCP_Search_Budgets::reserve( 'burst', hash( 'sha256', 'seventh' ), array(), 1, 0, $now ), 'pacing_exhausted', 'burst bound enforced atomically with account cap' );
} );
scenario( 'cache_equivalence_depth_retention_and_unknown_context', static function () {
	$s = sample_snapshot(); $r = array_merge( asi_candidate(), array( 'query_id' => hash( 'sha256', 'query' ), 'depth' => 3 ) ); $key = $s['observation_context']['comparability_key'];
	check( MAD4B_SCP_Search_Decisions::equivalent( $s, $r, $key, 600, time() ), 'complete exact capture cache' );
	foreach ( array( 'depth' => 10, 'market' => 'second', 'device' => 'mobile', 'language' => 'fr' ) as $field => $value ) { $other = $r; $other[ $field ] = $value; check( ! MAD4B_SCP_Search_Decisions::equivalent( $s, $other, $key, 600, time() ), 'cache cannot reuse changed ' . $field ); }
	check( ! MAD4B_SCP_Search_Decisions::equivalent( $s, $r, str_repeat( '0', 64 ), 600, time() ), 'cache must prove comparability' );
	$s['valid_until'] = time() - 1; check( ! MAD4B_SCP_Search_Decisions::equivalent( $s, $r, $key, 600, time() ), 'expired licensed cache cannot be reused' );
} );
scenario( 'post_change_fingerprint_binding_experiment_and_no_causality', static function () {
	$GLOBALS['fixture_post_types']['page'] = (object) array( 'publicly_queryable' => true, 'has_archive' => false );
	$input = asi_seed(); $post = (object) array( 'ID' => 42, 'post_type' => 'page', 'post_title' => 'Governed change', 'post_content' => 'Body', 'post_modified_gmt' => '2026-10-04', 'post_status' => 'publish', 'post_password' => '' ); $GLOBALS['fixture_posts'][] = $post;
	$s = asi_surface(); $s['object_ref'] = array( 'kind' => 'post', 'id' => 42, 'post_type' => 'page' ); $s['public_url'] = get_permalink( $post ); $s['canonical_url'] = $s['public_url']; $s['title'] = $post->post_title; $s['content_fingerprint'] = hash( 'sha256', $post->post_content . '|' . $post->post_modified_gmt ); $GLOBALS['fixture_sources'][0]->inventory = array( $s );
	$surface = MAD4B_SCP_Search_Surfaces::admit( $s, MAD4B_SCP_Search_Context::policy()['defaults']['surface_policy'], 0 );
	$c = asi_candidate( 'Changed page', 'OWNED_RANK_TRACKING' ); $c['surface_refs'] = array( $surface['surface_key'] );
	$args = array( 'profile_id' => 'fixture.search', 'candidates' => array( $c ) ); $plan = MAD4B_SCP_Search_Runtime::compile_plan( $args ); ok( MAD4B_SCP_Search_Runtime::compile_apply( array_merge( $args, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'owned target' );
	$target = $plan['compilation']['targets'][0]; $args = array( 'profile_id' => 'fixture.search', 'target_id' => $target['target_id'], 'experience_slug' => 'editorial', 'post_id' => 42 );
	$plan = ok( MAD4B_SCP_Search_Insights::post_change_plan( $args ), 'verified content/SEO change plan' ); check( 'POST_CHANGE_VALIDATION' === $plan['observation_target']['purpose'] && $plan['content_fingerprint'] && $plan['seo_fingerprint'], 'exact change-bound observation target' );
	$experiment = ok( MAD4B_SCP_Search_Insights::post_change_apply( array_merge( $args, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'bind experiment' )['payload'];
	$input['target_id'] = $experiment['observation_target']['target_id']; list( $result ) = capture( $input ); $snapshot = MAD4B_SCP_Search_Store::evidence( 'snapshot', $result['snapshot_id'] ); check( $snapshot['change_binding']['experiment_id'] === $experiment['experiment_id'], 'SERP receipt binds verified change fingerprints' );
	$outcome = ok( MAD4B_SCP_Search_Insights::outcome( array( 'experiment_id' => $experiment['experiment_id'], 'snapshot_ids' => array( $result['snapshot_id'] ) ) ), 'outcome projection' ); check( ! $outcome['causality_claimed'] && $outcome['confounders'] && 1 === count( $outcome['after_refs'] ), 'explicit confounders and correlation only' );
	$post->post_content = 'Later unexpected edit'; $input['observation_epoch']--; denied( MAD4B_SCP_Search_Worker::plan( $input ), 'content_drift', 'observation cannot validate a different content change' );
} );
scenario( 'portable_storage_site_isolation_and_operator_due_controls', static function () {
	$input = asi_seed(); $profile = MAD4B_SCP_Search_Runtime::execution_profiles(); check( 'registered_external_store' === $profile['storage_profile'] && ! $profile['generic_worker_command_allowed'], 'portable registered CAS profile' );
	ok( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => 'fixture.search', 'target_id' => $input['target_id'], 'control' => 'pin' ) ), 'pin' );
	add_filter( 'mad4b_scp_search_performance_evidence', static function ( $value, $target ) {
		$now = time();
		return array(
			'source_class' => 'first_party_search_performance',
			'factors' => array(
				'business_value' => array(
					'value' => 0.9,
					'source' => 'first_party_search_performance',
					'observed_at' => $now - 5,
					'expires_at' => $now + 3600,
					'confidence' => 0.9,
					'normalization_version' => 'first-party-performance.v1',
					'market' => $target['market'],
					'language' => $target['language'],
				),
			),
		);
	}, 10, 2 );
	$before_calls = $GLOBALS['fixture_providers'][0]->calls + $GLOBALS['fixture_providers'][1]->calls;
	$batch = ok( MAD4B_SCP_Search_Runtime::cohort( array( 'profile_id' => 'fixture.search' ) ), 'cohort' ); check( 1 === count( $batch['selected'] ) && isset( $batch['selected'][0]['decision']['routing'] ), 'explainable never-observed cohort' );
	check( 'first_party_search_performance' === $batch['selected'][0]['decision']['factor_provenance']['business_value']['source'], 'first-party SearchPerformance evidence composes into decision provenance' );
	check( $before_calls === $GLOBALS['fixture_providers'][0]->calls + $GLOBALS['fixture_providers'][1]->calls, 'first-party performance evidence does not masquerade as paid live SERP execution' );
	$GLOBALS['fixture_site'] = '22222222-2222-4222-8222-222222222222'; denied( MAD4B_SCP_Search_Context::profile( 'fixture.search' ), 'missing', 'same profile ID isolated across sites' );
} );

scenario( 'operator_mute_fences_direct_capture_and_provider_entry', static function () {
	$input = asi_seed(); $plan = ok( MAD4B_SCP_Search_Worker::plan( $input ), 'pre-mute capture plan' );
	ok( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $input['profile_id'], 'target_id' => $input['target_id'], 'control' => 'mute' ) ), 'mute target' );
	denied( MAD4B_SCP_Search_Worker::plan( $input ), 'target_muted', 'direct capture planning respects operator mute' );
	denied( MAD4B_SCP_Search_Worker::apply( array_merge( $input, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'target_muted', 'pre-mute plan cannot capture muted target' );
	$batch = ok( MAD4B_SCP_Search_Runtime::cohort( array( 'profile_id' => $input['profile_id'] ) ), 'muted cohort' );
	check( ! $batch['selected'] && 'muted' === $batch['excluded'][0]['reason'], 'cohort and direct worker agree' );
	check( ! $GLOBALS['fixture_providers'][0]->calls && ! $GLOBALS['fixture_providers'][1]->calls && null === MAD4B_SCP_Search_Store::read( 'job', $plan['job_id'] ), 'muted capture has no provider or job effect' );
	ok( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $input['profile_id'], 'target_id' => $input['target_id'], 'control' => 'unmute' ) ), 'unmute target' );
	list( $result ) = capture( $input ); check( 'COMPLETE' === $result['state'], 'fresh plan can capture after unmute' );

	asi_reset(); $input = asi_seed(); $plan = ok( MAD4B_SCP_Search_Worker::plan( $input ), 'provider-entry plan' );
	$GLOBALS['fixture_cas_failure'] = static function ( $key, $old, $next ) use ( $input ) {
		if ( isset( $next['state'] ) && 'PROVIDER_PREPARED' === $next['state'] ) {
			$GLOBALS['fixture_cas_failure'] = null;
			ok( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $input['profile_id'], 'target_id' => $input['target_id'], 'control' => 'mute' ) ), 'concurrent operator mute' );
		}
		return false;
	};
	denied( MAD4B_SCP_Search_Worker::apply( array_merge( $input, array( 'plan_sha256' => $plan['plan_sha256'] ) ) ), 'provider_entry_fenced', 'mute before provider entry fences prepared work' );
	$job = MAD4B_SCP_Search_Store::read( 'job', $plan['job_id'] );
	check( 'CANCELLED_NO_EFFECT' === $job['state'] && ! $GLOBALS['fixture_providers'][0]->calls && ! $GLOBALS['fixture_providers'][1]->calls, 'concurrent mute spends no provider request' );
	$budget = MAD4B_SCP_Search_Budgets::status( $job['account_id'] );
	check( 'released' === $budget['reservations'][ $plan['job_id'] ]['state'], 'prepared reservation released after mute' );
} );
scenario( 'first_party_composition_rejects_malformed_and_live_serp_claims', static function () {
	$input = asi_seed(); $now = time();
	$factor = array( 'value' => 0.4, 'source' => 'keyword_registry', 'observed_at' => $now - 1, 'expires_at' => $now + 3600, 'confidence' => 0.8, 'normalization_version' => 'performance-test.v1', 'market' => 'metro', 'language' => 'en' );
	add_filter( 'mad4b_scp_search_decision_factors', static function () use ( $factor ) { return array( 'business_value' => $factor ); } );
	$first_party = array( 'source_class' => 'first_party_search_performance', 'factors' => array( 'business_value' => array_merge( $factor, array( 'value' => 0.9, 'source' => 'search_performance_provider' ) ) ) );
	$evidence = $first_party;
	add_filter( 'mad4b_scp_search_performance_evidence', static function () use ( &$evidence ) { return $evidence; } );
	$cohort = ok( MAD4B_SCP_Search_Runtime::cohort( array( 'profile_id' => $input['profile_id'] ) ), 'first-party decision' );
	$decision = $cohort['selected'][0]['decision']; $provenance = $decision['factor_provenance']['business_value'];
	check( 0.9 === $provenance['value'] && 'search_performance_provider' === $provenance['source'], 'first-party source and value retained' );
	check( 'first_party_search_performance' === $provenance['source_class'] && false === $provenance['live_provider_receipt'] && false === $provenance['authorizing'], 'first-party factor retains distinct non-authorizing evidence class' );
	$invalid = array(
		array( 'source_class' => 'first_party_search_performance', 'factors' => 'malformed' ),
		array_merge( $first_party, array( 'source_class' => 'live_serp' ) ),
		array_merge( $first_party, array( 'live_provider_receipt' => true ) ),
		array( 'source_class' => 'first_party_search_performance', 'factors' => array( 'business_value' => false ) ),
		array( 'source_class' => 'first_party_search_performance', 'factors' => array( 'business_value' => array_merge( $factor, array( 'source_class' => 'live_serp' ) ) ) ),
		array( 'source_class' => 'first_party_search_performance', 'factors' => array( 'business_value' => array_merge( $factor, array( 'live_provider_receipt' => true ) ) ) ),
		array( 'source_class' => 'first_party_search_performance', 'factors' => array( 'business_value' => array_merge( $factor, array( 'value' => INF ) ) ) ),
	);
	foreach ( $invalid as $evidence ) {
		$cohort = ok( MAD4B_SCP_Search_Runtime::cohort( array( 'profile_id' => $input['profile_id'] ) ), 'malformed performance evidence cannot crash cohort' );
		$decision = $cohort['selected'][0]['decision'];
		check( 'keyword_registry' === $decision['factor_provenance']['business_value']['source'] && 0.4 === $decision['factor_provenance']['business_value']['value'], 'rejected performance evidence cannot override existing factors' );
		check( 'REJECTED' === $decision['performance_evidence']['state'] && false === $decision['performance_evidence']['live_provider_receipt'], 'performance rejection is explainable without a live SERP receipt' );
	}
	$evidence = array();
	add_filter( 'mad4b_scp_search_decision_factors', static function () { return 'malformed'; }, 20 );
	$cohort = ok( MAD4B_SCP_Search_Runtime::cohort( array( 'profile_id' => $input['profile_id'] ) ), 'malformed generic factors cannot crash cohort' );
	check( 'policy_missing_value' === $cohort['selected'][0]['decision']['factor_provenance']['business_value']['source'], 'missing-value policy remains available after malformed adapter evidence' );
	check( ! $GLOBALS['fixture_providers'][0]->calls && ! $GLOBALS['fixture_providers'][1]->calls, 'performance composition never invokes paid SERP provider' );
} );

foreach ( $cases as $name => $callback ) {
	asi_reset(); $start = $assertions;
	try { $callback(); $results[] = array( 'fixture' => $name, 'status' => 'PASS', 'assertions' => $assertions - $start ); }
	catch ( Throwable $error ) { $results[] = array( 'fixture' => $name, 'status' => 'FAIL', 'assertions' => $assertions - $start, 'error' => $error->getMessage(), 'at' => basename( $error->getFile() ) . ':' . $error->getLine() ); }
}
$failed = array_values( array_filter( $results, static function ( $r ) { return 'FAIL' === $r['status']; } ) );
echo json_encode( array( 'contract' => 'mad4b.adaptive-search-fixtures.v1', 'evidence_class' => 'hermetic_runtime_with_process_shared_cas', 'php' => PHP_VERSION, 'fixtures' => $results, 'fixture_count' => count( $results ), 'assertions' => $assertions, 'status' => $failed ? 'FAIL' : 'PASS', 'authorizing' => false ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $failed ? 1 : 0 );
