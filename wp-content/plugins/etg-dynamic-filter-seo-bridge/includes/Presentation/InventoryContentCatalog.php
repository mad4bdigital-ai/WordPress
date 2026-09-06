<?php
namespace ETG\DynamicFilterSEOBridge\Presentation;

final class InventoryContentCatalog {
    const CONTRACT='etg.dfsb.inventory-content-catalog.v2';
    const MAX_TOKENS=1200;
    const MAX_META_TERMS=10;
    const MAX_META_KEYS=50;

    public function build(array $snapshot,array $profiles=array()):array{
        $inventory=(array)($snapshot['inventory']??array());$tokens=array();
        $this->token($tokens,'title','Filter Title','text','composed');
        $this->token($tokens,'intro','Filter Intro','html','composed');
        $this->token($tokens,'keyword','Focus Keyword','text','composed');
        $this->token($tokens,'result_count','Result Count','text','runtime');
        $this->token($tokens,'result_summary','Result Summary','text','runtime');
        $this->token($tokens,'breadcrumb','Breadcrumb','text','composed');
        $this->token($tokens,'gallery_ids','Gallery IDs','text','media');
        $this->token($tokens,'image_id','Primary Image ID','image','media');
        $this->token($tokens,'image_url','Primary Image URL','url','media');
        foreach(array('language','profile_id','provider','query_id','query_builder_query_id','state_transport','ajax_only') as $key){$this->token($tokens,'context:'.$key,ucwords(str_replace('_',' ',$key)),'text','context');}
        foreach(array('current','archive') as $key){$this->token($tokens,'url:'.$key,ucfirst($key).' URL','url','url');}

        $roleTaxonomies=array();
        foreach((array)$profiles as $profile){if(!is_array($profile)){continue;}foreach((array)($profile['taxonomy_rules']??array()) as $taxonomy=>$rule){$taxonomy=sanitize_key((string)$taxonomy);$role=sanitize_key((string)($rule['role']??$taxonomy));if(''===$taxonomy||''===$role){continue;}$roleTaxonomies[$role][$taxonomy]=true;}}
        if(!$roleTaxonomies){foreach((array)($inventory['taxonomies']??array()) as $taxonomy=>$record){$taxonomy=sanitize_key((string)$taxonomy);if(''!==$taxonomy){$roleTaxonomies[$taxonomy][$taxonomy]=true;}}}
        $fields=array('name'=>'Term Name','slug'=>'Term Slug','description'=>'Description','short_description'=>'Short Description','seo_title'=>'SEO Title','meta_description'=>'Meta Description','focus_keyword'=>'Focus Keyword','image_id'=>'Image ID','image_url'=>'Image URL','count'=>'Term Count','location_level'=>'Location Level');
        foreach($roleTaxonomies as $role=>$taxonomyMap){
            foreach($fields as $field=>$label){$type=in_array($field,array('description','short_description'),true)?'html':('image_url'===$field?'url':'text');$this->token($tokens,'term:'.$role.':'.$field,ucwords(str_replace('_',' ',$role)).' — '.$label,$type,'term');}
            foreach(array('names'=>'Selected Names','slugs'=>'Selected Slugs','count'=>'Selected Count','descriptions'=>'Selected Descriptions','short_descriptions'=>'Selected Short Descriptions') as $field=>$label){$type=in_array($field,array('descriptions','short_descriptions'),true)?'html':'text';$this->token($tokens,'terms:'.$role.':'.$field,ucwords(str_replace('_',' ',$role)).' — '.$label,$type,'ajax-term-set');}
            foreach(array_keys($taxonomyMap) as $taxonomy){foreach($this->metaKeys($taxonomy) as $key){$this->token($tokens,'termmeta:'.$role.':'.$key,ucwords(str_replace('_',' ',$role)).' Meta — '.$key,'text','term-meta');}}
        }
        $topology=(array)($inventory['elementor_topology']??array());foreach(array_slice((array)($topology['bindings']??array()),0,100) as $binding){if(!is_array($binding)||'verified'!==(string)($binding['status']??'')){continue;}$providerId=sanitize_key((string)($binding['provider_query_id']??''));$qb=sanitize_key((string)($binding['query_builder_custom_query_id']??''));if($providerId){$this->token($tokens,'topology:'.$providerId.':query_builder_query_id','Topology '.$providerId.' Query Builder ID','text','topology',array('query_builder_query_id'=>$qb,'internal_id'=>(string)($binding['query_builder_internal_id']??''),'post_types'=>(array)($binding['post_types']??array())));}}
        ksort($tokens,SORT_STRING);$tokens=array_slice($tokens,0,self::MAX_TOKENS,true);
        return array('contract'=>self::CONTRACT,'authorizing'=>false,'read_only'=>true,'profile_mutation'=>false,'supports_ajax_runtime_state'=>true,'supports_elementor_media_tags'=>true,'snapshot_fingerprint'=>(string)($snapshot['snapshot_fingerprint']??''),'token_count'=>count($tokens),'tokens'=>$tokens);
    }

    private function token(array &$tokens,string $token,string $label,string $type,string $source,array $evidence=array()):void{if(count($tokens)>=self::MAX_TOKENS){return;}$token=strtolower(trim($token));if(''===$token||isset($tokens[$token])){return;}$tokens[$token]=array('token'=>$token,'placeholder'=>'{{'.$token.'}}','label'=>$label,'type'=>$type,'source'=>$source,'evidence'=>$evidence,'authorizing'=>false);}

    private function metaKeys(string $taxonomy):array{
        if(!function_exists('get_terms')||!function_exists('get_term_meta')){return array();}
        try{$ids=get_terms(array('taxonomy'=>$taxonomy,'hide_empty'=>false,'number'=>self::MAX_META_TERMS,'fields'=>'ids','orderby'=>'term_id','order'=>'ASC'));if(is_wp_error($ids)||!is_array($ids)){return array();}$keys=array();foreach($ids as $id){$all=get_term_meta((int)$id);if(!is_array($all)){continue;}foreach(array_keys($all) as $key){$key=sanitize_key((string)$key);if(''===$key){continue;}$keys[$key]=true;if(count($keys)>=self::MAX_META_KEYS){break 2;}}}ksort($keys,SORT_STRING);return array_keys($keys);}catch(\Throwable $e){return array();}
    }
}
