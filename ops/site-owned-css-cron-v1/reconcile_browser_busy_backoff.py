#!/usr/bin/env python3
"""One-time host attempt-history correction for provably pre-QA browser deferral.

Never modifies WordPress, certificates, server resources, or live CSS assets.
Always acquires original global + site locks, verifies receipt and worker SHA.
Dry-run default; --apply resets just one wrongly classified local retry.
"""
import argparse,datetime,fcntl,hashlib,json,os,pathlib,stat,sys,tempfile,time
ROOT=pathlib.Path('/opt/mad4b-company-provisioning/reports/wpcron-css-v1')
STATE=ROOT/'attempts.json'
RECEIPT=ROOT/'wayfa-first_fold-latest.json'
WORKER=pathlib.Path('/opt/wayfa-rebuild-20261102/operations/css-cron-v1/worker.py')
KEY='wayfa:first_fold:39'
NEW_WORKER_SHA='badfa081bf1f9e3235369c86bd091bf6e5a713e7a28dc357a5897549218396ba'
LOCKS=['/run/lock/mad4b-css-site-wayfa.lock','/run/lock/mad4b-css-wpcron-worker.lock']
RECEIPT_PENDING='CRITICAL_CSS_EVENT_LEDGER_COMMITTED wayfa pending '
RECEIPT_BUSY='SKIP wayfa shared browser busy; retry during next timer'

def read_verified(path):
    if path.is_symlink() or not path.is_file():raise ValueError('file_unavailable_or_symlink')
    f=path.stat()
    if (not stat.S_ISREG(f.st_mode) or f.st_uid!=0 or f.st_mode&0o022
        or not 60<=f.st_size<=300000):raise ValueError('file_permissions_or_size_untrusted')
    return json.loads(path.read_text())

def eligible(history,receipt):
    if not isinstance(history,dict) or not isinstance(receipt,dict):raise ValueError('invalid_records')
    entry=history.get(KEY)
    if not isinstance(entry,dict):raise ValueError('specific_ticket_attempt_not_found')
    if (receipt.get('contract')!='mad4b.css.wpcron.host-receipt.v1'
        or receipt.get('site')!='wayfa' or receipt.get('job')!='first_fold'
        or receipt.get('ticket')!=39 or receipt.get('exit')!=0
        or receipt.get('worker_complete') is not False
        or receipt.get('deferred') is not True
        or receipt.get('completion_reason')!='latest_home_event_gate_not_ready'):
        raise ValueError('receipt_not_exact_pre_qa_defer')
    observed=receipt.get('observed_output_tail','')
    if (not isinstance(observed,str) or RECEIPT_PENDING not in observed
        or RECEIPT_BUSY not in observed or 'QA_DECISION ' in observed
        or 'QA_COMPLETE ' in observed):raise ValueError('qa_or_event_receipt_untrusted')
    at=datetime.datetime.fromisoformat(receipt['at'].replace('Z','+00:00'))
    if entry.get('last_attempt')!=int(at.timestamp()):raise ValueError('attempt_receipt_timestamp_changed')
    if (not isinstance(entry.get('failures'),int) or entry['failures']<1
        or not isinstance(entry.get('next_retry'),int)):
        raise ValueError('no_false_backoff_left_to_reconcile')
    return entry

def atomic_replace(path,raw):
    fd,tmp=tempfile.mkstemp(prefix='.css-attempt-reconcile-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as f:
            os.fchmod(f.fileno(),0o600);f.write(raw);f.flush();os.fsync(f.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def run(apply):
    files=[]
    try:
        for lock in LOCKS:
            stream=open(lock,'a+');files.append(stream)
            fcntl.flock(stream.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
        if hashlib.sha256(WORKER.read_bytes()).hexdigest()!=NEW_WORKER_SHA:
            raise ValueError('corrected_worker_not_installed')
        history=read_verified(STATE);receipt=read_verified(RECEIPT)
        entry=eligible(history,receipt)
        state_sha=hashlib.sha256(STATE.read_bytes()).hexdigest()
        response={'contract':'mad4b.css.host-attempt-reclassification.v1',
                  'site':'wayfa','ticket':39,'key':KEY,
                  'old_failures':entry['failures'],'new_failures':0,
                  'old_retry_after':entry['next_retry'],
                  'evidence_receipt_sha256':hashlib.sha256(RECEIPT.read_bytes()).hexdigest(),
                  'history_before_sha256':state_sha,'wp_queue_changed':False,
                  'certificate_changed':False,'resource_budget_bypassed':False,
                  'applied':False}
        if apply:
            original=STATE.read_bytes()
            backup=ROOT/'attempts.before-browser-busy-reconcile-20261119.json'
            if backup.exists():raise ValueError('reconcile_already_attempted')
            fd=os.open(backup,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
            with os.fdopen(fd,'wb') as f:f.write(original);f.flush();os.fsync(f.fileno())
            next_state={**history,KEY:{**entry,'failures':0,'next_retry':int(time.time())}}
            atomic_replace(STATE,(json.dumps(next_state,indent=2,sort_keys=True)+'\n').encode())
            verified=read_verified(STATE)
            if ({k:v for k,v in verified.items() if k!=KEY} !=
                {k:v for k,v in history.items() if k!=KEY}):
                atomic_replace(STATE,original);raise ValueError('other_job_mutation_detected')
            response['applied']=True
            response['history_after_sha256']=hashlib.sha256(STATE.read_bytes()).hexdigest()
        print(json.dumps(response,sort_keys=True))
        return 0
    finally:
        for f in reversed(files):f.close()

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--apply',action='store_true');a=p.parse_args()
    try:sys.exit(run(a.apply))
    except (OSError,ValueError,TypeError,KeyError,json.JSONDecodeError,BlockingIOError) as e:
        print('HOLD_BACKOFF_RECONCILE:'+str(e)[:180],file=sys.stderr);sys.exit(4)
