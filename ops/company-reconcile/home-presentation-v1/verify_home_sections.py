#!/usr/bin/env python3
"""Read-only, source-target MAD4B homepage activation acceptance.

No rate, WordPress data, menu or tenant writes. The source is the canonical
MAD4B Platform module, while a company without Platform uses its MU copy.
"""
from __future__ import annotations
import hashlib
import json
import pathlib
import re
import subprocess
import urllib.request
import urllib.parse
import datetime

SOURCE="https://tours.dev.mad4b.com"
TARGET="https://wayfatours.com"
SRC_CONTAINER="php-wkgtkzta35wkkgouwt6ruljc"
DST_CONTAINER="php-guxhp9y6vgh7oouuw8plxrlr"
SRC_PATH="/var/www/html/wp-content/plugins/mad4b-platform/modules/operations/mad4b-central-homepage-sections.php"
DST_PATH="/var/www/html/wp-content/mu-plugins/041-mad4b-company-home-sections-v1.php"
GIT_SOURCE=pathlib.Path("/opt/mad4b-company-provisioning/worktrees/price-tag-company-reconcile-20261117/wp-content/plugins/mad4b-platform/modules/operations/mad4b-central-homepage-sections.php")
WP=r'''$file=getenv('MAD4B_HOME_MODULE_FILE');
$origin=getenv('MAD4B_HOME_EXPECTED_ORIGIN');
if(untrailingslashit(home_url('/'))!==$origin)throw new RuntimeException('SITE_BINDING_FAILED');
if(!function_exists('mad4b_home_sections_has_content'))throw new RuntimeException('HOME_ENGINE_NOT_LOADED');
$profile=class_exists('MAD4B_SCP_Site_Profile',false)?MAD4B_SCP_Site_Profile::status():[];
if(!is_file($file) || !is_readable($file))throw new RuntimeException('HOME_MODULE_NOT_READABLE');
$options=get_option('look-and-feel',[]);
$front_initial=(int)get_option('page_on_front');
$active=mad4b_home_sections_active_languages();
$wpml=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
$rows=[];
foreach($active as $language){
 $home_url=is_array($wpml)?(string)($wpml[$language]['url']??''):'';
 do_action('wpml_switch_language',$language);
 $approved=function_exists('native_destination_approved')?native_destination_approved($language):[];
 $items=[];
 foreach(['home_section_combined','home_section_nile'] as $section){
  $items[$section]=['enabled'=>($options[$section]??'on')!=='off',
   'policy_allowed'=>mad4b_home_sections_policy_allows($section),
   'has_content'=>mad4b_home_sections_has_content($section)];
 }
 $translated=(int)apply_filters('wpml_object_id',$front_initial,'page',false,$language);
 $rows[]=['language'=>$language,'sections'=>$items,
  'home_page_id'=>$translated,'home_page_status'=>$translated?get_post_status($translated):null,
  'home_url'=>$home_url,
  'country_menu_ids'=>array_values(array_map(fn($v)=>(int)$v['id'],$approved))];
}
echo wp_json_encode(['contract'=>'mad4b.company-home-sections-readback.v1',
 'origin'=>untrailingslashit(home_url('/')),
 'site_uuid'=>$profile['site_uuid']??null,
 'profile_ready'=>!empty($profile['configured'])&&!empty($profile['origin_match'])&&!empty($profile['environment_match'])&&!empty($profile['deployment_binding_match']),
 'module_sha256'=>hash_file('sha256',$file),
 'service_tours_discovery'=>function_exists('service_channel_allowed')?service_channel_allowed('tours','destination_discovery'):false,
 'operating_country_ids'=>class_exists('MAD4B_Company_Country_Operating_Scope_V1')?MAD4B_Company_Country_Operating_Scope_V1::operating_countries():[],
 'source_home_id'=>$front_initial,'active_language_codes'=>$active,'languages'=>$rows,
 'read_only'=>true],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);'''
WIDGET_MARKERS={"home_section_combined":"elementor-element-a28340c","home_section_nile":"elementor-element-a43662b"}

def site_probe(container:str,origin:str,path:str)->dict:
    assert re.fullmatch(r'php-[a-z0-9-]{10,80}',container)
    cp=subprocess.run(["docker","exec","-e",f"MAD4B_HOME_MODULE_FILE={path}",
      "-e",f"MAD4B_HOME_EXPECTED_ORIGIN={origin}",container,
      "wp","--allow-root","--path=/var/www/html","eval",WP],
      capture_output=True,text=True,timeout=85)
    if cp.returncode:raise RuntimeError("HOME_WP_READBACK_FAILURE:"+cp.stderr[-180:])
    lines=[s for s in cp.stdout.splitlines() if s.startswith('{"contract":"mad4b.company-home-sections-readback.v1"')]
    if len(lines)!=1:raise ValueError("HOME_READBACK_CARDINALITY")
    x=json.loads(lines[0])
    if x["origin"]!=origin or not x["profile_ready"] or not x["site_uuid"] or not x["read_only"]:
        raise ValueError("HOME_RUNTIME_ORIGIN_OR_PROFILE_UNVERIFIED")
    codes=x.get("active_language_codes",[])
    observed=[v.get("language") for v in x.get("languages",[])]
    if not codes or codes!=observed or len(set(codes))!=len(codes) or x["source_home_id"]<=0:
        raise ValueError("HOME_DYNAMIC_WPML_LOCALES_OR_FRONT_PAGE_MISSING")
    for lang in x["languages"]:
        if lang["home_page_id"]<=0 or lang["home_page_status"]!="publish":
            raise ValueError("HOME_ACTIVE_LANGUAGE_TRANSLATION_UNPUBLISHED:"+lang["language"])
        if not lang.get("home_url"):
            raise ValueError("HOME_ACTIVE_LANGUAGE_URL_MISSING:"+lang["language"])
    return x

def frontend(origin:str,record:dict)->dict:
    language=record["language"]
    if not re.fullmatch(r"[a-z]{2,3}(?:-[a-z0-9]{2,8})?",language):
        raise ValueError("HOME_UNTRUSTED_WPML_LANGUAGE_CODE")
    site=urllib.parse.urlsplit(origin)
    page=urllib.parse.urlsplit(record["home_url"])
    # Only enrolled, same-origin WPML URL bindings may be fetched. A company
    # using separate language domains must first enroll those origins.
    if page.scheme!="https" or page.hostname!=site.hostname or page.port!=site.port:
        raise ValueError("HOME_WPML_LANGUAGE_ORIGIN_NOT_ENROLLED:"+language)
    query=urllib.parse.parse_qsl(page.query,keep_blank_values=True)
    query.append(("mad4b_home_acceptance","20261117"))
    url=urllib.parse.urlunsplit((page.scheme,page.netloc,page.path or "/",
                                urllib.parse.urlencode(query),""))
    sections=record["sections"]
    req=urllib.request.Request(url,headers={"User-Agent":"MAD4B-Company-Home-ReadOnly-Acceptance/1","Accept-Encoding":"identity"})
    with urllib.request.urlopen(req,timeout=45) as response:
        if response.status!=200 or response.url.split('/')[2]!=origin.split('/')[2]:
            raise ValueError("HOME_HTTP_ORIGIN_FAILED")
        body=response.read(1500000)
        if len(body)>=1500000:raise ValueError("HOME_HTML_TOO_LARGE")
        html=body.decode("utf-8",errors="replace")
        found={key:marker in html for key,marker in WIDGET_MARKERS.items()}
        expected={key:bool(v["enabled"] and v["policy_allowed"] and v["has_content"]) for key,v in sections.items()}
        for key in WIDGET_MARKERS:
            if found[key]!=expected[key]:
                raise ValueError(f"HOME_{language}_{key}_RENDER_DRIFT:expected={expected[key]}:visible={found[key]}")
        return {"language":language,"http":response.status,"actual_sections":found,"expected_sections":expected,
                "html_bytes":len(body),"verified":True,"read_only":True}

def main():
    sha=hashlib.sha256(GIT_SOURCE.read_bytes()).hexdigest()
    source=site_probe(SRC_CONTAINER,SOURCE,SRC_PATH)
    target=site_probe(DST_CONTAINER,TARGET,DST_PATH)
    if source["module_sha256"]!=sha or target["module_sha256"]!=sha:raise ValueError("HOME_MODULE_SHA_DRIFT")
    results={}
    for name,record in (("source",source),("target",target)):
        origin=record["origin"]
        results[name]={"readback":record,"frontend":[frontend(origin,v) for v in record["languages"]]}
    r={"contract":"mad4b.company-home-service-destination-presentation-parity.v1",
       "utc":datetime.datetime.now(datetime.timezone.utc).isoformat(),
       "status":"PASS_VERIFIED","module_source_git_target_sha256":sha,
       "source_origin":SOURCE,"target_origin":TARGET,"source_and_target":results,
       "live_runtime_verified":True,"rate_and_tour_writes":False,"can_auto_publish":False,
       "does_not_grant_original313_release":True}
    print(json.dumps(r,ensure_ascii=False,indent=2))
if __name__=="__main__":
    main()
