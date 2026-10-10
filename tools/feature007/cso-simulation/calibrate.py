#!/usr/bin/env python3
"""Calibrate the actual pinned PHP engines, preserving red/green output bytes."""
import argparse, concurrent.futures, hashlib, json, os, pathlib, subprocess

HERE=pathlib.Path(__file__).resolve().parent
p=argparse.ArgumentParser();p.add_argument('--runtime',type=pathlib.Path,default=HERE.parent/'php-wasm-runtime-4d393227');p.add_argument('--fixtures',type=pathlib.Path,default=HERE.parent/'php-wasm-calibration');p.add_argument('--out',type=pathlib.Path,default=HERE/'calibration');a=p.parse_args()
a.out.mkdir(parents=True,exist_ok=True)
out=b''.join((f'{i:04d}|العربية|🙂|fixture|'+('x'*23)+'|\0\n').encode() for i in range(1500))+'tail🙂'.encode()
err=b''.join(('stderr:'+f'{i:04d}|العربية|🙂|fixture|'+('x'*23)+'|\0\n').encode() for i in range(1500))+'stderr-tail🙂'.encode()
cases=[]
for php in ('7.4','8.3'):
    cases.extend([(php,'runtime.php','version-crypto',0),(php,'exit42.php','exit42',42),(php,'syntax-error.php','syntax',255),(php,'infinite.php','timeout',124),(php,'process-limit.php','process-limit',0)])
    cases.extend((php,'output-integrity.php',f'bytes-{n}',0) for n in range(3))
def run(case):
    php,file,name,expected=case;stem=f'{php}-{name}';report=a.out/(stem+'.json')
    command=['node',str(HERE/'run-php.mjs'),'--php',php,'--repo',str(a.fixtures),'--file',file,'--runtime',str(a.runtime),'--report',str(report)]
    if name=='timeout':command+=['--timeout-ms','1000']
    env=os.environ.copy();env['CSO_RUNTIME_HOST_CANARY']='generated-calibration-canary-not-inherited'
    process=subprocess.run(command,capture_output=True,env=env,timeout=40)
    (a.out/(stem+'.stdout')).write_bytes(process.stdout);(a.out/(stem+'.stderr')).write_bytes(process.stderr)
    detail=json.loads(report.read_text()) if report.exists() else {}
    checks={'exit':process.returncode==expected,'source_fence':detail.get('source_immutability_verified') is True}
    if name=='version-crypto':
        try:
            d=json.loads(process.stdout);checks.update({key:d.get(key) is True for key in ('rsa_ok','aes_gcm_ok','env_home_absent','env_canary_absent','host_etc_absent','network_disabled','curl_disabled')});checks.update(php=d.get('php')==php+'.33',sapi=d.get('sapi')=='cli',int64=d.get('int_size')==8,openssl=d.get('openssl','').startswith('OpenSSL 1.1.1t'),sodium=d.get('sodium') is False,pcntl=d.get('pcntl') is False,no_stderr=not process.stderr)
        except Exception:checks['valid_json']=False
    elif name.startswith('bytes-'):checks.update(exact_stdout=process.stdout==out,exact_stderr=process.stderr==err)
    elif name=='exit42':checks.update(exact_stdout=process.stdout==b'exit-sentinel\n',no_stderr=not process.stderr)
    elif name=='syntax':checks.update(parse_error=b'Parse error' in process.stderr,no_stdout=not process.stdout)
    elif name=='timeout':checks.update(termination=detail.get('termination')=='wall_timeout',no_stdout=not process.stdout)
    elif name=='process-limit':checks.update(process_rejected=b'process-rejected\n' in process.stdout,not_executed=b'unexpected-process' not in process.stdout)
    return {'php':php,'case':name,'status':'PASS_WASM' if all(checks.values()) else 'FAIL','checks':checks,'report':str(report),'report_sha256':hashlib.sha256(report.read_bytes()).hexdigest() if report.exists() else None,'stdout_sha256':hashlib.sha256(process.stdout).hexdigest(),'stderr_sha256':hashlib.sha256(process.stderr).hexdigest()}
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:results=list(pool.map(run,cases))
summary={'kind':'actual-official-WASM-calibration','native_ci':False,'pass':sum(x['status']=='PASS_WASM' for x in results),'fail':sum(x['status']=='FAIL' for x in results),'expected_bytes':{'stdout':len(out),'stderr':len(err),'stdout_sha256':hashlib.sha256(out).hexdigest(),'stderr_sha256':hashlib.sha256(err).hexdigest()},'results':results}
(a.out/'summary.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n')
print(json.dumps({k:v for k,v in summary.items() if k!='results'},ensure_ascii=False))
for r in results:
    if r['status']=='FAIL':print(json.dumps(r,ensure_ascii=False))
raise SystemExit(1 if summary['fail'] else 0)
