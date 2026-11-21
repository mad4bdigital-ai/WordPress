"""Read-only cryptographic census of Original313 historical Root evidence.

A verified v2 archive is NOT a complete v3 predecessor. No absence proof or
NONE-state is issued from an incomplete archive. No key loading or signing.
"""
from __future__ import annotations
import base64
import hashlib
import json
import os
from pathlib import Path
import stat
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.exceptions import InvalidSignature

CONTRACT = "mad4b.original313.host-v134-private-archive-census.v1"
INDEX_CONTRACT = "mad4b.dynamic-operation-index.v1"
V2_DIRS=("enrollments","revisions","checkpoints","receipts",
         "first-issue-documents","plans","history","transactions",
         "broker-row-witness","queued-evidence","first-issue-send-intents")
V2_LEDGER="mad4b.original-host-current-revision.v1"
V2_CHECKPOINT="mad4b.original-host-current-revision-checkpoint.v1"
V2_FINAL_RECEIPT="mad4b.original313.host-anchor-final-receipt.v1"
DOMAINS={
 V2_LEDGER:"MAD4B_ORIGINAL_HOST_LEDGER_V1",
 V2_CHECKPOINT:"MAD4B_ORIGINAL_HOST_CHECKPOINT_V1",
 V2_FINAL_RECEIPT:"MAD4B_ORIGINAL313_HOST_FINAL_RECEIPT_V1",
}
SITE="e5e64bca-ed62-48d2-b2b3-dff693cd702a"
SITE_ORIGIN="https://tours.dev.mad4b.com"
class ArchiveUntrusted(ValueError):pass

def need(check,code):
    if not check:raise ArchiveUntrusted(code)

def digest(b:bytes):return hashlib.sha256(b).hexdigest()

def protected(path:Path,*,isdir=False):
    st=path.lstat()
    need(not path.is_symlink() and st.st_uid==0 and st.st_gid==0
         and st.st_nlink>=1
         and (stat.S_ISDIR(st.st_mode) if isdir else stat.S_ISREG(st.st_mode))
         and not(stat.S_IMODE(st.st_mode)&0o077),
         "ROOT313_UNTRUSTED_PRIVATE_ARCHIVE")
    return st

def signed(row:dict,contract:str,pub:bytes):
    need(row.get("contract")==contract and isinstance(row.get("signature"),str),
         "ROOT313_HISTORIC_CONTRACT_INVALID")
    payload={key:val for key,val in row.items() if key!="signature"}
    msg=(DOMAINS[contract]+"\n").encode()+json.dumps(
        payload,sort_keys=True,separators=(",",":"),ensure_ascii=False).encode()
    try:
        signature=base64.b64decode(row["signature"],validate=True)
        need(len(signature)==64,"ROOT313_SIGNATURE_LENGTH_INVALID")
        Ed25519PublicKey.from_public_bytes(pub).verify(signature,msg)
    except (InvalidSignature,ValueError,TypeError) as error:
        raise ArchiveUntrusted("ROOT313_HISTORICAL_SIGNATURE_INVALID") from error
    return digest(msg)

def history_census(*,root:Path,index_path:Path,target_site_uuid:str=SITE):
    need(os.geteuid()==0 and root.is_absolute() and index_path.is_absolute(),
         "ROOT313_ROOT_ACCESS_REQUIRED")
    protected(root,isdir=True)
    protected(index_path)
    index=json.loads(index_path.read_text())
    need(index.get("contract")==INDEX_CONTRACT and
         index.get("installed_by_first_party") is True,
         "ROOT313_INDEX_NOT_ENROLLED")
    broker=base64.b64decode(index["host_anchor_312_public_key_b64"],validate=True)
    independent=base64.b64decode(index["independent_verifier_public_key_b64"],validate=True)
    need(len(broker)==len(independent)==32 and broker!=independent,
         "ROOT313_SIGNER_INDEPENDENCE_INVALID")
    # Include the Root-level first-issue commissioning seal in the census.
    # Lock files are deliberately excluded, never treated as history.
    known_root_files={".first-issue-root-commissioned-v121.json"}
    other_root_entries=set(x.name for x in root.iterdir())-set(V2_DIRS)-known_root_files-{
       ".first-issue-runner.lock",".original313-txn.lock"}
    need(not other_root_entries,"ROOT313_UNINDEXED_PRIVATE_HISTORY_SOURCE")
    root_level=[]
    for name in sorted(known_root_files):
        path=root/name
        protected(path)
        obj=json.loads(path.read_bytes())
        need(type(obj) is dict and isinstance(obj.get("contract"),str),
             "ROOT313_COMMISSIONING_RECORD_INVALID")
        root_level.append({"name":name,"sha256":digest(path.read_bytes()),
                           "contract":obj["contract"]})
    files=[]
    by_dir={name:[] for name in V2_DIRS}
    all_records={}
    for name in V2_DIRS:
        folder=root/name
        protected(folder,isdir=True)
        for file in sorted(folder.iterdir(),key=lambda p:p.name):
            if file.name.startswith("."):continue
            protected(file)
            need(file.suffix==".json" and file.stat().st_size<=262144,
                 "ROOT313_UNEXPECTED_ARCHIVE_FILE")
            raw=file.read_bytes()
            obj=json.loads(raw)
            need(type(obj) is dict,"ROOT313_INVALID_ROOT_RECORD")
            record={
              "location":name+"/"+file.name,
              "sha256":digest(raw),
              "contract":obj.get("contract"),
              "company":obj.get("company") if type(obj.get("company")) is str else None,
              "environment":obj.get("environment") if type(obj.get("environment")) is str else None,
              "site_uuid":obj.get("site_uuid") if type(obj.get("site_uuid")) is str else None,
              "revision":obj.get("revision") if type(obj.get("revision")) is int else None,
            }
            if name in ("revisions","checkpoints","receipts"):
                expected={
                   "revisions":(V2_LEDGER,broker),
                   "checkpoints":(V2_CHECKPOINT,independent),
                   "receipts":(V2_FINAL_RECEIPT,independent),
                }[name]
                record["signed_payload_sha256"]=signed(obj,*expected)
                record["cryptographic_verification"]="PASS"
            else:
                record["cryptographic_verification"]="NOT_CHECKED_NON_SIGNATURE_ARCHIVE"
            files.append(record)
            by_dir[name].append(record)
            all_records[name+"/"+file.name]=obj
    revs={r['company']+':'+r['environment']:r for r in by_dir['revisions']
          if r['company'] and r['environment']}
    checks={r['company']+':'+r['environment']:r for r in by_dir['checkpoints']
            if r['company'] and r['environment']}
    need(len(revs)==len(by_dir['revisions']) and
         len(checks)==len(by_dir['checkpoints']) and
         revs.keys()==checks.keys(),
         "ROOT313_V2_LEDGER_CHECKPOINT_COVERAGE_GAP")
    chain=[]
    for scope in sorted(revs):
        ledger=revs[scope];checkpoint=checks[scope]
        r=all_records[ledger['location']]
        cp=all_records[checkpoint['location']]
        need(all(r.get(k)==cp.get(k) for k in
              ("company","environment","site_uuid","revision",
               "grant_revision","anchor_nonce","state")),
             "ROOT313_V2_LEDGER_CHECKPOINT_MISMATCH")
        need(cp["event_sha256"]==ledger["signed_payload_sha256"],
             "ROOT313_V2_CHECKPOINT_EVENT_DIGEST_DRIFT")
        matches=[rc for rc in by_dir['receipts']
                 if rc['company']==r["company"] and
                    rc['environment']==r["environment"] and
                    rc['site_uuid']==r["site_uuid"] and
                    rc['revision']==r["revision"]]
        need(len(matches)==1,"ROOT313_V2_RECEIPT_COVERAGE_GAP")
        receipt=all_records[matches[0]["location"]]
        need(receipt["event_sha256"]==cp["event_sha256"] and
             receipt["checkpoint_sha256"]==digest(
                 ("MAD4B_ORIGINAL_HOST_CHECKPOINT_V1\n"+json.dumps(
                   {k:v for k,v in cp.items() if k!="signature"},
                   sort_keys=True,separators=(",",":"),ensure_ascii=False)
                 ).encode()),
             "ROOT313_V2_RECEIPT_LINK_MISMATCH")
        chain.append({"scope":scope,"revision":r["revision"],"site_uuid":r["site_uuid"],
                      "signature_chain":"V2_LEDGER_313_CHECKPOINT_AND_FINAL_RECEIPT_VERIFIED",
                      "historical_chain_exhaustive":False})
    affected=[row for row in files if row["site_uuid"]==target_site_uuid or
              row["company"]=="mad4b-central"]
    return {
       "contract":CONTRACT,
       "root_archive_sha256":digest(json.dumps(
            [(r["location"],r["sha256"]) for r in files],
            sort_keys=True,separators=(",",":")).encode()),
       "index_sha256":digest(index_path.read_bytes()),
       "directory_count":len(V2_DIRS),
       "root_level_commissioning_files":root_level,
       "root_level_commissioning_count":len(root_level),
       "json_file_count":len(files)+len(root_level),
       "directory_counts":{k:len(v) for k,v in by_dir.items()},
       "v2_signed_archival_chains":chain,
       "v2_signed_current_pairs_verified":len(chain),
       "target_site_uuid":target_site_uuid,
       "target_site_detected_in_known_archives":bool(affected),
       "target_site_matching_records":[
          {"location":r["location"],"sha256":r["sha256"]} for r in affected],
       "complete_original313_predecessor_for_target":"NOT_ESTABLISHED",
       "absence_of_target_v2_not_treated_as_revision_zero":True,
       "complete_cross_generation_history_verified":False,
       "new_original313_v3_predecessor_signed":False,
       "root_owner_v3_approval_signed":False,
       "write_authorized":False,
       "files":files,
    }
