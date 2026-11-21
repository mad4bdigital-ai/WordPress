#!/usr/bin/env python3
from pathlib import Path
import re
import json

root = Path(__file__).resolve().parents[1]
scp = root / "wp-content/plugins/mad4b-site-control-plane"
main = (scp / "mad4b-site-control-plane.php").read_text()
public = (scp / "includes/class-mad4b-scp-public-document-kernel.php").read_text()

must_before = (
    "class-mad4b-scp-ai-only-early-auth-guard.php",
    "class-mad4b-scp-catalog-lifecycle.php",
    "class-mad4b-scp-tour-highlights-v1.php",
)
kernel = "class-mad4b-scp-public-document-kernel.php"
heavy = "class-mad4b-scp-admin-route-registry.php"
assert main.count(kernel) == 1 and main.count("MAD4B_SCP_Public_Document_Kernel::try_boot()") == 1
for fence in must_before:
    assert main.index(fence) < main.index(kernel), fence
assert main.index(kernel) < main.index(heavy)
assert main.index("MAD4B_SCP_Public_Document_Kernel::try_boot()") < main.index(heavy)
assert "MAD4B_SCP_FORCE_FULL_BOOT" in public
assert "private_headers" in public and "query_string" in public
assert "deployment_binding_match" in public and "origin_match" in public
assert "MAD4B_SCP_Company_Operations_Policy" in public
assert "MAD4B_SCP_Market_Context::boot();" in public
assert "MAD4B_SCP_Market_Preferences::boot();" in public
assert "MAD4B_SCP_MCP_Request_Scope::bootstrap();" in public
assert "MAD4B_SCP_Season_Calendar::boot();" not in public
assert "MAD4B_SCP_Site_Profile::status()" in public
assert "mad4b_scp_public_document_kernel_mode_v1" in public
assert "mad4b_scp_public_document_kernel_prefixes_v1" in public
assert "mad4b_scp_public_document_kernel_verified_paths_v1" in public
assert "mutation_pending_audit" in public and "host_bootstrap_required" in public
assert "untrusted_market_header" in public and "speculative_navigation" in public
assert not any(x in public for x in ("wayfatours.com", "tours.dev", "tours-and-activities"))
manifest=json.loads((scp / "config/public-document-kernel-manifest-v1.json").read_text())
assert manifest["contract"]=="mad4b.scp-public-document-kernel-manifest.v1"
assert manifest["default"]=="off"
assert len(manifest["modules"])==8
assert len(set(m["module"] for m in manifest["modules"]))==8
for component in manifest["modules"]:
    file=component["file"]
    assert (scp / "includes" / file).is_file(), file
    assert file in public, file
assert [i["module"] for i in manifest["modules"] if i["boot"]=="definition_only"]==["switcher_sources","season_calendar"]
assert all(x in manifest["inadmissible"] for x in ("admin","rest","cron","cli","auth","cookies"))
assert "MAD4B_SCP_Season_Calendar::boot();" not in public
assert "MAD4B_SCP_Company_Operations_Policy::boot();" not in public  # file-scope boot owns the fences
assert "update_option(" not in public and "wp_insert_post(" not in public
assert "file_put_contents(" not in public and "wp_remote_post(" not in public
assert re.search(r"if\s*\(\s*MAD4B_SCP_Public_Document_Kernel::try_boot\(\)\s*\)\s*\{\s*return;",main)
# This is a separate source module and does not rewrite vendor WordPress, Woo,
# WPML, JetEngine, Nginx, HTML cache, host grants or receipt policy.
print("SCP source public document kernel contract: PASS")
