#!/usr/bin/env python3
"""Source-only combined v118 + v120 preclaim Root Worker hook candidate.

NEVER installs itself. A separate first-party Root release must pin exact
installed worker SHA, separately commission Original313 issuer, and test
signed receipt recovery before applying any candidate.
"""
from __future__ import annotations
import importlib.util
from pathlib import Path
import hashlib

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
ORIGINAL_WORKER=ROOT/"claimed-job-worker.py"
ORIGINAL_SHA="35947c92db959f4ed0a1416b96b10bd65e7cbb3dd4baf8d6af3f303e6ceef5b3"
V118_PATCH=Path(__file__).resolve().parents[1]/"host-anchor-v118/patch_original306_worker.py"
V120_SHA="336ef367637ce1edbef4debbec4317b3b839d3d71e2e3135e0690eb40d96331f"
MARKER="FIRSTPARTY_HOST_V120_BROKER_ROW_WITNESS"
NEEDLE="""    if not firstparty_host_v118_capture_original_queued(job):
     continue
    # This remains ONLY the separately signed Original306 Claim.
    return sign_existing_original_306_claim(job,entry)
"""
REPLACEMENT="""    if not firstparty_host_v118_capture_original_queued(job):
     continue
    # FIRSTPARTY_HOST_V120_BROKER_ROW_WITNESS: separate Broker signature
    # over the original QUEUED record, before signed Original306 Claim.
    if not firstparty_host_v120_capture_original_broker_row(job):
     continue
    # This remains ONLY the separately signed Original306 Claim.
    return sign_existing_original_306_claim(job,entry)
"""
HELPER="""
# FIRSTPARTY_HOST_V120_BROKER_ROW_WITNESS: Root-only preclaim guard.
# Never derive a Broker Row from the claimed WordPress Job or old broker logs.
def firstparty_host_v120_capture_original_broker_row(job_id):
 try:
  import stat
  if (os.geteuid()!=0 or not isinstance(job_id,str)
      or not HEX32.fullmatch(job_id)):
   raise RuntimeError('HOST_V120_BROKER_SOURCE_ROOT_AND_ID_REQUIRED')
  path=ROOT/'host-authority-v120/broker_row_witness.py'
  st=path.lstat()
  if (path.is_symlink() or not stat.S_ISREG(st.st_mode)
      or st.st_uid!=0 or st.st_gid!=0 or st.st_nlink!=1
      or stat.S_IMODE(st.st_mode)!=0o600
      or hashlib.sha256(path.read_bytes()).hexdigest()!=
      '336ef367637ce1edbef4debbec4317b3b839d3d71e2e3135e0690eb40d96331f'):
   raise RuntimeError('HOST_V120_BROKER_WITNESS_SOURCE_PIN_CHANGED')
  spec=importlib.util.spec_from_file_location(
      '_host_v120_firstparty_signed_broker_row',path)
  if spec is None or spec.loader is None:
   raise RuntimeError('HOST_V120_BROKER_WITNESS_IMPORT_DENIED')
  mod=importlib.util.module_from_spec(spec)
  spec.loader.exec_module(mod)
  status=mod.capture_live_before_claim(job_id)
  return status in (
      'ORIGINAL_BROKER_QUEUED_WITNESS_SIGNED_AND_STORED',
      'ORIGINAL_BROKER_QUEUED_WITNESS_ALREADY_STORED')
 except Exception as exc:
  print('HOST_V120_SIGNED_BROKER_ROW_HELD:'+
        safe_capability_error(exc),file=sys.stderr)
  return False

"""

def prepare_candidate(source):
    if MARKER in source:
        raise ValueError("V120_ALREADY_PRESENT")
    if hashlib.sha256(source.encode()).hexdigest()!=ORIGINAL_SHA:
        raise ValueError("V120_OLD_WORKER_HEAD_DRIFT")
    spec=importlib.util.spec_from_file_location(
        "_v120_firstparty_v118_source_only",V118_PATCH)
    if spec is None or spec.loader is None:
        raise ValueError("V120_V118_SOURCE_PATCH_NOT_FOUND")
    v118=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(v118)
    prior=v118.patch_text(source)
    if prior.count(NEEDLE)!=1:
        raise ValueError("V120_V118_PRECLAIM_SITE_NOT_EXACT")
    marker="\ndef claim_authorized_job(actor):"
    if prior.count(marker)!=1:
        raise ValueError("V120_ORIGINAL306_CLAIM_SITE_CHANGED")
    result=prior.replace(NEEDLE,REPLACEMENT,1)
    result=result.replace(marker,"\n"+HELPER+marker,1)
    if result.count(MARKER)!=2:
        raise ValueError("V120_PRECLAIM_MARKER_COUNT")
    if result.count("return sign_existing_original_306_claim(job,entry)")!=source.count("return sign_existing_original_306_claim(job,entry)"):
        raise ValueError("V120_CLAIM_BEHAVIOR_CHANGED")
    compile(result,"v120-firstparty-worker-candidate","exec")
    return result

def main():
    print("HOLD_SOURCE_ONLY_SIGNED_BROKER_ROW_PATCH_NEEDS_REVIEWED_ORIGINAL313_ISSUER")
    return 2

if __name__=="__main__":
    raise SystemExit(main())
