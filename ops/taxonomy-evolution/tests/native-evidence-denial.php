<?php
declare(strict_types=1);
define('ABSPATH','/synthetic-fixture/');
class WP_Error {
 public function __construct(public string $code,public string $message) {}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($v):bool{return $v instanceof WP_Error;}
require dirname(__DIR__).'/TaxonomyEvolutionNativeEvidence.php';
$site=['company_id'=>'c1','site_uuid'=>'s1','origin'=>'https://example.test','environment'=>'staging'];
$move=['from'=>'location_jet','to'=>'destinations','version'=>1,'source_digest'=>str_repeat('a',64)];
$observed=[
 'company_id'=>'c1','site_uuid'=>'s1','origin'=>'https://example.test','environment'=>'staging',
 'complete'=>true,'provider'=>'enrolled_native_readback','inventory_revision'=>'revision-1',
 'registered_taxonomies'=>['location_jet'=>true,'destinations'=>false],
 'counts'=>['definitions'=>1,'terms'=>2,'translation_rows'=>2,'relationships'=>2,'unbound_wpml_terms'=>0],
 'references'=>[],'languages'=>['active'=>['en'],'hidden'=>[]],
 'preserve_rewrite_verified'=>true,'canonical_source_observed'=>true,
 'verified_restore_checkpoint'=>true,'native_executor_ready'=>true,'exact_runtime_provider_certified'=>true,
];
$p=MAD4B_TaxonomyEvolutionGovernance::plan($site,$move,$observed);
if($p['status']!=='PLAN_READY_FOR_INDEPENDENT_NATIVE_AUTHORIZATION')throw new RuntimeException('candidate_setup');
$target=['plan_sha256'=>$p['plan_sha256'],'company_id'=>'c1','site_uuid'=>'s1','origin'=>'https://example.test','environment'=>'staging','source_digest'=>$move['source_digest'],'inventory_revision'=>'revision-1'];
$result=MAD4B_TaxonomyEvolutionNativeEvidence::verify($p,[],$target);
if(!is_wp_error($result)||$result->get_error_code()!=='mad4b_taxonomy_evolution_native_crypto_or_policy_missing')throw new RuntimeException('must_fail_without_original_receipt_verifier');
$target['site_uuid']='foreign';$result=MAD4B_TaxonomyEvolutionNativeEvidence::verify($p,[],$target);
if(!is_wp_error($result))throw new RuntimeException('cross_site_must_fail');
$t=$p;$t['status']='forged';$result=MAD4B_TaxonomyEvolutionNativeEvidence::verify($t,[],$target);
if(!is_wp_error($result)||$result->get_error_code()!=='mad4b_taxonomy_evolution_invalid_plan')throw new RuntimeException('digest_tamper_must_fail');
echo "PASS native evidence gate fails closed without original signed runtime and on tampering",PHP_EOL;
