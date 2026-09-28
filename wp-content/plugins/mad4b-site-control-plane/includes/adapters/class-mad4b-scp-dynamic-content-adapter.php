<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Dynamic, provider-neutral content orchestration.
 * Runtime discovery is the authority: no CPT/taxonomy/term names are hard-coded.
 */
final class MAD4B_SCP_Dynamic_Content_Adapter extends MAD4B_SCP_Adapter_Base {
	const CONTRACT='mad4b.dynamic-content-orchestration.v1';
	const MODEL='mad4b/content-model-discover';
	const READBACK='mad4b/content-bundle-readback';
	const PLAN='mad4b/content-orchestration-plan';
	const APPLY='mad4b/content-apply-bundle';
	const PIPELINE_STATUS='mad4b/content-pipeline-status';
	const PIPELINE_UPDATE='mad4b/content-pipeline-settings-update';
	const ROLLBACK='mad4b.rollback.dynamic-content-bundle.v1';
	const PIPELINE_ROLLBACK='mad4b.rollback.dynamic-content-pipeline-settings.v1';
	const BINDING='_mad4b_dynamic_content_binding';
	const MAX_ITERATIONS=5;
	const MUTATION_LOCK_PREFIX='mad4b_scp_dynamic_content_lock_';
	const MUTATION_LOCK_TTL=900;

	public function id(){ return 'dynamic-content'; }
	public function label(){ return 'Dynamic Content Orchestration'; }
	public function is_available(){ return function_exists('get_post_types') && function_exists('wp_insert_post') && function_exists('wp_set_object_terms'); }
	protected function certified_provider_key(){ return 'core'; }
	protected function mutation_requires_certification(){ return false; }
	protected function detect_plugin_version(){ return defined('MAD4B_SCP_VERSION') ? (string) MAD4B_SCP_VERSION : ''; }
	public function ability_names(){ return array('read'=>array(self::MODEL,self::READBACK,self::PLAN,self::PIPELINE_STATUS),'content'=>array(self::APPLY),'admin'=>array(self::PIPELINE_UPDATE),'write'=>array()); }
	public function reversible_contracts(){ return array(self::APPLY=>self::ROLLBACK,self::PIPELINE_UPDATE=>self::PIPELINE_ROLLBACK); }

	private function pipeline_settings_schema(){
		$json=$this->json_value_schema();
		$stage=array(
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>array(
				'id'=>array('type'=>'string','minLength'=>1,'maxLength'=>64,'pattern'=>'^[a-z0-9_-]+$'),
				'enabled'=>array('type'=>'boolean'),
				'order'=>array('type'=>'integer','minimum'=>0,'maximum'=>10000),
				'phase'=>array('type'=>'string','enum'=>array('validate','repair','accept')),
				'depends_on'=>array(
					'type'=>'array',
					'maxItems'=>64,
					'uniqueItems'=>true,
					'items'=>array('type'=>'string','minLength'=>1,'maxLength'=>64,'pattern'=>'^[a-z0-9_-]+$')
				),
				'conditions'=>array('type'=>'object','maxProperties'=>32,'additionalProperties'=>$json),
				'policy'=>array('type'=>'object','maxProperties'=>64,'additionalProperties'=>$json),
			),
			'required'=>array('id','phase'),
		);
		return $this->schema(array(
			'expected_revision'=>array('type'=>'integer','minimum'=>0),
			'max_iterations'=>array('type'=>'integer','minimum'=>1,'maximum'=>5),
			'stop_on_no_progress'=>array('type'=>'boolean'),
			'no_progress_limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>3),
			'default_repair_mode'=>array('type'=>'string','enum'=>array('safe_only','off')),
			'stages'=>array('type'=>'array','maxItems'=>64,'items'=>$stage),
		),array('expected_revision'));
	}

	public function register_abilities(){
		if(!wp_has_ability(self::PIPELINE_STATUS)) $this->add_ability(
			self::PIPELINE_STATUS,
			'Dynamic Content Pipeline Status',
			'pipeline_status',
			array('MAD4B_SCP_Policy','can_read'),
			null,
			'read',
			true,
			false,
			true
		);

		if(!wp_has_ability(self::PIPELINE_UPDATE)) $this->add_ability(
			self::PIPELINE_UPDATE,
			'Update Dynamic Content Pipeline Settings',
			'pipeline_update',
			array($this,'can_pipeline_update'),
			$this->pipeline_settings_schema(),
			'admin',
			false,
			true,
			false
		);

		if(!wp_has_ability(self::MODEL)) $this->add_ability(
			self::MODEL,
			'Discover Dynamic Content Model',
			'discover_model',
			array('MAD4B_SCP_Policy','can_read'),
			$this->schema(array(
				'post_type'=>array('type'=>'string','maxLength'=>64,'default'=>''),
				'taxonomy'=>array('type'=>'string','maxLength'=>64,'default'=>''),
				'include_terms'=>array('type'=>'boolean','default'=>false),
				'include_observed_meta'=>array('type'=>'boolean','default'=>false),
				'sample_size'=>array('type'=>'integer','minimum'=>1,'maximum'=>20,'default'=>5),
				'term_search'=>array('type'=>'string','maxLength'=>200,'default'=>''),
				'term_offset'=>array('type'=>'integer','minimum'=>0,'maximum'=>1000000,'default'=>0),
				'term_limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>200,'default'=>50)
			)),
			'read',
			true,
			false,
			true
		);

		if(!wp_has_ability(self::READBACK)) $this->add_ability(
			self::READBACK,
			'Read Dynamic Content Bundle',
			'readback',
			array('MAD4B_SCP_Policy','can_read'),
			$this->schema(array(
				'post_id'=>array('type'=>'integer','minimum'=>1),
				'meta_keys'=>array('type'=>'array','maxItems'=>200,'items'=>array('type'=>'string','minLength'=>1,'maxLength'=>191)),
				'taxonomies'=>array('type'=>'array','maxItems'=>50,'items'=>array('type'=>'string','minLength'=>1,'maxLength'=>64))
			),array('post_id')),
			'read',
			true,
			false,
			true
		);

		if(!wp_has_ability(self::PLAN)) $this->add_ability(
			self::PLAN,
			'Plan Dynamic Content Orchestration',
			'orchestration_plan',
			array('MAD4B_SCP_Policy','can_read'),
			$this->schema(array(
				'mode'=>array('type'=>'string','enum'=>array('create','update'),'default'=>'create'),
				'post_type'=>array('type'=>'string','minLength'=>1,'maxLength'=>64,'pattern'=>'^[a-zA-Z0-9_-]+$'),
				'post_id'=>array('type'=>'integer','minimum'=>1),
				'operation_key'=>array('type'=>'string','minLength'=>8,'maxLength'=>128,'pattern'=>'^[A-Za-z0-9._:-]+$'),
				'post'=>array(
					'type'=>'object',
					'additionalProperties'=>false,
					'properties'=>array(
						'post_title'=>array('type'=>'string','maxLength'=>1000),
						'post_content'=>array('type'=>'string','maxLength'=>2097152),
						'post_excerpt'=>array('type'=>'string','maxLength'=>262144),
						'post_status'=>array('type'=>'string','enum'=>array('draft','pending','private','publish')),
						'post_parent'=>array('type'=>'integer','minimum'=>0),
						'post_author'=>array('type'=>'integer','minimum'=>1)
					)
				),
				'meta'=>array('type'=>'object','maxProperties'=>200,'additionalProperties'=>$this->json_value_schema()),
				'meta_entries'=>array(
					'type'=>'object',
					'maxProperties'=>200,
					'additionalProperties'=>array(
						'type'=>'object',
						'additionalProperties'=>false,
						'properties'=>array(
							'mode'=>array('type'=>'string','enum'=>array('single','multi','delete')),
							'value'=>$this->json_value_schema(),
							'values'=>array('type'=>'array','minItems'=>1,'maxItems'=>100,'items'=>$this->json_value_schema())
						),
						'required'=>array('mode')
					)
				),
				'taxonomies'=>array(
					'type'=>'object',
					'maxProperties'=>50,
					'additionalProperties'=>array(
						'type'=>'array',
						'maxItems'=>100,
						'items'=>array(
							'type'=>'object',
							'additionalProperties'=>false,
							'properties'=>array(
								'term_id'=>array('type'=>'integer','minimum'=>1),
								'slug'=>array('type'=>'string','minLength'=>1,'maxLength'=>200),
								'name'=>array('type'=>'string','minLength'=>1,'maxLength'=>200),
								'description'=>array('type'=>'string','maxLength'=>65535),
								'parent'=>array('type'=>'integer','minimum'=>0)
							)
						)
					)
				),
				'featured_media_id'=>array('type'=>'integer','minimum'=>0),
				'evidence'=>array('type'=>'object','maxProperties'=>64,'additionalProperties'=>$this->json_value_schema()),
				'acceptance_targets'=>array('type'=>'object','maxProperties'=>64,'additionalProperties'=>$this->json_value_schema()),
				'validation'=>array(
					'type'=>'object',
					'additionalProperties'=>false,
					'properties'=>array(
						'max_iterations'=>array('type'=>'integer','minimum'=>1,'maximum'=>self::MAX_ITERATIONS),
						'repair_mode'=>array('type'=>'string','enum'=>array('safe_only','off'))
					)
				)
			),array('post_type','operation_key','post')),
			'read',
			true,
			false,
			true
		);

		if(!wp_has_ability(self::APPLY)){
			$term_ref=array(
				'type'=>'object',
				'additionalProperties'=>false,
				'properties'=>array(
					'term_id'=>array('type'=>'integer','minimum'=>1),
					'slug'=>array('type'=>'string','minLength'=>1,'maxLength'=>200),
					'name'=>array('type'=>'string','minLength'=>1,'maxLength'=>200)
				)
			);
			$this->add_ability(
				self::APPLY,
				'Apply Dynamic Content Bundle',
				'apply_bundle',
				array($this,'can_apply_bundle'),
				$this->schema(array(
					'mode'=>array('type'=>'string','enum'=>array('create','update'),'default'=>'create'),
					'post_type'=>array('type'=>'string','minLength'=>1,'maxLength'=>64,'pattern'=>'^[a-zA-Z0-9_-]+$'),
					'post_id'=>array('type'=>'integer','minimum'=>1),
					'operation_key'=>array('type'=>'string','minLength'=>8,'maxLength'=>128,'pattern'=>'^[A-Za-z0-9._:-]+$'),
					'expected_state_sha256'=>array('type'=>'string','pattern'=>'^[A-Fa-f0-9]{64}$'),
					'expected_pipeline_settings_sha256'=>array('type'=>'string','pattern'=>'^[A-Fa-f0-9]{64}$'),
					'expected_bundle_sha256'=>array('type'=>'string','pattern'=>'^[A-Fa-f0-9]{64}$'),
					'post'=>array(
						'type'=>'object',
						'additionalProperties'=>false,
						'properties'=>array(
							'post_title'=>array('type'=>'string','maxLength'=>1000),
							'post_content'=>array('type'=>'string','maxLength'=>2097152),
							'post_excerpt'=>array('type'=>'string','maxLength'=>262144),
							'post_status'=>array('type'=>'string','enum'=>array('draft','pending')),
							'post_parent'=>array('type'=>'integer','minimum'=>0),
							'post_author'=>array('type'=>'integer','minimum'=>1)
						)
					),
					'meta'=>array('type'=>'object','maxProperties'=>200,'additionalProperties'=>$this->json_value_schema()),
					'meta_entries'=>array(
						'type'=>'object',
						'maxProperties'=>200,
						'additionalProperties'=>array(
							'type'=>'object',
							'additionalProperties'=>false,
							'properties'=>array(
								'mode'=>array('type'=>'string','enum'=>array('single','multi','delete')),
								'value'=>$this->json_value_schema(),
								'values'=>array('type'=>'array','minItems'=>1,'maxItems'=>100,'items'=>$this->json_value_schema())
							),
							'required'=>array('mode')
						)
					),
					'taxonomies'=>array('type'=>'object','maxProperties'=>50,'additionalProperties'=>array('type'=>'array','maxItems'=>100,'items'=>$term_ref)),
					'featured_media_id'=>array('type'=>'integer','minimum'=>0),
					'evidence'=>array('type'=>'object','maxProperties'=>64,'additionalProperties'=>$this->json_value_schema()),
					'acceptance_targets'=>array('type'=>'object','maxProperties'=>64,'additionalProperties'=>$this->json_value_schema()),
					'validation'=>array(
						'type'=>'object',
						'additionalProperties'=>false,
						'properties'=>array(
							'max_iterations'=>array('type'=>'integer','minimum'=>1,'maximum'=>self::MAX_ITERATIONS,'default'=>self::MAX_ITERATIONS),
							'repair_mode'=>array('type'=>'string','enum'=>array('safe_only','off'),'default'=>'safe_only')
						)
					)
				),array('post_type','operation_key','post','expected_pipeline_settings_sha256','expected_bundle_sha256')),
				'content',
				false,
				true,
				false
			);
		}
	}


	public function orchestration_plan($input=array()){
		$input=is_array($input)?$input:array();
		$post_type=isset($input['post_type'])?sanitize_key((string)$input['post_type']):'';
		if($post_type===''||!post_type_exists($post_type)) return new WP_Error('mad4b_dynamic_post_type_missing','Requested post type is not registered.');
		$mode=isset($input['mode'])?sanitize_key((string)$input['mode']):'create';
		if(!in_array($mode,array('create','update'),true)) return new WP_Error('mad4b_dynamic_mode_invalid','Plan mode must be create or update.');
		$operation_key=isset($input['operation_key'])?trim((string)$input['operation_key']):'';
		if(1!==preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$operation_key)) return new WP_Error('mad4b_dynamic_operation_key_invalid','Stable operation_key is required for orchestration planning.');
		$post_input=isset($input['post'])&&is_array($input['post'])?$input['post']:array();

		$post_object=get_post_type_object($post_type);
		$default_eligible=$post_object&&(!empty($post_object->show_ui)||!empty($post_object->public)||!empty($post_object->show_in_rest));
		$policy_eligible=(bool)apply_filters('mad4b_scp_dynamic_content_post_type_mutation_eligible',$default_eligible,$post_type,$post_object,$input);
		$create_cap=$post_object&&isset($post_object->cap->create_posts)?(string)$post_object->cap->create_posts:($post_object&&isset($post_object->cap->edit_posts)?(string)$post_object->cap->edit_posts:'edit_posts');
		$edit_cap=$post_object&&isset($post_object->cap->edit_posts)?(string)$post_object->cap->edit_posts:'edit_posts';
		$required_cap='create'===$mode?$create_cap:$edit_cap;
		$capability_allowed=current_user_can($required_cap);
		$post_id=isset($input['post_id'])?absint($input['post_id']):0;
		$target_ready=true;
		$target_reason='';
		$target=null;
		$live_target=false;
		if('update'===$mode){
			$target=$post_id>0?get_post($post_id):null;
			$target_ready=$target&&$post_type===(string)$target->post_type&&current_user_can('edit_post',$post_id);
			if(!$target_ready) $target_reason='update_target_missing_mismatch_or_denied';
			$live_target=$target&&in_array((string)$target->post_status,array('publish','private'),true);
		}elseif($post_id>0){
			$target_ready=false;
			$target_reason='create_mode_post_id_not_allowed';
		}
		$status=isset($post_input['post_status'])?sanitize_key((string)$post_input['post_status']):'';
		$live_state_requested=in_array($status,array('publish','private'),true);
		$publish_cap=$live_state_requested&&$post_object&&isset($post_object->cap->publish_posts)?(string)$post_object->cap->publish_posts:'';
		$publish_ready=!$live_state_requested;
		$environment=function_exists('wp_get_environment_type')?sanitize_key((string)wp_get_environment_type()):'unknown';
		$environment_eligible=(bool)apply_filters('mad4b_scp_dynamic_content_environment_mutation_eligible',true,$environment,$mode,$post_type,$input);
		$post_type_readiness=array(
			'environment'=>$environment,
			'environment_policy_eligible'=>$environment_eligible,
			'mode'=>$mode,
			'post_id'=>$post_id,
			'policy_eligible'=>$policy_eligible,
			'required_capability'=>$required_cap,
			'capability_allowed'=>$capability_allowed,
			'target_ready'=>(bool)$target_ready,
			'target_reason'=>$target_reason,
			'publish_capability'=>$publish_cap,
			'publish_allowed'=>$publish_ready,
			'ready'=>$environment_eligible&&$policy_eligible&&$capability_allowed&&$target_ready&&$publish_ready&&!$live_target,
		);

		$steps=array();
		$resolved=array();
		$missing=array();
		$blockers=array();
		if(!$environment_eligible) $blockers[]=array('code'=>'environment_policy_ineligible','environment'=>$environment,'mode'=>$mode,'post_type'=>$post_type);
		if(!$policy_eligible) $blockers[]=array('code'=>'post_type_policy_ineligible','post_type'=>$post_type);
		if(!$capability_allowed) $blockers[]=array('code'=>'post_type_capability_denied','post_type'=>$post_type,'capability'=>$required_cap,'mode'=>$mode);
		if(!$target_ready) $blockers[]=array('code'=>'post_target_not_ready','post_type'=>$post_type,'post_id'=>$post_id,'reason'=>$target_reason);
		if($live_state_requested) $blockers[]=array('code'=>'separate_publication_required','post_type'=>$post_type,'post_status'=>$status,'required_next_ability'=>'mad4b/content-update-post');
		if($live_target) $blockers[]=array('code'=>'live_target_requires_draft_workflow','post_type'=>$post_type,'post_id'=>$post_id,'post_status'=>(string)$target->post_status);
		$taxonomy_readiness=array();
		$taxonomies=isset($input['taxonomies'])&&is_array($input['taxonomies'])?$input['taxonomies']:array();
		ksort($taxonomies,SORT_STRING);

		foreach($taxonomies as $taxonomy=>$refs){
			$taxonomy=sanitize_key((string)$taxonomy);
			$tax=get_taxonomy($taxonomy);
			if(!$tax||!in_array($post_type,(array)$tax->object_type,true)) return new WP_Error('mad4b_dynamic_taxonomy_not_attached','Taxonomy is not attached to requested post type.',array('taxonomy'=>$taxonomy));

			$assign_cap=isset($tax->cap->assign_terms)?(string)$tax->cap->assign_terms:'edit_posts';
			$policy_eligible=(bool)apply_filters('mad4b_scp_dynamic_content_taxonomy_mutation_eligible',true,$taxonomy,$tax,$post_type,$input);
			$assign_allowed=current_user_can($assign_cap);
			$taxonomy_readiness[$taxonomy]=array(
				'assign_capability'=>$assign_cap,
				'assign_allowed'=>$assign_allowed,
				'policy_eligible'=>$policy_eligible,
				'ready'=>$assign_allowed&&$policy_eligible,
			);
			if(!$assign_allowed) $blockers[]=array('code'=>'taxonomy_assign_denied','taxonomy'=>$taxonomy,'capability'=>$assign_cap);
			if(!$policy_eligible) $blockers[]=array('code'=>'taxonomy_policy_ineligible','taxonomy'=>$taxonomy);

			$resolved[$taxonomy]=array();
			foreach(is_array($refs)?$refs:array() as $ref){
				if(!is_array($ref)) return new WP_Error('mad4b_dynamic_term_ref_invalid','Term refs must be objects.');
				$identity_count=(!empty($ref['term_id'])?1:0)+(!empty($ref['slug'])?1:0)+(!empty($ref['name'])?1:0);
				if(1!==$identity_count) return new WP_Error('mad4b_dynamic_term_ref_ambiguous','Each planned term requires exactly one identity: term_id, slug or name.');
				$term=null;$identity=array();
				if(!empty($ref['term_id'])){$term=get_term(absint($ref['term_id']),$taxonomy);$identity=array('term_id'=>absint($ref['term_id']));}
				elseif(!empty($ref['slug'])){$slug=sanitize_title((string)$ref['slug']);$term=get_term_by('slug',$slug,$taxonomy);$identity=array('slug'=>$slug);}
				elseif(!empty($ref['name'])){
					$name=sanitize_text_field((string)$ref['name']);
					$args=array('taxonomy'=>$taxonomy,'hide_empty'=>false,'name'=>$name,'number'=>2,'orderby'=>'term_id','order'=>'ASC');
					if(isset($ref['parent'])) $args['parent']=absint($ref['parent']);
					$matches=get_terms($args);
					if(is_wp_error($matches)) return $matches;
					if(count($matches)>1) return new WP_Error('mad4b_dynamic_term_name_ambiguous','Term name resolves to multiple terms; use term_id or slug.',array('taxonomy'=>$taxonomy,'name'=>$name));
					$term=!empty($matches)?$matches[0]:null;
					$identity=array('name'=>$name);
					if(isset($ref['parent'])) $identity['parent']=absint($ref['parent']);
				}
				else return new WP_Error('mad4b_dynamic_term_ref_invalid','Each planned term requires term_id, slug or name.');

				if($term&&!is_wp_error($term)){
					$term_eligible=(bool)apply_filters('mad4b_scp_dynamic_content_term_reference_eligible',true,$term,$taxonomy,$ref);
					$resolved[$taxonomy][]=array(
						'term_id'=>(int)$term->term_id,
						'name'=>(string)$term->name,
						'slug'=>(string)$term->slug,
						'policy_eligible'=>$term_eligible,
					);
					if(!$term_eligible) $blockers[]=array('code'=>'term_policy_ineligible','taxonomy'=>$taxonomy,'term_id'=>(int)$term->term_id);
					continue;
				}

				$name=isset($ref['name'])?sanitize_text_field((string)$ref['name']):'';
				if($name==='') {
					$missing[]=array('taxonomy'=>$taxonomy,'identity'=>$identity,'creatable'=>false,'reason'=>'name_required_for_create');
					continue;
				}
				$parent=isset($ref['parent'])?absint($ref['parent']):0;
				if($parent>0){
					if(empty($tax->hierarchical)){
						$missing[]=array('taxonomy'=>$taxonomy,'identity'=>$identity,'creatable'=>false,'reason'=>'parent_not_supported');
						continue;
					}
					$parent_term=get_term($parent,$taxonomy);
					if(is_wp_error($parent_term)||!$parent_term){
						$missing[]=array('taxonomy'=>$taxonomy,'identity'=>$identity,'creatable'=>false,'reason'=>'parent_invalid','parent'=>$parent);
						continue;
					}
				}
				$manage_cap=isset($tax->cap->manage_terms)?(string)$tax->cap->manage_terms:'manage_categories';
				$creatable=current_user_can($manage_cap)&&$assign_allowed&&$policy_eligible;
				$create_input=array(
					'taxonomy'=>$taxonomy,
					'name'=>$name,
					'description'=>isset($ref['description'])?(string)$ref['description']:'',
					'parent'=>$parent,
				);
				if(!empty($ref['slug']))$create_input['slug']=sanitize_title((string)$ref['slug']);
				$missing_entry=array('taxonomy'=>$taxonomy,'identity'=>$identity,'creatable'=>$creatable,'create_input'=>$create_input);
				$missing[]=$missing_entry;
				if($creatable)$steps[]=array(
					'order'=>count($steps)+1,
					'kind'=>'dependency_create_term',
					'ability'=>'mad4b/taxonomy-create-term',
					'provider'=>'core',
					'input'=>$create_input,
					'reversible'=>true,
					'approval_required'=>class_exists('MAD4B_SCP_Impact_Policy')?MAD4B_SCP_Impact_Policy::requires_approval('mad4b/taxonomy-create-term','core',$create_input):true
				);
			}
		}

		foreach($resolved as $taxonomy=>$items){
			usort($items,static function($a,$b){ return ((int)$a['term_id'])<=>((int)$b['term_id']); });
			$resolved[$taxonomy]=array_values($items);
		}
		ksort($resolved,SORT_STRING);
		ksort($taxonomy_readiness,SORT_STRING);
		usort($blockers,static function($a,$b){ return strcmp(wp_json_encode($a,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),wp_json_encode($b,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); });
		usort($missing,static function($a,$b){
			$ak=(isset($a['taxonomy'])?(string)$a['taxonomy']:'').'|'.wp_json_encode(isset($a['identity'])?$a['identity']:array(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
			$bk=(isset($b['taxonomy'])?(string)$b['taxonomy']:'').'|'.wp_json_encode(isset($b['identity'])?$b['identity']:array(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
			return strcmp($ak,$bk);
		});
		usort($steps,static function($a,$b){
			$ak=(isset($a['ability'])?(string)$a['ability']:'').'|'.wp_json_encode(isset($a['input'])?$a['input']:array(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
			$bk=(isset($b['ability'])?(string)$b['ability']:'').'|'.wp_json_encode(isset($b['input'])?$b['input']:array(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
			return strcmp($ak,$bk);
		});
		foreach($steps as $i=>$step) $steps[$i]['order']=$i+1;

		$pipeline_settings_sha256='';
		if(class_exists('MAD4B_SCP_Dynamic_Content_Pipeline')){
			$pipeline_settings=MAD4B_SCP_Dynamic_Content_Pipeline::effective();
			$pipeline_settings_sha256=isset($pipeline_settings['settings_sha256'])?(string)$pipeline_settings['settings_sha256']:'';
		}
		$expected_state_sha256='';
		if('update'===$mode&&$target_ready&&$target){
			$expected_state_sha256=$this->state_sha256($this->snapshot($post_id,$input));
		}
		$bundle_sha256=$this->bundle_sha256_for_input($input,$mode,$post_type,$post_id,$operation_key);
		$steps[]=array(
			'order'=>count($steps)+1,
			'kind'=>'content_bundle',
			'ability'=>self::APPLY,
			'provider'=>'core',
			'requires_dependency_resolution'=>!empty($missing),
			'reversible'=>true,
			'execution_bindings'=>array(
				'operation_key'=>$operation_key,
				'expected_state_sha256'=>$expected_state_sha256,
				'expected_pipeline_settings_sha256'=>$pipeline_settings_sha256,
				'expected_bundle_sha256'=>$bundle_sha256
			)
		);

		$canonical=array(
			'mode'=>$mode,
			'post_type'=>$post_type,
			'post_id'=>$post_id,
			'operation_key'=>$operation_key,
			'post_status'=>$status,
			'bundle_sha256'=>$bundle_sha256,
			'expected_state_sha256'=>$expected_state_sha256,
			'post_type_readiness'=>$post_type_readiness,
			'resolved_terms'=>$resolved,
			'missing_terms'=>$missing,
			'blockers'=>$blockers,
			'taxonomy_readiness'=>$taxonomy_readiness,
			'steps'=>$steps,
			'pipeline_settings_sha256'=>$pipeline_settings_sha256,
		);
		$canonical_json=wp_json_encode($canonical,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		return array(
			'contract'=>'mad4b.dynamic-content-orchestration-plan.v1',
			'ready'=>empty($blockers)&&empty(array_filter($missing,static function($m){return empty($m['creatable']);})),
			'plan_ready'=>empty($blockers)&&empty(array_filter($missing,static function($m){return empty($m['creatable']);})),
			'bundle_ready'=>empty($missing)&&empty($blockers),
			'dependency_count'=>count($missing),
			'blocker_count'=>count($blockers),
			'blockers'=>$blockers,
			'taxonomy_readiness'=>$taxonomy_readiness,
			'mode'=>$mode,
			'post_type'=>$post_type,
			'post_id'=>$post_id,
			'post_status'=>$status,
			'operation_key'=>$operation_key,
			'bundle_sha256'=>$bundle_sha256,
			'expected_state_sha256'=>$expected_state_sha256,
			'pipeline_settings_sha256'=>$pipeline_settings_sha256,
			'post_type_readiness'=>$post_type_readiness,
			'resolved_terms'=>$resolved,
			'missing_terms'=>$missing,
			'steps'=>$steps,
			'plan_sha256'=>is_string($canonical_json)?hash('sha256',$canonical_json):'',
			'read_only'=>true,
			'mutation_performed'=>false
		);
	}


	public function pipeline_status(){ return class_exists('MAD4B_SCP_Dynamic_Content_Pipeline') ? MAD4B_SCP_Dynamic_Content_Pipeline::effective() : array('contract'=>'mad4b.dynamic-content-pipeline.v1','error'=>'pipeline_unavailable'); }
	public function can_pipeline_update($input=array()){ return current_user_can('manage_options') ? true : new WP_Error('mad4b_dynamic_pipeline_admin_required','Administrator capability is required.'); }
	public function pipeline_update($input=array()){ return class_exists('MAD4B_SCP_Dynamic_Content_Pipeline') ? MAD4B_SCP_Dynamic_Content_Pipeline::persist(is_array($input)?$input:array()) : new WP_Error('mad4b_dynamic_pipeline_unavailable','Dynamic content pipeline is unavailable.'); }

	public function discover_model($input=array()){
		$input=is_array($input)?$input:array();
		$pt=isset($input['post_type'])?sanitize_key((string)$input['post_type']):'';
		$taxq=isset($input['taxonomy'])?sanitize_key((string)$input['taxonomy']):'';
		if($pt!==''&&!post_type_exists($pt)) return new WP_Error('mad4b_dynamic_post_type_missing','Requested post type is not registered.');
		if($taxq!==''&&!taxonomy_exists($taxq)) return new WP_Error('mad4b_dynamic_taxonomy_missing','Requested taxonomy is not registered.');
		$with_terms=!empty($input['include_terms']); $include_observed_meta=!empty($input['include_observed_meta']); $sample_size=isset($input['sample_size'])?max(1,min(20,absint($input['sample_size']))):5; $offset=isset($input['term_offset'])?max(0,min(1000000,absint($input['term_offset']))):0; $limit=isset($input['term_limit'])?max(1,min(200,absint($input['term_limit']))):50;
		$search=isset($input['term_search'])?sanitize_text_field((string)$input['term_search']):'';
		$out=array();
		foreach(get_post_types(array(),'objects') as $name=>$obj){
			if($pt!==''&&$name!==$pt) continue;
			if($taxq!==''&&!in_array($name,(array)get_taxonomy($taxq)->object_type,true)) continue;
			$default_eligible=!empty($obj->show_ui)||!empty($obj->public)||!empty($obj->show_in_rest);
			if(!apply_filters('mad4b_scp_dynamic_content_post_type_discoverable',$default_eligible,$name,$obj,$input)) continue;
			$taxes=array();
			foreach(get_object_taxonomies($name,'objects') as $tax){ $manage_cap=isset($tax->cap->manage_terms)?(string)$tax->cap->manage_terms:'manage_categories'; $default_taxonomy_discoverable=!empty($tax->public)||!empty($tax->show_ui)||!empty($tax->show_in_rest)||current_user_can($manage_cap); if(!apply_filters('mad4b_scp_dynamic_content_taxonomy_discoverable',$default_taxonomy_discoverable,$tax,$name,$input)) continue; $taxes[]=$this->taxonomy_descriptor($tax,$with_terms&&($taxq===''||$taxq===$tax->name),$search,$offset,$limit); }
			$meta=array();
			if(function_exists('get_registered_meta_keys')) foreach(get_registered_meta_keys('post',$name) as $key=>$args){
				$default_visible=!is_protected_meta($key,'post');
				if(!apply_filters('mad4b_scp_dynamic_content_model_meta_key_visible',$default_visible,$key,$name,$args)) continue;
				$meta[]=array(
					'key'=>(string)$key,
					'type'=>isset($args['type'])?(string)$args['type']:'string',
					'single'=>!empty($args['single']),
					'show_in_rest'=>!empty($args['show_in_rest']),
					'protected'=>is_protected_meta($key,'post')
				);
			}
			$observed_meta=$include_observed_meta?$this->observed_meta_keys($name,$sample_size,$meta):array();
			$descriptor=array(
				'name'=>$name,'label'=>(string)$obj->label,'public'=>!empty($obj->public),'show_ui'=>!empty($obj->show_ui),
				'show_in_rest'=>!empty($obj->show_in_rest),'hierarchical'=>!empty($obj->hierarchical),
				'rest_base'=>isset($obj->rest_base)?(string)$obj->rest_base:'','query_var'=>isset($obj->query_var)&&is_scalar($obj->query_var)?(string)$obj->query_var:'',
				'supports'=>array_keys(get_all_post_type_supports($name)),'capabilities'=>$this->capability_map(isset($obj->cap)?$obj->cap:array()),
				'effective_capabilities'=>$this->effective_post_type_capabilities($obj),
				'taxonomies'=>$taxes,'registered_meta'=>$meta,'observed_meta_keys'=>$observed_meta
			);
			$descriptor=apply_filters('mad4b_scp_dynamic_content_model_post_type',$descriptor,$name,$obj,$input);
			if(is_array($descriptor))$out[]=$descriptor;
		}
		return array('contract'=>self::CONTRACT,'wordpress_runtime_dynamic'=>true,'hard_coded_post_types'=>false,'hard_coded_taxonomies'=>false,'post_types'=>$out,'count'=>count($out),'read_only'=>true,'mutation_performed'=>false);
	}

	private function taxonomy_descriptor($tax,$with_terms,$search,$offset,$limit){
		$terms=array();$has_more=false;
		if($with_terms){
			$args=array('taxonomy'=>$tax->name,'hide_empty'=>false,'number'=>$limit+1,'offset'=>$offset,'orderby'=>'term_id','order'=>'ASC');
			if($search!=='') $args['search']=$search;
			$found=get_terms($args);
			if(!is_wp_error($found)) foreach($found as $t) $terms[]=array('term_id'=>(int)$t->term_id,'name'=>(string)$t->name,'slug'=>(string)$t->slug,'parent'=>(int)$t->parent,'count'=>(int)$t->count);
			$has_more=count($terms)>$limit;
			if($has_more) $terms=array_slice($terms,0,$limit);
		}
		$descriptor=array('name'=>(string)$tax->name,'label'=>(string)$tax->label,'hierarchical'=>!empty($tax->hierarchical),'public'=>!empty($tax->public),'show_ui'=>!empty($tax->show_ui),'show_in_rest'=>!empty($tax->show_in_rest),'rest_base'=>isset($tax->rest_base)?(string)$tax->rest_base:'','query_var'=>isset($tax->query_var)&&is_scalar($tax->query_var)?(string)$tax->query_var:'','object_types'=>array_values((array)$tax->object_type),'capabilities'=>$this->capability_map(isset($tax->cap)?$tax->cap:array()),'effective_capabilities'=>$this->effective_taxonomy_capabilities($tax),'terms'=>$terms,'terms_returned'=>count($terms),'term_offset'=>(int)$offset,'term_limit'=>(int)$limit,'has_more_terms'=>(bool)$has_more,'next_term_offset'=>$has_more?($offset+count($terms)):null);
		$descriptor=apply_filters('mad4b_scp_dynamic_content_model_taxonomy',$descriptor,$tax);return is_array($descriptor)?$descriptor:array();
	}

	private function capability_map($caps){
		$out=array();foreach(is_object($caps)?get_object_vars($caps):(array)$caps as $key=>$value)if(is_scalar($value))$out[sanitize_key((string)$key)]=(string)$value;ksort($out,SORT_STRING);return $out;
	}
	private function effective_taxonomy_capabilities($tax){
		$cap=is_object($tax)&&isset($tax->cap)?$tax->cap:null;
		$map=array(
			'manage_terms'=>$cap&&isset($cap->manage_terms)?(string)$cap->manage_terms:'manage_categories',
			'edit_terms'=>$cap&&isset($cap->edit_terms)?(string)$cap->edit_terms:'manage_categories',
			'delete_terms'=>$cap&&isset($cap->delete_terms)?(string)$cap->delete_terms:'manage_categories',
			'assign_terms'=>$cap&&isset($cap->assign_terms)?(string)$cap->assign_terms:'edit_posts',
		);
		$out=array();foreach($map as $key=>$capability)$out[$key]=array('capability'=>$capability,'allowed'=>current_user_can($capability));return $out;
	}

	private function effective_post_type_capabilities($obj){
		$cap=is_object($obj)&&isset($obj->cap)?$obj->cap:null;
		$map=array(
			'edit_posts'=>$cap&&isset($cap->edit_posts)?(string)$cap->edit_posts:'edit_posts',
			'publish_posts'=>$cap&&isset($cap->publish_posts)?(string)$cap->publish_posts:'publish_posts',
			'delete_posts'=>$cap&&isset($cap->delete_posts)?(string)$cap->delete_posts:'delete_posts',
			'create_posts'=>$cap&&isset($cap->create_posts)?(string)$cap->create_posts:($cap&&isset($cap->edit_posts)?(string)$cap->edit_posts:'edit_posts'),
		);
		$out=array();foreach($map as $key=>$capability)$out[$key]=array('capability'=>$capability,'allowed'=>current_user_can($capability));return $out;
	}

	private function observed_meta_keys($post_type,$sample_size,array $registered){
		$registered_keys=array();foreach($registered as $item)if(is_array($item)&&isset($item['key']))$registered_keys[(string)$item['key']]=true;
		$ids=get_posts(array('post_type'=>$post_type,'post_status'=>'any','posts_per_page'=>max(1,min(20,absint($sample_size))),'fields'=>'ids','orderby'=>'ID','order'=>'DESC','no_found_rows'=>true));
		$keys=array();
		foreach(is_array($ids)?$ids:array() as $id){
			$id=(int)$id;
			if(!current_user_can('read_post',$id)&&!current_user_can('edit_post',$id)) continue;
			foreach(array_keys((array)get_post_meta($id)) as $key){
				$key=(string)$key;if($key===''||is_protected_meta($key,'post'))continue;
				if(!current_user_can('read_post_meta',$id,$key)) continue;
				if(!apply_filters('mad4b_scp_dynamic_content_observed_meta_key_visible',true,$key,$post_type,(int)$id))continue;
				$keys[$key]=array('key'=>$key,'registered'=>isset($registered_keys[$key]),'protected'=>false);
			}
		}
		ksort($keys,SORT_STRING);return array_values(array_slice($keys,0,200,true));
	}

	public function can_apply_bundle($input=array()){ $v=$this->validate_bundle(is_array($input)?$input:array(),false); return is_wp_error($v)?$v:true; }

	private function json_compatible($value,$depth=0){
		if($depth>12) return false;
		if(is_resource($value)||is_object($value)) return false;
		if(is_array($value)){
			foreach($value as $k=>$v){
				if(!is_int($k)&&!is_string($k)) return false;
				if(!$this->json_compatible($v,$depth+1)) return false;
			}
		}
		return is_null($value)||is_scalar($value)||is_array($value);
	}

	private function desired_meta_specs(array $input){
		$specs=array();
		$legacy=isset($input['meta'])&&is_array($input['meta'])?$input['meta']:array();
		$explicit=isset($input['meta_entries'])&&is_array($input['meta_entries'])?$input['meta_entries']:array();

		foreach($legacy as $key=>$value){
			$key=(string)$key;
			if(array_key_exists($key,$explicit)) return new WP_Error('mad4b_dynamic_meta_duplicate_contract','A meta key cannot appear in both meta and meta_entries.',array('meta_key'=>$key));
			$specs[$key]=array('mode'=>'single','values'=>array($value));
		}
		foreach($explicit as $key=>$spec){
			$key=(string)$key;
			if(!is_array($spec)) return new WP_Error('mad4b_dynamic_meta_entry_invalid','meta_entries values must be objects.',array('meta_key'=>$key));
			$mode=isset($spec['mode'])?sanitize_key((string)$spec['mode']):'';
			if(!in_array($mode,array('single','multi','delete'),true)) return new WP_Error('mad4b_dynamic_meta_mode_invalid','Meta entry mode must be single, multi or delete.',array('meta_key'=>$key));
			if('delete'===$mode){
				if(array_key_exists('value',$spec)||array_key_exists('values',$spec)) return new WP_Error('mad4b_dynamic_meta_delete_payload_invalid','Delete meta mode must not include value or values.',array('meta_key'=>$key));
				$specs[$key]=array('mode'=>'delete','values'=>array());
				continue;
			}
			if('single'===$mode){
				if(!array_key_exists('value',$spec)||array_key_exists('values',$spec)) return new WP_Error('mad4b_dynamic_meta_single_payload_invalid','Single meta mode requires exactly value.',array('meta_key'=>$key));
				$specs[$key]=array('mode'=>'single','values'=>array($spec['value']));
				continue;
			}
			if(!isset($spec['values'])||!is_array($spec['values'])||array_key_exists('value',$spec)||empty($spec['values'])) return new WP_Error('mad4b_dynamic_meta_multi_payload_invalid','Multi meta mode requires a non-empty values array and no value field.',array('meta_key'=>$key));
			if(count($spec['values'])>100) return new WP_Error('mad4b_dynamic_meta_multi_limit','Multi meta mode exceeds the bounded value count.',array('meta_key'=>$key));
			$specs[$key]=array('mode'=>'multi','values'=>array_values($spec['values']));
		}
		ksort($specs,SORT_STRING);
		return $specs;
	}

	private function normalize_meta_storage_value($value){
		if(is_array($value)) return $this->canonical_value($value);
		if(is_null($value)||false===$value) return '';
		if(true===$value) return '1';
		if(is_int($value)||is_float($value)) return (string)$value;
		return (string)$value;
	}

	private function desired_meta_envelope(array $spec){
		$mode=isset($spec['mode'])?(string)$spec['mode']:'';
		$values=isset($spec['values'])&&is_array($spec['values'])?array_values($spec['values']):array();
		if('delete'===$mode) return array('exists'=>false,'values'=>array());
		$normalized=array();
		foreach($values as $value) $normalized[]=$this->normalize_meta_storage_value($value);
		return array('exists'=>true,'values'=>$normalized);
	}

	private function bundle_sha256_for_input(array $input,$mode,$post_type,$post_id,$operation_key){
		$basis=$this->canonical_value(array(
			'mode'=>sanitize_key((string)$mode),
			'post_type'=>sanitize_key((string)$post_type),
			'post_id'=>absint($post_id),
			'operation_key'=>(string)$operation_key,
			'post'=>isset($input['post'])&&is_array($input['post'])?$input['post']:array(),
			'meta'=>isset($input['meta'])&&is_array($input['meta'])?$input['meta']:array(),
			'meta_entries'=>isset($input['meta_entries'])&&is_array($input['meta_entries'])?$input['meta_entries']:array(),
			'taxonomies'=>isset($input['taxonomies'])&&is_array($input['taxonomies'])?$input['taxonomies']:array(),
			'featured_media_id'=>array_key_exists('featured_media_id',$input)?absint($input['featured_media_id']):null,
			'evidence'=>isset($input['evidence'])&&is_array($input['evidence'])?$input['evidence']:array(),
			'acceptance_targets'=>isset($input['acceptance_targets'])&&is_array($input['acceptance_targets'])?$input['acceptance_targets']:array(),
			'validation'=>isset($input['validation'])&&is_array($input['validation'])?$input['validation']:array(),
		));
		$json=wp_json_encode($basis,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		return is_string($json)?hash('sha256',$json):'';
	}

	private function validate_bundle(array $input,$strict){
		$mode=isset($input['mode'])?sanitize_key((string)$input['mode']):'create';
		if(!in_array($mode,array('create','update'),true)) return new WP_Error('mad4b_dynamic_mode_invalid','Mode must be create or update.');
		$pt=isset($input['post_type'])?sanitize_key((string)$input['post_type']):'';
		if($pt===''||!post_type_exists($pt)) return new WP_Error('mad4b_dynamic_post_type_missing','Requested post type is not registered.');
		$obj=get_post_type_object($pt); $default_eligible=$obj&&(!empty($obj->show_ui)||!empty($obj->public)||!empty($obj->show_in_rest));
		if(!apply_filters('mad4b_scp_dynamic_content_post_type_mutation_eligible',$default_eligible,$pt,$obj,$input)) return new WP_Error('mad4b_dynamic_post_type_ineligible','Requested post type is not eligible for dynamic governed mutation.');
		$environment=function_exists('wp_get_environment_type')?sanitize_key((string)wp_get_environment_type()):'unknown';
		if(!apply_filters('mad4b_scp_dynamic_content_environment_mutation_eligible',true,$environment,$mode,$pt,$input)) return new WP_Error('mad4b_dynamic_environment_mutation_denied','Dynamic content mutation is denied by the current environment policy.',array('environment'=>$environment,'mode'=>$mode,'post_type'=>$pt));
		$pipeline=class_exists('MAD4B_SCP_Dynamic_Content_Pipeline')?MAD4B_SCP_Dynamic_Content_Pipeline::effective():array();
		$current_pipeline_sha=isset($pipeline['settings_sha256'])?strtolower((string)$pipeline['settings_sha256']):'';
		$expected_pipeline_sha=isset($input['expected_pipeline_settings_sha256'])?strtolower(trim((string)$input['expected_pipeline_settings_sha256'])):'';
		if(1!==preg_match('/^[a-f0-9]{64}$/',$expected_pipeline_sha)||1!==preg_match('/^[a-f0-9]{64}$/',$current_pipeline_sha)||!hash_equals($current_pipeline_sha,$expected_pipeline_sha)) return new WP_Error(
			'mad4b_dynamic_pipeline_drift',
			'Dynamic content pipeline settings changed after planning or approval. Refresh the plan before mutation.',
			array('expected_pipeline_settings_sha256'=>$expected_pipeline_sha,'current_pipeline_settings_sha256'=>$current_pipeline_sha)
		);

		$create_cap=$obj&&isset($obj->cap->create_posts)?(string)$obj->cap->create_posts:($obj&&isset($obj->cap->edit_posts)?(string)$obj->cap->edit_posts:'edit_posts');
		$edit_cap=$obj&&isset($obj->cap->edit_posts)?(string)$obj->cap->edit_posts:'edit_posts';
		$required_cap='create'===$mode?$create_cap:$edit_cap;
		if(!current_user_can($required_cap)) return new WP_Error(
			'create'===$mode?'mad4b_dynamic_post_type_create_denied':'mad4b_dynamic_post_type_write_denied',
			'Current user does not have the required capability for this post type mutation.',
			array('capability'=>$required_cap,'mode'=>$mode)
		);

		$key=isset($input['operation_key'])?trim((string)$input['operation_key']):'';
		if(1!==preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key)) return new WP_Error('mad4b_dynamic_operation_key_invalid','Stable operation_key is required.');
		$expected_bundle_sha=isset($input['expected_bundle_sha256'])?strtolower(trim((string)$input['expected_bundle_sha256'])):'';
		$current_bundle_sha=$this->bundle_sha256_for_input($input,$mode,$pt,isset($input['post_id'])?absint($input['post_id']):0,$key);
		if(1!==preg_match('/^[a-f0-9]{64}$/',$expected_bundle_sha)||!hash_equals($current_bundle_sha,$expected_bundle_sha)) return new WP_Error(
			'mad4b_dynamic_bundle_drift',
			'Content bundle differs from the exact orchestration plan. Refresh the plan before mutation.',
			array('expected_bundle_sha256'=>$expected_bundle_sha,'current_bundle_sha256'=>$current_bundle_sha)
		);
		$post=isset($input['post'])&&is_array($input['post'])?$input['post']:array();

		$title_supported=post_type_supports($pt,'title');
		$title_required=(bool)apply_filters('mad4b_scp_dynamic_content_title_required',$title_supported,$pt,$mode,$input);
		if($title_required&&(!array_key_exists('post_title',$post)||''===trim((string)$post['post_title']))) return new WP_Error('mad4b_dynamic_title_required','This post type requires a non-empty post_title under the current site policy.');

		$status=isset($post['post_status'])?sanitize_key((string)$post['post_status']):('create'===$mode?'draft':'');
		if(in_array($status,array('publish','private'),true)) return new WP_Error(
			'mad4b_dynamic_direct_publication_denied',
			'Dynamic content bundles are draft/pending-only. Complete acceptance first, then use the separate governed publication ability.',
			array('post_status'=>$status,'required_next_ability'=>'mad4b/content-update-post')
		);

		if(isset($post['post_parent'])&&absint($post['post_parent'])>0){
			$parent_id=absint($post['post_parent']);
			if(empty($obj->hierarchical)) return new WP_Error('mad4b_dynamic_parent_not_supported','post_parent is only supported for hierarchical post types.');
			$parent=get_post($parent_id);
			if(!$parent||$pt!==(string)$parent->post_type) return new WP_Error('mad4b_dynamic_parent_invalid','post_parent must reference an existing item of the same post type.');
			if(!current_user_can('edit_post',$parent_id)) return new WP_Error('mad4b_dynamic_parent_denied','Current user cannot use the requested parent.');
		}

		if(isset($post['post_author'])){
			$author_id=absint($post['post_author']);
			if($author_id<1||!get_userdata($author_id)) return new WP_Error('mad4b_dynamic_author_invalid','Requested post author does not exist.');
			if($author_id!==get_current_user_id()){
				$edit_others_cap=$obj&&isset($obj->cap->edit_others_posts)?(string)$obj->cap->edit_others_posts:'edit_others_posts';
				if(!current_user_can($edit_others_cap)) return new WP_Error('mad4b_dynamic_author_change_denied','Current user cannot assign this post to another author.');
			}
		}
		$id=isset($input['post_id'])?absint($input['post_id']):0;
		if($mode==='update'){
			$p=get_post($id); if(!$p||$p->post_type!==$pt) return new WP_Error('mad4b_dynamic_update_target_invalid','Update target does not match post_type.');
			if(!current_user_can('edit_post',$id)) return new WP_Error('mad4b_dynamic_update_denied','Current user cannot edit target.');
			if(in_array((string)$p->post_status,array('publish','private'),true)) return new WP_Error(
				'mad4b_dynamic_live_target_denied',
				'Dynamic content bundles do not mutate live posts directly. Use a draft/shadow workflow and publish separately after acceptance.',
				array('post_id'=>$id,'post_status'=>(string)$p->post_status,'required_next_ability'=>'mad4b/content-update-post')
			);
			if($strict){ $expected=isset($input['expected_state_sha256'])?strtolower(trim((string)$input['expected_state_sha256'])):''; $actual=$this->state_sha256($this->snapshot($id,$input));
				if(1!==preg_match('/^[a-f0-9]{64}$/',$expected)||!hash_equals($actual,$expected)) return new WP_Error('mad4b_dynamic_state_drift','Target changed after planning; fresh readback and approval are required.'); }
		}elseif($id>0) return new WP_Error('mad4b_dynamic_create_post_id_denied','Create mode must not supply post_id.');
		$meta_specs=$this->desired_meta_specs($input);
		if(is_wp_error($meta_specs)) return $meta_specs;
		if(count($meta_specs)>200) return new WP_Error('mad4b_dynamic_meta_limit','Dynamic content bundle exceeds the bounded meta key count.');
		foreach($meta_specs as $mk=>$spec){
			if(1!==preg_match('/^[A-Za-z0-9_.:-]{1,191}$/',(string)$mk)) return new WP_Error('mad4b_dynamic_meta_key_invalid','Invalid meta key.');
			if(is_protected_meta($mk,'post')&&!apply_filters('mad4b_scp_dynamic_content_allow_protected_meta',false,$mk,$pt,$input)) return new WP_Error('mad4b_dynamic_protected_meta_denied','Protected meta requires exact site-policy allowlisting.');
			foreach(isset($spec['values'])?(array)$spec['values']:array() as $mv) if(!$this->json_compatible($mv)) return new WP_Error('mad4b_dynamic_meta_value_invalid','Meta values must be bounded JSON-compatible data.',array('meta_key'=>(string)$mk));
		}
		foreach(isset($input['taxonomies'])&&is_array($input['taxonomies'])?$input['taxonomies']:array() as $tax=>$refs){
			$tax=sanitize_key((string)$tax); $to=get_taxonomy($tax);
			if(!$to||!in_array($pt,(array)$to->object_type,true)) return new WP_Error('mad4b_dynamic_taxonomy_not_attached','Taxonomy is not attached to post_type.');
			if(!apply_filters('mad4b_scp_dynamic_content_taxonomy_mutation_eligible',true,$tax,$to,$pt,$input)) return new WP_Error('mad4b_dynamic_taxonomy_ineligible','Taxonomy is not eligible for dynamic governed mutation.');
			$rcap=isset($to->cap->assign_terms)?(string)$to->cap->assign_terms:'edit_posts'; if(!current_user_can($rcap)) return new WP_Error('mad4b_dynamic_taxonomy_assign_denied','Current user cannot assign taxonomy.');
			$r=$this->resolve_terms($tax,is_array($refs)?$refs:array()); if(is_wp_error($r)) return $r;
		}
		return array('mode'=>$mode,'post_type'=>$pt,'post_id'=>$id,'operation_key'=>$key,'pipeline_config'=>$pipeline);
	}

	private function resolve_terms($tax,array $refs){
		$ids=array();
		foreach($refs as $ref){
			if(!is_array($ref)) return new WP_Error('mad4b_dynamic_term_ref_invalid','Term refs must be objects.');
			$n=0;$t=null;
			if(!empty($ref['term_id'])){$n++;$t=get_term(absint($ref['term_id']),$tax);}
			if(!empty($ref['slug'])){$n++;$t=get_term_by('slug',sanitize_title((string)$ref['slug']),$tax);}
			if(!empty($ref['name'])){
				$n++;
				$name=sanitize_text_field((string)$ref['name']);
				$matches=get_terms(array('taxonomy'=>$tax,'hide_empty'=>false,'name'=>$name,'number'=>2,'orderby'=>'term_id','order'=>'ASC'));
				if(is_wp_error($matches)) return $matches;
				if(count($matches)>1) return new WP_Error('mad4b_dynamic_term_name_ambiguous','Term name resolves to multiple terms; use term_id or slug.',array('taxonomy'=>$tax,'name'=>$name));
				$t=!empty($matches)?$matches[0]:null;
			}
			if($n!==1) return new WP_Error('mad4b_dynamic_term_ref_ambiguous','Each term ref must use exactly one of term_id, slug or name.');
			if(!$t||is_wp_error($t)) return new WP_Error('mad4b_dynamic_term_missing','Referenced term does not exist; create it through governed taxonomy-create-term first.');
			if(!apply_filters('mad4b_scp_dynamic_content_term_reference_eligible',true,$t,$tax,$ref)) return new WP_Error('mad4b_dynamic_term_ineligible','Referenced term is not eligible for dynamic governed assignment.');
			$ids[]=(int)$t->term_id;
		}
		$ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);return $ids;
	}

	private function mutation_lock_name($mode,$post_type,$post_id,$binding){
		$identity='create'===sanitize_key((string)$mode)
			? 'create|'.sanitize_key((string)$post_type).'|'.(string)$binding
			: 'update|'.sanitize_key((string)$post_type).'|'.absint($post_id);
		return self::MUTATION_LOCK_PREFIX.substr(hash('sha256',$identity),0,48);
	}

	private function acquire_mutation_lock($mode,$post_type,$post_id,$binding){
		$name=$this->mutation_lock_name($mode,$post_type,$post_id,$binding);
		$token=wp_generate_uuid4();
		$value=array('token'=>$token,'acquired_at'=>time());
		if(add_option($name,$value,'','no')) return array('name'=>$name,'token'=>$token);
		$existing=get_option($name,array());
		$acquired=is_array($existing)&&isset($existing['acquired_at'])?(int)$existing['acquired_at']:0;
		if($acquired>0&&$acquired<=(time()-self::MUTATION_LOCK_TTL)){
			delete_option($name);
			if(add_option($name,$value,'','no')) return array('name'=>$name,'token'=>$token);
		}
		return new WP_Error('mad4b_dynamic_mutation_busy','Another dynamic content mutation is already operating on this exact target.',array('mode'=>sanitize_key((string)$mode),'post_type'=>sanitize_key((string)$post_type),'post_id'=>absint($post_id),'retryable'=>true));
	}

	private function release_mutation_lock(array $lock){
		$name=isset($lock['name'])?(string)$lock['name']:'';
		$token=isset($lock['token'])?(string)$lock['token']:'';
		if($name===''||$token==='') return;
		$current=get_option($name,array());
		if(is_array($current)&&isset($current['token'])&&hash_equals((string)$current['token'],$token)) delete_option($name);
	}

	private function binding($pt,$key){ return hash('sha256',self::CONTRACT.'|'.$pt.'|'.$key); }
	private function bound_post($pt,$binding){
		$ids=get_posts(array('post_type'=>$pt,'post_status'=>'any','posts_per_page'=>2,'fields'=>'ids','meta_key'=>self::BINDING,'meta_value'=>$binding,'orderby'=>'ID','order'=>'ASC','no_found_rows'=>true));
		if(count($ids)>1) return new WP_Error('mad4b_dynamic_binding_duplicate','Operation binding resolves to multiple posts.');
		return empty($ids)?0:(int)$ids[0];
	}
	private function post_fields(array $post){
		$out=array(); foreach(array('post_title','post_content','post_excerpt','post_status','post_parent','post_author') as $k) if(array_key_exists($k,$post)) $out[$k]=in_array($k,array('post_parent','post_author'),true)?absint($post[$k]):(string)$post[$k]; return $out;
	}

	private function not_started_error($error){
		if(!is_wp_error($error)) $error=new WP_Error('mad4b_dynamic_not_started','Dynamic content mutation did not start.');
		$code=$error->get_error_code();
		$data=$error->get_error_data($code);
		if(!is_array($data)) $data=array();
		$data['mad4b_execution_state']=array('contract'=>'mad4b.execution-state.v1','started'=>false);
		$error->add_data($data,$code);
		return $error;
	}

	public function apply_bundle($input=array()){
		$input=is_array($input)?$input:array();
		$pre=$this->validate_bundle($input,false);
		if(is_wp_error($pre)) return $this->not_started_error($pre);
		$binding=$this->binding($pre['post_type'],$pre['operation_key']);
		$lock=$this->acquire_mutation_lock($pre['mode'],$pre['post_type'],$pre['post_id'],$binding);
		if(is_wp_error($lock)) return $this->not_started_error($lock);
		try{
			return $this->apply_bundle_locked($input);
		} finally {
			$this->release_mutation_lock($lock);
		}
	}

	private function apply_bundle_locked(array $input){
		$v=$this->validate_bundle($input,true);
		if(is_wp_error($v)) return $this->not_started_error($v);

		$binding=$this->binding($v['post_type'],$v['operation_key']);
		$id=$v['post_id'];
		$before='update'===$v['mode']?$this->snapshot($id,$input):null;

		if($v['mode']==='create'){
			$existing=$this->bound_post($v['post_type'],$binding);
			if(is_wp_error($existing)) return $this->not_started_error($existing);
			if($existing) return $this->not_started_error(new WP_Error('mad4b_dynamic_binding_exists','operation_key already created a post.',array('post_id'=>$existing)));
			$arr=array_merge(array('post_type'=>$v['post_type']),$this->post_fields($input['post']));
			$arr['meta_input']=array(self::BINDING=>$binding);
			$id=wp_insert_post(wp_slash($arr),true);
			if(is_wp_error($id)) return $id;
		}else{
			$arr=array_merge(array('ID'=>$id,'post_type'=>$v['post_type']),$this->post_fields($input['post']));
			$u=wp_update_post(wp_slash($arr),true);
			if(is_wp_error($u)) return $u;
		}

		$a=$this->apply_desired($id,$input);
		if(is_wp_error($a)) return $this->failure_with_compensation($a,$v['mode'],$id,$before);

		$pipeline=isset($v['pipeline_config'])&&is_array($v['pipeline_config'])
			? $v['pipeline_config']
			: (class_exists('MAD4B_SCP_Dynamic_Content_Pipeline')
				? MAD4B_SCP_Dynamic_Content_Pipeline::effective()
				: array('contract'=>'mad4b.dynamic-content-pipeline.v1','max_iterations'=>3,'default_repair_mode'=>'safe_only','stop_on_no_progress'=>true,'no_progress_limit'=>1,'settings_sha256'=>''));
		$cfg=isset($input['validation'])&&is_array($input['validation'])?$input['validation']:array();
		$max=isset($cfg['max_iterations'])
			? max(1,min(self::MAX_ITERATIONS,absint($cfg['max_iterations'])))
			: max(1,min(self::MAX_ITERATIONS,(int)$pipeline['max_iterations']));
		$repair=isset($cfg['repair_mode'])
			? sanitize_key((string)$cfg['repair_mode'])
			: (isset($pipeline['default_repair_mode'])?(string)$pipeline['default_repair_mode']:'safe_only');

		$history=array();
		$previous='';
		$no_progress=0;
		$final=array();
		$findings=array();
		$acceptance='review_required';

		for($i=1;$i<=$max;$i++){
			$final=$this->snapshot($id,$input);
			$context=array(
				'adapter'=>$this,
				'post_id'=>$id,
				'mode'=>$v['mode'],
				'environment'=>function_exists('wp_get_environment_type')?wp_get_environment_type():'unknown',
				'post_type'=>$v['post_type'],
				'post_status'=>isset($final['post']['post_status'])?$final['post']['post_status']:'',
				'input'=>$input,
				'evidence'=>isset($input['evidence'])&&is_array($input['evidence'])?$input['evidence']:array(),
				'acceptance_targets'=>isset($input['acceptance_targets'])&&is_array($input['acceptance_targets'])?$input['acceptance_targets']:array(),
				'state'=>$final,
				'iteration'=>$i,
				'findings'=>array(),
				'repair_allowed'=>'safe_only'===$repair,
				'pipeline_config'=>$pipeline,
			);

			if(class_exists('MAD4B_SCP_Dynamic_Content_Pipeline')){
				$validated=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('validate',$context);
				if(is_wp_error($validated)) return $this->failure_with_compensation($validated,$v['mode'],$id,$before);
				$findings=isset($validated['findings'])?(array)$validated['findings']:array();
				$context=$validated;
			}else{
				$findings=$this->structural_findings($final,$input,$i);
			}

			$d=hash('sha256',wp_json_encode($findings,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
			$history[]=array(
				'iteration'=>$i,
				'finding_count'=>count($findings),
				'findings_sha256'=>$d,
				'stage_results'=>isset($context['stage_results'])?$context['stage_results']:array(),
			);

			if(!$findings) break;
			if($previous!==''&&hash_equals($previous,$d)) $no_progress++; else $no_progress=0;
			if($repair==='off'||$i===$max||(!empty($pipeline['stop_on_no_progress'])&&$no_progress>=max(1,(int)$pipeline['no_progress_limit']))) break;

			if(class_exists('MAD4B_SCP_Dynamic_Content_Pipeline')){
				$repaired=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('repair',$context);
				if(is_wp_error($repaired)) return $this->failure_with_compensation($repaired,$v['mode'],$id,$before);
				$context=$repaired;
				$last=count($history)-1;
				if($last>=0){
					$history[$last]['stage_results']=isset($context['stage_results'])?(array)$context['stage_results']:array();
					$history[$last]['repair_performed']=!empty($context['repair_performed']);
					$history[$last]['repair_skip_reason']=isset($context['repair_skip_reason'])?(string)$context['repair_skip_reason']:'';
				}
				if(isset($context['repair_performed'])&&!$context['repair_performed']) break;
			}else{
				$r=$this->repair_desired_state($id,$input);
				if(is_wp_error($r)) return $this->failure_with_compensation($r,$v['mode'],$id,$before);
				$last=count($history)-1;
				if($last>=0) $history[$last]['repair_performed']=true;
			}
			$previous=$d;
		}

		$final=$this->snapshot($id,$input);
		$final_context=array(
			'adapter'=>$this,
			'post_id'=>$id,
			'mode'=>$v['mode'],
			'environment'=>function_exists('wp_get_environment_type')?wp_get_environment_type():'unknown',
			'post_type'=>$v['post_type'],
			'post_status'=>isset($final['post']['post_status'])?$final['post']['post_status']:'',
			'input'=>$input,
			'evidence'=>isset($input['evidence'])&&is_array($input['evidence'])?$input['evidence']:array(),
			'acceptance_targets'=>isset($input['acceptance_targets'])&&is_array($input['acceptance_targets'])?$input['acceptance_targets']:array(),
			'state'=>$final,
			'iteration'=>count($history)+1,
			'findings'=>array(),
			'repair_allowed'=>false,
			'pipeline_config'=>$pipeline,
		);

		if(class_exists('MAD4B_SCP_Dynamic_Content_Pipeline')){
			$validated=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('validate',$final_context);
			if(is_wp_error($validated)) return $this->failure_with_compensation($validated,$v['mode'],$id,$before);
			$findings=isset($validated['findings'])?(array)$validated['findings']:array();
			$validated['findings']=$findings;

			$accepted=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('accept',$validated);
			if(is_wp_error($accepted)) return $this->failure_with_compensation($accepted,$v['mode'],$id,$before);
			$acceptance=isset($accepted['acceptance_status'])?(string)$accepted['acceptance_status']:($findings?'review_required':'accepted');
		}else{
			$findings=$this->structural_findings($final,$input,count($history)+1);
			$acceptance=$findings?'review_required':'accepted';
		}

		$final_status=isset($final['post']['post_status'])?sanitize_key((string)$final['post']['post_status']):'';
		$require_acceptance=(bool)apply_filters('mad4b_scp_dynamic_content_require_acceptance',in_array($final_status,array('publish','private'),true),$final_status,$v['mode'],$v['post_type'],$input,$pipeline);
		if($require_acceptance&&(!empty($findings)||'accepted'!==$acceptance)){
			return $this->failure_with_compensation(
				new WP_Error('mad4b_dynamic_acceptance_required','A live content state reached the draft-only orchestrator unexpectedly and failed final acceptance.',array('post_status'=>$final_status,'acceptance_status'=>$acceptance,'finding_count'=>count($findings))),
				$v['mode'],$id,$before
			);
		}

		$final_stage_results=isset($accepted)&&is_array($accepted)&&isset($accepted['stage_results'])?(array)$accepted['stage_results']:(isset($validated)&&is_array($validated)&&isset($validated['stage_results'])?(array)$validated['stage_results']:array());
		$evidence=isset($input['evidence'])&&is_array($input['evidence'])?$input['evidence']:array();
		$targets=isset($input['acceptance_targets'])&&is_array($input['acceptance_targets'])?$input['acceptance_targets']:array();

		return array(
			'contract'=>self::CONTRACT,
			'status'=>$findings?'review_required':'converged',
			'converged'=>!$findings,
			'acceptance_status'=>$acceptance,
			'mode'=>$v['mode'],
			'post_id'=>(int)$id,
			'post_type'=>$v['post_type'],
			'iterations'=>count($history),
			'pipeline_settings_sha256'=>isset($pipeline['settings_sha256'])?$pipeline['settings_sha256']:'',
			'evidence_sha256'=>$this->state_sha256($evidence),
			'acceptance_targets_sha256'=>$this->state_sha256($targets),
			'validation_history'=>$history,
			'final_stage_results'=>$final_stage_results,
			'unresolved_findings'=>$findings,
			'readback'=>$final,
			'readback_sha256'=>$this->state_sha256($final),
			'rollback_available'=>true,
			'compensation_on_error'=>true,
			'environment'=>function_exists('wp_get_environment_type')?sanitize_key((string)wp_get_environment_type()):'unknown',
			'production_mutation'=>function_exists('wp_get_environment_type')&&'production'===wp_get_environment_type(),
		);
	}

	public function repair_desired_state($id,array $input){ return $this->apply_desired($id,$input); }

	private function apply_desired($id,array $input){
		$meta_specs=$this->desired_meta_specs($input);
		if(is_wp_error($meta_specs)) return $meta_specs;
		foreach($meta_specs as $k=>$spec){
			if(!current_user_can('edit_post_meta',$id,(string)$k)) return new WP_Error('mad4b_dynamic_meta_edit_denied','Current user cannot edit the requested meta key.',array('meta_key'=>(string)$k,'post_id'=>(int)$id));
			delete_post_meta($id,(string)$k);
			if('delete'===(string)$spec['mode']) continue;
			foreach((array)$spec['values'] as $value){
				if(false===add_post_meta($id,(string)$k,$value,false)) return new WP_Error('mad4b_dynamic_meta_write_failed','A requested meta value could not be written.',array('meta_key'=>(string)$k));
			}
		}
		foreach(isset($input['taxonomies'])&&is_array($input['taxonomies'])?$input['taxonomies']:array() as $tax=>$refs){ $ids=$this->resolve_terms(sanitize_key((string)$tax),(array)$refs); if(is_wp_error($ids)) return $ids; $r=wp_set_object_terms($id,$ids,sanitize_key((string)$tax),false); if(is_wp_error($r)) return $r; }
		if(array_key_exists('featured_media_id',$input)){
			$m=absint($input['featured_media_id']);
			$current=(int)get_post_thumbnail_id($id);
			if($m>0){
				if(get_post_type($m)!=='attachment') return new WP_Error('mad4b_dynamic_featured_media_invalid','featured_media_id must reference attachment.');
				if($current!==$m&&!set_post_thumbnail($id,$m)) return new WP_Error('mad4b_dynamic_featured_media_failed','Featured media write failed.');
			}elseif($current>0){
				delete_post_thumbnail($id);
			}
		}
		clean_post_cache($id); return true;
	}

	public function structural_findings(array $state,array $input,$iteration){
		$f=array();$post=isset($state['post'])?$state['post']:array();
		foreach($this->post_fields($input['post']) as $k=>$e){$a=isset($post[$k])?$post[$k]:null;if(in_array($k,array('post_parent','post_author'),true)){$a=(int)$a;$e=(int)$e;}if($a!==$e)$f[]=array('code'=>'post_field_mismatch','field'=>$k,'repairable'=>true);}
		$meta_specs=$this->desired_meta_specs($input);
		if(is_wp_error($meta_specs)) return array(array('code'=>'meta_contract_invalid','repairable'=>false));
		foreach($meta_specs as $k=>$spec){$a=array_key_exists($k,$state['meta'])?$state['meta'][$k]:array('exists'=>false,'values'=>array());$expected=$this->desired_meta_envelope($spec);if($this->state_sha256($a)!==$this->state_sha256($expected))$f[]=array('code'=>'meta_mismatch','field'=>(string)$k,'repairable'=>true);}
		foreach(isset($input['taxonomies'])?(array)$input['taxonomies']:array() as $tax=>$refs){$e=$this->resolve_terms(sanitize_key((string)$tax),(array)$refs);$a=isset($state['taxonomies'][$tax])?$state['taxonomies'][$tax]:array();if(!is_wp_error($e)&&$e!==$a)$f[]=array('code'=>'taxonomy_mismatch','field'=>(string)$tax,'repairable'=>true);}
		if(array_key_exists('featured_media_id',$input)&&(int)$state['featured_media_id']!==absint($input['featured_media_id']))$f[]=array('code'=>'featured_media_mismatch','field'=>'featured_media_id','repairable'=>true);
		$extended=apply_filters('mad4b_scp_dynamic_content_validation_findings',$f,$state,$input,absint($iteration)); return is_array($extended)?array_values($extended):$f;
	}

	public function readback($input=array()){
		$input=is_array($input)?$input:array();
		$id=isset($input['post_id'])?absint($input['post_id']):0;
		if(!$id||!get_post($id))return new WP_Error('mad4b_dynamic_readback_target_missing','Post does not exist.');
		if(!current_user_can('read_post',$id)&&!current_user_can('edit_post',$id))return new WP_Error('mad4b_dynamic_readback_denied','Current user cannot read the requested post.');
		$post_type=(string)get_post_type($id);
		$meta_keys=isset($input['meta_keys'])&&is_array($input['meta_keys'])?array_values(array_unique(array_map('strval',$input['meta_keys']))):array();
		foreach($meta_keys as $meta_key){
			$default_readable=!is_protected_meta($meta_key,'post');
			if(!current_user_can('read_post_meta',$id,$meta_key)) return new WP_Error('mad4b_dynamic_meta_read_denied','Current user cannot read the requested meta key.',array('meta_key'=>$meta_key,'post_id'=>$id));
			if(!apply_filters('mad4b_scp_dynamic_content_meta_key_readable',$default_readable,$meta_key,$id,$post_type)) return new WP_Error('mad4b_dynamic_meta_read_denied','Requested meta key is not readable through the dynamic content surface.',array('meta_key'=>$meta_key));
		}
		$requested_taxonomies=isset($input['taxonomies'])&&is_array($input['taxonomies'])?array_values(array_unique(array_map('sanitize_key',$input['taxonomies']))):array();
		foreach($requested_taxonomies as $taxonomy){
			$tax=get_taxonomy($taxonomy);
			if(!$tax||!in_array($post_type,(array)$tax->object_type,true)) return new WP_Error('mad4b_dynamic_readback_taxonomy_invalid','Requested taxonomy is not attached to the target post type.',array('taxonomy'=>$taxonomy,'post_type'=>$post_type));
			$manage_cap=isset($tax->cap->manage_terms)?(string)$tax->cap->manage_terms:'manage_categories';
			$default_readable=!empty($tax->public)||!empty($tax->show_ui)||!empty($tax->show_in_rest)||current_user_can($manage_cap);
			if(!apply_filters('mad4b_scp_dynamic_content_taxonomy_readable',$default_readable,$tax,$post_type,$id)) return new WP_Error('mad4b_dynamic_taxonomy_read_denied','Requested taxonomy is not readable through the dynamic content surface.',array('taxonomy'=>$taxonomy,'post_type'=>$post_type));
		}
		$model=array('meta_keys'=>$meta_keys,'taxonomies'=>array_fill_keys($requested_taxonomies,array()));
		$s=$this->snapshot($id,$model);return array('contract'=>self::CONTRACT,'state'=>$s,'state_sha256'=>$this->state_sha256($s),'read_only'=>true,'mutation_performed'=>false);
	}
	private function state_scope_from_input(array $input){
		$meta_keys=array();
		if(isset($input['meta'])&&is_array($input['meta'])) $meta_keys=array_merge($meta_keys,array_keys($input['meta']));
		if(isset($input['meta_entries'])&&is_array($input['meta_entries'])) $meta_keys=array_merge($meta_keys,array_keys($input['meta_entries']));
		if(empty($meta_keys)&&isset($input['meta_keys'])&&is_array($input['meta_keys'])) $meta_keys=$input['meta_keys'];
		$meta_keys=array_values(array_unique(array_filter(array_map('strval',$meta_keys))));

		$taxonomies=array();
		if(isset($input['taxonomies'])&&is_array($input['taxonomies'])) $taxonomies=array_keys($input['taxonomies']);
		$taxonomies=array_values(array_unique(array_filter(array_map('sanitize_key',$taxonomies))));
		sort($meta_keys,SORT_STRING); sort($taxonomies,SORT_STRING);
		return array('meta_keys'=>$meta_keys,'taxonomies'=>$taxonomies);
	}

	private function input_from_state_scope(array $scope){
		return array(
			'meta'=>array_fill_keys(isset($scope['meta_keys'])&&is_array($scope['meta_keys'])?$scope['meta_keys']:array(),true),
			'taxonomies'=>array_fill_keys(isset($scope['taxonomies'])&&is_array($scope['taxonomies'])?$scope['taxonomies']:array(),array()),
		);
	}

	private function snapshot($id,array $input){
		$p=get_post($id);
		if(!$p) return array();
		$scope=$this->state_scope_from_input($input);
		$s=array(
			'post'=>array(
				'ID'=>(int)$p->ID,
				'post_type'=>(string)$p->post_type,
				'post_title'=>(string)$p->post_title,
				'post_content'=>(string)$p->post_content,
				'post_excerpt'=>(string)$p->post_excerpt,
				'post_status'=>(string)$p->post_status,
				'post_parent'=>(int)$p->post_parent,
				'post_author'=>(int)$p->post_author,
			),
			'meta'=>array(),
			'taxonomies'=>array(),
			'featured_media_id'=>(int)get_post_thumbnail_id($id),
			'scope'=>$scope,
		);
		foreach($scope['meta_keys'] as $k){
			$exists=metadata_exists('post',$id,$k);
			$s['meta'][$k]=array(
				'exists'=>(bool)$exists,
				'values'=>$exists?array_values(array_map(array($this,'normalize_meta_storage_value'),get_post_meta($id,$k,false))):array(),
			);
		}
		foreach($scope['taxonomies'] as $tax){
			$ids=wp_get_object_terms($id,$tax,array('fields'=>'ids'));
			$ids=is_wp_error($ids)?array():array_map('intval',$ids);
			sort($ids,SORT_NUMERIC);
			$s['taxonomies'][$tax]=array_values($ids);
		}
		return $s;
	}

	private function restore_snapshot_state($id,array $state){
		if($id<1||empty($state['post'])||!is_array($state['post'])) return new WP_Error('mad4b_dynamic_restore_state_invalid','Snapshot state is incomplete.');
		$current=get_post($id);
		if(!$current||!current_user_can('edit_post',$id)) return new WP_Error('mad4b_dynamic_restore_post_denied','Current user cannot restore the target post.');
		$obj=get_post_type_object($current->post_type);
		$desired_status=isset($state['post']['post_status'])?sanitize_key((string)$state['post']['post_status']):'';
		if(in_array($desired_status,array('publish','private'),true)){
			$publish_cap=$obj&&isset($obj->cap->publish_posts)?(string)$obj->cap->publish_posts:'publish_posts';
			if(!current_user_can($publish_cap)) return new WP_Error('mad4b_dynamic_restore_publish_denied','Current user cannot restore the recorded published/private state.');
		}
		$desired_author=isset($state['post']['post_author'])?absint($state['post']['post_author']):0;
		if($desired_author>0&&$desired_author!==get_current_user_id()){
			$edit_others=$obj&&isset($obj->cap->edit_others_posts)?(string)$obj->cap->edit_others_posts:'edit_others_posts';
			if(!current_user_can($edit_others)) return new WP_Error('mad4b_dynamic_restore_author_denied','Current user cannot restore the recorded author.');
		}
		$desired_parent=isset($state['post']['post_parent'])?absint($state['post']['post_parent']):0;
		if($desired_parent>0&&!current_user_can('edit_post',$desired_parent)) return new WP_Error('mad4b_dynamic_restore_parent_denied','Current user cannot restore the recorded parent relationship.');
		$arr=$state['post'];
		$arr['ID']=$id;
		unset($arr['post_type']);
		$r=wp_update_post(wp_slash($arr),true);
		if(is_wp_error($r)) return $r;

		foreach(isset($state['meta'])&&is_array($state['meta'])?$state['meta']:array() as $key=>$meta_state){
			if(!current_user_can('edit_post_meta',$id,(string)$key)) return new WP_Error('mad4b_dynamic_meta_restore_denied','Current user cannot restore a recorded meta key.',array('meta_key'=>(string)$key));
			delete_post_meta($id,(string)$key);
			if(!is_array($meta_state)||empty($meta_state['exists'])) continue;
			foreach(isset($meta_state['values'])&&is_array($meta_state['values'])?$meta_state['values']:array() as $value){
				$added=add_post_meta($id,(string)$key,$value,false);
				if(false===$added) return new WP_Error('mad4b_dynamic_meta_restore_failed','A meta value could not be restored.',array('meta_key'=>(string)$key));
			}
		}
		foreach(isset($state['taxonomies'])&&is_array($state['taxonomies'])?$state['taxonomies']:array() as $tax=>$ids){
			$taxonomy=sanitize_key((string)$tax);$tax_obj=get_taxonomy($taxonomy);if(!$tax_obj) return new WP_Error('mad4b_dynamic_taxonomy_restore_invalid','Recorded taxonomy is no longer registered.',array('taxonomy'=>$taxonomy));$assign_cap=isset($tax_obj->cap->assign_terms)?(string)$tax_obj->cap->assign_terms:'edit_posts';if(!current_user_can($assign_cap)) return new WP_Error('mad4b_dynamic_taxonomy_restore_denied','Current user cannot restore recorded taxonomy assignments.',array('taxonomy'=>$taxonomy));
			$x=wp_set_object_terms($id,array_map('intval',(array)$ids),$taxonomy,false);
			if(is_wp_error($x)) return $x;
		}
		$desired_media=isset($state['featured_media_id'])?absint($state['featured_media_id']):0;
		$current_media=(int)get_post_thumbnail_id($id);
		if($desired_media>0){
			if('attachment'!==get_post_type($desired_media)) return new WP_Error('mad4b_dynamic_featured_media_restore_invalid','Recorded featured media is no longer an attachment.');
			if($current_media!==$desired_media&&!set_post_thumbnail($id,$desired_media)) return new WP_Error('mad4b_dynamic_featured_media_restore_failed','Featured media could not be restored.');
		}elseif($current_media>0){
			delete_post_thumbnail($id);
		}
		clean_post_cache($id);
		return true;
	}

	private function local_compensate($mode,$id,$before){
		$mode=sanitize_key((string)$mode);
		if('create'===$mode){
			if($id>0&&get_post($id)&&!wp_delete_post($id,true)) return new WP_Error('mad4b_dynamic_compensation_delete_failed','Failed to remove the partially created post.');
			return array('compensated'=>true,'mode'=>'create');
		}
		if('update'===$mode&&is_array($before)){
			$r=$this->restore_snapshot_state($id,$before);
			if(is_wp_error($r)) return $r;
			$scope=isset($before['scope'])&&is_array($before['scope'])?$before['scope']:array();
			$readback=$this->snapshot($id,$this->input_from_state_scope($scope));
			if(!hash_equals($this->state_sha256($before),$this->state_sha256($readback))) return new WP_Error('mad4b_dynamic_compensation_verification_failed','Compensation completed but exact readback did not match the pre-mutation snapshot.');
			return array('compensated'=>true,'mode'=>'update');
		}
		return new WP_Error('mad4b_dynamic_compensation_state_invalid','Compensation state is invalid.');
	}

	private function failure_with_compensation($error,$mode,$id,$before){
		if(!is_wp_error($error)) $error=new WP_Error('mad4b_dynamic_operation_failed','Dynamic content operation failed.');
		$comp=$this->local_compensate($mode,$id,$before);
		if(is_wp_error($comp)){
			return new WP_Error(
				'mad4b_dynamic_compensation_failed',
				'Dynamic content operation failed and compensation could not be verified.',
				array(
					'original_error_code'=>$error->get_error_code(),
					'compensation_error_code'=>$comp->get_error_code(),
					'post_id'=>(int)$id,
					'mode'=>sanitize_key((string)$mode),
					'recovery_required'=>true,
				)
			);
		}
		$code=$error->get_error_code();
		$data=$error->get_error_data($code);
		if(!is_array($data)) $data=array();
		$data['mad4b_compensation']=$comp;
		$data['recovery_required']=false;
		$error->add_data($data,$code);
		return $error;
	}

	private function canonical_value($value){
		if(is_array($value)){
			$is_list=array_keys($value)===range(0,count($value)-1);
			if(!$is_list) ksort($value,SORT_STRING);
			foreach($value as $k=>$v) $value[$k]=$this->canonical_value($v);
		}
		return $value;
	}
	private function state_sha256($s){
		$canonical=$this->canonical_value($s);
		$json=wp_json_encode($canonical,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		return hash('sha256',false===$json?'null':$json);
	}


	private function pipeline_option_state(){
		$sentinel='__mad4b_dynamic_pipeline_missing__'.wp_generate_uuid4();
		$value=get_option(MAD4B_SCP_Dynamic_Content_Pipeline::OPTION,$sentinel);
		return array(
			'exists'=>$value!==$sentinel,
			'value'=>$value!==$sentinel&&is_array($value)?$value:array(),
		);
	}

	public function capture_reversible_state($ability,array $input){
		if(self::PIPELINE_UPDATE===(string)$ability){
			return array(
				'target_type'=>'dynamic-content-pipeline-settings',
				'target_id'=>MAD4B_SCP_Dynamic_Content_Pipeline::OPTION,
				'target'=>array('kind'=>'option','option'=>MAD4B_SCP_Dynamic_Content_Pipeline::OPTION),
				'state'=>$this->pipeline_option_state(),
			);
		}
		if(self::APPLY!==(string)$ability) return parent::capture_reversible_state($ability,$input);

		$v=$this->validate_bundle($input,true);
		if(is_wp_error($v)) return $v;
		$binding=$this->binding($v['post_type'],$v['operation_key']);

		if($v['mode']==='create'){
			$existing=$this->bound_post($v['post_type'],$binding);
			if(is_wp_error($existing)) return $existing;
			if($existing) return new WP_Error('mad4b_dynamic_binding_exists','operation_key already used.');
			return array(
				'target_type'=>'dynamic-content-create',
				'target_id'=>$binding,
				'target'=>array('mode'=>'create','binding'=>$binding,'post_type'=>$v['post_type']),
				'state'=>array('exists'=>false,'post_id'=>0),
			);
		}

		$scope=$this->state_scope_from_input($input);
		return array(
			'target_type'=>'dynamic-content-update',
			'target_id'=>(string)$v['post_id'],
			'target'=>array(
				'mode'=>'update',
				'post_id'=>$v['post_id'],
				'post_type'=>$v['post_type'],
				'scope'=>$scope,
			),
			'state'=>$this->snapshot($v['post_id'],$input),
		);
	}

	public function read_reversible_state($ability,array $target){
		if(self::PIPELINE_UPDATE===(string)$ability){ if(!current_user_can('manage_options')) return new WP_Error('mad4b_dynamic_pipeline_admin_required','Administrator capability is required.'); return $this->pipeline_option_state(); }
		if(self::APPLY!==(string)$ability) return parent::read_reversible_state($ability,$target);

		$mode=isset($target['mode'])?sanitize_key((string)$target['mode']):'';
		if('create'===$mode){
			$id=$this->bound_post(
				isset($target['post_type'])?sanitize_key((string)$target['post_type']):'',
				isset($target['binding'])?(string)$target['binding']:''
			);
			if(is_wp_error($id)) return $id;
			return array('exists'=>$id>0,'post_id'=>(int)$id);
		}

		if('update'===$mode){
			$id=isset($target['post_id'])?absint($target['post_id']):0;
			if($id<1||!get_post($id)) return array('exists'=>false,'post_id'=>0);
			$scope=isset($target['scope'])&&is_array($target['scope'])?$target['scope']:array();
			return $this->snapshot($id,$this->input_from_state_scope($scope));
		}

		return new WP_Error('mad4b_dynamic_reversible_target_invalid','Dynamic content reversible target mode is invalid.');
	}

	public function restore_reversible_state($ability,array $target,array $state,array $record){
		if(self::PIPELINE_UPDATE===(string)$ability){
			if(!current_user_can('manage_options')) return new WP_Error('mad4b_dynamic_pipeline_admin_required','Administrator capability is required.');
			if(!empty($state['exists'])){
				$value=isset($state['value'])&&is_array($state['value'])?$state['value']:array();
				update_option(MAD4B_SCP_Dynamic_Content_Pipeline::OPTION,$value,false);
			}else{
				delete_option(MAD4B_SCP_Dynamic_Content_Pipeline::OPTION);
			}
			$read=$this->pipeline_option_state();
			if($this->state_sha256($read)!==$this->state_sha256($state)) return new WP_Error('mad4b_dynamic_pipeline_rollback_failed','Pipeline settings rollback failed exact existence/value readback.');
			return array('restored'=>true,'settings'=>MAD4B_SCP_Dynamic_Content_Pipeline::effective(),'option_exists'=>!empty($read['exists']));
		}
		if(self::APPLY!==(string)$ability) return parent::restore_reversible_state($ability,$target,$state,$record);

		$mode=isset($target['mode'])?sanitize_key((string)$target['mode']):'';
		if('create'===$mode){
			if(!empty($state['exists'])) return new WP_Error('mad4b_dynamic_rollback_state_invalid','Create rollback requires an originally absent target.');
			$id=$this->bound_post(
				isset($target['post_type'])?sanitize_key((string)$target['post_type']):'',
				isset($target['binding'])?(string)$target['binding']:''
			);
			if(is_wp_error($id)) return $id;
			if($id>0){
				if(!current_user_can('delete_post',$id)) return new WP_Error('mad4b_dynamic_rollback_delete_denied','Current user cannot delete the created post.');
				if(!wp_delete_post($id,true)) return new WP_Error('mad4b_dynamic_rollback_delete_failed','Created post rollback failed.');
			}
			return array('restored'=>true,'post_deleted'=>$id>0);
		}

		if('update'===$mode){
			$id=isset($target['post_id'])?absint($target['post_id']):0;
			if($id<1||!get_post($id)) return new WP_Error('mad4b_dynamic_rollback_target_missing','Update rollback target no longer exists.');
			if(!current_user_can('edit_post',$id)) return new WP_Error('mad4b_dynamic_rollback_edit_denied','Current user cannot restore the updated post.');
			$r=$this->restore_snapshot_state($id,$state);
			if(is_wp_error($r)) return $r;
			$scope=isset($target['scope'])&&is_array($target['scope'])?$target['scope']:array();
			$readback=$this->snapshot($id,$this->input_from_state_scope($scope));
			if(!hash_equals($this->state_sha256($state),$this->state_sha256($readback))) return new WP_Error('mad4b_dynamic_rollback_verification_failed','Rollback completed but exact readback did not match the recorded before-state.');
			return array('restored'=>true,'post_id'=>$id);
		}

		return new WP_Error('mad4b_dynamic_rollback_target_invalid','Dynamic content rollback target mode is invalid.');
	}

}
