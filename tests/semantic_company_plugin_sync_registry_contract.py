#!/usr/bin/env python3
"""PR655+PR671 semantic convergence: optional remote queue, never inferred authority."""
import json
from pathlib import Path
p=Path(__file__).resolve().parents[1]/'wp-content/plugins/mad4b-site-control-plane/config/operation-registry.json'
d=json.loads(p.read_text());rows=[v for v in d['operations'] if v['id']=='company.plugin-sync.request'];assert len(rows)==1
r=rows[0]
assert r['required_runtime'] is False
assert r['generic_mutation_dispatch'] is False
assert r['supports']==['queue_plan_only']
assert r['side_effect_class']=='central_journal_plan_only'
assert r['retry_semantics']=='readback_before_retry'
assert r['planner']=='mad4b/company-plugin-sync-plan'
assert r['executor']=='mad4b/company-plugin-sync-request'
assert r['durable_resume'] is True
print('SEMANTIC_COMPANY_PLUGIN_SYNC_REGISTRY: PASS optional exact queue + readback no generic mutation')
