<?php
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
		public static function record( $ability, $summary, $status, $mutation = false ) { return array( 'recorded' => true ); }
		public static function transaction_committed() {}
		public static function transaction_rolled_back() {}
	}
}
if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) ) {
	final class MAD4B_SCP_Context_Authority {
		public static $brand_id = 'brand-context-ci';
		public static $assets = array();
		public static function profile() {
			return array(
				'contract' => 'mad4b.brand-context-profile.v1',
				'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
				'brand_id' => self::$brand_id,
				'brand_name' => 'CI Brand',
				'revision' => 7,
			);
		}
		public static function assets() { return self::$assets; }
		public static function asset( $asset_id ) {
			foreach ( self::$assets as $asset ) {
				if ( is_array( $asset ) && isset( $asset['asset_id'] ) && hash_equals( (string) $asset['asset_id'], (string) $asset_id ) ) return $asset;
			}
			return array();
		}
		public static function authority_manifest_fingerprint() {
			return hash( 'sha256', wp_json_encode( self::$assets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
		public static function registry_revision() { return 7; }
	}
}

require_once $root . '/includes/class-mad4b-scp-content-jobs.php';
require_once $root . '/includes/class-mad4b-scp-artifacts.php';
require_once $root . '/includes/class-mad4b-scp-context-pack.php';

$fail = static function ( $message ) { fwrite( STDERR, 'FAIL context-pack-runtime: ' . $message . PHP_EOL ); exit( 1 ); };
$check = static function ( $condition, $message ) use ( $fail ) { if ( ! $condition ) $fail( $message ); };
$error_code = static function ( $value ) { return is_wp_error( $value ) ? $value->get_error_code() : ''; };

$status = MAD4B_SCP_Schema::status( true );
$check( ! empty( $status['ready'] ) && MAD4B_SCP_Schema::VERSION >= 10, 'schema v10 not ready' );

$job = MAD4B_SCP_Content_Jobs::create_job( array(
	'brand_id' => 'brand-context-ci',
	'subject' => 'ContextPack runtime fixture',
	'primary_keyword' => 'context pack',
	'language' => 'en',
	'country' => 'eg',
	'content_type' => 'article',
	'writer_profile_id' => '',
	'writer_profile_version' => '',
	'research_depth' => 'standard',
	'automation_level' => 'review_gated',
	'target_post_type' => 'post',
	'desired_publish_at' => '',
	'reason' => 'create ContextPack runtime fixture',
) );
$check( is_array( $job ) && isset( $job['job']['job_id'] ), 'job create failed' );
$job_id = $job['job']['job_id'];

$asset = static function ( $id_seed, $category, $content, $language = '' ) {
	$hash = hash( 'sha256', $content );
	return array(
		'contract' => 'mad4b.context-asset.v1',
		'asset_id' => hash( 'sha256', 'asset:' . $id_seed ),
		'source_id' => hash( 'sha256', 'source:ci' ),
		'file_id' => 'file-' . $id_seed,
		'source_mode' => 'governed',
		'status' => 'ready',
		'content_complete' => true,
		'review_status' => 'approved',
		'content_hash' => $hash,
		'reviewed_content_hash' => $hash,
		'category' => $category,
		'authority_class' => 'brand_authority',
		'path' => '/CI/' . $id_seed . '.md',
		'content_excerpt' => substr( $content, 0, 100 ),
		'language' => $language,
		'priority' => 100,
	);
};

MAD4B_SCP_Context_Authority::$assets = array(
	'brand' => $asset( 'brand', 'brand_strategy', 'Brand strategy approved evidence.', 'en' ),
	'audience' => $asset( 'audience', 'audience_persona', 'Audience persona approved evidence.', 'en' ),
	'voice' => $asset( 'voice', 'tone_of_voice', 'Tone of voice approved evidence.', 'en' ),
	'seo' => $asset( 'seo', 'seo_strategy', 'SEO strategy optional evidence.', 'en' ),
	'arabic' => $asset( 'arabic', 'brand_strategy', 'Arabic-only context should not match English job.', 'ar' ),
);
$writer_asset = $asset( 'writer', 'writer_reference', 'Approved immutable writer profile.', 'en' );
MAD4B_SCP_Context_Authority::$assets['writer'] = $writer_asset;

$writer_job = MAD4B_SCP_Content_Jobs::create_job( array(
	'brand_id' => 'brand-context-ci',
	'subject' => 'WriterProfile binding fixture',
	'primary_keyword' => 'writer profile',
	'language' => 'en',
	'country' => 'eg',
	'content_type' => 'article',
	'writer_profile_id' => $writer_asset['asset_id'],
	'writer_profile_version' => $writer_asset['content_hash'],
	'research_depth' => 'standard',
	'automation_level' => 'review_gated',
	'target_post_type' => 'post',
	'desired_publish_at' => '',
	'reason' => 'bind exact approved WriterProfile',
) );
$check( is_array( $writer_job ) && isset( $writer_job['job']['job_id'] ), 'writer job create failed' );
$writer_job_id = $writer_job['job']['job_id'];
$writer_requirements = MAD4B_SCP_Context_Pack::resolve_requirements( array( 'job_id' => $writer_job_id ) );
$check( is_array( $writer_requirements ) && 64 === strlen( $writer_requirements['writer_profile_fingerprint'] ), 'content-addressed WriterProfile binding missing' );
$check( in_array( 'writer.profile', $writer_requirements['required_classes'], true ), 'bound WriterProfile is not a required knowledge class' );
$check( hash_equals( $writer_asset['content_hash'], $writer_requirements['writer_profile_version'] ), 'WriterProfile version is not exact content hash' );
$original_writer_hash = MAD4B_SCP_Context_Authority::$assets['writer']['content_hash'];
MAD4B_SCP_Context_Authority::$assets['writer']['content_hash'] = hash( 'sha256', 'mutated writer profile without new job binding' );
MAD4B_SCP_Context_Authority::$assets['writer']['reviewed_content_hash'] = MAD4B_SCP_Context_Authority::$assets['writer']['content_hash'];
$writer_drift = MAD4B_SCP_Context_Pack::resolve_requirements( array( 'job_id' => $writer_job_id ) );
$check( 'mad4b_writer_profile_version_stale' === $error_code( $writer_drift ), 'WriterProfile content drift did not invalidate bound version' );
MAD4B_SCP_Context_Authority::$assets['writer']['content_hash'] = $original_writer_hash;
MAD4B_SCP_Context_Authority::$assets['writer']['reviewed_content_hash'] = $original_writer_hash;

$requirements = MAD4B_SCP_Context_Pack::resolve_requirements( array( 'job_id' => $job_id ) );
$check( is_array( $requirements ), 'requirements resolver failed' );
foreach ( array( 'brand.core', 'audience.primary', 'voice.language' ) as $required ) {
	$check( in_array( $required, $requirements['required_classes'], true ), 'missing base requirement ' . $required );
}
$check( in_array( 'seo.strategy', $requirements['conditional_classes'], true ), 'article SEO should be conditional' );
$check( ! in_array( 'writer.profile', $requirements['required_classes'], true ), 'unbound WriterProfile became required' );
$check( ! in_array( 'writer.profile', $requirements['conditional_classes'], true ), 'unbound WriterProfile leaked into conditional context' );
$check( 64 === strlen( $requirements['job_requirements_sha256'] ), 'requirements digest missing' );

$preview1 = MAD4B_SCP_Context_Pack::preview( array( 'job_id' => $job_id ) );
$check( is_array( $preview1 ) && true === $preview1['ready'], 'ContextPack preview not ready' );
$check( array() === $preview1['missing_required_classes'], 'required classes unexpectedly missing' );
$check( 4 === count( $preview1['source_assets'] ), 'eligible source asset/class projection count mismatch' );
foreach ( $preview1['source_assets'] as $row ) {
	$check( 'approved' === $row['review_state'], 'unreviewed asset entered pack' );
	$check( 0 === strpos( $row['asset_version'], 'sha256:' ), 'asset version is not content-addressed' );
	$check( 64 === strlen( $row['fingerprint'] ), 'source fingerprint missing' );
}

usleep( 1000 );
$preview2 = MAD4B_SCP_Context_Pack::preview( array( 'job_id' => $job_id ) );
$check( $preview1['context_pack_sha256'] === $preview2['context_pack_sha256'], 'ContextPack deterministic digest changed with generated_at' );

$built = MAD4B_SCP_Context_Pack::build( array(
	'job_id' => $job_id,
	'reason' => 'persist exact governed ContextPack',
) );
$check( is_array( $built ) && true === $built['mutation_performed'], 'ContextPack artifact build failed' );
$check( 'context_pack' === $built['artifact']['artifact_type'], 'wrong artifact type' );
$check( 1 === $built['artifact']['version'], 'first ContextPack artifact version mismatch' );
$check( $preview1['context_pack_sha256'] === $built['context_pack_sha256'], 'persisted pack digest drifted' );

unset( MAD4B_SCP_Context_Authority::$assets['voice'] );
$missing = MAD4B_SCP_Context_Pack::preview( array( 'job_id' => $job_id ) );
$check( in_array( 'voice.language', $missing['missing_required_classes'], true ), 'missing voice class not detected' );
$blocked = MAD4B_SCP_Context_Pack::build( array( 'job_id' => $job_id, 'reason' => 'must fail missing context' ) );
$check( 'mad4b_context_pack_required_knowledge_missing' === $error_code( $blocked ), 'missing required knowledge did not fail closed' );

$list = MAD4B_SCP_Artifacts::list_artifacts( array( 'job_id' => $job_id, 'artifact_type' => 'context_pack' ) );
$check( 1 === $list['count'], 'blocked ContextPack build persisted an artifact' );

MAD4B_SCP_Context_Authority::$brand_id = 'different-brand';
$brand_mismatch = MAD4B_SCP_Context_Pack::preview( array( 'job_id' => $job_id ) );
$check( 'mad4b_context_pack_brand_mismatch' === $error_code( $brand_mismatch ), 'brand mismatch was not denied' );

echo "mad4b.context-pack.runtime.v1: PASS\n";
