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
echo "PASS $n/$n dated entrance and internal rates\n";
