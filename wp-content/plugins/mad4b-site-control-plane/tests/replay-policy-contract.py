from pathlib import Path
root=Path(__file__).resolve().parents[1]
main=(root/"mad4b-site-control-plane.php").read_text(encoding="utf-8")
prep=(root/"includes/class-mad4b-scp-preparation-receipt.php").read_text(encoding="utf-8")
abilities=(root/"includes/class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
policy=(root/"includes/class-mad4b-scp-replay-policy.php").read_text(encoding="utf-8")
config=(root/"config/replay-policy.json").read_text(encoding="utf-8")

for marker in ["class-mad4b-scp-preparation-receipt.php","class-mad4b-scp-replay-policy.php"]:
    if marker not in main: raise SystemExit("FAIL replay-policy bootstrap missing "+marker)
if main.index("class-mad4b-scp-preparation-receipt.php") > main.index("class-mad4b-scp-replay-policy.php"):
    raise SystemExit("FAIL replay policy loads before preparation receipt")
for marker in ["replay_policy_sha256","public static function claims(","MAD4B_SCP_Replay_Policy::policy_sha256"]:
    if marker not in prep: raise SystemExit("FAIL preparation receipt replay binding missing "+marker)
for marker in ["idempotency_key","MAD4B_SCP_Replay_Policy::begin(","MAD4B_SCP_Replay_Policy::complete(","replayed","replay_provider"]:
    if marker not in abilities: raise SystemExit("FAIL governed dispatcher replay integration missing "+marker)
for marker in ["MAD4B_SCP_Durable_Execution::begin_idempotency","MAD4B_SCP_Durable_Execution::complete_idempotency","reusable_same_idempotency","single_use","mad4b_preparation_replay_binding_conflict","blind_retry_allowed"]:
    if marker not in policy and marker not in config: raise SystemExit("FAIL replay policy invariant missing "+marker)
if "add_option(" in policy or "update_option(" in policy or "$wpdb" in policy:
    raise SystemExit("FAIL replay policy created parallel persistence instead of Durable Execution idempotency")
print("mad4b.replay-policy.contract.v1: PASS")
