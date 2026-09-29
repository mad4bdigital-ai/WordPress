<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Semantic_Diff {
	const CONTRACT='mad4b.dynamic-semantic-diff.v1';
	const MAX_PREVIEW=120;

	public static function compare( array $before, array $after, array $policy=array() ) {
		$entries=array();
		self::walk('', $before, $after, $entries, $policy, 0);
		return array('contract'=>self::CONTRACT,'changed'=>!empty($entries),'count'=>count($entries),'entries'=>array_slice($entries,0,500),'truncated'=>count($entries)>500,'read_only'=>true,'mutation_performed'=>false);
	}

	private static function walk($path,$before,$after,array &$entries,array $policy,$depth){
		if($depth>8||count($entries)>500) return;
		if($before===$after) return;
		if(is_array($before)&&is_array($after)){
			$keys=array_values(array_unique(array_merge(array_keys($before),array_keys($after))));
			sort($keys,SORT_STRING);
			foreach($keys as $key){
				$has_before=array_key_exists($key,$before); $has_after=array_key_exists($key,$after);
				$p=$path===''?(string)$key:$path.'.'.$key;
				if(!$has_before||!$has_after){ self::add($p,$has_before?$before[$key]:null,$has_after?$after[$key]:null,$has_before,$has_after,$entries,$policy); continue; }
				self::walk($p,$before[$key],$after[$key],$entries,$policy,$depth+1);
			}
			return;
		}
		self::add($path,$before,$after,true,true,$entries,$policy);
	}

	private static function add($path,$before,$after,$before_present,$after_present,array &$entries,array $policy){
		$redacted=self::must_redact($path,$policy);
		$entries[]=array(
			'path'=>$path,
			'changed'=>true,
			'before_present'=>(bool)$before_present,
			'after_present'=>(bool)$after_present,
			'before'=>self::summarize($before,$redacted),
			'after'=>self::summarize($after,$redacted),
			'redacted'=>$redacted,
		);
	}
	private static function must_redact($path,array $policy){
		if(0===strpos($path,'meta.')){
			$key=substr($path,5);
			$allow=isset($policy['safe_meta_keys'])&&is_array($policy['safe_meta_keys'])?array_map('strval',$policy['safe_meta_keys']):array();
			return !in_array($key,$allow,true);
		}
		return false;
	}
	private static function summarize($value,$redacted){
		if($redacted) return array('display'=>'[redacted]');
		if(is_string($value)){
			return array('sha256'=>hash('sha256',$value),'length'=>strlen($value),'preview'=>substr($value,0,self::MAX_PREVIEW));
		}
		if(is_scalar($value)||is_null($value)) return array('value'=>$value);
		$json=wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		return array('sha256'=>is_string($json)?hash('sha256',$json):'','length'=>is_string($json)?strlen($json):0);
	}
}
