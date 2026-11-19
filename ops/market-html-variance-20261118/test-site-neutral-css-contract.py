#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[2]
a=(root/'wp-content/mu-plugins/414-mad4b-stable-listing-css-identity.php').read_text()
b=(root/'wp-content/mu-plugins/436-mad4b-private-session-critical-css-v1.php').read_text()
checks=[
 ('no-company-domains',all(x not in a+b for x in ('wayfatours.com','tours.dev.mad4b.com'))),
 ('post-types-from-site-option',"get_option('mad4b_stable_dcss_post_types_v1',[])" in a),
 ('public-type-and-canonical-archive','post_type_exists($type)' in a and 'get_post_type_archive_link($type)' in a),
 ('private-host-from-site',"get_option('home','')" in b and '$incoming!==$host' in b),
 ('site-owned-wpml-routes',"get_option('mad4b_private_session_critical_css_routes_v1',[])" in b),
 ('site-owned-asset-path',"content_url('/litespeed/css/')" in b and 'WP_CONTENT_DIR.self::BASE' in b),
 ('exact-manifest-site-and-environment',"($data['site_host']??'')!==$site['host']" in b and "($data['environment']??'')!==$site['environment']" in b),
 ('measured-css-hash',"hash_equals($entry['critical_sha256']" in b),
 ('private-html-no-store',"Cache-Control: private, no-store" in b),
 ('require-390-768-QA','in_array(390' in b and 'in_array(768' in b),
 ('private-opt-in-default-off',"get_option(self::OPT, 'off') !== 'on'" in b),
 ('archive-opt-in-default-off',"get_option('mad4b_perf_archive_stable_dcss_v1','off')==='on'" in a),
 ('no-elementor-fixed-ids',all(t not in a+b for t in ('25102','69204','69205','30843','45927')))
]
for label,ok in checks:print(('PASS' if ok else 'FAIL')+' '+label)
if not all(ok for _,ok in checks):raise SystemExit(1)
print('SITE_NEUTRAL_CSS_CONTRACT',len(checks),'PASS')
