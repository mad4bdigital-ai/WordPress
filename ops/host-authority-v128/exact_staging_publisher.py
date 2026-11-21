"""Root-only transactional Original318 MU pair publisher with rollback.

This is intentionally not a standalone CLI, WordPress endpoint, Host Runner
operation or generic filesystem write interface. Its first-party invoker
must provide genuine signed enrollment, standing entitlement, exact grant,
current Desired and a signed atomic plan. Only the Original318 v3 MU pair is
supported, so a partially installed loader remains fail-closed.
"""
from __future__ import annotations
import hashlib
import fcntl
import json
from functools import wraps
import os
from pathlib import Path
import stat
import uuid

from fleet_policy import SiteBinding
from persistent_enrollment import ConnectionDenied, need
from live_current_source import read_current
from live_first_reconciliation import verify_desired,verify_previous_backup
from standing_write import admit_exact_standing_write

MU_PAIR = (
    "wp-content/mu-plugins/410-mad4b-original318-v3-readback.php",
    "wp-content/mu-plugins/class-mad4b-original318-host-v3-reader.php",
)
MAX_BYTES = 2*1024*1024
DOMAIN = "mad4b.host-v130.original318-transactional-apply.v1"

def _file_parts(root:Path,relative:str):
    need(relative in MU_PAIR,"EXACT_MU_PAIR_ONLY")
    nofollow=getattr(os,"O_NOFOLLOW",0)
    directory=getattr(os,"O_DIRECTORY",0)
    need(nofollow!=0 and directory!=0,"SECURE_OPENAT_REQUIRED")
    fd=os.open(str(root),os.O_RDONLY|directory|nofollow)
    parts=relative.split("/")
    try:
        for name in parts[:-1]:
            child=os.open(name,os.O_RDONLY|directory|nofollow,dir_fd=fd)
            os.close(fd)
            fd=child
        return fd,parts[-1]
    except Exception:
        os.close(fd)
        raise

def _read_exact(root:Path,relative:str):
    parent,name=_file_parts(root,relative)
    filefd=None
    try:
        try:
            filefd=os.open(name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,
                           dir_fd=parent)
        except FileNotFoundError:
            return None
        s=os.fstat(filefd)
        need(stat.S_ISREG(s.st_mode) and 0<=s.st_size<=MAX_BYTES,
             "ORIGINAL318_SOURCE_NOT_BOUNDED_REGULAR_FILE")
        out=bytearray()
        while len(out)<=MAX_BYTES:
            b=os.read(filefd,65536)
            if not b:break
            out.extend(b)
        after=os.fstat(filefd)
        need(len(out)<=MAX_BYTES and len(out)==s.st_size
             and (s.st_dev,s.st_ino,s.st_mtime_ns,s.st_ctime_ns)==
                 (after.st_dev,after.st_ino,after.st_mtime_ns,after.st_ctime_ns),
             "ORIGINAL318_SOURCE_CHANGED_DURING_READ")
        return bytes(out)
    finally:
        if filefd is not None:os.close(filefd)
        os.close(parent)

def _write_exact(root:Path,relative:str,raw:bytes|None):
    parent,name=_file_parts(root,relative)
    pending=".mad4b-v130-"+uuid.uuid4().hex+".pending"
    try:
        # Existing symlinks, special files and swapped directories fail closed.
        try:
            meta=os.stat(name,dir_fd=parent,follow_symlinks=False)
            need(stat.S_ISREG(meta.st_mode),"ORIGINAL318_TARGET_NOT_REGULAR")
        except FileNotFoundError:
            pass
        if raw is None:
            try:
                os.unlink(name,dir_fd=parent)
            except FileNotFoundError:
                pass
            return
        need(type(raw) is bytes and 0<len(raw)<=MAX_BYTES,
             "ORIGINAL318_ARTIFACT_INVALID")
        fd=os.open(pending,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,
                   0o640,dir_fd=parent)
        try:
            with os.fdopen(fd,"wb") as file:
                file.write(raw);file.flush();os.fsync(file.fileno())
            os.replace(pending,name,src_dir_fd=parent,dst_dir_fd=parent)
            os.fsync(parent)
        finally:
            try:os.unlink(pending,dir_fd=parent)
            except FileNotFoundError:pass
    finally:
        os.close(parent)

def _sha(raw:bytes|None):
    return "ABSENT" if raw is None else hashlib.sha256(raw).hexdigest()

def _no_symlink_ancestors(path:Path):
    current=path
    while True:
        need(not current.is_symlink(),"ORIGINAL318_PATH_SYMLINK_ANCESTOR")
        if current.parent==current:
            break
        current=current.parent

def _check_wp_root(root:Path):
    _no_symlink_ancestors(root)
    need(root.is_absolute() and root.is_dir() and not root.is_symlink()
         and (root/"wp-config.php").is_file(),
         "ORIGINAL318_INSTALLED_WORDPRESS_ROOT_REQUIRED")
    # The real site root must be provided by the enrolled Root caller, never
    # a job path, a Git staging checkout or user supplied config.
    need((root/"wp-content/mu-plugins").is_dir(),
         "ORIGINAL318_MU_DIR_MISSING")

def _private_backup(backup_root:Path,root:Path,operation_id:str,
                    previous:dict):
    _no_symlink_ancestors(backup_root)
    need(backup_root.is_absolute() and backup_root.is_dir()
         and not backup_root.is_symlink()
         and backup_root.resolve()!=root.resolve()
         and not backup_root.resolve().is_relative_to(root.resolve()),
         "ORIGINAL318_BACKUP_ROOT_INVALID")
    s=backup_root.stat()
    need(s.st_uid==os.geteuid() and not(stat.S_IMODE(s.st_mode)&0o077),
         "ORIGINAL318_BACKUP_ROOT_NOT_PRIVATE")
    need(len(operation_id)==32 and all(c in "0123456789abcdef"
                                     for c in operation_id),
         "ORIGINAL318_OPERATION_ID_INVALID")
    folder=backup_root/operation_id
    folder.mkdir(mode=0o700)
    for i,path in enumerate(MU_PAIR):
        raw=previous[path]
        if raw is not None:
            fd=os.open(str(folder/(str(i)+".before")),
                       os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
            with os.fdopen(fd,"wb") as f:
                f.write(raw);f.flush();os.fsync(f.fileno())
    manifest={
        "contract":DOMAIN,"operation_id":operation_id,
        "preimages":{p:{"state":"ABSENT" if v is None else "PRESENT",
                        "sha256":_sha(v), "bytes":0 if v is None else len(v)}
                     for p,v in previous.items()},
        "preimage_root":"installed_wordpress","rollback_tested":False,
    }
    with (folder/"MANIFEST.json").open("x") as f:
        os.chmod(folder/"MANIFEST.json",0o600)
        json.dump(manifest,f,sort_keys=True)
        f.flush();os.fsync(f.fileno())
    return folder

def exclusive_publisher_lock(func):
    """Serialize same-host first-party v130 installs; no network/cron API."""
    @wraps(func)
    def inner(*,wp_root:Path,private_backup_root:Path,**kwargs):
        _check_wp_root(wp_root)
        _no_symlink_ancestors(private_backup_root)
        need(private_backup_root.is_absolute() and private_backup_root.is_dir(),
             "ORIGINAL318_BACKUP_ROOT_INVALID")
        meta=private_backup_root.stat()
        need(meta.st_uid==os.geteuid()
             and not (stat.S_IMODE(meta.st_mode)&0o077),
             "ORIGINAL318_BACKUP_ROOT_NOT_PRIVATE")
        fd=os.open(str(private_backup_root/".original318-v130.lock"),
                   os.O_WRONLY|os.O_CREAT|os.O_NOFOLLOW,0o600)
        try:
            fcntl.flock(fd,fcntl.LOCK_EX)
            return func(wp_root=wp_root,private_backup_root=private_backup_root,
                        **kwargs)
        finally:
            fcntl.flock(fd,fcntl.LOCK_UN)
            os.close(fd)
    return inner

@exclusive_publisher_lock
def execute_v3_mu_pair(*,wp_root:Path,private_backup_root:Path,
                       site_binding:SiteBinding,origin:str,
                       current_reader,pinned_current_key:bytes,
                       approved_desired:dict,desired_highwater:dict,
                       desired_public:bytes,desired_registry_public:bytes,
                       entitlement:dict,entitlement_highwater:dict,
                       owner305_public:bytes,issuer312_public:bytes,
                       independent313_public:bytes,
                       operation_grant_public:bytes,activity_public:bytes,
                       plan_signer_public:bytes,
                       v3_enrollment:dict,v3_connection_highwater:dict,
                       original318_readback:dict,
                       operation_grant:dict,operation_activity:dict,
                       signed_plan:dict,signed_previous_backup:dict,
                       new_bytes:dict[str,bytes],
                       healthcheck,now:int,
                       production_consent=None):
    """Apply exact approved Original318 MU pair, rollback on failed health/readback.

    New bytes are independently checked against signed Desired. Signed plans
    are operation-bound. Each Root invoker must pin all seven identities from
    its authenticated host registry. No generic shell/network access here.
    """
    _check_wp_root(wp_root)
    need(callable(healthcheck),"ORIGINAL318_HEALTHCHECK_REQUIRED")
    approved=verify_desired(approved_desired,site_binding,
                            desired_public,desired_highwater,
                            desired_registry_public,now=now)
    admitted=admit_exact_standing_write(
        entitlement,entitlement_highwater,site_binding,
        owner305_public,independent313_public,
        v3_enrollment,v3_connection_highwater,original318_readback,
        operation_grant,operation_activity,
        issuer312_public,operation_grant_public,activity_public,
        signed_plan,plan_signer_public,
        "site.code.atomic.install",now=now,
        production_receipt=production_consent)
    plan=signed_plan["plan"]
    need(plan["desired_sha256"]==approved.signed_record_sha256
         and plan["source_commit_sha"]==approved.source_head_sha
         and set(plan["paths"])==set(MU_PAIR)
         and set(new_bytes)==set(MU_PAIR),
         "ORIGINAL318_SIGNED_PLAN_OR_ARTIFACT_SET_DRIFT")
    group=next((g for g in approved.groups
                if g[0]==plan["group_id"]),None)
    need(group is not None and set(group[1])==set(MU_PAIR)
         and not group[2],
         "ORIGINAL318_ATOMIC_GROUP_UNAPPROVED")
    targets=approved.desired_map()
    for path,raw in new_bytes.items():
        need(type(raw) is bytes and 0<len(raw)<=MAX_BYTES
             and targets[path]==("PRESENT",_sha(raw),len(raw)),
             "ORIGINAL318_ARTIFACT_NOT_APPROVED")
    old=read_current(site_binding,current_reader,pinned_current_key,
                     origin,now=now)
    need(old.snapshot_sha256==plan["current_sha256"],
         "ORIGINAL318_LIVE_CURRENT_PREIMAGE_DRIFT")
    expected=old.file_map()
    selected_group={"group_id":plan["group_id"],"paths":list(MU_PAIR)}
    signed_previous=verify_previous_backup(
        signed_previous_backup,old,site_binding,selected_group,
        pinned_current_key,now=now)
    need(signed_previous["backup_receipt_id"]==plan["previous"]["backup_receipt_id"]
         and signed_previous["backup_bundle_sha256"]==plan["previous"]["backup_bundle_sha256"],
         "ORIGINAL318_PREVIOUS_SIGNED_BACKUP_DRIFT")
    originals={path:_read_exact(wp_root,path) for path in MU_PAIR}
    def file_tuple(raw):
        return ("ABSENT","ABSENT",0) if raw is None else (
            "PRESENT",_sha(raw),len(raw))
    need(all(expected[path]==file_tuple(originals[path]) for path in MU_PAIR),
         "ORIGINAL318_LIVE_PREIMAGE_HASH_CHANGED")
    # A separate already-sealed backup was attested by the signed plan;
    # this local emergency backup is created before a single file changes.
    backup=_private_backup(private_backup_root,wp_root,
                           operation_grant["operation_id"],originals)
    # Commit-time CAS. The independent first-party Current signer must
    # provide a fresh distinct readback, not the cached plan snapshot.
    latest=read_current(site_binding,current_reader,pinned_current_key,
                        origin,now=now)
    need(latest.snapshot_nonce!=old.snapshot_nonce
         and latest.files==old.files,
         "ORIGINAL318_ATOMIC_PRECOMMIT_CAS_FAILED")
    modified=[]
    try:
        for path in MU_PAIR:
            # Track BEFORE the write: even if replace() succeeded but fsync
            # failed afterwards, rollback must still cover this path.
            modified.append(path)
            _write_exact(wp_root,path,new_bytes[path])
        need(healthcheck(wp_root) is True,
             "ORIGINAL318_WORDPRESS_BOOTSTRAP_FAILED")
        confirmed=read_current(site_binding,current_reader,pinned_current_key,
                               origin,now=now)
        need(confirmed.snapshot_nonce!=latest.snapshot_nonce
             and all(confirmed.file_map()[p]==targets[p] for p in MU_PAIR),
             "ORIGINAL318_POSTWRITE_READBACK_MISMATCH")
        return {
            "contract":DOMAIN,
            "operation_id":operation_grant["operation_id"],
            "site_uuid":site_binding.site_uuid,
            "group_id":plan["group_id"],
            "standing_write":admitted["state"],
            "previous_backup":str(backup),
            "current_snapshot_sha256":confirmed.snapshot_sha256,
            "write_performed":True,
            "postwrite_readback_verified":True,
            "rollback_required":False,
        }
    except Exception:
        # No blind rollback over a *third-party* concurrent change.
        recovery_required=[]
        for path in reversed(modified):
            current_sha=_sha(_read_exact(wp_root,path))
            if current_sha==_sha(originals[path]):
                # The failing write never changed this file.
                continue
            if current_sha!=_sha(new_bytes[path]):
                recovery_required.append(path)
                continue
            _write_exact(wp_root,path,originals[path])
        if recovery_required:
            raise ConnectionDenied("ORIGINAL318_RECOVERY_REQUIRED_CONCURRENT_WRITE")
        for path in MU_PAIR:
            need(_sha(_read_exact(wp_root,path))==_sha(originals[path]),
                 "ORIGINAL318_ROLLBACK_PREIMAGE_MISMATCH")
        rollback_current=read_current(site_binding,current_reader,pinned_current_key,
                                     origin,now=now)
        need(all(rollback_current.file_map()[p]==old.file_map()[p]
                 for p in MU_PAIR),
             "ORIGINAL318_ROLLBACK_READBACK_NOT_VERIFIED")
        raise ConnectionDenied("ORIGINAL318_APPLY_FAILED_ROLLED_BACK")
