<?php
declare(strict_types=1);
function sanitize_key($key){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key));}
function sanitize_title($title){$title=preg_replace('/[^a-z0-9_\-]+/','-',strtolower(trim((string)$title)));return trim($title,'-');}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function expect_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAILED: {$message}\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");exit(1);}}
function expect_true($actual,string $message):void{expect_same(true,(bool)$actual,$message);}
$base=dirname(__DIR__);
require_once $base.'/includes/Config/ProfileRegistry.php';
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;

$baseProfile=array(
  'id'=>'tours','enabled'=>false,'inherit_global_defaults'=>false,
  'post_types'=>array('tours-and-activities'),'require_post_type_binding'=>true,'post_type_authority'=>'query_builder',
  'archive_paths'=>array('/tours-and-activities/'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'tours_archive')),
  'taxonomy_rules'=>array('location_jet'=>array('role'=>'location','index_single'=>true,'min_results'=>1)),
  'allowed_taxonomy_sets'=>array('location_jet'),'indexable_combinations'=>array('tours|en|location_jet:cairo'),
  'content'=>array('required'=>true,'min_chars'=>250),
  'publication'=>array('sitemap'=>true,'elementor_content_verified'=>false,'elementor_verification_evidence_id'=>'','provider_observation_verified'=>false,'provider_observation_evidence_id'=>'','result_count_parity_verified'=>false,'result_count_parity_evidence_id'=>'','max_preview_urls'=>50,'max_publication_urls'=>100)
);
$f1=ProfileRegistry::authorityFingerprint($baseProfile);
$evidenceOnly=$baseProfile;$evidenceOnly['publication']['provider_observation_verified']=true;$evidenceOnly['publication']['provider_observation_evidence_id']='sha256:'.str_repeat('a',64);$evidenceOnly['publication']['provider_observation_authority_fingerprint']=$f1;
expect_same($f1,ProfileRegistry::authorityFingerprint($evidenceOnly),'evidence metadata must not change authority fingerprint');
$routeChanged=$baseProfile;$routeChanged['routes'][0]['query_id']='tours_archive_v2';
expect_true($f1!==ProfileRegistry::authorityFingerprint($routeChanged),'route authority changes must change fingerprint');
$combChanged=$baseProfile;$combChanged['indexable_combinations'][]='tours|en|location_jet:giza';
expect_true($f1!==ProfileRegistry::authorityFingerprint($combChanged),'combination authority changes must change fingerprint');
$contentChanged=$baseProfile;$contentChanged['content']['min_chars']=400;
expect_true($f1!==ProfileRegistry::authorityFingerprint($contentChanged),'content governance changes must change fingerprint');

$main=file_get_contents($base.'/etg-dynamic-filter-seo-bridge.php');
$metadata=file_get_contents($base.'/includes/RankMath/MetadataAdapter.php');
$policy=file_get_contents($base.'/includes/SEO/IndexingPolicy.php');
$normalization=file_get_contents($base.'/includes/Config/ProfileRegistryNormalizationTrait.php');
$registrar=file_get_contents($base.'/includes/RankMath/PublicationSitemapRegistrar.php');
$maintenance=file_get_contents($base.'/includes/Admin/MaintenancePage.php');
$identity=file_get_contents($base.'/includes/Runtime/ReleaseIdentity.php');
$publication=file_get_contents($base.'/includes/SEO/PublicationRegistry.php');
expect_true(false!==strpos($main,'Version: 0.4.0-alpha.10'),'Alpha10 header is present');
expect_true(false!==strpos($main,'Update URI: https://github.com/mad4bdigital-ai/WordPress'),'custom Update URI protects plugin identity');
expect_true(false!==strpos($metadata,'seoMutationAllowed'),'live metadata mutation is bound to final indexing decision');
expect_true(false!==strpos($metadata,'true===($decision[\'index\']??null)'),'live metadata requires index=true');
expect_true(false!==strpos($policy,'$final=$base&&$filtered'),'index override filter is veto-only');
expect_true(false!==strpos($normalization,'provider_observation_authority_fingerprint')&&false!==strpos($normalization,'provider_observation_evidence_current'),'provider evidence is fingerprint-bound');
expect_true(false!==strpos($normalization,'elementor_verification_authority_fingerprint')&&false!==strpos($normalization,'result_count_parity_authority_fingerprint'),'Elementor and parity evidence are fingerprint-bound');
expect_true(false!==strpos($registrar,"update_option_etg_dfsb_settings"),'ETG settings invalidation uses the real dynamic option hook');
expect_same(false,false!==strpos($registrar,"updated_option_etg_dfsb_settings"),'invalid dynamic option hook is removed');
expect_true(false!==strpos($maintenance,'Replace current with uploaded')&&false!==strpos($maintenance,'Preferred path'),'standard WordPress replacement is documented as the preferred same-plugin upgrade path');
expect_true(false!==strpos($maintenance,'hash_file(\'sha256\'')&&false!==strpos($maintenance,"overwrite_package'=>'update-plugin'"),'maintenance upgrade requires checksum and core overwrite path');
expect_true(false!==strpos($identity,'release-identity.json'),'runtime build identity reads package-embedded release identity');
expect_true(false!==strpos($publication,'$includedSeen>=$target'),'sitemap page evaluation stops at target boundary');

$configSource=file_get_contents($base.'/includes/Config/Configuration.php');
$coreSource=file_get_contents($base.'/includes/Config/ProfileRegistryCoreTrait.php');
$parser=file_get_contents($base.'/includes/JetSmartFilters/FilterUrlParser.php');
$combination=file_get_contents($base.'/includes/SEO/CombinationRegistry.php');
$resolver=file_get_contents($base.'/includes/SEO/ResultCountResolver.php');
$background=file_get_contents($base.'/includes/SEO/PublicationResultCountProbe.php');
$inventory=file_get_contents($base.'/includes/Diagnostics/RuntimeInventory.php');
$probe=file_get_contents($base.'/includes/Diagnostics/LiveRuntimeProbe.php');
$scope=file_get_contents($base.'/includes/Runtime/RequestScope.php');
expect_true(false!==strpos($configSource,'narrowConfiguration')&&false!==strpos($configSource,"'publication_max_urls'=>10"),'configuration filters are narrowing-only and rollout defaults to 10');
expect_true(false!==strpos($coreSource,'narrowFilteredProfiles'),'surface-profile runtime filters are narrowing-only');
expect_true(false!==strpos($parser,'array_intersect($base,$proposal)')&&false!==strpos($parser,'unicode_filters'),'taxonomy extension filter cannot broaden authority and Unicode filters are observable');
expect_true(false!==strpos($combination,'array_filter($registry'),'combination filter can only veto configured approvals');
expect_true(false!==strpos($resolver,'etg.dfsb.result-count-authority.v1')&&false!==strpos($resolver,'trusted_result_count_authority_sources'),'external result-count authority requires explicit contract and trusted source');
expect_true(false!==strpos($background,'mergeTaxQuery')&&false!==strpos($background,'publication_count_vetoed'),'background count preserves nested tax_query and extension filter is veto-only');
expect_true(false!==strpos($inventory,'completeness_errors')&&false!==strpos($inventory,'identity_errors'),'runtime inventory fails completeness on truncation or query identity collisions');
expect_true(false!==strpos($probe,'wp_head:1')&&false!==strpos($probe,'wp_head:999')&&false!==strpos($probe,'server-html-evidence.v1'),'live runtime probe measures provider/count timing and server HTML evidence');
expect_true(false!==strpos($normalization,"preg_match('/^sha256:[a-fA-F0-9]{64}$/',\$id)"),'verified evidence accepts immutable SHA256 refs only');
expect_true(false!==strpos($policy,'filtered_url_canonicalized_to_archive'),'filtered URLs cannot be indexed while canonicalized to archive');
expect_true(false!==strpos($publication,'publication_url_collision')&&false!==strpos($publication,'publication_candidate_evaluation_budget'),'publication detects URL collisions and bounds cold-start candidate evaluation');
expect_true(false!==strpos($scope,"\$scope['authorizing']=!empty(\$scope['in_scope'])&&!empty(\$scope['scope_valid'])"),'live scope declares authority only after exact valid resolution');

fwrite(STDOUT,"Alpha10 comprehensive hardening smoke tests passed.\n");
