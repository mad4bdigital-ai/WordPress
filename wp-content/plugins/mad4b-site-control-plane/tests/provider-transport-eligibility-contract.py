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
mutation_marker="private static function execute_mutation_impl"
if mutation_marker not in connector:
 raise SystemExit("FAIL connector mutation implementation is missing")
mutation=connector.split(mutation_marker,1)[1]
if "\n\tprivate static function" in mutation:
 mutation=mutation.split("\n\tprivate static function",1)[0]
if pre not in mutation or brk not in mutation or fin not in mutation:
 raise SystemExit("FAIL connector mutation implementation lost eligibility/breaker stages")
if not (mutation.index(pre)<mutation.index(brk)<mutation.index(fin)):
 raise SystemExit("FAIL strongest static deny is not evaluated before breaker transport admission")
# The repair diagnosis must inspect exact provider capability evidence without
# turning a read request into a mutation admission or simulated owner approval.
for evidence in (
 "public static function inspect_target_certification(",
 "'contract' => 'mad4b.provider-transport-static-inspection.v1'",
 "'authorization_checked' => false",
 "'breaker_checked' => false",
 "'transport_eligible' => null",
 "'authorizing' => false",
 "'mutation_performed' => false",
 "'reason_code' => $reason",
 "certify_exact_provider_capability",
 "review_exact_release_ring",
 "verify_separate_write_authority_and_breaker",
):
 if evidence not in elig: raise SystemExit("FAIL nonauthorizing source scan inspection marker missing: "+evidence)
inspect = elig.split("public static function inspect_target_certification(",1)[1].split("\n\tpublic static function resolve_facts(",1)[0]
for forbidden in ("begin_for_target(", "finalize_breaker(", "wp_remote_post(", "update_option(", "MAD4B_SCP_Authorization::authorize(", "execute_mutation("):
 if forbidden in inspect: raise SystemExit("FAIL read-only inspection has mutation/authority side effect: "+forbidden)
runtime = (root/"tests/provider-transport-eligibility-runtime.php").read_text(encoding="utf-8")
for fixture in ("provider_capability_not_write_certified","provider_release_ring_ineligible","static_gates_satisfied_only","production"):
 if fixture not in runtime: raise SystemExit("FAIL source scan provider inspection fixture missing: "+fixture)
print("mad4b.provider-transport-eligibility.contract.v1: PASS")
