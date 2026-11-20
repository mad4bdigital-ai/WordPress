#!/usr/bin/env python3
"""Source-only guarded Original306 Root Worker hook for v118 QUEUED evidence.

Does not patch the VPS worker at import or CLI execution. The only output is
an exact candidate source file selected by an explicit local development
caller; before production deployment the current worker SHA must be pinned
through a separately reviewed release.
"""
from __future__ import annotations
from pathlib import Path
import hashlib,re

MODULE_REL="host-authority-v118/queued_original_capture.py"
MODULE_SHA="b76778a5ecc786c74a02b5bd0a9c42f9256a17a7f46b4089ff1bd72368b0ecb2"
MARKER="FIRSTPARTY_HOST_V118_PRECLAIM_EVIDENCE"
NEEDLE='''   if first_anchor_scope and firstparty_host_v95_first_claim_only(actor,entry):
    # This returns the ordinary signed Original306 *Claim*, not an Anchor.
    # Original312/313 execution remains separately disabled in the Registry.
    return sign_existing_original_306_claim(job,entry)
'''
REPLACEMENT='''   if first_anchor_scope and firstparty_host_v95_first_claim_only(actor,entry):
    # FIRSTPARTY_HOST_V118_PRECLAIM_EVIDENCE: preserve the genuine QUEUED
    # original BEFORE Original306 overwrites the WordPress Job with CLAIMED.
    # The historical evidence is not an issuance or execution approval.
    if not firstparty_host_v118_capture_original_queued(job):
     continue
    # This remains ONLY the separately signed Original306 Claim.
    return sign_existing_original_306_claim(job,entry)
'''
HELPER='''
# FIRSTPARTY_HOST_V118_PRECLAIM_EVIDENCE: purpose-bound Staging-only
# capture. Installed first-party code and SHA must be pinned independently
# by Root, not accepted from WordPress, a request, a plugin or a user argument.
def firstparty_host_v118_capture_original_queued(job_id):
 try:
  import stat
  if (os.geteuid()!=0 or not isinstance(job_id,str) or
      not HEX32.fullmatch(job_id)):
   raise RuntimeError('HOST_V118_FIRST_PARTY_ROOT_AND_JOB_REQUIRED')
  path=ROOT/'host-authority-v118/queued_original_capture.py'
  st=path.lstat()
  if (path.is_symlink() or not stat.S_ISREG(st.st_mode) or
      st.st_uid!=0 or st.st_gid!=0 or st.st_nlink!=1 or
      stat.S_IMODE(st.st_mode)!=0o600 or
      hashlib.sha256(path.read_bytes()).hexdigest()!=
      'b76778a5ecc786c74a02b5bd0a9c42f9256a17a7f46b4089ff1bd72368b0ecb2'):
   raise RuntimeError('HOST_V118_PINNED_CAPTURE_SOURCE_CHANGED')
  spec=importlib.util.spec_from_file_location(
      '_host_v118_original306_preclaim_capture',path)
  if spec is None or spec.loader is None:
   raise RuntimeError('HOST_V118_CAPTURE_SOURCE_IMPORT_DENIED')
  mod=importlib.util.module_from_spec(spec)
  spec.loader.exec_module(mod)
  state=mod.capture_live(job_id)
  return state in (
      'ORIGINAL306_QUEUED_PREIMAGE_CAPTURED',
      'ORIGINAL306_QUEUED_PREIMAGE_ALREADY_STORED')
 except Exception as exc:
  # Never allow an unproven Staging Job into signing or generic execution.
  print('HOST_V118_QUEUED_CAPTURE_HELD:'+
        safe_capability_error(exc),file=sys.stderr)
  return False

'''

def patch_text(source:str)->str:
    if MARKER in source:
        raise ValueError("HOST_V118_ALREADY_PATCHED")
    if source.count(NEEDLE)!=1:
        raise ValueError("HOST_V118_EXACT_V95_CLAIM_SITE_REQUIRED")
    marker="\ndef claim_authorized_job(actor):"
    if source.count(marker)!=1:
        raise ValueError("HOST_V118_ROOT_WORKER_ENTRY_CHANGED")
    changed=source.replace(NEEDLE,REPLACEMENT,1)
    changed=changed.replace(marker,"\n"+HELPER+marker,1)
    if not changed.count(MARKER)==2:
        raise ValueError("HOST_V118_MARKER_COUNT_MISMATCH")
    if changed.count("return sign_existing_original_306_claim(job,entry)")!=source.count("return sign_existing_original_306_claim(job,entry)"):
        raise ValueError("HOST_V118_CLAIM_COUNT_DRIFT")
    return changed

def main():
    # Do not accept a remote path or write to a running worker from this script.
    print("HOLD_SOURCE_ONLY_PATCH_REQUIRES_TESTED_FIRST_ANCHOR_ISSUER")
    return 2

if __name__=="__main__":
    raise SystemExit(main())
