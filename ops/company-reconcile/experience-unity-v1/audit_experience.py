#!/usr/bin/env python3
"""Read-only cross-company user-experience contract acceptance.
All languages originate in the enrolled site's own WPML options; URL origin
must be enrolled. No WordPress, cache, filter, price or order writes.
"""
from __future__ import annotations
import concurrent.futures
import datetime
import html
import json
import pathlib
import re
import subprocess
import urllib.parse
import urllib.request

SITES=(
 ("source","https://tours.dev.mad4b.com","php-wkgtkzta35wkkgouwt6ruljc"),
 ("wayfatours","https://wayfatours.com","php-guxhp9y6vgh7oouuw8plxrlr"),
)
WP=r'''$expected=getenv('MAD4B_EXPECTED_ORIGIN');
if(untrailingslashit(home_url('/'))!==$expected)throw new RuntimeException('SITE_ORIGIN_MISMATCH');
$profile=class_exists('MAD4B_SCP_Site_Profile',false)?MAD4B_SCP_Site_Profile::status():[];
if(empty($profile['configured'])||empty($profile['origin_match'])||empty($profile['environment_match'])||empty($profile['deployment_binding_match']))
  throw new RuntimeException('SITE_PROFILE_UNVERIFIED');
if(!class_exists('MAD4B_Company_Country_Operating_Scope_V1')||!function_exists('mad4b_home_sections_active_languages'))
  throw new RuntimeException('EXPERIENCE_CONTRACT_MODULE_MISSING');
$active=mad4b_home_sections_active_languages();
$all=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
$look=get_option('look-and-feel',[]);
$service=get_option('service-activation',[]);
$dest=get_option('destination-activation',[]);
$front=(int)get_option('page_on_front');
global $wpdb;
$canonical_types=[];
foreach(['combined-tours','day-tours','nile-cruises'] as $slug){
 $canonical_types[$slug]=(int)$wpdb->get_var($wpdb->prepare(
   "SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt
    INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
    WHERE tt.taxonomy=%s AND t.slug=%s LIMIT 1",'tour-types',$slug));
}
$results=[];
foreach($active as $lang){
  do_action('wpml_switch_language',$lang);
  $home_id=(int)apply_filters('wpml_object_id',$front,'page',false,$lang);
  $tours=get_posts(['post_type'=>'tours-and-activities','post_status'=>'publish','numberposts'=>-1,'fields'=>'ids','suppress_filters'=>false]);
  $sample=(int)($tours[0]??0);
  $codes=[];$invalid=[];
  foreach($tours as $id){
    $decision=MAD4B_Company_Country_Operating_Scope_V1::decision((int)$id);
    $code=$decision['code'];
    $codes[$code]=($codes[$code]??0)+1;
    if($code!=='IN_SCOPE')$invalid[]=(int)$id;
  }
  $approved=native_destination_approved($lang);
  $facet=shared_presentation_published_facet_terms_v1('tours-and-activities','tour-types');
  $facet=array_map('intval',(array)$facet);
  $filter_types=[];
  foreach($canonical_types as $slug=>$canonical){
    $translated=(int)apply_filters('wpml_object_id',$canonical,'tour-types',false,$lang);
    $filter_types[$slug]=['term_id'=>$translated,
       'eligible_in_filter'=>$translated>0&&in_array($translated,$facet,true)];
  }
  $sections=[];
  foreach(['home_section_combined','home_section_nile'] as $key){
    $sections[$key]=[
      'enabled'=>($look[$key]??'on')!=='off',
      'policy_allowed'=>mad4b_home_sections_policy_allows($key),
      'has_content'=>mad4b_home_sections_has_content($key)];
  }
  $services=[];
  foreach(['tours','properties','transportations'] as $owner){
    $services[$owner]=[
      'enabled'=>service_activation_enabled($owner),
      'standalone'=>service_channel_allowed($owner,'standalone'),
      'discovery'=>service_channel_allowed($owner,'destination_discovery')];
  }
  $results[]=[
    'language'=>$lang,'home_id'=>$home_id,'home_status'=>$home_id?get_post_status($home_id):null,
    'home_url'=>(string)($all[$lang]['url']??''),
    'archive_url'=>get_post_type_archive_link('tours-and-activities'),
    'tour_id'=>$sample,'tour_url'=>$sample?get_permalink($sample):'',
    'published_tour_count'=>count($tours),'geographic_states'=>$codes,
    'invalid_published_tour_ids'=>$invalid,
    'country_menu'=>array_map(fn($v)=>['id'=>(int)$v['id'],'label'=>$v['label']],$approved),
    'home_sections'=>$sections,'filters_by_type'=>$filter_types,'services'=>$services];
}
$filters=[];foreach([23352,57372,57376,66104] as $id){
  $filters[]=['id'=>$id,'status'=>get_post_status($id),
    'query_builder_id'=>get_post_meta($id,'_query_builder_query',true),
    'taxonomy'=>get_post_meta($id,'_source_taxonomy',true),
    'source'=>get_post_meta($id,'_data_source',true)];
}
$holds=[];foreach([60619,60621,60625,60663,60665] as $id){
  if(get_post_type($id)!=='tours-and-activities')continue;
  $d=MAD4B_Company_Country_Operating_Scope_V1::decision($id);
  $holds[]=['id'=>$id,'status'=>get_post_status($id),'decision'=>$d['code'],
    'countries'=>$d['country_ids'],'reviewed_source_id'=>$d['reviewed_country_source_id']];
}
echo wp_json_encode(['contract'=>'mad4b.experience-unity-wp-readback.v1',
  'site'=>untrailingslashit(home_url('/')),'site_uuid'=>$profile['site_uuid']??null,
  'active_languages'=>$active,'language_rows'=>$results,
  'operating_countries'=>MAD4B_Company_Country_Operating_Scope_V1::operating_countries(),
  'catalog_countries'=>MAD4B_Company_Country_Operating_Scope_V1::catalog_countries(),
  'service_active'=>$service['active_services']??[],
  'has_pricing_workspace'=>class_exists('MAD4B_Pricing_Workspace_V1'),
  'filter_meta'=>$filters,'country_held_tours'=>$holds,
  'scope_module_sha'=>hash_file('sha256',WPMU_PLUGIN_DIR.'/020-mad4b-company-country-operating-scope-v1.php'),
  'read_only'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);'''
SECTION_MARKERS={
 "home_section_combined":"elementor-element-a28340c",
 "home_section_nile":"elementor-element-a43662b",
}
PRICE_MARKER="elementor-element-3f2b785"

def fetch(url:str,origin:str,kind:str,language:str,expected_sections=None):
    site=urllib.parse.urlsplit(origin);u=urllib.parse.urlsplit(url)
    if (u.scheme,u.hostname,u.port)!=(site.scheme,site.hostname,site.port):
        raise ValueError("UNENROLLED_LOCALE_ORIGIN:"+language)
    req=urllib.request.Request(url,headers={"User-Agent":"MAD4B-Experience-Unity-ReadOnly/1","Accept-Encoding":"identity"})
    with urllib.request.urlopen(req,timeout=35) as response:
        if urllib.parse.urlsplit(response.url).hostname!=site.hostname:
            raise ValueError("UNEXPECTED_HOST_REDIRECT:"+url)
        code=response.status; raw=response.read(1550000)
        if len(raw)>=1550000:raise ValueError("HTML_TOO_LARGE:"+url)
        body=html.unescape(raw.decode("utf-8","replace"))
        d={"language":language,"kind":kind,"http_status":code,
           "url":url,"bytes":len(raw),"read_only":True}
        if kind=="home":
            d["sections"]={k:(v in body) for k,v in SECTION_MARKERS.items()}
            expected={k:bool(x["enabled"] and x["policy_allowed"] and x["has_content"])
                      for k,x in expected_sections.items()}
            d["expected_sections"]=expected
            if d["sections"]!=expected:raise ValueError("HOME_POLICY_RENDER_MISMATCH:"+url+":"+str(d["sections"]))
        elif kind=="archive":
            d["price_tag_marker_count"]=body.count(PRICE_MARKER)
            d["listing_error_count"]=body.count("jet-listing-not-found")
            if d["price_tag_marker_count"]<1:
                raise ValueError("ARCHIVE_PRICE_TAG_NOT_RENDERED:"+url)
        elif kind=="tour":
            d["has_tour_content"]="elementor" in body.lower() and len(body)>20000
            if not d["has_tour_content"]:raise ValueError("TOUR_DETAILS_MISSING:"+url)
        return d

def probe(label,origin,container):
    cp=subprocess.run(["docker","exec","-e",f"MAD4B_EXPECTED_ORIGIN={origin}",
       container,"wp","--allow-root","--path=/var/www/html","eval",WP],
       capture_output=True,text=True,timeout=85)
    if cp.returncode:raise RuntimeError("WP_READBACK_FAILED:"+label+":"+cp.stderr[-250:])
    rows=[x for x in cp.stdout.splitlines() if x.startswith('{"contract":"mad4b.experience-unity-wp-readback.v1"')]
    if len(rows)!=1:raise RuntimeError("INVALID_READBACK_CARDINALITY:"+label)
    d=json.loads(rows[0])
    if d["site"]!=origin or not d["site_uuid"] or not d["read_only"]:
        raise ValueError("BINDING_MISMATCH:"+label)
    langs=d["active_languages"]
    if not langs or langs!=[v["language"] for v in d["language_rows"]]:
        raise ValueError("WPML_LANGUAGE_SET_MISMATCH:"+label)
    for row in d["language_rows"]:
        if row["home_status"]!="publish" or not row["home_url"] or not row["archive_url"]:
            raise ValueError("UNPUBLISHED_HOME_OR_ARCHIVE:"+row["language"])
        if row["invalid_published_tour_ids"]:
            raise ValueError("PUBLISHED_GEO_HOLD:"+label+":"+row["language"]+":"+str(row["invalid_published_tour_ids"]))
        if row["published_tour_count"]<=0 or not row["country_menu"]:
            raise ValueError("ACTIVE_TOURS_OR_HEADER_COUNTRIES_MISSING:"+label+":"+row["language"])
        if row["tour_id"]<=0 or not row["tour_url"]:
            raise ValueError("NO_PUBLISHED_TOUR_LINK:"+label+":"+row["language"])
        if not row["services"]["tours"]["standalone"]:
            raise ValueError("TOUR_SERVICE_NOT_ACTIVATED:"+label)
        if row["filters_by_type"]["combined-tours"]["eligible_in_filter"] != row["home_sections"]["home_section_combined"]["has_content"]:
            raise ValueError("COMBINED_FILTER_HOME_CONTENT_MISMATCH:"+label+":"+row["language"])
        if set(item["id"] for item in row["country_menu"])-set(d["operating_countries"]):
            raise ValueError("HEADER_COUNTRY_OUTSIDE_OPERATING_SCOPE:"+label)
    return d

def main():
    readbacks={name:probe(name,origin,c) for name,origin,c in SITES}
    if readbacks["source"]["scope_module_sha"]!=readbacks["wayfatours"]["scope_module_sha"]:
        raise ValueError("GEO_SCOPE_MODULE_DRIFT")
    if readbacks["wayfatours"]["operating_countries"]!=[665]:
        raise ValueError("WAYFA_COUNTRY_SCOPE_CHANGED")
    tasks=[]
    for name,origin,_ in SITES:
        for row in readbacks[name]["language_rows"]:
            lang=row["language"]
            tasks.extend([(name,origin,row["home_url"],"home",lang,row["home_sections"]),
                          (name,origin,row["archive_url"],"archive",lang,None),
                          (name,origin,row["tour_url"],"tour",lang,None)])
    results={name:[] for name,_,_ in SITES}
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as ex:
        jobs={ex.submit(fetch,url,origin,kind,lang,sect):(name,url) for name,origin,url,kind,lang,sect in tasks}
        for job in concurrent.futures.as_completed(jobs):
            name,url=jobs[job]
            try:results[name].append(job.result())
            except Exception as exc:raise RuntimeError("FRONTEND_CHECK_FAILED:"+url+":"+str(exc)) from exc
    report={"contract":"mad4b.experience-unity-end-to-end.v1",
       "observed_utc":datetime.datetime.now(datetime.timezone.utc).isoformat(),
       "state":"PASS_VERIFIED","read_only":True,
       "source_and_target":{name:{"runtime":readbacks[name],
                 "frontend":sorted(results[name],key=lambda x:(x["language"],x["kind"]))}
                  for name in results},
       "original313_release_certified":False,"source_tour_rate_or_page_writes":False}
    print(json.dumps(report,ensure_ascii=False,indent=2))
if __name__=="__main__":
    main()
