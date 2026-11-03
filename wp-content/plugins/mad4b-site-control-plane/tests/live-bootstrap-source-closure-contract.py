#!/usr/bin/env python3
"""Release source closure for the rc118 forward port; no WordPress side effects."""
from pathlib import Path
import re

plugin = Path(__file__).resolve().parents[1]
repo = plugin.parents[2]
main = (plugin / "mad4b-site-control-plane.php").read_text(encoding="utf8")
pattern = re.compile(r"""require_once\s+MAD4B_SCP_DIR\s*\.\s*['"]([^'"]+)['"]""")
requires = set(pattern.findall(main))
assert len(requires)>=500, ("missing_live_bootstrap", len(requires))
for path in requires:
    assert (plugin/path).is_file(),("bootstrap_missing",path)

for f in (plugin/"includes").rglob("*.php"):
    code=f.read_text(encoding="utf8")
    for dependency in re.findall(r"""require_once\s+__DIR__\s*\.\s*['"](/?[^'"]+\.php)['"]""",code):
        assert (f.parent/dependency.lstrip("/")).is_file(),("transitive_missing",f,dependency)

must_have=[
    "class-mad4b-scp-geo-quality.php",
    "class-mad4b-scp-locale-experience-alerts.php",
    "class-mad4b-scp-readiness-team-preparation.php",
    "class-mad4b-scp-readiness-event-automation.php",
    "adapters/class-mad4b-scp-chatbotx-governed-writes.php",
    "adapters/class-mad4b-scp-chatbotx-write-preflight.php",
]
for name in must_have:
    assert (plugin/"includes"/name).is_file(),("provider_missing",name)

readiness=(plugin/"includes/class-mad4b-scp-content-readiness-alerts.php").read_text()
assert len(readiness.splitlines())<=900
assert "mad4b/content_readiness/provider_job_intake" in readiness
assert "mad4b/content_readiness/valid_pricing_alert" in readiness
assert "class-mad4b-scp-content-readiness-admin-view.php" in readiness
assert "class-mad4b-scp-geo-quality.php" in readiness
assert "class-mad4b-scp-locale-experience-alerts.php" in readiness
assert "MAD4B_SCP_Content_Readiness_Admin_View::render()" in readiness

for adapter in [
    "mad4b-readiness-tour-origin-evidence.php",
    "mad4b-readiness-pricing-geo-locale-adapter.php",
    "mad4b-site-company-transport-adapter.php",
]:
    assert (repo/"wp-content/mu-plugins"/adapter).is_file(),("mu_adapter_missing",adapter)
assert ("class-mad4b-scp-private-operations-boundary.php" in main
        and main.index("class-mad4b-scp-private-operations-boundary.php") <
        main.index("MAD4B_SCP_VERSION")), "private boundary must precede fastpaths"
assert "MAD4B_SCP_Frontend_Impact_Priority::boot()" in main
assert "MAD4B_SCP_Content_Readiness_Alerts::boot()" in main
assert "class-mad4b-scp-readiness-preactivation.php" in main

company=(plugin/"includes/class-mad4b-scp-company-local-wpcli.php").read_text()
assert "mad4b/company/local_wpcli_identity" in company
assert "central_staging_scope_mismatch" in company
assert "MAD4B_SCP_Operation_Pipeline::compile" in company

print("MAD4B_LIVE_SOURCE_CLOSURE: PASS "
      f"(bootstrap_deps={len(requires)}, bounded_readiness, "
      "read-only providers, preserved private operations fence)")
