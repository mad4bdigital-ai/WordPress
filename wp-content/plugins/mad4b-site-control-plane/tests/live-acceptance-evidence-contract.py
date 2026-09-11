from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
observer = (root / 'includes/class-mad4b-scp-live-acceptance-observer.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
runtime_build = (root / 'MAD4B-RUNTIME-BUILD.txt').read_text(encoding='utf-8')
write = (root / 'includes/class-mad4b-scp-staging-write-authority.php').read_text(encoding='utf-8')
servers = (root / 'includes/class-mad4b-scp-servers.php').read_text(encoding='utf-8')
rest = (root / 'includes/class-mad4b-scp-rest-compatibility.php').read_text(encoding='utf-8')
external = (root / 'includes/class-mad4b-scp-external-handshake-evidence.php').read_text(encoding='utf-8')
authorization = (root / 'includes/class-mad4b-scp-authorization.php').read_text(encoding='utf-8')

required_observer = [
    "const QUERY_MONITOR_CONTRACT = 'mad4b.query-monitor-regression.v1'",
    "const PROVENANCE_CONTRACT = 'mad4b.build-provenance.v1'",
    "const EXTERNAL_ATTESTATION_CONTRACT = 'mad4b.external-handshake-attestation.v1'",
    "const WPML_RECEIPT_CONTRACT = 'mad4b.external-wpml-receipt.v1'",
    "const SNAPSHOT_VERIFY_CONTRACT = 'mad4b.snapshot-verify.v1'",
    "const AGGREGATE_CONTRACT = 'mad4b.live-acceptance-status.v1'",
    "const STAGING_HOST = 'staging.egypttourgates.com'",
    "add_action( 'doing_it_wrong_run'",
    "add_action( 'deprecated_function_run'",
    "add_action( 'deprecated_argument_run'",
    "add_action( 'deprecated_hook_run'",
    "add_action( 'deprecated_class_run'",
    "add_filter( 'rest_post_dispatch'",
    "'mad4b/query-monitor-regression-status'",
    "'mad4b/build-provenance-status'",
    "'mad4b/external-handshake-attestation-status'",
    "'mad4b/external-wpml-receipt-status'",
    "'mad4b/snapshot-verify'",
    "'mad4b/live-acceptance-status'",
    "'readonly' => true",
    "'surface' => 'read'",
    "'production_capture_persistence_enabled' => false",
    "'pending_external_evidence'",
    "'external_facts_self_certified' => false",
    "'provider_blocked_tool_leaks'",
    "'missing_expected_tools'",
    "'unexpected_tools'",
    "'raw_sql_exposed'",
    "'breakglass_exposed'",
    "'foreign_write_tool_exposed'",
    "'write_inventory_fingerprint_match'",
]
missing = [marker for marker in required_observer if marker not in observer]
if missing:
    raise SystemExit('Missing Live Acceptance observer contract: ' + ' | '.join(missing))

# Release identity must stay internally exact without hard-coding a specific RC.
header = re.search(r'(?mi)^\s*\*\s*Version:\s*([^\r\n]+)', main)
runtime = re.search(r"define\(\s*'MAD4B_SCP_VERSION'\s*,\s*'([^']+)'\s*\);", main)
build = re.search(r'(?mi)^release=([^\r\n]+)$', runtime_build)
if not header or not runtime or not build:
    raise SystemExit('Live Acceptance release identity evidence is incomplete.')
versions = [header.group(1).strip(), runtime.group(1).strip(), build.group(1).strip()]
if len(set(versions)) != 1:
    raise SystemExit('Live Acceptance release identity mismatch: ' + ' | '.join(versions))
if not re.fullmatch(r'\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?', versions[0]):
    raise SystemExit('Live Acceptance release identity format invalid: ' + versions[0])

for marker in [
    "require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-live-acceptance-observer.php'",
    "MAD4B_SCP_Live_Acceptance_Observer::boot_early();",
    "add_action( 'init', array( 'MAD4B_SCP_Plugin', 'boot' ), -1000000 );",
]:
    if marker not in main:
        raise SystemExit('Missing Live Acceptance bootstrap invariant: ' + marker)

# Early observer is observation/registration only. It may persist bounded evidence,
# but it must never create authorization, grants, servers, provider initialization,
# outbound probes, content/filesystem/SQL mutation, or generic dispatchers.
for forbidden in [
    'MAD4B_SCP_Agent_Registry::grant_ability',
    'MAD4B_SCP_Authorization::authorize_mutation',
    'wp_remote_get(',
    'wp_remote_post(',
    'wp_remote_request(',
    'rest_do_request(',
    'wp_insert_post(',
    'wp_update_post(',
    '$wpdb->query(',
    '$wpdb->update(',
    'file_put_contents(',
    "'mad4b-write' =>",
    "'mad4b-breakglass' =>",
]:
    if forbidden in observer:
        raise SystemExit('Observer widened authority or performed forbidden work: ' + forbidden)

# No direct early Ability materialization. Registration happens only on the
# canonical wp_abilities_api_init callback.
if re.search(r'\bwp_get_ability\s*\(', observer):
    raise SystemExit('Observer must not call wp_get_ability().')
if re.search(r'\bwp_get_abilities\s*\(', observer):
    raise SystemExit('Observer must not materialize the Ability registry.')

# Current local REST contract must stay separated from external WPML acceptance.
for marker in [
    "'wpml_internal_probe_role' => 'diagnostic_only'",
    "'wpml_internal_probe_blocks_local_certification' => false",
    "'external_wpml_acceptance_required' => true",
]:
    if marker not in rest:
        raise SystemExit('WPML local/external separation regressed: ' + marker)

# Keep existing external handshake v2 compatibility; companion attestation only
# hardens exact-set diff/freshness and never downgrades the canonical contract.
if "const CONTRACT = 'mad4b.external-handshake-evidence.v2'" not in external:
    raise SystemExit('Canonical external-handshake v2 contract was downgraded.')

# Governance invariants requested by the acceptance patch.
for marker in [
    "'production_auto_enable' => false",
    "'breakglass_auto_enable' => false",
    "'all_remote_writes_require_exact_approval' => true",
]:
    if marker not in write:
        raise SystemExit('Write authority invariant missing: ' + marker)
if "'mad4b/database-raw-query' === $ability_name" not in servers:
    raise SystemExit('Raw SQL exclusion from governed write projection is missing.')
if 'public static function blocked_write_tools' not in servers:
    raise SystemExit('Dynamic provider-blocked runtime truth is missing.')
if 'authorize_mutation' not in authorization or 'approval' not in authorization.lower():
    raise SystemExit('Central mutation authorization/approval contract missing.')

# Storage is bounded and only uses MAD4B-owned options/transients; never autoload.
if 'const MAX_EVENTS = 32' not in observer or 'const TELEMETRY_TTL = 21600' not in observer:
    raise SystemExit('Bounded telemetry/TTL contract missing.')
if "update_option( self::TELEMETRY_OPTION, self::$telemetry, false )" not in observer:
    raise SystemExit('Telemetry option must explicitly disable autoload.')

print('mad4b.live-acceptance-evidence.contract.v2: PASS')
