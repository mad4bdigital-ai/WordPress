from pathlib import Path
root=Path(__file__).resolve().parents[1]
durable=(root/"includes/class-mad4b-scp-durable-execution.php").read_text(encoding="utf-8")
profile=(root/"includes/class-mad4b-scp-provider-postcondition-profile.php").read_text(encoding="utf-8")
required_durable=[
 "MAD4B_SCP_Provider_Postcondition_Profile::assert_reconciliation_transition",
 "mad4b_postcondition_profile_required",
 "array $postcondition_observation = array()",
 "'postcondition_observation' => $postcondition_observation",
 "'postcondition_recovery' => $postcondition",
]
for marker in required_durable:
 if marker not in durable: raise SystemExit("FAIL durable-postcondition-binding missing: "+marker)
for marker in ["assert_reconciliation_transition","mad4b_postcondition_observation_integrity_invalid","mad4b_postcondition_profile_stale","mad4b_postcondition_transition_unproven"]:
 if marker not in profile: raise SystemExit("FAIL durable-postcondition-profile guard missing: "+marker)
print("mad4b.durable-postcondition-binding.contract.v1: PASS")
