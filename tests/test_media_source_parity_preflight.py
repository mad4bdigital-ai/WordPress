"""Synthetic ownership/source test: refuse releases without exact file maps."""
import importlib.util
from pathlib import Path
import tempfile
import hashlib
import json

MODULE=Path(__file__).resolve().parents[1]/'tools/mad4b_media_source_parity.py'
spec=importlib.util.spec_from_file_location('mad4b_media_source_parity',MODULE)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

with tempfile.TemporaryDirectory() as tmp:
    base=Path(tmp);live=base/'live';tracked=base/'git';source=base/'source'
    for root in [live,tracked,source]:(root/'mu-plugins').mkdir(parents=True)
    rel='mu-plugins/mad4b-photo-engine.php'
    for root in [live,tracked,source]:(root/rel).write_bytes(b'<?php\n'+b'/** review only */\n'*40)
    ok=m.audit(live,tracked,source)
    assert ok['status']=='exact_git_runtime_parity'
    assert ok['media_modules_examined']==1
    (tracked/rel).write_bytes(b'<?php\n'+b'/** old release */\n'*40)
    different=m.audit(live,tracked,source)
    assert different['status']=='hold_for_owner_aware_reconciliation'
    assert different['git_parity']['unreconciled_git_runtime']==1
    (tracked/rel).unlink()
    missing=m.audit(live,tracked,source)
    assert missing['git_parity']['unmapped_git_owner']==1
    (tracked/rel).write_bytes((live/rel).read_bytes())
    (source/rel).write_bytes(b'<?php\n'+b'/** source different */\n'*40)
    src=m.audit(live,tracked,source)
    assert src['status']=='exact_git_runtime_parity'
    assert src['source_parity']['source_differs_from_live']==1
    assert src['source_copy_and_grants_issued'] is False
    assert m.main(['--live-wp-content',str(live),'--git-wp-content',str(tracked),'--require-parity'])==0
    overlay=base/'site-overlay';mirror=overlay/'wp-content'/rel;mirror.parent.mkdir(parents=True)
    mirror.write_bytes((live/rel).read_bytes())
    data={'contract':'mad4b.wayfatours.production-media-runtime-source-mirror.v1','total_files':1,
          'source_files':[{'relative_path':rel,'sha256':hashlib.sha256(mirror.read_bytes()).hexdigest()}]}
    (overlay/'manifest.json').write_text(json.dumps(data))
    assert m.verify_company_overlay(live,overlay)['status']=='exact_live_source_mirror'
    assert m.main(['--live-wp-content',str(live),'--git-wp-content',str(tracked),
                   '--company-overlay',str(overlay),'--require-overlay-match'])==0
    mirror.write_bytes(b'changed')
    assert m.verify_company_overlay(live,overlay)['status']=='mirror_drift'
    assert m.main(['--live-wp-content',str(live),'--git-wp-content',str(tracked),
                   '--company-overlay',str(overlay),'--require-overlay-match'])==5

print('PASS media release parity refuses missing and diverged Git without touching live')
