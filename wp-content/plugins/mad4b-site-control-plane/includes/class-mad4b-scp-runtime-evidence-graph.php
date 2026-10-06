<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provenance-bound, read-only runtime evidence graph.
 *
 * The graph only observes already-loaded/registered runtime structures and
 * bounded plugin headers. It never executes discovered callbacks, unknown
 * plugin code, REST routes or cron events and never creates authority.
 */
final class MAD4B_SCP_Runtime_Evidence_Graph {
	const CONTRACT = 'mad4b.runtime-evidence-graph.v2';
	const GENERATION_CONTRACT = 'mad4b.runtime-evidence-generation.v2';
	const DIFF_CONTRACT = 'mad4b.runtime-evidence-graph-diff.v2';
	const MAX_ITEMS_PER_KIND = 256;
	const MAX_SYMBOLS = 256;
	const MAX_EDGES = 1024;
	const MAX_SNAPSHOT_BYTES = 2097152;
	const SOFT_ELAPSED_MS = 1500;
	const SOFT_MEMORY_DELTA_BYTES = 16777216;

	private static $request_cache = null;

	public static function boot() {
		if ( function_exists( 'add_action' ) ) add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register_read(
			'mad4b/runtime-evidence-graph',
			'Runtime Evidence Graph',
			array( __CLASS__, 'snapshot' ),
			array(
				'type'=>'object',
				'properties'=>array(
					'refresh'=>array('type'=>'boolean','default'=>false),
				),
				'additionalProperties'=>false,
			)
		);
		self::register_read(
			'mad4b/runtime-evidence-graph-diff',
			'Runtime Evidence Graph Diff',
			array( __CLASS__, 'diff' ),
			array(
				'type'=>'object',
				'properties'=>array(
					'before'=>array('type'=>'object','additionalProperties'=>true),
				),
				'required'=>array('before'),
				'additionalProperties'=>false,
			)
		);
	}

	private static function register_read( $name, $label, $callback, array $schema ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability( $name, array(
			'label'=>$label,
			'description'=>$label . ' from bounded structural runtime evidence. This ability is read-only and non-authorizing.',
			'category'=>'mad4b-read',
			'execute_callback'=>$callback,
			'permission_callback'=>array('MAD4B_SCP_Policy','can_read'),
			'input_schema'=>$schema,
			'output_schema'=>array('type'=>'object','additionalProperties'=>true),
			'meta'=>array(
				'public'=>false,
				'show_in_rest'=>false,
				'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
				'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true),
			),
		) );
	}

	public static function clear_request_cache() {
		self::$request_cache = null;
	}

	public static function snapshot( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$refresh = ! empty( $input['refresh'] );
		if ( ! $refresh && is_array( self::$request_cache ) ) {
			$cached = self::$request_cache;
			$cached['metrics']['cache_hit'] = true;
			return $cached;
		}

		$started = microtime( true );
		$memory_before = function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : 0;

		$ability_nodes = self::abilities();
		$nodes = array(
			'providers'=>self::providers(),
			'components'=>self::components(),
			'abilities'=>$ability_nodes,
			'schemas'=>self::schemas($ability_nodes),
			'operations'=>self::operations(),
			'plugins'=>self::plugins(),
			'rest_routes'=>self::rest_routes(),
			'post_types'=>self::post_types(),
			'taxonomies'=>self::taxonomies(),
			'meta_keys'=>self::meta_keys(),
			'hooks'=>self::hooks(),
			'cron_hooks'=>self::cron_hooks(),
			'admin_routes'=>self::admin_routes(),
			'mcp_descriptors'=>self::mcp_descriptors($ability_nodes),
			'symbols'=>self::symbols(),
			'database_tables'=>self::database_tables(),
		);
		$collection_status = array();
		foreach ( $nodes as $kind => $rows ) {
			usort( $rows, static function( $a, $b ) {
				return strcmp( isset($a['id'])?(string)$a['id']:'', isset($b['id'])?(string)$b['id']:'' );
			} );
			$nodes[ $kind ] = array_slice( $rows, 0, self::MAX_ITEMS_PER_KIND );
			$collection_status[ $kind ] = self::collection_status( $kind, count( $nodes[ $kind ] ) );
		}
		$site = class_exists( 'MAD4B_SCP_Ability_Contract_Inspector' )
			? MAD4B_SCP_Ability_Contract_Inspector::site_binding()
			: array();
		$edges = self::edges( $nodes );
		$basis = array(
			'contract'=>self::GENERATION_CONTRACT,
			'site'=>$site,
			'nodes'=>$nodes,
			'edges'=>$edges,
		);
		$generation = self::digest( self::GENERATION_CONTRACT, $basis );
		$elapsed_ms = round( ( microtime( true ) - $started ) * 1000, 3 );
		$memory_after = function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : $memory_before;
		$memory_delta = max( 0, (int) $memory_after - (int) $memory_before );
		$complete_for_absence = true;
		foreach ( $collection_status as $status ) {
			if ( empty( $status['trustworthy_for_absence'] ) ) {
				$complete_for_absence = false;
				break;
			}
		}

		$snapshot = array(
			'contract'=>self::CONTRACT,
			'generation_contract'=>self::GENERATION_CONTRACT,
			'generation_sha256'=>$generation,
			'site_binding'=>$site,
			'observation_phase'=>array(
				'wp_abilities_initialized'=>function_exists('did_action') ? did_action('wp_abilities_api_init') > 0 : null,
				'rest_api_initialized'=>function_exists('did_action') ? did_action('rest_api_init') > 0 : null,
			),
			'nodes'=>$nodes,
			'edges'=>$edges,
			'counts'=>array_map('count',$nodes),
			'edge_count'=>count($edges),
			'collection_status'=>$collection_status,
			'complete_for_absence'=>$complete_for_absence,
			'semantic_dimensions'=>array('provider','component','capability','operation','schema','precondition','effect','reversal','evidence'),
			'discovery'=>array(
				'callbacks_executed'=>false,
				'unknown_plugin_code_executed'=>false,
				'unknown_endpoints_invoked'=>false,
				'secret_values_read'=>false,
				'writes_performed'=>false,
			),
			'metrics'=>array(
				'elapsed_ms'=>$elapsed_ms,
				'memory_delta_bytes'=>$memory_delta,
				'elapsed_budget_ms'=>self::SOFT_ELAPSED_MS,
				'memory_delta_budget_bytes'=>self::SOFT_MEMORY_DELTA_BYTES,
				'within_soft_budget'=>$elapsed_ms <= self::SOFT_ELAPSED_MS && $memory_delta <= self::SOFT_MEMORY_DELTA_BYTES,
				'cache_hit'=>false,
			),
			'authorizing'=>false,
			'mutation_performed'=>false,
		);
		self::$request_cache = $snapshot;
		return $snapshot;
	}

	public static function diff( $input = array() ) {
		$input = is_array($input) ? $input : array();
		$before = isset($input['before']) && is_array($input['before']) ? $input['before'] : array();
		$valid = self::validate_snapshot_envelope( $before );
		if ( is_wp_error( $valid ) ) return $valid;

		$after = self::snapshot( array( 'refresh'=>true ) );
		if ( is_wp_error($after) ) return $after;
		$before_nodes = isset($before['nodes']) && is_array($before['nodes']) ? $before['nodes'] : array();
		$after_nodes = $after['nodes'];
		$added=array(); $removed=array(); $changed=array(); $uncertain_added=array(); $uncertain_removed=array();
		$kinds=array_values(array_unique(array_merge(array_keys($before_nodes),array_keys($after_nodes))));
		sort($kinds,SORT_STRING);

		foreach($kinds as $kind){
			$b=self::index_rows(isset($before_nodes[$kind])&&is_array($before_nodes[$kind])?$before_nodes[$kind]:array());
			$a=self::index_rows(isset($after_nodes[$kind])&&is_array($after_nodes[$kind])?$after_nodes[$kind]:array());
			$before_complete=self::kind_trustworthy_for_absence($before,$kind);
			$after_complete=self::kind_trustworthy_for_absence($after,$kind);
			foreach($a as $id=>$row){
				if(!isset($b[$id])) {
					if($before_complete) $added[]=array('kind'=>$kind,'id'=>$id);
					else $uncertain_added[]=array('kind'=>$kind,'id'=>$id);
					continue;
				}
				$bd=self::digest('mad4b.runtime-evidence-node.v2',$b[$id]);
				$ad=self::digest('mad4b.runtime-evidence-node.v2',$row);
				if(!hash_equals($bd,$ad)) $changed[]=array('kind'=>$kind,'id'=>$id,'before_sha256'=>$bd,'after_sha256'=>$ad);
			}
			foreach($b as $id=>$row){
				if(isset($a[$id])) continue;
				if($after_complete) $removed[]=array('kind'=>$kind,'id'=>$id);
				else $uncertain_removed[]=array('kind'=>$kind,'id'=>$id);
			}
		}

		$impact=self::transitive_impact($before,$after,$removed,$changed);
		$affected_read_ids=array_fill_keys($impact['affected_abilities'],true);
		$unrelated_reads=0;
		foreach(isset($after_nodes['abilities'])&&is_array($after_nodes['abilities'])?$after_nodes['abilities']:array() as $row){
			if(!is_array($row)||empty($row['id'])||empty($row['readonly'])||'read'!==(isset($row['execution_lane'])?$row['execution_lane']:'')) continue;
			if(!isset($affected_read_ids[(string)$row['id']])) $unrelated_reads++;
		}
		$incomplete=array();
		foreach($after['collection_status'] as $kind=>$status) if(empty($status['trustworthy_for_absence'])) $incomplete[]=$kind;
		sort($incomplete,SORT_STRING);

		return array(
			'contract'=>self::DIFF_CONTRACT,
			'before_generation_sha256'=>(string)$before['generation_sha256'],
			'after_generation_sha256'=>$after['generation_sha256'],
			'site_binding_verified'=>true,
			'added'=>$added,
			'removed'=>$removed,
			'changed'=>$changed,
			'uncertain_added'=>$uncertain_added,
			'uncertain_removed'=>$uncertain_removed,
			'affected_abilities'=>$impact['affected_abilities'],
			'affected_operations'=>$impact['affected_operations'],
			'affected_workflows'=>$impact['affected_workflows'],
			'dependency_impacts'=>$impact['dependency_impacts'],
			'unrelated_compatible_read_count'=>$unrelated_reads,
			'incomplete_kinds'=>$incomplete,
			'comparison_trustworthy_for_absence'=>empty($uncertain_added)&&empty($uncertain_removed)&&empty($incomplete),
			'isolation_policy'=>'removed_or_changed_only_fail_closed',
			'authorizing'=>false,
			'mutation_performed'=>false,
		);
	}

	private static function collector_contracts() {
		return array(
			'providers','components','abilities','schemas','operations','plugins','rest_routes','post_types',
			'taxonomies','meta_keys','hooks','cron_hooks','admin_routes','mcp_descriptors','symbols','database_tables',
		);
	}

	private static function collection_status( $kind, $emitted_count ) {
		$lifecycle='ready';
		if('rest_routes'===$kind && (!function_exists('did_action') || did_action('rest_api_init')<=0)) $lifecycle='not_initialized';
		elseif('providers'===$kind && !class_exists('MAD4B_SCP_Provider_Contracts')) $lifecycle='unavailable';
		elseif('operations'===$kind && !class_exists('MAD4B_SCP_Operation_Registry')) $lifecycle='unavailable';
		elseif('meta_keys'===$kind && !function_exists('get_registered_meta_keys')) $lifecycle='unavailable';
		elseif('admin_routes'===$kind && !class_exists('MAD4B_SCP_Admin_Route_Registry')) $lifecycle='unavailable';

		$possible_truncation=(int)$emitted_count>=self::MAX_ITEMS_PER_KIND;
		return array(
			'kind'=>(string)$kind,
			'observed_count'=>$possible_truncation ? null : (int)$emitted_count,
			'emitted_count'=>(int)$emitted_count,
			'max_items'=>self::MAX_ITEMS_PER_KIND,
			'lifecycle'=>$lifecycle,
			'truncated'=>$possible_truncation,
			'count_observation_complete'=>!$possible_truncation,
			'trustworthy_for_absence'=>'ready'===$lifecycle && !$possible_truncation,
		);
	}

	private static function kind_trustworthy_for_absence( array $snapshot, $kind ) {
		return !empty($snapshot['collection_status'][$kind]['trustworthy_for_absence']);
	}

	private static function validate_snapshot_envelope( array $snapshot ) {
		$encoded=wp_json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		if(!is_string($encoded)||strlen($encoded)>self::MAX_SNAPSHOT_BYTES) {
			return new WP_Error('mad4b_runtime_graph_before_oversized','Before snapshot exceeds the bounded graph input limit.');
		}
		if(self::CONTRACT!==(isset($snapshot['contract'])?(string)$snapshot['contract']:'')) {
			return new WP_Error('mad4b_runtime_graph_before_invalid','Before snapshot does not use the Runtime Candidate Graph v2 contract.');
		}
		if(self::GENERATION_CONTRACT!==(isset($snapshot['generation_contract'])?(string)$snapshot['generation_contract']:'')) {
			return new WP_Error('mad4b_runtime_graph_generation_contract_invalid','Before snapshot generation contract is invalid.');
		}
		if(empty($snapshot['nodes'])||!is_array($snapshot['nodes'])||!isset($snapshot['edges'])||!is_array($snapshot['edges'])) {
			return new WP_Error('mad4b_runtime_graph_before_shape_invalid','Before snapshot graph shape is invalid.');
		}
		if(count($snapshot['edges'])>self::MAX_EDGES) return new WP_Error('mad4b_runtime_graph_before_edges_unbounded','Before snapshot contains too many graph edges.');
		$allowed=self::collector_contracts();
		foreach($snapshot['nodes'] as $kind=>$rows) {
			if(!in_array((string)$kind,$allowed,true)||!is_array($rows)||count($rows)>self::MAX_ITEMS_PER_KIND) {
				return new WP_Error('mad4b_runtime_graph_before_nodes_unbounded','Before snapshot node inventory is invalid or unbounded.');
			}
		}

		$current_site=class_exists('MAD4B_SCP_Ability_Contract_Inspector')?MAD4B_SCP_Ability_Contract_Inspector::site_binding():array();
		$before_site=isset($snapshot['site_binding'])&&is_array($snapshot['site_binding'])?$snapshot['site_binding']:array();
		foreach(array('site_uuid','origin','environment') as $field){
			$current=isset($current_site[$field])?(string)$current_site[$field]:'';
			$previous=isset($before_site[$field])?(string)$before_site[$field]:'';
			if(''!==$current && !hash_equals($current,$previous)) return new WP_Error('mad4b_runtime_graph_cross_site_rejected','Runtime graph comparison is restricted to the same site and environment.');
		}
		$basis=array('contract'=>self::GENERATION_CONTRACT,'site'=>$before_site,'nodes'=>$snapshot['nodes'],'edges'=>$snapshot['edges']);
		$expected=self::digest(self::GENERATION_CONTRACT,$basis);
		$claimed=isset($snapshot['generation_sha256'])?(string)$snapshot['generation_sha256']:'';
		if(1!==preg_match('/^[a-f0-9]{64}$/D',$claimed)||!hash_equals($expected,$claimed)) {
			return new WP_Error('mad4b_runtime_graph_generation_mismatch','Before snapshot generation digest does not match its graph contents.');
		}
		$status=isset($snapshot['collection_status'])&&is_array($snapshot['collection_status'])?$snapshot['collection_status']:array();
		foreach($allowed as $kind){
			if(!isset($snapshot['nodes'][$kind])||!isset($status[$kind])||!is_array($status[$kind])) return new WP_Error('mad4b_runtime_graph_collection_status_missing','Before snapshot is missing collection completeness evidence.');
			if((int)$status[$kind]['emitted_count']!==count($snapshot['nodes'][$kind])) return new WP_Error('mad4b_runtime_graph_collection_status_mismatch','Before snapshot collection counts do not match emitted graph nodes.');
		}
		return true;
	}

	private static function transitive_impact( array $before, array $after, array $removed, array $changed ) {
		$seeds=array();
		foreach(array_merge($removed,$changed) as $row){
			if(!is_array($row)||empty($row['kind'])||empty($row['id'])) continue;
			$prefix=self::kind_prefix($row['kind']);
			if(''!==$prefix) $seeds[]=$prefix.':'.(string)$row['id'];
		}
		$seeds=array_values(array_unique($seeds));
		$edges=array_merge(isset($before['edges'])&&is_array($before['edges'])?$before['edges']:array(),isset($after['edges'])&&is_array($after['edges'])?$after['edges']:array());
		$reverse=array();
		foreach($edges as $edge){
			if(!is_array($edge)||empty($edge['from'])||empty($edge['to'])) continue;
			$reverse[(string)$edge['to']][]=(string)$edge['from'];
		}
		$seen=array_fill_keys($seeds,true);
		$queue=$seeds;
		while($queue && count($seen)<self::MAX_EDGES*4){
			$current=array_shift($queue);
			foreach(isset($reverse[$current])&&is_array($reverse[$current])?$reverse[$current]:array() as $dependent){
				if(isset($seen[$dependent])) continue;
				$seen[$dependent]=true;
				$queue[]=$dependent;
			}
		}
		$abilities=array(); $operations=array(); $providers=array(); $plugins=array();
		foreach(array_keys($seen) as $key){
			if(0===strpos($key,'ability:')) $abilities[]=substr($key,8);
			elseif(0===strpos($key,'operation:')) $operations[]=substr($key,10);
			elseif(0===strpos($key,'provider:')) $providers[]=substr($key,9);
			elseif(0===strpos($key,'plugin:')) $plugins[]=substr($key,7);
		}
		foreach(array(&$abilities,&$operations,&$providers,&$plugins) as &$items){$items=array_values(array_unique($items));sort($items,SORT_STRING);} unset($items);
		$operation_rows=array_merge(isset($before['nodes']['operations'])&&is_array($before['nodes']['operations'])?$before['nodes']['operations']:array(),isset($after['nodes']['operations'])&&is_array($after['nodes']['operations'])?$after['nodes']['operations']:array());
		$workflows=array();
		foreach($operation_rows as $row){
			if(!is_array($row)||empty($row['id'])||!in_array((string)$row['id'],$operations,true)) continue;
			if(!empty($row['pipeline_profile'])) $workflows[]='pipeline:'.(string)$row['pipeline_profile'];
		}
		$workflows=array_values(array_unique($workflows)); sort($workflows,SORT_STRING);
		$dependency_impacts=array();
		if(class_exists('MAD4B_SCP_Dependency_Impact_Graph')&&method_exists('MAD4B_SCP_Dependency_Impact_Graph','inspect')){
			foreach(array_slice($providers,0,32) as $provider){
				$impact=MAD4B_SCP_Dependency_Impact_Graph::inspect(array('provider_id'=>$provider));
				if(is_array($impact)) $dependency_impacts[]=self::safe_row($impact);
			}
			foreach(array_slice($plugins,0,32) as $plugin){
				$impact=MAD4B_SCP_Dependency_Impact_Graph::inspect(array('plugin'=>$plugin));
				if(is_array($impact)) $dependency_impacts[]=self::safe_row($impact);
			}
		}
		return array(
			'affected_abilities'=>$abilities,
			'affected_operations'=>$operations,
			'affected_workflows'=>$workflows,
			'dependency_impacts'=>array_slice($dependency_impacts,0,64),
		);
	}

	private static function kind_prefix( $kind ) {
		$map=array(
			'providers'=>'provider','components'=>'component','abilities'=>'ability','schemas'=>'schema','operations'=>'operation',
			'plugins'=>'plugin','rest_routes'=>'rest','post_types'=>'post_type','taxonomies'=>'taxonomy','meta_keys'=>'meta',
			'hooks'=>'hook','cron_hooks'=>'cron','admin_routes'=>'admin_route','mcp_descriptors'=>'mcp','symbols'=>'symbol','database_tables'=>'table',
		);
		return isset($map[$kind])?$map[$kind]:'';
	}

	private static function abilities() {
		$names=array();
		if(function_exists('wp_get_abilities')){
			$registered=wp_get_abilities();
			if(is_array($registered)) $names=array_merge($names,array_keys($registered));
		}
		if(class_exists('MAD4B_SCP_Servers')){
			foreach(array('mad4b-read','mad4b-chatgpt','mad4b-content','mad4b-write','mad4b-admin','mad4b-enrollment','mad4b-developer','mad4b-developer-breakglass','mad4b-breakglass') as $server){
				$names=array_merge($names,MAD4B_SCP_Servers::core_tools($server));
			}
			if(method_exists('MAD4B_SCP_Servers','chatgpt_full_catalog_candidates')) $names=array_merge($names,MAD4B_SCP_Servers::chatgpt_full_catalog_candidates());
		}
		if(class_exists('MAD4B_SCP_Adapter_Registry')){
			$registry=MAD4B_SCP_Adapter_Registry::instance();
			foreach(array('read','content','admin','write') as $surface) $names=array_merge($names,$registry->ability_names($surface));
		}
		$names=array_values(array_unique(array_filter(array_map('strval',$names))));
		sort($names,SORT_STRING); $out=array();
		foreach(array_slice($names,0,self::MAX_ITEMS_PER_KIND) as $name){
			if(!function_exists('wp_has_ability')||!wp_has_ability($name)) continue;
			$descriptor=class_exists('MAD4B_SCP_Capability_Descriptor_Registry')?MAD4B_SCP_Capability_Descriptor_Registry::describe($name):null;
			if(is_wp_error($descriptor)||!is_array($descriptor)) continue;
			$ability=function_exists('wp_get_ability')?wp_get_ability($name):null;
			$meta=is_object($ability)&&method_exists($ability,'get_meta')?$ability->get_meta():array();
			$meta=is_array($meta)?$meta:array();
			$annotations=isset($meta['annotations'])&&is_array($meta['annotations'])?$meta['annotations']:array();
			$schema=is_object($ability)&&method_exists($ability,'get_input_schema')?$ability->get_input_schema():array();
			$output_schema=is_object($ability)&&method_exists($ability,'get_output_schema')?$ability->get_output_schema():array();
			$schema_class=class_exists('MAD4B_SCP_Structural_Redaction')?MAD4B_SCP_Structural_Redaction::classify($schema,'ability_input_schema'):array('classification'=>'unknown','stats'=>array());
			$output_schema_class=class_exists('MAD4B_SCP_Structural_Redaction')?MAD4B_SCP_Structural_Redaction::classify($output_schema,'ability_output_schema'):array('classification'=>'unknown','stats'=>array());
			$output_schema_sha256=self::digest('mad4b.ability-output-schema.v1',is_array($output_schema)?self::safe_row($output_schema):$output_schema);
			$parts=explode('/',$name,2);
			$mcp_meta=isset($meta['mcp'])&&is_array($meta['mcp'])?$meta['mcp']:array();
			$resource_constraints_sha256='';
			if(isset($mcp_meta['resource_constraints'])&&is_array($mcp_meta['resource_constraints'])){
				$resource_constraints_sha256=self::digest('mad4b.runtime-resource-constraints.v1',self::safe_row($mcp_meta['resource_constraints']));
			}
			$out[]=array(
				'id'=>$name,
				'kind'=>'ability',
				'namespace'=>isset($parts[0])?$parts[0]:'',
				'action'=>isset($parts[1])?$parts[1]:'',
				'category'=>isset($descriptor['category'])?(string)$descriptor['category']:'',
				'required_capability'=>isset($mcp_meta['required_capability'])?sanitize_key((string)$mcp_meta['required_capability']):'',
				'data_classification'=>isset($mcp_meta['data_classification'])?sanitize_key((string)$mcp_meta['data_classification']):'',
				'resource_schema_version'=>isset($mcp_meta['resource_schema_version'])?substr((string)$mcp_meta['resource_schema_version'],0,64):'',
				'resource_constraints_sha256'=>$resource_constraints_sha256,
				'resource_values_exposed'=>false,
				'privilege_inferred_from_resources'=>false,
				'input_schema_sha256'=>isset($descriptor['input_schema_sha256'])?(string)$descriptor['input_schema_sha256']:'',
				'output_schema_sha256'=>$output_schema_sha256,
				'classification_sha256'=>isset($descriptor['classification_sha256'])?(string)$descriptor['classification_sha256']:'',
				'descriptor_generation_sha256'=>isset($descriptor['descriptor_sha256'])?(string)$descriptor['descriptor_sha256']:'',
				'readonly'=>!empty($descriptor['readonly']),
				'readonly_declared'=>!empty($descriptor['readonly_declared']),
				'execution_lane'=>isset($descriptor['execution_lane'])?(string)$descriptor['execution_lane']:'',
				'execution_provider'=>isset($descriptor['execution_provider'])?(string)$descriptor['execution_provider']:'',
				'execution_eligible'=>!empty($descriptor['execution_eligible']),
				'execution_boundary_verified'=>!empty($descriptor['execution_boundary_verified']),
				'breakglass'=>!empty($descriptor['breakglass']),
				'reversible_contract'=>isset($meta['mcp']['mad4b_reversible_contract'])?(string)$meta['mcp']['mad4b_reversible_contract']:'',
				'annotations'=>array(
					'readonly'=>isset($annotations['readonly'])&&is_bool($annotations['readonly'])?$annotations['readonly']:null,
					'destructive'=>isset($annotations['destructive'])&&is_bool($annotations['destructive'])?$annotations['destructive']:null,
					'idempotent'=>isset($annotations['idempotent'])&&is_bool($annotations['idempotent'])?$annotations['idempotent']:null,
				),
				'schema_evidence_classification'=>isset($schema_class['classification'])?(string)$schema_class['classification']:'unknown',
				'schema_secret_bearing'=>isset($schema_class['stats']['redacted']) && (int)$schema_class['stats']['redacted']>0,
				'output_schema_evidence_classification'=>isset($output_schema_class['classification'])?(string)$output_schema_class['classification']:'unknown',
				'output_schema_secret_bearing'=>isset($output_schema_class['stats']['redacted']) && (int)$output_schema_class['stats']['redacted']>0,
				'effect_class'=>(!empty($descriptor['readonly'])&&'read'===(isset($descriptor['execution_lane'])?$descriptor['execution_lane']:''))?'declared_read_only':'mutation_or_unknown',
				'mcp_surface'=>isset($meta['mcp']['surface'])?sanitize_key((string)$meta['mcp']['surface']):'',
				'mcp_public'=>!empty($meta['mcp']['public']),
				'preconditions'=>array(
					'execution_eligible'=>!empty($descriptor['execution_eligible']),
					'execution_boundary_verified'=>!empty($descriptor['execution_boundary_verified']),
					'provider_bound'=>!empty($descriptor['execution_provider']),
				),
				'effect'=>array(
					'class'=>(!empty($descriptor['readonly'])&&'read'===(isset($descriptor['execution_lane'])?$descriptor['execution_lane']:''))?'declared_read_only':'mutation_or_unknown',
					'annotation_is_authority'=>false,
				),
				'reversal'=>array(
					'contract'=>isset($meta['mcp']['mad4b_reversible_contract'])?(string)$meta['mcp']['mad4b_reversible_contract']:'',
					'declared'=>!empty($meta['mcp']['mad4b_reversible_contract']),
				),
				'evidence'=>array(
					'input_schema_sha256'=>isset($descriptor['input_schema_sha256'])?(string)$descriptor['input_schema_sha256']:'',
					'output_schema_sha256'=>$output_schema_sha256,
					'classification_sha256'=>isset($descriptor['classification_sha256'])?(string)$descriptor['classification_sha256']:'',
					'descriptor_generation_sha256'=>isset($descriptor['descriptor_sha256'])?(string)$descriptor['descriptor_sha256']:'',
				),
				'provenance'=>array('descriptor_contract'=>isset($descriptor['descriptor_contract'])?(string)$descriptor['descriptor_contract']:'','generation_contract'=>isset($descriptor['generation_contract'])?(string)$descriptor['generation_contract']:''),
			);
		}
		return $out;
	}

	private static function schemas( array $abilities ) {
		$out = array();
		foreach ( $abilities as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) continue;
			$out[] = array(
				'id' => 'ability:' . (string) $row['id'] . ':input',
				'kind' => 'schema',
				'owner_kind' => 'ability',
				'owner_id' => (string) $row['id'],
				'direction' => 'input',
				'schema_sha256' => isset( $row['input_schema_sha256'] ) ? (string) $row['input_schema_sha256'] : '',
				'secret_bearing' => ! empty( $row['schema_secret_bearing'] ),
				'values_read' => false,
				'privilege_inferred' => false,
			);
			if ( count( $out ) >= self::MAX_ITEMS_PER_KIND ) break;
			$out[] = array(
				'id' => 'ability:' . (string) $row['id'] . ':output',
				'kind' => 'schema',
				'owner_kind' => 'ability',
				'owner_id' => (string) $row['id'],
				'direction' => 'output',
				'schema_sha256' => isset( $row['output_schema_sha256'] ) ? (string) $row['output_schema_sha256'] : '',
				'secret_bearing' => ! empty( $row['output_schema_secret_bearing'] ),
				'values_read' => false,
				'privilege_inferred' => false,
			);
			if ( count( $out ) >= self::MAX_ITEMS_PER_KIND ) break;
		}
		return $out;
	}

	private static function operations() {
		$status = class_exists( 'MAD4B_SCP_Operation_Registry' ) && method_exists( 'MAD4B_SCP_Operation_Registry', 'status' )
			? MAD4B_SCP_Operation_Registry::status()
			: array();
		if ( is_wp_error( $status ) || ! is_array( $status ) ) return array();

		$rows = isset( $status['operations'] ) && is_array( $status['operations'] )
			? $status['operations']
			: array();

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) continue;
			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( '' === $id ) continue;

			$refs = array();
			foreach ( array( 'planner', 'executor', 'planner_ability', 'executor_ability', 'plan_ability', 'apply_ability', 'ability_name' ) as $field ) {
				if ( empty( $row[ $field ] ) || ! is_string( $row[ $field ] ) || 'exact_executor_from_plan' === $row[ $field ] ) continue;
				$refs[] = $row[ $field ];
			}
			$refs = array_values( array_unique( $refs ) );
			sort( $refs, SORT_STRING );

			$bound = method_exists( 'MAD4B_SCP_Operation_Registry', 'operation' )
				? MAD4B_SCP_Operation_Registry::operation( $id )
				: array();
			if ( is_wp_error( $bound ) || ! is_array( $bound ) ) $bound = array();

			$safe = self::safe_row( $row );
			$out[] = array(
				'id' => $id,
				'kind' => 'operation',
				'ability_refs' => $refs,
				'pipeline_profile' => isset( $row['pipeline_profile'] ) ? sanitize_key( (string) $row['pipeline_profile'] ) : '',
				'required_runtime' => ! array_key_exists( 'required_runtime', $row ) || true === $row['required_runtime'],
				'preconditions' => array(
					'planner_registered' => isset( $row['planner_registered'] ) ? $row['planner_registered'] : null,
					'executor_registered' => isset( $row['executor_registered'] ) ? $row['executor_registered'] : null,
					'descriptor_binding_ready' => isset( $row['descriptor_binding_ready'] ) ? (bool) $row['descriptor_binding_ready'] : ( isset( $bound['descriptor_binding_ready'] ) ? (bool) $bound['descriptor_binding_ready'] : null ),
				),
				'effect' => array(
					'class' => 'governed_operation_pipeline',
					'authority_inferred' => false,
				),
				'reversal' => array(
					'declared' => ! empty( $row['reversible'] ) || ! empty( $row['reversal_contract'] ),
					'contract' => isset( $row['reversal_contract'] ) ? (string) $row['reversal_contract'] : '',
				),
				'evidence' => array(
					'descriptor_sha256' => self::digest( 'mad4b.operation-graph-node.v2', $safe ),
					'planner_descriptor_sha256' => isset( $bound['planner_descriptor_sha256'] ) ? (string) $bound['planner_descriptor_sha256'] : '',
					'executor_descriptor_sha256' => isset( $bound['executor_descriptor_sha256'] ) ? (string) $bound['executor_descriptor_sha256'] : '',
				),
				'descriptor_sha256' => self::digest( 'mad4b.operation-graph-node.v2', $safe ),
			);

			if ( count( $out ) >= self::MAX_ITEMS_PER_KIND ) break;
		}
		return $out;
	}

	private static function providers() {
		if ( ! class_exists( 'MAD4B_SCP_Provider_Contracts' ) || ! method_exists( 'MAD4B_SCP_Provider_Contracts', 'all' ) ) return array();

		$contracts = MAD4B_SCP_Provider_Contracts::all();
		if ( ! is_array( $contracts ) ) return array();
		ksort( $contracts, SORT_STRING );

		$out = array();
		foreach ( $contracts as $provider => $contract ) {
			if ( ! is_string( $provider ) || '' === $provider || ! is_array( $contract ) ) continue;

			$status = method_exists( 'MAD4B_SCP_Provider_Contracts', 'runtime_status' )
				? MAD4B_SCP_Provider_Contracts::runtime_status( $provider )
				: array();
			if ( ! is_array( $status ) ) $status = array();

			$component_refs = array();
			if ( ! empty( $contract['components'] ) && is_array( $contract['components'] ) ) {
				foreach ( array_keys( $contract['components'] ) as $component_key ) {
					$component_key = sanitize_key( (string) $component_key );
					if ( '' !== $component_key ) $component_refs[] = $provider . ':' . $component_key;
				}
			} else {
				$component_refs[] = $provider . ':primary';
			}
			sort( $component_refs, SORT_STRING );

			$out[] = array(
				'id' => $provider,
				'kind' => 'provider',
				'label' => isset( $status['label'] ) ? (string) $status['label'] : ( isset( $contract['label'] ) ? (string) $contract['label'] : $provider ),
				'runtime_status' => isset( $status['status'] ) ? sanitize_key( (string) $status['status'] ) : 'unknown',
				'runtime_contract_ok' => ! empty( $status['runtime_contract_ok'] ),
				'certified_version' => isset( $status['certified_version'] ) ? (string) $status['certified_version'] : ( isset( $contract['version'] ) ? (string) $contract['version'] : '' ),
				'installed_version' => isset( $status['installed_version'] ) ? (string) $status['installed_version'] : '',
				'contract_mode' => isset( $status['contract_mode'] ) ? sanitize_key( (string) $status['contract_mode'] ) : ( isset( $contract['contract_mode'] ) ? sanitize_key( (string) $contract['contract_mode'] ) : '' ),
				'certification_authority' => isset( $status['certification_authority'] ) ? sanitize_key( (string) $status['certification_authority'] ) : '',
				'component_refs' => $component_refs,
				'contract_sha256' => self::digest( 'mad4b.provider-contract-graph-node.v1', self::safe_row( $contract ) ),
				'authority_inferred' => false,
			);

			if ( count( $out ) >= self::MAX_ITEMS_PER_KIND ) break;
		}
		return $out;
	}

	private static function components() {
		if ( ! class_exists( 'MAD4B_SCP_Provider_Contracts' ) || ! method_exists( 'MAD4B_SCP_Provider_Contracts', 'all' ) ) return array();

		$contracts = MAD4B_SCP_Provider_Contracts::all();
		if ( ! is_array( $contracts ) ) return array();
		ksort( $contracts, SORT_STRING );

		$out = array();
		foreach ( $contracts as $provider => $contract ) {
			if ( ! is_string( $provider ) || '' === $provider || ! is_array( $contract ) ) continue;

			$components = ! empty( $contract['components'] ) && is_array( $contract['components'] )
				? $contract['components']
				: array( 'primary' => $contract );
			ksort( $components, SORT_STRING );

			foreach ( $components as $component_key => $component ) {
				if ( ! is_array( $component ) ) continue;
				$key = sanitize_key( (string) $component_key );
				if ( '' === $key ) continue;

				$out[] = array(
					'id' => $provider . ':' . $key,
					'kind' => 'component',
					'provider_ref' => $provider,
					'label' => isset( $component['label'] ) ? (string) $component['label'] : $key,
					'plugin_file' => isset( $component['plugin_file'] ) ? (string) $component['plugin_file'] : '',
					'certified_version' => isset( $component['version'] ) ? (string) $component['version'] : '',
					'archive_sha256' => isset( $component['archive_sha256'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', strtolower( (string) $component['archive_sha256'] ) )
						? strtolower( (string) $component['archive_sha256'] )
						: '',
					'contract_sha256' => self::digest( 'mad4b.provider-component-graph-node.v1', self::safe_row( $component ) ),
					'code_executed' => false,
					'authority_inferred' => false,
				);

				if ( count( $out ) >= self::MAX_ITEMS_PER_KIND ) break 2;
			}
		}
		return $out;
	}

	private static function plugins() {
		if(!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins=get_plugins(); if(!is_array($plugins)) $plugins=array(); ksort($plugins,SORT_STRING);
		$active=(array)get_option('active_plugins',array()); $out=array();
		foreach($plugins as $file=>$headers){
			$headers=is_array($headers)?$headers:array();
			$header=array('name'=>isset($headers['Name'])?(string)$headers['Name']:'','version'=>isset($headers['Version'])?(string)$headers['Version']:'','plugin_uri'=>isset($headers['PluginURI'])?(string)$headers['PluginURI']:'','author'=>isset($headers['Author'])?(string)$headers['Author']:'','license'=>isset($headers['License'])?(string)$headers['License']:'');
			$out[]=array('id'=>(string)$file,'kind'=>'plugin','active'=>in_array($file,$active,true),'header_sha256'=>self::digest('mad4b.plugin-header.v1',$header),'header'=>$header,'candidate_package'=>array('state'=>'descriptive_only','unknown_code_executed'=>false,'authority_effect'=>'none'));
			if(count($out)>=self::MAX_ITEMS_PER_KIND) break;
		}
		return $out;
	}

	private static function rest_routes() {
		if(!function_exists('did_action')||did_action('rest_api_init')<=0||!function_exists('rest_get_server')) return array();
		$routes=rest_get_server()->get_routes(); if(!is_array($routes)) return array(); ksort($routes,SORT_STRING); $out=array();
		foreach($routes as $route=>$endpoints){
			$methods=array();
			foreach(is_array($endpoints)?$endpoints:array() as $endpoint){
				if(!is_array($endpoint)) continue;
				$m=isset($endpoint['methods'])?$endpoint['methods']:array();
				if(is_string($m)) $m=array($m);
				if(is_array($m)) foreach($m as $method=>$enabled) $methods[]=is_int($method)?strtoupper((string)$enabled):strtoupper((string)$method);
			}
			$methods=array_values(array_unique(array_filter($methods))); sort($methods,SORT_STRING);
			$out[]=array('id'=>(string)$route,'kind'=>'rest_route','methods'=>$methods,'callbacks_executed'=>false,'authority_inferred_from_method'=>false);
			if(count($out)>=self::MAX_ITEMS_PER_KIND) break;
		}
		return $out;
	}

	private static function post_types() {
		global $wp_post_types; $out=array();
		foreach(is_array($wp_post_types)?$wp_post_types:array() as $name=>$object){
			$out[]=array('id'=>(string)$name,'kind'=>'post_type','public'=>is_object($object)?(bool)$object->public:false,'show_ui'=>is_object($object)?(bool)$object->show_ui:false);
			if(count($out)>=self::MAX_ITEMS_PER_KIND) break;
		}
		return $out;
	}

	private static function taxonomies() {
		global $wp_taxonomies; $out=array();
		foreach(is_array($wp_taxonomies)?$wp_taxonomies:array() as $name=>$object){
			$types=is_object($object)&&isset($object->object_type)&&is_array($object->object_type)?array_values(array_map('strval',$object->object_type)):array();
			sort($types,SORT_STRING);
			$out[]=array('id'=>(string)$name,'kind'=>'taxonomy','object_types'=>$types,'public'=>is_object($object)?(bool)$object->public:false,'show_ui'=>is_object($object)?(bool)$object->show_ui:false);
			if(count($out)>=self::MAX_ITEMS_PER_KIND) break;
		}
		return $out;
	}

	private static function meta_keys() {
		if(!function_exists('get_registered_meta_keys')) return array();
		$out=array();
		foreach(array('post','term','user','comment') as $object_type){
			$rows=get_registered_meta_keys($object_type);
			if(!is_array($rows)) continue;
			ksort($rows,SORT_STRING);
			foreach($rows as $key=>$args){
				$sensitive=class_exists('MAD4B_SCP_Structural_Redaction')&&MAD4B_SCP_Structural_Redaction::sensitive_key($key);
				$out[]=array('id'=>$object_type.':'.($sensitive?hash('sha256',(string)$key):(string)$key),'kind'=>'meta_key','object_type'=>$object_type,'key_redacted'=>$sensitive,'type'=>is_array($args)&&isset($args['type'])?(string)$args['type']:'','single'=>is_array($args)&&isset($args['single'])?(bool)$args['single']:null);
				if(count($out)>=self::MAX_ITEMS_PER_KIND) break 2;
			}
		}
		return $out;
	}

	private static function hooks() {
		global $wp_filter;
		if(!is_array($wp_filter)) return array();
		$names=array_keys($wp_filter); sort($names,SORT_STRING); $out=array();
		foreach($names as $name){
			$hook=$wp_filter[$name];
			$callbacks=is_object($hook)&&isset($hook->callbacks)&&is_array($hook->callbacks)?$hook->callbacks:(is_array($hook)?$hook:array());
			$priorities=0; $callback_count=0;
			foreach($callbacks as $rows){
				$priorities++;
				if(is_array($rows)) $callback_count+=count($rows);
			}
			$sensitive=class_exists('MAD4B_SCP_Structural_Redaction')&&MAD4B_SCP_Structural_Redaction::sensitive_key($name);
			$out[]=array(
				'id'=>$sensitive?'hook:'.hash('sha256',(string)$name):'hook:'.(string)$name,
				'kind'=>'hook',
				'name_redacted'=>$sensitive,
				'priority_count'=>$priorities,
				'callback_count'=>$callback_count,
				'callbacks_invoked'=>false,
				'callback_identities_exposed'=>false,
			);
			if(count($out)>=self::MAX_ITEMS_PER_KIND) break;
		}
		return $out;
	}

	private static function cron_hooks() {
		if(!function_exists('_get_cron_array')) return array();
		$cron=_get_cron_array(); if(!is_array($cron)) return array();
		$hooks=array();
		foreach($cron as $timestamp=>$entries){
			if(!is_array($entries)) continue;
			foreach($entries as $hook=>$instances){
				if(!isset($hooks[$hook])) $hooks[$hook]=array('instance_count'=>0,'next_timestamp'=>(int)$timestamp);
				$hooks[$hook]['instance_count']+=is_array($instances)?count($instances):0;
				$hooks[$hook]['next_timestamp']=min($hooks[$hook]['next_timestamp'],(int)$timestamp);
			}
		}
		ksort($hooks,SORT_STRING); $out=array();
		foreach($hooks as $hook=>$row){
			$sensitive=class_exists('MAD4B_SCP_Structural_Redaction')&&MAD4B_SCP_Structural_Redaction::sensitive_key($hook);
			$out[]=array(
				'id'=>$sensitive?'cron:'.hash('sha256',(string)$hook):'cron:'.(string)$hook,
				'kind'=>'cron_hook',
				'name_redacted'=>$sensitive,
				'instance_count'=>(int)$row['instance_count'],
				'next_timestamp'=>(int)$row['next_timestamp'],
				'arguments_read'=>false,
			);
			if(count($out)>=self::MAX_ITEMS_PER_KIND) break;
		}
		return $out;
	}

	private static function admin_routes() {
		$rows=class_exists('MAD4B_SCP_Admin_Route_Registry')?MAD4B_SCP_Admin_Route_Registry::routes():array();
		$out=array();
		foreach(is_array($rows)?$rows:array() as $slug=>$row){
			if(!is_array($row)) continue;
			$id=is_string($slug)?$slug:(isset($row['slug'])?(string)$row['slug']:'');
			if(''===$id) continue;
			$out[]=array('id'=>$id,'kind'=>'admin_route','required_capability'=>isset($row['required_capability'])?(string)$row['required_capability']:'');
		}
		return array_slice($out,0,self::MAX_ITEMS_PER_KIND);
	}

	private static function mcp_descriptors( array $abilities ) {
		$out=array();
		foreach($abilities as $row){
			if(!is_array($row)||empty($row['id'])||empty($row['mcp_surface'])) continue;
			$out[]=array(
				'id'=>'mcp:'.(string)$row['id'],
				'kind'=>'mcp_descriptor',
				'ability_name'=>(string)$row['id'],
				'surface'=>(string)$row['mcp_surface'],
				'public'=>!empty($row['mcp_public']),
				'descriptor_generation_sha256'=>isset($row['descriptor_generation_sha256'])?(string)$row['descriptor_generation_sha256']:'',
				'authority_created'=>false,
			);
			if(count($out)>=self::MAX_ITEMS_PER_KIND) break;
		}
		return $out;
	}

	private static function edges( array $nodes ) {
		$edges=array();
		$ability_ids=array(); $provider_ids=array(); $component_ids=array();
		foreach(isset($nodes['abilities'])&&is_array($nodes['abilities'])?$nodes['abilities']:array() as $row) if(is_array($row)&&!empty($row['id'])) $ability_ids[(string)$row['id']]=true;
		foreach(isset($nodes['providers'])&&is_array($nodes['providers'])?$nodes['providers']:array() as $row) if(is_array($row)&&!empty($row['id'])) $provider_ids[(string)$row['id']]=true;
		foreach(isset($nodes['components'])&&is_array($nodes['components'])?$nodes['components']:array() as $row) if(is_array($row)&&!empty($row['id'])) $component_ids[(string)$row['id']]=true;
		foreach(isset($nodes['schemas'])&&is_array($nodes['schemas'])?$nodes['schemas']:array() as $row){
			if(!is_array($row)||empty($row['id'])||empty($row['owner_id'])) continue;
			$ability=(string)$row['owner_id'];
			if(isset($ability_ids[$ability])) $edges[]=array('from'=>'ability:'.$ability,'to'=>'schema:'.(string)$row['id'],'relation'=>'declares_schema');
		}
		foreach(isset($nodes['providers'])&&is_array($nodes['providers'])?$nodes['providers']:array() as $row){
			if(!is_array($row)||empty($row['id'])) continue;
			foreach(isset($row['component_refs'])&&is_array($row['component_refs'])?$row['component_refs']:array() as $component){
				if(isset($component_ids[$component])) $edges[]=array('from'=>'provider:'.(string)$row['id'],'to'=>'component:'.(string)$component,'relation'=>'contains_component');
			}
		}
		foreach(isset($nodes['abilities'])&&is_array($nodes['abilities'])?$nodes['abilities']:array() as $row){
			if(!is_array($row)||empty($row['id'])||empty($row['execution_provider'])) continue;
			$provider=(string)$row['execution_provider'];
			if(isset($provider_ids[$provider])) $edges[]=array('from'=>'ability:'.(string)$row['id'],'to'=>'provider:'.$provider,'relation'=>'bound_to_provider');
		}
		foreach(isset($nodes['operations'])&&is_array($nodes['operations'])?$nodes['operations']:array() as $row){
			if(!is_array($row)||empty($row['id'])) continue;
			foreach(isset($row['ability_refs'])&&is_array($row['ability_refs'])?$row['ability_refs']:array() as $ability){
				if(isset($ability_ids[$ability])) $edges[]=array('from'=>'operation:'.(string)$row['id'],'to'=>'ability:'.(string)$ability,'relation'=>'uses_ability');
			}
		}
		foreach(isset($nodes['mcp_descriptors'])&&is_array($nodes['mcp_descriptors'])?$nodes['mcp_descriptors']:array() as $row){
			if(is_array($row)&&!empty($row['ability_name'])&&isset($ability_ids[(string)$row['ability_name']])) {
				$edges[]=array('from'=>(string)$row['id'],'to'=>'ability:'.(string)$row['ability_name'],'relation'=>'describes_ability');
			}
		}
		usort($edges,static function($a,$b){return strcmp($a['from']."\0".$a['to']."\0".$a['relation'],$b['from']."\0".$b['to']."\0".$b['relation']);});
		return array_slice($edges,0,self::MAX_EDGES);
	}

	private static function symbols() {
		$names=array();
		foreach(get_declared_classes() as $name) if(0===strpos($name,'MAD4B_')) $names[]='class:'.$name;
		$functions=get_defined_functions();
		foreach(isset($functions['user'])&&is_array($functions['user'])?$functions['user']:array() as $name) if(0===strpos($name,'mad4b_')) $names[]='function:'.$name;
		$names=array_values(array_unique($names)); sort($names,SORT_STRING); $out=array();
		foreach(array_slice($names,0,self::MAX_SYMBOLS) as $name) $out[]=array('id'=>$name,'kind'=>'symbol','invoked'=>false);
		return $out;
	}

	private static function database_tables() {
		global $wpdb; $names=array();
		if(is_object($wpdb)&&method_exists($wpdb,'tables')) $names=array_merge($names,(array)$wpdb->tables('all'));
		if(class_exists('MAD4B_SCP_Schema')) $names=array_merge($names,array_values((array)MAD4B_SCP_Schema::tables()));
		$names=array_values(array_unique(array_filter(array_map('strval',$names)))); sort($names,SORT_STRING); $out=array();
		foreach(array_slice($names,0,self::MAX_ITEMS_PER_KIND) as $name) $out[]=array('id'=>$name,'kind'=>'database_table','schema_read'=>false,'row_values_read'=>false);
		return $out;
	}

	private static function index_rows( array $rows ) {
		$out=array();
		foreach($rows as $row) if(is_array($row)&&isset($row['id'])&&is_string($row['id'])&&''!==$row['id']) $out[$row['id']]=$row;
		ksort($out,SORT_STRING); return $out;
	}

	private static function safe_row( array $row ) {
		if(class_exists('MAD4B_SCP_Structural_Redaction')) return MAD4B_SCP_Structural_Redaction::redact($row,'runtime_evidence');
		return $row;
	}

	private static function digest( $contract, $value ) {
		if(class_exists('MAD4B_SCP_Ability_Contract_Inspector')){
			$d=MAD4B_SCP_Ability_Contract_Inspector::digest($contract,$value);
			if(!is_wp_error($d)) return $d;
		}
		return hash('sha256',wp_json_encode(self::sort_value($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
	}

	private static function sort_value( $value ) {
		if(!is_array($value)) return $value;
		$is_list=array_keys($value)===range(0,count($value)-1);
		if(!$is_list) ksort($value,SORT_STRING);
		foreach($value as $k=>$v) $value[$k]=self::sort_value($v);
		return $value;
	}
}

MAD4B_SCP_Runtime_Evidence_Graph::boot();
