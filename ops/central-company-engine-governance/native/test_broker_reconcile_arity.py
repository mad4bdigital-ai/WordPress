#!/usr/bin/env python3
"""Static CI regression for the original Staging broker reconcile contract.

AST only: never imports the privileged broker or runs SSH/WordPress.
"""
import ast
from pathlib import Path

source=Path(__file__).with_name("broker-native-ssh-reconcile.py")
tree=ast.parse(source.read_text(encoding="utf-8"),filename=str(source))
fn=next((x for x in tree.body if isinstance(x,ast.FunctionDef)
         and x.name=="current_signed_target_scopes"),None)
assert fn is not None,"Original claimed-job scope function missing"
returns=[x for x in ast.walk(fn) if isinstance(x,ast.Return)]
assert len(returns)>=3
for x in returns:
    assert isinstance(x.value,ast.Tuple),"Scope function must return a pair"
    assert len(x.value.elts)==2,"Unexpected broker return arity"
measured=next(x for x in tree.body if isinstance(x,ast.FunctionDef)
              and x.name=="measured")
binds=[x for x in ast.walk(measured)
       if isinstance(x,ast.Assign)
       and isinstance(x.value,ast.Call)
       and isinstance(x.value.func,ast.Name)
       and x.value.func.id=="current_signed_target_scopes"]
assert len(binds)==1
assert len(binds[0].targets)==1
assert isinstance(binds[0].targets[0],ast.Tuple)
assert len(binds[0].targets[0].elts)==2
print("BROKER_NATIVE_SCOPE_TUPLE_PAIR: PASS; source not executed")
