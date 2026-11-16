#!/usr/bin/env python3
"""Pinned first-party, no-network original PHP behavior evidence matrix.

Review candidates are intentionally explicit source->original PHP fixture pairs;
this is CI evidence ONLY, not automatic acceptance or production authority.
Every fixture loads the named original source and must actively pass.
"""
from pathlib import Path
import re
import shutil
import subprocess

ROOT = Path(__file__).resolve().parents[1]
PREFIX = "wp-content/plugins/mad4b-site-control-plane/"
SOURCE = PREFIX + "includes/"
# Only source files with an original same-PR hermetic behavioral fixture.
# No arbitrary fixture names, shell, site operation or automatic authorization.
CASES = (
    ("tests/background-agent-original-authority-hermetic.php",
     ("class-mad4b-scp-background-agent-admission.php",
      "class-mad4b-scp-background-agent-dispatch.php"),
     "PASS: BACKGROUND_AGENT_ORIGINAL_AUTHORITY"),
    ("tests/native-capability-registry-adapter-first-party.php",
     ("class-mad4b-scp-native-ability-trust.php",),
     "PASS: NATIVE_CAPABILITY_ADAPTER_REGISTRY"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/brand-core-remote-recovery-runtime.php",
     ("class-mad4b-scp-brand-core-remote-recovery.php",
      "class-mad4b-scp-operator-journeys.php"),
     "mad4b.site-control-plane.brand-core-remote-recovery.runtime.v1: PASS"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/unified-capability-sources-runtime.php",
     ("class-mad4b-scp-capability-source-inventory.php",),
     "PASS unified source inventory"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/capability-first-mcp-adapter-gate-runtime.php",
     ("class-mad4b-scp-dependency-manager.php",
      "class-mad4b-scp-runtime-release-set.php"),
     "PASS explicit_release_set_binds_to_unique_manifest_digest"),
    ("tests/remote-execution/operator-grants-convergence-smoke.php",
     ("class-mad4b-scp-developer-authority-policy-v2.php",
      "class-mad4b-scp-operator-grant-convergence.php"),
     "PASS no grant mutations or Production effects"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/drive-scan-continuation-runtime.php",
     ("class-mad4b-scp-drive-scan-admin.php",),
     "DRIVE_SCAN_PLAN_APPLY_PARITY: PASS"),
    ("tests/remote-execution/governed-enrollment-dispatch-smoke.php",
     ("class-mad4b-scp-enrollment-dispatch.php",
      "class-mad4b-scp-governed-ability-census.php"),
     "PASS first-party callback provenance is independently verified"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/mcp-class-provenance-runtime.php",
     ("class-mad4b-scp-mcp-class-provenance.php",),
     "mad4b.mcp-class-provenance.v1: PASS"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/qa30-native-general-settings-rest-lifecycle.php",
     ("class-mad4b-scp-mcp-peer-governance.php",),
     "QA30_SOURCE_CONTRACT_REGRESSION_PASS"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/qa27-candidate-upload-plan-rc118-runtime.php",
     ("class-mad4b-scp-self-update.php",),
     "QA27_RC118_GITHUB_SOURCE_UPLOAD_PLAN_PASS"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/upgrade-continuity-runtime.php",
     ("class-mad4b-scp-upgrade-continuity.php",),
     "mad4b.upgrade-continuity.runtime.v1: PASS"),
    ("tests/adapter-base-default-write-eligibility-hermetic.php",
     ("adapters/class-mad4b-scp-adapter-base.php",),
     "ADAPTER_BASE_DEFAULT_WRITE: PASS"),
    ("tests/dynamic-content-apply-native-ability-source-hermetic.php",
     ("adapters/class-mad4b-scp-dynamic-content-adapter.php",),
     "DYNAMIC_CONTENT_APPLY_NATIVE: PASS"),
    ("tests/remote-execution/governed-parent-enrollment-smoke.php",
     ("class-mad4b-scp-abilities.php",),
     "PASS zero grants and zero callback execution"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/context-media-normalization-runtime.php",
     ("class-mad4b-scp-context-local-document-normalizer.php",
      "class-mad4b-scp-google-drive-context.php",
      "class-mad4b-scp-wordpress-media-context.php"),
     "SUMMARY pass=10 fail=0"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/schema-migration-runtime-contract.php",
     ("class-mad4b-scp-schema.php",),
     "PASS"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/production-native-principal-parity-runtime.php",
     ("class-mad4b-scp-execution-commit-guard.php",
      "class-mad4b-scp-policy.php"),
     "PASS test_did_not_issue_grants_or_write_options"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/site-profile-feature-reenrollment-runtime.php",
     ("class-mad4b-scp-site-profile-enrollment.php",
      "class-mad4b-scp-site-profile.php"),
     "mad4b.site-profile-feature-reenrollment.runtime.v1: PASS"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/mcp-runtime-recovery-runtime.php",
     ("class-mad4b-scp-mcp-mu-bootstrap-refresh.php",
      "class-mad4b-scp-mcp-runtime-conflict-guard.php",
      "class-mad4b-scp-mcp-runtime-recovery.php"),
     "mad4b.mcp-runtime-recovery.v1: 39/39 PASS"),
    ("tests/operation-registry-native-policy-boundary-hermetic.php",
     ("class-mad4b-scp-operation-registry.php",),
     "NATIVE_OPERATION_REGISTRY_BOUNDARY: PASS"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/remote-media-rights-environment-runtime.php",
     ("class-mad4b-scp-remote-media-rights.php",),
     "REMOTE_MEDIA_RIGHTS_ENVIRONMENT: PASS"),
    ("tests/origin-external-research-source-bound-hermetic.php",
     ("class-mad4b-scp-research-groups.php",),
     "NATIVE_ORIGIN_EXTERNAL_RESEARCH: PASS"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/qa31-w1-authority-owner-boundary-runtime.php",
     ("class-mad4b-scp-company-owner-activation.php",),
     "QA31_W1_OWNER_BROWSER_GATE: PASS"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/staging-write-owner-intent-runtime.php",
     ("class-mad4b-scp-staging-write-owner-intent.php",),
     "STAGING_WRITE_OWNER_INTENT: PASS"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/ai-grant-mode-runtime.php",
     ("class-mad4b-scp-ai-grant-mode.php",),
     "PASS: AI_ONLY_GRANT_MODE_EXACT_OWNER_CONSENT"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/brand-core-control-loop-runtime.php",
     ("class-mad4b-scp-brand-core-control-loop.php",),
     "PASS: Brand Core one-source lifecycle"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/native-recovery-status-runtime.php",
     ("class-mad4b-scp-native-runtime-diagnostics.php",
      "class-mad4b-scp-runtime-components-admin-ui.php"),
     "PASS native WordPress Recovery:"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/connector-resilience-runtime.php",
     ("class-mad4b-scp-read-consistency.php",),
     "mad4b.connector-resilience.runtime"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/context-admin-recovery-runtime.php",
     ("class-mad4b-scp-admin-settings-persistence.php",
      "class-mad4b-scp-context-admin-ui.php"),
     "Context admin recovery render/handler runtime: PASS"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/qa31-w1-ollama-local-permission-runtime.php",
     ("class-mad4b-scp-local-ai-governance.php",
      "class-mad4b-scp-local-ollama-ability.php"),
     "QA31_W1_LOCAL_OLLAMA_GATE: PASS"),
    ("tests/readiness-native-cron-ownership-hermetic.php",
     ("class-mad4b-scp-firecrawl-crawl-integration-v1.php",),
     "PASS"),
)
def main():
    php = shutil.which("php")
    if not php:
        raise RuntimeError("Real PHP CLI required, no static or lint-only fallback")
    inspected = []
    for name, sources, success in CASES:
        file = ROOT / name
        assert file.is_file() and not file.is_symlink(), "Unreviewed fixture path: " + name
        src = file.read_text(encoding="utf-8", errors="replace")
        for source in sources:
            basename = source.rsplit("/", 1)[-1]
            assert (ROOT / SOURCE / source).is_file(), "Missing actual source"
            assert re.search(r"(?:require|include).*" + re.escape(basename),src), \
                "Fixture does not load original source: " + basename
        result = subprocess.run([php,str(file)],cwd=ROOT,
                                capture_output=True,text=True,timeout=90,check=False)
        lines = result.stdout.splitlines()
        assert result.returncode == 0 and any(success in line for line in lines), \
            f"Original source fixture failed {name}: {(result.stdout + result.stderr)[-1000:]}"
        assert not re.search(r"(?m)^(?:FAIL(?::|\s)|Fatal error:|Parse error:)",
                             result.stdout + "\n" + result.stderr), \
            "Reported original source failure in " + name
        inspected.extend(SOURCE + p for p in sources)
    assert len(inspected) == len(set(inspected)), "Duplicate source review marker"
    print(f"PASS: READINESS_ORIGINAL_SOURCE_MATRIX {len(CASES)} original PHP fixtures "
          f"for {len(inspected)} distinct first-party source files; no grants or writes")

if __name__ == "__main__":
    main()
