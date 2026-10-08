#!/usr/bin/env python3
"""Pure, non-authorizing ACI01 dynamic dependency, plan and graph conformance.

This is a specification fixture/interpreter: never use its output as a grant,
provider certification, WordPress executor selection or deployment approval.
"""
from __future__ import annotations
import re
from urllib.parse import urlsplit


class SpecViolation(ValueError):
    pass


def require(condition, reason):
    if not condition:
        raise SpecViolation(reason)


def valid_scope(scope):
    require(isinstance(scope, dict), 'scope_not_object')
    for field in ('tenant_id', 'site_uuid', 'brand_id', 'market', 'locale',
                  'environment', 'runtime_generation', 'restore_epoch', 'canonical_origin'):
        require(field in scope, 'scope_missing_' + field)
    require(scope['environment'] in ('staging', 'production', 'development', 'disposable'),
            'environment_unknown')
    for field in ('tenant_id', 'brand_id', 'market', 'locale'):
        v = scope[field]
        require(isinstance(v, str) and bool(re.fullmatch(r'[a-zA-Z0-9_.-]{1,80}', v)),
                'invalid_scope_' + field)
    require(isinstance(scope['site_uuid'], str) and re.fullmatch(r'[0-9a-fA-F-]{36}', scope['site_uuid']),
            'site_uuid_invalid')
    require(isinstance(scope['runtime_generation'], str)
            and re.fullmatch(r'[0-9a-f]{64}', scope['runtime_generation']),
            'runtime_generation_invalid')
    require(type(scope['restore_epoch']) is int and 0 <= scope['restore_epoch'] <= 2**63 - 1,
            'restore_epoch_invalid')
    origin = scope['canonical_origin']
    require(isinstance(origin, str) and len(origin) <= 256, 'origin_invalid')
    url = urlsplit(origin)
    require(url.scheme == 'https' and bool(url.hostname)
            and not url.username and not url.password
            and not url.path.strip('/') and not url.query and not url.fragment,
            'origin_invalid')
    return True


def topological(nodes: dict[str, list[str]]):
    """Fail closed on missing dependencies, self-edges and any cycle."""
    require(isinstance(nodes, dict), 'dag_invalid')
    for name, deps in nodes.items():
        require(isinstance(name, str) and isinstance(deps, list), 'dag_node_invalid')
        require(len(deps) == len(set(deps)), 'dag_duplicate_edge:' + name)
        require(all(d in nodes and d != name for d in deps), 'dag_unknown_or_self_edge:' + name)
    order, state = [], {}
    def visit(node):
        if state.get(node) == 'processing':
            raise SpecViolation('dag_cycle:' + node)
        if state.get(node) == 'done':
            return
        state[node] = 'processing'
        for dep in nodes[node]:
            visit(dep)
        state[node] = 'done'
        order.append(node)
    for node in sorted(nodes):
        visit(node)
    return order


def validate_model(task_registry, gate_registry, requirement_registry, system_map):
    require(all(isinstance(x, dict) for x in (task_registry, gate_registry, requirement_registry, system_map)),
            'model_not_object')
    require(task_registry.get('contract') == 'mad4b.aci-os.task-registry.v2', 'task_contract_invalid')
    require(gate_registry.get('contract') == 'mad4b.aci-os.gate-registry.v2', 'gate_contract_invalid')
    require(requirement_registry.get('contract') == 'mad4b.aci-os.requirement-registry.v2',
            'requirements_contract_invalid')
    require(system_map.get('graph_contract') == 'mad4b.aci-os.dynamic-flow.v2', 'graph_contract_invalid')
    for registry in (task_registry, gate_registry, requirement_registry, system_map):
        require(registry.get('authorizing') is False, 'model_must_not_authorize')
    tasks = task_registry.get('tasks')
    gates = gate_registry.get('gates')
    reqs = requirement_registry.get('requirements')
    require(isinstance(tasks, list) and len(tasks) == 71, 'task_count_invalid')
    require(isinstance(gates, list) and len(gates) == 11, 'gate_count_invalid')
    require(isinstance(reqs, list) and len(reqs) == 43, 'requirement_count_invalid')
    def keyed(items, key, category):
        require(all(isinstance(x, dict) and isinstance(x.get(key), str) for x in items),
                category + '_row_invalid')
        keys = [x[key] for x in items]
        require(len(set(keys)) == len(keys), category + '_duplicate')
        return {x[key]: x for x in items}
    tmap, gmap, rmap = keyed(tasks, 'id', 'task'), keyed(gates, 'id', 'gate'), keyed(reqs, 'id', 'requirement')
    task_dag = {}
    for tid, t in tmap.items():
        require(t.get('status') == 'OPEN' and t.get('authorizing') is False
                and t.get('completion_claimed') is False and t.get('gate') in gmap,
                'task_invalid:' + tid)
        refs = t.get('requirement_ids')
        require(isinstance(refs, list) and len(refs) >= 1 and len(refs) == len(set(refs))
                and all(r in rmap for r in refs), 'task_requirements_invalid:' + tid)
        task_dag[tid] = t.get('execution_dependencies')
    topological(task_dag)
    gate_dag = {}
    for gid, g in gmap.items():
        require(g.get('status') == 'OPEN' and g.get('authorizing') is False
                and g.get('completion_claimed') is False, 'gate_authority_invalid:' + gid)
        local_tasks = [t['id'] for t in tasks if t['gate'] == gid]
        require(g.get('task_ids') == local_tasks, 'gate_task_mismatch:' + gid)
        refs = list(dict.fromkeys(r for t in tasks if t['gate'] == gid for r in t['requirement_ids']))
        require(g.get('requirement_ids') == refs, 'gate_requirement_mismatch:' + gid)
        cond = g.get('conditional_dependencies', [])
        require(isinstance(cond, list) and all(isinstance(x, dict)
                and x.get('gate') in gmap and x.get('when') == 'native_relation_in_scope' for x in cond),
                'unreviewed_conditional_dependency:' + gid)
        gate_dag[gid] = g.get('depends_on', []) + [x['gate'] for x in cond]
    topological(gate_dag)
    for rid, r in rmap.items():
        expected = [t['id'] for t in tasks if rid in t['requirement_ids']]
        gate_ids = list(dict.fromkeys(tmap[t]['gate'] for t in expected))
        require(expected and r.get('task_ids') == expected
                and r.get('gates') == gate_ids
                and r.get('state') == 'OPEN', 'requirement_reverse_mapping_invalid:' + rid)
    _validate_authority_dominance(system_map)
    return {'tasks': len(tasks), 'requirements': len(reqs), 'gates': len(gates),
            'task_dag': task_dag, 'gate_dag': gate_dag}


def _validate_authority_dominance(system_map):
    nodes = system_map.get('nodes')
    edges = system_map.get('edges')
    barriers = system_map.get('mandatory_authority_dominators')
    sink = system_map.get('effect_target')
    require(isinstance(nodes, list) and len(set(nodes)) == len(nodes)
            and sink == 'native_write_effect' and sink in nodes, 'write_sink_invalid')
    require(barriers == ['commit_guard', 'governed_wordpress_dispatch']
            and all(b in nodes for b in barriers), 'write_barrier_invalid')
    require(isinstance(edges, list) and len(edges) >= 12, 'graph_edges_invalid')
    pairs = []
    for row in edges:
        require(isinstance(row, dict) and row.get('source') in nodes
                and row.get('target') in nodes, 'graph_unknown_node')
        pairs.append((row['source'], row['target']))
    require(len(set(pairs)) == len(pairs), 'graph_duplicate_edge')
    sources = [n for n in nodes if n != sink and n not in barriers]
    for barrier in barriers:
        adjacency = {}
        for a, b in pairs:
            if a != barrier and b != barrier:
                adjacency.setdefault(a, []).append(b)
        for source in sources:
            queue, seen = [source], set()
            while queue:
                current = queue.pop()
                if current in seen:
                    continue
                seen.add(current)
                if current == sink:
                    raise SpecViolation('write_bypass:' + barrier + ':' + source)
                queue.extend(adjacency.get(current, []))
    require(('commit_guard', 'governed_wordpress_dispatch') in pairs
            and ('governed_wordpress_dispatch', sink) in pairs,
            'write_path_incomplete')


def compile_candidate(intent, scope, state, model, policy):
    """Pure, scope-aware eligibility preview. Never returns an authorization token."""
    valid_scope(scope)
    require(all(isinstance(x, dict) for x in (intent, state, model, policy)),
            'candidate_input_invalid')
    require(policy.get('contract') == 'mad4b.aci-os.dynamic-policy.v1'
            and policy.get('authorizing') is False
            and policy.get('autonomy_ceiling') == 'L0_SPEC_PREVIEW',
            'policy_non_authorizing_required')
    registry = model['tasks']
    gates = model['gates']
    reqs = model['requirements']
    graph = model['system_map']
    checked = validate_model(registry, gates, reqs, graph)
    taskmap = {x['id']: x for x in registry['tasks']}
    gatemap = {x['id']: x for x in gates['gates']}
    requested = intent.get('task_ids')
    require(isinstance(requested, list) and 0 < len(requested) <= len(taskmap)
            and len(set(requested)) == len(requested)
            and all(i in taskmap for i in requested), 'requested_tasks_invalid')
    profile = intent.get('content_profile', {})
    require(isinstance(profile, dict) and type(profile.get('native_relation_in_scope', False)) is bool,
            'content_profile_invalid')
    task_dependencies = checked['task_dag']
    selected = set()
    def expand(task):
        selected.add(task)
        for dep in task_dependencies[task]:
            if dep not in selected:
                expand(dep)
    for task in requested:
        expand(task)
    ordered = [t for t in topological(task_dependencies) if t in selected]
    current_capabilities = state.get('capabilities', {})
    completed = state.get('completed_tasks', [])
    gate_certificates = state.get('certified_gates', [])
    require(isinstance(current_capabilities, dict) and isinstance(completed, list)
            and isinstance(gate_certificates, list), 'runtime_observation_invalid')
    require(all(x in taskmap for x in completed) and all(x in gatemap for x in gate_certificates),
            'runtime_claim_unknown')
    reasons = []
    statuses = []
    needed_gates = set()
    for task_id in ordered:
        task = taskmap[task_id]
        gate_id = task['gate']
        gate = gatemap[gate_id]
        needed_gates.add(gate_id)
        for condition in gate.get('conditional_dependencies', []):
            if profile.get(condition['when'], False):
                needed_gates.add(condition['gate'])
        missing = [d for d in task['execution_dependencies'] if d not in completed]
        cap = current_capabilities.get(task['capability_selector'])
        if missing:
            status = 'WAITING_DEPENDENCIES'
            reason = 'unfinished_execution_dependencies'
        elif not isinstance(cap, dict) or cap.get('certified') is not True:
            status = 'NEEDS_EVIDENCE'
            reason = 'capability_not_certified'
        elif cap.get('runtime_generation') != scope['runtime_generation']:
            status = 'NEEDS_EVIDENCE'
            reason = 'stale_capability_generation'
        else:
            status = 'READY_FOR_NON_AUTHORITATIVE_PLAN'
            reason = 'simulation_only'
        statuses.append({'task_id': task_id, 'state': status, 'reason': reason})
        if status != 'READY_FOR_NON_AUTHORITATIVE_PLAN':
            reasons.append(reason)
    if scope['environment'] == 'production':
        reasons.append('production_authority_out_of_scope')
    if any(x not in gate_certificates for x in needed_gates):
        reasons.append('certification_gate_incomplete')
    if intent.get('effect_class') in ('PAID', 'WORDPRESS_WRITE', 'HOST_WRITE'):
        reasons.append('effect_requires_separate_MAD4B_authority')
    if intent.get('effect_class') == 'PAID' and state.get('account_budget_reserved') is not True:
        reasons.append('account_cost_reservation_unverified')
    result_status = 'READY_FOR_NON_AUTHORITATIVE_PLAN' if not reasons else (
        'DENIED' if scope['environment'] == 'production' or
        'account_cost_reservation_unverified' in reasons else
        'WAITING_DEPENDENCIES' if 'unfinished_execution_dependencies' in reasons else
        'NEEDS_EVIDENCE')
    return {'contract': 'mad4b.aci-os.dynamic-candidate.v1',
            'status': result_status, 'scope_site_uuid': scope['site_uuid'],
            'environment': scope['environment'], 'execution_order': ordered,
            'applicable_certification_gates': sorted(needed_gates),
            'tasks': statuses, 'reason_codes': sorted(set(reasons)),
            'authorizing': False, 'eligible_for_mutation': False,
            'credentials_issued': False, 'mutation_performed': False}
