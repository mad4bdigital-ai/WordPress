#!/usr/bin/env python3
"""CI-only Source Convergence runtime coverage; never a release certificate.

Run actual source lineage tests and a site-neutral PHP role contract.
Retains old signed hashes, negative tests, and real output assertions.
"""
from pathlib import Path
import subprocess

repo=Path(__file__).resolve().parents[1]
cases=(
    (["python3","tests/test_content_readiness_trait_parity.py"], "CONTENT_READINESS_REVIEWED_EXTENSION_PARITY: PASS"),
    (["python3","tests/test_core_web_vitals_configuration_trait_parity.py"], "CWV_CONFIGURATION_ORIGINAL_BYTE_PARITY: PASS"),
    (["php","wp-content/plugins/mad4b-site-control-plane/tests/source-convergence-content-role-runtime.php"], "SOURCE_CONVERGENCE_CONTENT_TYPE_ROLE: PASS"),
    (["php","wp-content/plugins/mad4b-site-control-plane/tests/source-convergence-live-safety-regression.php"], "SOURCE_CONVERGENCE_LIVE_SAFETY_REGRESSION: PASS"),
    (["php","wp-content/plugins/mad4b-site-control-plane/tests/context-classification-readonly-preview-runtime.php"], "PASS: CONTEXT_CLASSIFICATION_READONLY_PREVIEW"),
    (["python3","tests/context-authority-original-review-suite.py"], "PASS: CONTEXT_AUTHORITY_ORIGINAL_REVIEW"),
    (["php","wp-content/plugins/mad4b-site-control-plane/tests/source-convergence-company-release-readiness-runtime.php"], "SOURCE_CONVERGENCE_COMPANY_RELEASE_READINESS: PASS"),
    (["php","tests/provider-field-intelligence-site-readonly-php74-hermetic.php"], "PROVIDER_FIELDS_SITE_PHP74: PASS"),
    (["php","wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-provider-field-read-parity-runtime.php"], "PASS: provider field GET-only"),
    (["php","wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-live-skills-list-paging-runtime.php"], "Live source Skills query and bounded pagination"),
    (["php","wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-live-catalog-read-runtime.php"], "Live source profile-bound read-only Skills catalog"),
    (["php","wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-live-source-skills-parity-runtime.php"], "Tours.dev Skills source parity"),
    (["php","wp-content/plugins/mad4b-site-control-plane/tests/cimd-scoped-bearer-identity-runtime.php"], "CIMD_SCOPED_SIGNED_BEARER_IDENTITY: PASS"),
    (["php","wp-content/plugins/mad4b-site-control-plane/tests/chatgpt-auto-binding-runtime.php"], "PASS: scoped CIMD client identity bound"),
    (["php","wp-content/plugins/mad4b-site-control-plane/tests/reconnect-hardening-runtime.php"], "mad4b.reconnect-hardening.runtime.v1: PASS"),
    (["php","wp-content/plugins/mad4b-site-control-plane/tests/tours-dev-fcp-local-observability-runtime.php"], "PASS"),
    (["python3","wp-content/plugins/mad4b-site-control-plane/tests/admin-route-registry-contract.py"], "Admin route link contract: PASS"),
    (["python3","tests/source-unification-ollama-overlay-contract.py"], "PR942_OLLAMA_BOOTSTRAP_SOURCE_REVIEW: PASS"),
    (["python3","tests/qa31_release_source_gate_contract.py"], "QA31_SOURCE_PARITY_NEGATIVE_REGRESSIONS: PASS"),
    (["python3","wp-content/plugins/mad4b-site-control-plane/tests/qa31-w1-authority-contract.py"], "SOURCE_ONLY_OLLAMA_NO_BOOT: PASS"),
)
for command,expected in cases:
    proc=subprocess.run(command,cwd=repo,check=False,capture_output=True,text=True,timeout=40)
    if proc.returncode or expected not in proc.stdout or 'FAIL' in proc.stdout:
        raise SystemExit(f"SOURCE_REVIEW_CONTRACT_FAIL: {command}: {proc.stdout[-500:]} {proc.stderr[-500:]}")
print("SOURCE_UNIFICATION_CI_ONLY_REVIEW: PASS (19 executable suites, no production authority)")
