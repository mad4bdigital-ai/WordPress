#!/usr/bin/env python3
from pathlib import Path
import json
root=Path(__file__).resolve().parents[1]
main=(root/"mad4b-site-control-plane.php").read_text(encoding="utf-8");abilities=(root/"includes/class-mad4b-scp-abilities.php").read_text(encoding="utf-8");policy=(root/"includes/class-mad4b-scp-legacy-dispatch-migration.php").read_text(encoding="utf-8");cfg=json.loads((root/"config/legacy-dispatch-migration.json").read_text(encoding="utf-8"))
if "class-mad4b-scp-legacy-dispatch-migration.php" not in main: raise SystemExit("FAIL bootstrap")
if abilities.count("MAD4B_SCP_Legacy_Dispatch_Migration::deny(")<2: raise SystemExit("FAIL dispatcher integration")
for m in ["mad4b.dispatch-preparation-required.v2","legacy_execution_allowed","telemetry_metric","sunset_gates","rediscover_and_prepare_signed_identity"]:
 if m not in policy: raise SystemExit("FAIL invariant "+m)
g=cfg.get("sunset_gates",{})
if cfg.get("current_state")!="deny_unprepared" or g.get("schema_only_legacy_admission") is not False or g.get("compatibility_bypass_allowed") is not False or g.get("signed_preparation_required") is not True: raise SystemExit("FAIL fail-closed sunset contract")
print("mad4b.legacy-dispatch-migration.contract.v1: PASS")
