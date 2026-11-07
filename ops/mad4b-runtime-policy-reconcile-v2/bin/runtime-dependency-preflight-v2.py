#!/usr/bin/env python3
"""MAD4B read-only dependency graph + ordered-map integrity preflight v2."""
import argparse,hashlib,json,re,sys,datetime
from pathlib import Path
parser=argparse.ArgumentParser()
parser.add_argument('--site',default='/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content')
parser.add_argument('--strict',action='store_true')
parser.add_argument('--output')
args=parser.parse_args()
site=Path(args.site);plugin=site/'plugins/mad4b-platform'
pmap=plugin/'config/ordered-mu-map.php'
manifest=json.loads((plugin/'config/ordered-mu-map-contract.json').read_text())
src=pmap.read_text()
entries=re.findall(r"\['([^']+\.php)','([^']+\.php)'\]",src)
problems=[]
if manifest.get('contract')!='mad4b.mu-ordered-map-integrity.v1':problems.append('MAP_CONTRACT_WRONG')
if len(entries)!=manifest.get('expected_count'):problems.append('MAP_COUNT_DRIFT')
if [list(x) for x in entries]!=manifest.get('ordered_entries'):problems.append('MAP_ORDER_DRIFT')
digest=hashlib.sha256(json.dumps([list(x) for x in entries],separators=(',',':'),ensure_ascii=False).encode()).hexdigest()
if digest!=manifest.get('ordered_entries_sha256'):problems.append('MAP_DIGEST_DRIFT')
names={name:i for i,(name,_) in enumerate(entries)}
if len(names)!=len(entries):problems.append('DUPLICATE_NAME')
deps={name:set() for name in names}
forward=[]
for i,(name,relative) in enumerate(entries):
  target=site/'plugins'/relative
  if not target.is_file():
    problems.append('MISSING_MODULE:'+name)
    continue
  if target.is_symlink():problems.append('SYMLINK_MODULE:'+name)
  body=target.read_text(errors='replace')
  for dep in re.findall(r"MAD4B_MU_File_Resolver_V1::path\(\s*['\"]([^'\"]+\.php)['\"]\s*\)",body):
    if dep not in names:
      problems.append('DEPENDENCY_NOT_IN_MAP:'+name+':'+dep)
    else:
      deps[name].add(dep)
      if names[dep]>i:forward.append([name,dep])
cycles=[];state={}
def dfs(name,stack):
  if state.get(name)==1:
    cycles.append(stack[stack.index(name):]+[name]);return
  if state.get(name)==2:return
  state[name]=1
  for dep in deps[name]:dfs(dep,stack+[dep])
  state[name]=2
for name in deps:
  if not state.get(name):dfs(name,[name])
if cycles:problems.append('CYCLE:'+repr(cycles[:3]))
for anchor in ['305-mad4b-original-sealed-rebind.php','306-mad4b-original-core-recovery.php','098-presentation-policy-context.php']:
  if anchor not in names:problems.append('MISSING_CRITICAL_DEP:'+anchor)
result={'contract':'mad4b.runtime-dependency-preflight.v2',
  'checked_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
  'map_sha256':hashlib.sha256(pmap.read_bytes()).hexdigest(),
  'count':len(entries),'direct_dependency_edges':sum(len(v) for v in deps.values()),
  'forward_dependency_edges':forward,'cycles':cycles,'issues':problems,
  'verdict':'PASS' if not problems else 'FAIL'}
if args.output:Path(args.output).write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,ensure_ascii=False))
sys.exit(1 if args.strict and problems else 0)
