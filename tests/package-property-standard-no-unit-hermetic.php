<?php
/** Offline contract for approved source receipts with no mandatory Unit/Cabin entity. */
define('ABSPATH', '/');
$GLOBALS['types'] = [100=>'properties',200=>'properties',101=>'units',300=>'transportations',301=>'transportations',9=>'stations',10=>'stations',11=>'stations'];
$GLOBALS['meta'] = [300=>['trans_train_number'=>'86','trans_from_station_id'=>9,
    'trans_to_station_id'=>10,'trans_departure_time'=>'19:45',
    'trans_arrival_time'=>'09:25',
    'trans_train_stops'=>[['station_id'=>9,'sort_num'=>1,'time'=>'19:45'],['station_id'=>10,'sort_num'=>2,'time'=>'09:25']]],
    301=>['trans_train_number'=>'87','trans_train_stops'=>[
       ['station_id'=>9,'sort_num'=>1,'time'=>'17:15'],
       ['station_id'=>11,'sort_num'=>2,'time'=>'20:35'],
       ['station_id'=>10,'sort_num'=>3,'time'=>'06:25']]]];
$GLOBALS['statuses']=[9=>'publish',10=>'publish',11=>'publish'];
function get_post_type($id){return $GLOBALS['types'][$id]??'';}
function get_post_status($id){return $GLOBALS['statuses'][$id]??'';}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function apply_filters($filter,$value,...$args){return $filter==='mad4b/package-cost-receipt/verify-authority';}
require_once __DIR__.'/../wp-content/mu-plugins/mad4b-package-cost-receipt-gate-v1.php';
$gate='MAD4B_Package_Cost_Receipt_Gate_V1';
$done=0;
function assertQualified($yes,$label){global $done; $done++; if(!$yes){fwrite(STDERR,"FAIL $label\n");exit(1);}}
$date='2027-03-01';
$context=['tour_id'=>500,'stage_key'=>'stage-one','kind'=>'hotel','currency'=>'EGP',
    'service_date'=>$date,'last_service_date'=>'2027-03-03','adults'=>2,
    'children'=>0,'infants'=>0,'property_id'=>100,'unit_ids'=>[]];
$receipt=['contract'=>$gate::CONTRACT,'status'=>'approved','kind'=>'hotel',
    'basis'=>'per_person_night','tour_id'=>500,'stage_key'=>'stage-one',
    'currency'=>'EGP','cost_minor'=>250000,'amount_role'=>'supplier_cost_not_retail',
    'supplier_mode'=>'self','supplier_ref'=>'company:self',
    'cost_method'=>'documented_actual','source_is_estimate'=>false,
    'source_document_sha256'=>str_repeat('a',64),'source_document_ref'=>'TEST-DOC-1',
    'source_document_kind'=>'actual_cost_record','valid_from'=>'2027-01-01',
    'valid_to'=>'2027-12-31','occupancy'=>['adults'=>2,'children'=>0,'infants'=>0],
    'property_id'=>100,'unit_id'=>0,'meal_plan'=>'BB',
    'availability'=>['status'=>'confirmed','service_date'=>$date,
      'available_quantity'=>1,'source_ref'=>'TEST-AVAIL',
      'checked_at'=>gmdate('c',time()-30),'expires_at'=>gmdate('c',time()+3600)],
    'approval_ref'=>'TEST-SIGNED'];
$r=$gate::validate($receipt,$context);
assertQualified($r['qualified'],'Property standard can qualify without any Unit post');
$bad=$receipt;$bad['property_id']=200;
assertQualified(!$gate::validate($bad,$context)['qualified'],'property identity is mandatory');
$bad=$receipt;$bad['unit_id']=101;
assertQualified(!$gate::validate($bad,$context)['qualified'],'explicit Unit must be in selected context');
$cruise=$receipt;$cruise['kind']='cruise';$cruise['basis']='per_cabin_night';
$cruise['vessel_id']=200;$cruise['cabin_unit_id']=0;
$cruise['cruise_program']=['id'=>2348,'nights'=>3];
$cruise['sailing_date']=$date;
$cc=$context;$cc['kind']='cruise';$cc['vessel_id']=200;
$cc['cabin_unit_id']=0;$cc['cruise_program']=['id'=>2348,'nights'=>3];
$r=$gate::validate($cruise,$cc);
assertQualified($r['qualified'],'cruise vessel Program sailing receipt qualifies with no Cabin Unit');
$bad=$cruise;$bad['sailing_date']='2027-03-02';
assertQualified(!$gate::validate($bad,$cc)['qualified'],'wrong sailing date denied');
$bad=$cruise;$bad['cabin_unit_id']=101;
assertQualified(!$gate::validate($bad,$cc)['qualified'],'unselected explicit cabin denied');
$cc['cruise_program']=['id'=>2349,'nights'=>4];
assertQualified(!$gate::validate($cruise,$cc)['qualified'],'wrong cruise program denied');
$train=$receipt;$train['kind']='sleeper_train';$train['basis']='per_cabin_trip';
$train['train_id']=300;$train['cabin_class']='Standard';$train['service_date']=$date;
$train['boarding_station_id']=9;$train['arrival_station_id']=10;
$train['departure_time']='19:45';$train['arrival_time']='09:25';
$tc=$context;$tc['kind']='sleeper_train';$tc['train_id']=300;$tc['train_number']='86';
$tc['cabin_class']='Standard';$tc['boarding_station_id']=9;$tc['arrival_station_id']=10;
assertQualified($gate::validate($train,$tc)['qualified'],'sleeper Standard class approved without Unit entity');
$train['cabin_class']='Unknown';
assertQualified(!$gate::validate($train,$tc)['qualified'],'unknown train class denied');
$return=$receipt;$return['kind']='sleeper_train';$return['basis']='per_person_trip';
$return['train_id']=301;$return['cabin_class']='Standard';$return['service_date']=$date;
$return['boarding_station_id']=11;$return['arrival_station_id']=10;
$return['departure_time']='20:35';$return['arrival_time']='06:25';
$returnContext=$tc;$returnContext['train_id']=301;$returnContext['train_number']='87';
$returnContext['boarding_station_id']=11;
assertQualified($gate::validate($return,$returnContext)['qualified'],
    'train 87 intermediate Luxor boarding preserved');
$return['boarding_station_id']=9;
assertQualified(!$gate::validate($return,$returnContext)['qualified'],
    'train 87 Aswan departure cannot replace passenger Luxor stage');
echo "PASS $done/$done optional Unit + cruise/sleeper receipt gate contract\n";
