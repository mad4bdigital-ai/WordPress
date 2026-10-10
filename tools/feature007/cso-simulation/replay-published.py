#!/usr/bin/env python3
"""Replay CSO fixtures against one immutable plugin snapshot, never claiming native CI."""
import argparse,concurrent.futures,hashlib,json,pathlib,re,subprocess
here=pathlib.Path(__file__).resolve().parent
p=argparse.ArgumentParser();p.add_argument('--repo',type=pathlib.Path,required=True);p.add_argument('--runtime',type=pathlib.Path,default=here.parent/'php-wasm-runtime-4d393227');p.add_argument('--out',type=pathlib.Path,required=True);p.add_argument('--commit',required=True);a=p.parse_args();a.out.mkdir(parents=True,exist_ok=True)
fixtures=[
 ('tests/cso01-read-foundation-runtime.php',[],False,rb'mad4b\.cso01\.read-foundation\.runtime\.v1: PASS\n'),
 ('tests/cso01-read-foundation-collision-runtime.php',[],False,rb'mad4b\.cso01\.read-registration-collision\.v1: PASS\n'),
 ('tests/cso01-read-foundation-registration-runtime.php',[],False,rb'mad4b\.cso01\.registration-null-fail-closed\.v1: PASS\n'),
 ('tests/cso01-unified-discovery-funnel-runtime.php',[],False,rb'mad4b\.cso01\.unified-readonly-funnel\.v1: PASS\n'),
 *[('tests/cso-gateway-runtime.php',[mode],False,('CSO GATEWAY '+mode+': [0-9]+ PASS\n').encode()) for mode in ('normal','partial','collision')],
 ('tests/cso-secrets-runtime.php',[],False,rb'PASS cso-secrets-runtime: [0-9]+ checks; explicit fake native executor, certificate, provider and DB; no live acceptance\n'),
 ('includes/class-mad4b-scp-cso-form-ui.php',[],True,rb'No syntax errors detected in /repo/includes/class-mad4b-scp-cso-form-ui\.php\n')]
cases=[(php,*f) for php in ('7.4','8.3') for f in fixtures]
def run(case):
 php,file,args,lint,expected=case;stem=php+'-'+pathlib.Path(file).stem+('-'+args[0] if args else '')+('-lint' if lint else '');report=a.out/(stem+'.json')
 command=['node',str(here/'run-php.mjs'),'--php',php,'--repo',str(a.repo),'--file',file,'--runtime',str(a.runtime),'--report',str(report)]+(['--lint'] if lint else [])+(['--',*args] if args else [])
 proc=subprocess.run(command,capture_output=True,timeout=45)
 (a.out/(stem+'.stdout')).write_bytes(proc.stdout);(a.out/(stem+'.stderr')).write_bytes(proc.stderr)
 d=json.loads(report.read_text()) if report.exists() else {}
 checks={'exit0':proc.returncode==0,'exact_marker':re.fullmatch(expected,proc.stdout) is not None,'stderr_empty':not proc.stderr,'source_fence':d.get('source_immutability_verified') is True}
 return {'php':php,'fixture':file,'args':args,'lint':lint,'status':'PASS_WASM' if all(checks.values()) else 'FAIL','checks':checks,'exit_code':proc.returncode,'report':str(report),'report_sha256':hashlib.sha256(report.read_bytes()).hexdigest() if report.exists() else None,'fixture_sha256':d.get('fixture_sha256'),'source_sha256':d.get('source_snapshot',{}).get('sha256'),'stdout_preview':proc.stdout.decode('utf-8','replace')[:1000],'stderr_preview':proc.stderr.decode('utf-8','replace')[:2000]}
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:results=list(pool.map(run,cases))
summary={'kind':'actual-official-WASM-CSO-simulation','native_ci':False,'live_acceptance':False,'tested_commit':a.commit,'pass':sum(r['status']=='PASS_WASM' for r in results),'fail':sum(r['status']=='FAIL' for r in results),'source_snapshots':sorted(set(r['source_sha256'] for r in results if r['source_sha256'])),'results':results}
(a.out/'results.json').write_text(json.dumps(summary,indent=2)+'\n')
print(json.dumps({k:v for k,v in summary.items() if k!='results'}))
for r in results:
 if r['status']=='FAIL':print(json.dumps(r))
raise SystemExit(1 if summary['fail'] else 0)
