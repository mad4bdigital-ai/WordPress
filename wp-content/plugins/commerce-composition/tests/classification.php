<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/Classification.php';
use Commerce\Composition\Classification;
$registry=['schema'=>1,'definitions'=>[
 ['kind'=>'package','post_type'=>'tours-and-activities','all'=>[
    ['source'=>'taxonomy','taxonomy'=>'tour-types_jet','term_slugs'=>['packages']]]],
 ['kind'=>'private','post_type'=>'tours-and-activities','all'=>[
    ['source'=>'taxonomy','taxonomy'=>'tour_categories_jet','term_slugs'=>['private-tour']],
    ['source'=>'meta','key'=>'tour_public_status','equals'=>'1']]],
 ['kind'=>'included','post_type'=>'products','all'=>[['source'=>'meta','key'=>'sales_bundle_mode','equals'=>'included']]],
 ['kind'=>'flexible','post_type'=>'transportations','all'=>[
    ['source'=>'taxonomy','taxonomy'=>'transportation-types','term_slugs'=>['transfer']],
    ['source'=>'meta','key'=>'is_private','equals'=>'1']]]]];
$terms=['tour-types_jet'=>['packages'],'tour_categories_jet'=>['private-tour']];
$meta=['tour_public_status'=>'1'];
$lookup=static function($tax)use(&$terms){return $terms[$tax]??[];};
$read=static function($key)use(&$meta){return $meta[$key]??'';};
$passes=0;$fail=[];
$check=static function($ok,$name)use(&$passes,&$fail){$ok?$passes++:$fail[]=$name;};
$matched=Classification::kinds($registry,'tours-and-activities',$lookup,$read);
$check($matched===['package','private'],'package_and_private_from_distinct_tax_meta_rules');
$terms=['tour-types_jet'=>[],'tour_categories_jet'=>['private-tour']];
$matched=Classification::kinds($registry,'tours-and-activities',$lookup,$read);
$check($matched===['private'],'no_package_without_type_term_even_if_other_metadata_present');
$terms=['tour-types_jet'=>[],'package_levels'=>['gold-package'],'tour_categories_jet'=>[]];
$matched=Classification::kinds($registry,'tours-and-activities',$lookup,$read);
$check(!in_array('package',$matched,true),'gold_level_not_package_type');
$terms=['tour-types_jet'=>['packages']];$meta=['tour_public_status'=>'0'];
$matched=Classification::kinds($registry,'tours-and-activities',$lookup,$read);
$check($matched===['package'],'package_independent_of_private_flag');
$meta=['sales_bundle_mode'=>'included'];
$matched=Classification::kinds($registry,'products',$lookup,$read);
$check($matched===['included'],'different_product_kind_from_meta');
$meta=['is_private'=>'1'];$terms=['transportation-types'=>['transfer']];
$matched=Classification::kinds($registry,'transportations',$lookup,$read);
$check($matched===['flexible'],'new_service_class_from_taxonomy_and_meta');
$meta=['is_private'=>'0'];
$matched=Classification::kinds($registry,'transportations',$lookup,$read);
$check($matched===[],'all_conditions_reject_partial');
$bad=$registry;$bad['definitions'][]=$bad['definitions'][0];
try {Classification::kinds($bad,'tours-and-activities',$lookup,$read);$check(false,'duplicate_classification_rejected');}
catch (\DomainException $e) {$check($e->getMessage()==='classification_rule_duplicate','duplicate_classification_rejected');}
$bad=$registry;$bad['definitions'][0]['all'][0]['term_slugs']=['<script>'];
try{Classification::kinds($bad,'tours-and-activities',$lookup,$read);$check(false,'untrusted_term_invalid');}
catch(\DomainException $e){$check($e->getMessage()==='classification_term_slug_invalid','untrusted_term_invalid');}
$rtl=$registry;$rtl['definitions'][]=['kind'=>'localized','post_type'=>'products',
 'all'=>[['source'=>'taxonomy','taxonomy'=>'product_type','term_slugs'=>['%d8%a8%d8%a7%d9%82%d8%a9']]]];
$rtlTerms=static fn(string $tax)=>$tax==='product_type'?['%d8%a8%d8%a7%d9%82%d8%a9']:[];
$check(in_array('localized',Classification::kinds($rtl,'products',$rtlTerms,static fn($key)=>''),true),'localized_percent_encoded_term_supported');
$number=$registry;$number['definitions'][]=['kind'=>'numeric','post_type'=>'products',
 'all'=>[['source'=>'taxonomy','taxonomy'=>'product_type','term_slugs'=>['24-hours']]]];
$numberTerms=static fn($tax)=>['24-hours'];
$check(in_array('numeric',Classification::kinds($number,'products',$numberTerms,static fn($key)=>''),true),'numeric_prefix_term_supported');
$variants=$registry;
$variants['definitions'][]=['kind'=>'multi_select','post_type'=>'products',
 'all'=>[['source'=>'meta','key'=>'service_caps','contains'=>'transfer']]];
$variants['definitions'][]=['kind'=>'flexible_calendar','post_type'=>'products',
 'all'=>[['source'=>'meta','key'=>'sale_mode','one_of'=>['tour','single','package']]]];
$variants['definitions'][]=['kind'=>'has_description','post_type'=>'products',
 'all'=>[['source'=>'meta','key'=>'policy_ref','exists'=>true]]];
$variantsMeta=['service_caps'=>['transfer','hotel'],'sale_mode'=>'single','policy_ref'=>'approval_9'];
$readVariants=static fn($key)=>$variantsMeta[$key]??'';
$got=Classification::kinds($variants,'products',static fn($tax)=>[],$readVariants);
$check(in_array('multi_select',$got,true),'jetengine_multiselect_field_supported');
$check(in_array('flexible_calendar',$got,true),'enum_field_one_of_supported');
$check(in_array('has_description',$got,true),'metadata_existence_predicate_supported');
echo json_encode(['result'=>$fail?'FAIL':'PASS','passed'=>$passes,'failed'=>$fail],JSON_UNESCAPED_SLASHES).PHP_EOL;
if($fail)exit(1);
