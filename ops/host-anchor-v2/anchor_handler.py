"""Source-pinned Host Anchor v2 entrypoint; not an admitted live issuer.

Never turn an imported manifest into a granted operation. The complete signed
original306/312 issuer, mutating Host transaction, independently signed
Original313 checkpoint, and rollback are separate originals. The registered
typed provider has execution_enabled=false until that certified release.
"""
from __future__ import annotations
import json
import os


def execute_claimed(*args, **kwargs):
    raise PermissionError(
        "HOST_ANCHOR_ISSUER_NOT_COMMISSIONED__ORIGINAL312_313_REQUIRED")


def main() -> int:
    state = ("ORIGINAL_ROOT_EXECUTOR_REQUIRED" if os.geteuid() != 0 else
             "HOST_ANCHOR_ISSUER_NOT_COMMISSIONED")
    print(json.dumps({
        "state": state,
        "execution_performed": False, "grant_issued": False,
        "anchor_issued": False, "anchor_revoked": False,
        "original313_signed_receipt": False,
    }, sort_keys=True))
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
