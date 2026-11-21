#!/usr/bin/env python3
"""Hermetic execution of the real CP4 stage-sourcing and cost-gate PHP.
Never signs a real supplier receipt or accesses live WordPress.
"""
from pathlib import Path
import os
import shutil
import subprocess
import textwrap

ROOT=Path(__file__).resolve().parents[1]
STAGE=ROOT/"wp-content/mu-plugins/mad4b-package-stage-sourcing-v1.php"
GATE=ROOT/"wp-content/mu-plugins/mad4b-package-cost-receipt-gate-v1.php"
ADMISSION=ROOT/"wp-content/mu-plugins/mad4b-package-supplier-admission-v1.php"
ALERTS=ROOT/"wp-content/mu-plugins/mad4b-cp4-cost-readiness-alerts-v1.php"
for file in (STAGE,GATE,ADMISSION,ALERTS):
    if not file.is_file():raise AssertionError(f"Source missing: {file}")
required={
 STAGE:("function stageTrainSegment(","'proposed_travel_segment'=>$segment","'supplier_timetable_confirmed'=>false"),
 GATE:("receipt_train_stage_segment_unverified","receipt_train_stop_order_invalid","receipt_train_timetable_invalid"),
 ADMISSION:("MAD4B_Package_Stage_Sourcing_V1::stageTrainSegment($tour,$stage)",),
 ALERTS:("MAD4B_Package_Stage_Sourcing_V1::stageTrainSegment($tour,$stage)",)
}
for file,needles in required.items():
    source=file.read_text()
    for text in needles:
        if text not in source:raise AssertionError(f"{file.name}: required source behavior missing: {text}")

fixture=textwrap.dedent(r'''
<?php
define('ABSPATH','/');
define('OBJECT','OBJECT');
class WP_Post {public $ID;public $post_type='stations';public $post_status='publish';public $post_title='Upper Egypt Railway Station (Bashteel)';function __construct($id){$this->ID=$id;}}
function is_wp_error($v) {return false;}
function get_page_by_path($path,$output,$type){return new WP_Post(68922);}
function get_post_type($id){return in_array($id,[54552,54553],true)?'transportations':'stations';}
function get_post_status($id){return 'publish';}
function get_posts($q){return ($q['post_type']??'')==='transportations'?[54552,54553]:[];}
function get_the_title($id){return 'Abela Sleeper Train '.$id;}
function get_edit_post_link($id,$context=''){return '/wp-admin/post.php?post='.$id.'&action=edit';}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/i','',$v));}
function wp_strip_all_tags($v){return strip_tags($v);}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function wp_get_object_terms($id,$tax,$args=[]){
 if($tax==='destinations')return match($id){
   68922=>['giza-city'],54258=>['aswan-city'],54257=>['luxor-city'],
   54256=>['asyut-city'],54232=>['giza-city'],default=>[]};
 return [];
}
function apply_filters($hook,$default,...$args){
 if($hook==='mad4b/package-cost-receipt/verify-authority')
   return $GLOBALS['test_signed_mock']===true &&
     ($args[0]['approval_ref']??'')==='FIXTURE_QA_ONLY';
 return $default;
}
function check($value,$name){if(!$value){fwrite(STDERR,'FAIL_'.$name."\n");exit(7);}}
function stop($id,$order,$time){return ['station_id'=>(string)$id,'sort_num'=>(string)$order,'time'=>$time];}
$GLOBALS['meta']=[
 60623=>[
  'itinerary_item'=>[
   'item-0'=>['title'=>'Cairo hotel arrival'],
   'item-1'=>['title'=>'Day 2: Sleeper train to Aswan'],
   'item-2'=>['title'=>'Aswan'],
   'item-3'=>['title'=>'Nile Cruise'],
   'item-4'=>['title'=>'Luxor'],
   'item-5'=>['title'=>'Day 6: Luxor Sleeper train to Cairo']
  ],
  '_mad4b_package_accommodation_source_plan_v1'=>['stages'=>[
   ['stage_key'=>'hotel-cairo-01','lodging_kind'=>'hotel','source_day_start'=>1,'nights'=>1,'destination_key'=>'cairo'],
   ['stage_key'=>'sleeper_train-train-02','lodging_kind'=>'sleeper_train','source_day_start'=>2,'nights'=>1],
   ['stage_key'=>'sleeper_train-train-04','lodging_kind'=>'sleeper_train','source_day_start'=>6,'nights'=>1]
  ]]
 ],
 54552=>['trans_train_number'=>'86','trans_train_stops'=>[
  stop(68922,1,'19:45'),stop(54232,2,'20:15'),
  stop(54256,3,'01:05'),stop(54257,4,'06:10'),stop(54258,5,'09:25')
 ]],
 54553=>['trans_train_number'=>'87','trans_train_stops'=>[
  stop(54258,1,'17:15'),stop(54257,2,'20:35'),
  stop(54256,3,'01:15'),stop(54232,4,'06:05'),stop(68922,5,'06:25')
 ]]
];
require getenv('CP4_STAGE_SOURCE');
require getenv('CP4_GATE_SOURCE');
$class='MAD4B_Package_Stage_Sourcing_V1';
$base=['lodging_kind'=>'sleeper_train','stage_key'=>'sleeper_train-train-04','selected_train_id'=>54553];
$seg=$class::stageTrainSegment(60623,$base);
check(is_array($seg)&&$seg['boarding_station_id']===54257
  && $seg['arrival_station_id']===68922&&$seg['boarding_time']==='20:35'
  && $seg['arrival_time']==='06:25'&&!$seg['commercially_assigned'],'luxor_leg_from_87');
$wrong=$base;$wrong['selected_train_id']=54552;
check($class::stageTrainSegment(60623,$wrong)===null,'wrong_route_86_not_admitted');
$bound=['lodging_kind'=>'sleeper_train','stage_key'=>'sleeper_train-train-02','selected_train_id'=>54552];
$out=$class::stageTrainSegment(60623,$bound);
check(is_array($out)&&$out['boarding_station_id']===68922&&
  $out['arrival_station_id']===54258&&$out['boarding_time']==='19:45','outbound_86_leg');
$gate='MAD4B_Package_Cost_Receipt_Gate_V1';
$context=['tour_id'=>60623,'stage_key'=>'sleeper_train-train-04','kind'=>'sleeper_train',
 'currency'=>'USD','service_date'=>'2026-12-17','last_service_date'=>'2026-12-17',
 'adults'=>2,'children'=>0,'infants'=>0,'train_id'=>54553,'train_number'=>'87',
 'cabin_class'=>'Elite','boarding_station_id'=>54257,'arrival_station_id'=>68922];
$receipt=['contract'=>$gate::CONTRACT,'status'=>'approved',
 'tour_id'=>60623,'stage_key'=>'sleeper_train-train-04',
 'kind'=>'sleeper_train','basis'=>'per_cabin_trip','currency'=>'USD',
 'cost_minor'=>45000,'amount_role'=>'supplier_cost_not_retail',
 'supplier_mode'=>'self','supplier_ref'=>'QA-SELF','cost_method'=>'documented_actual',
 'source_is_estimate'=>false,'source_document_sha256'=>hash('sha256','FAKE QA DATA'),
 'source_document_ref'=>'QA_ONLY_NOT_A_REAL_DOCUMENT','source_document_kind'=>'actual_cost_record',
 'valid_from'=>'2026-12-01','valid_to'=>'2026-12-31',
 'occupancy'=>['adults'=>2,'children'=>0,'infants'=>0],
 'train_id'=>54553,'cabin_class'=>'Elite','service_date'=>'2026-12-17',
 'boarding_station_id'=>54257,'arrival_station_id'=>68922,
 'departure_time'=>'20:35','arrival_time'=>'06:25',
 'availability'=>['status'=>'confirmed','service_date'=>'2026-12-17',
 'available_quantity'=>1,'source_ref'=>'FAKE_QA_ONLY',
 'checked_at'=>gmdate('Y-m-d\TH:i:s\Z'),
 'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600)],
 'approval_ref'=>'FIXTURE_QA_ONLY'
];
$GLOBALS['test_signed_mock']=false;
$false=$gate::validate($receipt,$context);
check(!$false['qualified'] && in_array('receipt_signed_authority_unverified',$false['blockers'],true),'unsigned_fails');
$GLOBALS['test_signed_mock']=true;
$pass=$gate::validate($receipt,$context);
check($pass['qualified'],'mock_signer_accepts_luxor_stage');
$r=$receipt;$r['boarding_station_id']=54258;$r['departure_time']='17:15';
$bad=$gate::validate($r,$context);
check(!$bad['qualified']&&in_array('receipt_train_stage_segment_unverified',$bad['blockers'],true),'aswan_wrong_segment');
$r=$receipt;$r['departure_time']='17:15';$bad=$gate::validate($r,$context);
check(!$bad['qualified']&&in_array('receipt_train_timetable_invalid',$bad['blockers'],true),'wrong_stop_time');
unset($context['boarding_station_id']);
$bad=$gate::validate($receipt,$context);
check(!$bad['qualified']&&in_array('receipt_train_stage_segment_unverified',$bad['blockers'],true),'missing_stage_proof');
$GLOBALS['test_signed_mock']=false;
echo "CP4_STAGE_SEGMENT_HERMETIC_PASS: train 86, train 87 Luxor boarding, source-only candidate, unsigned/false station/time/missing-stage refusal\n";
''')
php=shutil.which("php")
docker_container=os.environ.get("MAD4B_CP4_PHP_CONTAINER")
if php:
    command=[php]
    env={**os.environ,"CP4_STAGE_SOURCE":str(STAGE),"CP4_GATE_SOURCE":str(GATE)}
elif docker_container and shutil.which("docker"):
    target_stage="/tmp/mad4b-cp4-hermetic-sourcing.php"
    target_gate="/tmp/mad4b-cp4-hermetic-gate.php"
    subprocess.run(["docker","cp",str(STAGE),f"{docker_container}:{target_stage}"],check=True)
    subprocess.run(["docker","cp",str(GATE),f"{docker_container}:{target_gate}"],check=True)
    command=["docker","exec","-i","-e",f"CP4_STAGE_SOURCE={target_stage}",
             "-e",f"CP4_GATE_SOURCE={target_gate}",docker_container,"php"]
    env=os.environ.copy()
else:
    raise AssertionError("PHP is required; use MAD4B_CP4_PHP_CONTAINER only on authorized test VPS")
try:
    result=subprocess.run(command,input=fixture,text=True,capture_output=True,env=env)
finally:
    if not php:
        subprocess.run(["docker","exec","--user","root",docker_container,
                       "rm","-f",target_stage,target_gate],check=True)
if result.returncode or "CP4_STAGE_SEGMENT_HERMETIC_PASS" not in result.stdout:
    raise AssertionError(f"source regression failed: stdout={result.stdout} stderr={result.stderr}")
print(result.stdout.strip())
