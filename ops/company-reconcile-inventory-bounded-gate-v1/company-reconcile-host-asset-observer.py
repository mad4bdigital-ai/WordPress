#!/usr/bin/env python3
"""Cheap read-only host checksum observation; NOT an Original313/owner certificate.

Uses EXACT pre-enrolled local document roots and the existing canonical
source-owned presentation allowlist. No WordPress process, job or Grant needed.
Never upgrades the older *signed and WPCLI* inventory's freshness metadata.
"""
from __future__ import annotations
import ast,datetime,hashlib,json,os,pathlib,re,sys,tempfile
ROOT=pathlib.Path('/opt/mad4b-company-provisioning')
POLICY=ROOT/'policies/company-fx-distribution-v1.json'
SOURCE_ENGINE=ROOT/'bin/company-system-appearance-broker-reconcile.py'
REPORT=ROOT/'reports/company-reconcile-host-assets-current.json'
CONTRACT='mad4b.company-reconcile.host-asset-observation.v1'
MAX_FILE=4_000_000

def trusted_file(p:pathlib.Path,max_bytes:int)->bool:
    try:
        st=p.lstat();return p.is_file() and not p.is_symlink() and st.st_uid==0 and not (st.st_mode&0o022) and 0<st.st_size<=max_bytes
    except (OSError,ValueError):return False

def allowed_assets(code:pathlib.Path)->tuple[str,...]:
    if not trusted_file(code,120_000):raise RuntimeError('SOURCE_ASSET_MANIFEST_UNTRUSTED')
    tree=ast.parse(code.read_text(encoding='utf-8'))
    assignments=[n for n in tree.body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='ASSETS' for t in n.targets)]
    if len(assignments)!=1:raise RuntimeError('CANONICAL_ASSET_CATALOG_UNAVAILABLE')
    paths=ast.literal_eval(assignments[0].value)
    if not isinstance(paths,tuple) or not paths or len(paths)>40 or len(paths)!=len(set(paths)):
        raise RuntimeError('SOURCE_ASSET_CATALOG_INVALID')
    for rel in paths:
        if not isinstance(rel,str) or not re.fullmatch(r'wp-content/(?:plugins/mad4b-commerce/modules/presentation/[a-zA-Z0-9_./-]+|maintenance\.php)',rel) or '..' in rel:
            raise RuntimeError('SOURCE_ASSET_CATALOG_UNBOUNDED')
    return paths

def enrolled_pair(policy:dict)->tuple[dict,dict]:
    if policy.get('contract')!='mad4b.company-fx-distribution.v1':raise RuntimeError('ENROLLMENT_CONTRACT_INVALID')
    source=policy.get('source');targets=policy.get('targets')
    if not isinstance(source,dict) or not isinstance(targets,list) or len(targets)>40:
        raise RuntimeError('ENROLLMENT_SCHEMA_INVALID')
    eligible=[x for x in targets if isinstance(x,dict) and x.get('company_code')=='wayfatours' and x.get('environment')=='production']
    if len(eligible)!=1:raise RuntimeError('EXACT_COMPANY_ENROLLMENT_UNAVAILABLE')
    site=eligible[0]
    for entry,origin,uid in ((source,'https://tours.dev.mad4b.com','e5e64bca-ed62-48d2-b2b3-dff693cd702a'),
                             (site,'https://wayfatours.com','a3a7cc36-db7e-4a14-8456-b41857def05c')):
        if entry.get('origin')!=origin or entry.get('site_uuid')!=uid:
            raise RuntimeError('EXACT_ORIGIN_OR_UUID_BINDING_MISMATCH')
        root=pathlib.Path(str(entry.get('document_root','')))
        if not root.is_absolute() or root.is_symlink() or not root.is_dir() or root.resolve()!=root:
            raise RuntimeError('EXACT_CANONICAL_DOCUMENT_ROOT_REQUIRED')
    return source,site

def safe_digest(root:pathlib.Path,rel:str)->str|None:
    dest=root/rel
    if dest.is_symlink() or any((root/part).is_symlink() for part in list(pathlib.Path(rel).parents) if str(part)!='.'):
        raise RuntimeError('HOST_FILE_SYMLINK_NOT_ALLOWED')
    if not dest.exists():return None
    if not dest.is_file() or dest.stat().st_size>MAX_FILE:
        raise RuntimeError('UNSUPPORTED_HOST_ASSET_FILE')
    h=hashlib.sha256();size=0
    with dest.open('rb') as inp:
        while block:=inp.read(65536):
            size+=len(block)
            if size>MAX_FILE:raise RuntimeError('HOST_ASSET_FILE_TOO_LARGE')
            h.update(block)
    return h.hexdigest()

def snapshot(policy:dict,assets:tuple[str,...]):
    source,site=enrolled_pair(policy)
    src=pathlib.Path(source['document_root']);target=pathlib.Path(site['document_root'])
    records=[]
    for rel in assets:
        want=safe_digest(src,rel)
        if want is None:raise RuntimeError('ORIGINAL_SOURCE_ASSET_MISSING')
        have=safe_digest(target,rel)
        records.append({'path':rel,'source_sha256':want,'target_sha256':have,'different':want!=have})
    return {'contract':CONTRACT,'observed_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'source_origin':source['origin'],'source_uuid':source['site_uuid'],
        'target_origin':site['origin'],'target_uuid':site['site_uuid'],
        'company_code':'wayfatours','environment':'production','comparison':'HOST_READ_ONLY_FILE_SHA256',
        'scope':'SOURCE_OWNED_ADMIN_PRESENTATION_ASSET_CATALOG_ONLY',
        'catalog_files':len(records),'different_files':sum(bool(x['different']) for x in records),
        'items':records,'full_wpcli_inventory_certified':False,
        'original305_request_sha256_certified':False,'original313_signed_write_receipt_issued':False,
        'target_runtime_identity_proven_by_this_observer':False,'read_only':True,
        'tenant_mutated':False,'permissions_granted':False,'jobs_created':0}

def publish(data,report:pathlib.Path=REPORT):
    if report.is_symlink() or not report.parent.is_dir() or report.parent.is_symlink():
        raise RuntimeError('UNTRUSTED_HOST_REPORT_PATH')
    fd,name=tempfile.mkstemp(prefix='.company-host-assets-',dir=report.parent)
    try:
        with os.fdopen(fd,'w') as out:json.dump(data,out,indent=2);out.write('\n');out.flush();os.fsync(out.fileno())
        os.chmod(name,0o600);os.replace(name,report)
    finally:
        if os.path.exists(name):os.unlink(name)

def main():
    if not trusted_file(POLICY,100_000):raise RuntimeError('HOST_ENROLLMENT_POLICY_UNTRUSTED')
    data=snapshot(json.loads(POLICY.read_text()),allowed_assets(SOURCE_ENGINE))
    publish(data)
    print(json.dumps({'state':'FRESH_ADVISORY_HOST_ASSET_READBACK','target':'wayfatours:production',
                      'asset_count':data['catalog_files'],'different_files':data['different_files'],
                      'not_signed_original313':True,'full_wpcli_inventory_still_independent':True,
                      'report':str(REPORT)}))
if __name__=='__main__':main()
