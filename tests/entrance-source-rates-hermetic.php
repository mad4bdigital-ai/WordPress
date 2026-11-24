<?php
define('ABSPATH','/');
class WP_Error{function __construct(public $code){}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action(...$a){}
function sanitize_text_field($v){return trim(strip_tags($v));}
require __DIR__.'/../wp-content/mu-plugins/mad4b-generic-cost-composition-v1.php';
require __DIR__.'/../wp-content/mu-plugins/mad4b-entrance-source-rates-v1.php';
$c='MAD4B_Entrance_Source_Rates_V1';$n=0;
function ck($v,$why){global $n;if(!$v)throw new RuntimeException($why);$n++;}
$r=['fee_key'=>'adult-all','amount'=>'20','currency'=>'USD','basis'=>'per_person','visitor_category'=>'adult','market_scope'=>'all','valid_from'=>'2027-01-01','valid_to'=>'2027-12-31','review_status'=>'reviewed_reference','evidence_ref'=>'test-evidence','coverage'=>'admission_only'];
$ctx=['nationality'=>'US','currency'=>'USD','adults'=>2,'children'=>0,'infants'=>0];
$f=fn($rows,$context=null,$kind='attraction',$date='2027-02-03')=>$c::resolve($rows,$kind,$date,$context??$ctx);
ck($f([$r])['reference_total_minor']===4000,'two adults');
$foreign=array_replace($r,['fee_key'=>'foreign','amount'=>'30','market_scope'=>'foreign']);
$us=array_replace($r,['fee_key'=>'us','amount'=>'40','market_scope'=>'US']);
ck($f([$r,$foreign,$us])['reference_total_minor']===8000,'exact nationality');
ck($f([$r,$foreign],array_replace($ctx,['nationality'=>'EG']))['reference_total_minor']===4000,'EG not foreign');
ck($f([$r,$foreign])['reference_total_minor']===6000,'foreign fallback');
ck($f([$r],$ctx,'attraction','2028-01-01')['status']==='entrance_no_eligible_rate','expired');
ck($f([$r],array_replace($ctx,['nationality'=>'']))['status']==='entrance_context_required','nationality required');
ck($f([$r],array_replace($ctx,['children'=>1]))['status']==='entrance_no_eligible_rate','child missing');
$child=array_replace($r,['fee_key'=>'child','visitor_category'=>'child','amount'=>'10']);
ck($f([$r,$child],array_replace($ctx,['children'=>1]))['reference_total_minor']===5000,'mixed age');
$free=array_replace($r,['fee_key'=>'free','visitor_category'=>'infant','amount'=>'0']);
ck($f([$r,$free],array_replace($ctx,['infants'=>1]))['reference_total_minor']===4000,'explicit free infant');
ck($f([$r,array_replace($r,['fee_key'=>'duplicate'])])['status']==='entrance_ambiguous_rate','overlap blocked');
$g=array_replace($r,['fee_key'=>'group','basis'=>'per_group','visitor_category'=>'all','amount'=>'50']);
ck($f([$g])['reference_total_minor']===5000,'group once');
ck($f([$g,$r])['status']==='entrance_ambiguous_basis','mixed bases');
ck($f([array_replace($r,['review_status'=>'draft'])])['status']==='entrance_no_eligible_rate','draft excluded');
ck($f([array_replace($r,['coverage'=>'activity_bundle'])],$ctx,'activity')['status']==='activity_bundle_coverage_review_required','bundle not duplicated');
ck($f([$r],$ctx,'activity')['lines'][0]['price_role']==='internal_transfer_cost','internal transfer');
ck(!$f([$r])['commercially_approved'],'not authorization');
ck($c::serviceDate('2027-02-01',['number'=>'3'])==='2027-02-03','actual day');
ck(is_wp_error($c::normalize(array_replace($r,['evidence_ref'=>'']),'activity')),'evidence needed');
$egypt=array_replace($r,['fee_key'=>'egypt','amount'=>'5','market_scope'=>'EG']);
$student=array_replace($r,['fee_key'=>'student','amount'=>'10','visitor_category'=>'student','age_max'=>'24','market_scope'=>'US']);
$mixed=array_replace($ctx,['entrance_cohorts'=>"EG,adult,35,1,no\nUS,student,20,1,yes"]);
ck($f([$r,$egypt,$student],$mixed)['reference_total_minor']===1500,'mixed nationality adult and student');
ck($f([$r,$egypt,$student],array_replace($mixed,['entrance_cohorts'=>"EG,adult,35,1,no\nUS,student,25,1,yes"]))['status']==='entrance_no_eligible_rate','student age limit');
ck($f([$r,$egypt,$student],array_replace($mixed,['entrance_cohorts'=>"EG,adult,35,1,no\nUS,student,20,1,no"]))['status']==='entrance_cohort_invalid','student ID required');
ck($f([$r,$egypt],array_replace($mixed,['entrance_cohorts'=>'EG,adult,35,1,no']))['status']==='entrance_cohort_party_mismatch','party count checked');
ck($f([$r,$egypt,$g],$mixed)['status']==='entrance_mixed_group_basis_review_required','group charged neither per cohort nor silently');
$freeChild=array_replace($r,['fee_key'=>'under6','visitor_category'=>'child','age_max'=>'5','amount'=>'0']);
$ctxChild=array_replace($ctx,['children'=>1,'entrance_cohorts'=>"US,adult,30,2,no\nUS,child,5,1,no"]);
ck($f([$r,$freeChild],$ctxChild)['reference_total_minor']===4000,'under six explicit zero');
ck($f([$r,$freeChild],array_replace($ctxChild,['entrance_cohorts'=>"US,adult,30,2,no\nUS,child,6,1,no"]))['status']==='entrance_no_eligible_rate','age boundary');
ck(is_wp_error($c::normalize(array_replace($student,['age_max'=>'']),'attraction')),'student age rule required');
echo "PASS $n/$n dated entrance and internal rates\n";
require __DIR__.'/../wp-content/mu-plugins/mad4b-entrance-tariff-catalog-v1.php';
foreach(MAD4B_Entrance_Tariff_Catalog_V1::records() as $key=>$record){
 $rows=MAD4B_Entrance_Tariff_Catalog_V1::rows($key);
 ck(count($rows)===4,'four tariff rows '.$key);
 foreach($rows as $row)ck(!is_wp_error($c::normalize($row,'attraction'))&&$row['review_status']==='draft'&&$row['valid_from']===$row['valid_to'],'observed not future qualified');
}
echo "PASS $n/$n including official tariff catalog\n";
