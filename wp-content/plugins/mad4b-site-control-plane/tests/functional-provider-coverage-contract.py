#!/usr/bin/env python3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
discovery=(ROOT/'includes/class-mad4b-scp-plugin-discovery.php').read_text('utf-8')
registry=(ROOT/'includes/class-mad4b-scp-adapter-registry.php').read_text('utf-8')
ui=(ROOT/'includes/class-mad4b-scp-adapter-coverage-admin-ui.php').read_text('utf-8')
for marker in ['mad4b.provider-functional-coverage.v1','mad4b.provider-functional-coverage-item.v1','status_only_candidate','safety_blocked','adapter_missing','functional_coverage_report','authority_created']:
    assert marker in discovery, marker
assert 'mad4b/provider-functional-coverage' in registry
for marker in ['Functional Gaps','Functional coverage gaps','Next safe action','status_only_candidate','safety_blocked']:
    assert marker in ui, marker
for forbidden in ['$_POST','admin_post_','$wpdb->insert','$wpdb->update','$wpdb->delete']:
    assert forbidden not in ui, forbidden
print('mad4b.provider-functional-coverage.contract.v1: PASS')
