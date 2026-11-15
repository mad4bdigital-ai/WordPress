#!/usr/bin/env python3
"""Fail-closed negative regression suite for dynamic capability review enforcement."""
import copy
import sys
import tempfile
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
import production_readiness_registry as reg

import json

controls = json.loads((ROOT / reg.CONTROLS).read_text(encoding="utf-8"))
policy = json.loads((ROOT / reg.COVERAGE).read_text(encoding="utf-8"))
# Synthetic introduction/retirement scenarios need an empty history fixture.
# Preserve the current policy guards and rules, but never inherit repository
# review records into a fictional aaaa/bbbb Git history. Historical retention
# is exercised separately below using the explicit covered/retired fixtures.
for history in ("reviewed_capabilities", "reviewed_changes", "lifecycle", "policy_migrations"):
    policy[history] = []
base, head = "a" * 40, "b" * 40
path = reg.PLUGIN + "includes/class-mad4b-scp-example-security.php"
cap = "ability:mad4b/dynamic-security-demo"
test_path = "tests/production_readiness_registry_contract.py"
changed = [("A", path), ("A", test_path)]
added = {cap: path}

# Regression: the global "wp-content" prefix is not a content feature.
# The classifier must consider only the actual plugin-relative behavior.
resilience_path = reg.PLUGIN + "includes/class-mad4b-scp-connector-resilience.php"
assert reg.classify(resilience_path, resilience_path, policy)["id"] == "platform"
assert reg.classify(resilience_path, "service:" + resilience_path, policy)["id"] == "platform"
provider_path = reg.PLUGIN + "includes/class-mad4b-scp-provider-transport.php"
assert reg.classify(provider_path, provider_path, policy)["id"] == "mcp"
content_path = reg.PLUGIN + "includes/class-mad4b-scp-content-create.php"
assert reg.classify(content_path, content_path, policy)["id"] == "content"

# Sensitive tenant identities and WP users must never inherit a soft P1 admin
# review merely because the source is a REST route or an admin UI. This rule
# classifies the resource family, not a fixed list of WordPress operations.
tenant_user = reg.PLUGIN + "includes/class-mad4b-scp-company-user-lifecycle.php"
identity = reg.classify(tenant_user, "rest:mad4b/v1/admin/company-team/users", policy)
assert identity["id"] == "tenant_identity" and identity["risk"] == "P0"
assert {"ADMIN-01", "SECURITY-01"} <= set(identity["controls"])
tenant_team = reg.PLUGIN + "includes/class-mad4b-scp-company-master-admin.php"
assert reg.classify(tenant_team, "rest:mad4b/v1/admin/company-team/create", policy)["risk"] == "P0"
assert reg.classify(reg.PLUGIN + "includes/class-mad4b-scp-admin-options.php",
    "rest:mad4b/v1/admin/settings-status", policy)["id"] == "admin"
assert not policy.get("production_promotion_from_ci") and policy["authorizing"] is False

# A concatenated Ability fragment cannot be certified as a full identity.
# The original runtime registration (not this heuristic) is separately tested.
dynamic_ability_source = """
final class Provider {
 public static function abilities(){
  wp_register_ability('mad4b/direct-read', array());
  foreach(array('inventory'=>false,'reconcile'=>true) as $name=>$write){
    $ability='mad4b/search-provider-autoprovision-'.str_replace('_','-',$name);
    wp_register_ability($ability, array());
  }
 }
}
"""
with mock.patch.object(reg, "git_text", return_value=dynamic_ability_source):
    dynamic_caps = reg.php_capabilities(head,
       reg.PLUGIN + "includes/search/class-mad4b-scp-search-provider-autoprovision.php")
    assert dynamic_caps == {"ability:mad4b/direct-read"}, dynamic_caps
assert reg.PHP_ABILITY_PREFIX.search(
    "$ability='mad4b/search-provider-autoprovision-'.str_replace('_','-',$name);")
assert not reg.PHP_ABILITY_PREFIX.search(
    "wp_register_ability('mad4b/static-ability', array());")


def initial_baseline(_commit, path):
    if path == reg.CONTROLS:
        return copy.deepcopy(controls)
    if path == reg.COVERAGE:
        return copy.deepcopy(policy)
    raise AssertionError("Unexpected baseline source: " + path)

with mock.patch.object(reg, "json_file", side_effect=initial_baseline), \
     mock.patch.object(reg, "current_blob_sha", return_value="c" * 40):
    failures, accepted, _ = reg.validate_coverage(
        copy.deepcopy(policy), controls, base, head, changed, added, {})
    assert any("reviewed_coverage_missing" in x["reasons"] for x in failures)
    assert not accepted

    covered = copy.deepcopy(policy)
    covered["reviewed_capabilities"] = [{
        "id": cap,
        "control_ids": ["SECURITY-01", "OAUTH-01"],
        "regression_tests": [test_path],
        "owner_role": "Security + Capability Owner",
        "review_rationale": "Explicit security audit regression and owner coverage for new ability.",
        "status": "reviewed_for_ci_only",
        "production_authorized": False,
        "live_evidence_required": True,
        "needs_new_control": False,
        "coverage_reuse_rationale": "These existing security and OAuth controls cover the new ability.",
    }]
    covered["reviewed_changes"] = [{
        "path": path, "source_blob_sha": "c" * 40,
        "control_ids": ["SECURITY-01", "OAUTH-01"],
        "regression_tests": [test_path],
        "owner_role": "Security Capability Owner",
        "review_rationale": "New source is reviewed with corresponding executable security regression.",
        "status": "reviewed_for_ci_only",
        "production_authorized": False,
        "live_evidence_required": True,
    }]
    failures, accepted, _ = reg.validate_coverage(
        covered, controls, base, head, changed, added, {})
    assert not failures and accepted == [cap]

    missing_guard = copy.deepcopy(covered)
    missing_guard["guarantees"].pop("control_metadata_history_is_sha_bound")
    try:
        reg.validate_coverage(missing_guard, controls, base, head, changed, added, {})
        raise AssertionError("Removing a required guard must fail")
    except ValueError as exc:
        assert "Required coverage guards" in str(exc)

    missing_growth = copy.deepcopy(covered)
    missing_growth["capability_growth_policy"].pop("require_new_control_ids_for_expansion")
    try:
        reg.validate_coverage(missing_growth, controls, base, head, changed, added, {})
        raise AssertionError("Removing a required growth rule must fail")
    except ValueError as exc:
        assert "growth policy" in str(exc)

    without_growth = copy.deepcopy(covered)
    without_growth["reviewed_capabilities"][0].pop("needs_new_control")
    try:
        reg.validate_coverage(without_growth, controls, base, head, changed, added, {})
        raise AssertionError("No explicit checklist-growth decision must be denied")
    except ValueError as exc:
        assert "checklist growth decision" in str(exc)

    without_source = copy.deepcopy(covered)
    without_source["reviewed_changes"] = []
    failures, _, _ = reg.validate_coverage(without_source, controls, base, head,
                                           changed, added, {})
    assert any("exact_source_change_review_missing" in x["reasons"] for x in failures)

    # Modifying a registered source without adding a new capability still
    # requires new tests bound to the exact source blob.
    failures, _, _ = reg.validate_coverage(copy.deepcopy(policy), controls, base,
                                           head, [("M", path)], {}, {})
    assert any("exact_source_change_review_missing" in x["reasons"] for x in failures)
    stale_blob = copy.deepcopy(covered)
    stale_blob["reviewed_changes"][0]["source_blob_sha"] = "d" * 40
    failures, _, _ = reg.validate_coverage(stale_blob, controls, base, head,
                                           changed, added, {})
    assert any("exact_source_change_review_missing" in x["reasons"] for x in failures)

    stale_test = copy.deepcopy(covered)
    stale_test["reviewed_capabilities"][0]["regression_tests"] = [
        "tests/unrelated-existing-test.py"
    ]
    failures, accepted, _ = reg.validate_coverage(
        stale_test, controls, base, head, changed, added, {})
    assert not accepted and any(
        "same_pr_capability_regression_missing" in gap["reasons"]
        for gap in failures), "Existing unmodified test must not certify new capability"

    control_mismatch = copy.deepcopy(covered)
    control_mismatch["reviewed_capabilities"][0]["control_ids"] = [
        "PLATFORM-01", "UPDATES-01"]
    failures, accepted, _ = reg.validate_coverage(
        control_mismatch, controls, base, head, changed, added, {})
    assert not accepted and any(
        "review_controls_do_not_cover_mapped_impact" in gap["reasons"]
        for gap in failures), "Wrong mapped controls must remain review-blocking"

    source_mismatch = copy.deepcopy(covered)
    source_mismatch["reviewed_changes"][0]["control_ids"] = [
        "PLATFORM-01", "UPDATES-01"]
    failures, accepted, behavior = reg.validate_coverage(
        source_mismatch, controls, base, head, changed, added, {})
    assert not behavior and any(
        "review_controls_do_not_cover_mapped_impact" in gap["reasons"]
        for gap in failures), "Wrong source controls must never count as reviewed behavior"

    stale_source_test = copy.deepcopy(covered)
    stale_source_test["reviewed_changes"][0]["regression_tests"] = [
        "tests/unrelated-existing-test.py"]
    failures, accepted, behavior = reg.validate_coverage(
        stale_source_test, controls, base, head, changed, added, {})
    assert not behavior and any(
        "same_pr_behavior_regression_missing" in gap["reasons"]
        for gap in failures), "Unchanged test must never certify new source behavior"

    forged = copy.deepcopy(covered)
    forged["reviewed_capabilities"][0]["production_authorized"] = True
    try:
        reg.validate_coverage(forged, controls, base, head, changed, added, {})
        raise AssertionError("Source coverage cannot authorize production")
    except ValueError as exc:
        assert "Untrusted capability review status" in str(exc)

    gone = {cap: path}
    retirement_changes = [("D", path), ("A", test_path)]
    failures, _, _ = reg.validate_coverage(
        copy.deepcopy(policy), controls, base, head, retirement_changes, {}, gone)
    assert failures and "decommission_or_supersession_review_missing" in failures[0]["reasons"]

    retired = copy.deepcopy(policy)
    retired["lifecycle"] = [{
        "id": cap, "state": "superseded",
        "replacement": "ability:mad4b/replacement",
        "rationale": "Explicit migration from prior approved capability to replacement.",
        "production_authorized": False,
    }]
    failures, accepted, _ = reg.validate_coverage(
        retired, controls, base, head, retirement_changes, {}, gone)
    assert not failures and not accepted

    controls_missing = copy.deepcopy(controls)
    controls_missing["controls"] = [
        c for c in controls_missing["controls"] if c["id"] != "GOVERNANCE-01"
    ]
    try:
        reg.validate_coverage(policy, controls_missing, base, head, changed, {}, {})
        raise AssertionError("Deleting a historical audit control must fail")
    except ValueError as exc:
        assert "historical control was deleted" in str(exc).lower()

# A reviewed capability persists in Git after its introduction PR has merged.
# Its unchanged historic review must not fail the next unrelated PR.
def previous_review(_commit, path):
    if path == reg.CONTROLS:
        return copy.deepcopy(controls)
    if path == reg.COVERAGE:
        return copy.deepcopy(covered)
    raise AssertionError("Unexpected base catalog: " + path)

with mock.patch.object(reg, "json_file", side_effect=previous_review):
    failures, accepted, _ = reg.validate_coverage(
        copy.deepcopy(covered), controls, base, head,
        [("M", "docs/OTHER.md")], {}, {})
    assert not failures and not accepted, "Historical coverage survives later PRs"

    try:
        reg.validate_coverage(copy.deepcopy(policy), controls, base, head,
                              [("M", "docs/OTHER.md")], {}, {})
        raise AssertionError("Deleting historical reviewed coverage must fail")
    except ValueError as exc:
        assert "Previously reviewed capability history" in str(exc)

    narrower = copy.deepcopy(covered)
    narrower["reviewed_capabilities"][0]["control_ids"] = ["SECURITY-01"]
    try:
        reg.validate_coverage(narrower, controls, base, head,
                              [("M", "docs/OTHER.md")], {}, {})
        raise AssertionError("Old controls must not disappear from reviewed coverage")
    except ValueError as exc:
        assert "review coverage cannot shrink" in str(exc)

    downgrade = copy.deepcopy(controls)
    next(x for x in downgrade["controls"] if x["id"] == "GOVERNANCE-01")["priority"] = "P1"
    try:
        reg.validate_coverage(copy.deepcopy(covered), downgrade, base, head,
                              [("M", "docs/OTHER.md")], {}, {})
        raise AssertionError("Old P0 must not silently become P1")
    except ValueError as exc:
        assert "silently downgraded" in str(exc)

    weakened = copy.deepcopy(covered)
    weakened["domain_rules"][0]["pattern"] = "^$"
    try:
        reg.validate_coverage(weakened, controls, base, head,
                              [("M", "docs/OTHER.md")], {}, {})
        raise AssertionError("Weakening an inherited impact mapping must fail")
    except ValueError as exc:
        assert ("impact-mapping rules" in str(exc) or
                "Impact classification contract drift" in str(exc))

def previous_retirement(_commit, path):
    if path == reg.CONTROLS:
        return copy.deepcopy(controls)
    if path == reg.COVERAGE:
        return copy.deepcopy(retired)
    raise AssertionError("Unexpected base catalog: " + path)

with mock.patch.object(reg, "json_file", side_effect=previous_retirement):
    try:
        reg.validate_coverage(copy.deepcopy(policy), controls, base, head,
                              [("M", "docs/OTHER.md")], {}, {})
        raise AssertionError("Losing a historical retirement record must fail")
    except ValueError as exc:
        assert "historical decommission" in str(exc).lower()

    changed_retirement = copy.deepcopy(retired)
    changed_retirement["lifecycle"][0]["state"] = "deprecated"
    try:
        reg.validate_coverage(changed_retirement, controls, base, head,
                              [("M", "docs/OTHER.md")], {}, {})
        raise AssertionError("An old decommission record cannot be rewritten")
    except ValueError as exc:
        assert "decommission evidence cannot be silently changed" in str(exc)

# Static interface discovery broadens coverage without claiming runtime proof.
interface_source = """<?php
register_rest_route('mad4b/v1', '/exports', array());
add_action('wp_ajax_mad4b_export', 'callback');
wp_schedule_event(time(), 'hourly', 'mad4b_report_cron');
"""
with mock.patch.object(reg, "git_text", return_value=interface_source):
    names = reg.registered_surfaces(head, path)
    assert "rest:mad4b/v1/exports" in names
    assert "ajax:wp_ajax_mad4b_export" in names
    assert "cron:mad4b_report_cron" in names


# A generated Ability declared in a trait must be covered even when its
# eventual WordPress registration is performed by its owning Adapter.
trait_source = """<?php
trait MAD4B_Example_Definitions {
    public static function ability_definitions() {
        return array(array('name' => 'mad4b/new-trait-backed-ability'));
    }
}
"""
with mock.patch.object(reg, "git_text", return_value=trait_source):
    ids = reg.php_capabilities(head, reg.PLUGIN + "includes/trait-example.php")
    assert "ability:mad4b/new-trait-backed-ability" in ids
with mock.patch.object(reg, "git_text", return_value="<?php // 'mad4b/comment-only'"):
    assert not reg.php_capabilities(head, reg.PLUGIN + "includes/comment-only.php")

# A registered REST namespace must never become a fictional WP Ability
# merely because the same class also declares an ability inventory. A real
# ability name elsewhere in that source must still be detected.
mixed_source = """<?php
class MAD4B_Example_Registration {
    public static function register() {
        register_rest_route('mad4b/v1', '/agent-grants/status', array());
        wp_register_ability('mad4b/genuine-ability', array());
    }
}
"""
with mock.patch.object(reg, "git_text", return_value=mixed_source):
    discovered = reg.php_capabilities(head, reg.PLUGIN + "includes/class-mad4b-scp-agent-grant-lifecycle.php")
    assert "ability:mad4b/v1" not in discovered
    assert "ability:mad4b/genuine-ability" in discovered
    assert "rest:mad4b/v1/agent-grants/status" in reg.registered_surfaces(
        head, reg.PLUGIN + "includes/class-mad4b-scp-agent-grant-lifecycle.php")

# The REST route alone is not an Ability, even if unrelated ability definitions
# trigger the conservative static scanner in the same file.
namespace_only = """<?php
class MAD4B_Example_Registration {
    public static function ability_definitions() { return array(); }
    public static function register() {
        register_rest_route("mad4b/v1", '/agent-grants/status', array());
    }
}
"""
with mock.patch.object(reg, "git_text", return_value=namespace_only):
    assert not reg.php_capabilities(head, reg.PLUGIN + "includes/class-mad4b-scp-agent-grant-lifecycle.php")

# A controlled migration allows a reviewed rule correction without mutating
# history or weakening its P0/linked controls.
old_rule = copy.deepcopy(policy["domain_rules"][0])
updated_rule = copy.deepcopy(old_rule)
updated_rule["pattern"] = old_rule["pattern"] + "|additional-governed-capability"
migrated = copy.deepcopy(policy)
migrated["domain_rules"][0] = updated_rule
def digest(rule):
    return reg.hashlib.sha256(
        reg.json.dumps(rule, sort_keys=True, separators=(",", ":")).encode()
    ).hexdigest()
migrated["policy_migrations"] = [{
    "id": "impact-rule-migration-001",
    "rule_id": old_rule["id"],
    "from_sha256": digest(old_rule),
    "to_sha256": digest(updated_rule),
    "status": "reviewed_for_ci_only",
    "production_authorized": False,
    "live_evidence_required": True,
    "owner_role": "Security and Repository Governance",
    "review_rationale": "Expand classified input while preserving historic P0 risk and all controls with a regression test.",
    "regression_tests": [test_path],
}]
def old_policy(_commit, path):
    if path == reg.CONTROLS:
        return copy.deepcopy(controls)
    if path == reg.COVERAGE:
        return copy.deepcopy(policy)
    raise AssertionError("Unexpected old policy: " + path)
with mock.patch.object(reg, "json_file", side_effect=old_policy):
    failures, accepted, _ = reg.validate_coverage(
        migrated, controls, base, head, [("M", test_path)], {}, {})
    assert not failures and not accepted, "Reviewed migration should pass"
    unsafe = copy.deepcopy(migrated)
    unsafe["domain_rules"][0]["controls"] = old_rule["controls"][:-1]
    unsafe["policy_migrations"][0]["to_sha256"] = digest(unsafe["domain_rules"][0])
    try:
        reg.validate_coverage(unsafe, controls, base, head, [("M", test_path)], {}, {})
        raise AssertionError("Removing historic covered controls must fail")
    except ValueError as exc:
        assert "remove linked controls" in str(exc)

# Protect the scanner scope against mutable policy bypasses.
for field, value in (
    ("runtime_root", "wp-content/plugins/no-longer-used/"),
    ("included_subtrees", []),
    ("covered_extensions", [".php"]),
    ("root_php", False),
    ("excluded_subtrees", ["includes/", "tests/"]),
):
    weakened = copy.deepcopy(policy)
    weakened["code_change_rules"][field] = value
    with mock.patch.object(reg, "json_file", side_effect=initial_baseline):
        try:
            reg.validate_coverage(weakened, controls, base, head, [], {}, {})
        except ValueError as exc:
            assert "Protected runtime coverage scope" in str(exc)
        else:
            raise AssertionError("Scope bypass succeeded: " + field)

# Existing update risk must not capture the generic wp-content/plugins folder.
for suffix, expected in (
    ("includes/class-mad4b-scp-admin-options.php", "admin"),
    ("includes/class-mad4b-scp-database-schema.php", "data"),
    ("includes/class-mad4b-scp-mcp-hybrid.php", "mcp"),
    ("includes/class-mad4b-scp-authentication.php", "authority"),
    ("assets/js/admin-ui.js", "admin"),
):
    assert reg.classify(reg.PLUGIN + suffix, reg.PLUGIN + suffix, policy)["id"] == expected

shadow = copy.deepcopy(policy)
shadow["domain_rules"].insert(0, {
    "id": "shadow-all", "pattern": ".*", "risk": "P1",
    "controls": ["ADMIN-01", "BROWSER-01"],
})
with mock.patch.object(reg, "json_file", side_effect=initial_baseline):
    try:
        reg.validate_coverage(shadow, controls, base, head, [], {}, {})
    except ValueError as exc:
        assert ("Unbounded new rule" in str(exc) or
                "Impact classification contract drift" in str(exc))
    else:
        raise AssertionError("Unbounded precedence override allowed")



# The complete gap artifact is generated even when coverage must fail.
# It never represents independent Owner approval or a Production decision.
with tempfile.TemporaryDirectory(prefix='mad4b-readiness-gaps-') as temp:
    sample = {
        'contract': 'mad4b.dynamic-readiness-capability-impact.v1',
        'source_base_sha': base, 'exact_head_sha': head,
        'authorizing': False, 'production_authorized': False,
        'decision': 'FAIL_UNCOVERED_CHANGES',
        'counts': {'coverage_gaps': 1},
        'coverage_gaps': [{
            'id': cap, 'source': path, 'risk': 'P0',
            'controls': ['SECURITY-01'],
            'reasons': ['reviewed_coverage_missing'],
        }],
        'coverage_gap_reasons': {'reviewed_coverage_missing': 1},
        'introduced': [], 'retired': [], 'impact': [],
    }
    with mock.patch.object(reg, 'json_file', return_value=controls):
        reg.emit(sample, temp)
    artifact = json.loads((Path(temp) / 'capability-coverage-gaps.json').read_text())
    assert artifact['contract'] == 'mad4b.readiness-uncovered-changes.v1'
    assert artifact['exact_head_sha'] == head
    assert artifact['uncovered_count'] == 1 and len(artifact['items']) == 1
    assert not artifact['authorizing'] and not artifact['production_authorized']
    assert not artifact['independent_owner_approval_present']
    assert artifact['decision'] == 'FAIL_UNCOVERED_CHANGES'
    assert 'reviewed_coverage_missing' in (Path(temp) / 'capability-impact.md').read_text()


# Source-proofed original Ability maps and non-authorizing event names.
# Never demand a newly introduced WordPress Ability review for an Audit
# event or a pointer to an already-existing registered Ability. Still review
# changed source behavior and genuinely added registrations.
definition_map = """<?php
final class Original {
 const SOURCE_PREFLIGHT_ABILITY='mad4b/context-source-onboarding-preflight';
 function register_abilities(){
   $definitions=array(
     self::SOURCE_PREFLIGHT_ABILITY => array('callback'=>'plan')
   );
   foreach ($definitions as $ability => $definition) {
     if(wp_has_ability($ability))continue;
     wp_register_ability($ability, array('category'=>'mad4b-read'));
   }
 }
}"""
assert reg.registered_constant_map_owner(definition_map, "SOURCE_PREFLIGHT_ABILITY")
for negative in (
    definition_map.replace("wp_register_ability($ability, array('category'=>'mad4b-read'));",
                           "audit_record($ability);"),
    definition_map.replace("foreach ($definitions as $ability => $definition)",
                           "foreach ($foreign as $ability => $definition)"),
    definition_map.replace("self::SOURCE_PREFLIGHT_ABILITY =>", "self::SOME_OTHER_ABILITY =>"),
):
    assert not reg.registered_constant_map_owner(negative, "SOURCE_PREFLIGHT_ABILITY")

# This genuine ability is registered in a previously existing source tree.
# The current Context Authority only references its name as a next-step link.
owner_id="ability:mad4b/context-source-onboarding-preflight"
owner=reg.registered_ability_owner(reg.git("rev-parse","HEAD").stdout.decode().strip(),owner_id)
assert owner and owner.endswith("/class-mad4b-scp-brand-core-remote-recovery.php")
sample = """<?php
class Owner {
 function register_abilities(){
  wp_register_ability('mad4b/real-ability',array());
  self::audited_registry_result(
    array('nested'=>array(1,2), 'row'=>array('id'=>3)),
    'mad4b/context-existing-folder-settings',
    array('source_id'=>'x')
  );
  MAD4B_SCP_Audit::record('mad4b/context-foreign-recycle-archive-cleanup',
    array('resource'=>array(1,2)));
 }
}"""
masked=reg.mask_audit_event_literals(sample)
assert "'mad4b/real-ability'" in masked
assert "'mad4b/context-existing-folder-settings'" not in masked
assert "'mad4b/context-foreign-recycle-archive-cleanup'" not in masked
with mock.patch.object(reg, "git_text", return_value=sample):
    ids=reg.php_capabilities(head,reg.PLUGIN+"includes/class-mad4b-scp-context-authority.php")
    assert ids == {"ability:mad4b/real-ability"}, ids
for not_a_registration in (
    "<?php function register_abilities() { self::audited_registry_result(array(),"
    "'mad4b/not-a-registration', array()); }",
    "<?php function register_abilities() { MAD4B_SCP_Audit::record("
    "'mad4b/another-non-ability', array()); }",
):
    with mock.patch.object(reg, "git_text", return_value=not_a_registration):
        ids=reg.php_capabilities(head,reg.PLUGIN+"includes/class-mad4b-scp-foreign-recovery.php")
        assert not ids, ids

# Same literal outside the audited argument must remain in the inventory.
actual_registration=sample.replace(
    "self::audited_registry_result(", "wp_register_ability('mad4b/new-actual-ability',array());"
    "self::audited_registry_result("
)
with mock.patch.object(reg,"git_text",return_value=actual_registration):
    ids=reg.php_capabilities(head,reg.PLUGIN+"includes/class-mad4b-scp-context-authority.php")
    assert "ability:mad4b/new-actual-ability" in ids
print("PASS: READINESS_SOURCE_EVENT_VS_ABILITY 10 proof and denial cases")

print("DYNAMIC_READINESS_NEGATIVE_REGRESSIONS: PASS")
