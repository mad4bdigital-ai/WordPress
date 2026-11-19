#!/usr/bin/env python3
"""Bounded, reversible Wayfatours CSS release, no certificate or WP queue writes.

--check: no writes. --apply: exact old->new transition only, CPU/OOM guarded.
--rollback: revert this exact release's PHP + renderer + local profile.
All changes are isolated to Wayfatours and serialized against site CSS Cron.
"""
import argparse,contextlib,fcntl,hashlib,json,os,pathlib,re,stat,subprocess,sys,tempfile,urllib.request

BASE=pathlib.Path('/opt/wayfa-rebuild-20261102')
OPS=BASE/'operations/css-cron-v1'
PLUGIN=BASE/'site-copy/wp-content/mu-plugins/415-mad4b-critical-css-orchestrator.php'
CANDIDATE=pathlib.Path('/opt/mad4b-company-provisioning/releases/css-wayfa-adaptive767-20261119/415-mad4b-critical-css-orchestrator.v0.3.23.php')
RELEASE=CANDIDATE.parent
PROFILE=OPS/'site-profile.json'
ACTIVE=OPS/'renderer'
STAGED=OPS/'renderer.stage-v13'
ROLLBACK=OPS/'renderer.rollback-v12-20261119'
OLD_PHP='cdb55bd9ab6a86df26483450e33cf0bbc91296940db51102dde082fdb839db5b'
NEW_PHP='60fc91116affa5faf539cc835e12ce3939d598be68c94a56b21a36f860c5f29e'
OLD_TREE='cd4ff78aea00d5195083e81a9877df44cbcd47907c550e11c3c52aa7327fe09e'
NEW_TREE='07a31ac90fc93d09701c558727c48e1b76e9be2999d4ba599c66aa136e77c4d7'
LOCKS=['/run/lock/mad4b-css-wpcron-worker.lock',
       '/run/lock/mad4b-css-site-wayfa.lock',
       '/run/lock/mad4b-critical-css-wayfa.lock']

def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()

def tree_digest(root):
    if not root.is_dir() or root.is_symlink():raise ValueError('renderer_not_directory')
    files=[]
    for p in root.rglob('*'):
        if '__pycache__' in p.parts or p.suffix=='.pyc':continue
        if p.is_symlink():raise ValueError('renderer_symlink_denied')
        if p.is_file():files.append(p)
    if len(files)!=119:raise ValueError('renderer_count_invalid')
    digest=hashlib.sha256()
    for p in sorted(files,key=lambda p:p.relative_to(root).as_posix()):
        name=p.relative_to(root).as_posix().encode();data=p.read_bytes()
        digest.update(len(name).to_bytes(4,'big')+name+len(data).to_bytes(8,'big')+data)
    return digest.hexdigest()

def atomic_file(path,data,mode,uid=0,gid=0):
    fd,name=tempfile.mkstemp(prefix='.mad4b-css-release-',suffix='.tmp',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as f:
            os.fchmod(f.fileno(),mode)
            os.fchown(f.fileno(),uid,gid)
            f.write(data);f.flush();os.fsync(f.fileno())
        os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)

def oom_recent():
    # A missing kernel journal is not evidence that no OOM occurred.
    r=subprocess.run(['journalctl','-k','--since','-10 minutes','--no-pager','-o','cat'],
                     capture_output=True,text=True,timeout=12)
    if r.returncode!=0:raise ValueError('kernel_oom_journal_unavailable')
    return any('Out of memory: Killed process' in line or 'oom-kill:' in line
               for line in r.stdout.splitlines())

def healthy_http():
    request=urllib.request.Request('https://wayfatours.com/',
                  headers={'User-Agent':'MAD4B-CSS-release-readback/1.0'})
    with urllib.request.urlopen(request,timeout=7) as response:
        return response.status==200 and response.url.startswith('https://wayfatours.com/')

def preflight():
    problems=[]
    load1,load5,_=os.getloadavg()
    state={'contract':'mad4b.css.wayfa-v13-admission.v1',
           'load_1m':round(load1,2),'load_5m':round(load5,2),'cores':os.cpu_count(),
           'threshold_1m':14,'threshold_5m':24}
    if load1>14 or load5>24:problems.append('host_load_above_budgets')
    try:
        state['recent_kernel_oom']=oom_recent()
        if state['recent_kernel_oom']:problems.append('kernel_oom_within_10_minutes')
    except (OSError,subprocess.TimeoutExpired,ValueError) as e:
        problems.append('kernel_journal_unavailable')
    try:
        if os.geteuid()!=0:problems.append('not_root')
        if PLUGIN.is_symlink() or CANDIDATE.is_symlink() or PROFILE.is_symlink():
            problems.append('unsafe_symlink')
        if sha(PLUGIN)!=OLD_PHP:problems.append('live_consumer_drift')
        if sha(CANDIDATE)!=NEW_PHP:problems.append('candidate_sha_mismatch')
        current=json.loads(PROFILE.read_text())
        if current.get('site_id')!='wayfa' or current.get('site_host')!='wayfatours.com' or current.get('environment')!='production':
            problems.append('site_identity_mismatch')
        if current.get('renderer_sha256')!=OLD_TREE:problems.append('profile_v12_drift')
        if tree_digest(ACTIVE)!=OLD_TREE:problems.append('active_renderer_v12_drift')
        if tree_digest(STAGED)!=NEW_TREE:problems.append('staged_renderer_v13_drift')
        if ROLLBACK.exists():problems.append('v12_rollback_path_already_exists')
        if PLUGIN.stat().st_uid!=0 or stat.S_IMODE(PLUGIN.stat().st_mode)!=0o644:
            problems.append('plugin_permissions_changed')
        if PROFILE.stat().st_uid!=0 or stat.S_IMODE(PROFILE.stat().st_mode)!=0o600:
            problems.append('site_profile_permissions_changed')
    except (ValueError,OSError,KeyError,json.JSONDecodeError) as e:
        problems.append('release_source_unavailable:'+type(e).__name__)
    try:
        if not healthy_http():problems.append('wayfatours_http_not_200')
    except Exception as e:
        problems.append('wayfatours_http_unavailable:'+type(e).__name__)
    state.update(admitted=not problems,holds=problems,live_changed=False)
    return state

@contextlib.contextmanager
def css_locks():
    with contextlib.ExitStack() as stack:
        for path in LOCKS:
            stream=stack.enter_context(open(path,'a+'))
            fcntl.flock(stream.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
        yield

def apply_release():
    with css_locks():
        decision=preflight()
        if not decision['admitted']:
            print(json.dumps(decision,sort_keys=True));return 4
        # PHP lint in the installed runtime; no WordPress boot or WP-CLI load.
        lint=subprocess.run(['docker','exec','-i','php-guxhp9y6vgh7oouuw8plxrlr','php','-l'],
                            input=CANDIDATE.read_bytes(),capture_output=True,timeout=12)
        if lint.returncode!=0:raise ValueError('native_php_lint_failed')
        original_php=PLUGIN.read_bytes();original_profile=PROFILE.read_bytes()
        original_manifest=BASE/'site-copy/wp-content/mad4b-critical-css-v1/manifest.json'
        manifest_hash=sha(original_manifest)
        backup_file=RELEASE/'415-mad4b-critical-css-orchestrator.v0.3.22.backup'
        if backup_file.exists() and sha(backup_file)!=OLD_PHP:raise ValueError('rollback_blob_conflict')
        if not backup_file.exists():atomic_file(backup_file,original_php,0o600)
        switched_php=False;switched_renderer=False;profile_switched=False
        try:
            # Consumer v0.3.23 remains backward compatible with v1 manifest.
            atomic_file(PLUGIN,CANDIDATE.read_bytes(),0o644)
            switched_php=True
            os.rename(ACTIVE,ROLLBACK)
            os.rename(STAGED,ACTIVE)
            switched_renderer=True
            next_profile=json.loads(original_profile)
            next_profile['renderer_sha256']=NEW_TREE
            atomic_file(PROFILE,(json.dumps(next_profile,sort_keys=True,indent=2)+'\n').encode(),0o600)
            profile_switched=True
            if (sha(PLUGIN)!=NEW_PHP or tree_digest(ACTIVE)!=NEW_TREE
                or json.loads(PROFILE.read_text())['renderer_sha256']!=NEW_TREE
                or sha(original_manifest)!=manifest_hash
                or not healthy_http()):raise ValueError('release_http_or_integrity_readback_failed')
        except Exception:
            # Never delete queues, certificates, or critical CSS assets.
            if switched_renderer:
                if STAGED.exists():raise RuntimeError('staging_collision_prevented_rollback')
                os.rename(ACTIVE,STAGED)
                os.rename(ROLLBACK,ACTIVE)
            elif ROLLBACK.exists() and not ACTIVE.exists():os.rename(ROLLBACK,ACTIVE)
            if switched_php:atomic_file(PLUGIN,original_php,0o644)
            if profile_switched or PROFILE.read_bytes()!=original_profile:
                atomic_file(PROFILE,original_profile,0o600)
            raise
        receipt={'contract':'mad4b.css.wayfa-v13-release.v1','released':True,
                 'php_sha256':NEW_PHP,'renderer_sha256':NEW_TREE,
                 'rollback_php_sha256':OLD_PHP,'rollback_renderer_sha256':OLD_TREE,
                 'critical_manifest_unchanged':True,'wp_queue_unchanged':True,
                 'other_sites_unchanged':True,'certificate_issued':False}
        print(json.dumps(receipt,sort_keys=True))
        return 0

def rollback_release():
    # Emergency rollback is allowed during high load but never while CSS
    # Cron owns the release locks. Only this exact v13 state can be reverted.
    with css_locks():
        backup_file=RELEASE/'415-mad4b-critical-css-orchestrator.v0.3.22.backup'
        if (not backup_file.is_file() or sha(backup_file)!=OLD_PHP
            or sha(PLUGIN)!=NEW_PHP or not ROLLBACK.is_dir()
            or tree_digest(ROLLBACK)!=OLD_TREE or tree_digest(ACTIVE)!=NEW_TREE):
            raise ValueError('rollback_state_does_not_match_v13')
        original_profile=json.loads(PROFILE.read_text())
        if original_profile.get('site_id')!='wayfa' or original_profile.get('renderer_sha256')!=NEW_TREE:
            raise ValueError('rollback_profile_mismatch')
        if STAGED.exists():raise ValueError('rollback_stage_collision')
        os.rename(ACTIVE,STAGED)
        os.rename(ROLLBACK,ACTIVE)
        next_profile={**original_profile,'renderer_sha256':OLD_TREE}
        atomic_file(PROFILE,(json.dumps(next_profile,sort_keys=True,indent=2)+'\n').encode(),0o600)
        atomic_file(PLUGIN,backup_file.read_bytes(),0o644)
        if (sha(PLUGIN)!=OLD_PHP or tree_digest(ACTIVE)!=OLD_TREE
            or json.loads(PROFILE.read_text())['renderer_sha256']!=OLD_TREE):
            raise ValueError('emergency_rollback_failed_readback')
        print(json.dumps({'contract':'mad4b.css.wayfa-v13-rollback.v1',
            'restored_php_sha256':OLD_PHP,'restored_renderer_sha256':OLD_TREE,
            'queue_untouched':True,'certificate_untouched':True},sort_keys=True))
        return 0

def main():
    parser=argparse.ArgumentParser();parser.add_argument('action',choices=['check','apply','rollback'])
    args=parser.parse_args()
    if args.action=='check':
        print(json.dumps(preflight(),sort_keys=True));return 0
    try:return rollback_release() if args.action=='rollback' else apply_release()
    except BlockingIOError:
        print('HOLD_CSS_CRON_LOCK_BUSY',file=sys.stderr);return 4
    except Exception as e:
        print('ROLLBACK_OR_RELEASE_ERROR:'+type(e).__name__+':'+str(e)[:160],file=sys.stderr);return 5
if __name__=='__main__':sys.exit(main())
