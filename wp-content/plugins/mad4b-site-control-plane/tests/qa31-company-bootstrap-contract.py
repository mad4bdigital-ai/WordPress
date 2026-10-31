#!/usr/bin/env python3
"""QA31 source guard: central company runtime only on exact central host and DB."""
from pathlib import Path
s=(Path(__file__).resolve().parents[1]/"mad4b-site-control-plane.php").read_text()
anchor="require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-remote-work-queue.php';"
end="require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-remote-operation-parity.php';"
assert s.count("Central-only company onboarding")==1
part=s.split(anchor,1)[1].split(end,1)[0]
for expected in ("defined('DB_NAME')", "DB_NAME === 'etg_commerce_staging'",
                 "wp_parse_url(home_url(), PHP_URL_HOST) === 'tours.dev.mad4b.com'",
                 "class_exists('MAD4B_Company_Readiness_Governance', false)",
                 "class_exists('MAD4B_Start_Company_Runtime', false)",
                 "class-mad4b-scp-company-readiness-governance.php",
                 "class-mad4b-scp-start-your-company-runtime.php"):
    assert expected in part, "missing bootstrap scope: "+expected
assert part.index("class-mad4b-scp-company-readiness-governance.php") < part.index("class-mad4b-scp-start-your-company-runtime.php")
print("QA31_COMPANY_BOOTSTRAP_CONTRACT: PASS")
