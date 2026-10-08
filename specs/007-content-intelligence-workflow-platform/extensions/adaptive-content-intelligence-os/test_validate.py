#!/usr/bin/env python3
"""Fail-closed mutation and reproducibility tests for the ACI01 Spec Kit."""
from pathlib import Path
from tempfile import TemporaryDirectory
import importlib.util
import json
import shutil

ROOT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('aci_validate', ROOT / 'validate.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
assert m.validate() == [], m.validate()
original = json.loads((ROOT / 'manifest.json').read_text(encoding='utf-8'))
assert original['status'] == 'SPEC_BACKLOG_ONLY'
assert original['authorizing'] is False and original['mutations_enabled'] is False
assert original['no_parent_task_denominator_changes'] is True

with TemporaryDirectory() as temp:
    root = Path(temp)
    for file in original['required_documents'] + original['required_code'] + ['manifest.json']:
        dest = root / file
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT / file, dest)
    assert m.validate(root) == []
    def mutate_manifest(fn, expected):
        data = json.loads((root / 'manifest.json').read_text(encoding='utf-8'))
        fn(data)
        (root / 'manifest.json').write_text(json.dumps(data), encoding='utf-8')
        assert expected in m.validate(root), (expected, m.validate(root))
        (root / 'manifest.json').write_text(json.dumps(original), encoding='utf-8')
    mutate_manifest(lambda d: d.update(authorizing=True), 'unsafe_or_missing_manifest:authorizing')
    mutate_manifest(lambda d: d.update(status='DONE'), 'unsafe_or_missing_manifest:status')
    mutate_manifest(lambda d: d.update(production_authorized=True), 'unsafe_or_missing_manifest:production_authorized')
    mutate_manifest(lambda d: d['task_ids'].append('ACI-T9999'), 'task_inventory_invalid')
    mutate_manifest(lambda d: d['requirement_ids'].pop(), 'requirements_inventory_invalid')
    mutate_manifest(lambda d: d['gate_ids'].pop(), 'gate_inventory_invalid')
    source = (root / 'tasks.md').read_text(encoding='utf-8')
    (root / 'tasks.md').write_text(source.replace('[OPEN]', '[DONE]', 1), encoding='utf-8')
    assert 'task_status_or_count_invalid' in m.validate(root)
    (root / 'tasks.md').write_text(source, encoding='utf-8')
    (root / 'contracts/native-relations-wpml.md').unlink()
    assert any(s.startswith('missing_or_unsafe_file:') for s in m.validate(root))
print('mad4b.aci-os.spec-kit.tests.v1: PASS')
