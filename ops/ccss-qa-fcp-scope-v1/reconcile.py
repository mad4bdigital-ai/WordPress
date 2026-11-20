#!/usr/bin/env python3
"""Exact, site-owned CSS browser-QA fix: FCP is in-scope only for CCSS widths.

Site-neutral and plan-only by default. Apply requires the original root-owned
site CSS renderer digest, explicit identity and a locked backup. Never issues
CSS certificates, grants, overrides browser evidence or changes public cache.
"""
import argparse, fcntl, hashlib, json, os, pathlib, re, stat, tempfile

OLD = "&&Number.isFinite(fcpGain)&&fcpGain >= (ADAPTIVE_MAX&&width>ADAPTIVE_MAX ? -250 : 150)"
NEW = "&&mad4bFcpGate(width,ADAPTIVE_MAX,fcpGain)"
HELPER = """// FCP is an admission metric only where Critical CSS is actually active.
// Above the verified cap, the unmodified, render-blocking full CSS is used;
// compare layout, interactions, CLS, LCP and failed requests as usual.
function mad4bFcpGate(width,cap,gain){
 return Number.isFinite(gain) && ((cap>0&&width>cap) || gain>=150);
}
"""
ANCHOR = "if(ADAPTIVE_MAX!==0&&(!Number.isInteger(ADAPTIVE_MAX)||ADAPTIVE_MAX<480||ADAPTIVE_MAX>767))throw Error('INVALID_ADAPTIVE_BREAKPOINT');\n"
CONTRACT = 'mad4b.css.site-owned-cron.profile.v1'

def transform(source: str) -> str:
    if source.count(NEW) == 1 and source.count(HELPER) == 1 and OLD not in source:
        return source
    if (source.count(OLD) != 1 or source.count(ANCHOR) != 1
            or NEW in source or 'function mad4bFcpGate(' in source):
        raise ValueError('unrecognized_css_verify_contract')
    return source.replace(ANCHOR, ANCHOR + HELPER, 1).replace(OLD, NEW, 1)

def digest(root: pathlib.Path, replacement: bytes | None = None) -> str:
    if root.is_symlink() or not root.is_dir():
        raise ValueError('untrusted_renderer_dir')
    st = root.stat()
    if st.st_uid != 0 or st.st_mode & 0o022:
        raise ValueError('renderer_dir_permissions')
    paths=[]
    for p in root.rglob('*'):
        if '__pycache__' in p.parts or p.suffix == '.pyc':
            continue
        if p.is_symlink():
            raise ValueError('renderer_symlink')
        st=p.stat()
        if st.st_uid != 0 or st.st_mode & 0o022:
            raise ValueError('renderer_entry_permissions')
        if p.is_file():
            if not stat.S_ISREG(st.st_mode):
                raise ValueError('nonregular_renderer_entry')
            paths.append(p)
        elif not p.is_dir():
            raise ValueError('unknown_renderer_entry')
    if not 1 <= len(paths) <= 250:
        raise ValueError('renderer_count')
    result=hashlib.sha256()
    for p in sorted(paths,key=lambda x:x.relative_to(root).as_posix()):
        name=p.relative_to(root).as_posix().encode()
        data=(replacement if replacement is not None and name==b'tools/verify.js'
              else p.read_bytes())
        result.update(len(name).to_bytes(4,'big')+name)
        result.update(len(data).to_bytes(8,'big')+data)
    return result.hexdigest()

def atomic(path: pathlib.Path, data: bytes):
    old=path.stat()
    fd,name=tempfile.mkstemp(prefix='.ccss-qa-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(name,stat.S_IMODE(old.st_mode))
        os.chown(name,old.st_uid,old.st_gid)
        os.replace(name,path)
    finally:
        if os.path.exists(name):
            os.unlink(name)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--site-dir',required=True)
    ap.add_argument('--expected-site',required=True)
    ap.add_argument('--backup-dir')
    ap.add_argument('--apply',action='store_true')
    args=ap.parse_args()
    directory=pathlib.Path(args.site_dir)
    if directory.is_symlink() or not directory.is_dir() or not directory.resolve().is_relative_to(pathlib.Path('/opt')):
        raise ValueError('site_directory_not_enrolled')
    profile_path=directory/'site-profile.json'
    root=directory/'renderer'
    verify=root/'tools/verify.js'
    if profile_path.is_symlink() or verify.is_symlink() or not verify.is_file():
        raise ValueError('site_contract_symlink_or_missing')
    if profile_path.stat().st_uid != 0 or profile_path.stat().st_mode & 0o022:
        raise ValueError('profile_owner')
    p=json.loads(profile_path.read_text())
    if (p.get('contract')!=CONTRACT or p.get('site_id')!=args.expected_site
        or not re.fullmatch('[a-z][a-z0-9_-]{1,63}',args.expected_site)
        or p.get('environment') not in ('staging','production')
        or not re.fullmatch('[a-z0-9.-]+',p.get('site_host',''))):
        raise ValueError('site_identity_mismatch')
    lockfile=pathlib.Path('/run/lock/mad4b-css-site-'+args.expected_site+'.lock')
    with lockfile.open('a+b') as locked:
        fcntl.flock(locked, fcntl.LOCK_EX | fcntl.LOCK_NB)
        current=digest(root)
        if p.get('renderer_sha256') != current:
            raise ValueError('registered_renderer_digest_mismatch')
        original=verify.read_bytes()
        target=transform(original.decode('utf-8')).encode()
        proposed=digest(root,target)
        receipt={'contract':'mad4b.css-qa.fcp-scope-reconcile.v1',
          'site':args.expected_site,'host':p['site_host'],
          'current_digest':current,'proposed_digest':proposed,
          'changed':target!=original,'applied':False}
        if args.apply and target!=original:
            if not args.backup_dir:
                raise ValueError('backup_dir_required')
            backup=pathlib.Path(args.backup_dir)
            if (backup.is_symlink() or not backup.is_dir() or backup.stat().st_uid!=0
                or backup.stat().st_mode & 0o077
                or not backup.resolve().is_relative_to(pathlib.Path('/opt/mad4b-backups'))):
                raise ValueError('untrusted_backup_root')
            before_js=backup/('verify-before-'+current+'.js')
            before_profile=backup/('site-profile-before-'+current+'.json')
            for path, data in ((before_js,original),(before_profile,profile_path.read_bytes())):
                if path.exists():
                    if path.read_bytes()!=data:
                        raise ValueError('backup_conflict')
                else:
                    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
                    with os.fdopen(fd,'wb') as f:
                        f.write(data);f.flush();os.fsync(f.fileno())
            try:
                atomic(verify,target)
                if digest(root)!=proposed:
                    raise ValueError('render_digest_mismatch_after_patch')
                next_profile=dict(p,renderer_sha256=proposed)
                atomic(profile_path,(json.dumps(next_profile,indent=2)+'\n').encode())
                if digest(root)!=next_profile['renderer_sha256']:
                    raise ValueError('profile_drift_after_update')
            except Exception:
                atomic(verify,original)
                atomic(profile_path,before_profile.read_bytes())
                raise
            receipt['applied']=True
            receipt['backup_dir']=str(backup)
    print(json.dumps(receipt,sort_keys=True))

if __name__=='__main__':
    main()
