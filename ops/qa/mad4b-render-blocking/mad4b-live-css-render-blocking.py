#!/usr/bin/env python3
"""Staging-only live mobile browser probe; no website changes or external APIs."""
import asyncio,datetime,hashlib,json,os,pathlib,re,subprocess
from html.parser import HTMLParser
from urllib.parse import urlsplit
from playwright.async_api import async_playwright
ORIGIN='https://tours.dev.mad4b.com'
SITE_ID='e5e64bca-ed62-48d2-b2b3-dff693cd702a'
PAGES=['/','/ar/','/fr/','/de/','/es/','/it/',
       '/destination/africa-containent/egypt/',
       '/tours-and-activities/',
       '/ar/tours-and-activities/','/fr/tours-and-activities/',
       '/de/tours-and-activities/','/es/tours-and-activities/',
       '/it/tours-and-activities/',
       '/tours-and-activities/nile-cruise-luxor-to-aswan-5-days/',
       '/plan-a-trip/',
       '/tours-and-activities/best-of-egypt-cairo-luxor-nile-cruise-red-sea-12-days/',
       '/tours-and-activities/unforgettable-egypt-budget-tour-cairo-to-luxor-nile-cruise-discovery-8-days/',
       '/ar/tours-and-activities/unforgettable-egypt-budget-tour-cairo-to-luxor-nile-cruise-discovery-8-days/',
       '/fr/tours-and-activities/unforgettable-egypt-budget-tour-cairo-to-luxor-nile-cruise-discovery-8-days/',
       '/de/tours-and-activities/unforgettable-egypt-budget-tour-cairo-to-luxor-nile-cruise-discovery-8-days/',
       '/es/tours-and-activities/unforgettable-egypt-budget-tour-cairo-to-luxor-nile-cruise-discovery-8-days/',
       '/it/tours-and-activities/unforgettable-egypt-budget-tour-cairo-to-luxor-nile-cruise-discovery-8-days/',
       '/tours-and-activities/amazing-5-days-cairo-and-luxor-discovery-tour/',
       '/ar/tours-and-activities/amazing-5-days-cairo-and-luxor-discovery-tour/']
OUT=pathlib.Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/render-blocking-uael-20261031')
OUTPUT=OUT/'live-css-scan.json'
CONTRACT='mad4b.live-render-blocking.v1'
class Links(HTMLParser):
    def __init__(self):
        super().__init__();self.styles=[];self.inline_styles=[];self._active_style=None
    def handle_starttag(self,tag,attrs):
        if tag=='style':
            self._active_style={'id':dict(attrs).get('id') or 'unnamed-inline','bytes':0}
            return
        if tag!='link':return
        d=dict(attrs)
        if 'stylesheet' not in (d.get('rel') or '').split():return
        self.styles.append({'id':d.get('id') or '', 'href':d.get('href') or '',
            'media':d.get('media') or 'all',
            'onload':bool(d.get('onload'))})
    def handle_data(self,content):
        if self._active_style is not None:self._active_style['bytes']+=len(content.encode('utf-8'))
    def handle_endtag(self,tag):
        if tag=='style' and self._active_style is not None:
            self.inline_styles.append(self._active_style)
            self._active_style=None
async def inspect(browser,path,sem):
    async with sem:
        page=await browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
        url=ORIGIN+path
        try:
            await page.add_init_script("""window.__mad4b_lcp=[];
                new PerformanceObserver(list=>{for(const e of list.getEntries()){
                    window.__mad4b_lcp.push({ms:Math.round(e.startTime),
                      tag:e.element?.tagName||'', element_id:e.element?.getAttribute('data-id')||'',
                      text:(e.element?.innerText||'').slice(0,75),resource:e.url||'',loading:e.element?.getAttribute('loading')||''});
                }}).observe({type:'largest-contentful-paint',buffered:true});""")
            await page.add_init_script("""window.__mad4b_loaf=[];
                if(PerformanceObserver.supportedEntryTypes.includes('long-animation-frame')) {
                  new PerformanceObserver(list=>list.getEntries().forEach(e=>{
                    window.__mad4b_loaf.push({total:e.scripts.reduce((n,s)=>n+(s.forcedStyleAndLayoutDuration||0),0),
                     scripts:e.scripts.length});
                  })).observe({type:'long-animation-frame',buffered:true});
                }""")
            response=await page.goto(url,wait_until='domcontentloaded',timeout=25000)
            if not response or response.status!=200:raise RuntimeError('http_status_'+str(response.status if response else None))
            ard_valid=None
            if path=='/':
                try:
                    ard=await page.request.get(ORIGIN+'/.well-known/ard.json',timeout=10000)
                    parsed=await ard.json() if ard.status==200 else {}
                    entries=parsed.get('entries',[]) if isinstance(parsed,dict) else []
                    ard_valid=(ard.status==200 and ard.headers.get('content-type','').split(';')[0]
                        in ('application/ard+json','application/json') and isinstance(entries,list)
                        and len(entries)>0 and all(isinstance(e,dict)
                        and e.get('identifier','').startswith('urn:air:tours.dev.mad4b.com:')
                        and e.get('displayName') and e.get('type') and (bool(e.get('url'))!=bool(e.get('data')))
                        for e in entries))
                except Exception:
                    ard_valid=False
            raw=await response.text()
            parser=Links();parser.feed(raw)
            await page.wait_for_timeout(750)
            live=await page.evaluate('''() => ({
                uael:[...document.querySelectorAll('[data-widget_type^="uael-"]')]
                  .map(x=>({type:x.getAttribute('data-widget_type'),
                   height:Math.round(x.getBoundingClientRect().height)})),
                resources:performance.getEntriesByType('resource')
                   .filter(x=>x.initiatorType==='link' || x.name.includes('/css/') || x.initiatorType==='css')
                   .map(x=>({url:x.name,encoded:x.encodedBodySize||0,
                      duration:Math.round(x.duration),responseEndMs:Math.round(x.responseEnd)})).slice(0,300),
                headerLiteralText:[...document.body.childNodes].filter(n=>{
                    if(n.nodeType!==Node.TEXT_NODE)return false;
                    const t=(n.textContent||'').trim();
                    return t.length>0 && t.length<20 && (t.includes(String.fromCharCode(92)+'n') || t.includes('/n/n'));
                }).length,
                swiperActiveInert:document.querySelectorAll('.jet-listing-grid__slider.swiper-initialized .swiper-slide-active[inert]').length,
                forcedLayout:(()=>{const items=window.__mad4b_loaf||[];return {
                    supported:PerformanceObserver.supportedEntryTypes.includes('long-animation-frame'),
                    frames:items.length,forced_ms:Math.round(items.reduce((a,x)=>a+x.total,0))};})(),
                catalogInitial:(()=>{const locale=(document.documentElement.lang||'en').slice(0,2).toLowerCase();
                    const ids={en:25102,ar:40683,fr:35070,de:35076,es:35084,it:39902};
                    const targetId=ids[locale]||null;
                    const items=[...document.querySelectorAll('.jet-listing-grid__items')];
                    const list=items.find(x=>x.className.includes('jet-listing-grid--25102')&&x.getBoundingClientRect().width>300);
                    const first=list?.querySelector('.jet-listing-grid__item');
                    const elementId='82a8340';
                    const layout=first?.querySelector('[data-id="'+elementId+'"]');
                    const sheet=document.getElementById('mad4b-catalog-listing-native-first-paint-inline-css');
                    const hasLocalCSS=!!targetId && !!sheet && sheet.textContent.includes('.elementor-'+targetId+' ');
                    const hasOlderStructure=locale==='fr'||locale==='de';
                    return {present:!!list,style_present:hasLocalCSS,
                        layout_direction:layout?getComputedStyle(layout).flexDirection:null,
                        wpml_locale:locale,template_id:targetId,
                        structural_drift:hasOlderStructure && !!first &&
                          !first.querySelector('[data-id="82a8340"]')};})(),
                catalogUICards:(()=>{
                    const list=[...document.querySelectorAll('.jet-listing-grid__items')]
                        .find(x=>x.className.includes('jet-listing-grid--25102')&&x.getBoundingClientRect().width>180);
                    if(!list)return {present:false,samples:[]};
                    const cards=[...list.querySelectorAll(':scope > .jet-listing-grid__item')].slice(0,3);
                    const box=e=>{if(!e)return null;const r=e.getBoundingClientRect(),cs=getComputedStyle(e);
                        return {width:Math.round(r.width),height:Math.round(r.height),font_px:Math.round(parseFloat(cs.fontSize)*10)/10,
                            flex_direction:cs.flexDirection,flex_wrap:cs.flexWrap};};
                    return {present:true,samples:cards.map(card=>{
                        const price=card.querySelector('.tour-component-price');
                        const amount=price?.querySelector('.woocommerce-Price-amount,.price-current');
                        const unit=price?.querySelector('.price-unit');
                        const widget=price?.closest('.elementor-widget-heading');
                        const arrow=card.querySelector('.elementor-widget-icon .elementor-icon svg');
                        const clock=card.querySelector('.jet-listing-dynamic-terms__icon svg');
                        const favorite=card.querySelector('svg.e-far-heart');
                        return {post_id:card.getAttribute('data-post-id'),card:box(card),
                            arrow:box(arrow),clock:box(clock),favorite:box(favorite),
                            price:box(price),amount:box(amount),unit:box(unit),widget:box(widget),
                            price_source:price?.getAttribute('data-price-source')||null};
                    })};
                })(),
                chosenWithoutHref:[...document.querySelectorAll('a.chosen-single')]
                    .filter(x=>!x.getAttribute('href') || x.getAttribute('href')==='#').length,
                ardHref:document.querySelector('link[rel="ard"]')?.href||null,
                title:document.title,
                pageOverflow:Math.max(0,document.documentElement.scrollWidth-innerWidth),
                ttfbMs:Math.round(performance.getEntriesByType('navigation')[0]?.responseStart||0),
                cityLcpPreload:(()=>{let e=document.querySelector('#mad4b-city-first-slide-lcp');return e?{
                    href:e.href,priority:e.getAttribute('fetchpriority'),media:e.media,
                    as:e.as,type:e.type}:null})(),
                cityLcpRequests:performance.getEntriesByType('resource')
                    .filter(x=>x.name===document.querySelector('#mad4b-city-first-slide-lcp')?.href)
                    .map(x=>({initiator:x.initiatorType,start_ms:Math.round(x.startTime)})),
                accommodationVisibility:(()=>{let lists=[...document.querySelectorAll('.booking-choice-list')];return {
                    count:lists.length,
                    invalid_visible:lists.filter(x=>{
                        const p=x.parentElement,visible=getComputedStyle(x).display!=='none' && (!p||getComputedStyle(p).display!=='none');
                        return visible && (x.dataset.bookingChoiceEligible==='0'||x.textContent?.includes('No eligible hotel options'));
                    }).length,
                    eligible_visible:lists.filter(x=>x.dataset.bookingChoiceEligible==='1'&&getComputedStyle(x).display!=='none').length
                }})(),
                mobileTourTOCGap:(()=>{let x=document.querySelector('body.single-tours-and-activities .elementor-24784 .elementor-element-b29a19b');return x?parseFloat(getComputedStyle(x).marginBlockStart):null})(),
                isTourSingle:document.body.classList.contains('single-tours-and-activities'),
                departureDetails:(()=>{const widgets=[...document.querySelectorAll('[data-mad4b-departure-source="linked-tour"]')];
                    const cardLabels=[...document.querySelectorAll('.tk-tour-rate-label')];
                    const unconfirmed=cardLabels.filter(x=>x.dataset.mad4bDepartureOptin!=='1');
                    return {widgets:widgets.length,
                     unexpected_visible:widgets.filter(x=>x.dataset.mad4bDepartureOptin==='0'&&x.textContent.trim()).length,
                     continent_visible:widgets.filter(x=>/\bAfrica\b/i.test(x.textContent)).length,
                     optin_count:widgets.filter(x=>x.dataset.mad4bDepartureOptin==='1').length,
                     label_count:cardLabels.length,
                     stale_default_label_count:unconfirmed.filter(x=>/\b(Africa|Afternoon|Friday|Monday|Tuesday|Wednesday|Thursday|Saturday|Sunday|Morning|Evening)\b/i.test(x.textContent)).length,
                     unmarked_label_count:cardLabels.filter(x=>!x.hasAttribute('data-mad4b-departure-optin')).length};})(),
                mobileTOCBottomGap:(()=>{let toc=document.querySelector('.elementor-24784 .elementor-element-b29a19b');
                    if(!toc||getComputedStyle(toc).display==='none')return null;
                    let after=[...document.querySelectorAll('body.single-tours-and-activities .elementor-widget-heading h2.elementor-heading-title')]
                     .filter(x=>x.getBoundingClientRect().height>0 && x.getBoundingClientRect().top>toc.getBoundingClientRect().bottom)
                     .sort((a,b)=>a.getBoundingClientRect().top-b.getBoundingClientRect().top)[0];
                    return after?Math.round(after.getBoundingClientRect().top-toc.getBoundingClientRect().bottom):null})(),
                tourH2:(()=>{let h=[...document.querySelectorAll('body.single-tours-and-activities .elementor-widget-heading h2.elementor-heading-title')]
                    .find(x=>x.getBoundingClientRect().height>0);if(!h)return null;
                    const css=getComputedStyle(h);return {color:css.color,global_secondary:css.getPropertyValue('--e-global-color-secondary').trim(),font:css.fontFamily}})(),
                tourRateCSS:(()=>{let shadow=document.querySelector('#mad4b-rate-css-shadow');let original=document.querySelector('#elementor-post-56197');let cards=[...document.querySelectorAll('.tk-tour-rate-label')];let label=cards[0],control=label?.closest('.jet-toggle__control');let a=label?getComputedStyle(label):null,c=control?getComputedStyle(control):null;return{shadow_present:!!shadow,original_present:!!original,sha:shadow?.getAttribute('data-css-sha256')||null,card_count:cards.length,font_px:a?parseFloat(a.fontSize):null,card_height:label?Math.round(label.getBoundingClientRect().height):null,bg:c?.backgroundColor||null,radius_px:c?parseFloat(c.borderTopLeftRadius):null}})(),
                externalGoogleFonts:performance.getEntriesByType('resource')
                  .filter(x=>x.name.startsWith('https://fonts.googleapis.com/css'))
                  .map(x=>({url:x.name,duration_ms:Math.round(x.duration)})).slice(0,12),
                lcp:(window.__mad4b_lcp||[]).slice(-1)[0]||null})''')
            resources={r['url']:r for r in live['resources']}
            styles=[]
            for a in parser.styles:
                u=a['href']; resource=resources.get(u, {})
                # urlsplit ignores query strings for cache-busting.
                if not (u.startswith(ORIGIN+'/') or not u.startswith('http')):continue
                entry={'handle':a['id'],'media_initial':a['media'],'has_onload':a['onload'],
                       'url_path':urlsplit(u).path,'encoded_bytes':int(resource.get('encoded') or 0),
                       'duration_ms':int(resource.get('duration') or 0)}
                entry['blocking']=a['media'].lower() in ('','all','screen') and not a['onload']
                styles.append(entry)
            lcp=live.get('lcp') or None
            if lcp:
                asset=next((x for x in live['resources'] if x['url']==lcp.get('resource')),None)
                # When a cross-origin image lacks timing access, retain the LCP time
                # but do not infer its post-resource render delay.
                lcp['render_delay_ms']=max(0,int(lcp['ms'])-int(asset['responseEndMs'])) if asset and asset.get('responseEndMs') else None
                if not lcp.get('resource'):
                    lcp['render_delay_ms']=max(0,int(lcp['ms'])-int(live['ttfbMs']))
            return {'url':url,'http_status':response.status,'mobile_viewport':390,
                'title':live['title'],'uael_widgets':live['uael'],'css':styles,
                'horizontal_overflow_px':live['pageOverflow'],
                'ttfb_ms':int(live['ttfbMs']),'lcp':lcp,
                'catalog_initial':live['catalogInitial'],
                'catalog_ui_cards':live['catalogUICards'],
                'chosen_without_href':live['chosenWithoutHref'],
                'ard_href':live['ardHref'],
                'ard_manifest_valid':ard_valid,
                'html_cache_status':response.headers.get('x-mad4b-preset-cache',''),
                'header_literal_text_count':live['headerLiteralText'],
                'forced_layout':live['forcedLayout'],
                'swiper_active_inert':live['swiperActiveInert'],
                'is_tour_single':live['isTourSingle'],
                'departure_details':live['departureDetails'],
                'mobile_toc_bottom_gap_px':live['mobileTOCBottomGap'],
                'tour_h2':live['tourH2'],
                'tour_rate_css':live['tourRateCSS'],
                'accommodation_visibility':live['accommodationVisibility'],
                'mobile_toc_gap_px':live['mobileTourTOCGap'],
                'city_lcp_preload':live['cityLcpPreload'],
                'city_lcp_preload_head_offset':raw.find('id="mad4b-city-first-slide-lcp"'),
                'city_lcp_request_entries':live['cityLcpRequests'],
                'inline_css_bytes':sum(x['bytes'] for x in parser.inline_styles),
                'inline_css_largest':sorted(parser.inline_styles,key=lambda x:x['bytes'],reverse=True)[:5],
                'external_google_fonts':live['externalGoogleFonts']}
        finally:
            await page.close()
def classify(pages,audit=None):
    """Produce stable, per-URL CSS alerts only for measured mobile blocking assets."""
    issues=[]
    for page in pages:
        urlpath=urlsplit(page['url']).path
        seen_uael=any(x['height']>12 for x in page['uael_widgets'])
        for style in page['css']:
            kb=style['encoded_bytes']/1024
            if not style['blocking'] or kb<64:continue
            identity=urlpath+'|'+style['handle']
            code='perf_rbcss_'+hashlib.sha256(identity.encode()).hexdigest()[:12]
            label=f'Mobile render-blocking {style["handle"] or "CSS"} ({kb:.0f} KiB transfer) on {urlpath}; '+(
                'UAEL widget present — retain styles.' if seen_uael else 'No visible UAEL widget — defer CSS safely.'
            )
            issues.append({'code':code,'severity':'high','label':label[:225]})
    tour_pages=[page for page in pages if page.get('is_tour_single')]
    presentation_issues={}
    for page in tour_pages:
        path=urlsplit(page['url']).path
        departure=page.get('departure_details') or {}
        if int(departure.get('unexpected_visible') or 0)>0 or int(departure.get('continent_visible') or 0)>0:
            presentation_issues.setdefault(path,[]).append({'code':'perf_rate_origin_ui','severity':'high',
                'label':'Unconfirmed Rate detail is visible or continent is presented as Tour-origin; use related Tour metadata and False-by-default JetEngine glossary switch.'})
        # Only unconfirmed pricing labels are checked; opt-in Rate labels can show a real day.
        if int(departure.get('stale_default_label_count') or 0)>0:
            presentation_issues.setdefault(path,[]).append({'code':'perf_rate_schedule_default','severity':'high',
                'label':'Unconfirmed Friday/Afternoon/Africa default leaked into customer-facing Rate pricing titles.'})
        bottom=page.get('mobile_toc_bottom_gap_px')
        if isinstance(bottom,(int,float)) and (bottom>32 or bottom<8):
            presentation_issues.setdefault(path,[]).append({'code':'perf_toc_bottom_gap','severity':'medium',
                'label':f'Mobile Tour contents to first H2 gap {round(bottom)}px outside accepted 8–32px range.'})
        h2=page.get('tour_h2') or {}
        if h2 and h2.get('global_secondary'):
            hexc=str(h2['global_secondary']).lstrip('#')
            if len(hexc)==6 and all(c in '0123456789abcdefABCDEF' for c in hexc):
                expected='rgb('+', '.join(str(int(hexc[i:i+2],16)) for i in (0,2,4))+')'
                if h2.get('color')!=expected:
                    presentation_issues.setdefault(path,[]).append({'code':'perf_tour_h2_global','severity':'medium',
                        'label':'Tour editorial H2 color no longer follows Elementor Global Secondary color.'})
        accommodations=page.get('accommodation_visibility') or {}
        if int(accommodations.get('invalid_visible') or 0)>0:
            presentation_issues.setdefault(path,[]).append({'code':'perf_empty_accommodation_ui','severity':'high',
                'label':'Tour rate selector is exposing empty/ineligible hotel alternatives. Hide optional accommodation until Rate has valid published Properties.'})
        gap=page.get('mobile_toc_gap_px')
        if isinstance(gap,(int,float)) and gap<8:
            presentation_issues.setdefault(path,[]).append({'code':'perf_mobile_toc_spacing','severity':'medium',
                'label':'Mobile Tour Contents lacks minimum spacing below the preceding section; preserve Elementor navigation and desktop layout.'})
    unique={}
    for path,events in presentation_issues.items():
        for issue in events:
            code=issue['code']
            if code not in unique:
                issue['label']=issue['label']+' Sample: '+path
                unique[code]=issue
    issues.extend(unique.values())
    # The same published tour should not lose all rate cards in a translated locale.
    def canonical(path):
        return re.sub(r'^/(ar|fr|de|es|it)(?=/)', '', path)
    grouped={}
    for page in tour_pages:
        path=urlsplit(page['url']).path
        group=grouped.setdefault(canonical(path),{})
        locale=re.match(r'^/(ar|fr|de|es|it)/',path)
        group[locale.group(1) if locale else 'en']=int((page.get('departure_details') or {}).get('label_count') or 0)
    missing=[]
    for source,langs in grouped.items():
        if langs.get('en',0)>0:
            missing.extend([source+' ['+l+']' for l,n in langs.items() if l!='en' and n==0])
    if missing:
        issues.append({'code':'perf_rate_locale_parity','severity':'high',
            'label':'Translated Tour Rate lists missing where English has prices: '+', '.join(missing[:3])})
    for page in pages:
        if urlsplit(page['url']).path!='/tours-and-activities/unforgettable-egypt-budget-tour-cairo-to-luxor-nile-cruise-discovery-8-days/':continue
        rate=page.get('tour_rate_css') or {}
        healthy=bool(rate.get('shadow_present')) and (rate.get('card_count') or 0)>=2 \
            and isinstance(rate.get('font_px'),(int,float)) and 10<=rate['font_px']<=23 \
            and isinstance(rate.get('radius_px'),(int,float)) and rate['radius_px']>=12 \
            and rate.get('bg') not in (None,'rgba(0, 0, 0, 0)','transparent')
        if not healthy:
            issues.append({'code':'perf_ratecss_product','severity':'high',
                'label':'Tour rates accordion lost critical Elementor #56197 style parity or fallback on 8-day product; check CSS delivery and scheduled regeneration.'})
        elif not rate.get('original_present'):
            issues.append({'code':'perf_ratecss_source_missing','severity':'medium',
                'label':'Elementor #56197 CSS is missing but MAD4B verified shadow keeps the tour rate cards styled; repair original generated asset.'})
    for page in pages:
        path=urlsplit(page['url']).path
        cat=page.get('catalog_initial') or {}
        if re.fullmatch(r'/(?:ar/|fr/|de/|es/|it/)?tours-and-activities/',path):
            if not cat.get('style_present') or not cat.get('present') or cat.get('layout_direction')!='row':
                issues.append({'code':'perf_catalog_initial_layout','severity':'high',
                    'label':'Initial Tour catalog List View lacks native Elementor CSS/Flex direction; preserve original template dimensions without user layout toggles.'})
        if re.fullmatch(r'/(?:ar/|fr/|de/|es/|it/)?tours-and-activities/',path):
            ui=page.get('catalog_ui_cards') or {}
            faults=[]
            for card in (ui.get('samples') or []):
                for name,limit in [('arrow',27),('clock',27),('favorite',34)]:
                    m=card.get(name)
                    if m and (m.get('width',0)<8 or m.get('width',0)>limit or
                        m.get('height',0)<8 or m.get('height',0)>limit):
                        faults.append(name+' '+str(m.get('width'))+'x'+str(m.get('height')))
                price=card.get('price')
                if price:
                    amount=card.get('amount') or {}
                    unit=card.get('unit') or {}
                    widget=card.get('widget') or {}
                    card_box=card.get('card') or {}
                    if (price.get('font_px',0)<10 or price.get('font_px',0)>22 or
                        price.get('width',0)>card_box.get('width',0) or
                        price.get('height',0)>110 or
                        amount.get('font_px',0)>24 or
                        unit.get('font_px',0)>18 or
                        widget.get('height',0)>115):
                        faults.append('rate-derived Elementor price style '+str(price))
                elif card.get('widget'):
                    # Price absent is an eligible empty-state, not synthetic pricing.
                    pass
            if faults:
                issues.append({'code':'perf_catalog_svg_price_first_paint','severity':'high',
                    'label':('Initial Catalog List card SVG or governed rate-derived global price typography is oversized on '
                             +path+': '+', '.join(faults[:3]))[:225]})
        if path in ('/fr/tours-and-activities/','/de/tours-and-activities/') and cat.get('structural_drift'):
            issues.append({'code':'perf_catalog_template_drift','severity':'medium',
                'label':'French/German JetEngine List template has older Elementor element topology (0904328 instead of 82a8340); review governed WPML design sync without replacing translated content.'})
        if int(page.get('chosen_without_href') or 0)>0:
            issues.append({'code':'perf_chosen_crawlability','severity':'medium',
                'label':'JetSearch Chosen Tour Type control has an anchor without a valid public archive href on '+path})
        if path=='/' and page.get('ard_manifest_valid') is False:
            issues.append({'code':'perf_ard_manifest','severity':'high',
                'label':'Canonical ARD v0.91 endpoint is invalid, unavailable, or does not declare a public site-anchored resource.'})
        if page.get('ard_href') and page.get('ard_href')!='https://tours.dev.mad4b.com/.well-known/ard.json':
            issues.append({'code':'perf_ard_manifest','severity':'medium',
                'label':'ARD link points to a translated or invalid manifest URL; use canonical origin root.'})
    bad_header=[urlsplit(p['url']).path for p in pages if int(p.get('header_literal_text_count') or 0)>0]
    if bad_header:
        issues.append({'code':'perf_header_escape_text','severity':'high',
            'label':'Literal escaped linebreaks render above the header on '+', '.join(bad_header[:3])+'; check head/preload source.'})
    for page in pages:
        if int(page.get('swiper_active_inert') or 0)>0:
            path=urlsplit(page['url']).path
            issues.append({'code':'perf_swiper_focus_'+hashlib.sha256(path.encode()).hexdigest()[:12],
                'severity':'high','label':'An active Swiper slide became inert on '+path+'; preserve keyboard interaction.'})
    home=next((p for p in pages if urlsplit(p['url']).path=='/'),None)
    if home:
        info=home.get('forced_layout') or {}
        if info.get('supported') and int(info.get('forced_ms') or 0)>=250:
            issues.append({'code':'perf_forced_reflow_home','severity':'high',
                'label':f'Home mobile long-animation-frame scripts forced {info["forced_ms"]}ms layout in {info.get("frames",0)} frames; inspect Elementor/Swiper/LiteSpeed script scheduling.'})
    for page in pages:
        if urlsplit(page['url']).path!='/':continue
        preload=page.get('city_lcp_preload') or {}
        lcp=page.get('lcp') or {}
        reason=''
        if not preload or preload.get('priority')!='high' or preload.get('as')!='image' \
            or int(page.get('city_lcp_preload_head_offset',-1))>8192 \
            or int(page.get('city_lcp_preload_head_offset',-1))<0:
            reason='Missing, late, or non-high-priority city LCP image preload'
        elif lcp.get('element_id')=='95d1c9c' and lcp.get('resource')!=preload.get('href'):
            reason='Preloaded image URL no longer matches the live visible city LCP image'
        elif lcp.get('element_id')=='95d1c9c' and lcp.get('loading')=='lazy':
            reason='Visible city LCP unexpectedly became lazy-loaded'
        elif len(page.get('city_lcp_request_entries') or [])>1:
            reason='First city-card image was fetched more than once'
        if reason:
            issues.append({'code':'perf_lcpdiscover_home','severity':'high',
                'label':reason+'; preserve JetEngine City Slider and global fonts.'})
        reqs=page.get('city_lcp_request_entries') or []
        if reqs and preload:
            response_start=int(page.get('ttfb_ms') or 0)
            gap=int(reqs[0].get('start_ms') or 0)-response_start
            if gap>500:
                issues.append({'code':'perf_lcp_request_wait','severity':'high',
                    'label':f'City LCP preload request started {gap}ms after HTML first byte; correct early image discovery without removing JetEngine background.'})
    for page in pages:
        total=int(page.get('inline_css_bytes') or 0)
        if total>=256*1024:
            path=urlsplit(page['url']).path
            code='perf_inlinecss_'+hashlib.sha256(path.encode()).hexdigest()[:12]
            largest=(page.get('inline_css_largest') or [{}])[0]
            issues.append({'code':code,'severity':'high',
                'label':f'Mobile HTML embeds {total//1024}KiB inline CSS on {path}; largest block {largest.get("id","")}. Consider governed external/critical split; do not remove widget CSS.'})
    # Global, fixed issue codes occur only once per complete scan.
    uniques={}
    for issue in issues:
        uniques.setdefault(issue['code'],issue)
    issues=list(uniques.values())
    # A small external font CSS file may still add hundreds of milliseconds
    # through third-party connection setup, independent of its transfer size.
    for page in pages:
        external=page.get('external_google_fonts') or []
        if external:
            path=urlsplit(page['url']).path
            code='perf_fontcss_'+hashlib.sha256(path.encode()).hexdigest()[:12]
            total=max(int(x.get('duration_ms') or 0) for x in external)
            issues.append({'code':code,'severity':'high',
                'label':f'External Google Fonts CSS unexpectedly requested on mobile {path}; maximum observed request {total}ms. Local font source should be used.'})
    delayed=[]
    for page in pages:
        lcp=page.get('lcp') or {}
        delay=lcp.get('render_delay_ms')
        if isinstance(delay,(int,float)) and delay>=2500:
            path=urlsplit(page['url']).path
            code='perf_lcpdelay_'+hashlib.sha256(path.encode()).hexdigest()[:12]
            delayed.append({'code':code,'severity':'high','label':
                f'Mobile LCP element render delay {round(delay)}ms on {path}; '+
                f'element {lcp.get("tag","")}/{lcp.get("element_id","")}. Diagnose widget/layout scheduling; do not delete the element.'})
    issues.extend(delayed)
    # Read-only full Rate CPT integrity census: not limited to browser sample.
    if audit is not None:
        if not audit.get('schema_valid') or not audit.get('defaults_hidden'):
            issues.append({'code':'perf_rate_schema_drift','severity':'high',
                'label':'Tour Rate optional field default/glossary deviated from False and neutral; restore governed JetEngine CPT schema.'})
        if audit.get('opted_in_invalid_origin'):
            issues.append({'code':'perf_rate_origin_config','severity':'high',
                'label':'Opted-in Rate(s) lack a validated Tour origin: '+str(audit['opted_in_invalid_origin'][:8])})
        if audit.get('orphan_published_rate'):
            issues.append({'code':'perf_rate_orphan','severity':'high',
                'label':'Published Rate points to missing/wrong linked Tour: '+str(audit['orphan_published_rate'][:8])})
    # Require several sampled routes before treating the server latency as a site-level issue.
    slow=[p.get('ttfb_ms') for p in pages if isinstance(p.get('ttfb_ms'),(int,float))]
    if len(slow)>=7:
        median=sorted(slow)[len(slow)//2]
        if median>=2500:
            issues.append({'code':'perf_ttfb_site','severity':'high',
                'label':f'Staging mobile median TTFB {median}ms across {len(slow)} sampled pages; server/cache diagnosis required.'})
    for issue in issues:
        while len(issue['label'].encode('utf-8'))>225:
            issue['label']=issue['label'][:-1]
    return issues

async def run():
    if os.geteuid()!=0:raise RuntimeError('must_run_with_authorized_host_identity')
    OUT.mkdir(parents=True,exist_ok=True)
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,args=['--no-sandbox'])
        try:
            semaphore=asyncio.Semaphore(3)
            pages=await asyncio.gather(*[inspect(browser,x,semaphore) for x in PAGES])
        finally:await browser.close()
    audit_command=['/usr/bin/docker','exec','-u','www-data','etg-commerce-staging-php','wp','mad4b','rate-departure','audit']
    audit_result=subprocess.run(audit_command,capture_output=True,text=True,timeout=35)
    if audit_result.returncode:raise RuntimeError('rate_integrity_audit_unavailable:'+audit_result.stderr[:160])
    audit=json.loads(audit_result.stdout)
    if audit.get('site_uuid')!=SITE_ID or audit.get('scanned',0)<1:raise RuntimeError('rate_integrity_audit_identity_invalid')
    issues=classify(pages,audit)
    now=datetime.datetime.now(datetime.timezone.utc).isoformat()
    payload={'contract':CONTRACT,'complete':True,'site_uuid':SITE_ID,'origin':ORIGIN,
        'observed_at':now,'pages':pages,'rate_audit':audit,'issues':issues}
    OUTPUT.write_text(json.dumps(payload,ensure_ascii=False,indent=2),encoding='utf-8')
    # Pre-authorized WPCLI exact site identity; no login impersonation.
    bind=[
      '-e','MAD4B_CLI_TARGET_SITE_UUID='+SITE_ID,
      '-e','MAD4B_CLI_TARGET_ORIGIN='+ORIGIN,
      '-e','MAD4B_CLI_TARGET_DATABASE=etg_commerce_staging',
      '-e','MAD4B_CLI_ALLOWED_WRITE_ENVIRONMENTS=staging'
    ]
    cli=['/usr/bin/docker','exec','-u','www-data']+bind+['etg-commerce-staging-php','wp']
    identity=subprocess.run(cli+['option','get','home'],capture_output=True,text=True,timeout=16)
    if identity.returncode or identity.stdout.strip()!=ORIGIN:
        raise RuntimeError('site_identity_readback_failed')
    copy=subprocess.run(['/usr/bin/docker','cp',str(OUTPUT),'etg-commerce-staging-php:/tmp/mad4b-live-css-scan.json'],
       capture_output=True,text=True,timeout=15)
    if copy.returncode:raise RuntimeError('local_evidence_copy_failed')
    ingest=subprocess.run(cli+['mad4b','perf','monitor','import',
        '--file=/tmp/mad4b-live-css-scan.json','--confirm=IMPORT LIVE CSS EVIDENCE'],
        capture_output=True,text=True,timeout=30)
    if ingest.returncode:raise RuntimeError('WP_CLI_evidence_ingest_failed:'+ingest.stderr[:250])
    print(json.dumps({'site':ORIGIN,'pages':len(pages),'uael_mobile':[
        {'url':urlsplit(pg['url']).path,'has_visible_uael':any(x['height']>12 for x in pg['uael_widgets']),
         'uael_css':next(({'blocking':s['blocking'],'media_initial':s['media_initial'],
             'encoded_kib':round(s['encoded_bytes']/1024,1)} for s in pg['css']
             if s['handle']=='uael-frontend-css'),None)} for pg in pages],
        'issues':issues,'wpcli_import':json.loads(ingest.stdout)},ensure_ascii=False,indent=2))
if __name__=='__main__':asyncio.run(run())
