#!/usr/bin/env python3
"""Original Host Authority v2: fresh, independent readback of signed revisions.

No signer, issuer, Web/REST ingress, arbitrary paths or mutation. No claimed
Job becomes executable by this module. A WordPress option alone cannot
authorise execution: compare the original Broker-signed root event with the
independent Original313 high-water checkpoint and a fresh Original318 readback.
"""
from __future__ import annotations

import base64
import importlib.util
import functools
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time

BROKER_PUBLIC = Path("/opt/mad4b-company-transport/broker-v1/host-ed25519-public.base64")
INDEPENDENT_PUBLIC = Path(
    "/opt/mad4b-company-transport/native-313-independent/verifier-ed25519.pub"
)
REGISTRY_INDEX = Path(
    "/opt/mad4b-company-transport/native-313-independent/"
    "dynamic-operation-registry-v1/enrolled/index.json"
)
REVISIONS = Path("/var/lib/mad4b/original-host-v2/revisions")
CHECKPOINTS = Path("/var/lib/mad4b/original-host-v2/checkpoints")
WPCLI = (
    "docker", "exec", "-u", "33:33", "php-wkgtkzta35wkkgouwt6ruljc",
    "php", "/usr/local/bin/wp", "--path=/var/www/html", "--skip-themes",
    "mad4b", "wpcli-host-grant", "anchor-current-readback"
)
SITE = re.compile(r"\A[a-z][a-z0-9-]{2,47}\Z")
ENV = re.compile(r"\A[a-z][a-z0-9_-]{1,39}\Z")
HEX = re.compile(r"\A[a-f0-9]{64}\Z")


class CurrentReadbackDenied(ValueError):
    pass


def require(eligible: bool, reason: str) -> None:
    if not eligible:
        raise CurrentReadbackDenied(reason)


def _owned_file(path: Path, *, private: bool, maxsize: int = 131072) -> bytes:
    require(path.is_absolute() and not path.is_symlink(), "SOURCE_PATH_INVALID")
    require(path.parent.is_dir() and not path.parent.is_symlink(),
            "SOURCE_PARENT_MISSING_OR_SYMLINK")
    for candidate in (path, path.parent):
        st = candidate.stat()
        require(st.st_uid == 0 and st.st_gid == 0, "SOURCE_OWNER_INVALID")
        require(not st.st_mode & 0o022, "SOURCE_WRITABLE_BY_UNTRUSTED")
    for ancestor in path.parents:
        if ancestor == Path("/"):
            break
        require(not ancestor.is_symlink(), "SOURCE_ANCESTOR_SYMLINK")
    st = path.stat()
    require(stat.S_ISREG(st.st_mode) and st.st_nlink == 1 and
            1 <= st.st_size <= maxsize, "SOURCE_FILE_UNTRUSTED")
    if private:
        require(stat.S_IMODE(st.st_mode) == 0o600 and
                stat.S_IMODE(path.parent.stat().st_mode) == 0o700,
                "PRIVATE_REVISION_STORAGE_UNTRUSTED")
    return path.read_bytes()


def _json(path: Path, *, private: bool = True) -> dict:
    data = json.loads(_owned_file(path, private=private))
    require(type(data) is dict, "SOURCE_JSON_INVALID")
    return data


@functools.lru_cache(maxsize=1)
def _v81_revision_verifier():
    module_path = Path(__file__).absolute().parents[1] / "v81/current_revision_gate.py"
    require(module_path.is_file() and not module_path.is_symlink(),
            "ORIGINAL_REVISION_VERIFIER_NOT_INSTALLED")
    spec = importlib.util.spec_from_file_location("_mad4b_host_v81_revision", module_path)
    require(spec is not None and spec.loader is not None, "ORIGINAL_GATE_UNAVAILABLE")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def _pinned_keys() -> tuple[bytes, bytes]:
    broker_b64 = _owned_file(BROKER_PUBLIC, private=False, maxsize=128).strip()
    independent = _owned_file(INDEPENDENT_PUBLIC, private=False, maxsize=128)
    index = _json(REGISTRY_INDEX, private=False)
    require(index.get("contract") == "mad4b.dynamic-operation-index.v1"
            and index.get("installed_by_first_party") is True,
            "ORIGINAL_REGISTRY_NOT_ENROLLED")
    try:
        broker = base64.b64decode(broker_b64, validate=True)
        registered = base64.b64decode(
            index["independent_verifier_public_key_b64"], validate=True
        )
    except (TypeError, ValueError, KeyError) as error:
        raise CurrentReadbackDenied("PINNED_PUBLIC_KEYS_NOT_VALID") from error
    require(len(broker) == len(independent) == 32
            and independent == registered and broker != independent,
            "ORIGINAL_BROKER_AND_313_INDEPENDENT_KEYS_REQUIRED")
    return broker, independent


def _scope(job: dict) -> tuple[str, str]:
    require(type(job) is dict, "ORIGINAL_JOB_NOT_PRESENT")
    company, environment = job.get("company"), job.get("environment")
    require(type(company) is str and SITE.fullmatch(company) is not None
            and type(environment) is str and ENV.fullmatch(environment) is not None,
            "JOB_SCOPE_INVALID")
    require(type(job.get("site_uuid")) is str
            and type(job.get("anchor_revision")) is int
            and type(job.get("grant_revision")) is int
            and type(job.get("job_id")) is str
            and type(job.get("target_host_id")) is str
            and type(job.get("ssh_principal")) is str,
            "ORIGINAL_JOB_REVISION_OR_PRINCIPAL_MISSING")
    require(job.get("job_type") == "signed_remote_hostinger_wpcli_v2"
            and job.get("principal_type") == "signed_host_principal_v2"
            and job.get("state") == "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
            and job.get("executed") is False,
            "ORIGINAL_CLAIMED_V2_JOB_REQUIRED")
    require(job.get("operation_id") in (
        "company.host-authority.anchor.issue",
        "company.host-authority.anchor.revoke",
    ), "ORIGINAL_HOST_V2_OPERATION_REQUIRED")
    return company, environment


def _wordpress_snapshot(company: str, environment: str) -> dict:
    argv = (*WPCLI, "--company=" + company, "--environment=" + environment)
    p = subprocess.run(argv, capture_output=True, text=True, timeout=45, check=False)
    require(p.returncode == 0, "ORIGINAL318_CURRENT_READBACK_UNAVAILABLE")
    records = []
    for line in p.stdout.splitlines():
        if line.startswith("{"):
            records.append(json.loads(line))
    require(len(records) == 1 and type(records[0]) is dict,
            "ORIGINAL318_READBACK_RESPONSE_INVALID")
    return records[0]


def _read_sources(company: str, environment: str) -> tuple[dict, dict, dict]:
    name = company + "--" + environment + ".json"
    ledger = _json(REVISIONS / name)
    checkpoint = _json(CHECKPOINTS / name)
    wp = _wordpress_snapshot(company, environment)
    return ledger, checkpoint, wp


def verify_signed_current(job: dict, ledger: dict, checkpoint: dict, wp: dict,
                          broker_pub: bytes, independent_pub: bytes,
                          *, reserved: bool = False, now: int | None = None) -> dict:
    """Pure bridge to the original v81 cryptographic revision gate.

    Inputs MUST come from the fixed first-party readers in read_current().
    Callers MUST NOT expose this helper as an authority API.
    """
    _scope(job)
    require(wp.get("signed_anchor_active_in_option") is True
            and wp.get("current_anchor_eligible") is False
            and wp.get("original313_certified") is False
            and wp.get("write_authorized") is False
            and wp.get("fresh_central_highwater_verified") is False,
            "WORDPRESS_SNAPSHOT_MUST_NOT_SELF_CERTIFY")
    gate = _v81_revision_verifier()
    return gate.verify_fresh_current(
        job, ledger, checkpoint, wp, broker_pub, independent_pub,
        reserved=reserved, now=now,
    )


def read_current(job: dict, *, reserved: bool = False) -> dict:
    """Fixed original sources; precondition only, not an original Job issuer."""
    require(os.geteuid() == 0, "ORIGINAL_ROOT_READER_REQUIRED")
    company, environment = _scope(job)
    keys = _pinned_keys()
    ledger, checkpoint, wp = _read_sources(company, environment)
    return verify_signed_current(job, ledger, checkpoint, wp, *keys,
                                 reserved=reserved)


def main() -> int:
    """No CLI that accepts a fabricated Job or signature."""
    if len(sys.argv) != 1:
        raise CurrentReadbackDenied("ORIGINAL_WORKER_CALL_REQUIRED")
    # Read-only installation status; never derives an authorized Job.
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (CurrentReadbackDenied, OSError, ValueError) as error:
        print(json.dumps({"state": "HOLD", "reason": str(error)[:150],
                          "anchor_issued": False, "original313_certified": False}))
        raise SystemExit(2)
