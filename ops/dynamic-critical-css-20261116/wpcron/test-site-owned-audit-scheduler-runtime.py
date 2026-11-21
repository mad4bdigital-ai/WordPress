#!/usr/bin/env python3
"""Execute actual worker planner in an isolated environment without WP CLI."""
import ast,pathlib,sys
p=pathlib.Path(sys.argv[1])/'wpcron/site-owned-css-worker-v2.py'
tree=ast.parse(p.read_text())
fn=next(v for v in tree.body if isinstance(v,ast.FunctionDef) and
        v.name=='available_candidates')
source=compile(ast.Module(body=[fn],type_ignores=[]),str(p),'exec')
env={'TASKS':{k:None for k in ('families','public','first_fold','visual','audit','retention')},
     'PROFILE':{'tasks':['families','public','first_fold','visual','audit','retention']},
     'COOLDOWN':300,'SITE':'source','SOURCE':'untrusted-in-fixture'}
exec(source,env)
plan=env['available_candidates']
now=1800000000
pending={'public':{'id':5,'reason':'source_changed','requested_at':now-60},
         'families':{'id':7,'reason':'source_changed','requested_at':now-25},
         'audit':{'id':9,'reason':'scheduled','requested_at':now-4000}}
def keys(rows):return [r[3] for r in rows]
assert keys(plan('source',{'pending':pending},{},now))[0]=='audit'
print('PASS >one-hour overdue audit precedes fresh source-change jobs')
pending['audit']['requested_at']=now-3500
assert keys(plan('source',{'pending':pending},{},now))[0] in ('public','families')
print('PASS ordinary audits cannot block fresh approved CSS work')
pending['audit']['requested_at']=now-4000
history={'source:audit:9':{'next_retry':now+500}}
assert 'audit' not in keys(plan('source',{'pending':pending},history,now))
print('PASS overdue audits still obey bounded history retry cooldown')
pending['audit']['requested_at']='bad'
assert 'audit' not in keys(plan('source',{'pending':pending},{},now)) or \
       keys(plan('source',{'pending':pending},{},now))[0]!='audit'
print('PASS malformed timestamp cannot impersonate overdue status')
pending['audit']['requested_at']=now-4000
assert keys(plan('wayfa',{'pending':pending},{},now))[0]=='audit'
print('PASS same site-owned fairness policy applies to both enrolled companies')
print('PASS 5/5 live-function audit scheduling tests')
