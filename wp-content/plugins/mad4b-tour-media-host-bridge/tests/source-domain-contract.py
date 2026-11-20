#!/usr/bin/env python3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
CORE=ROOT.parent/'mad4b-site-control-plane'
bootstrap=(ROOT/'mad4b-tour-media-host-bridge.php').read_text()
assert "post_type_exists('tours-and-activities')" in bootstrap
assert "class_exists('MAD4B_SCP_Host_Agent_Bridge', false)" in bootstrap
assert "}, 99);" in bootstrap
assert 'update_option' not in bootstrap and 'add_option' not in bootstrap
mods=list((ROOT/'includes').glob('*.php'))
assert len(mods)==4
for f in mods:
    data=f.read_text()
    assert "defined('ABSPATH')" in data
    assert 'MAD4B_SCP_Host_Agent_Bridge::active_for' in data or 'Host_Agent_Bridge' in data
    assert not (CORE/'includes'/f.name).exists()
print('PASS opt-in tourism addon boundary, no core business defaults')
