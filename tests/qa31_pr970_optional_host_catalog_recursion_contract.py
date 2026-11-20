#!/usr/bin/env python3
"""Exactly one nonauthorizing source blob for recursive optional MCP catalog safety."""
from pathlib import Path
from unittest import mock
import json
import qa31_release_source_gate as qa

repo=Path(__file__).resolve().parents[1]
name='includes/class-mad4b-scp-server-core-catalog.php'
source=repo/'wp-content/plugins/mad4b-site-control-plane'/name
review=repo/'governance/release-parity/qa31-pr970-optional-host-catalog-recursion-successor-20261120.json'
data=source.read_bytes()
m=json.loads(review.read_text())
f=qa.reviewed_pr970_optional_host_catalog_recursion_successor
assert f(repo,name,data)==m['required_regression']
assert f(repo,name,data+b'\n/* unreviewed widening */') is None
assert f(repo,'includes/class-mad4b-scp-self-update.php',data) is None

original=Path.read_text
for field, forged_value in (
 ('authorizing',True),
 ('production_authorized',True),
 ('source_promotion_approved',True),
 ('is_build_provenance',True),
 ('live_evidence_required',False),
 ('reviewed_for_ci_only',False),
 ('status','approved_for_release'),
 ('release_gate','PRODUCTION_AUTHORIZED'),
 ('frozen_parent_head_sha','0'*40),
 ('frozen_parent_source_blob_sha','1'*40),
 ('frozen_parent_source_sha256','2'*64),
 ('frozen_original_source_review_sha256','f'*64),
 ('reviewed_source_blob_sha','3'*40),
 ('reviewed_source_sha256','4'*64),
 ('required_regression','tests/unsafe-script.php'),
 ('additional_regressions',[]),
 ('negative_regression','tests/unknown.py'),
 ('control_ids',['SECURITY-01']),
):
 forged=dict(m)
 forged[field]=forged_value
 def fake_read(self,*a,**kw):
  return json.dumps(forged) if self.resolve()==review.resolve() else original(self,*a,**kw)
 with mock.patch.object(Path,'read_text',fake_read):
  assert f(repo,name,data) is None,field
print('QA31_PR970_REENTRANT_OPTIONAL_HOST_CATALOG: PASS (exact Git blob, verified parent, no release)')
