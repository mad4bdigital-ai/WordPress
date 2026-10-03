from pathlib import Path
root=Path(__file__).resolve().parents[1]
elig=(root/"includes/class-mad4b-scp-provider-transport-eligibility.php").read_text(encoding="utf-8")
breaker=(root/"includes/class-mad4b-scp-provider-circuit-breaker.php").read_text(encoding="utf-8")
connector=(root/"includes/class-mad4b-scp-connector-resilience.php").read_text(encoding="utf-8")
main=(root/"mad4b-site-control-plane.php").read_text(encoding="utf-8")
required=[
 "kill_switch","authority","quarantine","certification","release_ring","breaker",
 "R0_DISPOSABLE","R1_CANARY_STAGING","R2_SELECTED_STAGING","R3_GENERAL_STAGING","R4_PRODUCTION_ELIGIBLE",
 "ACTIVE may imply general","never Production eligibility","blind_retry_allowed",
]
for marker in required:
 if marker not in elig: raise SystemExit("FAIL provider transport eligibility marker missing: "+marker)
if "public static function target_context" not in breaker:
 raise SystemExit("FAIL provider breaker target context is not reusable")
if "class-mad4b-scp-provider-transport-eligibility.php" not in main:
 raise SystemExit("FAIL provider transport eligibility is not loaded")
pre="MAD4B_SCP_Provider_Transport_Eligibility::preflight_mutation"
brk="MAD4B_SCP_Provider_Circuit_Breaker::begin_for_target"
fin="MAD4B_SCP_Provider_Transport_Eligibility::finalize_breaker"
if pre not in connector or brk not in connector or fin not in connector:
 raise SystemExit("FAIL connector mutation transport is not bound to eligibility precedence")
if not (connector.index(pre)<connector.index(brk)<connector.index(fin)):
 raise SystemExit("FAIL strongest static deny is not evaluated before breaker transport admission")
print("mad4b.provider-transport-eligibility.contract.v1: PASS")
