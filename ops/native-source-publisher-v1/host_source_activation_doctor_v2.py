#!/usr/bin/env python3
"""First-party MAD4B Host Source Release admission diagnostic.

READ-ONLY. Reports the independent installed authorities that are actually
present, not Git-only candidates or user-supplied booleans. Cannot sign a
Host312 receipt, enroll a provider, claim a job or perform a Host mutation.
"""
from __future__ import annotations
import hashlib
import hmac
import json
from pathlib import Path
import stat
import subprocess
from typing import Callable

import native_host_source_ownership as owners

HOST_FORMAT='host.source.ref.v1'
HOST_FAMILY='mad4b.native-host-source-release.v1'
HOST_OP='host.source.release'
ROOT=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
WORKER=Path('/opt/mad4b-company-transport/native-313-independent/claimed-job-worker.py')
PUBLISHER_POLICY=Path('/etc/mad4b/native-source-publisher-policy.v2.json')
HOST_CATALOG=owners.CATALOG
REQUIRED_DEPENDENCIES={
    'dynamic-operation-registry-v1/host_admission.py',
    'dynamic-operation-registry-v1/host312_original_issuer.py',
    'native-source-publisher-v1/native_host_source_ownership.py',
    'native-source-publisher-v1/native_host_source_transaction.py',
    'native-source-publisher-v1/native_host_source_readback.py',
    'native-source-publisher-v1/native_host_source_final_receipt.py',
    'native-source-publisher-v1/host_original306_worker_lane.py',
}


def trusted_regular(path: Path, *, private=False)->bool:
    try:
        if path.is_symlink() or not path.is_file():return False
        meta=path.stat()
        if meta.st_uid!=0 or meta.st_gid!=0 or not stat.S_ISREG(meta.st_mode):return False
        if meta.st_mode&0o022:return False
        if private and meta.st_mode&0o077:return False
        return True
    except OSError:
        return False


def actual_worker_host_contract(path:Path=WORKER)->bool:
    """Static rejection guard, never adequate as execution proof."""
    if not trusted_regular(path):return False
    try:
        text=path.read_text()
    except (OSError,UnicodeDecodeError):return False
    return all(x in text for x in (
        'host.source.ref.v1','signed_vps_host_source',
        'firstparty_host_source_claim_preflight',
        'verify_first_party_host312'))


def actual_original305_host_provider_ready(*,
        registry_index:Path=ROOT/'index.json',
        owner_catalog:Path=HOST_CATALOG,
        worker:Path=WORKER)->bool:
    """Require signed WP readiness AND exact current first-party root hashes.

    WordPress is the only authority to validate the original Broker signature.
    The Host doctor additionally compares the verified signed payload's
    Registry/Catalog/Worker SHA pins with live root-only resources. This
    rejects any post-signature drift, even inside the 180-second TTL.
    """
    named={
       'source_registry_sha256':registry_index,
       'owner_catalog_sha256':owner_catalog,
       'worker_sha256':worker,
    }
    if any(not trusted_regular(path) for path in named.values()):return False
    try:
        cmd=['docker','exec','-u','www-data','php-wkgtkzta35wkkgouwt6ruljc',
             'wp','--path=/var/www/html','--skip-themes','eval',
             """$p=MAD4B_Original_Native_Operation_Providers_V1::profile('host.source.release');
$v=get_option('mad4b_original_host_source_readiness_v1',false);
$ready=MAD4B_Original_Native_Operation_Providers_V1::legacy_admitted(
 'host.source.release','host','host.source.ref.v1');
echo wp_json_encode([
 'profile'=>is_array($p),'eligible'=>$ready,
 'source_registry_sha256'=>is_array($v)?($v['source_registry_sha256']??null):null,
 'owner_catalog_sha256'=>is_array($v)?($v['owner_catalog_sha256']??null):null,
 'worker_sha256'=>is_array($v)?($v['worker_sha256']??null):null]);"""]
        outcome=subprocess.run(cmd,capture_output=True,text=True,timeout=26,check=False)
        if outcome.returncode:return False
        rows=[json.loads(line) for line in outcome.stdout.splitlines()
              if line.startswith('{')]
        if (len(rows)!=1 or rows[0].get('profile') is not True
            or rows[0].get('eligible') is not True):return False
        for field,path in named.items():
            signed=rows[0].get(field)
            if not (isinstance(signed,str) and len(signed)==64
                    and hmac.compare_digest(
                        hashlib.sha256(path.read_bytes()).hexdigest(),signed)):
                return False
        return True
    except (OSError,ValueError,TypeError,subprocess.TimeoutExpired):
        return False


def source_publisher_enrollment(policy_file:Path=PUBLISHER_POLICY)->bool:
    if not trusted_regular(policy_file,private=True):return False
    try:
        import native_source_publisher as publisher
        # The installed publisher itself requires a root-PINNED exact policy,
        # two independent enrolled keys, target identities and allowed paths.
        if policy_file!=publisher.POLICY_PATH:return False
        return isinstance(publisher.checked_policy(),dict)
    except (ValueError,OSError,TypeError,RuntimeError,KeyError):return False


def host_owner_enrollment(path:Path=HOST_CATALOG)->bool:
    if not trusted_regular(path,private=True):return False
    try:
        if path!=owners.CATALOG:return False
        return isinstance(owners.checked_host_catalog(),dict)
    except (ValueError,OSError,TypeError,owners.HostSourceDenied):return False


def audit(registry_getter:Callable,*,root:Path=ROOT,worker:Path=WORKER,
          publisher_policy:Path=PUBLISHER_POLICY,host_catalog:Path=HOST_CATALOG)->dict:
    checks={}
    checks['original_registry_index_trusted']=trusted_regular(root/'index.json',private=True)
    checks['original_registry_module_trusted']=trusted_regular(root/'registry.py',private=True)
    index=None
    error=None
    if checks['original_registry_index_trusted'] and checks['original_registry_module_trusted']:
        try:
            index=registry_getter()
        except (OSError,ValueError,ImportError,KeyError,TypeError,RuntimeError) as exc:
            error=type(exc).__name__+':'+str(exc)[:85]
    checks['first_party_registry_installed_and_validated']=index is not None
    checks['original_host312_distinct_signer_root_enrolled']=(
        index is not None and isinstance(
            getattr(index,'host_resource_312_public_key',None),bytes) and
        len(index.host_resource_312_public_key)==32 and
        index.host_resource_312_public_key!=getattr(index,'independent_verifier_public_key',None))
    manifest=None
    if index is not None:
        manifest=getattr(index,'manifests',{}).get(HOST_OP)
    checks['original_host_source_manifest_registered']=isinstance(manifest,dict)
    checks['original_host_resource_type_matches']=bool(
        isinstance(manifest,dict) and
        manifest.get('source_family')==HOST_FAMILY and
        manifest.get('target_scope')=='host' and
        manifest.get('resource_kind')=='host_root_file' and
        manifest.get('job_type')=='signed_vps_host_source' and
        manifest.get('command',{}).get('format')==HOST_FORMAT)
    dep_hashes={d.get('path'):d.get('sha256') for d in manifest.get('dependencies',[])
                if isinstance(d,dict)} if isinstance(manifest,dict) else {}
    deps=set(dep_hashes)
    checks['exact_original_handler_and_dependencies_pinned']=bool(
        checks['original_host_resource_type_matches'] and
        REQUIRED_DEPENDENCIES<=deps and
        isinstance(manifest.get('handler'),dict) and
        isinstance(manifest.get('verifier'),dict))
    checks['original306_worker_host_resource_discriminated']=actual_worker_host_contract(worker)
    # Preclaim alone is not a working release lane. Require an enrolled
    # claimed-job executor and a live Original305 WP Provider separately.
    checks['original306_worker_host_claimed_executor_enrolled']=bool(
        checks['original306_worker_host_resource_discriminated'] and
        'firstparty_host_source_claimed_execution' in worker.read_text())
    checks['original305_wp_host_provider_active']=(
        actual_original305_host_provider_ready()
        if root==ROOT and worker==WORKER else False)
    checks['native_source_publisher_policy_enrolled']=source_publisher_enrollment(publisher_policy)
    checks['host_owner_catalog_enrolled']=host_owner_enrollment(host_catalog)
    finalizer=(manifest.get('finalizer') if isinstance(manifest,dict) else None)
    fin_rel=(finalizer.get('path') if isinstance(finalizer,dict) else None)
    checks['host_313_finalizer_registered']=bool(
        checks['exact_original_handler_and_dependencies_pinned'] and
        isinstance(fin_rel,str) and
        fin_rel.endswith('/original_313_host_source_finalizer.py') and
        fin_rel in deps and isinstance(finalizer.get('sha256'),str) and
        dep_hashes[fin_rel]==finalizer['sha256'] and
        len(finalizer['sha256'])==64)
    # This is a platform setup diagnostic, not a signed job admission. These
    # can NEVER be inferred from installed source or a self-supplied fixture.
    blockers=[key for key,value in checks.items() if value is not True]
    return {
        'contract':'mad4b.native-host-source-firstparty-activation-doctor.v1',
        'state':'SETUP_GATES_PRESENT_NEEDS_SIGNED_JOB' if not blockers else 'HOLD__MISSING_FIRST_PARTY_HOST_SOURCE_ADMISSION',
        'checks':checks,'blockers':blockers,
        'first_party_registry_error':error,
        'production_mutation_performed':False,
        'signed_original_312_job_verified':False,
        'master_admin_exact_ticket_verified':False,
        'broker_release_grant_verified':False,
        'independent_original_313_signed_receipt':False,
        'publication_authorized':False,
    }


def actual_status()->dict:
    import native_source_publisher as publisher
    return audit(publisher.original_registry)
