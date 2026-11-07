#!/usr/bin/env python3
"""Hermetic source fingerprint and non-blocking implementation contracts."""
import hashlib
import json
from pathlib import Path

root=Path(__file__).resolve().parents[3]
bundle=root/'ops/mad4b-company-live-convergence-20261106'
record=json.loads((bundle/'host-readiness/SOURCE-FINGERPRINTS.json').read_text())
assert record['contract']=='mad4b.content-readiness-gallery-source.v1'
for rel,sha in record['sha256'].items():
    path=root/rel
    assert path.is_file() and not path.is_symlink(),rel
    assert hashlib.sha256(path.read_bytes()).hexdigest()==sha,rel
ctx=(root/'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-cli-execution-context.php').read_text()
scan=(root/'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-cli-readiness.php').read_text()
readiness=(root/'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-readiness-alerts.php').read_text()
gallery=(root/'wp-content/mu-plugins/mad4b-tour-gallery-presentation.php').read_text()
host=(bundle/'host-readiness/mad4b-wayfa-readiness-scan.py').read_text()
assert 'content_readiness.scheduled_scan' in ctx
assert 'unified_operation_policy_allowed' in ctx
assert 'production_mutation_denied_without_dedicated_approval' not in ctx
assert "if ($operation !== 'content_readiness.scheduled_scan') return false;" in ctx
assert "MAD4B_READINESS_SCHEDULED_RUN" in scan
assert 'RUN SCHEDULED READINESS SCAN' in scan
assert "mad4b-tour-gallery-diagnostics" in readiness and 'media_gallery_reused_across_tours' in readiness
assert 'get_post_status($object_id)' in readiness and '||$gallery' in readiness
assert 'jet-engine/listing/data/get-post-meta' in gallery
assert 'private const MAX_POSTS = 3000;' in gallery
assert 'mad4b/content_readiness/external_issues' in gallery
assert 'MAD4B_Tour_Gallery_Presentation_V2' in gallery
assert 'wp_cache_set' in gallery
assert 'subprocess.run(' in host and 'run_failed:' in host and 'unverified_receipt_contract' in host
assert 'mad4b.cli-readiness.v1' in host
print(json.dumps({'result':'PASS','contract':record['contract'],'files':len(record['sha256'])}))
