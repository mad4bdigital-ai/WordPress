#!/usr/bin/env python3
from pathlib import Path
source=(Path(__file__).resolve().parents[1]/"wp-content"/"plugins"/"mad4b-site-control-plane"/"includes"/"class-mad4b-scp-external-media-ingest.php").read_text()
for k in ("operator_responsibility_accepted","mad4b_media_operator_attestation_required","mad4b_media_operator_scope_denied","mad4b_media_source_restricted","_mad4b_media_operator_responsibility_v1","'rights_status' => 'unverified'","mad4b_media_operator_evidence_write_failed","get_post_meta( $id, self::OPERATOR_META, true )"):
 assert k in source,k
assert "wp_http_validate_url( $url )" in source
assert "media_handle_sideload(" in source
print("PASS: operator media import source contract")
