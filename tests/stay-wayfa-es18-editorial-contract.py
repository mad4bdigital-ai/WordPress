#!/usr/bin/env python3
"""Static scope guard for one-time Wayfatours WPML editorial translation CLI."""
from pathlib import Path
import json
import re
s=(Path(__file__).resolve().parents[1]/'scripts/mad4b-stay-wayfatours-es18-editorial-cli.php').read_text()
m=re.search(r"\$descs=json_decode\('([^']+)'\s*,\s*true",s)
assert m, 'missing literal bounded hotel translations'
descs=json.loads(m.group(1))
assert len(descs)==18, len(descs)
required={70732,36475,36316,36197,36209,36479,36473,36474,36477,36140,70733,36355,70734,70735,70736,70737,70738,70739}
assert {int(i) for i in descs}==required
assert all(len(s)>75 for s in descs.values())
for guard in ['https://wayfatours.com','post_properties','source_language_code','wpml_set_element_language_details',
              'package_levels','catalog_activated','property-rates','verify','preflight_blocked',
              'unit_max_occupancy' if False else 'commercial_ready']:
    assert guard in s,guard
for unsafe in ['wc_create_order(', 'update_option(', 'delete_option(', 'flush_rewrite_rules(',
               'wp_delete_post(', 'wp_set_post_terms(', 'wp_schedule_event(']:
    assert unsafe not in s,unsafe
assert 'wp_insert_post(' in s and 'wp_update_post(' in s
assert "['plan','stage','publish','verify']" in s
assert s.index('if($errors)')<s.index('wp_insert_post(')
print('PASS Wayfatours exact-18 ES editorial source, two-phase writes, no commerce activation')
