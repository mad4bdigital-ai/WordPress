import hashlib, json, pathlib
root = pathlib.Path(__file__).resolve().parents[1]
manifest = json.loads((root / 'manifest.json').read_text())
assert manifest['deployment_auto_enabled'] is False
paths = set()
for row in manifest['files']:
    p = root / row['path']
    assert p.is_file() and row['path'] not in paths, row['path']
    paths.add(row['path'])
    assert p.stat().st_size == row['bytes'], row['path']
    assert hashlib.sha256(p.read_bytes()).hexdigest() == row['sha256'], row['path']
assert len(paths) == 3
print('PASS: FX resilience source manifest (3/3)')
