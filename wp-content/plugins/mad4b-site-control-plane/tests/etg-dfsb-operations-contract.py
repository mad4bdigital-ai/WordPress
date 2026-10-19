#!/usr/bin/env python3
"""ETG operation registry cannot bypass central staging/admin/reversibility."""
import json
from pathlib import Path
r=Path(__file__).resolve().parents[1]
v=json.loads((r/'config/operation-registry.json').read_text())
rows={x['id']:x for x in v['operations']}
for name,planner,executor in [
 ('wordpress.etg-dfsb.slot.apply','etg-dfsb/slot-plan','etg-dfsb/slot-apply'),
 ('wordpress.etg-dfsb.profile.apply','etg-dfsb/profile-diff','etg-dfsb/profile-apply')
]:
    x=rows.get(name)
    assert x is not None, f'Missing ETG operation {name}'
    assert x['planner']==planner and x['executor']==executor, 'planner/executor drift'
    assert x['risk']=='high' and x['owner_confirmation_required'] is True
    assert x['production_allowed'] is False and x['generic_mutation_dispatch'] is False
    assert x['pipeline_profile']=='governed_mutation_v1'
    assert x['retry_semantics']=='readback_before_retry'
assert v['default_mutation_policy']=='deny'
print('PASS ETG governed operation discoverability without publication or Production authority')
