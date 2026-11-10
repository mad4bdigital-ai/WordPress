#!/usr/bin/env bash
set -euo pipefail

ROOT="${GITHUB_WORKSPACE:-$(pwd)}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

cat >"$TMP/lineage.php" <<'PHP'
<?php
$root = getenv('MAD4B_REPO_ROOT');
require_once $root . '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-schema.php';
if (!class_exists('MAD4B_SCP_Site_Profile')) {
  final class MAD4B_SCP_Site_Profile {
    public static function site_uuid() { return '11111111-2222-4333-8444-555555555555'; }
  }
}
if (!class_exists('MAD4B_SCP_Audit')) {
  final class MAD4B_SCP_Audit {
    public static function record($ability,$summary,$status,$mutation){ return array('recorded'=>true); }
    public static function transaction_committed(){}
    public static function transaction_rolled_back(){}
  }
}
// Use the production physical writer/readback probe in this real-MariaDB test.
// The WordPress CLI fixture deliberately skips plugin bootstrap, so all
// dependencies used by the guarded write path must be explicitly loaded.
require_once $root . '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-database-topology.php';
require_once $root . '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-identifiers.php';
final class MAD4B_SCP_Operational_Integrity {
  public static function capture() {
    return array('scope'=>array(
      'tenant_ref'=>'test-tenant', 'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
      'brand_ref'=>'brand-lineage', 'blog_id'=>1, 'network_id'=>1,
      'environment'=>'staging', 'deployment_mode'=>'staging',
    ));
  }
  public static function assert_unchanged($checkpoint, $for_write = true) {
    return self::capture()['scope'] === ($checkpoint['scope'] ?? array())
      ? true : new WP_Error('brand_lineage_test_scope_changed');
  }
}
// In this isolated MariaDB/WordPress test, model policy from an actual
// authenticated WordPress administrator. No host-wide or Production grant is
// synthesized; tests below first prove an anonymous actor cannot create jobs.
if (!class_exists('MAD4B_SCP_Policy', false)) {
  final class MAD4B_SCP_Policy {
    public static function can_mutate() {
      return get_current_user_id() > 0 && current_user_can('manage_options');
    }
  }
}
require_once $root . '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-jobs.php';
require_once $root . '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-artifacts.php';

$fail = static function($m){ fwrite(STDERR, "FAIL brand-context-builder-concurrency-runtime: $m\n"); exit(1); };
$subject_key = hash('sha256', 'brand-context-subject|' . wp_generate_uuid4());
$storage = MAD4B_SCP_Schema::transactional_storage_status(
  array('content_jobs','content_job_events','audit_events','audit_heads'), true);
if (empty($storage['ready']) || empty($storage['read_your_writes'])) {
  fwrite(STDERR, 'CONTEXT_WRITER_PREFLIGHT: ' . wp_json_encode(array(
    'blockers'=>$storage['blockers'] ?? array(),
    'topology_blockers'=>$storage['database_topology']['blockers'] ?? array(),
    'engines'=>$storage['engines'] ?? array(),
  )) . "\\n");
  exit(1);
}
// Anonymous --skip-plugins WP-CLI must remain denied before opening any
// ContentJob transaction, even though this command runs on a trusted CI host.
$table = MAD4B_SCP_Schema::tables()['content_jobs'];
$before_unauthorized = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
$unauthorized = MAD4B_SCP_Content_Jobs::create_job(array(
  'brand_id'=>'brand-lineage', 'subject'=>'Anonymous mutation must fail',
  'language'=>'en', 'country'=>'eg', 'content_type'=>'brand_context_generation',
  'research_depth'=>'evidence_bound','automation_level'=>'review_gated',
  'reason'=>'verify that a CLI host alone cannot create a ContentJob',
));
if (!is_wp_error($unauthorized)
    || 'mad4b_content_job_authorized_actor_required' !== $unauthorized->get_error_code()
    || $before_unauthorized !== (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"))
  $fail('anonymous actor bypassed the pre-transaction ContentJob guard');
// Authenticate the test's seeded WordPress admin inside the hermetic fixture;
// do not impose a --user flag or use an invented NHI/write grant.
$admin = get_user_by('login','admin');
if (!$admin || !user_can($admin,'manage_options')) $fail('seeded CI WordPress admin unavailable');
wp_set_current_user((int)$admin->ID);
if (!MAD4B_SCP_Policy::can_mutate()) $fail('real CI administrator was not authenticated');
$jobs = array();
for ($i=0; $i<2; ++$i) {
  $j = MAD4B_SCP_Content_Jobs::create_job(array(
    'brand_id'=>'brand-lineage',
    'subject'=>'Brand lineage fixture ' . $i,
    'language'=>'en',
    'country'=>'eg',
    'content_type'=>'brand_context_generation',
    'research_depth'=>'evidence_bound',
    'automation_level'=>'review_gated',
    'reason'=>'create cross-job Brand Context lineage fixture',
  ));
  if (is_wp_error($j)) $fail($j->get_error_code());
  $jobs[] = $j['job']['job_id'];
}
$artifacts = array();
foreach ($jobs as $i=>$job_id) {
  $a = MAD4B_SCP_Artifacts::append_artifact(array(
    'job_id'=>$job_id,
    'artifact_type'=>'brand_context_draft',
    'payload'=>array('category'=>'tone_of_voice','content'=>'fixture ' . $i),
    'metadata'=>array('brand_context_subject_key'=>$subject_key),
    'producer_stage'=>'DRAFT',
    'producer_ref'=>'brand-context-runtime',
    'reason'=>'persist cross-job lineage fixture',
  ));
  if (is_wp_error($a)) $fail($a->get_error_code());
  $artifacts[] = $a['artifact']['artifact_id'];
}
$lineage = MAD4B_SCP_Artifacts::supersede_brand_context_subject($artifacts[1], $subject_key);
if (is_wp_error($lineage)) $fail($lineage->get_error_code());
if (1 !== (int)$lineage['superseded_count']) $fail('expected exactly one older active draft to be superseded');
$first = MAD4B_SCP_Artifacts::get_artifact(array('artifact_id'=>$artifacts[0]));
$second = MAD4B_SCP_Artifacts::get_artifact(array('artifact_id'=>$artifacts[1]));
if (is_wp_error($first) || is_wp_error($second)) $fail('artifact readback failed');
if ('superseded' !== $first['artifact']['status']) $fail('older cross-job draft remained active');
if ('active' !== $second['artifact']['status']) $fail('newest cross-job draft did not remain active');

$late_old = MAD4B_SCP_Artifacts::supersede_brand_context_subject($artifacts[0], $subject_key);
if (is_wp_error($late_old)) $fail($late_old->get_error_code());
if (!empty($late_old['current_artifact_is_active_generation'])) $fail('older generation was resurrected by out-of-order lineage reconciliation');
if ($artifacts[1] !== $late_old['active_artifact_id']) $fail('out-of-order lineage did not preserve newest active generation');
$first = MAD4B_SCP_Artifacts::get_artifact(array('artifact_id'=>$artifacts[0]));
$second = MAD4B_SCP_Artifacts::get_artifact(array('artifact_id'=>$artifacts[1]));
if ('superseded' !== $first['artifact']['status']) $fail('older generation became active after reversed lineage call');
if ('active' !== $second['artifact']['status']) $fail('newest generation lost active status after reversed lineage call');
echo "mad4b.brand-context-builder.cross-job-lineage.v2: PASS\n";
PHP

export MAD4B_REPO_ROOT="$ROOT"
wp --path="$ROOT" --allow-root --skip-plugins --skip-themes eval-file "$TMP/lineage.php"
