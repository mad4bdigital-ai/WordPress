<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only JetFormBuilder form inventory. */
final class MAD4B_SCP_JetFormBuilder_Adapter extends MAD4B_SCP_Adapter_Base {
	const POST_TYPE = 'jet-form-builder';
	public function id(){ return 'jetformbuilder'; }
	public function label(){ return 'JetFormBuilder'; }
	public function is_available(){ return post_type_exists( self::POST_TYPE ) || class_exists( 'Jet_Form_Builder\Plugin' ) || function_exists( 'jet_form_builder' ); }
	public function ability_names(){ return array('read'=>array('jetformbuilder/status','jetformbuilder/list-forms','jetformbuilder/get-form'),'content'=>array(),'admin'=>array()); }
	protected function mutation_requires_certification(){ return false; }
	protected function provider_certification($available){ return null; }
	public function register_abilities(){
		$read=array('MAD4B_SCP_Policy','can_read');
		$this->add_ability('jetformbuilder/status','Read JetFormBuilder Governed Status','status',$read);
		$this->add_ability('jetformbuilder/list-forms','List JetFormBuilder Forms','list_forms',$read,$this->schema(array('limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>100))));
		$this->add_ability('jetformbuilder/get-form','Read JetFormBuilder Form Metadata','get_form',$read,$this->schema(array('id'=>array('type'=>'integer','minimum'=>1)),array('id')));
	}
	public function status(){
		return array('contract'=>'mad4b.jetformbuilder-read-adapter.v1','id'=>$this->id(),'available'=>$this->is_available(),'post_type'=>self::POST_TYPE,'abilities'=>$this->ability_names(),'authority_mode'=>'read_only_non_authorizing','submission_values_exposed'=>false,'mutation_exposed'=>false);
	}
	public function list_forms($input=array()){
		if(!$this->is_available()) return $this->unavailable_error();
		$limit=isset($input['limit'])?absint($input['limit']):25; $limit=max(1,min(100,$limit));
		$ids=get_posts(array('post_type'=>self::POST_TYPE,'post_status'=>array('publish','draft','pending','private','future'),'posts_per_page'=>$limit,'orderby'=>'ID','order'=>'DESC','fields'=>'ids','no_found_rows'=>true,'suppress_filters'=>false));
		$items=array(); foreach((array)$ids as $id){$post=get_post((int)$id);if($post instanceof WP_Post)$items[]=$this->summary($post);}
		return array('contract'=>'mad4b.jetformbuilder-form-list.v1','items'=>$items,'count'=>count($items),'limit'=>$limit,'form_content_exposed'=>false,'submission_values_exposed'=>false);
	}
	public function get_form($input){
		$id=isset($input['id'])?absint($input['id']):0; $post=$id?get_post($id):null;
		if(!$post instanceof WP_Post || self::POST_TYPE!==$post->post_type) return new WP_Error('mad4b_jetformbuilder_form_not_found','The requested JetFormBuilder form does not exist.');
		$out=$this->summary($post); $out['block_types']=$this->block_types((string)$post->post_content); $out['content_sha256']=hash('sha256',(string)$post->post_content);
		return array('contract'=>'mad4b.jetformbuilder-form-metadata.v1','form'=>$out,'form_content_exposed'=>false,'submission_values_exposed'=>false);
	}
	private function summary($post){
		return array('id'=>(int)$post->ID,'title'=>sanitize_text_field((string)$post->post_title),'status'=>sanitize_key((string)$post->post_status),'modified_gmt'=>sanitize_text_field((string)$post->post_modified_gmt));
	}
	private function block_types($content){
		if(!function_exists('parse_blocks')) return array();
		$counts=array(); $walk=function($blocks) use (&$walk,&$counts){foreach((array)$blocks as $block){$name=isset($block['blockName'])?(string)$block['blockName']:'';if(''!==$name)$counts[$name]=isset($counts[$name])?$counts[$name]+1:1;if(!empty($block['innerBlocks']))$walk($block['innerBlocks']);}};
		$walk(parse_blocks($content)); ksort($counts,SORT_STRING); return $counts;
	}
}

/** Read-only Fluent Forms inventory through the documented PHP Forms API. */
final class MAD4B_SCP_FluentForms_Adapter extends MAD4B_SCP_Adapter_Base {
	public function id(){ return 'fluentforms'; }
	public function label(){ return 'Fluent Forms'; }
	public function is_available(){ return function_exists('fluentFormApi') || class_exists('FluentForm\App\Models\Form'); }
	public function ability_names(){ return array('read'=>array('fluentforms/status','fluentforms/list-forms','fluentforms/get-form'),'content'=>array(),'admin'=>array()); }
	protected function mutation_requires_certification(){ return false; }
	protected function provider_certification($available){ return null; }
	public function register_abilities(){
		$read=array('MAD4B_SCP_Policy','can_read');
		$this->add_ability('fluentforms/status','Read Fluent Forms Governed Status','status',$read);
		$this->add_ability('fluentforms/list-forms','List Fluent Forms Definitions','list_forms',$read,$this->schema(array('limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>100))));
		$this->add_ability('fluentforms/get-form','Read Fluent Form Metadata','get_form',$read,$this->schema(array('id'=>array('type'=>'integer','minimum'=>1)),array('id')));
	}
	public function status(){
		return array('contract'=>'mad4b.fluentforms-read-adapter.v1','id'=>$this->id(),'available'=>$this->is_available(),'abilities'=>$this->ability_names(),'authority_mode'=>'read_only_non_authorizing','entries_exposed'=>false,'submission_values_exposed'=>false,'mutation_exposed'=>false);
	}
	public function list_forms($input=array()){
		$api=$this->forms_api(); if(is_wp_error($api)) return $api;
		$limit=isset($input['limit'])?absint($input['limit']):25; $limit=max(1,min(100,$limit));
		try{$result=$api->forms(array('status'=>'all','sort_column'=>'id','sort_by'=>'DESC','per_page'=>$limit,'page'=>1),false);}catch(Throwable $e){return new WP_Error('mad4b_fluentforms_list_failed','Fluent Forms definitions could not be read through the provider API.');}
		$records=is_array($result)&&isset($result['data'])&&is_array($result['data'])?$result['data']:(is_array($result)?$result:array());
		$items=array(); foreach($records as $form)$items[]=$this->summary($form);
		return array('contract'=>'mad4b.fluentforms-form-list.v1','items'=>$items,'count'=>count($items),'limit'=>$limit,'entries_exposed'=>false,'submission_values_exposed'=>false);
	}
	public function get_form($input){
		$id=isset($input['id'])?absint($input['id']):0; if($id<1)return new WP_Error('mad4b_fluentforms_form_id_invalid','A positive Fluent Forms form ID is required.');
		$api=$this->forms_api(); if(is_wp_error($api))return $api;
		try{$form=$api->find($id);}catch(Throwable $e){return new WP_Error('mad4b_fluentforms_read_failed','The Fluent Forms definition could not be read through the provider API.');}
		if(!$form)return new WP_Error('mad4b_fluentforms_form_not_found','The requested Fluent Forms definition does not exist.');
		$summary=$this->summary($form); $fields=$this->value($form,'form_fields',''); $summary['definition_sha256']=hash('sha256',is_scalar($fields)?(string)$fields:wp_json_encode($fields));
		return array('contract'=>'mad4b.fluentforms-form-metadata.v1','form'=>$summary,'form_fields_exposed'=>false,'entries_exposed'=>false,'submission_values_exposed'=>false);
	}
	private function forms_api(){
		if(!function_exists('fluentFormApi'))return new WP_Error('mad4b_fluentforms_api_unavailable','The documented Fluent Forms PHP API is unavailable.');
		try{$api=fluentFormApi('forms');}catch(Throwable $e){$api=null;}
		return is_object($api)&&method_exists($api,'forms')&&method_exists($api,'find')?$api:new WP_Error('mad4b_fluentforms_api_contract_unavailable','The Fluent Forms PHP API contract is not compatible with this adapter.');
	}
	private function summary($form){
		return array(
			'id'=>(int)$this->value($form,'id',0),
			'title'=>sanitize_text_field((string)$this->value($form,'title','')),
			'status'=>sanitize_key((string)$this->value($form,'status','')),
			'type'=>sanitize_key((string)$this->value($form,'type','')),
			'created_at'=>sanitize_text_field((string)$this->value($form,'created_at','')),
			'updated_at'=>sanitize_text_field((string)$this->value($form,'updated_at','')),
		);
	}
	private function value($record,$key,$default=null){
		if(is_array($record))return array_key_exists($key,$record)?$record[$key]:$default;
		if(is_object($record)){try{return isset($record->$key)?$record->$key:$default;}catch(Throwable $e){return $default;}}
		return $default;
	}
}
