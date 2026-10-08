#!/usr/bin/env python3
"""ACI01 v2: fail-closed cross-file, dynamic graph and project clarity validator.

Only validates inert Spec Kit artifacts; no WordPress, model, external provider,
budget, deployment, approval, network or runtime effect is ever executed.
"""
from __future__ import annotations
import importlib.util
import json
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parent


def _read_json(path):
    return json.loads(path.read_text(encoding='utf-8'))


def _dynamic(root):
    spec = importlib.util.spec_from_file_location('aci_dynamic_core', root / 'dynamic_core.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def validate(root=ROOT):
    root = Path(root)
    faults = []
    try:
        manifest = _read_json(root / 'manifest.json')
    except (OSError, ValueError, TypeError) as error:
        return ['manifest_invalid:' + type(error).__name__]
    expected = {
        'contract': 'mad4b.aci-os.spec-kit.v1', 'extension_id': 'ACI01',
        'parent_feature_id': '007', 'parent_integration_pr': 258,
        'status': 'SPEC_BACKLOG_ONLY', 'design_review_status': 'DESIGN_REVIEW_REQUIRED',
        'spec_architecture_generation': 'DYNAMIC_BY_DEFAULT_V2',
        'authorizing': False, 'production_authorized': False, 'release_closure_included': False,
        'mutations_enabled': False, 'execution_enabled': False, 'spec_only': True,
        'no_parent_task_denominator_changes': True, 'autonomy_ceiling_for_spec': 'NONE',
        'task_status': 'OPEN',
    }
    for field, value in expected.items():
        if manifest.get(field) != value:
            faults.append('unsafe_or_missing_manifest:' + field)
    required_docs = manifest.get('required_documents')
    required_code = manifest.get('required_code')
    if not isinstance(required_docs, list) or not isinstance(required_code, list):
        return faults + ['required_file_inventory_invalid']
    paths = required_docs + required_code
    core = {
        'README.md', 'project-charter.md', 'dynamic-foundation.md', 'dynamic-policy.json',
        'content-recipes.json', 'domain-fact-authority.json', 'use-cases.json',
        'task-registry.json', 'requirements.json', 'acceptance-gates.json', 'system-map.json',
        'effect-contracts.json', 'evidence-trust.json', 'release-profiles.json',
        'ledger-contracts.json', 'optimization-policy.json', 'disposition-rules.json',
        'tasks.md', 'traceability.md', 'spec.md', 'architecture.md', 'data-model.md',
        'plan.md', 'acceptance.md', 'ui.md', 'operations.md',
        'validate.py', 'test_validate.py', 'dynamic_core.py', 'test_dynamic_core.py',
        'design_closure.py', 'test_design_closure.py',
    }
    if not core.issubset(paths) or len(paths) != len(set(paths)):
        faults.append('required_file_coverage_invalid')
    for name in paths:
        if (not isinstance(name, str) or not name or Path(name).is_absolute()
                or '..' in Path(name).parts or '\\' in name or name.startswith('.')):
            faults.append('unsafe_path_declared:' + repr(name))
            continue
        p = root / name
        if not p.is_file() or p.is_symlink() or p.stat().st_size == 0:
            faults.append('missing_or_unsafe_file:' + name)
    if faults:
        return faults
    docs = {n:(root / n).read_text(encoding='utf-8') for n in required_docs}
    machine_names = ('task-registry.json', 'requirements.json', 'acceptance-gates.json',
                     'system-map.json', 'dynamic-policy.json', 'content-recipes.json',
                     'domain-fact-authority.json', 'use-cases.json',
                     'effect-contracts.json', 'evidence-trust.json',
                     'release-profiles.json', 'ledger-contracts.json',
                     'optimization-policy.json', 'disposition-rules.json')
    try:
        machine = {name:json.loads(docs[name]) for name in machine_names}
    except (ValueError, KeyError, TypeError):
        return ['machine_registry_invalid']
    tasks = machine['task-registry.json']
    reqs = machine['requirements.json']
    gates = machine['acceptance-gates.json']
    graph = machine['system-map.json']
    policy = machine['dynamic-policy.json']
    recipes = machine['content-recipes.json']
    facts = machine['domain-fact-authority.json']
    cases = machine['use-cases.json']
    if (tasks.get('contract') != 'mad4b.aci-os.task-registry.v2'
            or tasks.get('authorizing') is not False or len(tasks.get('tasks', [])) != 71):
        faults.append('task_registry_contract_invalid')
    if (reqs.get('contract') != 'mad4b.aci-os.requirement-registry.v2'
            or reqs.get('authorizing') is not False):
        faults.append('requirement_registry_authority_invalid')
    if (gates.get('contract') != 'mad4b.aci-os.gate-registry.v2'
            or gates.get('authorizing') is not False):
        faults.append('gate_registry_authority_invalid')
    if (graph.get('graph_contract') != 'mad4b.aci-os.dynamic-flow.v2'
            or graph.get('authorizing') is not False or graph.get('production_authorized') is not False):
        faults.append('system_map_authority_invalid')
    if (policy.get('contract') != 'mad4b.aci-os.dynamic-policy.v1'
            or policy.get('principle') != 'DYNAMIC_BY_DEFAULT'
            or policy.get('autonomy_ceiling') != 'L0_SPEC_PREVIEW'
            or policy.get('authorizing') is not False):
        faults.append('dynamic_policy_invalid')
    if (recipes.get('contract') != 'mad4b.aci-os.content-recipes.v1'
            or recipes.get('authorizing') is not False
            or recipes.get('default_content_recipe') is not None):
        faults.append('recipe_policy_invalid')
    content_recipes = recipes.get('recipes', [])
    recipe_ids = [r.get('id') for r in content_recipes if isinstance(r, dict)]
    if (len(recipe_ids) != 6 or len(set(recipe_ids)) != len(recipe_ids)
            or set(recipe_ids) != {'article','destination','tour','taxonomy','landing','comparison'}):
        faults.append('content_recipe_coverage_invalid')
    if (facts.get('contract') != 'mad4b.aci-os.domain-fact-authority.v1'
            or facts.get('authorizing') is not False
            or any(x.get('site_uuid') is not None for x in facts.get('profiles', []))):
        faults.append('domain_fact_source_policy_invalid')
    scenarios = cases.get('use_cases', [])
    scenario_ids = [x.get('id') for x in scenarios if isinstance(x, dict)]
    allowed_states = set(policy.get('plan_states', []))
    if (cases.get('contract') != 'mad4b.aci-os.use-case-acceptance.v1'
            or cases.get('authorizing') is not False
            or len(scenarios) < 25 or len(scenario_ids) != len(scenarios)
            or len(set(scenario_ids)) != len(scenario_ids)
            or any(x.get('recipe_id') not in recipe_ids or x.get('expected_state') not in allowed_states
                   or x.get('authorizing') is not False
                   or not x.get('negative_case') for x in scenarios)):
        faults.append('use_case_coverage_invalid')
    req_ids = manifest.get('requirement_ids')
    task_ids = manifest.get('task_ids')
    gate_ids = manifest.get('gate_ids')
    expected_req = ['ACI-' + str(i).zfill(3) for i in range(1, 44)]
    expected_task = ['ACI-T' + str(i).zfill(4) for i in range(1, 72)]
    expected_gate = ['ACI-G' + str(i) for i in range(11)]
    if req_ids != expected_req:
        faults.append('requirements_inventory_invalid')
    if task_ids != expected_task or manifest.get('task_count') != 71:
        faults.append('task_inventory_invalid')
    if gate_ids != expected_gate:
        faults.append('gate_inventory_invalid')
    try:
        dynamic = _dynamic(root)
        dynamic.validate_model(tasks, gates, reqs, graph)
    except Exception as error:
        reason = str(error)
        if 'requirement_' in reason:
            faults.append('requirement_registry_coverage_invalid')
        elif 'gate_' in reason or 'unreviewed_conditional' in reason:
            faults.append('gate_registry_coverage_invalid')
        else:
            faults.append('dynamic_model_invalid')
        faults.append('dynamic_model_detail:' + reason)
    for title, registry, declared, category in (
        ('task-registry',tasks,task_ids,'task'),('requirement-registry',reqs,req_ids,'requirement'),
        ('gate-registry',gates,gate_ids,'gate')):
        rows = registry.get(category+'s', [])
        if not isinstance(rows, list) or [x.get('id') for x in rows] != declared:
            faults.append(title + '_inventory_mismatch')
    # Generated markdown must reflect the exact machine registry, not a broad
    # gate-level synthetic requirement mapping.
    md_tasks = re.findall(
        r'^- \[([ x])\] (ACI-T\d{4}) \[([A-Z_]+)\] (.+?) — requirements: ([^;]+); dependencies: ([^;]+); exit: ([^\n]+)$',
        docs['tasks.md'], re.M)
    actual_tasks = tasks.get('tasks', [])
    if len(md_tasks) != len(actual_tasks):
        faults.append('task_status_or_count_invalid')
    else:
        for row, task in zip(md_tasks,actual_tasks):
            checkbox, task_id, status, title, refs, predecessors, exit_text = row
            if (checkbox != ' ' or status != 'OPEN' or task_id != task['id']
                    or title != task['title']
                    or [x.strip() for x in refs.split(',')] != task['requirement_ids']
                    or ([] if predecessors == 'none' else [x.strip() for x in predecessors.split(',')])
                       != task['execution_dependencies']
                    or task['expected_evidence_contract'] not in exit_text):
                faults.append('task_status_or_count_invalid:' + task['id'])
    md_reqs = re.findall(
        r'^\| (ACI-\d{3}) \| ([^|]+) \| ([^|]+) \| ([^|]+) \|$',
        docs['traceability.md'], re.M)
    if len(md_reqs) != len(reqs.get('requirements', [])):
        faults.append('traceability_incomplete')
    else:
        for row, requirement in zip(md_reqs,reqs['requirements']):
            rid, related_gates, related_tasks, evidence = row
            if (rid != requirement['id']
                    or [x.strip() for x in related_gates.split(',')] != requirement['gates']
                    or [x.strip() for x in related_tasks.split(',')] != requirement['task_ids']):
                faults.append('traceability_incomplete:' + rid)
    loops = graph.get('loops', [])
    if ([x.get('id') for x in loops] !=
            ['discovery','evidence','content','validation','optimization']):
        faults.append('five_decision_loops_missing')
    for gid in expected_gate:
        if not all(gid in docs[p] for p in ('tasks.md','plan.md','acceptance.md')):
            faults.append('gate_missing:' + gid)
    if ('DESIGN_REVIEW_REQUIRED' not in docs['project-charter.md']
            or 'Dynamic' not in docs['dynamic-foundation.md']
            or 'authorizing=false' not in docs['README.md']):
        faults.append('project_charter_or_dynamic_principle_missing')
    try:
        import importlib.util
        closure_path = root / 'design_closure.py'
        spec = importlib.util.spec_from_file_location('aci_design_closure', closure_path)
        require_module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(require_module)
        bundle = {
            'effects': machine['effect-contracts.json'],
            'trust': machine['evidence-trust.json'],
            'release': machine['release-profiles.json'],
            'ledgers': machine['ledger-contracts.json'],
            'optimization': machine['optimization-policy.json'],
            'recipes': recipes,
            'facts': facts,
            'cases': cases,
            'rules': machine['disposition-rules.json'],
            'policy': policy,
            'task': tasks,
            'gates': gates,
            'requirements': reqs,
            'system': graph,
        }
        require_module.check_contracts(bundle)
    except Exception as error:
        faults.append('design_closure_contract_invalid')
        faults.append('design_closure_detail:' + str(error))
    return faults


def main():
    errors = validate()
    for issue in errors:
        print('ACI01 SPEC FAIL:', issue, file=sys.stderr)
    if errors:
        return 1
    print('mad4b.aci-os.spec-kit.v2: PASS (structure only, NOT design freeze or deployment)')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
