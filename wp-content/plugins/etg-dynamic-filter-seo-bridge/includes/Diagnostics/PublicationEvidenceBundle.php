<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Runtime\Readiness;
use ETG\DynamicFilterSEOBridge\SEO\PublicationRegistry;

final class PublicationEvidenceBundle {
	private $config; private $profiles; private $readiness; private $inventory; private $publication;
	public function __construct(Configuration $config, ProfileRegistry $profiles, Readiness $readiness, RuntimeInventory $inventory, PublicationRegistry $publication){$this->config=$config;$this->profiles=$profiles;$this->readiness=$readiness;$this->inventory=$inventory;$this->publication=$publication;}
	public function collect( int $previewLimit = 50 ): array {
		$previewLimit=max(1,min(100,$previewLimit));
		$inventory=$this->inventory->collect(); $readiness=$this->readiness->report(); $summary=$this->publication->publicationSummary($previewLimit); $profileEvidence=array(); $activationBlockers=array();
		if($this->config->enabled()){$activationBlockers[]='global_bridge_on_during_dark_validation_evidence';}
		if((string)($inventory['contract']??'')!==RuntimeInventory::CONTRACT||empty($inventory['evidence_complete'])){$activationBlockers[]='runtime_inventory_incomplete';}
		if(!empty($readiness['missing_dependencies'])){$activationBlockers[]='missing_dependencies';}
		if(!empty($readiness['missing_capabilities'])){$activationBlockers[]='missing_capabilities';}
		if(!empty($readiness['configuration_errors'])&&$this->config->enabled()){$activationBlockers[]='configuration_errors';}
		if(!empty($readiness['failed_runtime_checks'])){$activationBlockers[]='failed_runtime_checks';}
		$enabledProfiles=0;
		foreach($this->profiles->all() as $profileId=>$profile){
			$publication=(array)($profile['publication']??array()); $blockers=array();
			if(empty($profile['enabled'])){$blockers[]='profile_disabled';}else{$enabledProfiles++;}
			if(!empty($profile['require_provider_observation_for_index'])){if(empty($publication['provider_observation_verified'])){$blockers[]='provider_observation_not_verified';}if(empty($publication['provider_observation_evidence_id'])){$blockers[]='provider_observation_evidence_missing';}}
			if(!empty($publication['require_elementor_content'])){if(empty($publication['elementor_content_verified'])){$blockers[]='elementor_content_not_verified';}if(empty($publication['elementor_verification_evidence_id'])){$blockers[]='elementor_verification_evidence_missing';}}
			if(!empty($publication['require_result_count_parity_for_publication'])){if(empty($publication['result_count_parity_verified'])){$blockers[]='result_count_parity_not_verified';}if(empty($publication['result_count_parity_evidence_id'])){$blockers[]='result_count_parity_evidence_missing';}}
			if(empty($profile['indexable_combinations'])){$blockers[]='approved_combinations_empty';} if(empty($profile['routes'])){$blockers[]='exact_route_missing';} if(empty($profile['allowed_taxonomy_sets'])){$blockers[]='allowed_taxonomy_sets_empty';}
			$profileEvidence[(string)$profileId]=array('enabled'=>!empty($profile['enabled']),'route_count'=>count((array)($profile['routes']??array())),'approved_combination_count'=>count((array)($profile['indexable_combinations']??array())),'provider_observation_verified'=>!empty($publication['provider_observation_verified']),'provider_observation_evidence_id'=>(string)($publication['provider_observation_evidence_id']??''),'elementor_content_verified'=>!empty($publication['elementor_content_verified']),'elementor_verification_evidence_id'=>(string)($publication['elementor_verification_evidence_id']??''),'result_count_parity_verified'=>!empty($publication['result_count_parity_verified']),'result_count_parity_evidence_id'=>(string)($publication['result_count_parity_evidence_id']??''),'max_preview_urls'=>(int)($publication['max_preview_urls']??50),'max_publication_urls'=>(int)($publication['max_publication_urls']??100),'blockers'=>array_values(array_unique($blockers)));
		}
		if(0===$enabledProfiles){$activationBlockers[]='no_enabled_profiles_for_activation';}
		foreach($profileEvidence as $row){if(!empty($row['enabled'])){foreach((array)$row['blockers'] as $blocker){$activationBlockers[]='profile_blocker:'.$blocker;}}}
		return array('contract'=>'etg.dfsb.publication-evidence-bundle.v1','plugin_version'=>defined('ETG_DFSB_VERSION')?(string)ETG_DFSB_VERSION:'','generated_at_gmt'=>gmdate('c'),'authorizing'=>false,'read_only'=>true,'profile_mutation'=>false,'merge_authorized'=>false,'production_activation_authorized'=>false,'global_enabled'=>$this->config->enabled(),'configuration_revision'=>$this->config->revision(),'evidence_complete'=>empty($activationBlockers),'activation_blockers'=>array_values(array_unique($activationBlockers)),'required_external_evidence'=>array('server_side_elementor_html_snapshot','frontend_vs_request_adapter_vs_background_count_parity','multilingual_hreflang_and_translated_slug_validation','global_off_empty_live_sitemap_validation','bounded_global_on_sitemap_validation','sitemap_ttfb_query_count_and_memory_baseline'),'profile_evidence'=>$profileEvidence,'readiness'=>$readiness,'runtime_inventory'=>$inventory,'publication_preview'=>$summary);
	}
}
