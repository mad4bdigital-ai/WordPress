#!/usr/bin/env python3
"""Wayfatours-only v14 renderer activation; never mutates WordPress, CSS, tickets.

Preflight, install and optional emergency rollback are serialised with the
original site/global/critical-CSS locks. Fail-closed source pins and OOM guard.
"""
import contextlib,fcntl,hashlib,json,os,pathlib,re,subprocess,sys,tempfile,urllib.request
BASE=pathlib.Path('/opt/wayfa-rebuild-20261102')
DIR=BASE/'operations/css-cron-v1'
ACTIVE=DIR/'renderer'
STAGE=DIR/'renderer.stage-v14'
BACKUP=DIR/'renderer.rollback-v13-20261119'
PROFILE=DIR/'site-profile.json'
CONSUMER=BASE/'site-copy/wp-content/mu-plugins/415-mad4b-critical-css-orchestrator.php'
CSS_MANIFEST=BASE/'site-copy/wp-content/mad4b-critical-css-v1/manifest.json'
NEW='9e12e18e257e6102f8a866332e413043a87fbc8f6869c07ebbe5d2ce8c8bac9c'
OLD='07a31ac90fc93d09701c558727c48e1b76e9be2999d4ba599c66aa136e77c4d7'
PHP='60fc91116affa5faf539cc835e12ce3939d598be68c94a56b21a36f860c5f29e'
LOCKS=['/run/lock/mad4b-css-site-wayfa.lock','/run/lock/mad4b-css-wpcron-worker.lock','/run/lock/mad4b-critical-css-wayfa.lock']

def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()

def tree_digest(root):
    if not root.is_dir() or root.is_symlink():raise ValueError('invalid_renderer_directory')
    files=[]
    for f in root.rglob('*'):
        if '__pycache__' in f.parts or f.suffix=='.pyc':continue
        if f.is_symlink():raise ValueError('renderer_symlink')
        if f.is_file():files.append(f)
    if len(files)!=119:raise ValueError('renderer_count_not_119')
    digest=hashlib.sha256()
    for p in sorted(files,key=lambda f:f.relative_to(root).as_posix()):
        key=p.relative_to(root).as_posix().encode();data=p.read_bytes()
        digest.update(len(key).to_bytes(4,'big')+key+len(data).to_bytes(8,'big')+data)
    return digest.hexdigest()

def atomic(path,raw,mode):
    fd,temp=tempfile.mkstemp(prefix='.css-profile-v14-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as f:
            os.fchmod(f.fileno(),mode);os.fchown(f.fileno(),0,0)
            f.write(raw);f.flush();os.fsync(f.fileno())
        os.replace(temp,path)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def recent_oom():
    p=subprocess.run(['journalctl','-k','--since','-10 minutes','--no-pager','-o','cat'],capture_output=True,text=True,timeout=12)
    if p.returncode:raise ValueError('kernel_journal_unavailable')
    return 'Out of memory: Killed process' in p.stdout or 'oom-kill:' in p.stdout

def healthy_site():
    for route in ('/','/es/'):
        req=urllib.request.Request('https://wayfatours.com'+route,headers={'User-Agent':'MAD4B-Wayfa-Renderer-v14-readback/1.0'})
        with urllib.request.urlopen(req,timeout=8) as r:
            if r.status!=200 or not r.url.startswith('https://wayfatours.com/'):
                return False
    return True

def validate_pins():
    if any(x.is_symlink() for x in (PROFILE,CONSUMER,CSS_MANIFEST,ACTIVE,STAGE)):
        raise ValueError('source_symlink_denied')
    if sha(CONSUMER)!=PHP:raise ValueError('consumer_not_v0_3_23')
    pins=re.findall(r'^   CONSUMER_SHA=([0-9a-f]+)$',(STAGE/'run-site.sh').read_text(),re.M)
    if len(pins)!=2 or any(len(p)!=64 for p in pins) or pins[1]!=PHP:
        raise ValueError('invalid_wayfa_consumer_pin')
    profile=json.loads(PROFILE.read_text())
    if (profile.get('contract')!='mad4b.css.site-owned-cron.profile.v1'
        or profile.get('site_id')!='wayfa'
        or profile.get('site_host')!='wayfatours.com'
        or profile.get('environment')!='production'):
        raise ValueError('site_profile_identity_untrusted')
    if (PROFILE.stat().st_uid!=0 or PROFILE.stat().st_mode&0o077):
        raise ValueError('site_profile_permissions_untrusted')
    if profile.get('renderer_sha256')!=OLD or tree_digest(ACTIVE)!=OLD:
        raise ValueError('live_renderer_v13_drift')
    if tree_digest(STAGE)!=NEW or BACKUP.exists():
        raise ValueError('staged_v14_drift_or_backup_collision')
    return profile

def preflight():
    load1,load5,_=os.getloadavg()
    holds=[]
    state={'contract':'mad4b.css.wayfa-v14-admission.v1','load_1m':round(load1,2),'load_5m':round(load5,2),'live_changed':False}
    if os.geteuid()!=0:holds.append('requires_root')
    if load1>14 or load5>24:holds.append('cpu_load_threshold_exceeded')
    try:
        state['recent_kernel_oom']=recent_oom()
        if state['recent_kernel_oom']:holds.append('kernel_oom_last_10_minutes')
    except (OSError,subprocess.TimeoutExpired,ValueError):holds.append('kernel_oom_source_unavailable')
    try:
        validate_pins()
        if not healthy_site():holds.append('site_https_readback_failed')
    except (ValueError,OSError,KeyError,json.JSONDecodeError,TimeoutError) as e:
        holds.append('source_or_http_validation_failed:'+str(e)[:95])
    state.update(admitted=not holds,holds=holds)
    return state

@contextlib.contextmanager
def release_locks():
    with contextlib.ExitStack() as stack:
        for path in LOCKS:
            f=stack.enter_context(open(path,'a+'))
            fcntl.flock(f.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
        yield

def activate():
    with release_locks():
        decision=preflight()
        if not decision['admitted']:
            print(json.dumps(decision,sort_keys=True));return 4
        original=PROFILE.read_bytes();manifest=sha(CSS_MANIFEST)
        renamed=False;profile_changed=False
        try:
            os.rename(ACTIVE,BACKUP)
            os.rename(STAGE,ACTIVE)
            renamed=True
            profile=json.loads(original);profile['renderer_sha256']=NEW
            atomic(PROFILE,(json.dumps(profile,indent=2,sort_keys=True)+'\n').encode(),0o600)
            profile_changed=True
            if (sha(CONSUMER)!=PHP or tree_digest(ACTIVE)!=NEW
                or tree_digest(BACKUP)!=OLD
                or json.loads(PROFILE.read_text()).get('renderer_sha256')!=NEW
                or sha(CSS_MANIFEST)!=manifest or not healthy_site()):
                raise ValueError('post_release_integrity_or_http_failed')
        except Exception:
            if renamed:
                if STAGE.exists():raise RuntimeError('rollback_staging_collision')
                os.rename(ACTIVE,STAGE)
                os.rename(BACKUP,ACTIVE)
            elif not ACTIVE.exists() and BACKUP.is_dir():os.rename(BACKUP,ACTIVE)
            if profile_changed or PROFILE.read_bytes()!=original:atomic(PROFILE,original,0o600)
            raise
        print(json.dumps({'contract':'mad4b.css.wayfa-v14-activation.v1','activated':True,
            'old_renderer_sha256':OLD,'new_renderer_sha256':NEW,'consumer_sha256':PHP,
            'rollback_present':True,'manifest_unchanged':True,'wp_queue_unchanged':True,
            'source_staging_unchanged':True},sort_keys=True))
        return 0

def rollback():
    with release_locks():
        if (not BACKUP.is_dir() or STAGE.exists() or tree_digest(ACTIVE)!=NEW
            or tree_digest(BACKUP)!=OLD or sha(CONSUMER)!=PHP
            or json.loads(PROFILE.read_text()).get('renderer_sha256')!=NEW):
            raise ValueError('v14_rollback_requirements_not_met')
        os.rename(ACTIVE,STAGE);os.rename(BACKUP,ACTIVE)
        p=json.loads(PROFILE.read_text());p['renderer_sha256']=OLD
        atomic(PROFILE,(json.dumps(p,indent=2,sort_keys=True)+'\n').encode(),0o600)
        if tree_digest(ACTIVE)!=OLD:raise RuntimeError('rollback_postreadback_failed')
        print(json.dumps({'contract':'mad4b.css.wayfa-v14-rollback.v1','rolled_back':True,
            'renderer_sha256':OLD,'wp_queue_unchanged':True,'certificates_unchanged':True},sort_keys=True))
        return 0

if __name__=='__main__':
    import argparse
    a=argparse.ArgumentParser();a.add_argument('action',choices=('check','apply','rollback'));args=a.parse_args()
    try:sys.exit(activate() if args.action=='apply' else rollback() if args.action=='rollback' else (print(json.dumps(preflight(),sort_keys=True)) or 0))
    except BlockingIOError:print('HOLD_WAYFA_V14_CSS_LOCK_BUSY',file=sys.stderr);sys.exit(4)
    except Exception as e:print('HOLD_WAYFA_V14_RELEASE_FAILED:'+type(e).__name__+':'+str(e)[:180],file=sys.stderr);sys.exit(5)
