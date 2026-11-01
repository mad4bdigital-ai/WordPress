#!/usr/bin/env python3
"""Execute the hermetic restore-epoch recovery fixture under trusted CI."""
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "includes/class-mad4b-scp-restore-epoch.php"
FIXTURE = ROOT / "tests/restore-epoch-recovery-e2e-runtime.php"
source = SOURCE.read_text(encoding="utf-8")
for guard in (
    "restore_epoch_database_snapshot_detected",
    "restore_epoch_site_identity_mismatch",
    "restore_epoch_external_missing",
    "DEGRADED_RECOVERABLE",
    "QUARANTINED",
    "recovery_requires_write_disabled",
    "blind_retry_allowed",
    "mutation_performed",
):
    assert guard in source, "Missing source fail-closed marker: " + guard

for args in (["php", "-l", str(FIXTURE)], ["php", str(FIXTURE)]):
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, timeout=45)
    if result.returncode:
        raise SystemExit("RESTORE_EPOCH_RUNTIME_FAIL: " + result.stdout + result.stderr)
    if len(args) == 2:
        assert "SUMMARY=6/6" in result.stdout, result.stdout

print("RESTORE_EPOCH_RECOVERY_CONTRACT: PASS")
