#!/usr/bin/env python3
"""Fail-closed source and evidence contract for four-PR integrated candidate."""
import hashlib
import json
from pathlib import Path

ROOT = Path("wp-content/plugins/mad4b-site-control-plane")
SPECS = Path("specs/007-content-intelligence-workflow-platform/extensions/competitive-experience")

def require(path, *markers):
    source = Path(path).read_text(encoding="utf-8")
    for marker in markers:
        if marker not in source:
            raise SystemExit(f"INTEGRATION CONTRACT FAIL: {path}: missing {marker}")
    return source

bootstrap = require(
    ROOT / "mad4b-site-control-plane.php",
    "MAD4B_SCP_Plugin_Update_Popup::boot()",
    "MAD4B_SCP_Cross_Plugin_Deployment::boot()",
    "MAD4B_SCP_Cross_Plugin_Admin::boot()",
    "MAD4B_SCP_MCP_Hybrid::boot()",
    "MAD4B_SCP_Optional_Governance::boot()",
    "MAD4B_SCP_Optional_Governance_Remote::boot()",
)
early = bootstrap.split("unset( $mad4b_native_plugin_page", 1)[0]
if "MAD4B_SCP_Plugin_Update_Popup::boot()" not in early:
    raise SystemExit("INTEGRATION CONTRACT FAIL: Plugins dialog skipped by Early Zero-Touch")

require(
    ROOT / "includes/class-mad4b-scp-servers.php",
    "mad4b/native-mcp-catalog", "mad4b/adapter-candidates",
    "mad4b/optional-governance-status", "mad4b/optional-governance-plan",
    "mad4b/plugin-deployment-plan", "MAD4B_SCP_Admin_Remote_Parity::boot()",
)
require(
    ROOT / "includes/class-mad4b-scp-cross-plugin-deployment.php",
    "production_package_executor_not_yet_certified",
    "'wpcron_auto_execute' => false", "'mutation_performed' => false",
    "independent_production_promotion_attestation_required",
)
require(
    ROOT / "includes/class-mad4b-scp-plugin-update-popup.php",
    "data-mad4b-async-preview", "mad4b_cross_plugin_preview",
    "admin_footer-plugins.php",
)
require(ROOT / "includes/class-mad4b-scp-cross-plugin-admin.php",
        "check_ajax_referer", "current_user_can( 'manage_options' )",
        "'mutation_performed' => false", "'authorizing' => false")
require(ROOT / "includes/class-mad4b-scp-self-update.php",
        "Review certified update", "Check MAD4B updates")
require(ROOT / "includes/class-mad4b-scp-optional-governance.php",
        "final class MAD4B_SCP_Optional_Governance")
require(ROOT / "includes/class-mad4b-scp-mcp-hybrid.php",
        "MAD4B_SCP_MCP_Hybrid")
require(ROOT / "tests/admin-pages-wordpress.php",
        "mad4b-optional-governance-options",
        "Provider Coverage tab manifest and disposable WordPress render cases must match exactly")
require(".github/workflows/feature-007-pre-staging-hybrid-audit.yml",
        "dynamic-mcp-hybrid-runtime.php", "version_compare(PHP_VERSION, \"8.5.11\", \"<\")",
        "php: ['7.4', '8.3', '8.4', '8.5']")

registry = json.loads((ROOT / "config/cross-plugin-sources.json").read_text(encoding="utf-8"))
etg = next((x for x in registry.get("sources", []) if x.get("id") == "etg-dynamic-filter-seo-bridge"), None)
if not etg or etg.get("package_certification") != "pending" or etg.get("artifact_sha256"):
    raise SystemExit("INTEGRATION CONTRACT FAIL: ETG artifact prematurely certified")

# Verify every exact-source SHA-256 / bytes in the shared Feature 007 manifests.
# This does not promote any review flags or claim live deployment acceptance.
watched = [
    ROOT / "mad4b-site-control-plane.php",
    ROOT / "includes/class-mad4b-scp-servers.php",
    ROOT / "includes/class-mad4b-scp-operator-workspace.php",
    ROOT / "tests/admin-pages-wordpress.php",
    ROOT / "includes/class-mad4b-scp-server-write-catalog.php",
    ROOT / "includes/class-mad4b-scp-operator-control-center.php",
    Path(".github/workflows/feature-007-pre-staging-hybrid-audit.yml"),
]
fingerprints = {}
for source in watched:
    data = source.read_bytes()
    fingerprints[source.as_posix()] = (hashlib.sha256(data).hexdigest(), len(data))

verified = 0
def check_evidence(node):
    global verified
    if isinstance(node, list):
        for item in node:
            check_evidence(item)
    elif isinstance(node, dict):
        path = node.get("path")
        if isinstance(path, str) and path in fingerprints and isinstance(node.get("sha256"), str):
            digest, nbytes = fingerprints[path]
            if node["sha256"] != digest or ("bytes" in node and node["bytes"] != nbytes):
                raise SystemExit(f"INTEGRATION CONTRACT FAIL: stale SHA/bytes for {path}")
            verified += 1
        else:
            for item in node.values():
                check_evidence(item)

for name in [*(f"g{x}" for x in range(1, 10)), "ui"]:
    manifest = json.loads((SPECS / f"{name}-delivery.json").read_text(encoding="utf-8"))
    check_evidence(manifest)
    if manifest.get("production_authorized") is True or manifest.get("authorizing") is True:
        raise SystemExit(f"INTEGRATION CONTRACT FAIL: {name} authorization escalated")
if verified < 18:
    raise SystemExit(f"INTEGRATION CONTRACT FAIL: only {verified} evidence bindings covered")
print(f"MAD4B_COMBINED_FEATURE_CONTRACT: PASS (evidence_bindings={verified})")
