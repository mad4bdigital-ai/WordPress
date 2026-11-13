#!/usr/bin/env python3
"""Bounded source-and-PHP evidence for cross-environment guided plans."""
from pathlib import Path
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
include = root / "includes"
read = {
    "model": (include / "class-mad4b-scp-environment-journey.php").read_text(encoding="utf-8"),
    "abilities": (include / "class-mad4b-scp-abilities.php").read_text(encoding="utf-8"),
    "servers": (include / "class-mad4b-scp-servers.php").read_text(encoding="utf-8") + (include / "class-mad4b-scp-server-core-catalog.php").read_text(encoding="utf-8"),
}
for code in (
    "'mad4b/environment-journey'",
    "'environment_journey', 'read'",
    "MAD4B_SCP_Environment_Journey::read( $input )",
):
    if code not in read["abilities"]:
        raise SystemExit("ENV_JOURNEY: ABILITY_NOT_REGISTERED")
for code in ("'mad4b/environment-journey'",):
    if read["servers"].count(code) < 3:
        raise SystemExit("ENV_JOURNEY: MISSING_READ_OR_CHATGPT_MOUNT")
for code in (
    "MAD4B_SCP_Site_Profile::environment_resolution()",
    "MAD4B_SCP_Site_Profile::site_urls_match_enrollment()",
    "MAD4B_SCP_Site_Profile::origin_enrolled()",
    "MAD4B_SCP_Production_Automation::active()",
    "MAD4B_SCP_Enrollment_Dispatch::discover(",
    "'production_exact_owner_batch_and_signed_test_certificate'",
    "'automatic_execution' => false",
    "'blind_retry_allowed' => false",
):
    if code not in read["model"]:
        raise SystemExit("ENV_JOURNEY: MISSING_FAIL_CLOSED_POLICY: " + code)
# The model must infer its environment locally; never accept a caller's
# environment or a free-form hostname as authorization input.
if "'environment' =>" in read["abilities"].split("'mad4b/environment-journey'", 1)[1].split("), false, true, false, true", 1)[0]:
    raise SystemExit("ENV_JOURNEY: CLIENT_SUPPLIED_ENVIRONMENT_FORBIDDEN")
script = root / "tests" / "environment-journey-runtime.php"
try:
    result = subprocess.run(["php", str(script)], cwd=str(root),
                            capture_output=True, text=True, timeout=30, check=False)
except (OSError, subprocess.TimeoutExpired):
    raise SystemExit("ENV_JOURNEY: PHP_RUNTIME_UNAVAILABLE_OR_TIMEOUT")
if result.returncode or "MAD4B_ENVIRONMENT_JOURNEY: PASS 28" not in result.stdout:
    print((result.stderr + result.stdout)[-1600:], file=sys.stderr)
    raise SystemExit("ENV_JOURNEY: FAIL")
print("ENV_JOURNEY: PASS; all environment×operation routes read-only with separate Production and Staging authority")
