#!/usr/bin/env python3
"""ACI01 fail-closed standalone Spec Kit validator. No runtime or authority claims."""
from pathlib import Path
from collections import Counter
import json
import re
import sys

ROOT = Path(__file__).resolve().parent
REQ = re.compile(r'ACI-[0-9]{3}')
TASK = re.compile(r'ACI-T[0-9]{4}')
GATE = re.compile(r'ACI-G(?:10|[0-9])')


def validate(root=ROOT):
    root = Path(root)
    faults = []
    try:
        manifest = json.loads((root / 'manifest.json').read_text(encoding='utf-8'))
    except (OSError, ValueError) as exc:
        return ['manifest_invalid:' + type(exc).__name__]
    expected = {
        'contract': 'mad4b.aci-os.spec-kit.v1',
        'extension_id': 'ACI01', 'parent_feature_id': '007',
        'parent_integration_pr': 258, 'status': 'SPEC_BACKLOG_ONLY',
        'authorizing': False, 'production_authorized': False,
        'release_closure_included': False, 'mutations_enabled': False,
        'no_parent_task_denominator_changes': True,
        'autonomy_ceiling_for_spec': 'NONE', 'task_status': 'OPEN',
    }
    for key, value in expected.items():
        if manifest.get(key) != value:
            faults.append('unsafe_or_missing_manifest:' + key)
    if not isinstance(manifest.get('required_documents'), list) or not isinstance(manifest.get('required_code'), list):
        return faults + ['required_file_inventory_invalid']
    required = manifest['required_documents'] + manifest['required_code']
    if len(set(required)) != len(required) or len(required) < 17:
        faults.append('required_file_coverage_invalid')
    for name in required:
        path = root / name
        if ('..' in Path(name).parts or Path(name).is_absolute()
                or not path.is_file() or path.is_symlink() or not path.stat().st_size):
            faults.append('missing_or_unsafe_file:' + name)
    if faults:
        return faults
    docs = {name: (root / name).read_text(encoding='utf-8') for name in manifest['required_documents']}
    reqs = manifest.get('requirement_ids')
    task_ids = manifest.get('task_ids')
    gate_ids = manifest.get('gate_ids')
    if not isinstance(reqs, list) or reqs != ['ACI-' + str(i).zfill(3) for i in range(1, 44)]:
        faults.append('requirements_inventory_invalid')
    if not isinstance(gate_ids, list) or gate_ids != ['ACI-G' + str(i) for i in range(11)]:
        faults.append('gate_inventory_invalid')
    if (not isinstance(task_ids, list) or len(task_ids) != manifest.get('task_count')
            or task_ids != ['ACI-T' + str(i).zfill(4) for i in range(1, len(task_ids)+1)] or len(task_ids) < 60):
        faults.append('task_inventory_invalid')
        task_ids = task_ids if isinstance(task_ids, list) else []
    rows = re.findall(r'^- \[([ x])\] (ACI-T\d{4}) \[([A-Z_]+)\] (.+)$', docs['tasks.md'], re.M)
    row_ids = [r[1] for r in rows]
    if row_ids != task_ids or any(checkbox != ' ' or status != 'OPEN' for checkbox, _, status, _ in rows):
        faults.append('task_status_or_count_invalid')
    task_position = {t: i for i, t in enumerate(task_ids)}
    for checkbox, task_id, status, detail in rows:
        refs = REQ.findall(detail)
        if not refs or any(ref not in reqs for ref in refs):
            faults.append('task_requirement_missing:' + task_id)
        dep_field = detail.split('dependencies: ', 1)
        if len(dep_field) != 2:
            faults.append('task_dependency_missing:' + task_id)
            continue
        dependencies = dep_field[1].split(';', 1)[0]
        for dep in TASK.findall(dependencies):
            if dep not in task_position or task_position[dep] >= task_position[task_id]:
                faults.append('task_dependency_cycle_or_unknown:' + task_id)
    declared = {r[0] for r in re.findall(r'^\| (ACI-\d{3}) \|', docs['traceability.md'], re.M)}
    if declared != set(reqs):
        faults.append('traceability_incomplete')
    for name in ('spec.md', 'architecture.md', 'data-model.md','acceptance.md','plan.md','contracts/native-relations-wpml.md'):
        if len(docs.get(name, '')) < 1500:
            faults.append('thin_document:' + name)
    for word in ('wpml', 'native', 'budget', 'evidence', 'publication', 'review'):
        if not any(word in s.lower() for s in docs.values()):
            faults.append('architecture_keyword_missing:' + word)
    for gate in gate_ids:
        if gate not in docs['plan.md'] or gate not in docs['acceptance.md'] or gate not in docs['tasks.md']:
            faults.append('gate_missing:' + gate)
    if 'SPEC_BACKLOG_ONLY' not in docs['README.md'] or 'authorizing=false' not in docs['README.md']:
        faults.append('readme_authority_claim_invalid')
    try:
        req_register = json.loads(docs['requirements.json'])
        gate_register = json.loads(docs['acceptance-gates.json'])
        system_map = json.loads(docs['system-map.json'])
    except (ValueError, TypeError, KeyError):
        return faults + ['machine_registry_invalid']
    if (req_register.get('contract') != 'mad4b.aci-os.requirement-registry.v1'
            or req_register.get('status') != 'SPEC_BACKLOG_ONLY'
            or req_register.get('authorizing') is not False):
        faults.append('requirement_registry_authority_invalid')
    rows = req_register.get('requirements')
    if (not isinstance(rows, list) or [x.get('id') for x in rows if isinstance(x, dict)] != reqs
            or len(rows) != len(reqs) or any(not isinstance(x, dict) or x.get('state') != 'OPEN'
            or x.get('evidence_class') != 'DESIGN_DERIVED'
            or not isinstance(x.get('description'), str) or not x.get('description')
            or not x.get('task_ids') or any(t not in task_ids for t in x.get('task_ids', []))
            or not x.get('gates') or any(g not in gate_ids for g in x.get('gates', [])) for x in rows)):
        faults.append('requirement_registry_coverage_invalid')
    if (gate_register.get('contract') != 'mad4b.aci-os.gate-registry.v1'
            or gate_register.get('status') != 'SPEC_BACKLOG_ONLY'
            or gate_register.get('authorizing') is not False):
        faults.append('gate_registry_authority_invalid')
    gates = gate_register.get('gates')
    if (not isinstance(gates, list) or [x.get('id') for x in gates if isinstance(x, dict)] != gate_ids
            or len(gates) != len(gate_ids)
            or any(not isinstance(x, dict) or x.get('status') != 'OPEN'
                or x.get('completion_claimed') is not False or x.get('authorizing') is not False
                or not x.get('task_ids') or any(t not in task_ids for t in x.get('task_ids', []))
                or not x.get('negative_cases') for x in gates)):
        faults.append('gate_registry_coverage_invalid')
    if (system_map.get('contract') != 'mad4b.aci-os.system-map.v1'
            or system_map.get('status') != 'SPEC_BACKLOG_ONLY'
            or system_map.get('authorizing') is not False
            or system_map.get('production_authorized') is not False
            or system_map.get('existing_authority_reuse_required') is not True):
        faults.append('system_map_authority_invalid')
    loops = system_map.get('loops')
    if (not isinstance(loops, list) or [x.get('id') for x in loops if isinstance(x, dict)]
            != ['discovery', 'evidence', 'content', 'validation', 'optimization']
            or any(not x.get('hard_stop') or not x.get('output') for x in loops)):
        faults.append('five_decision_loops_missing')
    shortcut = system_map.get('forbidden_shortcuts')
    if not isinstance(shortcut, list) or len(shortcut) < 4 or any(
            not isinstance(row, list) or len(row) != 2 or row[1] != 'governed_wordpress_dispatch'
            for row in shortcut):
        faults.append('forbidden_authority_shortcuts_missing')
    return faults


def main():
    errors = validate()
    for error in errors:
        print('ACI01 FAIL:', error, file=sys.stderr)
    if errors:
        return 1
    print('mad4b.aci-os.spec-kit.v1: PASS (spec-only, non-authorizing)')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
