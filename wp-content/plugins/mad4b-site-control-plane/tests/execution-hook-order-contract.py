from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
authorization = (root / "includes/class-mad4b-scp-authorization.php").read_text(encoding="utf-8")
errors = (root / "includes/class-mad4b-scp-error-contract-registry.php").read_text(encoding="utf-8")
fence = (root / "includes/class-mad4b-scp-execution-fence.php").read_text(encoding="utf-8")
main = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")

required = {
    "authorization_pre_boundary": (authorization, r"add_filter\(\s*'wp_register_ability_args'\s*,\s*array\(\s*__CLASS__\s*,\s*'wrap_execution_boundary'\s*\)\s*,\s*190\s*,\s*2\s*\)"),
    "error_contract_normalization": (errors, r"add_filter\(\s*'wp_register_ability_args'\s*,\s*array\(\s*__CLASS__\s*,\s*'wrap_ability_args'\s*\)\s*,\s*195\s*,\s*2\s*\)"),
    "same_request_fence": (fence, r"add_filter\(\s*'wp_register_ability_args'\s*,\s*array\(\s*__CLASS__\s*,\s*'wrap_governed_write'\s*\)\s*,\s*200\s*,\s*2\s*\)"),
    "final_execution_admission": (fence, r"add_filter\(\s*'wp_register_ability_args'\s*,\s*array\(\s*__CLASS__\s*,\s*'wrap_final_execution_admission'\s*\)\s*,\s*PHP_INT_MAX\s*,\s*2\s*\)"),
}
for name, (source, pattern) in required.items():
    if not re.search(pattern, source):
        raise SystemExit("HOOK_ORDER_INVARIANT_MISSING:" + name)

if "propagate_trusted_execution_boundary" not in errors:
    raise SystemExit("HOOK_ORDER_ERROR_WRAPPER_PROVENANCE_PROPAGATION_MISSING")

include_error = main.find("class-mad4b-scp-error-contract-registry.php")
include_auth = main.find("class-mad4b-scp-authorization.php")
boot_auth = main.find("MAD4B_SCP_Authorization::boot();")
boot_error = main.find("MAD4B_SCP_Error_Contract_Registry::boot();")
include_fence = main.find("class-mad4b-scp-execution-fence.php")
if min(include_error, include_auth, boot_auth, boot_error, include_fence) < 0:
    raise SystemExit("HOOK_ORDER_BOOTSTRAP_MARKER_MISSING")
if not (include_error < include_auth < boot_auth < boot_error < include_fence):
    raise SystemExit(
        "HOOK_ORDER_BOOTSTRAP_DRIFT:"
        + ":".join(str(x) for x in (include_error, include_auth, boot_auth, boot_error, include_fence))
    )

priorities = [190, 195, 200]
if priorities != sorted(priorities) or not all(a < b for a, b in zip(priorities, priorities[1:])):
    raise SystemExit("HOOK_ORDER_PRIORITY_ORDER_INVALID")

print("mad4b.execution-hook-order.contract.v1: PASS priorities=190<195<200<PHP_INT_MAX")
