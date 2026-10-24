from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / 'includes'
media = (ROOT / 'class-mad4b-scp-wordpress-media-context.php').read_text()
pipeline = (ROOT / 'class-mad4b-scp-context-source-pipeline.php').read_text()
authority = (ROOT / 'class-mad4b-scp-context-authority.php').read_text()
admin = (ROOT / 'class-mad4b-scp-context-admin-ui.php').read_text()

contracts = {
    'media_mime_allowlist': "'application/pdf', 'text/plain', 'text/markdown'" in media,
    'bounded_full_inventory': 'LIMIT 1001' in media and 'count( $ids ) > 1000' in media,
    'revalidate_attachment_hash': "hash_equals( (string) $prior['content_hash'], (string) $record['content_hash'] )" in media,
    'version_bound_reuse': "hash_equals( (string) ( $prior['version'] ?? '' ), (string) $record['modifiedTime'] )" in media,
    'content_complete_proven': "! empty( $asset['content_complete'] )" in media,
    'provider_routed_scan': 'MAD4B_SCP_WordPress_Media_Context::inventory( $types, $source_id )' in pipeline,
    'complete_scan_admission': 'source_scan_apply_admission(' in pipeline,
    'registry_version_guard': "'expected_registry_revision'" in pipeline,
    'unsafe_partial_media_sync_denied': 'mad4b_pipeline_media_use_complete_scan' in pipeline,
    'scoped_absence_guard': 'scan_includes_absence' in authority and 'mad4b_context_scan_scope_missing' in authority,
    'scoped_mime_evidence': 'last_scan_scope_mime_types' in authority,
    'ui_mime_checkboxes': 'name="mime_types[]"' in admin and 'name="mime_types[]"' in media,
    'standalone_ui_no_attachment_list': 'Attachment IDs (comma-separated' not in media,
    'ui_source_dispatch': 'MAD4B_SCP_Context_Source_Pipeline::scan_complete(' in admin,
}
for label, valid in contracts.items():
    print(f"{label}: {'PASS' if valid else 'FAIL'}")
assert all(contracts.values()), 'Context Media inventory contract regression'
print(f'SUMMARY pass={sum(contracts.values())} fail={len(contracts)-sum(contracts.values())}')
