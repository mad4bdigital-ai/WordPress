#!/usr/bin/env python3
"""Static contract for external WPML diagnostics and ETG deployment readiness.

Repository health must fail for actual WPML/REST/MCP/OAuth defects, while
deployment drift by itself remains non-blocking diagnostic evidence. Exact
ETG runtime identity is enforced by a separate deployment-readiness gate.
"""

from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[4]
WORKFLOW = ROOT / ".github/workflows/mad4b-wpml-external-diagnostic.yml"
DEPLOYMENT_GATE = ROOT / ".github/workflows/mad4b-etg-deployment-readiness.yml"


def require(source: str, marker: str, message: str) -> None:
    if marker not in source:
        raise AssertionError(f"{message}: missing {marker!r}")


def main() -> int:
    source = WORKFLOW.read_text(encoding="utf-8")

    required = {
        "mad4b.wpml-external-http-diagnostic.v4": "diagnostic contract version",
        "'transport_state': transport_state": "transport classification",
        "'transport_reachable': transport_reachable": "transport reachability evidence",
        "'wpml_curl_exit': wpml_curl_exit": "WPML curl evidence",
        "'rest_index_curl_exit': rest_curl_exit": "REST-index curl evidence",
        "'wpml_http_status': wpml_http_status": "WPML HTTP evidence",
        "'rest_index_http_status': rest_http_status": "REST-index HTTP evidence",
        "evidence['transport_reachable']": "acceptance must depend on transport reachability",
        "'transport_unreachable' if not evidence['transport_reachable']": "inconclusive transport outcome",
        "evidence['acceptance_authorized'] = False": "raw diagnostic cannot self-authorize acceptance",
        "evidence['authority_granted'] = False": "raw diagnostic cannot grant authority",
        "if state == 'transport_unreachable':": "unreachable branch",
        "WPML endpoint/REST index remained unreachable after bounded retries and browser fallback": "transport outage must remain blocking after bounded recovery",
        "WPML REST namespace/route is missing on a reachable endpoint": "reachable route failure remains blocking",
        "WPML external contract failed on a reachable endpoint": "reachable response-contract failure remains blocking",
        "deployment drift is present together with live MCP/OAuth validation failures": "drift plus live failures must remain blocking",
        "::warning title=ETG deployment drift::": "drift-only evidence must be surfaced as warning",
        "use MAD4B ETG Deployment Readiness for strict deployment identity": "strict identity ownership must be delegated",
        "mad4b.wpml-external-http-acceptance.v5: PASS": "accepted evidence contract",
        'npm install --prefix "$out" --no-save --no-package-lock playwright@1.55.0': "Playwright package must be installed beside the /tmp ESM fallback script",
        '"$out/node_modules/.bin/playwright" install --with-deps chromium': "browser binary install must use the same local Playwright package",
        'cat > "$out/browser-fallback.mjs"': "browser fallback script must live beside its local node_modules",
        'node "$out/browser-fallback.mjs"': "browser fallback execution must preserve local ESM package resolution",
    }
    for marker, message in required.items():
        require(source, marker, message)

    accepted_start = source.find("evidence['accepted'] = (")
    outcome_start = source.find("evidence['diagnostic_outcome']", accepted_start)
    if accepted_start < 0 or outcome_start < 0:
        raise AssertionError("accepted-evidence block is incomplete")
    accepted_block = source[accepted_start:outcome_start]
    for marker in (
        "evidence['transport_reachable']",
        "evidence['namespace_present']",
        "evidence['route_present']",
        "evidence['wpml_contract_compatible']",
    ):
        require(accepted_block, marker, "accepted evidence lost a required conjunct")

    validate_start = source.find("state = evidence.get('transport_state')")
    pass_pos = source.find("mad4b.wpml-external-http-acceptance.v5: PASS", validate_start)
    unreachable_pos = source.find("if state == 'transport_unreachable':", validate_start)
    namespace_fail_pos = source.find("WPML REST namespace/route is missing on a reachable endpoint", validate_start)
    contract_fail_pos = source.find("WPML external contract failed on a reachable endpoint", validate_start)
    if min(validate_start, pass_pos, unreachable_pos, namespace_fail_pos, contract_fail_pos) < 0:
        raise AssertionError("validation ordering markers are incomplete")
    if not (validate_start < unreachable_pos < namespace_fail_pos < contract_fail_pos < pass_pos):
        raise AssertionError("transport/reachable-contract validation ordering drifted")

    drift_start = source.find("if evidence.get('deployment_drift'):")
    drift_failure_pos = source.find(
        "deployment drift is present together with live MCP/OAuth validation failures",
        drift_start,
    )
    drift_warning_pos = source.find("::warning title=ETG deployment drift::", drift_start)
    drift_exit_pos = source.find("raise SystemExit(0)", drift_warning_pos)
    if min(drift_start, drift_failure_pos, drift_warning_pos, drift_exit_pos) < 0:
        raise AssertionError("deployment-drift classifier markers are incomplete")
    if not (drift_start < drift_failure_pos < drift_warning_pos < drift_exit_pos):
        raise AssertionError("deployment drift must fail on live defects before becoming neutral")

    if "MAD4B_REQUIRE_RUNTIME_IDENTITY" in source:
        raise AssertionError("repository health diagnostic must not own strict deployment identity")

    if "accepted_evidence" not in source or "reachable_contract_failure" not in source:
        raise AssertionError("diagnostic outcomes are not exhaustive for accepted/reachable-failure states")

    gate = DEPLOYMENT_GATE.read_text(encoding="utf-8")
    for marker in (
        "name: MAD4B ETG Deployment Readiness",
        "workflow_dispatch:",
        "workflow_call:",
        "ETG exact runtime deployment gate",
        "build-provenance:source_commit_sha_mismatch",
        "runtime_identity_match",
        "wpml_contract_compatible",
        "'mutation_performed': False",
    ):
        require(gate, marker, "ETG deployment readiness contract")
    if "\n  push:" in gate:
        raise AssertionError("ETG deployment readiness must not run on every master push")
    if "if: github.event_name != 'pull_request'" not in gate:
        raise AssertionError("strict ETG deployment gate must not execute on PR validation")

    print("mad4b.wpml-external-diagnostic.contract.v2: PASS")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"mad4b.wpml-external-diagnostic.contract.v1: FAIL: {exc}", file=sys.stderr)
        raise
