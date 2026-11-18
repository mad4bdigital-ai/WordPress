#!/usr/bin/env python3
"""WP-Cron-owned due queue -> bounded VPS-only original CSS worker.

Invoked by plain OS cron. Never changes WordPress settings or CSS assets itself;
it calls only the already enrolled first-party CSS workers. One task per site
per invocation. Cross-company locks, source QA and CSS integrity remain owned
by their original consumers.
"""
import json,os,re,subprocess,sys,time
from pathlib import Path

ROOT=Path('/opt/mad4b-company-provisioning')
SOURCE=ROOT/'releases/template-aware-css-wpcron-v3-20261118/ops/dynamic-critical-css-20261116'
REPORT=ROOT/'reports/wpcron-css-v1'
CONTAINERS={'source':'php-wkgtkzta35wkkgouwt6ruljc',
            'wayfa':'php-guxhp9y6vgh7oouuw8plxrlr'}
CONTRACT='mad4b.css.wpcron.dispatch.v1'
TASKS={
 'public':lambda s:['/usr/bin/python3',str(SOURCE/'run-public-route.py'),s],
 'first_fold':lambda s:['/usr/bin/bash',str(SOURCE/'run-site.sh'),s],
 'families':lambda s:['/usr/bin/python3',str(SOURCE/'refresh-source-families.py')],
 'visual':lambda s:['/usr/bin/python3',str(SOURCE/'post-policy-visual-watch.py')],
 'audit':lambda s:['/usr/bin/python3',str(SOURCE/'health-audit.py'),'all'],
 'retention':lambda s:['/usr/bin/flock','-n','-E','0',
                         '/run/lock/mad4b-native-lazy-browser-global.lock',
                         '/usr/bin/python3',str(SOURCE/'report-retention.py'),'--apply']
}
PHP_CLASS='MAD4B_CCSS_WPCron_Dispatch_V1'
TICK='mad4b_css_scheduler_tick_v1'
DIRTY='mad4b_css_scheduler_dirty_v1'
COOLDOWN=300

def run(cmd,timeout):
    return subprocess.run(cmd,text=True,stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE,timeout=timeout,check=False)
def cli(site,args,timeout=70):
    return run(['docker','exec',CONTAINERS[site],'wp','--allow-root',*args],timeout)
def safe_state():
    p=REPORT/'attempts.json'
    if p.is_symlink():raise ValueError('unsafe-worker-history-symlink')
    if not p.exists():return {}
    if p.stat().st_size>200000:raise ValueError('worker-history-too-large')
    val=json.loads(p.read_text())
    return val if isinstance(val,dict) else {}
def save_state(data):
    p=REPORT/'attempts.json'
    tmp=p.with_name('.attempts-%d.json'%os.getpid())
    tmp.write_text(json.dumps(data,sort_keys=True)+'\n')
    os.chmod(tmp,0o600)
    os.replace(tmp,p)
def snapshot(site):
    # One WordPress bootstrap per site for both the native due-event pump
    # and exact private ticket retrieval. No arbitrary WP cron hook is run.
    php=('if(!class_exists("'+PHP_CLASS+'",false)){'
         'echo "MAD4B_CSS_STATUS=UNAVAILABLE";return;}'
         'echo "MAD4B_CSS_STATUS=".wp_json_encode('
         +PHP_CLASS+'::pump(),JSON_UNESCAPED_SLASHES);')
    call=cli(site,['eval',php])
    if call.returncode:
        print('WPCSS_WORDPRESS_STATUS_DEFER',site,call.stderr[-130:],flush=True)
        return None
    lines=[x.split('MAD4B_CSS_STATUS=',1)[-1] for x in call.stdout.splitlines()
           if 'MAD4B_CSS_STATUS=' in x]
    if len(lines)!=1 or lines[0]=='UNAVAILABLE':return None
    try:data=json.loads(lines[0])
    except (TypeError,ValueError):return None
    if not isinstance(data,dict) or data.get('contract')!=CONTRACT or data.get('site')!=site:
        raise ValueError('WP_CSS_ORIGINAL_SITE_CONTRACT_MISMATCH')
    # PHP encodes an empty associative-array job map as [], not {}.
    # Never crash or spin when a company has no outstanding CSS tickets.
    pending=data.get('pending')
    if pending == []:
        data['pending']={}
    elif not isinstance(pending,dict):
        raise ValueError('UNTRUSTED_WPCRON_PENDING_TICKETS')
    return data
def ack(site,job,jid):
    php='echo "MAD4B_CSS_ACK=".('+PHP_CLASS+'::acknowledge('+json.dumps(job)+','+str(jid)+')?"1":"0");'
    result=cli(site,['eval',php],timeout=65)
    return result.returncode==0 and 'MAD4B_CSS_ACK=1' in result.stdout
def available_candidates(site,data,history,now):
    rows=[]
    ordering={'families':0,'public':1,'first_fold':2,'visual':3,'audit':4,'retention':5}
    for job,rec in data.get('pending',{}).items():
        if job not in TASKS or (site=='wayfa' and job not in ('public','first_fold')):
            continue
        jid=rec.get('id')
        if not isinstance(jid,int) or isinstance(jid,bool) or jid<=0:continue
        key=site+':'+job+':'+str(jid)
        prior=history.get(key,0)
        next_retry=(int(prior.get('next_retry',0)) if isinstance(prior,dict)
                    else int(prior)+COOLDOWN)
        if now<next_retry:continue
        priority=0 if rec.get('reason') in ('source_changed','home_changed') else 1
        rows.append((priority,int(rec.get('requested_at') or 0),ordering[job],job,jid,key))
    rows.sort()
    return rows
def invoke(site,job,jid,key,history):
    stamp=time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())
    prior=history.get(key,{})
    failures=int(prior.get('failures',0)) if isinstance(prior,dict) else 0
    attempt_time=int(time.time())
    # A bounded retry backoff is private to the host; WP pending state stays
    # intact until exact generation is acknowledged by its original owner.
    history[key]={'last_attempt':attempt_time,'failures':failures,
                  'next_retry':attempt_time+COOLDOWN}
    save_state(history)
    command=TASKS[job](site)
    print('WPCSS_EXEC',site,job,jid,stamp,flush=True)
    try:
        result=run(command,timeout=650)
        output=(result.stdout+'\n'+result.stderr)[-12000:]
        success,reason=verified_task_outcome(
            site,job,result.returncode,result.stdout,result.stderr)
        # Do not mistake an original zero-exit SKIP/HOLD for completion.
        deferred=not success
        receipt={'contract':'mad4b.css.wpcron.host-receipt.v1','site':site,'job':job,
                 'ticket':jid,'at':stamp,'exit':result.returncode,'deferred':deferred,
                 'completion_reason':reason,
                 'worker_complete':success,'observed_output_tail':output[-1500:]}
        out=REPORT/(site+'-'+job+'-latest.json')
        tmp=out.with_suffix('.pending')
        tmp.write_text(json.dumps(receipt,indent=2,ensure_ascii=False)+'\n')
        os.chmod(tmp,0o600);os.replace(tmp,out)
        print('WPCSS_RESULT',site,job,'exit',result.returncode,
              'complete',success,'reason',reason,
              'tail',output[-220:].replace('\n',' '),flush=True)
        if success:
            if ack(site,job,jid):
                print('WPCSS_WORDPRESS_ACK',site,job,jid,flush=True)
                history.pop(key,None);save_state(history)
            else:
                print('WPCSS_ACK_STALE_OR_BOOT_DEFER',site,job,jid,flush=True)
        else:
            failures+=1
            backoff=min(3600,300*(2**min(failures,4)))
            history[key]={'last_attempt':attempt_time,'failures':failures,
                          'next_retry':int(time.time())+backoff}
            save_state(history)
    except (OSError,subprocess.TimeoutExpired) as e:
        failures+=1
        history[key]={'last_attempt':attempt_time,'failures':failures,
                      'next_retry':int(time.time())+min(3600,300*(2**min(failures,4)))}
        save_state(history)
        print('WPCSS_HEAVY_WORKER_DEFER',site,job,type(e).__name__,flush=True)
def verified_task_outcome(site,job,returncode,stdout,stderr):
    """Only an original, *completed* task result may consume a WP ticket.

    Python and shell workers legitimately exit 0 when they DEFER/SKIP/HOLD.
    An OS success code is not a source certificate or executed job receipt.
    Browser QA is observational, not a native CSS policy activation gate.
    """
    if returncode != 0:
        return False,'worker_exit_nonzero'
    lines=[line.strip() for line in stdout.splitlines() if line.strip()]
    if job=='public':
        prefixes=(
            'CERTIFIED_PUBLIC_CCSS '+site+' ',
            'CERTIFIED_PUBLIC_CCSS_REUSED '+site+' ',
            'READY_PUBLIC_CCSS_SOURCE_FAMILIES '+site+' ',
            'READY_PUBLIC_CCSS_QUEUE_EMPTY '+site+' ',
            'NATIVE_CSS_NONBENEFIT_MEASURED '+site+' ',
        )
        success=any(line.startswith(prefixes) for line in lines)
        return success,'verified_public_result' if success else 'public_source_or_browser_deferred'
    if job=='first_fold':
        success=any(
            line.startswith(('CERTIFIED '+site+' ',
                             'READY '+site+' all current public home CSS native-certified',
                             'SKIP '+site+' unchanged Critical CSS sources; no Chromium'))
            for line in lines)
        return success,'verified_home_result' if success else 'home_source_or_browser_deferred'
    if job=='families':
        success=all(
            any(line.startswith('SOURCE_FAMILY_REGISTRY_REFRESH '+s+' ') for line in lines)
            for s in ('source','wayfa'))
        return success,'verified_source_family_reports' if success else 'source_family_partial_or_deferred'
    if job=='visual':
        success=(all(
            any(line.startswith('POST_POLICY_VISUAL_OBSERVED '+s+' ') for line in lines)
            for s in ('source','wayfa'))
            and 'POST_POLICY_VISUAL_MONITOR_NO_CERTIFICATE_OR_POLICY_MUTATION' in stdout)
        return success,'verified_two_site_visual_reports' if success else 'visual_observer_partial_or_deferred'
    if job=='audit':
        # A health warning is an observation, not a CSS deployment veto;
        # an actual deferred or error status is not a completed audit.
        for line in lines:
            if line.startswith('PUBLIC_CCSS_INTEGRITY '):
                try:
                    payload=json.loads(line.partition(' ')[2])
                except (TypeError,ValueError):
                    return False,'audit_receipt_invalid'
                states=payload.get('sites',{})
                success=(isinstance(states,dict) and
                         all(isinstance(states.get(s),dict) and
                             states[s].get('status') in ('healthy','healthy_with_safe_fallback','degraded')
                             for s in ('source','wayfa')))
                return success,'verified_two_site_integrity' if success else 'audit_incomplete'
        return False,'audit_receipt_missing'
    if job=='retention':
        for line in lines:
            if line.startswith('MAD4B_CCSS_EVIDENCE_RETENTION '):
                try:
                    payload=json.loads(line.partition(' ')[2])
                except (TypeError,ValueError):
                    return False,'retention_receipt_invalid'
                success=payload.get('mode')=='apply' and isinstance(payload.get('eligible_count'),int)
                return success,'verified_retention' if success else 'retention_not_applied'
        return False,'retention_receipt_missing'
    return False,'unknown_job_not_admitted'


def cpu_window():
    guard=run(['/usr/bin/python3',str(SOURCE/'public-route-cpu-guard.py')],timeout=8)
    return guard.returncode==0,guard.stdout.strip()[:110]

def main():
    REPORT.mkdir(parents=True,exist_ok=True,mode=0o700)
    if REPORT.is_symlink():raise ValueError('invalid_report_root')
    history=safe_state();now=int(time.time())
    headroom,reason=cpu_window()
    for site in ('source','wayfa'):
        state=snapshot(site)
        if state is None:
            print('WPCSS_STATUS_RETRY_NEXT_WAKE',site,flush=True);continue
        pending=available_candidates(site,state,history,now)
        print('WPCSS_QUEUE',site,'pending',len(state.get('pending',{})),
              'eligible',len(pending),flush=True)
        if pending:
            _,_,_,job,jid,key=pending[0]
            if '--inspect' in sys.argv:
                print('WPCSS_INSPECT_NEXT',site,job,jid,flush=True)
            elif not headroom:
                print('WPCSS_CPU_QUEUE_PRESERVED',site,reason,flush=True)
            else:
                invoke(site,job,jid,key,history)
    # Avoid unlimited local worker history.
    if len(history)>1000:
        history={k:v for k,v in history.items()
                 if (int(v.get('last_attempt',0)) if isinstance(v,dict) else int(v))
                 >int(time.time())-7*86400}
        save_state(history)
if __name__=='__main__':
    try:main()
    except (OSError,ValueError,TypeError,KeyError) as e:
        print('WPCSS_DRIVER_RETRY',type(e).__name__,str(e)[:180],file=sys.stderr)
        sys.exit(3)
