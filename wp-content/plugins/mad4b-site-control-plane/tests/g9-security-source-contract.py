#!/usr/bin/env python3
"""G9 invariant smoke contract, intentionally additive to native PHP runtime CI.

A string-based source check is not a cryptographic attestation, a runtime test,
a provider acceptance certificate, or proof of a distributed lock.
"""
import ast
from pathlib import Path
import re

# parents[0]=tests; [1]=plugin; [2]=plugins; [3]=wp-content; [4]=repository root
ROOT = Path(__file__).resolve().parents[4]
assert (ROOT / "wp-content/plugins/mad4b-site-control-plane").is_dir(), "G9_REPOSITORY_ROOT_UNRESOLVED"
BASE = ROOT / "wp-content/plugins/mad4b-site-control-plane"
INCLUDES = BASE / "includes"


def source(name: str) -> str:
    file = INCLUDES / name
    assert file.is_file() and not file.is_symlink(), "MISSING_G9_FILE:" + name
    return file.read_text(encoding="utf-8")


def expect(text: str, markers: tuple[str, ...], context: str) -> None:
    for marker in markers:
        assert marker in text, "MISSING_G9_GUARD:" + context + ":" + marker

WORKFLOW = ".github/workflows/feature-007-spec-ci.yml"
WORKFLOW_JOB = "g9-resilience"


def workflow_require(condition: bool, reason: str) -> None:
    if not condition:
        raise AssertionError("G9_WORKFLOW_" + reason)


def workflow_section(text: str, key: str, indent: int, value: str = "") -> str:
    """Read one indentation-bounded block in the repository's reviewed YAML style.

    Ambiguous aliases, folded scripts or job text inside shell blocks fail closed.
    """
    lines = text.splitlines()
    header = " " * indent + key + ":" + (" " + value if value else "")
    matches = [index for index, line in enumerate(lines) if line.rstrip() == header]
    workflow_require(len(matches) == 1, "SECTION_INVALID:" + key)
    start = matches[0] + 1
    end = len(lines)
    for index in range(start, len(lines)):
        line = lines[index]
        if line.strip() and not line.lstrip().startswith("#"):
            if len(line) - len(line.lstrip(" ")) <= indent:
                end = index
                break
    return "\n".join(lines[start:end])


def workflow_scalar(text: str, key: str, indent: int, required: bool = True):
    values = re.findall(r"^" + " " * indent + re.escape(key) + r":[ \t]*(.*)$", text, re.MULTILINE)
    workflow_require(len(values) == 1 if required else len(values) <= 1, "FIELD_INVALID:" + key)
    return values[0].strip() if values else None


def verify_g9_workflow(workflow_text: str = None) -> None:
    """Require the native G9 checks in the allowed shared workflow's own job."""
    if workflow_text is None:
        file = ROOT / WORKFLOW
        workflow_require(file.is_file() and not file.is_symlink(), "UNAVAILABLE")
        workflow_text = file.read_text(encoding="utf-8")
    job = workflow_section(workflow_section(workflow_text, "jobs", 0), WORKFLOW_JOB, 2)
    workflow_require(workflow_scalar(job, "if", 4, False) is None
                     and workflow_scalar(job, "continue-on-error", 4, False) is None, "JOB_CAN_BE_SKIPPED")
    strategy = workflow_section(job, "strategy", 4)
    workflow_require(workflow_scalar(strategy, "fail-fast", 6) == "false", "FAIL_FAST_DRIFT")
    matrix = workflow_section(strategy, "matrix", 6)
    versions = ast.literal_eval(workflow_scalar(matrix, "php", 8))
    workflow_require(versions == ["7.4", "8.3"], "PHP_MATRIX_DRIFT")
    matrix_lines = [line for line in matrix.splitlines() if line.strip() and not line.lstrip().startswith("#")]
    workflow_require(len(matrix_lines) == 1, "MATRIX_FILTER_OR_EXTRA_AXIS")

    steps_text = workflow_section(job, "steps", 4)
    starts = [match.start() for match in re.finditer(r"^      - ", steps_text, re.MULTILINE)]
    workflow_require(bool(starts), "STEPS_MISSING")
    steps = [steps_text[start:end] for start, end in zip(starts, starts[1:] + [len(steps_text)])]
    steps = [step.replace("      - ", "        ", 1) for step in steps]
    scripts = []
    for step in steps:
        workflow_require(workflow_scalar(step, "if", 8, False) is None
                         and workflow_scalar(step, "continue-on-error", 8, False) is None
                         and workflow_scalar(step, "working-directory", 8, False) is None,
                         "STEP_CAN_BE_SKIPPED_OR_RELOCATED")
        shell = workflow_scalar(step, "shell", 8, False)
        workflow_require(shell in (None, "bash"), "SHELL_DRIFT")
        run = workflow_scalar(step, "run", 8, False)
        if run in ("|", "|-"):
            body = workflow_section(step, "run", 8, run)
            scripts.append([line.strip() for line in body.splitlines()
                            if line.strip() and not line.lstrip().startswith("#")])
        else:
            scripts.append([run] if run is not None else None)

    def unique_step(predicate, label):
        indexes = [index for index, step in enumerate(steps) if predicate(step)]
        workflow_require(len(indexes) == 1, label)
        return indexes[0]

    head = "${{ github.event.pull_request.head.sha || github.sha }}"
    checkout = unique_step(lambda step: workflow_scalar(step, "uses", 8, False) == "actions/checkout@v4", "CHECKOUT_MISSING")
    workflow_require(workflow_scalar(workflow_section(steps[checkout], "with", 8), "ref", 10) == head, "CHECKOUT_HEAD_DRIFT")
    setup = unique_step(lambda step: workflow_scalar(step, "uses", 8, False) == "shivammathur/setup-php@v2", "PHP_SETUP_MISSING")
    setup_options = workflow_section(steps[setup], "with", 8)
    workflow_require(workflow_scalar(setup_options, "php-version", 10) == "${{ matrix.php }}"
                     and workflow_scalar(setup_options, "tools", 10) == "none", "PHP_SETUP_DRIFT")
    assertion = ['test "$(git rev-parse HEAD)" = "' + head + '"']
    lint = [
        "set -euo pipefail",
        'for f in wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-resilience-{context,anchor}.php wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-g9-*.php wp-content/plugins/mad4b-site-control-plane/tests/g9-*.php; do php -l "$f" || exit 1; done',
    ]
    prefix = "wp-content/plugins/mad4b-site-control-plane/tests/"
    runtime = ["set -euo pipefail"] + ["php " + prefix + name for name in (
        "g9-resilience-gates-runtime.php", "g9-resilience-state-runtime.php",
        "g9-fleet-rollout-runtime.php", "g9-boot-runtime.php",
        "g9-ability-collision-runtime.php", "g9-partial-ability-registration-runtime.php",
        "g9-partial-ability-registration-runtime.php null",
        "g9-partial-ability-registration-runtime.php pretend",
    )]
    required_scripts = [assertion, lint, ["python3 " + prefix + "g9-delivery-contract.py"],
                        ["python3 " + prefix + "g9-security-source-contract.py"], runtime]
    positions = []
    for expected in required_scripts:
        matches = [index for index, script in enumerate(scripts) if script == expected]
        workflow_require(len(matches) == 1, "NATIVE_COMMANDS_OR_HEAD_ASSERTION_DRIFT")
        positions.append(matches[0])
    workflow_require(checkout < positions[0] < setup < positions[1]
                     and positions == sorted(positions), "STEP_ORDER_DRIFT")
def check_source_invariants() -> None:
    context = source("class-mad4b-scp-resilience-context.php")
    release = source("class-mad4b-scp-g9-release-fence.php")
    anchor = source("class-mad4b-scp-resilience-anchor.php")
    gates = source("class-mad4b-scp-g9-resilience-gates.php")
    local = source("class-mad4b-scp-g9-local-reader.php")
    restore = source("class-mad4b-scp-g9-restore-convergence.php")
    read = source("class-mad4b-scp-g9-read-surface.php")
    closure = source("class-mad4b-scp-g9-operational-readiness.php")
    fleet = source("class-mad4b-scp-g9-fleet-rollout.php")

    expect(release, (
        "MAD4B_SCP_G9_RELEASE_FENCE_ENABLED",
        "MAD4B_SCP_G9_RELEASE_LIMITS",
        "wider_ring_not_certified",
        "current_environment() !== 'staging'",
        "grants_changed_before_cas",
        "policy_changed_before_cas",
        "true !== ( $ready['ready'] ?? null )",
        "true !== ( $fresh['ready'] ?? null )",
        "true !== ( $generation['ready'] ?? null )",
        "true !== ( $epoch['ready'] ?? null )",
        "is_hash( $plan['preview_sha256'] ?? null )",
        "candidate_binding_match",
        "grant_rows_fingerprint",
        "policy_decision_sha256",
        "approval_impact_binding_sha256",
        "mad4b/runtime-release-set-apply",
        "native_execution_uncertain",
        "native_link_unavailable",
        "native_evidence_changed",
        "if ( array_key_exists( 'execution_receipt_sha256', $link ) )",
        "'terminal_receipt_sha256' => $receipt['terminal_receipt_sha256']",
        "mad4b.g9.native-release-link.v1",
        "native_request_id",
        "native_target_fingerprint",
        "MAD4B_SCP_Operation_Journal::trace",
        "g9_plan_sha256",
        "site_binding_sha256",
        "'operation_journal'",
        "'evidence_type'",
        "hash( 'sha256', $operation_id )",
        "build_provenance_status",
        "runtime_manifest_match",
        "package_changed_before_cas",
        "generation_changed_before_cas",
        "restore_changed_before_cas",
        "journal_head_sha256",
        "latest_sequence",
        "native_signature_invalid",
        "replay_or_capacity",
        "external_record_sha256",
    ), "release")
    assert "wp_register_ability" not in release, "RELEASE_MUTATION_ABILITY_EXPOSED"
    expect(context, (
        "'current_grant_snapshot_ready'",
        "'candidate_binding_match'",
        "self::is_hash( $authority['grant_rows_fingerprint']",
        "self::assert_observation_identity_current( $binding, $generation, $restore, $identity )",
        "observation_identity_changed",
        "MAD4B_SCP_Restore_Epoch::status( false, true )",
    ), "passive-grant-readiness")
    assert context.index("self::$reader->read_local( $binding )") < context.index("::current_execution_readiness()") < context.index("$current = self::assert_observation_identity_current("), "STALE_PRE_OBSERVATION_AUTHORITY"
    assert context.count("::current_execution_readiness()") == 1, "DUPLICATE_OBSERVATION_GRANT_READ"
    expect(anchor, (
        "mirror_missing",
        "mirror_anchor_mismatch",
        "'anchor_revision' => $next['revision']",
        "'anchor_sha256' => $next['anchor_sha256']",
        "directory_permissions_unsafe",
        "local_blog_mismatch",
        "local_site_mismatch",
        "mirror_lost_after_commit",
        "mirror_identity_mismatch",
        "directory_permissions_unsafe",
        "local_site_mismatch",
        "local_blog_mismatch",
        "history_truncation",
        "revision_exhausted",
        "clearstatcache( true, $dir )",
        "clock_rollback",
        "readback_failed",
        "mirror_lost_after_commit",
    ), "anchor")
    assert anchor.index("Could not persist external-anchor loss marker before") < anchor.index("@rename( $tmp, $path )"), "UNSAFE_EXTERNAL_MARKER_ORDER"
    expect(gates, (
        "distributed_fence_unavailable", "single_host_exclusive_verified",
        "inventory_incomplete", "observation_stale", "health_stale",
        "cloned_site_uuid", "cloned_origin", "external_effect_uncertain",
        "production_ring_denied",
        "true !== ( $provider['ready'] ?? null )",
        "false !== ( $provider['revoked'] ?? null )",
        "true !== ( $observation['host']['local_readback_verified'] ?? null )",
    ), "gates")
    expect(local, ("single_host_exclusive_verified' => false", "provider_inventory_complete' => false"), "passive-reader")
    expect(restore, (
        "'external_effects_verified' => false",
        "'requires_quarantine' => true",
        "'post_restore_acceptance_issued' => false",
    ), "restore")
    expect(read, (
        "private static $reader_pinned = false",
        "private static $boot_result = null",
        "private static $abilities_registered = false",
        "'mad4b_g9_ability_namespace_collision'",
        "'mad4b_g9_reader_not_pinned'",
        "'mad4b_g9_ability_runtime_unavailable'",
        "$abilities = array(",
        "$provider_verified",
        "$effects_verified",
        "$health_verified",
        "false !== ( $provider['revoked'] ?? null )",
        "if ( ! self::$reader_pinned",
        "self::$reader_pinned = true",
        "mad4b/g9-site-observation",
        "mad4b/g9-restore-status",
        "mad4b/g9-closure-status",
        "'permission_callback' => array( __CLASS__, 'can_read_ability' )",
        "public static function can_read_ability(",
        "MAD4B_SCP_Policy::can_read()",
        "private static function read_policy()",
        "'mad4b_g9_read_permission_denied'",
        "$policy = self::read_policy()",
        "wp_has_ability( $ability )",
        "! wp_has_ability( $ability )",
    ), "read-only-abilities")
    assert "mad4b/g9-release-reserve" not in read, "RESERVE_EXPOSED_AS_READ_ABILITY"
    assert (BASE / "tests/g9-ability-collision-runtime.php").is_file(), "G9_NAMESPACE_COLLISION_TEST_MISSING"
    assert (BASE / "tests/g9-partial-ability-registration-runtime.php").is_file(), "G9_PARTIAL_ABILITY_TEST_MISSING"
    expect(closure, ("native_executor_g9_reservation_binding_unimplemented",
                     "g9_reservation_host_feature_disabled",
                     "g9_host_release_threshold_policy_missing"), "native-admission-blocker")
    expect(closure, ("'operationally_closed' => false", "'ready_for_production' => false"), "closure")
    expect(closure, (
        "'external_fence_unavailable'",
        "'anchor_observation_valid'",
        "'anchor_error_code'",
        "'certified_provider_unready'",
        "false !== ( $provider['revoked'] ?? null )",
        "'external_effect_unreconciled'",
        "'current_health_window_unverified'",
        "'blind_retry_allowed' => false",
    ), "diagnostic-denial")
    expect(fleet, (
        "'cohort_promotion_allowed'=>false",
        "'automatic_rollback_allowed'=>false",
        "'rollback_risk_detected'",
        "'reported_rollback_completed_sites'",
        "'partial_rollback_observed'",
        "$last_operation_state",
        "'PREPARED'",
    ), "fleet")
    print("G9 SOURCE INVARIANTS: PASS (non-authorizing gate markers only; runtime CI still required)")


def check_workflow_denials() -> None:
    text = (ROOT / WORKFLOW).read_text(encoding="utf-8")
    job = workflow_section(workflow_section(text, "jobs", 0), WORKFLOW_JOB, 2)
    prefix = "wp-content/plugins/mad4b-site-control-plane/tests/"
    head = "${{ github.event.pull_request.head.sha || github.sha }}"
    fixture = "          php " + prefix + "g9-resilience-state-runtime.php"
    mutations = (
        ("matrix", job.replace("php: ['7.4', '8.3']", "php: ['8.3']")),
        ("checkout", job.replace("ref: " + head, "ref: ${{ github.ref }}")),
        ("head assertion", job.replace('run: test "$(git rev-parse HEAD)"', 'run: echo "$(git rev-parse HEAD)"')),
        ("job skip", "    if: false\n" + job),
        ("job error tolerance", "    continue-on-error: true\n" + job),
        ("step skip", job.replace("      - name: Lint G9 runtime", "      - if: false\n        name: Lint G9 runtime")),
        ("step error tolerance", job.replace("      - name: Lint G9 runtime", "      - continue-on-error: true\n        name: Lint G9 runtime")),
        ("fixture omission", job.replace(fixture, "")),
        ("comment as fixture", job.replace(fixture, "          # " + fixture.strip())),
        ("masked fixture failure", job.replace(fixture, fixture + " || true")),
        ("missing strict shell", job.replace("          set -euo pipefail\n", "")),
        ("delivery contract omission", job.replace("python3 " + prefix + "g9-delivery-contract.py", "echo skipped")),
        ("security contract omission", job.replace("python3 " + prefix + "g9-security-source-contract.py", "echo skipped")),
    )
    for name, changed in mutations:
        workflow_require(changed != job, "DENIAL_FIXTURE_DRIFT:" + name)
        candidate = text.replace(job, changed, 1)
        try:
            verify_g9_workflow(candidate)
        except (AssertionError, ValueError, SyntaxError):
            continue
        raise AssertionError("G9_WORKFLOW_DENIAL_NOT_ENFORCED:" + name)
    candidates = (
        text.replace(job, job.replace(fixture, ""), 1)
        + "\n  sibling-job:\n    runs-on: ubuntu-latest\n    steps:\n      - run: |\n" + fixture + "\n",
        text + "\n  " + WORKFLOW_JOB + ":\n" + job + "\n",
    )
    for candidate in candidates:
        try:
            verify_g9_workflow(candidate)
        except (AssertionError, ValueError, SyntaxError):
            continue
        raise AssertionError("G9_WORKFLOW_SIBLING_OR_DUPLICATE_JOB_ACCEPTED")
    print("G9 WORKFLOW DENIALS: PASS (15 mutations; commands in shared sibling jobs cannot substitute)")


if __name__ == "__main__":
    verify_g9_workflow()
    check_workflow_denials()
    check_source_invariants()
