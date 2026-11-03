<?php
/** Fully hermetic original intake selection + denial contract. No WP boot. */
define('ABSPATH','/hermetic/');
class WP_Error {private $code;function __construct($c){$this->code=$c;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
require dirname(__DIR__).'/tools/dfsb-neutral/locale-experience/native-intake.php';
$proof=['site_uuid'=>'site-one','sha256'=>str_repeat('a',64)];
function item($code,$id,$severity='high',$priority=90,$lang='fr'){
  global $proof;
  return [
   ['id'=>$id,'code'=>$code,'severity'=>$severity,'priority'=>$priority,
    'label'=>'Report a source-bound site defect'],
   ['id'=>$id,'code'=>$code,'severity'=>$severity,'priority'=>$priority,'status'=>'open',
    'source'=>'mad4b-locale-experience','content_job_id'=>'',
    'source_evidence'=>['observations'=>[['snapshot_sha256'=>$proof['sha256']]]],
    'content_job_request'=>['status'=>'PRIORITY_INTAKE','site_uuid'=>$proof['site_uuid'],
     'language'=>$lang,'source_sha256'=>str_repeat('b',64),
     'requires_governed_content_job_admission'=>true,'auto_publication_authorized'=>false]]
  ];
}
$cases=[];
function assert_case($name,$v){global $cases;$cases[$name]=(bool)$v;}
[$valid,$row]=item('lexp_asset_http_error_fr',str_repeat('c',64));
$ledger=[$valid['id']=>$row];
$pick=static fn($issues,$store,$minimum='high')=>MAD4B_Locale_Native_Intake::eligible($issues,$store,$GLOBALS['proof'],$minimum);
assert_case('real_bounded_source_admitted',count($pick([$valid],$ledger))===1);
assert_case('empty_intake_returns_nothing',$pick([],[])===[]);
[$medium,$mediumRow]=item('lexp_untranslated_ui_candidate_fr',str_repeat('d',64),'medium',81);
assert_case('medium_excluded_by_company_high_policy',count($pick([$medium],[$medium['id']=>$mediumRow],'high'))===0);
assert_case('medium_allowed_only_if_company_policy_expanded',count($pick([$medium],[$medium['id']=>$mediumRow],'medium'))===1);
assert_case('high_precedes_medium_with_same_source',($pick([$medium,$valid],$ledger+[$medium['id']=>$mediumRow],'medium')[0]['id']??'')===$valid['id']);
function deny_mutation($name,$edit){global $valid,$row,$pick;$test=$edit($row);assert_case($name,count($pick([$valid],[$valid['id']=>$test]))===0);}
deny_mutation('resolved_issue_not_restarted',static function($x){$x['status']='resolved';return $x;});
deny_mutation('different_source_not_admitted',static function($x){$x['source']='external-scrape';return $x;});
deny_mutation('wrong_code_not_admitted',static function($x){$x['code']='something_else';return $x;});
deny_mutation('different_severity_not_admitted',static function($x){$x['severity']='low';return $x;});
deny_mutation('already_bound_job_never_duplicated',static function($x){$x['content_job_id']='original';return $x;});
deny_mutation('job_request_must_be_pending',static function($x){$x['content_job_request']['status']='LINKED';return $x;});
deny_mutation('governed_contentjob_required',static function($x){$x['content_job_request']['requires_governed_content_job_admission']=false;return $x;});
deny_mutation('no_auto_publication_boundary',static function($x){$x['content_job_request']['auto_publication_authorized']=true;return $x;});
deny_mutation('different_site_uuid_denied',static function($x){$x['content_job_request']['site_uuid']='another-company';return $x;});
deny_mutation('changed_evidence_sha_denied',static function($x){$x['source_evidence']['observations'][0]['snapshot_sha256']=str_repeat('f',64);return $x;});
deny_mutation('missing_original_observation_denied',static function($x){unset($x['source_evidence']['observations']);return $x;});
deny_mutation('invalid_sha_denied',static function($x){$x['content_job_request']['source_sha256']='unverified';return $x;});
deny_mutation('invalid_wpml_language_denied',static function($x){$x['content_job_request']['language']='fr/en';return $x;});
deny_mutation('missing_original_job_intake_denied',static function($x){unset($x['content_job_request']);return $x;});
assert_case('hard_limit_no_unbounded_runs',MAD4B_Locale_Native_Intake::MAX_PER_RUN===5);
$denied=MAD4B_Locale_Native_Intake::run();
assert_case('no_untrusted_automatic_execution',is_wp_error($denied) && $denied->get_error_code()==='mad4b_locale_original_intake_not_enabled');
assert_case('selection_is_pure_no_mutation',serialize($row)===serialize($ledger[$valid['id']]));
$fail=array_keys(array_filter($cases,static fn($v)=>!$v));
foreach($fail as $name)echo 'FAIL '.$name,PHP_EOL;
echo 'SUMMARY checks='.count($cases).' failures='.count($fail),PHP_EOL;
if($fail)exit(1);
