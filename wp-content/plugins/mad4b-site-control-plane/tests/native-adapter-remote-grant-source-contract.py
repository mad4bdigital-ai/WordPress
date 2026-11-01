#!/usr/bin/env python3
"""CI-only exact source checks for native Ability discovery and staging grant lane.

No WordPress runtime, credentials, database mutations, or network requests.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INCLUDES = ROOT / "includes"
ADAPTER = (INCLUDES / "class-mad4b-scp-adapter-registry.php").read_text()
NATIVE = (INCLUDES / "class-mad4b-scp-native-ability-trust.php").read_text()
REMOTE = (ROOT / "config/remote-operation-catalog.php").read_text()
GRANT = (INCLUDES / "class-mad4b-scp-staging-write-grant-reconciliation.php").read_text()

def assert_native(s: str) -> None:
    assert "require_once __DIR__ . '/class-mad4b-scp-native-ability-trust.php';" in s
    assert "'mad4b/native-capability-registry'" in s
    start = s.index("$this->register_registry_ability( 'mad4b/native-capability-registry'")
    registration = s[start:s.index(");", start) + 2]
    for required in (
        "'native_capability_registry'", "without executing a callback",
        "'additionalProperties'=>false", "'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>96)",
    ):
        assert required in registration, required
    method = s.index("public function native_capability_registry(")
    body = s[method:s.index("}", method)]
    assert "return MAD4B_SCP_Native_Ability_Trust::catalog( $input );" in body
    # All dynamically registered Adapter Registry Abilities retain the shared
    # read-only and policy-gated registration configuration.
    core = s[s.index("private function register_registry_ability("):s.index("public function inventory()")]
    for fixed in (
        "'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' )",
        "'public' => false", "'show_in_rest' => false",
        "'surface' => 'read'", "'readonly' => true",
    ):
        assert fixed in core, fixed

def assert_remote(s: str) -> None:
    marker = "'staging_write_grant_reconciliation' => array("
    assert s.count(marker) == 1
    start = s.index(marker)
    end = s.index("'staging_candidate_binding' => array(", start)
    entry = s[start:end]
    for required in (
        "'status_ability' => 'mad4b/staging-write-grant-reconciliation-plan'",
        "'remote_ability' => 'mad4b/staging-write-grant-reconcile-staging'",
        "'authority_surface' => 'mad4b-enrollment'",
        "'remote_caller_role' => 'operator'",
        "'production_policy' => 'deny'",
        "'human_decision_required' => false",
        "'remote_mode' => 'exact_staging_reviewed_stale_grant_retirement'",
    ):
        assert required in entry, required
    # Explicit staging-only alias, unmodified real executor, no Production
    # approved-auto or optional bypass of plan hash, confirmation and grants.
    for required in (
        "const STAGING_ALIAS = 'mad4b/staging-write-grant-reconcile-staging';",
        "const CONFIRMATION = 'RECONCILE EXACT STAGING WRITE AUTHORITY';",
        "function can_execute(",
        "'mad4b_approved_auto_production_denied'",
        "'mad4b_grant_reconcile_plan_changed'",
    ):
        assert required in GRANT, required
    assert "public static function register_staging_alias()" in GRANT

def assert_native_source() -> None:
    for required in (
        "public static function catalog(", "'discovery_only'=>true",
        "'execution_authorized_by_catalog'=>false", "'mutation_performed'=>false",
        "function_exists('wp_get_abilities')",
    ):
        assert required in NATIVE, required

assert_native(ADAPTER)
assert_remote(REMOTE)
assert_native_source()
for altered in (
    ADAPTER.replace("'surface' => 'read'", "'surface' => 'write'"),
    ADAPTER.replace("MAD4B_SCP_Native_Ability_Trust::catalog( $input )", "return array()"),
    ADAPTER.replace("'mad4b/native-capability-registry'", "'mad4b/native-capability-write'"),
):
    assert altered != ADAPTER, "negative fixture did not mutate native registry"
    try:assert_native(altered)
    except (AssertionError, ValueError):continue
    raise AssertionError("unreviewed native discovery mutation was admitted")
for altered in (
    REMOTE.replace(
        "'remote_mode' => 'exact_staging_reviewed_stale_grant_retirement',\n"
        "\t\t\t\t'remote_caller_role' => 'operator',\n"
        "\t\t\t\t'production_policy' => 'deny',",
        "'remote_mode' => 'exact_staging_reviewed_stale_grant_retirement',\n"
        "\t\t\t\t'remote_caller_role' => 'operator',\n"
        "\t\t\t\t'production_policy' => 'allow',",
    ),
    REMOTE.replace("'remote_ability' => 'mad4b/staging-write-grant-reconcile-staging'",
                   "'remote_ability' => 'mad4b/staging-write-grant-reconcile'"),
):
    assert altered != REMOTE, "negative fixture did not mutate remote registry"
    try:assert_remote(altered)
    except (AssertionError, ValueError):continue
    raise AssertionError("unreviewed remote reconciliation authority mutation was admitted")
print("NATIVE_ADAPTER_REMOTE_GRANT_SOURCE: PASS (read-only discovery and exact staging alias; negatives)")
