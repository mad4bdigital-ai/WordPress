#!/usr/bin/env python3
"""Static contract for the raw external WPML diagnostic workflow.

The diagnostic may collect external HTTP evidence, but it is not itself an
acceptance authority. Network transport failures are inconclusive evidence,
not proof that the WPML route/contract is absent.
"""

from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[4]
WORKFLOW = ROOT / ".github/workflows/mad4b-wpml-external-diagnostic.yml"


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
        "INCONCLUSIVE transport_unreachable; acceptance remains false": "explicit inconclusive output",
        "raise SystemExit(0)": "transport outage must not masquerade as code-contract failure",
        "WPML REST namespace/route is missing on a reachable endpoint": "reachable route failure remains blocking",
        "WPML external contract failed on a reachable endpoint": "reachable response-contract failure remains blocking",
        "mad4b.wpml-external-http-acceptance.v5: PASS": "accepted evidence contract",
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
        raise AssertionError("transport-inconclusive and reachable-contract validation ordering drifted")

    if "accepted_evidence" not in source or "reachable_contract_failure" not in source:
        raise AssertionError("diagnostic outcomes are not exhaustive for accepted/reachable-failure states")

    print("mad4b.wpml-external-diagnostic.contract.v1: PASS")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"mad4b.wpml-external-diagnostic.contract.v1: FAIL: {exc}", file=sys.stderr)
        raise
