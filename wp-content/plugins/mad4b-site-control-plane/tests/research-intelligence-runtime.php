<?php
$root = dirname( __DIR__ );
require_once $root . '/includes/class-mad4b-scp-schema.php';

if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) {
	final class MAD4B_SCP_Site_Profile {
		public static function site_uuid() { return '11111111-2222-4333-8444-555555555555'; }
	}
}
if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) {
	final class MAD4B_SCP_Audit {
		public static function record( $ability, $summary, $status, $mutation = false ) { return array( 'recorded' => true ); }
		public static function transaction_committed() {}
		public static function transaction_rolled_back() {}
	}
}
require_once $root . '/includes/class-mad4b-scp-content-jobs.php';
require_once $root . '/includes/class-mad4b-scp-artifacts.php';
require_once $root . '/includes/class-mad4b-scp-research-intelligence.php';

$fail = static function ( $message ) { fwrite( STDERR, 'FAIL research-intelligence-runtime: ' . $message . PHP_EOL ); exit( 1 ); };
$check = static function ( $condition, $message ) use ( $fail ) { if ( ! $condition ) $fail( $message ); };
$error_code = static function ( $value ) { return is_wp_error( $value ) ? $value->get_error_code() : ''; };

$status = MAD4B_SCP_Schema::status( true );
$check( ! empty( $status['ready'] ) && MAD4B_SCP_Schema::VERSION >= 10, 'schema v10 not ready' );

$job = MAD4B_SCP_Content_Jobs::create_job( array(
	'brand_id' => 'brand-research-ci',
	'subject' => 'Research intelligence runtime fixture',
	'primary_keyword' => 'travel research',
	'language' => 'en',
	'country' => 'eg',
	'content_type' => 'article',
	'writer_profile_id' => '',
	'writer_profile_version' => '',
	'research_depth' => 'deep',
	'automation_level' => 'review_gated',
	'target_post_type' => 'post',
	'desired_publish_at' => '',
	'reason' => 'create research runtime fixture',
) );
$check( is_array( $job ) && isset( $job['job']['job_id'] ), 'job create failed' );
$job_id = $job['job']['job_id'];

$keyword_request = hash( 'sha256', 'keyword-request-v1' );
$keyword_input = array(
	'job_id' => $job_id,
	'provider_id' => 'ci_keyword_provider',
	'provider_state' => 'succeeded',
	'request_sha256' => $keyword_request,
	'collected_at' => '2026-09-25T00:00:00Z',
	'source_refs' => array( array( 'source_type' => 'api_record', 'ref' => 'kw:1' ) ),
	'usage' => array( 'requests' => 1 ),
	'market' => 'EG',
	'language' => 'en',
	'seed_terms' => array( 'travel research' ),
	'records' => array(
		array( 'keyword' => 'egypt travel guide', 'volume' => 1000, 'difficulty' => 42 ),
		array( 'keyword' => 'cairo itinerary', 'volume' => 700, 'difficulty' => 36 ),
	),
	'freshness_policy' => '7d',
);
$keyword = MAD4B_SCP_Research_Intelligence::record_keyword( $keyword_input );
$check( is_array( $keyword ) && 'keyword_research' === $keyword['artifact']['artifact_type'], 'keyword artifact failed' );
$check( false === $keyword['publishing_authority_created'], 'research created publishing authority' );

$keyword_replay = MAD4B_SCP_Research_Intelligence::record_keyword( $keyword_input );
$check( true === $keyword_replay['replayed'] && false === $keyword_replay['mutation_performed'], 'keyword idempotent replay failed' );

$keyword_conflict = $keyword_input;
$keyword_conflict['records'][0]['volume'] = 2000;
$conflict = MAD4B_SCP_Research_Intelligence::record_keyword( $keyword_conflict );
$check( 'mad4b_research_idempotency_conflict' === $error_code( $conflict ), 'keyword request hash conflict was accepted' );

// Provider failures are explicit durable observations, not silent empty success.
$timeout = MAD4B_SCP_Research_Intelligence::record_keyword( array(
	'job_id' => $job_id,
	'provider_id' => 'ci_keyword_provider',
	'provider_state' => 'timeout',
	'request_sha256' => hash( 'sha256', 'keyword-timeout-v1' ),
	'collected_at' => '2026-09-25T00:01:00Z',
	'source_refs' => array(),
	'usage' => array( 'requests' => 1 ),
	'market' => 'EG',
	'language' => 'en',
	'seed_terms' => array( 'timeout fixture' ),
	'records' => array(),
	'freshness_policy' => '7d',
	'error_class' => 'timeout',
) );
$check( 'timeout' === $timeout['artifact']['payload']['provider_state'], 'provider timeout was not explicit' );

$serp = MAD4B_SCP_Research_Intelligence::record_serp( array(
	'job_id' => $job_id,
	'provider_id' => 'ci_serp_provider',
	'provider_state' => 'succeeded',
	'request_sha256' => hash( 'sha256', 'serp-request-v1' ),
	'collected_at' => '2026-09-25T00:02:00Z',
	'source_refs' => array( array( 'source_type' => 'search_result', 'ref' => 'serp:1' ) ),
	'usage' => array( 'requests' => 1 ),
	'query' => 'egypt travel guide',
	'market' => 'EG',
	'language' => 'en',
	'device_location_assumptions' => array( 'device' => 'desktop', 'location' => 'Cairo' ),
	'results' => array(
		array( 'rank' => 1, 'url' => 'https://example.com/guide', 'title' => 'Example Guide' ),
		array( 'rank' => 2, 'url' => 'https://example.net/cairo', 'title' => 'Cairo Guide' ),
	),
	'features' => array( 'featured_snippet' => false ),
) );
$check( 'serp_research' === $serp['artifact']['artifact_type'], 'SERP artifact failed' );
$serp_id = $serp['artifact']['artifact_id'];

$selection = MAD4B_SCP_Research_Intelligence::select_competitors( array(
	'job_id' => $job_id,
	'serp_artifact_id' => $serp_id,
	'selected' => array( array( 'url' => 'https://example.com/guide', 'rank' => 1 ) ),
	'excluded' => array( array( 'url' => 'https://example.net/cairo', 'reason' => 'weak_match' ) ),
	'rationale_codes' => array( 'topical_match', 'high_rank' ),
	'operator_overrides' => array(),
) );
$check( 'competitor_set' === $selection['artifact']['artifact_type'], 'competitor selection artifact failed' );
$selection_id = $selection['artifact']['artifact_id'];

// Legal/robots policy is fail-closed.
$blocked_scrape = MAD4B_SCP_Research_Intelligence::record_scraped_page( array(
	'job_id' => $job_id,
	'provider_id' => 'ci_scrape_provider',
	'provider_state' => 'succeeded',
	'request_sha256' => hash( 'sha256', 'scrape-denied-v1' ),
	'collected_at' => '2026-09-25T00:03:00Z',
	'source_refs' => array(),
	'source_url' => 'https://example.com/guide',
	'canonical_url' => 'https://example.com/guide',
	'extraction_policy' => 'robots-aware-v1',
	'extracted_content_location' => 'evidence://scrape/denied',
	'extracted_sha256' => hash( 'sha256', 'denied' ),
	'source_authority_class' => 'external_source',
	'robots_policy_verdict' => 'denied',
) );
$check( 'mad4b_scrape_policy_denied' === $error_code( $blocked_scrape ), 'robots-denied scrape was persisted' );

$scrape = MAD4B_SCP_Research_Intelligence::record_scraped_page( array(
	'job_id' => $job_id,
	'provider_id' => 'ci_scrape_provider',
	'provider_state' => 'succeeded',
	'request_sha256' => hash( 'sha256', 'scrape-request-v1' ),
	'collected_at' => '2026-09-25T00:04:00Z',
	'source_refs' => array( array( 'source_type' => 'url', 'ref' => 'https://example.com/guide' ) ),
	'usage' => array( 'requests' => 1 ),
	'source_url' => 'https://example.com/guide',
	'canonical_url' => 'https://example.com/guide',
	'extraction_policy' => 'robots-aware-v1',
	'extracted_content_location' => 'evidence://scrape/example-guide',
	'extracted_sha256' => hash( 'sha256', 'normalized scraped content' ),
	'source_authority_class' => 'external_source',
	'robots_policy_verdict' => 'allowed',
	'competitor_selection_artifact_id' => $selection_id,
) );
$check( 'scraped_page' === $scrape['artifact']['artifact_type'], 'scraped page artifact failed' );
$scrape_id = $scrape['artifact']['artifact_id'];

$matrix = MAD4B_SCP_Research_Intelligence::build_coverage_matrix( array(
	'job_id' => $job_id,
	'matrix_type' => 'topic',
	'source_artifact_ids' => array( $serp_id, $selection_id, $scrape_id ),
	'dimensions' => array( 'topic', 'competitor', 'coverage' ),
	'items' => array(
		array( 'topic' => 'planning', 'coverage' => 0.8 ),
		array( 'topic' => 'transport', 'coverage' => 0.4 ),
	),
	'analysis_process_version' => 'ci-v1',
) );
$check( 'topic_coverage_matrix' === $matrix['artifact']['artifact_type'], 'coverage matrix artifact failed' );
$matrix_id = $matrix['artifact']['artifact_id'];

$gain = MAD4B_SCP_Research_Intelligence::build_information_gain( array(
	'job_id' => $job_id,
	'coverage_artifact_ids' => array( $matrix_id ),
	'differentiation_opportunities' => array( 'add verified local transport detail' ),
	'missing_questions_entities_topics' => array( 'airport transfer options' ),
	'first_party_evidence_opportunities' => array( 'ETG local guide data' ),
	'unsupported_areas_to_avoid' => array( 'unverified price claims' ),
	'recommended_priorities' => array( 'transport', 'evidence' ),
) );
$check( 'information_gain' === $gain['artifact']['artifact_type'], 'information gain artifact failed' );
$check( false === $gain['publishing_authority_created'], 'information gain created publishing authority' );

$edges = MAD4B_SCP_Artifacts::get_edges( array( 'job_id' => $job_id ) );
$relations = array_count_values( array_map( static function( $row ) { return $row['relation']; }, $edges['items'] ) );
$check( ! empty( $relations['selected_from'] ), 'SERP→competitor lineage missing' );
$check( ! empty( $relations['derived_from'] ), 'derived research lineage missing' );
$check( ! empty( $relations['summarizes'] ), 'coverage summarizes lineage missing' );

echo "mad4b.research-intelligence.runtime.v1: PASS\n";
