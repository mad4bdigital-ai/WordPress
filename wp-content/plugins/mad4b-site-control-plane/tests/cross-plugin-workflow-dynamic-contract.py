#!/usr/bin/env python3
"""Ensure candidate CI is data-driven and no workflow step can promote deployment."""
import json
from pathlib import Path
root=Path(__file__).resolve().parents[4]
workflow=(root/'.github/workflows/mad4b-cross-plugin-candidate.yml').read_text()
selector=(root/'tools/mad4b-cross-plugin-candidates.py').read_text()
registry=json.loads((root/'wp-content/plugins/mad4b-site-control-plane/config/cross-plugin-sources.json').read_text())
assert 'etg-dynamic-filter-seo-bridge' not in workflow
assert 'fromJSON(needs.discover-candidates.outputs.matrix)' in workflow
assert 'needs: discover-candidates' in workflow
assert workflow.count('uses: actions/checkout@v4')==2
assert workflow.count('ref: ${{ github.event.pull_request.head.sha || github.sha }}')==2
assert 'github.event.pull_request.number || github.ref' in workflow
assert 'cancel-in-progress: true' in workflow
assert 'git -C candidate -c protocol.version=2 fetch --no-tags --depth=1 origin' in workflow
assert 'git -C candidate rev-parse HEAD' in workflow
assert 'production' not in workflow.lower()
assert 'candidate_source_commit_sha' in selector
assert registry['default_execution']=='deny_until_exact_artifact_certified'
assert all(r['package_certification']=='pending' and not r['artifact_sha256']
           for r in registry['sources'] if r['review_state']=='source_reviewed_artifact_pending')
print('CROSS_PLUGIN_GENERIC_WORKFLOW: PASS')
