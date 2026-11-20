from pathlib import Path
import importlib.util
p=Path(__file__).resolve().parents[1]/'reconcile.py'
spec=importlib.util.spec_from_file_location('reconcile',p);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
source=m.ANCHOR+"\nlet fcpGain=150;const pass = " + m.OLD + ';'
target=m.transform(source)
assert m.OLD not in target
assert target.count(m.NEW)==1 and target.count(m.HELPER)==1
assert m.transform(target)==target
for bad in ('',source.replace(m.OLD,'true'),source+m.OLD):
    try:m.transform(bad)
    except ValueError:pass
    else:raise AssertionError('unrecognized execution contract accepted')
# Preserve the identical, strict post-FCP visual and interaction requirements.
assert '&&mad4bFcpGate(' in target
print('PASS: unchanged verified source, explicit breakpoint-scoped FCP contract')
