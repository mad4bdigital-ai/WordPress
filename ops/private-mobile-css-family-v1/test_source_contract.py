from pathlib import Path
import ast
root=Path(__file__).resolve().parents[2]
code=(root/'wp-content/mu-plugins/423-mad4b-private-mobile-css-family.php').read_text()
worker=(root/'ops/private-mobile-css-family-v1/monitor.py').read_text()
qa=(root/'ops/private-mobile-css-family-v1/qa-family-candidate.cjs').read_text()
required=[
 'MAD4B_Private_Mobile_CSS_Family_V1',
 'MAD4B_Mobile_CSS_Inline_Contract_V1::inline_loader',
 'private, no-store, max-age=0',
 'X-Accel-Expires: 0',
 'WPML',
 'has_session()',
 'hash_equals',
 'hash_file',
 'sha256',
 'MAD4B_TRUSTED_DEVICE_CLASS',
 'home_view()',
 'is_front_page()',
 'litespeed_buffer_finalize',
 'mad4b.private-mobile-css-family.v1',
 'qa_viewports',
 'home_dirty_generation',
 'css_epoch',
]
for marker in required:
 assert marker in code,marker
assert "header('Cache-Control: public" not in code
assert "set_customer_session_cookie" not in code
assert 'etg-' not in code.lower()
assert 'setcookie(' not in code
assert "'status':'certified'" in worker and 'REQUALIFICATION_REQUIRED' in worker
assert 'LIVE_PRIVATE_CSS_INTEGRITY_OK' in worker
assert 'existing_matches' in worker and 'daily_browser_health' in worker
assert 'x.w===0&&x.h===0&&y.w===0&&y.h===0' in qa
ast.parse(worker)
print('PASS: MAD4B original private CSS source, no shared cache, event-driven QA')
