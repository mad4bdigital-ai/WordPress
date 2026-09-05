<?php
namespace ETG\DynamicFilterSEOBridge\Admin;

use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Context\FilterContextBuilder;
use ETG\DynamicFilterSEOBridge\Runtime\Readiness;
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventory;
use ETG\DynamicFilterSEOBridge\Diagnostics\InventoryReconciler;
use ETG\DynamicFilterSEOBridge\SEO\IndexingPolicy;
use ETG\DynamicFilterSEOBridge\Simulation\ScenarioSimulator;

final class OperationalPage {
	private $config; private $profiles; private $readiness; private $builder; private $policy; private $simulator; private $inventory; private $reconciler;
	public function __construct(Configuration $config,ProfileRegistry $profiles,Readiness $readiness,FilterContextBuilder $builder,IndexingPolicy $policy,ScenarioSimulator $simulator,RuntimeInventory $inventory,InventoryReconciler $reconciler){$this->config=$config;$this->profiles=$profiles;$this->readiness=$readiness;$this->builder=$builder;$this->policy=$policy;$this->simulator=$simulator;$this->inventory=$inventory;$this->reconciler=$reconciler;}
	public function register():void{add_action('admin_init',array($this,'settings'));add_action('admin_menu',array($this,'menu'));add_action('admin_post_etg_dfsb_export_runtime_inventory',array($this,'exportRuntimeInventory'));add_action('admin_post_etg_dfsb_export_inventory_reconciliation',array($this,'exportInventoryReconciliation'));}
	public function settings():void{register_setting('etg_dfsb',Configuration::OPTION_NAME,array('sanitize_callback'=>array($this->config,'sanitize')));}
	public function menu():void{add_options_page('ETG Filter SEO','ETG Filter SEO','manage_options','etg-filter-seo',array($this,'render'));}

	public function render():void{
		if(!current_user_can('manage_options')){return;}
		$config=$this->config->all();
		$readiness=$this->readiness->report();
		$previewUri=isset($_GET['etg_preview_url'])?wp_unslash((string)$_GET['etg_preview_url']):'';
		$preview=null;
		if(''!==$previewUri){$context=$this->builder->build($previewUri);$preview=array('context'=>$this->safeContext($context),'indexing'=>$this->policy->decide($context));}
		$scenarioRaw=isset($_GET['etg_scenario_json'])?wp_unslash((string)$_GET['etg_scenario_json']):$this->defaultScenario();
		$scenarioResult=null;
		if(isset($_GET['etg_run_scenario'])){$decoded=json_decode($scenarioRaw,true);$scenarioResult=is_array($decoded)?$this->simulator->run($decoded):array('contract'=>'etg.dfsb.simulation.v1','synthetic'=>true,'error'=>'invalid_scenario_json');}
		$discovery=$this->profiles->discovery();
		$runtimeInventory=isset($_GET['etg_show_runtime_inventory'])?$this->inventory->collect():null;
		$reconciliation=isset($_GET['etg_reconcile_runtime_inventory'])?$this->reconciler->analyze($this->inventory->collect(),$this->profiles->all()):null;
		$blueprintPostType=isset($_GET['etg_blueprint_post_type'])?sanitize_key((string)wp_unslash($_GET['etg_blueprint_post_type'])):'';
		$blueprintTaxonomies=isset($_GET['etg_blueprint_taxonomies'])?preg_split('/[\r\n,]+/',wp_unslash((string)$_GET['etg_blueprint_taxonomies'])):array();
		$blueprint=null;
		if(isset($_GET['etg_build_blueprint'])){$blueprint=$this->profiles->blueprint($blueprintPostType,(array)$blueprintTaxonomies);}
		?>
		<div class="wrap">
		<h1>ETG Dynamic Filter SEO Bridge</h1>
		<p><strong>Readiness:</strong> <?php echo esc_html(strtoupper((string)$readiness['status'])); ?> &mdash; config <?php echo esc_html((string)$readiness['configuration_revision']); ?> &mdash; profiles <?php echo esc_html((string)$readiness['profile_count']); ?></p>
		<?php if(!empty($readiness['missing_dependencies'])):?><div class="notice notice-error inline"><p>Missing dependencies: <?php echo esc_html(implode(', ',$readiness['missing_dependencies'])); ?></p></div><?php endif;?>
		<?php if(!empty($readiness['missing_capabilities'])):?><div class="notice notice-error inline"><p>Missing capabilities: <?php echo esc_html(implode(', ',$readiness['missing_capabilities'])); ?></p></div><?php endif;?>
		<?php if(!empty($readiness['configuration_errors'])):?><div class="notice notice-error inline"><p>Configuration errors: <?php echo esc_html(implode(', ',$readiness['configuration_errors'])); ?></p></div><?php endif;?>
		<form method="post" action="options.php"><?php settings_fields('etg_dfsb'); ?><table class="form-table" role="presentation">
		<?php
		$this->checkboxRow('Global enabled (master kill switch)','enabled',$config);
		$this->rawTextareaRow('Surface Profiles JSON (authoritative)','profiles_json',$config,18);
		$this->textRow('Legacy archive slugs (inherited by default tours profile)','archive_slugs',$config);
		$this->textRow('Legacy providers','providers',$config);
		$this->textRow('Legacy query IDs','query_ids',$config);
		$this->textRow('Legacy allowed taxonomies','allowed_taxonomies',$config);
		$this->numberRow('Legacy maximum filters','max_filters',$config);
		$this->textRow('Allowed functional query params','allowed_query_params',$config);
		$this->textRow('Tracking query params','tracking_query_params',$config);
		$this->checkboxRow('Use built-in JetEngine count authority','enable_jet_engine_result_count_adapter',$config);
		$this->checkboxRow('Trust legacy numeric count hook','trust_legacy_result_count',$config);
		$this->checkboxRow('Require authoritative result count for index','require_result_count_for_index',$config);
		$this->numberRow('Legacy minimum results: location','min_results_location',$config);
		$this->numberRow('Legacy minimum results: pair','min_results_pair',$config);
		$this->numberRow('Legacy minimum results: triple','min_results_triple',$config);
		$this->checkboxRow('Legacy index single tour type','index_single_tour_type',$config);
		$this->textRow('Legacy indexable location levels','indexable_location_levels',$config);
		$this->checkboxRow('Legacy require exact combination approval','require_exact_combination_approval',$config);
		$this->textareaRow('Legacy indexable combinations','indexable_combinations',$config);
		$this->checkboxRow('Global content readiness fallback','require_content_readiness',$config);
		$this->checkboxRow('Global meta description fallback','require_meta_description',$config);
		$this->numberRow('Global minimum content characters fallback','min_content_chars',$config);
		?>
		<tr><th scope="row">Global canonical fallback</th><td><select name="<?php echo esc_attr(Configuration::OPTION_NAME); ?>[canonical_mode]"><option value="filtered" <?php selected($config['canonical_mode'],'filtered'); ?>>Filtered URL</option><option value="archive" <?php selected($config['canonical_mode'],'archive'); ?>>Language-aware archive URL</option></select></td></tr>
		<?php $this->checkboxRow('Diagnostics enabled','diagnostics_enabled',$config);$this->checkboxRow('Log decisions to PHP error log','log_decisions',$config); ?>
		</table><?php submit_button();?></form>

		<hr/><h2>Profile Discovery (read-only)</h2>
		<p>This inventory is discovery only. It never authorizes indexing or writes profiles.</p>
		<pre style="white-space:pre-wrap;max-width:1100px;background:#fff;padding:16px;border:1px solid #ccd0d4"><?php echo esc_html(wp_json_encode($discovery,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));?></pre>

		<h3>Build Disabled Profile Blueprint</h3>
		<p>The blueprint is read-only, starts disabled, and intentionally leaves archive/routes/taxonomy-set/combination authorities empty.</p>
		<form method="get"><input type="hidden" name="page" value="etg-filter-seo"/><input class="regular-text code" type="text" name="etg_blueprint_post_type" value="<?php echo esc_attr($blueprintPostType);?>" placeholder="property"/> <input class="regular-text code" type="text" name="etg_blueprint_taxonomies" value="<?php echo esc_attr(implode(', ',(array)$blueprintTaxonomies));?>" placeholder="property_city, property_type"/><input type="hidden" name="etg_build_blueprint" value="1"/><?php submit_button('Build Disabled Blueprint','secondary','',false);?></form>
		<?php if(null!==$blueprint):?><pre style="white-space:pre-wrap;max-width:1100px;background:#fff;padding:16px;border:1px solid #ccd0d4"><?php echo esc_html(wp_json_encode($blueprint,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));?></pre><?php endif;?>

		<hr/><h2>Runtime Inventory Export (read-only)</h2>
		<p>Generates a bounded structural snapshot for staging acceptance. The contract is explicitly <code>authorizing=false</code>; it does not save profiles, terms, routes, or index permissions, and it omits raw Query Builder arguments.</p>
		<form method="get" style="display:inline-block;margin-right:8px"><input type="hidden" name="page" value="etg-filter-seo"/><input type="hidden" name="etg_show_runtime_inventory" value="1"/><?php submit_button('Generate Runtime Inventory','secondary','',false);?></form>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" style="display:inline-block"><input type="hidden" name="action" value="etg_dfsb_export_runtime_inventory"/><?php wp_nonce_field('etg_dfsb_export_runtime_inventory'); submit_button('Download Inventory JSON','secondary','',false);?></form>
		<?php if(null!==$runtimeInventory):?><pre style="white-space:pre-wrap;max-width:1100px;max-height:620px;overflow:auto;background:#fff;padding:16px;border:1px solid #ccd0d4"><?php echo esc_html(wp_json_encode($runtimeInventory,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));?></pre><?php endif;?>

		<h3>Inventory Reconciliation (read-only)</h3>
		<p>Compares the current bounded inventory with configured Profiles. Findings may block or require review, but reconciliation never enables Profiles or copies discovered routes into authority.</p>
		<form method="get" style="display:inline-block;margin-right:8px"><input type="hidden" name="page" value="etg-filter-seo"/><input type="hidden" name="etg_reconcile_runtime_inventory" value="1"/><?php submit_button('Reconcile Current Inventory','secondary','',false);?></form>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" style="display:inline-block"><input type="hidden" name="action" value="etg_dfsb_export_inventory_reconciliation"/><?php wp_nonce_field('etg_dfsb_export_inventory_reconciliation'); submit_button('Download Reconciliation JSON','secondary','',false);?></form>
		<?php if(null!==$reconciliation):?><pre style="white-space:pre-wrap;max-width:1100px;max-height:620px;overflow:auto;background:#fff;padding:16px;border:1px solid #ccd0d4"><?php echo esc_html(wp_json_encode($reconciliation,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));?></pre><?php endif;?>

		<hr/><h2>Preview / Explain Real URL</h2>
		<form method="get"><input type="hidden" name="page" value="etg-filter-seo"/><input type="text" class="regular-text code" style="width:70%" name="etg_preview_url" value="<?php echo esc_attr($previewUri);?>" placeholder="/it/tours-and-activities/jsf/... or https://example.com/..."/><?php submit_button('Explain URL','secondary','',false);?></form>
		<?php if(null!==$preview):?><pre style="white-space:pre-wrap;max-width:1100px;background:#fff;padding:16px;border:1px solid #ccd0d4"><?php echo esc_html(wp_json_encode($preview,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));?></pre><?php endif;?>

		<hr/><h2>Synthetic Scenario Lab</h2>
		<p>Simulation is non-mutating and is not staging acceptance evidence. Use it to challenge profiles before activation.</p>
		<form method="get"><input type="hidden" name="page" value="etg-filter-seo"/><textarea class="large-text code" rows="14" name="etg_scenario_json"><?php echo esc_textarea($scenarioRaw);?></textarea><input type="hidden" name="etg_run_scenario" value="1"/><?php submit_button('Run Synthetic Scenario','secondary','',false);?></form>
		<?php if(null!==$scenarioResult):?><pre style="white-space:pre-wrap;max-width:1100px;background:#fff;padding:16px;border:1px solid #ccd0d4"><?php echo esc_html(wp_json_encode($scenarioResult,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));?></pre><?php endif;?>
		</div><?php
	}

	public function exportRuntimeInventory():void{
		if(!current_user_can('manage_options')){wp_die('Forbidden','Forbidden',array('response'=>403));}
		check_admin_referer('etg_dfsb_export_runtime_inventory');
		$payload=$this->inventory->collect();
		if(function_exists('nocache_headers')){nocache_headers();}
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="etg-dfsb-runtime-inventory.json"');
		echo wp_json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		exit;
	}

	public function exportInventoryReconciliation():void{
		if(!current_user_can('manage_options')){wp_die('Forbidden','Forbidden',array('response'=>403));}
		check_admin_referer('etg_dfsb_export_inventory_reconciliation');
		$payload=$this->reconciler->analyze($this->inventory->collect(),$this->profiles->all());
		if(function_exists('nocache_headers')){nocache_headers();}
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="etg-dfsb-inventory-reconciliation.json"');
		echo wp_json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		exit;
	}

	private function defaultScenario():string{
		$profiles=$this->profiles->all();$first=$profiles?(array)reset($profiles):array();$profileId=(string)($first['id']??'tours');$rules=(array)($first['taxonomy_rules']??array());$filters=array();foreach(array_slice(array_keys($rules),0,2) as $taxonomy){$filters[$taxonomy]='example';}
		$scenario=array('profile_id'=>$profileId,'language'=>'en','filters'=>$filters,'result_count'=>10,'result_count_authoritative'=>true,'runtime_ready'=>true,'provider_match'=>true,'content_ready'=>true);
		if(!empty($first['require_post_type_binding'])&&!empty($first['post_types'])){$scenario['post_type']=(string)reset($first['post_types']);}
		return (string)wp_json_encode($scenario,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
	}
	private function textRow(string $label,string $key,array $config):void{$value=implode(', ',(array)($config[$key]??array()));printf('<tr><th scope="row">%1$s</th><td><input class="regular-text code" type="text" name="%2$s[%3$s]" value="%4$s" /></td></tr>',esc_html($label),esc_attr(Configuration::OPTION_NAME),esc_attr($key),esc_attr($value));}
	private function textareaRow(string $label,string $key,array $config):void{$value=implode("\n",(array)($config[$key]??array()));printf('<tr><th scope="row">%1$s</th><td><textarea class="large-text code" rows="5" name="%2$s[%3$s]">%4$s</textarea></td></tr>',esc_html($label),esc_attr(Configuration::OPTION_NAME),esc_attr($key),esc_textarea($value));}
	private function rawTextareaRow(string $label,string $key,array $config,int $rows):void{$value=(string)($config[$key]??'');$decoded=json_decode($value,true);if(is_array($decoded)){$value=(string)wp_json_encode($decoded,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}printf('<tr><th scope="row">%1$s</th><td><textarea class="large-text code" rows="%5$d" name="%2$s[%3$s]">%4$s</textarea></td></tr>',esc_html($label),esc_attr(Configuration::OPTION_NAME),esc_attr($key),esc_textarea($value),$rows);}
	private function numberRow(string $label,string $key,array $config):void{printf('<tr><th scope="row">%1$s</th><td><input type="number" min="0" name="%2$s[%3$s]" value="%4$d" /></td></tr>',esc_html($label),esc_attr(Configuration::OPTION_NAME),esc_attr($key),(int)($config[$key]??1));}
	private function checkboxRow(string $label,string $key,array $config):void{printf('<tr><th scope="row">%1$s</th><td><input type="hidden" name="%2$s[%3$s]" value="0" /><label><input type="checkbox" name="%2$s[%3$s]" value="1" %4$s /> Enabled</label></td></tr>',esc_html($label),esc_attr(Configuration::OPTION_NAME),esc_attr($key),checked(!empty($config[$key]),true,false));}
	private function safeContext(array $context):array{foreach((array)($context['terms']??array()) as $role=>$term){if(is_array($term)){unset($term['description'],$term['short_description'],$term['gallery_ids']);$context['terms'][$role]=$term;}}if(isset($context['combo']['gallery_ids'])){$context['combo']['gallery_ids']=array_slice((array)$context['combo']['gallery_ids'],0,10);}if(isset($context['profile']['indexable_combinations'])){$context['profile']['indexable_combinations']=array_slice((array)$context['profile']['indexable_combinations'],0,20);}return $context;}
}
