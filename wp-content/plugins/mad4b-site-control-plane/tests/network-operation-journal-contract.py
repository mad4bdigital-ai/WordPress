from pathlib import Path
root=Path(__file__).resolve().parents[1]
schema=(root/"includes/class-mad4b-scp-schema.php").read_text(encoding="utf-8")
impl=(root/"includes/class-mad4b-scp-network-operation-journal.php").read_text(encoding="utf-8")
main=(root/"mad4b-site-control-plane.php").read_text(encoding="utf-8")
for marker in ["network_operations","network_operation_targets","network_operation_events","origin_idempotency","operation_target","target_idempotency","operation_sequence"]:
    if marker not in schema: raise SystemExit("FAIL network-operation schema missing "+marker)
for marker in [
    "class MAD4B_SCP_Network_Operation_Journal","function create(","function claim_target(","function record_target_outcome(",
    "function pause(","function resume(","function reconstruct(","function verify_target_context(",
    "target_binding_sha256","credential_binding_sha256","receipt_binding_sha256","catalog_sha256","approval_ticket_id","context_sha256",
    "network_target_terminal_immutable","network_target_claim_epoch_stale","replay_committed_allowed","FOR UPDATE","event_sha256"
]:
    if marker not in impl: raise SystemExit("FAIL network-operation invariant missing "+marker)
if "class-mad4b-scp-network-operation-journal.php" not in main:
    raise SystemExit("FAIL network-operation runtime is not bootstrapped")
for forbidden in ["switch_to_blog(", "wp_set_current_user(", "Authorization: Bearer", "client_secret"]:
    if forbidden in impl: raise SystemExit("FAIL network journal owns site credential/identity transport: "+forbidden)
print("mad4b.network-operation-journal.contract.v1: PASS")
