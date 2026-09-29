#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"
rest = (PLUGIN / "includes/class-mad4b-scp-rest-compatibility.php").read_text(encoding="utf-8")
runtime = (PLUGIN / "includes/class-mad4b-scp-runtime-convergence.php").read_text(encoding="utf-8")

assert "MAD4B_SCP_External_WPML_Acceptance_Finalizer::external_wpml_receipt_status" in rest
assert "bounded_external_wpml_receipt" in rest
assert "'external_wpml_acceptance_required' => $external_wpml_required" in rest
assert "'external_wpml_acceptance_verified' => $external_wpml_verified" in rest
assert "'external_http_probe_performed' => false" in rest
assert "'external_wpml_acceptance_verified' => false" not in rest

# Runtime convergence must consume the canonical REST projection rather than
# independently interpreting the receipt and creating a second truth source.
assert "external_wpml_acceptance_verified" in runtime
assert "external_wpml_acceptance_required" in runtime
assert "MAD4B_SCP_External_WPML_Acceptance_Finalizer::" not in runtime

print("REST external WPML receipt reconciliation contract: PASS")
