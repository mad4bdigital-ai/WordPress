#!/usr/bin/env python3
"""Hermetic creation / park / recovery / untracked change rejection."""
import importlib.util
import pathlib
import tempfile
import subprocess
import time
import os
import hashlib

P=pathlib.Path(__file__).with_name('workspace_lifecycle.py')
spec=importlib.util.spec_from_file_location('mad4b_workspace_lifecycle',P)
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
def cmd(*args, cwd=None):
    return subprocess.check_output(list(args),cwd=cwd,text=True).strip()
def run():
    with tempfile.TemporaryDirectory(prefix='mad4b-lifecycle-test-') as tmp:
        root=pathlib.Path(tmp)
        repo=root/'repo';checkout=root/'checkouts'/'old';checkout.parent.mkdir()
        cmd('git','init','-q',str(repo))
        cmd('git','config','user.name','MAD4B QA',cwd=repo)
        cmd('git','config','user.email','qa@example.invalid',cwd=repo)
        (repo/'file.txt').write_text('reviewed content\n')
        cmd('git','add','file.txt',cwd=repo)
        cmd('git','commit','-qm','baseline',cwd=repo)
        cmd('git','worktree','add','-qb','feature/old',str(checkout),cwd=repo)
        initial=cmd('git','rev-parse','HEAD',cwd=checkout)
        os.utime(checkout,(time.time()-20*86400,time.time()-20*86400))
        policy={'repositories':[str(repo)],'minimum_age_days':14,'max_remove_per_run':2,
                'max_1minute_load':999,'allowed_checkout_prefixes':[str(root/'checkouts')+'/'],
                'protected_paths':[str(repo)],'require_no_ignored_files':True,
                'suspend_dirty_automatically':False}
        mod.LOGDIR=root/'logs'
        mod.process_activity=lambda:[]
        plan=mod.audit_and_apply(policy,False)
        assert plan['eligible']==1 and checkout.exists(),plan
        (checkout/'new-unstaged.txt').write_text('must not vanish\n')
        denied=mod.audit_and_apply(policy,True)
        assert denied['suspended']==0 and denied['dirty']==1 and checkout.exists(),denied
        (checkout/'new-unstaged.txt').unlink()
        # Simulate 20 days of inactivity after the latest checkout mutation.
        os.utime(checkout,(time.time()-20*86400,time.time()-20*86400))
        accepted=mod.audit_and_apply(policy,True)
        assert accepted['suspended']==1 and not checkout.exists(),accepted
        events=(mod.LOGDIR/'events.jsonl').read_text()
        assert 'refs/parked/lifecycle/' in events
        import json
        record=json.loads(events.splitlines()[-1])
        assert cmd('git','rev-parse',record['recovery_ref'],cwd=repo)==initial
        restored=root/'restored'
        cmd('git','worktree','add','--detach','-q',str(restored),record['recovery_ref'],cwd=repo)
        assert (restored/'file.txt').read_text()=='reviewed content\n'
        assert cmd('git','status','--porcelain',cwd=restored)==''
        print('PASS: audit; no removal on dirty data; exact recovery ref; restored tracked file')
if __name__=='__main__':run()
