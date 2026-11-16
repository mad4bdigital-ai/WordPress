#!/usr/bin/env python3
"""First-party Original313 signed Host source receipt verifier only."""
from __future__ import annotations
from pathlib import Path
import os
import native_host_source_final_receipt as receipt

class OriginalHostReceiptVerifierDenied(ValueError):pass

def verify_actual_host_release(plan:dict,receipt_root:Path,nonce:str,signed:dict):
    if os.geteuid()!=0:
        raise OriginalHostReceiptVerifierDenied('ORIGINAL313_HOST_VERIFIER_ROOT_REQUIRED')
    return receipt.verify_original313_receipt(plan,receipt_root,nonce,signed)

if __name__=='__main__':
    raise SystemExit('ORIGINAL313_HOST_VERIFIER_CLI_DISABLED_USE_FIRSTPARTY_FINALIZER')
