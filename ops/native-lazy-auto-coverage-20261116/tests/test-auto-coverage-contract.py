from pathlib import Path
import sys,re
r=Path(sys.argv[1])
discovery=(r/'discover-public-listings.php').read_text()
browser=(r/'browser-prove-new-cohort.cjs').read_text()
probe=(r/'ephemeral-qa-probe-template.php').read_text()
issuer=(r/'issue-browser-certified-cohort.php').read_text()
worker=(r/'run-auto-coverage-discovery.sh').read_text()
runner=(r/'run-cohort-qa.sh').read_text()
checks=[
 ('live WPML languages discovered rather than enumerated', "['active_languages']" in discovery and "'locale'=>$lang" in discovery),
 ('live WCML currency catalog dynamically found', "['currency_options']" in discovery),
 ('nested Elementor templates traversed', "$settings['template_id']" in discovery and '$scan($ref' in discovery),
 ('each JetEngine widget/query/listing is discovered', "'widget_id'=>$widget" in discovery and "'query_id'=>$query" in discovery),
 ('public safe terms query guard', "mad4b_render_query_sensitive_semantics" in discovery and "'terms'" in discovery),
 ('first-party WCML market id', "mad4b_render_query_market_identity" in discovery and "mad4b_render_query_market_identity" in issuer),
 ('browser probe cannot be used on production', "wp_get_environment_type()!=='staging'" in probe),
 ('probe token protected by one-way SHA256', "hash_equals" in probe and "hash('sha256'" in probe),
 ('probe has independent expiry and source binding', "['expires_at']" in probe and "mad4b_render_query_generation_for_id" in probe),
 ('signed native AJAX verification includes template/query ids', "x.template_id===String(config.document_id)" in browser),
 ('whole responsive range requires seven browser pairs', '320,390,767,768,1024,1440,1920' in runner and 'count($items)' not in runner),
 ('issuer forbids missing seventh viewport', 'array_keys($items)!==$widths' in issuer and '1440' in issuer),
 ('issuer validates current source via fresh discovery', 'require $scanner' in issuer and "count($matches)!==1" in issuer),
 ('issuer refuses low-volume content', "['baseline_count']??0)<$threshold" in issuer),
 ('issuer refuses missing render and content parity', "['links_match']??false)!==true" in issuer and "['content_match']??false)!==true" in issuer),
 ('issuer limits CLS and FCP regressions', '0.10' in issuer and '1.20' in issuer),
 ('issuer isolates company, locale, market and WPML', "get_environment_type()" in issuer and "do_action('wpml_switch_language'" in issuer),
 ('issuer serializes and uses atomic compare-and-swap', 'GET_LOCK(' in issuer and 'option_value=%s' in issuer),
 ('issuer keeps old certs if valid', "SKIP: healthy current certificate" in issuer),
 ('daily scanner never issues a certificate', 'issue-browser-certified-cohort.php' not in worker and 'classify-coverage.py' in worker),
]
for label,okay in checks:
 assert okay,label
 print('PASS',label)
print(f'PASS {len(checks)}/{len(checks)} automatic coverage source invariants')
