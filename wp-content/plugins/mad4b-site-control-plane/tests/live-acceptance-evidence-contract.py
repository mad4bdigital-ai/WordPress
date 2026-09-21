from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
observer = (root / 'includes/class-mad4b-scp-live-acceptance-observer.php').read_text(encoding='utf-8')
finalizer = (root / 'includes/class-mad4b-scp-live-acceptance-finalizer.php').read_text(encoding='utf-8')
runtime_test = (root / 'tests/live-acceptance-observer-runtime.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
runtime_build = (root / 'MAD4B-RUNTIME-BUILD.txt').read_text(encoding='utf-8')
write = (root / 'includes/class-mad4b-scp-staging-write-authority.php').read_text(encoding='utf-8')
servers = (root / 'includes/class-mad4b-scp-servers.php').read_text(encoding='utf-8')
rest = (root / 'includes/class-mad4b-scp-rest-compatibility.php').read_text(encoding='utf-8')
external = (root / 'includes/class-mad4b-scp-external-handshake-evidence.php').read_text(encoding='utf-8')
authorization = (root / 'includes/class-mad4b-scp-authorization.php').read_text(encoding='utf-8')
live_truth = (root / 'includes/class-mad4b-scp-live-truth.php').read_text(encoding='utf-8')
write_cert = (root / 'includes/class-mad4b-scp-write-runtime-certification.php').read_text(encoding='utf-8')

required_observer = [
    "const QUERY_MONITOR_CONTRACT = 'mad4b.query-monitor-regression.v1'",
    "const PROVENANCE_CONTRACT = 'mad4b.build-provenance.v1'",
    "const EXTERNAL_ATTESTATION_CONTRACT = 'mad4b.external-handshake-attestation.v2'",
    "const WPML_RECEIPT_CONTRACT = 'mad4b.external-wpml-receipt.v1'",
    "const SNAPSHOT_VERIFY_CONTRACT = 'mad4b.snapshot-verify.v1'",
    "const AGGREGATE_CONTRACT = 'mad4b.live-acceptance-status.v1'",
    "MAD4B_SCP_Site_Profile::nonproduction_governed()",
    "MAD4B_SCP_Site_Profile::acceptance_enabled()",
    "MAD4B_SCP_Site_Profile::site_urls_match_enrollment()",
    "'acceptance_capture' => self::gate(",
    "'site_profile_acceptance_disabled'",
    "add_action( 'doing_it_wrong_run'",
    "add_action( 'deprecated_function_run'",
    "add_action( 'deprecated_argument_run'",
    "add_action( 'deprecated_hook_run'",
    "add_action( 'deprecated_class_run'",
    "add_filter( 'rest_post_dispatch'",
    "'mad4b/query-monitor-regression-status'",
    "'mad4b/frontend-performance-status'",
    "'frontend_performance_baseline' => self::gate(",
    "'mad4b.frontend-performance-evidence.v1'",
    "'baseline_only' => true",
    "'budget_evaluated' => false",
    "'ttfb_claimed' => false",
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
    "'provider_gated_write_tools'",
    "'provider_execution_mount_leaks'",
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

required_finalizer = [
    "const CONTRACT = 'mad4b.live-acceptance-finalizer.v1'",
    "const MUTATION_CONTRACT = 'mad4b.mutation-acceptance-receipt.v1'",
    "const PRODUCTION_CONTRACT = 'mad4b.production-unchanged-receipt.v1'",
    "const WPML_DIAGNOSTIC_CONTRACT = 'mad4b.external-wpml-diagnostic.v2'",
    "add_action( 'mad4b_scp_audit_committed'",
    "'mad4b/live-acceptance-execution-observed'",
    "'mad4b/live-acceptance-replay-denied-observed'",
    "'mad4b/live-acceptance-undo-observed'",
    "'mad4b_approval_replay_denied'",
    "MAD4B_SCP_Audit::verify_chain()",
    "public static function evaluate_mutation_receipt",
    "public static function evaluate_production_receipt",
    "public static function production_receipt_digest",
    "private static function trusted_external_finalizer_context",
    "MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()",
    "'mad4b:read'",
    "'candidate_mismatch'",
    "'build_fingerprint_mismatch'",
    "'stale_evidence'",
    "'partial_mutation_evidence'",
    "'replay_denial_unverified'",
    "'undo_unverified'",
    "'restored_state_mismatch'",
    "'production_snapshot_changed'",
    "'production_plugin_snapshot_changed'",
    "'evidence_digest_mismatch'",
    "'route_not_registered'",
    "'rest_no_route'",
    "'wp_error'",
    "'contract_mismatch'",
    "'non_json_response'",
    "'success'",
    "['production_receipt_accepted_from_caller_boolean'] = false",
]
missing = [marker for marker in required_finalizer if marker not in finalizer]
if missing:
    raise SystemExit('Missing Live Acceptance finalizer contract: ' + ' | '.join(missing))

# The Production proof input is structured evidence, not a self-certifying boolean.
production_schema = re.search(
    r"private static function production_receipt_schema\(\)\s*\{(.*?)\n\t\}",
    finalizer,
    re.S,
)
if not production_schema:
    raise SystemExit('Production receipt schema is missing.')
schema_body = production_schema.group(1)
for forbidden in ["'ready'", "'unchanged'", "'verified'"]:
    if forbidden in schema_body:
        raise SystemExit('Production receipt must not accept caller self-certification field: ' + forbidden)
for marker in [
    "'candidate_sha'", "'build_fingerprint'", "'origin'", "'environment'",
    "'production_runtime_identity'", "'baseline_snapshot_digest'", "'observed_snapshot_digest'",
    "'baseline_plugin_snapshot_digest'", "'observed_plugin_snapshot_digest'", "'checked_at'",
    "'issued_at'", "'issuer'", "'provenance'", "'evidence_digest'",
]:
    if marker not in schema_body:
        raise SystemExit('Production receipt is missing bound field: ' + marker)

# Mutation acceptance is reconstructed from local authoritative records/audit. It
# must never be accepted as an input payload on the read-only aggregate ability.
ability_schema = re.search(
    r"if \( 'mad4b/live-acceptance-status' === \$name \) \{(.*?)\n\t\t\}",
    finalizer,
    re.S,
)
if not ability_schema:
    raise SystemExit('Finalizer aggregate ability binding is missing.')
if 'mutation_receipt' in ability_schema.group(1) or 'mutation_acceptance' in ability_schema.group(1):
    raise SystemExit('Mutation acceptance must not be supplied by the caller.')
if "'production_receipt'" not in ability_schema.group(1):
    raise SystemExit('Structured external Production receipt input is missing.')

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
    "require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-live-acceptance-finalizer.php'",
    "MAD4B_SCP_Live_Acceptance_Observer::boot_early();",
    "MAD4B_SCP_Live_Acceptance_Finalizer::boot_early();",
    "add_action( 'init', array( 'MAD4B_SCP_Plugin', 'boot' ), -1000000 );",
]:
    if marker not in main:
        raise SystemExit('Missing Live Acceptance bootstrap invariant: ' + marker)

# Early observer/finalizer are evidence-only. They may persist bounded acceptance
# evidence and append audit bindings, but must never create grants, authorize a
# mutation, perform outbound probes, change content/filesystem state, or expose
# Breakglass/Production write authority.
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
    if forbidden in finalizer:
        raise SystemExit('Finalizer widened authority or performed forbidden work: ' + forbidden)

# No direct early Ability materialization. Registration happens only on the
# canonical wp_abilities_api_init callback.
for source_name, source in [('observer', observer), ('finalizer', finalizer)]:
    if re.search(r'\bwp_get_ability\s*\(', source):
        raise SystemExit(source_name + ' must not call wp_get_ability().')
    if re.search(r'\bwp_get_abilities\s*\(', source):
        raise SystemExit(source_name + ' must not materialize the Ability registry.')

# Local REST isolation and external WPML acceptance are independent gates.
for marker in [
    "'local_rest_isolation_ready' => $local_ready",
    "'local_rest_isolation' => array(",
    "'mcp_recovery_scoped_to_mad4b_routes' => $mcp_recovery_scoped",
    "'wpml_internal_probe_role' => 'diagnostic_only'",
    "'wpml_internal_probe_blocks_local_certification' => false",
    "'external_wpml_acceptance_required' => true",
    "'external_wpml_acceptance_verified' => false",
    "'external_http_probe_performed' => false",
]:
    if marker not in rest:
        raise SystemExit('WPML local/external separation regressed: ' + marker)

local_checks = re.search(
    r"\$local_checks\s*=\s*array\((.*?)\);\s*\$local_blockers",
    rest,
    re.S,
)
if not local_checks:
    raise SystemExit('Local REST structural check block is missing.')
local_body = local_checks.group(1)
if "$wpml['ready']" in local_body or "$wpml['query_parameters_preserved']" in local_body:
    raise SystemExit('Local REST readiness must not depend on WPML internal route readiness.')
if "$wpml['control_plane_block_detected']" not in local_body:
    raise SystemExit('Local REST readiness must still fail if MAD4B is proven to block WPML REST.')
if "'local_rest_isolation' => self::gate( ! empty( $rest['ready'] )" not in observer:
    raise SystemExit('Aggregate local REST gate no longer consumes the dedicated REST readiness result.')
if "'external_wpml' => self::gate( ! empty( $wpml['verified'] )" not in observer:
    raise SystemExit('External WPML acceptance must remain a separate observer gate before finalization.')
if "'frontend_performance_baseline' => self::gate( ! empty( $performance['ready'] )" not in observer:
    raise SystemExit('Front-end performance baseline must remain a separate current-build gate.')

# Environment/profile enrollment, acceptance capture and write eligibility are
# separate governance facts. Disabled features must stay fail-closed without
# being misreported as an origin-enrollment failure.
if "'environment_guard' => self::gate( self::staging_capture_allowed()" in observer:
    raise SystemExit('Environment Guard must not conflate acceptance capture with origin enrollment.')
for marker in [
    "'environment_guard' => self::gate( $environment_enrolled",
    "'acceptance_capture' => self::gate( $acceptance_enabled",
    "private static function nonproduction_profile_enrolled()",
    "MAD4B_SCP_Site_Profile::acceptance_enabled()",
]:
    if marker not in observer:
        raise SystemExit('Separated enrollment/acceptance diagnostic missing: ' + marker)
if "$checks['site_profile_bound'] = ! empty( $authority['eligible'] );" in live_truth:
    raise SystemExit('Live write truth must not conflate profile binding with write eligibility.')
for marker in [
    "$checks['site_profile_bound'] = ! empty( $authority['exact_profile_bound'] );",
    "$checks['write_feature_enabled'] = ! empty( $authority['profile_write_enabled'] );",
    "$checks['write_authority_eligible'] = ! empty( $authority['eligible'] );",
]:
    if marker not in live_truth:
        raise SystemExit('Live write truth separation missing: ' + marker)
if "$checks['exact_enrolled_origin'] = ! empty( $authority['eligible'] );" in write_cert:
    raise SystemExit('Canonical write certification must not conflate enrollment with authority eligibility.')
for marker in [
    "MAD4B_SCP_Site_Profile::origin_enrolled()",
    "$checks['write_feature_enabled'] = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::write_enabled();",
    "$checks['write_authority_eligible'] = ! empty( $authority['eligible'] );",
    "'profile_origin_enrolled' => $profile_origin_enrolled",
    "'profile_write_enabled' => $profile_write_enabled",
]:
    if marker not in write_cert:
        raise SystemExit('Canonical write certification diagnostic separation missing: ' + marker)

# Governed write eligibility requires the same exact site binding as the
# environment guard: profile origin/environment plus both home_url and site_url.
for marker in [
    "$site_urls_match = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();",
    "$exact_profile_bound = $origin_enrolled && $site_urls_match;",
    "$eligible = $configured && $exact_profile_bound && $write_enabled;",
    "'site_profile_site_urls_mismatch'",
    "'profile_site_urls_match' => $site_urls_match",
    "'exact_profile_bound' => $exact_profile_bound",
]:
    if marker not in write:
        raise SystemExit('Exact governed-write site binding hardening missing: ' + marker)
if "$eligible = $configured && $origin_enrolled && $write_enabled;" in write:
    raise SystemExit('Governed write eligibility must not ignore site_url/home_url parity.')
for marker in [
    "$profile_site_urls_match = $profile_available && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();",
    "$exact_profile_bound = $profile_origin_enrolled && $profile_site_urls_match;",
    "'profile_site_urls_match' => $profile_site_urls_match",
    "'exact_profile_bound' => $exact_profile_bound",
    "$checks['site_profile_bound'] = ! empty( $authority['exact_profile_bound'] );",
]:
    if marker not in live_truth:
        raise SystemExit('Live write truth exact binding diagnostic missing: ' + marker)

# Positive reachability is a mandatory regression, not only false-pass checks.
for marker in [
    'Valid authoritative mutation receipt must become ready.',
    'Wrong mutation SHA must fail closed.',
    'Wrong mutation fingerprint must fail closed.',
    'Stale mutation receipt must fail closed.',
    'Partial mutation evidence must fail closed.',
    'Replay not denied must fail closed.',
    'Missing undo proof must fail closed.',
    'Undo state drift must fail closed.',
    'Valid reproducible Production v2 receipt must become ready.',
    'Production v2 must report normalized plugin count.',
    'Production v2 producer must be verified.',
    'Plugin snapshot must be order-invariant after normalization.',
    'Production v2 wrong SHA must fail closed.',
    'Writable Production producer must fail closed.',
    'Production runtime drift must fail closed.',
    'Production plugin drift must fail closed.',
    'Duplicate Production plugin path must fail closed.',
    'Stale Production v2 receipt must fail closed.',
    'Opaque Production v1 contract must not close the v2 gate.',
    'Tampered Production v2 receipt must fail closed.',
    'All valid mandatory gates must make ready=true reachable.',
]:
    if marker not in runtime_test:
        raise SystemExit('Live Acceptance reachability regression is missing: ' + marker)

# Canonical external handshake v3 certifies the stable external catalog while
# current execution eligibility stays bound to mad4b-write separately.
if "const CONTRACT = 'mad4b.external-handshake-evidence.v3'" not in external:
    raise SystemExit('Canonical external-handshake v3 contract is missing.')
for marker in [
    'public static function external_write_tools',
    'public static function is_external_write_candidate',
    'stable registered tenant-bound catalog',
]:
    if marker not in servers and marker not in external:
        raise SystemExit('Stable external write-catalog contract missing: ' + marker)
if "'mad4b_write_capability_not_eligible'" not in (root / 'includes/class-mad4b-scp-transport-context.php').read_text(encoding='utf-8'):
    raise SystemExit('Discoverable-but-gated provider writes are not fail-closed at transport resolution.')

# Governance invariants requested by the acceptance patch.
for marker in [
    "'production_auto_enable' => false",
    "'breakglass_auto_enable' => false",
    "'all_remote_writes_require_exact_approval' => false",
    "'normal_remote_writes_require_exact_approval' => true",
    "'remote_write_approval_policy' => 'exact_approval_except_bounded_candidate_bootstrap'",
    "'remote_write_prior_approval_exceptions' => array( self::CANDIDATE_BOOTSTRAP_ABILITY )",
    "const CANDIDATE_BOOTSTRAP_ABILITY = 'mad4b/acceptance-target-provision'",
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
if 'const MAX_LEDGER_ENTRIES = 12' not in finalizer:
    raise SystemExit('Mutation acceptance ledger must remain bounded.')
if "update_option( self::LEDGER_OPTION, $ledger, false )" not in finalizer:
    raise SystemExit('Acceptance ledger must explicitly disable autoload.')
if "update_option( self::WPML_DIAGNOSTIC_OPTION, $receipt, false )" not in finalizer:
    raise SystemExit('WPML diagnostics must explicitly disable autoload.')

print('mad4b.live-acceptance-evidence.contract.v5: PASS')
