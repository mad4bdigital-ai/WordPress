#!/usr/bin/env python3
"""Contract for inert, removable Local Git MU canary (never production)."""
import ast
from pathlib import Path
root=Path(__file__).resolve().parents[4]
p=root/"ops/central-rollout/mu-local-git-staging-canary.py"
s=p.read_text()
ast.parse(s)
for value in [
 'NAME="999-mad4b-native-mu-git-canary.php"',
 '"release_scope":"staging_mu_test_only"',
 '"production_authorized":False',
 'TARGET.unlink();installed=False',
 'check_state(False)',
 'check_state(True,digest)',
 'if TARGET.exists() or TARGET.is_symlink()',
 'if sys.argv[1:] not in (["preflight"],["test-local-mu-canary"])',
]:
    assert value in s, "MU canary safety rule missing: "+value
for forbidden in ['"company_production"', 'wayfatours-production', 'shell=True', '--allow-root']:
    assert forbidden not in s, "MU must not dispatch to Production"
print("MAD4B_LOCAL_GIT_MU_CANARY_CONTRACT: PASS")
