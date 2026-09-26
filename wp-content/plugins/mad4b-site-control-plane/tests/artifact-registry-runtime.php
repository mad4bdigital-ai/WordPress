<?php
/**
 * Real MariaDB Artifact Registry lifecycle and lineage regression.
 */

$root = dirname( __DIR__ );
require_once $root . '/includes/class-mad4b-scp-schema.php';

if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) {
	final class MAD4B_SCP_Site_Profile {
		public static $uuid = '11111111-2222-4333-8444-555555555555';
		public static function site_uuid() { return self::$uuid; }
	}
}
if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) {
	final class MAD4B_SCP_Audit {
		public static function record( $ability, $summary, $status, $mutation ) { return array( 'recorded' => true ); }
		public static function transaction_committed() {}
		public static function transaction_rolled_back() {}
	}
}

require_once $root . '/includes/class-mad4b-scp-content-jobs.php';
require_once $root . '/includes/class-mad4b-scp-artifacts.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL artifact-registry-runtime: ' . $message . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message ) use ( $fail ) {
	if ( ! $condition ) $fail( $message );
};
$error_code = static function ( $value ) {
	return is_wp_error( $value ) ? $value->get_error_code() : '';
};

$status = MAD4B_SCP_Schema::status( true );
$check( ! empty( $status['ready'] ), 'schema is not ready' );
$check( MAD4B_SCP_Schema::VERSION >= 10, 'artifact schema v10 not active' );

$job_create = MAD4B_SCP_Content_Jobs::create_job( array(
	'brand_id' => 'brand-artifact-ci',
	'subject' => 'Artifact lineage runtime fixture',
	'primary_keyword' => 'artifact lineage',
	'language' => 'en',
	'country' => 'eg',
	'content_type' => 'article',
	'writer_profile_id' => '',
	'writer_profile_version' => '',
	'research_depth' => 'standard',
	'automation_level' => 'review_gated',
	'target_post_type' => 'post',
	'desired_publish_at' => '',
	'reason' => 'create artifact lineage fixture',
) );
$check( is_array( $job_create ) && isset( $job_create['job']['job_id'] ), 'ContentJob fixture creation failed' );
$job_id = $job_create['job']['job_id'];

$append = static function ( $type, $payload, $stage, $ref ) use ( $job_id, $check ) {
	$r = MAD4B_SCP_Artifacts::append_artifact( array(
		'job_id' => $job_id,
		'artifact_type' => $type,
		'payload' => $payload,
		'metadata' => array( 'fixture' => true ),
		'producer_stage' => $stage,
		'producer_ref' => $ref,
		'reason' => 'append runtime artifact fixture',
	) );
	$check( is_array( $r ) && isset( $r['artifact'] ), 'append failed for ' . $type );
	return $r['artifact'];
};

$context_v1 = $append(
	'context_pack',
	array( 'brand' => array( 'tone' => 'clear' ), 'language' => 'en' ),
	'KNOWLEDGE_DISPATCH',
	'ci:context-v1'
);
$check( 1 === $context_v1['version'], 'context v1 version mismatch' );
$check( 'active' === $context_v1['status'], 'context v1 not active' );
$canonical_context = wp_json_encode(
	array( 'brand' => array( 'tone' => 'clear' ), 'language' => 'en' ),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
$check( hash( 'sha256', $canonical_context ) === $context_v1['content_sha256'], 'context canonical SHA mismatch' );

$research = $append(
	'keyword_research',
	array( 'primary' => 'artifact lineage', 'terms' => array( 'content graph', 'versioning' ) ),
	'KEYWORD_RESEARCH',
	'ci:research-v1'
);
$blueprint = $append(
	'blueprint',
	array( 'sections' => array( 'intro', 'body', 'conclusion' ) ),
	'BLUEPRINT',
	'ci:blueprint-v1'
);

$link1 = MAD4B_SCP_Artifacts::link_artifacts( array(
	'from_artifact_id' => $context_v1['artifact_id'],
	'to_artifact_id' => $research['artifact_id'],
	'relation' => 'derived_from',
) );
$check( is_array( $link1 ) && true === $link1['mutation_performed'], 'context→research edge failed' );

$link2 = MAD4B_SCP_Artifacts::link_artifacts( array(
	'from_artifact_id' => $research['artifact_id'],
	'to_artifact_id' => $blueprint['artifact_id'],
	'relation' => 'derived_from',
) );
$check( is_array( $link2 ), 'research→blueprint edge failed' );

$duplicate_edge = MAD4B_SCP_Artifacts::link_artifacts( array(
	'from_artifact_id' => $research['artifact_id'],
	'to_artifact_id' => $blueprint['artifact_id'],
	'relation' => 'derived_from',
) );
$check( 'mad4b_artifact_link_failed' === $error_code( $duplicate_edge ), 'duplicate lineage edge did not fail closed' );

$context_v2 = $append(
	'context_pack',
	array( 'brand' => array( 'tone' => 'clear', 'audience' => 'travelers' ), 'language' => 'en' ),
	'KNOWLEDGE_DISPATCH',
	'ci:context-v2'
);
$check( 2 === $context_v2['version'], 'context v2 did not increment version' );
$check( $context_v1['artifact_id'] === $context_v2['supersedes_artifact_id'], 'supersedes lineage missing' );
$check( 'active' === $context_v2['status'], 'new context version was invalidated' );

$context_v1_after = MAD4B_SCP_Artifacts::get_artifact( array( 'artifact_id' => $context_v1['artifact_id'] ) );
$research_after = MAD4B_SCP_Artifacts::get_artifact( array( 'artifact_id' => $research['artifact_id'] ) );
$blueprint_after = MAD4B_SCP_Artifacts::get_artifact( array( 'artifact_id' => $blueprint['artifact_id'] ) );
$check( 'superseded' === $context_v1_after['artifact']['status'], 'old context did not become superseded' );
$check( 'stale' === $research_after['artifact']['status'], 'dependent research did not become stale' );
$check( 'stale' === $blueprint_after['artifact']['status'], 'transitive blueprint did not become stale' );

$edges = MAD4B_SCP_Artifacts::get_edges( array( 'job_id' => $job_id ) );
$check( is_array( $edges ) && 3 === $edges['count'], 'lineage edge count mismatch' );
$invalidated = 0;
$supersedes = 0;
foreach ( $edges['items'] as $edge ) {
	if ( ! empty( $edge['invalidated'] ) ) $invalidated++;
	if ( 'supersedes' === $edge['relation'] ) {
		$supersedes++;
		$check( empty( $edge['invalidated'] ), 'supersedes edge was incorrectly invalidated' );
		$check( $context_v1['artifact_id'] === $edge['from_artifact_id'], 'supersedes source mismatch' );
		$check( $context_v2['artifact_id'] === $edge['to_artifact_id'], 'supersedes target mismatch' );
	}
}
$check( 2 === $invalidated, 'expected two invalidated dependency edges' );
$check( 1 === $supersedes, 'expected one supersedes edge' );

$list = MAD4B_SCP_Artifacts::list_artifacts( array(
	'job_id' => $job_id,
	'artifact_type' => 'context_pack',
) );
$check( 2 === $list['count'], 'context artifact version list mismatch' );
$check( 1 === $list['items'][0]['version'] && 2 === $list['items'][1]['version'], 'artifact versions are not ordered' );

// Explicit invalidation marks the source stale without deleting lineage.
$invalidated_v2 = MAD4B_SCP_Artifacts::invalidate_artifact( array(
	'artifact_id' => $context_v2['artifact_id'],
	'reason_code' => 'context_revoked',
) );
$check( is_array( $invalidated_v2 ) && true === $invalidated_v2['mutation_performed'], 'explicit invalidation failed' );
$v2_after = MAD4B_SCP_Artifacts::get_artifact( array( 'artifact_id' => $context_v2['artifact_id'] ) );
$check( 'stale' === $v2_after['artifact']['status'], 'explicit invalidation did not stale source' );

// Site boundary: artifact IDs cannot be read through another site identity.
MAD4B_SCP_Site_Profile::$uuid = '99999999-8888-4777-8666-555555555555';
$cross_site = MAD4B_SCP_Artifacts::get_artifact( array( 'artifact_id' => $context_v2['artifact_id'] ) );
$check( 'mad4b_artifact_missing' === $error_code( $cross_site ), 'cross-site artifact lookup leaked evidence' );
MAD4B_SCP_Site_Profile::$uuid = '11111111-2222-4333-8444-555555555555';

echo "mad4b.artifact-registry.runtime.v1: PASS\n";
