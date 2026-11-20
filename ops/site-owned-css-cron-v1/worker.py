#!/usr/bin/env python3
"""Site-owned WordPress CSS Cron bridge to first-party browser runners.

Invoked by plain OS cron. Never changes WordPress settings or CSS assets itself;
it calls only the already enrolled first-party CSS workers. One task per site
per invocation. Cross-company locks, source QA and CSS integrity remain owned
by their original consumers.
"""
import hashlib,json,os,re,stat,subprocess,sys,time
from pathlib import Path

ROOT=Path('/opt/mad4b-company-provisioning')
REPORT=ROOT/'reports/wpcron-css-v1'

def renderer_digest(path):
    """Bind this site to one immutable, root-owned exact renderer tree.

    Never load symlinks, writable files or source code from another site's
    WordPress tree. Ignore Python's temporary bytecode caches.
    """
    root=Path(path)
    if root.is_symlink() or not root.is_dir():
        raise ValueError('renderer_directory_not_trusted')
    metadata=root.stat()
    if metadata.st_uid!=0 or metadata.st_mode&0o022:
        raise ValueError('renderer_root_permissions')
    paths=[]
    for entry in root.rglob('*'):
        if '__pycache__' in entry.parts or entry.suffix=='.pyc':
            continue
        if entry.is_symlink():
            raise ValueError('renderer_file_symlink')
        details=entry.stat()
        if details.st_uid!=0 or details.st_mode&0o022:
            raise ValueError('renderer_file_permissions')
        if entry.is_file():
            if not stat.S_ISREG(details.st_mode):
                raise ValueError('renderer_nonregular_file')
            paths.append(entry)
        elif not entry.is_dir():
            raise ValueError('renderer_unsupported_entry')
    if not paths or len(paths)>250:
        raise ValueError('renderer_file_count_untrusted')
    digest=hashlib.sha256()
    for entry in sorted(paths,key=lambda e:e.relative_to(root).as_posix()):
        name=entry.relative_to(root).as_posix().encode()
        data=entry.read_bytes()
        digest.update(len(name).to_bytes(4,'big')+name)
        digest.update(len(data).to_bytes(8,'big')+data)
    return digest.hexdigest()
CONTRACT='mad4b.css.wpcron.dispatch.v1'
PROFILE_CONTRACT='mad4b.css.site-owned-cron.profile.v1'

def read_site_profile(path=None):
    path=Path(path) if path is not None else Path(__file__).with_name('site-profile.json')
    if path.is_symlink() or not path.is_file():
        raise ValueError('missing_or_symlink_site_cron_profile')
    info=path.stat()
    if info.st_uid!=0 or info.st_mode&0o022 or not 50<=info.st_size<=4096:
        raise ValueError('site_cron_profile_permissions_or_size')
    data=json.loads(path.read_text())
    if not isinstance(data,dict) or data.get('contract')!=PROFILE_CONTRACT:
        raise ValueError('site_cron_contract')
    site=data.get('site_id');container=data.get('php_container');host=data.get('site_host')
    environment=data.get('environment');tasks=data.get('tasks')
    if site not in ('source','wayfa') or not isinstance(container,str) or (
        not re.fullmatch(r'php-[a-z0-9]{16,40}',container)
    ) or not isinstance(host,str) or not re.fullmatch(r'[a-z0-9.-]{3,253}',host):
        raise ValueError('site_cron_identity')
    if environment not in ('staging','production') or (
        not isinstance(tasks,list) or not tasks or len(tasks)>6
    ) or len(set(tasks))!=len(tasks) or any(not isinstance(t,str) for t in tasks):
        raise ValueError('site_cron_tasks_invalid')
    # Conservative first-party runtime registration. A different company
    # needs an explicit host executor enrollment, not a WordPress option.
    if site=='source' and environment!='staging':
        raise ValueError('source_environment_mismatch')
    if site=='wayfa' and environment!='production':
        raise ValueError('wayfa_environment_mismatch')
    if site=='wayfa' and 'retention' in tasks:
        raise ValueError('cross_site_retention_owner_denied')
    return data

PROFILE=read_site_profile()
SITE=PROFILE['site_id']
SOURCE=Path(__file__).resolve().parent/'renderer'
expected=PROFILE.get('renderer_sha256')
if not isinstance(expected,str) or not re.fullmatch('[0-9a-f]{64}',expected):
    raise ValueError('missing_exact_site_renderer_sha256')
if renderer_digest(SOURCE)!=expected:
    raise ValueError('site_renderer_source_drift_or_cross_tenant_copy')
CONTAINERS={SITE:PROFILE['php_container']}

TASKS={
 'public':lambda s:['/usr/bin/python3',str(SOURCE/'run-public-route.py'),s],
 'first_fold':lambda s:(
    ['/usr/bin/env','MAD4B_SOURCE_CSS_NETWORK_PROFILE=host',
     '/usr/bin/bash',str(SOURCE/'run-site.sh'),s] if s=='source'
    else ['/usr/bin/bash',str(SOURCE/'run-site.sh'),s]),
 'families':lambda s:['/usr/bin/python3',str(SOURCE/'refresh-source-families.py'),s],
 'visual':lambda s:['/usr/bin/python3',str(SOURCE/'post-policy-visual-watch.py'),s],
 'audit':lambda s:['/usr/bin/python3',str(SOURCE/'health-audit.py'),s],
 'retention':lambda s:['/usr/bin/flock','-n','-E','0',
                         '/run/lock/mad4b-native-lazy-browser-global.lock',
                         '/usr/bin/python3',str(SOURCE/'report-retention.py'),'--apply']
}
if any(task not in TASKS for task in PROFILE['tasks']):
    raise ValueError('site_cron_unsupported_original_task')
PHP_CLASS='MAD4B_CCSS_WPCron_Dispatch_V1'
TICK='mad4b_css_scheduler_tick_v1'
DIRTY='mad4b_css_scheduler_dirty_v1'
COOLDOWN=300
JOB_TIMEOUT={'first_fold':1280,'public':1250,'families':300,'visual':400,
             'audit':300,'retention':300}
HOST_WAKE_BUDGET=1380

def run(cmd,timeout,env=None):
    return subprocess.run(cmd,text=True,stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE,timeout=timeout,check=False,env=env)
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
    if (not isinstance(data,dict) or data.get('contract')!=CONTRACT
        or data.get('site')!=site or data.get('host')!=PROFILE['site_host']):
        raise ValueError('WP_CSS_SITE_OWNERSHIP_CONTRACT_MISMATCH')
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
        if job not in TASKS or job not in PROFILE['tasks']:
            continue
        jid=rec.get('id')
        if not isinstance(jid,int) or isinstance(jid,bool) or jid<=0:continue
        key=site+':'+job+':'+str(jid)
        prior=history.get(key,0)
        next_retry=(int(prior.get('next_retry',0)) if isinstance(prior,dict)
                    else int(prior)+COOLDOWN)
        # A newly enrolled, immutable first-party CSS runtime may repair a
        # proven former native-browser blocker (e.g. EACCES). Its *first*
        # source-home attempt must not inherit the previous release's
        # hour-long failed-attempt backoff. Once attempted, store this exact
        # release identity and respect ordinary resource/admission guards.
        if (site=='source' and job=='first_fold' and isinstance(prior,dict)
            and prior.get('worker_release')!=str(SOURCE)):
            next_retry=0
        if now<next_retry:continue
        priority=0 if (rec.get('reason') in ('source_changed','home_changed')
                       or (job=='first_fold' and site=='source')) else 1
        rows.append((priority,int(rec.get('requested_at') or 0),ordering[job],job,jid,key))
    rows.sort()
    return rows
def invoke(site,job,jid,key,history,timeout=None):
    stamp=time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())
    prior=history.get(key,{})
    release_switched=(site=='source' and job=='first_fold'
                      and isinstance(prior,dict)
                      and prior.get('worker_release')!=str(SOURCE))
    failures=(0 if release_switched else
              int(prior.get('failures',0)) if isinstance(prior,dict) else 0)
    worker_marker=({'worker_release':str(SOURCE)}
                   if site=='source' and job=='first_fold' else {})
    attempt_time=int(time.time())
    # A bounded retry backoff is private to the host; WP pending state stays
    # intact until exact generation is acknowledged by its original owner.
    history[key]={'last_attempt':attempt_time,'failures':failures,
                  'next_retry':attempt_time+COOLDOWN,**worker_marker}
    save_state(history)
    command=TASKS[job](site)
    print('WPCSS_EXEC',site,job,jid,stamp,flush=True)
    # First-party site-owned publication policy: publish the current
    # source-bound CSS candidate, then test in Chrome. No CI or browser
    # performance report is a certificate *issuance* prerequisite.
    release_env=None
    if site=='wayfa' and job=='public':
        release_env=dict(os.environ)
        release_env.update({
            'MAD4B_MULTI_CSS_V2_RELEASE_FIRST':'1',
            'MAD4B_MULTI_CSS_V2_PUBLISH':'1',
            'MAD4B_MULTI_CSS_V2_RELEASE_APPROVED':'1'})
    try:
        result=run(command,timeout=timeout or JOB_TIMEOUT[job],env=release_env)
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
            failures,next_retry=retry_policy(failures,reason,int(time.time()))
            history[key]={'last_attempt':attempt_time,'failures':failures,
                          'next_retry':next_retry,**worker_marker}
            save_state(history)
            if reason=='host_resource_deferred':
                print('WPCSS_NONFAILURE_RESOURCE_RETRY',site,job,jid,flush=True)
    except (OSError,subprocess.TimeoutExpired) as e:
        failures+=1
        history[key]={'last_attempt':attempt_time,'failures':failures,
                      'next_retry':int(time.time())+min(3600,300*(2**min(failures,4))),
                      **worker_marker}
        save_state(history)
        print('WPCSS_HEAVY_WORKER_DEFER',site,job,type(e).__name__,flush=True)
def retry_policy(previous_failures:int,reason:str,now:int):
    # Only a real QA failure earns exponential retry backoff; a proven
    # before-QA capacity deferral uses one normal tick with no failure count.
    if reason=='host_resource_deferred':
        return 0,now+COOLDOWN
    failures=max(0,int(previous_failures))+1
    return failures,now+min(3600,300*(2**min(failures,4)))


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
        # Explicit resource/lock deferrals occur BEFORE browser QA. Never
        # convert them into failed source certificates or long backoff.
        resource_prefixes=(
            'DEFER_PUBLIC_CCSS_RESOURCE_WINDOW '+site+' ',
            'DEFER_PUBLIC_CCSS_RESOURCE_SENSOR_UNAVAILABLE '+site,
            'SKIP_PUBLIC_CCSS_BROWSER_BUSY '+site,
            'SKIP_PUBLIC_CCSS_ALREADY_RUNNING',
            'DEFER_PUBLIC_CCSS_HOME_RECOVERY '+site+' ',
            'HOLD_PUBLIC_CCSS_HIGH_LOAD ',
        )
        if any(line.startswith(resource_prefixes) for line in lines):
            return False,'host_resource_deferred'
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
        # The original first-fold runner can print a provisional READY
        # following its browser readback and then correctly write PENDING
        # because the real anonymous CSS/Elementor source observer caught
        # source drift. The final event ledger outcome always wins.
        markers=[line for line in lines
                 if line.startswith('CRITICAL_CSS_EVENT_LEDGER_COMMITTED '+site+' ')]
        if markers and not markers[-1].startswith(
                'CRITICAL_CSS_EVENT_LEDGER_COMMITTED '+site+' ready '):
            # The original host may have fully deferred *before browser QA*
            # because another enrolled company holds the shared browser lock,
            # or the CPU budget closed after its initial admission. The event
            # ledger correctly remains PENDING, but this is not a failed CSS
            # certificate: do not apply hour-long exponential QA backoff.
            # Require both the exact original PENDING receipt and an explicit
            # pre-QA resource marker, with no measured QA result in the run.
            resource_prefixes=(
                'SKIP '+site+' shared browser busy; retry during next timer',
                'HOLD '+site+' CPU pressure; native CSS retained, next timer will retry',
            )
            pending=markers[-1].startswith(
                'CRITICAL_CSS_EVENT_LEDGER_COMMITTED '+site+' pending ')
            pre_qa_deferred=any(line.startswith(resource_prefixes) for line in lines)
            qa_executed=any(line.startswith((
                'QA_DECISION ', 'QA_COMPLETE ', 'CERTIFIED '+site+' ',
                'HOLD '+site+' no fully certified language',
            )) for line in lines)
            if pending and pre_qa_deferred and not qa_executed:
                return False,'host_resource_deferred'
            return False,'latest_home_event_gate_not_ready'
        if any(line.startswith((
                'HOLD_SOURCE_HOME_DELIVERY_ADMISSION',
                'HOLD source actual mobile CSS source/Elementor graph not yet fully certified'))
               for line in lines):
            return False,'actual_source_home_css_drift'
        success=any(
            line.startswith(('CERTIFIED '+site+' ',
                             'READY '+site+' all current public home CSS native-certified',
                             'SKIP '+site+' unchanged Critical CSS sources; no Chromium'))
            for line in lines)
        return success,'verified_home_result' if success else 'home_source_or_browser_deferred'
    if job=='families':
        if any(line.startswith(('DEFER_SOURCE_FAMILY_REFRESH ',
                                'SKIP_SOURCE_FAMILY_REFRESH_ALREADY_RUNNING'))
               for line in lines):
            return False,'host_resource_deferred'
        other='wayfa' if site=='source' else 'source'
        if any(line.startswith('SOURCE_FAMILY_REGISTRY_REFRESH '+other+' ') for line in lines):
            return False,'cross_site_family_receipt_denied'
        success=any(line.startswith('SOURCE_FAMILY_REGISTRY_REFRESH '+site+' ') for line in lines)
        return success,'verified_site_family_report' if success else 'source_family_partial_or_deferred'
    if job=='visual':
        other='wayfa' if site=='source' else 'source'
        if any(line.startswith('POST_POLICY_VISUAL_OBSERVED '+other+' ') for line in lines):
            return False,'cross_site_visual_receipt_denied'
        success=(any(line.startswith('POST_POLICY_VISUAL_OBSERVED '+site+' ') for line in lines)
                 and 'POST_POLICY_VISUAL_MONITOR_NO_CERTIFICATE_OR_POLICY_MUTATION' in stdout)
        return success,'verified_site_visual_report' if success else 'visual_observer_partial_or_deferred'
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
                success=(isinstance(states,dict) and set(states)=={site}
                         and isinstance(states[site],dict)
                         and states[site].get('status') in ('healthy','healthy_with_safe_fallback','degraded'))
                return success,'verified_site_integrity' if success else 'audit_incomplete'
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

def source_recovery_budget(record,drift,now=None,load=None):
    """Separate conservative source-home recovery lane, never general QA.

    The normal original CPU gate still applies to all route certification.
    Only a confirmed first-party *live* HTML/CSS mismatch may use the
    unused 8-vCPU recovery headroom with the same shared browser lock.
    """
    now=int(time.time() if now is None else now)
    if not drift or not isinstance(record,dict):
        return False,'no_authorized_live_css_recovery'
    if record.get('contract')!='mad4b.host-cpu-budget.v1' or record.get('cores')!=8:
        return False,'untrusted_host_cpu_budget'
    sampled=record.get('sampled_epoch')
    if not isinstance(sampled,int) or isinstance(sampled,bool) or not -5<=now-sampled<=105:
        return False,'stale_host_resource_evidence'
    bounds={'cpu_idle_pct':(35,100),'host_cpu_pct':(0,60),
            'load_1m':(0,5.9),'cpu_psi_some_avg10':(0,10),
            'cpu_steal_pct':(0,4),'wpcli_processes':(0,2)}
    for field,(lower,upper) in bounds.items():
        value=record.get(field)
        if isinstance(value,bool) or not isinstance(value,(int,float)) or not lower<=value<=upper:
            return False,'home_recovery_resource_budget_exhausted:'+field
    if not isinstance(record.get('wpcli_processes'),int):
        return False,'untrusted_wpcli_slot_count'
    observed=os.getloadavg()[0] if load is None else load
    if isinstance(observed,bool) or not isinstance(observed,(int,float)) or not 0<=observed<=5.9:
        return False,'realtime_load_exceeds_home_recovery'
    return True,'confirmed_source_home_css_recovery_lane'


def source_home_recovery_window():
    proof=ROOT/'reports/dynamic-critical-css-20261116/source/home-current-css-source.json'
    if proof.is_symlink() or not proof.is_file() or proof.stat().st_size>16384:
        return False,'source_html_css_proof_unavailable'
    # This report is written by an independently validated first-party HTTPS
    # observer. Re-check contract, provenance, six WPML scopes and recency.
    import importlib.util
    admission=SOURCE/'source-home-delivery-admission.py'
    spec=importlib.util.spec_from_file_location('source_home_css_recovery_admission',admission)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    stable,reason=module.evaluate(proof)
    if stable or reason!='unmatched_source_css_or_elementor_graph':
        return False,'no_confirmed_source_css_delivery_drift'
    sensor=Path('/run/mad4b-cpu-budget/status.json')
    if sensor.parent.is_symlink():
        return False,'cpu_sensor_parent_untrusted'
    try:
        fd=os.open(sensor,os.O_RDONLY|os.O_CLOEXEC|os.O_NOFOLLOW)
        try:
            statbuf=os.fstat(fd)
            if (not stat.S_ISREG(statbuf.st_mode) or statbuf.st_uid!=0
                or statbuf.st_mode&0o022 or not 50<=statbuf.st_size<=8192):
                return False,'cpu_sensor_not_root_attested'
            raw=os.read(fd,8193)
            if len(raw)!=statbuf.st_size:
                return False,'cpu_sensor_changed_during_read'
            return source_recovery_budget(json.loads(raw),True)
        finally:
            os.close(fd)
    except (OSError,ValueError,TypeError,json.JSONDecodeError):
        return False,'cpu_sensor_unavailable'


def observe_source_home_without_browser():
    # Anonymous first-party HTML+CSS fingerprint sampling has a private
    # cooldown inside its observer. It does not require a WordPress CLI
    # slot, browser lock or a successful CSS certificate job, and never
    # publishes a certificate or mutates WordPress/cache.
    command=['/usr/bin/nice','-n','18','/usr/bin/python3',
             str(SOURCE/'source-home-source-observer.py'),'source']
    try:
        result=run(command,timeout=145)
        out=(result.stdout+' '+result.stderr).strip()[-240:]
        if result.returncode:
            print('WPCSS_SOURCE_HOME_OBSERVATION_DEFERRED',out,flush=True)
        else:
            print('WPCSS_SOURCE_HOME_OBSERVED',out,flush=True)
    except (OSError,subprocess.TimeoutExpired) as error:
        print('WPCSS_SOURCE_HOME_OBSERVATION_RETRY',type(error).__name__,flush=True)

def observe_source_css_profiles():
    # Separate live HTTP and independently Chromium-certified CSS identities.
    # Read-only observation: never certify, edit CSS, flush cache or ack WP.
    proof=ROOT/'operations/wpcron-css-v1/source-css-profile-variance.py'
    try:
        result=run(['/usr/bin/python3',str(proof)],timeout=24)
        print('WPCSS_SOURCE_PROFILE_VARIANCE',result.returncode,
              (result.stdout+' '+result.stderr).strip()[-230:],flush=True)
    except (OSError,subprocess.TimeoutExpired) as error:
        print('WPCSS_SOURCE_PROFILE_VARIANCE_RETRY',type(error).__name__,flush=True)

def cheap_certified_first_fold(site,job,jid,key,history,reason,now):
    """Make original verified unchanged-certificates cheap under CPU pressure.

    Never bypass source identity or cert QA. PROBE_ONLY is hard-stopped in
    site-owned renderer immediately before Chromium/certification.
    """
    if site!=SITE or job!='first_fold' or reason=='central_wpcli_slots_reserved':
        return False
    previous=history.get(key,{})
    if isinstance(previous,dict) and now<int(previous.get('next_cheap_probe',0)):
        return False
    entry=dict(previous) if isinstance(previous,dict) else {}
    entry['next_cheap_probe']=now+900
    history[key]=entry
    save_state(history)
    env=dict(os.environ)
    env['MAD4B_CSS_PROBE_ONLY']='1'
    try:
        result=run(TASKS[job](site),timeout=170,env=env)
    except (OSError,subprocess.TimeoutExpired) as exc:
        print('WPCSS_CHEAP_PROBE_RETRY',site,type(exc).__name__,flush=True)
        return False
    output=result.stdout
    # The unmodified first-party event gate must report exact unchanged
    # source with zero exit; any changed or unproven CSS remains pending.
    verified,original_reason=verified_task_outcome(
        site,job,result.returncode,result.stdout,result.stderr)
    unchanged='SKIP '+site+' unchanged Critical CSS sources; no Chromium'
    if not verified or unchanged not in output:
        print('WPCSS_CERTIFICATE_CHANGE_NEEDS_BROWSER',site,jid,
              'exit',result.returncode,'reason',original_reason,flush=True)
        return False
    if ack(site,job,jid):
        history.pop(key,None)
        save_state(history)
        print('WPCSS_UNCHANGED_CERT_NO_BROWSER_ACK',site,jid,flush=True)
        return True
    print('WPCSS_CHEAP_PROBE_ACK_STALE',site,jid,flush=True)
    return False

def observe_source_only_variant_progress(site,job,reason,history,now):
    """Advisory-only original source progress during reserved WP-CLI slots.

    The original WordPress ticket is NOT acknowledged. One HTTPS source
    observation per native Cron tick; no new WP-CLI, Chromium, PHP cache,
    CSS issuance, HTML ready flag, cross-company writes or CPU bypass.
    The nested first-party observer independently validates the live CPU
    budget and root-owned fresh WordPress catalog snapshot.
    """
    if (site!=SITE or job not in ('families','public')
        or reason!='central_wpcli_slots_reserved'):
        return False
    script=SOURCE/'cached-variant-refresh.py'
    if script.is_symlink() or not script.is_file():
        return False
    key=site+':source_only_template_observation'
    record=history.get(key,{})
    if isinstance(record,dict) and now<int(record.get('next_retry',0)):
        return False
    history[key]={'next_retry':now+300,'last_attempt':now}
    save_state(history)
    try:
        result=run(['/usr/bin/python3',str(script),site],timeout=42)
    except (OSError,subprocess.TimeoutExpired) as exc:
        print('WPCSS_VARIANT_SOURCE_ONLY_DEFER',site,type(exc).__name__,flush=True)
        return False
    wanted='LIGHT_VARIANTS_FROM_WP_SNAPSHOT '+site+' '
    accepted=any(row.startswith(wanted) for row in result.stdout.splitlines())
    print('WPCSS_VARIANT_SOURCE_ONLY',site,
          'advisory_progress' if accepted else 'deferred',
          (result.stdout+' '+result.stderr).strip()[-180:],flush=True)
    return bool(result.returncode==0 and accepted)

def main():
    REPORT.mkdir(parents=True,exist_ok=True,mode=0o700)
    if REPORT.is_symlink():raise ValueError('invalid_report_root')
    history=safe_state();now=int(time.time());started=time.monotonic()
    if '--inspect' not in sys.argv and SITE=='source':
        observe_source_home_without_browser()
        observe_source_css_profiles()
    headroom,reason=cpu_window()
    for site in (SITE,):
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
            else:
                admitted=headroom
                if not admitted and site=='source' and job=='first_fold':
                    admitted,recovery_reason=source_home_recovery_window()
                    if admitted:
                        print('WPCSS_CONFIRMED_HOME_RECOVERY_PRIORITY',site,
                              recovery_reason,flush=True)
                if not admitted:
                    if cheap_certified_first_fold(site,job,jid,key,history,reason,now):
                        continue
                    # Keep lightweight, source-bound template segmentation
                    # progressing even while other WordPress CLI jobs occupy
                    # the heavy worker slots. Never consume the CSS ticket.
                    observe_source_only_variant_progress(
                        site,job,reason,history,now)
                    print('WPCSS_CPU_QUEUE_PRESERVED',site,reason,flush=True)
                    continue
                remaining=int(HOST_WAKE_BUDGET-(time.monotonic()-started))
                if remaining<180:
                    print('WPCSS_HOST_WAKE_BUDGET_DEFER',site,job,flush=True)
                else:
                    invoke(site,job,jid,key,history,timeout=min(JOB_TIMEOUT[job],remaining))
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
