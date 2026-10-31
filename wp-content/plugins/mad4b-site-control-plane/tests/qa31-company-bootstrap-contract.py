#!/usr/bin/env python3
"""QA31 source guard: universally load runtime; never promote site URL into execution authority."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
main=(root/"mad4b-site-control-plane.php").read_text()
governance=(root/"includes/class-mad4b-scp-company-readiness-governance.php").read_text()
runtime=(root/"includes/class-mad4b-scp-start-your-company-runtime.php").read_text()
start=main.index("// Install the runtime on every MAD4B site")
end=main.index("class-mad4b-scp-remote-operation-parity.php",start)
segment=main[start:end]
assert "DB_NAME" not in segment and "tours.dev.mad4b.com" not in segment
for item in ("class_exists('MAD4B_Company_Readiness_Governance', false)",
             "class_exists('MAD4B_Start_Company_Runtime', false)",
             "class-mad4b-scp-company-readiness-governance.php",
             "class-mad4b-scp-start-your-company-runtime.php"):
    assert item in segment, item
assert segment.index("company-readiness-governance.php") < segment.index("start-your-company-runtime.php")
for text in (governance,runtime):
    assert "DB_NAME==='etg_commerce_staging'" not in text
    assert "DB_NAME === 'etg_commerce_staging'" not in text
    assert "tours.dev.mad4b.com" not in text
    assert "FILTER_VALIDATE_DOMAIN" in text and "FILTER_VALIDATE_IP" in text
assert "mad4b_manage_company_operations" in governance
assert "mad4b_company_cross_tenant_denied" in governance
assert "MAD4B_Company_Readiness_Governance::guard($r)" in runtime
assert "company_site_uuid" in runtime and "host_binding_verified" in runtime
assert "current_user_can('activate_plugins')" in runtime
print("QA31_COMPANY_BOOTSTRAP_CONTRACT: PASS")
