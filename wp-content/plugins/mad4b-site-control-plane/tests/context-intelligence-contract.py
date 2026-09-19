from pathlib import Path

root = Path(__file__).resolve().parents[1]
intelligence = (root / "includes/class-mad4b-scp-context-intelligence.php").read_text(encoding="utf-8")
adapter = (root / "includes/adapters/class-mad4b-scp-context-adapter.php").read_text(encoding="utf-8")
main = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")

required_contracts = [
    "mad4b.context-conflict-report.v1",
    "mad4b.writer-reference-profile.v1",
    "mad4b.context-retrieval-ranking.v1",
    "mad4b.brand-compliance-report.v1",
]
for marker in required_contracts:
    if marker not in intelligence:
        raise SystemExit(f"Missing Context Intelligence contract: {marker}")

for ability in [
    "context/conflicts",
    "context/reference-profile",
    "context/retrieve",
    "context/compliance-check",
]:
    if ability not in adapter:
        raise SystemExit(f"Missing Context Intelligence ability: {ability}")

write_block = adapter.split("'write' => array(", 1)[1].split("'admin' => array(", 1)[0]
for ability in [
    "context/conflicts",
    "context/reference-profile",
    "context/retrieve",
    "context/compliance-check",
]:
    if ability in write_block:
        raise SystemExit(f"Read-only Context Intelligence ability leaked into write surface: {ability}")

for forbidden in [
    "wp_remote_post(",
    "wp_remote_request(",
    "update_option(",
    "add_option(",
    "delete_option(",
    "wp_insert_post(",
    "wp_update_post(",
]:
    if forbidden in intelligence:
        raise SystemExit(f"Context Intelligence must remain read-only; found {forbidden}")

for marker in [
    "validate_receipt_binding",
    "explicit_machine_readable_rules_only",
    "semantic_model_used",
    "human_required",
    "auto_resolution",
    "structural_reference_only",
    "imitation_instruction_allowed",
    "mandatory_assets",
    "ranked_optional_assets",
    "coverage_complete",
    "conflict_provider_read_limit_reached",
    "compliance_rule_limit_reached",
]:
    if marker not in intelligence:
        raise SystemExit(f"Missing fail-closed/explainability marker: {marker}")

if "class-mad4b-scp-context-intelligence.php" not in main:
    raise SystemExit("Context Intelligence runtime is not loaded by the plugin entrypoint")

print("mad4b.context-intelligence.contract.v2: PASS")
