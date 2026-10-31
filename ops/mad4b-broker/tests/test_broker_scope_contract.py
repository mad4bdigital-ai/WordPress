#!/usr/bin/env python3
"""Pure isolated contract checks for exact-scope Broker preflight and fixed canary."""
import ast
from pathlib import Path
import types

ROOT=Path(__file__).resolve().parents[1]
source=(ROOT/'broker-native-ssh-reconcile.py').read_text()
canary=(ROOT/'vps-broker-remote-ssh-canary.py').read_text()

def pure_function(text,name,inject):
    tree=ast.parse(text)
    funcs=[node for node in tree.body if isinstance(node,(ast.FunctionDef,ast.AsyncFunctionDef)) and node.name==name]
    assert len(funcs)==1,(name,'missing')
    namespace=dict(inject)
    exec(compile(ast.Module(body=funcs,type_ignores=[]),'<isolated-contract>','exec'),namespace)
    return namespace[name]

blockers=pure_function(source,'claim_scope_blockers',{})
assert blockers([],0)==['NO_CURRENT_EXACT_ELIGIBLE_JOB']
assert blockers([],None)==['CENTRAL_CLAIM_INVENTORY_UNAVAILABLE']
assert blockers([{'verified':True}],1)==[]
assert blockers([],1)==['CENTRAL_REMOTE_TARGET_SCOPE_BINDING_PENDING','NO_CURRENT_EXACT_ELIGIBLE_JOB']

class Clock:
    @staticmethod
    def time(): return 1000

hosts={'hosting-3':{'username':'registered-user'},'hosting-1':{'username':'other-user'}}
registry=types.SimpleNamespace(HOSTS=hosts)
fn=pure_function(canary,'eligible_shape',{
    'registry':registry,'HEX32':__import__('re').compile('^[a-f0-9]{32}$'),
    'COMMAND_SHA':'c'*64,'time':Clock,
})
claim={'job_id':'a'*32,'target_id':'hosting-3','command_sha256':'c'*64}
proof={'contract':'mad4b.broker-exact-ssh-authority.v1','eligible':True,'verified':True,
    'central_scope_only':True,'shell_executed':False,'job_id':'a'*32,'target_id':'hosting-3',
    'command_sha256':'c'*64,'credential_ref':'vault:registry/hosting-3',
    'host_principal':'registered-user','grant_id':'b'*32,'company':'scoped-company',
    'expires_epoch':1200}
assert fn(claim,proof)
for bad in [
    {**proof,'eligible':False},{**proof,'target_id':'hosting-1'},
    {**proof,'shell_executed':True},{**proof,'central_scope_only':False},
    {**proof,'command_sha256':'d'*64},{**proof,'expires_epoch':1040},
    {**proof,'credential_ref':'untrusted'},{**proof,'host_principal':'wrong-user'},
    {**proof,'grant_id':''},{**proof,'company':''}
]:
    assert not fn(claim,bad),bad

assert "claimed','--limit=1'" in canary
assert "COMMAND='printf MAD4B_REGISTRY_SSH_READ_CANARY'" in canary
assert "proof=verifier.verify(job,target,COMMAND_SHA)" in canary
assert "except FileExistsError:return {'state':'ALREADY_ATTEMPTED_NO_REPLAY'}" in canary
assert "'mad4b','broker-remote-ssh','receipt'" in canary
assert "REMOTE_EXECUTOR_NOT_ENROLLED_FOR_TARGET" in source
assert "CENTRAL_GRANT_VERIFIER_NOT_BOUND" in source
print("PASS broker precise idle/inventory/scope blockers and fixed signed remote canary (20+ assertions)")
