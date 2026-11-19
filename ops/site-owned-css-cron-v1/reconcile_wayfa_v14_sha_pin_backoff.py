#!/usr/bin/env python3
"""One-time ticket 39 pre-Chromium backoff correction after exact v14 repair.

Bound to the original exit-5 consumer-pin mismatch evidence and verified v14
site renderer. No WordPress, certificate, job acknowledgement, or browser writes.
"""
import contextlib,fcntl,hashlib,json,os,pathlib,stat,sys,tempfile,time
SITE=pathlib.Path('/opt/wayfa-rebuild-20261102')
CSS=SITE/'operations/css-cron-v1'
HISTORY=pathlib.Path('/opt/mad4b-company-provisioning/reports/wpcron-css-v1/attempts.json')
RECEIPT=HISTORY.parent/'wayfa-first_fold-latest.json'
BACKUP=HISTORY.parent/'attempts.before-wayfa-v14-pin-backoff-20261119.json'
PHP=SITE/'site-copy/wp-content/mu-plugins/415-mad4b-critical-css-orchestrator.php'
KEY='wayfa:first_fold:39'
PHP_SHA='60fc91116affa5faf539cc835e12ce3939d598be68c94a56b21a36f860c5f29e'
RENDERER_SHA='9e12e18e257e6102f8a866332e413043a87fbc8f6869c07ebbe5d2ce8c8bac9c'
LOCKS=['/run/lock/mad4b-css-site-wayfa.lock','/run/lock/mad4b-css-wpcron-worker.lock']

def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()

def read(p):
    if p.is_symlink() or not p.is_file():raise ValueError('untrusted_source')
    s=p.stat()
    if s.st_uid!=0 or s.st_mode&0o022 or not stat.S_ISREG(s.st_mode) or s.st_size>300000:
        raise ValueError('untrusted_source_permissions_or_size')
    return json.loads(p.read_text())

def evidence(hist,receipt,profile,pins):
    if (profile.get('site_id')!='wayfa' or profile.get('site_host')!='wayfatours.com'
        or profile.get('environment')!='production'
        or profile.get('renderer_sha256')!=RENDERER_SHA
        or len(pins)!=2 or pins[1]!=PHP_SHA or any(len(pin)!=64 for pin in pins)):
        raise ValueError('v14_identity_not_verified')
    old=hist.get(KEY)
    if (not isinstance(old,dict) or old.get('failures')!=1
        or not isinstance(old.get('next_retry'),int)):
        raise ValueError('no_exact_pin_related_backoff')
    if (receipt.get('contract')!='mad4b.css.wpcron.host-receipt.v1'
        or receipt.get('site')!='wayfa'
        or receipt.get('job')!='first_fold'
        or receipt.get('ticket')!=39
        or receipt.get('exit')!=5
        or receipt.get('completion_reason')!='worker_exit_nonzero'
        or receipt.get('worker_complete') is not False
        or receipt.get('deferred') is not True
        or not isinstance(receipt.get('observed_output_tail'),str)
        or receipt['observed_output_tail'].replace('\\n','\n').strip() !=
            'CSS_NETWORK_PROFILE wayfa bridge\nHOLD wayfa live native consumer drift'):
        raise ValueError('failure_not_exact_pre_browser_pin_mismatch')
    import datetime
    stamp=int(datetime.datetime.fromisoformat(receipt['at'].replace('Z','+00:00')).timestamp())
    if old.get('last_attempt')!=stamp:
        raise ValueError('receipt_no_longer_matches_attempt')
    return old

def atomic_write(p,data):
    fd,tmp=tempfile.mkstemp(prefix='.css-wayfa-v14-attempt-',dir=p.parent)
    try:
        with os.fdopen(fd,'wb') as f:
            os.fchmod(f.fileno(),0o600);os.fchown(f.fileno(),0,0)
            f.write(data);f.flush();os.fsync(f.fileno())
        os.replace(tmp,p)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def process(apply):
    import re
    with contextlib.ExitStack() as stack:
        for p in LOCKS:
            f=stack.enter_context(open(p,'a+'))
            fcntl.flock(f.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
        if sha(PHP)!=PHP_SHA:raise ValueError('live_php_changed')
        from_path=CSS/'renderer/run-site.sh'
        pins=re.findall(r'^   CONSUMER_SHA=([0-9a-f]+)$',from_path.read_text(),re.M)
        profile=read(CSS/'site-profile.json')
        history=read(HISTORY);receipt=read(RECEIPT)
        entry=evidence(history,receipt,profile,pins)
        before=HISTORY.read_bytes()
        report={'contract':'mad4b.css.wayfa-v14-pin-retry-reconciliation.v1',
                'ticket':39,'reason':'original_v13_65_character_consumer_pin',
                'old_failures':entry['failures'],'old_next_retry':entry['next_retry'],
                'new_failures':0,'applied':False,'wp_queue_unchanged':True,
                'certificates_unchanged':True,'qa_required':True,'resource_guard_unchanged':True,
                'original_receipt_sha256':sha(RECEIPT)}
        if apply:
            if BACKUP.exists() or BACKUP.is_symlink():raise ValueError('one_time_reset_already_attempted')
            fd=os.open(BACKUP,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
            with os.fdopen(fd,'wb') as f:f.write(before);f.flush();os.fsync(f.fileno())
            new_history={**history,KEY:{**entry,'failures':0,'next_retry':int(time.time())}}
            atomic_write(HISTORY,(json.dumps(new_history,sort_keys=True,indent=2)+'\n').encode())
            after=read(HISTORY)
            if ({k:v for k,v in after.items() if k!=KEY} !=
                    {k:v for k,v in history.items() if k!=KEY}):
                atomic_write(HISTORY,before);raise ValueError('other_task_changed')
            report['applied']=True
            report['history_after_sha256']=sha(HISTORY)
        print(json.dumps(report,sort_keys=True))
        return 0
if __name__=='__main__':
    import argparse
    p=argparse.ArgumentParser();p.add_argument('--apply',action='store_true');args=p.parse_args()
    try:sys.exit(process(args.apply))
    except (OSError,ValueError,TypeError,KeyError,json.JSONDecodeError,BlockingIOError) as e:
        print('HOLD_V14_SHA_PIN_RECONCILIATION:'+str(e)[:150],file=sys.stderr);sys.exit(4)
