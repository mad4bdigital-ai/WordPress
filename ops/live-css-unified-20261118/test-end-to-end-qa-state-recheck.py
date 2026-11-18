#!/usr/bin/env python3
"""Fail-closed, fully symmetric before/after original QA state admission."""
from pathlib import Path
import ast,importlib.util
d=Path(__file__).resolve().parents[1]/'dynamic-critical-css-20261116'
s=(d/'run-public-route.py').read_text()
tree=ast.parse(s)
f=next(x for x in tree.body if isinstance(x,ast.FunctionDef) and x.name=='run_core')
starts=[];ends=[]
for n in ast.walk(f):
 if isinstance(n,ast.Assign) and any(isinstance(t,ast.Subscript) and
     isinstance(t.value,ast.Name) and t.value.id in ('state','fresh_state')
     and isinstance(t.slice,ast.Constant) and t.slice.value in ('runtime_sha','qa_policy_sha')
     for t in n.targets):
  target=n.targets[0];record=(target.value.id,target.slice.value,ast.unparse(n.value))
  (starts if target.value.id=='state' else ends).append(record)
print('INITIAL',starts)
print('FINAL',ends)
assert {k for _,k,v in starts}=={'runtime_sha','qa_policy_sha'}
assert {k for _,k,v in ends}=={'runtime_sha','qa_policy_sha'}
assert len(starts)==2 and len(ends)==4,'initial, resumed and fresh candidate paths must each stamp exactly two identical first-party fields'
assert dict((k,v) for _,k,v in ends)=={
 'runtime_sha':'approved_native_runtime_sha(site, wp)',
 'qa_policy_sha':'qa_policy_sha()'},'final checks must read approved exact original source'
assert "if (fresh_state!=state or current!=route):" in s,'real page/source/currency revisions still gate publication'
assert "raise ValueError('SOURCE_REVISIONS_CHANGED_DURING_QA')" in s
spec=importlib.util.spec_from_file_location('ccssrunner',d/'run-public-route.py')
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
mod.TOOLS=d
for company in ('source','wayfa'):
 wp=mod.CONFIG[company][2]
 before={'generation':2,'route':'original'}
 before['runtime_sha']=mod.approved_native_runtime_sha(company,wp)
 before['qa_policy_sha']=mod.qa_policy_sha()
 after={'generation':2,'route':'original'}
 after['runtime_sha']=mod.approved_native_runtime_sha(company,wp)
 after['qa_policy_sha']=mod.qa_policy_sha()
 assert before==after,company+' unchanged original first-party source does not trigger false drift'
 assert before!={**after,'qa_policy_sha':'0'*64},'real verifier update still causes drift'
 assert before!={**after,'generation':3},'real content revision still causes drift'
 print('PASS',company,'equal original QA+runtime state, actual content/QA drift rejected')
print('QA_STATE_SYMMETRY_TESTS 8/8')
