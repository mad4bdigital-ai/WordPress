"""Exact Tours.dev LIVE-source preview inside the existing company release lane.

This reads only. Existing signed apply/rollback, v1 plan, and Original313
contracts remain unchanged; a preview is never an executable grant or plan.
"""
from __future__ import annotations
import hashlib
import json
from pathlib import Path, PurePosixPath
import stat
import tarfile
import time

SOURCE_ROOT = Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/plugins/mad4b-site-control-plane')
MANIFEST = Path('/opt/mad4b-company-provisioning/reports/host-live-control-plane-error-policy-v2-20261122/LIVE-SOURCE-MANIFEST-V2.json')
PACKAGE = Path('/opt/mad4b-company-provisioning/reports/host-live-control-plane-error-policy-v2-20261122/TOURSDEV-LIVE-V2-RUNTIME-SOURCE-ONLY.tar.gz')
HOST_EVIDENCE = Path('/opt/mad4b-company-transport/host-authority-v146-external-sites-readonly/latest.json')
PREIMAGES = Path('/opt/mad4b-company-provisioning/backups/host-v149-staging-release-preimage-20261122/STAGING-PREIMAGE-ACCEPTANCE.json')
EXACT_STAGING = {
 'all-royal-egypt:staging': ('49c562d1-8f2f-456f-b454-26816c6ba4cb','hosting-2','https://staging.allroyalegypt.com'),
 'egypt-tour-gates:staging': ('d745d81f-6fc4-5c6a-99dd-d953c92137bf','hosting-3','https://staging.egypttourgates.com'),
}
EXPECTED_PACKAGE_SHA = '3d018c5330be8414e51b995fdc109dcc0e7c50173569d912c46da5313767feec'
CONTRACT = 'mad4b.company-code-release.live-source-staging-preview.v1'

class PreviewDenied(ValueError):
    pass

def require(condition: bool, reason: str) -> None:
    if not condition:
        raise PreviewDenied(reason)

def canonical(value: object) -> bytes:
    return json.dumps(value,sort_keys=True,separators=(',',':')).encode()

def sha(path: Path) -> str:
    h = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda:stream.read(1048576),b''):
            h.update(block)
    return h.hexdigest()

def load_root_json(path: Path) -> dict:
    meta = path.lstat()
    require(stat.S_ISREG(meta.st_mode) and meta.st_uid == 0 and
            not (meta.st_mode & 0o022) and meta.st_size <= 3000000,
            'UNTRUSTED_EVIDENCE')
    value = json.loads(path.read_bytes())
    require(isinstance(value,dict),'BAD_EVIDENCE_SHAPE')
    return value

def validate_path(value: str) -> str:
    require(isinstance(value,str) and 0<len(value)<240 and '\\' not in value and '\x00' not in value and '//' not in value,'BAD_SOURCE_PATH')
    p=PurePosixPath(value)
    require(not p.is_absolute() and '..' not in p.parts and '.' not in p.parts and p.as_posix()==value and p.suffix in ('.php','.css','.js','.json','.txt','.md'),'BAD_SOURCE_PATH')
    require(not any(part in ('.git','vendor','node_modules','uploads') for part in p.parts),'UNSAFE_SOURCE_SUBTREE')
    return value

def source_file_hashes(manifest: dict, source_root: Path) -> dict[str,str]:
    require(manifest.get('contract')=='mad4b.toursdev-live.company-control-plane-source-epoch-v2' and manifest.get('source_of_truth')=='https://tours.dev.mad4b.com' and manifest.get('source_code_location')=='LIVE_SITE_FILES_NOT_GITHUB' and manifest.get('previous_overlay_reuse_allowed') is False,'SOURCE_PROVENANCE_MISMATCH')
    files=manifest.get('overlay_source_file_hashes')
    require(isinstance(files,dict) and len(files)==778 and manifest.get('executable_overlay_count')==778,'SOURCE_FILES_NOT_778')
    for relative, expected in files.items():
        path=source_root/validate_path(relative)
        require(isinstance(expected,str) and len(expected)==64 and all(c in '0123456789abcdef' for c in expected),'INVALID_SOURCE_DIGEST')
        require(path.is_file() and not path.is_symlink() and path.stat().st_size<=4_000_000 and sha(path)==expected,'LIVE_SOURCE_FILE_DRIFT:'+relative)
    return files

def archive_hashes(path: Path) -> dict[str,str]:
    require(path.is_file() and not path.is_symlink() and path.stat().st_size < 200_000_000,'UNTRUSTED_PREIMAGE_ARCHIVE')
    result={}
    with tarfile.open(path,'r:gz') as tar:
        for entry in tar:
            require(entry.isfile() or entry.isdir(),'UNSAFE_PREIMAGE_MEMBER')
            parts=PurePosixPath(entry.name).parts
            require(parts and parts[0]=='mad4b-site-control-plane' and '..' not in parts,'UNSAFE_PREIMAGE_PATH')
            if not entry.isfile():continue
            relative='/'.join(parts[1:])
            require(relative and relative not in result,'DUPLICATE_PREIMAGE_PATH')
            content=tar.extractfile(entry)
            require(content is not None and entry.size<=4_000_000,'UNSAFE_PREIMAGE_FILE')
            result[relative]=hashlib.sha256(content.read()).hexdigest()
    return result

def build_preview(policy: dict, manifest: dict, host: dict, backup: dict, *,
                  source_root: Path, package: Path, now: int | None=None) -> dict:
    now=int(time.time()) if now is None else int(now)
    # New entries are preview scopes only: NEVER place remote Shared Hosting
    # paths in the existing local Docker-target `targets` grant registry.
    targets=policy.get('live_source_staging_preview_targets')
    require(policy.get('contract')=='mad4b.company-code-release-policy.v1' and isinstance(targets,dict) and set(targets)==set(EXACT_STAGING),'PREVIEW_SCOPES_NOT_ENROLLED')
    require(manifest.get('overlay_sha256')==EXPECTED_PACKAGE_SHA and package.is_file() and not package.is_symlink() and sha(package)==EXPECTED_PACKAGE_SHA,'LIVE_SOURCE_PACKAGE_DRIFT')
    files=source_file_hashes(manifest,source_root)
    require(host.get('contract')=='mad4b.host-v146.external-host-exact-site-ssh-readback.v1' and host.get('write_authorized') is False and host.get('verified_site_count')==5 and type(host.get('observed_epoch')) is int and 0<=now-host['observed_epoch']<=7200,'STAGING_IDENTITY_NOT_FRESH')
    require(backup.get('contract')=='mad4b.host-v149.staging-plugin-preimage-offline-restore.v1' and backup.get('target_count')==2 and backup.get('external_staging_plugins_modified') is False,'PREIMAGE_RECEIPT_INVALID')
    siteproof={(r['company']+':'+r['environment']):r for r in host['sites']}
    preimages={(r['company']+':'+r['environment']):r for r in backup['targets']}
    require(set(EXACT_STAGING).issubset(siteproof) and set(preimages)==set(EXACT_STAGING),'BACKUP_SCOPE_DRIFT')
    sites=[]
    for scope, (uuid,hid,origin) in EXACT_STAGING.items():
        binding=targets[scope]
        require(isinstance(binding,dict) and binding=={'site_uuid':uuid,'host_id':hid,'origin':origin,'preview_only':True},'UNTRUSTED_PREVIEW_TARGET')
        evidence=siteproof[scope];p=preimages[scope]
        require(evidence.get('site_uuid')==uuid and evidence.get('host_id')==hid and evidence.get('canonical_origin')==origin and evidence.get('identity_verified') is True and evidence.get('pinned_ssh_verified') is True and evidence.get('host_company_binding_readonly_verified') is True,'REMOTE_SITE_PROOF_INVALID')
        require(p.get('site_uuid')==uuid and p.get('host_id')==hid and p.get('offline_plugin_restore_verified') is True and p.get('remote_database_backup_verified') is False,'PREIMAGE_NOT_VERIFIED')
        archive=Path(p['archive'])
        require(archive.is_file() and not archive.is_symlink() and sha(archive)==p['archive_sha256'],'PREIMAGE_ARCHIVE_DRIFT')
        old=archive_hashes(archive)
        changed=[]
        for source_relative, source_sha in files.items():
            before=old.get(source_relative)
            if before!=source_sha:
                changed.append({'source':'wp-content/plugins/mad4b-site-control-plane/'+source_relative,'target':'wp-content/plugins/mad4b-site-control-plane/'+source_relative,'source_sha256':source_sha,'expected_before_sha256':before})
        sites.append({'scope':scope,'site_uuid':uuid,'host_id':hid,'origin':origin,'preimage_archive_sha256':p['archive_sha256'],'equal_count':len(files)-len(changed),'changed_count':sum(x['expected_before_sha256'] is not None for x in changed),'missing_count':sum(x['expected_before_sha256'] is None for x in changed),'target_only_files_preserved_count':len(set(old)-set(files)),'file_changes':changed,'full_database_restore_verified':False})
    report={'contract':CONTRACT,'source':'TOURSDEV_LIVE_NOT_GIT','source_package_sha256':EXPECTED_PACKAGE_SHA,'source_file_count':len(files),'sites':sites,'external_staging_only':True,'other_target_files_preserved':True,'original_v1_apply_grants_unchanged':True,'release_executed':False,'signed_dispatch_authorized':False,'original313_signed_receipt':False,'requires_independent_owner_grant':True,'requires_verified_full_restore':True}
    report['preview_sha256']=hashlib.sha256(canonical(report)).hexdigest()
    return report

def preview_from_original_pins(policy: dict) -> dict:
    return build_preview(policy,load_root_json(MANIFEST),load_root_json(HOST_EVIDENCE),load_root_json(PREIMAGES),source_root=SOURCE_ROOT,package=PACKAGE)
