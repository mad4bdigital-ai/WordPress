#!/usr/bin/env python3
"""Executable, fail-closed CI-only source review for PR #744 ContentJobs.

No site writes, no production certification, no human/owner attestation.
"""
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"
INC = PLUGIN / "includes"

# Independently inspect source-owned scopes and safety predicates, not arbitrary
# reviewer labels. Guards are checked on all exact source candidates.
REVIEW_MARKERS = {
    "class-mad4b-scp-capability-transport-routing.php":
        ("function choose", "function rest_write_evidence", "site_uuid", "can_mutate"),
    "class-mad4b-scp-central-write-acceptance.php":
        ("function verify", "independent_verification", "rights_pass", "idempotency", "hash_equals", "function readback"),
    "class-mad4b-scp-content-job-groups.php":
        ("MAX_CHILDREN=25", "expected_snapshot_sha256", "group_apply_enabled", "hash_equals", "current_editorial_receipt"),
    "class-mad4b-scp-content-job-structural-qa.php":
        ("editorial_review_completed", "function attest", "site_uuid", "current_user_can"),
    "class-mad4b-scp-content-jobs.php":
        ("mad4b/content-job-group-apply", "mad4b/content-job-structural-cwa-apply", "mad4b_content_job_authorized_actor_required", "MAD4B_SCP_Operational_Integrity::assert_unchanged", "do_action"),
    "class-mad4b-scp-content-readiness-alerts.php":
        ("function rules", "get_post_types", "origin_source_fields", "schedule_submenu", "requires_governed_content_job_admission"),
    "class-mad4b-scp-content-relationship-graph.php":
        ("function resolve", "function planJob", "site_uuid", "target_id"),
    "class-mad4b-scp-content-relationship-providers.php":
        ("function inspect", "function nativeMeta", "function fields", "function templates"),
    "class-mad4b-scp-content-relationship-writeback-media.php":
        ("function authorize", "function readback", "Central_Write_Acceptance", "current_user_can"),
    "class-mad4b-scp-content-relationship-writeback-owner.php":
        ("function certificateValid", "function authorize", "function readback", "current_user_can"),
    "class-mad4b-scp-content-relationship-writeback.php":
        ("function plan", "function apply", "Central_Write_Acceptance", "hash_equals"),
    "class-mad4b-scp-content-workflow-modes.php":
        ("function requested", "human_review_required", "'can_publish' => false", "'publication_authorized' => false"),
    "class-mad4b-scp-contentjob-structural-cwa.php":
        ("function facts", "function readback", "wp_get_environment_type()!=='staging'", "MAD4B_SCP_Site_Profile::site_uuid()"),
    "class-mad4b-scp-dfsb-environment-policy.php":
        ("function localPackageApproved", "hash_equals", "schedule_submenu", "manage_options"),
    "class-mad4b-scp-readiness-workflow-options.php":
        ("function validate_board_stage", "function assigned_user", "schedule_submenu", "manage_options"),
    "class-mad4b-scp-unified-capability-gateway.php":
        ("function runtime_blog_matches", "function dispatch", "hash_equals"),
}
SOURCE = {name: (INC / name).read_text() for name in REVIEW_MARKERS}

def verify_sources(source):
    problems = []
    for name, markers in REVIEW_MARKERS.items():
        body = source[name]
        for marker in markers:
            if marker not in body:
                problems.append(f"{name}: missing {marker}")
    group = source["class-mad4b-scp-content-job-groups.php"]
    jobs = source["class-mad4b-scp-content-jobs.php"]
    cwa = source["class-mad4b-scp-central-write-acceptance.php"]
    structural = source["class-mad4b-scp-contentjob-structural-cwa.php"]
    modes = source["class-mad4b-scp-content-workflow-modes.php"]
    if "get_option('mad4b_contentjob_group_apply_enabled','no')" not in group:
        problems.append("group bulk opt-in is not default-deny")
    if "MAD4B_SCP_Central_Write_Acceptance::permitted()" not in group:
        problems.append("group apply bypasses per-site original CWA admission")
    if "(string)$plan['snapshot_sha256']" not in group:
        problems.append("group apply lacks exact snapshot binding")
    if "self::DEFAULT_MODE = 'autonomous'" in modes or "'can_publish' => true" in modes:
        problems.append("review mode authorizes publication")
    if "function_exists( 'do_action' )" not in jobs:
        problems.append("isolated fixture postcommit dispatch not bounded")
    if "!class_exists('MAD4B_SCP_Site_Profile')" not in structural:
        problems.append("structural-only owner missing site profile check")
    if not all(x in cwa for x in ("source_hash_stale", "rights_pass", "idempotency_identity_mismatch", "reference_id_mismatch")):
        problems.append("central CWA has lost source/rights/replay gates")
    return problems

problems = verify_sources(SOURCE)
# Mutation sensitivity: a weak marker-only test is insufficient.
mutations = [
    ("group opt-in", "class-mad4b-scp-content-job-groups.php",
     "get_option('mad4b_contentjob_group_apply_enabled','no')",
     "get_option('mad4b_contentjob_group_apply_enabled','yes')"),
    ("group CWA", "class-mad4b-scp-content-job-groups.php",
     "MAD4B_SCP_Central_Write_Acceptance::permitted()", "true"),
    ("CWA rights", "class-mad4b-scp-central-write-acceptance.php",
     "idempotency_identity_mismatch", "idempotency_check_omitted"),
    ("structural owner site", "class-mad4b-scp-contentjob-structural-cwa.php",
     "!class_exists('MAD4B_SCP_Site_Profile')", "false"),
]
for label, path, before, after in mutations:
    if before not in SOURCE[path]:
        problems.append("mutation anchor missing: " + label)
        continue
    mutant = dict(SOURCE)
    mutant[path] = mutant[path].replace(before, after)
    if not verify_sources(mutant):
        problems.append("mutation escaped source review: " + label)

# Capability registration is exact and read-only/write status is independently
# checked per new surface, not inferred from a group name.
abilities = {
    "mad4b/content-job-typed-relation-write-plan": True,
    "mad4b/content-job-typed-relation-write-apply": False,
    "mad4b/content-job-structural-cwa-apply": False,
    "mad4b/content-job-structural-qa-plan": True,
    "mad4b/content-job-structural-qa-attest": False,
    "mad4b/content-job-group-plan": True,
    "mad4b/content-job-group-apply": False,
    "mad4b/content-job-relationship-plan": True,
    "mad4b/content-workflow-modes": True,
}
jobs = SOURCE["class-mad4b-scp-content-jobs.php"]
for ability, readonly in abilities.items():
    if jobs.count("'" + ability + "'") != 1:
        problems.append("ability missing/duplicated: " + ability)
        continue
    start = jobs.index("'" + ability + "'")
    next_item = jobs.find("self::register(", start + len(ability))
    segment = jobs[start:next_item if next_item > 0 else len(jobs)]
    if not re.search(r",\s*" + ("true" if readonly else "false") + r"\s*\);\s*$", segment):
        problems.append("ability read/write scope changed: " + ability)

boot = (PLUGIN / "mad4b-site-control-plane.php").read_text()
if "class-mad4b-scp-dfsb-environment-policy.php" not in boot or "MAD4B_SCP_DFSB_Environment_Policy::boot()" not in boot:
    problems.append("source bootstrap missing original environment policy")
if not all((PLUGIN / f"tests/{name}").is_file() for name in
           ("brand-context-builder-concurrency-runtime.sh", "admin-route-registry-contract.py")):
    problems.append("original source behavior regressions missing")
if not (ROOT / "tests/wpml-contentjob-source-parity.py").is_file():
    problems.append("original WPML parity test missing")
print(f"CONTENTJOB_CI_SOURCE_REVIEW: {len(REVIEW_MARKERS)} source files, "
      f"{len(abilities)} exact abilities, {len(mutations)} fail-closed mutations")
if problems:
    for problem in problems:
        print("FAIL " + problem)
    raise SystemExit(1)
print("PASS CI source review only; human owner approval and Production remain independent")
