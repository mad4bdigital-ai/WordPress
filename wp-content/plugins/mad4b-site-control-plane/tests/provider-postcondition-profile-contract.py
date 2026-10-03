from pathlib import Path
import json,re
ROOT=Path(__file__).resolve().parents[1]
cfg=json.loads((ROOT/"config/provider-postcondition-profiles.json").read_text(encoding="utf-8"))
if cfg.get("contract")!="mad4b.provider-postcondition-profiles.v1":
    raise SystemExit("FAIL postcondition-profile-contract: catalog contract drift")
profiles=cfg.get("profiles") or {}
if len(profiles)<15:
    raise SystemExit("FAIL postcondition-profile-contract: certified family catalog unexpectedly small")

adapter_files={
 "core-content-modeling":"adapters/class-mad4b-scp-core-content-modeling-adapter.php",
 "seo":"adapters/class-mad4b-scp-seo-adapter.php",
 "woocommerce":"adapters/class-mad4b-scp-woocommerce-adapter.php",
 "media":"adapters/class-mad4b-scp-media-adapter.php",
 "elementor":"adapters/class-mad4b-scp-elementor-adapter.php",
 "jetengine":"adapters/class-mad4b-scp-jetengine-adapter.php",
}
for ability,row in sorted(profiles.items()):
    rtype=row.get("reader_type")
    if rtype=="mutation_manager":
        src=(ROOT/"includes/class-mad4b-scp-mutation-manager.php").read_text(encoding="utf-8")
        if ability!="mad4b/content-update-post" or "public static function postcondition_state(" not in src:
            raise SystemExit("FAIL postcondition-profile-contract: mutation-manager profile lacks exact reader: "+ability)
        continue
    if rtype!="adapter":
        raise SystemExit("FAIL postcondition-profile-contract: unsupported reader type: "+ability)
    adapter=row.get("adapter_id","")
    rel=adapter_files.get(adapter)
    if not rel:
        raise SystemExit("FAIL postcondition-profile-contract: unknown adapter profile: "+adapter)
    src=(ROOT/"includes"/rel).read_text(encoding="utf-8")
    if ability not in src:
        raise SystemExit("FAIL postcondition-profile-contract: ability not declared in adapter source: "+ability)
    if "read_reversible_state(" not in src:
        raise SystemExit("FAIL postcondition-profile-contract: adapter has no authoritative readback implementation: "+ability)
    if "reversible_contracts(" not in src:
        raise SystemExit("FAIL postcondition-profile-contract: adapter has no reversible contract surface: "+ability)

profile_src=(ROOT/"includes/class-mad4b-scp-provider-postcondition-profile.php").read_text(encoding="utf-8")
for marker in (
 "reader_not_certified","retry_requires_fresh_plan_and_authorization",
 "blind_retry_allowed","reconciliation_required","retry_reclaim_eligible",
):
    if marker not in profile_src:
        raise SystemExit("FAIL postcondition-profile-contract: recovery invariant missing: "+marker)
resilience=(ROOT/"includes/class-mad4b-scp-connector-resilience.php").read_text(encoding="utf-8")
if "postcondition_recovery" not in resilience or "automatic_retry_performed' => false" not in resilience:
    raise SystemExit("FAIL postcondition-profile-contract: connector mutation recovery is not postcondition-bound/fail-closed")
print("mad4b.provider-postcondition-profile.contract.v1: PASS")
