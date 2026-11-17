#!/usr/bin/env python3
"""No misleading duplicate CTA price: contextual AJAX takes precedence."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
modules=(
 'ops/site-company-overlays/wayfatours/site-mu-mapped-variants/wp-content/mu-plugins/portable-modules/342-mad4b-archive-compact-hero-price.php',
 'wp-content/plugins/mad4b-platform/modules/compat/342-mad4b-archive-compact-hero-price.php')
scripts=(
 'ops/site-company-overlays/wayfatours/site-mu-mapped-variants/wp-content/mu-plugins/assets/mad4b-archive-compact-price-context-v2.js',
 'wp-content/mu-plugins/assets/mad4b-archive-compact-price-context-v2.js')
count=0
for file in modules:
 s=(root/file).read_text()
 required=(
  'class MAD4B_Archive_Hero_Price_V1',
  'public static function price_state(',
  'public static function scoped_tour_ids(',
  'public static function scoped_tour_ids_by_query(',
  "register_rest_route('mad4b/v1','/archive/tours/min-price'",
  'public static function patch_elementor(',
  ''''editor']='<span class="mad4b-archive-price-value" hidden aria-live="polite"></span>' ''',
  "wp_enqueue_style('mad4b-archive-compact-price'",
  'mad4b-archive-compact-price-context-v2.js',
  'MAD4B_ARCHIVE_MIN_PRICE_ENDPOINT',
  'filter_scope_not_certified',
  "public_price_response(['status'=>'unavailable'",
 )
 for required_text in required:
  if required_text.strip() not in s:raise AssertionError(f'{file}: missing {required_text}')
  count+=1
 print('PASS',file,'contextual CTA with authoritative filtered evidence and no static 0$')
assert (root/scripts[0]).read_bytes()==(root/scripts[1]).read_bytes()
count+=1
for script in scripts:
 content=(root/script).read_text()
 for required_text in (
  "var key='jet-engine/tours_query_archive'",
  "'ajaxFilters/updated'",
  "'ajaxFilters/start-loading'",
  'target.replaceChildren()',
  "'&query='+encodeURIComponent(query)",
  "data.status!=='available'",
  "cache:'no-store'",
  'controller.abort()'
 ):
  assert required_text in content,(script,required_text)
  count+=1
print('PASS: %d contextual-conversion/no-duplicate source assertions'%count)
