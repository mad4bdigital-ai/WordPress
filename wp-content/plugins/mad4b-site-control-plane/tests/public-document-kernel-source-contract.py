#!/usr/bin/env python3
"""Prevent regression of the active, live-shared public singular kernel."""
from pathlib import Path
import json,re
root=Path(__file__).resolve().parents[1]
entry=(root/'mad4b-site-control-plane.php').read_text()
code=(root/'includes/class-mad4b-scp-public-document-kernel.php').read_text()
manifest=json.loads((root/'config/public-document-kernel-manifest-v1.json').read_text())
assert manifest['contract']=='mad4b.scp-public-document-kernel-manifest.v1'
assert manifest['default']=='off'
assert 'early_managed_credential_fence' in manifest['security_preconditions']
assert 'catalog_deactivation' in manifest['security_preconditions']
assert 'source_tour_highlights' in manifest['security_preconditions']
assert entry.count("require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-public-document-kernel.php';")==1
assert entry.count('MAD4B_SCP_Public_Document_Kernel::try_boot()')==1
start=entry.index("require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-public-document-kernel.php';")
end=entry.index("require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-route-registry.php';")
assert start<end
assert entry.index('unset( $mad4b_scp_early_zero_touch_reason );')<start
assert re.search(r'if\s*\(\s*MAD4B_SCP_Public_Document_Kernel::try_boot\(\)\s*\)\s*\{\s*return;\s*\}',entry[start:end])
for guard in ["if ( ! in_array( $mode, array( 'on', 'canary' ), true ) )", "if ( $mode === 'canary' && $environment !== 'staging' )",
              "! empty( $_COOKIE )", "MAD4B_SCP_Site_Profile::status()", "'verification_ready'"]:
    if guard == "'verification_ready'":continue
    assert guard in code,guard
assert len(code.splitlines())>125
print('PUBLIC_DOCUMENT_KERNEL_SOURCE_GUARD_PASS entrypoint, off-default, site-bound guard, class, manifest')
