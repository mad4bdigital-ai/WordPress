from pathlib import Path
p=(Path(__file__).resolve().parents[1]/'includes/class-mad4b-scp-context-admin-ui.php').read_text()
start=p.index('    /** Read-only page: it never starts or applies a Drive scan on GET. */')
end=p.index('\tprivate static function render_sources() {',start)
body=p[start:end]
for s in ['function render_scan_progress_page','hash_equals','indexed_source_receipt','esc_html','scan-ui-progress.v1','source_id']:
 assert s in body,s
for s in ['update_option','delete_option','source_scan_apply','source_scan_plan','scan_folder','::step(',"wp_safe_remote_post"]:
 assert s not in body,s
assert "if ( preg_match( '/^[a-f0-9]{64}$/D', $scan_source ) )" in p
assert 'function indexed_scan_readback' in p
assert 'View scan status' in p
print('PASS Context scan viewer fail-closed read-only contract')
