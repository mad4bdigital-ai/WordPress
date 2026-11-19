#!/usr/bin/env python3
"""Hermetic regression gate for the original WPML/JetEngine batching adapter."""
from pathlib import Path
import hashlib
import re
import sys
root=Path(__file__).resolve().parents[2]
file=root/'wp-content/mu-plugins/mad4b-elementor-wpml-schema-guard.php'
s=file.read_text()
checks={
    'native_prefetch_api':'$wpml_term_translations->prefetch_ids($ids)' in s,
    'public_opt_in':'mad4b_wpml_jetengine_taxonomy_prefetch_mode' in s,
    'request_scope':'private static array $tax_prefetched' in s,
    'admin_denial':'is_admin() || wp_doing_ajax()' in s,
    'cli_and_rest_denial':"(defined('WP_CLI') && WP_CLI)" in s and "(defined('REST_REQUEST') && REST_REQUEST)" in s,
    'bounded_term_batch':'count($ids)>800' in s and 'LIMIT 801' in s,
    'market_no_html_cache':'cache_set(' not in s and 'set_transient(' not in s,
    'no_sql_mutations':all(x not in s for x in ('$wpdb->insert(', '$wpdb->update(', '$wpdb->delete(')),
    'wpml_pinned_api':bool(re.search(r"hash_equals\('[a-f0-9]{64}'",s)),
    'hook_registration':"['MAD4B_Elementor_WPML_Schema_Guard_V1','jetengine_taxonomy_prefetch'],8,3" in s,
    'version':'Version: 1.1.4' in s,
    'exact_approved_live_sha':hashlib.sha256(file.read_bytes()).hexdigest()=='b87ee6eb43ea9a61e305d41c247558a2e32e7785251f9df825454333e6d22296',
}
for check,pass_ in checks.items():
    print(f'{"PASS" if pass_ else "FAIL"} {check}')
if not all(checks.values()):
    sys.exit(1)
print('WPML_JETENGINE_PREFETCH_SOURCE_GUARD_PASS',len(checks))
