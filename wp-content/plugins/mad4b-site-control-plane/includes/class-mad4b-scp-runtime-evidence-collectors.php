<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded descriptive collectors used by the runtime evidence graph.
 *
 * These collectors inspect already-registered runtime structures only. They
 * never execute callbacks, endpoints or unknown plugin code and never create
 * authority.
 */
final class MAD4B_SCP_Runtime_Evidence_Collectors {
	public static function post_types( $max ) {
		global $wp_post_types; $out=array();
		foreach(is_array($wp_post_types)?$wp_post_types:array() as $name=>$object){
			$out[]=array('id'=>(string)$name,'kind'=>'post_type','public'=>is_object($object)?(bool)$object->public:false,'show_ui'=>is_object($object)?(bool)$object->show_ui:false);
			if(count($out)>=(int)$max) break;
		}
		return $out;
	}

	public static function taxonomies( $max ) {
		global $wp_taxonomies; $out=array();
		foreach(is_array($wp_taxonomies)?$wp_taxonomies:array() as $name=>$object){
			$types=is_object($object)&&isset($object->object_type)&&is_array($object->object_type)?array_values(array_map('strval',$object->object_type)):array();
			sort($types,SORT_STRING);
			$out[]=array('id'=>(string)$name,'kind'=>'taxonomy','object_types'=>$types,'public'=>is_object($object)?(bool)$object->public:false,'show_ui'=>is_object($object)?(bool)$object->show_ui:false);
			if(count($out)>=(int)$max) break;
		}
		return $out;
	}

	public static function meta_keys( $max ) {
		if(!function_exists('get_registered_meta_keys')) return array();
		$out=array();
		foreach(array('post','term','user','comment') as $object_type){
			$rows=get_registered_meta_keys($object_type);
			if(!is_array($rows)) continue;
			ksort($rows,SORT_STRING);
			foreach($rows as $key=>$args){
				$sensitive=class_exists('MAD4B_SCP_Structural_Redaction')&&MAD4B_SCP_Structural_Redaction::sensitive_key($key);
				$out[]=array('id'=>$object_type.':'.($sensitive?hash('sha256',(string)$key):(string)$key),'kind'=>'meta_key','object_type'=>$object_type,'key_redacted'=>$sensitive,'type'=>is_array($args)&&isset($args['type'])?(string)$args['type']:'','single'=>is_array($args)&&isset($args['single'])?(bool)$args['single']:null);
				if(count($out)>=(int)$max) break 2;
			}
		}
		return $out;
	}

	public static function hooks( $max ) {
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
			if(count($out)>=(int)$max) break;
		}
		return $out;
	}

	public static function mcp_descriptors( array $abilities, $max ) {
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
			if(count($out)>=(int)$max) break;
		}
		return $out;
	}

	public static function symbols( $max ) {
		$names=array();
		foreach(get_declared_classes() as $name) if(0===strpos($name,'MAD4B_')) $names[]='class:'.$name;
		$functions=get_defined_functions();
		foreach(isset($functions['user'])&&is_array($functions['user'])?$functions['user']:array() as $name) if(0===strpos($name,'mad4b_')) $names[]='function:'.$name;
		$names=array_values(array_unique($names)); sort($names,SORT_STRING); $out=array();
		foreach(array_slice($names,0,(int)$max) as $name) $out[]=array('id'=>$name,'kind'=>'symbol','invoked'=>false);
		return $out;
	}
}
