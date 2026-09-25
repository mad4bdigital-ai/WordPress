#!/usr/bin/env python3
"""Repository-only pre-Staging hybrid audit for Feature 007.

Generic language/runtime quality stays delegated to maintained tools (PHP lint,
WordPressCS/PHPCompatibility/PHPStan/Plugin Check when configured by CI).
This script owns only MAD4B-specific invariants that generic tooling cannot
understand.
"""
from __future__ import annotations

import hashlib
import json
import re
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
CP = REPO / "wp-content" / "plugins" / "mad4b-site-control-plane"
PORTABLE = REPO / "plugins" / "mad4b-wordpress"
DIST = REPO / "dist"

errors: list[str] = []
checks: dict[str, dict] = {}

def fail(group: str, message: str) -> None:
    errors.append(f"{group}: {message}")

def record(group: str, **payload) -> None:
    checks[group] = payload


def _mask_php_noncode(source: str) -> str:
    """Mask quoted strings and comments while preserving offsets/newlines."""
    out = list(source)
    i = 0
    state = "code"
    quote = ""
    while i < len(source):
        ch = source[i]
        nxt = source[i + 1] if i + 1 < len(source) else ""
        if state == "code":
            if ch in ("'", '"', chr(96)):
                state = "string"
                quote = ch
                out[i] = " "
            elif ch == "/" and nxt == "/":
                state = "line_comment"
                out[i] = out[i + 1] = " "
                i += 1
            elif ch == "/" and nxt == "*":
                state = "block_comment"
                out[i] = out[i + 1] = " "
                i += 1
            elif ch == "#" and nxt != "[":
                state = "line_comment"
                out[i] = " "
        elif state == "string":
            if ch == "\\":
                out[i] = " "
                if i + 1 < len(source):
                    if source[i + 1] != "\n":
                        out[i + 1] = " "
                    i += 1
            elif ch == quote:
                out[i] = " "
                state = "code"
                quote = ""
            elif ch != "\n":
                out[i] = " "
        elif state == "line_comment":
            if ch == "\n":
                state = "code"
            else:
                out[i] = " "
        elif state == "block_comment":
            if ch == "*" and nxt == "/":
                out[i] = out[i + 1] = " "
                i += 1
                state = "code"
            elif ch != "\n":
                out[i] = " "
        i += 1
    return "".join(out)


def _php_structural_inventory(source: str) -> tuple[list[str], list[dict]]:
    """Find named classes and method duplicates within the same class scope."""
    masked = _mask_php_noncode(source)
    class_opens: dict[int, list[str]] = {}
    named_classes: list[str] = []

    named_re = re.compile(
        r"(?m)^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)\b"
    )
    for match in named_re.finditer(masked):
        brace = masked.find("{", match.end())
        if brace < 0:
            continue
        name = match.group(1)
        named_classes.append(name)
        class_opens.setdefault(brace, []).append(name)

    anon_index = 0
    for match in re.finditer(r"\bnew\s+class\b", masked):
        brace = masked.find("{", match.end())
        if brace < 0:
            continue
        anon_index += 1
        line = masked.count("\n", 0, match.start()) + 1
        class_opens.setdefault(brace, []).append(f"<anonymous#{anon_index}@L{line}>")

    method_events: dict[int, tuple[str, int]] = {}
    for match in re.finditer(
        r"(?m)^\s*(?:(?:public|protected|private)\s+)?(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(",
        masked,
    ):
        method_events[match.start()] = (
            match.group(1),
            masked.count("\n", 0, match.start()) + 1,
        )

    positions = set(method_events)
    positions.update(i for i, ch in enumerate(masked) if ch in "{}")
    depth = 0
    class_stack: list[tuple[str, int]] = []
    methods_by_scope: dict[str, list[tuple[str, int]]] = {}

    for pos in sorted(positions):
        if pos in method_events and class_stack:
            method_name, line = method_events[pos]
            scope = class_stack[-1][0]
            methods_by_scope.setdefault(scope, []).append((method_name, line))

        ch = masked[pos]
        if ch == "{":
            depth += 1
            for scope in class_opens.get(pos, []):
                class_stack.append((scope, depth))
        elif ch == "}":
            depth = max(0, depth - 1)
            while class_stack and class_stack[-1][1] > depth:
                class_stack.pop()

    duplicates: list[dict] = []
    for scope, rows in methods_by_scope.items():
        names = [name for name, _line in rows]
        repeated = sorted({name for name in names if names.count(name) > 1})
        if repeated:
            duplicates.append({
                "class_scope": scope,
                "methods": repeated,
                "lines": {
                    method: [line for name, line in rows if name == method]
                    for method in repeated
                },
            })
    return named_classes, duplicates

skill_rel = Path("wordpress-extension-strategy") / "SKILL.md"
portable_skill = PORTABLE / "skills" / skill_rel
seed_skill = CP / "skill-seeds" / skill_rel
skill_ok = portable_skill.is_file() and seed_skill.is_file()
if not skill_ok:
    fail("extension_strategy_skill", "portable or canonical seed Skill is missing")
else:
    p = portable_skill.read_bytes()
    s = seed_skill.read_bytes()
    if p != s:
        fail("extension_strategy_skill", "portable Skill and canonical seed differ")
    text = p.decode("utf-8")
    for marker in [
        "REUSE, ADDON, FORK, or NATIVE",
        "Add-on-first customization pattern",
        "never patch vendor files at runtime",
        "exact compatible provider/version range",
        "Production authority",
        "Quality-tool reuse",
    ]:
        if marker not in text:
            fail("extension_strategy_skill", f"missing policy marker: {marker}")
record(
    "extension_strategy_skill",
    portable=str(portable_skill.relative_to(REPO)),
    canonical_seed=str(seed_skill.relative_to(REPO)),
    sha256=hashlib.sha256(portable_skill.read_bytes()).hexdigest() if portable_skill.is_file() else "",
    byte_identical=skill_ok and portable_skill.read_bytes() == seed_skill.read_bytes(),
)

# Machine-readable extension architecture policy must agree with the permanent Skill.
strategy_path = CP / "config" / "wordpress-extension-strategy.json"
if not strategy_path.is_file():
    fail("extension_strategy_contract", "machine-readable strategy policy is missing")
    strategy = {}
else:
    strategy = json.loads(strategy_path.read_text(encoding="utf-8"))
    if strategy.get("contract") != "mad4b.wordpress-extension-strategy.v1":
        fail("extension_strategy_contract", "strategy contract mismatch")
    if strategy.get("decision_order") != ["REUSE", "ADDON", "FORK", "NATIVE"]:
        fail("extension_strategy_contract", "decision order drifted")
    addon = strategy.get("decisions", {}).get("ADDON", {})
    if addon.get("vendor_patch_allowed") is not False:
        fail("extension_strategy_contract", "ADDON may not patch vendor files")
    if addon.get("production_authority_inherited") is not False:
        fail("extension_strategy_contract", "ADDON may not inherit Production authority")
    if addon.get("exact_provider_version_runtime_certification_required") is not True:
        fail("extension_strategy_contract", "ADDON must require exact-version runtime certification")
record(
    "extension_strategy_contract",
    policy=str(strategy_path.relative_to(REPO)),
    contract=strategy.get("contract", "") if strategy else "",
    decision_order=strategy.get("decision_order", []) if strategy else [],
    addon_manifest_contract=strategy.get("addon_manifest_contract", "") if strategy else "",
)


# Add-on catalog and static policy enforcement. The catalog may be empty, but
# every future item must satisfy the same fail-closed contract before Staging.
addon_catalog_path = CP / "config" / "wordpress-addon-catalog.json"
if not addon_catalog_path.is_file():
    fail("addon_registry", "wordpress add-on catalog is missing")
    addon_catalog = {}
else:
    addon_catalog = json.loads(addon_catalog_path.read_text(encoding="utf-8"))
if addon_catalog.get("contract") != "mad4b.wordpress-addon-catalog.v1":
    fail("addon_registry", "wordpress add-on catalog contract mismatch")
if addon_catalog.get("defaults", {}).get("production_authorized") is not False:
    fail("addon_registry", "add-on catalog may not authorize Production")
addon_required_fields = {
    "addon_id","plugin_file","source_root","base_provider","compatible_versions","extension_points","capabilities",
    "data_ownership","authority_impact","rollback","certification","tests","portability",
    "supply_chain","network_access","multisite","performance_budget","observability","failure_policy",
    "release_ring","certified_pairs",
}
addon_violations = []
addon_static_hits = []
for manifest in addon_catalog.get("addons", []):
    if not isinstance(manifest, dict):
        addon_violations.append("manifest_not_object")
        continue
    addon_id = manifest.get("addon_id", "<unknown>")
    missing = sorted(addon_required_fields - set(manifest))
    if missing:
        addon_violations.append(f"{addon_id}:missing={missing}")
    authority = manifest.get("authority_impact", {})
    if authority.get("inherits_production_authority") is not False or authority.get("adds_generic_shell") is not False or authority.get("adds_raw_sql") is not False:
        addon_violations.append(f"{addon_id}:authority_boundary_invalid")
    if manifest.get("portability", {}).get("vendor_files_modified") is not False:
        addon_violations.append(f"{addon_id}:vendor_patch_invalid")
    certification = manifest.get("certification", {})
    if certification.get("exact_provider_version_required") is not True or certification.get("exact_addon_version_required") is not True:
        addon_violations.append(f"{addon_id}:exact_pair_not_required")
    if manifest.get("release_ring") not in {"shadow","canary","active"}:
        addon_violations.append(f"{addon_id}:release_ring_invalid")
    if manifest.get("failure_policy", {}).get("reconcile_before_retry_after_uncertain_write") is not True:
        addon_violations.append(f"{addon_id}:uncertain_retry_policy_invalid")
    if not isinstance(manifest.get("network_access", {}).get("allowed_hosts"), list):
        addon_violations.append(f"{addon_id}:network_allowlist_missing")
    source_root = manifest.get("source_root")
    if not isinstance(source_root, str) or not source_root.strip():
        addon_violations.append(f"{addon_id}:source_root_required")
    else:
        root = (REPO / str(source_root)).resolve()
        try:
            root.relative_to(REPO.resolve())
        except ValueError:
            addon_violations.append(f"{addon_id}:source_root_escape")
            continue
        if not root.is_dir():
            addon_violations.append(f"{addon_id}:source_root_missing")
            continue
        forbidden = {
            "shell_exec": r"\bshell_exec\s*\(",
            "proc_open": r"\bproc_open\s*\(",
            "passthru": r"\bpassthru\s*\(",
            "eval": r"\beval\s*\(",
            "direct_wpdb_query": r"\$wpdb\s*->\s*(?:query|insert|update|delete)\s*\(",
            "direct_filesystem_write": r"\b(?:file_put_contents|fwrite|unlink|rename)\s*\(",
            "direct_outbound_http": r"\bwp_remote_(?:get|post|request|head)\s*\(",
            "direct_content_or_option_mutation": r"\b(?:wp_update_post|update_option|add_option|delete_option)\s*\(",
        }
        for php_file in root.rglob("*.php"):
            src = php_file.read_text(encoding="utf-8", errors="replace")
            for label, pattern in forbidden.items():
                if re.search(pattern, src):
                    addon_static_hits.append({"addon_id": addon_id, "file": str(php_file.relative_to(REPO)), "primitive": label})
if addon_violations:
    fail("addon_registry", f"manifest violations: {addon_violations}")
if addon_static_hits:
    fail("addon_registry", f"forbidden direct execution/mutation primitives: {addon_static_hits}")
record(
    "addon_registry",
    catalog=str(addon_catalog_path.relative_to(REPO)),
    addon_count=len(addon_catalog.get("addons", [])) if isinstance(addon_catalog.get("addons"), list) else 0,
    violations=addon_violations,
    static_hits=addon_static_hits,
    exact_pair_certification_required=True,
    lifecycle_invalidation_required=True,
    production_authorized=False,
)

# Canonical Skill inventory must have one source of truth.
skill_manifest_path = CP / "config" / "skill-seed-manifest.json"
skill_manifest = json.loads(skill_manifest_path.read_text(encoding="utf-8")) if skill_manifest_path.is_file() else {}
if skill_manifest.get("contract") != "mad4b.skill-seed-manifest.v1":
    fail("skill_manifest", "canonical Skill seed manifest missing or invalid")
manifest_names = [row.get("name") for row in skill_manifest.get("skills", []) if isinstance(row, dict)]
seed_names = sorted(p.parent.name for p in (CP / "skill-seeds").glob("*/SKILL.md"))
portable_names = sorted(p.parent.name for p in (PORTABLE / "skills").glob("*/SKILL.md"))
if sorted(manifest_names) != seed_names or sorted(manifest_names) != portable_names:
    fail("skill_manifest", f"Skill inventory drift manifest={sorted(manifest_names)} seed={seed_names} portable={portable_names}")
record("skill_manifest", manifest=str(skill_manifest_path.relative_to(REPO)), count=len(manifest_names), seed_names=seed_names)

class_files = sorted((CP / "includes").glob("class-mad4b-scp-*.php"))
classes: dict[str, str] = {}
duplicate_classes: list[dict] = []
duplicate_methods: list[dict] = []
for path in class_files:
    src = path.read_text(encoding="utf-8")
    named_classes, scoped_method_duplicates = _php_structural_inventory(src)
    for cls in named_classes:
        if cls in classes:
            duplicate_classes.append({
                "class": cls,
                "first": classes[cls],
                "second": str(path.relative_to(REPO)),
            })
        else:
            classes[cls] = str(path.relative_to(REPO))
    for duplicate in scoped_method_duplicates:
        duplicate_methods.append({
            "file": str(path.relative_to(REPO)),
            **duplicate,
        })

if duplicate_classes:
    fail("structural_integrity", f"duplicate class declarations: {duplicate_classes}")
if duplicate_methods:
    fail("structural_integrity", f"duplicate named methods inside canonical class files: {duplicate_methods}")

line_caps = {
    CP / "includes" / "class-mad4b-scp-content-jobs.php": 1200,
    CP / "includes" / "class-mad4b-scp-scheduler-admission.php": 1200,
}
line_counts = {}
for path, cap in line_caps.items():
    count = len(path.read_text(encoding="utf-8").splitlines())
    line_counts[str(path.relative_to(REPO))] = {"lines": count, "cap": cap}
    if count > cap:
        fail("structural_integrity", f"{path.name} grew to {count} lines (cap {cap})")
record(
    "structural_integrity",
    class_files=len(class_files),
    duplicate_classes=duplicate_classes,
    duplicate_methods=duplicate_methods,
    guarded_line_counts=line_counts,
)

critical_runtime = [
    "class-mad4b-scp-artifacts.php",
    "class-mad4b-scp-addon-registry.php",
    "class-mad4b-scp-capability-traits.php",
    "class-mad4b-scp-content-intelligence-pipeline.php",
    "class-mad4b-scp-content-jobs.php",
    "class-mad4b-scp-context-pack.php",
    "class-mad4b-scp-brand-context-builder.php",
    "class-mad4b-scp-data-governance.php",
    "class-mad4b-scp-decommission-portability.php",
    "class-mad4b-scp-durable-execution.php",
    "class-mad4b-scp-governed-draft.php",
    "class-mad4b-scp-host-bridge.php",
    "class-mad4b-scp-operator-doctor.php",
    "class-mad4b-scp-publication-verification.php",
    "class-mad4b-scp-research-intelligence.php",
    "class-mad4b-scp-scheduler-admission.php",
    "class-mad4b-scp-workflow-providers.php",
]
bootstrap = (CP / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
missing_runtime = []
unloaded_runtime = []
for name in critical_runtime:
    path = CP / "includes" / name
    if not path.is_file():
        missing_runtime.append(name)
    if name not in bootstrap:
        unloaded_runtime.append(name)
if missing_runtime:
    fail("runtime_package_completeness", f"missing runtime files: {missing_runtime}")
if unloaded_runtime:
    fail("runtime_package_completeness", f"runtime files not loaded by plugin bootstrap: {unloaded_runtime}")
record(
    "runtime_package_completeness",
    critical_files=len(critical_runtime),
    missing=missing_runtime,
    not_bootstrapped=unloaded_runtime,
)

servers = (CP / "includes" / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
read_abilities = [
    "mad4b/capability-trait-profile",
    "mad4b/addon-registry-status",
    "mad4b/capability-trait-resolve",
    "mad4b/data-processing-evaluate",
    "mad4b/research-provider-plan",
    "mad4b/decommission-preflight",
    "mad4b/export-bundle-build",
    "mad4b/import-bundle-validate",
    "mad4b/scheduler-admission-evaluate",
    "mad4b/scheduler-fair-rank",
    "mad4b/operator-doctor",
]
write_abilities = ["mad4b/data-processing-record-decision"]
missing_surface_markers = [a for a in read_abilities + write_abilities if a not in servers]
if missing_surface_markers:
    fail("ability_surface_consistency", f"server projection missing abilities: {missing_surface_markers}")
if "mad4b/database-raw-query" not in servers or "'mad4b-breakglass'" not in servers:
    fail("ability_surface_consistency", "raw SQL breakglass isolation marker is missing")
record(
    "ability_surface_consistency",
    read_expected=read_abilities,
    write_expected=write_abilities,
    missing=missing_surface_markers,
    raw_sql_normal_write_forbidden=True,
)

lineage_requirements = {
    "includes/class-mad4b-scp-context-pack.php": [
        "writer_profile_id", "writer_profile_version", "writer_profile_fingerprint"
    ],
    "includes/class-mad4b-scp-content-intelligence-pipeline.php": [
        "writer_profile_id", "writer_profile_version", "writer_profile_fingerprint"
    ],
    "includes/class-mad4b-scp-governed-draft.php": [
        "writer_profile_id", "writer_profile_version", "writer_profile_fingerprint", "plan_sha256"
    ],
    "includes/class-mad4b-scp-workflow-providers.php": [
        "provider_profile_fingerprint", "capability_certification_fingerprint", "provider_release_ring", "plan_sha256"
    ],
    "includes/class-mad4b-scp-data-governance.php": [
        "rights_summary_fingerprint", "processor_profile_fingerprint"
    ],
}
lineage_missing = {}
for rel, markers in lineage_requirements.items():
    src = (CP / rel).read_text(encoding="utf-8")
    missing = [m for m in markers if m not in src]
    if missing:
        lineage_missing[rel] = missing
if lineage_missing:
    fail("immutable_lineage", f"missing lineage markers: {lineage_missing}")
record("immutable_lineage", requirements=lineage_requirements, missing=lineage_missing)

bounded_files = [
    CP / "includes" / "class-mad4b-scp-host-bridge.php",
    CP / "includes" / "class-mad4b-scp-data-governance.php",
    CP / "includes" / "class-mad4b-scp-decommission-portability.php",
    CP / "includes" / "class-mad4b-scp-scheduler-admission.php",
    CP / "includes" / "class-mad4b-scp-publication-verification.php",
]
forbidden_patterns = {
    "shell_exec(": r"\bshell_exec\s*\(",
    "exec(": r"(?<![A-Za-z0-9_])exec\s*\(",
    "system(": r"(?<![A-Za-z0-9_])system\s*\(",
    "passthru(": r"\bpassthru\s*\(",
    "proc_open(": r"\bproc_open\s*\(",
    "eval(": r"\beval\s*\(",
}
negative_hits = []
for path in bounded_files:
    src = path.read_text(encoding="utf-8")
    for label, pattern in forbidden_patterns.items():
        if re.search(pattern, src):
            negative_hits.append({"file": str(path.relative_to(REPO)), "primitive": label})
if negative_hits:
    fail("authority_negative_space", f"forbidden execution primitives found: {negative_hits}")
record(
    "authority_negative_space",
    scanned=[str(p.relative_to(REPO)) for p in bounded_files],
    forbidden_hits=negative_hits,
    generic_shell_allowed=False,
    raw_sql_normal_surface_allowed=False,
    production_authority_implied=False,
)

verdict = "PASS" if not errors else "FAIL"
result = {
    "contract": "mad4b.pre-staging-hybrid-audit.v1",
    "verdict": verdict,
    "repository_only": True,
    "staging_certification_implied": False,
    "production_authorized": False,
    "generic_quality_tooling_policy": {
        "reuse_external_tools": True,
        "recommended": [
            "PHP lint",
            "WordPressCS",
            "PHPCompatibilityWP",
            "PHPStan + WordPress stubs",
            "WordPress Plugin Check",
        ],
        "mad4b_custom_scope": [
            "structural integrity",
            "runtime/package completeness",
            "ability surface consistency",
            "immutable lineage",
            "authority negative-space",
        ],
    },
    "checks": checks,
    "errors": errors,
}
DIST.mkdir(exist_ok=True)
out = DIST / "pre-staging-hybrid-audit.json"
out.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
print(json.dumps(result, indent=2, sort_keys=True))
if errors:
    raise SystemExit(1)
