#!/usr/bin/env python3
"""Canonical schema-only Company Options Registry: Control Plane -> tenant JetEngine pages.

No option values, credentials, defaults or source media are imported.
"""
import hashlib
import json
import os
import pathlib
import re
import subprocess
import tempfile
from datetime import datetime, timezone

ROOT=pathlib.Path("/opt/mad4b-company-provisioning")
REGISTRY=ROOT/"policies/company-options-field-registry-v1.json"
REPORT=ROOT/"reports/wayfatours-company-options-readiness.json"
CENTRAL="etg-commerce-staging-php"
TENANT="wayfatours-production-php"
SCOPES={
    "contact-and-help-info":"brand_and_contact",
    "look-and-feel":"brand_media_and_presentation",
    "company-communication-operations":"company_communications",
    "global-offers":"company_marketing",
}
PHP=r"""<?php
global $wpdb;
$out=array('host'=>wp_parse_url(home_url(),PHP_URL_HOST),'db'=>DB_NAME,'pages'=>array());
foreach(array('contact-and-help-info','look-and-feel','company-communication-operations','global-offers') as $slug){
 $row=$wpdb->get_row($wpdb->prepare("SELECT id,slug,status,labels,meta_fields FROM {$wpdb->prefix}jet_post_types WHERE slug=%s AND status='page'",$slug),ARRAY_A);
 if(!$row)WP_CLI::error('JET_OPTIONS_PAGE_MISSING_'.$slug);
 $meta=maybe_unserialize($row['meta_fields']);
 if(!is_array($meta))WP_CLI::error('JET_OPTIONS_SCHEMA_INVALID_'.$slug);
 $fields=array();
 foreach($meta as $field){
   if(!is_array($field) || empty($field['name']))continue;
   $fields[]=array('key'=>(string)$field['name'],
     'type'=>(string)($field['type']??'text'),
     'label'=>wp_strip_all_tags((string)($field['title']??$field['name'])));
 }
 $labels=maybe_unserialize($row['labels']);
 $data=get_option($slug,null);
 $present=array();$configured=array();
 foreach($fields as $f){
    $key=$f['key'];$exists=is_array($data)&&array_key_exists($key,$data);
    $present[$key]=$exists;
    $configured[$key]=$exists && $data[$key]!==null &&
         $data[$key]!=='' && $data[$key]!==array();
 }
 $out['pages'][$slug]=array('id'=>(int)$row['id'],'name'=>is_array($labels)?(string)($labels['name']??$slug):$slug,
     'fields'=>$fields,'configured'=>$configured,'present'=>$present,'options_record_exists'=>is_array($data));
}
echo wp_json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
?>"""

def read(container):
    cmd=["docker","exec","-i","-u","33:33",container,"php","-d","memory_limit=512M",
         "/usr/local/bin/wp","eval-file","-","--path=/var/www/html",
         "--skip-plugins","--skip-themes"]
    p=subprocess.run(cmd,input=PHP,capture_output=True,text=True,timeout=85)
    if p.returncode:raise RuntimeError(container+": "+p.stderr[-600:])
    for line in reversed(p.stdout.splitlines()):
        if line.startswith("{"):return json.loads(line)
    raise RuntimeError("WP_READBACK_MISSING")

def atomic(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,t=tempfile.mkstemp(prefix=".company-options-",dir=path.parent)
    try:
        with os.fdopen(fd,"w") as f:json.dump(data,f,indent=2,ensure_ascii=False);f.write("\n")
        os.chmod(t,0o640);os.replace(t,path)
    finally:
        if os.path.exists(t):os.unlink(t)

def generate():
    central=read(CENTRAL);tenant=read(TENANT)
    if central["host"]!="tours.dev.mad4b.com" or central["db"]!="etg_commerce_staging":
        raise RuntimeError("CONTROL_PLANE_MISMATCH")
    if tenant["host"]!="wayfatours.mad4b.com" or tenant["db"]!="wayfatours_production":
        raise RuntimeError("TENANT_MISMATCH")
    pages={}
    for slug,scope in SCOPES.items():
        source=central["pages"][slug];target=tenant["pages"][slug]
        if source["fields"]!=target["fields"]:
            raise RuntimeError("OPTIONS_PAGE_DEFINITION_DRIFT:"+slug)
        keys=[f["key"] for f in source["fields"]]
        if len(keys)!=len(set(keys)):raise RuntimeError("DUPLICATE_OPTION_FIELD:"+slug)
        pages[slug]={"scope":scope,"label":source["name"],"fields":source["fields"],
                     "value_storage":{"system":"WordPress JetEngine Options Pages",
                                      "site":"tenant","option_name":slug},
                     "editor":{"wp_admin_page":slug},
                     "onboarding":{"copy_source_values":False,"empty_if_unconfigured":True,
                                   "accept_owner_entry_after_workflow":True}}
    registry={
      "contract":"mad4b.company-options-field-registry.v1",
      "authority":"MAD4B Control Plane",
      "definition_origin":"tours.dev.mad4b.com JetEngine page definitions",
      "value_authority":"per_company_own_jetengine_options_page",
      "company":"wayfatours",
      "page_count":len(pages),
      "field_count":sum(len(p["fields"]) for p in pages.values()),
      "pages":pages,
      "rules":{"all_source_option_values_excluded":True,
               "only_schema_definitions_mirrored":True,
               "brand_media_links_are_blank_until_entered":True,
               "preserve_dynamic_option_field_binders":True,
               "owner_edits_using_jetengine_options_pages":True,
               "empty_values_must_not_be_exported_to_other_companies":True,
               "price_booking_policies_are_not_reset_by_brand_customization":True}
    }
    fingerprint=hashlib.sha256(json.dumps(registry,sort_keys=True,ensure_ascii=False).encode()).hexdigest()
    registry["fingerprint"]=fingerprint
    atomic(REGISTRY,registry)
    readiness={"contract":"mad4b.company-options-readiness.v1","site_uuid":"b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b",
       "registry_fingerprint":fingerprint,"pages":{}, "generated_utc":datetime.now(timezone.utc).isoformat()}
    for slug,config in pages.items():
        t=tenant["pages"][slug]
        readiness["pages"][slug]={"registered":True,"fields":len(config["fields"]),
            "filled":sum(bool(x) for x in t["configured"].values()),
            "pending":sum(not bool(x) for x in t["configured"].values()),
            "options_record_exists":t["options_record_exists"],
            "admin_page":"admin.php?page="+slug}
    atomic(REPORT,readiness)
    return {"status":"SCHEMA_ONLY_REGISTRY_READY","page_count":registry["page_count"],
            "field_count":registry["field_count"],
            "fingerprint":fingerprint,
            "saved_company_values_copied":0,
            "tenant_configuration":{k:{"filled":v["filled"],"pending":v["pending"]}
                                   for k,v in readiness["pages"].items()}}
if __name__=="__main__":print(json.dumps(generate(),ensure_ascii=False))
