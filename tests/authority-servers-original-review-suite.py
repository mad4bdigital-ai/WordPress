#!/usr/bin/env python3
"""Non-authorizing exact original MAD4B Servers, Runtime and Write regression.

Only the enumerated hermetic first-party fixtures run. Each fixture requires
its actual source, uses no WordPress runtime or enrolled production database,
and must report a positive assertion marker.
"""
from pathlib import Path
import re
import shutil
import subprocess

ROOT = Path(__file__).resolve().parents[1]

# (review-bound test path, real first-party source, success marker)
FIXTURES = (
    ("tests/execution-authority-identity-native-hermetic.php",
     "class-mad4b-scp-execution-authority.php", "PASS: EXECUTION_AUTHORITY_IDENTITY_NATIVE"),
    ("tests/authority-auto-reconcile-host-hermetic.php",
     "class-mad4b-scp-authority-auto-reconciliation.php", "PASS: AUTHORITY_AUTO_RECONCILE_HOST"),
    ("tests/staging-host-candidate-gate-native-hermetic.php",
     "class-mad4b-scp-staging-write-candidate-binding.php", "PASS: HOST_CANDIDATE_NHI_GATE"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/staging-write-candidate-binding-runtime.php",
     "class-mad4b-scp-staging-write-candidate-binding.php",
     "mad4b.staging-write-candidate-binding.runtime.v2: PASS"),
    ("tests/native-capability-registry-adapter-first-party.php",
     "class-mad4b-scp-adapter-registry.php", "PASS: NATIVE_CAPABILITY_ADAPTER_REGISTRY"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/deployment-binding-native-runtime.php",
     "class-mad4b-scp-deployment-binding-native.php",
     "mad4b.site-control-plane.deployment-binding-native.runtime.v1: PASS"),
    ("tests/servers-original-native-transport-hermetic.php",
     "class-mad4b-scp-servers.php", "PASS: SERVERS_NATIVE_TRANSPORT"),
    ("tests/server-registration-descriptor-boundary-hermetic.php",
     "class-mad4b-scp-server-registration-evidence.php", "PASS: SERVER_DESCRIPTOR_EXACT_BINDING"),
    ("tests/staging-write-delegated-identity-native-hermetic.php",
     "class-mad4b-scp-staging-write-authority.php", "PASS: STAGING_DELEGATED_EXACT_POLICY"),
    ("tests/staging-write-host-candidate-native-hermetic.php",
     "class-mad4b-scp-staging-write-authority.php", "PASS: STAGING_HOST_CANDIDATE_CONTEXT"),
    ("tests/runtime-convergence-owner-gate-native-hermetic.php",
     "class-mad4b-scp-runtime-convergence.php", "PASS: RUNTIME_CONVERGENCE_OWNER_GATE"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/canonical-capability-descriptor-consumers-runtime.php",
     "class-mad4b-scp-servers.php",
     "mad4b.canonical-capability-descriptor-consumers.runtime.v1: PASS"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/runtime-superseded-restart-checkpoint-runtime.php",
     "class-mad4b-scp-runtime-convergence.php", "RUNTIME_SUPERSEDED_RESTART_CHECKPOINT: PASS"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/runtime-historical-read-isolation-runtime.php",
     "class-mad4b-scp-runtime-convergence.php", "RUNTIME_HISTORICAL_READ_ISOLATION: PASS"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/runtime-restart-grace-readonly-runtime.php",
     "class-mad4b-scp-runtime-convergence.php", "RUNTIME_RESTART_GRACE_READONLY: PASS"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/staging-write-grant-canonical-order-runtime.php",
     "class-mad4b-scp-staging-write-authority.php", "STAGING_OWNER_GRANT_ORDER_PARITY: PASS"),
    ("wp-content/plugins/mad4b-site-control-plane/tests/write-dispatch-scope-delegation-runtime.php",
     "class-mad4b-scp-staging-write-authority.php",
     "mad4b.write-dispatch-scope-delegation.runtime.v4: PASS"),
)

def main():
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP native CLI required, no lint-only fallback")
    successes = []
    for name, source, marker in FIXTURES:
        path = ROOT / name
        assert path.is_file() and not path.is_symlink(), "Unreviewed PHP file path"
        contents = path.read_text(encoding="utf-8", errors="replace")
        assert source in contents, "Original source not loaded: " + name
        result = subprocess.run(
            [php, str(path)], cwd=ROOT, capture_output=True, text=True,
            timeout=60, check=False,
        )
        combined = result.stdout + "\n" + result.stderr
        if (result.returncode != 0 or marker not in result.stdout
                or re.search(r"(?m)^(?:FAIL[: ]|Fatal error:|Warning:|Parse error:)",
                             combined)):
            raise AssertionError(f"{name} failed on original source:\n" + combined[-2200:])
        successes.append(name)
    assert len(successes) == len(FIXTURES)
    print("PASS: AUTHORITY_SERVERS_ORIGINAL_SUITE "
          + str(len(successes)) + " actual-source hermetic PHP fixtures")


if __name__ == "__main__":
    main()
