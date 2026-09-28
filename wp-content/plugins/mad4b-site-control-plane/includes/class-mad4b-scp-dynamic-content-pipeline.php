<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Configurable pipeline registry for dynamic content orchestration.
 *
 * Settings may enable, disable, order and parameterize registered stages, but
 * never provide executable callbacks. Trusted code registers callbacks through
 * filters; persisted settings only select among those registered stages.
 */
final class MAD4B_SCP_Dynamic_Content_Pipeline {
	const CONTRACT='mad4b.dynamic-content-pipeline.v1';
	const OPTION='mad4b_scp_dynamic_content_pipeline_v1';
	const MAX_STAGES=64;
	const MAX_ITERATIONS=5;
	const MAX_FINDINGS_PER_STAGE=500;
	const MAX_STAGE_ELAPSED_MS=30000;
	const LOCK_OPTION='mad4b_scp_dynamic_content_pipeline_lock_v1';
	const LOCK_TTL=30;

	public static function defaults(){
		return array(
			'contract'=>self::CONTRACT,
			'revision'=>0,
			'updated_at'=>'',
			'max_iterations'=>3,
			'stop_on_no_progress'=>true,
			'no_progress_limit'=>1,
			'default_repair_mode'=>'safe_only',
			'stages'=>array(
				array('id'=>'structural','enabled'=>true,'order'=>100,'phase'=>'validate','conditions'=>array(),'policy'=>array('severity'=>'error','required'=>true,'on_error'=>'stop')),
				array('id'=>'source_fidelity','enabled'=>false,'order'=>140,'phase'=>'validate','conditions'=>array(),'policy'=>array('required'=>false,'on_error'=>'finding')),
				array('id'=>'site_policy','enabled'=>true,'order'=>200,'phase'=>'validate','conditions'=>array(),'policy'=>array('severity'=>'error','required'=>false,'on_error'=>'finding')),
				array('id'=>'seo_validation','enabled'=>false,'order'=>220,'phase'=>'validate','conditions'=>array(),'policy'=>array('required'=>false,'on_error'=>'finding')),
				array('id'=>'frontend_validation','enabled'=>false,'order'=>240,'phase'=>'validate','conditions'=>array(),'policy'=>array('required'=>false,'on_error'=>'finding')),
				array('id'=>'safe_repair','enabled'=>true,'order'=>300,'phase'=>'repair','conditions'=>array(),'policy'=>array('max_repairs_per_iteration'=>200,'required'=>true,'on_error'=>'stop')),
				array('id'=>'acceptance','enabled'=>true,'order'=>400,'phase'=>'accept','conditions'=>array(),'policy'=>array('require_zero_findings'=>true,'required'=>true,'on_error'=>'stop')),
			),
		);
	}

	public static function raw(){ $v=get_option(self::OPTION,array()); return is_array($v)?$v:array(); }

	public static function effective(){
		$base=self::defaults(); $raw=self::raw();
		$base['revision']=isset($raw['revision'])?max(0,absint($raw['revision'])):0;
		$base['updated_at']=isset($raw['updated_at'])?sanitize_text_field((string)$raw['updated_at']):'';
		$base['max_iterations']=isset($raw['max_iterations'])?max(1,min(self::MAX_ITERATIONS,absint($raw['max_iterations']))):$base['max_iterations'];
		$base['stop_on_no_progress']=isset($raw['stop_on_no_progress'])?(bool)$raw['stop_on_no_progress']:$base['stop_on_no_progress'];
		$base['no_progress_limit']=isset($raw['no_progress_limit'])?max(1,min(3,absint($raw['no_progress_limit']))):$base['no_progress_limit'];
		$base['default_repair_mode']=isset($raw['default_repair_mode'])&&in_array($raw['default_repair_mode'],array('safe_only','off'),true)?$raw['default_repair_mode']:$base['default_repair_mode'];
		if(isset($raw['stages'])&&is_array($raw['stages'])) $base['stages']=self::normalize_stage_config($raw['stages']);
		$base['registry']=self::registry_summary();
		$base['condition_registry']=self::condition_registry_summary();
		$base['settings_sha256']=self::config_digest($base);
		return $base;
	}

	private static function config_digest(array $config){
		$digest_basis=array(
			'max_iterations'=>isset($config['max_iterations'])?(int)$config['max_iterations']:0,
			'stop_on_no_progress'=>!empty($config['stop_on_no_progress']),
			'no_progress_limit'=>isset($config['no_progress_limit'])?(int)$config['no_progress_limit']:0,
			'default_repair_mode'=>isset($config['default_repair_mode'])?(string)$config['default_repair_mode']:'',
			'stages'=>isset($config['stages'])&&is_array($config['stages'])?$config['stages']:array(),
		);
		$json=wp_json_encode($digest_basis,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		return hash('sha256',false===$json?'null':$json);
	}

	private static function pinned_config_from_context(array $context){
		if(empty($context['pipeline_config'])||!is_array($context['pipeline_config'])) return null;
		$config=$context['pipeline_config'];
		if(!isset($config['contract'])||self::CONTRACT!==(string)$config['contract']) return null;
		$expected=isset($config['settings_sha256'])?strtolower((string)$config['settings_sha256']):'';
		if(1!==preg_match('/^[a-f0-9]{64}$/',$expected)) return null;
		return hash_equals($expected,self::config_digest($config))?$config:null;
	}

	private static function normalize_stage_config(array $stages){
		$out=array();
		foreach(array_slice($stages,0,self::MAX_STAGES) as $stage){
			if(!is_array($stage)) continue;
			$id=isset($stage['id'])?sanitize_key((string)$stage['id']):'';
			$phase=isset($stage['phase'])?sanitize_key((string)$stage['phase']):'';
			if($id===''||!in_array($phase,array('validate','repair','accept'),true)) continue;
			$out[]=array(
				'id'=>$id,
				'enabled'=>!isset($stage['enabled'])||!empty($stage['enabled']),
				'order'=>isset($stage['order'])?max(0,min(10000,absint($stage['order']))):100,
				'phase'=>$phase,
				'conditions'=>self::normalize_conditions(isset($stage['conditions'])&&is_array($stage['conditions'])?$stage['conditions']:array()),
				'depends_on'=>isset($stage['depends_on'])&&is_array($stage['depends_on'])?array_values(array_unique(array_filter(array_map('sanitize_key',array_slice($stage['depends_on'],0,self::MAX_STAGES))))):array(),
				'policy'=>isset($stage['policy'])&&is_array($stage['policy'])?self::sanitize_json($stage['policy'],0):array(),
			);
		}
		usort($out,static function($a,$b){$cmp=$a['order']<=>$b['order'];return $cmp!==0?$cmp:strcmp($a['id'],$b['id']);});
		return $out;
	}

	private static function validate_stage_graph(array $stages){
		$by_id=array();
		foreach($stages as $stage){
			$id=isset($stage['id'])?sanitize_key((string)$stage['id']):'';
			if($id==='') return new WP_Error('mad4b_dynamic_pipeline_stage_id_invalid','Every pipeline stage requires a valid id.');
			if(isset($by_id[$id])) return new WP_Error('mad4b_dynamic_pipeline_stage_duplicate','Pipeline stage ids must be unique.',array('stage_id'=>$id));
			$by_id[$id]=$stage;
		}

		$phase_rank=array('validate'=>10,'repair'=>20,'accept'=>30);
		foreach($by_id as $id=>$stage){
			$phase=isset($stage['phase'])?sanitize_key((string)$stage['phase']):'';
			$order=isset($stage['order'])?(int)$stage['order']:0;
			foreach(isset($stage['depends_on'])?(array)$stage['depends_on']:array() as $dep){
				$dep=sanitize_key((string)$dep);
				if($dep===$id) return new WP_Error('mad4b_dynamic_pipeline_dependency_self','A pipeline stage cannot depend on itself.',array('stage_id'=>$id));
				if(!isset($by_id[$dep])) return new WP_Error('mad4b_dynamic_pipeline_dependency_missing','A configured pipeline dependency does not exist.',array('stage_id'=>$id,'dependency'=>$dep));
				$dep_stage=$by_id[$dep];
				$dep_phase=isset($dep_stage['phase'])?sanitize_key((string)$dep_stage['phase']):'';
				$dep_order=isset($dep_stage['order'])?(int)$dep_stage['order']:0;
				$allowed=false;
				if($phase==='validate') $allowed=$dep_phase==='validate'&&($dep_order<$order||($dep_order===$order&&strcmp($dep,$id)<0));
				elseif($phase==='repair') $allowed=$dep_phase==='validate'||($dep_phase==='repair'&&($dep_order<$order||($dep_order===$order&&strcmp($dep,$id)<0)));
				elseif($phase==='accept') $allowed=$dep_phase==='validate'||($dep_phase==='accept'&&($dep_order<$order||($dep_order===$order&&strcmp($dep,$id)<0)));
				if(!$allowed) return new WP_Error('mad4b_dynamic_pipeline_dependency_forward','A pipeline dependency cannot point to a stage that executes later or in an unavailable phase context.',array('stage_id'=>$id,'stage_phase'=>$phase,'dependency'=>$dep,'dependency_phase'=>$dep_phase));
			}
		}

		$visiting=array();$visited=array();
		$walk=function($id) use (&$walk,&$visiting,&$visited,$by_id){
			if(isset($visited[$id])) return true;
			if(isset($visiting[$id])) return new WP_Error('mad4b_dynamic_pipeline_dependency_cycle','Pipeline stage dependencies contain a cycle.',array('stage_id'=>$id));
			$visiting[$id]=true;
			foreach(isset($by_id[$id]['depends_on'])?(array)$by_id[$id]['depends_on']:array() as $dep){
				$dep=sanitize_key((string)$dep);
				if(!isset($by_id[$dep])) continue;
				$r=$walk($dep); if(is_wp_error($r)) return $r;
			}
			unset($visiting[$id]);$visited[$id]=true;return true;
		};
		foreach(array_keys($by_id) as $id){$r=$walk($id);if(is_wp_error($r))return $r;}
		return true;
	}

	private static function normalize_conditions(array $c){
		$out=array();
		foreach($c as $key=>$value){
			$key=sanitize_key((string)$key);
			if($key==='') continue;
			$out[$key]=self::sanitize_json($value,0);
		}
		ksort($out,SORT_STRING);
		return $out;
	}

	public static function condition_registry(){
		$registry=array(
			'mode'=>array('callback'=>array(__CLASS__,'condition_scalar_in'),'description'=>'Run when bundle mode matches one configured value.'),
			'environment'=>array('callback'=>array(__CLASS__,'condition_scalar_in'),'description'=>'Run in selected WordPress environments.'),
			'post_type'=>array('callback'=>array(__CLASS__,'condition_scalar_in'),'description'=>'Run for selected runtime post types.'),
			'post_status'=>array('callback'=>array(__CLASS__,'condition_scalar_in'),'description'=>'Run for selected post statuses.'),
			'has_meta'=>array('callback'=>array(__CLASS__,'condition_has_meta'),'description'=>'Require configured meta keys in the desired bundle.'),
			'has_taxonomy'=>array('callback'=>array(__CLASS__,'condition_has_taxonomy'),'description'=>'Require configured taxonomies in the desired bundle.'),
			'finding_code'=>array('callback'=>array(__CLASS__,'condition_finding_code'),'description'=>'Run only when one configured finding code is present.'),
		);
		$registry=apply_filters('mad4b_scp_dynamic_content_condition_registry',$registry);
		$out=array();
		foreach(is_array($registry)?$registry:array() as $id=>$condition){
			$id=sanitize_key((string)$id);
			if($id===''||!is_array($condition)||empty($condition['callback'])||!is_callable($condition['callback'])) continue;
			$out[$id]=array('callback'=>$condition['callback'],'description'=>isset($condition['description'])?sanitize_text_field((string)$condition['description']):'');
		}
		return $out;
	}

	private static function sanitize_json($value,$depth){
		if($depth>6) return null;
		if(is_array($value)){ $out=array(); foreach(array_slice($value,0,200,true) as $k=>$v){$key=is_int($k)?$k:substr(sanitize_key((string)$k),0,64);$out[$key]=self::sanitize_json($v,$depth+1);} return $out; }
		if(is_bool($value)||is_int($value)||is_float($value)||is_null($value)) return $value;
		return substr(sanitize_text_field((string)$value),0,500);
	}

	public static function registry(){
		$registry=array(
			'structural'=>array('phase'=>'validate','callback'=>array(__CLASS__,'stage_structural'),'description'=>'Verify desired post fields, meta, taxonomies and featured media against exact readback.'),
			'source_fidelity'=>array('phase'=>'validate','callback'=>array(__CLASS__,'stage_source_fidelity'),'description'=>'Compare the authored bundle against source evidence through a trusted site/add-on validator.'),
			'site_policy'=>array('phase'=>'validate','callback'=>array(__CLASS__,'stage_site_policy'),'description'=>'Run site/provider validation hooks that can add bounded findings.'),
			'seo_validation'=>array('phase'=>'validate','callback'=>array(__CLASS__,'stage_seo_validation'),'description'=>'Run trusted SEO validators against the exact readback and desired bundle.'),
			'frontend_validation'=>array('phase'=>'validate','callback'=>array(__CLASS__,'stage_frontend_validation'),'description'=>'Run trusted frontend/rendering acceptance validators without hard-coding a theme or builder.'),
			'safe_repair'=>array('phase'=>'repair','callback'=>array(__CLASS__,'stage_safe_repair'),'description'=>'Re-apply only desired state already authorized by the exact content bundle.'),
			'acceptance'=>array('phase'=>'accept','callback'=>array(__CLASS__,'stage_acceptance'),'description'=>'Reduce final findings to a deterministic acceptance decision.'),
		);
		$registry=apply_filters('mad4b_scp_dynamic_content_pipeline_registry',$registry);
		$out=array();
		foreach(is_array($registry)?$registry:array() as $id=>$stage){
			$id=sanitize_key((string)$id); if($id===''||!is_array($stage)||empty($stage['callback'])||!is_callable($stage['callback'])) continue;
			$phase=isset($stage['phase'])?sanitize_key((string)$stage['phase']):'validate'; if(!in_array($phase,array('validate','repair','accept'),true)) continue;
			$out[$id]=array('phase'=>$phase,'callback'=>$stage['callback'],'description'=>isset($stage['description'])?sanitize_text_field((string)$stage['description']):'');
		}
		return $out;
	}

	public static function registry_summary(){
		$out=array();foreach(self::registry() as $id=>$s)$out[]=array('id'=>$id,'phase'=>$s['phase'],'description'=>$s['description']);return $out;
	}
	public static function condition_registry_summary(){
		$out=array();foreach(self::condition_registry() as $id=>$s)$out[]=array('id'=>$id,'description'=>$s['description']);return $out;
	}

	public static function stage_applies(array $stage,array $context){
		$c=isset($stage['conditions'])&&is_array($stage['conditions'])?$stage['conditions']:array();
		$registry=self::condition_registry();
		foreach($c as $id=>$config){
			$id=sanitize_key((string)$id);
			if(!isset($registry[$id])) return new WP_Error(
				'mad4b_dynamic_pipeline_condition_unavailable',
				'A configured pipeline condition is not registered in the current runtime.',
				array('stage_id'=>isset($stage['id'])?(string)$stage['id']:'','condition_id'=>$id)
			);
			if(!call_user_func($registry[$id]['callback'],$id,$config,$context,$stage)) return false;
		}
		return true;
	}

	public static function condition_scalar_in($id,$config,array $context,array $stage){
		$values=is_array($config)?$config:array($config);$values=array_values(array_unique(array_filter(array_map('sanitize_key',$values))));
		$actual=isset($context[$id])?sanitize_key((string)$context[$id]):'';return empty($values)||in_array($actual,$values,true);
	}
	public static function condition_has_meta($id,$config,array $context,array $stage){
		$values=is_array($config)?$config:array($config);
		$meta=isset($context['input']['meta'])?(array)$context['input']['meta']:array();
		$entries=isset($context['input']['meta_entries'])?(array)$context['input']['meta_entries']:array();
		foreach($values as $key){
			$key=(string)$key;
			if(!array_key_exists($key,$meta)&&!array_key_exists($key,$entries))return false;
		}
		return true;
	}
	public static function condition_has_taxonomy($id,$config,array $context,array $stage){
		$values=is_array($config)?$config:array($config);$tax=isset($context['input']['taxonomies'])?(array)$context['input']['taxonomies']:array();
		foreach($values as $key)if(!array_key_exists((string)$key,$tax))return false;return true;
	}
	public static function condition_finding_code($id,$config,array $context,array $stage){
		$values=is_array($config)?$config:array($config);$values=array_map('sanitize_key',$values);$codes=array();
		foreach(isset($context['findings'])?(array)$context['findings']:array() as $f)if(is_array($f)&&isset($f['code']))$codes[]=sanitize_key((string)$f['code']);
		return empty($values)||!empty(array_intersect($values,$codes));
	}

	private static function dependency_status(array $stage,array $results){
		$deps=isset($stage['depends_on'])&&is_array($stage['depends_on'])?$stage['depends_on']:array();
		if(empty($deps)) return array('ready'=>true,'missing'=>array(),'failed'=>array());
		$by_id=array();
		foreach($results as $result){
			if(!is_array($result)||empty($result['stage_id'])) continue;
			$by_id[sanitize_key((string)$result['stage_id'])]=$result;
		}
		$missing=array();$failed=array();
		foreach($deps as $dep){
			$dep=sanitize_key((string)$dep);
			if($dep===''||!isset($by_id[$dep])){$missing[]=$dep;continue;}
			$status=isset($by_id[$dep]['status'])?sanitize_key((string)$by_id[$dep]['status']):'';
			if(!in_array($status,array('ok','accepted','converged'),true))$failed[]=$dep;
		}
		return array('ready'=>empty($missing)&&empty($failed),'missing'=>array_values(array_filter($missing)),'failed'=>array_values(array_filter($failed)));
	}

	public static function run_phase($phase,array $context){
		$phase=sanitize_key((string)$phase);$registry=self::registry();$cfg=self::pinned_config_from_context($context);if(!is_array($cfg))$cfg=self::effective();$results=isset($context['stage_results'])&&is_array($context['stage_results'])?array_values($context['stage_results']):array();$current=$context;
		foreach($cfg['stages'] as $stage){
			if(empty($stage['enabled'])||$stage['phase']!==$phase) continue;
			if(!isset($registry[$stage['id']])||$registry[$stage['id']]['phase']!==$phase){
				if(!empty($stage['policy']['required'])) return new WP_Error('mad4b_dynamic_pipeline_required_stage_unavailable','A required configured pipeline stage is not registered.',array('stage_id'=>$stage['id'],'phase'=>$phase));
				continue;
			}
			$dependency=self::dependency_status($stage,$results);
			if(empty($dependency['ready'])){
				if(!empty($stage['policy']['required'])) return new WP_Error('mad4b_dynamic_pipeline_dependency_unsatisfied','A required pipeline stage dependency is missing or did not complete successfully.',array('stage_id'=>$stage['id'],'missing'=>$dependency['missing'],'failed'=>$dependency['failed']));
				$results[]=array('stage_id'=>$stage['id'],'phase'=>$phase,'status'=>'skipped_dependency_unsatisfied','missing_dependencies'=>$dependency['missing'],'failed_dependencies'=>$dependency['failed'],'elapsed_ms'=>0,'findings'=>count(isset($current['findings'])?(array)$current['findings']:array()));
				continue;
			}
			$applicable=self::stage_applies($stage,$current);
			if(is_wp_error($applicable)){
				if(!empty($stage['policy']['required'])) return $applicable;
				$results[]=array(
					'stage_id'=>$stage['id'],
					'phase'=>$phase,
					'status'=>'skipped_condition_unavailable',
					'condition_error_code'=>$applicable->get_error_code(),
					'elapsed_ms'=>0,
					'findings'=>count(isset($current['findings'])?(array)$current['findings']:array())
				);
				continue;
			}
			if(!$applicable) continue;
			$started=microtime(true);
			$result=call_user_func($registry[$stage['id']]['callback'],$current,$stage['policy'],$stage);
			$elapsed=max(0,(int)round((microtime(true)-$started)*1000));
			$max_elapsed=isset($stage['policy']['max_elapsed_ms'])?max(1,min(self::MAX_STAGE_ELAPSED_MS,absint($stage['policy']['max_elapsed_ms']))):self::MAX_STAGE_ELAPSED_MS;
			if($elapsed>$max_elapsed&&!is_wp_error($result)) $result=new WP_Error('mad4b_dynamic_pipeline_stage_time_budget_exceeded','Pipeline stage exceeded its configured soft time budget.',array('stage_id'=>$stage['id'],'elapsed_ms'=>$elapsed,'max_elapsed_ms'=>$max_elapsed));
			if(is_array($result)&&isset($result['findings'])&&is_array($result['findings'])){
				$max_findings=isset($stage['policy']['max_findings'])?max(0,min(self::MAX_FINDINGS_PER_STAGE,absint($stage['policy']['max_findings']))):self::MAX_FINDINGS_PER_STAGE;
				if(count($result['findings'])>$max_findings) $result=new WP_Error('mad4b_dynamic_pipeline_findings_budget_exceeded','Pipeline stage returned more findings than its configured budget.',array('stage_id'=>$stage['id'],'finding_count'=>count($result['findings']),'max_findings'=>$max_findings));
			}
			if(is_wp_error($result)){
				$on_error=isset($stage['policy']['on_error'])?sanitize_key((string)$stage['policy']['on_error']):'stop';
				if('repair'===$phase||!in_array($on_error,array('skip','finding'),true)) return $result;
				if('finding'===$on_error){
					$findings=isset($current['findings'])?(array)$current['findings']:array();
					$findings[]=array('code'=>'pipeline_stage_error','stage_id'=>$stage['id'],'error_code'=>sanitize_key((string)$result->get_error_code()),'repairable'=>false);
					$current['findings']=$findings;
				}
				$results[]=array('stage_id'=>$stage['id'],'phase'=>$phase,'status'=>$on_error==='skip'?'skipped_error':'finding_error','elapsed_ms'=>$elapsed,'findings'=>count(isset($current['findings'])?(array)$current['findings']:array()));
				continue;
			}
			if(is_array($result)){
				if(isset($result['findings'])&&is_array($result['findings']))$current['findings']=array_values($result['findings']);
				foreach($result as $k=>$v)if($k!=='findings')$current[$k]=$v;
			}
			$results[]=array('stage_id'=>$stage['id'],'phase'=>$phase,'status'=>'ok','elapsed_ms'=>$elapsed,'findings'=>count(isset($current['findings'])?(array)$current['findings']:array()));
		}
		$current['stage_results']=$results;return $current;
	}

	public static function stage_structural(array $context,array $policy,array $stage){
		$adapter=isset($context['adapter'])?$context['adapter']:null;if(!is_object($adapter)||!method_exists($adapter,'structural_findings'))return new WP_Error('mad4b_dynamic_pipeline_adapter_invalid','Structural stage requires dynamic content adapter.');
		$context['findings']=$adapter->structural_findings(isset($context['state'])?(array)$context['state']:array(),isset($context['input'])?(array)$context['input']:array(),isset($context['iteration'])?absint($context['iteration']):0);return $context;
	}
	private static function extension_findings_stage($filter,array $context,array $policy,array $stage){
		if(!has_filter($filter)){
			if(!empty($policy['required'])) return new WP_Error('mad4b_dynamic_pipeline_validator_unavailable','A required pipeline validator has no registered implementation.',array('stage_id'=>$stage['id'],'filter'=>$filter));
			return $context;
		}
		$extra=apply_filters($filter,array(),$context,$policy,$stage);
		if(is_wp_error($extra)) return $extra;
		if(!is_array($extra)) return new WP_Error('mad4b_dynamic_pipeline_validator_invalid','Pipeline validator must return an array of findings or WP_Error.',array('stage_id'=>$stage['id']));
		$findings=isset($context['findings'])?(array)$context['findings']:array();
		$context['findings']=array_values(array_merge($findings,$extra));
		return $context;
	}
	public static function stage_source_fidelity(array $context,array $policy,array $stage){ return self::extension_findings_stage('mad4b_scp_dynamic_content_source_fidelity_findings',$context,$policy,$stage); }
	public static function stage_seo_validation(array $context,array $policy,array $stage){ return self::extension_findings_stage('mad4b_scp_dynamic_content_seo_findings',$context,$policy,$stage); }
	public static function stage_frontend_validation(array $context,array $policy,array $stage){ return self::extension_findings_stage('mad4b_scp_dynamic_content_frontend_findings',$context,$policy,$stage); }

	public static function stage_site_policy(array $context,array $policy,array $stage){
		$findings=isset($context['findings'])?(array)$context['findings']:array();
		$extra=apply_filters('mad4b_scp_dynamic_content_policy_findings',array(),$context,$policy,$stage);
		if(is_wp_error($extra))return $extra;
		if(is_array($extra))$findings=array_merge($findings,$extra);$context['findings']=array_values($findings);return $context;
	}
	public static function stage_safe_repair(array $context,array $policy,array $stage){
		if(empty($context['findings'])||empty($context['repair_allowed'])) return $context;
		$repairable=array_values(array_filter((array)$context['findings'],static function($finding){
			return is_array($finding)&&!empty($finding['repairable']);
		}));
		if(empty($repairable)){
			$context['repair_performed']=false;
			$context['repair_skip_reason']='no_repairable_findings';
			return $context;
		}
		$max=isset($policy['max_repairs_per_iteration'])?max(1,min(500,absint($policy['max_repairs_per_iteration']))):200;
		if(count($repairable)>$max)return new WP_Error('mad4b_dynamic_pipeline_repair_budget_exceeded','Repairable finding count exceeds its configured per-iteration budget.',array('repairable_finding_count'=>count($repairable),'max_repairs_per_iteration'=>$max));
		$adapter=isset($context['adapter'])?$context['adapter']:null;
		if(!is_object($adapter)||!method_exists($adapter,'repair_desired_state'))return new WP_Error('mad4b_dynamic_pipeline_repair_unavailable','Safe repair stage requires repair-capable adapter.');
		$r=$adapter->repair_desired_state(absint($context['post_id']),(array)$context['input']);
		if(is_wp_error($r))return $r;
		$context['repair_performed']=true;
		$context['repairable_finding_count']=count($repairable);
		return $context;
	}
	public static function stage_acceptance(array $context,array $policy,array $stage){
		$context['accepted']=empty($context['findings']);$context['acceptance_status']=$context['accepted']?'accepted':'review_required';return $context;
	}

	private static function acquire_settings_lock(){
		$token=wp_generate_uuid4();
		$value=array('token'=>$token,'acquired_at'=>time());
		if(add_option(self::LOCK_OPTION,$value,'',false)) return $token;
		$existing=get_option(self::LOCK_OPTION,array());
		$acquired=is_array($existing)&&isset($existing['acquired_at'])?(int)$existing['acquired_at']:0;
		if($acquired>0&&$acquired<=(time()-self::LOCK_TTL)){
			delete_option(self::LOCK_OPTION);
			if(add_option(self::LOCK_OPTION,$value,'',false)) return $token;
		}
		return new WP_Error('mad4b_dynamic_pipeline_busy','Pipeline settings are being updated by another request. Reload and retry.');
	}

	private static function release_settings_lock($token){
		$current=get_option(self::LOCK_OPTION,array());
		if(is_array($current)&&isset($current['token'])&&is_string($token)&&hash_equals((string)$current['token'],$token)) delete_option(self::LOCK_OPTION);
	}

	public static function persist(array $input){
		if(!current_user_can('manage_options')) return new WP_Error('mad4b_dynamic_pipeline_admin_required','Administrator capability is required.');
		$lock=self::acquire_settings_lock();
		if(is_wp_error($lock)) return $lock;
		try{
			return self::persist_locked($input);
		} finally {
			self::release_settings_lock($lock);
		}
	}

	private static function persist_locked(array $input){
		$current_raw=self::raw();
		$current_revision=isset($current_raw['revision'])?max(0,absint($current_raw['revision'])):0;
		if(!array_key_exists('expected_revision',$input)) return new WP_Error(
			'mad4b_dynamic_pipeline_expected_revision_required',
			'Pipeline update requires expected_revision from the latest persisted settings.'
		);
		$expected_revision=max(0,absint($input['expected_revision']));
		if($expected_revision!==$current_revision) return new WP_Error(
			'mad4b_dynamic_pipeline_stale',
			'Pipeline settings changed after they were read. Reload before saving.',
			array('expected_revision'=>$expected_revision,'current_revision'=>$current_revision)
		);

		$normalized=self::effective();
		if(isset($input['max_iterations'])) $normalized['max_iterations']=max(1,min(self::MAX_ITERATIONS,absint($input['max_iterations'])));
		if(isset($input['stop_on_no_progress'])) $normalized['stop_on_no_progress']=(bool)$input['stop_on_no_progress'];
		if(isset($input['no_progress_limit'])) $normalized['no_progress_limit']=max(1,min(3,absint($input['no_progress_limit'])));
		if(isset($input['default_repair_mode'])&&in_array($input['default_repair_mode'],array('safe_only','off'),true)) $normalized['default_repair_mode']=$input['default_repair_mode'];
		if(isset($input['stages'])&&is_array($input['stages'])) $normalized['stages']=self::normalize_stage_config($input['stages']);
		$graph=self::validate_stage_graph(isset($normalized['stages'])&&is_array($normalized['stages'])?$normalized['stages']:array());
		if(is_wp_error($graph)) return $graph;

		$normalized['revision']=$current_revision+1;
		$normalized['updated_at']=gmdate('c');
		unset($normalized['registry'],$normalized['condition_registry'],$normalized['settings_sha256']);
		update_option(self::OPTION,$normalized,false);

		$read=get_option(self::OPTION,array());
		if(serialize($read)!==serialize($normalized)) return new WP_Error('mad4b_dynamic_pipeline_save_failed','Pipeline settings failed exact readback.');
		return self::effective();
	}

}
