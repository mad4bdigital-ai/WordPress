#!/usr/bin/env python3
"""Automatic exact live-schema approval; secondary resolution on concrete drift."""
import hashlib
import json
import pathlib
import subprocess
import sys
import tempfile
import os
from datetime import datetime,timezone

ROOT=pathlib.Path("/opt/mad4b-company-provisioning")
REPORT=ROOT/"reports/wayfatours-schema-compatibility.json"
SOURCE="etg-commerce-staging-php"
TARGET="wayfatours-production-php"
PHP=r"""<?php
global $wpdb;
$tables=array();
foreach(array('jet_post_types','jet_taxonomies') as $name){
  $table=$wpdb->prefix.$name;
  if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table))!==$table)WP_CLI::error('SCHEMA_TABLE_NOT_FOUND_'.$name);
  $rows=$wpdb->get_results("SELECT * FROM {$table} ORDER BY id",ARRAY_A);
  $portable=array();$sourceScoped=array();
  foreach($rows as $row){
    $sourceOnly=($name==='jet_post_types' &&
      ($row['status']??'')==='rest-api-endpoint' &&
      preg_match('/^mad4b-hub-[a-f0-9]{12,}$/D',(string)($row['slug']??'')) &&
      stripos((string)($row['labels']??''),'wo-vacation')!==false &&
      stripos((string)($row['labels']??''),'research_observation')!==false &&
      strpos((string)($row['args']??''),'tours.dev.mad4b.com')!==false);
    if($sourceOnly){
      $sourceScoped[]=array('id'=>(int)$row['id'],
        'classification'=>'SOURCE_BOUND_RESEARCH_REST_ENDPOINT',
        'portable'=>false);
    }else $portable[]=$row;
  }
  $tables[$name]=array('count'=>count($rows),
    'sha256'=>hash('sha256',wp_json_encode($rows)),
    'portable_count'=>count($portable),
    'portable_sha256'=>hash('sha256',wp_json_encode($portable)),
    'source_scoped_exclusions'=>$sourceScoped);
}
$plugins=(array)get_option('active_plugins',array());
$find=function($slug)use($plugins){
 foreach($plugins as $path)if(strpos($path,$slug.'/')===0)return true;
 return false;
};
$types=array('jet-engine','elementor_library','tours-and-activities','properties','transportations');
$registered=array();
foreach($types as $n)$registered[$n]=post_type_exists($n);
$taxes=array('destinations','tour-types','tour-styles','duration-options');
$taxregistered=array();foreach($taxes as $n)$taxregistered[$n]=taxonomy_exists($n);
$allregistered = array_keys(get_post_types(array(),'names'));
$alltax = array_keys(get_taxonomies(array(),'names'));
echo wp_json_encode(array('registered_all_post_types'=>$allregistered,'registered_all_taxonomies'=>$alltax,'origin'=>home_url(),'database'=>DB_NAME,
 'environment'=>wp_get_environment_type(),'tables'=>$tables,
 'providers'=>array('jet-engine'=>$find('jet-engine'),
  'elementor'=>$find('elementor'),'elementor-pro'=>$find('elementor-pro'),
  'jet-smart-filters'=>$find('jet-smart-filters'),'jet-form-builder'=>$find('jet-form-builder')),
 'registered_post_types'=>$registered,'registered_taxonomies'=>$taxregistered),JSON_UNESCAPED_SLASHES).PHP_EOL;
?>"""

def inspect(container):
    args=["docker","exec","-i","-u","33:33",container,
          "php","-d","memory_limit=1024M","/usr/local/bin/wp","eval-file","-",
          "--path=/var/www/html"]
    r=subprocess.run(args,input=PHP,capture_output=True,text=True,timeout=120)
    if r.returncode:raise RuntimeError("LIVE_SCHEMA_READ_FAILED:"+container+":"+r.stderr[-350:])
    for line in reversed(r.stdout.splitlines()):
        if line.startswith("{"):return json.loads(line)
    raise RuntimeError("NO_SCHEMA_RESULT:"+container)

def compare(source,target):
    reasons={}
    if source["origin"]!="https://tours.dev.mad4b.com" or source["database"]!="etg_commerce_staging":
        reasons["source_identity"]="SOURCE_ORIGIN_UNEXPECTED"
    if target["origin"]!="https://wayfatours.mad4b.com" or target["database"]!="wayfatours_production":
        reasons["target_identity"]="TENANT_ORIGIN_UNEXPECTED"
    for table,count in (("jet_post_types",307),("jet_taxonomies",34)):
        a=source["tables"][table];b=target["tables"][table]
        portable_source=(a.get("portable_count",a["count"]),a.get("portable_sha256",a["sha256"]))
        portable_target=(b.get("portable_count",b["count"]),b.get("portable_sha256",b["sha256"]))
        if portable_source!=portable_target or portable_source[0]!=count:
            reasons[table]={"source":a,"target":b,"action":"SCHEMA_DIFF_RESOLVER"}
        if b.get("source_scoped_exclusions"):
            reasons[table+":source_scoped_target"]={
                "action":"REMOVE_INHERITED_SOURCE_BOUND_ENDPOINT",
                "rows":b["source_scoped_exclusions"]}
    needed=["jet-engine","elementor","elementor-pro","jet-smart-filters"]
    for x in needed:
        if not target["providers"].get(x):
            reasons["provider:"+x]="MISSING_ACTIVE_PROVIDER"
    required=["jet-engine","elementor_library","tours-and-activities","properties","transportations"]
    for x in required:
        if not target["registered_post_types"].get(x):
            reasons["post_type:"+x]="MISSING_REGISTERED_POST_TYPE"
    return reasons

def put(path,value):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,temp=tempfile.mkstemp(prefix=".wayfa-schema-",dir=path.parent)
    try:
        with os.fdopen(fd,"w") as f:json.dump(value,f,indent=2,ensure_ascii=False)
        os.chmod(temp,0o640);os.replace(temp,path)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def run():
    s=inspect(SOURCE);t=inspect(TARGET)
    diffs=compare(s,t)
    state="SCHEMA_PARITY_CONFIRMED" if not diffs else "RESOLUTION_ENGINE_REQUIRED"
    payload={
      "contract":"mad4b.company-config-compatibility.v1",
      "state":state,
      "source":s,
      "target":t,
      "diffs":diffs,
      "source_only_exclusions":{table:details.get("source_scoped_exclusions",[])
                                for table,details in s["tables"].items()
                                if details.get("source_scoped_exclusions")},
      "source_only_entries_are_not_cloned":True,
      "resolution_mode":"on-diff",
      "engine":"schema-provider-compatibility-resolver",
      "next_action":"AUTO_APPROVE_SCHEMA_BACKED_TEMPLATES" if not diffs else "RUN_SECONDARY_DIFF_RESOLUTION",
      "generated_utc":datetime.now(timezone.utc).isoformat()
    }
    payload["fingerprint"]=hashlib.sha256(json.dumps(
        {"source":s,"target":t,"diffs":diffs},sort_keys=True).encode()).hexdigest()
    put(REPORT,payload)
    return payload

if __name__=="__main__":
    if sys.argv[1:] not in ([],["--probe"],["--resolve-on-diff"]):
        raise SystemExit("usage --probe|--resolve-on-diff")
    result=run()
    print(json.dumps({"state":result["state"],"fingerprint":result["fingerprint"],
        "diffs":result["diffs"],
        "table_counts":{k:v["count"] for k,v in result["target"]["tables"].items()},
        "providers":result["target"]["providers"]},ensure_ascii=False))
    if result["diffs"] and "--resolve-on-diff" in sys.argv[1:]:
        print(json.dumps({"resolution_engine":"activated",
            "mode":"DIFF_DISCOVERY",
            "actions":result["diffs"],
            "changes_applied":0,
            "reason":"Requires source-backed remapping and independent verification"}))

