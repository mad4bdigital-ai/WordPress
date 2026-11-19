#!/usr/bin/env python3
"""v121 source-only exact Original306 worker overlay.

Does not alter any installed worker. Scope permits ONLY first Anchor
All Royal Egypt Staging after real Owner+328+v118/v120 proof and original
Broker-signed Claim. The separate pinned Root coordinator gates signing.
"""
from __future__ import annotations
from pathlib import Path
import hashlib
import importlib.util

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
CURRENT=ROOT/"claimed-job-worker.py"
CURRENT_SHA="35947c92db959f4ed0a1416b96b10bd65e7cbb3dd4baf8d6af3f303e6ceef5b3"
V120_PATCH=Path(__file__).resolve().parents[1]/"host-anchor-v120/patch_firstparty_worker.py"
V121_MODULE_SHA="834c7f9913fcaad8b5dcf43021d4b4b6d637957d8dfb43d0ea4d6faa96fa2dae"
MARKER="FIRSTPARTY_HOST_V121_EXACT_ISSUER"
NEEDLE=""" newly_claimed=claim_authorized_job(actor)
 d=cli_json(['--user='+str(actor),'mad4b','broker-authority','claimed','--limit=10'],35)
"""
REPLACEMENT=""" newly_claimed=claim_authorized_job(actor)
 # FIRSTPARTY_HOST_V121_EXACT_ISSUER: only first Anchor jobs with immutable
 # v118 QUEUED preimage and separately Broker-signed v120 witness.
 if newly_claimed and firstparty_host_v121_has_exact_witness(newly_claimed):
  firstparty_host_v121_exact_issue(newly_claimed)
 # Never replay WordPress Original318 after any ambiguous write. The v121
 # independent postwrite resumer uses the stored exact signed intent only.
 firstparty_host_v121_postwrite_receipt_recovery()
 d=cli_json(['--user='+str(actor),'mad4b','broker-authority','claimed','--limit=10'],35)
"""
HELPER="""
# FIRSTPARTY_HOST_V121_EXACT_ISSUER: Purpose-bound first-Anchor runner.
# No generic registry execution flag and no Owner impersonation.
def firstparty_host_v121_module():
 import stat
 path=ROOT/'host-authority-v121/first_anchor_coordinator.py'
 st=path.lstat()
 if (path.is_symlink() or not stat.S_ISREG(st.st_mode)
     or st.st_uid!=0 or st.st_gid!=0 or st.st_nlink!=1
     or stat.S_IMODE(st.st_mode)!=0o600
     or hashlib.sha256(path.read_bytes()).hexdigest()!=
     '834c7f9913fcaad8b5dcf43021d4b4b6d637957d8dfb43d0ea4d6faa96fa2dae'):
  raise RuntimeError('FIRSTPARTY_HOST_V121_ROOT_ISSUER_SOURCE_CHANGED')
 spec=importlib.util.spec_from_file_location(
     '_host_v121_exact_first_anchor_issuer',path)
 if spec is None or spec.loader is None:
  raise RuntimeError('FIRSTPARTY_HOST_V121_ROOT_ISSUER_UNAVAILABLE')
 mod=importlib.util.module_from_spec(spec)
 spec.loader.exec_module(mod)
 mod.root_gate()  # Only root-owned source and explicit activation permits.
 return mod

def firstparty_host_v121_has_exact_witness(job_id):
 try:
  if (os.geteuid()!=0 or not isinstance(job_id,str)
      or not HEX32.fullmatch(job_id)):
   return False
  # No WordPress write or root signer loaded at this probe.
  marker=ROOT/'host-authority-v120/broker_row_witness.py'
  witness=Path('/var/lib/mad4b/original-host-v2/broker-row-witness')/(job_id+'.json')
  queued=Path('/var/lib/mad4b/original-host-v2/queued-evidence')/(job_id+'.json')
  return (witness.is_file() and not witness.is_symlink() and
          queued.is_file() and not queued.is_symlink())
 except Exception:
  return False

def firstparty_host_v121_exact_issue(job_id):
 try:
  mod=firstparty_host_v121_module()
  result=mod.execute_locked(job_id,"issue")
  if result.get('state')!=(
      'FIRST_ANCHOR_REVISION_1_SIGNED_RECEIPT_AND_HIGHWATER_VERIFIED'):
   raise RuntimeError('HOST_V121_FIRST_ANCHOR_NOT_FINALIZED')
  print(json.dumps(result,sort_keys=True))
  return True
 except Exception as exc:
  print('FIRSTPARTY_HOST_V121_ISSUE_HELD:'+
        safe_capability_error(exc),file=sys.stderr)
  return False

def firstparty_host_v121_postwrite_receipt_recovery():
 \"\"\"No automatic original318 write. Postwrite-only recovery from
 immutable signed documents and a previously armed exact send-intent.
 A genuine Original318 Revision 1 readback is mandatory.
 \"\"\"
 try:
  root=Path('/var/lib/mad4b/original-host-v2/first-issue-send-intents')
  if not root.is_dir() or root.is_symlink():return
  plans=sorted(root.glob('*.json'))
  if len(plans)>1:
   raise RuntimeError('HOST_V121_UNEXPECTED_MULTIPLE_FIRST_ISSUE_INTENTS')
  if not plans:return
  jid=plans[0].stem
  if not HEX32.fullmatch(jid):raise RuntimeError('HOST_V121_BAD_RECOVERY_ID')
  # The signed Original313 receipt is immutable. No need to invoke slow
  # WP-CLI reads on every 60-second worker tick after it is completed.
  receipt=Path('/var/lib/mad4b/original-host-v2/receipts')/(jid+'.json')
  if receipt.is_symlink():raise RuntimeError('HOST_V121_SIGNED_RECEIPT_SYMLINK')
  if receipt.is_file():return
  mod=firstparty_host_v121_module()
  outcome=mod.execute_locked(jid,"resume")
  if (outcome.get('state')==
      'FIRST_ANCHOR_REVISION_1_SIGNED_RECEIPT_AND_HIGHWATER_VERIFIED'):
   print('HOST_V121_POSTWRITE_SIGNED_RECEIPT_VERIFIED:'+jid)
 except Exception as exc:
  print('FIRSTPARTY_HOST_V121_POSTWRITE_RECOVERY_HELD:'+
        safe_capability_error(exc),file=sys.stderr)

"""

def prepare_candidate(original):
    if MARKER in original:
        raise ValueError("V121_WORKER_OVERLAY_ALREADY_INSTALLED")
    need_exact=hashlib.sha256(original.encode()).hexdigest()
    if need_exact!=CURRENT_SHA:
        raise ValueError("V121_UNEXPECTED_BASE_WORKER_SOURCE")
    sp=importlib.util.spec_from_file_location(
        "_v121_source_only_v120_patch",V120_PATCH)
    if sp is None or sp.loader is None:
        raise ValueError("V121_FIRSTPARTY_V120_PRECLAIM_SOURCE_MISSING")
    mod=importlib.util.module_from_spec(sp)
    sp.loader.exec_module(mod)
    preclaimed=mod.prepare_candidate(original)
    if preclaimed.count(NEEDLE)!=1:
        raise ValueError("V121_ORIGINAL306_MAIN_ENTRY_NOT_EXACT")
    marker="\ndef claim_authorized_job(actor):"
    if preclaimed.count(marker)!=1:
        raise ValueError("V121_ORIGINAL306_ENTRYPOINT_CHANGED")
    out=preclaimed.replace(NEEDLE,REPLACEMENT,1)
    out=out.replace(marker,"\n"+HELPER+marker,1)
    if out.count(MARKER)!=2:
        raise ValueError("V121_WORKER_HOOK_MARKER_COUNT_DRIFT")
    if out.count("return sign_existing_original_306_claim(job,entry)")!=\
       original.count("return sign_existing_original_306_claim(job,entry)"):
        raise ValueError("V121_ORIGINAL306_SIGNED_CLAIM_CHANGED")
    compile(out,"host-v121-worker-candidate","exec")
    return out

def main():
    print("HOLD_SOURCE_ONLY_V121_ROOT_WORKER_HOOK_NOT_INSTALLED")
    return 2

if __name__=="__main__":
    raise SystemExit(main())
