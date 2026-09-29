<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Runtime_Metrics {
	const CONTRACT='mad4b.dynamic-runtime-metrics.v1';
	const MAX_VALUE=2147483647;
	const MAX_NAMES=50;
	const MAX_HOURS=720;

	public static function record($name,$value=1){
		global $wpdb;
		$name=strtolower(trim((string)$name));
		if(1!==preg_match('/^[a-z0-9_.-]{1,96}$/',$name)) return new WP_Error('mad4b_metric_name_invalid','Metric name is invalid.');
		$value=max(0,min(self::MAX_VALUE,(int)$value));
		$bucket_start=gmdate('Y-m-d H:00:00');
		$key=$name.'|'.$bucket_start;
		$t=MAD4B_SCP_Schema::tables();
		if(empty($t['metric_buckets'])) return new WP_Error('mad4b_metric_storage_unavailable','Metric storage is unavailable.');
		$now=gmdate('Y-m-d H:i:s');
		$sql=$wpdb->prepare(
			"INSERT INTO {$t['metric_buckets']} (bucket_key,metric_name,bucket_start,count_value,sum_value,min_value,max_value,updated_at)
			 VALUES (%s,%s,%s,1,%d,%d,%d,%s)
			 ON DUPLICATE KEY UPDATE count_value=count_value+1,sum_value=sum_value+VALUES(sum_value),
			 min_value=IF(min_value IS NULL,VALUES(min_value),LEAST(min_value,VALUES(min_value))),
			 max_value=IF(max_value IS NULL,VALUES(max_value),GREATEST(max_value,VALUES(max_value))),updated_at=VALUES(updated_at)",
			$key,$name,$bucket_start,$value,$value,$value,$now
		);
		$r=$wpdb->query($sql);
		return false===$r?new WP_Error('mad4b_metric_write_failed','Metric bucket update failed.'):true;
	}

	public static function summary(array $names=array(),$hours=24){
		global $wpdb;
		$hours=max(1,min(self::MAX_HOURS,absint($hours)));
		$names=array_values(array_unique(array_filter(array_map(static function($name){
			$name=strtolower(trim((string)$name));
			return 1===preg_match('/^[a-z0-9_.-]{1,96}$/',$name)?$name:'';
		},array_slice($names,0,self::MAX_NAMES)))));
		$t=MAD4B_SCP_Schema::tables();
		if(empty($t['metric_buckets'])) return new WP_Error('mad4b_metric_storage_unavailable','Metric storage is unavailable.');
		$since=gmdate('Y-m-d H:00:00',time()-($hours*3600));
		if($names){
			$placeholders=implode(',',array_fill(0,count($names),'%s'));
			$params=array_merge(array($since),$names);
			$sql=$wpdb->prepare("SELECT metric_name,SUM(count_value) count_value,SUM(sum_value) sum_value,MIN(min_value) min_value,MAX(max_value) max_value FROM {$t['metric_buckets']} WHERE bucket_start >= %s AND metric_name IN ($placeholders) GROUP BY metric_name ORDER BY metric_name ASC",$params);
		}else{
			$sql=$wpdb->prepare("SELECT metric_name,SUM(count_value) count_value,SUM(sum_value) sum_value,MIN(min_value) min_value,MAX(max_value) max_value FROM {$t['metric_buckets']} WHERE bucket_start >= %s GROUP BY metric_name ORDER BY metric_name ASC LIMIT 100",$since);
		}
		$rows=$wpdb->get_results($sql,ARRAY_A);
		$out=array();
		foreach(is_array($rows)?$rows:array() as $row){
			$count=(int)$row['count_value'];$sum=(int)$row['sum_value'];
			$out[]=array(
				'name'=>(string)$row['metric_name'],'count'=>$count,'sum'=>$sum,
				'min'=>null===$row['min_value']?null:(int)$row['min_value'],
				'max'=>null===$row['max_value']?null:(int)$row['max_value'],
				'avg'=>$count>0?round($sum/$count,2):0
			);
		}
		return array('contract'=>self::CONTRACT,'hours'=>$hours,'since'=>$since,'metrics'=>$out,'count'=>count($out),'read_only'=>true,'mutation_performed'=>false);
	}
}
