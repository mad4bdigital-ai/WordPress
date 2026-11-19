#!/usr/bin/env python3
"""Root-side ORIGINAL305 Host capability readiness: UNSIGNED input only.

This prepares an exact short-lived contract for the *existing original Broker*
to review and independently sign. No key access, no signature, no WordPress
option write, no Master Admin ticket, no Host Job, no Host source mutation.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import secrets
import stat
import time
import sys

# Runtime loads the first-party pinned Host owner/doctor from the ORIGINAL
# enrolled Registry, not an arbitrary Broker working directory. Source tests
# in Git still use the same original modules after the root enrollment.
ENROLLED_PUBLISHER=Path('/opt/mad4b-company-transport/native-313-independent/'
 'dynamic-operation-registry-v1/enrolled/native-source-publisher-v1')
if str(ENROLLED_PUBLISHER) not in sys.path:
    sys.path.insert(0,str(ENROLLED_PUBLISHER))
import host_source_activation_doctor_v3 as doctor
import native_host_source_ownership as owner

CONTRACT='mad4b.original-host-source-readiness.v1'
REGISTRY=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled/index.json')
CATALOG=Path('/etc/mad4b/native-source-publisher-host-owners.v1.json')
WORKER=Path('/opt/mad4b-company-transport/native-313-independent/claimed-job-worker.py')
REQUIRED={
 'first_party_registry_installed_and_validated',
 'original_host312_distinct_signer_root_enrolled',
 'original_host_source_manifest_registered',
 'original_host_resource_type_matches',
 'exact_original_handler_and_dependencies_pinned',
 'original306_worker_host_resource_discriminated',
 'original306_worker_host_claimed_executor_enrolled',
 'native_source_publisher_policy_enrolled',
 'host_owner_catalog_enrolled',
 'host_313_finalizer_registered',
}

class ReadinessDenied(ValueError):pass

def checked_sha(path:Path,*,private:bool=False)->str:
    if (path.is_symlink() or not path.is_file()
        or path.stat().st_uid!=0 or path.stat().st_gid!=0 or
        not stat.S_ISREG(path.stat().st_mode) or
        path.stat().st_mode&0o022 or
        (private and path.stat().st_mode&0o077)):
        raise ReadinessDenied('ORIGINAL_HOST_READINESS_UNTRUSTED_ROOT_ARTIFACT')
    return hashlib.sha256(path.read_bytes()).hexdigest()

def unsigned_input(*,now:int|None=None,nonce:str|None=None,
                   status:dict|None=None)->dict:
    if os.geteuid()!=0:
        raise ReadinessDenied('ORIGINAL_HOST_READINESS_REQUIRES_ROOT')
    report=doctor.actual_status() if status is None else status
    checks=report.get('checks') if isinstance(report,dict) else None
    if (not isinstance(checks,dict) or
        any(checks.get(k) is not True for k in REQUIRED) or
        report.get('publication_authorized') is not False):
        raise ReadinessDenied('ORIGINAL_HOST_READINESS_FIRSTPARTY_SETUP_INCOMPLETE')
    # Even a complete infrastructure setup is never an approved Host Job.
    # The Broker cannot use this as a Master/Admin grant or 312 proof.
    instant=int(time.time()) if now is None else now
    token=secrets.token_hex(16) if nonce is None else nonce
    if (not isinstance(token,str) or len(token)!=32 or
        any(ch not in '0123456789abcdef' for ch in token)):
        raise ReadinessDenied('ORIGINAL_HOST_READINESS_NONCE_NOT_CANONICAL')
    payload={
      'contract':CONTRACT,
      'operation':'host.source.release',
      'provider':'original_native_host_source',
      'authority_family':'original_305',
      'target_scope':'host',
      'command_format':'host.source.ref.v1',
      'evidence_contract':CONTRACT,
      'host_id':'srv2030908',
      'source_registry_sha256':checked_sha(REGISTRY,private=True),
      'owner_catalog_sha256':checked_sha(CATALOG,private=True),
      'worker_sha256':checked_sha(WORKER),
      'ready':True,
      'issued_epoch':instant,
      'expires_epoch':instant+180,
      'nonce':token,
    }
    return {
        'contract':'mad4b.original305-unsigned-readiness-handoff.v1',
        'state':'ORIGINAL_BROKER_INDEPENDENT_SIGNATURE_STILL_REQUIRED',
        'payload':payload,
        'payload_sha256':hashlib.sha256(json.dumps(
            payload,sort_keys=True,separators=(',',':'),
            ensure_ascii=False).encode()).hexdigest(),
        'signed':False,
        'original_broker_signature_issued':False,
        'wordpress_readiness_option_written':False,
        'master_admin_ticket_approved':False,
        'host312_proof_issued':False,
        'host_files_changed':False,
        'publication_authorized':False,
    }

if __name__=='__main__':
    parser=argparse.ArgumentParser(description='Unsigned original Host readiness for issuer')
    parser.add_argument('--check',action='store_true',required=True)
    args=parser.parse_args()
    print(json.dumps(unsigned_input(),sort_keys=True,ensure_ascii=False))
