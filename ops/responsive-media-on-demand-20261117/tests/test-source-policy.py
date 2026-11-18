from pathlib import Path
import sys
W=Path(sys.argv[1])
php=(W/'wp-content/mu-plugins/mad4b-responsive-media-on-demand-v1.php').read_text()
js=(W/'wp-content/mu-plugins/assets/mad4b-responsive-media-demand-v1.js').read_text()
report=(W/'ops/responsive-media-on-demand-20261117/README.md').read_text()
checks={
 'source is company-policy opt in':"'enabled' => !empty($p['enabled'])" in php,
 'only site local wp cron schedules':"wp_schedule_single_event(time()+60,self::HOOK" in php,
 'never generates inside REST query':'self::query($term_id,$width,true)' in php and 'wp_get_image_editor' not in php,
 'uses exact source allowlist':"in_array($term_id,$allowed,true)" in php,
 'supports dynamic active WPML languages':"wpml_active_languages" in php and "wpml_object_id" in php,
 'rejects uploaded untrusted paths':"str_contains($relative,'..')" in php,
 'source hash invalidation':"'stamp'=>$stamp" in php,
 'per source variant cap':'MAX_SOURCE_VARIANTS = 4' in php,
 'server physical resolution quantization': 'WIDTH_STEP = 64' in php,
 'site local global CPU serialization':'.mad4b-responsive-media-worker.lock' in php,
 'concurrency constrained on client':'maxConcurrent=2' in js,
 'visible cards batch under one WordPress bootstrap':"endpointUrl.searchParams.set('items',keys.join(','))" in js,
 'server limits one batch to eight cards':"(?:,[0-9]{1,9}:[0-9]{3,4}){0,7}" in php,
 'measurement uses IntersectionObserver geometry':'e.boundingClientRect.width' in js,
 'client avoids independent forced getBoundingClientRect':'getBoundingClientRect()' not in js,
 'browser source URL stays same origin':'asset.origin!==location.origin' in js,
 'JS never pre-generates all sizes':'resizeImage(' not in js and "endpointUrl.searchParams.set" in js,
 'frontend image fallback remains CSS until ready':"result.state!=='ready'" in js,
 'only content-addressed URL accepted':'[a-f0-9]{12}' in js,
 'on-demand starts after load not in render critical path':"window.addEventListener('load',startAfterLoad" in js,
 'REST responses never cache pending results':"Cache-Control','private, no-store, max-age=0" in php,
 'no global WP Cron disable or commerce writes':'DISABLE_WP_CRON' not in php and 'wc_' not in php,
 'operator documented':'26/26' in report,
 'company adapters explicit':'mad4b_responsive_media_policy_v1' in report,
}
for label,pass_ in checks.items():
 assert pass_,label
print(f'PASS {len(checks)}/{len(checks)} on-demand site-local media architecture/source invariants')
