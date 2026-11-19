#!/usr/bin/env python3
"""Non-authorizing source regression for mixed-class recovery state isolation.

The actual two-request WordPress+MU-bootstrap integration remains mandatory
inside Connection Governance. This executable source regression cannot claim
that runtime acceptance or exercise any privilege.
"""
from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[1]
p=ROOT/'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-mcp-runtime-conflict-guard.php'
s=p.read_text(encoding='utf-8')
first=ROOT/'wp-content/plugins/mad4b-site-control-plane/tests/runtime-mcp-mixed-class-first-request.php'
second=ROOT/'wp-content/plugins/mad4b-site-control-plane/tests/runtime-mcp-mixed-class-recovered.php'
checks=[
    ("actual mixed class classification", "$mixed = 'mixed_runtime' === ( $status['runtime_class_provenance_state'] ?? '' )" in s),
    ("positive failure provenance", "! empty( $status['runtime_class_provenance_failure_count'] )" in s),
    ("preventive is governed", "$preventive = $preventive && class_exists( 'MAD4B_SCP_MCP_Runtime_Recovery', false ) && MAD4B_SCP_MCP_Runtime_Recovery::active()" in s),
    ("visitor no repair hotpath", "MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()" in s),
    ("canonical official runtime required", "! empty( $status['runtime_from_official_plugin'] )" in s),
    ("canonical certified class set required", "'certified_class_set' === ( $status['runtime_class_provenance_state'] ?? '' )" in s),
    ("canonical exact zero provenance failures", "0 === (int) ( $status['runtime_class_provenance_failure_count'] ?? -1 )" in s),
    ("canonical pin evidence", "! empty( $status['mu_bootstrap_critical_class_set_pinned'] )" in s and "! empty( $status['mu_bootstrap_canonical_symbols_pinned'] )" in s),
    ("positive canonical readback", "$status['state'] = $canonical ? 'canonical_runtime'" in s),
    ("not claiming live acceptance", "source_provenance_observational" in s),
    ("independent first-request integration present", "mad4b.mcp-mixed-class-repair-first-request.v1: PASS" in first.read_text()),
    ("independent recovered integration present", "mad4b.mcp-mixed-class-repair-recovered.v1: PASS" in second.read_text()),
]
failed=[name for name,ok in checks if not ok]
if failed:raise AssertionError('MCP_MIXED_RUNTIME_SOURCE_CONTRACT_FAIL: '+', '.join(failed))
print('MCP_MIXED_RUNTIME_SOURCE_CONTRACT: PASS checks='+str(len(checks))+' (source-only, no live acceptance)')
print('PASS: MCP recovery source invariants '+str(len(checks))+' reviewed checks (real WordPress acceptance separate)')
