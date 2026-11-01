#!/usr/bin/env python3
"""Execute a bounded operational error regression matrix on exact Git HEAD.

Hermetic fixtures only: actual live ETG/ARE acceptance remains independent.
No network, administrator login, site write, owner claim, or Production action.
Tests remain fixed repo-owned paths and are subprocess-executed, not only linted.
"""
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parent
# Every path is repository-owned; callers cannot supply arbitrary scripts.
FIXTURES = (
    "enrollment-dispatch-runtime.php",
    "capability-traits-contract.php",
    "ci-outage-dynamic-enrollment-runtime.php",
    "governed-draft-contract.php",
    "imp01-import-preview-runtime.php",
    "wp-native-self-approval-runtime.php",
    "dynamic-first-party-enrollment-runtime.php",
    "staging-write-owner-intent-runtime.php",
    "staging-write-owner-transport-runtime.php",
    "staging-write-one-step-review-runtime.php",
    "staging-write-authority-postcondition-runtime.php",
    "frontend-origin-diagnostic-runtime.php",
    "native-incident-mu-runtime.php",
    "native-incident-early-runtime.php",
    "mcp-connection-failsoft-runtime.php",
    "session-safe-transport-resilience-runtime.php",
    "error-contract-registry-runtime.php",
    "connector-resilience-runtime.php",
    "mcp-preflight-registration-failure-runtime.php",
    "wpml-response-contract-runtime.php",
    "live-acceptance-wpml-authority-runtime.php",
    "live-acceptance-wpml-composition-runtime.php",
)


def main():
    # Regression for the changed MCP Read-server facade. Trait metadata must
    # mount only when registered, and never create independent write authority.
    servers = (ROOT.parent / "includes" / "class-mad4b-scp-servers.php").read_text()
    start = servers.find("public static function core_tools( $server_id )")
    end = servers.find("// Feature 007 operator diagnostics", start)
    trait_mount = servers[start:end] if start >= 0 and end > start else ""
    required = (
        "'mad4b-read' === $server_id",
        "class_exists( 'MAD4B_SCP_Capability_Traits', false )",
        "'mad4b/capability-trait-profile'",
        "'mad4b/capability-trait-resolve'",
        "function_exists( 'wp_has_ability' )",
        "wp_has_ability( $trait_ability )",
    )
    if not all(item in trait_mount for item in required):
        print("MAD4B_OPERATIONAL_ERROR_REGRESSION_FAIL: read-trait mount gate", file=sys.stderr)
        return 1
    if "mad4b-write" in trait_mount or "register_mutation" in trait_mount:
        print("MAD4B_OPERATIONAL_ERROR_REGRESSION_FAIL: read-trait authority leak", file=sys.stderr)
        return 1
    print("MAD4B_OPERATIONAL_ERROR_REGRESSION_PASS: bounded registered read-trait mounts")
    for name in FIXTURES:
        path = (ROOT / name).resolve()
        if path.parent != ROOT or not path.is_file():
            print("MAD4B_OPERATIONAL_ERROR_REGRESSION_FAIL: missing trusted fixture", file=sys.stderr)
            return 2
        try:
            result = subprocess.run(
                ["php", str(path)], cwd=ROOT, capture_output=True,
                text=True, check=False, timeout=60,
            )
        except (OSError, subprocess.TimeoutExpired):
            print("MAD4B_OPERATIONAL_ERROR_REGRESSION_FAIL: PHP unavailable or timeout", file=sys.stderr)
            return 2
        if result.returncode != 0:
            print("MAD4B_OPERATIONAL_ERROR_REGRESSION_FAIL: " + name, file=sys.stderr)
            print((result.stdout + "\n" + result.stderr)[-2000:], file=sys.stderr)
            return 1
        print("MAD4B_OPERATIONAL_ERROR_REGRESSION_PASS: " + name)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
