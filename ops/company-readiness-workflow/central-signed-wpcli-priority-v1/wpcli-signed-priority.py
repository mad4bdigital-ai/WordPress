#!/usr/bin/env python3
"""Read-only local admission hint for ORIGINAL owner-signed 305/306/312/313 Jobs.

Runs outside WordPress bootstrap and never claims, signs, executes, changes grants,
or manufactures 313 evidence. Not an authorization oracle. It only defers
optional scheduled Central WP-CLI work while a relevant live Job has time to run.
"""
import argparse, json, os, re, subprocess, sys, time
from pathlib import Path

sys.path.insert(0, '/opt/mad4b-company-transport')
SITE_REGISTRY = Path('/opt/mad4b-company-transport/dynamic-site-binding-v1/registry.json')
STATE_QUEUED='QUEUED_FOR_VPS_BROKER_CLAIM'
STATE_CLAIMED='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
PREFIX='mad4b_central_broker_job_v1_'
MAX_JOBS=64
UUID=re.compile(r'^[a-f0-9-]{36}$')
JOB_ID=re.compile(r'^[a-f0-9]{32}$')
SHA=re.compile(r'^[a-f0-9]{64}$')
SLUG=re.compile(r'^[a-z][a-z0-9-]{1,79}$')


def php_field(blob, name, kind='str'):
    """Read a specifically named, flat PHP-serialized primitive; never eval or unserialize."""
    key=name.encode('ascii')
    marker=b's:'+str(len(key)).encode('ascii')+b':"'+key+b'";'
    start=blob.find(marker)
    if start<0 or blob.find(marker,start+len(marker))>=0:
        return None
    raw=blob[start+len(marker):]
    if kind=='int':
        match=re.match(rb'i:(-?[0-9]{1,14});',raw)
        return int(match.group(1)) if match else None
    match=re.match(rb's:([0-9]{1,4}):"',raw)
    if not match:return None
    size=int(match.group(1))
    if size>160 or size<1:return None
    text=raw[match.end():match.end()+size]
    if len(text)!=size or raw[match.end()+size:match.end()+size+2]!=b'";':return None
    try:return text.decode('ascii')
    except UnicodeDecodeError:return None


def classify(record, option_name, now, host):
    if not option_name.startswith(PREFIX):return None
    job=option_name[len(PREFIX):]
    if not JOB_ID.fullmatch(job):return None
    if php_field(record,'job_id')!=job:return None
    state=php_field(record,'state')
    if state not in (STATE_QUEUED,STATE_CLAIMED):return None
    if php_field(record,'operation')!='company.remote.wpcli.full':return None
    if php_field(record,'target_scope')!='wordpress':return None
    if php_field(record,'target_host_id')!=host or php_field(record,'broker_host_id')!=host:return None
    if not (SLUG.fullmatch(php_field(record,'company') or '')
            and UUID.fullmatch(php_field(record,'site_uuid') or '')
            and SHA.fullmatch(php_field(record,'command_sha256') or '')):return None
    created=php_field(record,'created_epoch','int')
    expiry=php_field(record,'expires_epoch','int')
    if not isinstance(created,int) or not isinstance(expiry,int):return None
    if not (created<=now<expiry<=created+3600 and created>=now-3600):return None
    # Preserve the original executor's >=360-second safe start window.
    # Queued jobs need some runway to be claimed; already claimed need more.
    remaining=expiry-now
    minimum=420 if state==STATE_QUEUED else 360
    if remaining<=minimum:return None
    return {'state':state,'remaining_seconds':remaining,'job_id':job}


def enrolled_database():
    # Root-owned enrolled central PHP binding; Docker only, no WP-CLI or HTTP.
    from stdio_scope import enrolled_central_binding
    b=enrolled_central_binding()
    name=b['provider_locator']
    if not re.fullmatch(r'php-[a-z0-9]{20,40}',name):
        raise RuntimeError('CENTRAL_PHP_BINDING_INVALID')
    project=name.removeprefix('php-')
    db='db-'+project
    for container,service in ((name,'php'),(db,'db')):
        r=subprocess.run(['/usr/bin/docker','inspect','--format',
            '{{index .Config.Labels "com.docker.compose.project"}} '
            '{{index .Config.Labels "com.docker.compose.service"}} '
            '{{index .Config.Labels "coolify.environmentName"}} '
            '{{.State.Status}}',container],capture_output=True,text=True,timeout=5)
        if r.returncode!=0 or r.stdout.strip()!=f'{project} {service} staging running':
            raise RuntimeError('ENROLLED_CENTRAL_DB_RUNTIME_UNAVAILABLE')
    return db


def live_jobs(now=None, run=subprocess.run):
    now=int(time.time()) if now is None else int(now)
    host=run(['/usr/bin/hostname','-s'],capture_output=True,text=True,timeout=4)
    if host.returncode or not re.fullmatch('[a-zA-Z0-9.-]{5,80}',host.stdout.strip()):
        raise RuntimeError('HOST_SCOPE_UNAVAILABLE')
    host=host.stdout.strip()
    db=enrolled_database()
    sql=("SELECT option_name,HEX(option_value) FROM wp_options "
         "WHERE option_name LIKE 'mad4b_central_broker_job_v1_%' "
         f"ORDER BY option_id DESC LIMIT {MAX_JOBS}")
    command='export MYSQL_PWD="$MARIADB_PASSWORD"; exec mariadb -u "$MARIADB_USER" -D "$MARIADB_DATABASE" --batch --skip-column-names -e "$1"'
    r=run(['/usr/bin/docker','exec',db,'sh','-c',command,'priority-read',sql],
        capture_output=True,text=True,timeout=9)
    if r.returncode or len(r.stdout)>600000:
        raise RuntimeError('CENTRAL_JOB_PRIORITY_DB_READ_UNAVAILABLE')
    active=[]
    for line in r.stdout.splitlines():
        parts=line.split('\t',1)
        if len(parts)!=2 or len(parts[1])>40000:continue
        try:blob=bytes.fromhex(parts[1])
        except ValueError:continue
        value=classify(blob,parts[0],now,host)
        if value:active.append(value)
    return active


def selftest():
    def row(job,state,expiry,created=1000):
        data={'job_id':job,'state':state,'operation':'company.remote.wpcli.full',
            'target_scope':'wordpress','target_host_id':'srv2030908',
            'broker_host_id':'srv2030908','company':'wayfatours',
            'site_uuid':'a3a7cc36-db7e-4a14-8456-b41857def05c',
            'command_sha256':'1'*64,'created_epoch':created,'expires_epoch':expiry}
        x='a:'+str(len(data))+':{'
        for k,v in data.items():
            x+=f's:{len(k)}:"{k}";'
            x+=f'i:{v};' if type(v) is int else f's:{len(v)}:"{v}";'
        return (x+'}').encode()
    job='a'*32;option=PREFIX+job
    cases=[
        (row(job,STATE_QUEUED,2200),option,1200,True),
        (row(job,STATE_CLAIMED,2200),option,1200,True),
        (row(job,STATE_CLAIMED,1500),option,1200,False),
        (row(job,STATE_QUEUED,1400),option,1200,False),
        (row(job,STATE_CLAIMED,2200),option,2300,False),
        (row(job,'WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED',2200),option,1200,False),
        (row(job,STATE_QUEUED,2200),PREFIX+'b'*32,1200,False),
        (row(job,STATE_QUEUED,2200),option,1200,True)]
    assert all(bool(classify(blob,op,now,'srv2030908'))==expected for blob,op,now,expected in cases)
    assert classify(row(job,STATE_QUEUED,2200),option,1200,'different-host') is None
    assert classify(row(job,STATE_QUEUED,2200).replace(b'wayfatours',b'otherbrand'),option,1200,'srv2030908') is not None
    assert php_field(row(job,STATE_QUEUED,2200),'expires_epoch','int')==2200
    print(json.dumps({'state':'PRIORITY_CLASSIFIER_SELFTEST_PASS','checks':11,'jobs_queued_or_executed':0}))


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('action',choices=['status','selftest','optional-admit'])
    args=parser.parse_args()
    if args.action=='selftest':selftest();return 0
    if os.geteuid()!=0:raise RuntimeError('ROOT_READ_ONLY_SCOPE_REQUIRED')
    try:
        jobs=live_jobs()
        if args.action=='optional-admit':
            # systemd ExecCondition: 0 starts noncritical telemetry; 1 skips
            # it while a time-eligible owner-created Job is in flight.
            print(json.dumps({'contract':'mad4b.original-signed-wpcli-priority.v1',
                'state':'DEFER_OPTIONAL_TELEMETRY' if jobs else 'ALLOW_OPTIONAL_TELEMETRY',
                'jobs_detected':len(jobs),'no_authority_changed':True},sort_keys=True))
            return 1 if jobs else 0
        print(json.dumps({'contract':'mad4b.original-signed-wpcli-priority.v1',
            'state':'SIGNED_JOB_PRIORITY_ACTIVE' if jobs else 'NO_ELIGIBLE_ORIGINAL_SIGNED_JOB',
            'defer_optional':bool(jobs),'jobs_detected':len(jobs),
            'minimum_remaining_seconds':min((x['remaining_seconds'] for x in jobs),default=0),
            'claims_created':0,'grant_issued':False,'word_press_bootstrap':False},sort_keys=True))
        return 0
    except (OSError,ValueError,RuntimeError,subprocess.TimeoutExpired) as exc:
        print(json.dumps({'contract':'mad4b.original-signed-wpcli-priority.v1',
            'state':'PRIORITY_PROBE_UNAVAILABLE','defer_optional':False,
            'reason':str(exc) if str(exc).isupper() else type(exc).__name__,
            'claims_created':0,'grant_issued':False},sort_keys=True))
        # A failed *priority hint* is not permission to claim or execute
        # anything. Do not accidentally disable monitoring on DB outages.
        return 0 if args.action=='optional-admit' else 2

if __name__=='__main__':sys.exit(main())
