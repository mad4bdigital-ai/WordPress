#!/usr/bin/env python3
"""Read-only inventory of independently enrolled CSS Cron executors.

Neither registration in this file nor an installed WordPress option grants
execution: a valid root-owned site profile, SHA-bound local renderer,
site-specific backend and an already matching OS Cron entry are required.
"""
import argparse,hashlib,json,re,stat
from pathlib import Path
from cron_entry import render

CONTRACT='mad4b.css.site-executor-registry.v1'
PROFILE='mad4b.css.site-owned-cron.profile.v1'
ALIASES=re.compile(r'[a-z][a-z0-9_-]{1,63}\Z')
SHA=re.compile(r'[0-9a-f]{64}\Z')

def trusted_file(path,max_size=8192):
    if path.is_symlink() or not path.is_file() or path.stat().st_uid!=0:
        raise ValueError('untrusted_registry_file')
    if path.stat().st_mode & 0o022 or path.stat().st_size>max_size:
        raise ValueError('untrusted_registry_permissions_or_size')
    return json.loads(path.read_text())

def digest(root):
    if not root.is_dir() or root.is_symlink():
        raise ValueError('renderer_not_enrolled')
    if root.stat().st_uid!=0 or root.stat().st_mode&0o022:
        raise ValueError('renderer_permissions')
    files=[]
    for entry in root.rglob('*'):
        if '__pycache__' in entry.parts or entry.suffix=='.pyc':
            continue
        if entry.is_symlink() or entry.stat().st_uid!=0 or entry.stat().st_mode&0o022:
            raise ValueError('renderer_symlink_or_writable')
        if entry.is_file(): files.append(entry)
        elif not entry.is_dir():raise ValueError('renderer_non_regular')
    if not 1<=len(files)<=250:raise ValueError('renderer_file_count')
    d=hashlib.sha256()
    for f in sorted(files,key=lambda f:f.relative_to(root).as_posix()):
        name=f.relative_to(root).as_posix().encode()
        data=f.read_bytes()
        d.update(len(name).to_bytes(4,'big')+name+len(data).to_bytes(8,'big')+data)
    return d.hexdigest()

def inspect(entry):
    alias=entry.get('site_id')
    if not isinstance(alias,str) or not ALIASES.fullmatch(alias):
        raise ValueError('invalid_site_alias')
    if set(entry)!={'site_id','site_dir','cron_path','offset'}:
        raise ValueError('unexpected_registration_fields')
    folder=Path(entry['site_dir'])
    cron=Path(entry['cron_path'])
    offset=entry['offset']
    result={'site_id':alias,'status':'hold','reason':''}
    try:
        if not folder.is_absolute() or folder.is_symlink() or not folder.is_dir():
            raise ValueError('site_root_missing')
        if '/operations/css-cron-v1' != folder.as_posix()[-len('/operations/css-cron-v1'):]:
            raise ValueError('wrong_site_root_scope')
        profile=trusted_file(folder/'site-profile.json',4096)
        if profile.get('contract')!=PROFILE or profile.get('site_id')!=alias:
            raise ValueError('site_profile_identity')
        if not isinstance(profile.get('site_host'),str) or not re.fullmatch(r'[a-z0-9.-]{3,253}',profile['site_host']):
            raise ValueError('site_host_invalid')
        actual=digest(folder/'renderer')
        if not SHA.fullmatch(str(profile.get('renderer_sha256',''))) or actual!=profile['renderer_sha256']:
            raise ValueError('exact_renderer_pin_failed')
        worker=folder/'worker.py'
        if not worker.is_file() or worker.is_symlink() or worker.stat().st_uid!=0 or worker.stat().st_mode&0o022:
            raise ValueError('worker_not_enrolled')
        # The initial first-party CSS runtime supports only these original
        # enrolled backends. New companies need their OWN host renderer
        # contract and worker profile — never inherit another site's alias.
        if alias not in ('source','wayfa'):
            raise ValueError('site_backend_enrollment_required')
        expected=render(folder,offset)
        if not cron.is_absolute() or not cron.is_file() or cron.is_symlink() or cron.stat().st_uid!=0:
            raise ValueError('active_cron_missing')
        if expected not in cron.read_text().splitlines():
            raise ValueError('active_cron_mismatch')
        result.update(status='active_verified',reason='',host=profile['site_host'],
                      renderer_sha256=actual)
    except (ValueError,TypeError,KeyError,OSError) as e:
        result['reason']=str(e)[:160]
    return result

def snapshot(registry):
    records=trusted_file(Path(registry))
    if records.get('contract')!=CONTRACT or not isinstance(records.get('sites'),list):
        raise ValueError('invalid_registry_contract')
    if len(records['sites'])>32:raise ValueError('too_many_sites')
    aliases=[e.get('site_id') for e in records['sites'] if isinstance(e,dict)]
    if len(set(aliases))!=len(aliases):raise ValueError('duplicate_site_registration')
    results=[inspect(e) for e in records['sites']]
    return {'contract':CONTRACT,'active':sum(x['status']=='active_verified' for x in results),
            'held':sum(x['status']!='active_verified' for x in results),
            'sites':results,'mutated':False,'cron_installed':False}

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--registry',required=True)
    args=p.parse_args()
    print(json.dumps(snapshot(args.registry),ensure_ascii=False,indent=2))

if __name__=='__main__':main()
