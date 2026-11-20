#!/usr/bin/env python3
"""Company-scoped source-template certification registry, not route CSS promotion.

The source QA canaries certify the reusable Elementor/JetEngine source behavior.
Pages never inherit a certificate for a different CSS, first-fold image, WCML
market, document graph or route. Route CSS optimization remains guarded by 422.
"""
import collections,hashlib,importlib.util,json,pathlib,re,sys,time
from pathlib import Path
BASE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('public_route_queue',BASE/'public-route-queue.py')
queue=importlib.util.module_from_spec(spec);spec.loader.exec_module(queue)
ROOTS={
 'source':('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content','tours.dev.mad4b.com'),
 'wayfa':('/opt/wayfa-rebuild-20261102/site-copy/wp-content','wayfatours.com'),
}
CONTRACT='mad4b.public-css.source-family-registry.v1'
CERT='mad4b.public-critical-css.route-certificate.v1'
v2spec=importlib.util.spec_from_file_location(
    'mad4b_source_family_certified_v2_adapter',
    BASE/'public-v2-family-canary.py')
v2=importlib.util.module_from_spec(v2spec)
v2spec.loader.exec_module(v2)
MARKET_RE=re.compile(r'^(?:[A-Z]{2}|base|static)\|(?:[A-Z]{3}|NONE)$')
PROFILE_RE=re.compile(r'^(?:shared-template|elementor-document|taxonomy|archive):[A-Za-z0-9:/_-]{1,140}$')

def source_profile(route):
    value=route.get('source_profile')
    # Legacy catalog without a WP-attested source pointer is not groupable.
    if isinstance(value,str) and PROFILE_RE.fullmatch(value):return value
    return 'legacy-route:'+route['route_key']

def family_key(catalog,state,route):
    graph=route.get('source_graph')
    graph_ok=(isinstance(graph,str) and re.fullmatch('[a-f0-9]{64}',graph)
              and route.get('source_graph_status')=='configured')
    # Only the WordPress-attested plain-source negative can exclude an
    # unrelated tour/listing update. Missing/ambiguous data stays fail-closed.
    needs_listing=(not graph_ok or route.get('source_listing_dependency') is not False
                   or route['type'] not in ('page','post'))
    fields=[catalog['host'],route['type'],route['language'],source_profile(route),
            graph if graph_ok else 'legacy-or-unresolved',
            str(catalog.get('global_generation',0)),
            str(catalog.get('listing_generation',0)) if needs_listing else '',
            str(state['css_epoch']),str(state['native_breakpoint']),
            str(state.get('runtime_sha','')),
            ','.join(sorted(state.get('enabled_currencies',[]))),
            str(bool(state.get('wcml_multi_currency',False)))]
    return hashlib.sha256('|'.join(fields).encode()).hexdigest()

def asset(root,subdir,name,pat,maxbytes):
    if not isinstance(name,str) or not re.fullmatch(pat,name):return None
    file=root/'mad4b-public-critical-css-v1'/subdir/name
    if file.is_symlink() or not file.is_file() or file.stat().st_size>maxbytes:return None
    return file

def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()

def valid_canary(root,host,route,state,catalog,ledger_record,cert):
    if not isinstance(cert,dict) or not isinstance(ledger_record,dict):return False
    if cert.get('contract')==v2.CONTRACT:
        try:
            return v2.validate(root,host,route,state,catalog,ledger_record,cert,
                queue.source_key(catalog,route,state,
                    bool(ledger_record.get('depends_on_listings'))))
        except (ValueError,OSError,KeyError,TypeError,UnicodeError):
            return False
    if ledger_record.get('status')!='certified':return False
    if ledger_record.get('source_key')!=queue.source_key(catalog,route,state,bool(ledger_record.get('depends_on_listings'))):return False
    for name,value in (
       ('contract',CERT),('status','certified'),('host',host),('type',route['type']),
       ('object_id',route['id']),('path',route['path']),('language',route['language']),
       ('css_epoch',state['css_epoch']),('runtime_sha',state['runtime_sha']),
       ('native_mobile_breakpoint',767),('critical_cap_px',767)):
        if cert.get(name)!=value:return False
    proof=cert.get('evidence',{})
    if (not isinstance(proof,dict) or proof.get('decision')!='passed_evidence'
        or proof.get('tested')!=5 or proof.get('viewports')!=[320,390,414,767,768]):return False
    deps=cert.get('dependencies',{})
    if not isinstance(deps,dict) or deps.get('route_generation')!=route.get('revision'):return False
    if deps.get('global_generation')!=catalog.get('global_generation'):return False
    if deps.get('contains_dynamic_listing') and deps.get('listing_generation')!=catalog.get('listing_generation'):return False
    if not re.fullmatch(r'[a-f0-9]{64}',str(cert.get('elementor_doc_fingerprint',''))):return False
    market=cert.get('market_country');currency=cert.get('currency')
    if not isinstance(market,str) or not isinstance(currency,str) or not MARKET_RE.fullmatch(market+'|'+currency):return False
    key=hashlib.sha256((host+'|'+route['language']+'|'+route['path']+'|'+market+'|'+currency).encode()).hexdigest()
    if root is None:return False
    pub=root/'mad4b-public-critical-css-v1'/'certs'/(key+'.json')
    if pub.is_symlink() or not pub.is_file() or pub.stat().st_size>8192:return False
    # The cohort scanner's certificate must be the actual published version.
    try:
        if json.loads(pub.read_text())!=cert:return False
    except (OSError,ValueError,TypeError,json.JSONDecodeError):return False
    css=cert.get('css_file')
    if not isinstance(css,str) or not re.fullmatch(r'[a-f0-9]{32}\.css',css):return False
    source=root/'litespeed/css'/css
    if source.is_symlink() or not source.is_file():
        source=root/'litespeed/.mad4b-asset-retention/css'/css
    if source.is_symlink() or not source.is_file() or source.stat().st_size>6291456 or sha(source)!=cert.get('css_sha'):return False
    critical=asset(root,'assets',cert.get('critical_file'),r'[a-f0-9]{64}\.css',1048576)
    if critical is None or critical.stat().st_size!=cert.get('critical_bytes') or sha(critical)!=cert.get('critical_sha'):return False
    return True

def load_certs(root):
    rows={};base=root/'mad4b-public-critical-css-v1/certs'
    if base.is_symlink():raise ValueError('unsafe-source-certificate-directory')
    if not base.exists():return v2.load_into(root,rows)
    for p in base.glob('*.json'):
        if p.is_symlink() or p.stat().st_size>8192 or not re.fullmatch(r'[a-f0-9]{64}\.json',p.name):continue
        try:
            cert=json.loads(p.read_text());route=(cert['language'],cert['path'],cert['market_country'],cert['currency'])
            if route in rows:raise ValueError('multiple-current-market-certificates')
            rows[route]=cert
        except (OSError,ValueError,KeyError,TypeError,json.JSONDecodeError):continue
    return v2.load_into(root,rows)

def split_observed_variants(site,catalog,state,ledger,published,rows,variant_index):
    """Split only actual first-party Elementor DOM variants.

    Light probes are scheduling observations. They are not certificates,
    never qualify unmeasured CSS and never override original market gates.
    An unobserved sibling remains mapping-pending, not 'QA failed'.
    """
    if variant_index is None:return {},{}
    from importlib.util import spec_from_file_location,module_from_spec
    spec=spec_from_file_location('mad4b_family_template_variant',
                             BASE/'public-template-variant-index.py')
    variants=module_from_spec(spec);spec.loader.exec_module(variants)
    if (not isinstance(variant_index,dict)
        or variant_index.get('contract')!=variants.CONTRACT
        or variant_index.get('site')!=site or variant_index.get('host')!=catalog['host']):
        raise ValueError('wrong-original-template-variant-index')
    original=collections.defaultdict(list)
    for r in rows:original[family_key(catalog,state,r)].append(r)
    known={}
    for fkey,members in original.items():
        route_facts={}
        for r in members:
            rec=ledger['routes'].get(r['route_key'],{})
            certified=set()
            for (lang,path,market,currency),cert in published.items():
                if lang!=r['language'] or path!=r['path']:continue
                if valid_canary(Path(ROOTS[site][0]),catalog['host'],r,state,catalog,rec,cert):
                    certified.add((cert['elementor_doc_fingerprint'],market,currency))
            observed=variants.current_observation(
                variant_index,catalog,r,state,ledger,now=int(time.time()))
            if observed:
                # If the original browser measured two different DOMs for
                # the same exact route/market, don't accept a cheap probe
                # as a replacement for the certified proof.
                fact=(observed['doc_sha'],observed['market'],observed['currency'])
                if certified and fact not in certified:observed=None
            route_facts[r['route_key']]=(
                sorted(certified)[0] if len(certified)==1 else
                (observed['doc_sha'],observed['market'],observed['currency'])
                if observed and not certified else None)
        present={fact for fact in route_facts.values() if fact is not None}
        if len(present)>1:
            known[fkey]=route_facts
    return known,original

def build(site,catalog,state,ledger,root=None,variant_index=None):
    if site not in ROOTS or catalog.get('host')!=ROOTS[site][1] or ledger.get('site')!=site:
        raise ValueError('source-company-scope-mismatch')
    if state.get('site_host')!=catalog['host'] or state.get('critical_css')!='on' or not isinstance(state.get('css_epoch'),int):
        raise ValueError('source-engine-context-invalid')
    if root is None:root=Path(ROOTS[site][0])
    if str(root)!=ROOTS[site][0] or root.is_symlink():raise ValueError('untrusted-source-site-root')
    # Home and translated Home pages belong to a separate original CSS
    # certifier. Never nominate them as source-family representative
    # canaries: their native selective per-route cache release disallows
    # Home and would otherwise waste one entire expensive Chromium slot.
    catalogrows=[r for r in queue.safe_catalog(catalog,site)
                 if not queue.independently_certified_home(r)]
    published=load_certs(root)
    by_route=collections.defaultdict(list)
    for (lang,path,market,currency),cert in published.items():
        by_route[(lang,path)].append((market,currency,cert))
    groups=collections.defaultdict(list)
    for r in catalogrows:groups[family_key(catalog,state,r)].append(r)
    split_facts,_=split_observed_variants(
        site,catalog,state,ledger,published,catalogrows,variant_index)
    if split_facts:
        partition=collections.defaultdict(list)
        meta={}
        for base_key,members in groups.items():
            mapping=split_facts.get(base_key)
            if not mapping:
                partition[base_key].extend(members)
                continue
            for r in members:
                fact=mapping.get(r['route_key'])
                if fact:
                    doc,market,currency=fact
                    token='doc:'+doc+'|'+market+'|'+currency
                    key=hashlib.sha256((base_key+'|'+token).encode()).hexdigest()
                    meta[key]={'document_sha':doc,'market_country':market,
                               'currency':currency,'mapped':True}
                else:
                    key=hashlib.sha256((base_key+'|unmapped-source-variant').encode()).hexdigest()
                    meta[key]={'mapped':False}
                partition[key].append(r)
        groups=partition
    else:
        meta={}
    rows=[];qcounts=collections.Counter();qualified_routes=0
    for key,members in sorted(groups.items()):
        head=members[0];candidates=[];seen=set()
        variant_meta=meta.get(key,{})
        mapping_pending=variant_meta.get('mapped') is False
        for r in members:
            record=ledger['routes'].get(r['route_key'],{})
            for market,currency,cert in by_route.get((r['language'],r['path']),[]):
                if not valid_canary(root,catalog['host'],r,state,catalog,record,cert):continue
                candidates.append({'path':r['path'],'route_key':r['route_key'],'market':market,
                    'currency':currency,'css_sha':cert.get('css_sha') or cert.get('source_manifest_sha256'),
                    'doc_sha':cert['elementor_doc_fingerprint']})
                seen.add(r['route_key'])
        # Two distinct real browser QA canaries, same shared template DOM
        # and market, establish *source behavior*, not route CSS equivalence.
        # A unique Elementor-authored document or singleton needs only itself.
        shared=source_profile(head).startswith(('shared-template:','taxonomy:')) and len(members)>1
        graph_unresolved=any(r.get('source_graph_status')=='ambiguous' for r in members)
        supported=state.get('enabled_currencies')
        if not isinstance(supported,list) or not supported:
            supported=[state.get('base_currency','USD')]
        supported=sorted(set(v for v in supported
            if isinstance(v,str) and re.fullmatch('[A-Z]{3}',v)))
        if not supported:raise ValueError('source-required-currency-scope-unknown')
        geo_dynamic=bool(state.get('wcml_multi_currency',False))

        permarket=collections.defaultdict(list)
        for c in candidates:permarket[c['market']+'|'+c['currency']].append(c)
        proven={};divergent=[];context_evidence=[]
        required_samples=2 if shared else 1
        for market,examples in sorted(permarket.items()):
            first={v['route_key'] for v in examples}
            docs={v['doc_sha'] for v in examples}
            context_status=('divergent_source_variants' if len(docs)>1
                            else 'canary_qualified' if len(first)>=required_samples
                            else 'awaiting_independent_canary')
            context_evidence.append({
                'market_currency':market,'source_canary_status':context_status,
                'independent_routes':len(first),'document_variants':len(docs),
                'required_canaries':required_samples,
                # A source-behaviour proof never grants another route's CSS.
                'route_css_asset_transfer_authorized':False})
            if context_status=='divergent_source_variants':divergent.append(market)
            elif context_status=='canary_qualified':proven[market]=len(first)
        # Bound automatic sampling to a handful of independent exemplars
        # per *source generation*. A broken group with hundreds of tours
        # must not become the old page-by-page QA queue in disguise.
        attempted=[]
        for r in members:
            rec=ledger['routes'].get(r['route_key'],{})
            if (isinstance(rec,dict) and rec.get('attempted_at')
                and rec.get('source_key')==queue.source_key(catalog,r,state,
                    bool(rec.get('depends_on_listings')))
                # Count only unsuccessful attempts from the same exact
                # QA implementation. Older negative proofs must not cap
                # requalification when the original verifier changes.
                and rec.get('qa_policy_sha')==state.get('qa_policy_sha')):
                attempted.append(r['route_key'])
        bounded=len(set(attempted))>=4
        no_bundle=set()
        for r in members:
            rec=ledger['routes'].get(r['route_key'],{})
            if (not isinstance(rec,dict) or rec.get('status')!='hold'
                or rec.get('hold_reason')!='source_css_bundle_unavailable'):
                continue
            # Once the original optimizer contract has been enrolled, old
            # negative captures without its signature NEVER qualify. Nor may
            # a stale route source_key bypass a changed 406 or LiteSpeed ABI.
            current=queue.optimizer_source_key(catalog,r,state)
            if current is not None:
                accepted=rec.get('optimizer_source_key')==current
            elif state.get('source_optimizer_sha'):
                accepted=False
            else:
                accepted=(rec.get('source_key')==queue.source_key(
                    catalog,r,state,bool(rec.get('depends_on_listings'))))
            if accepted:no_bundle.add(r['route_key'])
        verified_currencies={market.split('|',1)[1] for market in proven}
        currency_coverage_complete=set(supported).issubset(verified_currencies)
        # Geo-activated WCML can negotiate country-specific HTML/price/layout.
        # Do not claim all countries are tested from base USD evidence.
        full_market_coverage=currency_coverage_complete and not geo_dynamic
        status=('source_canary_qualified' if proven and full_market_coverage
                else 'source_canary_market_partial' if proven
                else 'source_canary_pending')
        if graph_unresolved:status='source_graph_unresolved'
        elif mapping_pending:status='source_variant_mapping_pending'
        elif divergent:status='source_variants_need_split'
        elif not proven and len(no_bundle)>=2:
            status=('source_native_optimizer_unavailable' if not geo_dynamic
                    and len(supported)==1
                    else 'source_native_optimizer_market_partial')
        elif bounded and not proven:status='source_canary_hold'
        # Different conditional Theme Builder documents are different true
        # sources even when WordPress calls them the same CPT/archive type.
        # Never spend 1,000 route QA runs chasing a false shared family.
        if status in ('source_variants_need_split','source_variant_mapping_pending',
                       'source_graph_unresolved','source_native_optimizer_unavailable',
                       'source_native_optimizer_market_partial'):
            next_route=None
        else:
            choices=[]
            for r in members:
                if (r['route_key'] in seen
                    or (bounded and r['route_key'] not in attempted)
                    or (r['route_key'] in no_bundle and len(no_bundle)<2
                        and len(members)>len(no_bundle))):continue
                rec=ledger['routes'].get(r['route_key'],{})
                if not isinstance(rec,dict):continue
                keynow=queue.source_key(catalog,r,state,bool(rec.get('depends_on_listings')))
                unchanged=rec.get('source_key')==keynow
                if unchanged and rec.get('status') in ('certified','native_preferred','unsupported'):continue
                if unchanged and time.time()<int(rec.get('retry_after',0)):continue
                choices.append((0 if not rec else 1,int(rec.get('failures',0)),r['path'],r['route_key']))
            choices.sort()
            next_route=choices[0][3] if status!='source_canary_qualified' and choices else None
        qcounts[status]+=1;qualified_routes+=len(seen)
        rows.append({'family_key':key,'source_profile':source_profile(head),'route_type':head['type'],
          # Always-on compilation and dependency scheduling are policies;
          # Chromium outcome is a separate, post-compilation observation.
          # A source-family status is never an activation veto.
          'policy_state':'active',
          'quality_checks':'observational_nonblocking_for_policy',
          'route_css_assets_require_exact_first_party_integrity':True,
          'observed_variant':variant_meta,
          'language':head['language'],'member_count':len(members),'canary_count':len(seen),'attempted_canary_count':len(set(attempted)),
          'native_no_bundle_canaries':len(no_bundle),
          'status':status,'source_graph_status':head.get('source_graph_status','legacy'),
          'source_graph':head.get('source_graph'),
          'required_currencies':supported,
          'currency_profiles_proven':sorted(verified_currencies),
          'currencies_pending':sorted(set(supported)-verified_currencies),
          'market_country_scope':'dynamic_not_enumerated' if geo_dynamic else 'base',
          'market_coverage_complete':full_market_coverage,
          'proven_market_profiles':proven,'divergent_market_profiles':divergent,
          # Market+currency canary evidence is independently reported, even
          # when another WCML currency or geo context remains unqualified.
          # This is observability only: no public CSS asset reuse or policy gate.
          'source_context_evidence':context_evidence,
          'cross_route_source_behaviour_proven':bool(shared and proven
              and status=='source_canary_qualified'),
          'observed_source_variants':[
              {'market':market,'doc_sha':doc,
               'routes':sorted({c['route_key'] for c in candidates
                   if c['market']+'|'+c['currency']==market and c['doc_sha']==doc})}
              for market in sorted(permarket)
              for doc in sorted({c['doc_sha'] for c in permarket[market]})],
          'sample_paths':[c['path'] for c in candidates[:5]],
          'source_routes_not_individually_certified':len(members)-len(seen),
          'next_canary':next_route})
    # The source coverage is not a claim that unsampled pages now receive
    # route-specific mobile Critical CSS. Those use unchanged native CSS.
    order={'tours-and-activities':0,'properties':1,'destinations':2,
           'tour-types':3,'property-types':4,'tour-categories':5,'category':6,
           'page':7,'post':8,'archive:tours-and-activities':9,'archive:properties':10}
    wants=[r for r in rows if r['next_canary']]
    wants.sort(key=lambda r:(0 if r['status']=='source_canary_pending' else 1,
      order.get(r['route_type'],12),r['language'],r['source_profile']))
    return {'contract':CONTRACT,'site':site,'host':catalog['host'],
      'css_epoch':state['css_epoch'],'global_generation':catalog.get('global_generation'),
      'listing_generation':catalog.get('listing_generation'),
      'source_groups_total':len(rows),'source_groups_by_status':dict(sorted(qcounts.items())),
      'source_canary_qualified_groups':qcounts['source_canary_qualified'],
      'source_context_canary_proofs':sum(len(f['proven_market_profiles']) for f in rows),
      'source_context_divergent':sum(len(f['divergent_market_profiles']) for f in rows),
      'source_certified_singleton_groups':sum(1 for f in rows
          if f['member_count']==1 and f['status']=='source_canary_qualified'),
      'source_cross_route_behaviour_qualified_groups':sum(1 for f in rows
          if f['cross_route_source_behaviour_proven']),
      'currency_scope_required':sorted(set(state.get('enabled_currencies',[]) or [state.get('base_currency','USD')])),
      'country_market_scope':'dynamic_not_enumerated' if state.get('wcml_multi_currency') else 'base',
      'routes_discovered':len(catalogrows),'routes_with_exact_current_certificate':qualified_routes,
      'routes_on_native_or_unproven_css':len(catalogrows)-qualified_routes,
      'source_families':rows,'next_canary_route_key':wants[0]['next_canary'] if wants else None,
      'policy_contract':'mad4b.public-css.template-aware-policy.v1',
      'policy_state':'active',
      'quality_checks':'observational_nonblocking_for_policy',
      'variant_mapping_pending_routes':sum(r['member_count'] for r in rows
                                          if r['status']=='source_variant_mapping_pending'),
      'source_lineage_note':'Source behavior proof requires two exact five-viewport canaries (or singleton); unsampled routes do not inherit CSS assets or route-ready headers.'}


def main():
    if len(sys.argv)!=5:raise ValueError('usage: site catalog.json state.json ledger.json')
    site,cat,st,ledger=sys.argv[1:]
    result=build(site,queue.read(cat),queue.read(st),queue.read_ledger(Path(ledger),site))
    print(json.dumps(result,sort_keys=True,indent=2))

if __name__=='__main__':
    try:main()
    except (ValueError,KeyError,TypeError,OSError,json.JSONDecodeError) as exc:
        print('HOLD_PUBLIC_CCSS_SOURCE_REGISTRY',str(exc)[:160],file=sys.stderr);sys.exit(4)
