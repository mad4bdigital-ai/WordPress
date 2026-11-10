"""Read-only FAORE projection of the ORIGINAL host Broker event journal.

Original Broker v1 remains authoritative. A local event is an observation,
never a signed 305 grant, independent 312 authorization, or native 313 result.
"""
from pathlib import Path
import argparse
import json
import re
import stat

JOURNAL = Path("/opt/mad4b-company-transport/broker-v1/broker-events.jsonl")
ALLOWED_EVENTS = frozenset((
    "HEARTBEAT_VERIFIED", "CLAIM_ATTEMPTED",
    "CLAIM_VERIFIED_NOT_EXECUTED", "CLAIM_RESULT_UNCERTAIN",
    "PENDING_NOT_ELIGIBLE", "NATIVE_SSH_AUTHORITY_PENDING",
    "NATIVE_SSH_AUTHORITY_READY",
))
JOB_ID = re.compile(r"^[0-9a-f]{32}$")
COMPANY = re.compile(r"^[a-z][a-z0-9-]{2,47}$")


def observe(company: str, journal: Path = JOURNAL, limit: int = 100) -> dict:
    if COMPANY.fullmatch(company) is None:
        raise ValueError("COMPANY_ID_INVALID")
    if not 1 <= limit <= 500:
        raise ValueError("LIMIT_INVALID")
    journal = Path(journal)
    if journal.is_symlink():
        raise ValueError("JOURNAL_SYMLINK_DENIED")
    info = journal.stat()
    if not stat.S_ISREG(info.st_mode):
        raise ValueError("JOURNAL_NOT_A_FILE")
    if info.st_size > 10 * 1024 * 1024:
        raise ValueError("JOURNAL_TOO_LARGE")
    observed = []
    for line in journal.read_text(encoding="utf-8").splitlines()[-limit:]:
        if len(line) > 8192:
            continue
        try:
            row = json.loads(line)
        except json.JSONDecodeError:
            continue
        if not isinstance(row, dict) or row.get("event") not in ALLOWED_EVENTS:
            continue
        if row.get("company") != company:
            continue
        job_id = row.get("job_id", "")
        if not isinstance(job_id, str) or (job_id and not JOB_ID.fullmatch(job_id)):
            continue
        # Only bounded, non-sensitive fields. Never copy the journal payload.
        observed.append({
            "event": row["event"],
            "job_id": job_id,
        })
    return {
        "contract": "mad4b.faore.original-broker-observation.v1",
        "company_id": company,
        "evidence_state": "Observed" if observed else "Unverified",
        "events": observed,
        "event_count": len(observed),
        "stage_305": "NOT_VERIFIED_BY_JOURNAL",
        "stage_306": "NOT_EXECUTED",
        "stage_312": "REQUIRES_ORIGINAL_EXACT_PREFLIGHT",
        "stage_313": "PENDING_INDEPENDENT_NATIVE_READBACK",
        "write_authorized": False,
        "execution_verified": False,
        "read_only": True,
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Read-only, redacted original Broker observation")
    parser.add_argument("--company", required=True)
    args = parser.parse_args()
    print(json.dumps(observe(args.company), sort_keys=True))


if __name__ == "__main__":
    main()
