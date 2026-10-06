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
	public static function contracts() {
		return array(
			'providers'=>array('method'=>'providers'),
			'components'=>array('method'=>'components'),
			'abilities'=>array('method'=>'abilities'),
			'schemas'=>array('method'=>'schemas','source'=>'abilities'),
			'operations'=>array('method'=>'operations'),
			'plugins'=>array('method'=>'plugins'),
			'rest_routes'=>array('method'=>'rest_routes'),
			'post_types'=>array('method'=>'post_types'),
			'taxonomies'=>array('method'=>'taxonomies'),
			'meta_keys'=>array('method'=>'meta_keys'),
			'hooks'=>array('method'=>'hooks'),
			'cron_hooks'=>array('method'=>'cron_hooks'),
			'admin_routes'=>array('method'=>'admin_routes'),
			'mcp_descriptors'=>array('method'=>'mcp_descriptors','source'=>'abilities'),
			'symbols'=>array('method'=>'symbols'),
			'database_tables'=>array('method'=>'database_tables'),
		);
	}

	public static function collection_status( $kind, $emitted_count, $observed_count, $max_items, $source_incomplete = false ) {
		$lifecycle='ready';
		if('rest_routes'===$kind && (!function_exists('did_action') || did_action('rest_api_init')<=0)) $lifecycle='not_initialized';
		elseif('providers'===$kind && !class_exists('MAD4B_SCP_Provider_Contracts')) $lifecycle='unavailable';
		elseif('operations'===$kind && !class_exists('MAD4B_SCP_Operation_Registry')) $lifecycle='unavailable';
		elseif('meta_keys'===$kind && !function_exists('get_registered_meta_keys')) $lifecycle='unavailable';
		elseif('admin_routes'===$kind && !class_exists('MAD4B_SCP_Admin_Route_Registry')) $lifecycle='unavailable';
		$observed_count=max(0,(int)$observed_count);
		$emitted_count=max(0,(int)$emitted_count);
		$truncated=$observed_count>$emitted_count;
		return array(
			'kind'=>(string)$kind,
			'observed_count'=>$observed_count,
			'emitted_count'=>$emitted_count,
			'max_items'=>(int)$max_items,
			'lifecycle'=>$lifecycle,
			'truncated'=>$truncated,
			'source_incomplete'=>(bool)$source_incomplete,
			'count_observation_complete'=>!$source_incomplete,
			'trustworthy_for_absence'=>'ready'===$lifecycle && !$truncated && !$source_incomplete,
		);
	}

	public static function observed_count( $kind, array $rows, array $nodes ) {
		switch ( $kind ) {
			case 'abilities':
				return count( self::ability_names() );
			case 'schemas':
				return 2 * count( isset($nodes['abilities'])&&is_array($nodes['abilities'])?$nodes['abilities']:array() );
			case 'operations':
				$status=class_exists('MAD4B_SCP_Operation_Registry')&&method_exists('MAD4B_SCP_Operation_Registry','status')?MAD4B_SCP_Operation_Registry::status():array();
				return is_array($status)&&isset($status['operations'])&&is_array($status['operations'])?count($status['operations']):count($rows);
			case 'providers':
				$contracts=class_exists('MAD4B_SCP_Provider_Contracts')&&method_exists('MAD4B_SCP_Provider_Contracts','all')?MAD4B_SCP_Provider_Contracts::all():array();
				return is_array($contracts)?count($contracts):count($rows);
			case 'components':
				$contracts=class_exists('MAD4B_SCP_Provider_Contracts')&&method_exists('MAD4B_SCP_Provider_Contracts','all')?MAD4B_SCP_Provider_Contracts::all():array();
				$count=0;
				foreach(is_array($contracts)?$contracts:array() as $contract) $count+=is_array($contract)&&!empty($contract['components'])&&is_array($contract['components'])?count($contract['components']):1;
				return $count;
			case 'plugins':
				if(!function_exists('get_plugins')) require_once ABSPATH.'wp-admin/includes/plugin.php';
				$plugins=get_plugins();
				return is_array($plugins)?count($plugins):count($rows);
			case 'rest_routes':
				if(!function_exists('did_action')||did_action('rest_api_init')<=0||!function_exists('rest_get_server')) return 0;
				$routes=rest_get_server()->get_routes();
				return is_array($routes)?count($routes):count($rows);
			case 'post_types':
				global $wp_post_types;
				return is_array($wp_post_types)?count($wp_post_types):count($rows);
			case 'taxonomies':
				global $wp_taxonomies;
				return is_array($wp_taxonomies)?count($wp_taxonomies):count($rows);
			case 'meta_keys':
				if(!function_exists('get_registered_meta_keys')) return 0;
				$count=0;
				foreach(array('post','term','user','comment') as $type){$registered=get_registered_meta_keys($type);if(is_array($registered))$count+=count($registered);}
				return $count;
			case 'hooks':
				global $wp_filter;
				return is_array($wp_filter)?count($wp_filter):count($rows);
			case 'cron_hooks':
				if(!function_exists('_get_cron_array')) return 0;
				$cron=_get_cron_array(); $names=array();
				foreach(is_array($cron)?$cron:array() as $entries) foreach(is_array($entries)?$entries:array() as $hook=>$instances) $names[(string)$hook]=true;
				return count($names);
			case 'admin_routes':
				$routes=class_exists('MAD4B_SCP_Admin_Route_Registry')?MAD4B_SCP_Admin_Route_Registry::routes():array();
				return is_array($routes)?count($routes):count($rows);
			case 'mcp_descriptors':
				$count=0;
				foreach(isset($nodes['abilities'])&&is_array($nodes['abilities'])?$nodes['abilities']:array() as $row) if(is_array($row)&&!empty($row['mcp_surface'])) $count++;
				return $count;
			case 'symbols':
				$count=0;
				foreach(get_declared_classes() as $name) if(0===strpos($name,'MAD4B_')) $count++;
				$functions=get_defined_functions();
				foreach(isset($functions['user'])&&is_array($functions['user'])?$functions['user']:array() as $name) if(0===strpos($name,'mad4b_')) $count++;
				return $count;
			case 'database_tables':
				global $wpdb;
				$names=array();
				if(is_object($wpdb)&&method_exists($wpdb,'tables')) $names=array_merge($names,(array)$wpdb->tables('all'));
				if(class_exists('MAD4B_SCP_Schema')) $names=array_merge($names,array_values((array)MAD4B_SCP_Schema::tables()));
				return count(array_unique(array_filter(array_map('strval',$names))));
			default:
				return count($rows);
		}
	}

	private static function ability_names() {
		$names=array();
		if(function_exists('wp_get_abilities')){
			$registered=wp_get_abilities();
			if(is_array($registered)) $names=array_merge($names,array_keys($registered));
		}
		if(class_exists('MAD4B_SCP_Servers')){
			foreach(array('mad4b-read','mad4b-chatgpt','mad4b-content','mad4b-write','mad4b-admin','mad4b-enrollment','mad4b-developer','mad4b-developer-breakglass','mad4b-breakglass') as $server){
				$server_names=MAD4B_SCP_Servers::core_tools($server);
				if(is_array($server_names)) $names=array_merge($names,$server_names);
			}
			if(method_exists('MAD4B_SCP_Servers','chatgpt_full_catalog_candidates')){
				$candidates=MAD4B_SCP_Servers::chatgpt_full_catalog_candidates();
				if(is_array($candidates)) $names=array_merge($names,$candidates);
			}
		}
		if(class_exists('MAD4B_SCP_Adapter_Registry')){
			$registry=MAD4B_SCP_Adapter_Registry::instance();
			foreach(array('read','content','admin','write') as $surface){
				$surface_names=$registry->ability_names($surface);
				if(is_array($surface_names)) $names=array_merge($names,$surface_names);
			}
		}
		$names=array_values(array_unique(array_filter(array_map('strval',$names))));
		sort($names,SORT_STRING);
		return $names;
	}
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
