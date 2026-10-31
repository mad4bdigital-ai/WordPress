#!/usr/bin/env python3
"""MAD4B native VPS operator: independent SSH general shell and governed Raw SQL.

Direct operator lane only. NEVER accepts commands from WordPress broker queues.
It binds the host SSH principal and a root-owned local operator policy, hashes
the exact input bytes, journals before execution, and independently reads back.
Production writes and all unbacked writes are fail-closed by default.
"""
import argparse, datetime, hashlib, json, os, pathlib, re, sqlite3, subprocess, sys, time, uuid

ROOT=pathlib.Path('/opt/mad4b-company-transport/native-operator-v1')
POLICY=ROOT/'host-authority.json'
AUDIT=ROOT/'receipts'
DB=pathlib.Path('/opt/mad4b-company-transport/dispatch-queue-v1.sqlite3')
HOST='srv2030908'
CONTRACT='mad4b.native-vps-operator.v1'
TARGETS={
  ('tours-central','staging'):('etg-commerce-staging-db','https://tours.dev.mad4b.com'),
  ('wayfatours','production'):('wayfatours-production-db','https://wayfatours.mad4b.com')
}
INTENTS=('read','diagnostic','repair','configuration','upgrade','deployment','rollback')
MUTATING=('repair','configuration','upgrade','deployment','rollback')

def sha(data): return hashlib.sha256(data).hexdigest()

def safe_sql_read(data):
    # All SQL readbacks are inspections, even when the primary SQL is a write.
    try:
        statement=data.decode('utf-8').strip()
    except UnicodeDecodeError:
        abort('SQL_INSPECTION_UTF8_REQUIRED')
    if statement.endswith(';'): statement=statement[:-1].strip()
    if (';' in statement or '--' in statement or '/*' in statement
        or '#' in statement or not re.match(r'^(SELECT|SHOW|DESCRIBE|EXPLAIN)\s+',statement,re.I)
        or re.search(
            r'\b(INTO|OUTFILE|DUMPFILE|FOR\s+UPDATE|LOCK|CALL|LOAD_FILE|SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK)\b|:=',
            statement,re.I)):
        abort('SQL_READ_DIAGNOSTIC_MUST_BE_SINGLE_SAFE_INSPECTION')
    return statement.encode('utf-8')+b';'

def emit(obj): print(json.dumps(obj,sort_keys=True,separators=(',',':')))
def abort(reason): raise RuntimeError(reason)
def root_session():
    if os.geteuid()!=0 or not os.environ.get('SSH_CONNECTION'):
        abort('INDEPENDENT_AUTHENTICATED_ROOT_SSH_SESSION_REQUIRED')
    if subprocess.run(['/usr/bin/hostname','-s'],capture_output=True,text=True,
                      timeout=4,check=False).stdout.strip()!=HOST:
        abort('VPS_HOST_ID_MISMATCH')

def file_bytes(path):
    if not path or not pathlib.Path(path).is_absolute():abort('ABSOLUTE_ROOT_OWNED_FILE_REQUIRED')
    obj=pathlib.Path(path)
    st=obj.lstat()
    if obj.is_symlink() or not obj.is_file() or st.st_uid!=0 or st.st_mode & 0o077:
        abort('UNSAFE_COMMAND_FILE')
    if st.st_size<1 or st.st_size>65536:abort('COMMAND_FILE_SIZE_INVALID')
    return obj.read_bytes()

def authority():
    raw=file_bytes(str(POLICY))
    data=json.loads(raw)
    if data.get('contract')!=CONTRACT or data.get('host_id')!=HOST        or data.get('principal')!='root' or data.get('revoked') is True:
        abort('HOST_GRANT_MISSING_OR_REVOKED')
    if data.get('allow_general_shell') is not True or data.get('allow_raw_sql') is not True:
        abort('HOST_CAPABILITY_NOT_GRANTED')
    return data

def preflight(args,policy):
    if args.intent not in INTENTS or args.kind not in ('shell','sql'):
        abort('UNSUPPORTED_OPERATION')
    if args.kind=='sql' and (args.company,args.environment) not in TARGETS:
        abort('DATABASE_NOT_ENROLLED')
    if args.environment not in ('staging','production'):
        abort('ENVIRONMENT_NOT_ENROLLED')
    permitted=policy.get('scopes',{}).get(args.company+':'+args.environment,{})
    if permitted.get('allow_operator') is not True:
        abort('EXACT_COMPANY_ENVIRONMENT_GRANT_MISSING')
    if args.kind=='sql' and permitted.get('allow_sql') is not True:
        abort('RAW_SQL_NOT_AUTHORIZED_FOR_COMPANY')
    mutation=args.intent in MUTATING
    if mutation and permitted.get('allow_mutation') is not True:
        abort('MUTATION_NOT_AUTHORIZED')
    if mutation and args.environment=='production' and permitted.get('allow_production_mutation') is not True:
        abort('PRODUCTION_MUTATION_NOT_AUTHORIZED')
    command=file_bytes(args.file)
    if args.kind=='sql' and not mutation:
        safe_sql_read(command)
    command_hash=sha(command)
    if not re.fullmatch('[a-f0-9]{64}',args.sha256 or '') or args.sha256!=command_hash:
        abort('EXACT_COMMAND_SHA256_MISMATCH')
    readback=file_bytes(args.readback_file)
    if args.kind=='sql': safe_sql_read(readback)
    if not re.fullmatch('[a-f0-9]{64}',args.expected_readback_sha256 or ''):
        abort('INDEPENDENT_READBACK_SHA256_MISSING')
    if mutation:
        if not args.rollback_file or not args.backup_file:
            abort('MUTATION_REQUIRES_BACKUP_AND_ROLLBACK')
        rollback=file_bytes(args.rollback_file)
        snapshot=file_bytes(args.backup_file)
        if not re.fullmatch('[a-f0-9]{64}',args.backup_sha256 or '')            or sha(snapshot)!=args.backup_sha256:
            abort('BACKUP_DIGEST_MISMATCH')
    return {'contract':CONTRACT,'host_id':HOST,'lane':'vps_ssh',
      'company':args.company,'environment':args.environment,
      'kind':args.kind,'intent':args.intent,'command_sha256':command_hash,
      'readback_command_sha256':sha(readback),'expected_readback_sha256':args.expected_readback_sha256,
      'mutating':mutation,'backup_sha256':sha(snapshot) if mutation else None,
      'rollback_sha256':sha(rollback) if mutation else None,
      'production_mutation_authorized':mutation and args.environment=='production',
      'command_catalog_required':False,'authorization':'independent_root_ssh_plus_host_scoped_grant',
      'execution_performed':False}

def local_exec(cmd, timeout):
    if cmd is None:abort('COMMAND_MISSING')
    p=subprocess.run(['/bin/bash','--noprofile','--norc','-e','-o','pipefail'],
                     input=cmd,capture_output=True,timeout=timeout,check=False)
    return p

def sql_exec(container, cmd, timeout, read_only=False):
    script='MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot --database="$MARIADB_DATABASE" --batch --skip-column-names'
    if read_only:
        cmd=b'START TRANSACTION READ ONLY;\n'+safe_sql_read(cmd)+b'\nCOMMIT;\n'
    return subprocess.run(['/usr/bin/docker','exec','-i',container,'/bin/sh','-c',script],
                          input=cmd,capture_output=True,timeout=timeout,check=False)

def journal(job,event,data):
    # Common original Dispatcher event log; no SQL or shell text recorded.
    if not DB.exists():abort('DISPATCHER_JOURNAL_NOT_FOUND')
    detail=json.dumps(data,sort_keys=True,separators=(',',':'))
    with sqlite3.connect(str(DB),timeout=8) as conn:
        conn.execute('INSERT INTO events(job_id,at,event,detail) VALUES(?,?,?,?)',
                     ('operator:'+job,int(time.time()),event,detail[:2500]))
        conn.commit()

def receipt_file(id):
    AUDIT.mkdir(mode=0o700,parents=True,exist_ok=True)
    return AUDIT/(id+'.json')

def apply(args,plan):
    ident=args.correlation_id
    if not re.fullmatch('[A-Za-z0-9_.-]{8,90}',ident or ''):
        abort('CORRELATION_ID_REQUIRED')
    if sha(file_bytes(args.file))!=plan['command_sha256'] or sha(file_bytes(args.readback_file))!=plan['readback_command_sha256']:
        abort('COMMAND_OR_READBACK_CHANGED_SINCE_PREFLIGHT')
    if plan['mutating'] and (sha(file_bytes(args.backup_file))!=plan['backup_sha256'] or sha(file_bytes(args.rollback_file))!=plan['rollback_sha256']):
        abort('BACKUP_OR_ROLLBACK_CHANGED_SINCE_PREFLIGHT')
    path=receipt_file(ident)
    try: fd=os.open(str(path),os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
    except FileExistsError:abort('CORRELATION_ALREADY_CONSUMED_NO_REPLAY')
    start=dict(plan,state='ATTEMPTED',started_at=datetime.datetime.now(datetime.timezone.utc).isoformat())
    with os.fdopen(fd,'w') as f:
        f.write(json.dumps(start));f.flush();os.fsync(f.fileno())
    journal(ident,'OPERATOR_EXECUTION_ATTEMPTED',{'company':args.company,'kind':args.kind,
           'environment':args.environment,'intent':args.intent,'command_sha256':plan['command_sha256']})
    runner=local_exec if args.kind=='shell' else lambda b,t:sql_exec(TARGETS[(args.company,args.environment)][0],b,t,not plan['mutating'])
    readback_runner=local_exec if args.kind=='shell' else lambda b,t:sql_exec(TARGETS[(args.company,args.environment)][0],b,t,True)
    # Direct host operator command supplied from a root-owned local file, never a web/queue payload.
    try:
        result=runner(file_bytes(args.file),args.timeout)
        readback=readback_runner(file_bytes(args.readback_file),args.timeout)
        good=(result.returncode==0 and readback.returncode==0
            and sha(readback.stdout)==args.expected_readback_sha256)
        state='VERIFIED' if good else 'FAILED_EXECUTION_OR_READBACK'
        final=dict(start,state=state,exit_code=result.returncode,
          readback_exit_code=readback.returncode,
          output_sha256=sha(result.stdout),readback_sha256=sha(readback.stdout),
          readback_matches_expected=good,stderr_present=bool(result.stderr or readback.stderr),
          finished_at=datetime.datetime.now(datetime.timezone.utc).isoformat(),
          mutation_possible=plan['mutating'],mutation_performed=plan['mutating'] and result.returncode==0)
        # Rollback is a separate explicit host operation, not a blind replay on uncertain outcome.
    except (OSError,subprocess.TimeoutExpired):
        final=dict(start,state='UNKNOWN_REQUIRES_RECONCILIATION',verified=False,
          mutation_possible=plan['mutating'],
          finished_at=datetime.datetime.now(datetime.timezone.utc).isoformat())
    tmp=path.with_suffix('.tmp')
    with tmp.open('w') as f:
        f.write(json.dumps(final,sort_keys=True));f.flush();os.fsync(f.fileno())
    os.replace(tmp,path)
    journal(ident,'OPERATOR_'+final['state'],{'environment':args.environment,
      'company':args.company,'kind':args.kind,'command_sha256':plan['command_sha256'],
      'verified':final['state']=='VERIFIED'})
    return final

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('action',choices=['status','plan','apply'])
    parser.add_argument('--kind',choices=['shell','sql'],default='shell')
    parser.add_argument('--intent',choices=INTENTS,default='diagnostic')
    parser.add_argument('--company',default='tours-central')
    parser.add_argument('--environment',choices=['staging','production'],default='staging')
    parser.add_argument('--file')
    parser.add_argument('--sha256')
    parser.add_argument('--readback-file')
    parser.add_argument('--expected-readback-sha256')
    parser.add_argument('--backup-file')
    parser.add_argument('--backup-sha256')
    parser.add_argument('--rollback-file')
    parser.add_argument('--correlation-id')
    parser.add_argument('--timeout',type=int,default=30)
    args=parser.parse_args()
    root_session()
    policy=authority()
    if args.action=='status':
        emit({'contract':CONTRACT,'state':'INDEPENDENT_ROOT_SSH_PRINCIPAL_VERIFIED',
          'host_id':HOST,'persistent_host_grant_revoked':bool(policy.get('revoked')),
          'scopes':{x:{k:v for k,v in item.items() if k.startswith('allow_')}
                  for x,item in policy['scopes'].items()},
          'command_catalog_required':False,'wordpress_queue_execution_allowed':False,
          'execution_performed':False})
        return
    if args.timeout<1 or args.timeout>120:abort('TIMEOUT_NOT_ALLOWED')
    plan=preflight(args,policy)
    if args.action=='plan':emit(dict(plan,state='ADMITTED_REQUIRES_DIRECT_OPERATOR_APPLY'));return
    final=apply(args,plan)
    emit(final)
    if final['state']!='VERIFIED':raise SystemExit(75)

if __name__=='__main__':
    try: main()
    except (RuntimeError,ValueError,FileNotFoundError,PermissionError) as exc:
        emit({'contract':CONTRACT,'state':'DENIED','reason':str(exc),
              'execution_performed':False})
        sys.exit(77)
