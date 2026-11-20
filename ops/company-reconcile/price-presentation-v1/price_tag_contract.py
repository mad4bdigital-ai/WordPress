#!/usr/bin/env python3
"""Read-only, identity-bound MAD4B Elementor price tag reconciliation.

This observer cannot deploy plugins, copy pricing data, issue grants, or mutate a tenant.
Source and target containers must be independently enrolled by the caller.
"""
from __future__ import annotations
import html
import json
import re
import subprocess
from urllib.parse import urlparse
from urllib.request import Request, urlopen

CONTRACT = 'mad4b.company-elementor-price-tag-runtime-readback.v1'
WIDGET_ID = '3f2b785'  # Source-owned Featured Tours Listing contract; not a release locator.
WP = r'''global $wpdb;
$p=class_exists('MAD4B_SCP_Site_Profile',false)?MAD4B_SCP_Site_Profile::status():[];
$origin=untrailingslashit(home_url('/'));
$base=WP_PLUGIN_DIR.'/mad4b-commerce/';
$boot=$base.'mad4b-commerce.php';
$module=$base.'modules/commerce/tour-component-structured-price-tags.php';
$used=(bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_key=%s AND p.post_type IN (%s,%s) AND p.post_status=%s AND m.meta_value LIKE %s LIMIT 1",'_elementor_data','elementor_library','jet-engine','publish','%'.$wpdb->esc_like('tour-component-price-text').'%'));
$tags=[];$registered=false;
try{if(class_exists('Elementor\Plugin')){$tags=\Elementor\Plugin::instance()->dynamic_tags->get_tags();$registered=isset($tags['tour-component-price-text']);}}catch(Throwable $ex){$registered=false;}
$sample=null;
if(function_exists('tour_component_price_evidence') && function_exists('tour_component_structured_price_value')){
 $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s ORDER BY ID ASC LIMIT 200",'tours-and-activities','publish'));
 foreach($ids as $raw){$id=(int)$raw;
  $e=tour_component_price_evidence($id,'auto','upcoming');
  if(!is_array($e) || !is_numeric($e['amount']??null) || !is_string($e['currency']??null) || !is_string($e['unit']??null))continue;
  $txt=tour_component_structured_price_value('text',$id,'auto','upcoming',['role'=>'current','show_label'=>'yes','show_unit'=>'yes']);
  if($txt===''||$txt==='Price on request')continue;
  $sample=['id'=>$id,'rate_id'=>(int)($e['rate_id']??0),'currency'=>$e['currency'],'unit'=>$e['unit'],'formatted_nonempty'=>true,'evidence_status'=>$e['status']??null];break;
 }
}
$active=in_array('mad4b-commerce/mad4b-commerce.php',(array)get_option('active_plugins',[]),true);
echo wp_json_encode(['contract'=>'mad4b.price-tag.wp-readback.v1','origin'=>$origin,'site_uuid'=>$p['site_uuid']??null,'environment'=>$p['environment']??null,'profile_ready'=>!empty($p['configured'])&&!empty($p['origin_match'])&&!empty($p['environment_match'])&&!empty($p['deployment_binding_match']),'commerce_plugin_active'=>$active,'price_tag_used_in_published_template'=>$used,'module_sha256'=>is_file($module)?hash_file('sha256',$module):null,'bootstrap_sha256'=>is_file($boot)?hash_file('sha256',$boot):null,'tag_function_loaded'=>function_exists('tour_component_structured_price_value'),'tag_registered_in_elementor'=>$registered,'evidence_provider_loaded'=>function_exists('tour_component_price_evidence'),'runtime_presentation'=>defined('MAD4B_PRESENTATION_RUNTIME_ENABLED')&&MAD4B_PRESENTATION_RUNTIME_ENABLED===true,'runtime_commerce'=>defined('MAD4B_COMMERCE_RUNTIME_ENABLED')&&MAD4B_COMMERCE_RUNTIME_ENABLED===true,'sample'=>$sample,'read_only'=>true]);'''
SHA = re.compile(r'^[a-f0-9]{64}$')
CONTAINER = re.compile(r'^php-[a-z0-9-]{10,80}$')
PRICE_RE = re.compile(r'elementor-element-' + WIDGET_ID + r'[^>]*>\s*<div[^>]*>(.*?)</div>', re.I | re.S)
STRIP = re.compile(r'<[^>]*>')


def wp_probe(site: dict, timeout: int = 85) -> dict:
    container = str(site['container'])
    if not CONTAINER.fullmatch(container):
        raise ValueError('UNTRUSTED_PRICE_TAG_CONTAINER')
    done = subprocess.run(['docker', 'exec', '-u', '33:33', container, 'wp',
                           '--path=/var/www/html', '--skip-themes', 'eval', WP],
                          capture_output=True, text=True, timeout=timeout, check=False)
    if done.returncode:
        raise RuntimeError('PRICE_TAG_WP_READBACK_FAILED:' + done.stderr[-210:])
    lines = [x for x in done.stdout.splitlines() if x.startswith('{')]
    if len(lines) != 1:
        raise ValueError('PRICE_TAG_WP_READBACK_CARDINALITY')
    data = json.loads(lines[0])
    if (data.get('contract') != 'mad4b.price-tag.wp-readback.v1'
        or data.get('origin') != site['origin'] or data.get('read_only') is not True):
        raise ValueError('PRICE_TAG_UNBOUND_WP_READBACK')
    if not data.get('profile_ready') or not data.get('site_uuid'):
        raise ValueError('PRICE_TAG_SITE_PROFILE_UNVERIFIED')
    if not data.get('commerce_plugin_active'):
        raise ValueError('PRICE_TAG_COMMERCE_PLUGIN_NOT_ACTIVE')
    return data


def frontend_probe(site: dict, language: str, timeout: int = 28) -> dict:
    if language not in ('en', 'es'):
        raise ValueError('UNSUPPORTED_PRICE_TAG_LOCALE')
    origin = site['origin']
    parsed = urlparse(origin)
    if parsed.scheme != 'https' or not parsed.hostname or parsed.path not in ('', '/'):
        raise ValueError('UNBOUND_PRICE_TAG_ORIGIN')
    prefix = '/es' if language == 'es' else ''
    url = f'{origin}{prefix}/tours-and-activities/?mad4b_price_contract_probe=20261117'
    with urlopen(Request(url, headers={'User-Agent': 'MAD4B-Company-ReadOnly-PriceTag/1.0',
                                       'Accept-Encoding': 'identity'}), timeout=timeout) as response:
        if urlparse(response.url).hostname != parsed.hostname:
            raise ValueError('PRICE_TAG_FRONTEND_UNBOUND_REDIRECT')
        if response.status != 200:
            raise ValueError('PRICE_TAG_FRONTEND_HTTP_FAILURE')
        body = response.read(3_200_000)
        if len(body) >= 3_200_000:
            raise ValueError('PRICE_TAG_FRONTEND_HTML_TOO_LARGE')
        document = body.decode('utf-8', errors='replace')
        # Only source-owned carousel widget, not an unrelated page price.
        start = document.find('elementor-element-29f3807b')
        if start < 0:
            raise ValueError('PRICE_TAG_FEATURED_CAROUSEL_NOT_FOUND')
        segment = document[start:start + 200_000]
        amounts = [html.unescape(STRIP.sub('', m.group(1))).strip() for m in PRICE_RE.finditer(segment)]
        if not amounts:
            raise ValueError('PRICE_TAG_WIDGET_NOT_RENDERED')
        populated = sum(bool(x) for x in amounts)
        rate_shape = sum(bool(re.search(r'\b(?:USD|EUR|EGP|GBP)\s+[\d.,]+', x)) for x in amounts)
        return {'language': language, 'card_count': len(amounts),
                'priced_card_count': populated, 'valid_currency_amount_count': rate_shape,
                'all_priced': populated == len(amounts) and rate_shape == len(amounts),
                'label_localized': all(x.startswith('Desde ') for x in amounts) if language == 'es' else True,
                'cache_header': response.headers.get('x-wayfa-origin-cache', 'UNOBSERVED'),
                'network_source': 'VERIFIED_ENROLLED_ORIGIN', 'read_only': True}


def assess(source: dict, target: dict, frontend: list[dict] | None) -> dict:
    reasons = []
    module_match = bool(SHA.fullmatch(str(source.get('module_sha256') or ''))
                        and source.get('module_sha256') == target.get('module_sha256'))
    if not module_match:
        reasons.append('ENGINE_PRICE_TAG_MODULE_SOURCE_TARGET_SHA_DRIFT')
    for role, record in (('SOURCE', source), ('TARGET', target)):
        if not record.get('tag_function_loaded') or not record.get('tag_registered_in_elementor'):
            reasons.append(role + '_ELEMENTOR_PRICE_TAG_NOT_REGISTERED')
        if not record.get('evidence_provider_loaded'):
            reasons.append(role + '_PRICE_EVIDENCE_PROVIDER_MISSING')
        if record.get('price_tag_used_in_published_template') and not record.get('sample'):
            reasons.append(role + '_INDICATIVE_PRICE_SAMPLE_UNAVAILABLE')
    if source.get('price_tag_used_in_published_template') and not target.get('price_tag_used_in_published_template'):
        reasons.append('SOURCE_PRICED_TEMPLATE_MISSING_ON_TARGET')
    if target.get('price_tag_used_in_published_template'):
        if frontend is None:
            reasons.append('FRONTEND_PRICE_EVIDENCE_NOT_COLLECTED')
        elif not frontend or any(not item.get('all_priced') for item in frontend):
            reasons.append('PUBLISHED_FEATURED_CARDS_PRICE_EMPTY_OR_INVALID')
    state = ('HOLD_PRICE_PRESENTATION_CONTRACT' if reasons else
             'NOT_APPLICABLE_NO_PUBLISHED_PRICE_LISTING' if not target.get('price_tag_used_in_published_template') else
             'PASS_VERIFIED')
    return {'contract': CONTRACT, 'state': state,
            'reason_codes': reasons, 'module_source_target_sha_match': module_match,
            'source': source, 'target': target,
            'frontend': frontend,
            'target_p1': bool(reasons), 'can_auto_apply': False,
            'creates_grants': False, 'modifies_rates': False,
            'mutates_tenant': False, 'read_only': True}


def observe(source_site: dict, target_site: dict) -> dict:
    source = wp_probe(source_site)
    target = wp_probe(target_site)
    # Scope the current live Featured Tours sample to the two currently enrolled origins.
    expected = {'https://tours.dev.mad4b.com', 'https://wayfatours.com'}
    if {source_site['origin'], target_site['origin']} == expected:
        frontend = [frontend_probe(target_site, language) for language in ('en', 'es')]
    else:
        frontend = None
    return assess(source, target, frontend)
